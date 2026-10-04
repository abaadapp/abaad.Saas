<?php

namespace App\Support\Store;

use App\Models\Category;
use App\Models\Product;
use App\Support\MarketingSettings;
use App\Support\Website\Shelf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
 *
 * ═══ ومن أين تُؤخذ الأصناف ═══
 *
 * ما اختاره صاحبُ المتجر بيده وبترتيبه (`store_ribbon_upsells`) أوّلًا —
 * أيَّ صنفٍ من متجره المعروض، من أيّ قسم، ويبقى في قسمه. فإن لم يختر شيئًا
 * بقي القسمُ على القسم المكتوب في القائمة («الاضافات») كما كان: فلا يختفي
 * «أضف مع طلبك» يومَ النشر، ويختار هو حين يشاء.
 *
 * والاختيارُ متى كُتب حكم: صنفٌ مختارٌ أُخفي أو نفد يسقط وحده، ولا يُحشى
 * مكانَه شيءٌ من «الاضافات» — قائمتان لا تُخلطان في صفٍّ واحد.
 */
final class RibbonUpsells
{
    /** مفتاحُ ما يختاره بيده — معرّفاتٌ مرتّبةٌ بفواصل */
    public const KEY = 'store_ribbon_upsells';

    /** ما اختاره بيده بترتيبه — إلى حدّ القائمة. وفارغٌ لمن لم يختر أو لا قائمةَ له */
    public static function manualIds(int $businessId): array
    {
        $settings = self::settings($businessId);

        if ($settings === null) {
            return [];
        }

        return StorePage::ids(MarketingSettings::group($businessId, 'website')[self::KEY] ?? '', $settings['limit']);
    }

    /**
     * القائمةُ كما تُحفظ — أو رفض. ومن لا قسمَ «أضف مع طلبك» له يُردّ بـ403
     * قبل أن يُكتب شيء: الشاشةُ التي لا تعرض الصفَّ لا تحرس الباب.
     */
    public static function validated(int $businessId, Request $request): string
    {
        $settings = self::settings($businessId);

        abort_if($settings === null, 403);

        return ProductList::validated($businessId, self::KEY, $request->input(self::KEY), $settings['limit'], 'أضف مع طلبك');
    }

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

        $manual = self::manualIds($businessId);

        if ($manual !== []) {
            return self::chosen($businessId, $current, $shown, $manual, $settings['limit']);
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

    /**
     * ما اختاره بيده — بترتيبه، ممّا يُعرض وعلى الرفّ فعلًا.
     *
     * وصفحةُ صنفٍ من المختارة نفسِها لا يُعرض فيها القسم — قاعدةُ «إضافةٌ
     * لا تقترح إضافة» نفسُها التي تُسقطه في صفحة صنفٍ من «الاضافات».
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private static function chosen(int $businessId, Product $current, Builder $shown, array $ids, int $limit): Collection
    {
        if (in_array((int) $current->id, $ids, true)) {
            return collect();
        }

        $candidates = $shown
            ->whereIn('id', $ids)
            ->with(['category:id,name,name_en', 'variants' => fn ($q) => $q->where('active', true)->orderBy('sort_order')->orderBy('id')])
            ->get();

        $available = Shelf::availability($businessId, $candidates->pluck('id')->all());

        return ProductList::pick($candidates->filter(fn (Product $p) => $available[$p->id] ?? false), $ids)
            ->take($limit)
            ->values();
    }
}
