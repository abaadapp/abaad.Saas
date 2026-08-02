<?php

namespace App\Support;

use App\Models\BranchStock;

/**
 * الكمية المتاحة للبيع في فرعٍ بعينه.
 *
 * في النظام مخزونان: products.quantity رقمٌ واحد للشركة، و branch_stocks
 * رصيدٌ لكل فرع. نقطة البيع كانت تقرأ الأول وتخصم من الثاني — فتُجيز بيع
 * خمس قطع من صلالة ورصيدها صفر لأن في مسقط عشرًا، ويخرج الجدولان من
 * التوازن في المعاملة نفسها.
 *
 * ولا يكفي أن يُقرأ رصيد الفرع وحده: منتجات أُنشئت قبل وجود الفروع — أو
 * في نشاط لا فروع له — لا صفّ لها في branch_stocks إطلاقًا، فقراءتها صفرًا
 * تُفرغ المتجر على الشاشة. لذا القاعدة على حالتين:
 *
 *   - للمنتج صفوف فروع  →  رصيد هذا الفرع (وغيابُ صفّه يعني صفرًا حقيقيًا)
 *   - لا صفوف له أصلًا  →  لم يُوزَّع بعد، فالكمية الإجمالية هي المتاحة
 *
 * الهجرة backfill_branch_stocks تنقل البيانات القائمة إلى الحالة الأولى،
 * فتبقى الثانية للمنتج الجديد قبل أوّل حركة مخزون.
 */
class Stock
{
    /**
     * @param  int[]  $productIds  حصر البحث (فارغة = كل منتجات النشاط)
     * @param  bool  $lock  يقفل صفوف الفرع حتى نهاية المعاملة — للبيع لا للعرض
     * @return callable(int, int): int  دالة تُعطى (معرّف المنتج، كميته الإجمالية) فتُعيد المتاح
     */
    public static function availabilityResolver(
        int $businessId,
        ?int $branchId,
        array $productIds = [],
        bool $lock = false,
    ): callable {
        // بلا فرع (نشاط لا فروع له) لا معنى للتوزيع — الإجمالي هو المتاح
        if (! $branchId) {
            return fn (int $productId, int $total): int => $total;
        }

        $rows = BranchStock::where('business_id', $businessId)
            ->when($productIds, fn ($q) => $q->whereIn('product_id', $productIds))
            ->when($lock, fn ($q) => $q->orderBy('id')->lockForUpdate())
            ->get(['product_id', 'branch_id', 'quantity']);

        $here = [];
        $allocated = [];
        foreach ($rows as $row) {
            $allocated[$row->product_id] = true;
            if ((int) $row->branch_id === $branchId) {
                $here[$row->product_id] = (int) $row->quantity;
            }
        }

        return function (int $productId, int $total) use ($here, $allocated): int {
            if (! isset($allocated[$productId])) {
                return $total;
            }

            return $here[$productId] ?? 0;
        };
    }
}
