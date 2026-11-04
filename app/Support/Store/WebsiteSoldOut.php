<?php

namespace App\Support\Store;

use App\Models\Product;

/**
 * حالُ الصنف في الموقع — ثلاثٌ يختارها التاجرُ بيده.
 *
 *  • `available` — يُعرض ويُطلب، والمخزونُ يحكم كما كان.
 *  • `sold_out`  — «نفدت الكمية»: يُعرض بصورته وسعره وشارته، ولا يُطلب.
 *  • `hidden`    — لا يُعرض في الموقع: `published` نفسُه، بمعناه القائم.
 *
 * ═══ ولمن تُفتح ═══
 *
 * لمن في `storefront.website_sold_out_businesses` وحده، بمعرّفه — متجرُ سعود
 * (RIBBON، `businesses.id = 5`) اليوم. ولبسُ واجهةٍ خاصّة لا يفتحها: قرارٌ
 * يُكتب هناك لا يُستنتج من القالب.
 *
 * والقائمةُ تفتح الأثرَ كلَّه لا الخانةَ وحدها: الشارةُ في الموقع، والردُّ عند
 * إتمامه، ووسمُ قائمة المنتجات. فمن خرج منها عاد موقعُه كما كان ولو بقي
 * علَمٌ في عموده — لا صنفَ يُردّ بخانةٍ لا يجدها صاحبُه ليُعيده.
 *
 * ═══ والموقعُ وحده ═══
 *
 * واتساب يعرض الصنفَ ويبيعه كما كان (`WhatsAppOrderCatalog`
 * و`WhatsAppOrderCheckout::quote`): يُسعَّر بقاعدة الموقع بلا هذا العلَم،
 * ويبقى عليه الرفُّ والنشرُ والإضافات.
 *
 * ═══ ولا مخزونَ هنا ═══
 *
 * لا كمّيّةَ ولا رفَّ ولا حركة: من علّم باقةً «نفدت» لم يبع منها شيئًا،
 * ومن أعادها «متوفّرة» لم يُضف إلى رفّها شيئًا. والصندوقُ لا يقرؤه.
 */
final class WebsiteSoldOut
{
    public const AVAILABLE = 'available';

    public const SOLD_OUT = 'sold_out';

    public const HIDDEN = 'hidden';

    public const STATUSES = [self::AVAILABLE, self::SOLD_OUT, self::HIDDEN];

    public static function allowed(int $businessId): bool
    {
        return in_array($businessId, array_map('intval', (array) config('storefront.website_sold_out_businesses', [])), true);
    }

    /** أنفدت كمّيّتُه في الموقع الآن؟ — علَمُه، في متجرٍ فُتحت له الخانة */
    public static function of(Product $product): bool
    {
        return (bool) $product->website_sold_out && self::allowed((int) $product->business_id);
    }

    /**
     * ما نفدت كمّيّتُه من أصناف سلّةٍ وصلت — معرّفاتُه وحدها، ومن متجرها.
     *
     * @return list<int>
     */
    public static function inCart(int $businessId, mixed $items): array
    {
        $ids = collect(is_array($items) ? $items : [])
            ->map(fn ($i) => is_array($i) ? (int) ($i['id'] ?? 0) : 0)
            ->filter()->unique()->values()->all();

        if ($ids === [] || ! self::allowed($businessId)) {
            return [];
        }

        return Product::where('business_id', $businessId)->whereIn('id', $ids)
            ->where('published', true)->where('website_sold_out', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** حالُه كما تقرؤها الخانة — والمخفيُّ مخفيٌّ أيًّا كان علَمُه */
    public static function status(Product $product): string
    {
        return match (true) {
            ! $product->published => self::HIDDEN,
            (bool) $product->website_sold_out => self::SOLD_OUT,
            default => self::AVAILABLE,
        };
    }

    /**
     * العمودان كما تُكتبهما الحالة.
     *
     * والإخفاءُ لا يمسّ علَمَ النفاد: من أخفى باقةً نفدت ثمّ أظهرها من القائمة
     * يجدها كما تركها — لا «متوفّرةً» بقرارٍ لم يتّخذه.
     *
     * @return array<string, bool>
     */
    public static function columns(string $status): array
    {
        return match ($status) {
            self::AVAILABLE => ['published' => true, 'website_sold_out' => false],
            self::SOLD_OUT => ['published' => true, 'website_sold_out' => true],
            self::HIDDEN => ['published' => false],
        };
    }
}
