<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حصّةُ فرعٍ من مصروفٍ موزَّع — نسبةٌ تحليليّة لا مالٌ ثانٍ.
 *
 * المصروفُ صفٌّ واحد وقيدٌ واحد؛ وهذه تقول كم منه على فرع مسقط وكم على
 * صحار في تقرير صافي الربح. ولا `business_id` هنا: الملكيّةُ من المصروف
 * الأب، والفرعُ يُتحقَّق أنّه من متجره عند الكتابة (`ExpenseScope`).
 */
class ExpenseBranchAllocation extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:3'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function branch(): BelongsTo
    {
        // والفرعُ المحذوف حذفًا ناعمًا يبقى اسمُه مقروءًا في التاريخ
        return $this->belongsTo(Branch::class)->withTrashed();
    }
}
