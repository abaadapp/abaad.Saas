<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\Demo;
use Illuminate\Http\Request;

class PosController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** إتمام البيع وحفظ الطلب */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'customer' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'delivery_fee' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
        ]);

        $subtotal = collect($data['items'])->sum(fn ($i) => $i['price'] * $i['qty']);
        $order = Order::create([
            'business_id' => $this->bid(),
            'number' => 'INV-' . random_int(78900, 99999),
            'customer_name' => $data['customer'] ?? 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'branch' => 'الفرع الرئيسي',
            'status' => 'مكتمل',
            'payment_method' => $data['payment_method'] ?? 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => $subtotal,
            'discount' => $data['discount'] ?? 0,
            'tax' => $data['tax'] ?? 0,
            'delivery_fee' => $data['delivery_fee'] ?? 0,
            'total' => $data['total'] ?? $subtotal,
            'ordered_at' => now(),
        ]);
        foreach ($data['items'] as $i) {
            $order->items()->create([
                'product_id' => $i['id'] ?? null,
                'name' => $i['name'],
                'price' => $i['price'],
                'quantity' => $i['qty'],
                'note' => $i['note'] ?? null,
                'total' => $i['price'] * $i['qty'],
            ]);
            if (! empty($i['id'])) {
                Product::where('business_id', $this->bid())->where('id', $i['id'])->decrement('quantity', $i['qty']);
            }
        }

        \App\Support\Activity::log('checkout', 'أتمّ بيعًا ' . $order->number . ' بقيمة ' . number_format($order->total, 3) . ' ر.ع', ['subject_id' => $order->id]);

        $this->notifyNewOrder($order);

        return response()->json(['ok' => true, 'invoice' => $order->number]);
    }

    /** تعليق الطلب */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'customer' => ['nullable', 'string'],
            'total' => ['nullable', 'numeric'],
            // معلّق = بانتظار الاستكمال الآن · محفوظ = مسودّة للرجوع إليها لاحقًا
            'kind' => ['nullable', 'in:hold,save'],
        ]);
        $saved = ($data['kind'] ?? 'hold') === 'save';

        Order::create([
            'business_id' => $this->bid(),
            'number' => ($saved ? 'SAVE-' : 'HOLD-') . random_int(300, 999),
            'customer_name' => $data['customer'] ?? 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'status' => $saved ? 'محفوظ' : 'معلّق',
            'is_held' => true,
            'subtotal' => $data['total'] ?? 0,
            'total' => $data['total'] ?? 0,
            'ordered_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

}
