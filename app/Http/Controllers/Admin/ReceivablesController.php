<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Activity;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\Receivables;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الذممُ المدينة — «ما لك».
 *
 * ولا تُخلط بشاشة «المستحقّات»: تلك ما على المتجر (موردون، مصروفات، رواتب)
 * وهذه ما له على عملائه. جمعُهما في شاشةٍ واحدة يجعل التاجر يقرأ رقمًا لا
 * يعرف أهو دائنٌ به أم مدين.
 */
class ReceivablesController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    public function index(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Finance/Receivables', [
            'totals' => Receivables::totals($bid),
            'aging' => Receivables::aging($bid),
            'invoices' => Receivables::openInvoices($bid)->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->number,
                'customer' => $i->customer?->name ?? '—',
                'due_at' => optional($i->due_at)->format('Y-m-d'),
                'total' => (float) $i->total,
                'outstanding' => $i->outstanding(),
                'days_overdue' => $i->daysOverdue(),
                'state' => $i->paymentState(),
            ])->all(),
            // والمطابقةُ تُعرض لا تُخفى: نظامٌ يقول رقمًا يجب أن يُثبته
            'reconciliation' => Receivables::reconcile($bid),
        ]);
    }

    /** كشفُ حساب العميل — رصيدٌ افتتاحيٌّ ثمّ حركةٌ برصيدٍ جارٍ */
    public function statement(Request $request, int|string $customer): Response
    {
        $bid = $this->bid();
        $customer = Customer::where('business_id', $bid)->whereKey($customer)->firstOrFail();

        $from = $request->date('from') ?? now()->startOfYear();
        $to = $request->date('to') ?? now()->endOfDay();

        return Inertia::render('Admin/Finance/CustomerStatement', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'type' => $customer->customer_type,
                'monthly_billing' => (bool) $customer->monthly_billing,
                'payment_terms_days' => $customer->payment_terms_days,
            ],
            'statement' => Receivables::statement($bid, (int) $customer->id, $from, $to),
            /*
             * بيعاتُه الآجلة التي لم تُطبع لها ورقةٌ بعد.
             *
             * وهي ذمّةٌ قائمةٌ من لحظة البيع — تُعرض هنا كي لا يظنّ التاجر أنّ
             * ما لم يُفوتَر لم يُبَع.
             */
            'uninvoiced' => Receivables::uninvoicedOrders($bid, (int) $customer->id)
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'at' => optional($o->ordered_at ?? $o->created_at)->format('Y-m-d'),
                    'total' => (float) $o->total,
                ])->all(),
            'summary' => [
                'outstanding' => Receivables::customerOutstanding($bid, (int) $customer->id),
                'credit' => Receivables::customerCredit($bid, (int) $customer->id),
            ],
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            /*
             * وما لا يُحفظ لا يُرسم.
             *
             * زرُّ «أصدر فاتورة بها» كان يُرسم لكلّ
             * من فتح الشاشة، ويُردّان عند الضغط بـ٤٠٣. والموظّف يظنّ العطبَ
             * في النظام فيعيد المحاولة — وزرٌّ لا يُدير شيئًا أسوأ من غيابه.
             */
            'may' => [
                'bill' => (bool) auth()->user()?->may(Permissions::CUSTOMER_INVOICE_ISSUE),
            ],
        ]);
    }

    /**
     * فوترةُ الشهر — ورقةٌ واحدة على طلباتٍ مختارة.
     *
     * والمعرّفاتُ تُفحص في الخدمة لا هنا: طلبٌ من متجرٍ آخر، أو مدفوعٌ، أو
     * مفوتَرٌ سلفًا — ثلاثتُها تُردّ هناك حيث القفل.
     */
    public function bill(Request $request, int|string $customer)
    {
        /*
         * وبابٌ ثانٍ للإصدار يحمل حارسَ الأوّل.
         *
         * `consolidate` تُصدر الورقةَ وتكتب قيدَها كما يفعل زرُّ «إصدار» في
         * شاشة الفاتورة. وبابان لفعلٍ واحد أحدُهما محروسٌ يعني أنّ الحارس
         * زينة: من يُردّ هناك يدخل من هنا.
         */
        abort_if(! auth()->user()?->may(Permissions::CUSTOMER_INVOICE_ISSUE), 403);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'due_at' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['order_ids' => __('الطلبات')]);

        $record = Customer::where('business_id', $this->bid())->whereKey($customer)->firstOrFail();

        try {
            $invoice = CustomerInvoices::consolidate(
                $record, $data['order_ids'], $data, auth()->id()
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order_ids' => $e->getMessage()]);
        }

        return redirect()->route('admin.customerInvoices.show', $invoice->id)->with('toast', [
            'msg' => __('صدرت الفاتورة :n على :c طلبات', [
                'n' => $invoice->number, 'c' => count($data['order_ids']),
            ]),
            'type' => 'success',
        ]);
    }

    /**
     * شروطُ سداد العميل — مدّتُه وفوترتُه الشهريّة، ويُسجَّل تغييرُها.
     *
     * ولا إذنَ ولا حدَّ ائتمانٍ هنا: أيُّ عميلٍ مسجَّلٍ يُباع له آجلًا (انظر
     * `CreditSales`). والبابُ تحت «المالية» كشاشته — ما يُفتح هناك يُحفظ هناك.
     */
    public function terms(Request $request, int|string $customer)
    {
        $data = $request->validate([
            'monthly_billing' => ['sometimes', 'boolean'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [], [
            'payment_terms_days' => __('مدة السداد'),
        ]);

        $customer = Customer::where('business_id', $this->bid())->whereKey($customer)->firstOrFail();
        $customer->update($data);

        Activity::log('updated', 'عدّل شروط سداد «'.$customer->name.'»: مدّة '.($data['payment_terms_days'] ?? '—'), [
            'subject_id' => $customer->id, 'subject_type' => 'customer',
        ]);

        return back()->with('toast', ['msg' => __('حُفظت الإعدادات'), 'type' => 'success']);
    }
}
