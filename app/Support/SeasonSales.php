<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مبيعاتُ الموسم — نسبتُها ساعةَ البيع، وقراءتُها بعده.
 *
 * ═══ تحليلٌ لا دفترٌ ثانٍ ═══
 *
 * لا قيدَ ولا معاملةَ ولا حسابَ باسم الموسم: البيعةُ تُقيَّد كما كانت تُقيَّد
 * بالحرف (`Books::recordSale`)، وهذا يقرأ بنودَها التي حملت اسمَ الموسم
 * ويجمعها. فالدفترُ مصدرُ الحقيقة، والموسمُ مرشِّحٌ عليه.
 *
 * ═══ والنسبةُ تُكتب حين تُعرف، وتُترك حين تُظنّ ═══
 *
 * تُنسب البيعةُ إلى الموسم حين يختاره الكاشيرُ في الصندوق ويبيع صنفًا
 * من أصنافه — وحينها فقط. بيعةٌ من شاشة «الكل» لا تُنسب ولو كان الموسمُ
 * الجاري واحدًا: تقريرٌ ناقصٌ صادق خيرٌ من تقريرٍ كاملٍ مخمَّن. وما بيع
 * قبل هذه الميزة لا يُملأ بأثرٍ رجعيّ للسبب نفسه.
 *
 * ═══ والأرقامُ بقاعدة ربحيّة المنتجات ═══
 *
 * المبيعاتُ ثمنُ البنود كما بيعت (سعرُ البند × كميّته + إضافاته) قبل خصم
 * الفاتورة — وهي قاعدةُ `Demo::productProfitability` حرفًا. والتكلفةُ من
 * لقطة البند يومَ بيعه، وبطاقةُ المنتج لما لا لقطةَ له — قاعدةُ
 * `Demo::cogsFor` و`Books::costOf` نفسُها. فلا يقرأ التاجرُ عن الصنف
 * الواحد ربحين.
 */
final class SeasonSales
{
    /** كم صنفًا في «أفضل منتجات الموسم» */
    public const TOP = 10;

    /* ═══════════ النسبة ═══════════ */

    /**
     * يقرّر لكلّ بندٍ موسمَه — أو لا موسمَ له.
     *
     * المعرّفُ من الشاشة لا يُصدَّق: يُقبل حين يكون الموسمُ لهذا المتجر،
     * جاريًا اليوم، معروضًا في الصندوق، والصنفُ من أصنافه. وما سوى ذلك
     * يُطرح صامتًا ولا تُردّ البيعة: النسبةُ تحليلٌ، والبيعةُ مال — وسلّةٌ
     * عُلّقت في آخر يومٍ من الموسم واستُؤنفت بعده تُباع بلا موسم، لا تُرفض.
     *
     * @param  array<int, array{product: mixed, season_id?: mixed}>  $lines  كما تخرج من `priceItems`
     * @return array<int, array{id: int, name: string}|null> بترتيب البنود
     */
    public static function attribute(int $businessId, array $lines, ?Carbon $today = null): array
    {
        $requested = collect($lines)
            ->map(fn ($l) => (int) ($l['season_id'] ?? 0))
            ->filter(fn ($id) => $id > 0)->unique()->values();

        if ($requested->isEmpty()) {
            return array_fill(0, count($lines), null);
        }

        $seasons = Season::where('business_id', $businessId)->live($today)
            ->where('show_in_pos', true)
            ->whereIn('id', $requested->all())
            ->with(['products' => fn ($q) => $q->select('products.id')])
            ->get()->keyBy('id');

        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $season = $seasons->get((int) ($l['season_id'] ?? 0));
            $product = $l['product'] ?? null;

            $out[$i] = $season && $product && $season->products->contains('id', $product->id)
                ? ['id' => (int) $season->id, 'name' => (string) $season->name]
                : null;
        }

