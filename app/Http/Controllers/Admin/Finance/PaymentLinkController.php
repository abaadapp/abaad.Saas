<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Support\Demo;
use App\Support\PaymentLinks;
use App\Support\PaymobSettlements;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * المالية ← روابط الدفع — وتسوياتُ Paymob.
 *
 * كلُّ شيءٍ بمتجر الجلسة وحده: الرابطُ والعميلُ والفاتورةُ والحسابُ البنكيّ
 * تُطلب بـ`business_id` المتجر، ومعرّفٌ من متجرٍ آخر ⇒ ٤٠٤. ولا شيءَ هنا في
 * لوحة المنصّة: حسابُ Paymob حسابُ التاجر.
 *
 * والقسمُ «المالية» من اسم المسار (`admin.finance.*`). والإنشاءُ فعلُ طلب
 * الدفع (`PAYMENT_LINK_CREATE`) — والإلغاءُ يبقى فعلَ التحصيل
 * (`CUSTOMER_PAYMENT_CREATE`) كما كان: من يُمنح أن يطلب لا يُمنح بها أن
 * يُلغي روابطَ غيره. وفاتورةٌ أو عميلٌ يُطلب لهما الرابطُ من صفحتيهما بلا
 * هذا القسم (`PaymentRequestController`).
 */
