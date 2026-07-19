<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLedger extends Model
{
    protected $table = 'customer_ledger';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'due_at' => 'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
