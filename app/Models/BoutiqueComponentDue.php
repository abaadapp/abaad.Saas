<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صفٌّ في دفتر ما يُستحقّ للبوتيكات عن مكوّنات الباقات وإضافاتها.
 *
 * لقطةٌ لا تُعدَّل: العكسُ صفٌّ سالبٌ يشير إلى أصله — انظر `BoutiqueDues`.
 */
class BoutiqueComponentDue extends Model
{
    public const RECIPE = 'recipe';

    public const ADDON = 'addon';

    /** مستحقٌّ، وعكسُ مستحقٍّ لم يُسوَّ، ورصيدٌ للمتجر عن مستحقٍّ سُوِّي ثمّ عُكس */
    public const DUE = 'due';

    public const REVERSAL = 'reversal';

    public const CREDIT = 'credit';

    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:3',
        'quantity' => 'decimal:3',
        'amount' => 'decimal:3',
        'occurred_at' => 'datetime',
        'period_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
