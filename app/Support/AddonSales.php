<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ما بيع من الإضافات — من لقطة البيع، مصدرٌ واحد لكلّ من يسأل.
 *
 * يقرؤه تقريرُ الإضافات، وملخّصُ تقرير الطلبات، وملخّصُ المبيعات، والملفّاتُ
 * الثلاثة التي تخرج منها. ولو جمع كلٌّ منهم الإضافاتِ بيده لَافترق رقمُ
 * الشاشة عن رقم الورقة عند أوّل شرطٍ يُنسى.
 *
 * ═══ والإضافةُ جزءٌ من الطلب لا فوقه ═══
 *
 * ثمنُها داخلٌ في `orders.total` منذ لحظة البيع: `subtotal` يجمع
 * `price × qty + addons_total` لكلّ بند، والإضافةُ المستقلّة بندٌ كغيره
 * (انظر `PosController::checkout`). فما هنا **كشفٌ لجزءٍ من الإجمالي** لا
 * رقمٌ يُضاف إليه — ومن جمعه على المبيعات عدّه مرّتين.
 *
 * ═══ وموضعان تُكتب فيهما الإضافة ═══
 *
 *  · على بند منتج — `order_item_addons`: الاسمُ والسعرُ والكميّةُ والمجموعُ
 *    لقطةً، و`cost` تكلفةُ **الواحدة** منها (انظر `SaleLines::pickAddons`).
 *  · بندًا مستقلًّا من شريط الصندوق — `order_items` بلا منتجٍ ولا تفاصيلِ
 *    طلبٍ مخصَّص، و`cost` كذلك تكلفةُ الواحدة. ومعرّفُها في
 *    `standalone_addon_id` لِما بيع بعد عمودها، وفراغٌ لما قبله.
 *
 * ═══ والمجهولُ لا يُنسب بالظنّ ═══
 *
 * صفٌّ بلا معرّف — إضافةٌ حُذفت بعد بيعها، أو بندٌ مستقلٌّ قديم — يُجمع
 * باسمه يومَ البيع ويُوسم «غير مربوط». لا يُلصق بإضافةٍ حيّةٍ تشابهه اسمًا.
 *
 * والتجميعُ في القاعدة لا في الذاكرة، وعلى طلباتٍ حُصرت قبله: المتجر
 * والمدّة والفرع والقناة تُقال في `$orders` قبل أن يُلمس بندٌ واحد.
 */
final class AddonSales
{
    public const ATTACHED = 'attached';

    public const STANDALONE = 'standalone';

    /**
     * كلُّ إضافةٍ بيعت في هذه الطلبات — صفًّا خامًا واحدًا لكلّ اختيار.
     *
     * `$orders` استعلامُ طلباتٍ **محصور** (متجرٌ ومبيعٌ وما سواهما): يُقرأ منه
     * المعرّفُ وحده، ولا يُعاد حصرُه هنا — فيبقى تعريفُ «ما بيع» واحدًا
     * (`Order::scopeSold`) ولا يُكتب مرّةً ثانية.
     */
    public static function lines(Builder $orders): QueryBuilder
    {
        $ids = (clone $orders)->select('orders.id');

        $attached = DB::table('order_item_addons')
            ->join('order_items', 'order_items.id', '=', 'order_item_addons.order_item_id')
            ->whereIn('order_items.order_id', $ids)
            ->selectRaw("order_items.order_id as order_id, '".self::ATTACHED."' as source, "
                .'order_item_addons.addon_id as addon_id, order_item_addons.name as name, '
                .'order_item_addons.name_en as name_en, order_item_addons.quantity as quantity, '
                .'order_item_addons.total as total, '
                // الفراغُ خدمةٌ لا رصيدَ لها — تكلفتُها صفر، كما في `Demo::addonProfitByProduct`
                .'COALESCE(order_item_addons.cost, 0) * order_item_addons.quantity as cost');

        /*
         * والبندُ المستقلّ: بلا منتجٍ، وليس طلبًا مخصَّصًا.
         *
         * الطلبُ المخصَّص بلا منتجٍ كذلك، ويُعرف بتفاصيله (`OrderItem::isCustom`).
         * ولا بندَ ثالثَ بلا منتجٍ يكتبه الصندوقُ أو الموقع — والموقعُ لا يقبل
         * سطرًا بلا منتجٍ أصلًا (`WebCheckout::assertPublished`).
         */
        $standalone = DB::table('order_items')
            ->whereIn('order_items.order_id', (clone $orders)->select('orders.id'))
            ->whereNull('order_items.product_id')
            ->whereNull('order_items.custom_details')
            ->selectRaw("order_items.order_id as order_id, '".self::STANDALONE."' as source, "
                .'order_items.standalone_addon_id as addon_id, order_items.name as name, '
                .'NULL as name_en, order_items.quantity as quantity, order_items.total as total, '
                .'COALESCE(order_items.cost, 0) * order_items.quantity as cost');

        return $attached->unionAll($standalone);
    }

