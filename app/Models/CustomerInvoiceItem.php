<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندُ فاتورةٍ — لقطةٌ لا إحالة: اسمٌ وثمنٌ ونسبةُ ضريبةٍ محفوظة */
class CustomerInvoiceItem extends Model
{
    protected $guarded = [];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
