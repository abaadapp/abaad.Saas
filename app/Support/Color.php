<?php

namespace App\Support;

/**
 * حسابُ الألوان — قراءتُها ومزجُها وقياسُ التباين بينها.
 *
 * ═══ ولمَ خرجت من `Website\Theme` ═══
 *
 * كُتبت هناك لأنّ الموقع أوّلُ ما احتاجها. ثمّ احتاجتها الورقة: التاجر
 * يختار لونَ فاتورته كما يختار لونَ موقعه، ونصٌّ لا يُقرأ على خلفيته عطبٌ
 * على الورق كما هو على الشاشة — بل أسوأ، لأنّ الورقة لا تُصحَّح بعد طبعها.
 *
 * ونسخةٌ ثانية من هذا الحساب في `Document\Theme` كانت تعني قاعدتين
 * للتباين: تُصحَّح إحداهما ويبقى العطب في الأخرى، فيخرج موقعٌ مقروء وورقةٌ
 * لا تُقرأ — أو العكس. فالحسابُ واحد، ومن يريده يناديه.
 *
 * ولا سياسةَ هنا: هذا الملفّ يقول «كم التباين» و«ما مزيجُ اللونين»، ولا
 * يقول «ما لونُ الورقة» ولا «ما حدُّ المقبول». تلك قراراتُ من يستعمله —
 * `Website\Theme` للموقع و`Document\Theme` للورقة — وقد تختلفان بحقّ:
 * شاشةٌ مضيئة وورقٌ مطبوع لا يقرآن السوادَ نفسَه.
 */
class Color
{
    /**
     * لونٌ بصيغة `#rrggbb` — والمختصر يُمدّ، وما ليس لونًا يعود إلى سابقه.
     *
     * والحارسُ ليس تجميلًا: القيمة تُكتب في `style` على الورقة، وقيمةٌ مثل
     * `red; background:url(x)` تخرج من الحقل إلى الرسم كما هي.
     */
    public static function normalize(mixed $value, string $fallback): string
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

    /** مزيجُ لونين — `$weight` صفرٌ يعني الأوّل كاملًا وواحدٌ يعني الثاني */
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

    /**
     * اللون بصيغة `r, g, b` — لكتابته داخل `rgba()`.
     *
     * والورقةُ تحتاجه لظلٍّ أو طبقةٍ شفّافة فوق صورة الغلاف: `rgba` تقبل
     * القنوات أرقامًا لا `#rrggbb`.
     */
    public static function channels(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf('%d, %d, %d', (int) $r, (int) $g, (int) $b);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalize($hex, '#000000'), '#');

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
}
