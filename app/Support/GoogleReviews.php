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
    /** مفتاح أبعاد في إعدادات المنصّة (business_id = null) — معمًّى كمفتاح التاجر */
    public const PLATFORM_KEY = 'google_places_key';

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
        /*
         * وبصمةُ المفتاح جزءٌ منه — لا مسحَ ذاكرةٍ عند تبديله.
         *
         * المفتاح قد يكون مفتاح المنصّة، يقرؤه مئةُ متجر. فتبديلُه بمسح
         * الذاكرة كلِّها يعني `Cache::flush()` — وهي تُسقط ما ليس لنا في
         * مخزنٍ مشترك. وبالبصمة يسقط المحفوظ بالمفتاح القديم وحده، من
         * تلقائه، في كلّ متجرٍ دفعةً واحدة.
         */
        return 'google-reviews:'.$businessId.':'.sha1($placeId)
            .':'.substr(sha1((string) self::apiKey($businessId)), 0, 8);
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
        /*
         * مفتاح التاجر أوّلًا، ثمّ مفتاح المنصّة.
         *
         * وكان مفتاح التاجر وحده: يُطلب من صاحب محلّ وردٍ في مسقط أن يفتح
         * حسابًا في Google Cloud وينشئ مشروعًا ويُفعّل واجهةً ويربط بطاقةً —
         * ليقرأ تقييمات محلّه. فلا يفعل، فتبقى الشاشة فارغة، ولا شيء يقول
         * إنّ العطب في أنّ الطريق لم يكن معبَّدًا أصلًا.
         *
         * فصار لأبعاد مفتاحُها: التاجر يحدّد محلَّه وتُقرأ تقييماته. ومن
         * أراد أن تُحتسب النداءات على حسابه هو يلصق مفتاحه فيتقدّم على
         * مفتاح المنصّة — لأنّ فاتورته فاتورتُه.
         */
        return self::decrypt(MarketingSettings::group($businessId, 'google')['google_api_key'] ?? '')
            ?? self::platformKey();
    }

    /**
     * مراحلُ الربط — بترتيبها، وحالُ كلٍّ منها ومن يملك إصلاحها.
     *
     * والشكل شكلُ واتساب نفسه (انظر `Integration::step`) لأنّ الشاشتين
     * ترسمانه برسّامٍ واحد: أداةٌ تكتب حقلًا باسمٍ آخر تعني شاشةً تعرف كلّ
     * أداةٍ على حدة، وتعني خطوةً تُضاف في إحداهما ولا تظهر في الأخرى.
     *
     * و`$pulled` يُمرَّر ولا يُسحب هنا: `pull` نداءٌ على الشبكة، والشاشة
     * تسحبه أصلًا. وسحبُه مرّتين في الفتحة الواحدة نداءٌ يُحتسب على الفاتورة
     * ولا يُقرأ منه شيء.
     *
     * @param  array{state:string, error:?string}  $pulled  ما ردّته `pull`
     * @return array{connected:bool, ready:bool, steps:list<array<string,mixed>>}
     */
    public static function readiness(int $businessId, array $pulled): array
    {
        $started = MarketingSettings::group($businessId, 'connect')['google_setup_started'];
        $placeId = self::forBusiness($businessId)['place_id'];
        $own = self::keyHint($businessId) !== null;

        /* بدأ: ضغط الزرّ، أو حدّد محلَّه قبل أن يوجد الزرّ */
        return Integration::payload(
            $started === '1' || $placeId !== null,
            [
            /*
             * المفتاح أوّلًا لأنّه شرطُ القراءة — وهو على أبعاد لا عليه.
             *
             * وكان على التاجر أن يفتح حسابًا في Google Cloud ويُنشئ مشروعًا
             * ويربط بطاقة ليقرأ تقييمات محلّه. فلا يفعل، فتبقى الشاشة فارغة.
             */
            Integration::step(
                'platform',
                'خرائط Google مهيّأة في أبعاد',
                self::apiKey($businessId) !== null,
                detail: $own ? __('تُقرأ بمفتاحك أنت — والنداءات على حسابك.') : null,
                fix: 'مفتاح الخرائط إعدادُ أبعاد لا إعدادُك — راجعنا لتهيئته، أو الصق مفتاحك الخاصّ.',
                theirs: true,
            ),
            /* وهذه وحدها بيده: أيُّ محلٍّ من ملايين المحلّات هو محلُّك */
            Integration::step(
                'place',
                'محلّك محدَّد على الخرائط',
                $placeId !== null,
                fix: 'الصق معرّف المكان أدناه — ورابطُ الخرائط العاديّ لا يحمله.',
            ),
            Integration::step(
                'reviews',
                'تقييماتك تُقرأ هنا',
                ($pulled['state'] ?? '') === 'ok',
                fix: match ($pulled['state'] ?? '') {
                    // خطأُ Google يُقال بنصّه: «لم تُقرأ» لا يقول ما يُصلَح
                    'error' => $pulled['error'] ?? 'لم تُقرأ التقييمات — راجع المعرّف والمفتاح.',
                    'nokey' => 'لا مفتاح — أكمل الخطوة الأولى.',
                    'unlinked' => 'حدّد محلّك أوّلًا.',
                    default => null,
                },
                theirs: ($pulled['state'] ?? '') === 'nokey',
            ),
        ]);
    }

    /** مفتاح أبعاد — يُقرأ لكلّ تاجرٍ لم يلصق مفتاحه */
    public static function platformKey(): ?string
    {
        return self::decrypt(
            \App\Models\Setting::whereNull('business_id')->where('key', self::PLATFORM_KEY)->value('value')
        );
    }

    /** يحفظ مفتاح المنصّة معمًّى — و`null` محوٌ صريح */
    public static function storePlatformKey(?string $plain): void
    {
        $plain = trim((string) $plain);

        \App\Models\Setting::updateOrCreate(
            ['business_id' => null, 'key' => self::PLATFORM_KEY],
            ['value' => $plain === '' ? '' : Crypt::encryptString($plain)],
        );

        // ولا مسحَ ذاكرة: بصمةُ المفتاح في مفتاحها — انظر cacheKey
    }

    /** آخرُ أربعةِ أحرفٍ من مفتاح المنصّة — أو null إن لم يُحفظ */
    public static function platformKeyHint(): ?string
    {
        $key = self::platformKey();

        return $key === null ? null : '••••'.substr($key, -4);
    }

    /** فكُّ التعمية — والفشلُ غيابٌ لا استثناء (انظر apiKey) */
    private static function decrypt(?string $stored): ?string
    {
        $stored = trim((string) $stored);

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
        /*
         * ومفتاحُ التاجر وحده — لا الذي يقع عليه `apiKey`.
         *
         * تلك تردّ مفتاح المنصّة لمن لا مفتاح له، ولو قُرئ هنا لَقال للتاجر
         * «مفتاحك محفوظ ••••ab12» وهو لم يحفظ شيئًا — فيبحث عن مفتاحٍ لا
         * يملكه ليحذفه أو يبدّله.
         */
        $key = self::decrypt(MarketingSettings::group($businessId, 'google')['google_api_key'] ?? '');

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
