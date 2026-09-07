<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة */
class CustomerCreditNote extends Model
{
    protected $guarded = [];

    protected $casts = ['issued_at' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
