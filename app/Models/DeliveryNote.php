<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إشعار تسليم شحنة — مستند حركةٍ لا مستند مال.
 *
 * يُخرج البضاعة من المخزون عند تسليمه ولا يُنشئ ذمّةً ولا قيدًا: الذمّة
 * نشأت بالفاتورة، وخلطُهما يُحمّل العميل مرّتين.
 */
class DeliveryNote extends Model
{
    protected $guarded = [];

    protected $casts = ['delivered_at' => 'date'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function items(): HasMany { return $this->hasMany(DeliveryNoteItem::class); }

    public function isEditable(): bool { return $this->status === 'مسودة'; }

    public static function nextNumber(int $businessId): string
    {
        $last = static::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'DN-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
