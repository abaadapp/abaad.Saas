<?php

namespace App\Models;

use App\Support\Addons\Catalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إضافةُ اشتراك في كتالوج المنصّة — لا إضافةُ منتجٍ في متجر (تلك `Addon`).
 *
 * انظر `App\Support\Addons\Catalog`.
 */
class SubscriptionAddon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'monthly_price' => 'decimal:3',
        'yearly_price' => 'decimal:3',
        'for_sale' => 'boolean',
        'for_new_merchants' => 'boolean',
    ];

    /**
     * والرمزُ يُولَّد إن لم يُعطَ — ولا يُعطى إلّا من الكود (الترحيلُ وبذوره).
     *
     * شاشةُ المنصّة لا ترسله، والمتحكّمُ يُسقطه لو أُرسل: انظر `Catalog::newCode`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $addon) {
            if (blank($addon->code)) {
                $addon->code = Catalog::newCode();
            }
        });
    }

    /** الباقاتُ وما هي فيها: `eligible` تُشترى، `included` مشمولة */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'subscription_addon_plans')->withPivot('mode')->withTimestamps();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(BusinessAddon::class);
    }
}
