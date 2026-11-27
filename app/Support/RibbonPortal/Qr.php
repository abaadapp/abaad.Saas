<?php

namespace App\Support\RibbonPortal;

use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;

/** رمزُ QR متّجهًا — بالمكتبة التي تطبع رموزَ الفواتير (`mpdf/qrcode`)، بلا خدمةٍ خارجيّة */
final class Qr
{
    public static function svg(string $text, int $size = 240): string
    {
        $qr = new QrCode($text, 'M');
        $qr->disableBorder();

        // بلا ترويسة XML: يُضمَّن في صفحة. و`viewBox` ليُصغَّر كاملًا — بلاه يُقصّ طرفُه فلا يُمسح
        $svg = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', (new Svg)->output($qr, $size, '#ffffff', '#111111')));

        return (string) preg_replace('/^<svg /', '<svg viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges" ', $svg, 1);
    }

    public static function dataUri(string $text, int $size = 240): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $size));
    }
}
