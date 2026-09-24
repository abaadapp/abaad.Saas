<?php

namespace App\Models;

use App\Support\MarketingSettings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    /**
     * وكلُّ كتابةٍ تُبطل ما حُفظ في الذاكرة — لا كتابةُ بابٍ واحدٍ منها.
     *
     * `MarketingSettings::group` تحفظ ما قرأت لتُجيب مرّةً في الطلب الواحد
     * بدل سبعَ عشرةَ مرّة. ولها بابٌ يكتب (`save`) يُبطل ما يخصّه — لكنّ
     * تسعةً وثمانين موضعًا في النظام تكتب هذا الجدول بالنموذج مباشرةً:
     * هجرةٌ، وبذرةٌ، وشاشةُ إعداداتٍ أخرى، وفحص.
     *
     * فلو عُلِّق الإبطالُ على ذلك الباب وحده لَقرأ موضعٌ كتب بالنموذج قيمتَه
     * القديمة في الطلب نفسِه — ولا شيءَ يقول له لماذا. والتعليقُ على النموذج
     * يجعل النسيان مستحيلًا لا مستبعَدًا.
     */
    protected static function booted(): void
    {
        $forget = fn (Setting $s) => MarketingSettings::forget((int) $s->business_id);

        static::saved($forget);
        static::deleted($forget);
    }
}
