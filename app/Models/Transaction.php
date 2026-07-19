<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'occurred_at' => 'datetime'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
