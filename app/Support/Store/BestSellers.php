<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * ما بيع فعلًا من أصناف المتجر — بقاعدة `Order::sold` وحدها.
 *
 * ═══ ولمَ موضعٌ واحد ═══
 *
 * يسأله قسمُ «الأكثر مبيعًا» في الرئيسية وخيارُ «الأكثر مبيعًا» في صفّ
 * المتجر. ولو حسب كلٌّ منهما بيده لَافترقا يومًا: يُستثنى الملغى في أحدهما
 * ويُعدّ في الآخر، فيقول الرفُّ «الأكثر مبيعًا» عن غير ما تقوله الرئيسية.
 *
 * والمتجرُ من العنوان لا من الطلب، والأصنافُ ممّا يُعرض من متجره وحدَه —
 * فلا يدخل الحسبةَ بيعُ متجرٍ آخر ولو تشاركا معرّفَ صنف.
 */
final class BestSellers
{
    /**
     * كم بيع من كلّ صنف — لما بيع منه شيءٌ فقط.
     *
     * @param  iterable<int>  $productIds  أصنافٌ من متجره المعروضة
     * @return Collection<int, float> معرّفُ الصنف ← الكمّيّة
     */
    public static function quantities(int $businessId, iterable $productIds): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', Order::where('business_id', $businessId)->sold()->select('id'))
            ->whereIn('product_id', collect($productIds)->all())
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as q')
            ->pluck('q', 'product_id');
    }

    /**
     * الأصنافُ التي بيعت، من الأكثر — وما لم يُبع منه شيءٌ خارجها.
     *
     * والترتيبُ ترتيبُ الرئيسية نفسُه: الكمّيّةُ ثمّ الأحدث. ولا يُخترع
     * «أكثرُ مبيعًا» لمتجرٍ لم يبع: قائمةٌ فارغةٌ تُقال فارغة.
     *
     * @param  Collection<int, Product>  $shown
     * @return Collection<int, Product>
     */
    public static function ranked(int $businessId, Collection $shown): Collection
    {
        $sold = self::quantities($businessId, $shown->pluck('id'));

        return $shown
            ->filter(fn ($p) => (float) ($sold[$p->id] ?? 0) > 0)
            ->sortByDesc(fn ($p) => [(float) $sold[$p->id], $p->id])
            ->values();
    }
}
