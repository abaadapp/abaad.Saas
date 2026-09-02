<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * الظهور في البحث وربطُ Google Analytics — لموقع التاجر خارج النظام.
 *
 * والحدُّ يُقال أوّلًا لأنّه يحكم كلَّ ما بعده: **الموقع ليس عندنا**. لا
 * نستضيفه ولا نبني صفحاته، فلا نستطيع أن نضع فيه وسمًا ولا عنوانًا ولا
 * وصفًا. وكلُّ ما تفعله هذه الشاشة شيئان: تُعطيه **ما يلصقه** في موقعه،
 * ثمّ **تفتح موقعه وتقول ما رأت**.
 *
 * ولهذا لا حقلَ هنا لعنوان الصفحة ولا لوصفها ولا للكلمات المفتاحية — وهي
 * الحقول التي حُذفت من `MarketingSettings` لأنّها تُملأ ولا يقرؤها شيء.
 * وحقلٌ يُملأ ولا يصل صفحةً أسوأ من غيابه: يظنّ صاحبُه أنّه ضبط سيو موقعه.
 *
 * و«مربوط» هنا تعني أنّ الوسم **رُئي في الصفحة**، لا أنّ معرّفًا لُصق في
 * حقل. ولو قيست الحالة باللصق لَقالت الشاشة «مربوط» لمن نسي أن يلصق الوسم
 * في موقعه، فينتظر أرقامًا لا تأتي ولا يعرف لماذا.
 */
class Seo
{
    /**
     * كم يبقى الفحص في الذاكرة — بالدقائق.
     *
     * ثلاثون: الفحصُ يفتح موقع التاجر من خادمنا، وشاشةٌ تُفتح عشر مرّاتٍ في
     * اليوم تصير عشرة طلباتٍ على استضافته. وهو أيضًا بطيء — ثلاثةُ طلباتٍ
     * لصفحةٍ وrobots وsitemap — فلا يُعاد عند كلّ فتحة.
     */
    public const CACHE_MINUTES = 30;

    /**
     * معرّفُ القياس — أو null إن لم يُقرأ.
     *
     * و`G-` وحدها هي المقبولة: `UA-` توقّفت عن جمع البيانات منتصف ٢٠٢٣،
     * و`GTM-` معرّفُ «مدير الوسوم» لا القياس. ومن لصق أحدهما ورأى «مربوط»
     * انتظر أرقامًا لا تأتي أبدًا — والشاشة هي التي كذبت عليه.
     */
    public static function measurementId(?string $input): ?string
    {
        $value = strtoupper(trim((string) $input));

        return preg_match('/^G-[A-Z0-9]{4,}$/', $value) ? $value : null;
    }

    /** ماذا يُلصق في الموقع — الوسم كاملًا بمعرّفه */
    public static function snippet(string $measurementId): string
    {
        return <<<HTML
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={$measurementId}"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', '{$measurementId}');
        </script>
        HTML;
    }

    /** إعدادات المتجر: النطاق والمعرّف وما يُبنى منهما */
    public static function forBusiness(int $businessId): array
    {
        $domain = trim((string) (MarketingSettings::group($businessId, 'website')['site_domain'] ?? ''));
        $id = self::measurementId(MarketingSettings::group($businessId, 'seo')['ga_measurement_id'] ?? null);

        return [
            'domain' => $domain,
            'site_url' => $domain === '' ? null : SiteAudit::url($domain),
            'measurement_id' => $id,
            'snippet' => $id === null ? null : self::snippet($id),
            // بابُ التقارير عند Google — الأرقام تُقرأ هناك، انظر `check` أدناه
            'analytics_url' => 'https://analytics.google.com/',
        ];
    }

    private static function cacheKey(int $businessId): string
    {
        return 'seo-audit:'.$businessId;
    }

    public static function forget(int $businessId): void
    {
        Cache::forget(self::cacheKey($businessId));
    }

