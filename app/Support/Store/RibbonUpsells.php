<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;
use App\Support\Website\Shelf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «أضف مع طلبك» في صفحة صنف RIBBON — ما يُعرض تحت الكمّيّة وقبل زرّ السلّة.
 *
 * ═══ ولمَ أصنافٌ عاديّة لا إضافاتُ الصندوق ═══
 *
 * إضافاتُ الصندوق (`ProductAddons`) تُحسب داخل بند الصنف في نقطة البيع، ولا
 * يعرفها إتمامُ الموقع: `WebCheckout` يقبل معرّفَ صنفٍ ومقاسًا وكمّيّة، ويكتب
 * `addons_total = 0`. فالمعروضُ هنا أصنافٌ منشورةٌ من قسمٍ في المتجر نفسه،
 * تدخل السلّةَ بنودًا عاديّة، ويسعّرها الإتمامُ من القاعدة ويتحقّق من رفّها
 * كأيّ صنف. والصفحةُ تركّب السلّة وحسب — لا ثمنَ يُصدَّق منها.
 *
 * ═══ ولمن ═══
 *
 * لمن في `storefront.ribbon_product_upsells` وحده — لا لكلّ من يلبس RIBBON.
 * ومن ليس فيها لا يُسأل عنه في القاعدة شيء.
 */
final class RibbonUpsells
{
    /**
     * ما كُتب لهذا المتجر في القائمة — أو `null` إن لم يُكتب له شيء.
     *
     * @return array{category: string, limit: int}|null
     */
    public static function settings(int $businessId): ?array
    {
        $row = ((array) config('storefront.ribbon_product_upsells', []))[$businessId] ?? null;

        if (! is_array($row)) {
            return null;
        }

        $category = (string) ($row['category'] ?? '');
        $limit = (int) ($row['limit'] ?? 0);

        return $category === '' || $limit <= 0 ? null : ['category' => $category, 'limit' => $limit];
    }

    /**
     * أصنافُ «أضف مع طلبك» لصفحة `$current`.
     *
     * و`$shown` قاعدةُ الواجهة لما يُعرض (المتجرُ نفسُه، `active`، `published`)
     * تُمرَّر من المتحكّم ولا تُكتب هنا ثانيةً — فلا تفترق القاعدتان. وفوقها:
     * قسمُ الإضافات **في المتجر نفسه** بالاسم حرفًا بحرف، وما على الرفّ فعلًا
     * (`Shelf`)، بترتيب الرفّ (الاسم ثمّ المعرّف).
     *
     * وصفحةُ صنفٍ من قسم الإضافات نفسِه لا يُعرض فيها القسم: إضافةٌ تقترح
     * إضافة. وبه يخرج الصنفُ المفتوح من قائمته — فلا شرطَ ثانيًا له لا يُبلَغ.
     *
     * @return Collection<int, Product>
     */
    public static function for(int $businessId, Product $current, Builder $shown): Collection
    {
        $settings = self::settings($businessId);

        if ($settings === null) {
            return collect();
        }

        $categories = Category::where('business_id', $businessId)
            ->where('name', $settings['category'])
            ->pluck('id')->map(fn ($id) => (int) $id);

        if ($categories->isEmpty() || $categories->contains((int) $current->category_id)) {
            return collect();
        }

        $candidates = $shown
            ->whereIn('category_id', $categories->all())
            ->with(['category:id,name,name_en', 'variants' => fn ($q) => $q->where('active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')->orderBy('id')
            ->get();

        $available = Shelf::availability($businessId, $candidates->pluck('id')->all());

        return $candidates
            ->filter(fn (Product $p) => $available[$p->id] ?? false)
            ->take($settings['limit'])
            ->values();
    }
}
