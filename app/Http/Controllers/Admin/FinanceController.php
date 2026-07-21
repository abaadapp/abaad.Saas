<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\Demo;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:دخل,مصروف'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'method' => ['required', 'string', 'max:50'],
            'occurred_at' => ['nullable', 'date'],
        ]);
        Transaction::create([
            'business_id' => $this->bid(),
            'reference' => 'TRX-' . random_int(60000, 99999),
            'description' => $data['description'] ?? '—',
            'method' => $data['method'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'employee_name' => auth()->user()->name,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        \App\Support\Activity::log('created', 'سجّل معاملة ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.finance.index')->with('toast', ['msg' => __('تم تسجيل المعاملة بنجاح'), 'type' => 'success']);
    }
}
