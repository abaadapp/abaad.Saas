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

    /** يبني حقل TLV واحد: [رقم الوسم][الطول بالبايت][القيمة] */
    private static function tag(int $tag, string $value): string
    {
        $bytes = strlen($value); // strlen بالبايت — صحيح للـ UTF-8

        return chr($tag) . chr($bytes) . $value;
    }

    /** يبني محتوى QR مباشرة من طلب (Order) وإعدادات الضريبة والنشاط */
    public static function forOrder($order, array $vat, array $business): string
    {
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
