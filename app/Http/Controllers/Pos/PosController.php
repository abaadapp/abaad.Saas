<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\Demo;
use Illuminate\Http\Request;

class PosController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** فرع الطلب: الفرع المختار حاليًا، وإلا أول فرع للنشاط — حتى يظهر الطلب تحت فلتر الفروع */
    private function branch(): array
    {
        $branch = Demo::currentBranchId()
            ? \App\Models\Branch::where('business_id', $this->bid())->find(Demo::currentBranchId())
            : \App\Models\Branch::where('business_id', $this->bid())->orderBy('id')->first();

        return [
            'id' => $branch?->id,
            'name' => $branch?->name ?? 'الفرع الرئيسي',
        ];
    }

    /** بحث خادمي في كل فواتير المتجر (رقم/عميل/هاتف) — يغطّي كامل التاريخ لا آخر 30 فقط */
    public function searchReceipts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['receipts' => []]);
        }

        return response()->json(['receipts' => Demo::receipts($q, 50)]);
    }

    /** تغذية حيّة لكميات المنتجات وحالاتها — تستطلعها شاشة نقطة البيع لتحديث "المتوفر" تلقائيًا دون إعادة تحميل */
    public function stockFeed()
    {
        $products = collect(Demo::products())->map(fn ($p) => [
            'id' => $p['id'],
            'qty' => $p['qty'],
            'status' => $p['stock_status'],
        ])->values();

        return response()->json([
            'products' => $products,
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /** إتمام البيع وحفظ الطلب */
    /** كوبون النشاط بالكود (غير حسّاس لحالة الأحرف) */
    private function findCoupon(?string $code): ?Coupon
    {
        if (empty($code)) {
            return null;
        }

        return Coupon::where('business_id', $this->bid())
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->first();
    }

    /** التحقق من كود الخصم وتطبيقه (يُستدعى من السلة قبل الدفع) */
    public function applyCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = $this->findCoupon($data['code']);
        $subtotal = (float) $data['subtotal'];

        $error = match (true) {
            ! $coupon => __('كود الخصم غير صحيح'),
            ! $coupon->active => __('هذا الكوبون موقوف'),
            $coupon->expires_at && $coupon->expires_at->isPast() => __('انتهت صلاحية الكوبون'),
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
            $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Demo::money($coupon->min_order)]),
            default => null,
        };

        if ($error) {
            return response()->json(['ok' => false, 'error' => $error], 422);
        }

        return response()->json([
            'ok' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount' => $coupon->discountFor($subtotal),
            'message' => __('تم تطبيق الكوبون: :code', ['code' => $coupon->code]),
        ]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'delivery_fee' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'resume_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        // صمود الانقطاع: لو أُعيد رفع نفس الطلب (بعد عودة الاتصال) نعيد الفاتورة الأصلية بدل تكراره
        if (! empty($data['client_uuid'])) {
            $existing = Order::where('business_id', $this->bid())
                ->where('client_uuid', $data['client_uuid'])
                ->first();
            if ($existing) {
                return response()->json(['ok' => true, 'invoice' => $existing->number, 'duplicate' => true]);
            }
        }

        $subtotal = collect($data['items'])->sum(fn ($i) => $i['price'] * $i['qty']);
        $branch = $this->branch();

        // احتساب استخدام الكوبون إن كان صالحًا فعلًا (إعادة تحقق خادمية)
        $coupon = $this->findCoupon($data['coupon_code'] ?? null);
        $couponApplied = $coupon && $coupon->isValid() && $subtotal >= (float) $coupon->min_order;
        if ($couponApplied) {
            $coupon->increment('used_count');
        }
        $order = Order::create([
            'business_id' => $this->bid(),
            'number' => 'INV-' . random_int(78900, 99999),
            'client_uuid' => $data['client_uuid'] ?? null,
            'customer_name' => $data['customer'] ?? 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'branch_id' => $branch['id'],
            'branch' => $branch['name'],
            'status' => 'مكتمل',
            'payment_method' => $data['payment_method'] ?? 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => $subtotal,
            'discount' => $data['discount'] ?? 0,
            'coupon_code' => $couponApplied ? $coupon->code : null,
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
                $product = Product::where('business_id', $this->bid())->where('id', $i['id'])->first();
                if ($product) {
                    $product->decrement('quantity', $i['qty']);
                    \App\Models\BranchStock::adjust($this->bid(), $branch['id'], $product->id, -(int) $i['qty']);
                    // تسجيل البيع كحركة مخزون ليكتمل سجل التدقيق (كم نقص ولماذا)
                    \App\Models\InventoryMovement::create([
                        'business_id' => $this->bid(),
                        'branch_id' => $branch['id'],
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'type' => 'بيع',
                        'quantity' => '-' . (int) $i['qty'],
                        'employee_name' => auth()->user()->name,
                    ]);
                }
            }
        }

        // تسجيل البيع كمعاملة دخل في المالية تلقائيًا (لتظهر المبيعات في لوحات المالية فورًا)
        \App\Models\Transaction::create([
            'business_id' => $this->bid(),
            'order_id' => $order->id,
            'reference' => $order->number,
            'description' => 'مبيعات نقطة البيع — ' . ($order->customer_name ?? 'عميل نقدي'),
            'method' => $order->payment_method ?? 'نقدي',
            'type' => 'دخل',
            'amount' => $order->total,
            'tax_amount' => $order->tax ?? 0,
            'employee_name' => auth()->user()->name,
            'occurred_at' => $order->ordered_at ?? now(),
        ]);

        // الطلب المعلّق الذي استُكمل لم يعد لازمًا بعد إتمام بيعه
        if (! empty($data['resume_id'])) {
            $held = Order::where('business_id', $this->bid())->where('is_held', true)->find($data['resume_id']);
            if ($held) {
                $held->items()->delete();
                $held->delete();
            }
        }

        // نقاط الولاء: تُمنح للعميل المسجّل (لا للعميل النقدي) حسب إعدادات النشاط
        $pointsEarned = $this->awardLoyaltyPoints($order);

        \App\Support\Activity::log('checkout', 'أتمّ بيعًا ' . $order->number . ' بقيمة ' . number_format($order->total, 3) . ' ر.ع', ['subject_id' => $order->id]);

        $this->notifyNewOrder($order);

        return response()->json(['ok' => true, 'invoice' => $order->number, 'points_earned' => $pointsEarned]);
    }

    /** تعليق الطلب */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            'total' => ['nullable', 'numeric'],
            // معلّق = بانتظار الاستكمال الآن · محفوظ = مسودّة للرجوع إليها لاحقًا
            'kind' => ['nullable', 'in:hold,save'],
        ]);
        $saved = ($data['kind'] ?? 'hold') === 'save';

        $order = Order::create([
            'business_id' => $this->bid(),
            'number' => ($saved ? 'SAVE-' : 'HOLD-') . random_int(300, 999),
            'customer_name' => $data['customer'] ?? 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'branch_id' => $this->branch()['id'],
            'branch' => $this->branch()['name'],
            'status' => $saved ? 'محفوظ' : 'معلّق',
            'is_held' => true,
            'subtotal' => $data['total'] ?? 0,
            'total' => $data['total'] ?? 0,
            'ordered_at' => now(),
        ]);

        // حفظ الأصناف — بدونها لا يمكن استكمال الطلب لاحقًا
        foreach ($data['items'] as $i) {
            $order->items()->create([
                'product_id' => $i['id'] ?? null,
                'name' => $i['name'],
                'price' => $i['price'],
                'quantity' => $i['qty'],
                'note' => $i['note'] ?? null,
                'total' => $i['price'] * $i['qty'],
            ]);
        }

        return response()->json(['ok' => true, 'number' => $order->number]);
    }

    /** استكمال طلب معلّق/محفوظ: يعيد أصنافه إلى السلة */
    public function resume($id)
    {
        $order = Order::where('business_id', $this->bid())->where('is_held', true)
            ->with('items')->findOrFail($id);

        session()->flash('resume_cart', [
            'id' => $order->id,
            'customer' => $order->customer_name,
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->product_id,
                'name' => $i->name,
                'price' => (float) $i->price,
                'qty' => (int) $i->quantity,
                'note' => $i->note ?? '',
            ])->all(),
        ]);

        return redirect()->route('pos.index');
    }

    /** حذف طلب معلّق/محفوظ */
    public function discard($id)
    {
        $order = Order::where('business_id', $this->bid())->where('is_held', true)->findOrFail($id);
        $number = $order->number;
        $order->items()->delete();
        $order->delete();

        return back()->with('toast', ['msg' => __('تم حذف الطلب :number', ['number' => $number]), 'type' => 'warning']);
    }


    /** إضافة عميل سريع من نقطة البيع */
    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'tax_number' => ['nullable', 'string', 'max:50'],
        ]);
        $data['business_id'] = $this->bid();
        $data = \App\Support\Customers::localizeName($data);
        $customer = \App\Models\Customer::create($data);
        \App\Support\Activity::log('created', 'أضاف عميلًا من نقطة البيع: ' . $data['name']);

        // طلب AJAX من السلة: نُعيد العميل ليُحدَّد تلقائيًا للطلب الجاري بلا إعادة تحميل
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'label' => (app()->getLocale() === 'en' && filled($customer->name_en)) ? $customer->name_en : $customer->name,
                    'phone' => $customer->phone ?? '',
                ],
            ]);
        }

        return back()->with('toast', ['msg' => __('تم إضافة العميل'), 'type' => 'success']);
    }

    /**
     * منح نقاط الولاء للعميل المسجّل عند الشراء (يحترم إعدادات التفعيل والمعدّل).
     * المعدّل = نقاط لكل 1 ر.ع من إجمالي الطلب (افتراضي 1). يُرجِع عدد النقاط الممنوحة.
     */
    private function awardLoyaltyPoints(Order $order): int
    {
        $walkIn = 'عميل نقدي';
        if (empty($order->customer_name) || $order->customer_name === $walkIn) {
            return 0;
        }

        $bid = $this->bid();
        $enabled = \App\Models\Setting::where('business_id', $bid)->where('key', 'loyalty_enabled')->value('value');
        if ($enabled === '0') {
            return 0; // معطّل صراحةً (مفعّل افتراضيًا)
        }

        $rate = (float) (\App\Models\Setting::where('business_id', $bid)->where('key', 'loyalty_earn_rate')->value('value') ?? 1);
        if ($rate <= 0) {
            return 0;
        }

        $customer = \App\Models\Customer::where('business_id', $bid)->where('name', $order->customer_name)->first();
        if (! $customer) {
            return 0;
        }

        $points = (int) floor((float) $order->total * $rate);
        if ($points <= 0) {
            return 0;
        }

        $customer->increment('points', $points);
        $order->update(['customer_id' => $customer->id, 'points_earned' => $points]);

        return $points;
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
