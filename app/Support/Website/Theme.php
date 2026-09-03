<?php

namespace App\Support\Website;

/**
 * رموز التصميم — ستّة اختياراتٍ يبني منها النظام موقعًا متناسقًا.
 *
 * التاجر لا يختار عشرين لونًا. يختار لونًا أساسيًّا وخلفيةً ولون نصٍّ وخطًّا
 * وحوافَّ وشكلَ زر، ويشتقّ النظام ما بقي: لونَ ما يُكتب فوق اللون الأساسيّ،
 * ولونَ السطور الخافتة، ولونَ البطاقات. فلا يقع في تركيبةٍ سيّئة لأنّه لم
 * يُعطَ سبيلًا إليها.
 *
 * والقراءة مضمونةٌ بالبناء لا بالنصيحة: نصٌّ لا يُقرأ على خلفيته يُصحَّح عند
 * الحفظ إلى أقربِ ما يُقرأ. وهذا ليس تضييقًا على الذوق — التاجر الذي يختار
 * رماديًّا فاتحًا على أبيض لا يريد موقعًا لا يُقرأ، وإنما لا يعرف أنّه فعل.
 * ونسبةُ ٤٫٥ هي حدّ WCAG AA للنصّ العاديّ، وهي ما يُقاس به الموقع إن قِيس.
 *
 * ولا تُخزَّن المشتقّات مع المختار: تُحسب عند القراءة. فلو تغيّرت قاعدةُ
 * الاشتقاق غدًا لتغيّرت المواقع كلّها معها، ولو خُزّنت لبقيت كلُّ مواقع
 * الأمس على قاعدةٍ متروكة.
 */
class Theme
{
    /** نسبة التباين التي دونها لا يُقرأ النصّ — WCAG AA */
    public const MIN_CONTRAST = 4.5;

    /** الخطوط المتاحة — عربيّةٌ كلّها، فالنظام عربيّ أوّلًا */
    public const FONTS = [
        'system' => 'خط النظام (الأسرع)',
        'cairo' => 'القاهرة',
        'tajawal' => 'تجوّل',
        'almarai' => 'المراعي',
        'ibm-plex-arabic' => 'IBM Plex عربي',
        'rubik' => 'روبيك',
    ];

    /** درجة تدوير الحواف — ورقمُ كلٍّ منها بالبكسل يُشتقّ لا يُكتب */
    public const RADII = [
        'none' => 'حادّة',
        'small' => 'خفيفة',
        'medium' => 'متوسطة',
        'large' => 'دائرية',
    ];

    public const RADIUS_PX = ['none' => 0, 'small' => 6, 'medium' => 12, 'large' => 999];

    /** شكل الأزرار */
    public const BUTTONS = [
        'solid' => 'ممتلئ',
        'outline' => 'محدَّد',
        'soft' => 'فاتح',
    ];

    /** ما يبدأ به موقعٌ لم يختر شيئًا */
    public const DEFAULTS = [
        'primary' => '#111111',
        'background' => '#ffffff',
        'text' => '#111111',
        'font' => 'system',
        'radius' => 'medium',
        'button' => 'solid',
    ];

    /**
     * يقبل ما اختاره التاجر، ويردّ ما يصلح — لا ما كتب.
     *
     * اللون الذي ليس لونًا يعود إلى سابقه، والنصُّ الذي لا يُقرأ على خلفيته
     * يُصحَّح. والتصحيح صامتٌ عمدًا: رسالةٌ تقول «لونك سيّئ» تُوقف التاجر عند
     * كلّ محاولة، والنتيجةُ المقروءة تقول له ما يكفي.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current  ما عليه الموقع الآن
     * @return array<string, string>
     */
    public static function normalize(array $input, array $current = []): array
    {
        $base = array_merge(self::DEFAULTS, array_intersect_key($current, self::DEFAULTS));

        $theme = [
            'primary' => self::color($input['primary'] ?? null, $base['primary']),
            'background' => self::color($input['background'] ?? null, $base['background']),
            'text' => self::color($input['text'] ?? null, $base['text']),
            'font' => self::pick($input['font'] ?? null, self::FONTS, $base['font']),
            'radius' => self::pick($input['radius'] ?? null, self::RADII, $base['radius']),
            'button' => self::pick($input['button'] ?? null, self::BUTTONS, $base['button']),
        ];

        // نصٌّ لا يُقرأ على خلفيته يُصحَّح إلى الأسود أو الأبيض — أيّهما يُقرأ
        if (self::contrast($theme['text'], $theme['background']) < self::MIN_CONTRAST) {
            $theme['text'] = self::readableOn($theme['background']);
        }

        return $theme;
    }

