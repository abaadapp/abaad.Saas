<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueReturnRecovery;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مبيعاتُ البوتيك وفواتيرُه — قراءةٌ لا كتابة.
 *
 * ═══ حالتان لا تُجمعان في رقمٍ واحد ═══
 *
 *   - **المنفرد:** صنفُ البوتيك بِيع بنفسه. بندُه في الفاتورة يحمل صاحبَه
 *     ونسبتَه ساعةَ البيع (`order_items.boutique_*`)، وللبوتيك الإجماليُّ
 *     ناقصًا عمولةَ المتجر — كما في `Boutiques::soldTotals` والكشف.
 *   - **قطعةٌ في منتج المتجر:** مكوّنٌ في باقة (وصفة) أو إضافةٌ عليها. بندُ
 *     الفاتورة منتجُ المتجر، وللبوتيك ثمنُ قطعته لقطةً ساعةَ البيع بلا عمولة
 *     (`boutique_component_dues`) — كما في `BoutiqueDues::statement`.
 *
 * فالقطعةُ لا تُعدّ بيعًا منفردًا، ولا يُضاف ثمنُ الباقة إلى مبيعات البوتيك:
 * ما يُجمع للبوتيك صافي المنفرد ومستحقُّ القطع، وما يُجمع للمتجر إيرادُ بنده
 * ناقصًا مستحقَّ قطعه.
 *
 * ولا يكتب هذا الصنف شيئًا: لا قيدَ ولا مستحقَّ ولا ختم. يقرأ ما كتبه البيعُ
 * والتصحيحُ والتسوية — ولا يُعيد حسابَ لقطة.
 */
final class BoutiqueSales
{
    public const PER_PAGE = 25;

    /** أنواعُ العمليّة كما تُرشَّح وتُعرض */
    public const STANDALONE = 'standalone';

    public const BUNDLE = 'bundle';

    public const ADDON = 'addon';

    /** بندٌ في فاتورة عميلٍ بلا طلب — بلقطته ساعةَ الإصدار (`BoutiqueInvoiceDues`) */
    public const INVOICE = 'invoice';

    public const TYPES = [self::STANDALONE, self::INVOICE, self::BUNDLE, self::ADDON];

    /** من أيّ بابٍ صدرت فاتورةُ العميل — وليست قناةً في `SalesChannel`: لا طلبَ وراءها */
    private const INVOICE_DOORS = ['manual' => 'فاتورة عميل', 'whatsapp' => 'طلب واتساب'];

    /** حالُ التسوية: لم تحمله ورقة، أو حملته ورقةٌ لم تُسدَّد، أو سُدِّدت */
    public const PENDING = 'pending';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    /** فاتورةُ عميلٍ لم يكتمل تحصيلُها — لا تحملها ورقةٌ بعد */
    public const AWAITING = BoutiqueInvoiceDues::AWAITING;

    public const STATES = [self::AWAITING, self::PENDING, self::ISSUED, self::PAID];

    /** حالُ الفاتورة نفسِها */
    public const CANCELLED = 'cancelled';

    public const PARTIAL = 'partial';

    public const ACTIVE = 'active';

    /** رُدّ كلُّ ما في البند */
    public const RETURNED = 'returned';

    /** تصحيحاتٌ تُنقص كمّيّةَ ما بيع — منها يُعرف المرتجعُ الجزئيّ */
    private const REDUCING = [OrderEdit::LINE, OrderEdit::ADDON, OrderEdit::REPLACE_LINE];

    /**
     * ما يطلبه الرابط — مُنقًّى، وما لا يصحّ يُقرأ «الكلّ».
     *
     * بوتيكٌ من متجرٍ آخر لا يُرشَّح به: يُقرأ «الكلّ» كأنّه لم يُطلب، فلا
     * يُعرف من الردّ أهو موجودٌ عند غيرك — كما `Boutiques::scope`.
     *
     * @return array{period: string, boutique: ?int, type: ?string, settle: ?string, state: ?string, q: string, page: int}
     */
    public static function filters(Request $request, int $businessId): array
    {
        $boutique = $request->query('boutique');
        $boutique = is_string($boutique) && ctype_digit($boutique)
            && Boutique::where('business_id', $businessId)->whereKey((int) $boutique)->exists()
            ? (int) $boutique : null;

        $type = $request->query('type');
        $settle = $request->query('settle');
        $state = $request->query('state');
        $page = $request->query('page');

        return [
            'period' => Boutiques::period($request->query('period')),
            'boutique' => $boutique,
            'type' => in_array($type, self::TYPES, true) ? $type : null,
            'settle' => in_array($settle, self::STATES, true) ? $settle : null,
            'state' => in_array($state, [self::ACTIVE, self::PARTIAL, self::CANCELLED], true) ? $state : null,
            'q' => mb_substr(Search::term($request), 0, 80),
            'page' => is_string($page) && ctype_digit($page) && (int) $page > 0 ? min((int) $page, 100000) : 1,
        ];
    }

    /** أوّلُ الشهر وآخرُ لحظةٍ فيه */
    private static function bounds(string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return [$from, (clone $from)->endOfMonth()];
    }

    /* ═══════════ الفواتير ═══════════ */

