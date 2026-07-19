<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * المرتجعات/الاسترجاع — إرجاع طلب كليًا أو جزئيًا مع إعادة الكميات إلى المخزون
 * وتسجيل معاملة استرجاع وتحديث حالة الطلب. محصور بالمستأجر الحالي.
 */
class ReturnController extends Controller
{
    public function store(Request $request, $id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $order = Order::where('business_id', $bid)->where('number', $id)->with('items')->firstOrFail();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $requested = collect($validated['items'])->filter(fn ($q) => (int) $q > 0);
        if ($requested->isEmpty()) {
            return back()->with('toast', ['msg' => 'حدّد كمية استرجاع واحدة على الأقل', 'type' => 'warning']);
        }

        $result = DB::transaction(function () use ($order, $requested, $validated, $bid) {
            $amount = 0.0;
            $count = 0;

            foreach ($order->items as $item) {
                $qty = (int) ($requested[$item->id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $qty = min($qty, $item->remaining); // لا يتجاوز المتبقّي
                if ($qty <= 0) {
                    continue;
                }

                $item->increment('returned_quantity', $qty);
                $amount += $item->price * $qty;
                $count += $qty;

                if ($item->product_id) {
                    Product::where('id', $item->product_id)->where('business_id', $bid)->increment('quantity', $qty);
                }
            }

            if ($count === 0) {
                return ['amount' => 0, 'count' => 0];
            }

            $order->refresh()->load('items');
            $fullyReturned = $order->items->every(fn ($it) => $it->remaining === 0);
            $type = $fullyReturned ? 'كلي' : 'جزئي';

            OrderReturn::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'order_number' => $order->number,
                'type' => $type,
                'amount' => round($amount, 3),
                'items_count' => $count,
                'reason' => $validated['reason'] ?? null,
                'employee_name' => auth()->user()?->name,
            ]);

            Transaction::create([
                'business_id' => $bid,
                'reference' => 'RET-' . $order->number,
                'description' => 'استرجاع للطلب ' . $order->number,
                'method' => $order->payment_method,
                'type' => 'استرجاع',
                'amount' => round($amount, 3),
                'employee_name' => auth()->user()?->name,
                'occurred_at' => now(),
            ]);

            $order->update(['status' => $fullyReturned ? 'مرتجع' : 'مرتجع جزئي']);

            return ['amount' => round($amount, 3), 'count' => $count, 'type' => $type];
        });

        if ($result['count'] === 0) {
            return back()->with('toast', ['msg' => 'لا توجد كميات قابلة للاسترجاع', 'type' => 'warning']);
        }

        Activity::log('return', "استرجاع {$result['type']} للطلب {$order->number} بقيمة " . Demo::money($result['amount']), ['subject_id' => $order->id]);

        return back()->with('toast', ['msg' => "تم استرجاع {$result['count']} صنف بقيمة " . Demo::money($result['amount']), 'type' => 'success']);
    }
}
