<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\Demo;
use App\Support\ExpenseScope;
use App\Support\ListFilters;
use App\Support\Lists\ExpensesList;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\ReportingPeriod;
use App\Support\Sort;
use App\Support\StockLosses;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
    private const SORTS = ExpensesList::SORTS;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request)
    {
        $bid = $this->bid();

        /*
         * الشاشة شهريّة: المصروف يُقرأ بالشهر لا بالعمر كلّه.
         *
         * «كم أنفقتُ هذا الشهر؟» سؤالٌ يُسأل كلّ شهر، وقائمةٌ تعرض ثلاث سنوات
         * دفعةً واحدة لا تجيبه. و«كل الشهور» تبقى خيارًا لمن يبحث عن فاتورةٍ
         * قديمة بعينها.
         */
        $period = ListFilters::expensePeriod($request);
        $bounded = $period->start !== null || $period->end !== null;
        // `Y-m` لشهرٍ واحد — يقرؤه منتقي الشهر القديم؛ وفارغٌ لغيره
        $month = (string) ExpensesList::month($request);

        // مجموع الفترة يُحسب على الفترة كلّها لا على صفحتها: الترقيم يقصّ الصفوف
        // ولا يقصّ السؤال — «كم أنفقتُ هذا الشهر؟» جوابُه واحدٌ مهما تصفّحت
        $base = $period->bound(Expense::where('business_id', $bid), 'spent_at', date: true);

        // الاستعلامُ نفسُه الذي تقرؤه ملفّاتُ التصدير الثلاثة — انظر `ExpensesList`
        $q = ExpensesList::query($request);

        $expenses = $q->with(['branch:id,name', 'allocations.branch:id,name'])
            ->paginate(Pagination::perPage($request, 10))->withQueryString();

        $mayRead = (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW);

        /*
         * وتاريخُ قيد كلّ مصروفٍ حيّ — سؤالٌ واحدٌ للصفحة كلّها.
         *
         * به تعرف الشاشةُ قبل الضغط أنّ حذفَ مصروفٍ أو تغييرَ نطاقه يعبر حدَّ
         * شهر، فتسأل أين يُسجَّل التصحيح بدل أن يُردّ الطلب. وهو تاريخُ القيد
         * لا `spent_at`: المصروفُ المستعاد قيدُه بيوم حذفه — انظر `BooksPeriod`.
         */
        $entryDates = DB::table('journal_entries')
            ->where('business_id', $bid)
            ->where('sourceable_type', Expense::class)
            ->whereIn('sourceable_id', collect($expenses->items())->pluck('id'))
            ->whereNull('reversed_at')->whereNull('reverses_id')
            ->selectRaw('sourceable_id, MIN(entry_date) as d')->groupBy('sourceable_id')
            ->pluck('d', 'sourceable_id');
        $books = BooksPeriod::context($bid, auth()->user());

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
                // ونطاقُه — يقرؤه زرُّ «نطاق المصروف» ليُفتح على ما هو عليه
                'scope' => ExpenseScope::of($e),
                'branch_id' => $e->branch_id,
                'branch' => $e->branch?->name,
                'allocations' => $e->allocations->map(fn ($a) => [
                    'branch_id' => (int) $a->branch_id,
                    'branch' => $a->branch?->name,
                    'amount' => (float) $a->amount,
                ])->values()->all(),
                'correction' => BooksPeriod::describe($books,
                    isset($entryDates[$e->id]) ? Carbon::parse($entryDates[$e->id]) : null),
            ])->all(),
            // فروعُ المتجر — خياراتُ «فرع محدّد» و«موزَّع على عدة فروع»
            'branches' => Branch::where('business_id', $bid)->orderBy('id')
                ->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'pagination' => Pagination::meta($expenses),
            'types' => Demo::expenseTypes(),
            // خيارات الحساب — مصدرها واحد مع ما يقبله التحقّق
            'accountOptions' => Books::expenseAccountOptions(),
            'filters' => $request->only('q', 'type', 'status', 'tab') + $period->params()
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            // الشهر المعروض ومجموعه — ما بعد الترقيم لا يُجمع في المتصفح
            'month' => $month,
            // والفترةُ كما تقرؤها الواجهة — شهرٌ أو نطاقٌ أو سنةٌ أو الكلّ
            // بلا أزرارٍ سريعة: الشاشةُ شهريّة، و«هذا الشهر» افتراضيُّها
            'period' => $period->screen($bid, ['presets' => []] + ReportingPeriod::EVERYTHING),
            'monthTotal' => $bounded ? (float) (clone $base)->paid()->sum('amount') : null,
            'monthUnpaid' => $bounded ? (float) (clone $base)->unpaid()->sum('amount') : null,
            'monthCount' => $bounded ? (clone $base)->count() : null,
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

        /*
         * ونطاقُه — للنشاط كلِّه افتراضًا، أو لفرع، أو موزَّعٌ على فروع.
         *
         * يُقرأ قبل رفع المرفق: نطاقٌ مرفوض لا يترك ملفًّا على القرص بلا صفّ.
         */
        $scope = ExpenseScope::read($bid, $request->all(), (float) $data['amount']);

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
        $data['branch_id'] = $scope['branch_id'];

        try {
            $expense = DB::transaction(function () use ($data, $scope) {
                $expense = Expense::create($data);
                ExpenseScope::apply($expense, $scope);

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
            // فرعُ المصروف المباشر — والموزَّعُ دفعةٌ واحدة للنشاط (`ExpenseScope::journalBranch`)
            'branch_id' => ExpenseScope::journalBranch($expense),
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
    private function postingFailed(Throwable $e, ?string $fallback = null): string
    {
        Activity::log('updated', 'تعذّر ترحيل قيد مصروف: '.$e->getMessage(), [
            'subject_type' => 'expense',
        ]);

        return $e instanceof \RuntimeException
            ? $e->getMessage()
            : ($fallback ?? __('تعذّر ترحيل المصروف إلى الدفتر — لم يُسجَّل شيء.'));
    }

    /**
     * تسديد فاتورة مستحقّة.
     *
     * لحظةُ خروج المال هي لحظة قيده: قبلها الفاتورة التزامٌ عليك، وبعدها
     * نقدٌ نقص. ولا تعديل للمصروفات بعدُ، فبدون هذا الزرّ تبقى «غير مدفوع»
     * إلى الأبد.
     */
    /**
     * نطاقُ مصروفٍ قائم — لمن يُحسب في ربح الفروع، لا مبلغُه ولا تاريخُه.
     *
     * لم يكن للمصروف بابُ تعديل، وكلُّ ما سُجّل قبل الفروع «للنشاط كلِّه».
     * فهذا يُعيد نسبتَه وحدها: إلى فرع، أو موزَّعًا، أو إلى النشاط. والمبلغُ
     * والتاريخ والحالة لا تُمسّ من هنا.
     *
     * ═══ وقيدُ الدفتر يتبع فرعَه — بالعكس لا بالتعديل ═══
     *
     * قيدُ المصروف المباشر يحمل فرعَه (`ExpenseScope::journalBranch`). فإن
     * تبدّل فرعُ القيد عُكس القيدُ ورُحّل من جديد — طريقةُ الدفتر نفسُها في
     * الحذف والاستعادة — فلا يُعدَّل قيدٌ مرحَّل، وصافي أثره صفرٌ مع الجديد.
     * وما رحّلته حركةٌ من شاشة المالية قيدُه على حركتها لا على المصروف:
     * يقول من أيّ صندوقٍ خرج المال، فلا يُمسّ.
     */
    public function updateScope(Request $request, $id)
    {
        $bid = $this->bid();
        $expense = Expense::where('business_id', $bid)->findOrFail($id);

        $scope = ExpenseScope::read($bid, $request->all(), (float) $expense->amount);
        $correction = $this->correction($request);

        try {
            DB::transaction(function () use ($bid, $id, $scope, $correction) {
                /*
                 * ويُقرأ المصروفُ ثانيةً تحت قفل، وفرعُ قيده معه.
                 *
                 * كان «قبل» يُقرأ خارج المعاملة: طلبان على النطاق نفسه يقرآن
                 * الفرعَ القديم كلاهما، فيعكس الثاني قيدًا عكسه الأوّل ويُرحّل
                 * فوقه — قيدان حيّان لمصروفٍ واحد.
                 */
                $expense = Expense::where('business_id', $bid)->lockForUpdate()->findOrFail($id);
                $before = ExpenseScope::journalBranch($expense);

                ExpenseScope::apply($expense, $scope);
                $expense->refresh();

                $after = ExpenseScope::journalBranch($expense);
                $entryDate = Books::expenseEntryDate($expense);

                if ($entryDate && $before !== $after) {
                    /*
                     * والعكسُ وإعادةُ الترحيل بتاريخٍ واحد.
                     *
                     * كانت إعادةُ الترحيل بتاريخ المصروف والعكسُ بيوم التغيير:
                     * مصروفُ سبتمبر يُعاد نطاقُه في أكتوبر فيُطرح من أكتوبر
                     * ويُضاف إلى سبتمبر ثانيةً. فالشهرُ نفسُه إن لم يُعبَر حدّ،
                     * وإلّا فما يختاره التاجرُ صراحةً — انظر `BooksPeriod`.
                     */
                    $at = BooksPeriod::resolve($bid, $entryDate, $correction['mode'], $correction['reason'], auth()->user());
                    $why = BooksPeriod::reason(__('تغيير نطاق المصروف'), $entryDate, $at, $correction['reason']);

                    Books::unpostExpense($expense, auth()->id(), $why, $at);
                    Books::recordExpense($expense, $at ?? BooksPeriod::openOr($bid, $entryDate), auth()->id(), $why);
                }

                // والحركةُ التي أنشأها المصروفُ نفسُه تتبعه — لا حركةُ المالية
                if ($entryDate && $expense->transaction_id) {
                    Transaction::whereKey($expense->transaction_id)->update(['branch_id' => $after]);
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            abort(404);
        } catch (Throwable $e) {
            return back()->withErrors(['scope' => $this->postingFailed($e)]);
        }

        Activity::log('updated', 'غيّر نطاق المصروف: '.$expense->reference, ['subject_id' => $expense->id]);

        return back()->with('toast', ['msg' => __('حُفظ نطاق المصروف'), 'type' => 'success']);
    }

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
            $paid = DB::transaction(function () use ($expense) {
                /*
                 * ويُسأل ثانيةً تحت قفل — «أهي مسدَّدة؟» أعلاه تُسأل بلا قفل.
                 *
                 * ثلاثُ ضغطاتٍ متزامنة على PostgreSQL وجدتها غيرَ مسدَّدة كلُّها،
                 * فكتبت كلٌّ حركتَها: قيدٌ واحد في الدفتر، وثلاثُ حركاتٍ في الصندوق.
                 */
                $locked = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

                if ($locked->isPaid()) {
                    return false;
                }

                $locked->update(['status' => Expense::PAID, 'spent_at' => now()]);
                $this->postToLedger($locked->fresh());

                return true;
            });
        } catch (ModelNotFoundException $e) {
            // حُذفت بين قراءتها والقفل — لا تُسدَّد فاتورةٌ في المحذوفات
            abort(404);
        } catch (Throwable $e) {
            return back()->withErrors(['pay' => $this->postingFailed($e)]);
        }

        if (! $paid) {
            return back()->with('toast', ['msg' => __('الفاتورة مسدَّدة أصلًا'), 'type' => 'info']);
        }

        Activity::log('updated', 'سدّد المصروف: '.$expense->reference, ['subject_id' => $expense->id]);

        return back()->with('toast', ['msg' => __('سُجّل السداد'), 'type' => 'success']);
    }

    public function destroy(Request $request, $id)
    {
        $bid = $this->bid();
        $correction = $this->correction($request);

        /*
         * ═══ أربعُ خطواتٍ تقع معًا أو لا يقع منها شيء ═══
         *
         * حذفُ حركة المالية، وعكسُ القيد، وحذفُ المصروف — كانت متتاليةً خارج
         * معاملة، وكلُّ عكسٍ في معاملته وحده. فإن سقط العكسُ بعد حذف الحركة
         * اختفى المالُ من شاشة المالية وبقي في الدفتر، وإن سقط حذفُ المصروف
         * بعد العكس بقي مصروفٌ ظاهرٌ لا يعدّه الدفتر. وكلاهما لا يظهر إلّا
         * لمن يطابق الشاشتين.
         *
         * ويُقرأ المصروفُ تحت قفل: ضغطتان على «حذف» لا تمرّان كلتاهما — الثانية
         * تنتظر الأولى ثمّ لا تجد مصروفًا حيًّا فتُردّ ٤٠٤. وسجلُّ النشاط بعد
         * نجاح المعاملة، كي لا يقول «حُذف» عن شيءٍ لم يُحذف.
         */
        try {
            [$why, $at] = DB::transaction(function () use ($bid, $id, $correction) {
                $expense = Expense::where('business_id', $bid)->lockForUpdate()->findOrFail($id);

                /*
                 * ومصروفٌ وُلد من حركةٍ في شاشة المالية لا يُحذف من هنا.
                 *
                 * قيدُه على حركته لا عليه (`Books::recordMovement`)، فلا يجد
                 * `unpostExpense` ما يعكسه: كان الحذفُ يُخفي المصروفَ والحركةَ
                 * ويترك قيدَهما حيًّا — مالٌ يقرؤه الدفترُ خارجًا ولا تقرؤه
                 * شاشة. والردُّ أسلمُ من محوٍ نصفيّ.
                 *
                 * ولا يُدَلّ على بابٍ لا يُفتح: شاشةُ القيود لا تعكس إلّا اليدويّ،
                 * ولا حذفَ لحركة المالية بعدُ — فالرسالةُ تقول ما هو، لا ما لا يُفعل.
                 */
                if ($this->bornOfAMovement($expense)) {
                    throw ValidationException::withMessages(['expense' => __(
                        'هذا المصروف سُجّل من حركةٍ في شاشة المالية، وقيدُه على الحركة لا عليه — فلا يُحذف من هنا، كي لا يبقى قيدُه حيًّا بلا مصروف.',
                    )]);
                }

                /*
                 * وكذلك صفُّ هالك المخزون: قيدُه على حركة المخزون (مدين مصروفاتٌ
                 * أخرى، دائن المخزون)، والصفُّ هنا وجهُه في الشاشة. حذفُه من هنا
                 * يُخفي الهالكَ ويُبقيه في الدفتر، واستعادتُه كانت تُعيده نقدًا
                 * من الصندوق (`Books::postedElsewhere`).
                 */
                if ($expense->method === StockLosses::EXPENSE_METHOD) {
                    throw ValidationException::withMessages(['expense' => __(
                        'هذا هالكُ مخزونٍ قُيّد من الجرد، وقيدُه على المخزون لا على الصندوق — فلا يُحذف من شاشة المصروفات، كي لا يختفي من هنا ويبقى في الدفتر.',
                    )]);
                }

                $why = __('حذف المصروف');
                $at = null;

                if ($entryDate = Books::expenseEntryDate($expense)) {
                    $at = BooksPeriod::resolve($bid, $entryDate, $correction['mode'], $correction['reason'], auth()->user());
                    $why = BooksPeriod::reason($why, $entryDate, $at, $correction['reason']);
                }

                /*
                 * القيد يتبع مصروفه.
                 *
                 * كان الحذف يُخفي المصروف ويترك سطره في دفتر المالية: تقرأ
                 * المصروفات فترى صفرًا، وتقرأ المالية فترى ٣٠٠. والقيد اليتيم يدخل
                 * المطابقة البنكية كأنّ مبلغًا خرج.
                 */
                $expense->transaction()->delete();
                // وقيدُ الدفتر يُعكس لا يُمحى: أثرُه صفرٌ في الرصيد، وتاريخُه مقروء
                Books::unpostExpense($expense, auth()->id(), $why, $at);

                /*
                 * المرفق يبقى مع المصروف المحذوف.
                 *
                 * صار الحذف ناعمًا يُستدرَك من «المحذوفات»، ومصروفٌ يعود بلا فاتورته
                 * نصفُ استعادة: القيد يظهر في التقرير ولا شيء يُقدَّم للمحاسب.
                 * والملفات تُنظَّف مع المسح النهائي لا مع الإخفاء.
                 */
                $expense->delete();

                return [$why, $at];
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())
                ->with('toast', ['msg' => collect($e->errors())->flatten()->first(), 'type' => 'danger']);
        } catch (ModelNotFoundException $e) {
            abort(404);
        } catch (Throwable $e) {
            return back()->with('toast', ['msg' => $this->postingFailed($e, __('تعذّر حذف المصروف — لم يتغيّر شيء.')), 'type' => 'danger']);
        }

        $expense = Expense::withTrashed()->where('business_id', $bid)->findOrFail($id);
        // والسطرُ عمودٌ بـ255 حرفًا — يُقصَّر صدرُه ويبقى السببُ، ولا يسقط على PostgreSQL بعد أن تمّ الحذف
        Activity::log('deleted', BooksPeriod::fit('حذف المصروف: '.$expense->reference, $why !== __('حذف المصروف') ? $why : null),
            ['subject_id' => $expense->id, 'subject_type' => 'expense']);

        return back()->with('toast', [
            'msg' => __('تم حذف المصروف'),
            'type' => 'warning',
            /*
             * و«تراجع» لا يُعرض لعكسٍ وقع في شهرٍ مضى: الإشعارُ يُرسل الاستعادةَ
             * بلا اختيارٍ ولا سبب، والخادمُ يردّها حينئذٍ دائمًا. فالاستعادةُ من
             * «المحذوفات» حيث يُسأل عنهما.
             */
            'undo' => $at && BooksPeriod::crosses($at) ? null
                : ['url' => route('admin.expenses.restore', $expense->id), 'label' => $expense->reference ?: $expense->type],
        ]);
    }

    /**
     * اختيارُ تصحيح الفترة السابقة وسببُه — يُرسَلان حين يعبر التصحيحُ حدَّ شهر.
     *
     * @return array{mode: ?string, reason: ?string}
     */
    private function correction(Request $request): array
    {
        $data = $request->validate([
            'correction' => ['nullable', 'string', Rule::in(BooksPeriod::MODES)],
            'correction_reason' => ['nullable', 'string', 'max:'.BooksPeriod::REASON_MAX],
        ]);

        return ['mode' => $data['correction'] ?? null, 'reason' => $data['correction_reason'] ?? null];
    }

    /** هل قيدُ هذا المصروف على حركته في شاشة المالية لا عليه؟ */
    private function bornOfAMovement(Expense $expense): bool
    {
        return Books::liveEntriesFor($expense)->isEmpty() && Books::movementEntryIsLive($expense);
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
