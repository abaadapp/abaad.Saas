<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'spent_at' => 'date'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
