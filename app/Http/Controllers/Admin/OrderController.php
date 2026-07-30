<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        $q = Order::where('business_id', $this->bid())->where('is_held', false)->withCount('items')
            ->when(Demo::currentBranchId(), fn ($w) => $w->where('branch_id', Demo::currentBranchId()));

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('customer_name', 'like', "%{$s}%"));
        }
        if ($pm = $request->query('payment')) { $q->where('payment_method', $pm); }
        if ($d = $request->query('date')) { $q->whereDate('ordered_at', $d); }

        $orders = $q->orderByDesc('ordered_at')->paginate(10)->withQueryString()->through(fn ($o) => [
            'id' => $o->number, 'customer' => $o->customer_name ?? 'عميل نقدي',
            'employee' => $o->employee_name ?? '—', 'branch' => $o->branch,
            'items_count' => $o->items_count, 'total' => (float) $o->total,
            'payment' => $o->payment_method, 'status' => $o->status,
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
        ]);

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->items(),
            'pagination' => \App\Support\Pagination::meta($orders),
            'filters' => $request->only('q', 'payment', 'date'),
        ]);
    }

}
