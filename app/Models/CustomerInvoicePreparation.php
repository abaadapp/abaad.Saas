<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تجهيزُ فاتورة عميل — ما يُجهَّز وإلى من ومتى، لا بيعٌ ثانٍ.
 *
 * الفاتورةُ ورقةٌ ماليّة وبنودُها مصدرُ الأصناف والكميّات؛ وهذا الصفُّ
 * يحمل ما لا تقوله: الفرعُ والموعدُ والمستلِمُ والبطاقةُ وحالُ التجهيز.
 * ولا يظهر في لوحة التجهيز حتى `activated_at` — سدادٌ كاملٌ موثَّق.
 * انظر `Support\CustomerInvoicePreparations`.
 */
class CustomerInvoicePreparation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'is_gift' => 'boolean',
        'hide_sender' => 'boolean',
        'card_identity_confirmed_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** أظهرت في اللوحة؟ — بسدادٍ كامل، مرّةً لا تعود */
    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }
}