    /**
     * صفوفُ الفواتير في الشهر — بندٌ منفرد، أو قطعةٌ في منتج المتجر — صفحةً صفحة.
     *
     * والصفحةُ من القاعدة لا من الذاكرة: آلافُ الفواتير لا تُحمَّل لتُعرض
     * خمسٌ وعشرون منها. والفرزُ والترشيحُ والعدُّ في استعلامٍ واحد.
     */
    public static function rows(int $businessId, array $f): LengthAwarePaginator
    {
        [$from, $to] = self::bounds($f['period']);
        $orders = Order::where('business_id', $businessId)->where('is_held', false)
            ->whereBetween('ordered_at', [$from, $to])->select('id');

        $branches = array_values(array_filter([
            $f['type'] === null || $f['type'] === self::STANDALONE ? self::standalone($businessId, $f, $orders) : null,
            $f['type'] === null || in_array($f['type'], [self::BUNDLE, self::ADDON], true) ? self::pieces($businessId, $f, $orders) : null,
            $f['type'] === null || $f['type'] === self::INVOICE ? self::invoiced($businessId, $f, $from, $to) : null,
        ]));

        $union = array_shift($branches);
        foreach ($branches as $b) {
            $union->unionAll($b);
        }

        $q = $f['q'];
        $like = Search::like();

        $page = DB::query()->fromSub($union, 'r')
            ->when($f['settle'] !== null, fn ($w) => $w->where('r.settle_state', $f['settle']))
            ->when(($f['state'] ?? null) !== null, fn ($w) => self::whereState($w, $f['state']))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('r.order_number', $like, '%'.$q.'%')
                ->orWhere('r.product_name', $like, '%'.$q.'%')
                ->orWhere('r.parent_name', $like, '%'.$q.'%')))
            ->orderByDesc('r.ordered_at')->orderByDesc('r.order_id')->orderBy('r.type')->orderBy('r.ref_id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $f['page']);

        $states = self::invoiceStates(collect($page->items())->pluck('order_id')->filter()->unique()->all());
        // وبندُ فاتورة العميل بأرقام لقطته هي — لا تُعاد من مبلغٍ ونسبة
        $kept = BoutiqueInvoiceLine::whereIn('id', collect($page->items())->where('type', self::INVOICE)->pluck('ref_id'))->get()->keyBy('id');

        $page->setCollection(collect($page->items())->map(fn ($r) => $r->type === self::INVOICE
            ? self::presentInvoice($r, $kept->get($r->ref_id))
            : self::present($r, $states)));

        return $page;
    }

    /** بنودُ البوتيك المنفردة — بلقطة البند ساعةَ البيع */
    private static function standalone(int $businessId, array $f, $orders)
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('boutique_settlements as bs', 'bs.id', '=', 'oi.boutique_settlement_id')
            ->leftJoin('expenses as e', 'e.id', '=', 'bs.expense_id')
            ->where('o.business_id', $businessId)
            ->whereIn('oi.order_id', $orders)
            ->whereNotNull('oi.boutique_id')
            ->when($f['boutique'] !== null, fn ($q) => $q->where('oi.boutique_id', $f['boutique']))
            ->selectRaw('? as type', [self::STANDALONE])
            ->selectRaw('oi.id as ref_id, o.id as order_id, o.number as order_number, o.ordered_at, o.status as order_status, o.channel as channel')
            ->selectRaw('oi.boutique_id, oi.boutique_name, oi.name as product_name, oi.variant_name as variant')
            ->selectRaw('NULL as parent_name, NULL as parent_variant, NULL as parent_total, NULL as addon_name')
            ->selectRaw('oi.quantity as quantity, oi.price as unit_price, oi.total as amount, oi.boutique_rate as rate, oi.boutique_vat_rate as vat_rate')
            ->selectRaw('0 as credited, NULL as kept_state, oi.boutique_settlement_id as settlement_id, bs.number as settlement_number')
            ->selectRaw('CAST(NULL AS BIGINT) as invoice_id, NULL as line_state, NULL as form_token')
            /*
             * وحالُ تسويته كما يقرؤها `BoutiqueSettlement::paid` لجزء المنفرد:
             * سُدِّد إن كان مصروفُ ورقته مدفوعًا — لا ما يُخمَّن من تاريخ.
             */
            ->selectRaw(
                'CASE WHEN oi.boutique_settlement_id IS NULL THEN ? WHEN e.status = ? THEN ? ELSE ? END as settle_state',
                [self::PENDING, Expense::PAID, self::PAID, self::ISSUED],
            );
    }

    /**
     * قطعُ البوتيك في منتجات المتجر — صفٌّ لكلّ قطعةٍ في بندها.
     *
     * دفترُ المستحقّات يكتب صفًّا لكلّ حدث: المستحقُّ، وعكسُه، وإعادةُ كتابته
     * بعد تصحيح الكمّيّة. فتُجمع صفوفُ القطعة الواحدة في بندها: ما بقي مستحقًّا
     * (`due` و`reversal`)، وما صار رصيدًا للمتجر بعد ورقتها (`credit`) — منفصلين.
     */
    private static function pieces(int $businessId, array $f, $orders)
    {
        $kinds = [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL];

        $groups = DB::table('boutique_component_dues as d')
            ->where('d.business_id', $businessId)
            ->whereIn('d.order_id', $orders)
            ->when($f['boutique'] !== null, fn ($q) => $q->where('d.boutique_id', $f['boutique']))
            ->when($f['type'] === self::BUNDLE, fn ($q) => $q->where('d.source', BoutiqueComponentDue::RECIPE))
            ->when($f['type'] === self::ADDON, fn ($q) => $q->where('d.source', BoutiqueComponentDue::ADDON))
            ->groupBy('d.order_id', 'd.order_item_id', 'd.order_item_addon_id', 'd.source', 'd.product_id', 'd.unit_price', 'd.boutique_id')
            ->selectRaw('MIN(d.id) as ref_id, d.order_id, d.order_item_id, d.order_item_addon_id, d.source, d.product_id, d.unit_price, d.boutique_id')
            ->selectRaw('MAX(d.boutique_name) as boutique_name, MAX(d.product_name) as product_name')
            ->selectRaw('SUM(CASE WHEN d.kind IN (?, ?) THEN d.quantity ELSE 0 END) as quantity', $kinds)
            ->selectRaw('SUM(CASE WHEN d.kind IN (?, ?) THEN d.amount ELSE 0 END) as amount', $kinds)
            ->selectRaw('SUM(CASE WHEN d.kind IN (?, ?) AND d.boutique_settlement_id IS NULL THEN d.amount ELSE 0 END) as unsettled', $kinds)
            ->selectRaw('0 - SUM(CASE WHEN d.kind = ? THEN d.amount ELSE 0 END) as credited', [BoutiqueComponentDue::CREDIT])
            ->selectRaw('MAX(CASE WHEN d.kind = ? THEN d.boutique_settlement_id END) as settlement_id', [BoutiqueComponentDue::DUE])
            ->selectRaw('MAX(d.kept_state) as kept_state');

        return DB::query()->fromSub($groups, 'g')
            ->join('orders as o', 'o.id', '=', 'g.order_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'g.order_item_id')
            ->leftJoin('order_item_addons as a', 'a.id', '=', 'g.order_item_addon_id')
            ->leftJoin('boutique_settlements as bs', 'bs.id', '=', 'g.settlement_id')
            ->where('o.business_id', $businessId)
            ->selectRaw('CASE WHEN g.source = ? THEN ? ELSE ? END as type', [BoutiqueComponentDue::RECIPE, self::BUNDLE, self::ADDON])
            ->selectRaw('g.ref_id, o.id as order_id, o.number as order_number, o.ordered_at, o.status as order_status, o.channel as channel')
            ->selectRaw('g.boutique_id, g.boutique_name, g.product_name, NULL as variant')
            ->selectRaw('oi.name as parent_name, oi.variant_name as parent_variant, oi.total as parent_total, a.name as addon_name')
            ->selectRaw('g.quantity, g.unit_price, g.amount, CAST(NULL AS DECIMAL) as rate, CAST(NULL AS DECIMAL) as vat_rate')
            ->selectRaw('g.credited, g.kept_state, g.settlement_id, bs.number as settlement_number')
            ->selectRaw('CAST(NULL AS BIGINT) as invoice_id, NULL as line_state, NULL as form_token')
            /*
             * ما بقي منه بلا ورقةٍ لم يُسوَّ بعد — ولو حملت ورقةٌ أصلَه.
             * وجزءُ المكوّنات يُسدَّد بنفسه (`BoutiqueDues::pay`).
             */
            ->selectRaw(
                'CASE WHEN g.settlement_id IS NULL OR g.unsettled <> 0 THEN ? WHEN bs.components_paid_at IS NOT NULL OR bs.components_due <= 0 THEN ? ELSE ? END as settle_state',
                [self::PENDING, self::PAID, self::ISSUED],
            );
    }

    /**
     * بنودُ فواتير العملاء بلا طلب — بلقطتها، في شهر اكتمال تحصيلها (أو إصدارها ما لم يكتمل).
     *
     * شهرُها شهرُ الكشف: فيه صارت مستحقّةَ الدفع. وما بقي منها بعد ما رُدّ.
     */
    private static function invoiced(int $businessId, array $f, Carbon $from, Carbon $to)
    {
        return DB::table('boutique_invoice_lines as bil')
            ->join('customer_invoices as ci', 'ci.id', '=', 'bil.customer_invoice_id')
            ->leftJoin('boutique_settlements as bs', 'bs.id', '=', 'bil.boutique_settlement_id')
            ->leftJoin('expenses as e', 'e.id', '=', 'bs.expense_id')
            ->where('bil.business_id', $businessId)
            ->whereRaw('COALESCE(bil.collected_at, bil.issued_at) BETWEEN ? AND ?', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($f['boutique'] !== null, fn ($q) => $q->where('bil.boutique_id', $f['boutique']))
            ->selectRaw('? as type', [self::INVOICE])
            ->selectRaw('bil.id as ref_id, CAST(NULL AS BIGINT) as order_id, ci.number as order_number, bil.issued_at as ordered_at, ci.status as order_status, NULL as channel')
            ->selectRaw('bil.boutique_id, bil.boutique_name, bil.name as product_name, NULL as variant')
            ->selectRaw('NULL as parent_name, NULL as parent_variant, CAST(NULL AS DECIMAL) as parent_total, NULL as addon_name')
            ->selectRaw('bil.quantity - bil.returned_quantity as quantity, bil.unit_price, bil.gross - bil.returned_gross as amount, bil.rate as rate, CAST(NULL AS DECIMAL) as vat_rate')
            ->selectRaw('0 as credited, NULL as kept_state, bil.boutique_settlement_id as settlement_id, bs.number as settlement_number')
            ->selectRaw('ci.id as invoice_id')
            ->selectRaw('CASE WHEN bil.voided_at IS NOT NULL THEN ? WHEN bil.returned_quantity >= bil.quantity THEN ? WHEN bil.returned_quantity > 0 THEN ? ELSE ? END as line_state',
                [self::CANCELLED, self::RETURNED, self::PARTIAL, self::ACTIVE])
            ->selectRaw('ci.form_token as form_token')
            ->selectRaw(
                'CASE WHEN bil.boutique_settlement_id IS NOT NULL THEN CASE WHEN e.status = ? THEN ? ELSE ? END WHEN bil.collected_at IS NULL THEN ? ELSE ? END as settle_state',
                [Expense::PAID, self::PAID, self::ISSUED, self::AWAITING, self::PENDING],
            );
    }

    /** مُرشِّحُ حال الفاتورة في القاعدة — بالقاعدة التي تُقرأ بها الصفحة (`invoiceStates`) */
    private static function whereState($query, string $state)
    {
        $reduced = fn ($e) => $e->selectRaw('1')->from('order_edits as oe')
            ->whereColumn('oe.order_id', 'r.order_id')
            ->whereIn('oe.kind', self::REDUCING)
            ->whereColumn('oe.qty_after', '<', 'oe.qty_before');

        // وبندُ فاتورة العميل حالُه في عموده — لا طلبَ وراءه يُسأل
        $order = fn ($w) => $w->whereNotNull('r.order_id');

        return match ($state) {
            self::CANCELLED => $query->where(fn ($w) => $w->where(fn ($o) => $order($o)->where('r.order_status', Order::CANCELLED))
                ->orWhere('r.line_state', self::CANCELLED)),
            self::PARTIAL => $query->where(fn ($w) => $w->where(fn ($o) => $order($o)->where('r.order_status', '!=', Order::CANCELLED)->whereExists($reduced))
                ->orWhereIn('r.line_state', [self::PARTIAL, self::RETURNED])),
            default => $query->where(fn ($w) => $w->where(fn ($o) => $order($o)->where('r.order_status', '!=', Order::CANCELLED)->whereNotExists($reduced))
                ->orWhere('r.line_state', self::ACTIVE)),
        };
    }

    /**
     * حالُ كلّ فاتورةٍ في الصفحة: ملغاة، أو مرتجعةٌ جزئيًّا، أو سارية بحالها.
     *
     * والمرتجعُ الجزئيّ ما يقوله سجلُّ التصحيح (`order_edits`): كمّيّةٌ نقصت
     * من بندٍ أو إضافة، أو صنفٌ استُبدل — لا ما يُستنتج من الأرقام وحدها.
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private static function invoiceStates(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $reduced = OrderEdit::whereIn('order_id', $orderIds)
            ->whereIn('kind', self::REDUCING)
            ->whereColumn('qty_after', '<', 'qty_before')
            ->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->flip();

        return Order::whereIn('id', $orderIds)->get(['id', 'status'])
            ->mapWithKeys(fn (Order $o) => [(int) $o->id => match (true) {
                $o->status === Order::CANCELLED => self::CANCELLED,
                $reduced->has((int) $o->id) => self::PARTIAL,
                default => self::ACTIVE,
            }])->all();
    }

    /* ═══════════ البيسة ═══════════ */

    /*
     * والقسمةُ من `BoutiqueMoney` — القاعدةُ نفسُها التي يقرؤها الكشفُ والورقةُ والتقرير:
     * عمولةُ كلّ بندٍ إلى أقرب بيسة والنصفُ إلى أعلى، والمجاميعُ جمعُ بيساتٍ صحيحة. فلا
     * فرقَ تقريبٍ بين هذا القسم وورقةٍ تصدر. وورقةٌ صدرت قبل توحيد القاعدة تبقى كما صدرت،
     * ويُعرض فرقُها (`old_sheets`).
     */

    /** مبلغٌ بالبيسات — العمودُ بثلاث منازل، فالضربُ في ألفٍ ثمّ التقريبُ إلى عددٍ صحيح لا يخطئ */
    private static function baisa(float|int|string|null $x): int
    {
        return (int) round((float) $x * 1000);
    }

    /** البيساتُ ريالاتٍ للإرسال — و`+ 0.0` تمحو «−٠» */
    private static function money(int $baisa): float
    {
        return Baisa::toMoney($baisa) + 0.0;
    }

    /** الصفُّ كما يُرسَل — أرقامٌ بثلاث خانات، والعمولةُ بنسبة البند ساعةَ بيعه */
    private static function present(object $r, array $states): array
    {
        $amountB = self::baisa($r->amount);
        $amount = self::money($amountB);
        $standalone = $r->type === self::STANDALONE;
        $rateH = $standalone ? BoutiqueMoney::rate($r->rate) : 0;
        $rate = $standalone ? $rateH / 100 : null;
        $part = BoutiqueMoney::split($amountB, $rateH, $standalone ? BoutiqueMoney::rate($r->vat_rate) : 0);
        $commissionB = $part['commission'];
        /*
         * فاتورةٌ ملغاةٌ لم تحملها ورقة: بندُها كما سُجّل ولا نصيبَ فيه لأحد — كما في
         * نافذة الفاتورة والملخّص. وما حملته ورقةٌ يبقى كما صدر، ومرتجعُه منفصل.
         */
        $state = $states[(int) $r->order_id] ?? self::ACTIVE;
        $void = $standalone && $state === self::CANCELLED && $r->settle_state === self::PENDING;
        $commission = self::money($void ? 0 : $commissionB);

        return [
            'key' => $r->type.':'.$r->ref_id,
            'type' => $r->type,
            'order_id' => (int) $r->order_id,
            'order_number' => (string) $r->order_number,
            'ordered_at' => $r->ordered_at ? Carbon::parse($r->ordered_at)->format('Y-m-d H:i') : null,
            'order_status' => (string) $r->order_status,
            // البابُ الذي بيع منه — من الطلب كما كُتب، باسمه من مصدره الواحد
            'channel' => SalesChannel::label($r->channel),
            'invoice_state' => $state,
            'void' => $void,
            'boutique_id' => (int) $r->boutique_id,
            'boutique' => (string) ($r->boutique_name ?? ''),
            'product' => (string) $r->product_name,
            'variant' => $r->variant,
            'parent' => $r->parent_name,
            'parent_variant' => $r->parent_variant,
            'parent_total' => $r->parent_total === null ? null : round((float) $r->parent_total, 3),
            'addon' => $r->addon_name,
            'quantity' => round((float) $r->quantity, 3),
            'unit_price' => round((float) $r->unit_price, 3),
            'amount' => $amount,
            'rate' => $rate,
            'commission' => $commission,
            'net' => self::money($void ? 0 : $part['net']),
            'credited' => round((float) $r->credited, 3) + 0.0,
            'kept_state' => $r->kept_state,
            'settle_state' => $r->settle_state,
            'settlement' => $r->settlement_number,
        ];
    }

    /** صفُّ بندٍ من فاتورة عميل — بلقطته ناقصًا ما رُدّ منه */
    private static function presentInvoice(object $r, ?BoutiqueInvoiceLine $l): array
    {
        $void = $r->line_state === self::CANCELLED;
        $left = fn (string $k) => round((float) ($l?->{$k} ?? 0) - (float) ($l?->{'returned_'.$k} ?? 0), 3) + 0.0;

        return [
            'key' => self::INVOICE.':'.$r->ref_id,
            'type' => self::INVOICE,
            'order_id' => null,
            'invoice_id' => (int) $r->invoice_id,
            'order_number' => (string) $r->order_number,
            'ordered_at' => $r->ordered_at ? Carbon::parse($r->ordered_at)->format('Y-m-d H:i') : null,
            'order_status' => (string) $r->order_status,
            'channel' => __(self::INVOICE_DOORS[str_starts_with((string) $r->form_token, WhatsAppOrderCheckout::FORM_TOKEN) ? 'whatsapp' : 'manual']),
            'invoice_state' => $r->line_state,
            'void' => $void,
            'boutique_id' => (int) $r->boutique_id,
            'boutique' => (string) $r->boutique_name,
            'product' => (string) $r->product_name,
            'variant' => null,
            'parent' => null,
            'parent_variant' => null,
            'parent_total' => null,
            'addon' => null,
            // الملغاةُ بما كُتبت، وما رُدّ منه فما بقي
            'quantity' => round((float) $r->quantity, 3),
            'unit_price' => round((float) $r->unit_price, 3),
            'amount' => $left('gross'),
            'rate' => round((float) $r->rate, 2),
            'commission' => $void ? 0.0 : $left('commission'),
            'net' => $void ? 0.0 : $left('net'),
            'credited' => 0.0,
            'kept_state' => null,
            'settle_state' => $r->settle_state,
            'settlement' => $r->settlement_number,
        ];
    }

    /* ═══════════ الملخّص ═══════════ */

    /**
     * من يقرأ مبيعات البوتيك وعمولاتها ومستحقّاتها؟ صاحبُ المتجر ومن أذن له
     * بالمال: صلاحيّةُ المصروفات — وهي التي يقرّر بها الشاشةُ نفسُها مرتجعاتِ
     * البوتيك (`reviewReturn`)، ومستحقُّ البوتيك مصروفٌ عليه. فالمالكُ والمدير
     * (`*`) يقرآن، ومن خُصّصت صلاحيّاتُه يدويًّا يقرأ إن أُعطي «المصروفات».
     * ودورُ المبيعات والمخزون يفتحان شاشة البوتيك بالمنتجات ولا يقرآن هذا.
     */
    public static function mayRead(?User $user): bool
    {
        return $user !== null && $user->allows('expenses');
    }

    /**
     * حالُ حساب القطع داخل منتجات المتجر: `on` يُحسب، `off` المفتاحُ مطفأ،
     * `taxed` المتجرُ يحتسب الضريبة فيرفض النظامُ هذه البيعات (`BoutiqueDues`).
     * قراءةٌ فقط — لا تفتح شيئًا ولا تتجاوز رفضًا.
     */
    public static function piecesState(int $businessId): string
    {
        if (! BoutiqueDues::active($businessId)) {
            return 'off';
        }

        return Vat::enabled($businessId) ? 'taxed' : 'on';
    }

    /**
     * أرقامُ الشهر للبوتيك المختار أو للكلّ — كلُّ رقمٍ من مصدره، ولا يُجمع اثنان يتداخلان.
     *
     *   - **المبيعات:** المنفردُ من `Boutiques::soldTotals` (ما بِيع ولم يُلغَ)،
     *     ومستحقُّ القطع من دفترها بشهر استحقاقه (`period_at`) كما يقرؤه الكشف.
     *   - **منتجاتُ المتجر التي دخلتها قطع:** إيرادُ بنودها، وما لكلّ البوتيكات
     *     منها، وما يبقى للمتجر — قبل خصم الفاتورة وقبل سائر تكاليفه. ليس ربحًا.
     *   - **التسوية:** ما صدر في أوراق الشهر وما سُدِّد منه وما لم يُسدَّد، وما
     *     ينتظر ورقةً (`Boutiques::statement`). والمتبقّي = ما ينتظر + ما لم يُسدَّد.
     *   - **المرتجعاتُ والأرصدة** منفصلةً: لا تُطرح من المبيعات مرّةً ثانية.
     *
     * @return array<string, mixed>
     */
    public static function summary(int $businessId, array $f): array
    {
        [$from, $to] = self::bounds($f['period']);
        $boutiques = Boutique::where('business_id', $businessId)
            ->when($f['boutique'] !== null, fn ($q) => $q->whereKey($f['boutique']))
            ->orderBy('id')->get();
        $ids = $boutiques->pluck('id')->map(fn ($id) => (int) $id)->all();

        /*
         * ── المنفرد: البنودُ نفسُها التي يقرؤها النظام (`Boutiques::soldLines`)، بالقاعدة
         * نفسِها (`BoutiqueMoney::sql`) في القاعدة نفسها — بلا تحميل البنود.
         */
        $sql = BoutiqueMoney::sql('order_items');
        $grossB = 0;
        $commB = 0;
        $netB = 0;
        $nets = [];
        $quantity = 0.0;
        $lines = 0;
        // استعلامٌ واحد لكلّ البوتيكات — لا اثنان لكلّ بوتيك
        $sold = Order::where('business_id', $businessId)->sold()->whereBetween('ordered_at', [$from, $to])->select('id');
        $perBoutique = $ids === [] ? collect() : DB::table('order_items')->whereIn('order_items.boutique_id', $ids)
            ->whereIn('order_items.order_id', $sold)
            ->groupBy('order_items.boutique_id')
            ->selectRaw('order_items.boutique_id')
            ->selectRaw("COALESCE(SUM({$sql['gross']}), 0) as gross_b")
            ->selectRaw("COALESCE(SUM({$sql['commission']}), 0) as commission_b")
            ->selectRaw("COALESCE(SUM({$sql['net']}), 0) as net_b")
            // وما لم تحمله ورقةٌ منها — وهو ما يقوله كشفُ البوتيك (`Boutiques::statement`)
            ->selectRaw("COALESCE(SUM(CASE WHEN order_items.boutique_settlement_id IS NULL THEN {$sql['net']} ELSE 0 END), 0) as pending_net_b")
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->selectRaw('COUNT(*) as line_count')
            ->get();
        foreach ($perBoutique as $row) {
            $grossB += (int) $row->gross_b;
            $commB += (int) $row->commission_b;
            $netB += (int) $row->net_b;
            $nets[(int) $row->boutique_id] = self::money((int) $row->pending_net_b);
            $quantity = round($quantity + (float) $row->quantity, 3);
            $lines += (int) $row->line_count;
        }
        $standalone = [
            'gross' => self::money($grossB),
            'commission' => self::money($commB),
            'net' => self::money($netB),
            'quantity' => $quantity,
            'lines' => $lines,
        ];

        $standalone['orders'] = $ids === [] ? 0 : (int) OrderItem::whereIn('order_id', $sold)
            ->whereIn('boutique_id', $ids)->distinct()->count('order_id');
        /*
         * فواتيرُ الشهر التي فيها ضريبةٌ وبندُ بوتيك: عمولتُها من مبلغ البند كما
         * سُجّل — شاملًا الضريبة إن كانت الأسعارُ شاملة. سياسةٌ قائمة لم تُعتمد
         * بعد، فتُقال على الشاشة ولا تُغيَّر هنا.
         */
        $standalone['taxed_orders'] = $ids === [] ? 0 : (int) OrderItem::whereIn('order_id', (clone $sold)->where('tax', '>', 0))
            ->whereIn('boutique_id', $ids)->distinct()->count('order_id');

        // ── القطع: ما بقي مستحقًّا في شهر استحقاقه — المستحقُّ وعكسُه يتقاصّان
        $dues = BoutiqueComponentDue::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])
            ->whereBetween('period_at', [$from, $to])
            ->groupBy('source')->selectRaw('source, SUM(amount) as amount, SUM(quantity) as quantity')
            ->get()->keyBy('source');
        $bundle = round((float) ($dues[BoutiqueComponentDue::RECIPE]->amount ?? 0), 3);
        $addon = round((float) ($dues[BoutiqueComponentDue::ADDON]->amount ?? 0), 3);

        $components = [
            'bundle' => $bundle,
            'addon' => $addon,
            'due' => round($bundle + $addon, 3),
            'quantity' => round((float) $dues->sum('quantity'), 3),
        ];

        // ── منتجاتُ المتجر التي دخلتها قطعُ هذا البوتيك — كلُّ بندٍ مرّةً واحدة
        $parents = BoutiqueComponentDue::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])
            ->whereIn('order_id', $sold)->whereNotNull('order_item_id')
            ->distinct()->pluck('order_item_id');
        $revenue = round((float) OrderItem::whereIn('id', $parents)->sum(DB::raw('total + COALESCE(addons_total, 0)')), 3);
        // وما على البند لكلّ البوتيكات — لا لهذا وحده: الباقي للمتجر بعدها كلِّها
        $allDues = round((float) BoutiqueComponentDue::whereIn('order_item_id', $parents)
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])->sum('amount'), 3);

        $bundles = [
            'lines' => $parents->count(),
            'revenue' => $revenue,
            'boutiques_due' => $allDues,
            'shop_remaining' => round($revenue - $allDues, 3),
        ];

        // ── التسوية: أوراقُ الشهر بما حملته، وما ينتظر ورقة
        $sheets = BoutiqueSettlement::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->where('kind', BoutiqueSettlement::SETTLEMENT)->where('period', $f['period'])
            ->with('expense')->get();

        // ما خُفّض من كلّ ورقةٍ بمرتجعٍ أو مقاصّة — استعلامان للشهر لا اثنان لكلّ ورقة (`BoutiqueReturns::reductions`)
        $sheetIds = $sheets->pluck('id');
        $reductions = BoutiqueReturn::whereIn('boutique_settlement_id', $sheetIds)->where('status', BoutiqueReturn::REDUCED)
            ->groupBy('boutique_settlement_id')->selectRaw('boutique_settlement_id, SUM(amount) as total')->pluck('total', 'boutique_settlement_id');
        $offsets = BoutiqueReturnRecovery::whereIn('boutique_settlement_id', $sheetIds)->where('kind', BoutiqueReturnRecovery::OFFSET)
            ->groupBy('boutique_settlement_id')->selectRaw('boutique_settlement_id, SUM(amount) as total')->pluck('total', 'boutique_settlement_id');
        $payable = fn (BoutiqueSettlement $sheet) => max(0.0, round((float) $sheet->expense->amount
            - round((float) ($reductions[$sheet->id] ?? 0) + (float) ($offsets[$sheet->id] ?? 0), 3), 3));

        // وما سُدّدت ورقتُه دونه لأنّ تحصيلَه أُلغي: صدر ولم يُسدَّد (`BoutiqueHolds::owed`)
        $withheld = BoutiqueHolds::owedOn($sheetIds);

        $issued = 0.0;
        $paid = 0.0;
        $unpaid = 0.0;
        foreach ($sheets as $s) {
            $issued = round($issued + (float) $s->net + (float) $s->components_net, 3);

            // جزءُ المنفرد بما يُسدَّد فعلًا بعد ما خُفّض منه بمرتجع (`BoutiqueReturns::payable`)
            $part = (int) $s->lines_count === 0 || $s->expense === null ? 0.0 : $payable($s);
            $standalonePaid = (int) $s->lines_count > 0 && $s->expense?->status === Expense::PAID;
            if ($standalonePaid) {
                $paid = round($paid + $part - ($withheld[$s->id] ?? 0.0), 3);
                $unpaid = round($unpaid + ($withheld[$s->id] ?? 0.0), 3);
            } else {
                $unpaid = round($unpaid + $part, 3);
            }

            $componentsNet = round((float) $s->components_net, 3);
            $s->components_paid_at !== null || (float) $s->components_due <= 0
                ? $paid = round($paid + $componentsNet, 3)
                : $unpaid = round($unpaid + $componentsNet, 3);
        }

        $pending = ['standalone' => 0.0, 'components' => 0.0, 'credit' => 0.0, 'total' => 0.0];

        // ── فواتيرُ العملاء بلا طلب: ما اكتمل تحصيلُه في الشهر، وما ينتظر تحصيلًا، وما أُلغي تحصيلُه بعد ورقته
        $invoices = self::invoices($businessId, $ids, $from, $to);
        $readyNets = $ids === [] ? collect() : BoutiqueInvoiceDues::ready($businessId, null, $f['period'])->toBase()
            ->whereIn('boutique_invoice_lines.boutique_id', $ids)->groupBy('boutique_invoice_lines.boutique_id')
            ->selectRaw('boutique_invoice_lines.boutique_id, SUM(boutique_invoice_lines.net - boutique_invoice_lines.returned_net) as net')->pluck('net', 'boutique_id');
        foreach ($readyNets as $id => $net) {
            $nets[(int) $id] = round(($nets[(int) $id] ?? 0.0) + (float) $net, 3);
        }
        /*
         * جزءُ المكوّنات: من لا رصيدَ له فصافيه مستحقُّه مجمَّعًا — وهو ما تقوله
         * `BoutiqueDues::statement` بلا رصيد. ومن له رصيدٌ (مقاصّةٌ من الأقدم) تُقرأ
         * دالّتُها نفسُها له: حسابُها لا يُكرَّر هنا. والاختبارُ يقابل النوعين.
         */
        $dueByBoutique = $ids === [] ? collect() : BoutiqueComponentDue::where('business_id', $businessId)->whereIn('boutique_id', $ids)
            ->whereNull('boutique_settlement_id')
            ->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL])
            ->whereBetween('period_at', [$from, $to])
            ->groupBy('boutique_id')->selectRaw('boutique_id, SUM(amount) as due')->pluck('due', 'boutique_id');
        $withCredit = $ids === [] ? [] : BoutiqueComponentDue::where('business_id', $businessId)->whereIn('boutique_id', $ids)
            ->where('kind', BoutiqueComponentDue::CREDIT)->distinct()->pluck('boutique_id')->map(fn ($id) => (int) $id)->flip()->all();
        foreach ($boutiques as $b) {
            $net = $nets[(int) $b->id] ?? 0.0;
            $due = round((float) ($dueByBoutique[(int) $b->id] ?? 0), 3);
            $part = isset($withCredit[(int) $b->id])
                ? BoutiqueDues::statement($businessId, (int) $b->id, $f['period'])
                : ['net' => $due, 'credit' => 0.0];
            $pending['standalone'] = round($pending['standalone'] + $net, 3);
            $pending['components'] = round($pending['components'] + $part['net'], 3);
            $pending['credit'] = round($pending['credit'] + $part['credit'], 3);
            $pending['total'] = round($pending['total'] + $net + $part['net'], 3);
        }

        // ── المرتجعاتُ والأرصدة — بيوم وقوعها، منفصلةً عن المبيعات
        $notes = BoutiqueReturn::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->whereBetween('returned_at', [$from, $to])->get();
        // و`+ 0.0` تمحو «−٠»: صفرٌ سالبٌ يُطبع «-0.000» في الشاشة
        $credits = round(-(float) BoutiqueComponentDue::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->where('kind', BoutiqueComponentDue::CREDIT)->whereBetween('period_at', [$from, $to])->sum('amount'), 3) + 0.0;
        $cancelled = Order::where('business_id', $businessId)->where('is_held', false)
            ->where('status', Order::CANCELLED)->whereBetween('ordered_at', [$from, $to])->select('id');
        $cancelledGross = $ids === [] ? 0.0 : round((float) OrderItem::whereIn('order_id', $cancelled)
            ->whereIn('boutique_id', $ids)->sum('total'), 3);

        return [
            'period' => $f['period'],
            'boutique' => $f['boutique'],
            'standalone' => $standalone,
            'invoices' => $invoices,
            'components' => $components,
            'bundles' => $bundles,
            'uncounted' => self::uncounted($businessId, $f['boutique'] === null ? null : $ids, $from, $to),
            // هل تُحسب قطعُ البوتيك داخل منتجات المتجر الآن؟ يُقال على الشاشة ولا يُتجاوز رفضُه
            'pieces' => self::piecesState($businessId),
            /*
             * أثرُ الإطفاء يُقال ولا يُخفي شيئًا. `hosting` مفتاحُ المنصّة لهذا
             * المتجر؛ والشاشةُ تبقى ولو أُطفئ ما دام عنده بوتيك (`Boutiques::holds`).
             * و`pieces_recorded`: أفي القاعدة مستحقُّ قطعٍ كُتب قبل إطفاء حسابها؟
             */
            'hosting' => Boutiques::hosts(Business::find($businessId)),
            'pieces_recorded' => BoutiqueComponentDue::where('business_id', $businessId)->exists(),
            // ما للبوتيك عن مبيعات الشهر قبل التسوية: صافي المنفرد + ما اكتمل تحصيلُه من فواتير العملاء + مستحقُّ القطع — لا إجماليُّ الباقات
            'owed' => round($standalone['net'] + $invoices['net'] + $components['due'], 3),
            'old_sheets' => self::oldSheets($sheets),
            'settlement' => [
                'sheets' => $sheets->count(),
                'issued' => $issued,
                'paid' => $paid,
                'unpaid' => $unpaid,
                'pending' => $pending,
                'remaining' => round($pending['total'] + $unpaid, 3),
            ],
            'returns' => [
                'notes' => $notes->count(),
                'amount' => round((float) $notes->sum('amount'), 3),
                'reduced' => round((float) $notes->where('status', BoutiqueReturn::REDUCED)->sum('amount'), 3),
                'claims_open' => round((float) $notes->where('status', BoutiqueReturn::CLAIM)->sum(fn ($n) => BoutiqueReturns::remaining($n)), 3),
                'review' => $notes->whereIn('status', [BoutiqueReturn::REVIEW, BoutiqueReturn::AWAITING])->count(),
                'shop' => round((float) $notes->where('status', BoutiqueReturn::SHOP)->sum('amount'), 3),
                'component_credits' => $credits,
                'cancelled_gross' => $cancelledGross,
            ],
        ];
    }

    /**
     * أوراقُ الشهر التي صدرت قبل توحيد القاعدة، وفرقُها عن جمع بنودها — خبرٌ، لا يُعدَّل به شيء.
     *
     * كانت تجمع الصنفَ ثمّ تقرّبه بالكسر العائم. والورقةُ كما صدرت ووُقّعت؛ وفرقُها بيساتٌ
     * تُعرض بمصدرها لا تُخفى.
     *
     * @return array{count: int, difference: float}
     */
    private static function oldSheets($sheets): array
    {
        $old = $sheets->filter(fn (BoutiqueSettlement $s) => $s->rounding === null && (int) $s->lines_count > 0);
        if ($old->isEmpty()) {
            return ['count' => 0, 'difference' => 0.0];
        }

        $net = BoutiqueMoney::sql('order_items')['net'];
        $lines = DB::table('order_items')->whereIn('boutique_settlement_id', $old->pluck('id'))
            ->groupBy('boutique_settlement_id')->selectRaw("boutique_settlement_id, SUM({$net}) as net_b")->pluck('net_b', 'boutique_settlement_id');
        // وما نقص من بندٍ بعد ورقته في يومه يُردّ إليه من إشعاره — كما صدرت
        $back = BoutiqueReturn::whereIn('boutique_settlement_id', $old->pluck('id'))->where('source', BoutiqueReturn::QUANTITY)
            ->groupBy('boutique_settlement_id')->selectRaw('boutique_settlement_id, SUM(amount) as amount')->pluck('amount', 'boutique_settlement_id');
        $diff = $old->map(fn (BoutiqueSettlement $s) => self::baisa($s->net) - (int) ($lines[$s->id] ?? 0) - self::baisa($back[$s->id] ?? 0))->filter();

        return ['count' => $diff->count(), 'difference' => self::money((int) $diff->sum())];
    }

    /**
     * فواتيرُ العملاء بلا طلب للبوتيكات: ما اكتمل تحصيلُه في الشهر (بما بقي منه، أو بما
     * حملته ورقتُه)، وما ينتظر تحصيلًا الآن أيًّا كان شهرُ صدوره، وما حملته ورقةٌ ثمّ
     * أُلغي تحصيلُه. وما ينتظر لا يُجمع في المستحقّ: لا تحمله ورقةٌ بعد.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private static function invoices(int $businessId, array $ids, Carbon $from, Carbon $to): array
    {
        $part = fn (string $k) => "COALESCE(SUM(CASE WHEN boutique_settlement_id IS NULL THEN {$k} - returned_{$k} ELSE settled_{$k} END), 0) as {$k}";
        $row = BoutiqueInvoiceLine::where('business_id', $businessId)->whereIn('boutique_id', $ids ?: [0])
            ->whereNull('voided_at')->whereBetween('collected_at', [$from, $to])->toBase()
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT customer_invoice_id) as invoice_count')
            ->selectRaw(implode(', ', array_map($part, ['quantity', 'gross', 'commission', 'net'])))->first();

        return [
            'lines' => (int) $row->line_count,
            'invoices' => (int) $row->invoice_count,
            'quantity' => round((float) $row->quantity, 3) + 0.0,
            'gross' => round((float) $row->gross, 3) + 0.0,
            'commission' => round((float) $row->commission, 3) + 0.0,
            'net' => round((float) $row->net, 3) + 0.0,
            'awaiting' => BoutiqueInvoiceDues::totals(BoutiqueInvoiceDues::awaiting($businessId)->whereIn('boutique_invoice_lines.boutique_id', $ids ?: [0])),
            'reopened' => BoutiqueInvoiceDues::totals(BoutiqueInvoiceDues::reopened($businessId)->whereIn('boutique_invoice_lines.boutique_id', $ids ?: [0])),
        ];
    }

    /**
     * ما كُتب في فواتير العملاء من أصناف البوتيك قبل أن تُحفظ لقطتُها — ولا يدخل الأرقامَ أعلاه.
     *
     * بندٌ صدرت فاتورتُه قبل الهجرة لا يحمل صاحبَ الصنف ولا نسبتَه. فيُقال كم هي كما
     * كُتبت، ولا يُحسب لها مستحقٌّ بنسبة اليوم. والبوتيكُ بحسب بطاقة الصنف **اليوم** —
     * فهو خبرٌ يُراجَع لا نسبة. وما له لقطةٌ خارجٌ: محسوبٌ أعلاه. وفاتورةُ الطلب خارجة.
     *
     * @param  list<int>|null  $ids
     * @return array{lines: int, invoices: int, amount: float}
     */
    private static function uncounted(int $businessId, ?array $ids, Carbon $from, Carbon $to): array
    {
        $row = DB::table('customer_invoice_items as cii')
            ->join('customer_invoices as ci', 'ci.id', '=', 'cii.customer_invoice_id')
            ->join('products as p', 'p.id', '=', 'cii.product_id')
            ->where('ci.business_id', $businessId)->where('p.business_id', $businessId)
            ->where('ci.status', CustomerInvoice::ISSUED)
            ->whereNull('cii.order_item_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('boutique_invoice_lines as bil')->whereColumn('bil.customer_invoice_item_id', 'cii.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_invoice_orders as cio')->whereColumn('cio.customer_invoice_id', 'ci.id'))
            ->when($ids !== null, fn ($q) => $q->whereIn('p.boutique_id', $ids ?: [0]), fn ($q) => $q->whereNotNull('p.boutique_id'))
            ->where('ci.issued_at', '>=', $from->toDateString())->where('ci.issued_at', '<', $to->copy()->startOfMonth()->addMonth()->toDateString())
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT ci.id) as invoice_count, COALESCE(SUM(cii.line_total), 0) as amount')
            ->first();

        return [
            'lines' => (int) $row->line_count,
            'invoices' => (int) $row->invoice_count,
            'amount' => round((float) $row->amount, 3) + 0.0,
        ];
    }

    /* ═══════════ تفاصيلُ فاتورة ═══════════ */

    /**
     * فاتورةٌ كما تُقرأ من شاشة البوتيك — بنودُها كلُّها ونصيبُ كلّ جهةٍ ومالُها.
     *
     * ═══ والكاملةُ لمن يفتح المبيعات ═══
     *
     * `$full` لمن يملك قسمَ «المبيعات» — وهو من يفتح الفاتورةَ نفسَها أصلًا. ومن
     * يقرأ مالَ البوتيك ولا يملكه يرى ما لبوتيكٍ فيه نصيبٌ وحده: بنودَ البوتيكات
     * وعمولتَها وصافيها، لا منتجاتِ المتجر ولا الخصمَ ولا الضريبةَ ولا ما دُفع.
     * ولا بياناتُ الزبون في الحالين. وفاتورةٌ لا نصيبَ فيها لبوتيكٍ تُردّ `null` —
     * فلا يصير البابُ قارئًا لكلّ فواتير المتجر.
     *
     * @return array<string, mixed>|null
     */
    public static function invoice(int $businessId, int $orderId, bool $full = true): ?array
    {
        $order = Order::where('business_id', $businessId)->where('is_held', false)->find($orderId);
        if ($order === null) {
            return null;
        }

        $dues = BoutiqueComponentDue::where('order_id', $order->id)->where('business_id', $businessId)
            ->orderBy('id')->get();
        $items = OrderItem::where('order_id', $order->id)
            ->with(['addons:id,order_item_id,name,total,quantity'])
            ->orderBy('id')->get();
        $standalone = $items->whereNotNull('boutique_id');

        if ($standalone->isEmpty() && $dues->isEmpty()) {
            return null;
        }

        // أوراقُ البنود والقطع معًا — استعلامٌ واحد لا استعلامٌ لكلّ صفّ
        $sheets = BoutiqueSettlement::where('business_id', $businessId)
            ->whereIn('id', $standalone->pluck('boutique_settlement_id')->merge($dues->pluck('boutique_settlement_id'))->filter()->unique()->values())
            ->with('expense:id,status')->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $pieces = $dues->where('order_item_id', $item->id);

            $line = [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'variant' => $item->variant_name,
                'quantity' => (int) $item->quantity,
                'price' => round((float) $item->price, 3),
                'total' => round((float) $item->total, 3),
                'addons_total' => round((float) ($item->addons_total ?? 0), 3),
                'boutique' => null,
                'pieces' => [],
                'dues' => 0.0,
                'shop_remaining' => null,
                // ما يبقى للمتجر من البند قبل خصم الفاتورة: كلُّه لبندٍ من منتجاته
                'shop_share' => 0.0,
            ];

            if ($item->boutique_id !== null) {
                $part = BoutiqueMoney::item($item);
                $sheet = $sheets->get($item->boutique_settlement_id);
                $line['boutique'] = [
                    'name' => (string) $item->boutique_name,
                    'rate' => BoutiqueMoney::rate($item->boutique_rate ?? 0) / 100,
                    'commission' => self::money($part['commission']),
                    'net' => self::money($part['net']),
                    'settlement' => $sheet?->number,
                    'settle_state' => $sheet === null ? self::PENDING
                        : ($sheet->expense?->status === Expense::PAID ? self::PAID : self::ISSUED),
                ];
            }

            $live = $pieces->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL]);
            $groups = $pieces->groupBy(fn (BoutiqueComponentDue $d) => $d->order_item_addon_id.'|'.$d->source.'|'.$d->product_id.'|'.$d->unit_price.'|'.$d->boutique_id);
            foreach ($groups as $g) {
                $first = $g->first();
                $owed = $g->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL]);
                $sheet = $sheets->get($g->where('kind', BoutiqueComponentDue::DUE)->pluck('boutique_settlement_id')->filter()->last());
                $unsettled = round((float) $owed->whereNull('boutique_settlement_id')->sum('amount'), 3);

                $line['pieces'][] = [
                    'boutique' => (string) $first->boutique_name,
                    'product' => (string) $first->product_name,
                    'type' => $first->source === BoutiqueComponentDue::RECIPE ? self::BUNDLE : self::ADDON,
                    'addon' => $first->order_item_addon_id ? $item->addons->firstWhere('id', $first->order_item_addon_id)?->name : null,
                    'quantity' => round((float) $owed->sum('quantity'), 3),
                    'unit_price' => round((float) $first->unit_price, 3),
                    'amount' => round((float) $owed->sum('amount'), 3),
                    'credited' => round(-(float) $g->where('kind', BoutiqueComponentDue::CREDIT)->sum('amount'), 3) + 0.0,
                    'kept_state' => $g->pluck('kept_state')->filter()->first(),
                    'settlement' => $sheet?->number,
                    'settle_state' => $sheet === null || $unsettled != 0.0 ? self::PENDING
                        : ($sheet->components_paid_at !== null || (float) $sheet->components_due <= 0 ? self::PAID : self::ISSUED),
                ];
            }

            if ($line['pieces'] !== []) {
                $line['dues'] = round((float) $live->sum('amount'), 3);
                $line['shop_remaining'] = round($line['total'] + $line['addons_total'] - $line['dues'], 3);
            }

            $line['shop_share'] = self::money(self::baisa($line['total']) + self::baisa($line['addons_total'])
                - self::baisa($line['dues']) - self::baisa($line['boutique']['net'] ?? 0));
            $lines[] = $line;
        }

        /*
         * نصيبُ كلّ جهةٍ من بنود الفاتورة — لا من إجماليّها مرّتين.
         * البوتيكُ: صافي ما بيع منه منفردًا + ثمنُ قطعه في منتجات المتجر.
         * والمتجرُ: ما بقي من البنود بعدهم — منتجاتُه، وعمولتُه، وباقي باقاته.
         * فمجموعُ الجهات = مجموعُ البنود بالبناء، والخصمُ والضريبةُ والتوصيل
         * على الفاتورة كما سُجّلت لا يُوزَّع منها شيء.
         */
        $parties = [];
        $blank = fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'gross' => 0, 'commission' => 0, 'net' => 0, 'components' => 0];
        foreach ($standalone as $item) {
            $id = (int) $item->boutique_id;
            $parties[$id] ??= $blank($id, (string) $item->boutique_name);
            $part = BoutiqueMoney::item($item);
            foreach (['gross', 'commission', 'net'] as $k) {
                $parties[$id][$k] += $part[$k];
            }
        }
        foreach ($dues->whereIn('kind', [BoutiqueComponentDue::DUE, BoutiqueComponentDue::REVERSAL]) as $d) {
            $id = (int) $d->boutique_id;
            $parties[$id] ??= $blank($id, (string) $d->boutique_name);
            $parties[$id]['components'] += self::baisa($d->amount);
        }
        ksort($parties);

        /*
         * وفاتورةٌ ملغاة لا نصيبَ فيها لأحد: بنودُها تُعرض كما سُجّلت، وأنصبتُها
         * أصفار — كما لا يحسبها الملخّصُ ولا الكشف. وقطعُها عُكس مستحقُّها أصلًا.
         */
        $void = (string) $order->status === Order::CANCELLED;
        $balance = round((float) ($order->balance_due ?? 0), 3);
        $unpaid = $order->payment_status === 'غير مدفوع';
        $owedB = 0;
        $parties = array_values(array_map(function (array $p) use ($void, &$owedB) {
            $net = $void ? 0 : $p['net'];
            $components = $void ? 0 : $p['components'];
            $owedB += $net + $components;

            return [
                'id' => $p['id'], 'name' => $p['name'],
                'standalone_gross' => self::money($void ? 0 : $p['gross']),
                'commission' => self::money($void ? 0 : $p['commission']),
                'standalone_net' => self::money($net),
                'components_due' => self::money($components),
                'owed' => self::money($net + $components),
            ];
        }, $parties));
        $linesB = (int) collect($lines)->sum(fn ($l) => self::baisa($l['total']) + self::baisa($l['addons_total']));
        $linesTotal = self::money($linesB);
        $tax = round((float) $order->tax, 3);
        $fee = round((float) $order->delivery_fee, 3);
        $total = round((float) $order->total, 3);
        $discount = round((float) $order->discount, 3);
        /*
         * أين الضريبة من أسعار البنود؟ تُقرأ من الفاتورة نفسها لا من إعداد اليوم،
         * ولا من «المجموع الفرعيّ»: في الأسعار الشاملة يُكتب صافيًا من الضريبة
         * (بنودٌ ٣٢ ← مجموعٌ فرعيٌّ ٣٠٫٧١٤). فالسؤالُ مطابقةُ البنود للإجماليّ:
         *   البنود − الخصم + التوصيل = الإجماليّ          ← داخل الأسعار (`inside`)
         *   البنود − الخصم + الضريبة + التوصيل = الإجماليّ ← مضافةٌ فوقها (`added`)
         * وما لم يطابق أيًّا منهما فـ`unknown`: تُراجع الفاتورة ولا يُعتمد نصيبُها.
         * وعمولةُ البوتيك من مبلغ البند كما سُجّل — فهي تتبع هذا الموضع.
         */
        $inside = $linesB - self::baisa($discount) + self::baisa($fee) === self::baisa($total);
        $added = $linesB - self::baisa($discount) + self::baisa($tax) + self::baisa($fee) === self::baisa($total);
        $basis = match (true) {
            $tax <= 0 => $inside ? 'none' : 'unknown',
            $inside => 'inside',
            $added => 'added',
            default => 'unknown',
        };

        $edits = OrderEdit::where('order_id', $order->id)->orderBy('id')->get()
            ->filter(fn (OrderEdit $e) => in_array($e->kind, [OrderEdit::LINE, OrderEdit::ADDON, OrderEdit::REPLACE_LINE, OrderEdit::CANCEL], true))
            ->map(fn (OrderEdit $e) => [
                'kind' => $e->kind, 'subject' => $e->subject,
                'qty_before' => $e->qty_before === null ? null : (int) $e->qty_before,
                'qty_after' => $e->qty_after === null ? null : (int) $e->qty_after,
                'reason' => $e->reason, 'at' => optional($e->created_at)->format('Y-m-d H:i'),
            ])->values()->all();

        $state = self::invoiceStates([(int) $order->id])[(int) $order->id] ?? self::ACTIVE;
        // مجاميعُ الجهات نفسِها — لا حسابٌ ثانٍ قد يفترق عنها
        $sumOf = fn (string $k) => self::money((int) collect($parties)->sum(fn ($p) => self::baisa($p[$k])));

        if (! $full) {
            return self::boutiquePart($order, $state, $lines, $parties, $void, [
                'standalone_gross' => $sumOf('standalone_gross'), 'commission' => $sumOf('commission'),
                'standalone_net' => $sumOf('standalone_net'), 'components_due' => $sumOf('components_due'), 'owed' => $sumOf('owed'),
            ]);
        }

        return [
            'full' => true,
            'order' => [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
                'ordered_at' => optional($order->ordered_at)->format('Y-m-d H:i'),
                'status' => (string) $order->status,
                'channel' => SalesChannel::label($order->channel),
                'invoice_state' => $state,
                'subtotal' => round((float) $order->subtotal, 3),
                'discount' => $discount,
                'tax' => $tax,
                'delivery_fee' => $fee,
                'total' => $total,
                'tax_basis' => $basis,
            ],
            'lines' => $lines,
            'money' => [
                'lines' => $linesTotal,
                'discount' => $discount,
                'discount_coupon' => min($discount, round((float) ($order->coupon_discount ?? 0), 3)),
                'discount_points' => $order->loyaltyDiscount(),
                // البنودُ بعد خصم الفاتورة كلِّه — وقبل الضريبة المضافة والتوصيل
                'after_discount' => self::money($linesB - self::baisa($discount)),
                'tax' => $tax,
                'tax_basis' => $basis,
                'delivery_fee' => $fee,
                'total' => $total,
                /*
                 * ما دفعه العميل فعلًا: الإجماليّ ناقصَ ما بقي عليه — والفاتورةُ
                 * غيرُ المدفوعة لم يُدفع منها شيء. والملغاةُ لا تُقرأ دفعًا.
                 */
                'paid' => $void || $unpaid ? 0.0 : self::money(self::baisa($total) - self::baisa($balance)),
                'balance' => $void ? 0.0 : ($unpaid ? $total : $balance),
                'boutiques_owed' => self::money($owedB),
                /*
                 * حصّةُ المتجر بعد أن تحمّل الخصم — والبوتيكُ لا ينقص منه شيء.
                 * والضريبةُ المشمولة داخل البنود يوردها المتجر من حصّته؛ والمضافةُ
                 * يدفعها العميل فوقها فلا تُحسب منها. وليست صافي ربح: لا تكلفة
                 * منتجاتٍ ولا مصاريف فيها.
                 */
                'shop_after_discount' => self::money($void ? 0 : $linesB - $owedB - self::baisa($discount)),
                'shop_after_tax' => self::money($void ? 0 : $linesB - $owedB - self::baisa($discount) - ($basis === 'inside' ? self::baisa($tax) : 0)),
            ],
            'shares' => [
                'boutiques' => $parties,
                'shop' => self::money($void ? 0 : $linesB - $owedB),
                'void' => $void,
                'lines_total' => $linesTotal,
                // البنودُ تطابق إجماليَّ الفاتورة المسجَّل؟ — وإلّا فالفاتورةُ تُراجع ولا يُعتمد نصيبُها
                'reconciles' => $basis !== 'unknown',
            ],
            'totals' => [
                'standalone_gross' => $sumOf('standalone_gross'),
                'commission' => $sumOf('commission'),
                'standalone_net' => $sumOf('standalone_net'),
                'components_due' => $sumOf('components_due'),
                'owed' => $sumOf('owed'),
            ],
            'edits' => $edits,
        ];
    }

    /**
     * فاتورةُ عميلٍ بلا طلب كما تُقرأ من شاشة البوتيك — بالشكل نفسِه الذي تُقرأ به فاتورةُ الطلب.
     *
     * أنصبةُ البوتيكات من لقطة بنودها (ناقصًا ما رُدّ منها)، وللمتجر ما بقي من البنود بعد
     * إشعارات الدائن. والخصمُ كلُّه — خصمُ البنود ونقاطُها — على المتجر. وفاتورةٌ لا لقطةَ
     * فيها تُردّ `null`: فلا يصير البابُ قارئًا لكلّ فواتير العملاء.
     *
     * @return array<string, mixed>|null
     */
    public static function customerInvoice(int $businessId, int $invoiceId, bool $full = true): ?array
    {
        $invoice = CustomerInvoice::where('business_id', $businessId)->with(['items', 'creditNotes'])->find($invoiceId);
        $kept = $invoice === null ? collect() : BoutiqueInvoiceDues::lines($invoice)->keyBy('customer_invoice_item_id');

        if ($kept->isEmpty()) {
            return null;
        }

        $void = $invoice->status === CustomerInvoice::CANCELLED;
        $left = fn (BoutiqueInvoiceLine $l, string $k) => self::baisa($l->{$k}) - self::baisa($l->{'returned_'.$k});
        $grossB = 0;
        $discountB = 0;
        $paidLinesB = 0;
        $lines = [];

        foreach ($invoice->items as $item) {
            $itemGrossB = BoutiqueMoney::gross($item->quantity, $item->unit_price);
            $grossB += $itemGrossB;
            $discountB += self::baisa($item->discount);
            $paidLinesB += self::baisa($item->line_total);
            $l = $kept->get($item->id);

            $lines[] = [
                'id' => (int) $item->id,
                'name' => (string) $item->description,
                'variant' => null,
                'quantity' => round((float) $item->quantity, 3),
                'price' => round((float) $item->unit_price, 3),
                'total' => self::money($itemGrossB),
                'addons_total' => 0.0,
                'discount' => round((float) $item->discount, 3),
                'returned' => $l === null ? 0.0 : round((float) $l->returned_quantity, 3),
                'boutique' => $l === null ? null : [
                    'name' => (string) $l->boutique_name,
                    'rate' => round((float) $l->rate, 2),
                    'commission' => self::money($void ? 0 : $left($l, 'commission')),
                    'net' => self::money($void ? 0 : $left($l, 'net')),
                    'settlement' => $l->settlement?->number,
                    'settle_state' => BoutiqueInvoiceDues::state($l),
                ],
                'pieces' => [],
                'dues' => 0.0,
                'shop_remaining' => null,
                'shop_share' => self::money($void ? 0 : $itemGrossB - ($l === null ? 0 : self::baisa($l->net))),
            ];
        }

        $parties = [];
        foreach ($kept as $l) {
            $id = (int) $l->boutique_id;
            $parties[$id] ??= ['id' => $id, 'name' => (string) $l->boutique_name, 'gross' => 0, 'commission' => 0, 'net' => 0];
            foreach (['gross', 'commission', 'net'] as $k) {
                $parties[$id][$k] += $left($l, $k);
            }
        }
        ksort($parties);
        $owedB = 0;
        $parties = array_values(array_map(function (array $p) use ($void, &$owedB) {
            $net = $void ? 0 : $p['net'];
            $owedB += $net;

            return [
                'id' => $p['id'], 'name' => $p['name'],
                'standalone_gross' => self::money($void ? 0 : $p['gross']),
                'commission' => self::money($void ? 0 : $p['commission']),
                'standalone_net' => self::money($net),
                'components_due' => 0.0,
                'owed' => self::money($net),
            ];
        }, $parties));
        $sumOf = fn (string $k) => self::money((int) collect($parties)->sum(fn ($p) => self::baisa($p[$k])));

        /*
         * أين الضريبة؟ من البنود نفسِها: ما يدفعه العميل عنها (`line_total`) هو صافيها
         * بعد الخصم (مشمولة)، أو فوقه ضريبتُها (مضافة).
         */
        $taxB = self::baisa($invoice->tax_total);
        $totalB = self::baisa($invoice->total);
        $basis = match (true) {
            $paidLinesB !== $totalB => 'unknown',
            $taxB <= 0 => $grossB - $discountB === $totalB ? 'none' : 'unknown',
            $grossB - $discountB === $totalB => 'inside',
            $grossB - $discountB + $taxB === $totalB => 'added',
            default => 'unknown',
        };

        // ما رُدّ بإشعارٍ دائن بالأساس الذي كُتبت به البنود: شاملًا ضريبتَه أو قبلها
        $creditedB = (int) $invoice->creditNotes->sum(fn ($n) => self::baisa($n->amount) - ($basis === 'added' ? self::baisa($n->tax_amount) : 0));
        $linesB = $grossB - $creditedB;
        $state = $void ? self::CANCELLED : ($kept->contains(fn ($l) => (float) $l->returned_quantity > 0) ? self::PARTIAL : self::ACTIVE);
        $discount = self::money($discountB);
        $points = min($discount, round((float) $invoice->loyalty_discount, 3));
        $channel = __(self::INVOICE_DOORS[str_starts_with((string) $invoice->form_token, WhatsAppOrderCheckout::FORM_TOKEN) ? 'whatsapp' : 'manual']);
        $head = ['id' => (int) $invoice->id, 'number' => (string) $invoice->number, 'ordered_at' => optional($invoice->issued_by_at ?? $invoice->issued_at)->format('Y-m-d H:i'),
            'status' => (string) $invoice->status, 'channel' => $channel, 'invoice_state' => $state];
        $totals = ['standalone_gross' => $sumOf('standalone_gross'), 'commission' => $sumOf('commission'), 'standalone_net' => $sumOf('standalone_net'), 'components_due' => 0.0, 'owed' => $sumOf('owed')];

        if (! $full) {
            return [
                'full' => false,
                'order' => $head + ['subtotal' => null, 'discount' => null, 'tax' => null, 'delivery_fee' => null, 'total' => null, 'tax_basis' => null],
                'lines' => array_values(array_map(fn (array $l) => [...$l, 'shop_share' => null, 'addons_total' => null, 'discount' => null],
                    array_filter($lines, fn (array $l) => $l['boutique'] !== null))),
                'money' => null,
                'shares' => ['boutiques' => $parties, 'shop' => null, 'void' => $void, 'lines_total' => null, 'reconciles' => true],
                'totals' => $totals,
                'edits' => [],
            ];
        }

        return [
            'full' => true,
            'order' => $head + [
                'subtotal' => round((float) $invoice->subtotal, 3), 'discount' => $discount, 'tax' => self::money($taxB),
                'delivery_fee' => 0.0, 'total' => self::money($totalB), 'tax_basis' => $basis,
            ],
            'lines' => $lines,
            'money' => [
                'lines' => self::money($grossB),
                'discount' => $discount,
                'discount_coupon' => 0.0,
                'discount_points' => $points,
                'after_discount' => self::money($grossB - $discountB),
                'tax' => self::money($taxB),
                'tax_basis' => $basis,
                'delivery_fee' => 0.0,
                'total' => self::money($totalB),
                // ما رُدّ بإشعاراتٍ دائنة — والفاتورةُ كما صدرت
                'credited' => $invoice->creditedTotal() + 0.0,
                'paid' => $void ? 0.0 : $invoice->paidTotal() + 0.0,
                'balance' => $void ? 0.0 : $invoice->outstanding() + 0.0,
                'boutiques_owed' => self::money($owedB),
                'shop_after_discount' => self::money($void ? 0 : $linesB - $owedB - $discountB),
                'shop_after_tax' => self::money($void ? 0 : $linesB - $owedB - $discountB - ($basis === 'inside' ? $taxB - (int) $invoice->creditNotes->sum(fn ($n) => self::baisa($n->tax_amount)) : 0)),
            ],
            'shares' => [
                'boutiques' => $parties,
                'shop' => self::money($void ? 0 : $linesB - $owedB),
                'void' => $void,
                'lines_total' => self::money($linesB),
                'reconciles' => $basis !== 'unknown',
            ],
            'totals' => $totals,
            'edits' => $invoice->creditNotes->sortBy('id')->map(fn ($n) => [
                'kind' => 'إشعار دائن', 'subject' => (string) $n->number, 'qty_before' => null, 'qty_after' => null,
                'reason' => $n->reason, 'at' => optional($n->issued_at)->format('Y-m-d'),
            ])->values()->all(),
        ];
    }

    /**
     * ما للبوتيكات في الفاتورة وحده — لمن يقرأ مالها ولا يفتح المبيعات.
     *
     * لا بندَ من منتجات المتجر، ولا مبلغَ يُستنتج منه ما للمتجر: البندُ الذي دخلته
     * قطعةُ بوتيكٍ باسمه لا بثمنه. وما غاب `null` لا صفر — صفرٌ يُقرأ مبلغًا.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $parties
     * @param  array<string, float>  $totals
     * @return array<string, mixed>
     */
    private static function boutiquePart(Order $order, string $state, array $lines, array $parties, bool $void, array $totals): array
    {
        $lines = array_values(array_filter($lines, fn (array $l) => $l['boutique'] !== null || $l['pieces'] !== []));

        return [
            'full' => false,
            'order' => [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
                'ordered_at' => optional($order->ordered_at)->format('Y-m-d H:i'),
                'status' => (string) $order->status,
                'channel' => SalesChannel::label($order->channel),
                'invoice_state' => $state,
                'subtotal' => null, 'discount' => null, 'tax' => null, 'delivery_fee' => null, 'total' => null, 'tax_basis' => null,
            ],
            'lines' => array_map(fn (array $l) => [
                ...$l,
                'shop_share' => null,
                'shop_remaining' => null,
                // إضافاتُ المتجر على بند البوتيك للمتجر، وثمنُ بندٍ من المتجر دخلته قطعةٌ للمتجر
                'addons_total' => null,
                ...($l['boutique'] === null ? ['price' => null, 'total' => null] : []),
            ], $lines),
            'money' => null,
            'shares' => ['boutiques' => $parties, 'shop' => null, 'void' => $void, 'lines_total' => null, 'reconciles' => true],
            'totals' => $totals,
            'edits' => [],
        ];
    }
}
