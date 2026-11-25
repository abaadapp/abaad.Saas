<?php

namespace App\Support;

use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueInvoiceReturn;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueReturnRecovery;
use App\Models\BoutiqueSettlement;
use App\Models\BoutiqueSheetHold;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حركاتُ البوتيك — كلُّ ما غيّر ما بينه وبين المتجر، بمستنده وقيده.
 *
 * ═══ قراءةٌ لا كتابة ═══
 *
 * لا جدولَ حركاتٍ يُملأ: كلُّ سطرٍ يُقرأ من مستنده (بندِ البيع، صفِّ المستحقّ، الورقة،
 * مصروفها، الإشعار، الاسترداد). فلا يفترق السجلُّ عن مستنداته، ولا يُكتب لحركةٍ قيدٌ ثانٍ —
 * يُشار إلى القيد الذي كُتب لها.
 *
 * ═══ والأثرُ رقمٌ واحد ═══
 *
 * «ما على المتجر للبوتيك» ناقصًا «ما للمتجر على البوتيك». فالبيعُ يزيده، والسدادُ والمرتجعُ
 * الذي يتحمّله البوتيكُ ورصيدُ المتجر تُنقصه، والاستردادُ منه يزيده. وصدورُ الورقة لا يغيّره
 * إلّا بفرق تقريبها عن جمع بنودها — وهو يُعرض بمبلغه لا يُخفى. ومجموعُ الآثار هو
 * `balance()` المقروءُ من الأحوال: ما لم يُسوَّ، وما صدر ولم يُسدَّد، ناقصًا الأرصدة
 * والمطالبات. والاختبارُ يقابلهما بالبيسة.
 */
final class BoutiqueMovements
{
    public const PER_PAGE = 25;

    public const RECORDED = 'recorded';

    public const CHANGED = 'changed';

    public const CANCELLED = 'cancelled';

    public const PIECE = 'piece';

    public const PIECE_BACK = 'piece_back';

    public const KEPT = 'kept';

    public const CREDIT = 'credit';

    public const SHEET = 'sheet';

    public const PAID = 'paid';

    public const PIECES_PAID = 'pieces_paid';

    public const NOTE = 'note';

    public const DECIDED = 'decided';

    public const OFFSET = 'offset';

    public const CLAIM_OFFSET = 'claim_offset';

    public const RECEIPT = 'receipt';

    public const REFUND = 'refund';

    /** بندُ فاتورة عميلٍ بلا طلب (`BoutiqueInvoiceDues`): سُجّل، واكتمل تحصيلُه، وأُلغيت فاتورتُه، ورُدّ منه قبل ورقته */
    public const INVOICED = 'invoiced';

    public const COLLECTED = 'collected';

    public const VOIDED = 'voided';

    public const INVOICE_RETURN = 'invoice_return';

    /** تحصيلٌ أُلغي بعد الورقة (`BoutiqueHolds`): عُلّق، وفُكّ بسببه، وسُدّد بعد فكّه */
    public const HELD = 'held';

    public const HOLD_CLOSED = 'hold_closed';

    public const HOLD_PAID = 'hold_paid';

    /** ما يُرشَّح به — مجموعةٌ بأنواعها */
    public const GROUPS = [
        'dues' => [self::RECORDED, self::CHANGED, self::PIECE, self::INVOICED, self::COLLECTED],
        'sheets' => [self::SHEET],
        'payments' => [self::PAID, self::PIECES_PAID, self::HOLD_PAID],
        'returns' => [self::CANCELLED, self::PIECE_BACK, self::KEPT, self::NOTE, self::DECIDED, self::VOIDED, self::INVOICE_RETURN],
        'holds' => [self::HELD, self::HOLD_CLOSED],
        'credits' => [self::CREDIT, self::OFFSET, self::REFUND, self::CLAIM_OFFSET, self::RECEIPT],
    ];

    /* ═══════════ الكتابة: صفُّ المال وأثرُ التصحيح ═══════════ */

    /** صفٌّ في «الحركات» لمالٍ دخل من بوتيكٍ أو خرج إليه — مرتبطًا بقيده إن كُتب */
    public static function cash(int $businessId, string $kind, float $amount, string $method, string $description, Carbon $at, ?JournalEntry $entry = null, ?int $branchId = null): Transaction
    {
        return Transaction::create([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'reference' => Transaction::nextReference($businessId),
            'description' => mb_substr($description, 0, 255),
            // والتحويلُ باسمه الواحد: به يُقابَل كشفُ البنك (`Bank::transactions`)
            'method' => $method === 'تحويل' ? PaymentMethods::TRANSFER : $method,
            'type' => in_array($kind, [Transaction::BOUTIQUE_REFUND, Transaction::BOUTIQUE_CLAIM], true) ? 'دخل' : 'مصروف',
            'kind' => $kind,
            'amount' => round($amount, 3),
            'employee_name' => PosCashier::name() ?? auth()->user()?->name,
            'occurred_at' => $at,
            'journal_entry_id' => $entry?->id,
        ]);
    }

