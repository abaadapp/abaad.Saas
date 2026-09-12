<?php

namespace App\Support\Website;

/**
 * القوالب — ألوانٌ وخطٌّ وهيئةُ أقسامٍ، لا صفحاتٌ وكود.
 *
 * القالب هنا **إعدادٌ لمحرّكٍ واحد**، لا موقعٌ ثانٍ بملفّاته. ولهذا سبب عمليّ
 * لا جماليّ: قوالبُ كلٌّ منها كودُه تعني أنّ إصلاح عطبٍ في «آراء العملاء»
 * يُصلَح ثماني مرّات ويُنسى في السابعة، وأنّ إضافة قسمٍ جديد تعني ثماني
 * نسخٍ منه. فالقالب هنا صفٌّ في جدول: اسمٌ ووصفٌ وستّة رموز تصميم.
 *
 * ونتيجةُ ذلك أنّ إضافة قالبٍ جديد سطرٌ واحد هنا. لا هجرة، ولا شاشة، ولا
 * تعديلٌ في المحرّر ولا في العارض.
 *
 * ═══ و`presets`: لماذا لا يفترق القالبان باللون وحده ═══
 *
 * قالبان لونُهما مختلفٌ وكلُّ ما عداهما واحد يُقرآن قالبًا واحدًا بلونين —
 * والتاجر الذي يُعرض عليه ثلاثةٌ منها يظنّ أنّه يختار لونًا، فيختار أيَّها
 * كان. فلكلّ قالبٍ هنا **هيئةٌ** أيضًا: شكلُ الترويسة، وارتفاعُ الواجهة
 * ومحاذاتُها، وعددُ أعمدة المنتجات، وشكلُ التصنيفات.
 *
 * وكلُّها مقابضُ موجودةٌ في وصف القسم أصلًا (`Sections::CATALOGUE`) يملكها
 * التاجر بعد الإنشاء — فالقالب يختار له بدايتَها لا أكثر. ولا رسمَ جديدًا
 * في العارض ولا حقلَ جديدًا في القاعدة: القالبُ يقول «ابدأ من هنا»، والرسمُ
 * الذي كان هو الرسم.
 *
 * ═══ ومتى تُطبَّق ═══
 *
 * **عند الإنشاء وحده.** تبديلُ القالب بعد ذلك يبدّل الرموز ولا يمسّ المحتوى
 * — وهو عقدٌ قائم: من رفع ارتفاع واجهته ثمّ بدّل قالبه لا يُعاد ارتفاعُه إلى
 * ما لم يختره. وهيئةُ القسم بعد الإنشاء ملكُ من كتبها لا ملكُ القالب.
 *
 * ومَن يبدّل قالبه لا يفقد شيئًا: الصفحات والأقسام والمحتوى تبقى كما هي،
 * ويتبدّل ما يُشتقّ منها في العرض. وهذا لا يصحّ لو كان القالب صفحاتٍ.
 */
class Templates
{
    public const DEFAULT = 'modern';

    /**
     * ما يُعرض على من لا موقع له — ثلاثةٌ لا ستّة.
     *
     * ستّةُ خياراتٍ في أوّل شاشةٍ يفتحها التاجر ليست سخاءً: هي ستّةُ قراراتٍ
     * لا يملك أساسًا للمفاضلة بينها، فيقف أو يختار أوّلَها. وثلاثةٌ بينها
     * فرقٌ يُرى في لمحة قرارٌ يُتّخذ في ثانية.
     *
     * والثلاثةُ الباقية لا تُحذف: مواقعُ تعمل اليوم مبنيّةٌ عليها، وتُعرض في
     * «التصميم» لمن صار له موقعٌ وعرف ما يريد.
     */
    public const FEATURED = ['modern', 'minimal', 'bold'];

