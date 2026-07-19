<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function ledger(): HasMany { return $this->hasMany(CustomerLedger::class); }
}
