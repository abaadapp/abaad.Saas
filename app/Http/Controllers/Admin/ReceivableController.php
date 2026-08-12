<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Transaction;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * الذمم: من عليه لي، وكم، ومنذ متى.
 *
 * كان كلّ بيعٍ يُكتب «مدفوع» مثبّتًا في الكود، فلا بيع على الحساب أصلًا —
 * والتاجر الذي يبيع لمقاولٍ أو مطعمٍ بالآجل يمسك دفترًا على الورق بجانب
 * نظامه. فيبيع النظام وتُحصَّل الديون خارجه، وهو نصف نظام.
 */
class ReceivableController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** الفواتير الآجلة التي بقي منها شيء */
    private function openOrders()
    {
        return Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->whereColumn('paid_amount', '<', 'total');
    }

    public function index()
    {
        $orders = (clone $this->openOrders())->whereNotNull('customer_id')->get();

        $names = Customer::where('business_id', $this->bid())
            ->whereIn('id', $orders->pluck('customer_id')->unique())
            ->pluck('name', 'id');

        $customers = $orders->groupBy('customer_id')->map(function ($rows, $customerId) use ($names) {
            $oldest = $rows->sortBy('ordered_at')->first();

            return [
                'id' => (int) $customerId,
                'name' => $names[$customerId] ?? __('عميل محذوف'),
                'balance' => round($rows->sum(fn ($o) => $o->outstanding()), 3),
                'invoices' => $rows->count(),
                // أقدم فاتورةٍ لم تُسدَّد: عمرُ الدَّين أهمّ من عدده
                'oldest' => optional($oldest->ordered_at)->format('Y-m-d'),
                'days' => $oldest->ordered_at ? (int) $oldest->ordered_at->startOfDay()->diffInDays(now()->startOfDay()) : 0,
                'overdue' => $rows->contains(fn ($o) => $o->isOverdue()),
            ];
        })->sortByDesc('balance')->values()->all();

        return Inertia::render('Admin/Finance/Receivables', [
            'customers' => $customers,
            'summary' => [
                'total' => round(array_sum(array_column($customers, 'balance')), 3),
                'customers' => count($customers),
                'overdue' => round(collect($orders)->filter(fn ($o) => $o->isOverdue())->sum(fn ($o) => $o->outstanding()), 3),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function show($id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);

        $orders = Order::where('business_id', $this->bid())
            ->where('customer_id', $customer->id)
            ->where('is_held', false)
            ->orderByDesc('ordered_at')
            ->get()
            ->filter(fn ($o) => $o->outstanding() > 0)
            ->map(fn ($o) => [
                'id' => $o->id,
                'number' => $o->number,
                'date' => optional($o->ordered_at)->format('Y-m-d'),
                'due' => optional($o->due_at)->format('Y-m-d'),
                'total' => (float) $o->total,
                'paid' => (float) $o->paid_amount,
                'remaining' => $o->outstanding(),
                'overdue' => $o->isOverdue(),
            ])->values()->all();

        $payments = CustomerPayment::where('business_id', $this->bid())
            ->where('customer_id', $customer->id)
            ->orderByDesc('paid_at')->limit(50)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'date' => optional($p->paid_at)->format('Y-m-d'),
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'note' => $p->note,
                'employee' => $p->employee_name,
            ])->all();

        return Inertia::render('Admin/Finance/ReceivableShow', [
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone],
            'orders' => $orders,
            'payments' => $payments,
            'balance' => round(array_sum(array_column($orders, 'remaining')), 3),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * تسجيل دفعة.
     *
     * تُوزَّع على الأقدم فالأحدث حين لا تُخصَّص لفاتورة — وهو العرف: الدَّين
     * الأقدم يُسدَّد أوّلًا. والزائد عن الدَّين يُرفض لا يُقيَّد رصيدًا
     * دائنًا: رصيدٌ لا شاشة له يضيع.
     */
    public function pay(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['required', 'string', 'max:50'],
            'order_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ]);

        return DB::transaction(function () use ($customer, $data) {
            $query = Order::where('business_id', $this->bid())
                ->where('customer_id', $customer->id)
                ->where('is_held', false)
                ->whereColumn('paid_amount', '<', 'total')
                ->orderBy('ordered_at')
                ->lockForUpdate();

            if (! empty($data['order_id'])) {
                $query->whereKey($data['order_id']);
            }

            $orders = $query->get();
            $due = round($orders->sum(fn ($o) => $o->outstanding()), 3);
            $amount = round((float) $data['amount'], 3);

            if ($due <= 0) {
                return back()->withErrors(['amount' => __('لا دَين على هذا العميل')]);
            }
            if ($amount > $due) {
                return back()->withErrors([
                    'amount' => __('المبلغ أكبر من الدَّين (:due)', ['due' => Demo::money($due)]),
                ]);
            }

            $left = $amount;
            foreach ($orders as $order) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $order->outstanding());
                $order->paid_amount = round((float) $order->paid_amount + $take, 3);
                if ($order->outstanding() <= 0) {
                    $order->payment_status = 'مدفوع';
                }
                $order->save();
                $left = round($left - $take, 3);
            }

            $paidAt = ! empty($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at']) : now();

            CustomerPayment::create([
                'business_id' => $this->bid(),
                'customer_id' => $customer->id,
                'order_id' => $data['order_id'] ?? null,
                'amount' => $amount,
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'employee_name' => auth()->user()->name,
                'paid_at' => $paidAt,
            ]);

            /*
             * الدفعة نقدٌ وصل اليوم — لا مبيعاتٌ جديدة.
             *
             * البيعة الآجلة قُيّدت دخلًا يوم وقعت، فتكرارها هنا يضاعف
             * الإيراد. ولذلك النوع «تحصيل» لا «دخل»: يظهر في دفتر المالية
             * وحركة الصندوق، ولا يدخل مجموع المبيعات.
             */
            Transaction::create([
                'business_id' => $this->bid(),
                'reference' => Transaction::nextReference($this->bid()),
                'description' => __('تحصيل دَين — :name', ['name' => $customer->name]),
                'method' => $data['method'],
                'type' => 'تحصيل',
                'amount' => $amount,
                'employee_name' => auth()->user()->name,
                'occurred_at' => $paidAt,
            ]);

            \App\Support\Activity::log('created', 'حصّل ' . $amount . ' من ' . $customer->name);

            return back()->with('toast', ['msg' => __('تم تسجيل الدفعة'), 'type' => 'success']);
        });
    }
}
