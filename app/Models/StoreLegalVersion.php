<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نسخةٌ من صفحةٍ قانونيّة لمتجر — سياسةُ الخصوصيّة أو تعليماتُ حذف البيانات.
 *
 * الإصدارُ صفر مسوّدةٌ يُعدّلها صاحبُها ما شاء، ويُمحى حين تُنشر. وما فوقه
 * نسخٌ منشورةٌ لا تُعدَّل ولا تُحذف: الرجوعُ إلى نصٍّ سابقٍ نشرٌ جديدٌ برقمٍ
 * أعلى (`Store\StoreLegal::restore`). وحذفُ المتجر نفسِه يمحوها بالقاعدة.
 */
class StoreLegalVersion extends Model
{
    public const DRAFT = 0;

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if ((int) $v->getOriginal('version') !== self::DRAFT) {
                throw new \LogicException('النسخةُ المنشورة لا تُعدَّل — انشر إصدارًا جديدًا.');
            }
        });

        static::deleting(function (self $v) {
            if ((int) $v->version !== self::DRAFT) {
                throw new \LogicException('النسخةُ المنشورة لا تُحذف.');
            }
        });
    }

    /** النصُّ بلغة الصفحة — ولا تقع لغةٌ على الأخرى */
    public function body(string $lang): string
    {
        return $lang === 'en' ? (string) $this->body_en : (string) $this->body_ar;
    }
}