    /**
     * صفٌّ لكلّ إضافة — بهويّتها واسمِها يومَ البيع.
     *
     * والمفتاحُ **المعرّفُ مع الاسم**: إضافةٌ أُعيدت تسميتها تبقى مبيعاتُها
     * القديمة باسمها القديم لا تُكتب بالجديد — وهي القاعدة نفسُها في ربحية
     * المنتجات (`Demo::productProfitability` تجمع بالمعرّف والاسم).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Builder $orders, ?int $limit = null): array
    {
        $rows = DB::query()->fromSub(self::lines($orders), 'x')
            ->selectRaw('addon_id, name, MAX(name_en) as name_en, '
                .'COUNT(DISTINCT order_id) as orders, COUNT(*) as uses, SUM(quantity) as quantity, '
                .'SUM(total) as revenue, SUM(cost) as cost, '
                ."SUM(CASE WHEN source = '".self::STANDALONE."' THEN quantity ELSE 0 END) as standalone_quantity")
            ->groupBy('addon_id', 'name')
            ->orderByDesc('revenue')->orderBy('name')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();

        return $rows->map(function ($r) {
            $revenue = round((float) $r->revenue, 3);
            $cost = round((float) $r->cost, 3);
            $quantity = (int) $r->quantity;

            return [
                // مفتاحٌ ثابتٌ للصفّ في الشاشة — لا معرّفَ في القاعدة لمجموعة
                'id' => ($r->addon_id ?? 'x').':'.$r->name,
                'addon_id' => $r->addon_id === null ? null : (int) $r->addon_id,
                'name' => Demo::ln($r->name, $r->name_en),
                'linked' => $r->addon_id !== null,
                // ويُقال في الورقة كما يُقال على الشاشة: هذا الصفُّ مجموعٌ بالاسم لا بالهويّة
                'link' => $r->addon_id !== null ? __('مربوطة بإضافة') : __('باسمها يوم البيع'),
                'orders' => (int) $r->orders,
                'uses' => (int) $r->uses,
                'quantity' => $quantity,
                'standalone_quantity' => (int) $r->standalone_quantity,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 3),
                'average' => $quantity > 0 ? round($revenue / $quantity, 3) : 0.0,
            ];
        })->values()->all();
    }

    /** عددُ الصفوف كلِّها — لخبر البتر حين يُقصّ الجدول عند سقفه */
    public static function count(Builder $orders): int
    {
        return (int) DB::query()->fromSub(
            DB::query()->fromSub(self::lines($orders), 'x')->select('addon_id', 'name')->groupBy('addon_id', 'name'),
            'g',
        )->count();
    }

    /**
     * المجاميع — باستعلامٍ واحد على الصفوف نفسها.
     *
     * و«عددُ الطلبات» طلباتٌ مميَّزة لا مجموعُ طلبات الصفوف: طلبٌ فيه
     * شوكولاتةٌ ودبٌّ طلبٌ واحد فيه إضافات، لا اثنان.
     *
     * @return array{uses: int, quantity: int, orders: int, revenue: float, cost: float, profit: float, unlinked: float}
     */
    public static function totals(Builder $orders): array
    {
        $t = DB::query()->fromSub(self::lines($orders), 'x')
            ->selectRaw('COUNT(*) as uses, COALESCE(SUM(quantity), 0) as quantity, '
                .'COUNT(DISTINCT order_id) as orders, COALESCE(SUM(total), 0) as revenue, '
                .'COALESCE(SUM(cost), 0) as cost, '
                .'COALESCE(SUM(CASE WHEN addon_id IS NULL THEN total ELSE 0 END), 0) as unlinked')
            ->first();

        $revenue = round((float) ($t->revenue ?? 0), 3);
        $cost = round((float) ($t->cost ?? 0), 3);

        return [
            'uses' => (int) ($t->uses ?? 0),
            'quantity' => (int) ($t->quantity ?? 0),
            'orders' => (int) ($t->orders ?? 0),
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => round($revenue - $cost, 3),
            // ما يُعرض باسمه وحده — يُقال على الشاشة لا يُخفى
            'unlinked' => round((float) ($t->unlinked ?? 0), 3),
        ];
    }
}
