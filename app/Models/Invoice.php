<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = [];
    protected $casts = ['issued_at' => 'date', 'amount' => 'decimal:3'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
}
