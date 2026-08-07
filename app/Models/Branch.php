<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    /*
     * الحذف يُخفي ولا يمحو — وهنا أهمّ من غيره.
     *
     * الحذف النهائي كان يتسلسل عبر قيود المفاتيح: تُمحى صناديق الفرع وإذون
     * موظفيه، ويُيتَّم سجلّ حركة المخزون، وتبقى مبيعاته تشير إلى رقمٍ لا وجود
     * له. الصفُّ الباقي يمنع ذلك كلّه.
     */
    use SoftDeletes;

    protected $guarded = [];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    /** ما يتعلّق بالفرع — يُعدّ قبل الحذف ليعرف الضاغط ما يُخفيه */
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function devices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PosDevice::class);
    }
}
