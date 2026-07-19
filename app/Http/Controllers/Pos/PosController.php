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
        ]);
        Order::create([
            'business_id' => $this->bid(),
            'number' => 'HOLD-' . random_int(300, 999),
            'customer_name' => $data['customer'] ?? 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'status' => 'معلّق',
            'is_held' => true,
            'subtotal' => $data['total'] ?? 0,
            'total' => $data['total'] ?? 0,
            'ordered_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** إغلاق الوردية */
    public function closeShift(Request $request)
    {
        $data = $request->validate(['actual_balance' => ['required', 'numeric']]);
        $shift = Shift::where('business_id', $this->bid())->where('status', 'مفتوحة')->latest()->first();
        if ($shift) {
            $shift->update([
                'actual_balance' => $data['actual_balance'],
                'difference' => $data['actual_balance'] - $shift->expected_balance,
                'closed_at' => now(),
                'status' => 'مغلقة',
            ]);
            \App\Support\Activity::log('shift', 'أغلق الوردية — الرصيد الفعلي ' . number_format($data['actual_balance'], 3) . ' ر.ع', ['subject_id' => $shift->id]);
        }

        return redirect()->route('pos.shift')->with('toast', ['msg' => 'تم إغلاق الوردية بنجاح', 'type' => 'success']);
    }

    /** إضافة عميل سريع من نقطة البيع */
    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
        ]);
        $data['business_id'] = $this->bid();
        \App\Models\Customer::create($data);
        \App\Support\Activity::log('created', 'أضاف عميلًا من نقطة البيع: ' . $data['name']);

        return back()->with('toast', ['msg' => 'تم إضافة العميل', 'type' => 'success']);
    }

    /** إشعار صاحب المتجر بطلب جديد عبر البريد (غير مُعطِّل عند الفشل، ويحترم إعداد التفعيل) */
    private function notifyNewOrder(Order $order): void
    {
        $business = \App\Models\Business::find($this->bid());
        if (! $business || ! $business->email) {
            return;
        }
        $enabled = \App\Models\Setting::where('business_id', $this->bid())->where('key', 'notify_new_order')->value('value');
        if ($enabled === '0') {
            return;
        }
        try {
            \Illuminate\Support\Facades\Mail::to($business->email)->send(new \App\Mail\NewOrderMail($order));
        } catch (\Throwable $e) {
            report($e); // لا نُفشل عملية البيع بسبب البريد
        }
    }
}
