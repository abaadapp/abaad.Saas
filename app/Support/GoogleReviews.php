<?php

namespace App\Support;

/**
 * ربطُ المحلّ بملفّه على خرائط Google — ومنه رابطُ طلب التقييم.
 *
 * وما يفعله هذا الربط بالضبط: يحوّل **معرّف المكان** إلى رابطٍ يفتح نافذة
 * «اكتب تقييمًا» على ملفّ هذا المحلّ بعينه. فيُطبع رمزًا على الإيصال، يمسحه
 * الزبون وهو واقفٌ عند المنضدة فيكتب تقييمه — وهي اللحظة الوحيدة التي
 * يكتب فيها أحدٌ تقييمًا.
 *
 * وما **لا** يفعله، ويجب أن يُقال: لا يسحب نصوصَ التقييمات من Google إلى
 * النظام. ذاك يحتاج «Google Business Profile API» بموافقةٍ مسبقة من Google
 * على النشاط نفسه، ولا يُفتح بمفتاحٍ يكتبه التاجر في حقل. ووعدٌ بذلك هنا
 * يعني شاشةً تقول «التقييمات ٠» إلى الأبد، ويظنّ صاحبها أنّ محلّه بلا تقييم.
 */
class GoogleReviews
{
    /**
     * معرّفُ المكان — أو null إن لم يُقرأ.
     *
     * ويُقبل المعرّف مكتوبًا، أو رابطًا يحمله صراحةً (`place_id:` أو
     * `placeid=`). ولا يُستخرج من رابط `/maps/place/...` العاديّ: ذاك يحمل
     * رقم CID لا معرّف المكان، وتحويلُه تخمينٌ — ومعرّفٌ خاطئ يرسل زبائنك
     * ليقيّموا محلًّا آخر، وهو عطبٌ لا يظهر لصاحبه أبدًا.
     */
    public static function placeId(?string $input): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        // مكتوبًا كما هو
        if (preg_match('/^[A-Za-z0-9_-]{20,}$/', $input)) {
            return $input;
        }

        foreach (['/place_id[:=]([A-Za-z0-9_-]{20,})/', '/placeid=([A-Za-z0-9_-]{20,})/i'] as $pattern) {
            if (preg_match($pattern, $input, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** هل هذا المدخَل مقروء؟ — للتحقّق قبل الحفظ */
    public static function readable(?string $input): bool
    {
        return self::placeId($input) !== null;
    }

    /** رابطُ «اكتب تقييمًا» — يفتح النافذة على هذا المحلّ مباشرةً */
    public static function reviewUrl(?string $placeId): ?string
    {
        $id = self::placeId($placeId);

        return $id === null ? null : 'https://search.google.com/local/writereview?placeid='.$id;
    }

    /** رابطُ الملفّ على الخرائط — ليتحقّق التاجر بعينه أنّه محلّه */
    public static function placeUrl(?string $placeId): ?string
    {
        $id = self::placeId($placeId);

        return $id === null ? null : 'https://www.google.com/maps/place/?q=place_id:'.$id;
    }

    /** إعدادات المتجر — المعرّف كما حُفظ ورابطاه */
    public static function forBusiness(int $businessId): array
    {
        $settings = MarketingSettings::group($businessId, 'google');
        $id = self::placeId($settings['google_place_id'] ?? null);

        return [
            'place_id' => $id,
            'source' => $settings['google_maps_url'] ?? '',
            'on_receipt' => ($settings['google_review_on_receipt'] ?? '0') === '1',
            'review_url' => self::reviewUrl($id),
            'place_url' => self::placeUrl($id),
        ];
    }

    /**
     * هل يُطبع رمزُ التقييم على الإيصال؟
     *
     * الشرطان معًا: مقبضٌ مُشغَّل ومعرّفٌ مقروء. ومقبضٌ يعمل بلا معرّف يطبع
     * رمزًا لا يفتح شيئًا — ورقةٌ فيها مربّعٌ أسود يمسحه الزبون فلا يجد.
     */
    public static function onReceipt(int $businessId): ?string
    {
        $config = self::forBusiness($businessId);

        return $config['on_receipt'] && $config['review_url'] ? $config['review_url'] : null;
    }
}
