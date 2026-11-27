<?php

namespace App\Support;

use Brick\Math\BigInteger;

/**
 * ضريبةُ القيمة المضافة سطرًا سطرًا — المحرّكُ الواحد للصندوق وفواتير العملاء.
 *
 * ═══ ما كان ═══
 *
 * محرّكان لرقمٍ واحد: الصندوقُ (`SaleLines::taxFor`، `OrderCorrection`) يجمع
 * ضرائبَ البنود بلا تقريب ثمّ يقرّب المجموعَ مرّةً، وفاتورةُ العميل
 * (`CustomerInvoices::compute`) تقرّب ضريبةَ كلّ بند ثمّ تجمع. والحسابُ بكسورٍ
 * عائمة يقلب نصفَ البيسة أحيانًا. فالسلّةُ نفسُها بالنقاط نفسِها كانت تُكتب
 * ضريبتُها في الصندوق ‎1.285‎ وفي الفاتورة ‎1.286‎.
 *
 * ═══ والقاعدةُ الواحدة — من مواصفة الفوترة العُمانيّة ═══
 *
 * PINT-OM تطلب ضريبةً لكلّ سطر (BTOM-016) تساوي «نسبةَ البند × صافيه»
 * (IBR-168-OM، بتسامح ±0.001 لكلّ سطر)، وضريبةَ الفئة **مجموعَ ضرائب الأسطر**
 * (ALIGNED-IBRP-S-09-OM)، ولا مبلغَ بأكثر من ثلاث منازل (IBR-DEC-03-OM).
 * فهنا:
 *
 *   1. صافي كلّ سطرٍ بالبيسات الصحيحة.
 *   2. خصمُ المستند (كوبونٌ أو نقاط) يُوزَّع على الأسطر بالبيسات بنسبة
 *      صافيها (`Baisa::spread`) — فصافي السطر بعد الخصم ثلاثُ منازل تُكتب.
 *   3. ضريبةُ السطر = صافيه × النسبة ÷ 100 (أو ÷ (100 + النسبة) للمشمولة)،
 *      مدوَّرةً نصفًا إلى أعلى إلى البيسة — فهي داخل تسامح السطر دائمًا.
 *   4. ضريبةُ المستند = مجموعُ ضرائب الأسطر بالضبط.
 *
 * ومرآتُها في الواجهة `resources/js/lib/vat-lines.ts` — ويقابل الاثنين ملفُّ
 * حالاتٍ مشترك (`tests/fixtures/vat-lines-random-cases.json`).
 *
 * ═══ وما صدر لا يُعاد ═══
 *
 * بيعةُ الصندوق تحمل قاعدتَها (`orders.vat_rounding`): ما بيع قبلها بلا علامة
 * يُصحَّح بقاعدته القديمة (`OrderCorrection::legacyTax`)، ولا تُمسّ ضريبةُ
 * فاتورةٍ صدرت.
 */
final class VatLines
{
    /** علامةُ القاعدة على البيعة — `orders.vat_rounding` */
    public const RULE = 'line';

    /**
     * ضريبةُ سطرٍ واحد بالبيسات.
     *
     * @param  int  $netB  صافي السطر بعد خصمه بالبيسات
     */
    public static function lineTax(int $netB, float $rate, bool $inclusive): int
    {
        if ($netB <= 0 || $rate <= 0) {
            return 0;
        }

        [$rn, $rd] = Baisa::exact($rate);
        $num = BigInteger::of($netB)->multipliedBy($rn);
        $den = $inclusive ? $rd->multipliedBy(100)->plus($rn) : $rd->multipliedBy(100);

        return Baisa::halfUp($num, $den)->toInt();
    }

    /**
     * ضريبةُ مستندٍ بخصمٍ واحدٍ يُوزَّع على أسطره.
     *
     * @param  list<array{net: int, rate: float}>  $lines  صافي كلّ سطرٍ بالبيسات قبل خصم المستند
     * @param  int  $discountB  خصمُ المستند بالبيسات — ولا يتجاوز مجموعَ الأسطر
     * @return array{tax: int, lines: list<array{net: int, discount: int, taxable: int, tax: int}>}
     */
    public static function document(array $lines, int $discountB, bool $inclusive): array
    {
        $nets = array_map(fn ($l) => max(0, (int) $l['net']), $lines);
        $shares = Baisa::spread($discountB, $nets);
        $out = [];
        $tax = 0;

        foreach ($lines as $i => $l) {
            $taxable = $nets[$i] - $shares[$i];
            $lineTax = self::lineTax($taxable, (float) $l['rate'], $inclusive);
            $tax += $lineTax;
            $out[] = ['net' => $nets[$i], 'discount' => $shares[$i], 'taxable' => $taxable, 'tax' => $lineTax];
        }

        return ['tax' => $tax, 'lines' => $out];
    }
}
