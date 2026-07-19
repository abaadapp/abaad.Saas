<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
}
