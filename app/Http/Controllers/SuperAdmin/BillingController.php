<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Invoice;
use App\Support\Billing;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /** تجديد اشتراك متجر دورةً كاملة وإصدار فاتورتها */
    public function renew(Request $request, $businessId)
    {
        $data = $request->validate([
            'cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $business = Business::findOrFail($businessId);

        if (! $business->plan_id) {
            return back()->with('toast', [
                'msg' => __('اختر باقةً للمتجر قبل التجديد.'),
                'type' => 'warning',
            ]);
        }

        $result = Billing::renew($business, $data['cycle']);

        return back()->with('toast', [
            'msg' => __('جُدّد حتى :date · فاتورة :n', [
                'date' => $result['subscription']->ends_at->format('Y-m-d'),
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
        Billing::markPaid($invoice);

        return back()->with('toast', [
            'msg' => __('سُجّل سداد :n', ['n' => $invoice->number]),
            'type' => 'success',
        ]);
    }
}
