<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرفقُ رسالةِ دعم — على القرص الخاصّ، يُقرأ ببابٍ يسأل عن صاحبه.
 *
 * ولا `Storage::url()` هنا ولا accessor يبنيه: عمودٌ يُقرأ خامًا، ومن
 * أراد الملفَّ مرَّ بالمتحكّم — انظر `SupportAttachmentController`.
 */
class SupportAttachment extends Model
{
    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
