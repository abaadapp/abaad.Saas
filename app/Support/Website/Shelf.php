<?php

namespace App\Support\Website;

use App\Models\Product;
use App\Models\Setting;

/**
 * ما على الرفّ فعلًا — كما تقرؤه نقطة البيع، لا كما يُظنّ.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * موقعُ التاجر كان يعرض كلَّ صنفٍ `active` ويضع تحته «اطلب عبر واتساب». وذلك
 * الزرُّ **ادّعاءُ توفّر**: زبونٌ يضغطه على صنفٍ نفد يكتب رسالةً ويُجاب
 * «انتهى». وهو أسوأ من غياب الصنف، لأنّ الخذلان وقع بعد أن قرّر الشراء.
 *
 * ولم يكن ذلك خطأً في المخزون: المخزون صحيحٌ في القاعدة. كان الموقع لا يسأله.
 *
 * ═══ والقاعدة ثلاثة شروط، وكلُّها مقروءةٌ من النظام لا مخترعة ═══
 *
 * ١) **متجرٌ يأذن بالبيع تحت الصفر** (`allow_negative_stock`) لا نفادَ عنده:
 *    من أذن لكاشيره أن يبيع ما ليس على الرفّ لا يريد أن يُخفي موقعُه صنفًا.
 *
 * ٢) **وذو الوصفة لا يُحكم عليه بكمّيته**: مخزونُه مكوّناتُه (انظر `Recipe`)،
 *    وكمّيتُه هو تبقى صفرًا أبدًا. والحكمُ عليها يُفرغ الموقع من الباقات كلِّها
 *    — وهي البضاعة نفسُها في محلّ ورود.
 *
 * ٣) **وما عداهما**: `products.quantity` — وهو مجموعُ الشركة الذي يحفظه
 *    `StockLedger::move` مع أرصدة الفروع. والموقع بلا فرع، فالمجموع هو
 *    جوابُه الصحيح: زبونُ الإنترنت لا يشتري من رفِّ فرعٍ بعينه.
 *
 * ولا يُقرأ شيءٌ من هذا في العارض: العارض يرسم ما يصله. والقرار هنا، في
 * موضعٍ واحد تقرؤه صفحةُ الموقع ولوحةُ تشغيله معًا — فلا يقول أحدُهما «نفد»
 * ويقول الآخر «متوفّر» عن الصنف نفسه.
 */
final class Shelf
{
    /**
     * أيبيع هذا المتجر ما ليس عنده؟ — إعدادُ نقطة البيع نفسُه.
     *
     * ويُقرأ من `settings` مباشرةً لا من `Demo::businessSettings`: تلك تقرأ
     * متجرَ الجلسة، وهذه تُنادى من صفحةٍ عامّة لا جلسةَ فيها.
     */
    public static function sellsBelowZero(int $businessId): bool
    {
        return (string) Setting::where('business_id', $businessId)
            ->where('key', 'allow_negative_stock')->value('value') === '1';
    }

    /**
     * أعلى الرفّ من هذه الأصناف شيء؟
     *
     * @param  array<int, int>  $productIds
     * @return array<int, bool> معرّف ← أمتوفّر
     */
    public static function availability(int $businessId, array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($ids === []) {
            return [];
        }

        if (self::sellsBelowZero($businessId)) {
            return array_fill_keys($ids, true);
        }

        $rows = Product::where('business_id', $businessId)->whereIn('id', $ids)
            /*
             * وذو الوصفة يُعرف باستعلامٍ واحد لا باستعلامٍ لكلّ صنف.
             *
             * `withCount` تُضيف عمودًا محسوبًا، فمئةُ صنفٍ تُقرأ في نداءٍ
             * واحد. وصفحةُ الموقع تُبنى عند كلّ زيارة — واستعلامٌ لكلّ بطاقة
             * يجعل متجرًا بأربعين صنفًا أربعين رحلةً إلى القاعدة.
             */
            ->withCount('recipeItems')
            ->get(['id', 'quantity', 'tracks_stock']);

        $out = array_fill_keys($ids, false);

        foreach ($rows as $row) {
            /* وما لا يُعدّ على رفٍّ معروضٌ دائمًا: خدمةٌ لا تنفد */
            $out[(int) $row->id] = ! $row->tracksStock()
                || (int) $row->recipe_items_count > 0
                || (int) $row->quantity > 0;
        }

        return $out;
    }
}