class PaymentLinkController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function mayCollect(): void
    {
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_PAYMENT_CREATE), 403);
    }

    private function mayRequest(): void
    {
        abort_if(! auth()->user()?->may(Permissions::PAYMENT_LINK_CREATE), 403);
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $status = $request->string('status')->toString();
        $status = in_array($status, CustomerPaymentLink::STATUSES, true) ? $status : null;

        $panel = PaymentLinks::panel($bid, fn ($q) => $q->when($status, fn ($x) => $x->where('status', $status))->limit(300));

        return Inertia::render('Admin/Finance/PaymentLinks', $panel + [
            'status' => $status,
            'counts' => CustomerPaymentLink::where('business_id', $bid)
                ->where('status', '!=', CustomerPaymentLink::FAILED)
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')
                ->map(fn ($n) => (int) $n)->all(),
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->limit(1000)
                ->get(['id', 'name', 'phone'])
                ->map(fn (Customer $c) => ['value' => (string) $c->id, 'label' => trim($c->name.($c->phone ? ' — '.$c->phone : ''))])->all(),
            'clearing' => PaymobSettlements::clearing($bid),
            // ما ينتظر أن يُسوّى، وأين مالُ كلٍّ منه — يختار منه التاجرُ ما حمله التحويل
            'payouts' => ($page = PaymobSettlements::candidatesPage($bid))['rows'],
            // والبقيّةُ دفعةً بعد دفعة — لا تختفي مدفوعةٌ خلف أقدمِ ثلاثمئة
            'payouts_next' => $page['next'],
            'payouts_waiting' => PaymobSettlements::waiting($bid),
            // ما يُرسَل في الطلب الواحد — والتحويلُ الأكبرُ يُجمع على دفعات
            'payouts_chunk' => PaymobSettlements::CHUNK,
            'payouts_paused' => PaymobSettlements::paused(),
            'settlements' => PaymobSettlement::where('business_id', $bid)->with('bankAccount')->withCount('items')
                ->latest('settled_on')->latest('id')->limit(50)->get()
                ->map(fn (PaymobSettlement $s) => [
                    'id' => $s->id,
                    'settled_on' => optional($s->settled_on)->format('Y-m-d'),
                    'gross' => (float) $s->gross,
                    'fee' => (float) $s->fee,
                    'net' => (float) $s->net,
                    'bank' => $s->bankAccount?->displayName(),
                    'reference' => $s->reference,
                    'notes' => $s->notes,
                    'status' => $s->status,
                    'hold_reason' => $s->hold_reason,
                    'items' => (int) $s->items_count,
                ] + ($s->status === PaymobSettlements::LEGACY ? PaymobSettlements::legacyPanel($s) : []))->all(),
            'bankAccounts' => BankAccount::where('business_id', $bid)->where('active', true)
                ->orderByDesc('is_primary')->orderBy('id')
                ->get(['id', 'label', 'bank_name', 'account_name', 'iban', 'is_primary'])
                ->map(fn (BankAccount $a) => ['value' => (string) $a->id, 'label' => $a->displayName()])->all(),
        ]);
    }

    /** الدفعةُ التالية ممّا ينتظر أن يُسوّى — بعد آخرِ صفٍّ عُرض (`after`) */
    public function payoutCandidates(Request $request): JsonResponse
    {
        $page = PaymobSettlements::candidatesPage($this->bid(), $request->string('after')->toString() ?: null);

        return response()->json(['payouts' => $page['rows'], 'next' => $page['next']]);
    }

    /** فواتيرُ عميلٍ عليها باقٍ — لاختيار فاتورة الرابط */
    public function invoices(Request $request): JsonResponse
    {
        $bid = $this->bid();

        // بلا عميلٍ مختارٍ لا فواتير — قائمةٌ فارغة، لا ٤٠٤
        if (! $request->filled('customer_id')) {
            return response()->json([]);
        }

        $customer = Customer::where('business_id', $bid)->findOrFail((int) $request->query('customer_id'));

        return response()->json(
            CustomerInvoice::where('business_id', $bid)->where('customer_id', $customer->id)->live()
                ->orderBy('due_at')->orderBy('id')->get()
                ->filter(fn (CustomerInvoice $i) => $i->outstanding() > 0)
                ->map(fn (CustomerInvoice $i) => ['value' => (string) $i->id, 'label' => $i->number, 'outstanding' => $i->outstanding()])
                ->values()->all(),
        );
    }

    public function store(Request $request)
    {
        $this->mayRequest();
        $bid = $this->bid();

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'customer_invoice_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['nullable', 'string', 'max:250'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.PaymentLinks::MAX_DAYS],
        ], [], [
            'customer_id' => __('العميل'),
            'customer_invoice_id' => __('الفاتورة'),
            'amount' => __('المبلغ'),
            'description' => __('الوصف'),
            'days' => __('مدة الرابط'),
        ]);

        // العميلُ والفاتورةُ من هذا المتجر وحده — ومن غيره ٤٠٤ لا رابط
        $customer = Customer::where('business_id', $bid)->findOrFail($data['customer_id']);
        $invoice = isset($data['customer_invoice_id'])
            ? CustomerInvoice::where('business_id', $bid)->findOrFail($data['customer_invoice_id'])
            : null;

        // ورابطُ الفاتورة يُطلب بباقيها إن لم يُكتب مبلغ — والمبلغُ الحرّ يُكتب
        if ($invoice === null && ! isset($data['amount'])) {
            throw ValidationException::withMessages(['amount' => __('اكتب مبلغ الرابط.')]);
        }

        if ($invoice !== null && (int) $invoice->customer_id !== (int) $customer->id) {
            // والرسالةُ على حقلها كما كانت حين كانت `create` تقولها
            throw ValidationException::withMessages(['amount' => __('الفاتورة :n ليست على هذا العميل.', ['n' => $invoice->number])]);
        }

        $reused = false;

        try {
            // ورابطُ فاتورةٍ يمرّ بالباب الذي يمنع طلبين للمال نفسِه — من أيّ شاشةٍ جاء
            [$link, $reused] = $invoice !== null
                ? PaymentLinks::forInvoice(
                    $invoice, auth()->id(),
                    isset($data['amount']) ? (float) $data['amount'] : null,
                    $data['description'] ?? null,
                    days: isset($data['days']) ? (int) $data['days'] : null,
                )
                : [PaymentLinks::create(
                    $bid, $customer, null,
                    (float) $data['amount'],
                    $data['description'] ?? null,
                    auth()->id(),
                    isset($data['days']) ? (int) $data['days'] : null,
                ), false];
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => $reused
                ? __('على الفاتورة رابطٌ قائم بالمبلغ نفسه — لم يُنشأ رابطٌ ثانٍ')
                : __('تم إنشاء رابط الدفع — انسخه وأرسله للعميل'),
            'type' => $reused ? 'info' : 'success',
        ])->with('paymentLink', PaymentLinks::row($link->load(['customer', 'invoice'])));
    }

    public function cancel(int|string $id)
    {
        $this->mayCollect();

        $link = CustomerPaymentLink::where('business_id', $this->bid())->findOrFail($id);

        try {
            PaymentLinks::cancel($link, auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', ['msg' => __('أُلغي رابط الدفع'), 'type' => 'success']);
    }

    /**
     * تسويةُ Paymob — ما حوّلته إلى البنك ناقصًا رسومَها، ومدفوعاتُه بأسمائها.
     *
     * الإجماليُّ لا يُكتب: هو مجموعُ المدفوعات المختارة، والصافي والرسومُ من
     * كشف Paymob أو البنك. انظر `PaymobSettlements::record`.
     */
    public function settle(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.PaymobSettlements::CHUNK],
            'items.*' => ['required', 'string', 'regex:/^(intent|link):[0-9]+$/'],
            'fee' => ['required', 'numeric', 'min:0'],
            'net' => ['required', 'numeric', 'gt:0'],
            'settled_on' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'items' => __('مدفوعات التحويل'),
            'items.*' => __('مدفوعات التحويل'),
            'fee' => __('الرسوم'),
            'net' => __('الصافي'),
            'settled_on' => __('تاريخ التسوية'),
            'bank_account_id' => __('الحساب البنكي'),
            'reference' => __('المرجع'),
            'notes' => __('ملاحظات'),
        ]);

        try {
            $settlement = PaymobSettlements::record($this->bid(), $data, auth()->id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return back()->with('toast', $settlement->status === PaymobSettlements::HELD
            ? ['msg' => __('حُفظت التسوية ولم تُرحَّل — في مدفوعاتها ما يحتاج مراجعة'), 'type' => 'warning']
            : ['msg' => __('سُجّلت تسوية Paymob'), 'type' => 'success']);
    }

    /** تسويةٌ معلّقة: يُعاد فحصُها بعد مراجعة سببها، فتُرحَّل إن تأكّد كلُّ شيء */
    public function retrySettlement(int $id)
    {
        // تسويةُ متجرٍ آخر ٤٠٤ — قبل الـtry: `ModelNotFoundException` نفسُها RuntimeException
        PaymobSettlement::where('business_id', $this->bid())->findOrFail($id);

        try {
            $settlement = PaymobSettlements::retry($this->bid(), $id, auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', $settlement->status === PaymobSettlements::POSTED
            ? ['msg' => __('رُحّلت التسوية'), 'type' => 'success']
            : ['msg' => __('ما زالت التسوية معلّقة — انظر السبب'), 'type' => 'warning']);
    }

    /*
     * ═══ تحويلٌ بآلاف المدفوعات — على دفعات ═══
     *
     * لا يُرسَل في طلبٍ واحد: يُفتح بأرقام كشفه، وتُضاف مدفوعاتُه دفعةً بعد دفعة
     * (`CHUNK`) — بأسمائها أو «كلُّ ما ينتظر حتّى يوم» — ثمّ يُرحَّل قيدًا واحدًا.
     * انظر `PaymobSettlements::openDraft` … `postDraft`. ومسوّدةُ متجرٍ آخر ٤٠٤.
     */

    public function openSettlementDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fee' => ['required', 'numeric', 'min:0'],
            'net' => ['required', 'numeric', 'gt:0'],
            'settled_on' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'fee' => __('الرسوم'),
            'net' => __('الصافي'),
            'settled_on' => __('تاريخ التسوية'),
            'bank_account_id' => __('الحساب البنكي'),
            'reference' => __('المرجع'),
            'notes' => __('ملاحظات'),
        ]);

        return response()->json($this->draftJson($this->draftStep(fn () => PaymobSettlements::openDraft($this->bid(), $data, auth()->id()))), 201);
    }

    public function addToSettlementDraft(Request $request, int $id): JsonResponse
    {
        $this->ownDraft($id);
        $data = $this->draftItems($request);

        return response()->json($this->draftJson($this->draftStep(fn () => PaymobSettlements::addToDraft($this->bid(), $id, $data['items']))));
    }

    public function addWaitingToSettlementDraft(Request $request, int $id): JsonResponse
    {
        $this->ownDraft($id);
        $data = $request->validate(['through' => ['required', 'date']], [], ['through' => __('حتى تاريخ')]);
        $step = $this->draftStep(fn () => PaymobSettlements::addWaitingToDraft($this->bid(), $id, $data['through']));

        return response()->json($this->draftJson($step['draft']) + ['added' => $step['added'], 'remaining' => $step['remaining']]);
    }

    public function removeFromSettlementDraft(Request $request, int $id): JsonResponse
    {
        $this->ownDraft($id);
        $data = $this->draftItems($request);

        return response()->json($this->draftJson($this->draftStep(fn () => PaymobSettlements::removeFromDraft($this->bid(), $id, $data['items']))));
    }

    public function dropUnsureFromSettlementDraft(int $id)
    {
        $this->ownDraft($id);

        try {
            PaymobSettlements::dropUnsureFromDraft($this->bid(), $id);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', ['msg' => __('أُخرجت المدفوعات غير المؤكّدة من التحويل — تعود لتنتظر'), 'type' => 'success']);
    }

    public function postSettlementDraft(int $id)
    {
        $this->ownDraft($id);

        try {
            $settlement = PaymobSettlements::postDraft($this->bid(), $id, auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', $settlement->status === PaymobSettlements::POSTED
            ? ['msg' => __('سُجّلت تسوية Paymob'), 'type' => 'success']
            : ['msg' => __('لم يُرحَّل التحويل — بقي مسوّدة، انظر السبب'), 'type' => 'warning']);
    }

    // مسوّدةُ متجرٍ آخر ٤٠٤ — قبل الـtry: `ModelNotFoundException` نفسُها RuntimeException
    private function ownDraft(int $id): void
    {
        PaymobSettlement::where('business_id', $this->bid())->findOrFail($id);
    }

    private function draftItems(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.PaymobSettlements::CHUNK],
            'items.*' => ['required', 'string', 'regex:/^(intent|link):[0-9]+$/'],
        ], [], [
            'items' => __('مدفوعات التحويل'),
            'items.*' => __('مدفوعات التحويل'),
        ]);
    }

    private function draftStep(callable $step): mixed
    {
        try {
            return $step();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
    }

    /** @return array{id: int, status: string, count: int, gross: float} */
    private function draftJson(PaymobSettlement $draft): array
    {
        return ['id' => (int) $draft->id, 'status' => (string) $draft->status, 'count' => $draft->items()->count(), 'gross' => (float) $draft->gross];
    }

    /**
     * تحويلٌ سُجّل قبل التحديث لا يسمّي مدفوعاته: صاحبُ المتجر يحدّد ما حمله من
     * كشف Paymob، ويكتب ما استند إليه. لا قيدَ يُكتب — انظر `PaymobSettlements::attribute`.
     */
    public function attributeSettlement(Request $request, int $id)
    {
        // تسويةُ متجرٍ آخر ٤٠٤ — قبل الـtry: `ModelNotFoundException` نفسُها RuntimeException
        PaymobSettlement::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.PaymobSettlements::CHUNK],
            'items.*' => ['required', 'string', 'regex:/^(intent|link):[0-9]+$/'],
            'note' => ['required', 'string', 'min:5', 'max:255'],
        ], [], [
            'items' => __('مدفوعات التحويل'),
            'items.*' => __('مدفوعات التحويل'),
            'note' => __('ما استندتَ إليه'),
        ]);

        try {
            PaymobSettlements::attribute($this->bid(), $id, $data['items'], $data['note'], auth()->id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُدّد ما حمله التحويل القديم'), 'type' => 'success']);
    }

    /** تراجعٌ عن تحديدٍ خاطئ لما حمله تحويلٌ قديم — تعود المدفوعةُ للمراجعة */
    public function unattributeSettlementItem(int $id)
    {
        PaymobSettlementItem::where('business_id', $this->bid())->findOrFail($id);

        try {
            PaymobSettlements::unattribute($this->bid(), $id);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', ['msg' => __('تُرك التحديد — عادت المدفوعة إلى المراجعة'), 'type' => 'success']);
    }

    /** تسويةٌ معلّقة تُلغى قبل ترحيلها — وتعود مدفوعاتُها لتُختار */
    public function cancelSettlement(int $id)
    {
        // تسويةُ متجرٍ آخر ٤٠٤ — قبل الـtry: `ModelNotFoundException` نفسُها RuntimeException
        PaymobSettlement::where('business_id', $this->bid())->findOrFail($id);

        try {
            PaymobSettlements::cancel($this->bid(), $id);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        return back()->with('toast', ['msg' => __('أُلغيت التسوية المعلّقة'), 'type' => 'success']);
    }
}
