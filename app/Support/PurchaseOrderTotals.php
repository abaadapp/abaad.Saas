<?php

namespace App\Support;

use App\Models\Setting;

/**
 * إجماليُّ أمر الشراء — صيغةٌ واحدة يقرؤها الخادمُ والشاشة.
 *
 * ═══ لماذا موضعٌ واحد ═══
 *
 * الشاشةُ تجمع لتُري التاجرَ ما يوقّع عليه، والخادمُ يجمع ليكتب في القاعدة.
 * ولو حُسبت الصيغةُ مرّتين لافترقتا يومًا — وحين تفترقان يرى التاجرُ رقمًا
 * ويُحفظ غيرُه، ولا يشتكي أحد. فالخادمُ هو المرجع دائمًا (`from()` تُهمل ما
 * ترسله الشاشة من إجماليّات جملةً)، وهذه الصيغةُ منقولةٌ حرفيًّا إلى
 * `lib/purchase-totals.ts` — واختبارٌ يقابل الاثنين.
 *
 * ═══ والترتيب مقصود ═══
 *
 *   مجموعُ البنود
 *   − خصمُ المورّد        ← يقع على البضاعة، لا على الشحن
 *   + تكلفةُ الشحن
 *   ─────────────
 *   = الوعاءُ الخاضع
 *   + الضريبة (نسبةُ المتجر على الوعاء)
 *   ─────────────
 *   = الإجماليّ
 *
 * والخصمُ قبل الضريبة لأنّ الضريبة تُحتسب على ما يُدفع فعلًا؛ والشحنُ داخلُ
 * الوعاء لأنّه جزءٌ من ثمن التوريد في فاتورة المورّد كما تصل.
 *
 * ═══ وهو ما تقابله المطابقة ═══
 *
 * `SupplierInvoices::match` تقابل `invoice.total` بـ`purchase_orders.total`.
 * وسندُ المورّد إجماليُّه (مجموع + ضريبة). فلو بقي إجماليُّ الأمر بلا ضريبة
 * لَمُنع كلُّ سندٍ في متجرٍ ضريبتُه مفعّلة — بمقدار الضريبة بالضبط.
 */
final class PurchaseOrderTotals
{
    /** أقصى ما يُقبل من منازل: الريال العماني ثلاثة */
    private const SCALE = 3;

    /**
     * @param  array<int, array{cost?: mixed, quantity?: mixed}>  $items
     * @return array{items_subtotal: float, supplier_discount: float, shipping_cost: float, taxable: float, tax: float, tax_rate: float, total: float}
     */
    public static function compute(array $items, float $discount, float $shipping, float $taxRate): array
    {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += self::line($item);
        }

        $subtotal = round($subtotal, self::SCALE);
        $discount = round(max(0, $discount), self::SCALE);
        $shipping = round(max(0, $shipping), self::SCALE);

        /*
         * وخصمٌ أكبرُ من البضاعة يُحصر ولا يُقلب.
         *
         * الحارسُ على المدخلات يردّه برسالة، وهذه آخرُ حيلةٍ: إجماليٌّ سالبٌ
         * يُكتب في القاعدة يجعل المطابقة تقابل رقمًا لا معنى له.
         */
        $discount = min($discount, $subtotal);
        $taxable = round($subtotal - $discount + $shipping, self::SCALE);
        $tax = round($taxable * (max(0, $taxRate) / 100), self::SCALE);

        return [
            'items_subtotal' => $subtotal,
            'supplier_discount' => $discount,
            'shipping_cost' => $shipping,
            'taxable' => $taxable,
            'tax' => $tax,
            'tax_rate' => round(max(0, $taxRate), 2),
            'total' => round($taxable + $tax, self::SCALE),
        ];
    }

    /** إجماليُّ سطرٍ واحد: الكميّةُ بوحدة الشراء × تكلفة الوحدة الواحدة منها */
    public static function line(array $item): float
    {
        return round(((float) ($item['cost'] ?? 0)) * ((float) ($item['quantity'] ?? 0)), self::SCALE);
    }

    /**
     * كم وحدةَ تخزينٍ يعني هذا السطر — وهو ما يدخل الرفَّ لا الكميّة كما كُتبت.
     *
     * خمسُ ربطاتٍ في العشرين مئةُ حبّة. والمعاملُ صفرٌ أو فارغٌ يُقرأ واحدًا:
     * بندٌ قديمٌ لا معاملَ له اشتُري بوحدة التخزين نفسها.
     */
    public static function baseQuantity(float $quantity, ?float $unitsPer): float
    {
        $factor = $unitsPer !== null && $unitsPer > 0 ? $unitsPer : 1.0;

        return round($quantity * $factor, self::SCALE);
    }

    /** نسبةُ ضريبة المتجر — أو صفرٌ إن أُطفئت */
    public static function taxRateFor(int $businessId): float
    {
        if (! Vat::enabled($businessId)) {
            return 0.0;
        }

        return (float) (Setting::where('business_id', $businessId)
            ->where('key', 'vat_rate')->value('value') ?? 5);
    }
}
