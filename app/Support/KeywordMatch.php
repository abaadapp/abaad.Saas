<?php

namespace App\Support;

/**
 * ‏‏أكتب الزبونُ الكلمةَ التي كتبها التاجر؟ — بعد أن يُطبَّع النصّان معًا.
 *
 * ═══ ولمَ التطبيع ═══
 *
 * التاجر يكتب «السعر»، والزبون «السّعر؟» أو «سعره» أو «PRICE». ومقارنةُ الحروف كما
 * هي تفوّت أكثرها، فيظنّ التاجر أنّ أتمتته لا تعمل. فيُحذف التشكيل والتطويل والعلامات، وتُوحَّد
 * الألفات والياء والتاء المربوطة والهمزات، والأرقامُ الهنديّة والفارسيّة لاتينيّة، والحروفُ
 * اللاتينيّة صغيرة. و«ال» في أوّل الكلمة تُنزع من النصّين معًا، فـ«السعر» و«سعر» واحد.
 *
 * ═══ والمطابقتان ═══
 *
 *  - `contains`: الكلمةُ في أيّ موضعٍ من الرسالة («كم سعر الباقة» فيها «سعر»).
 *  - `exact`: الرسالةُ كلُّها هي الكلمة لا غير («سعر» وحدها).
 */
class KeywordMatch
{
    public const CONTAINS = 'contains';

    public const EXACT = 'exact';

    public const MODES = [self::CONTAINS, self::EXACT];

    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '', 'ک' => 'ك', 'ی' => 'ي']);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // ‏‏و«ال» في أوّل الكلمة — ما بقي بعدها حرفان على الأقلّ، فلا تُمسّ «الا» ولا «الي»
        $words = array_map(fn (string $w) => mb_strlen($w) >= 4 && str_starts_with($w, 'ال') ? mb_substr($w, 2) : $w, $words);

        return implode(' ', $words);
    }

    /**
     * أوّلُ كلمةٍ من `$keywords` في `$text` — أو `null`.
     *
     * والأطولُ أوّلًا: «حالة الطلب» قبل «الطلب»، فيُعرف أيُّ الكلمتين أصاب.
     *
     * @param  list<string>  $keywords
     */
    public static function first(string $text, array $keywords, string $mode = self::CONTAINS): ?string
    {
        $haystack = self::normalize($text);

        if ($haystack === '') {
            return null;
        }

        $sorted = $keywords;
        usort($sorted, fn ($a, $b) => mb_strlen(self::normalize((string) $b)) <=> mb_strlen(self::normalize((string) $a)));

        foreach ($sorted as $keyword) {
            $needle = self::normalize((string) $keyword);

            if ($needle === '') {
                continue;
            }

            $hit = $mode === self::EXACT
                ? $haystack === $needle
                : str_contains($haystack, $needle);

            if ($hit) {
                return (string) $keyword;
            }
        }

        return null;
    }
}