    /**
     * الرموز الكاملة كما يقرؤها العارض — المختارُ وما اشتُقّ منه.
     *
     * @param  array<string, mixed>  $theme
     * @return array<string, string|int>
     */
    public static function tokens(array $theme): array
    {
        $theme = self::normalize($theme);
        $dark = self::luminance($theme['background']) < 0.4;

        return $theme + [
            // ما يُكتب فوق اللون الأساسيّ: أبيضُ على داكنٍ وأسودُ على فاتح
            'on_primary' => self::readableOn($theme['primary']),
            // البطاقات: خطوةٌ عن الخلفية لا لونٌ آخر — فلا تتنافر مع شيء
            'surface' => $dark ? self::lighten($theme['background'], 0.06) : self::darken($theme['background'], 0.03),
            'border' => $dark ? self::lighten($theme['background'], 0.14) : self::darken($theme['background'], 0.10),
            // السطور الخافتة تبقى مقروءة: مزجٌ لا شفافية
            'muted' => self::mix($theme['text'], $theme['background'], 0.45),
            'radius_px' => self::RADIUS_PX[$theme['radius']],
        ];
    }

    /* ------------------------------ الألوان ------------------------------ */

    /** لونٌ بصيغة `#rrggbb` — والمختصر يُمدّ، وما ليس لونًا يعود إلى سابقه */
    public static function color(mixed $value, string $fallback): string
    {
        $raw = mb_strtolower(trim((string) (is_scalar($value) ? $value : '')));

        if (preg_match('/^#([0-9a-f]{3})$/', $raw, $m) === 1) {
            [$r, $g, $b] = str_split($m[1]);

            return "#{$r}{$r}{$g}{$g}{$b}{$b}";
        }

        return preg_match('/^#[0-9a-f]{6}$/', $raw) === 1 ? $raw : $fallback;
    }

    /**
     * نسبة التباين بين لونين — WCAG.
     *
     * تُقاس بالإضاءة النسبية لا بالفرق بين الأرقام: `#0000ff` و`#000000`
     * متقاربان رقمًا وبعيدان في العين، والعكس يقع كثيرًا.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** الأسود أو الأبيض — أيّهما يُقرأ فوق هذا اللون */
    public static function readableOn(string $background): string
    {
        return self::contrast('#ffffff', $background) >= self::contrast('#111111', $background)
            ? '#ffffff' : '#111111';
    }

    /** الإضاءة النسبية (0 أسود · 1 أبيض) */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::color($hex, '#000000'), '#');

        return [
            (float) hexdec(substr($hex, 0, 2)),
            (float) hexdec(substr($hex, 2, 2)),
            (float) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function toHex(float $r, float $g, float $b): string
    {
        return sprintf('#%02x%02x%02x',
            (int) round(max(0, min(255, $r))),
            (int) round(max(0, min(255, $g))),
            (int) round(max(0, min(255, $b))),
        );
    }

    public static function mix(string $a, string $b, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        $w = max(0.0, min(1.0, $weight));

        return self::toHex(
            $r1 + ($r2 - $r1) * $w,
            $g1 + ($g2 - $g1) * $w,
            $b1 + ($b2 - $b1) * $w,
        );
    }

    public static function lighten(string $hex, float $amount): string
    {
        return self::mix($hex, '#ffffff', $amount);
    }

    public static function darken(string $hex, float $amount): string
    {
        return self::mix($hex, '#000000', $amount);
    }

    /** @param array<string, string> $allowed */
    private static function pick(mixed $value, array $allowed, string $fallback): string
    {
        $raw = (string) (is_scalar($value) ? $value : '');

        return array_key_exists($raw, $allowed) ? $raw : $fallback;
    }

    /** الخطوط والحواف والأزرار كما تُعرض في شاشة التصميم */
    public static function options(): array
    {
        $map = fn (array $list) => collect($list)->map(fn ($l, $v) => ['value' => $v, 'label' => __($l)])->values()->all();

        return [
            'fonts' => $map(self::FONTS),
            'radii' => $map(self::RADII),
            'buttons' => $map(self::BUTTONS),
        ];
    }
}
