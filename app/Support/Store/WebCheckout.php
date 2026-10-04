<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Books;
use App\Support\CouponLimits;
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
use App\Support\Store\PriceMovedAfterPayment;
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

    /**
     * الدفعُ بالبطاقة — ولا يُعرض إلّا لمن أكمل بوّابتَه.
     *
     * وليس مفتاحًا في الإعدادات كأختيه: مفتاحٌ يُرفع وبوّابةٌ ناقصةٌ يعني
     * زبونًا يختار «بطاقة» فيقف على صفحةٍ بيضاء. فالسؤالُ عن اكتمال
     * المفاتيح نفسِها (انظر `PaymentGateway::ready`).
     */
    public const PAY_CARD = 'card';

    /** أقصى أيامٍ يُحجز الموعدُ بعدها */
    public const MAX_DAYS_AHEAD = 60;

    /* ═══════════ الإعدادات ═══════════ */

    /**
     * إعداداتُ التوصيل والدفع كما ضبطها صاحبُ المحلّ.
     *
     * @return array{fee: float, free_over: ?float, areas: list<string>, slots: list<string>, hours: string, note: string, image_note: string, image_note_en: string, area_note: string, area_note_en: string, cod: bool, transfer: bool, bank: string, allow_orders: bool}
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
            /* وتنبيهُ الصورة — تقرؤه الصفحاتُ الثلاث من هنا لا من ثلاثة مواضع */
            'image_note' => trim((string) $site['store_image_note']),
            // وبالإنجليزيّة للصفحة الإنجليزيّة — ولا يقع أحدُهما على الآخر
            'image_note_en' => trim((string) ($site['store_image_note_en'] ?? '')),
            // وتنبيهُ نطاق التوصيل — لكلّ لغةٍ نصُّها، ولا يمسّ قواعدَ المنطقة
            'area_note' => trim((string) ($site['store_delivery_area_note'] ?? '')),
            'area_note_en' => trim((string) ($site['store_delivery_area_note_en'] ?? '')),
            'cod' => ($site['store_pay_cod'] ?? '1') === '1',
            'transfer' => ($site['store_pay_transfer'] ?? '0') === '1',
            'bank' => trim((string) $site['store_bank']),
            'allow_orders' => ($site['store_allow_orders'] ?? '1') === '1',
        ];
    }

    /**
     * وسائلُ الدفع المتاحة على الموقع — بمفاتيحها ووسيلتها في الطلب.
     *
     * ═══ والبطاقةُ لطلبٍ جديد غيرُها لدفعةٍ قُبضت ═══
     *
     * لطلبٍ جديد تُعرض لمن في موقعه سلّةٌ وبوّابتُه مكتملة (`Paymob::enabled`).
     * أمّا إتمامُ دفعةٍ قُبض مالُها فيُعطى نيّتَها (`$settling`): بدأت والبطاقةُ
     * متاحة، فلا يُردّ طلبُها لأنّ شيئًا تبدّل والزبونُ على صفحة البنك.
     * والنيّةُ تُسأل عنها القاعدةُ لا يُصدَّق وجودُها (`settles`) — فلا تُصنَع
     * بها بطاقةٌ لطلبٍ لم يُدفع.
     *
     * @param  StorePaymentIntent|null  $settling  نيّةُ الدفعة التي يُتمّ `place` طلبَها
     * @return array<string, string> [cod|transfer|card => وسيلةُ الدفع كما تُكتب في الطلب]
     */
    public static function payments(int $businessId, ?StorePaymentIntent $settling = null): array
    {
        $s = self::settings($businessId);
        $out = [];
        if ($s['cod']) {
            $out[self::PAY_COD] = PaymentMethods::CASH;
        }
        if ($s['transfer']) {
            $out[self::PAY_TRANSFER] = PaymentMethods::TRANSFER;
        }
        if (Paymob::enabled($businessId) || self::settles($businessId, $settling)) {
            $out[self::PAY_CARD] = PaymentMethods::CARD;
        }

        return $out;
    }

    /**
     * أهذه دفعةُ بطاقةٍ قُبض مالُها لهذا المتجر؟ — من القاعدة لا من الذاكرة.
     *
     * `PaymobController::settle` يكتب «مدفوعة» ورقمَ العمليّة بعد أن يُصدِّق
     * التوقيع، ثمّ يُتمّ الطلب. ونسختُه في الذاكرة ما زالت «معلَّقة» — فتُقرأ
     * الحالُ من الصفّ. ونيّةُ متجرٍ آخر، أو نيّةٌ لم يُقبض مالُها، لا تفتح شيئًا.
     */
    private static function settles(int $bid, ?StorePaymentIntent $intent): bool
    {
        return $intent !== null
            && StorePaymentIntent::whereKey($intent->id)
                ->where('business_id', $bid)
                ->where('status', StorePaymentIntent::PAID)
                ->whereNotNull('provider_transaction_id')
                ->exists();
    }

    /** أيقبل هذا المتجر طلبًا من موقعه الآن؟ */
    public static function accepts(Business $business, ?StorePaymentIntent $settling = null): bool
    {
        return $business->storefrontTheme() !== null
            && self::settings((int) $business->id)['allow_orders']
            && self::payments((int) $business->id, $settling) !== [];
    }

    /* ═══════════ التسعير ═══════════ */

    /**
     * السلّةُ مسعَّرةً من القاعدة — بلا كتابة.
     *
     * تُقرأ في صفحة السلّة وصفحة إتمام الطلب معًا، وتُعاد قراءتُها عند
     * الإتمام تحت قفل. والخطأُ يُقال بندًا بندًا كما يقوله الصندوق.
     *
     * @param  array<string, mixed>  $payload  حمولةُ المتصفّح كما وصلت — لا يُوثَق بشكلها
     * @return array{lines: list<array>, subtotal: float, discount: float, delivery: float, tax: float, total: float, coupon: ?string, promo_error: ?string, free_over: ?float}
     */
    public static function quote(Business $business, array $payload, bool $lock = false): array
    {
        $bid = (int) $business->id;
        $items = self::items($payload['items'] ?? []);
        $sale = new SaleLines($bid);

        $lines = $sale->priceItems($items, $lock);
        self::assertPublished($bid, $lines);
        // ونصُّ كرت الهدية مع بنده — يُردّ الكرتُ بلا نصّ، ويُمحى عمّا سواه
        $lines = GiftCardProduct::settle($bid, $lines);

        /*
         * وكرتُ الهدية سطرٌ كأيّ سطر — يُضاف هنا وينتهي أمرُه.
         *
         * بعد `assertPublished` لا قبله: صنفُ الكرت غيرُ منشورٍ عمدًا (لا
         * يُشترى وحدَه من الشبكة)، ولو مرّ على الفحص لَرُدّت كلُّ سلّةٍ
         * فيها كرت بـ«صنفٌ لم يعد متاحًا».
         *
         * وبإدخاله في الأسطر يقرؤه ما بعده كلُّه بلا استثناء: المجموعُ
         * الفرعيّ، والضريبةُ، وحصّةُ الخصم، وبندُ الطلب، وتقريرُ الأصناف.
         * ولو حُسب رسمًا على حدةٍ — كرسم التوصيل — لَوجب أن يُذكر في كلّ
         * موضعٍ من هذه، ويُنسى في واحد.
         */
        if (GiftCard::wanted($bid, $payload)) {
            $lines[] = GiftCard::line($bid);
        }

        $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty']), 3);

        /*
         * وكودُ الخصم يُقرأ لمن أبقى حقلَه.
         *
         * من أطفأه لا يريد كوبونًا يُطبَّق أصلًا — والحقلُ المخفيُّ لا يمنع
         * إرسالَه. وبلا هذا يُخصم من فاتورته بكودٍ قديمٍ يعرفه زبونٌ واحد،
         * وهو لا يرى في شاشته حقلًا يشكّ فيه.
         */
        $promo = CheckoutFields::shows($bid, 'promo') ? self::text($payload['promo'] ?? null) : null;

        [$coupon, $discount, $promoError] = self::coupon($bid, $promo, $subtotal, $lock);

        $tax = $sale->taxFor($lines, $subtotal, $discount);
        if (Vat::inclusive($bid)) {
            $subtotal = round($subtotal - $tax, 3);
        }

        /*
         * وطريقةُ الاستلام من بين ما فتحه صاحبُ المحلّ وحدَه.
         *
         * وكان الفراغُ يُقرأ «توصيلًا» دائمًا — فمحلٌّ أطفأ التوصيل يُسعّر
         * رسمَه في الشاشة ثمّ يُردّ الطلبُ عند الضغط: رقمٌ يراه الزبون ولا
         * يُطالَب به، ولا يفهم من أين جاء.
         */
        $offered = CheckoutFields::fulfilments($bid);
        $asked = self::text($payload['fulfil'] ?? '');

        /*
         * والافتراضيُّ «توصيل» متى كان مفتوحًا — لا أوّلَ ما في القائمة.
         *
         * `FlowerOrder::FULFILLMENT` يبدأ بالاستلام، فقراءةُ أوّلِه تقلب
         * افتراضيَّ كلّ متجرٍ قائم: تسعيرةٌ بلا `fulfil` كانت تحمل رسمَ
         * التوصيل فتصير بلا رسم — ويُعرض للزبون مجموعٌ أقلُّ ممّا سيُدفع.
         */
        $fallback = in_array(FlowerOrder::DELIVERY, $offered, true) ? FlowerOrder::DELIVERY : $offered[0];
        $fulfil = in_array($asked, $offered, true) ? $asked : $fallback;
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
                // نصُّ الكرت يعود إلى السلّة لتُراجعه — ولا نصَّ لبندٍ سواه
                'gift_card' => (bool) ($l['gift_card'] ?? false),
                'note' => ($l['gift_card'] ?? false) ? $l['note'] : null,
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
    /**
     * الدفعُ بالبطاقة — يفتح صفحةَ البوّابة ويردّ رابطَها، ولا يكتب طلبًا.
     *
     * ═══ ولا طلبَ قبل أن يصل المال ═══
     *
     * الطلبُ يخصم المخزون. ولو كُتب قبل الدفع لَخصم كلُّ زائرٍ فتح صفحةَ
     * البطاقة ثمّ أغلقها باقةً من الرفّ — فينفد ما هو موجود، ويُردّ زبونٌ
     * حاضرٌ بالنقد عن صنفٍ يملأ الثلّاجة.
     *
     * والفحصُ هنا فحصُ `place` نفسُه: من أرسل حمولةً لا تصحّ يُردّ قبل أن
     * تُفتح له دفعة، لا بعد أن يدفع.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function toCard(Business $business, array $payload, string $lang = 'ar'): string
    {
        $bid = (int) $business->id;

        if (! self::accepts($business)) {
            throw ValidationException::withMessages(['items' => __('المتجر لا يستقبل طلبات من الموقع الآن.')]);
        }

        $form = self::validated($bid, $payload);
        $quote = self::quote($business, $payload + ['fulfil' => $form['fulfil']]);

        /*
         * ═══ وحدُّ الكوبون يُفحص هنا قبل صفحة الدفع، ثمّ تُحجز فرصتُه ═══
         *
         * الطلبُ في هذا المسار يُكتب من إشعار البوّابة بعد أن يُقبض المال.
         * فلو تُرك الفحصُ لهناك لَدفع الزبونُ ثمّ رُدّ طلبُه، وصار مالٌ مقبوضًا
         * بلا طلب — يردُّه صاحبُ المحلّ بيده. فيُقال له قبل أن يدفع.
         *
         * والفحصُ وحدَه لا يكفي: بين هذه اللحظة ووصول الإشعار دقائق يستعمل
         * فيها غيرُه آخرَ فرصةٍ في الكود — من هذا الباب أو من الدفع عند
         * الاستلام. فتُحجز الفرصةُ باسم هذه النيّة، وتُحسب في الحدّين حتّى
         * تصير استعمالًا أو تنقضي مدّتُها.
         */
        $customer = $quote['_coupon'] ? self::existingCustomer($bid, $form['phone']) : null;
        $couponKey = $quote['_coupon'] ? CouponLimits::identity($customer, $form['phone']) : null;

        if ($quote['_coupon'] && $why = CouponLimits::refusal($quote['_coupon'], $couponKey, $customer?->id)) {
            throw ValidationException::withMessages(['promo' => $why]);
        }

        $opened = Paymob::open($business, $payload, $quote, $lang);

        CouponLimits::reserve($quote['_coupon'], $opened['intent'], $couponKey, $customer?->id);

        return $opened['url'];
    }

    /**
     * @param  StorePaymentIntent|null  $intent  نيّةُ الدفع التي قُبض بها المال — لمسار البطاقة
     */
    public static function place(Business $business, array $payload, string $lang = 'ar', bool $paid = false, ?StorePaymentIntent $intent = null): Order
    {
        $bid = (int) $business->id;
        // دفعةٌ قُبضت تُتمّ طلبَها ولو رُفع المتجرُ من قائمة Paymob بعد فتحها — انظر `payments`
        $settling = $paid ? $intent : null;

        if (! self::accepts($business, $settling)) {
            throw ValidationException::withMessages(['items' => __('المتجر لا يستقبل طلبات من الموقع الآن.')]);
        }

        $form = self::validated($bid, $payload, $settling);

        return DB::transaction(function () use ($business, $bid, $payload, $form, $lang, $paid, $intent, $settling) {
            // بقفل: الفحصُ والخصم على كميّةٍ لا تتغيّر تحتهما — كما في الصندوق
            $q = self::quote($business, $payload + ['fulfil' => $form['fulfil']], lock: true);
            $lines = $q['_lines'];
            $branchId = $business->branches()->orderBy('id')->value('id');
            $branchName = $business->branches()->orderBy('id')->value('name') ?? 'الفرع الرئيسي';

            (new SaleLines($bid))->assertStock($lines, $branchId);

            $customer = self::customer($bid, $form, $lang);

            /*
             * ═══ وحدُّ الكوبون لكلّ زبون — بعد أن يُعرف الزبون ═══
             *
             * والموقعُ يعرفه دائمًا: الهاتفُ حقلٌ لا يملك صاحبُ المحلّ إخفاءَه
             * (`CheckoutFields::FIELDS` لا تحمله)، فالزبونُ مُعرَّفٌ في كلّ
             * طلبٍ يدخل من هنا — بطاقةً ورقمًا.
             *
             * ويُردّ صريحًا ولا يُبتلع: لو أُسقط الخصمُ صامتًا لَقرأ الزبونُ
             * سعرًا في السلّة ودفع غيرَه.
             */
            $couponKey = CouponLimits::identity($customer, $form['phone']);

            if ($q['_coupon'] && $why = CouponLimits::refusal($q['_coupon'], $couponKey, $customer->id, $intent)) {
                throw ValidationException::withMessages(['promo' => $why]);
            }

            /*
             * ═══ وخصمٌ سقط بين التسعيرة والإتمام يُقال، لا يُبتلع ═══
             *
             * الزبونُ كتب كودَه ورأى السعرَ المخفَّض، ثمّ مضى إلى «أكمل الطلب».
             * وبين اللحظتين قد ينتهي الكودُ: تاريخُه انقضى عند منتصف الليل،
             * أو نفدت مرّاتُه من صندوقٍ آخر، أو أوقفه صاحبُ المحلّ.
             *
             * وكان `quote` تردُّه وتُفرِغ `_coupon`، فيمضي الطلبُ **بالسعر
             * الكامل** ولا شيء يقول للزبون. فيصله طلبٌ بواحدٍ وعشرين وقد وافق
             * على تسعةَ عشر — ويكتشفه عند التسليم أو في الفاتورة.
             *
             * فيُردّ الطلبُ ويُقال له السببُ والإجماليُّ الجديد. فإن قبله بعث
             * به ثانيةً وقد رأى ما يدفع — والصفحةُ ترسل `agreed_total` كما
             * عُرض له، فيُقابَل بما صار إليه. وهذا هو الإقرارُ: لا مقبضٌ
             * يُضغط، بل سعرٌ رآه وأرسله.
             *
             * ولا يقع هذا في مسار البطاقة: هناك الفرصةُ محجوزةٌ والمبلغُ
             * مقبوضٌ، وحسابُه بابٌ آخر (`PriceMovedAfterPayment`).
             */
            if (! $paid && $q['promo_error'] !== null) {
                $agreed = $form['agreed_total'] ?? null;
                $same = $agreed !== null
                    && (int) round(((float) $agreed) * 1000) === (int) round(((float) $q['total']) * 1000);

                if (! $same) {
                    throw ValidationException::withMessages([
                        'promo' => __(':why الإجمالي الجديد :total — أكّد الطلب مرّة أخرى للموافقة عليه.', [
                            'why' => $q['promo_error'],
                            'total' => Money::format((float) $q['total'], Money::of($bid)),
                        ]),
                    ]);
                }
            }

            /*
             * ═══ وما يُكتب هو ما قُبض — أو لا يُكتب ═══
             *
             * المالُ خرج من الزبون بمبلغِ النيّة، والطلبُ يُسعَّر من القاعدة
             * ثانيةً هنا. وبين اللحظتين قد يتبدّل شيء: كوبونٌ نفد، أو سعرُ
             * صنفٍ رُفع. فيُكتب الطلبُ بمبلغٍ غير الذي دفعه الزبون — يقرأ
             * فاتورةً بواحدٍ وعشرين وقد دفع تسعةَ عشر، ولا شيء يقول لأحدٍ
             * ما وقع.
             *
             * فإن اختلفا لا يُكتب طلبٌ بمبلغٍ آخر: تبقى الدفعةُ بلا طلب،
             * ويُوقَظ صاحبُ المحلّ في جرسه (`StorePaymentIntent::strayPayment`)
             * ليردّ المالَ أو يجهّز الطلبَ بيده — وكلاهما قرارُه لا قرارُنا.
             *
             * والحجزُ يجعل هذا بابًا لا يُطرق في الكوبونات: الفرصةُ محفوظةٌ
             * باسم هذه النيّة حتّى يصل إشعارُها. وهو مفتوحٌ لما لا نحجزه —
             * سعرُ صنفٍ تبدّل — فلا يُبتلع فرقٌ ماليٌّ صامتًا.
             */
            if ($paid && $intent !== null) {
                $charged = (int) round(((float) $intent->amount) * 1000);
                $now = (int) round(((float) $q['total']) * 1000);

                if ($charged !== $now) {
                    /*
                     * وبنوعه يُميَّز لا برسالته: `settle` تقرأ هذا النوعَ وحدَه
                     * فتردّ المال. ومطابقةُ نصٍّ عربيٍّ لتقرير ردٍّ ماليٍّ تكسر
                     * يومَ يُحرَّر حرفٌ في الرسالة.
                     */
                    throw new PriceMovedAfterPayment($charged, $now);
                }
            }

            $method = self::payments($bid, $settling)[$form['pay']];
            $scheduled = self::scheduledFor($form);
            // وقد دخل الكرتُ الأسطرَ في `quote` — وهنا تُكتب أعمدتُه على الطلب
            $wantsCard = GiftCard::wanted($bid, $payload);

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
                /*
                 * ولا مالَ دخل بعد — تحويلًا كان أو عند الاستلام؛ الدفترُ
                 * يُدين الذمم. إلّا ما دُفع بالبطاقة: هذا لا يُنشأ طلبُه
                 * أصلًا حتّى يصل مالُه، فيُكتب مدفوعًا ويدخل البنكَ لا
                 * الذمم (انظر `Books::recordSale`).
                 */
                'payment_status' => $paid ? 'مدفوع' : 'غير مدفوع',
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
            ]
            // والهديّةُ لغير مشتريها — ولا شيءَ لطلبٍ ليس هديّة (`GiftOrders`)
            + GiftOrders::columns($bid, $form)
            + GiftCard::columns(
                $wantsCard,
                $form['card_align'] ?? null,
                $wantsCard ? GiftCard::keep($bid, $form['card_file'] ?? null, $form['card_file_name'] ?? null) : null,
            ) + FlowerOrder::attributes([
                'fulfillment_type' => $form['fulfil'],
                /*
                 * والمستلِمُ من كتبه الزبون — وإلّا فهو نفسُه.
                 *
                 * الفراغُ يعني «أنا»: من لم يملأ الحقل يشتري لنفسه، فيُنسخ
                 * اسمُه ليبقى للطلب مستلِمٌ في كل شاشةٍ تقرؤه.
                 */
                'recipient_name' => filled($form['recipient_name'] ?? null) ? $form['recipient_name'] : $form['name'],
                'recipient_phone' => filled($form['recipient_phone'] ?? null) ? $form['recipient_phone'] : $form['phone'],
                // واسمُ المُرسِل هو المشتري — فيُطبع على الكرت بلا أن يُسأل عنه
                'sender_name' => $form['name'],
                'scheduled_for' => $scheduled?->toDateTimeString(),
                /*
                 * ومن كرتُه صنفٌ من رفّه فنصُّه من بنده لا من خانة الإتمام —
                 * مصدرٌ واحد (`GiftCardProduct::orderMessage`).
                 */
                'card_message' => GiftCardProduct::on($bid)
                    ? GiftCardProduct::orderMessage($lines)
                    : ($form['card'] ?? null),
                /*
                 * والعنوانُ من قسم التوصيل لكلّ طلب — وفي الهديّة هو عنوانُ
                 * المستلِم. لا عنوانَ ثانٍ ولا طلبَ بلا عنوان (`GiftOrders`).
                 */
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
                    // نصُّ كرت الهدية وحده — وسائرُ البنود بلا نصّ (`GiftCardProduct::settle`)
                    'note' => $l['note'] ?? null,
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
                // وما لا رفَّ له لا يُخصم — كرتُ الهدية، كما في `SaleLines::demand`
                if ($l['no_stock'] ?? false) {
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
                /*
                 * والسجلُّ بمعرّف الطلب: إشعارُ بوّابةٍ أُعيد إرسالُه لا يُحتسب
                 * ثانيةً. والحجزُ — إن كان — يُحوَّل ولا يُضاف إليه صفٌّ ثانٍ،
                 * فلا يُحسب الزبونُ مرّتين على شراءٍ واحد.
                 */
                CouponLimits::record($q['_coupon'], $order, $couponKey, $customer->id, $intent);
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

    /**
     * نصٌّ من حمولة المتصفّح — وما ليس نصًّا فراغ.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * الحمولةُ JSON يكتبها المتصفّح، ومن كتبها بيده يكتب ما شاء. وكانت
     * الحقولُ تُقرأ بـ`(string) ($payload['x'] ?? '')` أو تُمرَّر إلى وسيطٍ
     * موسومٍ `?string` — وكلاهما **ينهار** على مصفوفة:
     *
     *   {"fulfil": ["x"]}  →  ErrorException: Array to string conversion
     *   {"promo":  ["x"]}  →  TypeError: must be of type ?string
     *
     * وصفحةُ السلّة تردّ ٥٠٠ حيث كان يجب أن تردّ «اختر طريقة الاستلام».
     * وضررُه أبعدُ من الرسالة: كلُّ ٥٠٠ يُسجَّل، فبابٌ عامٌّ يُردّ بـ٥٠٠ على
     * مُدخَلٍ تافهٍ يُملأ به سجلُّ الأخطاء من هاتفٍ واحد.
     *
     * والتحقّقُ لا يسبق القراءة دائمًا: `quote` يُسعّر بلا `validated`
     * أصلًا — هو بابٌ يُنادى مع كلّ تبديل، لا يُشترط فيه نموذجٌ كامل.
     *
     * فالقراءةُ نفسُها تحرس، في موضعٍ واحدٍ يقرأ منه كلُّ حقل.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * البنودُ كما يقبلها `SaleLines::priceItems` — معرّفٌ ومقاسٌ وكميّة، لا سعرَ من المتصفّح.
     *
     * ═══ وما يصل من الشبكة ليس مصفوفةً حتّى يُثبَت ═══
     *
     * الحمولةُ JSON يكتبها المتصفّح، ومن كتبها بيده يكتب ما شاء. وكان
     * التوقيعُ `array $raw` وحدَه هو الحارس، فـ`{"items":"x"}` يرمي
     * `TypeError` قبل أن يبلغ سطرًا واحدًا هنا — وصفحةُ السلّة تردّ ٥٠٠،
     * والزبون يرى انهيارًا حيث كان يجب أن يقرأ «السلة فارغة».
     *
     * وضررُه أبعدُ من الرسالة: كلُّ ٥٠٠ يُسجَّل في سجلّ الأخطاء، فبابٌ
     * عامٌّ يُردّ بـ٥٠٠ على مُدخَلٍ تافهٍ يُملأ به السجلّ من هاتفٍ واحد.
     *
     * والبندُ الذي ليس مصفوفةً يُطرح بلا سطرٍ يطرحه: `$i['id'] ?? 0` على
     * رقمٍ أو نصٍّ تعود `null` فيصير المعرّفُ صفرًا، والصفرُ مطروحٌ أسفلَه.
     * وقد كُتب هنا `if (! is_array($i)) continue;` فنجا من الطفرة — أي
     * أنّه لم يكن يحرس شيئًا. وما لا يحرس يُحذف ويُكتب سببُه، لا يُترك
     * يُوهم قارئَه أنّ خلفه سؤالًا.
     *
     * و`(array) $raw` تعمل عملَ هذا الشرط حرفًا بحرف — `(array) 'x'` تعطي
     * `['x']` فيُطرح بلا معرّف. فُضّل الشرطُ لأنّه يقول ما يقصد: «ما ليس
     * قائمةَ بنودٍ فلا بنودَ فيه»، لا يُحوّل نصًّا إلى قائمةٍ من حرفٍ واحد.
     */
    private static function items(mixed $raw): array
    {
        $items = [];
        foreach (array_slice(array_values(is_array($raw) ? $raw : []), 0, self::MAX_LINES) as $i) {
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
                // يُقرأ لبند كرت الهدية وحده، ويُمحى عمّا سواه بعد التسعير
                'note' => GiftCardProduct::message($i['note'] ?? null),
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

    /**
     * بياناتُ الزبون والتسليم والدفع — تُفحص قبل أن يُقرأ صفٌّ واحد
     *
     * @param  StorePaymentIntent|null  $settling  نيّةُ دفعةٍ قُبضت — انظر `payments`
     */
    private static function validated(int $bid, array $payload, ?StorePaymentIntent $settling = null): array
    {
        $settings = self::settings($bid);
        $payments = self::payments($bid, $settling);

        /*
         * ═══ والقواعدُ تُبنى ممّا انتقاه صاحبُ المحلّ ═══
         *
         * لا قائمةً ثابتةً هنا وأخرى في الشاشة: `CheckoutFields` موضعٌ
         * واحد يقرأ منه الاثنان، فحقلٌ أُخفي لا يبقى مشترَطًا هنا يردّ
         * الطلبَ بخطأٍ عن حقلٍ لا يراه الزبون.
         *
         * و«مطلوب» تُكتب `required` وما سواها `nullable` — وحقلٌ مُطفأٌ
         * يخرج من القواعد كلِّها — و`validate()` لا تُرجع إلّا ما له قاعدة.
         */
        $gifts = GiftOrders::on($bid);

        $field = fn (string $name, array $rules) => match (CheckoutFields::state($bid, $name)) {
            CheckoutFields::OFF => null,
            CheckoutFields::REQUIRED => array_merge(['required'], $rules),
            default => array_merge(['nullable'], $rules),
        };

        $rules = array_filter([
            'name' => EnglishCheckout::rules($bid, ['required', 'string', 'max:120'], EnglishCheckout::NAME),
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{8,}$/'],
            'fulfil' => ['required', 'in:'.implode(',', CheckoutFields::fulfilments($bid))],
            /*
             * ═══ والعنوانُ والمنطقةُ لا يُشترطان هنا ═══
             *
             * «مطلوب» فيهما تعني «مطلوبٌ لمن يُوصَّل إليه» — ومن اختار
             * الاستلامَ من المحلّ لا يُسأل عن عنوانه. فالقاعدةُ هنا تُبقيهما
             * `nullable` دائمًا، والشرطُ يقع في `after` حيث تُقرأ طريقةُ
             * الاستلام (وهو موضعُه قبل هذه الشاشة ويبقى).
             *
             * ولولا هذا لَرُدّ كلُّ طلبِ استلامٍ من المحلّ بـ«اكتب العنوان».
             */
            'area' => CheckoutFields::shows($bid, 'area') ? ['nullable', 'string', 'max:120'] : null,
            'address' => EnglishCheckout::rules($bid, CheckoutFields::shows($bid, 'address') ? ['nullable', 'string', 'max:500'] : null, EnglishCheckout::ADDRESS),
            'date' => $field('date', [
                'date', 'after_or_equal:today',
                'before_or_equal:'.today()->addDays(CheckoutFields::maxDays($bid))->toDateString(),
            ]),
            'slot' => $field('slot', ['string', 'max:60']),
            'card' => ['nullable', 'string', 'max:'.FlowerOrder::CARD_MAX],
            'pay' => ['required', 'in:'.implode(',', array_keys($payments) ?: ['-'])],

            /*
             * ═══ والمستلِمُ غيرُ المشتري ═══
             *
             * كان الاسمُ والهاتفُ يُنسخان مستلِمًا كما هما — فمن أهدى باقةً
             * لأمّه سُجّل هو المستلِم، ووصل المنسّقُ إلى رقمه هو ليسأل عن
             * عنوانٍ لا يعرفه. وهو أكثرُ ما يقع في محلّ ورد: أكثرُ الطلبات
             * تُشترى لغير مشتريها.
             *
             * واختياريٌّ لا مطلوب: من يشتري لنفسه لا يُسأل عن مستلِمٍ، وحقلٌ
             * يُفرض عليه يُملأ باسمه مرّتين فلا يفرّق أحدٌ بعدها.
             */
            /*
             * والثلاثةُ بالإنجليزيّة لمن في قائمتها — انظر `EnglishCheckout`.
             *
             * ومن رفع ميزةَ الإهداء يُقرأ منه المستلِمُ ولو أطفأ خانتَه في
             * الطلب العاديّ: الهديّةُ تشترطه (`GiftOrders::after`).
             */
            'recipient_name' => EnglishCheckout::rules(
                $bid,
                $field('recipient', ['string', 'max:120']) ?? ($gifts ? ['nullable', 'string', 'max:120'] : null),
                EnglishCheckout::NAME,
            ),
            'recipient_phone' => CheckoutFields::shows($bid, 'recipient')
                ? array_merge(
                    [CheckoutFields::requires($bid, 'recipient') ? 'required' : 'nullable'],
                    array_slice(FlowerOrder::PHONE_RULE, 1),
                )
                : ($gifts ? FlowerOrder::PHONE_RULE : null),

            /*
             * وكرتُ الهدية — اختيارٌ بثمنٍ لا خانةُ نصّ.
             *
             * والنصُّ اختياريٌّ فيه: من يشتري الكرتَ ليكتبه بيده في المحلّ
             * يطلبه بلا نصّ، ومن أرفق تصميمًا لا يحتاج أن يكتب شيئًا.
             */
            /*
             * ═══ والإجماليُّ الذي رآه الزبونُ ووافق عليه ═══
             *
             * تُرسله الصفحةُ كما عُرض له. ولا يُحسب منه شيء — التسعيرُ من
             * القاعدة وحدها — وإنّما يُقابَل بما صار إليه: فإن سقط الخصمُ
             * بين التسعيرة والإتمام (كودٌ انتهت صلاحيتُه، أو نفدت مرّاتُه من
             * صندوقٍ آخر) عُلم أنّه لم يوافق على السعر الجديد، فيُقال له
             * ويُطلَب إقرارُه — ولا يمضي الطلبُ بسعرٍ لم يره.
             *
             * واختياريٌّ لأنّ الردَّ لا يقع إلّا حين يسقط الخصم: طلبٌ بلا
             * كوبونٍ لا يُسأل عن إقرارٍ لم يتبدّل فيه شيء.
             */
            'agreed_total' => ['nullable', 'numeric', 'min:0'],

            'gift_card' => ['nullable', 'boolean'],
            'card_align' => ['nullable', 'in:'.implode(',', GiftCard::ALIGNS)],
            'card_file' => ['nullable', 'string', 'max:64'],
            'card_file_name' => ['nullable', 'string', 'max:160'],
        ] + GiftOrders::rules($bid));

        /*
         * ═══ وقائمةُ القواعد هي الحارسُ وحدَها ═══
         *
         * كان هنا `strip()` يمسح ما أُرسل عن حقلٍ مُطفأ. وهو والقواعدُ
         * يقولان الشيءَ نفسَه: `validate()` لا تُرجع إلّا ما له قاعدة،
         * فحقلٌ خرج من القواعد لا يبلغ `$form` أصلًا مهما دُسّ في الحمولة.
         *
         * وحارسان لسؤالٍ واحد يفترقان يومًا — يُبدَّل أحدُهما ويُظنّ الآخرُ
         * يحرس. وقد نجا من الطفرات لأنّه لا يحرس شيئًا: عُطِّل فلم يتغيّر
         * شيء. فالحارسُ واحد، والقواعدُ أقربُ إلى ما يُقرأ.
         */
        $v = validator($payload, $rules, [
            'name.required' => __('اكتب اسمك.'),
            'phone.required' => __('اكتب رقم هاتفك.'),
            'phone.regex' => __('رقم الهاتف غير صحيح.'),
            'date.required' => __('اختر موعد التسليم.'),
            'date.after_or_equal' => __('الموعد لا يكون في الماضي.'),
            'pay.in' => __('اختر وسيلة الدفع.'),
        ] + EnglishCheckout::messages());

        $v->after(function ($v) use ($bid, $payload, $settings) {
            /*
             * وكرتٌ يُطلب من متجرٍ أطفأه يُردّ — لا يُبتلع صامتًا.
             *
             * الشاشةُ لا تعرضه، لكنّ من يعرف شكلَ الحمولة يُرسلها. وبلا هذا
             * يمرّ الطلبُ بلا كرتٍ ولا ثمن، فينتظر الزبونُ كرتًا لا يأتي.
             */
            if (filter_var($payload['gift_card'] ?? false, FILTER_VALIDATE_BOOL) && ! GiftCard::enabled($bid)) {
                $v->errors()->add('gift_card', __('كرت الهدية غير متاح في هذا المتجر.'));
            }

            /*
             * ═══ وطريقةٌ واحدة للكرت لا اثنتان ═══
             *
             * كان الزبونُ يكتب رسالةً **ويرفع تصميمًا** في الطلب نفسِه. فلا
             * الشاشةُ تقول أيُّهما يُطبع، ولا من يطبعه يعرف: يقرأ سطرًا في
             * الطلب وملفًّا مرفقًا يقولان شيئين، فيختار أحدَهما بظنّه — أو
             * يطبع الاثنين على كرتٍ واحد.
             *
             * فيُردّ الطلبُ ويُسأل: أيَّهما تريد؟
             *
             * والفراغُ يبقى مقبولًا كما كان: من يشتري الكرتَ ليكتبه بيده في
             * المحلّ يطلبه بلا نصٍّ ولا ملفّ. الممنوعُ الجمعُ لا الترك.
             */
            $hasText = self::text($payload['card'] ?? '') !== '';
            $hasFile = self::text($payload['card_file'] ?? '') !== '';

            if ($hasText && $hasFile) {
                $v->errors()->add('card', __('اختر طريقةً واحدة للكرت: رسالةً تكتبها أو ملفًّا ترفعه.'));
            }

            /*
             * ═══ والشرطُ مربوطٌ بالتوصيل لا بالحقل وحده ═══
             *
             * «مطلوب» في العنوان والمنطقة تعني «مطلوبٌ لمن يُوصَّل إليه».
             * ومن اختار الاستلامَ من المحلّ لا يُسأل عن عنوانه — وكان هذا
             * حالَ النظام قبل الشاشة ويبقى.
             */
            /*
             * والهديّةُ كأيّ طلب: لا تُعفى من العنوان ولا من المنطقة. كان
             * «تواصلوا مع المستلم» يُعفيها — ورُفع للطلبات الجديدة (`GiftOrders`).
             */
            if (self::text($payload['fulfil'] ?? null) === FlowerOrder::DELIVERY) {
                foreach (['address' => __('اكتب العنوان بالتفصيل.'), 'area' => __('اختر المنطقة.')] as $f => $msg) {
                    if (CheckoutFields::requires($bid, $f) && self::text($payload[$f] ?? '') === '') {
                        $v->errors()->add($f, $msg);
                    }
                }
            }

            /*
             * والقائمةُ تُحرَس متى ضُبطت ومتى أُرسلت قيمة.
             *
             * وهو غيرُ «مطلوب»: من ترك المنطقةَ اختياريّةً وضبط قائمةً لا
             * يريد منطقةً من خارجها — يريد أن يُقبل الفراغ. وقيمةٌ من خارج
             * القائمة تعني سائقًا يُرسَل إلى حيث لا يُوصَّل.
             */
            // والهديّةُ تشترط مستلِمَها — انظر `GiftOrders::after`
            GiftOrders::after($v, $bid, $payload);

            foreach (['area' => ['areas', __('اختر المنطقة.')], 'slot' => ['slots', __('اختر وقت التسليم.')]] as $f => [$list, $msg]) {
                if ($settings[$list] !== [] && CheckoutFields::shows($bid, $f)
                    && filled($payload[$f] ?? null) && ! in_array(trim((string) $payload[$f]), $settings[$list], true)) {
                    $v->errors()->add($f, $msg);
                }
            }
        });

        return $v->validate();
    }

    /** موعدُ التسليم: اليومُ المختار، وأوّلُ ساعةٍ من فترته إن كُتبت بساعة */
    private static function scheduledFor(array $form): ?Carbon
    {
        /*
         * ولا موعدَ لمن لم يُسأل عنه.
         *
         * متجرٌ أطفأ حقلَ الموعد يبيع لِما هو جاهزٌ الآن، فلا يُخترع له
         * موعدٌ: `Carbon::parse('')` تقرأ «الآن» فيخرج طلبٌ موعدُه لحظةُ
         * وقوعه، ويقف في لوحة التجهيز متأخّرًا بعد دقيقة.
         */
        if (! filled($form['date'] ?? null)) {
            return null;
        }

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

    /**
     * الزبونُ القائمُ بهذا الرقم — بحثًا لا إنشاءً.
     *
     * يقرؤه بابان: الإتمامُ ليعرف من يشتري، وبابُ البطاقة ليفحص حدَّ الكوبون
     * **قبل** أن يُفتح للزبون صفحةُ دفع. ولو فُحص بعدها لَدفع ثمّ رُدّ طلبُه،
     * فيصير مالٌ مقبوضًا بلا طلب — وهو أسوأُ ما يقع في هذا المسار.
     *
     * والمطابقةُ بالرقم مطبَّعًا: البطاقتان بالرقم نفسِه زبونٌ واحد.
     */
    private static function existingCustomer(int $bid, ?string $phone): ?Customer
    {
        if (blank($phone)) {
            return null;
        }

        $wanted = WhatsAppPhone::normalize($phone);

        $found = Customer::where('business_id', $bid)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'name', 'name_en', 'phone', 'language'])
            ->first(fn ($c) => (string) $c->phone === (string) $phone
                || ($wanted !== null && WhatsAppPhone::normalize($c->phone) === $wanted));

        return $found ? Customer::find($found->id) : null;
    }

    /** الزبونُ بهاتفه: يُعرف إن كان معروفًا، ويُكتب إن لم يكن */
    private static function customer(int $bid, array $form, string $lang): Customer
    {
        $existing = self::existingCustomer($bid, $form['phone']);

        if ($existing) {
            if ($existing->language === null) {
                $existing->forceFill(['language' => in_array($lang, ['ar', 'en'], true) ? $lang : 'ar'])->save();
            }

            return $existing->refresh();
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
