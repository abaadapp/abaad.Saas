<?php

namespace App\Support\Document;

use App\Support\Color;

/**
 * رموزُ تصميم الورقة — ما يختاره التاجر، وما تشتقّه أبعاد.
 *
 * ═══ القاعدة: التاجرُ يملك الهويّة، وأبعادُ تملك الجودة ═══
 *
 * يرفع شعارَه وغلافَه ويختار لونَه، ولا يلمس المسافاتِ ولا الخطَّ ولا بنيةَ
 * الجدول ولا الهوامش ولا التسلسل البصريّ. وهذا ليس تضييقًا: من يُعطى
 * ثلاثين مقبضًا يُخرج ورقةً لا تشبه شيئًا، ويرسلها باسمه — واسمُ أبعاد
 * عليها أيضًا.
 *
 * ═══ ولمَ لونان من لونٍ واحد ═══
 *
 * التاجرُ يختار لونًا واحدًا، ويُشتقّ منه اثنان يعملان في موضعين مختلفين:
 *
 *  • `primary` — كما اختاره تمامًا. للغلاف ولشريطٍ يُملأ، حيث اللونُ مساحةٌ
 *    لا نصّ، فسطوعُه ميزةٌ لا عطب.
 *  • `primary_ink` — نسخةٌ منه **مضمونةُ القراءة على الأبيض**. للنصّ
 *    والأرقام وخطوطِ الفصل.
 *
 * وبلا هذا الفصل: من اختار أصفرَ فاقعًا لعلامته يخرج عنوانُ فاتورته أصفرَ
 * على أبيض — لا يُقرأ على الشاشة، ويختفي كليًّا في طابعةٍ بالأبيض والأسود.
 * فيرى التاجر شعارَه على الورقة ولا يرى أنّ رقمَ فاتورته غاب.
 *
 * ═══ والورقةُ بيضاء دائمًا ═══
 *
 * لا يُختار لونُ خلفيّتها: صفحةٌ ملوّنةٌ بالكامل تشرب حبرَ الطابعة، وتخرج
 * من طابعةٍ اقتصاديّة بلونٍ غير الذي اختير، وتجعل نصَّها أقلَّ قراءةً في كلّ
 * حال. والفواتير التي تُحتذى — من Stripe إلى ما بعدها — بيضاءُ كلُّها:
 * لونُها يقع في اللمسات لا في المساحات.
 */
class Theme
{
    /**
     * أدنى تباينٍ يُقرأ — WCAG AA للنصّ العاديّ.
     *
     * وهو حدُّ الشاشة. والورقةُ أشدّ: الطابعةُ الاقتصادية تفقد درجةً أو
     * درجتين، والحبرُ يبهت. فيُشدّ الحدُّ في `ink()` إلى ما يحتمل ذلك.
     */
    public const MIN_CONTRAST = 4.5;

    /** حدُّ الورق — أعلى من حدّ الشاشة لأنّ الطبع يفقد ما لا تفقده الشاشة */
    public const PRINT_CONTRAST = 6.0;

    /** ما تبدأ به ورقةٌ لم يختر صاحبُها شيئًا — حبرٌ لا لون */
    public const DEFAULTS = [
        'primary' => '#111827',
        'accent' => '',
    ];

    /**
     * الهندسةُ ثابتة — ليست إعدادًا.
     *
     * المقاساتُ بالنقطة (pt): الورقة تُقاس بالمليمتر، والبكسل في mpdf
     * يُحوَّل بمعامل شاشةٍ لا معنى له على ورق.
     *
     * والسلّمُ نسبيٌّ لا أرقامٌ متفرّقة: كلُّ مقاسٍ يضربه معاملُ الخطّ الذي
     * اختاره التاجر، فتكبر الورقةُ معًا ولا يكبر سطرُ الجسد وحده.
     */
    public const GEOMETRY = [
        // المسافة الأساس — وكلُّ فراغٍ في الورقة مضاعفٌ منها
        'spacing' => 6.0,
        'radius' => 6.0,
        'radius_lg' => 10.0,
        // ارتفاعُ الغلاف: يكفي ليُرى ولا يأكل ثلثَ الورقة
        'cover_height' => 108.0,
        'logo_height' => 40.0,
        // سلّمُ الخطّ
        'text_xs' => 7.5,
        'text_sm' => 8.5,
        /*
         * والأساسُ عشرُ نقاط — لا تُخفَّض «لأنّ الأقلَّ أنيق».
         *
         * ١٠pt أصغرُ ما يُقرأ مطبوعًا بلا جهد لمن تجاوز الأربعين، وهو من
         * يقرأ الفواتير. والورقةُ الأنيقة التي لا تُقرأ ليست أنيقة.
         * ومعاملُ التاجر يضربه: من اختار «كبير» يبلغ ١١٫٤.
         */
        'text_base' => 10.0,
        'text_md' => 11.5,
        'text_lg' => 15.0,
        'text_xl' => 20.0,
    ];

