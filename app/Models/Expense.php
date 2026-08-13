<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    // قيدٌ مالي لا يُمحى بضغطة — انظر الهجرة add_soft_deletes_to_products_and_expenses
    use SoftDeletes;

    /** الحالة التي تعني أن المال خرج فعلًا */
    public const PAID = 'مدفوع';

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'spent_at' => 'date', 'due_date' => 'date'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    /** قيد الدفتر المقابل — يُنشأ يوم السداد لا يوم التسجيل */
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }

    /**
     * المدفوع وحده مصروف.
     *
     * كانت فاتورةٌ بحالة «غير مدفوع» تُخصم من الربح وتُقيَّد في الدفتر كأنّ
     * المبلغ خرج: ربحٌ أقلّ ممّا هو، ونقدٌ أقلّ ممّا في الدرج. والحالة تُعرض
     * ولا تُغيّر شيئًا.
     *
     * والقديم بلا حالة يُعدّ مدفوعًا: هكذا كان يُحسب قبل هذا التمييز.
     */
    public function scopePaid($query)
    {
        return $query->where(fn ($w) => $w->whereNull('status')->orWhere('status', self::PAID));
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNotNull('status')->where('status', '!=', self::PAID);
    }

    public function isPaid(): bool
    {
        return $this->status === null || $this->status === self::PAID;
    }
}
