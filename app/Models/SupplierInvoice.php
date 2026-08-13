<?php

namespace App\Models;

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
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

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

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->outstanding() > 0 && $this->due_at->isPast();
    }
}
