<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جزءٌ من رصيد المتجر على البوتيك قاصّته ورقةٌ أو ردّه سندُ استرداد.
 *
 * والباقي من الرصيد قيمتُه ناقصًا هذه الأسطر — انظر `BoutiqueDues::remaining`.
 */
class BoutiqueCreditOffset extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:3',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(BoutiqueComponentDue::class, 'credit_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BoutiqueSettlement::class, 'boutique_settlement_id');
    }
}
