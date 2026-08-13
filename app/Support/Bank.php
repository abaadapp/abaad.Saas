<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * ما مرّ بالحساب البنكي فعلًا.
 *
 * كان كشف الحساب البنكي والمطابقةُ يقرآن كلّ معاملة أيًّا كانت وسيلتها، والنقد
 * لا يمرّ بالبنك. فكان «الرصيد الحالي» مجموعَ ما دخل المتجر وخرج منه لا رصيدَ
 * الحساب، ولا يطابق كشف البنك في ريالٍ واحد أبدًا.
 *
 * وأسوأ منه أثرًا: كانت بيعةٌ نقدية بـ٤٧٫٢٥٠ تُطابَق بإيداعٍ بنكيّ بالمبلغ
 * نفسه فيُكتب «مطابق» — فتقول الشاشة «الحساب سليم» وهي لم تقارن شيئًا. وهذه
 * الشاشة تُفتح لكشف الفرق لا لإخفائه.
 *
 * وتاريخ الرصيد الافتتاحي يُحترم هنا: الرصيد الافتتاحي يتضمّن ما قبله، فجمعُه
 * إليه يحسبه مرّتين.
 */
class Bank
{
    /** الوسائل التي تمرّ بالحساب البنكي */
    public const METHODS = ['بطاقة', 'تحويل بنكي'];

    /**
     * الحساب الرئيسي — وجهةُ ما لا يُنسب إلى حسابٍ بعينه.
     *
     * صار للنشاط أكثر من حساب، و«أوّل ما يوجد» يتبدّل بترتيب الصفوف فيتبدّل
     * الكشف بلا أن يمسّه أحد. فالرئيسيّ أوّلًا، ثمّ الأقدم.
     */
    public static function account(int $businessId): BankAccount
    {
        return BankAccount::where('business_id', $businessId)
            ->orderByDesc('is_primary')->orderBy('id')->first()
            ?? BankAccount::create(['business_id' => $businessId, 'is_primary' => true]);
    }

    /**
     * معاملات النظام التي يُتوقّع ظهورها في كشف البنك.
     *
     * تُستثنى المعاملات السابقة لتاريخ الرصيد الافتتاحي لأنها داخلةٌ فيه.
     */
    public static function transactions(int $businessId): Builder
    {
        $openingDate = self::account($businessId)->opening_date;

        return Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('method', self::METHODS)
            ->when($openingDate, fn ($q) => $q->where('occurred_at', '>=', $openingDate->copy()->startOfDay()));
    }
}
