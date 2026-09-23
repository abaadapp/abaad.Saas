<?php

namespace App\Support;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * البوتيكات — نسبةُ المحلّ ممّا يُباع تحت سقفه.
 *
 * وهي طبقةٌ فوق الأصناف لا نظامٌ إلى جانبها: البيعُ يقع في الصندوق أو في
 * الموقع كما يقع اليوم، والمخزونُ يُخصم كما يُخصم، والتقاريرُ تقرأ ما تقرأ.
 * وما يُضاف أنّ البندَ يذكر صاحبَه ونسبتَه ساعةَ بيعه.
 */
final class Boutiques
{
    /** نوعُ المصروف الذي تُولده التسوية — به تُرشَّح في شاشة المصروفات */
    public const EXPENSE_TYPE = 'تسوية بوتيكات';

    /**
     * ═══ بضاعةُ البوتيك أمانة ═══
     *
     * هو يملكها حتّى تُباع. فلا يُكتب لها قيدُ تكلفةِ بضاعةٍ مباعة، ولا
     * تدخل قيمةَ مخزون المحلّ — وإلّا حُسب الثمنُ مرّتين: مرّةً تكلفةً يومَ
     * البيع، ومرّةً مصروفًا يومَ التسوية. والربحُ يُقرأ أقلَّ ممّا هو بكلّ
     * قطعةٍ تُباع.
     *
     * فلقطةُ التكلفة على بند البوتيك صفرٌ مهما كُتب في بطاقة الصنف.
     */
    public const CONSIGNED_COST = 0.0;

    /* ═══════════ البابُ مفتوحٌ لمن فُتح له ═══════════ */

    /**
     * أيُؤوي هذا المحلُّ بوتيكات؟
     *
     * ومن لا، لا يرى تبويبًا ولا حقلًا ولا يُكتب على بنوده شيء — فالميزةُ
     * لا تُثقل شاشةَ من لا يستعملها، ولا تُغيّر عليه بيعةً واحدة.
     */
    public static function hosts(?Business $business): bool
    {
        return (bool) ($business?->boutiques_enabled ?? false);
    }

    /**
     * أخلف البابِ شيءٌ لهذا المحلّ؟ — مفتاحُه، أو بوتيكٌ قائمٌ عنده.
     *
     * والصفُّ يفتح البابَ ولو أُطفئ المفتاح. فالمفتاحُ أُطفئ مرّةً بلا أن
     * يطلبه أحد — كلُّ حفظةِ اسمٍ أو هاتفٍ في شاشة الشركات كانت تُطفئه
     * (أُصلح) — والبيعُ لا يتوقّف بإطفائه: `attribute` تقرأ البوتيك من
     * بطاقة الصنف، فتبقى بنودُه تُكتب بنسبته وتكلفتُها صفر.
     *
     * فكان التاجرُ يُحرم شاشةَ مالٍ ما زال يُحسب: بضاعةُ أمانةٍ تُباع، ودَينٌ
     * لصاحبها يكبر، وكشفُه ٤٠٤ — فلا يراه ولا يُسوّيه ولا يعرف كم عليه.
     *
     * ومن لا بوتيكَ عنده أصلًا يبقى على حاله: لا تبويبَ ولا شاشة.
     */
    public static function holds(?Business $business): bool
    {
        if ($business === null) {
            return false;
        }

        return self::hosts($business)
            || Boutique::where('business_id', $business->id)->exists();
    }

    /** يقطع الطلبَ عمّن لا شيءَ خلف بابه — 404 لا 403: لا وجودَ للشاشة عنده */
    public static function enforce(?Business $business): void
    {
        abort_unless(self::holds($business), 404);
    }

    /* ═══════════ النسبةُ ساعةَ البيع ═══════════ */

