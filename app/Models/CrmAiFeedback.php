<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حكمُ موظّف المبيعات على اقتراحٍ — يُقرأ لتحسين التعليمات والمعرفة.
 *
 * ولا تدريبَ نموذجٍ يجري منه، ولا يُدّعى ذلك في أيّ شاشة: ادّعاءُ تدريبٍ لا
 * يقع يجعل من يضغط الزرَّ يظنّ أنّه علّم شيئًا.
 */
class CrmAiFeedback extends Model
{
    protected $table = 'crm_ai_feedback';

    protected $guarded = [];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }
}
