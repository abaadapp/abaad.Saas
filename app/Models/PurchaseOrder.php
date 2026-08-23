<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = [];
    protected $casts = ['total' => 'decimal:3', 'ordered_at' => 'datetime', 'received_at' => 'datetime'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }

    /** سندات المورّد المحرَّرة على هذا الأمر — أمرٌ مفوتَر لا يُفوتَر ثانية */
    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }

    /** أوراق ما دخل من هذا الأمر — دفعةٌ دفعة (انظر GoodsReceiptNote) */
    public function receiptNotes(): HasMany { return $this->hasMany(GoodsReceiptNote::class); }
}
