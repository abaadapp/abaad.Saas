<?php

namespace App\Support;

use App\Models\CustomerPayment;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «مقبوضات اليوم» — مالٌ وصل فعلًا، من دفتر الأستاذ لا من جدولٍ تشغيليّ.
 *
 * ═══ ولمَ الدفتر ═══
 *
 * المالُ يصل من أبوابٍ كثيرة: بيعُ الصندوق نقدًا وبالبطاقة، وبيعُ الموقع
 * عبر Paymob، وتحصيلُ الفواتير القديمة والجديدة، وروابطُ الدفع، والتحويلات،
 * والدفعاتُ المقدّمة، والشيكُ يومَ يُصرف. ولكلّ بابٍ جدولُه: `orders`
 * و`customer_payments` و`customer_payment_links`. وجمعُها بيدٍ يعدّ الدفعةَ
 * مرّتين حيث يلتقي جدولان، ويُخطئ حالَ السداد في كلّ بابٍ له قاعدتُه
 * (`orders.balance_due` و`paid_after_sale` والشيكُ تحت التحصيل…).
 *
 * والدفترُ قد حسم ذلك كلَّه وقتَ وقع: البيعةُ تُدين الصندوقَ أو البنكَ أو
 * Paymob بما دُفع فيها وحده والباقي ذمّة (`Books::recordSale`)، والتحصيلُ
 * يُدين المالَ ويُدين الشيكَ «تحت التحصيل» لا البنك (`CustomerPayments::post`)،
 * وصرفُ الشيك يُدين البنكَ يومَ صرفه (`Cheques::clear`). وكلُّ قبضٍ قيدٌ
 * واحد — فلا يُعدّ مرّتين.
 *
 * ═══ ما يُعدّ ═══
 *
 * سطورُ «أوراق المال» (`MONEY`: الصندوق، والبنكُ وأوراقُ حساباته، وPaymob
 * تحت التسوية) في قيدٍ طرفُه عميلٌ أو بيع (`CUSTOMER_SIDE`) — مدينُها قبضٌ
 * ودائنُها ردّ. فلا يُعدّ:
 *   - تسويةُ Paymob إلى البنك (`PaymobSettlements`): مالٌ قُبض يومَ دفعه
 *     العميل، وانتقالُه إلى البنك بعد أيّامٍ ناقصًا رسومَها تسويةُ مزوّدٍ لا
 *     قبضٌ ثانٍ؛
 *   - إيداعُ الدرج في البنك، والمصروفات، والموردون، ورأسُ المال والمسحوبات،
 *     وسدادُ الضريبة — لا عميلَ فيها؛
 *   - الشيكُ تحت التحصيل والمرتجع — لم يمسّ ورقةَ مال؛
 *   - رابطٌ معلّقٌ أو فاشلٌ أو منتهٍ، ورابطُ التجربة — لا قيدَ لها أصلًا.
 *
 * ═══ والإلغاءُ والاسترجاعُ بيومهما ═══
 *
 * تحصيلٌ أُلغي وبيعةٌ رُدّت يُعكس قيدُهما يومَ وقع العكس (`Ledger::reverse`)،
 * فيُقرأ ردًّا في يومه (`out`) — ويبقى يومُ القبض كما قرأه التاجرُ وأُغلق.
 * لا رقمٌ مضى يتغيّر بصمت.
 *
 * ═══ والفرع ═══
 *
 * قيدٌ له فرعٌ — البيعةُ وعكسُها وتصحيحُها — في فرعه. والتحصيلُ لا فرعَ له
 * (`CustomerPayments::post`)، فيُنسب بتخصيصاته: ما خُصّص لفاتورة فرعٍ في
 * ذلك الفرع، ودفعةٌ واحدةٌ على فاتورتي فرعين تنقسم بينهما ولا تُعدّ في
 * كليهما. وما بقي — دفعةٌ مقدّمة، ورابطٌ بلا فاتورة، وفاتورةٌ بلا فرع — غيرُ
 * منسوبٍ (`unattributed`): في «كل الفروع» وحده، لا يُلقى على فرعٍ لم يقبضه.
 * فالفروعُ معًا وغيرُ المنسوب يساوي النشاطَ كلَّه.
 */
final class Receipts
{
    /** أوراقُ المال — ومعها أوراقُ الحسابات البنكيّة وما تحت هذه الثلاث */
    public const MONEY = ['cash', 'bank', 'paymob_clearing'];

    /**
     * قيدٌ فيه سطرٌ على واحدٍ من هذه طرفُه عميلٌ أو بيع.
     *
     * و«الضريبة المستحقّة» ليست منها: سدادُها للجهاز يُدين الضريبةَ ويُدين
     * البنك — مالٌ خرج لا ردٌّ لعميل. وقيدُ البيع لا يخلو من إيرادٍ أو ذمّة.
     */
    public const CUSTOMER_SIDE = ['receivable', 'sales', 'sales_website', 'sales_returns', 'cheques_receivable'];

