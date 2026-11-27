<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Product;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;

/**
 * قسمةُ بندٍ بين البوتيك والمتجر — قاعدةٌ واحدة لكلّ قناة، بالبيسات الصحيحة.
 *
 * يقرؤها الكشفُ والورقةُ والتقريرُ وقسمُ المبيعات ونافذةُ الفاتورة والمرتجعُ وفاتورةُ
 * العميل: لا حسابَ ثانٍ في موضعٍ آخر. فما يقوله البندُ تقوله الورقةُ والدفترُ بالبناء.
 *
 * ═══ والتقريبُ مرّةً، على البند ═══
 *
 *   عمولةُ البند (حصّةُ المتجر) = أساسُه × نسبتُه ساعةَ البيع، إلى أقرب بيسة والنصفُ
 *   إلى أعلى. وصافيه (حصّةُ البوتيك) = أساسُه − عمولتُه. والمجاميعُ جمعُ بيساتٍ لا تُقرَّب
 *   ثانية. وبأعدادٍ صحيحة: ١٫٠٠٥ × ١٠٪ = ٠٫١٠٠٥ ← ٠٫١٠١ — والكسرُ العائم يقول ٠٫١٠٠.
 *
 * ═══ والأساس ═══
 *
 *   `AS_RECORDED` — القائمةُ لكلّ متجر: مبلغُ البند كما سُجّل (شاملًا الضريبة إن كانت
 *     الأسعارُ شاملة).
 *   `BEFORE_VAT` — معروضةٌ لم تُعتمد: مبلغُه قبل ضريبته. لا تسري إلّا لمتجرٍ كُتب له
 *     يومُها (`businesses.boutique_commission_before_vat_from`)، ولا بابَ في النظام يكتبه،
 *     ولا تسري على ما بيع قبله: البندُ يحمل نسبةَ ما يُخرَج منه لقطةً ساعةَ بيعه.
 *
 * ولا خصمَ يُطرح هنا: الخصمُ على المتجر وحده، أيًّا كانت القناة.
 */
final class BoutiqueMoney
{
    public const AS_RECORDED = 'as_recorded';

    public const BEFORE_VAT = 'before_vat';

    /** علامةُ الورقة التي قُرّبت بندًا بندًا — `boutique_settlements.rounding` */
    public const LINE = 'line';

    /** النسبةُ بأجزاءٍ من مئةٍ من المئة — العمودُ بمنزلتين (١٢٫٥٪ ← ١٢٥٠) */
    public static function rate(float|int|string|null $rate): int
    {
        return (int) round((float) $rate * 100);
    }

    /** مبلغٌ محفوظٌ بثلاث منازل بالبيسات — موجبًا كان أو سالبًا */
    public static function baisa(float|int|string|null $x): int
    {
        return (int) round((float) $x * 1000);
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
     * @param  int  $vatH  نسبةُ الضريبة التي تُخرَج من المبلغ قبل العمولة — صفرٌ: كما سُجّل
     * @return array{gross: int, vat: int, base: int, commission: int, net: int}
     */
    public static function split(int $grossB, int $rateH, int $vatH = 0): array
    {
        $vat = $vatH > 0 ? VatLines::lineTax($grossB, $vatH / 100, true) : 0;
        $base = $grossB - $vat;
        $commission = self::commission($base, $rateH);

        return ['gross' => $grossB, 'vat' => $vat, 'base' => $base, 'commission' => $commission, 'net' => $base - $commission];
    }

    /** قسمةُ بندِ طلبٍ كما كُتب — `total` و`boutique_rate` و`boutique_vat_rate` */
    public static function item(object $item): array
    {
        return self::split(self::baisa($item->total), self::rate($item->boutique_rate ?? 0), self::rate($item->boutique_vat_rate ?? 0));
    }

    /**
     * القسمةُ نفسُها بلغة القاعدة — بالبيسات الصحيحة، لكلّ صفّ.
     *
     * @return array{gross: string, vat: string, base: string, commission: string, net: string}
     */
    public static function sql(string $alias = 'order_items'): array
    {
        $gross = "CAST(ROUND({$alias}.total * 1000) AS BIGINT)";
        $rate = "CAST(ROUND(COALESCE({$alias}.boutique_rate, 0) * 100) AS BIGINT)";
        $vatRate = "CAST(ROUND(COALESCE({$alias}.boutique_vat_rate, 0) * 100) AS BIGINT)";
        // نصفٌ إلى أعلى بقسمةٍ صحيحة: (2أ + ب) ÷ 2ب
        $vat = "((2 * {$gross} * {$vatRate} + (10000 + {$vatRate})) / (2 * (10000 + {$vatRate})))";
        $base = "({$gross} - {$vat})";
        $commission = "(({$base} * {$rate} + 5000) / 10000)";

        return ['gross' => $gross, 'vat' => $vat, 'base' => $base, 'commission' => $commission, 'net' => "({$base} - {$commission})"];
    }

    /* ═══════════ أيُّ أساس؟ ═══════════ */

    /** قاعدةُ هذا المتجر في هذا الوقت — القائمةُ ما لم يُكتب له يومُ غيرها */
    public static function rule(int $businessId, ?Carbon $at = null): string
    {
        $from = Business::whereKey($businessId)->value('boutique_commission_before_vat_from');

        return $from !== null && Carbon::parse($from)->startOfDay()->lte($at ?? now()) ? self::BEFORE_VAT : self::AS_RECORDED;
    }

    /**
     * نسبةُ الضريبة التي تُخرَج من مبلغ بندٍ يُباع الآن — أو `null`: كما سُجّل.
     *
     * تُخرَج من سعرٍ يشملها وحده: المضافةُ فوقه ليست فيه أصلًا.
     */
    public static function vatInside(int $businessId, ?Product $product, string $rule): ?float
    {
        if ($rule !== self::BEFORE_VAT || ! Vat::inclusive($businessId)) {
            return null;
        }

        $rate = Vat::rateFor($product, $businessId);

        return $rate > 0 ? round($rate, 2) : null;
    }
}
