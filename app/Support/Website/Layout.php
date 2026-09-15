<?php

namespace App\Support\Website;

/**
 * رموز التخطيط — القالب بنيةٌ لا لوحةُ ألوان.
 *
 * `Theme` تصف بأيّ لونٍ يُصبغ الموقع، وهذه تصف **كيف يُبنى**: عرضُ المحتوى،
 * وكثافةُ الفراغ، وسلّمُ العناوين، وشكلُ الترويسة والواجهة وبطاقة المنتج
 * والشبكة والتصنيفات والتذييل.
 *
 * ولماذا طبقةٌ ثانية أصلًا؟ لأنّ ستّة ألوانٍ لا تصنع قالبًا. كانت القوالب
 * الستّة صفحةً واحدة بألوانٍ متبدّلة: الترويسةُ واحدة والواجهةُ واحدة
 * والبطاقةُ واحدة. والتاجر يرى ذلك في ثانية، ويسأل لماذا يبدو متجرُه كمتجر
 * جاره. فصار للقالب رموزُ بنيةٍ يقرؤها العارضُ نفسُه (`renderer/layout.ts`)
 * فيرسم بها بناءً آخر — لا لونًا آخر.
 *
 * وثلاثة قيود:
 *
 * ١) **الافتراضيُّ هو رسمُ الأمس حرفيًّا.** `DEFAULTS` هنا تطابق
 *    `LAYOUT_DEFAULTS` في العارض، وهي ما كان يُرسم قبل هذه الطبقة. فموقعٌ
 *    قائمٌ لا رموزَ تخطيطٍ له — ولا نشرةٌ منشورةٌ قبل اليوم — يتبدّل شكلُه.
 *
 * ٢) **القائمةُ مغلقة.** ما ليس في `ALLOWED` يعود إلى افتراضيّه. فطلبٌ ملفَّق
 *    لا يزرع قيمةً لا يعرفها العارض فتخرج صفحةٌ بلا تخطيط.
 *
 * ٣) **تُحسب عند القراءة لا تُخزَّن مشتقّةً.** كما في `Theme`: ما يُخزَّن هو
 *    ما اختاره التاجر، وما يقرؤه العارض يُشتقّ في `Website::tokens()`.
 *
 * @see resources/js/Pages/Admin/Website/preview/renderer/layout.ts
 */
class Layout
{
    /**
     * الرمز ← قيمُه المقبولة، وأوّلُها افتراضيُّه.
     *
     * وأوّلُ كلّ قائمةٍ هو ما كان يُرسم قبل هذه الطبقة — عمدًا: الافتراضيّ
     * يُقرأ من مكانٍ واحد فلا يفترق عن `DEFAULTS`.
     */
    public const ALLOWED = [
        'width' => ['normal', 'narrow', 'wide', 'full'],
        'density' => ['balanced', 'compact', 'spacious'],
        'scale' => ['balanced', 'compact', 'editorial', 'display', 'precise'],
        'heading' => ['center', 'start', 'editorial'],
        'header' => ['minimal', 'centered', 'commerce', 'editorial'],
        'hero' => ['classic', 'centered', 'split', 'editorial', 'showcase'],
        'card' => ['plain', 'soft', 'commerce', 'editorial', 'bare'],
        'grid' => ['classic', 'dense', 'large', 'editorial'],
        'ratio' => ['square', 'portrait', 'landscape'],
        'categories' => ['cards', 'pills', 'covers', 'tiles', 'list'],
        'footer' => ['columns', 'minimal', 'brand', 'split'],
        'surface_style' => ['bordered', 'flat', 'raised'],
    ];

