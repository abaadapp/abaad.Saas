<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جزءٌ من مطالبة مرتجعٍ استُردّ: استلامٌ من البوتيك بمستند، أو خصمٌ من ورقةٍ لاحقة.
 *
 * والباقي من المطالبة قيمتُها ناقصًا هذه الأسطر — يُقرأ ولا يُخزَّن.
 */
class BoutiqueReturnRecovery extends Model
{
    public const RECEIPT = 'receipt';

    public const OFFSET = 'offset';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:3',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(BoutiqueReturn::class, 'boutique_return_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BoutiqueSettlement::class, 'boutique_settlement_id');
    }
}
