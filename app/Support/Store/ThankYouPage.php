<?php

namespace App\Support\Store;

use App\Support\MarketingSettings;

/**
 * نصوصُ صفحة الشكر — ما كتبه التاجر بلغة الصفحة، أو نصُّ النظام بها.
 *
 * ═══ ما يملكه التاجر منها ═══
 *
 * أربعةُ نصوص لكلّ لغة: العنوان، والرسالة تحته، وزرُّ الإيصال، وزرُّ
 * متابعة التسوّق. وما سواها — رقمُ الطلب وأصنافُه ومبالغُه وطريقةُ دفعه —
 * يكتبه النظام من الطلب نفسِه، فلا نصَّ يُكتب في الإعدادات يحلّ محلّ رقم.
 *
 * ═══ ولا تقع لغةٌ على أخرى ═══
 *
 * الصفحةُ الإنجليزيّة تقرأ حقولَ `_en` وحدَها، وفارغُها يأخذ نصَّ النظام
 * الإنجليزيّ — لا ما كتبه التاجر بالعربيّة. وزبونٌ إنجليزيٌّ يقرأ عنوانًا
 * عربيًّا فوق صفحةٍ إنجليزيّة يظنّ الصفحةَ معطوبة.
 *
 * والنصُّ نصٌّ: يُطبع مُهرَّبًا (`{{ }}`)، ولا وسمَ يُرسم منه.
 */
final class ThankYouPage
{
    /** الحقلُ ومفتاحُه في `RibbonTexts` — نصُّ النظام حين لا يكتب التاجر شيئًا */
    public const FIELDS = [
        'title' => 'thanks',
        'message' => 'thanksMsg',
        'receipt' => 'viewReceipt',
        'continue' => 'continueShopping',
    ];

    /** مفتاحُ الحفظ لحقلٍ بلغة — `store_thanks_title` و`store_thanks_title_en` */
    public static function key(string $field, string $lang): string
    {
        return 'store_thanks_'.$field.($lang === 'en' ? '_en' : '');
    }

    /**
     * النصوصُ الأربعة بلغة الصفحة.
     *
     * @return array{title: string, message: string, receipt: string, continue: string}
     */
    public static function texts(int $businessId, string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $site = MarketingSettings::group($businessId, 'website');
        $system = RibbonTexts::for($lang);

        $out = [];

        foreach (self::FIELDS as $field => $fallback) {
            $mine = trim((string) ($site[self::key($field, $lang)] ?? ''));
            $out[$field] = $mine !== '' ? $mine : (string) $system[$fallback];
        }

        return $out;
    }
}
