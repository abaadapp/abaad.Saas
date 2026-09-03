<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الحركة المالية — ما دخل وما خرج، وبابُ تسجيل ما لا مستند له.
 *
 * كانت هذه الشاشة مسارَ حفظٍ بلا شاشة: `POST /finance/transactions` مبنيًّا
 * ولا زرَّ يقصده في الواجهة كلّها. وكان يكتب صفًّا في `transactions` ولا
 * يكتب قيدًا في `journal_entries` — أي حركةً ماليةً لا يقابلها شيءٌ في دفتر
 * الأستاذ، وهو بالضبط ما تمنعه المحاسبة المزدوجة.
 *
 * وكان يسأل «دخل أم مصروف؟». وهما لا يكفيان: تحويلُ المال من الدرج إلى
 * البنك ليس دخلًا ولا مصروفًا، وسحبُ المالك ليس مصروفًا، و«دخل» تخلط بيعةَ
 * نقطة البيع بتعويضٍ من شركة تأمين فتُقرأ الثانية مبيعاتٍ في كلّ تقرير.
 *
 * فصار السؤال «ماذا حدث؟»، والجوابُ من قائمةٍ يفهمها من لا يعرف المحاسبة —
 * و`Books` تترجمه إلى القيد الصحيح. ولا يُسأل التاجر عن مدينٍ ولا دائن ولا
 * رقم حساب: تلك أسئلة «المحاسبة المتقدّمة» لمن يملكها.
 */
class FinanceController extends Controller
{
    /** ما يُرتَّب في جدول الحركة */
    private const SORTS = [
        'reference' => 'reference',
        'date' => 'occurred_at',
        'description' => 'description',
        'amount' => 'amount',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $q = Transaction::where('business_id', $bid)->with('order:id,status');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }
        if ($kind = $request->query('kind')) {
            $q->where('kind', $kind);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('occurred_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('occurred_at', '<=', $to);
        }

        /*
         * المجاميع تُحسب على المدة كلّها لا على صفحتها.
         *
         * والنسخة تُؤخذ قبل الترقيم: `paginate` تكتب حدَّ الصفحة في الاستعلام
         * نفسه، فجمعٌ بعدها يجمع عشرين صفًّا ويقول إنها المدة كلّها.
         *
         * والتحويل صنفٌ ثالث لا يُجمع مع الدخل: هو انتقالٌ بين جيبين لا مالٌ
         * دخل المتجر أو خرج منه، وجمعُه معه يُضخّم المقبوض بمالٍ قُبض مرّة.
         *
         * والفاتورة الملغاة تخرج من المجموع وتبقى في الجدول: مالٌ لم يُقبض
         * لا يُجمع، وسجلٌّ وقع لا يُمحى — انظر Transaction::scopeNotCancelled.
         */
        $totals = (clone $q)->reorder()->notCancelled()
            ->selectRaw('type, COALESCE(SUM(amount),0) total')->groupBy('type')
            ->pluck('total', 'type');

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('occurred_at')->orderByDesc('id'));

        $rows = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        return Inertia::render('Admin/Finance/Transactions', [
            'rows' => collect($rows->items())->map(fn ($t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'date' => optional($t->occurred_at)->format('Y-m-d H:i'),
                'description' => $t->description,
                'method' => $t->method,
                'type' => $t->type,
                'kind' => $t->kind,
                'kind_label' => Books::label($t->kind),
                'amount' => (float) $t->amount,
                'employee' => $t->employee_name,
                // ما رُحّل إلى الأستاذ وما لم يُرحَّل — يُقرأ ولا يُخفى
                'posted' => $t->journal_entry_id !== null,
                // والملغاة تُوسم ولا تُحذف: خرجت من المجموع وبقيت في السجلّ
                'cancelled' => $t->isCancelled(),
            ])->all(),
            'pagination' => Pagination::meta($rows),
            'filters' => $request->only('q', 'kind', 'from', 'to') + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'movements' => Books::movementOptions(),
            'kinds' => $this->kindFilters($bid),
            /*
             * أنواع المصروفات — اختياريّةٌ في النموذج.
             *
             * وبلا هذا يسقط كلُّ مصروفٍ يُسجَّل هنا في «مصروف عام»، فيصير
             * ترشيحُ شاشة المصروفات بالنوع لا يفصل شيئًا. والحقل يبقى
             * اختياريًّا: من يريد التسجيل بسرعة يتركه.
             */
            'expenseTypes' => collect(Demo::expenseTypes())->pluck('name')->all(),
            'summary' => [
                'in' => round((float) ($totals['دخل'] ?? 0), 3),
                'out' => round((float) ($totals['مصروف'] ?? 0), 3),
                'transfers' => round((float) ($totals['تحويل'] ?? 0), 3),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * تسجيل حركةٍ يدوية — في الدفترين معًا أو في أيٍّ منهما.
     *
     * والبيع ليس منها: مبيعات نقطة البيع تكتب صفَّها بنفسها لحظةَ البيع،
     * وتسجيلُها هنا يدويًّا يعني بيعةً مرّتين في كلّ تقرير. فالقائمة لا
     * تحمله، والحارس هنا لا في الشاشة وحدها — الطلب قد يصل من غيرها.
     */
    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'kind' => ['required', Rule::in(Books::manualKinds())],
            // حركةٌ بصفر صفٌّ في الدفتر لا يغيّر رصيدًا ولا يُصحَّح
            'amount' => ['required', 'numeric', 'min:0.001'],
            'side' => ['nullable', Rule::in([Books::CASH, Books::BANK])],
            'description' => ['nullable', 'string', 'max:255'],
            'expense_type' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            // معرّف المتصفّح: ضغطتان على «حفظ» لا تُخرجان المال مرّتين
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ], [
            'kind.in' => __('اختر نوع الحركة — ومبيعات نقطة البيع تُسجَّل من نقطة البيع لا من هنا.'),
            'amount.min' => __('المبلغ يجب أن يكون أكبر من صفر'),
        ]);

        // النوع الذي لا يسأل عن جهةٍ تحدّدها وصفتُه — ولا تُقبل منه
        $asks = Books::MOVEMENTS[$data['kind']]['asks'] !== null;

        if ($asks && empty($data['side'])) {
            return back()->withInput()->withErrors(['side' => __('اختر: من الصندوق أم من البنك؟')]);
        }

        try {
            $transaction = Books::recordMovement(
                $bid,
                $data + ['branch_id' => Demo::currentBranchId()],
                auth()->id(),
                auth()->user()->name,
            );
        } catch (RuntimeException $e) {
            /*
             * سبب الرفض يُقال في الشاشة لا في السجلّ: «تعذّر الحفظ» وحدها
             * تجعل التاجر يعيد الضغط عشر مرّات على حركةٍ لن تُقبل.
             */
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        \App\Support\Activity::log(
            'created',
            'سجّل حركة '.Books::label($data['kind']).' بقيمة '.$data['amount'],
            ['subject_id' => $transaction->id],
        );

        return back()->with('toast', ['msg' => __('سُجّلت الحركة ورُحّلت إلى الدفتر'), 'type' => 'success']);
    }

    /** أنواع الحركة الموجودة فعلًا — قائمةٌ لا تعرض تصفيةً بلا نتائج */
    private function kindFilters(int $bid): array
    {
        return Transaction::where('business_id', $bid)->distinct()->pluck('kind')
            ->filter()->values()
            ->map(fn ($k) => ['value' => $k, 'label' => Books::label($k)])
            ->all();
    }
}
