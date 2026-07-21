<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:3'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
}
