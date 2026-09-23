<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Books;
use App\Support\Customers;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use App\Support\Money;
use App\Support\OrderNumbers;
use App\Support\OrderStatus;
use App\Support\Boutiques;
use App\Support\PaymentMethods;
use App\Support\Recipe;
use App\Support\SaleLines;
use App\Support\SalesChannel;
use App\Support\StockLedger;
use App\Support\Vat;
use App\Support\Website\Shelf;
use App\Support\WhatsAppPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إتمامُ الطلب من الموقع — سلّةٌ تصير طلبًا حقيقيًّا في أبعاد.
 *
 * ═══ البابُ الثاني للبيع ═══
 *
 * كان الصندوقُ البابَ الوحيد الذي يُنشئ طلبًا، وطلبُ الموقع رسالةَ واتساب.
 * وهذا بابٌ ثانٍ يُنشئ الطلبَ نفسَه بالقواعد نفسِها: السعرُ من القاعدة لا من
 * المتصفّح (`SaleLines::priceItems`)، والرفُّ يُفحص ويُخصم (`assertStock`
 * و`StockLedger`)، والضريبةُ بنسبة كلّ صنف (`taxFor`)، والرقمُ من التسلسل
 * نفسِه (`OrderNumbers`)، والقيدُ من `Books::recordSale`. فطلبُ الموقع
 * وطلبُ الصندوق عن السلّة نفسِها يكتبان في الدفتر الشيءَ نفسَه حرفًا.
 *
 * ═══ وما يفترق ═══
 *
 * - القناةُ `website` على الطلب، فيُعرف في التقارير.
 * - الحالةُ «جديد» لا «مكتمل»: طلبٌ يُجهَّز ويُوصَّل، ويدخل لوحةَ التجهيز
 *   بموعده. وحين يؤكّده صاحبُ المحلّ تخرج رسالةُ واتساب كسائر الطلبات.
 * - السدادُ «غير مدفوع» — تحويلًا بنكيًّا كان أو عند الاستلام: لا مالَ
 *   دخل بعد، والدفترُ يُدين الذمم كما يفعل في البيع الآجل.
 * - لا بطاقةَ ولا بوّابة: حقولُ البطاقة لا تُرسم حتى تُربط بوّابةٌ — أرقامُ
 *   بطاقاتٍ بلا بوّابة لا تُجمع.
 * - لا نقاطَ ولاء ولا موسم: لا كاشيرَ اختار موسمًا، ولا نقاطٌ تُحتسب لزبونٍ
 *   لم يُسجَّل من الصندوق. يُقال ذلك ولا يُدَّعى غيرُه.
 */
final class WebCheckout
{
    /** أقصى بنودٍ في سلّةٍ واحدة — حمايةٌ من الإغراق لا حدٌّ يُلمس */
    public const MAX_LINES = 40;

    public const MAX_QTY = 50;

    public const PAY_COD = 'cod';

    public const PAY_TRANSFER = 'transfer';

    /** أقصى أيامٍ يُحجز الموعدُ بعدها */
    public const MAX_DAYS_AHEAD = 60;

    /* ═══════════ الإعدادات ═══════════ */

    /**
     * إعداداتُ التوصيل والدفع كما ضبطها صاحبُ المحلّ.
     *
     * @return array{fee: float, free_over: ?float, areas: list<string>, slots: list<string>, hours: string, note: string, cod: bool, transfer: bool, bank: string, allow_orders: bool}
     */
    public static function settings(int $businessId): array
    {
        $site = MarketingSettings::group($businessId, 'website');
        $list = fn (string $raw): array => array_values(array_filter(array_map('trim', preg_split('/[\n,،]+/u', $raw) ?: [])));

        return [
            'fee' => round(max(0.0, (float) ($site['store_delivery_fee'] ?: 0)), 3),
            'free_over' => $site['store_free_delivery_over'] !== '' ? round((float) $site['store_free_delivery_over'], 3) : null,
            'areas' => $list((string) $site['store_delivery_areas']),
            'slots' => $list((string) $site['store_delivery_slots']),
            'hours' => trim((string) $site['store_hours']),
            'note' => trim((string) $site['store_delivery_note']),
            'cod' => ($site['store_pay_cod'] ?? '1') === '1',
            'transfer' => ($site['store_pay_transfer'] ?? '0') === '1',
            'bank' => trim((string) $site['store_bank']),
            'allow_orders' => ($site['store_allow_orders'] ?? '1') === '1',
        ];
    }

