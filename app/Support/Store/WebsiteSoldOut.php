<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Product;

/**
 * حالُ الصنف في الموقع — ثلاثٌ يختارها التاجرُ بيده.
 *
 *  • `available` — يُعرض ويُطلب، والمخزونُ يحكم كما كان.
 *  • `sold_out`  — «نفدت الكمية»: يُعرض بصورته وسعره وشارته، ولا يُطلب.
 *  • `hidden`    — لا يُعرض في الموقع: `published` نفسُه، بمعناه القائم.
 *
 * ═══ ولمَ الواجهةُ الخاصّة وحدها اليوم ═══
 *
 * RIBBON (`Store\RibbonController`) يُبقي الصنفَ في القوائم بشارته ويُغلق
 * زرَّ إضافته. والصفحةُ البسيطة والبانِي لا يعرفان الشارة بعد: صنفٌ يُرى
 * قابلًا للشراء ثمّ يُردّ عند الإتمام أسوأُ من خانةٍ لا تظهر. فتُفتح الخانةُ
 * لمن لبس واجهةً خاصّة، وتُفتح لغيره يوم يرسمها موقعُه — هنا لا في كلّ شاشة.
 *
 * والإتمامُ يُطيع العمودَ لكلّ متجر (`WebCheckout::assertPublished`): الحمايةُ
 * لا تُشترط بالواجهة، والخانةُ وحدها تُشترط.
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
        return Business::find($businessId)?->storefrontTheme() !== null;
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

        if ($ids === []) {
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