    /**
     * فحصُ الموقع — حالةُ الربط وحالةُ الظهور معًا.
     *
     * والحالةُ تُسمّى باسمها لا تُجمع في «لا شيء»: «بلا نطاق» و«لا يُفتح»
     * و«جاهز» ثلاثةُ مواقفَ لكلٍّ منها ما يُفعل. ولو ردّت جميعًا قائمةً
     * فارغة لَقرأ التاجر «موقعك سليم» وموقعُه لا يفتح أصلًا.
     *
     * @return array{state:string, error:?string, checked_at:?string, site:?array, checks:list<array>}
     */
    public static function check(int $businessId, bool $refresh = false): array
    {
        $config = self::forBusiness($businessId);

        if ($config['domain'] === '') {
            return self::state('nodomain');
        }

        $slot = self::cacheKey($businessId);

        if ($refresh) {
            Cache::forget($slot);
        }

        $cached = Cache::get($slot);

        if (is_array($cached)) {
            return $cached;
        }

        $fetched = SiteAudit::fetch($config['domain']);

        if (! $fetched['ok']) {
            /*
             * والتعذّرُ لا يُحفظ في الذاكرة.
             *
             * موقعٌ أُصلح يعمل في اللحظة، فحفظُ العطل نصفَ ساعةٍ يجعل التاجر
             * يُصلح ثمّ يرى العطلَ نفسه فيظنّ أنّه لم يُصلح.
             */
            return self::state('unreachable', error: $fetched['error']);
        }

        $page = SiteAudit::read($fetched['html']);
        $tagged = $config['measurement_id'] !== null
            && SiteAudit::carriesTag($fetched['html'], $config['measurement_id']);

        $payload = self::state('ok', checkedAt: now()->toIso8601String(), site: [
            'url' => $fetched['url'],
            'status' => $fetched['status'],
            'https' => $fetched['https'],
            'title' => $page['title'],
            'description' => $page['description'],
            'tagged' => $tagged,
        ], checks: self::checks($config, $fetched, $page, $tagged));

        Cache::put($slot, $payload, now()->addMinutes(self::CACHE_MINUTES));

        return $payload;
    }

