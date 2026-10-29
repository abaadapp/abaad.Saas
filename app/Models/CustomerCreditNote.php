<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة.
 *
 * و`items` بنودُه حين يولد من تصحيح الفاتورة (`CustomerInvoiceEdits`): ما نقص
 * من كلّ بندٍ وضريبتُه. والإشعارُ الذي يكتبه التاجر بمبلغٍ وحده بلا بنود.
 */
class CustomerCreditNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date',
        'items' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceRevision::class, 'revision_id');
    }
}
