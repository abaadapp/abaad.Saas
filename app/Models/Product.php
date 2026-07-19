<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:3', 'cost' => 'decimal:3', 'active' => 'boolean'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }

    // مفاتيح متوافقة مع الواجهات (Demo shape)
    public function getQtyAttribute() { return $this->quantity; }
    public function getAlertAttribute() { return $this->alert_qty; }
    public function getCatAttribute() { return $this->category?->name; }
    public function getStockStatusAttribute(): string
    {
        if ($this->quantity <= 0) return 'نفد المخزون';
        return $this->quantity < $this->alert_qty ? 'منخفض' : 'متوفر';
    }

    /** رابط الصورة: يدعم الروابط الخارجية والملفات المرفوعة */
    public function getImageAttribute($value): string
    {
        if (! $value) {
            return 'https://picsum.photos/seed/prod' . $this->id . '/400/400';
        }
        if (str_starts_with($value, 'http')) {
            return $value;
        }
        return \Illuminate\Support\Facades\Storage::url($value);
    }
}
