<?php

namespace App\Support;

/**
 * اسمُ الصنف كما يُعرض في الموقع — بلغة الصفحة التي تعرضه.
 *
 * صنفٌ واحد باسمين: `name` بالعربيّة و`name_en` بالإنجليزيّة. لا صفَّ لكلّ
 * لغة — المعرّفُ والمخزونُ والسعرُ واحد، والاسمُ وحده يتبدّل.
 *
 * ═══ ولمَ لا `Lexicon` هنا كما في `CategoryName` ═══
 *
 * اسمُ القسم لفظٌ عامّ («هدايا»، «عروض») يعرفه المعجمُ يقينًا. واسمُ الصنف
 * يكتبه التاجر لبضاعته («باقة الربيع الوردية»)، وترجمتُه اختراعٌ عليه. فما
 * لم يكتب له اسمًا إنجليزيًّا يُعرض باسمه كما كتبه.
 *
 * ═══ والبحثُ لا يمرّ من هنا ═══
 *
 * الزبونُ يبحث بأيّ الاسمين شاء في أيّ لغة — انظر `searchable`.
 */
final class ProductName
{
    public static function display(?string $name, ?string $nameEn, string $locale): string
    {
        if ($locale === 'en' && filled($nameEn)) {
            return (string) $nameEn;
        }

        return (string) $name;
    }

    /**
     * الاسمُ الآخر — ما لا يُعرض بهذه اللغة ويُبحث به.
     *
     * `null` حين لا اسمَ آخر، أو حين يطابق المعروض: لا يُرسَل الاسمُ مرّتين.
     */
    public static function other(?string $name, ?string $nameEn, string $locale): ?string
    {
        $shown = self::display($name, $nameEn, $locale);
        $other = $shown === (string) $name ? (string) $nameEn : (string) $name;

        return trim($other) !== '' && $other !== $shown ? $other : null;
    }

    /** نصُّ البحث — الاسمان معًا، أيًّا كانت لغةُ الصفحة */
    public static function searchable(?string $name, ?string $nameEn): string
    {
        return trim((string) $name.' '.(string) $nameEn);
    }
}
