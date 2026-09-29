<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductImages;

/**
 * لوحتا «تسوّق حسب الفئة» و«وصل حديثًا» في محرّر الواجهة — لمتاجرَ بعينها.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * صاحبُ المحلّ يفتح «تسوّق حسب الفئة» في محرّر صفحته فلا يجد إلّا سطرًا:
 * «محتوى هذا القسم يُكتب في: الأصناف والفئات». فلا يعرف أيُّ فئاته تظهر ولا
 * لماذا غابت أخرى، ولا أيُّ أصنافه هي «وصل حديثًا» — فيخرج من المحرّر
 * ليبحث، ويعود ولا يدري أأصاب.
 *
 * فصار الصفّان يعرضان الحالَ نفسَها التي تبنيها الواجهة: الفئاتُ بعدد ما
 * يُعرض في كلٍّ منها، وأحدثُ أربعة أصنافٍ معروضة. عرضٌ لا تحرير — القاعدةُ
 * في `Store\RibbonController` ولا تُمسّ من هنا.
 *
 * ═══ ولمَ قائمةٌ لا واجهة ═══
 *
 * قرارُ المالك: لمتجر سعود (RIBBON، `businesses.id = 5`) وحده. ولبسُ واجهة
 * RIBBON لا يكفي ليُفتح لمتجرٍ آخر، ولا قائمةُ Paymob تُستعار له: قراران
 * منفصلان يُرفع أحدهما ولا يُرفع الآخر. انظر `storefront.ribbon_catalog_editor_businesses`.
 *
 * ═══ والقاعدةُ مكتوبةٌ هنا ثانيةً — ومحروسةٌ بالمقارنة ═══
 *
 * «المعروض» = مفعَّلٌ ومنشور، و«وصل حديثًا» = أحدثُ أربعةٍ منه بالمعرّف.
 * وهي في `RibbonController` خاصّةٌ لا تُنادى من خارجه، ولا يُعدَّل هو في هذا
 * العمل. فحارسُها يطلب الواجهةَ نفسَها ويقارن — فإن افترقتا سقط.
 */
final class CatalogTools
{
    /** كم صنفًا يعرض «وصل حديثًا» — عددُ الواجهة لا عددٌ يُختار */
    public const NEW_ARRIVALS = 4;

    /** أمفتوحةٌ لهذا المتجر؟ — من القائمة وحدها */
    public static function allowed(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_catalog_editor_businesses', []));

        return in_array($businessId, $list, true);
    }

    /**
     * حمولةُ اللوحتين — و`null` لمن لم تُفتح له فلا يُرسَل شيء.
     *
     * واستعلامان للفئات (الصفوف، ثمّ العدُّ مجمَّعًا) واثنان للأحدث (الأصناف
     * وفئاتُها بضمّة) — مهما كثرت الفئات. ولا سعرَ ولا مخزونَ ولا كلفة:
     * اللوحتان لا تقرأ شيئًا منها.
     *
     * @return array{categories: list<array{id: int, name: string, name_en: ?string, shown_count: int}>, new_arrivals: list<array{id: int, name: string, image: ?string, category: ?string}>}|null
     */
    public static function for(int $businessId): ?array
    {
        if (! self::allowed($businessId)) {
            return null;
        }

        $counts = self::shown($businessId)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) as n')
            ->pluck('n', 'category_id');

        $categories = Category::where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name', 'name_en'])
            ->map(fn (Category $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'name_en' => filled($c->name_en) ? (string) $c->name_en : null,
                'shown_count' => (int) ($counts[$c->id] ?? 0),
            ])->all();

        $newArrivals = self::shown($businessId)
            ->with('category:id,name')
            ->orderByDesc('id')
            ->limit(self::NEW_ARRIVALS)
            ->get(['id', 'name', 'image', 'category_id'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                // الخام لا المقروء: الصورةُ البديلة من الإنترنت ليست بضاعتَه
                'image' => ProductImages::hasRealMain($p) ? $p->image : null,
                'category' => $p->category?->name,
            ])->all();

        return ['categories' => $categories, 'new_arrivals' => $newArrivals];
    }

    /** ما تعرضه الواجهة — قاعدةُ `RibbonController::shown` نفسُها */
    private static function shown(int $businessId)
    {
        return Product::where('business_id', $businessId)
            ->where('active', true)
            ->where('published', true);
    }
}
