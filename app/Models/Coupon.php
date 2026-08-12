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

    /**
     * آخر لحظةٍ يعمل فيها الكوبون — نهاية يومه لا أوّله.
     *
     * التاريخ يُحفظ «2026-08-12» فيُقرأ 00:00:00. فكوبونٌ ينتهي اليوم كان
     * **ميّتًا من لحظة إنشائه**: عرض «خصم اليوم فقط» لا يعمل ولا مرّة،
     * والتاجر يظنّ الكود خطأً من الكاشير. ومن انتهى أمس ينتهي أمس كما يجب.
     */
    public function endsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->expires_at?->copy()->endOfDay();
    }

    public function isExpired(): bool
    {
        return $this->endsAt()?->isPast() ?? false;
    }

    public function isValid(): bool
    {
        if (! $this->active) return false;
        if ($this->isExpired()) return false;
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
