<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * فاتورةُ عميل — التزامٌ ماليٌّ عليه، لا حالةُ سدادٍ في طلب.
 *
 * ولا تُخلط بـ`App\Models\Invoice`: تلك فاتورةُ اشتراك المنصّة على التاجر.
 */
class CustomerInvoice extends Model
{
    public const DRAFT = 'مسودة';

    public const ISSUED = 'صادرة';

    public const CANCELLED = 'ملغاة';

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'issued_by_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * الطلباتُ التي تغطّيها — صفرٌ أو واحدٌ أو شهرٌ كامل.
     *
     * وهي مفتاحُ منع الترحيل المزدوج: فاتورةٌ تغطّي طلبًا لا تُقيَّد، لأنّ
     * بيعةَ ذلك الطلب قُيّدت لحظةَ وقوعها في `Books::recordSale`.
     */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'customer_invoice_orders');
    }

    /** هل تغطّي طلبًا؟ — فإن غطّت فلا قيدَ لها */
    public function coversOrders(): bool
    {
        return $this->orders()->exists();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerInvoiceItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CustomerCreditNote::class);
    }

    /** ما يُعدّ ذمّةً قائمة — الملغاة ليست ذمّة، والمسودّة لم تُصدر بعد */
    public function scopeLive($query)
    {
        return $query->where('status', self::ISSUED);
    }

    /**
     * المسدَّد — مجموعُ تخصيصات الدفعات غير الملغاة.
     *
     * ويُشتقّ ولا يُخزَّن: عمودٌ وجدولٌ يقولان الشيء نفسه يفترقان يومًا،
     * وأوّلُ دفعةٍ تُلغى ولا يُحدَّث فيها العمود تجعل الفاتورة تقول مدفوعةً.
     */
    public function paidTotal(): float
    {
        return round((float) DB::table('customer_payment_allocations as a')
            ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->where('a.customer_invoice_id', $this->id)
            ->whereNull('p.cancelled_at')
            ->sum('a.amount'), 3);
    }

    /** ما رُدَّ منها بإشعار دائن */
    public function creditedTotal(): float
    {
        return round((float) $this->creditNotes()->sum('amount'), 3);
    }

    /**
     * الباقي على العميل.
     *
     * إجمالي - مسدَّد - مردود. ولا ينزل تحت الصفر: زيادةُ الدفع تبقى غيرَ
     * مخصَّصةٍ في رصيد العميل ولا تجعل فاتورةً سالبة.
     */
    public function outstanding(): float
    {
        if ($this->status !== self::ISSUED) {
            return 0.0;
        }

        return round(max(0, (float) $this->total - $this->paidTotal() - $this->creditedTotal()), 3);
    }

    /**
     * حالُ السداد كما تُقرأ — لا كما تُخزَّن.
     *
     * و«متأخرة» محسوبةٌ من تاريخ الاستحقاق لا حالةٌ في عمود: حالةٌ تُخزَّن
     * تحتاج مهمّةً ليليّة تُحدّثها، وأوّلُ ليلةٍ تسقط فيها تجعل الشاشة تقول
     * «غير مستحقّة» عن دَينٍ تأخّر شهرًا.
     */
    public function paymentState(): string
    {
        if ($this->status === self::CANCELLED) {
            return 'ملغاة';
        }
        if ($this->status === self::DRAFT) {
            return 'مسودة';
        }

        $out = $this->outstanding();
        if ($out <= 0) {
            return 'مدفوعة';
        }
        if ($this->due_at !== null && $this->due_at->endOfDay()->isPast()) {
            return 'متأخرة';
        }

        return $this->paidTotal() > 0 ? 'مدفوعة جزئيًا' : 'غير مدفوعة';
    }

    /** كم يومًا تأخّرت — صفرٌ إن لم تتأخّر */
    public function daysOverdue(): int
    {
        if ($this->due_at === null || $this->outstanding() <= 0) {
            return 0;
        }

        return max(0, (int) $this->due_at->endOfDay()->diffInDays(now(), absolute: false));
    }
}
