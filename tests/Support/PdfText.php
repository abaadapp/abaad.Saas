<?php

namespace Tests\Support;

/**
 * نصُّ صفحات PDF من mpdf — مقاسُ كلّ صفحةٍ ونصُّها المكتوب، بلا مكتبة.
 *
 * mpdf يكتب السلاسلَ الحرفيّة بترميز UTF-16BE بخريطة ToUnicode هويّة،
 * فيُفكّ ذلك هنا.
 *
 * والعربيّةُ المُشكَّلة (OTL) تُكتب رموزَ حروفٍ لا نصًّا — فما يُقرأ هنا
 * بثقةٍ هو اللاتينيّ والأرقام. ولذلك تُقرأ أقسامُ الورقة من نسختها
 * الإنجليزيّة حين يُسأل الـPDF نفسُه عن ترتيبها. لكنّ الرموزَ تقول شكلَ
 * كلّ حرفٍ في موضعه — وبه يُعرف السطرُ الذي قُطعت فيه كلمة (`wordCuts`).
 */
final class PdfText
{
    /**
     * صفحاتُ الـPDF بالترتيب: عرضُها وارتفاعُها بالمليمتر، ونصُّها كما رُسم.
     *
     * mpdf يكتب النصَّ سلاسلَ حرفيّة `( … )` بترميز UTF-16BE، وخريطةُ ToUnicode
     * عنده هويّة (`<0000> <FFFF> <0000>`). وكان يكتب المسافةَ رمزًا خاصًّا
     * (U+E28D) حتى أُصلح خطُّ الورقة (`keep_spaces`) — فيُقرأ مسافةً إن بقي.
     *
     * @return list<array{width: float, height: float, text: string}>
     */
    public static function pages(string $pdf): array
    {
        return array_map(fn (array $page) => [
            'width' => $page['width'],
            'height' => $page['height'],
            'text' => self::text($page['content']),
        ], self::contents($pdf));
    }

    /**
     * أسطرُ النصّ المرسومة بالترتيب: حجمُ خطّها ورموزُها كما كُتبت، بلا تنقية.
     *
     * كلُّ `BT … ET` فيه نصٌّ سطرٌ واحد (أو قطعةٌ منه بخطٍّ آخر)، وحجمُه آخرُ
     * `Tf` قبله: mpdf يكتب الخطَّ في كتلةٍ وحدها ثمّ يكتب بها. والجدولُ الذي
     * صغّره المحرّكُ ليتّسع يُعرف هنا: أسطرُه بحجمٍ لا يكتبه القالب.
     *
     * وموضعُه (`x`, `y` بالنقطة من أسفل الصفحة) من `Td` في كتلته: خلايا الصفّ
     * الواحد على خطٍّ واحد تتشارك `y`.
     *
     * @return list<array{size: float, text: string, x: float, y: float}>
     */
    public static function lines(string $pdf): array
    {
        $lines = [];
        foreach (self::contents($pdf) as $page) {
            $size = 0.0;
            $line = null;
            $at = [0.0, 0.0];
            preg_match_all('#\((?:\\\\.|[^\\\\)])*\)|\bBT\b|\bET\b|([\d.]+)\s+Tf\b|(-?[\d.]+)\s+(-?[\d.]+)\s+Td\b#s', $page['content'], $tokens, PREG_SET_ORDER);
            foreach ($tokens as $token) {
                if ($token[0] === 'BT') {
                    $line = '';
                } elseif ($token[0] === 'ET') {
                    if ($line !== null && $line !== '') {
                        $lines[] = ['size' => $size, 'text' => $line, 'x' => $at[0], 'y' => $at[1]];
                    }
                    $line = null;
                } elseif (($token[1] ?? '') !== '') {
                    $size = (float) $token[1];
                } elseif (($token[2] ?? '') !== '') {
                    $at = [(float) $token[2], (float) $token[3]];
                } elseif ($line !== null) {
                    $line .= mb_convert_encoding(self::literal(substr($token[0], 1, -1)), 'UTF-8', 'UTF-16BE');
                }
            }
        }

        return $lines;
    }

