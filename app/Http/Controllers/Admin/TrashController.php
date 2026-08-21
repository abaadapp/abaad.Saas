<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * المحذوفات — الزرّ الذي يردّ ما أذهبته ضغطة.
 *
 * الحذف الناعم وحده لا يكفي: صفٌّ باقٍ في القاعدة لا يراه أحد إلا من يملك
 * وصولًا إليها، فيصير الاسترداد مكالمةً مع الدعم لا فعلًا يفعله صاحب المتجر.
 * هذه الشاشة هي ما يجعل «الحذف قابل للتراجع» جملةً صحيحة.
 */
class TrashController extends Controller
{
    /**
     * مهلة الاسترداد — بعدها يُمحى الصفّ محوًا نهائيًّا.
     *
     * كانت مرشِّح عرضٍ فقط: تختفي الصفوف من الشاشة وتبقى في القاعدة أبدًا.
     * فيقرأ التاجر «٩٠ يومًا» ويظنّ ما حذفه ذهب وهو باقٍ، ثم لا يستطيع بعد
     * اليوم ٩١ استعادته ولا محوَه — غير مرئيّ وغير قابل للتصرّف معًا.
     * وأمر `trash:purge` المجدول هو ما يجعل هذا الرقم وعدًا يُنفَّذ.
     */
    public const WINDOW_DAYS = 90;

    /** أنواع السلّة ونماذجها — مرجعٌ واحد للشاشة والمسارات والأمر المجدول */
    public const MODELS = [
        'product' => Product::class,
        'expense' => Expense::class,
        'branch' => Branch::class,
        'customer' => Customer::class,
    ];

    /**
     * ما يُمحى محوًا نهائيًّا — الفرع ليس منها، لا بزرٍّ ولا بجدول.
     *
     * صفّ الفرع يحمل قيودًا متسلسلة: محوُه يمحو معه تسجيلَ كل صندوقٍ فيه
     * وأذونَ موظفيه، ويُفرّغ فرعَ العميل وحركةِ المخزون وأمرِ الشراء، وتبقى
     * مبيعاته تشير إلى رقمٍ لا وجود له. أي أن الضغطة لا تحذف فرعًا بل تُعيد
     * كتابة تاريخ المتجر بصمت. ولذلك يُخفى الفرع ويبقى قابلًا للاستعادة
     * أبدًا — والمهلة لا تسري عليه.
     */
    // والعميل منها: من طلب حذف بياناته يجب أن تُمحى فعلًا لا أن تُخفى
    public const PURGEABLE = ['product', 'expense', 'customer'];

    private static function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(): \Inertia\Response
    {
        return \Inertia\Inertia::render('Admin/Settings/Trash', self::panelData());
    }

    /**
     * «من حذفه» — يُقرأ من سجلّ النشاط لا من جدول الصفّ.
     *
     * الصفّ يحمل متى حُذف ولا يحمل من حذفه، والسؤال الأول لصاحب متجرٍ فيه
     * موظّفون هو الثاني لا الأول: الاستعادة تُصلح الضرر مرّة، ومعرفة الفاعل
     * تمنع تكراره. والاسم منسوخٌ في السجلّ وقت الفعل، فيبقى وإن حُذف الحساب.
     *
     * ويُطابَق بالنوع والمعرّف معًا: المعرّف وحده يخلط منتجًا رقمه ٥ بمصروفٍ
     * رقمه ٥. ولذلك صار كل حذفٍ يكتب `subject_type` — وما قُيّد قبل ذلك لا
     * نوع له فيُعرض «—»، ولا سبيل إلى استنتاجه بأثرٍ رجعيّ.
     */
    private static function withDeleter(Builder $q, string $type): Builder
    {
        $log = fn (string $col) => ActivityLog::query()
            ->select($col)
            ->whereColumn('subject_id', $q->getModel()->getTable().'.id')
            ->where('subject_type', $type)
            ->where('action', 'deleted')
            ->latest('id')
            ->limit(1);

        return $q->addSelect(['deleted_by' => $log('user_name')]);
    }