    /**
     * `goals` الوجهات التي يليق بها — يُرشَّح بها في شاشة الاختيار.
     * و`null` تعني «يليق بكلّها».
     *
     * و`presets` هيئةُ الأقسام التي يبدأ بها موقعُ هذا القالب — مفاتيحُها
     * أنواعُ الأقسام، وقيمُها حقولٌ من وصف القسم نفسه. وما لا يعرفه الوصفُ
     * يسقط في `apply`، فلا يُكتب في القاعدة مفتاحٌ لا يقرؤه أحد.
     */
    public const CATALOGUE = [
        'minimal' => [
            'label' => 'بسيط',
            'hint' => 'أبيضُ وأسود، وحوافُّ حادّة — والمنتج هو البطل',
            'theme' => [
                'primary' => '#111111', 'background' => '#ffffff', 'text' => '#111111',
                'font' => 'system', 'radius' => 'none', 'button' => 'outline',
            ],
            'presets' => [
                // ترويسةٌ لا تزاحم: شعارٌ وقائمة، والبحثُ لمن يطلبه
                Sections::HEADER => ['preset' => 'simple', 'show_search' => false],
                // واجهةٌ قصيرة محاذاةً إلى أوّل السطر — النصّ يُقرأ ولا يُتأمَّل
                'hero' => ['align' => 'start', 'height' => 'small', 'overlay' => 'light'],
                'featured_products' => ['columns' => '4'],
                'latest_products' => ['columns' => '4'],
                'categories' => ['style' => 'pills'],
            ],
        ],
        'modern' => [
            'label' => 'عصري',
            'hint' => 'أزرقُ هادئ وحوافُّ مستديرة — يصلح لأكثر المتاجر',
            'theme' => [
                'primary' => '#2563eb', 'background' => '#ffffff', 'text' => '#0f172a',
                'font' => 'cairo', 'radius' => 'medium', 'button' => 'solid',
            ],
            'presets' => [
                // ترويسةٌ موسّعة: بحثٌ وسلّة — متجرٌ يُتصفّح لا صفحةٌ تُقرأ
                Sections::HEADER => ['preset' => 'full', 'show_search' => true],
                'hero' => ['align' => 'center', 'height' => 'medium', 'overlay' => 'medium'],
                'featured_products' => ['columns' => '3'],
                'latest_products' => ['columns' => '3'],
                'categories' => ['style' => 'cards'],
            ],
        ],
        'bold' => [
            'label' => 'جريء',
            'hint' => 'خلفيةٌ داكنة وواجهةٌ بملء الشاشة — للعلامات الشابّة',
            'theme' => [
                'primary' => '#f97316', 'background' => '#0b0b0f', 'text' => '#f5f5f5',
                'font' => 'rubik', 'radius' => 'large', 'button' => 'solid',
            ],
            'presets' => [
                Sections::HEADER => ['preset' => 'centered', 'show_search' => false],
                // واجهةٌ بملء الشاشة وتعتيمٌ قويّ: الصورةُ تحمل الرسالة
                'hero' => ['align' => 'center', 'height' => 'large', 'overlay' => 'strong'],
                // وعمودان: صورةٌ كبيرةٌ لكلّ منتج لا شبكةُ مصغّرات
                'featured_products' => ['columns' => '2'],
                'latest_products' => ['columns' => '2'],
                'categories' => ['style' => 'grid'],
            ],
        ],
        'luxury' => [
            'label' => 'فاخر',
            'hint' => 'ذهبيٌّ على فحميّ — للعطور والهدايا والمجوهرات',
            'theme' => [
                'primary' => '#b8860b', 'background' => '#14110f', 'text' => '#f2ede4',
                'font' => 'tajawal', 'radius' => 'none', 'button' => 'outline',
            ],
            'presets' => [
                Sections::HEADER => ['preset' => 'centered', 'show_search' => false],
                'hero' => ['align' => 'center', 'height' => 'large', 'overlay' => 'strong'],
                'featured_products' => ['columns' => '3'],
                'latest_products' => ['columns' => '3'],
                'categories' => ['style' => 'grid'],
            ],
        ],
        'fashion' => [
            'label' => 'أزياء',
            'hint' => 'ورديٌّ ناعم ومساحاتٌ للصور — للملابس والتجميل',
            'theme' => [
                'primary' => '#be185d', 'background' => '#fffafc', 'text' => '#1f1220',
                'font' => 'almarai', 'radius' => 'large', 'button' => 'soft',
            ],
            'presets' => [
                Sections::HEADER => ['preset' => 'centered', 'show_search' => true],
                'hero' => ['align' => 'center', 'height' => 'large', 'overlay' => 'light'],
                'featured_products' => ['columns' => '2'],
                'latest_products' => ['columns' => '3'],
                'categories' => ['style' => 'grid'],
            ],
        ],
        'food' => [
            'label' => 'مطاعم وأطعمة',
            'hint' => 'أخضرُ دافئ وقائمةٌ تُقرأ بسرعة — للمطاعم والحلويات',
            'theme' => [
                'primary' => '#15803d', 'background' => '#fffdf7', 'text' => '#14210f',
                'font' => 'cairo', 'radius' => 'large', 'button' => 'solid',
            ],
            'presets' => [
                Sections::HEADER => ['preset' => 'simple', 'show_search' => true],
                'hero' => ['align' => 'start', 'height' => 'medium', 'overlay' => 'medium'],
                'featured_products' => ['columns' => '3'],
                'latest_products' => ['columns' => '4'],
                'categories' => ['style' => 'pills'],
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

    /** رموز تصميم القالب — مضمونةَ القراءة كغيرها */
    public static function theme(string $key): array
    {
        return Theme::normalize(self::CATALOGUE[self::key($key)]['theme']);
    }

    /**
     * هيئةُ قسمٍ كما يبدأ بها هذا القالب.
     *
     * والمفتاحُ الذي لا يعرفه وصفُ القسم يسقط: القالب يختار من مقابضَ قائمة
     * ولا يخترع مقبضًا. فلو رُفع حقلٌ من وصف قسمٍ غدًا سقط ذكرُه هنا بلا أن
     * يُكتب في القاعدة ما لا يُقرأ.
     *
     * @param  array<string, mixed>  $data  ما بناه `MerchantData::seed`
     * @return array<string, mixed>
     */
    public static function apply(string $template, string $type, array $data): array
    {
        $preset = self::CATALOGUE[self::key($template)]['presets'][$type] ?? [];

        return array_merge($data, array_intersect_key($preset, $data));
    }

    /**
     * ما تعرضه شاشة اختيار القالب.
     *
     * ومع كلٍّ منها لوحةُ ألوانه: ثلاثةُ ألوانٍ تكفي لتُقرأ الهويّة في بطاقةٍ
     * صغيرة. وأمّا شاشةُ الإنشاء فتعرض الموقعَ نفسه مرسومًا بكلّ قالب — انظر
     * `BuilderController::wizard`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(?string $goal = null): array
    {
        $out = [];

        foreach (self::CATALOGUE as $key => $spec) {
            if ($goal !== null && isset($spec['goals']) && ! in_array($goal, $spec['goals'], true)) {
                continue;
            }

            $theme = Theme::tokens($spec['theme']);

            $out[] = [
                'key' => $key,
                'label' => __($spec['label']),
                'hint' => __($spec['hint']),
                'theme' => $theme,
                // ثلاثةُ ألوانٍ تكفي لتُقرأ الهويّة قبل الاختيار
                'swatch' => [$theme['primary'], $theme['background'], $theme['text']],
                /*
                 * وأيُّها معتمد — تقرؤه اللوحةُ فتعرض الثلاثة وتطوي الباقي.
                 *
                 * والقائمةُ تبقى واحدة: لو أرسل الخادمُ ثلاثةً وأخفى ثلاثةً
                 * لَما وجد صاحبُ موقعٍ مبنيٍّ على «فاخر» قالبَه في شاشته.
                 */
                'featured' => in_array($key, self::FEATURED, true),
            ];
        }

        return $out;
    }

    /** الثلاثةُ التي تُعرض على من لا موقع له — بترتيب `FEATURED` لا بترتيب الجدول */
    public static function featured(): array
    {
        $all = collect(self::options())->keyBy('key');

        return collect(self::FEATURED)->map(fn ($key) => $all[$key] ?? null)->filter()->values()->all();
    }
}
