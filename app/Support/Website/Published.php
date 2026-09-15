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
     * أَلِهذا النشاط موقعٌ مبنيٌّ منشور؟ — سؤالٌ يُسأل بلا بناء اللقطة.
     *
     * `forBusiness` تبني المستند كلَّه: تحلّ المنتجات والتصنيفات والآراء على
     * اللقطة المجمّدة. ومن أراد أن يعرف **أيّ** صفحةٍ تُخدم — شاشةُ إعدادات
     * مثلًا — لا يحتاج شيئًا من ذلك؛ فسؤالُه بها يجعل فتحَ صفحةِ إعداداتٍ
     * يقرأ كتالوجَ المتجر كلَّه.
     *
     * والمؤشّرُ وحده يكفي جوابًا: مفتاحُه `nullOnDelete`، فنسخةٌ تذهب تمحو
     * مؤشّرَها معها ولا يبقى موقعٌ يشير إلى نسخةٍ لا وجودَ لها. ولو تُرك
     * المفتاحُ بلا ذلك يومًا لَافترق الجوابان — وذاك ما يحرسه اختبارُ
     * «مؤشّرٌ إلى نسخةٍ ذهبت».
     */
    public static function hasPublished(int $businessId): bool
    {
        return Website::where('business_id', $businessId)->whereNotNull('published_version_id')->exists();
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
    public static function outline(array $site, ?array $only = null): array
    {
        $out = [];

        /*
         * ═══ ونصُّ الصفحة المطلوبة وحدَها ═══
         *
         * كانت تمشي الصفحات كلَّها وتصبّ نصَّها في جسد **العنوان الواحد**
         * الذي كان يُخدَم — فيقرأ الزاحف «من نحن» و«تواصل معنا» في صفحة
         * المتجر الرئيسية، ويقرأ الموقعَ كلَّه صفحةً واحدة. ولمّا صار لكلّ
         * صفحةٍ عنوانُها صار لكلٍّ نصُّها.
         *
         * و`null` تبقى تمشي الجميع: من ينادي الدالّة ليسأل «أفي اللقطة نصٌّ
         * أصلًا؟» يسأل عن الموقع لا عن صفحة.
         */
        $pages = $only !== null ? [$only] : self::pagesOf($site);

        foreach ($pages as $page) {
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
    /**
     * روابطُ المستند الداخليّة مبنيّةً على قاعدة هذا الطلب.
     *
     * ═══ ولمَ يلزم أصلًا ═══
     *
     * روابطُ القائمة مكتوبةٌ من الجذر: «/» و«/shop» و«/about» — يكتبها
     * النظام نفسُه من صفحات الموقع (`Website\Nav`). وهي صحيحةٌ على النطاق
     * الفرعيّ وعلى نطاق التاجر، لأنّ جذر المضيف هناك جذرُ المتجر.
     *
     * أمّا على المسار البديل (`app.abaadapp.om/s/متجري`) فالجذرُ جذرُ أبعاد:
     * «/shop» تخرج إلى `app.abaadapp.om/shop` — ٤٠٤ — و«الرئيسية» تُخرج
     * الزبونَ إلى **صفحة دخول أبعاد**. فكلُّ رابطٍ في قائمة كلِّ موقعٍ منشور
     * كان يخرج من المتجر.
     *
     * ═══ ولمَ هنا لا في طبقة الرسم ═══
     *
     * طبقةُ الرسم مصدرُها `storefront/src/site` وتُنسخ إلى هنا بأمرٍ واحد
     * (انظر `RendererParityTest`): تعديلُها في هذا المستودع يُمحى عند أوّل
     * مزامنة، فيعود العطبُ إلى الإنتاج صامتًا. فتُحلّ الروابطُ قبل أن تصلها.
     *
     * ═══ والمشيُ عامٌّ لا قائمةُ حقولٍ تُكتب ═══
     *
     * الروابطُ في مواضع كثيرة: قائمةُ الترويسة، وقائمةُ التذييل، ووجهةُ كلّ
     * زرٍّ في كلّ قسم (`cta_href`). وقائمةٌ تُكتب باليد تنسى التاليَ دائمًا —
     * فيُمشى المستندُ كلُّه ويُحلّ كلُّ مفتاحٍ اسمُه `href` أو ينتهي بـ`_href`.
     *
     * ولا يُمسّ إلّا المسارُ الداخليّ من الجذر: العناوينُ الكاملة و`//` و`#`
     * و`mailto:` و`tel:` تخرج كما كُتبت، والنسبيُّ بلا شرطةٍ أولى كذلك.
     *
     * @param  array<string,mixed>  $site
     * @return array<string,mixed>
     */
    public static function rebase(array $site, string $base): array
    {
        if ($base === '') {
            return $site;
        }

        $walk = function ($node) use (&$walk, $base) {
            if (! is_array($node)) {
                return $node;
            }

            foreach ($node as $key => $value) {
                if (is_string($value) && is_string($key)
                    && ($key === 'href' || str_ends_with($key, '_href'))) {
                    $node[$key] = self::rebased($value, $base);

                    continue;
                }

                $node[$key] = $walk($value);
            }

            return $node;
        };

        return $walk($site);
    }

    /** رابطٌ واحد مبنيًّا على القاعدة — والخارجيُّ يبقى كما هو */
    private static function rebased(string $href, string $base): string
    {
        if ($href === '' || ! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return $href;
        }

        // و«/» وحدها تصير القاعدةَ نفسها لا القاعدةَ وشرطةً معلّقة
        return $href === '/' ? $base : $base.$href;
    }

    /**
     * الصفحةُ التي يطلبها هذا المسار — أو `null` إن لم تكن.
     *
     * و`null` في `$path` تعني الرئيسية: هي ما يُخدَم على جذر الموقع.
     *
     * والمسوّدةُ لا تُخدَم — و**لا يُعاد فحصُها هنا**: `visible` تُسقط صفحات
     * المسوّدة من المستند قبل أن يصل، وهي الموضعُ الواحد الذي يقرّر ما يراه
     * زائر. وفحصٌ ثانٍ يقول الشيء نفسه يفترق عنه يوم يُبدَّل أحدهما — وكان
     * مكتوبًا هنا فنجَت منه طفرةٌ تُلغيه: لأنّه لا يحرس شيئًا.
     *
     * @param  array<string,mixed>  $site
     * @return array<string,mixed>|null
     */
    public static function pageAt(array $site, ?string $path): ?array
    {
        $pages = array_values(self::pagesOf($site));

        if ($path === null || $path === '' || $path === '/') {
            return collect($pages)->first(fn ($p) => ($p['is_home'] ?? false) === true)
                ?? ($pages[0] ?? null);
        }

        $wanted = '/'.trim($path, '/');

        return collect($pages)->first(
            fn ($p) => '/'.trim((string) ($p['slug'] ?? ''), '/') === $wanted
        );
    }

    /**
     * عنوانُ هذه الصفحة الأصليّ — جذرُ الموقع وعليه مسارُها.
     *
     * والرئيسيةُ تبقى على الجذر عاريًا: `https://…/s/متجري/` و`…/s/متجري`
     * عنوانان لصفحةٍ واحدة، وإشارةُ `canonical` إلى أحدهما تجمع ما تفرّق.
     */
    public static function canonicalFor(?string $root, ?array $page): ?string
    {
        if ($root === null) {
            return null;
        }

        $slug = '/'.trim((string) ($page['slug'] ?? '/'), '/');

        return $slug === '/' ? $root : rtrim($root, '/').$slug;
    }

    public static function head(array $site, ?array $page = null): array
    {
        $seo = is_array($site['seo'] ?? null) ? $site['seo'] : [];

        /*
         * وسيوُ الصفحة يعلو سيوَ الموقع — حقلًا حقلًا لا كتلةً.
         *
         * صفحةٌ كُتب لها عنوانٌ ولم يُكتب لها وصف تأخذ عنوانَها ووصفَ الموقع:
         * ودمجُ الكتلتين كتلةً واحدة كان سيُفرّغ الوصف لأنّ الصفحة لم تذكره.
         * والفارغُ لا يعلو المكتوب: حقلٌ تُرك فارغًا ليس اختيارًا لفراغه.
         */
        $pageSeo = is_array($page['seo'] ?? null) ? $page['seo'] : [];

        foreach (['title', 'description', 'image'] as $field) {
            if (trim((string) ($pageSeo[$field] ?? '')) !== '') {
                $seo[$field] = $pageSeo[$field];
            }
        }

        if (array_key_exists('index', $pageSeo)) {
            $seo['index'] = $pageSeo['index'];
        }

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