        return $out;
    }

    /* ═══════════ التقرير ═══════════ */

    /**
     * بنودُ الموسم المبيعة — بيعٌ حقيقيّ لا سلّةٌ معلّقة ولا طلبٌ ملغى.
     *
     * والبيعُ ما تقوله `Order::scopeSold` لا شرطٌ يُكتب هنا ثانيةً: قاعدةٌ
     * واحدة للتقارير كلِّها. والحصرُ بالمتجر مرّتين — على الموسم وعلى
     * الطلب — فلا يبلغ معرّفُ موسمٍ بيعةَ جارٍ ولو خُمّن.
     */
    private static function lines(Season $season, ?string $channel = null): Builder
    {
        $orders = Order::where('business_id', $season->business_id)->sold()
            ->when($channel, fn ($q) => $channel === SalesChannel::UNKNOWN
                ? $q->whereNull('channel')
                : $q->where('channel', $channel))
            ->select('id');

        return OrderItem::query()
            ->where('order_items.season_id', $season->id)
            ->whereIn('order_items.order_id', $orders);
    }

    /** أَلِلموسم بيعاتٌ منسوبة؟ — يُسأل قبل حذفه */
    public static function hasSales(Season $season): bool
    {
        return self::lines($season)->exists();
    }

    /**
     * تقريرُ أداء الموسم.
     *
     * @param  string|null  $channel  قناةٌ بعينها، أو `null` لكلّ القنوات
     * @return array{
     *   summary: array{sales: float, cogs: float, gross_profit: float, margin: float, orders: int, units: int},
     *   channels: list<array{key: string, label: string, sales: float, orders: int, gross_profit: float}>,
     *   top: list<array{product_id: int|null, name: string, units: int, sales: float, gross_profit: float}>,
     *   unattributed: array{orders: int, units: int},
     *   channel: string|null
     * }
     */
    public static function report(Season $season, ?string $channel = null): array
    {
        $cards = self::cards((int) $season->business_id);
        $rows = self::grouped($season, $channel, ['order_items.product_id', 'order_items.name'])
            ->map(fn ($r) => self::costed($cards, $r));

        $sales = round((float) $rows->sum('sales'), 3);
        $cogs = round((float) $rows->sum('cogs'), 3);
        $profit = round($sales - $cogs, 3);

        $summary = [
            'sales' => $sales,
            'cogs' => $cogs,
            'gross_profit' => $profit,
            // كما في `Demo::productProfitability`: منزلةٌ واحدة، وصفرٌ حين لا بيع
            'margin' => $sales > 0 ? round($profit / $sales * 100, 1) : 0.0,
            'orders' => (int) self::lines($season, $channel)->distinct()->count('order_items.order_id'),
            'units' => (int) $rows->sum('units'),
        ];

        $top = $rows->sortByDesc('sales')->take(self::TOP)->values()
            ->map(fn ($r) => [
                'product_id' => $r['product_id'],
                'name' => $r['name'],
                'units' => $r['units'],
                'sales' => $r['sales'],
                'gross_profit' => round($r['sales'] - $r['cogs'], 3),
            ])->all();

        return [
            'summary' => $summary,
            'channels' => self::channels($season, $cards),
            'top' => $top,
            'unattributed' => self::unattributed($season),
            'channel' => $channel,
        ];
    }

    /**
     * ملخّصُ كلّ مواسم المتجر — لتقرير «المواسم» في فهرس التقارير.
     *
     * استعلاماتٌ ثلاثة للمتجر كلِّه لا ستّةٌ لكلّ موسم: متجرٌ بثلاثين موسمًا
     * كان سيطلب مئتي استعلامٍ لصفحةٍ واحدة. والأرقامُ بقاعدة `report`
     * نفسِها — حرفًا — فلا يقرأ التاجرُ عن الموسم الواحد رقمًا هنا وآخرَ في
     * صفحته.
     *
     * @return array<int, array{sales: float, cogs: float, gross_profit: float, margin: float, orders: int, units: int}> [معرّف الموسم => ...]
     */
    public static function summaries(int $businessId): array
    {
        $orders = Order::where('business_id', $businessId)->sold()->select('id');
        $lines = fn () => OrderItem::query()
            ->whereNotNull('order_items.season_id')
            ->whereIn('order_items.order_id', $orders);

        $rows = $lines()
            ->groupBy('order_items.season_id', 'order_items.product_id')
            ->selectRaw('order_items.season_id as season_id, order_items.product_id as product_id'
                .', SUM(order_items.quantity) as units'
                .', SUM(order_items.total + COALESCE(order_items.addons_total, 0)) as sales'
                .', SUM(order_items.cost * order_items.quantity) as cost_snapshot'
                .', SUM(CASE WHEN order_items.cost > 0 THEN order_items.quantity ELSE 0 END) as costed_qty')
            ->get();

        $addonCosts = OrderItemAddon::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_addons.order_item_id')
            ->whereIn('order_items.id', $lines()->select('order_items.id'))
            ->groupBy('order_items.season_id', 'order_items.product_id')
            ->selectRaw('order_items.season_id as season_id, order_items.product_id as product_id'
                .', SUM(COALESCE(order_item_addons.cost, 0) * order_item_addons.quantity) as cost')
            ->get()
            ->keyBy(fn ($r) => $r->season_id.'|'.$r->product_id);

        $orderCounts = $lines()
            ->groupBy('order_items.season_id')
            ->selectRaw('order_items.season_id as season_id, COUNT(DISTINCT order_items.order_id) as n')
            ->pluck('n', 'season_id');

        $cards = self::cards($businessId);
        $out = [];

        foreach ($rows as $r) {
            $r->addon_cost = (float) ($addonCosts[$r->season_id.'|'.$r->product_id]->cost ?? 0);
            $c = self::costed($cards, $r);
            $id = (int) $r->season_id;
            $out[$id] ??= ['sales' => 0.0, 'cogs' => 0.0, 'units' => 0];
            $out[$id]['sales'] += $c['sales'];
            $out[$id]['cogs'] += $c['cogs'];
            $out[$id]['units'] += $c['units'];
        }

        foreach ($out as $id => &$v) {
            $sales = round($v['sales'], 3);
            $cogs = round($v['cogs'], 3);
            $profit = round($sales - $cogs, 3);
            $v = [
                'sales' => $sales,
                'cogs' => $cogs,
                'gross_profit' => $profit,
                'margin' => $sales > 0 ? round($profit / $sales * 100, 1) : 0.0,
                'orders' => (int) ($orderCounts[$id] ?? 0),
                'units' => (int) $v['units'],
            ];
        }
        unset($v);

        return $out;
    }

    /**
     * المبيعاتُ حسب القناة — ما وُجد منها فقط.
     *
     * قناةٌ بلا بيعةٍ لا تُرسم صفرًا: «الموقع الإلكتروني: ٠» يوهم أنّ الموقع
     * يبيع ويُحصى، وهو اليومَ لا يُنشئ طلبًا. والطلباتُ التي سبقت عمودَ
     * القناة تُقرأ «غير محدّدة» لا «صندوق».
     */
    private static function channels(Season $season, array $cards): array
    {
        $byChannel = self::grouped($season, null, ['orders.channel', 'order_items.product_id'])
            ->map(fn ($r) => self::costed($cards, $r))
            ->groupBy(fn ($r) => $r['channel'] ?? SalesChannel::UNKNOWN);

        $orders = self::lines($season)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->groupBy('orders.channel')
            ->selectRaw('orders.channel as channel, COUNT(DISTINCT order_items.order_id) as n')
            ->get()
            ->mapWithKeys(fn ($r) => [($r->channel ?? SalesChannel::UNKNOWN) => (int) $r->n]);

        return $byChannel->map(function ($rows, $key) use ($orders) {
            $sales = round((float) $rows->sum('sales'), 3);
            $cogs = round((float) $rows->sum('cogs'), 3);

            return [
                'key' => $key,
                'label' => SalesChannel::label($key === SalesChannel::UNKNOWN ? null : $key),
                'sales' => $sales,
                'orders' => (int) ($orders[$key] ?? 0),
                'gross_profit' => round($sales - $cogs, 3),
            ];
        })->sortByDesc('sales')->values()->all();
    }

    /**
     * ما بيع من أصناف الموسم في مدّته ولم يُنسب إليه — عدًّا لا مالًا.
     *
     * يُقال للتاجر كي يعرف أنّ التقرير يغطّي ما نُسب لا كلَّ ما يشبهه،
     * ولا يُضاف إلى الأرقام: بيعةٌ من شاشة «الكل» قد تكون للموسم وقد لا
     * تكون، والعدُّ يقول «انظر» لا «هذا لك».
     */
    private static function unattributed(Season $season): array
    {
        $productIds = $season->products()->pluck('products.id');
        if ($productIds->isEmpty()) {
            return ['orders' => 0, 'units' => 0];
        }

        $orders = Order::where('business_id', $season->business_id)->sold()
            ->whereDate('ordered_at', '>=', $season->starts_at->toDateString())
            ->whereDate('ordered_at', '<=', $season->ends_at->toDateString())
            ->select('id');

        $row = OrderItem::query()
            ->whereIn('order_items.order_id', $orders)
            ->whereIn('order_items.product_id', $productIds)
            ->whereNull('order_items.season_id')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders, COALESCE(SUM(order_items.quantity), 0) as units')
            ->first();

        return ['orders' => (int) ($row->orders ?? 0), 'units' => (int) ($row->units ?? 0)];
    }

    /* ═══════════ أدوات ═══════════ */

    /**
     * تجميعُ البنود في الاستعلام لا في الذاكرة — موسمٌ بعشرة آلاف بيعةٍ لا
     * يُحمَّل صفًّا صفًّا. وأرقامُ الإضافات تُقرأ في استعلامٍ ثانٍ بالمجموعة
     * نفسها وتُضمّ.
     *
     * @param  list<string>  $by  أعمدةُ التجميع
     */
    private static function grouped(Season $season, ?string $channel, array $by)
    {
        $lines = self::lines($season, $channel)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->groupBy($by)
            ->selectRaw(implode(', ', array_map(fn ($c) => "$c as ".last(explode('.', $c)), $by))
                .', SUM(order_items.quantity) as units'
                .', SUM(order_items.total + COALESCE(order_items.addons_total, 0)) as sales'
                .', SUM(order_items.cost * order_items.quantity) as cost_snapshot'
                .', SUM(CASE WHEN order_items.cost > 0 THEN order_items.quantity ELSE 0 END) as costed_qty')
            ->get();

        // تكلفةُ الإضافات — من لقطتها على البند، بالمجموعة نفسها
        $addonCosts = OrderItemAddon::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_addons.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.id', self::lines($season, $channel)->select('order_items.id'))
            ->groupBy($by)
            ->selectRaw(implode(', ', array_map(fn ($c) => "$c as ".last(explode('.', $c)), $by))
                .', SUM(COALESCE(order_item_addons.cost, 0) * order_item_addons.quantity) as cost')
            ->get()
            ->keyBy(fn ($r) => self::groupKey($r, $by));

        return $lines->map(function ($r) use ($addonCosts, $by) {
            $r->addon_cost = (float) ($addonCosts[self::groupKey($r, $by)]->cost ?? 0);

            return $r;
        });
    }

    private static function groupKey($row, array $by): string
    {
        return implode('|', array_map(fn ($c) => (string) ($row->{last(explode('.', $c))} ?? ''), $by));
    }

    /**
     * بطاقاتُ أصناف المتجر — تكلفتُها اليوم، لما لا لقطةَ له.
     *
     * تُقرأ مرّةً للتقرير لا لكلّ صفّ، وخامًا لا نماذج (انظر `Demo::cogsFor`).
     *
     * @return array<int, float>
     */
    private static function cards(int $businessId): array
    {
        return DB::table('products')->where('business_id', $businessId)
            ->pluck('cost', 'id')->map(fn ($c) => (float) $c)->all();
    }

    /** صفٌّ مجمَّع بتكلفته — اللقطةُ أوّلًا وبطاقةُ المنتج لما لا لقطةَ له */
    private static function costed(array $cards, $r): array
    {
        $uncosted = (int) $r->units - (int) $r->costed_qty;
        $card = (float) ($cards[$r->product_id ?? 0] ?? 0);

        return [
            'product_id' => isset($r->product_id) ? (int) $r->product_id : null,
            'name' => (string) ($r->name ?? ''),
            'channel' => $r->channel ?? null,
            'units' => (int) $r->units,
            'sales' => round((float) $r->sales, 3),
            'cogs' => round((float) $r->cost_snapshot + $card * $uncosted + (float) $r->addon_cost, 3),
        ];
    }
}