    /**
     * الرموز كاملةً لمتجرٍ — المختارُ وما اشتُقّ منه.
     *
     * ولا تُخزَّن المشتقّات مع المختار: تُحسب عند القراءة. فلو تغيّرت قاعدةُ
     * الاشتقاق غدًا لتغيّرت الأوراقُ كلُّها معها، ولو خُزّنت لبقي كلُّ متجرٍ
     * على قاعدةٍ متروكة. (والورقةُ الصادرةُ محميّةٌ بغير هذا — انظر
     * `Version`: هي تُرسم بقالبها لا بقالب اليوم.)
     *
     * @param  array<string, mixed>  $brand  ما اختاره التاجر
     * @param  float  $scale  معامل حجم الخطّ من «قوالب الأوراق»
     * @return array<string, string|float>
     */
    public static function tokens(array $brand = [], float $scale = 1.0): array
    {
        $primary = Color::normalize($brand['primary'] ?? null, self::DEFAULTS['primary']);

        /*
         * ولونُ اللمسة يُشتقّ إن لم يُختر.
         *
         * ومزيجٌ لا لونٌ ثانٍ من عند النظام: لونٌ مختارٌ من قائمةٍ يتنافر مع
         * علامة التاجر عند أوّل أحمرَ يختاره. والمشتقُّ يبقى في عائلة لونه.
         */
        $accentRaw = Color::normalize($brand['accent'] ?? null, '');
        $accent = $accentRaw !== '' ? $accentRaw : Color::mix($primary, '#ffffff', 0.30);

        $ink = '#0f172a';
        $paper = '#ffffff';

        return [
            /* ————— ما اختاره التاجر ————— */
            'primary' => $primary,
            'accent' => $accent,

            /* ————— نسخُ القراءة ————— */
            'primary_ink' => self::ink($primary, $paper),
            'accent_ink' => self::ink($accent, $paper),
            'on_primary' => Color::readableOn($primary),
            'on_accent' => Color::readableOn($accent),

            /* ————— طبقةٌ فاتحة من لونه: خلفيّةُ رأس الجدول والبطاقات ————— */
            'primary_wash' => Color::mix($primary, $paper, 0.94),
            'primary_edge' => Color::mix($primary, $paper, 0.80),

            /* ————— الحبرُ الثابت ————— */
            'text' => $ink,
            'muted' => Color::mix($ink, $paper, 0.42),
            'faint' => Color::mix($ink, $paper, 0.60),
            'background' => $paper,
            'surface' => '#fafafa',
            'border' => '#e8e8e8',
            'rule' => '#f1f1f0',

            /* ————— الهندسة، مضروبةً بمعامل التاجر ————— */
            'radius' => self::GEOMETRY['radius'],
            'radius_lg' => self::GEOMETRY['radius_lg'],
            'spacing' => self::GEOMETRY['spacing'],
            'cover_height' => self::GEOMETRY['cover_height'],
            'logo_height' => self::GEOMETRY['logo_height'],
            'scale' => $scale,
        ];
    }

    /**
     * لونٌ صالحٌ ليكون نصًّا على الورقة — يُغمَّق حتى يُقرأ.
     *
     * ═══ ولا يُستبدل بالأسود ═══
     *
     * إسقاطُ لون التاجر إلى الأسود عند أوّل ضعفٍ في التباين يمحو هويّته:
     * من اختار ذهبيًّا يجد ورقتَه سوداء ولا يعرف لماذا. والتغميقُ يُبقيه في
     * عائلة لونه — الذهبيُّ يصير بُنّيًّا داكنًا، وهو لونُه هو ويُقرأ.
     *
     * والخطواتُ محدودةٌ بعشرين: حلقةٌ تنتظر تباينًا قد لا يبلغه لونٌ ما
     * تدور بلا نهاية على ورقةٍ واحدة.
     */
    public static function ink(string $color, string $on = '#ffffff'): string
    {
        $out = $color;

        for ($i = 0; $i < 20 && Color::contrast($out, $on) < self::PRINT_CONTRAST; $i++) {
            $out = Color::darken($out, 0.08);
        }

        return $out;
    }

    /**
     * ما يُقبل حفظُه من اختيارات الهوية.
     *
     * و«فارغ» قيمةٌ صحيحة للّمسة: من لم يختر لونًا ثانيًا يُشتقّ له. ولو
     * رُدَّ الفراغُ إلى الافتراضيّ لما استطاع أحدٌ العودةَ إلى الاشتقاق بعد
     * أن جرّب لونًا.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current  ما عليه المتجر الآن
     * @return array<string, string>
     */
    public static function normalize(array $input, array $current = []): array
    {
        $base = array_merge(self::DEFAULTS, array_intersect_key($current, self::DEFAULTS));

        return [
            'primary' => Color::normalize($input['primary'] ?? null, $base['primary']),
            'accent' => array_key_exists('accent', $input)
                ? Color::normalize($input['accent'], '')
                : $base['accent'],
        ];
    }

    /**
     * الرموز مكتوبةً قِيَمًا حرفيّة — لأنّ mpdf لا يعرف `var()`.
     *
     * ═══ وهنا يقع أهمُّ قرارٍ في هذه الطبقة ═══
     *
     * الورقةُ تُقرأ في محرّكين: mpdf ليخرج الـPDF، والمتصفّح لتُرى وتُرسَل
     * رابطًا. والمتصفّحُ يفهم `--document-primary`، وmpdf **لا يفهمها** —
     * يتجاهلها صامتًا، فتخرج الورقةُ بلا لونٍ ولا خطأ يقول لماذا.
     *
     * فتُكتب مرّتين في الكتلة نفسها: متغيّراتٍ للمتصفّح وقيمًا حرفيّةً بعدها
     * لـmpdf. والقيمةُ الحرفيّة تأتي أخيرًا فتغلب في المحرّكين معًا — أي
     * أنّ الشكل واحدٌ فيهما لا شكلان.
     *
     * @param  array<string, string|float>  $tokens
     */
    public static function css(array $tokens): string
    {
        $lines = [];

        foreach ($tokens as $key => $value) {
            if ($key === 'scale') {
                continue;
            }

            $name = str_replace('_', '-', $key);
            $lines[] = is_float($value) || is_int($value)
                ? "        --document-{$name}: {$value}pt;"
                : "        --document-{$name}: {$value};";
        }

        return implode("\n", $lines);
    }
}
