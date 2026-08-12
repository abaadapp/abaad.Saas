<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];
    protected $casts = [
        'subtotal' => 'decimal:3', 'discount' => 'decimal:3', 'tax' => 'decimal:3',
        'delivery_fee' => 'decimal:3', 'total' => 'decimal:3', 'is_held' => 'boolean',
        'coupon_discount' => 'decimal:3', 'paid_amount' => 'decimal:3',
        'ordered_at' => 'datetime', 'due_at' => 'date',
    ];

    /** ما بقي على العميل في هذه الفاتورة */
    public function outstanding(): float
    {
        return max(0, round((float) $this->total - (float) $this->paid_amount, 3));
    }

    /** دَينٌ متأخّر: بقي منه شيءٌ وفات موعده */
    public function isOverdue(): bool
    {
        return $this->outstanding() > 0 && $this->due_at !== null && $this->due_at->endOfDay()->isPast();
    }

    public function payments(): HasMany { return $this->hasMany(CustomerPayment::class); }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
}
