<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    protected $guarded = [];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
