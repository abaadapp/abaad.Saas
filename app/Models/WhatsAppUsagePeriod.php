<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عدّاد استهلاك الشهر — يُقرأ بالنموذج ويُزاد بجملةٍ ذرّية (انظر WhatsAppQuota) */
class WhatsAppUsagePeriod extends Model
{
    protected $table = 'whatsapp_usage_periods';

    protected $guarded = [];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
