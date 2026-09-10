<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Setting;
use App\Models\Website;
use App\Support\DomainOptions;

/**
 * اللقطةُ المنشورة — من يملك هذا العنوان، وما الذي يُعرض عليه.
 *
 * ومصدرٌ واحد يقرأ منه بابان: `PublishedSiteController` يردّها مستندًا
 * لعارضٍ خارجيّ، و`StorefrontController` يرسمها صفحةً يفتحها الزبون. وكانت
 * القراءةُ في المتحكّم وحده، فلمّا صار للموقع بابٌ ثانٍ في أبعاد نفسها كان
 * الخياران: نداءُ HTTP من الخادم إلى نفسه، أو نسخُ المنطق. وكلاهما يفترق
 * يومًا عن الآخر في حارسٍ أو في حال.
 *
 * وثلاثةٌ تُحرَس هنا، وكلٌّ منها بابٌ لو تُرك:
 *
 * ١) **المنشور وحده يخرج.** المسوّدة عملُ التاجر الذي لم يرضَ عنه بعد؛
 *    خروجُها يُبطل معنى «انشر» كلَّه.
 * ٢) **العنوان يُطابَق بحرفه.** لا يُقرأ موقعٌ بمعرّفه: معرّفٌ متسلسل يجعل
 *    عدّادًا بسيطًا يمرّ على مواقع المتاجر كلّها.
 * ٣) **الصيانة تُقال ولا يُكشف ما وراءها.** الموقع في الصيانة يردّ رسالته
 *    ولا يردّ صفحاته.
 */
final class Published
{
    /** لا عنوانَ بهذا الاسم */
    public const NOT_FOUND = 'not_found';

    /** المتجر موجود ولم ينشر موقعه بعد */
    public const NOT_PUBLISHED = 'not_published';

    public const MAINTENANCE = 'maintenance';

    public const OK = 'ok';

    /**
     * صاحبُ العنوان ولقطتُه — بالنطاق كما يصل في `Host`.
     *
     * @return array{state:string, business_id?:int, ...}
     */
    public static function forHost(string $host): array
    {
        $host = mb_strtolower(trim($host));

        /*
         * النطاق → المتجر، من إعداداته لا من جدول المواقع.
         *
         * `site_domain` هو مصدر النطاق الوحيد في النظام منذ توحيده — يقرؤه
         * زرّ «الموقع» وشاشة الدومين والفاتورة. وعمودٌ ثانٍ في `websites`
         * كان سيفترق عنه عند أوّل تعديل.
         */
        $businessId = Setting::whereNotNull('business_id')
            ->where('key', 'site_domain')
            ->whereRaw('LOWER(value) = ?', [$host])
            ->value('business_id') ?: self::bySubdomain($host);

        return $businessId ? self::forBusiness((int) $businessId) : ['state' => self::NOT_FOUND];
    }

    /**
     * ولقطةُ متجرٍ بعينه — يسألها البابُ الذي عرف صاحبه من اسمه المحجوز.
     *
     * @return array{state:string, business_id?:int, ...}
     */
    public static function forBusiness(int $businessId): array
    {
        $website = Website::where('business_id', $businessId)->with('publishedVersion')->first();

        if (! $website || ! $website->publishedVersion) {
            return ['state' => self::NOT_PUBLISHED];
        }

        if ($website->maintenance) {
            /*
             * وصفحةُ الصيانة تُرسم بهويّة المتجر لا بهويّة العارض.
             *
             * زائرٌ يرى صفحةً بيضاء عليها «الموقع تحت الصيانة» لا يعرف أنّه
             * وصل إلى المكان الصحيح. فالشعارُ واللونُ ووسيلةُ التواصل تُرسل
             * معها — يعرف بها أنّه في متجر من قصده، ويصل إليه إن استعجل.
             */
            return [
                'state' => self::MAINTENANCE,
                'business_id' => $businessId,
                'maintenance' => true,
                'name' => $website->name,
                'message' => $website->maintenance_message ?: 'نعود قريبًا',
                'tokens' => $website->tokens(),
                'brand' => Preview::brand($businessId, ['name' => $website->name]),
                'locale' => 'ar',
                'dir' => 'rtl',
            ];
        }

        $version = $website->publishedVersion;

        return [
            'state' => self::OK,
            'business_id' => $businessId,
            'published_at' => optional($version->published_at)->toIso8601String(),
            'version' => $version->number,
            // اللقطة المجمّدة، ومعها الكتالوج كما هو اليوم — انظر Preview
            'site' => Preview::resolve($version->payload, $businessId),
        ];
    }

