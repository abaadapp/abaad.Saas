<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تسويةُ Paymob — ما حوّلته إلى بنك التاجر ناقصًا رسومَها. انظر `PaymobSettlements`.
 */
class PaymobSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gross' => 'decimal:3',
        'fee' => 'decimal:3',
        'net' => 'decimal:3',
        'settled_on' => 'date',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
