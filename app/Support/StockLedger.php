<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * حركةُ مخزونٍ واحدة — أربع كتاباتٍ تقع معًا أو لا تقع.
 *
 * كلّ خصمٍ في هذا النظام يكرّر الرقصة نفسها: يضمن توزيع المنتج على الفروع،
 * ثم يغيّر الكمية الإجمالية، ثم رصيد الفرع، ثم يقيّد الحركة للتدقيق. وكانت
 * مكتوبةً بيدها في ستّة مواضع — ومن نسي سطرًا منها في موضعٍ أخرج الجدولين
 * عن التوازن بلا أثرٍ يُقرأ.
 *
 * والوصفة ضاعفت الحاجة: بيعُ باقةٍ واحدة صار أربع حركاتٍ لا واحدة.
 */
class StockLedger
{
    /**
     * يطبّق فروقًا على أصنافٍ عدّة — السالب خصمٌ والموجب ردّ.
     *
     * @param  array<int, int>  $deltas  [معرّف المنتج => الفرق]
     */
    public static function move(
        int $businessId,
        ?int $branchId,
        array $deltas,
        string $type,
        ?string $employeeName = null,
        ?string $note = null,
    ): void {
        $deltas = array_filter($deltas, fn ($d) => (int) $d !== 0);
        if (! $deltas) {
            return;
        }

        // ترتيبٌ ثابت بالمعرّف: قفل الصفوف بترتيبٍ مختلف بين عمليتين
        // متزامنتين يُنتج تعارضًا دائريًّا يوقف الاثنتين
        ksort($deltas);

        $products = Product::where('business_id', $businessId)
            ->whereIn('id', array_keys($deltas))
            ->get()->keyBy('id');

        foreach ($deltas as $productId => $delta) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            // التوزيع أوّلًا ثم الخصم — وإلّا بدأ صفّ الفرع من صفر فصار سالبًا
            BranchStock::ensureAllocated($businessId, (int) $product->id, (int) $product->quantity);
            $product->increment('quantity', $delta);
            BranchStock::adjust($businessId, $branchId, (int) $product->id, (int) $delta);

            InventoryMovement::create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'type' => $type,
                'quantity' => ($delta > 0 ? '+' : '').$delta,
                'employee_name' => $employeeName,
                'note' => $note,
            ]);
        }
    }

    /**
     * أثرُ المكوّنات المستهلَكة في وصفةٍ خلال مدّة — بالكمية والتكلفة.
     *
     * يقرأ حركات المخزون من نوع «استهلاك وصفة» لا بنودَ الطلبات: بيعُ باقةٍ
     * ليس بيعَ وردة، وحسابُ الاستهلاك من كميّة الباقات يخلط وحداتٍ لا
     * تُخلط. وهذا المقام هو المقارن الصحيح لمقدار الهالك.
     *
     * @return array<int, float>  [معرّف المنتج => الكمية المستهلَكة]
     */
    public static function consumedBetween(int $businessId, ?int $branchId, string $from, string $to): array
    {
        return InventoryMovement::where('business_id', $businessId)
            ->where('type', self::RECIPE)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from, $to])
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($rows) => round($rows->sum(fn ($r) => abs((float) $r->quantity)), 3))
            ->all();
    }

    /** نوع الحركة حين تُستهلك مكوّناتٌ لصنع باقة */
    public const RECIPE = 'استهلاك وصفة';

    /** نوع الحركة حين تُباع إضافةٌ لها رصيدٌ في الرفّ */
    public const ADDON = 'بيع إضافة';

    /** ردُّ ما لم يُبَع بعد تصحيح فاتورة */
    public const CORRECTION = 'تعديل فاتورة';

    /** يجمع فرقين على الصنف نفسه بدل أن يطمس أحدهما الآخر */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $id => $delta) {
            $a[$id] = ($a[$id] ?? 0) + $delta;
        }

        return $a;
    }

    /** لا يُستدعى إلا داخل معاملة — يُبقي الفحص والخصم على رقمٍ لا يتغيّر تحتهما */
    public static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('حركة المخزون تُكتب داخل معاملة أو لا تُكتب.');
        }
    }
}