    /**
     * أسطرٌ عربيّةٌ قُطعت فيها كلمة — والصوابُ ألّا يُرجع شيئًا.
     *
     * ═══ وكيف يُعرف من الـPDF ═══
     *
     * mpdf يُشكّل الجملةَ قبل أن يقطّعها أسطرًا، فيكتب كلَّ حرفٍ بشكل موضعه
     * من كلمته: أوّلٌ ووسطٌ وآخرٌ ومنفرد (ﺑ ﺒ ﺐ ب). ويكتب السطرَ بترتيب
     * العرض من اليسار. فأوّلُ حرفٍ عربيٍّ من يسار السطر آخرُ حرفٍ في كلمته،
     * وآخرُه من اليمين أوّلُها:
     *
     *   - في اليسار حرفٌ «أوّل» أو «وسط» ⇐ كلمةٌ لم تتمّ عند آخر السطر
     *     («تغلي» من «تغليف»).
     *   - وفي اليمين حرفٌ «آخر» أو «وسط» ⇐ بقيّةُ كلمةٍ في أوّل السطر («ف»).
     *
     * وما حول الطرف من مسافةٍ وحركةٍ وترقيمٍ وأرقام يُتخطّى. ورمزٌ لا يُصنَّف
     * (لاتينيٌّ، أو شكلٌ بديلٌ في المنطقة الخاصّة، أو رباطٌ) يوقف السؤالَ عن
     * ذلك الطرف بلا حكم — فلا يُتّهم سطرٌ سليم.
     *
     * @return list<string> رموزُ كلِّ سطرٍ مقطوع بالست عشريّ — لرسالة الفشل
     */
    public static function wordCuts(string $pdf): array
    {
        $cuts = [];
        foreach (self::lines($pdf) as $line) {
            $chars = mb_str_split($line['text']);
            $left = self::edgeForm($chars);
            $right = self::edgeForm(array_reverse($chars));

            if (in_array($left, ['initial', 'medial'], true) || in_array($right, ['final', 'medial'], true)) {
                $cuts[] = implode(' ', array_map(fn (string $c) => sprintf('%04X', mb_ord($c)), $chars));
            }
        }

        return $cuts;
    }

    /** شكلُ أوّل حرفٍ عربيٍّ من هذا الطرف — أو null إن سبقه ما لا يُصنَّف */
    private static function edgeForm(array $chars): ?string
    {
        foreach ($chars as $char) {
            $code = mb_ord($char);
            $mark = ($code >= 0x064B && $code <= 0x065F) || $code === 0x0670;
            if ($code === 0x20 || $mark || preg_match('/^[\p{P}\p{S}\p{N}]$/u', $char)) {
                continue;
            }

            return self::arabicForm($code);
        }

        return null;
    }

    /**
     * شكلُ الحرف العربيّ في موضعه — من «أشكال العرض العربيّة-ب» (U+FE80–U+FEFC).
     *
     * الكتلةُ مرتّبةٌ حرفًا حرفًا: لكلّ حرفٍ يتّصل من الجهتين أربعةُ أشكال
     * (منفردٌ، آخرٌ، أوّلٌ، وسط)، ولكلّ حرفٍ لا يتّصل بما بعده شكلان (منفردٌ،
     * آخر)، وللهمزة شكلٌ واحد. والحرفُ برمزه الأصليّ (U+0621–U+064A) هو ما
     * يكتبه الخطّ للمنفرد.
     */
    private static function arabicForm(int $code): ?string
    {
        static $forms = null;

        if ($forms === null) {
            $forms = [];
            $at = 0xFE80;
            // ء آ أ ؤ إ ئ ا ب ة ت ث ج ح خ د ذ ر ز س ش ص ض ط ظ ع غ ف ق ك ل م ن ه و ى ي لآ لأ لإ لا
            foreach ([1, 2, 2, 2, 2, 4, 2, 4, 2, 4, 4, 4, 4, 4, 2, 2, 2, 2, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 2, 2, 4, 2, 2, 2, 2] as $count) {
                foreach (array_slice(['isolated', 'final', 'initial', 'medial'], 0, $count) as $k => $form) {
                    $forms[$at + $k] = $form;
                }
                $at += $count;
            }
        }

        return $code >= 0x0621 && $code <= 0x064A ? 'isolated' : ($forms[$code] ?? null);
    }

    /**
     * صفحاتُ الـPDF بترتيبها: مقاسُها وتيّارُ رسمها مفكوكًا.
     *
     * @return list<array{width: float, height: float, content: string}>
     */
    private static function contents(string $pdf): array
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
                'content' => self::stream($byId[(int) $contents[1]] ?? ''),
            ];
        }

        return $pages;
    }

    /** سلسلةٌ حرفيّةٌ بلا قوسيها — تُفكّ هروبُها كما يكتبها mpdf */
    private static function literal(string $raw): string
    {
        $bytes = '';
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $c = $raw[$i];
            if ($c !== '\\') {
                $bytes .= $c;

                continue;
            }
            $next = $raw[++$i] ?? '';
            if (ctype_digit($next)) {
                $octal = $next;
                while (strlen($octal) < 3 && ctype_digit($raw[$i + 1] ?? '')) {
                    $octal .= $raw[++$i];
                }
                $bytes .= chr(octdec($octal) & 0xFF);
            } else {
                $bytes .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$next] ?? $next;
            }
        }

        return $bytes;
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
