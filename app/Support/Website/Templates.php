<?php

namespace App\Support\Website;

/**
 * القوالب — بنيةٌ ولونٌ وخطّ، لا صفحاتٌ وكود.
 *
 * القالب هنا **إعدادٌ لمحرّكٍ واحد**، لا موقعٌ ثانٍ بملفّاته. ولهذا سبب عمليّ
 * لا جماليّ: قوالبُ كلٌّ منها كودُه تعني أنّ إصلاح عطبٍ في «آراء العملاء»
 * يُصلَح أربع مرّات ويُنسى في الثالثة، وأنّ إضافة قسمٍ جديد تعني أربع نسخٍ
 * منه. فالقالب هنا صفٌّ في جدول.
 *
 * ═══ ما تغيّر ═══
 *
 * كان الصفُّ ستّةَ رموزِ لونٍ وخطّ. وكان ذلك يكفي ليبدو موقعان مختلفين من
 * بعيد ولا يكفي ليكونا مختلفين: الترويسةُ واحدة والواجهةُ واحدة وبطاقةُ
 * المنتج واحدة — فالقوالب صفحةٌ واحدة بألوانٍ متبدّلة. والتاجر يرى ذلك في
 * ثانية ويسأل لماذا يشبه متجرُه متجرَ جاره.
 *
 * فصار للصفّ نصفٌ ثانٍ: `layout` — رموزُ بنيةٍ يقرؤها العارضُ نفسُه
 * (`renderer/layout.ts`) فيرسم بها بناءً آخر لا لونًا آخر. انظر `Layout`.
 *
 * ═══ والقديمة تبقى ═══
 *
 * ستّةُ قوالبَ قديمة تعمل عليها مواقعُ منشورة. حذفُ مفتاحٍ منها يعني موقعًا
 * يُفتح بلا قالب، فتبقى كلُّها `legacy`: تُرسم كما كانت — رموزُ تخطيطها هي
 * الافتراضيّة التي هي رسمُ الأمس حرفيًّا — ولا تُعرض لمن يختار اليوم.
 *
 * ونتيجةُ ذلك أنّ إضافة قالبٍ جديد تبقى سطرًا واحدًا هنا: لا هجرة، ولا شاشة،
 * ولا تعديلٌ في المحرّر ولا في العارض. ومَن يبدّل قالبه لا يفقد شيئًا:
 * الصفحات والأقسام والمحتوى تبقى كما هي، ويتبدّل ما يُشتقّ منها في العرض.
 */
class Templates
{
    public const DEFAULT = 'mono';

