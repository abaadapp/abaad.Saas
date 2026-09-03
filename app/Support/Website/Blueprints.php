<?php

namespace App\Support\Website;

/**
 * ما يُبنى للتاجر قبل أن يلمس شيئًا.
 *
 * أسوأ ما يمكن أن يُقدَّم لمن لا يعرف تصميم المواقع صفحةٌ بيضاء وزرُّ «أضف
 * قسمًا». هو لا يعرف ما الأقسام، ولا أيّها يأتي أوّلًا، ولا كم يكفي — فيضيف
 * ثلاثةً بلا ترتيب ويترك الموقع.
 *
 * فالجواب عن سؤالٍ واحد — «ماذا تريد من موقعك؟» — يبني موقعًا كاملًا: صفحاتٍ
 * بأسمائها وروابطها، وفي كلٍّ منها أقسامٌ مرتّبةً كما تُرتَّب في المواقع التي
 * تعمل. ثمّ يعدّل التاجر ما يريد على شيءٍ قائم.
 *
 * والوجهة تحدّد ما يُعرض وما لا يُعرض بعدها: من اختار «تعريفيّ» لا يرى سلّةً
 * ولا «الأكثر مبيعًا» ولا إعداداتِ الطلب. هذا هو Progressive Disclosure —
 * لا إخفاءً في الشاشة، بل غيابًا من البنية.
 */
class Blueprints
{
    /** بيع المنتجات — سلّةٌ وطلب */
    public const STORE = 'store';

    /** عرض المنتجات بلا طلب — كتالوجٌ يُطلب منه عبر واتساب */
    public const CATALOG = 'catalog';

    /** موقع تعريفيّ — من نحن وأين وكيف نُوصَل */
    public const PROFILE = 'profile';

    public const GOALS = [
        self::STORE => [
            'label' => 'بيع المنتجات',
            'hint' => 'متجرٌ كامل: منتجات وسلّة وطلبٌ ودفع',
            'icon' => 'shopping-bag',
        ],
        self::CATALOG => [
            'label' => 'عرض المنتجات بلا طلب',
            'hint' => 'يرى الزائر منتجاتك وأسعارك ويطلب عبر واتساب',
            'icon' => 'layout-grid',
        ],
        self::PROFILE => [
            'label' => 'موقع تعريفي',
            'hint' => 'من نحن وماذا نقدّم وأين نحن — بلا متجر',
            'icon' => 'building-2',
        ],
    ];

    /**
     * الصفحات وأقسامها لكلّ وجهة.
     *
     * والترتيب مقصود: ما يقنع الزائر أوّلًا (الواجهة)، ثم ما يبحث عنه
     * (الأقسام والمنتجات)، ثم ما يطمئنه (المزايا والآراء)، ثم ما يوصله بك.
     */
    public const PAGES = [
        self::STORE => [
            ['key' => 'home', 'title' => 'الرئيسية', 'slug' => '/', 'home' => true, 'removable' => false, 'sections' => [
                'hero', 'categories', 'featured_products', 'promo', 'best_sellers', 'benefits', 'testimonials',
            ]],
            ['key' => 'shop', 'title' => 'المتجر', 'slug' => '/shop', 'removable' => false, 'sections' => [
                'categories', 'latest_products',
            ]],
            ['key' => 'about', 'title' => 'من نحن', 'slug' => '/about', 'sections' => [
                'image_text', 'stats',
            ]],
            ['key' => 'contact', 'title' => 'تواصل معنا', 'slug' => '/contact', 'sections' => [
                'contact', 'map', 'social',
            ]],
        ],
        self::CATALOG => [
            ['key' => 'home', 'title' => 'الرئيسية', 'slug' => '/', 'home' => true, 'removable' => false, 'sections' => [
                'hero', 'categories', 'featured_products', 'benefits', 'testimonials',
            ]],
            ['key' => 'shop', 'title' => 'المنتجات', 'slug' => '/shop', 'removable' => false, 'sections' => [
                'categories', 'latest_products',
            ]],
            ['key' => 'about', 'title' => 'من نحن', 'slug' => '/about', 'sections' => [
                'image_text',
            ]],
            ['key' => 'contact', 'title' => 'تواصل معنا', 'slug' => '/contact', 'sections' => [
                'contact', 'map', 'social',
            ]],
        ],
        self::PROFILE => [
            ['key' => 'home', 'title' => 'الرئيسية', 'slug' => '/', 'home' => true, 'removable' => false, 'sections' => [
                'hero', 'image_text', 'benefits', 'gallery', 'testimonials',
            ]],
            ['key' => 'about', 'title' => 'من نحن', 'slug' => '/about', 'sections' => [
                'image_text', 'stats',
            ]],
            ['key' => 'contact', 'title' => 'تواصل معنا', 'slug' => '/contact', 'sections' => [
                'contact', 'map', 'social',
            ]],
        ],
    ];

