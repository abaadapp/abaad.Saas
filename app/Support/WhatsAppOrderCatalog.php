<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\ShelfOrder;
use App\Support\Website\Shelf;
use Illuminate\Support\Collection;

/**
 * ما يُعرض للزبون في محادثة الطلب — من قاعدة المتجر بقاعدة موقعه نفسِها.
 *
 * المعروضُ ما يُعرض في الموقع: صنفٌ نشطٌ منشور (`RibbonController::shown`)،
 * على رفّه شيء (`Shelf::availability`)، بترتيب صاحبه (`ShelfOrder`)، واسمُه
 * واسمُ قسمه بلغة الزبون (`ProductName` و`CategoryName`). والإضافاتُ المفتوحةُ
 * للموقع وحدها (`ProductAddons::for` ببابه).
 *
 * والأسعارُ هنا للعرض وحده: ما يُدفع يُحسب عند الملخّص من القاعدة
 * (`WhatsAppOrderCheckout::preview`) — لا ممّا قيل للزبون في القائمة.
 */
class WhatsAppOrderCatalog
{
    /** @return Collection<int, Product> */
    private static function shown(int $bid): Collection
    {
        $products = Product::where('business_id', $bid)->where('active', true)->where('published', true)
            ->with(['variants', 'category:id,name,name_en'])
            ->orderBy('name')
            ->get();

        $stock = Shelf::availability($bid, $products->pluck('id')->all());

        return $products->filter(fn (Product $p) => (bool) ($stock[(int) $p->id] ?? false))->values();
    }

    /**
     * الأقسامُ التي فيها صنفٌ متاح — بترتيب صاحب المحلّ.
     *
     * @return list<array{id:int, name:string, count:int}>
     */
    public static function categories(int $bid, string $lang): array
    {
        $counts = self::shown($bid)->groupBy('category_id')->map->count();

        return Category::where('business_id', $bid)->inStoreOrder()->get()
            ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => CategoryName::display($c->name, $c->name_en, $lang),
                'count' => (int) $counts[$c->id],
            ])
            ->values()->all();
    }

    /** اسمُ قسمٍ بلغة الزبون — أو فراغ لقسمٍ ليس للمتجر */
    public static function categoryName(int $bid, int $categoryId, string $lang): string
    {
        $c = Category::where('business_id', $bid)->whereKey($categoryId)->first();

        return $c ? CategoryName::display($c->name, $c->name_en, $lang) : '';
    }

    /**
     * أصنافُ قسمٍ متاحةٌ للطلب — بترتيب رفّه.
     *
     * @return list<array{id:int, name:string, price:float, from:bool}>
     */
    public static function products(int $bid, int $categoryId, string $lang): array
    {
        $list = self::shown($bid)->where('category_id', $categoryId)->values();
        $list = ShelfOrder::apply($bid, $list, $categoryId);

        return $list->map(function (Product $p) use ($lang) {
            $prices = $p->variants->where('active', true)->pluck('price')->map(fn ($v) => (float) $v);

            return [
                'id' => (int) $p->id,
                'name' => ProductName::display($p->name, $p->name_en, $lang),
                'price' => $prices->isEmpty() ? (float) $p->sellingPrice() : (float) $prices->min(),
                'from' => $prices->count() > 1 && $prices->min() !== $prices->max(),
            ];
        })->values()->all();
    }

    /** صنفٌ يُطلب الآن بمعرّفه — من هذا المتجر، نشطٌ منشورٌ على رفّه شيء */
    public static function product(int $bid, int $productId): ?Product
    {
        return self::shown($bid)->firstWhere('id', $productId);
    }

    /** @return list<array{id:int, name:string, price:float}> */
    public static function variants(Product $p, string $lang): array
    {
        return $p->variants->where('active', true)->values()->map(fn ($v) => [
            'id' => (int) $v->id,
            'name' => $lang === 'en' && filled($v->name_en) ? (string) $v->name_en : (string) $v->name,
            'price' => (float) $v->price,
        ])->all();
    }

    /**
     * إضافاتُ الصنف المفتوحةُ للموقع، وعلى رفّها شيء.
     *
     * @return list<array{id:int, name:string, price:float}>
     */
    public static function addons(int $bid, Product $p, string $lang): array
    {
        $addons = ProductAddons::for($p, null, null, SaleLines::WEBSITE);
        $stock = Shelf::availability($bid, $addons->pluck('inventory_product_id')->filter()->all());

        return $addons
            ->filter(fn (Addon $a) => $a->inventory_product_id === null || ($stock[(int) $a->inventory_product_id] ?? false))
            ->map(fn (Addon $a) => [
                'id' => (int) $a->id,
                'name' => ProductName::display($a->name, $a->name_en, $lang),
                'price' => (float) $a->price,
            ])->values()->all();
    }

    /** أهذا كرتُ الهدية الذي يُطبع نصُّه؟ — يُسأل عن نصّه بدل كمّيّته */
    public static function isCard(int $bid, Product $p): bool
    {
        return GiftCardProduct::is($bid, $p);
    }

    public static function productName(Product $p, string $lang): string
    {
        return ProductName::display($p->name, $p->name_en, $lang);
    }
}
