<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إصدارٌ من قالب مستند — نصُّه كما حُفظ، وبصمتُه، ومن حفظه ومن نشره.
 *
 * صفٌّ لكلّ رقم إصدار، يُكتب مرّةً حين يُحفظ نصٌّ جديد ولا يتغيّر بعدها: تعديلُ
 * القالب إصدارٌ جديد لا يمحو ما قبله. والمستندُ الصادر يحمل رقمَ إصداره
 * (`template_version`) ونصَّه التامّ — هذا السجلُّ للمدير لا بديلٌ عن نسخة المستند.
 */
class PlatformDocumentTemplateVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlatformDocumentTemplate::class, 'template_id');
    }
}
