<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندٌ حملته ورقةٌ ثمّ أُلغي تحصيلُ فاتورته — موقوفٌ سدادُه أو معروضٌ للمراجعة. انظر `BoutiqueHolds` */
class BoutiqueSheetHold extends Model
{
    /** أُعيد التحصيل */
    public const RECOLLECTED = 'recollected';

    /** رُدّت القطعةُ بإشعارٍ حُسم */
    public const RETURNED = 'returned';

    /** أُفرج عنه بقرارٍ وسببه — يُسدَّد ولو لم يُقبض */
    public const RELEASED = 'released';

    /** سُدّدت ورقتُه قبل الإلغاء وروجع — لا قيدَ ولا سداد */
    public const REVIEWED = 'reviewed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_before' => 'boolean',
            'opened_at' => 'datetime',
            'withheld_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'recollected_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BoutiqueInvoiceLine::class, 'boutique_invoice_line_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BoutiqueSettlement::class, 'boutique_settlement_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
