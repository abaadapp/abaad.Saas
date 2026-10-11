<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\PaymobSettlement;
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
 * والقسمُ «المالية» من اسم المسار (`admin.finance.*`)، والإنشاءُ والإلغاءُ
 * فعلُ التحصيل نفسُه (`CUSTOMER_PAYMENT_CREATE`) — من يطلب مالًا من عميلٍ
 * يملك تسجيلَه.
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
            'settlements' => PaymobSettlement::where('business_id', $bid)->with('bankAccount')
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
                ])->all(),
            'bankAccounts' => BankAccount::where('business_id', $bid)->where('active', true)
                ->orderByDesc('is_primary')->orderBy('id')
                ->get(['id', 'label', 'bank_name', 'account_name', 'iban', 'is_primary'])
                ->map(fn (BankAccount $a) => ['value' => (string) $a->id, 'label' => $a->displayName()])->all(),
        ]);
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
        $this->mayCollect();
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

        try {
            $link = PaymentLinks::create(
                $bid, $customer, $invoice,
                isset($data['amount']) ? (float) $data['amount'] : null,
                $data['description'] ?? null,
                auth()->id(),
                isset($data['days']) ? (int) $data['days'] : null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('تم إنشاء رابط الدفع — انسخه وأرسله للعميل'),
            'type' => 'success',
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

    /** تسويةُ Paymob — ما حوّلته إلى البنك ناقصًا رسومَها */
    public function settle(Request $request)
    {
        $data = $request->validate([
            'gross' => ['required', 'numeric', 'gt:0'],
            'fee' => ['required', 'numeric', 'min:0'],
            'net' => ['required', 'numeric', 'gt:0'],
            'settled_on' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'gross' => __('الإجمالي'),
            'fee' => __('الرسوم'),
            'net' => __('الصافي'),
            'settled_on' => __('تاريخ التسوية'),
            'bank_account_id' => __('الحساب البنكي'),
            'reference' => __('المرجع'),
            'notes' => __('ملاحظات'),
        ]);

        try {
            PaymobSettlements::record($this->bid(), $data, auth()->id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['gross' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّلت تسوية Paymob'), 'type' => 'success']);
    }
}
