<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * تذكيرٌ بموسم — نسبيٌّ يتبع بدايتَه، أو ثابتٌ في يومٍ وساعة.
 */
class SeasonReminder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'remind_at' => 'datetime',
        'active' => 'boolean',
        'days_before' => 'integer',
    ];

    public const RELATIVE = 'relative';

    public const FIXED = 'fixed';

    public const TYPES = [self::RELATIVE, self::FIXED];

    /** أقصى ما يُذكَّر به قبل الموسم — سنةٌ تكفي، وما فوقها خطأُ إدخال */
    public const MAX_DAYS_BEFORE = 365;

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * متى يحين — محسوبٌ لا مخزَّن.
     *
     * النسبيُّ يُحسب من بداية الموسم في كلّ قراءة: من أخّر موسمَه شهرًا تأخّر
     * تذكيرُه معه بلا أن يمسّه. والثابتُ كما اختير — تغييرُ الموسم لا يحرّكه.
     */
    public function dueAt(?Season $season = null): ?Carbon
    {
        $season ??= $this->season;

        if ($this->type === self::FIXED) {
            return $this->remind_at?->copy();
        }

        if (! $season || $this->days_before === null) {
            return null;
        }

        return $season->starts_at->copy()->startOfDay()->subDays($this->days_before)->setTime(9, 0);
    }
}
