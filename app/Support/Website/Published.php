<?php

namespace App\Support\Website;

use App\Models\Website;

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
        /*
         * والعنوانُ يُحلّ من جدول العناوين وحده.
         *
         * كان يُقرأ من موضعين: صفٌّ في `settings` للنطاق الخاصّ، وعمودٌ في
         * `businesses` للاسم المحجوز. ولا تفرّدَ في أيٍّ منهما ولا حالَ ربط
         * — فعنوانٌ لم يُوجَّه بعد كان يُخدَم كعنوانٍ عامل، ونطاقٌ كتبه
         * اثنان يُنتقى أحدُهما بترتيبٍ لا يضمنه محرّكٌ لأحد.
         *
         * و`website_domains` تحمل الاثنين صفوفًا، وتفرّدُها في الفهرس.
         * ولا يُخدَم إلّا النشط: من ادّعى نطاقًا لا يملكه لا يُخدَم عليه
         * موقعُه قبل أن يثبت توجيهُه.
         */
        $businessId = Domains::resolve($host);

        return $businessId ? self::forBusiness($businessId) : ['state' => self::NOT_FOUND];
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
            'site' => self::visible(Preview::resolve($version->payload, $businessId)),
        ];
    }

    /**
     * المستندُ العلنيّ — ما يجوز أن يراه زائر، لا أكثر.
     *
     * ═══ ولا يكفي أن يُسقطه الرسم ═══
     *
     * طبقةُ الرسم تُسقط المخفيَّ في وضع الموقع، فلا يظهر على الشاشة. لكنّ
     * المستند كلَّه يخرج مع الصفحة — لقطةً في وسم `application/json` يقرؤها
     * المتصفّح — فيبقى نصُّ ما أخفاه التاجر في مصدر صفحته لمن يفتحه.
     * والتاجر الذي أخفى «آراء العملاء» أو ترك صفحةً مسوّدةً فيها أسعارُ
     * موسمٍ قادم يظنّ أنّه أخفاها.
     *
     * فما لا يُرسم لا يخرج أصلًا. والنشرةُ في `website_versions` تبقى كاملةً
     * — منها تُستعاد المسوّدة بكلّ ما فيها؛ وهذا التنقيةُ عند الخروج لا عند
     * التجميد.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function visible(array $doc): array
    {
        $doc['globals'] = array_values(array_filter(
            (array) ($doc['globals'] ?? []),
            fn ($slot) => is_array($slot) && ($slot['visible'] ?? true) !== false,
        ));

        $doc['pages'] = array_values(array_map(static function ($page) {
            $page['sections'] = array_values(array_filter(
                (array) ($page['sections'] ?? []),
                fn ($section) => is_array($section) && ($section['visible'] ?? true) !== false,
            ));

            return $page;
        }, array_filter(
            (array) ($doc['pages'] ?? []),
            fn ($page) => is_array($page) && (string) ($page['status'] ?? 'published') === 'published',
        )));

        return $doc;
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
            /*
             * ═══ والمسوّدةُ لا تخرج من هنا ═══
             *
             * كانت الصفحات تُمشى كلُّها والأقسام كلُّها: فصفحةٌ حالُها
             * «مسوّدة» — كتبها التاجر ولم يرضَ عنها — يخرج نصُّها في جسد
             * الصفحة الحيّة، وقسمٌ **أخفاه** يخرج معه. وطبقةُ الرسم تُسقط
             * الاثنين في وضع الموقع، فلا يراهما من عنده JavaScript ويراهما
             * من يزحف. وهو أسوأ التقسيمين: المخفيُّ يُفهرس ولا يُرى.
             */
            if ((string) ($page['status'] ?? 'published') !== 'published') {
                continue;
            }

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
     * ═══ و«السماح لمحرّكات البحث» يخرج من هنا ═══
     *
     * `seo.index` تُحفظ في شاشة الظهور منذ بُنيت **ولا يقرؤها شيء**: التاجر
     * يُطفئها ويرى «حُفظ»، وموقعُه يبقى مفهرسًا. ومقبضٌ لا يُدير شيئًا أسوأ
     * من غياب المقبض — لأنّ صاحبه يظنّ أنّه فعل.
     *
     * @return array{title:string, description:string, image:?string, robots:string}
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
            /*
             * والغيابُ إذنٌ بالفهرسة: موقعٌ نُشر قبل وجود المفتاح لا يُخفى
             * من غوغل فجأةً لأنّ مفتاحًا أُضيف بعده.
             */
            'robots' => ($seo['index'] ?? true) ? 'index, follow' : 'noindex, nofollow',
        ];
    }

    /**
     * نصوصُ قسمٍ واحد — بأنواع حقوله كما أعلنها الكتالوج.
     *
     * @return list<string>
     */
    private static function textOf(array $section): array
    {
        // والمخفيُّ مخفيٌّ في القراءتين — انظر `outline`
        if (($section['visible'] ?? true) === false) {
            return [];
        }

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
