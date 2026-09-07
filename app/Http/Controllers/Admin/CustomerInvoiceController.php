<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Support\Activity;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Receivables;
use App\Support\Search;
use App\Support\WhatsAppPhone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * فواتيرُ العملاء — الشاشةُ وبابُ الكتابة.
 *
 * ولا يُقرأ إجماليٌّ من الواجهة بحال: الخادمُ يحسب من البنود. من يستطيع فتح
 * أدوات المتصفّح يستطيع إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة.
 */
class CustomerInvoiceController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** الفاتورةُ من متجر الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): CustomerInvoice
    {
        return CustomerInvoice::where('business_id', $this->bid())
            ->whereKey($id)->with('items', 'customer')->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $rows = CustomerInvoice::where('business_id', $bid)->with('customer')
            // والمعاملُ من `Search::like` لا مكتوبًا بيده: `like` على Postgres
            // حسّاسٌ لحالة الأحرف و`ilike` ليس كذلك — ومن كتبه بيده أصاب في
            // شاشةٍ وأخطأ في أخرى
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('po_number', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('issued_at', '<=', $d))
            ->orderByDesc('id')->limit(300)->get();

        // و«المتأخّرة» تُرشَّح بعد الاشتقاق لا بعمودٍ مخزَّن يحتاج مهمّةً ليليّة
        if ($request->boolean('overdue')) {
            $rows = $rows->filter(fn ($i) => $i->daysOverdue() > 0)->values();
        }

        return Inertia::render('Admin/CustomerInvoices/Index', [
            'invoices' => $rows->map(fn ($i) => $this->row($i))->all(),
            'customers' => Customer::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name'])->all(),
            'filters' => $request->only('q', 'customer_id', 'status', 'from', 'to', 'overdue'),
            'totals' => Receivables::totals($bid),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(CustomerInvoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'customer' => $i->customer?->name ?? $i->customer_name ?? '—',
            'customer_id' => $i->customer_id,
            'status' => $i->status,
            'state' => $i->paymentState(),
            'issued_at' => optional($i->issued_at)->format('Y-m-d'),
            'due_at' => optional($i->due_at)->format('Y-m-d'),
            'total' => (float) $i->total,
            'paid' => $i->paidTotal(),
            'outstanding' => $i->outstanding(),
            'days_overdue' => $i->daysOverdue(),
            'po_number' => $i->po_number,
        ];
    }

    public function show(int|string $id): Response
    {
        $invoice = $this->find($id);

        return Inertia::render('Admin/CustomerInvoices/Show', [
            'invoice' => $this->row($invoice) + [
                'subtotal' => (float) $invoice->subtotal,
                'discount_total' => (float) $invoice->discount_total,
                'tax_total' => (float) $invoice->tax_total,
                'notes' => $invoice->notes,
                'contract_number' => $invoice->contract_number,
                'external_reference' => $invoice->external_reference,
                'department' => $invoice->department,
                'cost_center' => $invoice->cost_center,
                'attention_to' => $invoice->attention_to,
                'orders' => $invoice->orders->pluck('number')->all(),
                'cancellation_reason' => $invoice->cancellation_reason,
                'items' => $invoice->items->map(fn ($it) => [
                    'description' => $it->description,
                    'quantity' => (float) $it->quantity,
                    'unit_price' => (float) $it->unit_price,
                    'discount' => (float) $it->discount,
                    'tax_rate' => (float) $it->tax_rate,
                    'line_total' => (float) $it->line_total,
                ])->all(),
                'payments' => $invoice->allocations()->with('payment')->get()
                    ->filter(fn ($a) => $a->payment && $a->payment->cancelled_at === null)
                    ->map(fn ($a) => [
                        'number' => $a->payment->number,
                        'amount' => (float) $a->amount,
                        'method' => $a->payment->method,
                        'at' => optional($a->payment->occurred_at)->format('Y-m-d'),
                    ])->values()->all(),
                'credit_notes' => $invoice->creditNotes->map(fn ($n) => [
                    'number' => $n->number,
                    'amount' => (float) $n->amount,
                    'reason' => $n->reason,
                    'at' => optional($n->issued_at)->format('Y-m-d'),
                ])->all(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'contract_number' => ['nullable', 'string', 'max:60'],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'cost_center' => ['nullable', 'string', 'max:60'],
            'attention_to' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'issue' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'customer_id' => __('العميل'),
            'items' => __('بنود الفاتورة'),
        ]);

        $customer = Customer::where('business_id', $this->bid())
            ->whereKey($data['customer_id'])->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('هذا العميل ليس من عملاء متجرك.'),
            ]);
        }

        try {
            $invoice = CustomerInvoices::create($this->bid(), $customer, $data, $data['items'], auth()->id());

            if ($request->boolean('issue')) {
                CustomerInvoices::issue($invoice, auth()->id());
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.customerInvoices.show', $invoice->id)->with('toast', [
            'msg' => __('أُنشئت الفاتورة :n', ['n' => $invoice->number]), 'type' => 'success',
        ]);
    }

    public function issue(int|string $id)
    {
        try {
            $invoice = CustomerInvoices::issue($this->find($id), auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('صدرت الفاتورة :n', ['n' => $invoice->number]), 'type' => 'success',
        ]);
    }

    public function cancel(Request $request, int|string $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], [
            'reason' => __('سبب الإلغاء'),
        ]);

        try {
            CustomerInvoices::cancel($this->find($id), $data['reason'], auth()->id());
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغيت الفاتورة'), 'type' => 'success']);
    }

    public function creditNote(Request $request, int|string $id)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        try {
            $note = CustomerInvoices::creditNote(
                $this->find($id),
                (float) $data['amount'],
                (float) ($data['tax_amount'] ?? 0),
                $data['reason'],
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('أُصدر إشعار الدائن :n', ['n' => $note->number]), 'type' => 'success',
        ]);
    }

    /** تسجيلُ تحصيل — من الفاتورة أو على حساب العميل */
    public function pay(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string'],
            'bank_account_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable', 'date'],
            'external_reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_invoice_id' => ['nullable', 'integer'],
        ], [], ['amount' => __('المبلغ'), 'method' => __('وسيلة الدفع')]);

        $customer = Customer::where('business_id', $this->bid())
            ->whereKey($data['customer_id'])->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => __('هذا العميل ليس من عملاء متجرك.'),
            ]);
        }

        $allocations = [];
        if (! empty($data['customer_invoice_id'])) {
            $allocations = [(int) $data['customer_invoice_id'] => (float) $data['amount']];
        }

        try {
            $payment = CustomerPayments::record(
                $this->bid(), $customer, (float) $data['amount'], $data, $allocations, auth()->id()
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        /*
         * ونتيجةُ التوزيع تُقال، لا تُطبَّق في صمت.
         *
         * دفعةٌ على الحساب تُوزَّع على الأقدم فالأقدم — ومن لا يرى أين ذهبت
         * يكتشف بعد شهرٍ أنّها سدّت غيرَ ما قصد.
         */
        $spread = $payment->allocations->map(fn ($a) => $a->invoice?->number.': '.$a->amount)->join('، ');
        $left = $payment->unallocated();

        return back()->with('toast', [
            'msg' => __('سُجّل التحصيل :n', ['n' => $payment->number])
                .($spread !== '' ? ' — '.$spread : '')
                .($left > 0 ? ' — '.__('وبقي :n رصيدًا للعميل', ['n' => $left]) : ''),
            'type' => 'success',
        ]);
    }

    /**
     * تذكيرٌ بالسداد عبر واتساب.
     *
     * ولا يُبنى موصلٌ ثانٍ للمزوّد: بنيةُ الإرسال التلقائيّ القائمة مبنيّةٌ
     * على الطلبات وأحداثِ حالتها، وحشرُ الفاتورة فيها يعني تعديلَ مسارٍ حيٍّ
     * يرسل إلى زبائن اليوم.
     *
     * فالتذكيرُ يدويٌّ صريح: النصُّ يُكتب في الخادم — الاسمُ والرقمُ والباقي
     * وتاريخُ الاستحقاق — ويُفتح على واتساب التاجر ليرسله بنفسه. وهذا يعمل
     * اليوم بلا وعدٍ بأتمتةٍ غير مبنيّة.
     */
    public function remind(int|string $id)
    {
        $invoice = $this->find($id);

        if ($invoice->outstanding() <= 0) {
            return back()->withErrors(['remind' => __('لا مبلغ مستحقًّا على هذه الفاتورة.')]);
        }

        $phone = WhatsAppPhone::normalize(
            $invoice->customer?->contact_phone ?: $invoice->customer?->phone
        );

        if (! $phone) {
            return back()->withErrors(['remind' => __('لا رقم واتساب لهذا العميل — أضِفه في صفحته.')]);
        }

        $text = __(':shop — تذكير بفاتورة :number. المبلغ المستحق :amount:due', [
            'shop' => Demo::businessName(),
            'number' => $invoice->number,
            'amount' => number_format($invoice->outstanding(), 3),
            'due' => $invoice->due_at ? '، '.__('تاريخ الاستحقاق ').$invoice->due_at->format('Y-m-d') : '',
        ]);

        Activity::log('updated', 'أعدّ تذكير سداد للفاتورة '.$invoice->number, [
            'subject_id' => $invoice->id, 'subject_type' => 'customer_invoice',
        ]);

        return back()->with('toast', [
            'msg' => __('افتح واتساب وأرسل التذكير'),
            'type' => 'success',
            'link' => ['url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text), 'label' => __('فتح واتساب')],
        ]);
    }

    public function cancelPayment(Request $request, int|string $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $payment = CustomerPayment::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
        CustomerPayments::cancel($payment, $data['reason'], auth()->id());

        return back()->with('toast', ['msg' => __('أُلغي التحصيل'), 'type' => 'success']);
    }
}
