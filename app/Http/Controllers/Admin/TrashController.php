<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Demo;
use App\Support\PlanLimits;
use App\Support\ProductImages;
use App\Support\StockLosses;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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

    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Trash', self::panelData());
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
            if ($type === 'expense') {
                // والمصروفُ الذي كُتب له قيدٌ لا يُمحى (`Books::expenseHasHistory`) — فلا يُخفى بانقضاء المهلة
                $q->where(fn ($w) => $w->where('deleted_at', '>=', $since)
                    ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('journal_entries')
                        ->where('journal_entries.sourceable_type', Expense::class)
                        ->whereColumn('journal_entries.sourceable_id', 'expenses.id'))
                    // وقيدُ مصروف شاشة المالية على حركته، وقيدُ الهالك على المخزون
                    ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('transactions')
                        ->whereColumn('transactions.id', 'expenses.transaction_id')
                        ->whereNotNull('transactions.journal_entry_id'))
                    ->orWhere('expenses.method', StockLosses::EXPENSE_METHOD));
            } elseif (in_array($type, self::PURGEABLE, true)) {
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

        $period = BooksPeriod::context($bid, auth()->user());

        $expenses = $trashed('expense')->get()->map(function ($e) use ($left, $period) {
            $kept = Books::expenseHasHistory($e);

            return [
                'id' => $e->id,
                'reference' => $e->reference,
                'title' => $e->description ?: $e->type,
                'amount' => (float) $e->amount,
                'spentAt' => $e->spent_at?->format('Y-m-d'),
                'deletedAt' => $e->deleted_at?->format('Y-m-d H:i'),
                'deletedBy' => $e->deleted_by,
                // له قيدٌ في الدفتر: دليلٌ محاسبيّ يبقى، فلا عدَّ تنازليًّا ولا زرَّ محو
                'kept' => $kept,
                'daysLeft' => $kept ? null : $left($e),
                // والاستعادةُ تُعيد القيدَ بيوم حذفه — فإن عبر حدَّ شهرٍ سُئل قبلها
                'correction' => BooksPeriod::describe($period, $e->isPaid() ? Books::lastExpenseReversalDate($e) : null),
            ];
        });

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

    /**
     * هل صار رمز المنتج المخفيّ أو باركودُه لمنتجٍ حيّ؟
     *
     * @return array{code: string, name: string}|null
     */
    private static function codeClash(Product $row): ?array
    {
        foreach (['sku', 'barcode'] as $column) {
            if (blank($row->{$column})) {
                continue;
            }

            $holder = Product::where('business_id', $row->business_id)
                ->where($column, $row->{$column})
                ->whereKeyNot($row->id)
                ->first();

            if ($holder) {
                return ['code' => (string) $row->{$column}, 'name' => (string) $holder->name];
            }
        }

        return null;
    }

    /**
     * فرعٌ حيٌّ يحمل اسمَ هذا المحذوف — أو `null`.
     *
     * ولا `whereKeyNot` هنا: الصفُّ الذي يُستعاد محذوفٌ بعد، و`Branch::where`
     * تستثني المحذوف بحكم `SoftDeletes`. فشرطٌ يُقصي نفسَه لا يُقصي شيئًا —
     * «ما لا يحرس شيئًا لا يُكتب». وقد نجت طفرتُه فعلًا قبل أن يُحذف.
     *
     * والحصرُ بالمتجر يحرس: بدونه يُردّ إحياءُ «صلالة» لأنّ متجرًا آخر في
     * المنصّة فتح فرعًا بالاسم نفسِه — رفضٌ لا يفهمه صاحبُه ولا سبيلَ له إليه.
     */
    private static function nameClash(Branch $row): ?string
    {
        $taken = Branch::where('business_id', $row->business_id)
            ->where('name', $row->name)
            ->exists();

        return $taken ? (string) $row->name : null;
    }

    private static function label(string $type, $row): string
    {
        return $type === 'expense'
            ? ($row->reference ?: $row->description ?: $row->type)
            : $row->name;
    }

    /**
     * المحو النهائي قرارُ صاحب المتجر وحده.
     *
     * كلّ زرٍّ هنا يتبع قسم صفِّه: `admin.customers.purge` قسمُه «العملاء»،
     * و`admin.products.purge` قسمُه «المنتجات». والاستعادة تُراد كذلك — زرّ
     * «تراجع» في إشعار الحذف يردّ ما حذفه صاحبُه من مكانه، ولو تبع الإعدادات
     * لظهر الزرّ ثمّ رُدَّ ٤٠٣.
     *
     * أمّا المحو فلا زرّ تراجع بعده ولا شاشة له إلا المحذوفات — وهي في
     * الإعدادات. فكان البائع يملك «العملاء»، ولا يرى تلك الشاشة، ويستطيع مع
     * ذلك محوَ عميلٍ محوًا لا رجعة فيه بمسارٍ لم يُعرض له قطّ.
     */
    private static function gate(): void
    {
        abort_unless(auth()->user()?->allows('settings'), 403, __('ليس لديك صلاحية للمحو النهائي — وهو في الإعدادات.'));
    }

    /** يردّ صفًّا مخفيًّا */
    /** ما يعدّه سقفُ الباقة — يُفحص عند الاستعادة كما يُفحص عند الإنشاء */
    private const CAPPED = [
        'branch' => 'branches',
        'product' => 'products',
    ];

    private const CAP_LABELS = [
        'branches' => 'الفروع',
        'products' => 'المنتجات',
    ];

    public function restore(Request $request, int $id)
    {
        $type = self::typeOf($request);
        $row = self::findTrashed($type, $id);

        /*
         * والرمزُ المخفيّ يُفرَج عنه عند الحذف — فقد يكون شُغل.
         *
         * قيدُ التفرّد في نموذج المنتج يتجاوز المحذوف عمدًا: صنفٌ حُذف لا
         * يحجز رمزه ولا باركوده. فيُحذف «أ» ويُضاف «ب» بالباركود نفسه —
         * وكلاهما صحيح. ثمّ تُضغط «تراجع» فيعود «أ» حيًّا: صنفان حيّان
         * بباركودٍ واحد، والماسح يختار أحدهما فيُخصم من صنفٍ ويبقى الآخر
         * على الرفّ. ويظهر الفرق في الجرد بلا سبب يُعرف.
         *
         * والردّ هنا أسلم من التغيير الصامت: تسميةُ رمزٍ جديد لمنتجٍ يعود
         * تكتب في بضاعة التاجر ما لم يطلبه.
         */
        if ($type === 'product' && ($clash = self::codeClash($row))) {
            return back()->with('toast', [
                'msg' => __('تعذّرت الاستعادة: :code صار لمنتج «:name» — غيّره أولًا.', $clash),
                'type' => 'danger',
            ]);
        }

        /*
         * والفرعُ كذلك: اسمُه قد يكون شُغل بعد حذفه.
         *
         * قيدُ التفرّد في `BranchController` يتجاوز المحذوف عمدًا — فرعٌ حُذف
         * لا يحجز اسمه. فيُحذف «صلالة» ويُفتح «صلالة» جديدٌ في مكانه، ثمّ
         * تُضغط «تراجع»: فرعان حيّان بالاسم نفسِه.
         *
         * وهو أسوأ من تكرارٍ في قائمة: الفرعُ يُعرَف باسمه في ترويسة الملفّ
         * وفي عمود «الفرع» وفي رسائل الجرد. فيقرأ التاجر تقريرَ «صلالة» ولا
         * يدري أيَّ صلالة يقرأ، وينسب بضاعةَ أحدهما إلى الآخر.
         *
         * والردُّ أسلم من تسميةٍ صامتة: كتابةُ اسمٍ لم يطلبه في فرعٍ يعود
         * تُخفي المشكلة ولا تحلّها.
         */
        if ($type === 'branch' && ($holder = self::nameClash($row))) {
            return back()->with('toast', [
                'msg' => __('تعذّرت الاستعادة: «:name» صار اسمًا لفرعٍ آخر — غيّره أولًا.', ['name' => $holder]),
                'type' => 'danger',
            ]);
        }

        /*
         * والسقف يُفحص قبل الإحياء.
         *
         * `PlanLimits` تُفرَض عند الإنشاء وحده، والاستعادة إنشاءٌ في أثرها:
         * يُحذف الفرع، ويُفتح غيرُه في مكانه، ثمّ تُضغط «تراجع» — فيصير
         * فرعان لباقةٍ بيعت بفرعٍ واحد. والحدُّ الذي يُلتفّ عليه بضغطتين ليس
         * حدًّا، وإنما وعدٌ يكسره أوّل من ينتبه إليه.
         *
         * والردّ في القناتين معًا: زرّ «تراجع» يعيش في شريط التنبيه، وهو لا
         * يعرض أخطاء النموذج — فرفضٌ في `withErrors` وحدها لا يراه أحد.
         */
        if ($capped = self::CAPPED[$type] ?? null) {
            $business = auth()->user()?->business;

            if (PlanLimits::reached($business, $capped)) {
                $refusal = __('بلغت حدّ باقة «:plan»: :cap من :label. رقِّ الباقة قبل الاستعادة.', [
                    'plan' => $business->plan->name,
                    'cap' => PlanLimits::cap($business, $capped),
                    'label' => __(self::CAP_LABELS[$capped]),
                ]);

                return back()
                    ->with('toast', ['msg' => $refusal, 'type' => 'danger'])
                    ->withErrors(['restore' => $refusal]);
            }
        }

        // المصروف يعود ومعه قيده في الدفتر — بمرجعه نفسه لا بمرجعٍ جديد
        if ($type === 'expense') {
            return $this->restoreExpense($request, $row);
        }

        $row->restore();

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
        self::gate();
        $type = self::typeOf($request);

        // لا مسار محوٍ للفرع أصلًا، والحارس هنا كي لا يُفتح واحدٌ سهوًا
        abort_unless(in_array($type, self::PURGEABLE, true), 404);

        $row = self::findTrashed($type, $id);
        $label = self::label($type, $row);

        try {
            self::purgeRow($type, $row);
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

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
        // المصروفُ يُسأل عنه تحت قفل — انظر `purgeExpense`
        if ($type === 'expense') {
            self::purgeExpense($row);

            return;
        }

        /*
         * القيمة الخام لا المقروءة: `Product::getImageAttribute` يردّ رابطًا
         * جاهزًا للعرض (‎/storage/…‎، أو رابط صورةٍ بديلة إن لم تكن هناك صورة).
         * وتمرير الرابط إلى `delete` لا يجد شيئًا ولا يشتكي — فيُمحى الصفّ
         * ويبقى الملفّ. عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
         */
        $files = match ($type) {
            /*
             * ومنذ صار للمنتج معرضٌ لا صورةً واحدة، لم يعد العمود وحده كافيًا:
             * صفوفُ `product_images` يأخذها قيدُ المفتاح عند المحو، وملفّاتُها
             * لا يأخذها شيء — فتبقى على القرص بلا صفٍّ يشير إليها.
             */
            'product' => ProductImages::files($row),
            default => [],
        };

        foreach ($files as $file) {
            // رابطٌ خارجيّ لا ملفّ على قرصنا — لا يُمَسّ
            if ($file && ! str_starts_with($file, 'http')) {
                Storage::disk('public')->delete($file);
            }
        }

        /*
         * رصيد المنتج في كل فرع يذهب معه. لا قيد مفتاحٍ يتكفّل بذلك —
         * `branch_stocks.product_id` عمودٌ عاديّ — فالصفوف تبقى تحمل كمّياتٍ
         * لمنتجٍ لا وجود له، ويحسبها تقرير «قيمة المخزون» في مجموعه.
         */
        if ($type === 'product') {
            BranchStock::where('product_id', $row->id)->delete();
        }

        $row->forceDelete();
    }

    /**
     * محوُ مصروفٍ نهائيًّا — يُسأل عنه تحت قفل، لا على صفٍّ قُرئ قبله.
     *
     * ═══ ومصروفٌ كُتب له قيدٌ لا يُمحى نهائيًّا ═══
     *
     * كان المحوُ يمحو صفَّ المصروف وحركتَه ومرفقَه، ويترك في الدفتر قيدًا
     * وعكسَه يشيران إلى مستندٍ لا وجود له: مبلغٌ خرج ثمّ رُدّ ولا ورقةَ
     * تقول ما كان ولا لمن. والأمرُ المجدول يفعل ذلك وحده بعد ٩٠ يومًا،
     * فتضيع أدلّةُ شهرٍ كاملٍ بلا أن يضغط أحدٌ شيئًا.
     *
     * فما له قيدٌ — حيٌّ أو معكوس، عليه أو على حركته أو على المخزون —
     * يبقى محذوفًا ظاهرًا في «المحذوفات» بمرفقه وحركته. وما لم يُقيَّد قطّ
     * (فاتورةٌ لم تُدفع) يُمحى كما كان، وحركتُه (إن وُجدت) معه.
     *
     * ═══ والسؤالُ داخل المعاملة ═══
     *
     * استعادةٌ تقع بين السؤال والمحو تُحيي المصروف وقد تكتب له قيدًا، ثمّ
     * يمحوه هذا بمعرّفه: مصروفٌ حيٌّ يُمحى وقيدُه يشير إلى لا شيء. والمرفقُ
     * على القرص الخاصّ يُمحى بعد المعاملة لا قبلها — معاملةٌ تسقط لا تُعيد ملفًّا.
     *
     * @throws DomainException
     */
    private static function purgeExpense(Expense $row): void
    {
        $file = DB::transaction(function () use ($row) {
            $expense = Expense::onlyTrashed()->where('business_id', $row->business_id)->lockForUpdate()->find($row->id)
                ?? throw new DomainException(__('لم يعد هذا المصروف في المحذوفات — استُعيد قبل لحظة.'));

            if (Books::expenseHasHistory($expense)) {
                throw new DomainException(__('لا يُمحى هذا المصروف نهائيًّا: كُتب له قيدٌ في الدفتر، فيبقى في المحذوفات دليلًا محاسبيًّا بمرفقه وحركته.'));
            }

            $expense->transaction()->withTrashed()->forceDelete();
            $expense->forceDelete();

            return $expense->getRawOriginal('attachment');
        });

        // رابطٌ خارجيّ لا ملفّ على قرصنا — لا يُمَسّ
        if ($file && ! str_starts_with($file, 'http')) {
            Storage::disk('local')->delete($file);
        }
    }

    /**
     * استعادةُ مصروف — الصفُّ وحركتُه وقيدُه معًا أو لا شيء.
     *
     * ═══ كانت ثلاث خطواتٍ بلا معاملة ═══
     *
     * إحياءُ المصروف، ثمّ حركتِه، ثمّ قيده. فإن سقط القيدُ عاد مصروفٌ ظاهرٌ
     * لا يعدّه الدفتر. وضغطتان على «استعادة» كانتا تجدان المصروفَ بلا قيدٍ
     * حيٍّ كلتاهما فتُرحّلان قيدين. فالصفُّ يُقرأ تحت قفل، ويُسأل ثانيةً
     * أهو محذوف — والثانيةُ لا تجده فتُردّ ٤٠٤.
     *
     * ═══ وتاريخُ القيد العائد: يومُ حذفه لا يومُ المصروف ═══
     *
     * كان القيدُ يعود بتاريخ المصروف، والعكسُ بيوم الحذف: مصروفُ سبتمبر
     * يُحذف في أكتوبر ثمّ يُستعاد فيتضاعف سبتمبر ويبقى أكتوبر سالبًا. فالقيدُ
     * يعود بتاريخ عكسه فيتقابلان في فترةٍ واحدة — وإن عبر ذلك حدَّ شهرٍ
     * سُئل التاجرُ صراحةً (`BooksPeriod::resolve`).
     */
    private function restoreExpense(Request $request, Expense $row)
    {
        $data = $request->validate([
            'correction' => ['nullable', 'string', Rule::in(BooksPeriod::MODES)],
            'correction_reason' => ['nullable', 'string', 'max:'.BooksPeriod::REASON_MAX],
        ]);
        $bid = (int) $row->business_id;

        try {
            $why = DB::transaction(function () use ($bid, $row, $data) {
                $expense = Expense::onlyTrashed()->where('business_id', $bid)->lockForUpdate()->findOrFail($row->id);

                $why = __('استعادة المصروف');
                $reversal = $expense->isPaid() ? Books::lastExpenseReversalDate($expense) : null;
                $at = null;

                /*
                 * وهالكُ مخزونٍ عُكس قيدُه لا يُعاد من هنا.
                 *
                 * قيدُه مدين مصروفاتٌ أخرى ودائن المخزون، و`recordExpense` يبني
                 * القيدَ من النوع والوسيلة — فيعود الهالكُ مدفوعًا من الصندوق لا
                 * منقوصًا من المخزون. وما لم يُعكس (قيدُه على حركة المخزون حيّ)
                 * يعود صفُّه وحده، ولا يُكتب له شيء (`Books::postedElsewhere`).
                 */
                if ($expense->method === StockLosses::EXPENSE_METHOD && $reversal) {
                    throw ValidationException::withMessages(['expense' => __(
                        'هذا هالكُ مخزونٍ عُكس قيدُه في الدفتر، ولا يُعاد من هنا: قيدُه على المخزون لا على الصندوق. وإن كان صحيحًا فسجّله من جديد من المخزون.',
                    )]);
                }

                if ($reversal) {
                    $at = BooksPeriod::resolve($bid, $reversal, $data['correction'] ?? null, $data['correction_reason'] ?? null, auth()->user());
                    $why = BooksPeriod::reason($why, $reversal, $at, $data['correction_reason'] ?? null);
                }

                $expense->restore();
                $expense->transaction()->withTrashed()->restore();

                // وقيدُه المزدوج يُعاد بناؤه: عُكس مع الحذف فلا يُستعاد بالإحياء
                if ($expense->isPaid()) {
                    Books::recordExpense($expense, $at ?? ($reversal ? BooksPeriod::openOr($bid, $reversal) : null), auth()->id(), $why);
                }

                return $why;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())
                ->with('toast', ['msg' => collect($e->errors())->flatten()->first(), 'type' => 'danger']);
        } catch (ModelNotFoundException $e) {
            abort(404);
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['msg' => __('تعذّرت استعادة المصروف — لم يتغيّر شيء.'), 'type' => 'danger']);
        }

        $label = self::label('expense', $row);

        // والسطرُ عمودٌ بـ255 حرفًا — يُقطع هنا لا يُسقط على PostgreSQL بعد أن تمّت الاستعادة
        Activity::log('restore', mb_substr('استعاد المصروف: '.$label.($why !== __('استعادة المصروف') ? ' — '.$why : ''), 0, 255),
            ['subject_id' => $row->id, 'subject_type' => 'expense']);

        return back()->with('toast', [
            'msg' => __('تمت الاستعادة: :name', ['name' => $label]),
            'type' => 'success',
        ]);
    }
}
