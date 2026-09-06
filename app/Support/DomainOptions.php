<?php

namespace App\Support;

use App\Models\Setting;

/**
 * لاحقةُ النطاق الفرعيّ لأبعاد — وبناءُ العنوان الكامل منها.
 *
 * كان هذا الملفّ يصف «الطرق الثلاث إلى عنوان»: نطاقٌ يملكه التاجر، ونطاقٌ
 * فرعيٌّ يحجزه، ونطاقٌ **تشتريه أبعاد وتجهّزه**. والثالث لم يُبنَ قطّ —
 * جدولُ طلباته وشاشتاه بقيت في فرعٍ لم يُدمج. والشاشةُ التي شُحنت فعلًا
 * تقول للتاجر عكسَه: «أبعاد لا يبيع النطاقات ولا يسجّلها».
 *
 * فرُفع منه ما يصف ذلك الطريق: ثوابتُه وأسعارُه وفحصُ تفرّد اسمه. وبقي ما
 * يُقرأ فعلًا — اللاحقةُ وبناءُ المضيف منها.
 *
 * ولا تُعاد الطرقُ هنا: `Storefront::PATHS` هي قائمةُ الطرق الحيّة، وتقرأ
 * `site_path` الذي تكتبه شاشةُ الإعدادات. وكانت هنا قائمةٌ ثانية تسمّي
 * الطريقَ نفسه باسمٍ آخر (`subdomain` مقابل `sub`) وتقرأ مفتاحًا آخر —
 * وقائمتان لسؤالٍ واحد تفترقان يومًا.
 */
class DomainOptions
{
    /** لاحقةُ نطاقات أبعاد الفرعية حين لا تضبطها المنصّة */
    public const DEFAULT_SUFFIX = 'abaadapp.om';

    /** مفتاحُ اللاحقة في إعدادات المنصّة */
    public const SUFFIX_KEY = 'domain_subdomain_suffix';

    /** اللاحقة كما ضبطتها المنصّة — أو الافتراضية */
    public static function suffix(): string
    {
        return self::suffixFrom([
            self::SUFFIX_KEY => Setting::whereNull('business_id')->where('key', self::SUFFIX_KEY)->value('value'),
        ]);
    }

    /** @param  array<string, string|null>  $saved */
    private static function suffixFrom(array $saved): string
    {
        $value = trim((string) ($saved[self::SUFFIX_KEY] ?? ''));

        return $value !== '' ? $value : self::DEFAULT_SUFFIX;
    }

    /** العنوان الكامل للنطاق الفرعي — الاسم واللاحقة */
    public static function host(string $label): string
    {
        return $label.'.'.self::suffix();
    }
}
