<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;

/**
 * الوصفة — ممّ تُصنع الباقة، وكم يُخصم من الرفّ حين تُباع.
 *
 * نقطةُ قرارٍ واحدة لا منطقٌ موزَّع: أيّ وصفةٍ تخصّ هذا البند، وكم يستهلك،
 * وبكم يكلّف. ثلاثة أسئلة تُسأل في نقطة البيع وفي تصحيح الفاتورة وفي شاشة
 * المنتج وفي تقرير الهالك — وجوابٌ واحد لها في كلّ موضع.
 *
 * ولو تفرّقت لصار «الكبير» يخصم عشرين وردةً عند البيع وثمانيَ عشرة عند
 * الإلغاء، ولا يظهر الفرق إلا في جردٍ بعد شهر.
 */
class Recipe
{
    /**
     * صفوف الوصفة التي تخصّ هذا البند.
     *
     * لمقاسٍ له صفوفُه: صفوفُه وحدها. ولمقاسٍ بلا صفوف — أو لمنتجٍ بلا
     * مقاسات — صفوفُ المنتج نفسه (`variant_id` فارغ).
     *
     * ولا جمعَ بين الاثنين: لو جُمعا لاستهلك «الكبير» أساسَ المنتج فوق ورده،
     * فيُخصم ضعفُ ما يجب — وهو خطأٌ لا يُكتشف إلا حين ينفد الورد باكرًا.
     *
     * @param  Collection<int, RecipeItem>|null  $preloaded  صفوف المنتج كلّها إن كانت محمّلة (تجنّبًا لـN+1)
     * @return Collection<int, RecipeItem>
     */
    public static function forLine(Product $product, ?ProductVariant $variant = null, ?Collection $preloaded = null): Collection
    {
        $rows = $preloaded ?? RecipeItem::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();

        if ($variant) {
            $own = $rows->where('variant_id', $variant->id);
            if ($own->isNotEmpty()) {
                return $own->values();
            }
        }

        return $rows->whereNull('variant_id')->values();
    }

    /** هل لهذا البند وصفةٌ تُخصم مكوّناتها بدل خصمه هو؟ */
    public static function has(Product $product, ?ProductVariant $variant = null, ?Collection $preloaded = null): bool
    {
        return self::forLine($product, $variant, $preloaded)->isNotEmpty();
    }

    /**
     * تكلفة صنع قطعةٍ واحدة بأسعار اليوم.
     *
     * تقديريّة لا نهائية: تكلفة المكوّن متوسّطٌ يتحرّك مع كلّ شراء. ولذلك
     * تُنسخ لحظة البيع في `order_items.cost` ولا تُقرأ من هنا في التقارير —
     * وإلا تغيّر ربحُ الشهر الماضي لأنّ المورّد رفع سعره اليوم.
     *
     * @param  array<int, float>|null  $costs  [معرّف المكوّن => تكلفته] إن كانت محمّلة
     */
    public static function unitCost(Product $product, ?ProductVariant $variant = null, ?Collection $preloaded = null, ?array $costs = null): float
    {
        $rows = self::forLine($product, $variant, $preloaded);

        if ($rows->isEmpty()) {
            return 0.0;
        }

        if ($costs === null) {
            $costs = Product::whereIn('id', $rows->pluck('component_product_id')->unique())
                ->pluck('cost', 'id')->map(fn ($c) => (float) $c)->all();
        }

        return round($rows->sum(fn (RecipeItem $r) => $r->effectiveQuantity() * ($costs[$r->component_product_id] ?? 0.0)), 3);
    }

    /**
     * ما يُستهلك من كلّ مكوّنٍ لبيع كميّةٍ من هذا البند — بالكسر لا بالصحيح.
     *
     * @return array<int, float>  [معرّف المكوّن => الكمية العشرية]
     */
    public static function consumptionFor(Product $product, ?ProductVariant $variant, int $qty, ?Collection $preloaded = null): array
    {
        $out = [];
        foreach (self::forLine($product, $variant, $preloaded) as $row) {
            $id = (int) $row->component_product_id;
            $out[$id] = ($out[$id] ?? 0.0) + $row->effectiveQuantity() * $qty;
        }

        return $out;
    }

    /**
     * يجمع استهلاك بنودٍ كثيرة في خريطةٍ واحدة قبل تحويلها إلى أعداد صحيحة.
     *
     * الجمع قبل التقريب مقصود: باقتان فيهما نصفُ لفّة تغليفٍ لكلٍّ تستهلكان
     * لفّةً واحدة. ولو قُرّب كلُّ بندٍ وحده لصارتا لفّتين — وهو فرقٌ يتراكم
     * حتى يُخرج الجرد عن الحقيقة.
     *
     * @param  array<int, array{product: Product, variant: ?ProductVariant, qty: int}>  $lines
     * @return array<int, float>
     */
    public static function aggregate(array $lines, ?Collection $preloaded = null): array
    {
        $total = [];
        foreach ($lines as $line) {
            foreach (self::consumptionFor($line['product'], $line['variant'] ?? null, (int) $line['qty'], $preloaded) as $id => $q) {
                $total[$id] = ($total[$id] ?? 0.0) + $q;
            }
        }

        return $total;
    }

    /**
     * الكمية الصحيحة التي تُخصم من الرفّ مقابل استهلاكٍ عشريّ.
     *
     * المخزون في هذا النظام أعدادٌ صحيحة (`products.quantity` و
     * `branch_stocks.quantity`)، والوصفة تسمح بالكسر. فيلزم قرار.
     *
     * والقرار: يُرفع إلى الأعلى بعد الجمع. لأنّ ربع لفّةٍ استُهلك ليس صفرًا —
     * واللفّة التي فُتحت لن تعود إلى الرفّ كاملة. والتقريب إلى الأدنى يجعل
     * النظام يعدّ بضاعةً استُعملت فعلًا، وهو أخطر من عدّها ناقصة: الأوّل
     * يبيع ما ليس عنده، والثاني ينبّه باكرًا.
     *
     * ولأنّ الجمع يسبق الرفع، فالوصفة ذات الأعداد الصحيحة — وهي الغالبة:
     * اثنتا عشرة وردة، وقطعتا جيبسوفيلا، ولفّة — لا تتأثّر بهذا إطلاقًا.
     */
    public static function units(float $consumed): int
    {
        return (int) ceil(round($consumed, 4));
    }
}
