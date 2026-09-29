<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AddonSales;
use App\Support\Demo;
use App\Support\Document\Pdf\Driver as PdfDriver;
use App\Support\OrderCorrection;
use App\Support\Pdf;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * الإضافاتُ والتوصيلُ يُقرآن ممّا بيع — كشفٌ لجزءٍ من الإجمالي لا رقمٌ فوقه.
 *
 * ═══ وأثقلُ ما يُحرس ═══
 *
 * أنّ ثمنَ الإضافة ورسمَ التوصيل **داخل** `orders.total` منذ البيع، فلا
 * يعدّهما تقريرٌ مرّتين: بوكيه بعشرة ولفّتان بثلاثة ودبٌّ بأربعة ورسمُ
 * توصيلٍ باثنين طلبٌ واحدٌ بتسعة عشر — لا بستّةٍ وعشرين.
 *
 * ثمّ أنّ الماضي لا يتحرّك: شوكولاتةٌ بيعت باسمها وسعرها تبقى كذلك وإن
 * تغيّر اسمُها أو سعرُها أو حُذفت. وأنّ ما لا هويّةَ له لا يُنسب بالظنّ.
 */
class AddonsAndDeliveriesAreReadFromWhatWasSoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $main;

    private Branch $second;

    private User $owner;

    private Product $bouquet;

    private Product $bearStock;

    /** لفٌّ — خدمةٌ بلا مخزون */
    private Addon $wrap;

    /** شوكولاتة — بسعرها يومَ البيع */
    private Addon $chocolate;

    /** دبٌّ — قطعةٌ من الرفّ، يُباع بندًا مستقلًّا من شريط الصندوق */
    private Addon $bear;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'محل الورد', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->second = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع صحار']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@ward.test',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        // الفرعان يبيعان بلا أن يُوزَّع الرفُّ عليهما — ليس المخزونُ ما يُختبر هنا
        Setting::create(['business_id' => $this->business->id, 'key' => 'allow_negative_stock', 'value' => '1']);
        // المثالُ بلا ضريبةٍ صراحةً — لا يُفترض صفرُها. وحالُ الضريبة في اختبارٍ وحدَه
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->bouquet = $this->product('بوكيه الحب', 10.000, 3.000);
        $this->bearStock = $this->product('دبّ صغير', 0, 1.500);

        $this->wrap = $this->addon('لفّ فاخر', 1.500);
        $this->chocolate = $this->addon('Chocolate', 2.000);
        $this->bear = $this->addon('دبّ', 4.000, $this->bearStock->id);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function product(string $name, float $price, float $cost, ?Business $business = null): Product
    {
        $business ??= $this->business;
        $p = Product::create([
            'business_id' => $business->id, 'name' => $name,
            'price' => $price, 'cost' => $cost, 'quantity' => 100, 'active' => true,
        ]);
        BranchStock::ensureAllocated($business->id, $p->id, 100);

        return $p;
    }

    private function addon(string $name, float $price, ?int $stockId = null, ?Business $business = null): Addon
    {
        return Addon::create([
            'business_id' => ($business ?? $this->business)->id, 'name' => $name, 'price' => $price, 'active' => true,
            'inventory_product_id' => $stockId,
            'inventory_quantity' => $stockId ? 1 : null,
        ]);
    }

    /** بندُ بوكيه — بإضافاته إن وُجدت */
    private function bouquetLine(array $addons = [], int $qty = 1): array
    {
        return ['id' => $this->bouquet->id, 'name' => 'بوكيه الحب', 'qty' => $qty, 'addons' => $addons];
    }

    /** إضافةٌ مستقلّة من شريط الصندوق — كما تُرسلها الشاشة (`addonLine`) */
    private function standalone(Addon $addon, int $qty = 1): array
    {
        return ['id' => null, 'addon_id' => $addon->id, 'name' => $addon->name, 'qty' => $qty];
    }

    /** بيعٌ حقيقيّ من باب الصندوق — لا صفٌّ يُكتب باليد */
    private function sell(array $items, array $extra = [], ?Branch $branch = null, ?User $by = null): Order
    {
        $this->actingAs($by ?? $this->owner)
            ->withSession(['current_branch' => ($branch ?? $this->main)->id])
            ->postJson('/pos/checkout', array_merge([
                'items' => $items,
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('addon', true),
            ], $extra))
            ->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** طلبُ توصيلٍ — بما يطلبه الخادم لطلبٍ يُجهَّز */
    private function delivery(float $fee): array
    {
        return [
            'customer' => 'سارة',
            'fulfillment_type' => 'delivery',
            'scheduled_for' => now()->addDay()->toDateTimeString(),
            'recipient_name' => 'مريم',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوض — شارع ١٨',
            'delivery_fee' => $fee,
        ];
    }

    private function pickup(): array
    {
        return [
            'customer' => 'خالد',
            'fulfillment_type' => 'pickup',
            'scheduled_for' => now()->addDay()->toDateTimeString(),
        ];
    }

    /** ما تعرضه صفحةُ تقرير — بخصائصها كما وصلت */
    private function props(string $route, array $query = []): array
    {
        return $this->actingAs($this->owner)->get(route($route, $query))->assertOk()->viewData('page')['props'];
    }

    private function addons(array $filters = []): array
    {
        $this->actingAs($this->owner);

        return ReportData::addons($this->business->id, $filters + ['range' => 'month']);
    }

    private function orders(array $filters = []): array
    {
        $this->actingAs($this->owner);

        return ReportData::orders($this->business->id, $filters + ['range' => 'month']);
    }

    private function row(array $report, string $name): ?array
    {
        return collect($report['rows'])->firstWhere('name', $name);
    }

    /** الطلبُ الواحد في المثال: بوكيه ١٠ + لفّتان ٣ + دبٌّ مستقلّ ٤ + توصيل ٢ */
    private function theExample(): Order
    {
        return $this->sell(
            [$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 2]]), $this->standalone($this->bear)],
            $this->delivery(2.000),
        );
    }

    /* ═══════════════ ١ · الإجماليُّ يحمل الإضافات والتوصيل أصلًا ═══════════════ */

    public function test_the_order_total_already_carries_the_addons_and_the_delivery_fee(): void
    {
        $order = $this->theExample();

        // ١٠ + ٢×١٫٥ + ٤ + ٢ — الإضافاتُ والرسمُ في الإجماليّ من لحظة البيع
        $this->assertSame('19.000', $order->total);
        $this->assertSame('2.000', $order->delivery_fee);
        $this->assertSame('17.000', $order->subtotal);
        $this->assertSame('0.000', $order->tax, 'المثالُ بلا ضريبة — لا يُفترض غيرُ ذلك');
        $this->assertSame('0.000', $order->discount);

        // وبندُ البوكيه يحمل إضافاته في `addons_total` — و`lineTotal` يجمعهما
        $item = $order->items()->whereNotNull('product_id')->firstOrFail();
        $this->assertSame('3.000', $item->addons_total);
        $this->assertSame(13.0, $item->lineTotal());
    }

    public function test_reading_the_reports_changes_no_stored_number(): void
    {
        $order = $this->theExample();
        $before = DB::table('orders')->where('id', $order->id)->first(['subtotal', 'discount', 'tax', 'delivery_fee', 'total']);
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['total', 'addons_total', 'cost'])->toArray();

        $this->addons();
        $this->orders();
        $this->props('admin.reports.addons');
        $this->props('admin.reports.orders');
        $this->actingAs($this->owner);
        Reports::salesReport('month');

        $this->assertEquals($before, DB::table('orders')->where('id', $order->id)->first(['subtotal', 'discount', 'tax', 'delivery_fee', 'total']));
        $this->assertEquals($items, DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['total', 'addons_total', 'cost'])->toArray());
    }

    /* ═══════════════ ٢ · المطابقة — لا شيء يُعدّ مرّتين ═══════════════ */

    public function test_the_example_reconciles_to_the_last_baisa(): void
    {
        $this->theExample();

        $addons = $this->addons();
        $wrap = $this->row($addons, 'لفّ فاخر');
        $bear = $this->row($addons, 'دبّ');

        // المرتبطةُ بالبند: لفّتان في طلبٍ واحد
        $this->assertSame(3.0, $wrap['revenue']);
        $this->assertSame(2, $wrap['quantity']);
        $this->assertSame(1, $wrap['orders'], 'عددُ الطلبات قُرئ كميّةً');
        $this->assertSame(1, $wrap['uses']);

        // والمستقلّة: دبٌّ واحد بأربعة
        $this->assertSame(4.0, $bear['revenue']);
        $this->assertSame(1, $bear['quantity']);
        $this->assertSame(1, $bear['standalone_quantity']);

        // والمجموعُ سبعة — من طلبٍ واحد
        $this->assertSame(7.0, $addons['summary']['revenue']);
        $this->assertSame(1, $addons['summary']['orders']);
        $this->assertSame(2, $addons['summary']['uses']);
        $this->assertSame(3, $addons['summary']['quantity']);
        // وإجماليُّ الطلبات نفسِها تسعة عشر — لا ستّةٌ وعشرون
        $this->assertSame(19.0, $addons['summary']['sales']);

        $orders = $this->orders();
        $this->assertSame(19.0, $orders['summary']['total'], 'أُضيفت الإضافاتُ أو الرسمُ على الإجماليّ');
        $this->assertSame(19.0, $orders['summary']['average']);
        $this->assertSame(7.0, $orders['summary']['addons']);
        $this->assertSame(2.0, $orders['summary']['delivery_fees']);
        $this->assertSame(1, $orders['summary']['delivery']);
        $this->assertSame(0, $orders['summary']['pickup']);

        // وملخّصُ المبيعات: الإجماليُّ كما هو، و«منها إضافات» جزءٌ منه
        $this->actingAs($this->owner);
        $summary = Demo::reportSummary('month');
        $this->assertSame(19.0, (float) $summary['sales']);
        $this->assertSame(7.0, $summary['addons']);
    }

    public function test_with_vat_on_the_addons_are_still_inside_the_total_not_added_to_it(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $order = $this->theExample();
        $tax = (float) $order->tax;

        // الضريبةُ على الوعاء (١٧) — والإجماليُّ يحملها مع الإضافات والرسم
        $this->assertGreaterThan(0, $tax);
        $this->assertSame(round(17.0 + $tax + 2.0, 3), (float) $order->total);

        // وقيمةُ الإضافات بأسعارها كما بيعت — لا تتغيّر بالضريبة ولا تُضاف فوق الإجماليّ
        $this->assertSame(7.0, $this->addons()['summary']['revenue']);
        $this->assertSame((float) $order->total, $this->orders()['summary']['total']);
        $this->assertSame((float) $order->total, $this->addons()['summary']['sales']);
    }

    public function test_the_sales_summary_numbers_are_unchanged_by_the_new_line(): void
    {
        $this->theExample();
        $this->actingAs($this->owner);

        $summary = Demo::reportSummary('month');
        $rows = collect(Reports::summaryRows($summary))->pluck('value', 'label');

        // «منها إضافات» سطرٌ يلي الإجماليّ — ولا يغيّر الربحَ ولا الضريبة ولا التكلفة
        $this->assertSame(19.0, $rows[__('إجمالي المبيعات')]);
        $this->assertSame(7.0, $rows[__('منها إضافات')]);
        $this->assertSame(round(19.0 - 0.0 - (float) $summary['cogs'] - (float) $summary['expenses'], 3), (float) $summary['profit']);
        $labels = collect(Reports::summaryRows($summary))->pluck('label')->all();
        $this->assertSame(array_search(__('إجمالي المبيعات'), $labels) + 1, array_search(__('منها إضافات'), $labels));
    }

    public function test_addon_cost_is_the_snapshot_cost_times_the_quantity(): void
    {
        // دبّان مستقلّان: تكلفةُ الواحد ١٫٥ من صنف الرفّ — فالتكلفة ثلاثة
        $this->sell([$this->standalone($this->bear, 2)]);
        // ثمّ يرتفع ثمنُ الدبّ في المخزن — ولا تتحرّك تكلفةُ ما بيع
        $this->bearStock->update(['cost' => 9.000]);

        $bear = $this->row($this->addons(), 'دبّ');

        $this->assertSame(8.0, $bear['revenue']);
        $this->assertSame(3.0, $bear['cost']);
        $this->assertSame(5.0, $bear['profit']);
        $this->assertSame(4.0, $bear['average']);
    }

    public function test_an_attached_stocked_addon_costs_per_unit_times_its_quantity(): void
    {
        // الدبُّ على البوكيه ثلاثًا: تكلفةُ اللقطة للواحدة، وتُضرب في الكميّة
        $this->sell([$this->bouquetLine([['addon_id' => $this->bear->id, 'qty' => 3]])]);

        $bear = $this->row($this->addons(), 'دبّ');

        $this->assertSame(12.0, $bear['revenue']);
        $this->assertSame(4.5, $bear['cost']);
        $this->assertSame(7.5, $bear['profit']);
    }

    public function test_orders_count_is_distinct_orders_not_lines_nor_quantity(): void
    {
        // طلبٌ فيه اللفُّ على بندين — مرّتا استخدام، وطلبٌ واحد، وكميّةُ أربع
        $this->sell([
            $this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 3]]),
            $this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]]),
        ]);
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $wrap = $this->row($this->addons(), 'لفّ فاخر');

        $this->assertSame(2, $wrap['orders']);
        $this->assertSame(3, $wrap['uses']);
        $this->assertSame(5, $wrap['quantity']);
        $this->assertSame(7.5, $wrap['revenue']);
    }

    /* ═══════════════ ٣ · الماضي لا يتحرّك ═══════════════ */

    public function test_a_renamed_repriced_or_disabled_addon_keeps_its_sold_name_and_price(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]])]);

        $this->chocolate->update(['name' => 'Dark Chocolate', 'price' => 3.500, 'active' => false]);

        $report = $this->addons();
        $row = $this->row($report, 'Chocolate');

        $this->assertNotNull($row, 'التقريرُ قرأ الاسمَ من الإضافة اليوم');
        $this->assertSame(2.0, $row['revenue']);
        $this->assertSame(2.0, $row['average']);
        $this->assertTrue($row['linked']);
        $this->assertNull($this->row($report, 'Dark Chocolate'));
    }

    public function test_a_deleted_addon_keeps_its_history_as_an_unlinked_snapshot(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]])]);
        $this->sell([$this->standalone($this->bear)]);

        $this->chocolate->delete();
        $this->bear->delete();

        $report = $this->addons();
        $chocolate = $this->row($report, 'Chocolate');
        $bear = $this->row($report, 'دبّ');

        $this->assertSame(2.0, $chocolate['revenue']);
        $this->assertFalse($chocolate['linked']);
        $this->assertSame(4.0, $bear['revenue']);
        $this->assertFalse($bear['linked']);
        $this->assertSame(6.0, $report['summary']['unlinked']);
    }

    /* ═══════════════ ٤ · الإضافةُ المستقلّة ═══════════════ */

    public function test_a_standalone_addon_now_remembers_which_addon_it_was(): void
    {
        $order = $this->sell([$this->standalone($this->bear, 2)]);
        $line = $order->items()->firstOrFail();

        $this->assertNull($line->product_id);
        $this->assertSame($this->bear->id, (int) $line->standalone_addon_id);
        // ولا يتغيّر ما يُحسب: السعرُ والمجموعُ والتكلفةُ كما كانت
        $this->assertSame('4.000', $line->price);
        $this->assertSame('8.000', $line->total);
        $this->assertSame('0.000', $line->addons_total);
        $this->assertEquals(1.5, (float) $line->cost);
    }

    public function test_a_product_line_carries_no_standalone_identity(): void
    {
        $order = $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $this->assertNull($order->items()->firstOrFail()->standalone_addon_id);
    }

    public function test_the_same_addon_on_a_line_and_standalone_is_one_row(): void
    {
        $this->sell([
            $this->bouquetLine([['addon_id' => $this->bear->id, 'qty' => 1]]),
            $this->standalone($this->bear, 2),
        ]);

        $rows = collect($this->addons()['rows'])->where('name', 'دبّ');

        $this->assertCount(1, $rows, 'المرتبطةُ والمستقلّة لإضافةٍ واحدة انقسمتا صفّين');
        $this->assertSame(3, $rows->first()['quantity']);
        $this->assertSame(2, $rows->first()['standalone_quantity']);
        $this->assertSame(1, $rows->first()['orders']);
        $this->assertSame(2, $rows->first()['uses']);
    }

    public function test_an_old_standalone_line_without_identity_is_shown_by_its_name_and_does_not_break(): void
    {
        // بندٌ مستقلٌّ من قبل العمود — بلا منتجٍ ولا معرّف
        $order = $this->sell([$this->bouquetLine()]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'standalone_addon_id' => null,
            'name' => 'بطاقة قديمة', 'price' => 1.000, 'cost' => 0.200, 'quantity' => 3,
            'total' => 3.000, 'addons_total' => 0,
        ]);

        $old = $this->row($this->addons(), 'بطاقة قديمة');

        $this->assertNotNull($old);
        $this->assertFalse($old['linked'], 'بندٌ بلا هويّةٍ نُسب إلى إضافة');
        $this->assertNull($old['addon_id']);
        $this->assertSame(3.0, $old['revenue']);
        $this->assertSame(0.6, $old['cost']);
    }

    public function test_an_old_line_is_not_guessed_onto_a_live_addon_with_the_same_name(): void
    {
        $this->sell([$this->standalone($this->bear)]);
        $order = $this->sell([$this->bouquetLine()]);
        // بندٌ قديمٌ اسمُه «دبّ» — ولا دليلَ أنّه هذه الإضافة
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null,
            'name' => 'دبّ', 'price' => 4.000, 'cost' => 0, 'quantity' => 1, 'total' => 4.000, 'addons_total' => 0,
        ]);

        $bears = collect($this->addons()['rows'])->where('name', 'دبّ');

        $this->assertCount(2, $bears);
        $this->assertSame([true, false], $bears->sortByDesc('linked')->pluck('linked')->values()->all());
    }

    public function test_a_custom_arrangement_line_is_not_an_addon(): void
    {
        $order = $this->sell([$this->bouquetLine()]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null,
            'name' => 'باقة مخصّصة', 'price' => 25.000, 'cost' => 0, 'quantity' => 1, 'total' => 25.000,
            'addons_total' => 0, 'custom_details' => ['mode' => 'value', 'template' => ['id' => 1]],
        ]);

        $this->assertNull($this->row($this->addons(), 'باقة مخصّصة'));
        $this->assertSame(0.0, $this->addons()['summary']['revenue']);
    }

    public function test_a_standalone_addon_stays_out_of_the_products_report(): void
    {
        $this->sell([$this->bouquetLine(), $this->standalone($this->bear)]);
        $this->actingAs($this->owner);

        $products = ReportData::products($this->business->id, ['range' => 'month']);

        // المنتجاتُ بمعرّفها — والإضافةُ المستقلّة ليست منتجًا، كما كانت
        $this->assertSame(10.0, $products['summary']['revenue']);
        $this->assertNull(collect($products['rows'])->firstWhere('name', 'دبّ'));
    }

    public function test_product_profitability_reads_the_standalone_line_as_it_always_did(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 2]]), $this->standalone($this->bear)]);
        $this->actingAs($this->owner);

        $rows = collect(Demo::productProfitability('month'))->keyBy('name');

        // البوكيه يحمل إضافته المرتبطة (١٠ + ٣)، والبندُ المستقلّ صفُّه كما كان قبل العمود
        $this->assertSame(13.0, (float) $rows['بوكيه الحب']['revenue']);
        $this->assertSame(4.0, (float) $rows['دبّ']['revenue']);
    }

    /* ═══════════════ ٥ · الفروع والقنوات والمتاجر والمدّة ═══════════════ */

    public function test_one_branch_does_not_leak_into_another(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])], branch: $this->main);
        $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]])], branch: $this->second);

        $main = $this->addons(['branch_id' => (string) $this->main->id]);
        $second = $this->addons(['branch_id' => (string) $this->second->id]);

        $this->assertSame(['لفّ فاخر'], collect($main['rows'])->pluck('name')->all());
        $this->assertSame(['Chocolate'], collect($second['rows'])->pluck('name')->all());
        $this->assertSame(3.5, $this->addons()['summary']['revenue']);
    }

    public function test_pos_and_website_are_separated_by_channel(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        // الموقعُ لا يبيع إضافاتٍ اليوم — فقناتُه صفرٌ صادق لا نسخةٌ من الصندوق
        $this->assertSame(1.5, $this->addons(['channel' => 'pos'])['summary']['revenue']);
        $this->assertSame(0.0, $this->addons(['channel' => 'website'])['summary']['revenue']);
        $this->assertSame([], $this->addons(['channel' => 'website'])['rows']);
    }

    public function test_an_order_from_before_the_channel_column_is_read_under_unknown(): void
    {
        $order = $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);
        DB::table('orders')->where('id', $order->id)->update(['channel' => null]);

        $this->assertSame(1.5, $this->addons(['channel' => 'unknown'])['summary']['revenue']);
        $this->assertSame(0.0, $this->addons(['channel' => 'pos'])['summary']['revenue']);
    }

    public function test_an_unknown_channel_reads_the_whole_shop_not_an_error(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $this->assertSame(1.5, $this->addons(['channel' => 'mars'])['summary']['revenue']);
    }

    public function test_one_shop_never_reads_another_shops_addons(): void
    {
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $otherBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $otherOwner = User::create([
            'business_id' => $other->id, 'name' => 'مالكهم', 'email' => 'other@ward.test',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $other->id, 'key' => 'allow_negative_stock', 'value' => '1']);
        $theirProduct = $this->product('وردهم', 5.000, 1.000, $other);
        $theirAddon = $this->addon('إضافتهم', 9.000, null, $other);
        $this->actingAs($otherOwner)->withSession(['current_branch' => $otherBranch->id])
            ->postJson('/pos/checkout', [
                'items' => [['id' => $theirProduct->id, 'name' => 'وردهم', 'qty' => 1, 'addons' => [['addon_id' => $theirAddon->id, 'qty' => 1]]]],
                'payment_method' => 'نقدي', 'client_uuid' => uniqid('o', true),
            ])->assertOk();

        $mine = $this->addons();
        $this->assertSame(['لفّ فاخر'], collect($mine['rows'])->pluck('name')->all());
        $this->assertSame(1.5, $mine['summary']['revenue']);

        // وفرعُ متجرٍ آخر في الرابط لا يفتح شيئًا منه
        $this->assertSame([], $this->addons(['branch_id' => (string) $otherBranch->id])['rows']);

        // والصفحةُ تقرأ متجرَ صاحب الجلسة
        $screen = $this->props('admin.reports.addons');
        $this->assertSame(1.5, $screen['summary']['revenue']);
    }

    public function test_the_month_does_not_read_a_sale_outside_it(): void
    {
        $old = $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]])]);
        DB::table('orders')->where('id', $old->id)->update(['ordered_at' => now()->startOfMonth()->subDay()]);
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $this->assertSame(['لفّ فاخر'], collect($this->addons(['range' => 'month'])['rows'])->pluck('name')->all());
        $this->assertSame(3.5, $this->addons(['range' => 'all'])['summary']['revenue']);
    }

    public function test_a_cancelled_order_sells_no_addon(): void
    {
        $cancelled = $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]]), $this->standalone($this->bear)]);
        OrderCorrection::cancel($cancelled->fresh());
        $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]])]);

        $report = $this->addons();

        $this->assertSame(Order::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(['لفّ فاخر'], collect($report['rows'])->pluck('name')->all());
        $this->assertSame(1.5, $report['summary']['revenue']);
        $this->assertSame(1, $report['summary']['orders']);
        // وملخّصُ الطلبات يقرأ الشيءَ نفسَه: إضافاتُ الملغى ليست فيه
        $this->assertSame(1.5, $this->orders()['summary']['addons']);
    }

    public function test_a_held_cart_sells_no_addon(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->main->id])
            ->postJson('/pos/hold', ['items' => [$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1]]), $this->standalone($this->bear)]])
            ->assertSuccessful();

        $held = Order::where('is_held', true)->latest('id')->firstOrFail();
        $this->assertSame($this->bear->id, (int) $held->items()->whereNull('product_id')->value('standalone_addon_id'));
        $this->assertSame(0.0, $this->addons()['summary']['revenue']);
    }

    /* ═══════════════ ٦ · التوصيل والاستلام ═══════════════ */

    public function test_delivery_and_pickup_are_counted_from_the_fulfillment_type(): void
    {
        $this->sell([$this->bouquetLine()], $this->delivery(2.000));
        // توصيلٌ بلا رسم — يبقى توصيلًا
        $this->sell([$this->bouquetLine()], $this->delivery(0));
        $this->sell([$this->bouquetLine()], $this->pickup());
        // بيعةُ منضدة — لا توصيل ولا استلام
        $this->sell([$this->bouquetLine()]);

        $summary = $this->orders()['summary'];

        $this->assertSame(2, $summary['delivery'], 'توصيلٌ برسمٍ صفر لم يُعدّ توصيلًا');
        $this->assertSame(1, $summary['pickup']);
        $this->assertSame(2.0, $summary['delivery_fees']);
        $this->assertSame(4, $summary['count']);
        // والإجماليُّ مجموعُ الطلبات — الرسمُ فيه مرّةً واحدة
        $this->assertSame(42.0, $summary['total']);
    }

    public function test_a_cancelled_delivery_is_not_a_delivery_done_but_stays_in_the_table(): void
    {
        $gone = $this->sell([$this->bouquetLine()], $this->delivery(3.000));
        OrderCorrection::cancel($gone->fresh());
        $this->sell([$this->bouquetLine()], $this->delivery(2.000));

        $report = $this->orders();

        $this->assertSame(1, $report['summary']['delivery']);
        $this->assertSame(2.0, $report['summary']['delivery_fees']);
        $this->assertSame(1, $report['summary']['cancelled']);
        // والجدولُ كما كان: الملغى فيه موسومًا
        $this->assertCount(2, $report['rows']);
        $this->assertContains(Order::CANCELLED, collect($report['rows'])->pluck('status')->all());
    }

    public function test_the_fulfillment_filter_reads_the_column_not_the_fee(): void
    {
        $this->sell([$this->bouquetLine()], $this->delivery(0));
        $this->sell([$this->bouquetLine()], $this->pickup());
        $this->sell([$this->bouquetLine()]);

        $delivery = $this->orders(['fulfillment' => 'delivery']);
        $pickup = $this->orders(['fulfillment' => 'pickup']);
        $all = $this->orders(['fulfillment' => 'anything']);

        $this->assertSame(1, $delivery['summary']['count'], 'توصيلٌ برسمٍ صفر سقط من المرشّح');
        $this->assertSame([__('توصيل')], collect($delivery['rows'])->pluck('fulfillment')->all());
        $this->assertSame(1, $pickup['summary']['count']);
        $this->assertSame([__('استلام من المحل')], collect($pickup['rows'])->pluck('fulfillment')->all());
        // وقيمةٌ لا تُعرف لا تُرشّح — ولا تُفرغ الجدول
        $this->assertSame(3, $all['summary']['count']);
        $this->assertContains(null, collect($all['rows'])->pluck('fulfillment')->all());
    }

    public function test_the_orders_screen_keeps_the_fulfillment_filter_it_was_given(): void
    {
        $this->sell([$this->bouquetLine()], $this->delivery(1.000));
        $this->sell([$this->bouquetLine()], $this->pickup());

        $props = $this->props('admin.reports.orders', ['range' => 'month', 'fulfillment' => 'delivery']);

        $this->assertSame('delivery', $props['filters']['fulfillment']);
        $this->assertSame(1, $props['summary']['count']);
        $this->assertSame(1.0, $props['summary']['delivery_fees']);
        $this->assertSame(['pickup', 'delivery'], collect($props['options']['fulfillments'])->pluck('value')->all());
    }

    /* ═══════════════ ٧ · الشاشةُ والملفّاتُ الثلاثة من مصدرٍ واحد ═══════════════ */

    public function test_the_addons_report_is_a_real_page_in_the_analytics_index(): void
    {
        $entry = collect(Reports::ALL)->firstWhere('key', 'addons');

        $this->assertSame('analytical', $entry['category']);
        $this->assertSame('reports', $entry['section']);
        $this->assertSame('الإضافات', $entry['title']);

        $this->actingAs($this->owner);
        $this->assertContains('addons', collect(Reports::forUser($this->owner))->pluck('key')->all());
        $this->assertSame('Admin/Reports/Addons', $this->actingAs($this->owner)->get(route('admin.reports.addons'))->viewData('page')['component']);
    }

    public function test_the_addons_report_is_guarded_by_the_reports_section(): void
    {
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => 'clerk@ward.test',
            'password' => bcrypt('secret'), 'role' => 'accountant', 'status' => 'نشط',
            'permissions' => ['orders'],
        ]);

        $this->actingAs($clerk)->get(route('admin.reports.addons'))->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.reports.export.csv', 'addons'))->assertForbidden();
        $this->assertNotContains('addons', collect(Reports::forUser($clerk))->pluck('key')->all());
    }

    public function test_the_screen_and_the_three_files_carry_the_same_addon_numbers(): void
    {
        $this->theExample();
        $this->sell([$this->bouquetLine([['addon_id' => $this->chocolate->id, 'qty' => 1]])], branch: $this->second);

        $query = ['range' => 'month', 'branch_id' => (string) $this->main->id];
        $screen = $this->props('admin.reports.addons', $query);

        // الشاشة: الفرعُ الرئيسيّ وحده
        $this->assertSame(7.0, $screen['summary']['revenue']);
        $this->assertSame(['دبّ', 'لفّ فاخر'], collect($screen['rows'])->pluck('name')->all());

        // CSV
        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'addons'] + $query))->streamedContent();
        $this->assertStringContainsString(Demo::money(7.0), $csv);
        $this->assertStringNotContainsString('Chocolate', $csv, 'الملفّ تجاهل الفرع الذي رشّحت به الشاشة');

        // XLSX — الصفوفُ نفسُها بالترتيب نفسه وبالأرقام نفسها
        $path = tempnam(sys_get_temp_dir(), 'addons').'.xlsx';
        file_put_contents($path, $this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', ['report' => 'addons'] + $query))->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $headings = ReportColumns::headings('addons');
        $at = collect($sheet)->search(fn ($r) => array_slice($r, 0, count($headings)) === $headings);
        $this->assertNotFalse($at, 'رأسُ الجدول غائبٌ عن الورقة');
        $body = array_slice($sheet, $at + 1, count($screen['rows']));
        foreach ($screen['rows'] as $i => $row) {
            // والصفرُ في إكسل خليّةٌ فارغة (`fromArray` لا يكتبه) — يُقرأ صفرًا
            $cell = fn ($v) => $v === null ? 0.0 : (is_numeric($v) ? (float) $v : $v);
            $this->assertSame(array_map($cell, ReportColumns::cells('addons', $row)),
                array_map($cell, array_slice($body[$i], 0, count($headings))));
        }

        // PDF — البطاقاتُ نفسُها والصفوفُ نفسُها
        $html = $this->pdfHtml(route('admin.reports.export.pdf', ['report' => 'addons'] + $query));
        foreach (ReportColumns::cards('addons', $screen['summary']) as $card) {
            $this->assertStringContainsString($card['label'], $html);
            $this->assertStringContainsString($card['value'], $html);
        }
        $this->assertStringContainsString('لفّ فاخر', $html);
        $this->assertStringNotContainsString('Chocolate', $html);
    }

    public function test_the_orders_files_carry_the_delivery_and_addon_cards_and_the_fulfillment_column(): void
    {
        $this->theExample();
        $this->sell([$this->bouquetLine()], $this->pickup());

        $screen = $this->props('admin.reports.orders', ['range' => 'month']);
        $cards = collect(ReportColumns::cards('orders', $screen['summary']))->pluck('value', 'label');

        $this->assertSame(Demo::money(2.0), $cards[__('رسوم التوصيل — ضمن الإجمالي')]);
        $this->assertSame(Demo::money(7.0), $cards[__('قيمة الإضافات — ضمن الإجمالي')]);
        $this->assertSame('1', $cards[__('طلبات التوصيل (غير الملغاة)')]);
        $this->assertSame('1', $cards[__('طلبات الاستلام (غير الملغاة)')]);
        $this->assertSame(Demo::money(29.0), $cards[__('إجمالي المبيعات')]);

        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'orders', 'range' => 'month']))->streamedContent();
        $this->assertStringContainsString(__('رسوم التوصيل — ضمن الإجمالي'), $csv);
        $this->assertStringContainsString(__('نوع التنفيذ'), $csv);
        $this->assertStringContainsString(__('استلام من المحل'), $csv);

        $html = $this->pdfHtml(route('admin.reports.export.pdf', ['report' => 'orders', 'range' => 'month', 'fulfillment' => 'pickup']));
        $this->assertStringContainsString(__('استلام من المحل'), $html);
        $this->assertStringNotContainsString(__('توصيل').'<', $html, 'ملفُّ «استلام» حمل صفَّ توصيل');
    }

    public function test_the_export_obeys_the_fulfillment_filter_like_the_screen(): void
    {
        $this->sell([$this->bouquetLine()], $this->delivery(5.000));
        $this->sell([$this->bouquetLine()], $this->pickup());

        $csv = $this->actingAs($this->owner)
            ->get(route('admin.reports.export.csv', ['report' => 'orders', 'range' => 'month', 'fulfillment' => 'pickup']))
            ->streamedContent();

        $this->assertStringNotContainsString(Demo::money(5.0), $csv);
        $this->assertStringContainsString(Demo::money(0.0), $csv);
    }

    public function test_the_numbers_are_aggregated_in_the_database_not_line_by_line(): void
    {
        foreach (range(1, 6) as $i) {
            $this->sell([$this->bouquetLine([['addon_id' => $this->wrap->id, 'qty' => 1], ['addon_id' => $this->chocolate->id, 'qty' => 1]]), $this->standalone($this->bear)]);
        }
        $this->actingAs($this->owner);

        DB::enableQueryLog();
        ReportData::addons($this->business->id, ['range' => 'month']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // مجاميعُ وصفوفٌ وعدٌّ وإجماليٌّ وخياران — لا استعلامٌ لكلّ إضافة ولا لكلّ طلب
        $this->assertLessThanOrEqual(8, $queries, "التقريرُ نفّذ {$queries} استعلامًا");
    }

    public function test_the_single_source_is_the_one_every_reader_uses(): void
    {
        $this->theExample();
        $this->actingAs($this->owner);

        $orders = Order::where('business_id', $this->business->id)->sold();

        $this->assertSame(AddonSales::totals($orders)['revenue'], $this->addons()['summary']['revenue']);
        $this->assertSame(AddonSales::totals($orders)['revenue'], $this->orders()['summary']['addons']);
        $this->assertSame(AddonSales::totals($orders)['revenue'], Demo::reportSummary('month')['addons']);
    }

    /** ما كان سيُطبع — يُلتقط قبل المحرّك (انظر `TheSalesReportFollowsTheChosenBranchTest`) */
    private function pdfHtml(string $url): string
    {
        $fake = new class implements PdfDriver
        {
            public string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;

                return response('PDF');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('PDF');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 1.0;
            }
        };

        $was = Pdf::swap($fake);

        try {
            $this->actingAs($this->owner)->get($url)->assertOk();
        } finally {
            Pdf::swap($was);
        }

        return $fake->html;
    }
}
