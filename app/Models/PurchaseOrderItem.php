<?php

namespace App\Models;

use App\Support\PurchaseOrderTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cost' => 'decimal:3',
        'units_per_purchase_unit' => 'decimal:3',
        'line_total' => 'decimal:3',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** المتبقّي — بوحدة الشراء كما طُلب، لا بوحدة التخزين */
    public function getRemainingAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }

    /**
     * كم وحدةَ تخزينٍ يعني هذا البند — وهو ما يدخل الرفَّ عند الاعتماد.
     *
     * وبندٌ قديمٌ بلا معامل يُقرأ بواحد: كلُّ ما مضى اشتُري بوحدة التخزين.
     */
    public function getBaseQuantityAttribute(): float
    {
        return PurchaseOrderTotals::baseQuantity(
            (float) $this->quantity,
            (float) $this->units_per_purchase_unit,
        );
    }
}
