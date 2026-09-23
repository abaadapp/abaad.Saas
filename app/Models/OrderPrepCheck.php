<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * علامةُ تجهيزٍ واحدة — «هذا جُمع».
 *
 * وجودُ الصفّ هو العلامة: لا عمودَ يُقلب، ورفعُها حذفٌ. انظر الهجرة
 * `the_bench_ticks_off_what_it_has_gathered` والدالّات في `PrepChecklist`.
 */
class OrderPrepCheck extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
