<?php

namespace App\Models;

use App\Support\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date', 'amount' => 'decimal:3'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    /** فاتورةُ هذا التجديد — فارغةٌ لدوراتٍ كُتبت قبل أن يحمل التجديدُ فاتورتَه */
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }

    /**
     * دوراتٌ منحت مدّةً فعلًا — لا تجديدٌ ينتظر سدادَه ولا تجديدٌ أُلغي.
     *
     * ما يقرأ «آخرَ دورةٍ» ليتبعها المتجر يقرأ هذه وحدَها: التجديدُ المعلّق
     * بلا `ends_at`، وPostgreSQL تُقدّم الفارغَ في الترتيب التنازليّ — فكان
     * `latest('ends_at')` سيلتقطه أوّلًا ويكتب للمتجر تاريخَ انتهاءٍ فارغًا.
     */
    public function scopeGranted(Builder $query): Builder
    {
        return $query->whereNotIn('status', [Billing::SUB_PENDING, Billing::SUB_CANCELLED])->whereNotNull('ends_at');
    }
}
