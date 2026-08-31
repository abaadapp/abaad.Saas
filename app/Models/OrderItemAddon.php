<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إضافةٌ اختارها الزبون على بندٍ بعينه — بلقطتها.
 *
 * الاسم والسعر منسوخان لا مقروءين: تغيير سعر الشوكولاتة اليوم لا يجوز أن
 * يغيّر ما دفعه زبونُ الشهر الماضي.
 */
class OrderItemAddon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:3',
        'total' => 'decimal:3',
        'cost' => 'decimal:3',
        'quantity' => 'integer',
    ];

    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }

    public function addon(): BelongsTo { return $this->belongsTo(Addon::class); }
}
