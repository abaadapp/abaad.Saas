<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملاحظةٌ داخليّة على عميلٍ محتمَل.
 *
 * لا تخرج إلى واتساب ولا إلى بريدٍ ولا إلى أيّ شاشةِ تاجر. والحارسُ ليس
 * هذا التعليق — بل أنّ لا متحكّمَ خارجَ لوحة المنصّة يقرأ هذا الجدول:
 * انظر `CrmNotesNeverLeaveThePlatformTest`.
 */
class CrmNote extends Model
{
    protected $table = 'crm_notes';

    protected $guarded = [];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
