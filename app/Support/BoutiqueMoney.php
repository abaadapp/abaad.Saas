<?php

namespace App\Support;

use Brick\Math\BigInteger;

/**
 * قسمةُ بندٍ بين البوتيك والمتجر — بالبيسات الصحيحة، من موضعٍ واحد.
 *
 * العمولةُ (حصّةُ المتجر) = مبلغُ البند × نسبتُه ساعةَ البيع، إلى أقرب بيسة والنصفُ
 * إلى أعلى. والصافي (حصّةُ البوتيك) = المبلغ − العمولة. والمجاميعُ جمعُ بيساتٍ لا
 * تُقرَّب مرّةً ثانية. ولا خصمَ يُطرح هنا: الخصمُ على المتجر وحده.
 */
final class BoutiqueMoney
{
    /** القاعدةُ القائمة: الأساسُ مبلغُ البند كما سُجّل — شاملًا الضريبة إن كانت الأسعارُ شاملة */
    public const AS_RECORDED = 'as_recorded';

    /** النسبةُ بأجزاءٍ من مئةٍ من المئة — العمودُ بمنزلتين (١٢٫٥٪ ← ١٢٥٠) */
    public static function rate(float|int|string|null $rate): int
    {
        return (int) round((float) $rate * 100);
    }

    /** عمولةُ مبلغٍ بالبيسات — نصفٌ إلى أعلى (وبعيدًا عن الصفر للسالب)، بلا كسرٍ عائم */
    public static function commission(int $amountB, int $rateH): int
    {
        $x = $amountB * $rateH;

        return $x >= 0 ? intdiv($x + 5000, 10000) : -intdiv(-$x + 5000, 10000);
    }

    /** الكمّيّةُ × السعر بالبيسات، نصفٌ إلى أعلى — كما يحسبهما بندُ الفاتورة (`CustomerInvoices::compute`) */
    public static function gross(float|string $quantity, float|string $unitPrice): int
    {
        return Baisa::halfUp(BigInteger::of(Baisa::of((float) $quantity))->multipliedBy(Baisa::of((float) $unitPrice)), BigInteger::of(1000))->toInt();
    }

    /**
     * قسمةُ مبلغ بند.
     *
     * @return array{gross: int, base: int, commission: int, net: int}
     */
    public static function split(int $grossB, int $rateH): array
    {
        $commission = self::commission($grossB, $rateH);

        return ['gross' => $grossB, 'base' => $grossB, 'commission' => $commission, 'net' => $grossB - $commission];
    }
}
