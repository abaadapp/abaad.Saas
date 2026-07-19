<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Currency extends Model
{
    protected $guarded = [];
    protected $casts = ['rate' => 'decimal:6', 'is_base' => 'boolean', 'active' => 'boolean'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
