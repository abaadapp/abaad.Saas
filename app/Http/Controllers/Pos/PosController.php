<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\Demo;
use App\Support\PosCashier;
use App\Support\Stock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    /** 100 نقطة = وحدة عملة واحدة — يجب أن تطابق POINTS_PER_UNIT في usePosCart.ts */
    private const POINTS_PER_UNIT = 100;


    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * نسبة ضريبة القيمة المضافة: إعداد النشاط، ثم الإعداد العام، ثم 5%.
     *
     * والمفتاح يُقرأ أوّلًا. كان في الإعدادات مقبضٌ يقول «تفعيل ضريبة القيمة
     * المضافة — تُحتسب على كل فاتورة بيع» ولا يقرؤه شيء: يُطفئه من لا ضريبة
     * عليه — ومعظم من يبيع في عُمان دون حدّ التسجيل كذلك — فتبقى الضريبة
     * تُضاف إلى كل فاتورة، ويُقرّ بها في التقرير الضريبي، ويجبيها من زبائنه
     * وهو غير مخوَّلٍ بجبايتها. مقبضٌ يطمئن ولا يفعل، في أخطر موضع.
     */
    private function vatRate(): float
    {
        $bid = $this->bid();

        if (! \App\Support\Vat::enabled($bid)) {
            return 0.0;
        }

        $v = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value')
            ?? \App\Models\Setting::whereNull('business_id')->where('key', 'vat_rate')->value('value');

        return max(0.0, (float) ($v ?? 5));
    }

    /**
     * ضريبة الفاتورة بنسبة كل صنف على حدة.
     *
     * كانت نسبةً واحدة على المجموع كلّه، فيدفع بائع الخبز ضريبةً على صنفٍ
     * صفريّ. والصنف الذي لا نسبة له يأخذ نسبة المتجر، فلا يتغيّر شيء لمن
     * لم يلمس الحقل.
     *
     * وخصم الفاتورة (كوبونًا كان أو نقاطًا) يُوزَّع على البنود بنسبة قيمتها:
     * حسمُه من وعاءٍ واحد يُنقص ضريبة الصنف الصفريّ ويُبقيها كاملةً على
     * الخاضع — وهو خطأٌ في الإقرار لا في الشاشة وحدها.
     */
    private function taxFor(array $lines, float $subtotal, float $discount): float
    {
        /*
         * الإطفاء يسبق كل نسبة — نسبة المتجر ونسبة الصنف معًا.
         *
         * ولا يكفي أن تصير نسبة المتجر صفرًا: الصنف الذي كُتبت له نسبةٌ خاصّة
         * لا يقرأ نسبة المتجر أصلًا، فيبقى يُضرَّب بضريبته وحده في متجرٍ
         * أطفأ الضريبة كلّها.
         */
        if (! \App\Support\Vat::enabled($this->bid())) {
            return 0.0;
        }

        $default = $this->vatRate();
        $inclusive = \App\Support\Vat::inclusive($this->bid());
        $tax = 0.0;

        foreach ($lines as $l) {
            $net = $l['price'] * $l['qty'];
            $share = $subtotal > 0 ? $net / $subtotal : 0;
            $taxable = $net - ($discount * $share);
            $rate = $l['product'] ? $l['product']->taxRate($default) : $default;

            /*
             * «مشمولة» تُستخرَج ولا تُضاف: ما على الرفّ هو ما يدفعه الزبون،
             * فالضريبة جزءٌ منه — ١٠٥ بنسبة ٥٪ ضريبتها ٥ لا ٥.٢٥.
             */
            $tax += $inclusive
                ? ($taxable * $rate) / (100 + $rate)
                : ($taxable * $rate) / 100;
        }

        return round($tax, 3);
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
        // نفس مصدر الشاشة عند فتحها: رصيد الفرع النشط. تغذيةٌ تقيس شيئًا
        // آخر غير ما عُرض أول مرّة تجعل الرقم يقفز بلا سبب ظاهر.
        $available = Stock::availabilityResolver($this->bid(), Demo::activeBranchId());

        $products = Product::where('business_id', $this->bid())
            ->orderBy('id')->get(['id', 'quantity', 'alert_qty'])
            ->map(function ($p) use ($available) {
                $qty = $available($p->id, (int) $p->quantity);

                return [
                    'id' => $p->id,
                    'qty' => $qty,
                    'stock_status' => Product::statusFor($qty, (int) $p->alert_qty),
                ];
            })->values();

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
     *
     * @param  bool  $lock  يقفل صفوف المنتجات حتى نهاية المعاملة.
     *
     * الكمية كانت تُقرأ بلا قفل ثم يُحكم عليها في assertStock ثم تُخصم —
     * وبين القراءة والخصم نافذة. كاشيران يبيعان آخر قطعة في اللحظة نفسها
     * يقرآن كلاهما «المتوفر 1»، فيمرّان معًا ويصير المخزون سالبًا وقد بيعت
     * قطعة لا وجود لها.
     *
     * لم يظهر ذلك على SQLite لأنها تقفل القاعدة كلها عند الكتابة فتُسلسِل
     * العمليات — القفل من المحرّك لا من الكود. وعلى PostgreSQL (وجهة النقل)
     * القراءات لا تتعارض، فالنافذة مفتوحة على مصراعيها.
     */
    private function priceItems(array $items, bool $lock = false): array
    {
        $bid = $this->bid();
        $query = Product::where('business_id', $bid)
            ->whereIn('id', collect($items)->pluck('id')->filter()->unique()->all());

        if ($lock) {
            // ترتيب ثابت: قفل الصفوف بترتيب مختلف بين عمليتين يُنتج تعارضًا دائريًا
            $query->orderBy('id')->lockForUpdate();
        }

        $products = $query->get()->keyBy('id');
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
                // السعر بعد خصم الصنف — والسعر قبله يُحفظ ليُطبع على الفاتورة
                $lines[] = ['product' => $product, 'name' => $product->name,
                    'price' => $product->sellingPrice(), 'list_price' => (float) $product->price,
                    'qty' => $qty, 'note' => $i['note'] ?? null];
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
                'price' => (float) $addon->price, 'list_price' => (float) $addon->price,
                'qty' => $qty, 'note' => $i['note'] ?? null];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /** يمنع البيع بما يتجاوز المتوفر — إلا إذا سمح النشاط بالمخزون السالب صراحةً */
    private function assertStock(array $lines, ?int $branchId = null): void
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

        // الحكم على رصيد الفرع الذي سيُخصم منه. الحكم على مجموع الشركة كان
        // يُجيز بيع خمس قطع من صلالة ورصيدها صفر لأن في مسقط عشرًا.
        $available = Stock::availabilityResolver(
            $this->bid(), $branchId, array_keys($needed), lock: true,
        );

        $short = [];
        foreach ($needed as $pid => $want) {
            $have = $available($pid, (int) $byId[$pid]->quantity);
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
    private function nextNumber(string $prefix, int $start = 1): string
    {
        $offset = strlen($prefix) + 1; // عدد صحيح من strlen، فلا خطر حقن هنا

        // SQLite تتساهل مع CAST لنصّ غير رقمي فتُرجع 0، أما PostgreSQL فترفع
        // «invalid input syntax for type integer». ولأن هذا السطر يجري مع كل
        // بيعة، رقمٌ واحد شاذّ — من نسخة مستعادة أو إدخال يدوي — كان يكفي
        // لتعطيل الصندوق كلّه بعد النقل إلى PostgreSQL.
        $driver = DB::connection()->getDriverName();
        $suffix = match ($driver) {
            'pgsql' => "NULLIF(regexp_replace(SUBSTRING(number FROM {$offset}), '\\D', '', 'g'), '')::bigint",
            'mysql', 'mariadb' => "CAST(SUBSTRING(number, {$offset}) AS UNSIGNED)",
            default => "CAST(SUBSTR(number, {$offset}) AS INTEGER)",
        };

        $last = Order::where('business_id', $this->bid())
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw("{$suffix} DESC")
            ->value('number');

        // أوّل فاتورةٍ بهذه البادئة تبدأ من الرقم الذي اختاره التاجر، وما بعدها
        // يتبع الأخير. ولذلك تغيير البادئة يفتح تسلسلًا جديدًا لا يصطدم بالقديم.
        $n = $last ? (int) substr($last, strlen($prefix)) : max(0, $start - 1);

        return $prefix . str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * بادئة رقم الفاتورة كما اختارها التاجر.
     *
     * كانت 'INV-' مثبّتةً في الكود بينما الإعدادات تعرض حقلًا يُحفظ ولا يُقرأ.
     * والتنقية ليست زينة: البادئة تدخل شرط LIKE، فـ«%» فيها تجعل كل فاتورةٍ
     * مطابقةً فيقفز العدّاد إلى رقمٍ لا يخصّها.
     */
    private function salePrefix(): string
    {
        $raw = trim((string) $this->setting('inv_prefix', 'INV-'));
        $clean = str_replace(['%', '_', '\\'], '', $raw);

        return $clean === '' ? 'INV-' : mb_substr($clean, 0, 12);
    }

    /**
     * طرق الدفع التي أذن بها التاجر.
     *
     * كانت مفاتيح pay_* تُحفظ ولا يقرؤها أحد: يُطفئ التاجر «بطاقة» فتبقى
     * معروضةً في الصندوق ومقبولةً — ثم يحاسب موظفًا قبِل ما ظنّ أنه منعه.
     *
     * ولا تعود فارغةً أبدًا: من أطفأ الثلاث لا يُراد به أن يقف البيع، فيبقى
     * النقد. حجبُ وسيلةٍ إعدادٌ، وإيقافُ الصندوق عطل.
     */
    public static function enabledPaymentMethods(array $settings): array
    {
        $all = ['نقدي' => 'pay_cash', 'بطاقة' => 'pay_card', 'تحويل بنكي' => 'pay_transfer'];
        $on = [];
        foreach ($all as $label => $key) {
            if (($settings[$key] ?? '1') !== '0') {
                $on[] = $label;
            }
        }

        return $on ?: ['نقدي'];
    }

    /**
     * وسيلة الدفع تُختار ولا تُخمَّن.
     *
     * كانت تُردّ إلى أوّل المأذون حين تغيب أو لا تُعرف — أي أنّ بيعةً بالبطاقة
     * وصلت بوسيلةٍ خاطئة تُقيَّد «نقدي»، وبيعةً بلا وسيلةٍ أصلًا تُقيَّد نقدًا.
     * وأثرُ ذلك في الدرج لا في الشاشة: إقفال الوردية يطلب مالًا لم يدخل
     * الصندوق، فيقف الكاشير أمام عجزٍ لم يُحدثه ويُعوّضه من جيبه أو يُسجّله
     * فرقًا. وهو أسوأ صنف من العطب: كلّ ما يُرى منه سليم.
     *
     * فصارت مطلوبةً في التحقّق، وهذه تحرس ما بعده: قيمةٌ خارج المأذون تُردّ
     * بخطأ تحقّقٍ لا بتخمين.
     */
    private function paymentMethod(?string $requested): string
    {
        $allowed = self::enabledPaymentMethods(Demo::businessSettings());

        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_method' => __('اختر وسيلة الدفع.'),
            ]);
        }

        return $requested;
    }

    /** ينشئ الطلب برقم فريد، ويعيد المحاولة إن سبقه كاشير آخر إلى الرقم نفسه */
    private function createNumbered(array $attrs, string $prefix, int $start = 1): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::create($attrs + ['number' => $this->nextNumber($prefix, $start)]);
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
        $branch = \App\Models\Branch::where('business_id', $this->bid())
            ->find(Demo::activeBranchId());

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

        // نفس التجريد المطبَّق على القائمة: البحث بلا هذا يُبطل الحجب كلّه،
        // لأنه يتجاوز الثلاثين إلى تاريخ الفرع كلّه
        return response()->json([
            'receipts' => \App\Support\ReceiptVisibility::filter(Demo::receipts($q, 50)),
        ]);
    }

    /**
     * فاتورة واحدة بتفاصيلها — تُفتح بالنقر على رقمها.
     *
     * تفصيلُ فاتورةٍ بعينها متاح للجميع: الزبون يستلمها مطبوعة على أي حال،
     * والكاشير يحتاجها عند الإرجاع. الممنوع هو الاطّلاع بالجملة.
     */
    public function showReceipt(string $number)
    {
        $receipt = collect(Demo::receipts($number, 50))->firstWhere('number', $number);

        abort_if($receipt === null, 404);

        return response()->json(['receipt' => $receipt]);
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
            $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
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
        /*
         * لا بيع بلا وردية مفتوحة.
         *
         * بيعةٌ خارج وردية لا تدخل في حساب أي درج: تُقبض نقدًا وتغيب عن
         * المتوقّع، فيظهر الدرج زائدًا بلا تفسير — ويصير الإقفال طقسًا لا
         * يكشف شيئًا. والباب هنا لا في الواجهة وحدها: الطلب يصل من جهازٍ
         * قد تكون شاشته قديمة.
         */
        $shift = \App\Support\Shifts::current();
        if (! $shift && \App\Support\Shifts::blocksSelling()) {
            return response()->json([
                'ok' => false,
                'shift_required' => true,
                'message' => __('افتح وردية الصندوق قبل البيع.'),
            ], 409);
        }

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
            // المعرّف هو ما تتبعه النقاط؛ والهاتف مرجعٌ ثانٍ حين يغيب
            'customer_id' => ['nullable', 'integer'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            // مطلوبة: انظر `paymentMethod` أدناه لأثر تخمينها في إقفال الوردية
            'payment_method' => ['required', 'string'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'resume_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            /*
             * تفاصيل طلب الورد — اختياريّةٌ كلّها.
             *
             * بيعةُ المارّ يجب أن تبقى ثلاث نقرات: يضع الباقة، يضغط الدفع،
             * ينتهي. وإلزامُ المستلِم والموعد على كلّ بيعةٍ يجعل الكاشير يملأ
             * حقولًا لا معنى لها في نصف بيعات اليوم — فيملؤها بأيّ شيء،
             * وتصير البيانات أسوأ من غيابها.
             */
        ] + \App\Support\FlowerOrder::rules(), [
            'payment_method.required' => __('اختر وسيلة الدفع.'),
        ] + \App\Support\FlowerOrder::messages());

        // والتوصيل وحده يُسأل عن مستلِمه وعنوانه — شرطٌ بين حقول لا على حقل
        if ($flowerErrors = \App\Support\FlowerOrder::afterValidation($data)) {
            throw \Illuminate\Validation\ValidationException::withMessages($flowerErrors);
        }

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
        $result = DB::transaction(function () use ($data, $shift) {
            $bid = $this->bid();
            $branch = $this->branch();

            // بقفل: الفحص والخصم يجب أن يقعا على كمية لا تتغيّر تحتهما،
            // وعلى رصيد الفرع الذي سيُخصم منه لا على مجموع الشركة
            $lines = $this->priceItems($data['items'], lock: true);
            $this->assertStock($lines, $branch['id']);

            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']), 3);

            // الكوبون: يُعاد التحقق منه خادميًا وتُحتسب قيمته من أسعارنا نحن
            $coupon = $this->findCoupon($data['coupon_code'] ?? null);
            $couponApplied = $coupon && $coupon->isValid() && $subtotal >= (float) $coupon->min_order;
            $couponDiscount = $couponApplied ? min((float) $coupon->discountFor($subtotal), $subtotal) : 0.0;

            // موعدٌ في المستقبل يعني طلبًا يُجهَّز لا بيعةً انتهت — انظر 'status' أدناه
            $scheduled = filled($data['scheduled_for'] ?? null);

            $customer = $this->customerFor($data['customer'] ?? null, $data['customer_id'] ?? null, $data['customer_phone'] ?? null);
            $redeem = $this->resolveRedemption($customer, $subtotal, $couponDiscount, (int) ($data['redeem_points'] ?? 0));

            $discount = round(min($couponDiscount + $redeem['discount'], $subtotal), 3);
            $delivery = (float) ($data['delivery_fee'] ?? 0);
            $tax = $this->taxFor($lines, $subtotal, $discount);

            /*
             * «مشمولة»: المعروض هو المستحقّ، فالمجموع الفرعي يُنقص منه ما
             * استُخرج ضريبةً — ويبقى `subtotal - discount + tax` مساويًا لما
             * قرأه الزبون على الشاشة. وبلا هذا تُجمع الضريبة مرّتين: مرّةً
             * داخل السعر ومرّةً فوقه.
             */
            if (\App\Support\Vat::inclusive($bid)) {
                $subtotal = round($subtotal - $tax, 3);
            }

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
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                // تُنسب إلى الوردية إن كانت مفتوحة، ولو كان المنع مطفأً
                'shift_id' => $shift?->id,
                /*
                 * الصندوق الذي خرجت منه الفاتورة.
                 *
                 * يُقرأ من الخادم لا من الطلب: القيمة الوحيدة الموثوقة هي
                 * رمز الجهاز في الكوكي الموقَّعة. وحين ينقص الدرج عشرين ريالًا
                 * في محلٍّ فيه ثلاثة صناديق، هذا العمود وحده يقول أيّها.
                 */
                'pos_device_id' => \App\Support\PosTerminal::current()?->id,
                'payment_method' => $this->paymentMethod($data['payment_method'] ?? null),
                'payment_status' => 'مدفوع',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponApplied ? $coupon->code : null,
                // ثمن العرض وحده: `discount` يجمعه مع نقاط الولاء فلا يُعرف
                // كم كلّف كوبونٌ بعينه — وهو ما يقرّر إعادته أو إيقافه
                'coupon_discount' => $couponDiscount,
                'tax' => $tax,
                'delivery_fee' => $delivery,
                'total' => $total,
                'ordered_at' => now(),
                /*
                 * الحالة تتبع الطلب لا العكس.
                 *
                 * بيعةُ المنضدة تُدفع وتُؤخذ في اللحظة نفسها فهي «مكتمل» كما
                 * كانت. أمّا ما له موعدٌ في المستقبل فلم يكتمل شيء منه بعد:
                 * يُسجَّل «جديد» ليدخل لوحة التجهيز. ولو بقي «مكتمل» لَما ظهر
                 * لعامل التجهيز أبدًا — فيُجهَّز الطلب بورقةٍ على الجدار كما
                 * كان قبل النظام.
                 */
                'status' => $scheduled
                    ? \App\Support\OrderStatus::PENDING
                    : \App\Support\OrderStatus::COMPLETED,
            ] + \App\Support\FlowerOrder::attributes($data),
                $this->salePrefix(), max(1, (int) $this->setting('inv_start', 1)));

            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    /*
                     * تكلفة القطعة تُلتقط يوم البيع لا تُقرأ يوم التقرير.
                     *
                     * تكلفة المنتج تُكتب فوقها عند كل استلامٍ بآخر سعر شراء،
                     * فقراءتُها لاحقًا تجعل ربح الشهر الماضي يتغيّر لأن المورّد
                     * رفع سعره اليوم. واللقطة تُثبّت ما مضى.
                     */
                    'cost' => (float) ($l['product']?->cost ?? 0),
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                ]);

                if (! $l['product']) {
                    continue;
                }
                $product = $l['product'];
                // التوزيع أولًا ثم الخصم — وإلا بدأ صفّ الفرع من صفر فصار سالبًا
                \App\Models\BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);
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
                    'employee_name' => PosCashier::name(),
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
                // «دخل» وحدها لا تكفي: تقرأها التقارير مبيعاتٍ ويقرأها
                // إيداعُ المالك دخلًا كذلك — انظر Transaction::SALE
                'kind' => \App\Models\Transaction::SALE,
                'amount' => $order->total,
                'tax_amount' => $order->tax ?? 0,
                'employee_name' => PosCashier::name(),
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

        /*
         * البيعة تُرحَّل إلى دفتر الأستاذ — بعد أن تتمّ لا داخلها.
         *
         * خارج معاملة البيع عمدًا: البيع سبع كتابات مترابطة تُنفَّذ كلّها أو
         * لا تُنفَّذ أيّها، والدفتر ليس منها. حسابٌ أغلقه التاجر في شجرته يجب
         * ألّا يمنع بيعةً من أن تتمّ والزبون واقفٌ عند الصندوق — لكنّه لا يمرّ
         * صامتًا: يُكتب في السجلّ برقم الفاتورة ويُستدرَك بأمر
         * `sales:post-ledger` (انظر Books::trySale).
         *
         * ولا تُرحَّل مرّتين: الطلب المكرَّر يعود من فحص `client_uuid` أعلاه
         * قبل أن يصل إلى هنا، ولو وصل لَردّه سؤالُ الدفتر عن قيدٍ حيّ.
         */
        \App\Support\Books::trySale($order, PosCashier::id());

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
            'coupon_code' => ['nullable', 'string', 'max:40'],
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
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                'status' => $saved ? 'محفوظ' : 'معلّق',
                'is_held' => true,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                // الكود وحده يُحفظ لا قيمة خصمه: الطلب قد يُستكمل غدًا وقد
                // يكون الكوبون انتهى أو نفدت مرات استخدامه، فيُعاد التحقق
                // منه وقت الدفع لا وقت التعليق.
                'coupon_code' => $data['coupon_code'] ?? null,
                'ordered_at' => now(),
            ], $saved ? 'SAVE-' : 'HOLD-');

            // حفظ الأصناف — بدونها لا يمكن استكمال الطلب لاحقًا
            foreach ($lines as $l) {
                $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    // لقطة التكلفة — انظر التعليق في إتمام البيع
                    'cost' => (float) ($l['product']?->cost ?? 0),
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
            // يعود الكود إلى السلة لتُعيد الواجهة تطبيقه، فيراه الكاشير
            // ويُحتسب عند الدفع. لا نُعيد قيمة الخصم — تُحسب من جديد.
            'coupon_code' => $order->coupon_code,
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
    /**
     * إضافة مناسبةٍ للمتجر من نافذة الدفع.
     *
     * لا شاشة إعداداتٍ لها: من لم يجد مناسبته يجدها وهو واقفٌ أمام الزبون،
     * لا بعد أن يخرج من الصندوق ويفتح الإعدادات ويعود. والزبون ينتظر.
     */
    public function storeOccasion(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:'.\App\Support\FlowerOrder::CUSTOM_LABEL_MAX],
        ], [
            'label.required' => __('اكتب اسم المناسبة.'),
            'label.min' => __('اسم المناسبة قصير جدًّا.'),
        ]);

        try {
            $added = \App\Support\FlowerOrder::addOccasion($data['label'], $this->bid());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        \App\Support\Activity::log('created', 'أضاف مناسبة: '.$added['value']);

        return response()->json(['ok' => true] + $added);
    }

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            // لا name_en: localizeName أدناه يشتقّه من الاسم المُدخَل
            'name' => ['required', 'string', 'max:255'],
            'phone' => \App\Support\Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email'],
            'tax_number' => ['nullable', 'string', 'max:50'],
        ], [
            'phone.unique' => __('هذا الرقم مسجَّل لعميل آخر — نقاط الولاء تتبع الرقم.'),
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
    /**
     * عميل النشاط — بالمعرّف، ثم بالهاتف، ثم بالاسم إن كان فريدًا.
     *
     * كان يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود. والاسم ليس مفتاحًا: متجرٌ
     * فيه ثلاثة باسم «محمد» كان يمنح نقاط شراء كلٍّ منهم لأوّلهم في الجدول،
     * ويخصم رصيده هو عند استبدال غيره. النقاط مالٌ فعلي، فالخلط فيها خسارة
     * لصاحبها وهبةٌ لسواه — ولا يظهر شيء من ذلك في أي شاشة.
     *
     * والهاتف هو ما يعرّف الشخص عند التاجر فعلًا، فهو المرجع الثاني.
     *
     * وحين يبقى الاسم وحده ويطابق أكثر من واحد: لا يُربط أحد. بيعةٌ بلا نقاط
     * يشتكي منها العميل فتُصحَّح، ونقاطٌ تذهب لغير صاحبها لا يلحظها أحد.
     */
    private function customerFor(?string $name, ?int $id = null, ?string $phone = null): ?\App\Models\Customer
    {
        $scope = fn () => \App\Models\Customer::where('business_id', $this->bid());

        if ($id) {
            return $scope()->find($id);
        }

        if (filled($phone)) {
            $found = $scope()->where('phone', $phone)->first();
            if ($found) {
                return $found;
            }
        }

        if (empty($name) || $name === 'عميل نقدي') {
            return null;
        }

        // الكاشير الإنجليزي يرى name_en ويرسله، فنطابق العمودين معًا:
        // المطابقة بالعربي وحده كانت تُسقط ربط العميل ونقاط ولائه.
        $matches = $scope()
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $name))
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
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
