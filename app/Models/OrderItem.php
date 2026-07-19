<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:3', 'total' => 'decimal:3'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
