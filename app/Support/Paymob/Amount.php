<?php

namespace App\Support\Paymob;

/**
 * المبلغُ بالعملة ← بأصغر وحداتها كما تقرؤه Paymob (`amount_cents`) — وبالعكس.
 *
 * ═══ ولمَ لا «× ١٠٠» ═══
 *
 * `amount_cents` اسمٌ لا وحدة: هو أصغرُ وحدةٍ في العملة. والريالُ العُمانيّ
 * ألفُ بيسة لا مئة — فـ٢٠٫٠٠٠ ر.ع تُرسَل ٢٠٠٠٠، و«× ١٠٠» يطلب من العميل
 * عُشرَ ما عليه. وكذلك الدينارُ الكويتيّ والبحرينيّ والأردنيّ. فالعددُ من
 * منازل العملة العشريّة (ISO 4217) لا ثابتٌ مكتوب.
 *
 * وهو ما تفعله صفحةُ الموقع منذ وُجدت (`Store\Paymob::open` — «ألفُ بيسةٍ
 * في الريال»)، وقد قُبضت به دفعاتٌ حقيقيّة على `oman.paymob.com`.
 */
final class Amount
{
    /** عملاتٌ بثلاث منازل — والباقي منزلتان، إلّا ما لا كسرَ له */
    private const THREE = ['OMR', 'KWD', 'BHD', 'JOD', 'TND', 'LYD', 'IQD'];

    private const ZERO = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'XAF', 'XOF'];

    public static function decimals(string $currency): int
    {
        $code = strtoupper(trim($currency));

        return match (true) {
            in_array($code, self::THREE, true) => 3,
            in_array($code, self::ZERO, true) => 0,
            default => 2,
        };
    }

    /** ٢٠٫٠٠٠ ر.ع ← ٢٠٠٠٠ — والتقريبُ إلى أقرب وحدة، لا قصٌّ يُسقط بيسة */
    public static function toMinor(float|int|string $amount, string $currency): int
    {
        $factor = 10 ** self::decimals($currency);

        return (int) round(round((float) $amount, self::decimals($currency)) * $factor);
    }

    /** ٢٠٠٠٠ ← ٢٠٫٠٠٠ ر.ع */
    public static function fromMinor(int $minor, string $currency): float
    {
        $decimals = self::decimals($currency);

        return round($minor / (10 ** $decimals), $decimals);
    }
}
