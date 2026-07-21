<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $guarded = [];

    protected $casts = ['opening_balance' => 'decimal:3', 'opening_date' => 'date'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
