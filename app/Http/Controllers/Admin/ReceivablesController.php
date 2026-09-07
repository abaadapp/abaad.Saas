<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Activity;
use App\Support\Demo;
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
                'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
                'payment_terms_days' => $customer->payment_terms_days,
            ],
            'statement' => Receivables::statement($bid, (int) $customer->id, $from, $to),
            'summary' => [
                'outstanding' => Receivables::customerOutstanding($bid, (int) $customer->id),
                'credit' => Receivables::customerCredit($bid, (int) $customer->id),
                'headroom' => Receivables::creditHeadroom($customer),
            ],
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ]);
    }

    /** إعداداتُ ائتمان العميل — بابٌ واحدٌ يكتبها، ويُسجَّل تغييرُها */
    public function credit(Request $request, int|string $customer)
    {
        $data = $request->validate([
            'allow_credit_sales' => ['required', 'boolean'],
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
