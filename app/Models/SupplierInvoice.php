<?php

namespace App\Models;

use App\Support\SupplierInvoices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سند مورّد — فاتورته كما وصلت.
 *
 * رقمه رقمُ المورّد لا رقمُنا، فيتكرّر بين موردين ولا يتكرّر عند الواحد:
 * القيد الفريد على الثلاثة (النشاط، المورّد، الرقم) يمنع إدخال السند مرّتين
 * وهو أكثر أخطاء الإدخال شيوعًا — ولا يمنع مورّدين رقّما سنديهما بالرقم نفسه.
 */
class SupplierInvoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date', 'due_at' => 'date',
        'subtotal' => 'decimal:3', 'tax' => 'decimal:3',
        'total' => 'decimal:3', 'paid' => 'decimal:3',
        // وأختامُ التوقيع تواريخُ لا نصوص — انظر GoodsReceiptNote
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'override_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

    /**
     * السنداتُ التي صارت دَينًا فعلًا — المعتمَدةُ وحدها.
     *
     * ═══ ولمَ لا تُجمع كلُّها ═══
     *
     * الذمّةُ تنشأ بالاعتماد وحده: المعتمَدُ له قيدٌ في الدفتر، والمرفوضُ لا
     * قيدَ له، والملغى عُكس قيدُه، والمعلَّقُ ورقةٌ وصلت ولم يوقّعها أحد —
     * ولا يُسدَّد شيءٌ منها (انظر `SupplierInvoiceController::pay`).
     *
     * فجمعُ الكلّ يجعل بطاقةَ «مستحقّ للموردين» تقول ما لا يقوله حساب
     * الموردين في الدفتر: سندٌ رُفض لأنّه مكرَّر يبقى دَينًا على الشاشة إلى
     * الأبد. وتقريرُ الإقرار الضريبيّ أُصلح من هذا العطب نفسِه قبلها — انظر
     * `ReportData::vat`.
     */
    public function scopeOwed(Builder $q): Builder
    {
        return $q->where('approval_status', SupplierInvoices::APPROVED);
    }

    /** أهذا السندُ دَينٌ في الدفتر؟ — الحكمُ نفسُه الذي تقيسه `scopeOwed` */
    public function owed(): bool
    {
        return $this->approval_status === SupplierInvoices::APPROVED;
    }

    public function outstanding(): float
    {
        return round(max(0, (float) $this->total - (float) $this->paid), 3);
    }

    /** الحالة تتبع المدفوع ولا تُكتب يدويًّا فتناقضه */
    public function syncStatus(): void
    {
        $out = $this->outstanding();
        $this->update([
            'status' => $out <= 0.0005 ? 'مدفوع' : ((float) $this->paid > 0 ? 'جزئي' : 'غير مدفوع'),
        ]);
    }

    /**
     * متأخّرٌ عن استحقاقه — ولا يتأخّر إلّا دَين.
     *
     * وسندٌ مرفوضٌ مضى موعدُه كان يُرسم أحمرَ يقول «متأخّر»: مطالبةٌ لا
     * وجودَ لها، على ورقةٍ قيل عنها «لا تُدفع».
     */
    public function isOverdue(): bool
    {
        return $this->owed() && $this->due_at !== null
            && $this->outstanding() > 0 && $this->due_at->isPast();
    }
}