    /**
     * وسائلُ الدفع المتاحة على الموقع — بمفاتيحها ووسيلتها في الطلب.
     *
     * @return array<string, string> [cod|transfer => وسيلةُ الدفع كما تُكتب في الطلب]
     */
    public static function payments(int $businessId): array
    {
        $s = self::settings($businessId);
        $out = [];
        if ($s['cod']) {
            $out[self::PAY_COD] = PaymentMethods::CASH;
        }
        if ($s['transfer']) {
            $out[self::PAY_TRANSFER] = PaymentMethods::TRANSFER;
        }

        return $out;
    }

    /** أيقبل هذا المتجر طلبًا من موقعه الآن؟ */
    public static function accepts(Business $business): bool
    {
        return $business->storefrontTheme() !== null
            && self::settings((int) $business->id)['allow_orders']
            && self::payments((int) $business->id) !== [];
    }

    /* ═══════════ التسعير ═══════════ */

    /**
     * السلّةُ مسعَّرةً من القاعدة — بلا كتابة.
     *
     * تُقرأ في صفحة السلّة وصفحة إتمام الطلب معًا، وتُعاد قراءتُها عند
     * الإتمام تحت قفل. والخطأُ يُقال بندًا بندًا كما يقوله الصندوق.
     *
     * @param  array{items: list<array{id: int, variant_id?: ?int, qty: int}>, fulfil?: string, promo?: ?string}  $payload
     * @return array{lines: list<array>, subtotal: float, discount: float, delivery: float, tax: float, total: float, coupon: ?string, promo_error: ?string, free_over: ?float}
     */
    public static function quote(Business $business, array $payload, bool $lock = false): array
    {
        $bid = (int) $business->id;
        $items = self::items($payload['items'] ?? []);
        $sale = new SaleLines($bid);

        $lines = $sale->priceItems($items, $lock);
        self::assertPublished($bid, $lines);

        $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']), 3);

        [$coupon, $discount, $promoError] = self::coupon($bid, $payload['promo'] ?? null, $subtotal, $lock);

        $tax = $sale->taxFor($lines, $subtotal, $discount);
        if (Vat::inclusive($bid)) {
            $subtotal = round($subtotal - $tax, 3);
        }

        $fulfil = ($payload['fulfil'] ?? FlowerOrder::DELIVERY) === FlowerOrder::PICKUP ? FlowerOrder::PICKUP : FlowerOrder::DELIVERY;
        $settings = self::settings($bid);
        $delivery = self::deliveryFee($settings, $fulfil, $subtotal);

        $total = round(max(0, $subtotal - $discount) + $tax + $delivery, 3);

        return [
            'lines' => array_map(fn ($l, $i) => [
                'id' => (int) $l['product']->id,
                'variant_id' => $l['variant']?->id,
                'name' => $l['name'],
                'name_en' => $l['product']->name_en,
                'variant' => $l['variant']?->name,
                'variant_en' => $l['variant']?->name_en,
                'image' => $l['product']->image,
                'price' => (float) $l['price'],
                'qty' => (int) $l['qty'],
                'line' => round($l['price'] * $l['qty'], 3),
            ], $lines, array_keys($lines)),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery' => $delivery,
            'tax' => $tax,
            'total' => $total,
            'coupon' => $coupon?->code,
            'promo_error' => $promoError,
            'free_over' => $settings['free_over'],
            'fulfil' => $fulfil,
            '_lines' => $lines,
            '_coupon' => $coupon,
        ];
    }

    /* ═══════════ الإتمام ═══════════ */

