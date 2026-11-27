<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ما ردّه إشعارُ دائنٍ من بندٍ لبوتيك — صفٌّ لكلّ ردّ، لا يُعدَّل. انظر `BoutiqueInvoiceDues::returned` */
class BoutiqueInvoiceReturn extends Model
{
    protected $guarded = [];

    protected $casts = ['after_sheet' => 'boolean'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(BoutiqueInvoiceLine::class, 'boutique_invoice_line_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'customer_credit_note_id');
    }
}
