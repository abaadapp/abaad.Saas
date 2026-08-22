<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** أثر تعديلٍ وقع على فاتورة — يُكتب ولا يُعدَّل ولا يُحذف */
class OrderEdit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'order_total_before' => 'decimal:3',
        'order_total_after' => 'decimal:3',
    ];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    /** حُذف البند أم نقصت كميّته؟ — تُقرأ من الأرقام لا من عمودٍ ثالث يفترق عنها */
    public function removed(): bool { return $this->qty_after === 0; }
}
