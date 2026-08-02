<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\Demo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    /** 100 نقطة = وحدة عملة واحدة — يجب أن تطابق POINTS_PER_UNIT في usePosCart.ts */
    private const POINTS_PER_UNIT = 100;

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** نسبة ضريبة القيمة المضافة: إعداد النشاط، ثم الإعداد العام، ثم 5% */
    private function vatRate(): float
    {
        $bid = $this->bid();
        $v = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value')
            ?? \App\Models\Setting::whereNull('business_id')->where('key', 'vat_rate')->value('value');

        return max(0.0, (float) ($v ?? 5));
    }

    private function setting(string $key, $default = null)
    {
        return \App\Models\Setting::where('business_id', $this->bid())->where('key', $key)->value('value') ?? $default;
    }

    /**
     * تغذية المخزون — الكميات وحدها، تُستطلَع من شاشة البيع كل بضع ثوانٍ.
     *
     * بعد بيعِه هو يحدّث الكاشير قائمته بـreload جزئي، لكن بيع زميله على
     * جهاز آخر (أو تعديل المخزون من اللوحة، أو استلام أمر شراء) كان يبقى
     * خفيًّا حتى تُحدَّث الصفحة — فيَعِد الزبون بصنف نفد ثم يُرفض عند الدفع.
     *
     * لا يُعيد المنتجات كاملة عن قصد: الاسم والسعر والصورة لا تتغيّر كل
     * عشرين ثانية، والكمية هي وحدها المتحرّكة. حمولة أخفّ عشرات المرّات
     * على شبكة متجر قد تكون بطيئة.
     */
    public function stockFeed()
    {
        $products = Product::where('business_id', $this->bid())
            ->orderBy('id')->get(['id', 'quantity', 'alert_qty'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'qty' => $p->quantity,
                'stock_status' => $p->stock_status,
            ])->values();

        return response()->json([
            'products' => $products,
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * يُسعّر بنود السلة من قاعدة البيانات لا من الطلب.
     *
     * سعر العميل مُدخَل غير موثوق: قبولُه كما يأتي كان يسمح ببيع منتج حقيقي
     * بـ0.001 أو بسعر سالب يقيّد "دخلًا" سالبًا في المالية. كل بند هنا يجب أن
     * يطابق منتجًا أو إضافة ضمن نفس النشاط، وإلا رُفض الطلب كله.
     */
    private function priceItems(array $items): array
    {
        $bid = $this->bid();
        $products = Product::where('business_id', $bid)
            ->whereIn('id', collect($items)->pluck('id')->filter()->unique()->all())
            ->get()->keyBy('id');
        $addons = \App\Models\Addon::where('business_id', $bid)->get();

        $lines = [];
        $errors = [];

        foreach ($items as $idx => $i) {
            $qty = max(1, (int) $i['qty']);

            if (! empty($i['id'])) {
                $product = $products->get((int) $i['id']);
                if (! $product) {
                    $errors["items.$idx.id"] = __('صنف غير موجود في هذا المتجر.');
                    continue;
                }
                $lines[] = ['product' => $product, 'name' => $product->name,
                    'price' => (float) $product->price, 'qty' => $qty, 'note' => $i['note'] ?? null];
                continue;
            }

            // إضافة: بالمعرّف إن أُرسل، وإلا بالاسم — لطلبات مؤجَّلة رُفعت من نسخة أقدم من الواجهة
            $addon = ! empty($i['addon_id'])
                ? $addons->firstWhere('id', (int) $i['addon_id'])
                : $addons->first(fn ($a) => $a->name === ($i['name'] ?? null) || $a->name_en === ($i['name'] ?? null));

            if (! $addon || ! $addon->active) {
                $errors["items.$idx.name"] = __('صنف غير متاح للبيع.');
                continue;
            }
            $lines[] = ['product' => null, 'name' => $addon->name,
                'price' => (float) $addon->price, 'qty' => $qty, 'note' => $i['note'] ?? null];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /** يمنع البيع بما يتجاوز المتوفر — إلا إذا سمح النشاط بالمخزون السالب صراحةً */
    private function assertStock(array $lines): void
    {
        if ((string) $this->setting('allow_negative_stock', '0') === '1') {
            return;
        }

        // نفس المنتج قد يرد في أكثر من بند، فالحكم على المجموع لا على كل بند وحده
        $needed = [];
        $byId = [];
        foreach ($lines as $l) {
            if ($l['product']) {
                $needed[$l['product']->id] = ($needed[$l['product']->id] ?? 0) + $l['qty'];
                $byId[$l['product']->id] = $l['product'];
            }
        }

        $short = [];
        foreach ($needed as $pid => $want) {
            $have = (int) $byId[$pid]->quantity;
            if ($have < $want) {
                $short[] = __(':name — المتوفر :have والمطلوب :want', [
                    'name' => $byId[$pid]->name, 'have' => $have, 'want' => $want,
                ]);
            }
        }

        if ($short) {
            throw ValidationException::withMessages(['items' => $short]);
        }
    }

    /**
     * رقم متسلسل لكل نشاط.
     *
     * كان random_int(78900, 99999) بلا قيد فريد: 21,100 قيمة فقط تعني احتمال
     * تصادم ≈61% خلال 200 فاتورة — أي فاتورتين مختلفتين تحملان الرقم نفسه.
     */
    private function nextNumber(string $prefix): string
    {
        $offset = strlen($prefix) + 1; // عدد صحيح من strlen، فلا خطر حقن هنا
        $last = Order::where('business_id', $this->bid())
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw("CAST(SUBSTR(number, $offset) AS INTEGER) DESC")
            ->value('number');

        $n = $last ? (int) substr($last, strlen($prefix)) : 0;

        return $prefix . str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /** ينشئ الطلب برقم فريد، ويعيد المحاولة إن سبقه كاشير آخر إلى الرقم نفسه */
    private function createNumbered(array $attrs, string $prefix): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::create($attrs + ['number' => $this->nextNumber($prefix)]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('تعذّر توليد رقم فاتورة فريد.');
    }

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
            'items.*.addon_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string'],
            // السعر يُقرأ من القاعدة لا من الطلب؛ يُقبل الحقل للتوافق ويُتجاهل
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'resume_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
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

        // البيع سبع كتابات مترابطة (طلب، بنود، مخزون، حركات، معاملة، نقاط، تنظيف
        // المعلّق). انقطاعٌ في المنتصف كان يترك طلبًا بلا معاملة مالية أو مخزونًا
        // منقوصًا بلا فاتورة — فتُنفَّذ كلها أو لا تُنفَّذ أيٌّ منها.
        $result = DB::transaction(function () use ($data) {
            $bid = $this->bid();
            $branch = $this->branch();

            $lines = $this->priceItems($data['items']);
            $this->assertStock($lines);

            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']), 3);

            // الكوبون: يُعاد التحقق منه خادميًا وتُحتسب قيمته من أسعارنا نحن
            $coupon = $this->findCoupon($data['coupon_code'] ?? null);
            $couponApplied = $coupon && $coupon->isValid() && $subtotal >= (float) $coupon->min_order;
            $couponDiscount = $couponApplied ? min((float) $coupon->discountFor($subtotal), $subtotal) : 0.0;

            $customer = $this->customerFor($data['customer'] ?? null);
            $redeem = $this->resolveRedemption($customer, $subtotal, $couponDiscount, (int) ($data['redeem_points'] ?? 0));

            $discount = round(min($couponDiscount + $redeem['discount'], $subtotal), 3);
            $delivery = (float) ($data['delivery_fee'] ?? 0);
            $tax = round((($subtotal - $discount) * $this->vatRate()) / 100, 3);
            $total = round($subtotal - $discount + $tax + $delivery, 3);

            if ($couponApplied) {
                $coupon->increment('used_count');
            }

            $order = $this->createNumbered([
                'business_id' => $bid,
                'client_uuid' => $data['client_uuid'] ?? null,
                'customer_name' => $customer?->name ?? $data['customer'] ?? 'عميل نقدي',
                'customer_name_en' => $customer?->name_en,
                'customer_id' => $customer?->id,
                'employee_name' => auth()->user()->name,
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                'status' => 'مكتمل',
                'payment_method' => $data['payment_method'] ?? 'نقدي',
                'payment_status' => 'مدفوع',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponApplied ? $coupon->code : null,
                'tax' => $tax,
                'delivery_fee' => $delivery,
                'total' => $total,
                'ordered_at' => now(),
            ], 'INV-');

            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                ]);

                if (! $l['product']) {
                    continue;
                }
                $product = $l['product'];
                $product->decrement('quantity', $l['qty']);
                \App\Models\BranchStock::adjust($bid, $branch['id'], $product->id, -$l['qty']);
                // تسجيل البيع كحركة مخزون ليكتمل سجل التدقيق (كم نقص ولماذا)
                \App\Models\InventoryMovement::create([
                    'business_id' => $bid,
                    'branch_id' => $branch['id'],
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'type' => 'بيع',
                    'quantity' => '-' . $l['qty'],
                    'employee_name' => auth()->user()->name,
                ]);
            }

            // تسجيل البيع كمعاملة دخل في المالية تلقائيًا (لتظهر المبيعات في لوحات المالية فورًا)
            \App\Models\Transaction::create([
                'business_id' => $bid,
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
                $held = Order::where('business_id', $bid)->where('is_held', true)->find($data['resume_id']);
                if ($held) {
                    $held->items()->delete();
                    $held->delete();
                }
            }

            $loyalty = $this->recordLoyalty($order, $customer, $redeem['points']);

            return ['order' => $order, 'loyalty' => $loyalty];
        });

        $order = $result['order'];

        \App\Support\Activity::log('checkout', 'أتمّ بيعًا ' . $order->number . ' بقيمة ' . number_format($order->total, 3) . ' ر.ع', ['subject_id' => $order->id]);

        // البريد خارج المعاملة: بطؤه أو فشله يجب ألّا يُبقي القفل أو يُلغي بيعًا تمّ
        $this->notifyNewOrder($order);

        return response()->json([
            'ok' => true,
            'invoice' => $order->number,
            'total' => (float) $order->total,
            'points_earned' => $result['loyalty']['earned'],
            'points_redeemed' => $result['loyalty']['redeemed'],
        ]);
    }

    /** تعليق الطلب */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            'total' => ['nullable', 'numeric'],
            // معلّق = بانتظار الاستكمال الآن · محفوظ = مسودّة للرجوع إليها لاحقًا
            'kind' => ['nullable', 'in:hold,save'],
        ]);
        $saved = ($data['kind'] ?? 'hold') === 'save';

        // المعلّق يُستكمل لاحقًا فيصير فاتورة، فأسعاره تُقرأ من القاعدة أيضًا.
        // ولا حارس مخزون هنا: التعليق لا يخصم شيئًا، والحارس يعمل عند الدفع.
        return DB::transaction(function () use ($data, $saved) {
            $lines = $this->priceItems($data['items']);
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']), 3);
            $branch = $this->branch();

            $order = $this->createNumbered([
                'business_id' => $this->bid(),
                'customer_name' => $data['customer'] ?? 'عميل نقدي',
                'employee_name' => auth()->user()->name,
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                'status' => $saved ? 'محفوظ' : 'معلّق',
                'is_held' => true,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'ordered_at' => now(),
            ], $saved ? 'SAVE-' : 'HOLD-');

            // حفظ الأصناف — بدونها لا يمكن استكمال الطلب لاحقًا
            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                ]);
            }

            return response()->json(['ok' => true, 'number' => $order->number]);
        });
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
            // لا name_en: localizeName أدناه يشتقّه من الاسم المُدخَل
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
     * نقاط الولاء للعميل المسجّل: تستبدل النقاط المطلوبة (خصم) ثم تمنح نقاط الشراء.
     * تحترم إعداد التفعيل والمعدّل، وتربط الطلب بالعميل. تُرجِع ['earned'=>x, 'redeemed'=>y].
     */
    /** عميل النشاط بالاسم — أو null للعميل النقدي */
    private function customerFor(?string $name): ?\App\Models\Customer
    {
        if (empty($name) || $name === 'عميل نقدي') {
            return null;
        }

        // الكاشير الإنجليزي يرى name_en ويرسله، فنطابق العمودين معًا:
        // المطابقة بالعربي وحده كانت تُسقط ربط العميل ونقاط ولائه.
        return \App\Models\Customer::where('business_id', $this->bid())
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $name))
            ->first();
    }

    /**
     * كم نقطة تُستبدَل فعلًا وكم تساوي خصمًا — يُحتسب قبل بناء الفاتورة.
     *
     * يطابق سقف usePosCart: نسبة من المجموع الفرعي، ولا يتجاوز المتبقّي بعد الكوبون،
     * ولا رصيد العميل. النقاط مالٌ فعلي، فلا تُؤخذ قيمة الخصم من العميل.
     */
    private function resolveRedemption(?\App\Models\Customer $customer, float $subtotal, float $couponDiscount, int $requested): array
    {
        $none = ['points' => 0, 'discount' => 0.0];

        if (! $customer || $requested <= 0 || (string) $this->setting('loyalty_enabled', '1') === '0') {
            return $none;
        }

        // الحد الأدنى لبدء الاستبدال: تحته تتراكم النقاط فقط
        $redeemMin = max(0, (int) $this->setting('loyalty_redeem_min', 100));
        if ((int) $customer->points < $redeemMin) {
            return $none;
        }

        $maxPct = max(0, min(100, (int) $this->setting('loyalty_redeem_max_pct', 50)));
        $cap = min($subtotal * $maxPct / 100, max(0.0, $subtotal - $couponDiscount));

        $points = min($requested, (int) $customer->points, (int) floor($cap * self::POINTS_PER_UNIT));
        if ($points <= 0) {
            return $none;
        }

        return ['points' => $points, 'discount' => $points / self::POINTS_PER_UNIT];
    }

    /** يقيّد الاستبدال والاكتساب على العميل بعد اكتمال الفاتورة */
    private function recordLoyalty(Order $order, ?\App\Models\Customer $customer, int $redeemPoints): array
    {
        if (! $customer || (string) $this->setting('loyalty_enabled', '1') === '0') {
            return ['earned' => 0, 'redeemed' => 0];
        }

        if ($redeemPoints > 0) {
            $customer->decrement('points', $redeemPoints);
            \App\Models\PointTransaction::record($customer, 'redeem', $redeemPoints, (int) $customer->points, $order->id, 'استبدال عند البيع — فاتورة ' . $order->number);
        }

        // اكتساب نقاط الشراء (على الإجمالي بعد الخصم)
        $earned = 0;
        $rate = (float) $this->setting('loyalty_earn_rate', 5);
        if ($rate > 0) {
            $earned = (int) floor((float) $order->total * $rate);
            if ($earned > 0) {
                $customer->increment('points', $earned);
                \App\Models\PointTransaction::record($customer, 'earn', $earned, (int) $customer->points, $order->id, 'اكتساب من الشراء — فاتورة ' . $order->number);
            }
        }

        $order->points_earned = $earned;
        $order->redeemed_points = $redeemPoints;
        $order->save();

        return ['earned' => $earned, 'redeemed' => $redeemPoints];
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