    /**
     * ما تغيّر من نصيب بوتيكٍ بتصحيح بندٍ لم تحمله ورقة — أعمدةٌ على صفّ التصحيح.
     *
     * وما حملته ورقةٌ لا يُكتب هنا: أثرُه إشعارُ مرتجع (`BoutiqueReturns::onDecrease`).
     *
     * @return array<string, mixed>
     */
    public static function changed(OrderItem $item, float $totalAfter): array
    {
        if ($item->boutique_id === null || $item->boutique_settlement_id !== null) {
            return [];
        }

        return [
            'boutique_id' => (int) $item->boutique_id,
            'boutique_item_id' => (int) $item->id,
            'boutique_share' => self::money(self::net($item, $totalAfter) - self::net($item, (float) $item->total)),
        ];
    }

    /* ═══════════ السجلّ ═══════════ */

    /** @return array{period: string, group: ?string, page: int} */
    public static function filters(Request $request): array
    {
        $group = $request->query('group');
        $page = $request->query('page');

        return [
            'period' => Boutiques::period($request->query('period')),
            'group' => is_string($group) && isset(self::GROUPS[$group]) ? $group : null,
            'page' => is_string($page) && ctype_digit($page) && (int) $page > 0 ? min((int) $page, 100000) : 1,
        ];
    }

    /**
     * حركاتُ شهرٍ لبوتيك — برصيدٍ قبلها، ورصيدٍ بعد كلّ حركة، ورصيدٍ في آخره.
     *
     * @return array<string, mixed>
     */
    public static function page(int $businessId, int $boutiqueId, array $f, ?User $user = null): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $f['period'].'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();
        $union = fn () => DB::query()->fromSub(self::union($businessId, $boutiqueId), 'm');

        $opening = (int) $union()->where('m.at', '<', $from)->sum('m.effect_b');
        $month = $union()->whereBetween('m.at', [$from, $to])
            ->selectRaw('m.*, SUM(m.effect_b) OVER (ORDER BY m.at, m.ord, m.kind, m.ref_id ROWS UNBOUNDED PRECEDING) as running_b');
        $totals = DB::query()->fromSub($month, 't')->groupBy('t.kind')
            ->selectRaw('t.kind, COUNT(*) as n, SUM(t.effect_b) as effect_b, SUM(t.shown_b) as shown_b')->get()->keyBy('kind');

        $rows = DB::query()->fromSub($month, 't')
            ->when($f['group'] !== null, fn ($q) => $q->whereIn('t.kind', self::GROUPS[$f['group']]))
            ->orderByDesc('t.at')->orderByDesc('t.ord')->orderByDesc('t.kind')->orderByDesc('t.ref_id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $f['page']);

        return [
            'rows' => self::present($rows->items(), $businessId, $opening, $user),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'opening' => self::money($opening),
            'closing' => self::money($opening + (int) $totals->sum('effect_b')),
            'balance' => self::balance($businessId, $boutiqueId),
            'groups' => collect(self::GROUPS)->map(fn (array $kinds) => [
                'count' => (int) $totals->only($kinds)->sum('n'),
                'effect' => self::money((int) $totals->only($kinds)->sum('effect_b')),
            ])->all(),
        ];
    }

    /** مجموعُ آثار الحركات كلِّها — ويساوي `balance()` */
    public static function effects(int $businessId, int $boutiqueId): float
    {
        return self::money((int) DB::query()->fromSub(self::union($businessId, $boutiqueId), 'm')->sum('m.effect_b'));
    }

