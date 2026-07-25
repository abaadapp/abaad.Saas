<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchStock extends Model
{
    protected $guarded = [];

    /**
     * يطبّق تغييرًا على رصيد فرع لمنتج (يُنشئ السجل عند الحاجة).
     * نقطة مركزية واحدة لكل حركات المخزون حتى يبقى مجموع الفروع = كمية المنتج.
     */
    public static function adjust(int $businessId, ?int $branchId, int $productId, int $delta): void
    {
        if (! $branchId || $delta === 0) {
            return;
        }
        $row = static::firstOrNew([
            'branch_id' => $branchId,
            'product_id' => $productId,
        ]);
        $row->business_id = $businessId;
        $row->quantity = max(0, (int) $row->quantity + $delta);
        $row->save();
    }
}
