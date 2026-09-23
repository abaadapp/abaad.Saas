<?php

namespace App\Support\Store;

use App\Support\FlowerOrder;
use App\Support\MarketingSettings;

/**
 * حقولُ إتمام الطلب — ما يُعرض منها وما يُشترط، كما ينتقيه صاحبُ المحلّ.
 *
 * ═══ ولمَ موضعٌ واحد تقرأ منه الشاشةُ والخادم ═══
 *
 * الشاشةُ ترسم الحقلَ والخادمُ يشترطه. ولو قرأ كلٌّ منهما من عنده لَافترقا
 * يومًا: حقلٌ مُخفًى في الشاشة ما زال مطلوبًا في الخادم يردّ الطلبَ بخطأٍ
 * عن حقلٍ لا يراه الزبون — فلا يعرف ماذا يُصلح، ويُغلق الصفحة.
 *
 * والعكسُ أسوأ: حقلٌ يُعرض «مطلوبًا» ولا يشترطه الخادم يمرّ فارغًا، فيصل
 * الطلبُ بلا عنوانٍ ولا موعد.
 *
 * ═══ والفراغُ يعني «ما كان» ═══
 *
 * مفتاحٌ لم يُكتب يُقرأ بالقاعدة التي كان يعمل بها المتجر قبل هذه الشاشة —
 * لا بقيمةٍ يخترعها النظام. فمتجرٌ يعمل اليوم لا يتبدّل عليه شيءٌ بترقية،
 * ولا يستيقظ صاحبُه على حقلٍ صار مطلوبًا لم يطلبه. وهي قاعدةُ
 * `store_delivery_fee` و`store_gift_card_price` نفسُها في هذا الملفّ.
 */
final class CheckoutFields
{
    /** لا يُعرض ولا يُشترط ولا يُقرأ ممّا أُرسل */
    public const OFF = 'off';

    /** يُعرض ويُقبل فارغًا */
    public const OPTIONAL = 'optional';

    /** يُعرض ولا يمضي الطلبُ بلا مِلئه */
    public const REQUIRED = 'required';

    public const STATES = [self::OFF, self::OPTIONAL, self::REQUIRED];

    /**
     * الحقولُ التي يملك صاحبُ المحلّ أمرَها.
     *
     * ═══ وما ليس فيها ليس سهوًا ═══
     *
     * «الاسم» و«الهاتف» و«طريقة الاستلام» و«الدفع» تحمل النظامَ لا الشاشة:
     * الزبونُ يُعرف بهاتفه (`WebCheckout::customer`)، والطلبُ بلا اسمٍ لا
     * يُنادى في التجهيز، وبلا طريقةِ استلامٍ لا يُعرف أيخرج مع سائقٍ أم
     * ينتظر على الرفّ. فإخفاؤها ليس خيارًا يُتاح ويُندم عليه.
     *
     * ونصُّ الكرت له مفتاحُه (`store_gift_card`) — فلا يُحكم من موضعين.
     */
    public const FIELDS = ['area', 'address', 'date', 'slot', 'recipient', 'promo'];

    /** أقصى ما يُحجز مقدَّمًا حين لا يُكتب عدد — وهو ما كان قبل الشاشة */
    public const DEFAULT_MAX_DAYS = 60;

    /* ═══════════ حالُ الحقل ═══════════ */

    /**
     * حالُ حقلٍ في هذا المتجر — والفراغُ يُقرأ بما كان.
     *
     * @return self::OFF|self::OPTIONAL|self::REQUIRED
     */
    public static function state(int $businessId, string $field): string
    {
        $site = MarketingSettings::group($businessId, 'website');
        $raw = trim((string) ($site['store_field_'.$field] ?? ''));

        if (in_array($raw, self::STATES, true)) {
            return $raw;
        }

        return self::legacy($businessId, $field, $site);
    }

    /**
     * ما كان عليه الحقلُ قبل أن تُفتح هذه الشاشة.
     *
     * و«المنطقة» وحدَها تتبع إعدادَ المناطق: كانت تُشترط حين تُضبط قائمةٌ
     * («اختر المنطقة») وتُترك حرّةً حين لا تُضبط. ولو جُعلت مطلوبةً دائمًا
     * لَصار حقلٌ حرٌّ في مئة متجرٍ إلزامًا لم يطلبه أحد.
     *
     * @param  array<string, mixed>  $site
     */
    private static function legacy(int $businessId, string $field, array $site): string
    {
        if ($field === 'area') {
            return trim((string) ($site['store_delivery_areas'] ?? '')) === ''
                ? self::OPTIONAL
                : self::REQUIRED;
        }

        return in_array($field, ['address', 'date'], true) ? self::REQUIRED : self::OPTIONAL;
    }

    /** أيُرسم هذا الحقل في الصفحة؟ */
    public static function shows(int $businessId, string $field): bool
    {
        return self::state($businessId, $field) !== self::OFF;
    }

    /** أيمضي الطلبُ بلا مِلئه؟ */
    public static function requires(int $businessId, string $field): bool
    {
        return self::state($businessId, $field) === self::REQUIRED;
    }

    /**
     * الحقولُ كلُّها بحالها — للشاشة ولمن يبني القواعد.
     *
     * @return array<string, string>
     */
    public static function all(int $businessId): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = self::state($businessId, $field);
        }

        return $out;
    }

    /* ═══════════ طرقُ الاستلام ═══════════ */

    /**
     * ما يُعرض من طرق الاستلام — ولا تخلو من واحدة.
     *
     * محلٌّ لا يوصّل يُطفئ التوصيل فيختفي العنوانُ والمنطقةُ والرسم معه،
     * ومحلٌّ لا يستقبل زبائنَه يُطفئ الاستلام. وإطفاؤهما معًا يعني متجرًا
     * لا يُسلّم شيئًا — فيُقرأ الفراغُ «الاثنتان» لا «لا شيء»، وإلّا سقط
     * المتجرُ كلُّه بإعدادٍ حُفظ خطأً.
     *
     * @return list<string>
     */
    public static function fulfilments(int $businessId): array
    {
        $raw = (string) (MarketingSettings::group($businessId, 'website')['store_fulfil'] ?? '');

        $picked = array_values(array_intersect(
            FlowerOrder::FULFILLMENT,
            array_map('trim', explode(',', $raw)),
        ));

        return $picked ?: FlowerOrder::FULFILLMENT;
    }

    /** أيُسلَّم بالتوصيل أصلًا؟ — وعليه يتوقّف الرسمُ والعنوان */
    public static function delivers(int $businessId): bool
    {
        return in_array(FlowerOrder::DELIVERY, self::fulfilments($businessId), true);
    }

    /* ═══════════ الموعد ═══════════ */

    /** كم يومًا يُحجز مقدَّمًا — والفراغُ ستّون كما كان */
    public static function maxDays(int $businessId): int
    {
        $raw = trim((string) (MarketingSettings::group($businessId, 'website')['store_max_days'] ?? ''));

        if ($raw === '' || ! is_numeric($raw)) {
            return self::DEFAULT_MAX_DAYS;
        }

        return max(0, min(365, (int) $raw));
    }
}