    /**
     * ما على المتجر للبوتيك ناقصًا ما له عليه — من الأحوال لا من الحركات.
     *
     * وبندُ الفاتورة ممّا على المتجر من يوم صدورها — ولا يُسدَّد قبل تحصيله. و`withheld` ما سُدّدت
     * أوراقٌ دونه لأنّ تحصيلَه أُلغي.
     *
     * @return array{unsettled: float, invoiced: float, pieces_unsettled: float, sheets_unpaid: float, withheld: float, pieces_unpaid: float, credit: float, claims: float, net: float}
     */
    public static function balance(int $businessId, int $boutiqueId): array
    {
        $net = self::netSql('order_items');
        $unsettled = (int) DB::table('order_items')->where('boutique_id', $boutiqueId)->whereNull('boutique_settlement_id')
            ->whereIn('order_id', Order::where('business_id', $businessId)->sold()->select('id'))
            ->selectRaw("COALESCE(SUM({$net}), 0) as b")->value('b');
        $invoiced = self::baisa(DB::table('boutique_invoice_lines')->where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->whereNull('boutique_settlement_id')->whereNull('voided_at')->selectRaw('COALESCE(SUM(net - returned_net), 0) as v')->value('v'));
        $withheld = self::baisa(BoutiqueSheetHold::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->whereNotNull('withheld_at')->whereNull('paid_at')->get()->sum(fn (BoutiqueSheetHold $h) => BoutiqueHolds::owed($h)));
        $pieces = self::baisa(BoutiqueComponentDue::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->whereNull('boutique_settlement_id')->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])->sum('amount'));

        $sheets = BoutiqueSettlement::where('business_id', $businessId)->where('boutique_id', $boutiqueId)
            ->where('kind', BoutiqueSettlement::SETTLEMENT)->with('expense')->get();
        $sheetsUnpaid = $sheets->filter(fn (BoutiqueSettlement $s) => $s->expense !== null && $s->expense->status !== Expense::PAID)
            ->sum(fn (BoutiqueSettlement $s) => self::baisa(BoutiqueReturns::payable($s->expense)));
        $piecesUnpaid = $sheets->filter(fn (BoutiqueSettlement $s) => (float) $s->components_due > 0 && $s->components_paid_at === null)
            ->sum(fn (BoutiqueSettlement $s) => self::baisa($s->components_net));

        $credit = self::baisa(array_sum(BoutiqueDues::credits($businessId, $boutiqueId)));
        $claims = self::baisa(BoutiqueReturns::claims($businessId, $boutiqueId)->sum(fn (BoutiqueReturn $c) => BoutiqueReturns::remaining($c)));

        return [
            'unsettled' => self::money($unsettled),
            'invoiced' => self::money($invoiced),
            'withheld' => self::money($withheld),
            'pieces_unsettled' => self::money($pieces),
            'sheets_unpaid' => self::money((int) $sheetsUnpaid),
            'pieces_unpaid' => self::money((int) $piecesUnpaid),
            'credit' => self::money($credit),
            'claims' => self::money($claims),
            'net' => self::money($unsettled + $invoiced + $withheld + $pieces + (int) $sheetsUnpaid + (int) $piecesUnpaid - $credit - $claims),
        ];
    }

    /* ═══════════ المصادر ═══════════ */

    /** كلُّ حركات البوتيك في استعلامٍ واحد — بالبيسات الصحيحة */
    private static function union(int $businessId, int $boutiqueId)
    {
        $branches = [
            self::recorded($businessId, $boutiqueId),
            self::recordedThenRemoved($businessId, $boutiqueId),
            self::recordedThenReturnedWhole($businessId, $boutiqueId),
            self::corrected($businessId, $boutiqueId),
            self::cancelled($businessId, $boutiqueId),
            self::pieces($businessId, $boutiqueId),
            self::keptPieces($businessId, $boutiqueId),
            self::sheets($businessId, $boutiqueId),
            self::sheetPayments($businessId, $boutiqueId),
            self::piecePayments($businessId, $boutiqueId),
            self::notes($businessId, $boutiqueId),
            self::decisions($businessId, $boutiqueId),
            self::offsets($businessId, $boutiqueId),
            self::recoveries($businessId, $boutiqueId),
            self::invoiced($businessId, $boutiqueId),
            self::collected($businessId, $boutiqueId),
            self::voided($businessId, $boutiqueId),
            self::invoiceReturns($businessId, $boutiqueId),
            ...self::holds($businessId, $boutiqueId),
        ];

        $union = array_shift($branches);
        foreach ($branches as $b) {
            $union->unionAll($b);
        }

        return $union;
    }

    /** أعمدةُ السطر — بترتيبٍ واحد في كلّ فرع */
    private static function columns($query, string $kind, int $ord, string $at, string $ref, string $sheet, string $order, string $name, string $qty, string $shown, string $effect, string $invoice = 'NULL')
    {
        return $query->selectRaw("CAST(? AS VARCHAR(20)) as kind, CAST({$ord} AS BIGINT) as ord, {$at} as at, {$ref} as ref_id", [$kind])
            ->selectRaw("CAST({$sheet} AS BIGINT) as sheet_id, CAST({$order} AS BIGINT) as order_id, CAST({$name} AS VARCHAR(255)) as name")
            ->selectRaw("CAST({$qty} AS DECIMAL(14,3)) as qty, CAST({$shown} AS BIGINT) as shown_b, CAST({$effect} AS BIGINT) as effect_b, CAST({$invoice} AS BIGINT) as invoice_id");
    }

    private static function b(string $sql): string
    {
        return "CAST(ROUND(COALESCE({$sql}, 0) * 1000) AS BIGINT)";
    }

    /** إشعاراتُ بندٍ أُنقصت كمّيّتُه بعد ورقته: نقص إجماليُّه وبقيت حصّتُه في ورقته */
    private static function backToSheet(string $item): string
    {
        return '(SELECT COALESCE(SUM('.self::b('r.amount').'), 0) FROM boutique_returns r'
            ." WHERE r.order_item_id = {$item}.id AND r.source = '".BoutiqueReturn::QUANTITY."')";
    }

    /** بندٌ منفرد سُجّل: نصيبُ البوتيك كما بيع — ما بقي منه وما نقص بعده */
    private static function recorded(int $businessId, int $boutiqueId)
    {
        $changes = '(SELECT COALESCE(SUM('.self::b('x.boutique_share').'), 0) FROM order_edits x WHERE x.boutique_item_id = oi.id AND x.boutique_share IS NOT NULL)';
        $sum = '('.self::netSql('oi').' + '.self::backToSheet('oi')." - {$changes})";

        return self::columns(
            DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.business_id', $businessId)->where('o.is_held', false)->where('oi.boutique_id', $boutiqueId),
            self::RECORDED, 1, 'o.ordered_at', 'oi.id', 'oi.boutique_settlement_id', 'o.id', 'oi.name', 'oi.quantity', $sum, $sum,
        );
    }

    /** وبندٌ حُذف قبل أن تحمله ورقة: ما سُجّل له من صفوف تصحيحه */
    private static function recordedThenRemoved(int $businessId, int $boutiqueId)
    {
        $sum = '(0 - SUM('.self::b('x.boutique_share').'))';

        return self::columns(
            DB::table('order_edits as x')->join('orders as o', 'o.id', '=', 'x.order_id')
                ->where('x.business_id', $businessId)->where('x.boutique_id', $boutiqueId)->whereNotNull('x.boutique_share')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_items as gone')->whereColumn('gone.id', 'x.boutique_item_id'))
                ->groupBy('x.boutique_item_id', 'o.id', 'o.ordered_at'),
            self::RECORDED, 1, 'o.ordered_at', 'MIN(x.id)', 'NULL', 'o.id', 'MIN(x.subject)', 'MAX(x.qty_before)', $sum, $sum,
        );
    }

    /** وبندٌ حُذف بعد ورقته: ما حملته من صفوف إشعاراته */
    private static function recordedThenReturnedWhole(int $businessId, int $boutiqueId)
    {
        $sum = 'SUM('.self::b('r.amount').')';

        return self::columns(
            DB::table('boutique_returns as r')->where('r.business_id', $businessId)->where('r.boutique_id', $boutiqueId)
                ->where('r.source', BoutiqueReturn::QUANTITY)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_items as gone')->whereColumn('gone.id', 'r.order_item_id'))
                ->groupBy('r.order_id', 'r.boutique_settlement_id', 'r.product_id', 'r.name', 'r.variant_name', 'r.rate', 'r.sold_at'),
            self::RECORDED, 1, 'r.sold_at', 'MIN(r.id)', 'r.boutique_settlement_id', 'r.order_id', 'r.name', 'SUM(r.quantity)', $sum, $sum,
        );
    }

    private static function corrected(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('order_edits as x')->where('x.business_id', $businessId)->where('x.boutique_id', $boutiqueId)->whereNotNull('x.boutique_share'),
            self::CHANGED, 2, 'x.created_at', 'x.id', 'NULL', 'x.order_id', 'x.subject', 'x.qty_after - x.qty_before', self::b('x.boutique_share'), self::b('x.boutique_share'),
        );
    }

    /** طلبٌ أُلغي قبل أن تحمل بندَه ورقة — وما حملته ورقةٌ إشعارُه في `notes` */
    private static function cancelled(int $businessId, int $boutiqueId)
    {
        $at = "COALESCE((SELECT MAX(c.created_at) FROM order_edits c WHERE c.order_id = o.id AND c.kind = '".OrderEdit::CANCEL."'), o.updated_at)";
        $net = '(0 - '.self::netSql('oi').')';

        return self::columns(
            DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.business_id', $businessId)->where('o.is_held', false)->where('o.status', Order::CANCELLED)
                ->where('oi.boutique_id', $boutiqueId)->whereNull('oi.boutique_settlement_id'),
            self::CANCELLED, 3, $at, 'oi.id', 'NULL', 'o.id', 'oi.name', '0 - oi.quantity', $net, $net,
        );
    }

    private static function pieces(int $businessId, int $boutiqueId)
    {
        $q = DB::table('boutique_component_dues as d')->where('d.business_id', $businessId)->where('d.boutique_id', $boutiqueId);
        $kind = "CASE d.kind WHEN '".BoutiqueComponentDue::DUE."' THEN '".self::PIECE."' WHEN '".BoutiqueComponentDue::REVERSAL."' THEN '".self::PIECE_BACK."' ELSE '".self::CREDIT."' END";

        return $q->selectRaw("CAST({$kind} AS VARCHAR(20)) as kind, CAST(1 AS BIGINT) as ord, d.occurred_at as at, d.id as ref_id")
            ->selectRaw('CAST(d.boutique_settlement_id AS BIGINT) as sheet_id, CAST(d.order_id AS BIGINT) as order_id, CAST(d.product_name AS VARCHAR(255)) as name')
            ->selectRaw('CAST(d.quantity AS DECIMAL(14,3)) as qty, '.self::b('d.amount').' as shown_b, '.self::b('d.amount').' as effect_b, CAST(NULL AS BIGINT) as invoice_id');
    }

    /** قطعةٌ أُلغي طلبُها ولم تعد: مستحقُّها باقٍ — لا أثرَ على ما للبوتيك، وثمنُها خسارةٌ على المتجر */
    private static function keptPieces(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('boutique_component_dues as d')->where('d.business_id', $businessId)->where('d.boutique_id', $boutiqueId)
                ->where('d.kind', BoutiqueComponentDue::DUE)->whereNotNull('d.kept_state'),
            self::KEPT, 4, 'd.updated_at', 'd.id', 'd.boutique_settlement_id', 'd.order_id', 'd.product_name', 'd.quantity', self::b('d.amount'), '0',
        );
    }

    /** صدورُ ورقة: لا يغيّر ما للبوتيك إلّا بفرق تقريبها عن جمع بنودها */
    private static function sheets(int $businessId, int $boutiqueId)
    {
        $carried = '(SELECT COALESCE(SUM('.self::netSql('oi').' + '.self::backToSheet('oi').'), 0) FROM order_items oi WHERE oi.boutique_settlement_id = bs.id)';
        $gone = '(SELECT COALESCE(SUM('.self::b('r.amount')."), 0) FROM boutique_returns r WHERE r.boutique_settlement_id = bs.id AND r.source = '".BoutiqueReturn::QUANTITY."'"
            .' AND NOT EXISTS (SELECT 1 FROM order_items g WHERE g.id = r.order_item_id))';

        $invoiced = '(SELECT COALESCE(SUM('.self::b('il.settled_net').'), 0) FROM boutique_invoice_lines il WHERE il.boutique_settlement_id = bs.id)';

        return self::columns(
            DB::table('boutique_settlements as bs')->where('bs.business_id', $businessId)->where('bs.boutique_id', $boutiqueId)
                ->where('bs.kind', BoutiqueSettlement::SETTLEMENT),
            self::SHEET, 5, 'bs.issued_at', 'bs.id', 'bs.id', 'NULL', 'bs.number', 'NULL',
            self::b('bs.net').' + '.self::b('bs.components_net'),
            self::b('bs.net')." - {$carried} - {$gone} - {$invoiced}",
        );
    }

    /** سدادُ المنفرد من ورقةٍ: ما خرج فعلًا (`transactions`) — وورقةٌ استُنفد صافيها بلا مال */
    private static function sheetPayments(int $businessId, int $boutiqueId)
    {
        $reduced = '(SELECT COALESCE(SUM(r.amount), 0) FROM boutique_returns r WHERE r.boutique_settlement_id = bs.id AND r.status = \''.BoutiqueReturn::REDUCED.'\')';
        $offset = '(SELECT COALESCE(SUM(v.amount), 0) FROM boutique_return_recoveries v WHERE v.boutique_settlement_id = bs.id AND v.kind = \''.BoutiqueReturnRecovery::OFFSET.'\')';
        $paid = '(0 - '.self::b("COALESCE(t.amount, e.amount - {$reduced} - {$offset})").')';

        return self::columns(
            DB::table('boutique_settlements as bs')->join('expenses as e', 'e.id', '=', 'bs.expense_id')
                ->leftJoin('transactions as t', 't.id', '=', 'e.transaction_id')
                ->where('bs.business_id', $businessId)->where('bs.boutique_id', $boutiqueId)->where('e.status', Expense::PAID),
            self::PAID, 6, 'COALESCE(t.occurred_at, e.updated_at)', 'bs.id', 'bs.id', 'NULL', 'bs.number', 'NULL', $paid, $paid,
        );
    }

    /** سدادُ قطع ورقةٍ، وسندُ استردادٍ من البوتيك */
    private static function piecePayments(int $businessId, int $boutiqueId)
    {
        $refund = "bs.kind = '".BoutiqueSettlement::REFUND."'";
        $effect = "CASE WHEN {$refund} THEN ".self::b('bs.components_credit').' ELSE 0 - '.self::b('bs.components_net').' END';

        return DB::table('boutique_settlements as bs')->where('bs.business_id', $businessId)->where('bs.boutique_id', $boutiqueId)
            ->whereNotNull('bs.components_paid_at')
            ->selectRaw("CAST(CASE WHEN {$refund} THEN '".self::REFUND."' ELSE '".self::PIECES_PAID."' END AS VARCHAR(20)) as kind, CAST(6 AS BIGINT) as ord, bs.components_paid_at as at, bs.id as ref_id")
            ->selectRaw('CAST(bs.id AS BIGINT) as sheet_id, CAST(NULL AS BIGINT) as order_id, CAST(bs.number AS VARCHAR(255)) as name')
            ->selectRaw("CAST(NULL AS DECIMAL(14,3)) as qty, CAST({$effect} AS BIGINT) as shown_b, CAST({$effect} AS BIGINT) as effect_b, CAST(NULL AS BIGINT) as invoice_id");
    }

    /** إشعارُ مرتجعٍ بعد ورقته كُتب — ولا أثرَ له حتّى يُحسم */
    private static function notes(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('boutique_returns as r')->where('r.business_id', $businessId)->where('r.boutique_id', $boutiqueId),
            self::NOTE, 7, 'r.returned_at', 'r.id', 'r.boutique_settlement_id', 'r.order_id', 'r.name', 'r.quantity', self::b('r.amount'), '0',
        );
    }

    /** وحُسم: على البوتيك يُنقص ما له (خفضًا لورقةٍ أو مطالبةً)، وعلى المتجر لا يغيّره */
    private static function decisions(int $businessId, int $boutiqueId)
    {
        $borne = "'".implode("','", BoutiqueReturn::BORNE)."'";

        return self::columns(
            DB::table('boutique_returns as r')->where('r.business_id', $businessId)->where('r.boutique_id', $boutiqueId)
                ->whereIn('r.status', [...BoutiqueReturn::BORNE, BoutiqueReturn::SHOP]),
            self::DECIDED, 8, 'COALESCE(r.decided_at, r.updated_at)', 'r.id', 'r.boutique_settlement_id', 'r.order_id', 'r.name', 'r.quantity',
            self::b('r.amount'), "CASE WHEN r.status IN ({$borne}) THEN 0 - ".self::b('r.amount').' ELSE 0 END',
        );
    }

    /** مقاصّةُ رصيدٍ للمتجر من ورقة — من 1370 إلى 2160 عند سدادها، فلا تغيّر الفرقَ بينهما */
    private static function offsets(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('boutique_credit_offsets as c')->join('boutique_settlements as bs', 'bs.id', '=', 'c.boutique_settlement_id')
                ->where('c.business_id', $businessId)->where('c.boutique_id', $boutiqueId)->where('bs.kind', BoutiqueSettlement::SETTLEMENT),
            self::OFFSET, 5, 'c.created_at', 'c.id', 'bs.id', 'NULL', 'bs.number', 'NULL', self::b('c.amount'), '0',
        );
    }

    /** تحصيلُ مطالبةٍ من البوتيك يُنقص ما له عليه، وخصمُها من ورقةٍ ينقل ولا يغيّر */
    private static function recoveries(int $businessId, int $boutiqueId)
    {
        $receipt = "v.kind = '".BoutiqueReturnRecovery::RECEIPT."'";

        return DB::table('boutique_return_recoveries as v')->join('boutique_returns as r', 'r.id', '=', 'v.boutique_return_id')
            ->where('v.business_id', $businessId)->where('v.boutique_id', $boutiqueId)
            ->selectRaw("CAST(CASE WHEN {$receipt} THEN '".self::RECEIPT."' ELSE '".self::CLAIM_OFFSET."' END AS VARCHAR(20)) as kind, CAST(9 AS BIGINT) as ord, v.created_at as at, v.id as ref_id")
            ->selectRaw('CAST(v.boutique_settlement_id AS BIGINT) as sheet_id, CAST(r.order_id AS BIGINT) as order_id, CAST(r.name AS VARCHAR(255)) as name')
            ->selectRaw('CAST(NULL AS DECIMAL(14,3)) as qty, '.self::b('v.amount')." as shown_b, CASE WHEN {$receipt} THEN ".self::b('v.amount').' ELSE 0 END as effect_b, CAST(NULL AS BIGINT) as invoice_id');
    }

    /** بندُ فاتورة عميلٍ بلا طلب: نصيبُ البوتيك بلقطته — ممّا على المتجر من يوم صدورها، ولا يُسدَّد قبل تحصيله */
    private static function invoiced(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('boutique_invoice_lines as l')->where('l.business_id', $businessId)->where('l.boutique_id', $boutiqueId),
            self::INVOICED, 1, 'l.issued_at', 'l.id', 'l.boutique_settlement_id', 'NULL', 'l.name', 'l.quantity', self::b('l.net'), self::b('l.net'), 'l.customer_invoice_id',
        );
    }

    /** واكتمل تحصيلُ فاتورته: صار قابلًا للتسوية — ولا يغيّر ما له */
    private static function collected(int $businessId, int $boutiqueId)
    {
        return self::columns(
            DB::table('boutique_invoice_lines as l')->where('l.business_id', $businessId)->where('l.boutique_id', $boutiqueId)->whereNotNull('l.collected_at'),
            self::COLLECTED, 4, 'l.collected_at', 'l.id', 'l.boutique_settlement_id', 'NULL', 'l.name', 'l.quantity', self::b('l.net - l.returned_net'), '0', 'l.customer_invoice_id',
        );
    }

    /** وفاتورةٌ أُلغيت قبل ورقته — بما بقي منه ساعتَها */
    private static function voided(int $businessId, int $boutiqueId)
    {
        $left = '(0 - '.self::b('l.net - l.returned_net').')';

        return self::columns(
            DB::table('boutique_invoice_lines as l')->where('l.business_id', $businessId)->where('l.boutique_id', $boutiqueId)->whereNotNull('l.voided_at'),
            self::VOIDED, 3, 'l.voided_at', 'l.id', 'NULL', 'NULL', 'l.name', 'l.quantity', $left, $left, 'l.customer_invoice_id',
        );
    }

    /** وما ردّه إشعارٌ دائن قبل ورقته — وما بعدها إشعارُه في `notes` */
    private static function invoiceReturns(int $businessId, int $boutiqueId)
    {
        $net = '(0 - '.self::b('r.net').')';

        return self::columns(
            DB::table('boutique_invoice_returns as r')->join('boutique_invoice_lines as l', 'l.id', '=', 'r.boutique_invoice_line_id')
                ->where('l.business_id', $businessId)->where('l.boutique_id', $boutiqueId)->where('r.after_sheet', false),
            self::INVOICE_RETURN, 2, 'r.created_at', 'r.id', 'NULL', 'NULL', 'l.name', 'r.quantity', $net, $net, 'l.customer_invoice_id',
        );
    }

    /**
     * تعليقُ ما أُلغي تحصيلُه بعد ورقته، وفكُّه، وسدادُه بعد فكّه.
     *
     * التعليقُ وفكُّه لا يغيّران ما للبوتيك: هو دَينٌ في الحالين. والسدادُ يُنقصه بما خرج.
     */
    private static function holds(int $businessId, int $boutiqueId): array
    {
        $of = fn () => DB::table('boutique_sheet_holds as h')->join('boutique_invoice_lines as l', 'l.id', '=', 'h.boutique_invoice_line_id')
            ->where('h.business_id', $businessId)->where('h.boutique_id', $boutiqueId);
        $paid = '(0 - '.self::b('h.paid_amount').')';

        return [
            self::columns($of(), self::HELD, 7, 'h.opened_at', 'h.id', 'h.boutique_settlement_id', 'NULL', 'l.name', 'NULL', self::b('h.amount'), '0', 'h.customer_invoice_id'),
            self::columns($of()->whereNotNull('h.closed_at'), self::HOLD_CLOSED, 8, 'h.closed_at', 'h.id', 'h.boutique_settlement_id', 'NULL', 'l.name', 'NULL', self::b('h.amount'), '0', 'h.customer_invoice_id'),
            self::columns($of()->whereNotNull('h.paid_at'), self::HOLD_PAID, 9, 'h.paid_at', 'h.id', 'h.boutique_settlement_id', 'NULL', 'l.name', 'NULL', $paid, $paid, 'h.customer_invoice_id'),
        ];
    }

    /* ═══════════ العرض ═══════════ */

    /**
     * السطورُ بمستنداتها وقيودها — استعلامٌ لكلّ نوعِ مستندٍ لا لكلّ سطر.
     *
     * ومن لا يملك «المبيعات» أو «الماليّة» يرى رقمَ المستند بلا بابه.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function present(array $rows, int $businessId, int $opening, ?User $user): array
    {
        $rows = collect($rows);
        $ids = fn (string ...$kinds) => $rows->whereIn('kind', $kinds)->pluck('ref_id')->map(fn ($id) => (int) $id)->all();

        $orders = Order::where('business_id', $businessId)->whereIn('id', $rows->pluck('order_id')->filter()->unique())->pluck('number', 'id');
        $sheets = BoutiqueSettlement::where('business_id', $businessId)->whereIn('id', $rows->pluck('sheet_id')->filter()->unique())->get()->keyBy('id');
        $expenses = Expense::withTrashed()->whereIn('id', $sheets->pluck('expense_id')->filter())->get(['id', 'reference', 'transaction_id'])->keyBy('id');
        $recoveries = BoutiqueReturnRecovery::whereIn('id', $ids(self::RECEIPT, self::CLAIM_OFFSET))->get()->keyBy('id');
        $holds = BoutiqueSheetHold::whereIn('id', $ids(self::HELD, self::HOLD_CLOSED, self::HOLD_PAID))->get()->keyBy('id');
        $transactions = Transaction::withTrashed()->where('business_id', $businessId)->whereIn('id', collect()
            ->merge($expenses->pluck('transaction_id'))->merge($sheets->pluck('transaction_id'))->merge($recoveries->pluck('transaction_id'))->merge($holds->pluck('transaction_id'))->filter()->unique())
            ->get(['id', 'reference', 'journal_entry_id'])->keyBy('id');
        $notes = BoutiqueReturn::whereIn('id', $ids(self::NOTE, self::DECIDED))->get()->keyBy('id');
        $dues = BoutiqueComponentDue::whereIn('id', $ids(self::PIECE, self::PIECE_BACK, self::CREDIT, self::KEPT))->get(['id', 'reason', 'kept_state', 'kept_reason', 'user_id'])->keyBy('id');
        $edits = OrderEdit::whereIn('id', $ids(self::CHANGED))->get(['id', 'reason', 'employee_name'])->keyBy('id');
        $invoices = CustomerInvoice::where('business_id', $businessId)->whereIn('id', $rows->pluck('invoice_id')->filter()->unique())->pluck('number', 'id');

        $entries = self::entries($businessId, [
            Order::class => $rows->whereIn('kind', [self::PIECE, self::PIECE_BACK])->pluck('order_id')->filter()->all(),
            BoutiqueSettlement::class => $sheets->keys()->all(),
            Expense::class => $expenses->keys()->all(),
            BoutiqueReturn::class => $notes->keys()->all(),
            BoutiqueReturnRecovery::class => $recoveries->keys()->all(),
            BoutiqueComponentDue::class => $dues->keys()->all(),
            BoutiqueInvoiceLine::class => $ids(self::INVOICED, self::VOIDED),
            BoutiqueInvoiceReturn::class => $ids(self::INVOICE_RETURN),
            BoutiqueSheetHold::class => $holds->keys()->all(),
        ], $transactions->pluck('journal_entry_id')->filter()->all());
        $of = fn (string $class, ?int $id, ?string $source = null) => $id === null ? null
            : collect($entries['by'][$class.'|'.$id] ?? [])->first(fn ($e) => $source === null || $e->source === $source);

        $opens = ['orders' => (bool) $user?->allows('orders'), 'finance' => (bool) $user?->allows('finance')];

        return $rows->map(function (object $r) use ($orders, $sheets, $expenses, $recoveries, $transactions, $notes, $dues, $edits, $invoices, $holds, $of, $entries, $opening, $opens) {
            $hold = in_array($r->kind, [self::HELD, self::HOLD_CLOSED, self::HOLD_PAID], true) ? $holds->get((int) $r->ref_id) : null;
            $invoice = $r->invoice_id === null ? null : $invoices->get((int) $r->invoice_id);
            $sheet = $r->sheet_id === null ? null : $sheets->get((int) $r->sheet_id);
            $recovery = $recoveries->get((int) $r->ref_id);
            $note = in_array($r->kind, [self::NOTE, self::DECIDED], true) ? $notes->get((int) $r->ref_id) : null;
            $due = in_array($r->kind, [self::PIECE, self::PIECE_BACK, self::CREDIT, self::KEPT], true) ? $dues->get((int) $r->ref_id) : null;
            $edit = $r->kind === self::CHANGED ? $edits->get((int) $r->ref_id) : null;
            $expense = $r->kind === self::PAID && $sheet ? $expenses->get($sheet->expense_id) : null;

            $transaction = $transactions->get(match ($r->kind) {
                self::PAID => $expense?->transaction_id,
                self::PIECES_PAID, self::REFUND => $sheet?->transaction_id,
                self::RECEIPT => $recovery?->transaction_id,
                self::HOLD_PAID => $hold?->transaction_id,
                default => null,
            });

            $entry = match ($r->kind) {
                // المستحقُّ المنفرد لا قيدَ له حتّى ورقته، وقطعُ الطلب في قيد تكلفته
                self::PIECE, self::PIECE_BACK => $of(Order::class, (int) $r->order_id, Books::SALE_COST),
                self::SHEET => $of(BoutiqueSettlement::class, (int) $r->ref_id, Boutiques::ACCRUAL),
                self::PAID => $of(Expense::class, $expense?->id),
                self::PIECES_PAID, self::REFUND => $of(BoutiqueSettlement::class, (int) $r->ref_id, BoutiqueDues::SOURCE),
                self::DECIDED => $of(BoutiqueReturn::class, (int) $r->ref_id),
                self::CREDIT, self::KEPT => $of(BoutiqueComponentDue::class, (int) $r->ref_id),
                self::RECEIPT => $entries['ids'][(int) $transaction?->journal_entry_id] ?? $of(BoutiqueReturnRecovery::class, (int) $r->ref_id),
                self::CLAIM_OFFSET => $of(BoutiqueReturnRecovery::class, (int) $r->ref_id),
                // الاعترافُ أوّلُ قيود البند، وردُّه عند إلغاء فاتورته آخرُها
                self::INVOICED => $of(BoutiqueInvoiceLine::class, (int) $r->ref_id),
                self::VOIDED => collect($entries['by'][BoutiqueInvoiceLine::class.'|'.$r->ref_id] ?? [])->slice(1)->last(),
                self::INVOICE_RETURN => $of(BoutiqueInvoiceReturn::class, (int) $r->ref_id),
                self::HOLD_PAID => $of(BoutiqueSheetHold::class, (int) $r->ref_id),
                default => null,
            };

            $number = $r->order_id === null ? null : $orders->get((int) $r->order_id);

            return [
                'key' => $r->kind.':'.$r->ref_id,
                'kind' => $r->kind,
                'state' => $note?->status ?? $due?->kept_state ?? ($r->kind === self::HOLD_CLOSED ? $hold?->closed_as : null),
                'at' => Carbon::parse($r->at)->format('Y-m-d H:i'),
                'name' => $r->name,
                'quantity' => $r->qty === null ? null : round((float) $r->qty, 3) + 0.0,
                'amount' => self::money((int) $r->shown_b),
                'effect' => self::money((int) $r->effect_b),
                'balance' => self::money($opening + (int) $r->running_b),
                'order' => $number,
                'invoice' => $invoice,
                'sheet' => $sheet?->number,
                'expense' => $expense?->reference,
                'entry' => $entry?->number,
                'transaction' => $transaction?->reference,
                'why' => $note?->decision_note ?? $note?->reason ?? $due?->reason ?? $due?->kept_reason ?? $edit?->reason ?? $recovery?->reference
                    ?? ($r->kind === self::HELD ? $hold?->why : ($r->kind === self::HOLD_CLOSED ? $hold?->note : null)),
                'who' => $edit?->employee_name,
                'order_url' => match (true) {
                    ! $opens['orders'] => null,
                    $number !== null => route('admin.orders.show', $number),
                    $invoice !== null => route('admin.customer-invoices.show', (int) $r->invoice_id),
                    default => null,
                },
                'entry_url' => $entry !== null && $opens['finance'] ? route('admin.finance.journal', ['q' => $entry->number]) : null,
                'transaction_url' => $transaction !== null && $opens['finance'] ? route('admin.finance.transactions', ['q' => $transaction->reference]) : null,
            ];
        })->values()->all();
    }

    /**
     * قيودُ المستندات الحيّة — والمعكوسُ لا يُشار إليه قيدًا قائمًا.
     *
     * @param  array<class-string, list<int>>  $sources
     * @param  list<int>  $ids
     * @return array{by: array<string, list<JournalEntry>>, ids: array<int, JournalEntry>}
     */
    private static function entries(int $businessId, array $sources, array $ids): array
    {
        $sources = array_filter(array_map(fn ($list) => array_values(array_unique(array_map('intval', $list))), $sources));
        if ($sources === [] && $ids === []) {
            return ['by' => [], 'ids' => []];
        }

        $found = JournalEntry::where('business_id', $businessId)->whereNull('reversed_at')->whereNull('reverses_id')
            ->where(function ($w) use ($sources, $ids) {
                foreach ($sources as $class => $list) {
                    $w->orWhere(fn ($s) => $s->where('sourceable_type', $class)->whereIn('sourceable_id', $list));
                }
                $w->orWhereIn('id', $ids);
            })->orderBy('id')->get(['id', 'number', 'source', 'sourceable_type', 'sourceable_id']);

        return [
            'by' => $found->groupBy(fn (JournalEntry $e) => $e->sourceable_type.'|'.$e->sourceable_id)->map(fn ($g) => $g->all())->all(),
            'ids' => $found->keyBy('id')->all(),
        ];
    }

    /* ═══════════ البيسة ═══════════ */

    /** نصيبُ البوتيك من مبلغ بندِ طلب، بالبيسات — القاعدةُ الواحدة (`BoutiqueMoney`) */
    private static function net(OrderItem $item, float $total): int
    {
        return BoutiqueMoney::split(self::baisa($total), BoutiqueMoney::rate($item->boutique_rate), BoutiqueMoney::rate($item->boutique_vat_rate ?? 0))['net'];
    }

    /** وبلغة القاعدة */
    private static function netSql(string $alias): string
    {
        return BoutiqueMoney::sql($alias)['net'];
    }

    private static function baisa(float|int|string|null $x): int
    {
        return (int) round((float) $x * 1000);
    }

    private static function money(int $baisa): float
    {
        return Baisa::toMoney($baisa) + 0.0;
    }
}
