<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
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

    public const TYPES = [self::STANDALONE, self::BUNDLE, self::ADDON];

    /** حالُ التسوية: لم تحمله ورقة، أو حملته ورقةٌ لم تُسدَّد، أو سُدِّدت */
    public const PENDING = 'pending';

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const STATES = [self::PENDING, self::ISSUED, self::PAID];

    /** حالُ الفاتورة نفسِها */
    public const CANCELLED = 'cancelled';

    public const PARTIAL = 'partial';

    public const ACTIVE = 'active';

    /**
     * ما يطلبه الرابط — مُنقًّى، وما لا يصحّ يُقرأ «الكلّ».
     *
     * بوتيكٌ من متجرٍ آخر لا يُرشَّح به: يُقرأ «الكلّ» كأنّه لم يُطلب، فلا
     * يُعرف من الردّ أهو موجودٌ عند غيرك — كما `Boutiques::scope`.
     *
     * @return array{period: string, boutique: ?int, type: ?string, settle: ?string, q: string, page: int}
     */
    public static function filters(Request $request, int $businessId): array
    {
        $boutique = $request->query('boutique');
        $boutique = is_string($boutique) && ctype_digit($boutique)
            && Boutique::where('business_id', $businessId)->whereKey((int) $boutique)->exists()
            ? (int) $boutique : null;

        $type = $request->query('type');
        $settle = $request->query('settle');
        $page = $request->query('page');

        return [
            'period' => Boutiques::period($request->query('period')),
            'boutique' => $boutique,
            'type' => in_array($type, self::TYPES, true) ? $type : null,
            'settle' => in_array($settle, self::STATES, true) ? $settle : null,
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
            $f['type'] === null || $f['type'] !== self::STANDALONE ? self::pieces($businessId, $f, $orders) : null,
        ]));

        $union = array_shift($branches);
        foreach ($branches as $b) {
            $union->unionAll($b);
        }

        $q = $f['q'];
        $like = Search::like();

        $page = DB::query()->fromSub($union, 'r')
            ->when($f['settle'] !== null, fn ($w) => $w->where('r.settle_state', $f['settle']))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('r.order_number', $like, '%'.$q.'%')
                ->orWhere('r.product_name', $like, '%'.$q.'%')
                ->orWhere('r.parent_name', $like, '%'.$q.'%')))
            ->orderByDesc('r.ordered_at')->orderByDesc('r.order_id')->orderBy('r.type')->orderBy('r.ref_id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $f['page']);

        $states = self::invoiceStates(collect($page->items())->pluck('order_id')->unique()->all());

        $page->setCollection(collect($page->items())->map(fn ($r) => self::present($r, $states)));

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
            ->selectRaw('oi.id as ref_id, o.id as order_id, o.number as order_number, o.ordered_at, o.status as order_status')
            ->selectRaw('oi.boutique_id, oi.boutique_name, oi.name as product_name, oi.variant_name as variant')
            ->selectRaw('NULL as parent_name, NULL as parent_variant, NULL as parent_total, NULL as addon_name')
            ->selectRaw('oi.quantity as quantity, oi.price as unit_price, oi.total as amount, oi.boutique_rate as rate')
            ->selectRaw('0 as credited, NULL as kept_state, oi.boutique_settlement_id as settlement_id, bs.number as settlement_number')
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
            ->selectRaw('g.ref_id, o.id as order_id, o.number as order_number, o.ordered_at, o.status as order_status')
            ->selectRaw('g.boutique_id, g.boutique_name, g.product_name, NULL as variant')
            ->selectRaw('oi.name as parent_name, oi.variant_name as parent_variant, oi.total as parent_total, a.name as addon_name')
            ->selectRaw('g.quantity, g.unit_price, g.amount, NULL as rate')
            ->selectRaw('g.credited, g.kept_state, g.settlement_id, bs.number as settlement_number')
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
            ->whereIn('kind', [OrderEdit::LINE, OrderEdit::ADDON, OrderEdit::REPLACE_LINE])
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
     * قاعدةُ التقريب في هذا القسم — واحدةٌ في الصفّ والفاتورة والملخّص:
     *
     *   عمولةُ البند = مبلغُ البند كما سُجّل × نسبتُه ساعةَ البيع، مدوَّرةً إلى
     *   أقرب بيسة، والنصفُ إلى أعلى. وصافي البوتيك = مبلغُ البند − عمولتُه.
     *   والمجاميعُ جمعُ بيساتٍ صحيحة — لا تُقرَّب مرّةً ثانية.
     *
     * والحسابُ بأعدادٍ صحيحة لا بالكسر العائم: ١٫٠٠٥ × ١٠٪ = ٠٫١٠٠٥ تمامًا،
     * فعمولتُه ٠٫١٠١. والكسرُ العائم يراها ٠٫١٠٠٤٩٩… فيُنزلها ٠٫١٠٠ — وهذا ما
     * تفعله `Boutiques::statement` (ورقةُ التسوية) اليوم. لا يُغيَّر هنا: الفرقُ
     * يُعرض في الملخّص (`rounding`) ويُرفع قرارًا، ولا يُخفى.
     */

    /** مبلغٌ بالبيسات — العمودُ بثلاث منازل، فالضربُ في ألفٍ ثمّ التقريبُ إلى عددٍ صحيح لا يخطئ */
    private static function baisa(float|int|string|null $x): int
    {
        return (int) round((float) $x * 1000);
    }

    /** النسبةُ بأجزاءٍ من مئةٍ من المئة — العمودُ بمنزلتين (١٢٫٥٪ ← ١٢٥٠) */
    private static function rateHundredths(float|int|string|null $rate): int
    {
        return (int) round((float) $rate * 100);
    }

    /** عمولةُ بندٍ بالبيسات — نصفٌ إلى أعلى (وبعيدًا عن الصفر للسالب)، بلا كسرٍ عائم */
    public static function commissionBaisa(int $totalBaisa, int $rateHundredths): int
    {
        $x = $totalBaisa * $rateHundredths;

        return $x >= 0 ? intdiv($x + 5000, 10000) : -intdiv(-$x + 5000, 10000);
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
        $rateH = $standalone ? self::rateHundredths($r->rate ?? 0) : 0;
        $rate = $standalone ? $rateH / 100 : null;
        $commissionB = $standalone ? self::commissionBaisa($amountB, $rateH) : 0;
        $commission = self::money($commissionB);

        return [
            'key' => $r->type.':'.$r->ref_id,
            'type' => $r->type,
            'order_id' => (int) $r->order_id,
            'order_number' => (string) $r->order_number,
            'ordered_at' => $r->ordered_at ? Carbon::parse($r->ordered_at)->format('Y-m-d H:i') : null,
            'order_status' => (string) $r->order_status,
            'invoice_state' => $states[(int) $r->order_id] ?? self::ACTIVE,
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
            'net' => self::money($amountB - $commissionB),
            'credited' => round((float) $r->credited, 3) + 0.0,
            'kept_state' => $r->kept_state,
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
         * ── المنفرد: البنودُ نفسُها التي يقرؤها النظام (`Boutiques::soldLines`)،
         * محسوبةً بالبيسة لكلّ بند (القاعدة أعلاه) في القاعدة نفسها — بلا تحميل
         * البنود. وبجانبها ما تقوله دالّتا النظام لنفس الشهر، ليُرى الفرقُ لا ليُخفى:
         * تقريرُ المبيعات (`soldTotals`) يقرّب المجموعَ مرّةً، وورقةُ التسوية
         * (`statement`) تقرّب كلَّ صنفٍ بكسرٍ عائم.
         */
        $lineB = 'CAST(ROUND(order_items.total * 1000) AS BIGINT)';
        $commissionB = '('.$lineB.' * CAST(ROUND(COALESCE(order_items.boutique_rate, 0) * 100) AS BIGINT) + 5000) / 10000';
        $grossB = 0;
        $commB = 0;
        $pendingNetB = 0;
        $reportCommB = 0;
        $quantity = 0.0;
        $lines = 0;
        foreach ($boutiques as $b) {
            $row = Boutiques::soldLines($businessId, $b, $from, $to)
                ->selectRaw("COALESCE(SUM($lineB), 0) as gross_b")
                ->selectRaw("COALESCE(SUM($commissionB), 0) as commission_b")
                ->selectRaw("COALESCE(SUM(CASE WHEN order_items.boutique_settlement_id IS NULL THEN $lineB - ($commissionB) ELSE 0 END), 0) as pending_net_b")
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as quantity')
                ->selectRaw('COUNT(*) as line_count')
                ->toBase()->first();
            $grossB += (int) $row->gross_b;
            $commB += (int) $row->commission_b;
            $pendingNetB += (int) $row->pending_net_b;
            $quantity = round($quantity + (float) $row->quantity, 3);
            $lines += (int) $row->line_count;
            $reportCommB += self::baisa(Boutiques::soldTotals($businessId, $b, $from, null, null, $to)['commission']);
        }
        $standalone = [
            'gross' => self::money($grossB),
            'commission' => self::money($commB),
            'net' => self::money($grossB - $commB),
            'quantity' => $quantity,
            'lines' => $lines,
        ];

        $sold = Order::where('business_id', $businessId)->sold()->whereBetween('ordered_at', [$from, $to])->select('id');
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

        $issued = 0.0;
        $paid = 0.0;
        $unpaid = 0.0;
        foreach ($sheets as $s) {
            $issued = round($issued + (float) $s->net + (float) $s->components_net, 3);

            // جزءُ المنفرد بما يُسدَّد فعلًا بعد ما خُفّض منه بمرتجع (`BoutiqueReturns::payable`)
            $part = (int) $s->lines_count === 0 || $s->expense === null ? 0.0 : BoutiqueReturns::payable($s->expense);
            $standalonePaid = (int) $s->lines_count > 0 && $s->expense?->status === Expense::PAID;
            $standalonePaid ? $paid = round($paid + $part, 3) : $unpaid = round($unpaid + $part, 3);

            $componentsNet = round((float) $s->components_net, 3);
            $s->components_paid_at !== null || (float) $s->components_due <= 0
                ? $paid = round($paid + $componentsNet, 3)
                : $unpaid = round($unpaid + $componentsNet, 3);
        }

        $pending = ['standalone' => 0.0, 'components' => 0.0, 'credit' => 0.0, 'total' => 0.0];
        $sheetNetB = 0;
        foreach ($boutiques as $b) {
            $st = Boutiques::statement($businessId, (int) $b->id, $f['period']);
            $sheetNetB += self::baisa($st['net']);
            $pending['standalone'] = round($pending['standalone'] + $st['net'], 3);
            $pending['components'] = round($pending['components'] + $st['components']['net'], 3);
            $pending['credit'] = round($pending['credit'] + $st['components']['credit'], 3);
            $pending['total'] = round($pending['total'] + $st['payable'], 3);
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
            'components' => $components,
            'bundles' => $bundles,
            // هل تُحسب قطعُ البوتيك داخل منتجات المتجر الآن؟ يُقال على الشاشة ولا يُتجاوز رفضُه
            'pieces' => self::piecesState($businessId),
            // ما للبوتيك عن مبيعات الشهر قبل التسوية: صافي المنفرد + مستحقُّ القطع — لا إجماليُّ الباقات
            'owed' => round($standalone['net'] + $components['due'], 3),
            /*
             * مطابقةُ التقريب — بالبيسة، والفرقُ موجبٌ حين تقول دالّةُ النظام أكثر.
             * لا يُعدَّل بها مبلغ: تُعرض حين لا تكون صفرًا، والمعتمدُ للدفع الورقة.
             */
            'rounding' => [
                'lines_commission' => self::money($commB),
                'report_commission' => self::money($reportCommB),
                'report_difference' => self::money($reportCommB - $commB),
                'lines_pending_net' => self::money($pendingNetB),
                'sheet_pending_net' => self::money($sheetNetB),
                'sheet_difference' => self::money($sheetNetB - $pendingNetB),
            ],
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

    /* ═══════════ تفاصيلُ فاتورة ═══════════ */

    /**
     * فاتورةٌ كما تُقرأ من شاشة البوتيك — بنودُها التي لبوتيكٍ فيها نصيب، وحدها.
     *
     * لا بياناتُ الزبون ولا بنودُ المتجر الخالصة: الشاشةُ لحساب البوتيكات، ومن
     * أراد الفاتورةَ كاملةً فتحها من قسم المبيعات بصلاحيّته (`can_open`).
     * وفاتورةٌ لا نصيبَ فيها لبوتيكٍ تُردّ `null` — فلا يصير البابُ قارئًا
     * لكلّ فواتير المتجر.
     *
     * @return array<string, mixed>|null
     */
    public static function invoice(int $businessId, int $orderId): ?array
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
                $rateH = self::rateHundredths($item->boutique_rate ?? 0);
                $totalB = self::baisa($item->total);
                $commissionB = self::commissionBaisa($totalB, $rateH);
                $sheet = $sheets->get($item->boutique_settlement_id);
                $line['boutique'] = [
                    'name' => (string) $item->boutique_name,
                    'rate' => $rateH / 100,
                    'commission' => self::money($commissionB),
                    'net' => self::money($totalB - $commissionB),
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
        $blank = fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'gross' => 0, 'commission' => 0, 'components' => 0];
        foreach ($standalone as $item) {
            $id = (int) $item->boutique_id;
            $parties[$id] ??= $blank($id, (string) $item->boutique_name);
            $totalB = self::baisa($item->total);
            $parties[$id]['gross'] += $totalB;
            $parties[$id]['commission'] += self::commissionBaisa($totalB, self::rateHundredths($item->boutique_rate ?? 0));
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
        $owedB = 0;
        $parties = array_values(array_map(function (array $p) use ($void, &$owedB) {
            $net = $void ? 0 : $p['gross'] - $p['commission'];
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

        return [
            'order' => [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
                'ordered_at' => optional($order->ordered_at)->format('Y-m-d H:i'),
                'status' => (string) $order->status,
                'invoice_state' => $state,
                'subtotal' => round((float) $order->subtotal, 3),
                'discount' => $discount,
                'tax' => $tax,
                'delivery_fee' => $fee,
                'total' => $total,
                'tax_basis' => $basis,
            ],
            'lines' => $lines,
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
}
