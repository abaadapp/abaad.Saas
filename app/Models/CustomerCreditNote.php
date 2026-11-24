<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة.
 *
 * و`items` بنودُه حين يُسمّى ما رُدّ (`BoutiqueInvoiceDues::noteItems`)، و`basis` سببُه.
 * والإشعارُ بمبلغٍ وحده بلا بنودٍ وبلا سبب — كما كان.
 */
class CustomerCreditNote extends Model
{
    /** بضاعةٌ رُدّت — وهو اسمُه في تصحيح الفاتورة (#195) */
    public const RETURN = 'return';

    /** خصمٌ أو تسويةُ سعرٍ بلا مرتجع — على المتجر */
    public const PRICE = 'price';

    public const BASES = [self::RETURN, self::PRICE];

    protected $guarded = [];

    protected $casts = ['issued_at' => 'date', 'items' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
