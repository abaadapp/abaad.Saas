<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total' => 'decimal:3',
        'items_subtotal' => 'decimal:3',
        'supplier_discount' => 'decimal:3',
        'shipping_cost' => 'decimal:3',
        'tax' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'ordered_at' => 'datetime',
        // موعدٌ متوقَّع لا لحظةُ وقوع: يومٌ بلا ساعة
        'expected_delivery_at' => 'date',
        'received_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** سندات المورّد المحرَّرة على هذا الأمر — أمرٌ مفوتَر لا يُفوتَر ثانية */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
