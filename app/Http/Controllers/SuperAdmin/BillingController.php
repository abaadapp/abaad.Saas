<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\Billing;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    /** إصدارُ فاتورة تجديدٍ لمتجر — والتمديدُ ينتظر سدادَها (`Billing::markPaid`) */
    public function renew(Request $request, $businessId)
    {
        $data = $request->validate([
            'cycle' => ['required', Rule::in(Billing::CYCLES)],
        ]);

        $business = Business::findOrFail($businessId);

        if (! $business->plan_id) {
            return back()->with('toast', [
                'msg' => __('اختر باقةً للمتجر قبل التجديد.'),
                'type' => 'warning',
            ]);
        }

        try {
            $result = Billing::renew($business, $data['cycle']);
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'warning']);
        }

        // لا «جُدّد» قبل السداد: الفاتورة صدرت، والاشتراكُ كما كان
        return back()->with('toast', [
            'msg' => __('تم إصدار فاتورة التجديد :n. لن يتم تمديد الاشتراك إلا بعد تسجيل السداد.', [
                'n' => $result['invoice']->number,
            ]),
            'type' => 'success',
        ]);
    }

    /**
     * تسجيل سداد فاتورة.
     *
     * السداد يدويّ عمدًا: التحصيل الإلكتروني يحتاج بوّابة دفع، وحتى تُختار
     * تبقى الفاتورة تُصدَر ويُسجَّل تحويلها البنكي — وهو ما يجري فعلًا اليوم.
     */
    public function pay($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        try {
            Billing::markPaid($invoice);
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        $renewal = Subscription::where('invoice_id', $invoice->id)->where('status', Billing::SUB_ACTIVE)->first();

        return back()->with('toast', [
            'msg' => $renewal?->ends_at
                ? __('تم تسجيل السداد وتفعيل تجديد الاشتراك بنجاح حتى :date.', ['date' => $renewal->ends_at->format('Y-m-d')])
                : __('سُجّل سداد :n', ['n' => $invoice->number]),
            'type' => 'success',
        ]);
    }

    /** إلغاءُ فاتورة تجديدٍ لم تُسدَّد — قبل إصدار غيرها، أو حين تتغيّر الباقة */
    public function cancel($invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        try {
            Billing::cancelRenewal($invoice);
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'warning']);
        }

        return back()->with('toast', [
            'msg' => __('أُلغيت فاتورة التجديد :n، والاشتراك كما كان.', ['n' => $invoice->number]),
            'type' => 'warning',
        ]);
    }
}
