<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفعةٌ من عميلٍ على حسابه.
 *
 * تُقيَّد صفًّا مستقلًّا لا تُكتب فوق الفاتورة: العميل يسدّد على دفعات، ومن
 * كتب «مدفوع» فوق البيعة محا متى دفع وكم دفع في كل مرّة — وهو ما يُحتجّ به
 * حين يختلف الطرفان.
 */
class CustomerPayment extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:3', 'paid_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
