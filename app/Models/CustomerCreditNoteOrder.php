<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الطلبُ الذي كان عنه إشعارٌ دائنٌ يدويّ — ومن سمّاه ومتى ولماذا (`CreditNoteOrders`).
 *
 * سطرٌ لكلّ إشعار، يُكتب مرّةً ولا يُعدَّل ولا يُحذف: هو سجلُّ التدقيق نفسُه.
 */
class CustomerCreditNoteOrder extends Model
{
    /** سُمّي الطلبُ في نموذج الإصدار */
    public const AT_ISSUE = 'issue';

    /** سُمّي بعد الإصدار — بتحقّق صاحب النشاط أو المحاسب */
    public const LATER = 'later';

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'tax_amount' => 'float'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'customer_credit_note_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