    /**
     * ومن لا نطاق له: الاسمُ الذي حجزه.
     *
     * وهو `site_slug` لا مفتاحٌ آخر: هو الذي يُفحص تفرّدُه عند الحفظ، وهو
     * الذي يُعرض للتاجر عنوانًا في لوحته. فمن حجز اسمه وبنى موقعه ونشره ثمّ
     * فتح عنوانه وجد «غير موجود» — ولوحتُه تقول إنّ العنوان له.
     *
     * ولا يُقرأ موقعٌ بمعرّفه: عدّادٌ بسيط يمرّ على مواقع المتاجر كلّها.
     * والاسم المحجوز نصٌّ اختاره صاحبُه — لا يُخمَّن بالعدّ.
     */
    private static function bySubdomain(string $host): ?int
    {
        $suffix = '.'.mb_strtolower(DomainOptions::suffix());

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = mb_substr($host, 0, -mb_strlen($suffix));

        // اسمٌ من جزءٍ واحد لا غير: `a.b.abaadapp.om` ليس نطاقًا فرعيًّا محجوزًا
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return Business::whereRaw('LOWER(site_slug) = ?', [$label])->value('id');
    }

    /* ─────────────────────────── ما يُقرأ بلا JavaScript ─────────────────────────── */

    /**
     * نصُّ الموقع مستخرَجًا — لمن لا JavaScript عنده، ولمن يفهرس.
     *
     * والصفحةُ تُرسم في المتصفّح بطبقة الرسم المشتركة (انظر
     * `resources/js/site.tsx`)، فمن أطفأ JavaScript أو زحف قبل أن يُنفَّذ
     * يرى صندوقًا فارغًا. وصفحةٌ فارغة تُقرأ «لا شيء هنا».
     *
     * ═══ والاستخراجُ من `Sections::CATALOGUE` لا من أسماءٍ تُكتب هنا ═══
     *
     * قائمةُ حقولٍ مكتوبةٌ في هذا الملفّ («title»، «subtitle»، «about»…)
     * تفترق عن الكتالوج عند أوّل قسمٍ يُضاف: يُبنى القسم ويُنشر ويظهر في
     * المتصفّح، ولا يظهر حرفٌ منه لمن لا JavaScript عنده — بلا خطأٍ يُرفع.
     *
     * فتُقرأ الأنواع من مصدرها: ما أعلن الكتالوجُ أنّه `text` أو `textarea`
     * هو نصٌّ يُقرأ. وقسمٌ جديد يُدرَج بلا سطرٍ هنا.
     *
     * @param  array<string,mixed>  $site  اللقطة كما يردّها `Preview::resolve`
     * @return list<string>
     */
    public static function outline(array $site): array
    {
        $out = [];

        foreach (self::pagesOf($site) as $page) {
            foreach ((array) ($page['sections'] ?? []) as $section) {
                foreach (self::textOf($section) as $line) {
                    $out[] = $line;
                }
            }
        }

        // والأقسامُ العامّة خارج الصفحات — وفي التذييل نبذةُ المتجر
        foreach ((array) ($site['globals'] ?? []) as $slot) {
            if (is_array($slot)) {
                foreach (self::textOf($slot) as $line) {
                    $out[] = $line;
                }
            }
        }

        return array_values(array_unique(array_filter($out, fn ($s) => $s !== '')));
    }

    /** صفحاتُ اللقطة — والرئيسيةُ أوّلًا كما رتّبها صاحبُها */
    private static function pagesOf(array $site): array
    {
        $pages = $site['pages'] ?? null;

        return is_array($pages) ? $pages : [];
    }

    /**
     * ما يُكتب في `<head>` — عنوانٌ ووصفٌ وصورةُ مشاركة.
     *
     * وهذا وحدَه يُرسم في الخادم مهما كان الجسد: رابطٌ يُشارَك في واتساب أو
     * إنستغرام لا يُنفّذ JavaScript قبل أن يرسم بطاقتَه، فما لا يكن في
     * `<head>` لا يظهر في البطاقة — ويُشارَك رابطُ متجرٍ بلا اسمٍ ولا صورة.
     *
     * @return array{title:string, description:string, image:?string}
     */
    public static function head(array $site): array
    {
        $seo = is_array($site['seo'] ?? null) ? $site['seo'] : [];
        $name = trim((string) ($site['name'] ?? ''));
        $image = trim((string) ($seo['image'] ?? ''));

        return [
            'title' => trim((string) ($seo['title'] ?? '')) ?: ($name ?: __('متجر')),
            'description' => trim((string) ($seo['description'] ?? '')),
            // الفراغ فراغٌ لا رابطٌ إلى جذر الموقع — انظر Preview::absoluteSeo
            'image' => $image !== '' ? $image : null,
        ];
    }

    /**
     * نصوصُ قسمٍ واحد — بأنواع حقوله كما أعلنها الكتالوج.
     *
     * @return list<string>
     */
    private static function textOf(array $section): array
    {
        $type = (string) ($section['type'] ?? '');
        $fields = Sections::CATALOGUE[$type]['fields'] ?? null;

        if (! is_array($fields)) {
            return [];
        }

        $data = (array) ($section['data'] ?? []);
        $out = [];

        foreach ($fields as $key => $spec) {
            if (! in_array($spec['type'] ?? '', ['text', 'textarea'], true)) {
                continue;
            }

            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }
}
