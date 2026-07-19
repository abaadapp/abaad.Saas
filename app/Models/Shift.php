<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $guarded = [];
    protected $casts = [
        'opened_at' => 'datetime', 'closed_at' => 'datetime',
        'opening_balance' => 'decimal:3', 'cash_sales' => 'decimal:3', 'card_sales' => 'decimal:3',
        'returns' => 'decimal:3', 'expenses' => 'decimal:3', 'expected_balance' => 'decimal:3',
        'actual_balance' => 'decimal:3', 'difference' => 'decimal:3',
    ];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