    /**
     * المقبوضُ من يوم `$from` إلى ما قبل يوم `$to`.
     *
     * @return array{total: float, in: float, out: float, paymob: float, unattributed: float}
     */
    public static function between(int $businessId, CarbonInterface $from, CarbonInterface $to, ?int $branchId = null): array
    {
        $money = self::moneyAccounts($businessId);
        $paymob = self::ids($businessId, ['paymob_clearing']);

        if ($money === []) {
            return ['total' => 0.0, 'in' => 0.0, 'out' => 0.0, 'paymob' => 0.0, 'unattributed' => 0.0];
        }

        [$value, $bindings] = $branchId === null
            ? ['l.debit - l.credit', []]
            : self::branchShare($businessId, '= ?', [$branchId]);

        $paymobIn = $paymob === [] ? '0' : 'CASE WHEN l.account_id IN ('.implode(',', $paymob).') THEN 1 ELSE 0 END';

        $row = DB::query()->fromSub(
            self::lines($businessId, $money, $from, $to)->selectRaw("({$value}) as v, {$paymobIn} as is_paymob", $bindings),
            't'
        )->selectRaw('COALESCE(SUM(v), 0) as total,'
            .' COALESCE(SUM(CASE WHEN v > 0 THEN v ELSE 0 END), 0) as received,'
            .' COALESCE(SUM(CASE WHEN v < 0 THEN -v ELSE 0 END), 0) as returned,'
            .' COALESCE(SUM(CASE WHEN is_paymob = 1 THEN v ELSE 0 END), 0) as paymob')->first();

        $total = round((float) $row->total, 3);

        // وغيرُ المنسوب يُقال في «كل الفروع» وحده — هو ما لا يراه فرعٌ بعينه
        $unattributed = 0.0;
        if ($branchId === null) {
            [$any, $anyBindings] = self::branchShare($businessId, 'IS NOT NULL', []);
            $attributed = (float) self::lines($businessId, $money, $from, $to)
                ->selectRaw("COALESCE(SUM({$any}), 0) as v", $anyBindings)->first()->v;
            $unattributed = round($total - $attributed, 3);
        }

        return [
            'total' => $total,
            'in' => round((float) $row->received, 3),
            'out' => round((float) $row->returned, 3),
            'paymob' => round((float) $row->paymob, 3),
            'unattributed' => $unattributed,
        ];
    }

    /** سطورُ المال في قيود العملاء والبيع — في المدّة، ولهذا المتجر وحده */
    private static function lines(int $businessId, array $money, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('journal_entries as orig', fn ($j) => $j->on('orig.id', '=', 'e.reverses_id')
                ->on('orig.business_id', '=', 'e.business_id'))
            ->where('e.business_id', $businessId)
            ->whereIn('l.account_id', $money)
            ->where('e.entry_date', '>=', $from->toDateString())
            ->where('e.entry_date', '<', $to->toDateString())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as c')
                ->join('accounts as ca', 'ca.id', '=', 'c.account_id')
                ->whereColumn('c.journal_entry_id', 'e.id')
                ->where('ca.business_id', $businessId)
                ->whereIn('ca.system_key', self::CUSTOMER_SIDE));
    }

    /**
     * نصيبُ الفرع من السطر — بفرع قيده، أو بتخصيصات تحصيله.
     *
     * والتحصيلُ (وعكسُه وصرفُ شيكه) سطرُ مالِه بمبلغه كلِّه، فنصيبُ الفرع
     * منه ما خُصّص لفواتير ذلك الفرع، بإشارة السطر: قبضٌ موجب وعكسُه سالب.
     * والتخصيصُ يبقى بعد الإلغاء (`CustomerPayments::cancel`)، فيُعكس النصيبُ
     * نفسُه يومَ يُلغى.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function branchShare(int $businessId, string $branch, array $bindings): array
    {
        $payment = 'COALESCE(orig.sourceable_id, e.sourceable_id)';

        return [
            'CASE'
            ." WHEN e.branch_id IS NOT NULL THEN CASE WHEN e.branch_id {$branch} THEN l.debit - l.credit ELSE 0 END"
            .' WHEN COALESCE(orig.sourceable_type, e.sourceable_type) = ? THEN'
            .' (CASE WHEN l.debit > l.credit THEN 1 ELSE -1 END)'
            .' * (SELECT COALESCE(SUM(a.amount), 0) FROM customer_payment_allocations a'
            .' JOIN customer_invoices ci ON ci.id = a.customer_invoice_id'
            ." WHERE a.customer_payment_id = {$payment} AND ci.business_id = ? AND ci.branch_id {$branch})"
            .' ELSE 0 END',
            [...$bindings, CustomerPayment::class, $businessId, ...$bindings],
        ];
    }

    /** @return array<int, int> */
    private static function moneyAccounts(int $businessId): array
    {
        $roots = self::ids($businessId, self::MONEY);

        if ($roots === []) {
            return [];
        }

        $leaves = DB::table('accounts')->where('business_id', $businessId)->whereIn('parent_id', $roots)->pluck('id');
        $banks = DB::table('bank_accounts')->where('business_id', $businessId)->whereNotNull('account_id')->pluck('account_id');

        return $leaves->concat($banks)->concat($roots)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, int> */
    private static function ids(int $businessId, array $keys): array
    {
        return DB::table('accounts')->where('business_id', $businessId)->whereIn('system_key', $keys)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
