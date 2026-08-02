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
        return self::statusFor((int) $this->quantity, (int) $this->alert_qty);
    }

    /**
     * حالة المخزون لأي كمية — لا لكمية المنتج الإجمالية وحدها.
     *
     * نقطة البيع تحكم على رصيد الفرع لا على مجموع الشركة، فتحتاج القاعدة
     * نفسها مطبَّقة على رقم آخر. تركُها مكرّرة في موضعين يعني أن تغيير حدّ
     * التنبيه يومًا ما يُطبَّق في أحدهما فقط.
     */
    public static function statusFor(int $quantity, int $alertQty): string
    {
        if ($quantity <= 0) {
            return 'نفد المخزون';
        }

        return $quantity < $alertQty ? 'منخفض' : 'متوفر';
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
