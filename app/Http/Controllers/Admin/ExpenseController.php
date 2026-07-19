<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\Demo;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['nullable', 'string', 'max:50'],
            'spent_at' => ['nullable', 'date'],
        ]);
        $data['business_id'] = $this->bid();
        $data['method'] = $data['method'] ?? 'نقدي';
        $data['spent_at'] = $data['spent_at'] ?? now();
        $data['employee_name'] = auth()->user()->name;
        Expense::create($data);
        \App\Support\Activity::log('created', 'سجّل مصروف ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.expenses.index')->with('toast', ['msg' => 'تم تسجيل المصروف بنجاح', 'type' => 'success']);
    }
}
