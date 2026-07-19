<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = [];
    protected $casts = [
        'value' => 'decimal:3', 'min_order' => 'decimal:3',
        'expires_at' => 'datetime', 'active' => 'boolean',
    ];

    public function isValid(): bool
    {
        if (! $this->active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) return false;
        return true;
    }

    public function discountFor(float $subtotal): float
    {
        if ($subtotal < (float) $this->min_order) return 0;
        $d = $this->type === 'نسبة' ? $subtotal * (float) $this->value / 100 : (float) $this->value;
        return round(min($d, $subtotal), 3);
    }
}
