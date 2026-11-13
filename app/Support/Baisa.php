<?php

namespace App\Support;

use Brick\Math\BigInteger;

/**
 * المالُ بالبيسات الصحيحة — لا بالكسور العائمة.
 *
 * الريالُ ألفُ بيسة، وكلُّ مبلغٍ يُكتب أو يُطبع أو يُطلب من Paymob ثلاثُ
 * منازل لا رابعة لها (PINT-OM: ‏IBR-DEC-03-OM). فالحسابُ يجري على عددٍ صحيح
 * من البيسات، ويُقرأ الرقمُ العائم من أقصر تمثيلٍ عشريٍّ له (`json_encode`) —
 * فـ‎0.1‎ عُشرٌ تامّ لا ‎0.1000000000000000055…‎، ولا يقع نصفُ بيسةٍ مرّةً فوق
 * الحدّ ومرّةً تحته بحسب تمثيل الرقم.
 *
 * ومرآتُها في الواجهة `resources/js/lib/baisa.ts` (BigInt) — حرفًا بحرف.
 */
final class Baisa
{
    /**
     * الرقمُ كما يُكتب عشريًّا — نسبةٌ تامّة (بسط، مقام).
     *
     * @return array{0: BigInteger, 1: BigInteger}
     */
    public static function exact(float|int $x): array
    {
        $s = is_finite((float) $x) ? json_encode((float) $x) : '0';

        if (! is_string($s) || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i', $s, $m)) {
            return [BigInteger::zero(), BigInteger::one()];
        }

        $fraction = $m[3] ?? '';
        $num = BigInteger::of($m[2].$fraction);
        $scale = strlen($fraction) - (int) ($m[4] ?? 0);
        $den = BigInteger::one();

        if ($scale >= 0) {
            $den = BigInteger::ten()->power($scale);
        } else {
            $num = $num->multipliedBy(BigInteger::ten()->power(-$scale));
        }

        return [$m[1] === '-' ? $num->negated() : $num, $den];
    }

    /** `a ÷ b` مدوَّرًا نصفًا إلى أعلى — `round()` في PHP لما ليس سالبًا */
    public static function halfUp(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->multipliedBy(2)->plus($b)->quotient($b->multipliedBy(2));
    }

    /** مبلغٌ بالبيسات — `round($x, 3) × 1000` بلا كسرٍ عائم، والسالبُ صفر */
    public static function of(float|int $x): int
    {
        [$n, $d] = self::exact(max(0.0, (float) $x));

        return self::halfUp($n->multipliedBy(1000), $d)->toInt();
    }

    /** البيساتُ ريالاتٍ — للكتابة في عمود `decimal(…, 3)` */
    public static function toMoney(int $baisa): float
    {
        return $baisa / 1000.0;
    }

    /**
     * يقسم مبلغًا صحيحًا على أوزانٍ بنسبتها — والحصصُ تجمع إليه بالضبط.
     *
     * حصّةُ كلٍّ `⌊المبلغ × وزنه ÷ مجموع الأوزان⌋`، وما بقي من القصّ (أقلُّ من
     * عدد الأوزان) يذهب إلى أكبرها وزنًا ما اتّسع له، ثمّ إلى الذي يليه (والتساوي
     * بالترتيب). ولا تتجاوز حصّةٌ وزنَها: خصمٌ لا يُنزل بندًا تحت الصفر. وهي القسمةُ نفسُها التي يوزَّع بها
     * خصمُ النقاط على بنود فاتورة العميل (`CustomerInvoices::withLoyalty`)
     * وخصمُ البيعة على بنود الصندوق (`VatLines::document`) — فبيعةٌ وفاتورةٌ
     * بالبنود نفسِها والخصمِ نفسِه يقع على كلّ بندٍ منهما الخصمُ نفسُه.
     *
     * @param  array<int|string, int>  $weights  أوزانٌ غيرُ سالبة بالبيسات
     * @return array<int|string, int> بالمفاتيح نفسِها
     */
    public static function spread(int $amount, array $weights): array
    {
        $shares = array_map(fn () => 0, $weights);
        $sum = array_sum(array_map(fn ($w) => max(0, $w), $weights));

        if ($amount <= 0 || $sum <= 0) {
            return $shares;
        }

        $amount = min($amount, $sum);

        foreach ($weights as $k => $w) {
            $shares[$k] = BigInteger::of(max(0, $w))->multipliedBy($amount)->quotient($sum)->toInt();
        }

        $left = $amount - array_sum($shares);
        $order = $weights;
        uasort($order, fn ($a, $b) => $b <=> $a);

        foreach ($order as $k => $w) {
            if ($left <= 0) {
                break;
            }
            $add = min(max(0, $w) - $shares[$k], $left);
            $shares[$k] += $add;
            $left -= $add;
        }

        return $shares;
    }
}
