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
        $occurredAt = $data['occurred_at'] ?? now();

        $transaction = Transaction::create([
            'business_id' => $this->bid(),
            'reference' => Transaction::nextReference($this->bid()),
            'description' => $data['description'] ?? '—',
            'method' => $data['method'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'employee_name' => auth()->user()->name,
            'occurred_at' => $occurredAt,
        ]);

        /*
         * المصروف المسجَّل هنا يُقيَّد مصروفًا فعلًا.
         *
         * كانت المالية تقبل «مصروف» وتكتبه صفًّا في المعاملات — وبطاقاتها
         * تجمع الدخل وحده، والربح يُقرأ من جدول المصروفات لا من هنا. فالمبلغ
         * يظهر في الجدول ولا ينقص ربحًا ولا يدخل تقريرًا: بابان لشيءٍ واحد،
         * أحدهما مسدود، ولا شيء يقول للتاجر أيّهما.
         */
        if ($data['type'] === 'مصروف') {
            \App\Models\Expense::create([
                'business_id' => $this->bid(),
                'type' => __('مصروف عام'),
                'description' => $data['description'] ?? __('مصروف من شاشة المالية'),
                'amount' => $data['amount'],
                'method' => $data['method'],
                'employee_name' => auth()->user()->name,
                'spent_at' => \Illuminate\Support\Carbon::parse($occurredAt)->toDateString(),
                'transaction_id' => $transaction->id,
            ]);
        }

        \App\Support\Activity::log('created', 'سجّل معاملة ' . $data['type'] . ' بقيمة ' . $data['amount']);

        return redirect()->route('admin.finance.index')->with('toast', ['msg' => __('تم تسجيل المعاملة بنجاح'), 'type' => 'success']);
    }
}
