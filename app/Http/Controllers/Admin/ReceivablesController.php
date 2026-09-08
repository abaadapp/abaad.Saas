<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Activity;
use App\Support\CreditSales;
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
                'allow_credit_sales' => (bool) $customer->allow_credit_sales,
                'monthly_billing' => (bool) $customer->monthly_billing,
                'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
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
                'headroom' => Receivables::creditHeadroom($customer),
            ],
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            /*
             * وما لا يُحفظ لا يُرسم.
             *
             * نموذجُ شروط الائتمان وزرُّ «أصدر فاتورة بها» كانا يُرسمان لكلّ
             * من فتح الشاشة، ويُردّان عند الضغط بـ٤٠٣. والموظّف يظنّ العطبَ
             * في النظام فيعيد المحاولة — وزرٌّ لا يُدير شيئًا أسوأ من غيابه.
             */
            'may' => [
                'credit' => (bool) auth()->user()?->may(CreditSales::OVERRIDE),
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
     * إعداداتُ ائتمان العميل — بابٌ واحدٌ يكتبها، ويُسجَّل تغييرُها.
     *
     * ═══ ومن يضع الحدَّ هو من يتجاوزه ═══
     *
     * كان هذا البابُ مفتوحًا بقسم «العملاء» وحده، بلا فعلٍ يُمنح باسمه —
     * و`credit.override` (تجاوزُ الحدّ في البيع) للمالك ومدير الفرع وحدهما.
     * فالحارسُ كان يُتجاوَز بطلبٍ واحد: من لا يملك التجاوز يرفع الحدَّ، أو
     * يمحوه فيصير `null` — و`Receivables::creditHeadroom` تقرأ الفراغَ «بلا
     * حدّ». ثمّ يبيع آجلًا بلا سقفٍ ولا سببٍ مكتوبٍ ولا سطرٍ في السجلّ.
     *
     * ويفتح «السماحَ بالبيع الآجل» كذلك — والقاعدةُ الأصل «لا آجلَ إلّا
     * بإذن» (انظر `CreditSales`). فزبونُ المارّة يصير مدينًا بضغطة.
     *
     * فصارا فعلًا واحدًا: من يُؤتمن على تجاوز السقف يُؤتمن على وضعه، ولا
     * يُؤتمن على وضعه من لا يُؤتمن على تجاوزه. ومفتاحان لثقةٍ واحدة
     * يفترقان يومًا.
     */
    public function credit(Request $request, int|string $customer)
    {
        abort_if(! auth()->user()?->may(CreditSales::OVERRIDE), 403);

        $data = $request->validate([
            'allow_credit_sales' => ['required', 'boolean'],
            'monthly_billing' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [], [
            'credit_limit' => __('حد الائتمان'),
            'payment_terms_days' => __('مدة السداد'),
        ]);

        $customer = Customer::where('business_id', $this->bid())->whereKey($customer)->firstOrFail();
        $customer->update($data);

        Activity::log('updated', 'عدّل إعدادات ائتمان «'.$customer->name.'»: '
            .($data['allow_credit_sales'] ? 'آجل مسموح' : 'آجل ممنوع')
            .'، حدّ '.($data['credit_limit'] ?? '—'), [
                'subject_id' => $customer->id, 'subject_type' => 'customer',
            ]);

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الائتمان'), 'type' => 'success']);
    }
}
