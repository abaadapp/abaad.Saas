<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];
    protected $casts = [
        'subtotal' => 'decimal:3', 'discount' => 'decimal:3', 'tax' => 'decimal:3',
        'delivery_fee' => 'decimal:3', 'total' => 'decimal:3', 'is_held' => 'boolean',
        'coupon_discount' => 'decimal:3',
        'ordered_at' => 'datetime',
    ];

    /** الحالة التي تعني أن البيعة رُدَّت ولم تعد بيعًا */
    public const CANCELLED = 'ملغي';

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }

    /**
     * ما بيع فعلًا: لا سلّةً معلّقة ولا طلبًا ملغى.
     *
     * كان الشرط يُكتب بيدٍ في كل استعلام على حدة، فكُتب في ثلاثة مواضع
     * ونُسي في أحدٍ وثلاثين: بطاقات التقارير تجمع الملغى والمخطّط تحتها
     * يستثنيه، فتقرأ الشاشةُ الواحدة رقمين متناقضين عن الفترة نفسها.
     * وأخطرها الإقرار الضريبي — ضريبةٌ تُقرّ على بيعةٍ أُلغيت.
     *
     * فصار موضعًا واحدًا يقرأ منه الجميع: من نسي «الملغى» لا يستطيع أن
     * ينساه، لأن النطاق يحمله معه.
     */
    public function scopeSold($query)
    {
        return $query->where('is_held', false)->where('status', '!=', self::CANCELLED);
    }
}