    /**
     * قوالب الصفحة الواحدة — لزرّ «صفحة جديدة».
     *
     * والفارغة أوّلها لا آخرها ليست تناقضًا مع «لا تبدأ من فراغ»: من يضيف
     * صفحةً بعد أن صار له موقع يعرف ما يريد فيها. والفراغُ الممنوع فراغُ
     * البداية، لا فراغُ صفحةٍ يضيفها من له موقعٌ قائم.
     */
    public const PAGE_TEMPLATES = [
        'blank' => ['label' => 'صفحة فارغة', 'hint' => 'ابدأ بما تشاء', 'sections' => []],
        'about' => ['label' => 'من نحن', 'hint' => 'قصّة النشاط وأرقامه', 'sections' => ['image_text', 'stats', 'benefits']],
        'contact' => ['label' => 'تواصل معنا', 'hint' => 'الهاتف والعنوان والخريطة', 'sections' => ['contact', 'map', 'social']],
        'faq' => ['label' => 'أسئلة شائعة', 'hint' => 'ما يسأله الزبائن كلّ يوم', 'sections' => ['faq', 'contact']],
        /*
         * ولا نصَّ قانونيًّا جاهزًا: صفحةٌ نصّية يكتب فيها التاجر سياسته.
         *
         * سياسةُ خصوصيةٍ يكتبها النظام تبدو نصًّا قانونيًّا وليست منه: تُنسخ
         * كما هي إلى مئة متجر، ولا تصف ما يفعله أيٌّ منها ببيانات زبائنه.
         * فالقالب يعطيه الصفحة والمكان، والنصّ من عنده.
         */
        'privacy' => ['label' => 'سياسة الخصوصية', 'hint' => 'صفحةٌ نصّية تكتب فيها سياستك', 'sections' => ['image_text']],
        'terms' => ['label' => 'الشروط والأحكام', 'hint' => 'صفحةٌ نصّية تكتب فيها شروطك', 'sections' => ['image_text']],
        'landing' => ['label' => 'صفحة عرض', 'hint' => 'لحملةٍ أو منتجٍ بعينه', 'sections' => ['hero', 'featured_products', 'benefits', 'contact']],
    ];

    public static function goal(?string $goal): string
    {
        return $goal !== null && isset(self::GOALS[$goal]) ? $goal : self::STORE;
    }

    /** @return array<int, array<string, mixed>> */
    public static function pages(string $goal): array
    {
        return self::PAGES[self::goal($goal)];
    }

    /** هل يبيع هذا الموقع؟ — سلّةٌ وطلبٌ ودفع */
    public static function sells(string $goal): bool
    {
        return self::goal($goal) === self::STORE;
    }

    /** هل يعرض منتجاتٍ أصلًا؟ */
    public static function hasCatalogue(string $goal): bool
    {
        return in_array(self::goal($goal), [self::STORE, self::CATALOG], true);
    }

    /** ما تعرضه الخطوة الأولى من المعالج */
    public static function goalOptions(): array
    {
        return collect(self::GOALS)->map(fn ($g, $key) => [
            'key' => $key,
            'label' => __($g['label']),
            'hint' => __($g['hint']),
            'icon' => $g['icon'],
        ])->values()->all();
    }

    /** ما يعرضه زرّ «صفحة جديدة» */
    public static function pageTemplateOptions(string $goal): array
    {
        return collect(self::PAGE_TEMPLATES)->map(fn ($t, $key) => [
            'key' => $key,
            'label' => __($t['label']),
            'hint' => __($t['hint']),
            // القسم الذي لا يصلح للوجهة يسقط من القالب ولا يمنع استعماله
            'sections' => collect($t['sections'])
                ->filter(fn ($type) => self::sectionFits($type, $goal))->values()->all(),
        ])->values()->all();
    }

    public static function sectionFits(string $type, string $goal): bool
    {
        $goals = Sections::CATALOGUE[$type]['goals'] ?? null;

        return $goals === null || in_array(self::goal($goal), $goals, true);
    }
}
