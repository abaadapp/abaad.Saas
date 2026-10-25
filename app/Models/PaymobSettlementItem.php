<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطرُ تسوية Paymob — مدفوعةٌ واحدةٌ حملها التحويل، وأين كان مالُها في الدفتر. انظر `PaymobSettlements`.
 */
class PaymobSettlementItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:3',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PaymobSettlement::class, 'paymob_settlement_id');
    }
}
