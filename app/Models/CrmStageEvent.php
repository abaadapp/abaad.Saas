<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * انتقالُ مرحلةٍ — سطرٌ يُكتب ولا يُعدَّل ولا يُمحى.
 *
 * و`updated_at` معطَّل: صفٌّ يقول «حدث كذا في الساعة كذا» لا يُحدَّث، ووجودُ
 * عمودٍ يقبل التحديث دعوةٌ إلى تحديثه.
 */
class CrmStageEvent extends Model
{
    protected $table = 'crm_stage_events';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = ['created_at' => 'datetime'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }
}