    /**
     * لكلّ سطرٍ في السلّة: صاحبُه ونسبتُه — أو `null` إن كان صنفَ المحلّ.
     *
     * ويُقرأ من الصنف لا ممّا أرسلته الشاشة: البوتيكُ ليس خيارًا يختاره
     * الكاشير، هو صفةُ الصنف. ولو قُرئ من الطلب لَأمكن أن يُنسب بيعٌ إلى
     * بوتيكٍ لا صنفَ له فيه.
     *
     * @param  array<int, array{product?: \App\Models\Product|null}>  $lines
     * @return array<int, array{id: int, name: string, rate: float}|null>
     */
    public static function attribute(int $businessId, array $lines): array
    {
        $ids = collect($lines)
            ->map(fn ($l) => (int) (($l['product'] ?? null)?->boutique_id ?? 0))
            ->filter(fn ($id) => $id > 0)->unique()->values();

        if ($ids->isEmpty()) {
            return array_fill(0, count($lines), null);
        }

        $boutiques = Boutique::where('business_id', $businessId)
            ->whereIn('id', $ids->all())->get()->keyBy('id');

        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $b = $boutiques->get((int) (($l['product'] ?? null)?->boutique_id ?? 0));

            $out[$i] = $b === null ? null : [
                'id' => (int) $b->id,
                // الاسمُ لقطةٌ كذلك: بوتيكٌ يُغادر ويُحذف لا يُفقد كشفًا صدر معناه
                'name' => (string) $b->name,
                'rate' => round((float) $b->commission_rate, 2),
            ];
        }

