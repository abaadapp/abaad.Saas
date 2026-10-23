<?php

namespace App\Support;

use App\Models\Product;

/**
 * وصفُ الصنف — بلغة الصفحة، وحيث اختار صاحبُه أن يُعرض.
 *
 * وصفان: `description` بالعربيّة و`description_en` بالإنجليزيّة، ومفتاحان:
 * `description_web_enabled` للموقع و`description_pos_enabled` للكاشير.
 *
 * ═══ والمفتاحان غيرُ قنوات البيع ═══
 *
 * `published` و`pos_enabled` أين يُشترى الصنف، وهذان أين يُقرأ وصفُه. باقةٌ
 * تُباع في القناتين قد يُعرض وصفُها للزبون في الموقع وحده — وإخفاءُ الوصف لا
 * يُخفي الصنف. وما لا يُباع في قناةٍ لا يظهر وصفُه فيها، والمفتاحُ يبقى محفوظًا
 * لمن يعيد فتحها.
 *
 * ═══ واللغة ═══
 *
 * الصفحةُ الإنجليزيّة تعرض الوصفَ الإنجليزيّ، وبلا وصفٍ إنجليزيٍّ تعرض العربيّ:
 * أوصافُ ما قبل العمود كلُّها عربيّة، وصفحةٌ بلا وصفٍ أسوأ من وصفٍ بلغة المحلّ.
 * والعربيّةُ تعرض العربيّ وحده — كاسم الصنف (`ProductName`). ولا ترجمةَ
 * مخترَعة في الاتّجاهين.
 */
final class ProductDescription
{
    public static function display(?string $ar, ?string $en, string $locale): string
    {
        $en = trim((string) $en);

        if ($locale === 'en' && $en !== '') {
            return $en;
        }

        return trim((string) $ar);
    }

    /** ما يُعرض للزبون في الموقع — فراغٌ حين أُطفئ */
    public static function online(Product $p, string $locale): string
    {
        if (! ($p->description_web_enabled ?? true)) {
            return '';
        }

        return self::display($p->description, $p->description_en, $locale);
    }

    /** ما يُعرض للكاشير — `null` حين أُطفئ، أو لا يُباع الصنفُ في الصندوق، أو لا وصف */
    public static function atPos(Product $p, string $locale): ?string
    {
        if (! ($p->description_pos_enabled ?? false) || ! $p->sellsAtPos()) {
            return null;
        }

        $text = self::display($p->description, $p->description_en, $locale);

        return $text === '' ? null : $text;
    }
}
