<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturn extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
