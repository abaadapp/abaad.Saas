<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:3', 'total' => 'decimal:3'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    /** الكمية القابلة للاسترجاع (المتبقية) */
    public function getRemainingAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }
}
