<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلبُ إضافةٍ أو تجديدِها — يُطلب، فتُصدر فاتورتُه، فيُفعَّل بسدادها.
 *
 * انظر `App\Support\Addons\AddonOrders`.
 */
class AddonOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:3',
        'amount' => 'decimal:3',
        'decided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class, 'subscription_addon_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(BusinessAddon::class, 'business_addon_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
