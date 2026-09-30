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
 * ═══ والقاعدةُ واحدة — ومحروسةٌ بالمقارنة ═══
 *
 * «المعروض» = مفعَّلٌ ومنشور — مكتوبٌ هنا كما في `RibbonController::shown`.
 * و«وصل حديثًا» لا يُكتب هنا: تبنيه `NewArrivals::pick` للواجهة وللمحرّر
 * معًا، تلقائيًّا أو يدويًّا. وحارسُها يطلب الواجهةَ نفسَها ويقارن — فإن
 * افترقتا سقط.
 */
final class CatalogTools
{
    /** كم صنفًا يعرض «وصل حديثًا» — عددُ الواجهة لا عددٌ يُختار */
    public const NEW_ARRIVALS = StorePage::NEW_ARRIVALS;

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
     * ولمن في قائمة الاختيار اليدويّ (`NewArrivals::allowed`) مفتاحان
     * زائدان: الطريقةُ والمعرّفاتُ كما يحرّرها — من المسوّدة إن كانت، كما
     * يقرأ المحرّرُ سائرَ حقوله (`PageEditor::values`). ومن ليس فيها لا
     * يصله منهما شيء.
     *
     * @return array{categories: list<array{id: int, name: string, name_en: ?string, shown_count: int}>, new_arrivals: list<array{id: int, name: string, image: ?string, category: ?string}>, new_arrivals_mode?: string, new_arrival_ids?: list<int>}|null
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

        /*
         * وما يعرضه القسمُ الآن — بقاعدة الواجهة نفسِها (`NewArrivals::pick`)
         * لا بنسخةٍ ثانية منها: تلقائيًّا أو يدويًّا، وبرجوعه إلى الأحدث.
         */
        $shown = self::shown($businessId)->with('category:id,name')->get(['id', 'name', 'image', 'category_id']);

        $newArrivals = NewArrivals::pick($businessId, $shown)
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                // الخام لا المقروء: الصورةُ البديلة من الإنترنت ليست بضاعتَه
                'image' => ProductImages::hasRealMain($p) ? $p->image : null,
                'category' => $p->category?->name,
            ])->all();

        $out = ['categories' => $categories, 'new_arrivals' => $newArrivals];

        if (NewArrivals::allowed($businessId)) {
            $draft = StoreContent::draft($businessId);
            $out['new_arrivals_mode'] = StorePage::mode($draft['store_new_arrivals_mode'] ?? '');
            $out['new_arrival_ids'] = StorePage::ids($draft['store_new_arrivals'] ?? '', StorePage::NEW_ARRIVALS);
        }

        return $out;
    }

    /** ما تعرضه الواجهة — قاعدةُ `RibbonController::shown` نفسُها */
    private static function shown(int $businessId)
    {
        return Product::where('business_id', $businessId)
            ->where('active', true)
            ->where('published', true);
    }
}
