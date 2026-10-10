<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PdfController;
use App\Models\Order;
use App\Models\Transaction;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Lists\TransactionsList;
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
    private const SORTS = TransactionsList::SORTS;

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * ورقةُ الحركة — الفاتورةُ كما يقرؤها العميل، إلى جانب الجدول.
     *
     * ═══ ولمَ بابٌ مستقلٌّ لا حمولةٌ مع الصفحة ═══
     *
     * رسمُ عشرين ورقةً لكلّ فتحةٍ للحركة المالية يُشغّل قالبَ المستند عشرين
     * مرّة ويحمل معها صورَ الشعار والرموز — والتاجرُ يفتح واحدةً أو لا يفتح
     * شيئًا. فتُطلب حين تُطلب.
     *
     * ═══ ولمَ HTML لا PDF ═══
     *
     * PDF يخرج من الصفحة إلى قارئ المتصفّح، فيفقد التاجر مكانَه في الجدول —
     * ويعود بزرّ المتصفّح لا بزرٍّ نعرفه. والورقةُ هنا هي نفسُها التي تُطبع:
     * `PdfController::saleHtml` بابٌ واحد، فلا تفترق المعاينةُ عن الطابعة.
     * والـPDF يبقى خلف «تكبير» و«تحميل» و«طباعة» في اللوحة نفسها.
     *
     * وحركةٌ بلا فاتورة — مصروفٌ أو تحويل — تُردّ ٤٠٤: لا ورقةَ لها أصلًا،
     * والشاشةُ لا تعرض لها بابًا.
     */
    public function paper(Request $request, int $id)
    {
        $bid = $this->bid();

        // المفتاحُ بعد حصر المتجر: رقمٌ مُخمَّن في العنوان لا يفتح ورقة جار
        $transaction = Transaction::where('business_id', $bid)->findOrFail($id);

        $order = $transaction->order_id
            ? Order::where('business_id', $bid)->whereKey($transaction->order_id)->with('items')->first()
            : null;

        abort_unless((bool) $order, 404);

        $paper = PdfController::saleHtml($bid, $order);

        return response()->json([
            'html' => $paper['html'],
            'size' => $paper['paper'],
            'number' => $order->number,
            'url' => route('admin.orders.pdf', $order->number),
        ]);
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        /*
         * الاستعلامُ نفسُه الذي تقرؤه ملفّاتُ التصدير — انظر `TransactionsList`.
         *
         * والمجاميع على المدة كلّها لا على صفحتها، والتحويل صنفٌ ثالث لا يُجمع
         * مع الدخل، والملغاة خارج المجموع وداخل الجدول.
         */
        $summary = TransactionsList::summary(TransactionsList::filtered($request));
        $rows = TransactionsList::query($request)->paginate(Pagination::perPage($request, 20))->withQueryString();

        return Inertia::render('Admin/Finance/Transactions', [
            'rows' => collect($rows->items())->map(fn ($t) => TransactionsList::row($t))->all(),
            'pagination' => Pagination::meta($rows),
            'filters' => $request->only('q', 'kind') + TransactionsList::period($request)->params()
                + Sort::params($request, self::SORTS),
            'period' => TransactionsList::period($request)->screen($bid),
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
            'summary' => $summary,
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
