<?php

namespace Tests\Support;

/**
 * نصُّ صفحات PDF من mpdf — مقاسُ كلّ صفحةٍ ونصُّها المكتوب، بلا مكتبة.
 *
 * mpdf يكتب السلاسلَ الحرفيّة بترميز UTF-16BE بخريطة ToUnicode هويّة،
 * ويكتب الفراغَ رمزًا خاصًّا (U+E28D). فيُفكّ ذلك هنا.
 *
 * والعربيّةُ المُشكَّلة (OTL) تُكتب رموزَ حروفٍ لا نصًّا — فما يُقرأ هنا
 * بثقةٍ هو اللاتينيّ والأرقام. ولذلك تُقرأ أقسامُ الورقة من نسختها
 * الإنجليزيّة حين يُسأل الـPDF نفسُه عن ترتيبها.
 */
final class PdfText
{
    /**
     * صفحاتُ الـPDF بالترتيب: عرضُها وارتفاعُها بالمليمتر، ونصُّها كما رُسم.
     *
     * mpdf يكتب النصَّ سلاسلَ حرفيّة `( … )` بترميز UTF-16BE، وخريطةُ ToUnicode
     * عنده هويّة (`<0000> <FFFF> <0000>`). والمسافةُ في خطّه رمزٌ خاصّ (U+E28D).
     *
     * @return list<array{width: float, height: float, text: string}>
     */
    public static function pages(string $pdf): array
    {
        preg_match_all('#(\d+) 0 obj\s*(.*?)\s*endobj#s', $pdf, $objects, PREG_SET_ORDER);
        $byId = [];
        foreach ($objects as $object) {
            $byId[(int) $object[1]] = $object[2];
        }

        $pages = [];
        foreach ($byId as $body) {
            if (! preg_match('#/Type\s*/Page\b(?!s)#', $body)) {
                continue;
            }
            preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $body, $box);
            preg_match('#/Contents\s+(\d+)\s+0\s+R#', $body, $contents);
            $pages[] = [
                'width' => round(((float) $box[3] - (float) $box[1]) / 72 * 25.4, 1),
                'height' => round(((float) $box[4] - (float) $box[2]) / 72 * 25.4, 1),
                'text' => self::text(self::stream($byId[(int) $contents[1]] ?? '')),
            ];
        }

        return $pages;
    }

    private static function stream(string $object): string
    {
        if (! preg_match('#^(.*?)stream\r?\n(.*)\r?\nendstream#s', $object, $m)) {
            return '';
        }

        return str_contains($m[1], '/FlateDecode') ? (string) @gzuncompress($m[2]) : $m[2];
    }

    /**
     * السلاسلُ الحرفيّة كلُّها بالترتيب، بينها مسافةٌ واحدة: خلايا الصفّ الواحد
     * نصوصٌ منفصلة (`BT … ET`)، فرأسُ الجدول يُقرأ «Item Price Qty Total».
     */
    private static function text(string $content): string
    {
        $out = '';
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $c = $content[$i];
            if ($c === 'E' && substr($content, $i, 2) === 'ET') {
                $out .= "\n";

                continue;
            }
            if ($c !== '(') {
                continue;
            }
            $bytes = '';
            $depth = 1;
            for ($i++; $i < $length && $depth > 0; $i++) {
                $c = $content[$i];
                if ($c === '\\') {
                    $next = $content[++$i] ?? '';
                    if (ctype_digit($next)) {
                        $octal = $next;
                        while (strlen($octal) < 3 && ctype_digit($content[$i + 1] ?? '')) {
                            $octal .= $content[++$i];
                        }
                        $bytes .= chr(octdec($octal) & 0xFF);
                    } else {
                        $bytes .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$next] ?? $next;
                    }

                    continue;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    break;
                }
                $bytes .= $c;
            }
            $out .= mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{E28D}", ' ', $out)));
    }
}
