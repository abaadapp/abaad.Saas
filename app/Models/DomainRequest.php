<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلبُ تاجرٍ أن تشتري له أبعاد نطاقًا وتجهّزه.
 *
 * لا مسجِّل نطاقاتٍ موصولٌ بالنظام ولا بوّابة دفعٍ لها، فالشراء عملُ إنسان:
 * يكتب التاجر ما يريد، ويراه المشغّل في لوحته، ويشتريه ويضبطه ثم يقول «تمّ».
 *
 * والصفّ هنا هو ما يجعل الطلب طلبًا: زرٌّ يرسل بريدًا ولا يخلّف أثرًا يعني
 * طلبًا يضيع بلا أن يعرف أحدٌ أنّه ضاع — لا التاجر الذي ينتظر، ولا المشغّل
 * الذي لم تصله الرسالة.
 */
class DomainRequest extends Model
{
    protected $guarded = [];

    /** بانتظار المشغّل */
    public const PENDING = 'معلّق';

    /** اشتُري النطاق وضُبط */
    public const DONE = 'مكتمل';

    /** تعذّر — والسبب في `note` */
    public const REJECTED = 'مرفوض';

    public const STATUSES = [self::PENDING, self::DONE, self::REJECTED];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