    /**
     * `layout` رموزُ بنيته — وما لم يُذكر منها يأخذ افتراضيَّه (`Layout`).
     * `legacy` قالبٌ يُرسم ولا يُعرض في الاختيار.
     */
    public const CATALOGUE = [
        /* ═══════════════════ الجيل الثاني ═══════════════════ */

        'atelier' => [
            'label' => 'أناقة',
            'hint' => 'صورٌ كبيرة وبياضٌ واسع وخطٌّ نسخيّ — للورد والعطور والهدايا الفاخرة',
            'theme' => [
                'primary' => '#8a6a4a', 'background' => '#faf7f2', 'text' => '#1c1714',
                'font' => 'ibm-plex-arabic', 'radius' => 'none', 'button' => 'outline',
            ],
            'layout' => [
                'width' => 'wide', 'density' => 'spacious', 'scale' => 'editorial', 'heading' => 'editorial',
                'header' => 'editorial', 'hero' => 'editorial', 'card' => 'editorial', 'grid' => 'editorial',
                'ratio' => 'portrait', 'categories' => 'tiles', 'footer' => 'brand', 'surface_style' => 'flat',
                'heading_font' => 'amiri',
            ],
        ],
        'souq' => [
            'label' => 'سوق',
            'hint' => 'بحثٌ وأقسامٌ وشبكةٌ كثيفة — لمن عنده منتجاتٌ كثيرة وعروضٌ تتبدّل',
            'theme' => [
                'primary' => '#b4123b', 'background' => '#ffffff', 'text' => '#111827',
                'font' => 'cairo', 'radius' => 'medium', 'button' => 'solid',
            ],
            'layout' => [
                'width' => 'wide', 'density' => 'compact', 'scale' => 'compact', 'heading' => 'start',
                'header' => 'commerce', 'hero' => 'showcase', 'card' => 'commerce', 'grid' => 'dense',
                'ratio' => 'square', 'categories' => 'pills', 'footer' => 'columns', 'surface_style' => 'bordered',
            ],
        ],
        'bloom' => [
            'label' => 'إبداع',
            'hint' => 'واجهةٌ مشطورة وبطاقاتٌ متفاوتة — للبوكيهات والمناسبات والهدايا',
            'theme' => [
                'primary' => '#db2777', 'background' => '#fff8fa', 'text' => '#1f1220',
                'font' => 'rubik', 'radius' => 'large', 'button' => 'soft',
            ],
            'layout' => [
                'width' => 'normal', 'density' => 'balanced', 'scale' => 'editorial', 'heading' => 'start',
                'header' => 'centered', 'hero' => 'split', 'card' => 'soft', 'grid' => 'editorial',
                'ratio' => 'landscape', 'categories' => 'covers', 'footer' => 'split', 'surface_style' => 'raised',
            ],
        ],
        'mono' => [
            'label' => 'صافي',
            'hint' => 'أبيضُ وأسود وصورٌ كبيرة بلا زينة — للعلامات التي تترك المنتج يتكلّم',
            'theme' => [
                'primary' => '#111111', 'background' => '#ffffff', 'text' => '#111111',
                'font' => 'ibm-plex-arabic', 'radius' => 'none', 'button' => 'solid',
            ],
            'layout' => [
                'width' => 'narrow', 'density' => 'spacious', 'scale' => 'balanced', 'heading' => 'start',
                'header' => 'minimal', 'hero' => 'centered', 'card' => 'plain', 'grid' => 'large',
                'ratio' => 'portrait', 'categories' => 'list', 'footer' => 'minimal', 'surface_style' => 'flat',
            ],
        ],

        /* ═══════════════════ الجيل الأوّل ═══════════════════ */

        /*
         * ولا `layout` لأيٍّ منها: الافتراضيُّ في `Layout` هو رسمُ ما قبل
         * طبقة التخطيط بالضبط. فموقعٌ على «فاخر» منذ سنة يُفتح اليوم كما
         * فُتح أمس — وهذا هو معنى ألّا تُكسر المواقع القائمة.
         */
        'minimal' => [
            'label' => 'بسيط',
            'legacy' => true,
            'hint' => 'أبيضُ وأسود، مساحاتٌ واسعة، والمنتج هو البطل',
            'theme' => [
                'primary' => '#111111', 'background' => '#ffffff', 'text' => '#111111',
                'font' => 'system', 'radius' => 'small', 'button' => 'solid',
            ],
        ],
        'modern' => [
            'label' => 'عصري',
            'legacy' => true,
            'hint' => 'أزرقُ هادئ وحوافُّ مستديرة — يصلح لأكثر المتاجر',
            'theme' => [
                'primary' => '#2563eb', 'background' => '#ffffff', 'text' => '#0f172a',
                'font' => 'cairo', 'radius' => 'medium', 'button' => 'solid',
            ],
        ],
        'bold' => [
            'label' => 'جريء',
            'legacy' => true,
            'hint' => 'خلفيةٌ داكنة ولونٌ صارخ — للعلامات الشابّة',
            'theme' => [
                'primary' => '#f97316', 'background' => '#0b0b0f', 'text' => '#f5f5f5',
                'font' => 'rubik', 'radius' => 'medium', 'button' => 'solid',
            ],
        ],
        'luxury' => [
            'label' => 'فاخر',
            'legacy' => true,
            'hint' => 'ذهبيٌّ على فحميّ — للعطور والهدايا والمجوهرات',
            'theme' => [
                'primary' => '#b8860b', 'background' => '#14110f', 'text' => '#f2ede4',
                'font' => 'tajawal', 'radius' => 'none', 'button' => 'outline',
            ],
        ],
        'fashion' => [
            'label' => 'أزياء',
            'legacy' => true,
            'hint' => 'ورديٌّ ناعم ومساحاتٌ للصور — للملابس والتجميل',
            'theme' => [
                'primary' => '#be185d', 'background' => '#fffafc', 'text' => '#1f1220',
                'font' => 'almarai', 'radius' => 'large', 'button' => 'soft',
            ],
        ],
        'food' => [
            'label' => 'مطاعم وأطعمة',
            'legacy' => true,
            'hint' => 'أخضرُ دافئ وقائمةٌ تُقرأ بسرعة — للمطاعم والحلويات',
            'theme' => [
                'primary' => '#15803d', 'background' => '#fffdf7', 'text' => '#14210f',
                'font' => 'cairo', 'radius' => 'large', 'button' => 'solid',
            ],
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::CATALOGUE[$key]);
    }

    public static function key(?string $key): string
    {
        return $key !== null && self::exists($key) ? $key : self::DEFAULT;
    }

    /** أهو من الجيل الأوّل؟ — يُرسم ولا يُعرض لمن يختار اليوم */
    public static function isLegacy(string $key): bool
    {
        return (bool) (self::CATALOGUE[self::key($key)]['legacy'] ?? false);
    }

    /** رموز ألوان القالب — مضمونةَ القراءة كغيرها */
    public static function theme(string $key): array
    {
        return Theme::normalize(self::CATALOGUE[self::key($key)]['theme']);
    }

    /** رموز بنية القالب — وما لم يُذكر منها يأخذ افتراضيَّه */
    public static function layout(string $key): array
    {
        return Layout::normalize(self::CATALOGUE[self::key($key)]['layout'] ?? []);
    }

    /**
     * ما تعرضه شاشة اختيار القالب.
     *
     * ومع كلٍّ منها رموزُه كاملةً لا بقعةُ لون: الشاشة ترسم بها **معاينةً
     * حقيقية** ببيانات التاجر ومنتجاته (انظر `BuilderController::wizard`).
     * وبقعةُ لونٍ كانت تكفي حين كان القالب لونًا، ولا تكفي حين صار بنية:
     * لا يُرى الفرقُ بين ترويسةٍ تجارية وترويسةٍ تحريرية في ثلاث بقع.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(bool $legacy = false): array
    {
        $out = [];

        foreach (self::CATALOGUE as $key => $spec) {
            if (! $legacy && ! empty($spec['legacy'])) {
                continue;
            }

            $theme = Theme::tokens($spec['theme']);

            $out[] = [
                'key' => $key,
                'label' => __($spec['label']),
                'hint' => __($spec['hint']),
                'legacy' => (bool) ($spec['legacy'] ?? false),
                'theme' => $theme,
                // رموزُ المستند كما يقرؤها العارض — لونًا وبنية
                'tokens' => $theme + self::layout($key),
                // وثلاثةُ ألوانٍ تبقى لبطاقاتٍ صغيرة لا تتّسع لمعاينة
                'swatch' => [$theme['primary'], $theme['background'], $theme['text']],
            ];
        }

        return $out;
    }
}
