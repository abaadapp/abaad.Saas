<?php

namespace App\Support;

use App\Models\Account;
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
     *
     * ═══ ولا يُنشئ شيئًا ═══
     *
     * كانت تُنشئ حسابًا بنكيًّا بلا اسمٍ ولا بنكٍ ولا آيبان حين لا تجد
     * واحدًا — وهي تُستدعى من **قراءة** الشاشة. فمن فتح «المالية» مرّةً
     * واحدة وُلد في متجره صفٌّ يُعرض «حساب بنكي»، وحجب الحالةَ الفارغة
     * المكتوبة في الشاشة: «أضف حساب نشاطك البنكي…» — دعوةٌ لا تظهر أبدًا.
     *
     * وأسوأ منه أثرًا: هذا الصفُّ يملك ورقة «البنك» (1200) في الشجرة، فكلُّ
     * ترحيلٍ بنكيٍّ يقع فيها. ثمّ يضيف التاجر حسابَه الحقيقيّ فيأخذ ورقةً
     * أختًا لا يدخلها شيء — فيقرأ رصيدًا لا يتحرّك، ورصيدًا يتحرّك لحسابٍ
     * لا يعرفه.
     */
    public static function current(int $businessId): ?BankAccount
    {
        return BankAccount::where('business_id', $businessId)
            ->orderByDesc('is_primary')->orderBy('id')->first();
    }

    /**
     * ورقةُ الحساب البنكيّ في الشجرة — وجهةُ الترحيل.
     *
     * ═══ ولماذا لا يكفي المفتاح النظاميّ ═══
     *
     * كلُّ ترحيلٍ بنكيٍّ في النظام يقول `'bank'`، والمفتاح يقصد ورقةً واحدة
     * (1200) يملكها **أوّل** حسابٍ بنكيّ وُجد. و`BankAccount::balance()`
     * تقرأ ورقةَ حسابها هي. فمتجرٌ بحسابين يقرأ رصيدَ حسابه الرئيسيّ لا
     * يتحرّك مهما حُصِّل فيه، والمالُ كلُّه في ورقة حسابٍ آخر.
     *
     * وهذا لا يُصلح في مواضع الترحيل — سبعةٌ منها تُنسى ثامنتُها — بل في
     * السطر الذي يقرأ الحساب: `Ledger::post`.
     *
     * وورقةٌ مغلقةٌ أو ذاتُ أبناءٍ لا يُرحَّل إليها، فتُردّ `null` ويسقط
     * الترحيل إلى الورقة النظامية: قيدٌ في مكانٍ غير دقيق خيرٌ من بيعةٍ
     * تسقط في وجه الكاشير.
     */
    public static function leaf(int $businessId, int|string|null $bankAccountId = null): ?Account
    {
        $account = ($bankAccountId !== null && $bankAccountId !== '')
            ? BankAccount::where('business_id', $businessId)->whereKey($bankAccountId)->first()
            : self::current($businessId);

        $leaf = $account?->account;

        return $leaf && $leaf->isPostable() ? $leaf : null;
    }

    /**
     * مجموعُ ما في البنك — رقمٌ واحد لكلّ شاشةٍ تسأله.
     *
     * ═══ والموقوفةُ تُجمع مع المفعّلة ═══
     *
     * حسابٌ أُوقف قد يبقى فيه رصيد، وإخفاؤه من «أين المال الآن» يجعل الشاشة
     * تقول رقمًا أصغر ممّا في الدفتر بلا أن تقول لماذا. وكانت شاشة الحسابات
     * تجمع المفعّلة وحدها، والملخّصُ يجمع الكلّ — ويقول تعليقُه إنّهما
     * متّفقان. شاشتان ترسمان «مجموع الأرصدة» برقمين.
     *
     * ═══ وما لا يملكه حسابٌ يُعدّ ═══
     *
     * متجرٌ باع بالبطاقة ولم يسجّل حسابه البنكيّ بعد: مالُه في ورقة «البنك»
     * (1200) ولا حسابَ يملكها، فجمعُ الحسابات وحدها يقرأ صفرًا والميزانيةُ
     * تقرأ المال. ولا يُعدُّ مرّتين: يُضاف حين لا يملكه أحد وحده.
     */
    public static function total(int $businessId): float
    {
        $accounts = BankAccount::where('business_id', $businessId)->with('account')->get();
        $sum = (float) $accounts->sum(fn (BankAccount $a) => $a->balance());

        $system = Account::where('business_id', $businessId)->where('system_key', 'bank')->first();
        $owned = $accounts->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($system && ! in_array($system->id, $owned, true)) {
            $sum += $system->balance();
        }

        return round($sum, 3);
    }

    /**
     * معاملات النظام التي يُتوقّع ظهورها في كشف البنك.
     *
     * تُستثنى المعاملات السابقة لتاريخ الرصيد الافتتاحي لأنها داخلةٌ فيه.
     */
    public static function transactions(int $businessId): Builder
    {
        $openingDate = self::current($businessId)?->opening_date;

        return Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('method', self::METHODS)
            ->when($openingDate, fn ($q) => $q->where('occurred_at', '>=', $openingDate->copy()->startOfDay()));
    }
}
