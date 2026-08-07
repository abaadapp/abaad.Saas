<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهاز نقطة بيع مفعَّل — يعرف متجره وفرعه.
 *
 * هو المصدر الموثوق للفرع داخل نقطة البيع. لا يُقرأ الفرع من الواجهة ولا من
 * جلسة المتصفّح: الجلسة يبدّلها المدير من تبويبٍ آخر، والواجهة يكتبها من شاء.
 */
class PosDevice extends Model
{
    protected $guarded = [];

    public const ACTIVE = 'نشط';

    public const REVOKED = 'ملغى';

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
