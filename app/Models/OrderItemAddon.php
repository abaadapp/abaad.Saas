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
        // لقطةُ ما أُخذ من الرفّ لحظة البيع — لا علاقةٌ تُقرأ اليوم
        'inventory_quantity' => 'decimal:3',
    ];

    /**
     * ما لا يُعرض للزبون.
     *
     * الفاتورة تقول «شوكولاتة ×١» ولا تقول من أيّ رفٍّ أُخذت ولا بكم كلّفت.
     * والحجب هنا لا في كلّ شاشةٍ على حدة: شاشةٌ تنسى الحجب تُسرّب التكلفة
     * إلى إيصالٍ يُطبع للزبون، ولا يُكتشف إلا حين يسأل عن الرقم.
     */
    protected $hidden = ['cost', 'inventory_product_id', 'inventory_quantity'];

    /** الصنف الذي نقص من الرفّ لأجل هذه الإضافة — كما كان يوم البيع */
    public function inventoryProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_product_id');
    }

    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }

    public function addon(): BelongsTo { return $this->belongsTo(Addon::class); }
}
