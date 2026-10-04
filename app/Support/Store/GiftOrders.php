<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use App\Support\WhatsAppPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ميزةُ الإهداء — الطلبُ هديّةٌ لغير مشتريه، لأيّ متجرٍ يرفع مفتاحَها.
 *
 * ═══ ولمَ مشتركةٌ لا لمتجرٍ بعينه ═══
 *
 * المفتاحُ في إعدادات المتجر نفسِه (`store_gift_checkout`)، مطفأٌ حتّى يرفعه
 * صاحبُه، ولا يُسأل فيه عن معرّفٍ ولا عن واجهة. والقواعدُ والحفظُ هنا، يقرؤها
 * `WebCheckout` — بابُ إتمام الطلب المشترك. والواجهةُ ترسم الخانات وحدها.
 *
 * ═══ وما الهديّة ═══
 *
 * - **المشتري يبقى صاحبَ الطلب:** هو العميلُ والدافعُ وصاحبُ الفاتورة، ولا
 *   يُستبدل بالمستلِم. واسمُه هو المُهدي (`sender_name`) بلا أن يُسأل ثانيةً.
 * - **والمستلِمُ مطلوب:** اسمُه ورقمُه — بقاعدة الهاتف القائمة.
 * - **والمناسبةُ اختياريّة:** من القائمة الثابتة (`FlowerOrder::OCCASIONS`)،
 *   و«أخرى» يكتبها الزبونُ بيده (`occasion_text`).
 * - **و«لا تذكر اسمي»** (`hide_sender`) يخفيه عن المستلِم وحده — التاجرُ
 *   يرى المشتري دائمًا (`FlowerOrder::cardForRecipient`).
 * - **وموقعُ المستلِم:** يكتبه المشتري الآن (`provided`) فتبقى قواعدُ العنوان
 *   كما هي، أو يتواصل المتجرُ مع المستلِم (`contact_recipient`) فيُقبل الطلبُ
 *   بلا عنوان — ويبقى العنوانُ فارغًا حتّى يُعرف، لا نصًّا يقول «سيُتواصل».
 *
 * ولا تمسّ مالًا: لا ثمنَ للهديّة، ولا كرتَ يُضاف معها — كرتُ الهدية
 * (`GiftCard`, `GiftCardProduct`) شيءٌ آخر يبقى كما هو.
 */
final class GiftOrders
{
    public const KEY = 'store_gift_checkout';

    /** يكتب المشتري موقعَ المستلِم الآن */
    public const PROVIDED = 'provided';

    /** يتواصل المتجرُ مع المستلِم ليعرف موقعَه */
    public const CONTACT = 'contact_recipient';

    public const MODES = [self::PROVIDED, self::CONTACT];

    /** هل رفع هذا المتجرُ ميزةَ الإهداء؟ — مفتاحُه وحده */
    public static function on(int $businessId): bool
    {
        return (string) (MarketingSettings::group($businessId, 'website')[self::KEY] ?? '0') === '1';
    }

