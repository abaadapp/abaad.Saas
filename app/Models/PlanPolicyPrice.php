<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سعرُ باقةٍ في سياسة تسعيرٍ بعينها — وسعرُها في صفّها يبقى لسياستها السابقة.
 *
 * انظر `App\Support\Addons\PricingPolicy::planPrice`.
 */
class PlanPolicyPrice extends Model
{
    protected $guarded = [];

    protected $casts = ['monthly_price' => 'decimal:3', 'yearly_price' => 'decimal:3'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