    /**
     * يُنشئ الطلبَ — كلَّه أو لا شيء.
     *
     * @param  array<string, mixed>  $payload  البنودُ وبياناتُ الزبون والتسليم والدفع
     */
    public static function place(Business $business, array $payload, string $lang = 'ar'): Order
    {
        $bid = (int) $business->id;

        if (! self::accepts($business)) {
            throw ValidationException::withMessages(['items' => __('المتجر لا يستقبل طلبات من الموقع الآن.')]);
        }

        $form = self::validated($bid, $payload);

        return DB::transaction(function () use ($business, $bid, $payload, $form, $lang) {
            // بقفل: الفحصُ والخصم على كميّةٍ لا تتغيّر تحتهما — كما في الصندوق
            $q = self::quote($business, $payload + ['fulfil' => $form['fulfil']], lock: true);
            $lines = $q['_lines'];
            $branchId = $business->branches()->orderBy('id')->value('id');
            $branchName = $business->branches()->orderBy('id')->value('name') ?? 'الفرع الرئيسي';

            (new SaleLines($bid))->assertStock($lines, $branchId);

            $customer = self::customer($bid, $form, $lang);
            $method = self::payments($bid)[$form['pay']];
            $scheduled = self::scheduledFor($form);

            $order = (new OrderNumbers($bid))->createNumbered([
                'business_id' => $bid,
                'customer_name' => $customer->name,
                'customer_name_en' => $customer->name_en,
                'customer_id' => $customer->id,
                'employee_name' => __('الموقع الإلكتروني'),
                'user_id' => null,
                'branch_id' => $branchId,
                'branch' => $branchName,
                'pos_device_id' => null,
                // البابُ الذي دخل منه الطلب — وهذا الموضعُ الوحيد الذي يكتبه
                'channel' => SalesChannel::WEBSITE,
                'bank_account_id' => null,
                'payment_method' => $method,
                // لا مالَ دخل بعد — تحويلًا كان أو عند الاستلام؛ الدفترُ يُدين الذمم
                'payment_status' => 'غير مدفوع',
                'subtotal' => $q['subtotal'],
                'discount' => $q['discount'],
                'coupon_code' => $q['_coupon']?->code,
                'coupon_discount' => $q['discount'],
                'tax' => $q['tax'],
                'delivery_fee' => $q['delivery'],
                'total' => $q['total'],
                'ordered_at' => now(),
                'status' => OrderStatus::PENDING,
                'notes' => null,
            ] + FlowerOrder::attributes([
                'fulfillment_type' => $form['fulfil'],
                'recipient_name' => $form['name'],
                'recipient_phone' => $form['phone'],
                'scheduled_for' => $scheduled?->toDateTimeString(),
                'card_message' => $form['card'] ?? null,
                'delivery_address' => $form['fulfil'] === FlowerOrder::DELIVERY
                    ? trim(($form['area'] ?? '').' — '.($form['address'] ?? ''), " —\t")
                    : null,
                'delivery_notes' => $form['slot'] ?? null,
            ]), (new OrderNumbers($bid))->salePrefix(), max(1, (int) (Setting::where('business_id', $bid)->where('key', 'inv_start')->value('value') ?? 1)));

            // وصاحبُ الصنف ونسبتُه — القاعدةُ نفسُها التي يقرأ بها الصندوق
            $boutiqueOf = Boutiques::attribute($bid, $lines);

            foreach (array_values($lines) as $idx => $l) {
                $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'variant_sku' => $l['variant']?->sku,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    /*
                     * لقطةُ التكلفة يومَ البيع، وصاحبُ البند ونسبتُه —
                     * القاعدةُ نفسُها في الصندوق، ومن موضعٍ واحد يكتبها.
                     */
                    ...Boutiques::itemColumns($boutiqueOf[$idx] ?? null, (float) ($l['cost'] ?? 0)),
                    'quantity' => $l['qty'],
                    'note' => null,
                    'total' => round($l['price'] * $l['qty'], 3),
                    'addons_total' => 0,
                    'custom_details' => null,
                ]);
            }

            // الرفُّ يُخصم للسلّة كلِّها — وذو الوصفة بمكوّناته لا بنفسه (كما في الصندوق)
            $sale = [];
            $recipeUse = [];
            foreach ($lines as $l) {
                if (! $l['product']) {
                    continue;
                }
                if (! ($l['has_recipe'] ?? false)) {
                    $sale[$l['product']->id] = ($sale[$l['product']->id] ?? 0) + $l['qty'];

                    continue;
                }
                foreach (Recipe::consumptionFor($l['product'], $l['variant'] ?? null, $l['qty'], $l['recipe']) as $pid => $qty) {
                    $recipeUse[$pid] = ($recipeUse[$pid] ?? 0.0) + $qty;
                }
            }

            $by = __('الموقع الإلكتروني');
            StockLedger::move($bid, $branchId, array_map(fn ($n) => -$n, $sale), 'بيع', $by);
            StockLedger::move($bid, $branchId, array_map(fn ($n) => -Recipe::units($n), $recipeUse), StockLedger::RECIPE, $by, $order->number);

            if ($q['_coupon']) {
                $q['_coupon']->increment('used_count');
            }

            // معاملةُ الدخل كما يكتبها الصندوق — والقيدُ يقرأ حالَ السداد
            Transaction::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'reference' => $order->number,
                'description' => 'مبيعات الموقع الإلكتروني — '.$order->customer_name,
                // ونوعٌ يخصُّ الموقع: به تُفرَز «الحركة المالية» وتُسمّى صفوفُها
                'kind' => Transaction::WEB_SALE,
                'method' => $method,
                'bank_account_id' => null,
                'type' => 'دخل',
                'amount' => $order->total,
                'tax_amount' => $order->tax ?? 0,
                'employee_name' => $by,
                'occurred_at' => $order->ordered_at ?? now(),
            ]);

            try {
                Books::recordSale($order);
            } catch (\Throwable $e) {
                Activity::log('updated', 'تعذّر ترحيل قيد البيع '.$order->number.': '.$e->getMessage(), [
                    'business_id' => $bid, 'subject_id' => $order->id, 'subject_type' => 'order',
                ]);
            }

            Activity::log('checkout', 'طلبٌ من الموقع '.$order->number.' بقيمة '.Money::format((float) $order->total, Money::of($bid)), [
                'business_id' => $bid, 'subject_id' => $order->id,
            ]);

            return $order;
        });
    }

    /** رمزُ صفحة التأكيد — لا يُفتح طلبٌ بمعرّفه وحده */
    public static function token(Order $order): string
    {
        return substr(hash_hmac('sha256', 'web-order:'.$order->id.':'.$order->number, (string) config('app.key')), 0, 20);
    }

    /* ═══════════ أدوات ═══════════ */

    /** البنودُ كما يقبلها `SaleLines::priceItems` — معرّفٌ ومقاسٌ وكميّة، لا سعرَ من المتصفّح */
    private static function items(array $raw): array
    {
        $items = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_LINES) as $i) {
            $id = (int) ($i['id'] ?? 0);
            $qty = (int) ($i['qty'] ?? 0);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'variant_id' => ! empty($i['variant_id']) ? (int) $i['variant_id'] : null,
                'qty' => min(self::MAX_QTY, $qty),
                'name' => '',
                'note' => null,
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('السلة فارغة')]);
        }

        return $items;
    }

    /** ما لا يُعرض للزبون لا يُباع له: `published` شرطُ الموقع فوق شرط الصندوق */
    private static function assertPublished(int $bid, array $lines): void
    {
        $ids = collect($lines)->map(fn ($l) => $l['product']?->id)->filter()->unique()->values();
        $shown = Product::where('business_id', $bid)->whereIn('id', $ids)->where('published', true)->pluck('id')->all();
        $hidden = $ids->diff($shown);

        if ($hidden->isNotEmpty() || collect($lines)->contains(fn ($l) => ! $l['product'])) {
            throw ValidationException::withMessages(['items' => __('صنفٌ في سلّتك لم يعد متاحًا — أزله وأعد المحاولة.')]);
        }

        /* وما نفد من الرفّ يُقال هنا قبل الإتمام — بقاعدة الموقع نفسِها */
        $available = Shelf::availability($bid, $ids->all());
        foreach ($lines as $l) {
            if (! ($available[(int) $l['product']->id] ?? false)) {
                throw ValidationException::withMessages(['items' => __('«:name» نفد من المتجر.', ['name' => $l['name']])]);
            }
        }
    }

    /**
     * الكوبون — بقواعد الصندوق نفسِها، والخطأُ يُقال لا يُبتلع.
     *
     * @return array{0: ?Coupon, 1: float, 2: ?string}
     */
    private static function coupon(int $bid, ?string $code, float $subtotal, bool $lock): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return [null, 0.0, null];
        }

        $coupon = Coupon::where('business_id', $bid)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        $error = match (true) {
            ! $coupon => __('كود الخصم غير صحيح'),
            ! $coupon->active => __('هذا الكوبون موقوف'),
            $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
            $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Money::format((float) $coupon->min_order, Money::of($bid))]),
            default => null,
        };

        if ($error !== null) {
            return [null, 0.0, $error];
        }

        return [$coupon, round(min((float) $coupon->discountFor($subtotal), $subtotal), 3), null];
    }

    private static function deliveryFee(array $settings, string $fulfil, float $subtotal): float
    {
        if ($fulfil === FlowerOrder::PICKUP || $subtotal <= 0) {
            return 0.0;
        }
        if ($settings['free_over'] !== null && $subtotal >= $settings['free_over']) {
            return 0.0;
        }

        return $settings['fee'];
    }

    /** بياناتُ الزبون والتسليم والدفع — تُفحص قبل أن يُقرأ صفٌّ واحد */
    private static function validated(int $bid, array $payload): array
    {
        $settings = self::settings($bid);
        $payments = self::payments($bid);

        $v = validator($payload, [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{8,}$/'],
            'fulfil' => ['required', 'in:'.implode(',', FlowerOrder::FULFILLMENT)],
            'area' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(self::MAX_DAYS_AHEAD)->toDateString()],
            'slot' => ['nullable', 'string', 'max:60'],
            'card' => ['nullable', 'string', 'max:'.FlowerOrder::CARD_MAX],
            'pay' => ['required', 'in:'.implode(',', array_keys($payments) ?: ['-'])],
        ], [
            'name.required' => __('اكتب اسمك.'),
            'phone.required' => __('اكتب رقم هاتفك.'),
            'phone.regex' => __('رقم الهاتف غير صحيح.'),
            'date.required' => __('اختر موعد التسليم.'),
            'date.after_or_equal' => __('الموعد لا يكون في الماضي.'),
            'pay.in' => __('اختر وسيلة الدفع.'),
        ]);

        $v->after(function ($v) use ($payload, $settings) {
            if (($payload['fulfil'] ?? null) === FlowerOrder::DELIVERY) {
                if (trim((string) ($payload['address'] ?? '')) === '') {
                    $v->errors()->add('address', __('اكتب العنوان بالتفصيل.'));
                }
                if ($settings['areas'] !== [] && ! in_array(trim((string) ($payload['area'] ?? '')), $settings['areas'], true)) {
                    $v->errors()->add('area', __('اختر المنطقة.'));
                }
            }
            if ($settings['slots'] !== [] && filled($payload['slot'] ?? null) && ! in_array($payload['slot'], $settings['slots'], true)) {
                $v->errors()->add('slot', __('اختر وقت التسليم.'));
            }
        });

        return $v->validate();
    }

    /** موعدُ التسليم: اليومُ المختار، وأوّلُ ساعةٍ من فترته إن كُتبت بساعة */
    private static function scheduledFor(array $form): ?Carbon
    {
        $day = Carbon::parse($form['date'])->startOfDay();
        $slot = (string) ($form['slot'] ?? '');

        if (preg_match('/(\d{1,2})\s*(am|pm|ص|م)?/iu', $slot, $m)) {
            $h = (int) $m[1];
            $mer = mb_strtolower($m[2] ?? '');
            if (in_array($mer, ['pm', 'م'], true) && $h < 12) {
                $h += 12;
            }

            return $day->copy()->setTime(min(23, $h), 0);
        }

        return $day->copy()->setTime(9, 0);
    }

    /** الزبونُ بهاتفه: يُعرف إن كان معروفًا، ويُكتب إن لم يكن */
    private static function customer(int $bid, array $form, string $lang): Customer
    {
        $wanted = WhatsAppPhone::normalize($form['phone']);

        $existing = Customer::where('business_id', $bid)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'name', 'name_en', 'phone', 'language'])
            ->first(fn ($c) => (string) $c->phone === (string) $form['phone']
                || ($wanted !== null && WhatsAppPhone::normalize($c->phone) === $wanted));

        if ($existing) {
            if ($existing->language === null) {
                $existing->forceFill(['language' => in_array($lang, ['ar', 'en'], true) ? $lang : 'ar'])->save();
            }

            return Customer::find($existing->id);
        }

        $data = Customers::localizeName([
            'business_id' => $bid,
            'name' => trim($form['name']),
            'phone' => trim($form['phone']),
            'language' => in_array($lang, ['ar', 'en'], true) ? $lang : 'ar',
        ]);

        return Customer::create($data);
    }
}