    /**
     * قواعدُ الحقول — لمن رفع الميزة وحده؛ وما لا قاعدةَ له لا يُحفظ.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(int $businessId): array
    {
        if (! self::on($businessId)) {
            return [];
        }

        return [
            'is_gift' => ['nullable', 'boolean'],
            'occasion' => ['nullable', Rule::in(FlowerOrder::OCCASIONS)],
            'occasion_text' => ['nullable', 'string', 'max:'.FlowerOrder::CUSTOM_LABEL_MAX],
            'hide_sender' => ['nullable', 'boolean'],
            'recipient_location' => ['nullable', Rule::in(self::MODES)],
        ];
    }

    /** هل الحمولةُ هديّةٌ في متجرٍ رفع الميزة؟ */
    public static function wanted(int $businessId, array $payload): bool
    {
        return self::on($businessId) && filter_var($payload['is_gift'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** هديّةٌ تُوصَّل ويتواصل المتجرُ مع مستلِمها لموقعه — فلا يُشترط عنوان */
    public static function contactsRecipient(int $businessId, array $payload): bool
    {
        return self::wanted($businessId, $payload)
            && self::text($payload['fulfil'] ?? '') === FlowerOrder::DELIVERY
            && self::text($payload['recipient_location'] ?? '') === self::CONTACT;
    }

    /**
     * ما تشترطه الهديّة — بعد قواعد الحقول.
     *
     * المستلِمُ اسمًا ورقمًا، ولو أطفأ صاحبُ المحلّ خانةَ المستلِم في الطلب
     * العاديّ: الهديّةُ بلا من تُهدى إليه لا تُسلَّم.
     */
    public static function after(Validator $v, int $businessId, array $payload): void
    {
        if (! self::wanted($businessId, $payload)) {
            return;
        }

        if (self::text($payload['recipient_name'] ?? '') === '') {
            $v->errors()->add('recipient_name', __('اكتب اسم المستلم.'));
        }

        if (self::text($payload['recipient_phone'] ?? '') === '') {
            $v->errors()->add('recipient_phone', __('اكتب رقم المستلم.'));
        }
    }

    /**
     * أعمدةُ الهديّة على الطلب — ولا شيءَ لطلبٍ ليس هديّة.
     *
     * @param  array<string, mixed>  $form  ما تحقّق منه `WebCheckout::validated`
     * @return array<string, mixed>
     */
    public static function columns(int $businessId, array $form): array
    {
        if (! self::wanted($businessId, $form)) {
            return [];
        }

        $occasion = self::text($form['occasion'] ?? '');
        $custom = self::text($form['occasion_text'] ?? '');
        $delivery = self::text($form['fulfil'] ?? '') === FlowerOrder::DELIVERY;
        $mode = self::text($form['recipient_location'] ?? '');

        return [
            'is_gift' => true,
            'hide_sender' => filter_var($form['hide_sender'] ?? false, FILTER_VALIDATE_BOOL),
            'occasion_type' => $occasion !== '' ? $occasion : null,
            // نصُّ «أخرى» وحدها — ولا يُكتب لمناسبةٍ من القائمة
            'occasion_text' => $occasion === 'other' && $custom !== '' ? $custom : null,
            // ولا طريقةَ موقعٍ لاستلامٍ من المحلّ
            'recipient_location_mode' => $delivery ? (in_array($mode, self::MODES, true) ? $mode : self::PROVIDED) : null,
        ];
    }

    /**
     * المناسباتُ في إتمام الطلب — الثابتةُ وحدها، بلغة الصفحة.
     *
     * ومناسباتُ المتجر المضافة بيده (`FlowerOrder::customOccasions`) لا تُعرض
     * هنا: تُخزَّن بنصّها العربيّ، فتظهر عربيّةً في الصفحة الإنجليزيّة.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function occasionOptions(): array
    {
        return array_map(
            fn ($k) => ['value' => $k, 'label' => __(FlowerOrder::OCCASION_LABELS[$k])],
            FlowerOrder::OCCASIONS,
        );
    }

    /** المناسبةُ كما تُقرأ في شاشة الطلب — و«أخرى» بنصّها إن كُتب */
    public static function occasionLabel(Order $order): ?string
    {
        if ($order->occasion_type === 'other' && filled($order->occasion_text)) {
            return __('أخرى').': '.$order->occasion_text;
        }

        return FlowerOrder::occasionLabel($order->occasion_type);
    }

    /** بانتظار التواصل مع المستلِم لموقعه — ولم يُكتب عنوانٌ بعد */
    public static function awaitingLocation(Order $order): bool
    {
        return (bool) $order->is_gift
            && $order->fulfillment_type === FlowerOrder::DELIVERY
            && $order->recipient_location_mode === self::CONTACT
            && blank($order->delivery_address);
    }

    /** طريقةُ تحديد الموقع كما تُقرأ في شاشة الطلب */
    public static function locationLabel(Order $order): ?string
    {
        return match (true) {
            self::awaitingLocation($order) => __('الموقع: بانتظار التواصل مع المستلم'),
            $order->recipient_location_mode === self::CONTACT => __('تواصلوا مع المستلم للحصول على الموقع'),
            $order->recipient_location_mode === self::PROVIDED => __('سأدخل الموقع الآن'),
            default => null,
        };
    }

    /**
     * هاتفُ المشتري في كتلة الهديّة — لمن يرى العملاءَ أصلًا.
     *
     * لا صلاحيةَ جديدةً للإهداء: من يفتح الطلبَ يراه بقسم «المبيعات»،
     * ورقمُ العميل خلف قسم «العملاء» كما في صفحته. فموظّفٌ يجهّز الطلبَ
     * ولا يرى العملاءَ لا يبلغ رقمَ المشتري لأنّ الطلبَ هديّة.
     *
     * و«لا تذكر اسمي» لا يمسّه: يُخفي المشتريَ عن المستلِم وحده.
     */
    public static function buyerPhone(Order $order, ?User $viewer): ?string
    {
        if (! $order->is_gift || ! $viewer?->allows('customers')) {
            return null;
        }

        return $order->customer?->phone ?: null;
    }

    /**
     * رسالةُ التاجر إلى المستلِم ليعرف موقعَه — تُفتح ولا تُرسَل.
     *
     * لا اسمَ مُهدٍ فيها (أُخفي أو لم يُخفَ: المستلِمُ يعرفه من الكرت إن أراد
     * المشتري)، ولا ثمنَ ولا هاتفَ المشتري ولا شيءَ من الدفع. اسمُ المتجر
     * والطلبُ وحدهما.
     *
     * و`null` إن لم يكن الطلبُ ينتظر موقعًا أو لا رقمَ صالحًا للمستلِم.
     */
    public static function contactLink(Order $order, string $shop): ?string
    {
        if (! self::awaitingLocation($order)) {
            return null;
        }

        $phone = WhatsAppPhone::normalize($order->recipient_phone);

        if (! $phone) {
            return null;
        }

        $text = implode("\n", [
            __('مرحبًا، لدينا توصيل هدية لكم من متجر :shop 🌷', ['shop' => $shop]),
            __('نرجو إرسال موقع التوصيل المناسب لإكمال الطلب.'),
            __('شكرًا لكم.'),
        ]);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
