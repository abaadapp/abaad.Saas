<?php

namespace App\Support;

use App\Models\Business;
use App\Support\Website\Domains;
use App\Support\Website\Published;
use Illuminate\Support\Facades\Cache;

/**
 * الظهور في البحث وربطُ Google Analytics.
 *
 * ═══ وموقعان لا موقعٌ واحد ═══
 *
 * بُنيت هذه الشاشة يوم كان موقعُ التاجر **خارج النظام** دائمًا: لا نستضيفه
 * ولا نبني صفحاته، فلا نضع فيه وسمًا. وكلُّ ما تفعله حينها شيئان: تُعطيه
 * **ما يلصقه**، ثمّ **تفتح موقعه وتقول ما رأت**.
 *
 * ثمّ صار لأبعاد بانِي مواقع، وصارت الصفحةُ التي يفتحها الزبون **صفحتَنا**
 * (`site/show.blade.php`). فبقيت الشاشة على حالها الأول:
 *
 *  ١) تاجرٌ بنى موقعه ونشره على أبعاد يُقال له «لم تُضف نطاق موقعك بعد» —
 *     وموقعُه مفتوحٌ يعمل، ويُقرأ في Google.
 *  ٢) ومن ربط نطاقه الخاصّ بأبعاد يُقال له «انسخ الوسم والصقه داخل
 *     `<head>`» — و`<head>` نكتبه نحن، لا يملك إليه بابًا.
 *
 * فكان المعرّفُ يُحفظ ولا يخرج في صفحةٍ واحدة قطّ: التاجر يلصقه ويرى
 * «حُفظ»، وينتظر أرقامًا لا تأتي أبدًا. وهو الفخُّ الذي بُنيت الشاشةُ
 * لتجنّبه — وقعت فيه حين تبدّل ما تحتها ولم تتبدّل.
 *
 * والقسمةُ اليوم واحدة: **إن كنّا نخدم الصفحة وضعنا الوسم فيها بأنفسنا**،
 * وإن كانت عند غيرنا أعطيناه ما يلصق. و`hostedUrl` هي التي تفصل.
 *
 * ولا حقلَ هنا لعنوان الصفحة ولا وصفها: عنوانُ الموقع المبنيّ يسكن
 * `websites.seo` وله شاشتُه (`admin.website.seo`)، وحقلٌ ثانٍ له هنا حقلان
 * يقولان الشيء نفسه — يفترقان يومًا.
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

    /*
     * ═══ حدودُ ما يُعرض في نتيجة البحث ═══
     *
     * تُقرأ في الفحص وفي نصّ النصيحة معًا — رقمٌ في الشرط وآخرُ في الجملة
     * يفترقان يومَ يُبدَّل أحدهما، فتقول الشاشة «✓» وتحتها «اكتبه في ١٢٠».
     *
     * والحدُّ الأدنى ليس تزيّدًا: الباني يكتب للمتجر وصفًا افتراضيًّا
     * («تسوّق من متجري.» — خمسةَ عشرَ حرفًا)، وكان الفحصُ يُجيزه بعلامةٍ
     * خضراء. فيقرأ التاجر «وصف الصفحة ✓» ولا يكتب وصفًا قطّ — وGoogle
     * تطرح الوصفَ القصير وتختار جملةً من صفحته هي.
     *
     * فنظامٌ يكتب حشوًا ثمّ يشهد له بالسلامة أسوأ من نظامٍ لا يكتب شيئًا.
     */

    /** أقلُّ عنوانٍ يُفيد — اسمُ المحلّ وحده لا يُبحث عنه */
    public const TITLE_MIN = 15;

    /** وما زاد تقطعه Google بثلاث نقاط */
    public const TITLE_MAX = 60;

    /** أقلُّ وصفٍ تعرضه Google كما كُتب — وما دونه تستبدله غالبًا */
    public const DESC_MIN = 70;

    public const DESC_MAX = 160;

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

    /**
     * الوسمُ الذي يخرج في صفحة المتجر — أو null.
     *
     * ويُقرأ في القالب لا يُحقن في المستند المنشور: اللقطة تُجمَّد يوم النشر،
     * ومعرّفٌ يُلصق بعدها لا يصل صفحةً حتى يُعاد النشر — فيُطالَب التاجر
     * بنشرةٍ لا شأنَ لها بما بدّله.
     */
    public static function tagFor(int $businessId): ?string
    {
        $id = self::measurementId(MarketingSettings::group($businessId, 'seo')['ga_measurement_id'] ?? null);

        return $id === null ? null : self::snippet($id);
    }

    /**
     * عنوانُ موقع التاجر حين **نخدمه نحن** — أو null حين يكون عند غيرنا.
     *
     * وهو الفاصلُ الذي تُبنى عليه الشاشةُ كلُّها: من كان موقعُه عندنا وضعنا
     * وسمَه بأنفسنا ولم نطلب منه لصقَ شيء، وقِسنا صفحته على عنوانها الذي
     * يُفتح — لا على نطاقٍ لم يكتبه لأنّه لا يحتاجه.
     *
     * والشرطان اللذان يقرؤهما الزائر نفسُه: أن يُخدَم المتجر (نشطًا غيرَ
     * مقفَل)، وأن يكون صاحبُه قد **نشر** — بانيًا أو صفحةَ متجرٍ بسيطة.
     * فموقعٌ في المسوّدة لا عنوان له يُفحص، وقولُ «سليم» عنه طمأنينةٌ كاذبة.
     */
    public static function hostedUrl(int $businessId): ?string
    {
        $business = Business::find($businessId);

        if ($business === null || ! Storefront::serving($business)) {
            return null;
        }

        $built = Published::forBusiness($businessId)['state'] !== Published::NOT_PUBLISHED;

        if (! $built && ! Storefront::published($business)) {
            return null;
        }

        // والعنوانُ الذي يُخدَم لا الذي يُتمنّى — نطاقُه إن فُعِّل، وإلّا عنوانُنا
        return Domains::canonical($businessId, $business->site_slug);
    }

    /** إعدادات المتجر: النطاق والمعرّف وما يُبنى منهما */
    public static function forBusiness(int $businessId): array
    {
        $site = MarketingSettings::group($businessId, 'website');
        // موقعٌ مُطفأ من الإعدادات لا يُفحص: النطاق محفوظٌ ولا يُقصد به شيء
        $enabled = ($site['site_on'] ?? '1') === '1';
        $hosted = self::hostedUrl($businessId);
        $domain = $enabled ? trim((string) ($site['site_domain'] ?? '')) : '';
        $id = self::measurementId(MarketingSettings::group($businessId, 'seo')['ga_measurement_id'] ?? null);

        return [
            /*
             * و«مُطفأ» لا تُطفئ موقعًا نخدمه.
             *
             * `site_on` مفتاحُ الموقع الخارجيّ: زرُّه في الشريط وفحصُه هنا.
             * أمّا صفحةٌ ننشرها نحن فإطفاؤها «صيانة» في البانِي أو سحبُ
             * النشر — لا مفتاحٌ في شاشةٍ أخرى. ولو أطفأه هذا المفتاح لَقالت
             * الشاشة «موقعك مُطفأ» عن موقعٍ يفتحه الزبون الآن.
             */
            'enabled' => $enabled || $hosted !== null,
            'hosted' => $hosted !== null,
            /*
             * وعنوانٌ واحد يُفتح ويُفحص — لا حقلُ نطاقٍ بجانبه.
             *
             * كان هنا `domain` أيضًا يحمل ما يُفحص، و`site_url` ما يُفتح.
             * وصارا يقولان الشيء نفسه يوم صار المفحوصُ عنوانَنا، إلّا أنّ
             * أحدهما اسمُ مضيفٍ والآخر عنوانٌ كامل — وحقلان يقولان الشيء
             * نفسه بصيغتين يفترقان يوم يُبدَّل أحدهما.
             *
             * و`SiteAudit::url` تقبل الاثنين: تُكمل البادئة لما نقصته وتترك
             * ما اكتمل. فلا حاجة إلى الصيغة الناقصة.
             */
            'site_url' => $hosted ?? ($domain === '' ? null : SiteAudit::url($domain)),
            'measurement_id' => $id,
            /*
             * ولا يُعطى ما لا يُلصق.
             *
             * صفحتُه عندنا، و`<head>` نكتبه نحن. فبطاقةُ «الوسم الذي تلصقه
             * في موقعك» فوق صفحةٍ لا يملك إليها بابًا تجعله يبحث عن مكانٍ
             * لا وجود له، ثمّ يظنّ العطبَ في نفسه.
             */
            'snippet' => ($id === null || $hosted !== null) ? null : self::snippet($id),
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

        /*
         * و«مُطفأ» ليست «بلا نطاق».
         *
         * الأولى قرارٌ اتّخذه التاجر ويُلغيه بمفتاح، والثانية نقصٌ يُكمله
         * بكتابة نطاقه. وجمعُهما في رسالةٍ واحدة يجعل من أطفأ موقعه يبحث
         * عن نطاقٍ كتبه بالفعل.
         */
        if (! $config['enabled']) {
            return self::state('off');
        }

        /*
         * و«بلا نطاق» لا تُقال لمن موقعُه عندنا.
         *
         * كانت تُقال له: `site_domain` حقلُ الموقع الخارجيّ، ومن بنى موقعه
         * على أبعاد لا يكتبه لأنّه لا يحتاجه — فيُقفل بابُ الشاشة كلِّه
         * خلف «أضف نطاق موقعك»، وموقعُه مفتوحٌ يعمل ويُقرأ في Google.
         *
         * والشرطُ واحدٌ لا اثنان: `domain` تحمل العنوانَ الذي يُفحص أيًّا كان
         * مصدرُه — عنوانَنا لمن نخدمه، ونطاقَه لمن هو عند غيرنا (انظر
         * `forBusiness`). وشرطُ `hosted` بجانبه كان يقول الشيءَ نفسه مرّتين،
         * ونجَت منه طفرةٌ رفعتْه — لأنّه لا يحرس شيئًا.
         */
        if ($config['site_url'] === null) {
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

        $fetched = SiteAudit::fetch($config['site_url']);

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

        $hosted = (bool) ($config['hosted'] ?? false);

        $out[] = self::item(
            'analytics',
            'وسم Google Analytics في الصفحة',
            $config['measurement_id'] === null ? 'off' : ($tagged ? 'pass' : 'fail'),
            $config['measurement_id'] === null
                ? __('لم يُحفظ معرّف قياس بعد.')
                : ($tagged
                    ? ($hosted
                        ? __('الوسم :id يُركّب في صفحتك تلقائيًّا — ورأيتُه فيها.', ['id' => $config['measurement_id']])
                        : __('الوسم :id موجودٌ في صفحتك الرئيسية.', ['id' => $config['measurement_id']]))
                    : __('لم أجد الوسم :id في صفحتك.', ['id' => $config['measurement_id']])),
            $tagged || $config['measurement_id'] === null
                ? null
                /*
                 * ولا يُقال لمن لا يملك `<head>` أن يلصق فيه.
                 *
                 * صفحتُه عندنا: الوسمُ يخرج فيها لحظةَ الحفظ، فإن لم يُرَ
                 * فالفحصُ محفوظٌ من قبله لا الوسمُ ناقص.
                 */
                : ($hosted
                    ? __('الوسم يخرج في صفحتك من تلقائه — اضغط «افحص الآن» ليُقرأ من جديد.')
                    : __('انسخ الوسم أعلاه والصقه داخل <head> في كل صفحة، ثم افحص مرّةً أخرى.')),
        );

        /* ------------------------------ الظهور ------------------------------ */

        $out[] = self::item(
            'noindex',
            'السماح بالفهرسة',
            $page['noindex'] ? 'fail' : 'pass',
            $page['noindex']
                ? __('صفحتك تحمل «noindex» — أنت تطلب من Google ألّا تعرضها.')
                : __('لا شيء يمنع Google من فهرسة الصفحة.'),
            $page['noindex']
                ? ($hosted
                    ? __('افتح «الموقع الإلكتروني ‹ الظهور في البحث» وارفع «اسمح لمحرّكات البحث»، ثمّ انشر.')
                    : __('احذف وسم robots الذي يحمل noindex من الصفحة — غالبًا بقي من يوم التجربة.'))
                : null,
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
        $titleLength = mb_strlen($title);

        $out[] = self::item(
            'title',
            'عنوان الصفحة',
            match (true) {
                $title === '' => 'fail',
                $titleLength > self::TITLE_MAX, $titleLength < self::TITLE_MIN => 'warn',
                default => 'pass',
            },
            match (true) {
                $title === '' => __('لا عنوان في الصفحة.'),
                $titleLength < self::TITLE_MIN => __(':title (:n حرفًا) — قصيرٌ، ولا يُبحث عنه.', [
                    'title' => $title, 'n' => $titleLength,
                ]),
                default => __(':title (:n حرفًا)', ['title' => $title, 'n' => $titleLength]),
            },
            self::fixText(
                $hosted,
                match (true) {
                    $title === '' => __('اكتبه في «الموقع الإلكتروني ‹ الظهور في البحث» — هو السطر الأزرق في نتيجة البحث.'),
                    $titleLength > self::TITLE_MAX => __('اختصره من «الموقع الإلكتروني ‹ الظهور في البحث» إلى نحو :max حرفًا — Google تقطع ما زاد.', ['max' => self::TITLE_MAX]),
                    $titleLength < self::TITLE_MIN => __('وسّعه من «الموقع الإلكتروني ‹ الظهور في البحث»: اسمُ المحلّ وحده لا يُبحث عنه — أضِف ما تبيع ومدينتك، :min حرفًا فأكثر.', ['min' => self::TITLE_MIN]),
                    default => null,
                },
                match (true) {
                    $title === '' => __('أضف <title> يحمل اسم محلّك ومدينته — هو السطر الأزرق في نتيجة البحث.'),
                    $titleLength > self::TITLE_MAX => __('اختصره إلى نحو :max حرفًا — Google تقطع ما زاد.', ['max' => self::TITLE_MAX]),
                    $titleLength < self::TITLE_MIN => __('وسّعه: اسمُ المحلّ وحده لا يُبحث عنه — أضِف ما تبيع ومدينتك، :min حرفًا فأكثر.', ['min' => self::TITLE_MIN]),
                    default => null,
                },
            ),
        );

        $descriptionLength = mb_strlen($description);

        $out[] = self::item(
            'description',
            'وصف الصفحة',
            match (true) {
                $description === '' => 'warn',
                $descriptionLength > self::DESC_MAX, $descriptionLength < self::DESC_MIN => 'warn',
                default => 'pass',
            },
            match (true) {
                $description === '' => __('لا وصف في الصفحة.'),
                $descriptionLength < self::DESC_MIN => __(':n حرفًا — قصيرٌ، وGoogle تستبدله غالبًا بجملةٍ تختارها هي.', [
                    'n' => $descriptionLength,
                ]),
                default => __(':n حرفًا', ['n' => $descriptionLength]),
            },
            self::fixText(
                $hosted,
                match (true) {
                    $description === '' => __('اكتبه في «الموقع الإلكتروني ‹ الظهور في البحث» في :min–:max حرفًا — هو السطر الرمادي تحت العنوان.', ['min' => self::DESC_MIN, 'max' => self::DESC_MAX]),
                    $descriptionLength > self::DESC_MAX => __('اختصره من «الموقع الإلكتروني ‹ الظهور في البحث» إلى نحو :max حرفًا.', ['max' => self::DESC_MAX]),
                    $descriptionLength < self::DESC_MIN => __('وسّعه من «الموقع الإلكتروني ‹ الظهور في البحث» إلى :min–:max حرفًا تقول ما تبيع ولمن — الوصفُ الحاليّ حشوٌ كتبه النظام لا أنت.', ['min' => self::DESC_MIN, 'max' => self::DESC_MAX]),
                    default => null,
                },
                match (true) {
                    $description === '' => __('أضف <meta name="description"> في :min–:max حرفًا — هو السطر الرمادي تحت العنوان.', ['min' => self::DESC_MIN, 'max' => self::DESC_MAX]),
                    $descriptionLength > self::DESC_MAX => __('اختصره إلى نحو :max حرفًا.', ['max' => self::DESC_MAX]),
                    $descriptionLength < self::DESC_MIN => __('وسّعه إلى :min–:max حرفًا تقول ما تبيع ولمن.', ['min' => self::DESC_MIN, 'max' => self::DESC_MAX]),
                    default => null,
                },
            ),
        );

        $out[] = self::item(
            'viewport',
            'العرض على الهاتف',
            $page['viewport'] ? 'pass' : 'fail',
            $page['viewport'] ? __('الصفحة تُعلن مقاسها للهاتف.') : __('لا وسم viewport — الصفحة تُعرض مصغّرةً على الهاتف.'),
            $page['viewport'] ? null : __('أضف <meta name="viewport" content="width=device-width, initial-scale=1"> — وأكثرُ زبائنك يفتحون من هاتف.'),
        );

        /*
         * ═══ وملفّا الجذر لا يُسألان عن موقعٍ نخدمه ═══
         *
         * `robots.txt` و`sitemap.xml` يسكنان **جذر المضيف**، وعنوانُ متجرٍ
         * على مسارٍ (`/s/متجري`) ليس جذرًا — فسؤالُهما يقيس جذرَ أبعاد لا
         * موقعَ التاجر، ويُعلن عنه حكمًا ليس له.
         *
         * وأبعدُ من ذلك: ما ينقص منهما ينقص **منّا** لا منه. و«اطلبها ممّن
         * بنى موقعك» تقولها الشاشة لمن بنى موقعه عندنا — فيبحث عن جهةٍ
         * يسألها ولا يجدها. ونصيحةٌ لا يملك قارئُها تنفيذها ضجيجٌ يُنقص
         * الثقة بما فوقها.
         */
        if ($hosted) {
            return $out;
        }

        $out[] = self::item(
            'robots',
            'ملفّ robots.txt',
            SiteAudit::serves($config['site_url'], 'robots.txt') ? 'pass' : 'warn',
            null,
            __('ملفٌّ يقول لمحرّكات البحث ما تقرأ وما تترك — وغيابه ليس عطبًا، لكنّ وجوده أوضح.'),
        );

        $out[] = self::item(
            'sitemap',
            'خريطة الموقع sitemap.xml',
            SiteAudit::serves($config['site_url'], 'sitemap.xml') ? 'pass' : 'warn',
            null,
            __('تُسرّع اكتشاف صفحاتك — اطلبها من من بنى موقعك.'),
        );

        return $out;
    }

    /** ما يُصلح البند — نصٌّ لمن يملك صفحته عندنا، وآخرُ لمن يملكها عند غيرنا */
    private static function fixText(bool $hosted, ?string $ours, ?string $theirs): ?string
    {
        return $hosted ? $ours : $theirs;
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
