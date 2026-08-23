<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صنفٌ في إشعار استلام — اسمه منسوخ فلا يُفرّغه حذف المنتج */
class GoodsReceiptNoteItem extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:3', 'cost' => 'decimal:3'];

    public function note(): BelongsTo { return $this->belongsTo(GoodsReceiptNote::class, 'goods_receipt_note_id'); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
