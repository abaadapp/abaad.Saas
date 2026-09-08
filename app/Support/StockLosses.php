<?php

namespace App\Support;

use App\Models\Expense;
use Illuminate\Support\Carbon;

/**
 * نقصُ المخزون وزيادتُه — يُكتبان في الدفترين معًا.
 *
 * ═══ العطب ═══
 *
 * للحدث الواحد بابان: «تعديل مخزون» يدويٌّ من شاشة التعديلات، و«تسوية جرد»
 * من شاشة الجرد. وكلٌّ منهما كان يكتب في دفترٍ واحد ويترك الآخر:
 *
 *   · اليدويُّ يُرحّل إلى الأستاذ — مدين مصروف / دائن مخزون — **ولا يكتب صفَّ
 *     مصروف**. والربحُ يُقرأ من جدول المصروفات (انظر `Demo::reportSummary`)،
 *     فخسارةُ التلف لا تُنقصه أبدًا: التاجر يرى ربحًا لم يجنه.
 *
 *   · والجردُ يكتب صفَّ المصروف **ولا يُرحّل شيئًا**. فيبقى المخزون في
 *     الميزانية بقيمة بضاعةٍ ليست عنده: يقرأ المتجرُ نفسَه أغنى ممّا هو.
 *     وهو العطبُ نفسه الذي وُصف في `ExpenseController::postToLedger` وأُصلح
 *     هناك — ثمّ دخل من هذا الباب.
 *
 * وقياسُه في متجرٍ حيّ: أحدَ عشرَ صفَّ «فاقد جرد» بمئتين وسبعةٍ وتسعين ريالًا
 * في جدول المصروفات، وصفرٌ في ميزان المراجعة يقابلها.
 *
 * ═══ والقاعدة هنا لا في البابين ═══
 *
 * بابان يكتبان الحدث نفسه بوصفتين يفترقان يومًا — وقد افترقا. فالوصفةُ
 * موضعٌ واحد يقرأ منه الاثنان، ومن يفتح بابًا ثالثًا غدًا يجده مكتوبًا.
 *
 * ═══ ولماذا النقصُ وحده يصير مصروفًا ═══
 *
 * الزيادةُ في العدّ غالبًا خطأُ تسجيلٍ سابق لا ربحٌ جديد؛ وقيدُها إيرادًا
 * يضخّم الأرباح بلا بيعة. فتُردّ إلى المخزون في الأستاذ — أصلٌ وُجد فعلًا —
 * ولا يُكتب لها صفٌّ في المصروفات: صفُّ مصروفٍ بمبلغٍ سالب يُقرأ خطأً في كلّ
 * شاشةٍ تجمع العمود.
 */
final class StockLosses
{
    /** مصدرُ القيد — يُقرأ في الدفتر ويُبحث به */
    public const SOURCE = 'تعديل مخزون';

    /**
     * كتابةُ الحدث في الدفترين.
     *
     * تُنادى **داخل** معاملةٍ قائمة: الصفّان معًا أو لا شيء. وقيمةٌ بصفرٍ لا
     * تُكتب — منتجٌ بلا تكلفة لا مبلغ له يُقيَّد.
     *
     * @param  float  $value  قيمةُ البضاعة — موجبةٌ دائمًا
     * @param  bool  $loss  نقصٌ (خسارة) أم زيادة
     */
    public static function record(
        int $businessId,
        float $value,
        bool $loss,
        string $description,
        Carbon $when,
        ?int $branchId = null,
        ?int $userId = null,
        ?string $employeeName = null,
        string $expenseType = 'فاقد جرد',
        $sourceable = null,
    ): void {
        $value = round($value, 3);

        if ($value <= 0) {
            return;
        }

        Ledger::post(
            $businessId,
            $description,
            $loss
                ? [
                    ['account' => 'other_expenses', 'debit' => $value],
                    ['account' => 'inventory', 'credit' => $value],
                ]
                : [
                    ['account' => 'inventory', 'debit' => $value],
                    ['account' => 'other_expenses', 'credit' => $value],
                ],
            $when,
            self::SOURCE,
            $branchId,
            $userId,
            $sourceable,
        );

        if (! $loss) {
            return;
        }

        Expense::create([
            'business_id' => $businessId,
            // لا عمود فرعٍ في المصروفات — فالفرع في الوصف ليُقرأ في التقرير
            'reference' => 'SHR-'.$when->format('YmdHis').'-'.random_int(100, 999),
            'type' => $expenseType,
            'description' => $description,
            'amount' => $value,
            'method' => 'قيد داخلي',
            'employee_name' => $employeeName,
            'spent_at' => $when->toDateString(),
        ]);
    }
}
