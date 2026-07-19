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
        'ordered_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
}
