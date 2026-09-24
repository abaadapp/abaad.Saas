<?php

namespace App\Models;

use App\Support\Contention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BranchStock extends Model
{
    protected $guarded = [];

    /**
     * يطبّق تغييرًا على رصيد فرع لمنتج (يُنشئ السجل عند الحاجة).
     * نقطة مركزية واحدة لكل حركات المخزون حتى يبقى مجموع الفروع = كمية المنتج.
     *
     * الفرق يُطبَّق كما هو ولو أنزل الرصيد تحت الصفر. كان هنا max(0, …)
     * يقصّ الناتج بصمت، فينكسر التوازن «مجموع الفروع = كمية المنتج» ولا
     * يظهر ذلك في أي تقرير: رصيد فرعٍ يقف عند صفر بينما خُصم منه فعليًا.
     * رصيدٌ سالب إشارةُ خللٍ يجب أن تُرى، لا أن تُخبَّأ.
     */
    /**
     * يضمن أن للمنتج توزيعًا على الفروع قبل أوّل خصم منه.
     *
     * منتجٌ بلا أي صفّ هنا يعني «لم يُوزَّع بعد»، وكميته كلها في
     * products.quantity. لو خُصم منه مباشرةً لأُنشئ صفٌّ يبدأ من صفر فيصير
     * سالبًا — رأينا −1 بعد أوّل بيعة — وينكسر التوازن. فيُنقل رصيده كاملًا
     * إلى الفرع العامل أولًا، ثم يُخصم منه.
     *
     * والرصيد القديم يُنسب إلى **الفرع الرئيسي** لا إلى الفرع الذي تجري عليه
     * العملية: بضاعةٌ لم تُوزَّع يومًا موجودة في المستودع الأصلي، لا في الفرع
     * الذي صادف أنه يستلم شحنة اليوم. نسبتُها للفرع المستلِم كانت تُظهر ستّ
     * عشرة قطعة في صلالة وإنما وصلها ستّ.
     *
     * @param  int  $quantity  الكمية الإجمالية قبل التغيير
     */
    public static function ensureAllocated(int $businessId, int $productId, int $quantity): void
    {
        if (static::where('product_id', $productId)->exists()) {
            return;
        }

        $mainBranch = Branch::where('business_id', $businessId)->orderBy('id')->value('id');

        if (! $mainBranch) {
            return; // نشاط بلا فروع — الكمية الإجمالية هي المتاحة أصلًا
        }

        /*
         * والإنشاءُ في نقطة حفظ — كما في `adjust` تحتَها.
         *
         * بين `exists` وهذا السطر يتّسع الوقتُ لغيرك: بيعتان لصنفٍ لم يُوزَّع
         * بعد تقعان معًا فتقرآن «لا صفَّ له» كلتاهما، فيصطدم الثاني بقيد
         * التفرّد على (الفرع، المنتج).
         *
         * وابتلاعُ الاصطدام هنا ليس ترفًا: على PostgreSQL — وهي قاعدةُ
         * الإنتاج — أوّلُ أمرٍ يفشل داخل معاملةٍ يُجهضها كلَّها. وهذه تُنادى
         * من داخل معاملة البيع ومعاملة اعتماد الاستلام ومعاملة الجرد، فكان
         * الاصطدامُ **يُسقط البيعةَ نفسَها** لا صفَّ الفرع وحده. انظر
         * `Contention` — والحالةُ مكتوبةٌ في وصفها بالحرف.
         *
         * ولا يُعاد شيءٌ بعد الاصطدام: المطلوبُ أن يكون للمنتج توزيعٌ، وقد
         * صار. ومن سبقنا كتب الرقمَ نفسَه — لأنّ `ensureAllocated` وحدَها
         * تُنشئ صفَّ التوزيع الأوّل، وهي تُنادى قبل كلّ خصمٍ بالقيمة نفسِها.
         */
        Contention::attempt(fn () => static::create([
            'business_id' => $businessId,
            'branch_id' => $mainBranch,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]));
    }

    /**
     * خصمٌ لا ينزل بالرصيد تحت الصفر — يُقاس ويُكتب في جملةٍ واحدة.
     *
     * ═══ ولمَ لا يُقرأ الرصيدُ ثمّ يُقارن ═══
     *
     * كان البابان — شاشةُ التعديلات وحركةُ المخزون — يقرآن دفترَ الفرع ثمّ
     * يقارنان ثمّ يكتبان. وتلفان يقعان معًا على رصيدٍ عشرة يقرآن «عشرة»
     * كلاهما فيمرّان، ثمّ يُخصم ستّةَ عشر. قِستُها: **رصيدُ الفرع ناقصَ
     * ستّة، والإجماليُّ ناقصَ ستّة** — بلا رسالةٍ ولا أثر.
     *
     * ورصيدٌ سالب يُفسد كلَّ ما يُبنى عليه: قيمةُ المخزون تصير سالبة،
     * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطةُ البيع تبيع ما ليس عندها.
     *
     * ولا يكفي قفلُ الصفّ: قاعدةُ الفحص SQLite لا صفوفَ تُقفل فيها، فحارسٌ
     * بالقفل وحدَه حارسٌ لا يُقاس. والشرطُ في `where` يُنفَّذ في القاعدة مع
     * الكتابة نفسِها، فهو واحدٌ على القاعدتين ويُقاس على كلتيهما.
     *
     * @param  int  $amount  المقدار الموجب المطلوب خصمه
     * @return bool false إن لم يعد في الرفّ ما يكفي — فلا يُكتب شيء
     */
    public static function draw(int $branchId, int $productId, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        return static::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('quantity', '>=', $amount)
            ->update([
                'quantity' => DB::raw('quantity - '.$amount),
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * رصيد كل فرعٍ لكل منتج — قاعدةٌ واحدة تقرؤها الشاشة والخادم.
     *
     * ومنتجٌ لم يُوزَّع قطّ رصيدُه كلّه في الفرع الأوّل، وهي القاعدة نفسها
     * التي يطبّقها `ensureAllocated` عند أوّل حركة. واختلافُ الشاشة عنها
     * يعني رقمًا دفتريًّا يخالف ما سيحسبه الخادم — والفرق يظهر تسويةً لم
     * يطلبها أحد.
     *
     * @return array<int, array<int, int>> [معرّف المنتج][معرّف الفرع] => الكمية
     */
    public static function books(int $businessId): array
    {
        $main = Branch::where('business_id', $businessId)->orderBy('id')->value('id');
        $rows = static::where('business_id', $businessId)->get()->groupBy('product_id');

        return Product::where('business_id', $businessId)
            ->get(['id', 'quantity'])
            ->mapWithKeys(function ($p) use ($rows, $main) {
                $group = $rows[$p->id] ?? collect();

                return [$p->id => $group->isEmpty()
                    ? ($main ? [$main => (int) $p->quantity] : [])
                    : $group->mapWithKeys(fn ($s) => [(int) $s->branch_id => (int) $s->quantity])->all()];
            })
            ->all();
    }

    /** رصيد منتجٍ في فرعٍ بعينه — بالقاعدة نفسها */
    public static function bookOf(int $businessId, int $productId, int $branchId): int
    {
        return (int) (static::books($businessId)[$productId][$branchId] ?? 0);
    }

    /**
     * يطبّق الفرق بجملةٍ واحدة — لا قراءةً ثمّ كتابة.
     *
     * كان الصفّ يُقرأ ثمّ يُحسب ثمّ يُحفظ. وبيعتان لصنفٍ واحد في الفرع نفسه
     * تقعان معًا تقرآن «عشرة» كلتاهما وتكتبان «تسعة» كلتاهما: قطعةٌ خرجت من
     * الرفّ ولم تخرج من الدفتر. وينكسر التوازن الذي يقوم عليه النظام كلّه
     * — «مجموع الفروع = كمية المنتج» — بلا أثرٍ يُقرأ في أيّ شاشة، ولا
     * يظهر إلّا في جردٍ لا يُعرف من أين جاء فرقُه.
     *
     * والزيادة في القاعدة نفسها ذرّيّةٌ لا تحتاج قفلًا. ويبقى إنشاء الصفّ
     * أوّل مرّة: قيد التفرّد على (الفرع، المنتج) يجعل الثاني ينكسر بدل أن
     * يُنشئ صفًّا ثانيًا — فيُلتقط ويُطبَّق الفرق على ما أنشأه السابق.
     */
    public static function adjust(int $businessId, ?int $branchId, int $productId, int $delta): void
    {
        if (! $branchId || $delta === 0) {
            return;
        }

        $apply = fn () => static::where('branch_id', $branchId)->where('product_id', $productId)
            ->update([
                'quantity' => DB::raw('quantity + '.$delta),
                'updated_at' => now(),
            ]);

        if ($apply()) {
            return;
        }

        /*
         * والإنشاءُ في نقطة حفظ: اصطدامان متزامنان على المفتاح نفسه يقعان،
         * وابتلاعُ الاصطدام على PostgreSQL يُجهض المعاملةَ المحيطة — وهي
         * هنا معاملةُ بيعٍ أو استلام. انظر `Contention`.
         */
        $created = Contention::attempt(fn () => static::create([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'quantity' => $delta,
        ]));

        if ($created === null) {
            $apply();
        }
    }
}
