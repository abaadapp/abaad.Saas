<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رابطُ دفعٍ لعميل — تحصيلٌ يُطلب، لا طلبُ متجر.
 *
 * يُفتح عند Paymob بحساب التاجر (`Paymob\QuickLink`)، ويُغلق بإشعارها
 * الموقَّع وحده (`PaymentLinks::callback`) بتحصيلٍ واحد. انظر `PaymentLinks`.
 */
class CustomerPaymentLink extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::PAID, self::EXPIRED, self::CANCELLED, self::FAILED];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:3',
        'is_live' => 'boolean',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** أينتظر دفعًا الآن؟ — معلَّقٌ لم تنقضِ مهلتُه */
    public function open(): bool
    {
        return $this->status === self::PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
