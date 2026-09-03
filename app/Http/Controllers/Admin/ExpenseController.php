<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Support\Books;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpenseController extends Controller
{
    /**
     * ما يُرتَّب في قائمة المصروفات.
     *
     * والنوع نصٌّ في الصف نفسه فيُرتَّب، والمرفق لا — وجودُه من عدمه ليس
     * ترتيبًا.
     */
    private const SORTS = [
        'reference' => 'reference',
        'due_date' => 'due_date',
        'type' => 'type',
        'amount' => 'amount',
        'status' => 'status',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        $bid = $this->bid();
        $q = Expense::where('business_id', $bid);

        /*
         * الشاشة شهريّة: المصروف يُقرأ بالشهر لا بالعمر كلّه.
         *
         * «كم أنفقتُ هذا الشهر؟» سؤالٌ يُسأل كلّ شهر، وقائمةٌ تعرض ثلاث سنوات
         * دفعةً واحدة لا تجيبه — يُجمع منها بالعين فيُخطئ الجمع. و«كل الشهور»
         * تبقى خيارًا لمن يبحث عن فاتورةٍ قديمة بعينها.
         */
        $month = (string) $request->query('month', now()->format('Y-m'));
        $span = null;

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $first = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01');
            $span = [$first->copy()->startOfMonth(), $first->copy()->endOfMonth()];
            $q->whereBetween('spent_at', $span);
        } else {
            $month = '';
        }

        // مجموع الشهر يُحسب على الشهر كلّه لا على صفحته: الترقيم يقصّ الصفوف
        // ولا يقصّ السؤال — «كم أنفقتُ هذا الشهر؟» جوابُه واحدٌ مهما تصفّحت
        $base = Expense::where('business_id', $bid)
            ->when($span, fn ($w) => $w->whereBetween('spent_at', $span));

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhere('type', 'like', "%{$s}%"));
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('spent_at')->orderByDesc('id'));

        $expenses = $q->paginate((int) $request->query('per_page', 10))->withQueryString();

        return \Inertia\Inertia::render('Admin/Expenses/Index', [
            'expenses' => collect($expenses->items())->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'due_date' => optional($e->due_date)->format('Y-m-d'),
                'type' => $e->type,
                'amount' => (float) $e->amount,
                'status' => $e->status,
                // رابط المرفق يُبنى هنا؛ المسار وحده لا يفتحه المتصفح
                'attachment' => $e->attachment ? \Illuminate\Support\Facades\Storage::url($e->attachment) : null,
                'attachment_name' => $e->attachment_name,
                'description' => $e->description,
            ])->all(),
            'pagination' => \App\Support\Pagination::meta($expenses),
            'types' => Demo::expenseTypes(),
            /*
             * حسابات المصروفات — لمن يملك «المحاسبة المتقدّمة» وحده.
             *
             * الشاشة تُخفي عمود الحساب بصلاحيتها، لكنّ الإخفاء لا يمنع من يقرأ
             * حمولة الصفحة. والقائمة أوراقُ شجرة المتجر بأسمائها ورموزها —
             * وهي ما يُمنع منه من لم يُمنح القسم، فلا تُرسل إليه أصلًا.
             */
            'expenseAccounts' => $request->user()?->allows('accounting')
                ? $this->expenseAccounts($bid)
                : [],
            'filters' => $request->only('q', 'type', 'status', 'tab') + ['month' => $month]
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            // الشهر المعروض ومجموعه — ما بعد الترقيم لا يُجمع في المتصفح
            'month' => $month,
            'monthTotal' => $month ? (float) (clone $base)->paid()->sum('amount') : null,
            'monthUnpaid' => $month ? (float) (clone $base)->unpaid()->sum('amount') : null,
            'monthCount' => $month ? (clone $base)->count() : null,
            // الشهور التي فيها مصروفٌ فعلًا — قائمةٌ لا تعرض شهورًا فارغة
            'months' => $this->months($bid),
            // المدفوع وحده هو المصروف — والمستحقّ يُعرض إلى جانبه لا يختفي:
            // رقمٌ خرج من حسابٍ بلا أن يظهر في آخر يضيع
            'totalAmount' => (float) Expense::where('business_id', $bid)->paid()->sum('amount'),
            'totalCount' => Expense::where('business_id', $bid)->count(),
            'unpaidAmount' => (float) Expense::where('business_id', $bid)->unpaid()->sum('amount'),
            'unpaidCount' => Expense::where('business_id', $bid)->unpaid()->count(),
            // ما يستحقّ خلال أسبوع — ليُرى قبل أن يفوت لا بعده
            'dueSoonCount' => Expense::where('business_id', $bid)->unpaid()
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
                ->count(),
            'overdueCount' => Expense::where('business_id', $bid)->unpaid()
                ->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay())->count(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // حركةٌ بصفر لا تعني شيئًا — انظر Books::recordMovement
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['nullable', 'string', 'max:50'],
            'spent_at' => ['nullable', 'date'],
            // كان عمودًا يُعرض في الجدول ويُرشَّح به ولا سبيل لإدخاله: النموذج
            // لا يرسله والخادم لا يقبله، فيقول «—» إلى الأبد
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'attachment' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
        ], [
            'attachment.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachment.max' => __('أقصى حجم للمرفق 10 ميجابايت.'),
        ], ['attachment' => __('المرفق')]);

        // رفع المرفق (إن وُجد)
        $attachment = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachment = $file->store("expenses/{$bid}", 'public');
        }

        $data['business_id'] = $bid;
        $data['method'] = $data['method'] ?? 'نقدي';
        $data['status'] = $data['status'] ?? 'مدفوع';
        $data['spent_at'] = $data['spent_at'] ?? now();
        $data['employee_name'] = auth()->user()->name;
        $data['reference'] = $this->nextReference($bid);
        $data['attachment'] = $attachment;
        $data['attachment_name'] = $attachmentName;

        /*
         * المصروف يظهر في دفتر المالية وفي دفتر الأستاذ معًا — إن كان قد دُفع.
         *
         * كان لكلّ منهما جدولُه: مصروفٌ من هذه الشاشة لا يُرى في المالية،
         * ومصروفٌ من المالية لا ينقص الربح. فصار المصدر واحدًا والدفتر
         * يعرضهما معًا — والربح يُقرأ من جدول المصروفات كما كان، فلا يُعدّ
         * المبلغ مرّتين.
         *
         * وكانت `postToLedger` تُسمّى ترحيلًا ولا ترحّل: تكتب صفًّا في
         * `transactions` ولا تكتب قيدًا في `journal_entries` — فمصروفُ ثلاثمئة
         * يظهر في المالية ولا أثر له في ميزان المراجعة، والمحاسب يقرأ دفترًا
         * ينقصه كلُّ ما أنفقه المتجر. فصار الترحيل من باب `Books` وحده.
         *
         * والقيد يوم خروج المال لا يوم تسجيل الورقة: فاتورةٌ سُجّلت اليوم
         * وتُدفع بعد أسبوع ليست نقدًا خرج من الدرج.
         *
         * والكتابتان في معاملةٍ واحدة: مصروفٌ يُحفظ ثمّ يسقط ترحيلُه يترك
         * الدفترين مفترقين من جديد — وهو العطب نفسه بابًا آخر.
         */
        try {
            $expense = DB::transaction(function () use ($data) {
                $expense = Expense::create($data);

                if ($expense->isPaid()) {
                    Books::recordExpense($expense, auth()->id());
                }

                return $expense;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        \App\Support\Activity::log('created', 'سجّل مصروف ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.expenses.index')->with('toast', ['msg' => __('تم تسجيل المصروف بنجاح'), 'type' => 'success']);
    }

    /**
     * تسديد فاتورة مستحقّة.
     *
     * لحظةُ خروج المال هي لحظة قيده: قبلها الفاتورة التزامٌ عليك، وبعدها
     * نقدٌ نقص. ولا تعديل للمصروفات بعدُ، فبدون هذا الزرّ تبقى «غير مدفوع»
     * إلى الأبد.
     */
    public function markPaid($id)
    {
        $expense = Expense::where('business_id', $this->bid())->findOrFail($id);

        if ($expense->isPaid()) {
            return back()->with('toast', ['msg' => __('الفاتورة مسدَّدة أصلًا'), 'type' => 'info']);
        }

        try {
            DB::transaction(function () use ($expense) {
                $expense->update(['status' => Expense::PAID, 'spent_at' => now()]);
                Books::recordExpense($expense->fresh(), auth()->id());
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        \App\Support\Activity::log('updated', 'سدّد المصروف: '.$expense->reference, ['subject_id' => $expense->id]);

        return back()->with('toast', ['msg' => __('سُجّل السداد'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $expense = Expense::where('business_id', $this->bid())->findOrFail($id);

        /*
         * القيد يتبع مصروفه.
         *
         * كان الحذف يُخفي المصروف ويترك سطره في دفتر المالية: تقرأ
         * المصروفات فترى صفرًا، وتقرأ المالية فترى ٣٠٠. والقيد اليتيم يدخل
         * المطابقة البنكية كأنّ مبلغًا خرج. والدفتران يتبعانه معًا الآن:
         * الحركة وقيدُها في الأستاذ (انظر Books::unrecord).
         */
        Books::unrecord($expense->transaction);
        \App\Support\Activity::log('deleted', 'حذف المصروف: ' . $expense->reference, ['subject_id' => $expense->id, 'subject_type' => 'expense']);

        /*
         * المرفق يبقى مع المصروف المحذوف.
         *
         * صار الحذف ناعمًا يُستدرَك من «المحذوفات»، ومصروفٌ يعود بلا فاتورته
         * نصفُ استعادة: القيد يظهر في التقرير ولا شيء يُقدَّم للمحاسب.
         * والملفات تُنظَّف مع المسح النهائي لا مع الإخفاء.
         */
        $expense->delete();

        return back()->with('toast', [
            'msg' => __('تم حذف المصروف'),
            'type' => 'warning',
            'undo' => ['url' => route('admin.expenses.restore', $expense->id), 'label' => $expense->reference ?: $expense->type],
        ]);
    }

    /**
     * الشهور التي فيها مصروفٌ فعلًا — أحدثها أوّلًا، والجاري معها دائمًا.
     *
     * قائمةٌ تولَّد من التقويم تعرض شهورًا فارغة يفتحها التاجر فلا يجد شيئًا،
     * وقائمةٌ من البيانات وحدها تُسقط الشهر الجاري قبل أوّل مصروفٍ فيه —
     * فيفتح الشاشة في أوّل الشهر فلا يجد شهره.
     */
    private function months(int $bid): array
    {
        $found = Expense::where('business_id', $bid)->whereNotNull('spent_at')
            ->get(['spent_at'])->map(fn ($e) => $e->spent_at->format('Y-m'))->all();

        // collect() صراحةً: مجموعة Eloquent تبقى كذلك بعد map فيسقط unique
        // عليها بحثًا عن مفاتيح نماذج في مصفوفة نصوص
        return collect($found)->push(now()->format('Y-m'))
            ->unique()->sortDesc()->values()->all();
    }

    /**
     * أوراق المصروفات المفتوحة — ما يقبل قيدًا فعلًا.
     *
     * الآباء والمغلقة لا تُعرض: اختيارٌ يُردّ دائمًا لا يُعرض، وردُّه هنا
     * يقع يوم يُسجَّل المصروف لا يوم يُربط النوع.
     */
    private function expenseAccounts(int $bid): array
    {
        $parents = \App\Models\Account::where('business_id', $bid)->whereNotNull('parent_id')
            ->distinct()->pluck('parent_id')->all();

        return \App\Models\Account::where('business_id', $bid)->where('type', 'مصروف')
            ->where('active', true)->whereNotIn('id', $parents)->orderBy('code')
            ->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->code.' — '.$a->name])->all();
    }

    /** توليد الرقم المرجعي التالي للنشاط */
    private function nextReference(int $bid): string
    {
        $last = Expense::where('business_id', $bid)->whereNotNull('reference')->orderByDesc('id')->value('reference');
        $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001;

        return 'EXP-' . $n;
    }
}
