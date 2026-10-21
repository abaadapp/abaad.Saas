<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نسخةٌ من شروط المتجر وأحكامه — تُكتب مرّةً ولا تُعدَّل.
 *
 * والطلبُ يشير إليها بمن وافق عليها (`orders.terms_version_id`). فلو عُدّل
 * صفُّها بعد ذلك لَصار الطلبُ يقول «وافق على هذا» عن نصٍّ لم يره صاحبُه.
 * فالتعديلُ إصدارٌ جديد (`Store\StoreTerms::revise`)، والحذفُ مردود.
 * وحذفُ المتجر نفسِه يمحوها بالقاعدة (`cascadeOnDelete`) لا من هنا.
 */
class StoreTermsVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('نسخةُ الشروط لا تُعدَّل — احفظ إصدارًا جديدًا.');
        });

        static::deleting(function () {
            throw new \LogicException('نسخةُ الشروط لا تُحذف — عليها موافقاتُ طلبات.');
        });
    }

    /** النصُّ بلغة الصفحة — ولا تقع لغةٌ على الأخرى */
    public function body(string $lang): string
    {
        return $lang === 'en' ? (string) $this->body_en : (string) $this->body_ar;
    }

    /** بصمةُ النصّ بلغة الصفحة */
    public function hashFor(string $lang): string
    {
        return $lang === 'en' ? (string) $this->hash_en : (string) $this->hash_ar;
    }
}
