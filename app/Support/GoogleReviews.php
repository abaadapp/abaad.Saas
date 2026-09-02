<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * ربطُ المحلّ بملفّه على خرائط Google — ومنه رابطُ طلب التقييم.
 *
 * وما يفعله هذا الربط بالضبط: يحوّل **معرّف المكان** إلى رابطٍ يفتح نافذة
 * «اكتب تقييمًا» على ملفّ هذا المحلّ بعينه. فيُطبع رمزًا على الإيصال، يمسحه
 * الزبون وهو واقفٌ عند المنضدة فيكتب تقييمه — وهي اللحظة الوحيدة التي
 * يكتب فيها أحدٌ تقييمًا.
 *
 * ويسحب التقييمات كذلك — بمفتاح «Places API (New)» من مشروع التاجر في
 * Google Cloud. وكان هذا مرفوضًا هنا بحجّة أنّه يحتاج «Business Profile API»
 * بموافقةٍ على النشاط: وذاك صحيحٌ لبابٍ آخر — بابِ **الردّ** على التقييمات
 * وإدارةِ الملفّ. أمّا قراءةُ الملفّ العامّ ومعدّلِه وتقييماتِه فتُفتح بمفتاح
 * Places، وهو ما يفعله `GooglePlaces`.
 *
 * وما **لا** يفعله، ويجب أن يُقال: لا يخزّن التقييمات في قاعدتنا. تُسحب حيّةً
 * وتُحفظ في الذاكرة ساعاتٍ معدودة ثمّ تسقط — لأنّ شروط Google تمنع الاحتفاظ
 * بمحتوى الأماكن، ولأنّ تقييمًا حُذف من هناك يجب أن يختفي من هنا. وخمسةٌ حدُّ
 * ما تُعيده Google من النصوص مهما بلغ عددُ التقييمات.
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

    /* ------------------------------ سحب التقييمات ----------------------------- */

    /**
     * كم تبقى المسحوبات في الذاكرة — بالدقائق.
     *
     * ستُّ ساعاتٍ لا دائمًا ولا لحظيًّا: كلُّ فتحةٍ للصفحة نداءٌ مدفوعٌ على
     * Google، فشاشةٌ تُفتح عشر مراتٍ في اليوم تصير عشرة نداءات. والاحتفاظ
     * الدائم ممنوعٌ بشروطهم، ويُبقي تقييمًا حُذف من هناك معروضًا هنا.
     */
    public const CACHE_MINUTES = 360;

    /** موضعُ المسحوب في الذاكرة — لكلّ متجرٍ ومعرّفٍ موضعُه */
    private static function cacheKey(int $businessId, string $placeId): string
    {
        return 'google-reviews:'.$businessId.':'.sha1($placeId);
    }

    /**
     * مفتاحُ الواجهة كما حُفظ — مفكوكَ التعمية.
     *
     * ويُخزَّن معمًّى: إعداداتُ المتاجر تُقرأ في شاشاتٍ وتُصدَّر في نُسَخٍ
     * احتياطية، ومفتاحٌ مكشوفٌ فيها فاتورةٌ يدفعها صاحبُه على نداءات غيره.
     *
     * وفكُّه قد يفشل إن بُدّل `APP_KEY` — فيُعدّ غائبًا لا يُرمى استثناءً:
     * صفحةٌ تنكسر لا تخبر التاجر أنّ عليه إعادةَ لصق مفتاحه.
     */
    public static function apiKey(int $businessId): ?string
    {
        $stored = trim((string) (MarketingSettings::group($businessId, 'google')['google_api_key'] ?? ''));

        if ($stored === '') {
            return null;
        }

        try {
            $key = trim(Crypt::decryptString($stored));
        } catch (\Throwable) {
            return null;
        }

        return $key === '' ? null : $key;
    }

    /** يحفظ المفتاح معمًّى — والفراغُ محوٌ صريح */
    public static function storeKey(int $businessId, ?string $plain): void
    {
        $plain = trim((string) $plain);

        MarketingSettings::save($businessId, 'google', [
            'google_api_key' => $plain === '' ? '' : Crypt::encryptString($plain),
        ]);

        self::forget($businessId);
    }

    /**
     * آخرُ أربعةِ أحرفٍ من المفتاح — ليعرف التاجر أيَّ مفتاحٍ حفظ.
     *
     * ولا يُرسل المفتاح إلى الشاشة كاملًا: صفحةُ الإعدادات تُفتح على شاشةٍ في
     * المحلّ، ومفتاحٌ ظاهرٌ في حقلٍ يُقرأ من خلف الكتف.
     */
    public static function keyHint(int $businessId): ?string
    {
        $key = self::apiKey($businessId);

        return $key === null ? null : '••••'.substr($key, -4);
    }

    /** يُسقط المسحوب من الذاكرة — بعد تبديل معرّفٍ أو مفتاح، وعند «حدِّث الآن» */
    public static function forget(int $businessId): void
    {
        $id = self::forBusiness($businessId)['place_id'];

        if ($id !== null) {
            Cache::forget(self::cacheKey($businessId, $id));
        }
    }

    /**
     * تقييماتُ المحلّ من Google.
     *
     * والحالةُ تُقال باسمها لا بمصفوفةٍ فارغة: «غير مربوط» و«بلا مفتاح»
     * و«خطأ» و«جاهز» أربعةُ مواقفَ لكلٍّ منها ما يُفعل. ولو ردّت جميعًا
     * قائمةً فارغة لَرأى التاجر «لا تقييمات» في الحالات الأربع — وهي في
     * ثلاثٍ منها كذبة.
     *
     * @return array{state:string, error:?string, fetched_at:?string, place:?array}
     */
    public static function pull(int $businessId, bool $refresh = false): array
    {
        $placeId = self::forBusiness($businessId)['place_id'];

        if ($placeId === null) {
            return self::state('unlinked');
        }

        $key = self::apiKey($businessId);

        if ($key === null) {
            return self::state('nokey');
        }

        $slot = self::cacheKey($businessId, $placeId);

        if ($refresh) {
            Cache::forget($slot);
        }

        $cached = Cache::get($slot);

        if (is_array($cached)) {
            return $cached;
        }

        $result = GooglePlaces::details($placeId, $key);

        if (! $result['ok']) {
            /*
             * الخطأ لا يُحفظ في الذاكرة.
             *
             * ومفتاحٌ صُحِّح في Google Cloud يعمل في اللحظة، فحفظُ الرفض ستَّ
             * ساعاتٍ يجعل التاجر يصحّح ثمّ يرى الرفضَ نفسه فيظنّ أنّه لم يُصلح.
             */
            return self::state('error', error: $result['error']);
        }

        $payload = self::state('ok', place: $result['place'], fetchedAt: now()->toIso8601String());

        Cache::put($slot, $payload, now()->addMinutes(self::CACHE_MINUTES));

        return $payload;
    }

    private static function state(
        string $state,
        ?string $error = null,
        ?array $place = null,
        ?string $fetchedAt = null,
    ): array {
        return ['state' => $state, 'error' => $error, 'fetched_at' => $fetchedAt, 'place' => $place];
    }
}
