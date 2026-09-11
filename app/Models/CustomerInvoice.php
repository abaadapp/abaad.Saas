<?php

namespace App\Models;

use App\Support\Cheques;
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
        'document_snapshot' => 'array',
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

    /**
     * بنودُ الورقة بترتيبها المكتوب — `sort_order` يكتبه `CustomerInvoices::compute`.
     *
     * ═══ ولماذا صار الترتيبُ مطلوبًا ═══
     *
     * SQLite يردّ الصفوف بترتيب الإدخال فتبدو مرتّبةً بلا طلب، وPostgreSQL
     * لا يضمن ترتيبًا بلا `ORDER BY` — وهو محرّك الإنتاج. فالورقةُ المطبوعة
     * والشاشةُ والـPDF كانت تعرض البنودَ بترتيبٍ يقع لا بترتيبٍ كُتب، ويختلف
     * بين قراءةٍ وأخرى. ولا خطأَ يقول شيئًا: الأرقامُ صحيحة والسطورُ مبعثرة.
     *
     * وكشفه CI على PostgreSQL في اختبارٍ يقرأ أوّلَ بند — وكان يمرّ محلّيًّا
     * على SQLite في كلّ مرّة. وهو الصنفُ نفسُه الذي وثّقه DEC-003:
     * «لا يُفترض ترتيبٌ لم يُطلب».
     *
     * ═══ ولماذا لا تُرتَّب كلُّ علاقةٍ ═══
     *
     * `Account::lines` و`BankAccount::lines` و`Product::orderItems` تُجمَّع
     * لا تُعرض: `Account::balance` تكتب `SUM(debit)` عبر العلاقة، و`ORDER BY`
     * مع تجميعٍ بلا `GROUP BY` يرفضه PostgreSQL. وهي جماعاتٌ تحليليّة لا
     * سطورُ مستند — فلا ترتيبَ لها يُقصد.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerInvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** مستنداتُ العميل المرفقة — أمرُ شراءٍ أو عقدٌ أو طلبٌ موقَّع */
    public function attachments(): HasMany
    {
        return $this->hasMany(CustomerInvoiceAttachment::class);
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
     * «المسدَّد» و«الباقي» بلغة القاعدة — لأنّ الترشيح لا يقع في PHP.
     *
     * ═══ ولماذا كُتبا مرّتين ═══
     *
     * القائمةُ كانت تُحمَّل كاملةً ثمّ تُرشَّح في الذاكرة. ومع ترقيم الصفحات
     * صار ذلك مستحيلًا: من يُرشّح صفحةً بعد جلبها يُرشّح عشرين صفًّا من
     * أربعمئة، فتقول الشاشةُ «لا فواتير متأخّرة» ولها عشرون. فالسؤالُ يجب
     * أن يبلغ القاعدة.
     *
     * ═══ وكيف لا يفترقان ═══
     *
     * تعبيرٌ واحد يُكتب هنا ويُقرأ في الاختيار وفي الترشيح معًا — لا نسخةٌ
     * في كلّ استعلام. واختبارٌ يقابل ما تردّه القاعدة بما تقوله `paymentState`
     * على مصفوفةِ حالاتٍ كاملة، فإن افترقا سقط.
     */
    public static function paidSql(): string
    {
        /*
         * والشيكُ المرتجع لا يُعدّ مسدَّدًا.
         *
         * ورقةٌ رجعت من البنك مالٌ لم يصل: قيدُها عُكس في الدفتر، وذمّةُ
         * العميل عادت عليه. ولو بقي تخصيصُها محسوبًا لَقالت الورقة «مدفوعة»
         * والدفترُ يقول «عليه دَين» — رقمان يقولان الشيء نفسه ويفترقان،
         * ولا يُعرف أيُّهما الصادق.
         *
         * و«تحت التحصيل» يُعدّ مسدَّدًا: الذمّةُ خرجت من العميل إلى الورقة
         * فعلًا، وحساب «ذمم العملاء» نقص بقيده. فالورقةُ والدفتر يقولان
         * الشيء نفسه.
         */
        return '(SELECT COALESCE(SUM(a.amount), 0) FROM customer_payment_allocations a'
            .' JOIN customer_payments p ON p.id = a.customer_payment_id'
            .' WHERE a.customer_invoice_id = customer_invoices.id AND p.cancelled_at IS NULL'
            ." AND (p.cheque_status IS NULL OR p.cheque_status <> 'مرتجع'))";
    }

    public static function creditedSql(): string
    {
        return '(SELECT COALESCE(SUM(n.amount), 0) FROM customer_credit_notes n'
            .' WHERE n.customer_invoice_id = customer_invoices.id)';
    }

    /**
     * الباقي — بلا حصرٍ عند الصفر، عمدًا.
     *
     * `outstanding()` تحصره لأنّها تُعرَض: فاتورةٌ سالبة على الشاشة خبرٌ
     * كاذب. وهذا التعبيرُ يُقارَن ولا يُعرض، والحصرُ لا يغيّر جوابَ مقارنة:
     * زيادةُ الدفع تجعله سالبًا فيقع تحت العتبة — «مدفوعة»، وهو الصواب.
     *
     * ولا `MAX(0, …)`: هي دالّةُ قيمةٍ في SQLite ودالّةُ **تجميع** في
     * PostgreSQL — فتمرّ محلّيًّا وتنهار على محرّك الإنتاج. ونظيرتُها هناك
     * `GREATEST` ولا وجود لها في SQLite. فالتخلّي عمّا لا يلزم أسلمُ من
     * تفريعٍ على اسم المحرّك.
     */
    public static function outstandingSql(): string
    {
        return '(customer_invoices.total - '.self::paidSql().' - '.self::creditedSql().')';
    }

    /**
     * والمتأخّرةُ: صادرةٌ، لها استحقاقٌ مضى، وعليها باقٍ.
     *
     * والمقارنةُ بنهاية يوم الاستحقاق لا ببدايته — كما في `paymentState`:
     * من يستحقّ اليوم لا يتأخّر اليوم.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::ISSUED)
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', now()->toDateString())
            ->whereRaw(self::outstandingSql().' > 0.0005');
    }

    /**
     * ترشيحٌ بحال السداد — بالقاعدة، وبنفس القواعد التي تقرأها الشاشة.
     */
    public function scopePaymentState($query, string $state)
    {
        return match ($state) {
            'مسودة' => $query->where('status', self::DRAFT),
            'ملغاة' => $query->where('status', self::CANCELLED),
            'مدفوعة' => $query->where('status', self::ISSUED)
                ->whereRaw(self::outstandingSql().' <= 0.0005'),
            'متأخرة' => $query->overdue(),
            'مدفوعة جزئيًا' => $query->where('status', self::ISSUED)
                ->whereRaw(self::outstandingSql().' > 0.0005')
                ->whereRaw(self::paidSql().' > 0')
                ->where(fn ($q) => $q->whereNull('due_at')
                    ->orWhereDate('due_at', '>=', now()->toDateString())),
            'غير مدفوعة' => $query->where('status', self::ISSUED)
                ->whereRaw(self::outstandingSql().' > 0.0005')
                ->whereRaw(self::paidSql().' <= 0')
                ->where(fn ($q) => $q->whereNull('due_at')
                    ->orWhereDate('due_at', '>=', now()->toDateString())),
            // وحالٌ لا يعرفها لا تُرجع الجدولَ كلَّه — تُرجع لا شيء
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * المسدَّد — مجموعُ تخصيصات الدفعات غير الملغاة.
     *
     * ويُشتقّ ولا يُخزَّن: عمودٌ وجدولٌ يقولان الشيء نفسه يفترقان يومًا،
     * وأوّلُ دفعةٍ تُلغى ولا يُحدَّث فيها العمود تجعل الفاتورة تقول مدفوعةً.
     */
    public function paidTotal(): float
    {
        /*
         * وبالقاعدة نفسها التي في `paidSql` حرفًا بحرف — انظرها هناك.
         *
         * وهما اثنان لأنّ إحداهما تُحقن في `whereRaw` والأخرى تُقرأ لصفٍّ
         * واحد. وافتراقُهما يعني ورقةً تقول «مدفوعة» في الشاشة و«عليها باقٍ»
         * في القائمة — فيحرسه `test_both_readings_of_paid_agree`.
         */
        return round((float) DB::table('customer_payment_allocations as a')
            ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->where('a.customer_invoice_id', $this->id)
            ->whereNull('p.cancelled_at')
            ->where(fn ($q) => $q->whereNull('p.cheque_status')->orWhere('p.cheque_status', '<>', Cheques::BOUNCED))
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
