<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صنفٌ في إشعار تسليم — اسمه منسوخ فلا يُفرّغه حذف المنتج */
class DeliveryNoteItem extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:3'];

    public function note(): BelongsTo { return $this->belongsTo(DeliveryNote::class, 'delivery_note_id'); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
