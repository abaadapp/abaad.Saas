<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلبٌ يُجمع داخل محادثة واتساب — انظر `App\Support\WhatsAppOrders`.
 *
 * والفاتورةُ والرابطُ والتجهيزُ ليست هنا: هذا الصفُّ يشير إليها بعد أن تُكتب
 * بالمسارات القائمة، ولا يحمل مالًا ولا قيدًا.
 */
class WhatsAppOrderSession extends Model
{
    protected $table = 'whatsapp_order_sessions';

    protected $guarded = [];

    protected $casts = [
        'cart' => 'array',
        'details' => 'array',
        'context' => 'array',
        'total' => 'decimal:3',
        'seq' => 'integer',
        'last_activity_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /* ═══ أحوالُ الطلب كما يراها الموظّف ═══ */

    /** يختار الزبونُ أصنافه ويكتب تفاصيلَ التسليم */
    public const COLLECTING = 'collecting';

    /** عُرض الملخّصُ بمبلغه — ينتظر موافقةَ الزبون الصريحة */
    public const CONFIRMING = 'confirming';

    /** وافق — تُكتب الفاتورةُ ويخرج رابطُ الدفع، ثمّ يُنتظر المال */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    /** ثبت الدفعُ كاملًا وظهرت المهمّةُ في لوحة التجهيز */
    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** سلّم الآليُّ المحادثةَ لموظّف — ولا يردّ فيها حتى يُنهى الطلب */
    public const NEEDS_HUMAN = 'needs_human';

    /** ما يُبقي الطلبَ مفتوحًا في محادثته */
    public const OPEN = [self::COLLECTING, self::CONFIRMING, self::AWAITING_PAYMENT, self::NEEDS_HUMAN];

    public const STATES = [self::COLLECTING, self::CONFIRMING, self::AWAITING_PAYMENT, self::PAID, self::CANCELLED, self::NEEDS_HUMAN];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChat::class, 'chat_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentLink::class, 'payment_link_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN, true);
    }
}
