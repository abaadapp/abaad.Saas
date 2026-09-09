<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Books;
use App\Support\Demo;
use App\Support\ListFilters;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

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

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

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
        // القاعدة نفسها التي يقرأ بها الملفّ — انظر App\Support\ListFilters
        $span = ListFilters::expenseSpan($request);
        $month = $span ? $span[0]->format('Y-m') : '';

        if ($span) {
            $q->whereBetween('spent_at', $span);
        }

        // مجموع الشهر يُحسب على الشهر كلّه لا على صفحته: الترقيم يقصّ الصفوف
        // ولا يقصّ السؤال — «كم أنفقتُ هذا الشهر؟» جوابُه واحدٌ مهما تصفّحت
        $base = Expense::where('business_id', $bid)
            ->when($span, fn ($w) => $w->whereBetween('spent_at', $span));

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('reference', $like, "%{$s}%")
                ->orWhere('description', $like, "%{$s}%")
                ->orWhere('type', $like, "%{$s}%"));
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('spent_at')->orderByDesc('id'));

        $expenses = $q->paginate(Pagination::perPage($request, 10))->withQueryString();

        $mayRead = (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW);

        return Inertia::render('Admin/Expenses/Index', [
            'expenses' => collect($expenses->items())->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'due_date' => optional($e->due_date)->format('Y-m-d'),
                'type' => $e->type,
                'amount' => (float) $e->amount,
                'status' => $e->status,
                /*
                 * ورابطُ المرفق بابٌ يسأل — لا مسارٌ على القرص العامّ.
                 *
                 * ومن لا يملك الفعل لا يُبنى له رابط: الرابطُ يُرسم أيقونةً
                 * في الجدول، وأيقونةٌ تُردّ عند ضغطها أسوأ من غيابها.
                 */
                'attachment' => $e->attachment && $mayRead
                    ? route('admin.expenses.attachment', $e->id)
                    : null,
                'attachment_name' => $e->attachment_name,
                'description' => $e->description,
            ])->all(),
            'pagination' => Pagination::meta($expenses),
            'types' => Demo::expenseTypes(),
            // خيارات الحساب — مصدرها واحد مع ما يقبله التحقّق
            'accountOptions' => Books::expenseAccountOptions(),
            'filters' => $request->only('q', 'type', 'status', 'tab') + ['month' => $month]
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
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
            'amount' => ['required', 'numeric', 'min:0'],
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
            // القرصُ الخاصّ: فاتورةُ مصروفٍ تقول كم يدفع المتجر ولمن
            $attachment = $file->store("expenses/{$bid}", 'local');
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
         * المصروف يظهر في دفتر المالية أيضًا — إن كان قد دُفع.
         *
         * كان لكلّ منهما جدولُه: مصروفٌ من هذه الشاشة لا يُرى في المالية،
         * ومصروفٌ من المالية لا ينقص الربح. فصار المصدر واحدًا والدفتر
         * يعرضهما معًا — والربح يُقرأ من جدول المصروفات كما كان، فلا يُعدّ
         * المبلغ مرّتين.
         *
         * والقيد يوم خروج المال لا يوم تسجيل الورقة: فاتورةٌ سُجّلت اليوم
         * وتُدفع بعد أسبوع ليست نقدًا خرج من الدرج.
         *
         * والثلاثةُ معًا أو لا شيء — انظر `postToLedger`.
         */
        try {
            $expense = DB::transaction(function () use ($data) {
                $expense = Expense::create($data);

                if ($expense->isPaid()) {
                    $this->postToLedger($expense);
                }

                return $expense;
            });
        } catch (Throwable $e) {
            // ومرفقٌ رُفع قبل المعاملة لا يبقى على القرص بلا صفٍّ يشير إليه
            if ($attachment) {
                Storage::disk('local')->delete($attachment);
            }

            throw ValidationException::withMessages(['amount' => $this->postingFailed($e)]);
        }

        Activity::log('created', 'سجّل مصروف '.$data['type'].' بقيمة '.$data['amount']);

        return redirect()->route('admin.expenses.index')->with('toast', ['msg' => __('تم تسجيل المصروف بنجاح'), 'type' => 'success']);
    }

    /**
     * يكتب قيد الدفتر المقابل ويربطه بالمصروف.
     *
     * ═══ الثلاثةُ معًا أو لا شيء ═══
     *
     * كان القيد يُكتب خارج معاملة، وفشلُه يُبتلع ويُقيَّد في سجلّ النشاط:
     *
     *     try { Books::recordExpense(...); } catch (Throwable) { Activity::log(...); }
     *
     * فيبقى في القاعدة مصروفٌ يقول «مدفوع»، وصفُّ حركةٍ يقول إنّ مالًا خرج،
     * **ولا قيدَ في الدفتر**. فتقرأ شاشةُ المصروفات ثلاثمئة، ويقرأ ميزانُ
     * المراجعة صفرًا، ويُقرأ ربحُ الشهر أعلى ممّا هو. ولا يظهر ذلك إلّا
     * لمن يطابق الدفترَ بالشاشة — وهو آخرُ من يُطابق.
     *
     * ولا تُبتلع الاستثناءات هنا: تصعد فتُلغي المعاملةَ كلَّها، فلا يُكتب
     * مصروفٌ لا يستطيع الدفترُ حملَه. والرسالةُ تصل من كتبه لا سجلًّا لا
     * يفتحه أحد.
     */
    private function postToLedger(Expense $expense): void
    {
        $transaction = Transaction::create([
            'business_id' => $expense->business_id,
            'reference' => Transaction::nextReference($expense->business_id),
            // الوصف اختياريّ فقد يغيب عن الطلب أصلًا — لا يكفي أن يكون nullable
            'description' => $expense->type.(($expense->description ?? '') !== '' ? ' — '.$expense->description : ''),
            'method' => $expense->method,
            'type' => 'مصروف',
            'amount' => $expense->amount,
            'employee_name' => $expense->employee_name,
            'occurred_at' => $expense->spent_at,
        ]);

        $expense->update(['transaction_id' => $transaction->id]);

        /*
         * وقيدٌ مزدوج معه.
         *
         * الصفّ أعلاه دفترُ صندوق: مبلغٌ ونوع. والدفتر المحاسبيّ يريد طرفين
         * — مصروفٌ مدين ونقدٌ دائن — وبدونهما يظهر في الشجرة إيرادٌ بلا ما
         * يقابله من مصروفات المحلّ، فيُقرأ ربحٌ لم يتحقّق.
         */
        Books::recordExpense($expense->fresh());
    }

    /**
     * رسالةٌ تُقرأ بدل نصّ استثناءٍ داخليّ.
     *
     * وسببُ الفشل يُقيَّد **خارج** المعاملة الساقطة: `Activity::log` داخلها
     * يسقط معها، فيضيع الخبرُ الذي من أجله كُتب.
     */
    private function postingFailed(Throwable $e): string
    {
        Activity::log('updated', 'تعذّر ترحيل قيد مصروف: '.$e->getMessage(), [
            'subject_type' => 'expense',
        ]);

        return $e instanceof \RuntimeException
            ? $e->getMessage()
            : __('تعذّر ترحيل المصروف إلى الدفتر — لم يُسجَّل شيء.');
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

        /*
         * والوسمُ والقيدُ في معاملةٍ واحدة: مصروفٌ يقول «مدفوع» بلا قيدٍ في
         * الدفتر هو العطبُ نفسُه الذي في `store`.
         */
        try {
            DB::transaction(function () use ($expense) {
                $expense->update(['status' => Expense::PAID, 'spent_at' => now()]);
                $this->postToLedger($expense->fresh());
            });
        } catch (Throwable $e) {
            return back()->withErrors(['pay' => $this->postingFailed($e)]);
        }

        Activity::log('updated', 'سدّد المصروف: '.$expense->reference, ['subject_id' => $expense->id]);

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
         * المطابقة البنكية كأنّ مبلغًا خرج.
         */
        $expense->transaction()->delete();
        // وقيدُ الدفتر يُعكس لا يُمحى: أثرُه صفرٌ في الرصيد، وتاريخُه مقروء
        Books::unpostExpense($expense, auth()->id(), __('حذف المصروف'));
        Activity::log('deleted', 'حذف المصروف: '.$expense->reference, ['subject_id' => $expense->id, 'subject_type' => 'expense']);

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

    /** توليد الرقم المرجعي التالي للنشاط */
    private function nextReference(int $bid): string
    {
        $last = Expense::where('business_id', $bid)->whereNotNull('reference')->orderByDesc('id')->value('reference');
        $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001;

        return 'EXP-'.$n;
    }
}
