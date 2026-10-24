<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطرُ سند النقل — صنفٌ واحد بكميّاته الأربع وما سُوّي من فرقه.
 *
 * والمعلّقُ (قيدَ النقل أو فرقًا لم يُسوَّ) يُحسب ولا يُخزَّن:
 *
 *   المرسلُ − المستلمُ − ما فُقد − ما أُعيد − ما وصل متأخّرًا
 *
 * رقمٌ يُخزَّن إلى جانب أجزائه يفترق عنها يومًا، فيُقرأ في الجرد ما لا يقوله
 * السطر.
 */
class BranchTransferLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_qty' => 'integer',
        'approved_qty' => 'integer',
        'sent_qty' => 'integer',
        'received_qty' => 'integer',
        'lost_qty' => 'integer',
        'returned_qty' => 'integer',
        'late_qty' => 'integer',
        'unit_cost' => 'decimal:3',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransfer::class, 'branch_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** ما خرج من المصدر ولم يدخل رفًّا ولم يُسوَّ */
    public function outstanding(): int
    {
        return max(0, (int) $this->sent_qty - (int) $this->received_qty
            - $this->lost_qty - $this->returned_qty - $this->late_qty);
    }

    /** الكميّةُ التي يُعمل بها في المرحلة الحاليّة: المعتمدةُ إن اعتُمد، وإلّا المطلوبة */
    public function plannedQty(): int
    {
        return (int) ($this->approved_qty ?? $this->requested_qty);
    }
}
