<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = [];
    protected $casts = ['cost' => 'decimal:3'];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

    public function getRemainingAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }
}