    /**
     * بيانات قسم المحذوفات — تُقرأ من صفحته المستقلّة ومن لوحة الإعدادات
     * حيث يُفتح مكانها.
     *
     * @return array<string, mixed>
     */
    public static function panelData(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $bid = self::bid();

        $trashed = function (string $type) use ($bid, $since) {
            $q = self::MODELS[$type]::onlyTrashed()->where('business_id', $bid);

            // الفرع لا يُمحى فلا مهلة له، ولا يُخفى من الشاشة بمرور الوقت
            if (in_array($type, self::PURGEABLE, true)) {
                $q->where('deleted_at', '>=', $since);
            }

            return self::withDeleter($q->orderByDesc('deleted_at'), $type);
        };

        /** أيّامٌ بقيت قبل المحو النهائي — الرقم هو ما يدفع إلى القرار */
        $left = fn ($row) => max(0, self::WINDOW_DAYS - (int) $row->deleted_at?->diffInDays(now()));

        $products = $trashed('product')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => (float) $p->price,
            'quantity' => (int) $p->quantity,
            'deletedAt' => $p->deleted_at?->format('Y-m-d H:i'),
            'deletedBy' => $p->deleted_by,
            'daysLeft' => $left($p),
        ]);

        $expenses = $trashed('expense')->get()->map(fn ($e) => [
            'id' => $e->id,
            'reference' => $e->reference,
            'title' => $e->description ?: $e->type,
            'amount' => (float) $e->amount,
            'spentAt' => $e->spent_at?->format('Y-m-d'),
            'deletedAt' => $e->deleted_at?->format('Y-m-d H:i'),
            'deletedBy' => $e->deleted_by,
            'daysLeft' => $left($e),
        ]);

        /*
         * الفروع تُعرض — وجدولها يظهر عند وجود محذوفٍ فقط.
         *
         * كانت مستثناةً كي لا يقف جدولٌ فارغ دائمًا في الشاشة، وهي حُجّة
         * صحيحة ضدّ الازدحام وخاطئة ضدّ الوجود: سبيلها الوحيد كان زرَّ
         * «تراجع» في إشعارٍ يختفي بعد ١٢ ثانية. أي أن للفرع — وهو أثمن ما
         * يُحذف — نافذةَ استردادٍ أقصر من نافذة المنتج بمليون مرّة.
         */
        $branches = $trashed('branch')->get()->map(fn ($b) => [
            'id' => $b->id,
            'name' => $b->name,
            'address' => $b->address,
            'deletedAt' => $b->deleted_at?->format('Y-m-d H:i'),
            'deletedBy' => $b->deleted_by,
        ]);

        /*
         * والعملاء: صفٌّ يحمل نقاطًا وعناوين وفواتيرَ تشير إليه، فحذفه
         * إخفاءٌ لا محو — ولا يكون له سبيلٌ إلى العودة إلا من هنا.
         */
        $customers = $trashed('customer')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'points' => (int) $c->points,
            'deletedAt' => $c->deleted_at?->format('Y-m-d H:i'),
            'deletedBy' => $c->deleted_by,
            'daysLeft' => $left($c),
        ]);

        return [
            'products' => $products,
            'expenses' => $expenses,
            'customers' => $customers,
            /*
             * `trashedBranches` لا `branches`: قسم الفروع في الإعدادات يرسل
             * `branches` وهي الفروع العاملة. والمفتاحان يلتقيان في خصائص
             * صفحةٍ واحدة، فاسمٌ مشترك يجعل قسمًا يقرأ بيانات قسمٍ آخر —
             * ويظهر الفرع المحذوف حيًّا في قائمة الفروع.
             */
            'trashedBranches' => $branches,
            'windowDays' => self::WINDOW_DAYS,
        ];
    }

    /**
     * النوع لا يصل من المتصفّح: كل مسارٍ يحمله في `defaults` — فيقع المسار
     * داخل مجموعة قسمه، ويرث حارسه. من يملك حذف المنتجات يردّها ويمحوها،
     * ومن يملك الفروع يفعل بفرعه. ولو جاء النوع من الطلب لصار مسارًا واحدًا
     * يحرسه قسمٌ واحد، ولوجب أن يكون «الإعدادات» — فيمحو موظّفُ الجرد قيدًا
     * ماليًّا محوًا لا رجعة فيه.
     */
    private static function typeOf(Request $request): string
    {
        $type = (string) ($request->route()->defaults['type'] ?? '');

        return isset(self::MODELS[$type]) ? $type : abort(404);
    }

    /** المتجر يُقرأ من الجلسة لا من الطلب: معرّفٌ من متجر الجار يُردّ ٤٠٤ */
    private static function findTrashed(string $type, int $id)
    {
        return self::MODELS[$type]::onlyTrashed()
            ->where('business_id', self::bid())
            ->findOrFail($id);
    }

    private static function label(string $type, $row): string
    {
        return $type === 'expense'
            ? ($row->reference ?: $row->description ?: $row->type)
            : $row->name;
    }

    /** يردّ صفًّا مخفيًّا */
    public function restore(Request $request, int $id)
    {
        $type = self::typeOf($request);
        $row = self::findTrashed($type, $id);
        $row->restore();

        // المصروف يعود ومعه قيده في الدفتر — بمرجعه نفسه لا بمرجعٍ جديد
        if ($type === 'expense') {
            $row->transaction()->withTrashed()->restore();
        }

        $label = self::label($type, $row);

        Activity::log('restore', match ($type) {
            'product' => 'استعاد المنتج: ',
            'expense' => 'استعاد المصروف: ',
            'branch' => 'استعاد الفرع: ',
            'customer' => 'استعاد العميل: ',
        }.$label, ['subject_id' => $row->id, 'subject_type' => $type]);

        return back()->with('toast', [
            'msg' => __('تمت الاستعادة: :name', ['name' => $label]),
            'type' => 'success',
        ]);
    }

    /**
     * محوٌ لا رجعة فيه — ولا زرّ «تراجع» بعده.
     *
     * قسمٌ اسمه «المحذوفات» لا يستطيع الحذف تسميةٌ في غير محلّها: من أدخل
     * منتجًا باسمٍ خاطئ، أو بيانات عميلٍ طلب حذفها، لم يكن له سبيل. والملفّ
     * يُمحى مع الصفّ — الصورة والمرفق كانا يبقيان على القرص بعد ذهاب ما
     * يشير إليهما، فلا أحد يعرف أنهما هناك ولا كيف يصل إليهما.
     */
    public function purge(Request $request, int $id)
    {
        $type = self::typeOf($request);

        // لا مسار محوٍ للفرع أصلًا، والحارس هنا كي لا يُفتح واحدٌ سهوًا
        abort_unless(in_array($type, self::PURGEABLE, true), 404);

        $row = self::findTrashed($type, $id);
        $label = self::label($type, $row);

        self::purgeRow($type, $row);

        Activity::log('deleted', match ($type) {
            'product' => 'محا المنتج نهائيًّا: ',
            'expense' => 'محا المصروف نهائيًّا: ',
            'customer' => 'محا العميل نهائيًّا: ',
        }.$label, ['subject_id' => $id, 'subject_type' => $type]);

        return back()->with('toast', [
            'msg' => __('تم المحو النهائي: :name', ['name' => $label]),
            'type' => 'warning',
        ]);
    }

    /**
     * المحو الفعلي — يستدعيه الزرّ والأمر المجدول معًا.
     *
     * موضعٌ واحد لأن الفرق بين الطريقين توقيتٌ لا سلوك: ما ينساه أحدهما من
     * تنظيفٍ يترك ملفًّا يتيمًا على القرص لا يعرف أحد سببه.
     */
    public static function purgeRow(string $type, $row): void
    {
        /*
         * القيمة الخام لا المقروءة: `Product::getImageAttribute` يردّ رابطًا
         * جاهزًا للعرض (‎/storage/…‎، أو رابط صورةٍ بديلة إن لم تكن هناك صورة).
         * وتمرير الرابط إلى `delete` لا يجد شيئًا ولا يشتكي — فيُمحى الصفّ
         * ويبقى الملفّ. عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
         */
        $file = match ($type) {
            'product' => $row->getRawOriginal('image'),
            'expense' => $row->getRawOriginal('attachment'),
            default => null,
        };

        // رابطٌ خارجيّ لا ملفّ على قرصنا — لا يُمَسّ
        if ($file && ! str_starts_with($file, 'http')) {
            Storage::disk('public')->delete($file);
        }

        /*
         * رصيد المنتج في كل فرع يذهب معه. لا قيد مفتاحٍ يتكفّل بذلك —
         * `branch_stocks.product_id` عمودٌ عاديّ — فالصفوف تبقى تحمل كمّياتٍ
         * لمنتجٍ لا وجود له، ويحسبها تقرير «قيمة المخزون» في مجموعه.
         */
        if ($type === 'product') {
            \App\Models\BranchStock::where('product_id', $row->id)->delete();
        }

        // ومحوُ المصروف يمحو قيده: لا يبقى في الدفتر سطرٌ لا أصل له
        if ($type === 'expense') {
            $row->transaction()->withTrashed()->forceDelete();
        }

        $row->forceDelete();
    }
}
