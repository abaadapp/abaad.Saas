<?php

namespace App\Support;

/**
 * توليد محتوى رمز QR للفوترة الإلكترونية بصيغة TLV ثم Base64
 * (المعيار الخليجي المعتمد — ZATCA — والمتوقّع تبنّيه في سلطنة عُمان).
 *
 * الحقول الخمسة الإلزامية:
 *   1) اسم البائع  2) الرقم الضريبي  3) الطابع الزمني (ISO-8601)
 *   4) إجمالي الفاتورة شامل الضريبة  5) قيمة ضريبة القيمة المضافة
 */
class EInvoice
{
    public static function qrPayload(string $seller, string $vatNumber, string $timestamp, string $total, string $vat): string
    {
        $tlv = self::tag(1, $seller)
            . self::tag(2, $vatNumber)
            . self::tag(3, $timestamp)
            . self::tag(4, $total)
            . self::tag(5, $vat);

        return base64_encode($tlv);
    }

    /**
     * يبني حقل TLV واحد: [رقم الوسم][الطول بالبايت][القيمة]
     *
     * والطول بايتٌ واحد، فأقصى قيمةٍ ٢٥٥ بايتًا. والحرف العربي بايتان: اسم
     * متجرٍ يتجاوز ١٢٧ حرفًا كان يجعل chr تلتفّ على ٢٥٦ فتُكتب صفرًا،
     * فينهار الوسم وما بعده — ويُطبع مربّعٌ يبدو سليمًا وهو خردة، ولا يشتكي
     * أحد. وmb_strcut يقصّ عند حدّ بايت مع احترام حدود الحرف، فلا يخرج نصف
     * حرفٍ يكسر ترميز القارئ.
     */
    private static function tag(int $tag, string $value): string
    {
        $value = mb_strcut($value, 0, 255, 'UTF-8');

        return chr($tag) . chr(strlen($value)) . $value;
    }

    /**
     * يبني محتوى QR مباشرة من طلب (Order) وإعدادات الضريبة والنشاط.
     *
     * وبلا رقمٍ ضريبي لا رمز: الوسم الثاني إلزاميّ في المعيار، ورمزٌ حقلُه
     * الضريبي فارغ باطلٌ بالتأكيد — وأسوأ من غيابه، لأن تحته سطرًا يقول
     * «رمز الفوترة الإلكترونية» فيظنّ التاجر نفسه ممتثلًا. والورقة نفسها لا
     * تقول «فاتورة ضريبية» بلا رقم، فكان الشرط قائمًا للعنوان ومفقودًا
     * للرمز. والقالبان يُخفيانه حين يعود فارغًا.
     */
    public static function forOrder($order, array $vat, array $business): string
    {
        if (trim((string) ($vat['number'] ?? '')) === '') {
            return '';
        }

        $timestamp = $order->ordered_at
            ? $order->ordered_at->toIso8601String()
            : now()->toIso8601String();

        return self::qrPayload(
            $business['name'] ?? 'Abad POS',
            (string) ($vat['number'] ?? ''),
            $timestamp,
            number_format((float) $order->total, 3, '.', ''),
            number_format((float) $order->tax, 3, '.', ''),
        );
    }
}