        return $out;
    }

    /**
     * أعمدةُ البند التي يكتبها البائعان — الصندوق والموقع.
     *
     * وموضعٌ واحد يكتبها: ثلاثةُ أعمدةٍ تُنسخ في ثلاثة مواضع تفترق يومًا،
     * فيُكتب المعرّفُ بلا نسبة — وبندٌ بلا نسبةٍ لا يدخل كشفَ حساب.
     *
     * وتكلفتُه صفرٌ: الأمانةُ لا تُكلّف المحلَّ شيئًا — انظر `CONSIGNED_COST`.
     *
     * @param  array{id: int, name: string, rate: float}|null  $at
     * @return array<string, mixed>
     */
    public static function itemColumns(?array $at, float $cost): array
    {
        if ($at === null) {
            return ['boutique_id' => null, 'boutique_name' => null, 'boutique_rate' => null, 'cost' => $cost];
        }

        return [
            'boutique_id' => $at['id'],
            'boutique_name' => $at['name'],
            'boutique_rate' => $at['rate'],
            'cost' => self::CONSIGNED_COST,
        ];
    }

    /* ═══════════ كشفُ الحساب ═══════════ */

    /** الشهرُ كما يُكتب: «2027-02» — ومن أرسل غيرَه رُدّ إلى شهر اليوم */
    public static function period(?string $raw): string
    {
        return is_string($raw) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)
            ? $raw
            : now()->format('Y-m');
    }

    /**
     * أانتهى هذا الشهرُ فصار يُسوَّى؟
     *
     * ═══ ولا تُصدَر تسويةُ شهرٍ ما زال يبيع ═══
     *
     * التسويةُ تُجمّد الأرقام، وفهرسُ القاعدة الفريد يمنع ثانيةً لشهرٍ
     * واحد. فمن أصدرها في العاشر أخذ عشرةَ أيّام وأغلق البابَ على عشرين:
     * ما يُباع في بقيّة الشهر يبقى في الكشف حيًّا ولا يجد تسويةً تحمله،
     * ولا تصرخ شاشةٌ ولا يختلّ ميزان — يضيع مالُ البوتيك صامتًا.
     *
     * فالشهرُ الجاري — وما بعده — لا يُسوَّى حتّى يُغلق.
     */
    public static function isClosed(string $period): bool
    {
        return $period < now()->format('Y-m');
    }

    /**
     * ما بِيع لبوتيكٍ في شهر — صنفًا صنفًا، ثمّ مجموعًا.
     *
     * ═══ والحسبةُ من لقطة البند لا من بطاقة البوتيك ═══
     *
     * النسبةُ تُقرأ من `boutique_rate` المكتوبة ساعةَ البيع. فبوتيكٌ تُرفع
     * نسبتُه اليوم لا تُعاد به حسبةُ ما بِيع أمس — وهو المال الذي اتُّفق
     * عليه، لا الذي يُتّفق عليه غدًا.
     *
     * والملغى يخرج: `Order::scopeSold` هي قاعدةُ «ما بِيع» في النظام كلِّه.
     *
     * @return array{
     *     period: string, from: string, to: string,
     *     lines: list<array<string, mixed>>,
     *     gross: float, commission: float, net: float, quantity: float, lines_count: int
     * }
     */
    public static function statement(int $businessId, int $boutiqueId, string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();

        $rows = OrderItem::query()
            ->where('order_items.boutique_id', $boutiqueId)
            ->whereIn('order_items.order_id', Order::where('business_id', $businessId)->sold()
                ->whereBetween('ordered_at', [$from, $to])->select('id'))
            ->get([
                'order_items.product_id', 'order_items.name', 'order_items.variant_name',
                'order_items.quantity', 'order_items.total', 'order_items.boutique_rate',
            ]);

        $lines = [];
        foreach ($rows as $r) {
            /*
             * والمفتاحُ الصنفُ ونسبتُه معًا.
             *
             * صنفٌ بِيع قبل تغيير النسبة وبعده سطران لا سطر: جمعُهما في سطرٍ
             * واحد يُجبر على كتابة نسبةٍ واحدةٍ لا تصدق على نصفه.
             */
            $rate = round((float) ($r->boutique_rate ?? 0), 2);
            $key = ((int) $r->product_id).'|'.$r->name.'|'.($r->variant_name ?? '').'|'.$rate;

            $lines[$key] ??= [
                'product_id' => $r->product_id ? (int) $r->product_id : null,
                'name' => (string) $r->name,
                'variant' => $r->variant_name,
                'rate' => $rate,
                'quantity' => 0.0,
                'gross' => 0.0,
                'commission' => 0.0,
                'net' => 0.0,
            ];

            $lines[$key]['quantity'] += (float) $r->quantity;
            $lines[$key]['gross'] += (float) $r->total;
        }

        $gross = 0.0;
        $commission = 0.0;
        $quantity = 0.0;

        foreach ($lines as $k => $l) {
            // والتقريبُ على السطر لا على المجموع: السطرُ هو ما يُراجَع ويُجمَع بيدٍ
            $lineGross = round($l['gross'], 3);
            $lineCommission = round($lineGross * $l['rate'] / 100, 3);

            $lines[$k]['gross'] = $lineGross;
            $lines[$k]['commission'] = $lineCommission;
            $lines[$k]['net'] = round($lineGross - $lineCommission, 3);
            $lines[$k]['quantity'] = round($l['quantity'], 3);

            $gross += $lineGross;
            $commission += $lineCommission;
            $quantity += $lines[$k]['quantity'];
        }

        $lines = array_values($lines);
        usort($lines, fn ($a, $b) => $b['gross'] <=> $a['gross']);

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'lines' => $lines,
            'quantity' => round($quantity, 3),
            'gross' => round($gross, 3),
            'commission' => round($commission, 3),
            'net' => round($gross - $commission, 3),
            'lines_count' => count($lines),
        ];
    }

    /* ═══════════ التسوية ═══════════ */

    /**
     * يُصدر تسويةَ الشهر — مستندًا مجمَّدًا ومصروفًا يُسدَّد.
     *
     * ولا مسارَ مالٍ ثانٍ يُبنى: ما على المحلّ للبوتيك مستحقٌّ كسائر ما عليه،
     * فيدخل «المبالغ المستحقة» ويُسدَّد من بابه ويكتب قيدَه.
     *
     * والتفرّدُ يحرسه فهرسُ القاعدة لا فحصٌ هنا وحده: ضغطتان متقاربتان
     * تمرّان من الفحص معًا ثمّ تصطدمان بالفهرس — فيُقرأ الاصطدام كلمةً
     * يفهمها صاحبُه لا خطأَ قاعدة.
     *
     * @throws SettlementRefused
     */
    public static function settle(Business $business, Boutique $boutique, string $period, ?string $employee = null): BoutiqueSettlement
    {
        if (! self::isClosed($period)) {
            throw new SettlementRefused(__('الشهرُ لم ينتهِ بعد — تُصدَر تسويتُه بعد آخر يومٍ فيه، وإلّا ضاع ما يُباع في بقيّته.'));
        }

        $statement = self::statement((int) $business->id, (int) $boutique->id, $period);

        if ($statement['lines_count'] === 0) {
            throw new SettlementRefused(__('لا مبيعات لهذا البوتيك في هذا الشهر — لا تسوية بلا بيع.'));
        }

        /*
         * ═══ ولا فحصَ ثانٍ للتفرّد ═══
         *
         * كان هنا `exists()` قبل الكتابة. وهو وحارسُ الفهرس يقولان الشيء
         * نفسَه — و«فحصان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما». وأسوأُ
         * منه أنّ الفحصَ يقرأ حالًا قد تتبدّل بين قراءته وكتابته، فيمرّ
         * منه الضغطتان المتقاربتان معًا ويبقى الفهرسُ هو الذي يردّ فعلًا.
         *
         * فالحارسُ واحد — الفهرس — وكلمتُه تُترجَم إلى ما يفهمه صاحبُه.
         * وهذا الفرعُ هو الطريقُ لا مخرجُ الطوارئ، فيُسلك ويُحرَس.
         */
        try {
            return self::write($business, $boutique, $period, $statement, $employee);
        } catch (QueryException $e) {
            /*
             * وضغطتان متقاربتان تمرّان من الفحص معًا.
             *
             * فالفهرسُ الفريد هو الحارسُ الأخير — وهو الصادق: الفحصُ يقرأ
             * حالًا قد تتبدّل بين قراءته وكتابته. وما يقوله الفهرسُ يُترجَم
             * إلى الكلمة نفسِها، ولا يُعرض نصُّ القاعدة على التاجر.
             */
            if (str_contains($e->getMessage(), 'boutique_settlements')) {
                throw new SettlementRefused(__('تسويةُ هذا الشهر صدرت من قبل — تُفتح ولا تُصدَر ثانيةً.'));
            }

            throw $e;
        }
    }

    /** كتابةُ المستند ومصروفه — في معاملةٍ واحدة */
    private static function write(Business $business, Boutique $boutique, string $period, array $statement, ?string $employee): BoutiqueSettlement
    {
        return DB::transaction(function () use ($business, $boutique, $period, $statement, $employee) {
            $number = self::nextNumber((int) $business->id, $period);
            $due = Carbon::createFromFormat('Y-m-d', $statement['to'])->endOfDay();

            /*
             * والمصروفُ بصافي ما للبوتيك — لا بالإجماليّ.
             *
             * البيعةُ دخلت إيرادًا بكاملها ساعةَ وقوعها (وهي طريقةُ الإجمالي
             * التي اختارها صاحبُ المحلّ)، فما يخرج الآن هو حصّةُ البوتيك
             * وحدها. وربحُ المحلّ ما بينهما — وهو العمولة.
             */
            $expense = Expense::create([
                'business_id' => $business->id,
                'type' => self::EXPENSE_TYPE,
                'description' => __('تسوية :name عن :period', ['name' => $boutique->name, 'period' => $period]),
                'amount' => $statement['net'],
                'reference' => $number,
                'due_date' => $due->toDateString(),
                'spent_at' => $due->toDateString(),
                'status' => Expense::UNPAID,
                'employee_name' => $employee,
            ]);

            $settlement = BoutiqueSettlement::create([
                'business_id' => $business->id,
                'boutique_id' => $boutique->id,
                'number' => $number,
                'period' => $period,
                'gross' => $statement['gross'],
                'commission' => $statement['commission'],
                'net' => $statement['net'],
                'lines_count' => $statement['lines_count'],
                'expense_id' => $expense->id,
                'issued_at' => now(),
            ]);

            Activity::log('created', 'تسوية بوتيك '.$boutique->name.' عن '.$period.' بمبلغ '.$statement['net'], [
                'business_id' => $business->id, 'subject_id' => $settlement->id, 'subject_type' => 'boutique_settlement',
            ]);

            return $settlement;
        });
    }

    /** رقمُ التسوية — «BQ-2027-02-3» : الشهرُ فيه ليُقرأ بلا فتحه */
    private static function nextNumber(int $businessId, string $period): string
    {
        $n = BoutiqueSettlement::where('business_id', $businessId)->where('period', $period)->count() + 1;

        return 'BQ-'.$period.'-'.$n;
    }
}