    /** أسماءٌ يعرضها المحرّر — وما لا يُعرض للتاجر لا اسمَ له هنا */
    public const LABELS = [
        'width' => [
            'label' => 'عرض المحتوى',
            'options' => ['normal' => 'متوسط', 'narrow' => 'ضيّق', 'wide' => 'واسع', 'full' => 'ملء الشاشة'],
        ],
        'density' => [
            'label' => 'شكل الموقع',
            'options' => ['balanced' => 'متوازن', 'compact' => 'متراصّ', 'spacious' => 'فسيح'],
        ],
        /*
         * والسلّم ليس حجمًا وحده.
         *
         * كان ثلاثةً تبدّل حجم العنوان لا غير، فكان القالبان يختلفان في
         * رقمٍ ويتشابهان في كلّ ما عداه. وصار كلُّ سلّمٍ يحمل معه وزنَ
         * العنوان وارتفاعَ سطره وعرضَ سطره — وهي التي تُقرأ شخصيّةً. ولذلك
         * صارت أسماؤها أصواتًا لا مقاسات: «فخم» و«جريء» و«دقيق».
         */
        'scale' => [
            'label' => 'شخصية العناوين',
            'options' => [
                'balanced' => 'متوازن', 'compact' => 'عمليّ', 'editorial' => 'فخم — خفيفٌ وكبير',
                'display' => 'جريء — ثقيلٌ ومضموم', 'precise' => 'دقيق — صغيرٌ ونظيف',
            ],
        ],
        'heading' => [
            'label' => 'عناوين الأقسام',
            'options' => ['center' => 'في الوسط', 'start' => 'من الحافّة', 'editorial' => 'بخطٍّ فوقها'],
        ],
        'header' => [
            'label' => 'الترويسة',
            'options' => [
                'minimal' => 'بسيطة', 'centered' => 'الشعار في الوسط',
                'commerce' => 'متجر — بحثٌ وشريط أقسام', 'editorial' => 'واسعة وهادئة',
            ],
        ],
        'hero' => [
            'label' => 'الواجهة',
            'options' => [
                'classic' => 'صورة خلفية', 'centered' => 'نصّ في الوسط', 'split' => 'نصّ وصورة',
                'editorial' => 'صورة ملء العرض', 'showcase' => 'لوحة عرض',
            ],
        ],
        'card' => [
            'label' => 'شكل المنتجات',
            'options' => [
                'plain' => 'بسيط', 'soft' => 'بطاقات ناعمة', 'commerce' => 'بطاقات متجر',
                'editorial' => 'صور كبيرة', 'bare' => 'بلا إطار',
            ],
        ],
        'grid' => [
            'label' => 'شبكة المنتجات',
            'options' => ['classic' => 'عادية', 'dense' => 'كثيفة', 'large' => 'كبيرة', 'editorial' => 'متفاوتة'],
        ],
        'ratio' => [
            'label' => 'نسبة صور المنتجات',
            'options' => ['square' => 'مربّعة', 'portrait' => 'طوليّة', 'landscape' => 'عرضيّة'],
        ],
        'categories' => [
            'label' => 'شكل التصنيفات',
            'options' => [
                'cards' => 'بطاقات', 'pills' => 'أزرار', 'covers' => 'أغلفة',
                'tiles' => 'بلاطات كبيرة', 'list' => 'قائمة',
            ],
        ],
        'footer' => [
            'label' => 'التذييل',
            'options' => ['columns' => 'أعمدة', 'minimal' => 'نحيف', 'brand' => 'العلامة أوّلًا', 'split' => 'مشطور'],
        ],
        'surface_style' => [
            'label' => 'حدود البطاقات',
            'options' => ['bordered' => 'حدٌّ رفيع', 'flat' => 'بلا حدود', 'raised' => 'ظلّ خفيف'],
        ],
    ];

    /**
     * ما يراه التاجر في «التصميم» مباشرةً — والباقي تحت «خيارات إضافية».
     *
     * وثلاثةٌ لا عشرة: من فتح شاشة التصميم يريد أن يجرّب لا أن يملأ استمارة.
     * وما لا يُعرض ليس ممنوعًا — هو تحت زرٍّ واحد.
     */
    public const PRIMARY = ['density', 'card', 'ratio'];

    /** ما يُعرض تحت «خيارات إضافية» — والترتيب هو ترتيب العرض */
    public const ADVANCED = ['width', 'scale', 'heading', 'header', 'hero', 'grid', 'categories', 'footer', 'surface_style'];

    public static function defaults(): array
    {
        return array_map(fn (array $values) => $values[0], self::ALLOWED) + ['heading_font' => ''];
    }

    /**
     * يقبل ما اختاره التاجر، ويردّ ما يصلح — لا ما كتب.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current  ما عليه الموقع الآن
     * @return array<string, string>
     */
    public static function normalize(array $input, array $current = []): array
    {
        $base = array_merge(self::defaults(), array_intersect_key($current, self::defaults()));
        $out = [];

        foreach (self::ALLOWED as $key => $values) {
            $raw = (string) (is_scalar($input[$key] ?? null) ? $input[$key] : '');
            $out[$key] = in_array($raw, $values, true) ? $raw : (string) $base[$key];
        }

        /*
         * وخطُّ العناوين من قائمة الخطوط نفسها — أو فراغٌ يعني خطَّ النصّ.
         *
         * وهو الرمز الوحيد الذي يقرأ من `Theme`: قائمتان للخطوط تعنيان خطًّا
         * يُضاف في إحداهما ولا يُضاف في الأخرى.
         */
        $font = (string) (is_scalar($input['heading_font'] ?? null) ? $input['heading_font'] : '');
        $out['heading_font'] = array_key_exists($font, Theme::FONTS)
            ? $font
            : (string) ($base['heading_font'] ?? '');

        return $out;
    }

    /** ما تعرضه شاشة التصميم — مترجَمًا، ومقسومًا إلى ظاهرٍ ومطويّ */
    public static function options(): array
    {
        $one = fn (string $key) => [
            'key' => $key,
            'label' => __(self::LABELS[$key]['label']),
            'options' => collect(self::LABELS[$key]['options'])
                ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => __($label)])
                ->values()->all(),
        ];

        return [
            'primary' => array_map($one, self::PRIMARY),
            'advanced' => array_map($one, self::ADVANCED),
        ];
    }
}
