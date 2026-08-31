<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:3', 'total' => 'decimal:3', 'addons_total' => 'decimal:3'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    /** المقاس المباع — للتجميع لا للعرض؛ العرض من اللقطة أدناه */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }

    public function addons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItemAddon::class);
    }

    /**
     * ما يظهر على الفاتورة — الاسم ومقاسه.
     *
     * من اللقطة لا من العلاقة: مقاسٌ أُعيد تسميته «وسط فاخر» بعد شهرٍ لا
     * يجوز أن يغيّر فاتورةً طُبعت ووُقّعت.
     */
    public function displayName(): string
    {
        return filled($this->variant_name) ? $this->name.' — '.$this->variant_name : (string) $this->name;
    }

    /** ثمن البند كاملًا: مقاسه وإضافاته — وهو ما يُجمع في مجموع الفاتورة */
    public function lineTotal(): float
    {
        return round((float) $this->total + (float) $this->addons_total, 3);
    }
}
