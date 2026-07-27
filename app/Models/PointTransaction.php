<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حركة نقاط ولاء واحدة (كسب أو استبدال) في سجل العميل. */
class PointTransaction extends Model
{
    protected $guarded = [];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    /**
     * تسجيل حركة نقاط بعد تعديل رصيد العميل (يُمرَّر الرصيد النهائي).
     * type: 'earn' موجب · 'redeem' سالب.
     */
    public static function record(Customer $customer, string $type, int $points, int $balanceAfter, ?int $orderId = null, ?string $note = null): self
    {
        return static::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'order_id' => $orderId,
            'type' => $type,
            'points' => $type === 'redeem' ? -abs($points) : abs($points),
            'balance_after' => $balanceAfter,
            'note' => $note,
        ]);
    }
}
