<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * مسيرة رواتب شهر.
 *
 * ثلاث حالات لا رابعة: مسودةٌ تُعدَّل، ومعتمدةٌ صارت مستحقًّا في الدفتر،
 * ومصروفةٌ خرج مالُها. والرجوع من حالةٍ إلى ما قبلها ممنوع — لأن كلًّا من
 * الاعتماد والصرف يترك قيدًا، والتراجع عن قيدٍ يكون بقيدٍ عكسيّ لا بمحوه.
 */
class PayrollRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gross' => 'decimal:3',
        'deductions' => 'decimal:3',
        'net' => 'decimal:3',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'مسودة';
    }

    /** إعادة حساب الإجماليات من سطورها — لا تُكتب يدويًّا فتفترق عنها */
    public function recalculate(): void
    {
        $this->update([
            /*
             * والمجموعُ بـ`sum` لا بـ`selectRaw`.
             *
             * `lines()` صارت مرتّبةً بـ`id` — والورقةُ تُعرض سطرًا سطرًا فلا
             * تُترك لترتيبٍ يقع. و`selectRaw` تُبقي `ORDER BY` مع تجميعٍ بلا
             * `GROUP BY`، وPostgreSQL يرفضه بـ42803 (وSQLite يتساهل).
             * و`sum()` تمسح الترتيبَ بنفسها حين لا تجميعَ يدويّ — فهي الطريق.
             */
            'gross' => $this->lines()->sum(DB::raw('basic + allowances + overtime')),
            'deductions' => $this->lines()->sum('deductions'),
            'net' => $this->lines()->sum('net'),
        ]);
    }

    public static function nextNumber(int $businessId): string
    {
        $last = static::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'PR-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
