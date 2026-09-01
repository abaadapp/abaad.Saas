<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = ['features' => 'array', 'capabilities' => 'array', 'is_popular' => 'boolean', 'monthly_price' => 'decimal:3', 'yearly_price' => 'decimal:3'];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