    /**
     * بنودُ الفحص — لكلٍّ حالتُه وما يُفعل.
     *
     * و«ما يُفعل» ليس زينة: «الوصف ناقص» خبرٌ لا يُصلح شيئًا، و«اكتب وصفًا
     * في ١٢٠–١٦٠ حرفًا يظهر تحت العنوان في نتائج البحث» يُصلح.
     *
     * @return list<array{key:string, label:string, state:string, detail:?string, fix:?string}>
     */
    private static function checks(array $config, array $fetched, array $page, bool $tagged): array
    {
        $title = (string) $page['title'];
        $description = (string) $page['description'];

        $out = [];

        /* ------------------------------ الربط ------------------------------ */

        $out[] = self::item(
            'analytics',
            'وسم Google Analytics في الصفحة',
            $config['measurement_id'] === null ? 'off' : ($tagged ? 'pass' : 'fail'),
            $config['measurement_id'] === null
                ? __('لم يُحفظ معرّف قياس بعد.')
                : ($tagged
                    ? __('الوسم :id موجودٌ في صفحتك الرئيسية.', ['id' => $config['measurement_id']])
                    : __('لم أجد الوسم :id في صفحتك.', ['id' => $config['measurement_id']])),
            $tagged || $config['measurement_id'] === null
                ? null
                : __('انسخ الوسم أعلاه والصقه داخل <head> في كل صفحة، ثم افحص مرّةً أخرى.'),
        );

        /* ------------------------------ الظهور ------------------------------ */

        $out[] = self::item(
            'noindex',
            'السماح بالفهرسة',
            $page['noindex'] ? 'fail' : 'pass',
            $page['noindex']
                ? __('صفحتك تحمل «noindex» — أنت تطلب من Google ألّا تعرضها.')
                : __('لا شيء يمنع Google من فهرسة الصفحة.'),
            $page['noindex'] ? __('احذف وسم robots الذي يحمل noindex من الصفحة — غالبًا بقي من يوم التجربة.') : null,
        );

        $out[] = self::item(
            'https',
            'الاتصال المُعمّى (HTTPS)',
            $fetched['https'] ? 'pass' : 'fail',
            $fetched['https'] ? __('الموقع يفتح على https.') : __('الموقع يفتح على http بلا تعمية.'),
            $fetched['https'] ? null : __('اطلب من مزوّد الاستضافة شهادة SSL — المتصفّحات تُعلّم الموقع «غير آمن».'),
        );

        /*
         * وطولُ العنوان يُقاس لأنّه يُقصّ.
         *
         * Google تقطع ما تجاوز نحوَ ستّين حرفًا في النتيجة، فعنوانٌ طويلٌ
         * يُعرض ناقصًا بثلاث نقاط — واسمُ المحلّ يكون في نصفه المقطوع.
         */
        $out[] = self::item(
            'title',
            'عنوان الصفحة',
            $title === '' ? 'fail' : (mb_strlen($title) > 60 ? 'warn' : 'pass'),
            $title === ''
                ? __('لا عنوان في الصفحة.')
                : __(':title (:n حرفًا)', ['title' => $title, 'n' => mb_strlen($title)]),
            $title === ''
                ? __('أضف <title> يحمل اسم محلّك ومدينته — هو السطر الأزرق في نتيجة البحث.')
                : (mb_strlen($title) > 60 ? __('اختصره إلى نحو ٦٠ حرفًا — Google تقطع ما زاد.') : null),
        );

        $out[] = self::item(
            'description',
            'وصف الصفحة',
            $description === '' ? 'warn' : (mb_strlen($description) > 160 ? 'warn' : 'pass'),
            $description === ''
                ? __('لا وصف في الصفحة.')
                : __(':n حرفًا', ['n' => mb_strlen($description)]),
            $description === ''
                ? __('أضف <meta name="description"> في ١٢٠–١٦٠ حرفًا — هو السطر الرمادي تحت العنوان.')
                : (mb_strlen($description) > 160 ? __('اختصره إلى نحو ١٦٠ حرفًا.') : null),
        );

        $out[] = self::item(
            'viewport',
            'العرض على الهاتف',
            $page['viewport'] ? 'pass' : 'fail',
            $page['viewport'] ? __('الصفحة تُعلن مقاسها للهاتف.') : __('لا وسم viewport — الصفحة تُعرض مصغّرةً على الهاتف.'),
            $page['viewport'] ? null : __('أضف <meta name="viewport" content="width=device-width, initial-scale=1"> — وأكثرُ زبائنك يفتحون من هاتف.'),
        );

        $out[] = self::item(
            'robots',
            'ملفّ robots.txt',
            SiteAudit::serves($config['domain'], 'robots.txt') ? 'pass' : 'warn',
            null,
            __('ملفٌّ يقول لمحرّكات البحث ما تقرأ وما تترك — وغيابه ليس عطبًا، لكنّ وجوده أوضح.'),
        );

        $out[] = self::item(
            'sitemap',
            'خريطة الموقع sitemap.xml',
            SiteAudit::serves($config['domain'], 'sitemap.xml') ? 'pass' : 'warn',
            null,
            __('تُسرّع اكتشاف صفحاتك — اطلبها من من بنى موقعك.'),
        );

        return $out;
    }

    private static function item(string $key, string $label, string $state, ?string $detail, ?string $fix): array
    {
        return [
            'key' => $key,
            'label' => __($label),
            // pass | warn | fail | off
            'state' => $state,
            'detail' => $detail,
            // ما يُصلح لا يُعرض لما هو سليم: نصيحةٌ تحت بندٍ ناجح ضجيج
            'fix' => in_array($state, ['pass', 'off'], true) ? null : $fix,
        ];
    }

    private static function state(
        string $state,
        ?string $error = null,
        ?string $checkedAt = null,
        ?array $site = null,
        array $checks = [],
    ): array {
        return [
            'state' => $state,
            'error' => $error,
            'checked_at' => $checkedAt,
            'site' => $site,
            'checks' => $checks,
        ];
    }
}
