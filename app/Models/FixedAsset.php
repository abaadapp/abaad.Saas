<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * أصلٌ ثابت يُهلَك بالقسط الثابت شهرًا بشهر.
 *
 * الإهلاك شهريٌّ لا سنويّ لأن الدفتر يُقفل شهريًّا: أصلٌ اشتُري في نوفمبر
 * يحمل شهرين من إهلاك سنته لا سنةً كاملة.
 */
class FixedAsset extends Model
{
    protected $guarded = [];

    protected $casts = [
        'purchased_at' => 'date',
        'disposed_at' => 'date',
        'depreciated_through' => 'date',
        'cost' => 'decimal:3',
        'salvage_value' => 'decimal:3',
        'accumulated' => 'decimal:3',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    /** قسط الشهر الواحد — (التكلفة ناقص الخردة) على العمر */
    public function monthlyDepreciation(): float
    {
        $base = (float) $this->cost - (float) $this->salvage_value;

        return $this->life_months > 0 ? round($base / $this->life_months, 3) : 0.0;
    }

    /** القيمة الدفترية: ما بقي من الأصل بعد ما أُهلك منه */
    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated, 3);
    }

    /**
     * ما يُستحقّ إهلاكه حتى شهرٍ بعينه — ولا يتجاوز القيمة القابلة للإهلاك.
     *
     * بلا السقف يستمرّ الإهلاك بعد انتهاء العمر فتهبط القيمة الدفترية تحت
     * قيمة الخردة ثم تصير سالبة: أصلٌ يُنتج مصروفًا إلى الأبد.
     */
    public function dueThrough(Carbon $through): float
    {
        if ($this->status !== 'نشط' || $this->life_months <= 0) {
            return 0.0;
        }

        $from = $this->depreciated_through
            ? $this->depreciated_through->copy()->startOfMonth()->addMonth()
            : $this->purchased_at->copy()->startOfMonth();

        $months = max(0, $from->diffInMonths($through->copy()->startOfMonth()) + ($from->lte($through) ? 1 : 0));
        $depreciable = (float) $this->cost - (float) $this->salvage_value;
        $remaining = max(0.0, $depreciable - (float) $this->accumulated);

        return round(min($months * $this->monthlyDepreciation(), $remaining), 3);
    }
}
