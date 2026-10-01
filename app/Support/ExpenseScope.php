<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseBranchAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * نطاقُ المصروف — للنشاط كلِّه، أو لفرعٍ محدّد، أو موزَّعٌ على فروع.
 *
 * ═══ مصدرٌ واحد للحالات الثلاث ═══
 *
 *   النشاطُ كلُّه   ← `branch_id` فارغ، ولا توزيع
 *   فرعٌ محدّد      ← `branch_id` مملوء، ولا توزيع
 *   موزَّع          ← `branch_id` فارغ، وتوزيعٌ مجموعُه مبلغُ المصروف
 *
 * ولا يجتمع فرعٌ وتوزيع أبدًا: من كتب أحدهما مُحي الآخر في المعاملة نفسها.
 *
 * ═══ والتوزيعُ تحليلٌ لا مال ═══
 *
 * المصروفُ الموزَّع صفٌّ واحد وقيدٌ واحد ودفعةٌ واحدة. وصفوفُ التوزيع تقول
 * كم منه على كلّ فرع في تقرير صافي الربح — لا تُكتب حركةً ولا قيدًا. فإيجارٌ
 * بألفٍ موزَّعٌ ستّمئةً وأربعمئة يخرج من الصندوق ألفًا، لا ألفين.
 *
 * ═══ والفرعُ من متجر من سجّل الدخول ═══
 *
 * يُسأل عنه بـ`business_id` في الاستعلام نفسه، ولا يُقرأ متجرٌ من الطلب. ولا
 * مفتاحَ أجنبيًّا في القاعدة يحرسه (قرارُ المالك — انظر الهجرة)، فهذا حارسُه.
 */
final class ExpenseScope
{
    public const BUSINESS = 'business';

    public const BRANCH = 'branch';

    public const SPLIT = 'split';

    public const ALL = [self::BUSINESS, self::BRANCH, self::SPLIT];

    /** حالُ مصروفٍ محفوظ — من الصفّ وصفوف توزيعه */
    public static function of(Expense $expense): string
    {
        if ($expense->branch_id !== null) {
            return self::BRANCH;
        }

        $has = $expense->relationLoaded('allocations')
            ? $expense->allocations->isNotEmpty()
            : $expense->allocations()->exists();

        return $has ? self::SPLIT : self::BUSINESS;
    }

    /**
     * يقرأ النطاقَ من الطلب ويتحقّق منه — ولا يُصحّح أرقام التاجر.
     *
     * والفراغُ «النشاطُ كلُّه»: هو المعنى القديم لكلّ مصروف، ولا يُنسب مصروفٌ
     * إلى فرعٍ لأنّ الجلسة مفتوحةٌ عليه — إيجارُ المتجر لا يصير مصروفَ فرع
     * الكاشير.
     *
     * @param  array<string, mixed>  $input
     * @return array{scope: string, branch_id: ?int, allocations: list<array{branch_id: int, amount: float}>}
     */
    public static function read(int $businessId, array $input, float $amount): array
    {
        $scope = (string) ($input['scope'] ?? '') ?: self::BUSINESS;

        if (! in_array($scope, self::ALL, true)) {
            throw ValidationException::withMessages(['scope' => __('اختر نطاق المصروف.')]);
        }

        if ($scope === self::BUSINESS) {
            return ['scope' => $scope, 'branch_id' => null, 'allocations' => []];
        }

        $owned = Branch::where('business_id', $businessId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($scope === self::BRANCH) {
            $branch = filter_var($input['branch_id'] ?? null, FILTER_VALIDATE_INT);

            if ($branch === false || ! in_array($branch, $owned, true)) {
                throw ValidationException::withMessages(['branch_id' => __('اختر فرعًا من فروع متجرك.')]);
            }

            return ['scope' => $scope, 'branch_id' => $branch, 'allocations' => []];
        }

        $rows = array_values((array) ($input['allocations'] ?? []));

        if ($rows === []) {
            throw ValidationException::withMessages(['allocations' => __('أضف فرعًا واحدًا على الأقل للتوزيع.')]);
        }

        $out = [];
        $seen = [];
        $sum = 0;

        foreach ($rows as $i => $row) {
            $branch = filter_var(is_array($row) ? ($row['branch_id'] ?? null) : null, FILTER_VALIDATE_INT);
            $value = is_array($row) ? ($row['amount'] ?? null) : null;

            if ($branch === false || ! in_array($branch, $owned, true)) {
                throw ValidationException::withMessages(["allocations.{$i}.branch_id" => __('اختر فرعًا من فروع متجرك.')]);
            }

            if (isset($seen[$branch])) {
                throw ValidationException::withMessages(["allocations.{$i}.branch_id" => __('الفرع مكرّر في التوزيع.')]);
            }

            if (! is_numeric($value) || self::mils((float) $value) <= 0) {
                throw ValidationException::withMessages(["allocations.{$i}.amount" => __('المبلغ الموزّع يجب أن يكون أكبر من صفر.')]);
            }

            $seen[$branch] = true;
            $mils = self::mils((float) $value);
            $sum += $mils;
            $out[] = ['branch_id' => $branch, 'amount' => $mils / 1000];
        }

        /*
         * والمجموعُ يساوي المصروف — بالبيسة لا بالكسر العشريّ.
         *
         * `0.1 + 0.2` في الحاسب ليست `0.3`. فيُقارَن عددٌ صحيح من أجزاء الألف،
         * وهي دقّةُ العمود نفسُه. ولا يُكمَّل الفرقُ من عندنا: التاجرُ يرى
         * «المتبقّي» ويصحّحه بيده.
         */
        if ($sum !== self::mils($amount)) {
            throw ValidationException::withMessages([
                'allocations' => __('مجموع التوزيع (:sum) لا يساوي مبلغ المصروف (:amount).', [
                    'sum' => number_format($sum / 1000, 3),
                    'amount' => number_format(self::mils($amount) / 1000, 3),
                ]),
            ]);
        }

        return ['scope' => $scope, 'branch_id' => null, 'allocations' => $out];
    }

    /**
     * يكتب النطاقَ على المصروف — فرعًا أو توزيعًا، والآخرُ يُمحى معه.
     *
     * @param  array{scope: string, branch_id: ?int, allocations: list<array{branch_id: int, amount: float}>}  $scope
     */
    public static function apply(Expense $expense, array $scope): void
    {
        DB::transaction(function () use ($expense, $scope) {
            $expense->forceFill(['branch_id' => $scope['branch_id']])->save();

            ExpenseBranchAllocation::where('expense_id', $expense->id)->delete();

            foreach ($scope['allocations'] as $row) {
                ExpenseBranchAllocation::create([
                    'expense_id' => $expense->id,
                    'branch_id' => $row['branch_id'],
                    'amount' => $row['amount'],
                ]);
            }
        });

        $expense->unsetRelation('allocations');
    }

    /**
     * الفرعُ الذي يحمله قيدُ الدفتر لهذا المصروف.
     *
     * المباشرُ يحمل فرعَه. والموزَّعُ قيدٌ واحد للنشاط: الدفترُ لا يقسم سطرًا
     * واحدًا على مراكز كلفة، وتكرارُ القيد لكلّ فرعٍ يُخرج المالَ مرّتين.
     */
    public static function journalBranch(Expense $expense): ?int
    {
        return $expense->branch_id !== null ? (int) $expense->branch_id : null;
    }

    /** المبلغُ أجزاءً من الألف — دقّةُ عمود المبلغ */
    private static function mils(float $value): int
    {
        return (int) round($value * 1000);
    }
}
