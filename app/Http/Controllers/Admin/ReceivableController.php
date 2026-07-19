<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Support\Demo;
use Illuminate\Http\Request;

class ReceivableController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    private function customer($id): Customer
    {
        return Customer::where('business_id', $this->bid())->findOrFail($id);
    }

    /** تسجيل دين (بيع آجل) على العميل */
    public function recordDebt(Request $request, $id)
    {
        $customer = $this->customer($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'note' => ['nullable', 'string', 'max:255'],
            'order_number' => ['nullable', 'string', 'max:50'],
            'due_at' => ['nullable', 'date'],
        ]);
        CustomerLedger::create([
            'business_id' => $this->bid(),
            'customer_id' => $customer->id,
            'type' => 'دين',
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
            'order_number' => $data['order_number'] ?? null,
            'due_at' => $data['due_at'] ?? now()->addDays(30),
        ]);
        \App\Support\Activity::log('created', 'سجّل دينًا على ' . $customer->name . ' بقيمة ' . number_format($data['amount'], 3), ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => 'تم تسجيل الدين', 'type' => 'success']);
    }

    /** تسجيل سداد من العميل */
    public function recordPayment(Request $request, $id)
    {
        $customer = $this->customer($id);
        $balance = Demo::customerBalance($customer->id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001', 'max:' . max(0.001, $balance)],
            'method' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        CustomerLedger::create([
            'business_id' => $this->bid(),
            'customer_id' => $customer->id,
            'type' => 'سداد',
            'amount' => $data['amount'],
            'method' => $data['method'] ?? 'نقدي',
            'note' => $data['note'] ?? null,
        ]);
        \App\Support\Activity::log('updated', 'استلم سدادًا من ' . $customer->name . ' بقيمة ' . number_format($data['amount'], 3), ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => 'تم تسجيل السداد', 'type' => 'success']);
    }

    /** ضبط حدّ الائتمان للعميل */
    public function setLimit(Request $request, $id)
    {
        $customer = $this->customer($id);
        $data = $request->validate(['credit_limit' => ['required', 'numeric', 'min:0']]);
        $customer->update(['credit_limit' => $data['credit_limit']]);
        \App\Support\Activity::log('settings', 'حدّد حدّ ائتمان ' . $customer->name . ': ' . number_format($data['credit_limit'], 3), ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => 'تم حفظ حدّ الائتمان', 'type' => 'success']);
    }
}
