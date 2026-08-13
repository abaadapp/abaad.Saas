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
            // والموظف من البحث: «من باع هذه الفاتورة؟» سؤالٌ يُبحث به كثيرًا
            $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                ->orWhere('employee_name', 'like', "%{$s}%"));
        }
        if ($pm = $request->query('payment')) { $q->where('payment_method', $pm); }
        // الملغى كان يجلس بين المكتمل بلا تمييز ولا فرز
        if ($st = $request->query('status')) { $q->where('status', $st); }
        if ($d = $request->query('date')) { $q->whereDate('ordered_at', $d); }
        // مدًى لا يومًا واحدًا: من أراد مبيعات أسبوع كان يفتح الشاشة سبع مرّات
        if ($from = $request->query('from')) { $q->whereDate('ordered_at', '>=', $from); }
        if ($to = $request->query('to')) { $q->whereDate('ordered_at', '<=', $to); }

        /*
         * مجموع ما رُشّح لا مجموع الصفحة.
         *
         * الجدول يعرض عشرة صفوف من مئة، فجمعُ المعروض يقول رقمًا لا معنى له.
         * ويُحسب قبل الترقيم على النسخة نفسها من الاستعلام.
         */
        $filtered = (clone $q);
        $totalAmount = (float) $filtered->clone()->sold()->sum('total');
        $totalCount = $filtered->clone()->count();
        $cancelledCount = $filtered->clone()->where('status', Order::CANCELLED)->count();

        $orders = $q->orderByDesc('ordered_at')->paginate(10)->withQueryString()->through(fn ($o) => [
            'id' => $o->number, 'customer' => \App\Support\Demo::customerLabel($o->customer_name),
            'employee' => $o->employee_name ?? '—', 'branch' => $o->branch,
            'items_count' => $o->items_count, 'total' => (float) $o->total,
            'payment' => $o->payment_method, 'status' => $o->status,
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
        ]);

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->items(),
            'pagination' => \App\Support\Pagination::meta($orders),
            'filters' => $request->only('q', 'payment', 'status', 'date', 'from', 'to'),
            // المبلغ من المُباع وحده، والعدد من الكلّ — والملغى يُذكر صراحةً
            // كي لا يُقرأ الفرقُ بينهما خطأً في الجمع
            'totalAmount' => $totalAmount,
            'totalCount' => $totalCount,
            'cancelledCount' => $cancelledCount,
        ]);
    }

}
