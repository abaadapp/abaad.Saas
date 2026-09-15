<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرفقُ رسالةٍ في دفتر المبيعات — على القرص الخاصّ، يُقرأ ببابٍ يسأل عن صاحبه.
 *
 * ولا `Storage::url()` هنا ولا accessor يبنيه: عمودٌ يُقرأ خامًا، ومن أراد
 * الملفَّ مرَّ بالمتحكّم — انظر `CrmAttachmentController`.
 */
class CrmAttachment extends Model
{
    protected $table = 'crm_attachments';

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(CrmMessage::class, 'message_id');
    }
}
