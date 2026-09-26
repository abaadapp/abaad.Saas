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
        'acknowledged_for' => 'date',
        'active' => 'boolean',
        'days_before' => 'integer',
    ];

    /**
     * ما يُختار بضغطةٍ — وما عداه يُكتب بالأيّام.
     *
     * والشهرُ ثلاثون والشهران ستّون: عددُ أيّامٍ ثابتٌ لا شهرٌ تقويميّ، فلا
     * يتبدّل موعدُ التذكير بين فبراير وأغسطس لموسمٍ في اليوم نفسِه.
     */
    public const PRESETS = [7, 14, 30, 60];

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

    /**
     * أحان وقتُه ولم يُقرأ في هذه الدورة؟
     *
     * ═══ والدورةُ بدايةُ الموسم ═══
     *
     * لا «أُخفيَ» وحدَها: مواسمُ التاجر تعود كلَّ سنة، والهجريُّ منها يتقدّم
     * أحدَ عشرَ يومًا في كلّ عام. فمن أخفى تذكيرَ رمضانَ هذا العام يجب أن
     * يعودَ إليه العامَ القادم حين يُعاد تأريخُ الموسم.
     *
     * فالمقارنةُ ببداية الموسم اليومَ: ساوت ما أُخفي عليه فقد قرأه في هذه
     * الدورة، واختلفت فهي دورةٌ أخرى.
     */
    public function isDue(?Season $season = null, ?Carbon $now = null): bool
    {
        $season ??= $this->season;
        $now ??= now();

        if (! $this->active || ! $season || ! $season->active) {
            return false;
        }

        $at = $this->dueAt($season);

        if ($at === null || $at->gt($now)) {
            return false;
        }

        return ! $this->isAcknowledgedFor($season);
    }

    /** أقُرئ في دورة هذا الموسم؟ */
    public function isAcknowledgedFor(Season $season): bool
    {
        return $this->acknowledged_for !== null
            && $this->acknowledged_for->isSameDay($season->starts_at);
    }
}
