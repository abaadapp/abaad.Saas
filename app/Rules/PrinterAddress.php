<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * عنوانُ طابعة الشبكة — عنوانٌ على الشبكة المحليّة لا غير.
 *
 * ═══ ولمَ يُحرس عند الحفظ ═══
 *
 * تطبيقُ أبعاد يرفض عند الطباعة كلَّ عنوانٍ ليس محليًّا (`INVALID_PRINTER_ADDRESS`
 * — انظر `mobile/PROTOCOL.md` §4). وكانت الإعداداتُ تقبل أيَّ نصّ: يكتب الموظفُ
 * `192.168.1.50:9100` أو `http://192.168.1.50` فيُقال «تم الحفظ»، ثمّ يقف الإيصالُ
 * عند أوّل بيعٍ برسالةٍ لا يربطها أحدٌ بما كُتب قبل أيّام. فيحذف الطابعةَ ويضيفها،
 * ويحذف الجهازَ ويفعّله، والعلّةُ في حرفين.
 *
 * والقاعدةُ هنا نسخةُ قاعدة التطبيقين حرفًا حرفًا، ومتّجهاتُها واحدة
 * (`mobile/shared/fixtures/printing-vectors.json` → `address`) — حارسُها
 * `APrinterAddressIsRefusedWhereTheAppWouldRefuseItTest`. فلا يُقبل هنا ما
 * يردّه التطبيق، ولا يُردّ ما يقبله.
 */
class PrinterAddress implements ValidationRule
{
    /** أيقبله تطبيقُ أبعاد عنوانًا لطابعة؟ — بعد قصّ المسافات */
    public static function allows(string $address): bool
    {
        $address = trim($address);

        return self::privateIpv4($address) || self::privateIpv6($address) || self::mdns($address);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail(__('عنوان الطابعة غير صالح — اكتب عنوانها على الشبكة المحلية وحده، مثل 192.168.1.50، والمنفذ في حقله.'));
        }
    }

    /** أربعةُ أجزاءٍ عشريّة بلا أصفارٍ بادئة، في 10/8 أو 172.16/12 أو 192.168/16 أو 169.254/16 */
    private static function privateIpv4(string $address): bool
    {
        if (! preg_match('/^(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})$/', $address, $m)) {
            return false;
        }

        [$a, $b] = [(int) $m[1], (int) $m[2]];

        foreach (array_slice($m, 1) as $part) {
            if ((int) $part > 255) {
                return false;
            }
        }

        return $a === 10
            || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168)
            || ($a === 169 && $b === 254);
    }

    /** ULA ‏(fc00::/7) أو link-local ‏(fe80::/10) */
    private static function privateIpv6(string $address): bool
    {
        if (! str_contains($address, ':') || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $bytes = inet_pton($address);

        if ($bytes === false) {
            return false;
        }

        $first = ord($bytes[0]);
        $second = ord($bytes[1]);

        return ($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80);
    }

    /** اسمُ mDNS ينتهي بـ`.local` — تسمياتٌ من [a-z0-9-] لا تبدأ ولا تنتهي بشرطة */
    private static function mdns(string $address): bool
    {
        $name = strtolower($address);

        if (! str_ends_with($name, '.local')) {
            return false;
        }

        $labels = explode('.', substr($name, 0, -strlen('.local')));

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
