<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * استحقاقُ متجرٍ لإضافة — مدّتُه وكمّيّتُه وسعرُه المتّفق.
 *
 * ساريةٌ من يوم بدايتها إلى يوم نهايتها شاملًا، ما لم تُلغَ. وانقضاؤها يُقرأ
 * من التاريخ — لا مهمّةَ تُغلقها ولا صفَّ يُحذف. انظر `App\Support\Addons\Entitlements`.
 */
class BusinessAddon extends Model
{
    public const PURCHASE = 'purchase';

    public const GRANT = 'grant';

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'cancelled_at' => 'datetime',
        'unit_price' => 'decimal:3',
        'renews' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddon::class, 'subscription_addon_id');
    }

    /** ساريةٌ اليوم: بدأت، ولم تنقضِ، ولم تُلغَ */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNull('cancelled_at')
            ->whereDate('starts_at', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today));
    }

    public function isActive(): bool
    {
        $today = now()->startOfDay();

        return $this->cancelled_at === null
            && $this->starts_at->lte($today)
            && ($this->ends_at === null || $this->ends_at->gte($today));
    }
}
