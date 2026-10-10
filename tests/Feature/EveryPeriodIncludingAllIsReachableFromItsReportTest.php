<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CostsAndLosses;
use App\Support\Ledger;
use App\Support\ReportingPeriod;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * «كل الفترات» في التكاليف والهالك، وكلُّ سنةٍ فيها بياناتٌ في المنتقي.
 *
 * ═══ ما كان ═══
 *
 *   - التكاليفُ والهالك يردّان `period=all` بـ٤٢٢: المقارنةُ بالمدّة السابقة
 *     تحتاج حدّين. فلا يُقرأ العمرُ كلُّه في تقريرين يُسألان عنه.
 *   - السنواتُ في المنتقي من الطلبات والقيود والمصروفات والحركة وحدها. فنشاطٌ
 *     بدأ بجردٍ أو أمرِ شراءٍ أو كشفِ بنكٍ أو سجلّ نشاطٍ قبل أوّل طلب لا يجد
 *     سنتَه — وهي تُفتح بالرابط.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - «كل الفترات» بلا حدّ: ما قبل سنواتٍ يُعدّ، ولا تسقط إلى الشهر الجاري.
 *   - ولا مقارنةَ مختلَقة: السابقُ `null` على الشاشة، وشرطةٌ في الملفّ.
 *   - الفرعُ والفئةُ والقسمُ تبقى معها، والشاشةُ والسطورُ والملفّاتُ الثلاثة
 *     برقمٍ واحد.
 *   - أقدمُ سنةٍ في أيّ مصدرٍ تقرؤه التقارير في المنتقي، واختيارُها يعمل على
 *     الشاشة وفي الملفّ — والسنةُ المفتوحةُ بالرابط تُرى في المنتقي.
 */
class EveryPeriodIncludingAllIsReachableFromItsReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر العمر كله', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@all.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function props(string $route, array $query = []): array
    {
        return $this->get(route($route, $query))->assertOk()->viewData('page')['props'];
    }

    private function body(TestResponse $res): string
    {
        $res->assertOk();
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function filename(TestResponse $res): string
    {
        preg_match('/filename="?([^";]+)"?/', (string) $res->headers->get('content-disposition'), $m);

        return $m[1] ?? '';
    }

    /** @return list<list<mixed>> */
    private function sheet(TestResponse $res): array
    {
        $path = tempnam(sys_get_temp_dir(), 'all').'.xlsx';
        file_put_contents($path, $this->body($res));
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
        @unlink($path);

        return $rows;
    }

    private function cost(Business $shop, string $account, float $v, string $on, ?Branch $branch = null): void
    {
        Ledger::post($shop->id, 'قيد '.$account.' '.$v, [
            ['account' => $account, 'debit' => $v], ['account' => 'cash', 'credit' => $v],
        ], Carbon::parse($on), 'يدوي', $branch?->id);
    }

    private function product(string $name, ?Category $category = null): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'category_id' => $category?->id,
            'name' => $name, 'price' => 2, 'cost' => 1, 'quantity' => 500, 'active' => true,
        ]);
    }

    private function adjust(Product $product, float $delta, string $reason, string $at, Branch $branch, float $cost = 1): void
    {
        StockAdjustment::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'product_id' => $product->id,
            'number' => StockAdjustment::nextNumber($this->shop->id), 'quantity_delta' => $delta,
            'cost_at_time' => $cost, 'reason' => $reason, 'adjusted_at' => $at,
        ]);
    }

    /* ═══════════ ١ · التكاليف: كل الفترات بلا حدّ وبلا سابق ═══════════ */

    public function test_all_periods_of_costs_reads_every_year_without_a_made_up_previous_period(): void
    {
        $this->cost($this->shop, 'rent', 5, '2021-03-01', $this->muscat);
        $this->cost($this->shop, 'rent', 30, '2026-10-05', $this->muscat);
        $this->cost($this->shop, 'cogs', 400, '2022-06-01', $this->muscat);
        $this->cost($this->shop, 'rent', 100, '2024-01-01', $this->sohar);
        $this->cost($this->other, 'rent', 9000, '2023-01-01');
        // غيرُ مدفوعٍ قبل خمس سنين — المطابقةُ تقرؤه كما يقرأ الدفترُ قيودَه
        Expense::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'type' => 'تشغيلية',
            'description' => 'فاتورة قديمة', 'amount' => 3, 'method' => 'نقدي', 'status' => 'غير مدفوع',
            'employee_name' => 'المالك', 'spent_at' => '2021-02-01',
        ]);

        $query = ['period' => 'all', 'branch_id' => (string) $this->muscat->id, 'category' => CostsAndLosses::OPERATING];
        $screen = $this->props('admin.reports.costs', $query);

        // الإيجارُ في مسقط وحده: ٢٠٢١ و٢٠٢٦ — لا صحار ولا تكلفةُ المبيعات ولا الجار
        $this->assertEquals(35.0, $screen['summary']['total'], 'سقطت «كل الفترات» إلى غير العمر كلِّه');
        $this->assertNull($screen['filters']['from']);
        $this->assertNull($screen['filters']['to']);
        $this->assertSame((string) $this->muscat->id, $screen['filters']['branch_id']);
        $this->assertSame(CostsAndLosses::OPERATING, $screen['filters']['category']);
        $this->assertSame('all', $screen['period']['kind']);
        $this->assertSame('كل الفترات', $screen['period']['label']);
        $this->assertTrue($screen['period']['capabilities']['all']);

        // لا سابقَ لكلّ شيء: لا مدّةٌ مختلَقة ولا أصفارٌ تُقرأ أرقامًا
        $this->assertNull($screen['scope']['previous']);
        $this->assertNull($screen['previousSummary']);
        foreach ($screen['comparison'] as $row) {
            $this->assertNull($row['previous']);
            $this->assertNull($row['delta']);
            $this->assertNull($row['change_pct']);
        }
        $rent = collect($screen['categories'])->firstWhere('key', CostsAndLosses::OPERATING);
        $this->assertNull($rent['previous']);
        $this->assertNull($rent['rows'][0]['previous']);
        $this->assertNull($rent['rows'][0]['delta']);
        $this->assertSame(1, $screen['reconciliation']['unpaid_count']);

        // السطورُ تحت الصفّ بالفترة نفسِها — لا الشهرُ الجاري
        $lines = $this->getJson(route('admin.reports.costs.lines', $query))->assertOk()->json();
        $this->assertEquals(35.0, $lines['total']);
        $this->assertSame(2, $lines['count']);
        $this->assertSame('2021-03-01', $lines['lines'][0]['date']);

        // والملفّاتُ الثلاثة
        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'costs'] + $query));
        $this->assertSame('report-costs-all.csv', $this->filename($csv));
        $body = $this->body($csv);
        $this->assertStringContainsString('كل الفترات', $body);
        $this->assertStringNotContainsString('2026-10-01', $body, 'رأسُ الملفّ يقول الشهرَ الجاري');

        $xlsx = $this->get(route('admin.reports.export.xlsx', ['report' => 'costs'] + $query));
        $this->assertSame('report-costs-all.xlsx', $this->filename($xlsx));
        $accounts = array_values(array_filter($this->sheet($xlsx), fn ($r) => ($r[0] ?? null) === 'مصروفات التشغيل' && ($r[2] ?? null) !== null));
        $this->assertCount(1, $accounts);
        // [الفئة، الرمز، الحساب، هذه المدّة، الحصّة، السابقة، الفرق، التغيّر]
        $this->assertEquals(35, $accounts[0][3]);
        $this->assertSame(['—', '—', '—'], [$accounts[0][5], $accounts[0][6], $accounts[0][7]], 'كُتبت مقارنةٌ لا وجود لها');

        $pdf = $this->get(route('admin.reports.export.pdf', ['report' => 'costs'] + $query));
        $pdf->assertOk();
        $this->assertSame('report-costs-all.pdf', $this->filename($pdf));
    }

    public function test_a_bounded_costs_period_still_compares_with_the_one_before(): void
    {
        $this->cost($this->shop, 'rent', 12, '2025-08-10');
        $this->cost($this->shop, 'rent', 30, '2025-09-15');

        $screen = $this->props('admin.reports.costs', ['period' => 'month', 'month' => '2025-09']);

        $this->assertEquals(12.0, $screen['previousSummary']['total']);
        $this->assertSame('2025-08-01', $screen['scope']['previous']['from']);
        $this->assertEquals(18.0, $screen['comparison'][0]['delta']);

        // والزرُّ القديم `range=all` يفتح كما كان: من أوّل الشهر إلى اليوم
        $legacy = $this->props('admin.reports.costs', ['range' => 'all']);
        $this->assertSame(['2026-10-01', '2026-10-10'], [$legacy['filters']['from'], $legacy['filters']['to']]);
        $this->assertNotNull($legacy['previousSummary']);
    }

    /* ═══════════ ٢ · الهالك: كل الفترات بلا حدّ وبلا سابق ═══════════ */

    public function test_all_periods_of_waste_reads_every_year_on_screen_and_in_the_three_files(): void
    {
        $flowers = Category::create(['business_id' => $this->shop->id, 'name' => 'ورود']);
        $vases = Category::create(['business_id' => $this->shop->id, 'name' => 'مزهريات']);
        $rose = $this->product('ورد أبيض', $flowers);
        $vase = $this->product('مزهرية', $vases);

        $this->adjust($rose, -4, 'تلف', '2022-05-10 10:00:00', $this->muscat);
        $this->adjust($rose, -3, 'تلف', '2026-10-05 10:00:00', $this->muscat);
        $this->adjust($rose, -50, 'تلف', '2024-02-02 10:00:00', $this->sohar);
        $this->adjust($vase, -9, 'فقد', '2023-07-07 10:00:00', $this->muscat);
        // استهلاكُ وصفةٍ في ٢٠٢٢ — مقامُ «الهالك مقابل الاستهلاك» من العمر كلِّه أيضًا
        $used = InventoryMovement::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'product_id' => $rose->id,
            'product_name' => $rose->name, 'type' => StockLedger::RECIPE, 'quantity' => '-20',
        ]);
        DB::table('inventory_movements')->where('id', $used->id)->update(['created_at' => '2022-05-01 10:00:00']);

        $query = ['period' => 'all', 'branch_id' => (string) $this->muscat->id, 'category_id' => (string) $flowers->id];
        $screen = $this->props('admin.reports.waste', $query);

        $this->assertEquals(7.0, $screen['totals']['quantity'], 'سقطت «كل الفترات» إلى غير العمر كلِّه');
        $this->assertEquals(7.0, $screen['totals']['value']);
        $this->assertNull($screen['filters']['from']);
        $this->assertNull($screen['filters']['to']);
        $this->assertSame((string) $this->muscat->id, $screen['filters']['branch_id']);
        $this->assertSame('كل الفترات', $screen['period']['label']);
        // لا سابقَ ولا نسبةَ منه
        $this->assertNull($screen['previous']);
        $this->assertNull($screen['change']);
        // والمنحنى من أوّل شهرٍ فيه هالك — لا الأشهرُ الستّةُ الأخيرة
        $this->assertSame('2022-05', $screen['overTime'][0]['label']);
        $this->assertSame('2026-10', end($screen['overTime'])['label']);
        $this->assertEquals(7.0, array_sum(array_column($screen['overTime'], 'quantity')));
        $this->assertSame([['label' => 'ورد أبيض', 'waste' => 7.0, 'consumed' => 20.0, 'rate' => 35.0, 'value' => 7.0]], $screen['versusConsumption']);

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'waste'] + $query));
        $this->assertSame('report-waste-all.csv', $this->filename($csv));
        $body = $this->body($csv);
        $this->assertStringContainsString('كل الفترات', $body);
        // وصفُّ «مقابل الاستهلاك» كالشاشة: [الصنف، المستهلَك، الهالك، النسبة، القيمة]
        $this->assertStringContainsString('"ورد أبيض",20,7,35,7', $body, 'الاستهلاكُ في الملفّ من غير العمر كلِّه');

        $xlsx = $this->get(route('admin.reports.export.xlsx', ['report' => 'waste'] + $query));
        $this->assertSame('report-waste-all.xlsx', $this->filename($xlsx));
        $roseRows = array_values(array_filter($this->sheet($xlsx), fn ($r) => ($r[0] ?? null) === 'ورد أبيض'));
        $this->assertNotEmpty($roseRows);
        $this->assertEquals(7, $roseRows[0][1], 'الملفُّ بغير كميّة الشاشة');

        $pdf = $this->get(route('admin.reports.export.pdf', ['report' => 'waste'] + $query));
        $pdf->assertOk();
        $this->assertSame('report-waste-all.pdf', $this->filename($pdf));
    }

    /* ═══════════ ٣ · كلُّ سنةٍ فيها بياناتٌ في المنتقي ═══════════ */

    public function test_the_oldest_year_of_any_report_source_is_in_the_picker_and_opens_on_screen_and_in_the_file(): void
    {
        // الطلبُ الأوّل في ٢٠٢٥ — وما سواه أقدمُ منه
        Order::create([
            'business_id' => $this->shop->id, 'number' => 'P-1', 'status' => 'مكتمل', 'is_held' => false,
            'subtotal' => 10, 'total' => 10, 'ordered_at' => '2025-03-03 10:00:00',
        ]);

        $log = ActivityLog::create([
            'business_id' => $this->shop->id, 'user_id' => $this->owner->id, 'user_name' => 'المالك',
            'action' => 'created', 'subject_type' => 'product', 'subject_id' => 1,
            'description' => 'سجلٌّ من ٢٠١٩', 'icon' => 'plus', 'color' => 'primary',
        ]);
        DB::table('activity_logs')->where('id', $log->id)->update(['created_at' => '2019-04-04 10:00:00']);

        $this->adjust($this->product('صنف الجرد'), -2, StockAdjustment::STOCKTAKE_LOSS, '2020-05-05 10:00:00', $this->muscat);

        $supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'مورّد قديم']);
        PurchaseOrder::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'supplier_id' => $supplier->id,
            'number' => 'PO-2021', 'status' => 'مطلوب', 'total' => 60, 'ordered_at' => '2021-06-06 10:00:00',
        ]);

        BankStatementLine::create([
            'business_id' => $this->shop->id, 'date' => '2022-07-07',
            'description' => 'كشف ٢٠٢٢', 'reference' => 'BK-2022', 'amount' => 70,
        ]);

        // ومتجرُ الجار الأقدمُ منها كلِّها لا يُوسّع منتقي غيره
        $old = ActivityLog::create([
            'business_id' => $this->other->id, 'user_id' => 0, 'user_name' => 'الجار',
            'action' => 'created', 'subject_type' => 'product', 'subject_id' => 1,
            'description' => 'سجلّ الجار', 'icon' => 'plus', 'color' => 'primary',
        ]);
        DB::table('activity_logs')->where('id', $old->id)->update(['created_at' => '2001-01-01 10:00:00']);

        $this->assertSame(range(2026, 2019), ReportingPeriod::years($this->shop->id));

        $cases = [
            'activity' => ['2019', 'سجلٌّ من ٢٠١٩'],
            'stocktake' => ['2020', 'صنف الجرد'],
            'purchases' => ['2021', 'PO-2021'],
            'bank' => ['2022', 'BK-2022'],
        ];

        foreach ($cases as $report => [$year, $text]) {
            $query = ['period' => 'year', 'year' => $year];
            $screen = $this->props('admin.reports.'.$report, $query);

            $this->assertContains(2019, $screen['period']['years'], $report.': أقدمُ سنةٍ غائبةٌ عن المنتقي');
            $this->assertSame($year, $screen['period']['label']);
            $this->assertCount(1, $screen['rows'], $report.': سنتُه لا تقرأ ما فيها');

            $csv = $this->get(route('admin.reports.export.csv', ['report' => $report] + $query));
            $this->assertSame('report-'.$report.'-'.$year.'.csv', $this->filename($csv));
            $this->assertStringContainsString($text, $this->body($csv), $report.': الملفُّ بغير سنة الشاشة');

            // وخارجَ سنتِه لا يُقرأ
            $this->assertCount(0, $this->props('admin.reports.'.$report, ['period' => 'year', 'year' => '2023'])['rows']);
        }
    }

    /**
     * كلُّ مصدرٍ وحدَه يكفي لسنته — لا يختبئ مصدرٌ خلف آخرَ أقدمَ منه.
     *
     * نشاطٌ لكلّ مصدر، وفي كلٍّ صفٌّ واحدٌ بسنةٍ لا يشاركه فيها غيرُه.
     */
    public function test_each_report_source_alone_brings_its_oldest_year_to_the_picker(): void
    {
        $shop = fn () => Business::create(['name' => 'متجر مصدر', 'type' => 'عام', 'status' => 'نشط']);
        $back = fn (string $table, int $id, string $column, string $at) => DB::table($table)->where('id', $id)->update([$column => $at]);

        $sources = [
            2011 => function (Business $b) {
                Order::create(['business_id' => $b->id, 'number' => 'O-1', 'status' => 'مكتمل', 'is_held' => false,
                    'subtotal' => 1, 'total' => 1, 'ordered_at' => '2011-01-01 10:00:00']);
            },
            2012 => function (Business $b) {
                Ledger::seedChart($b->id);
                Ledger::post($b->id, 'قيد', [['account' => 'rent', 'debit' => 1], ['account' => 'cash', 'credit' => 1]], Carbon::parse('2012-01-01'));
            },
            2013 => function (Business $b) {
                Expense::create(['business_id' => $b->id, 'type' => 'تشغيلية', 'description' => 'م', 'amount' => 1,
                    'method' => 'نقدي', 'status' => Expense::PAID, 'employee_name' => 'م', 'spent_at' => '2013-01-01']);
            },
            2014 => function (Business $b) {
                Transaction::create(['business_id' => $b->id, 'reference' => Transaction::nextReference($b->id),
                    'description' => 'ح', 'method' => 'نقدي', 'type' => 'دخل', 'kind' => 'income',
                    'amount' => 1, 'employee_name' => 'م', 'occurred_at' => '2014-01-01 10:00:00']);
            },
            2015 => function (Business $b) {
                $supplier = Supplier::create(['business_id' => $b->id, 'name' => 'مورّد']);
                SupplierInvoice::create(['business_id' => $b->id, 'supplier_id' => $supplier->id, 'supplier_ref' => 'SI-1',
                    'issued_at' => '2015-01-01', 'subtotal' => 1, 'tax' => 0, 'total' => 1, 'status' => 'غير مدفوعة']);
            },
            2016 => function (Business $b) {
                BankStatementLine::create(['business_id' => $b->id, 'date' => '2016-01-01', 'description' => 'ك', 'reference' => 'B', 'amount' => 1]);
            },
            2017 => function (Business $b) {
                $supplier = Supplier::create(['business_id' => $b->id, 'name' => 'مورّد']);
                PurchaseOrder::create(['business_id' => $b->id, 'supplier_id' => $supplier->id, 'number' => 'PO-1',
                    'status' => 'مطلوب', 'total' => 1, 'ordered_at' => '2017-01-01 10:00:00']);
            },
            2018 => function (Business $b) {
                $p = Product::create(['business_id' => $b->id, 'name' => 'ص', 'price' => 1, 'cost' => 1, 'quantity' => 5, 'active' => true]);
                StockAdjustment::create(['business_id' => $b->id, 'product_id' => $p->id, 'number' => StockAdjustment::nextNumber($b->id),
                    'quantity_delta' => -1, 'cost_at_time' => 1, 'reason' => 'تلف', 'adjusted_at' => '2018-01-01 10:00:00']);
            },
            2019 => function (Business $b) use ($back) {
                $log = ActivityLog::create(['business_id' => $b->id, 'user_id' => 0, 'user_name' => 'م', 'action' => 'created',
                    'subject_type' => 'product', 'subject_id' => 1, 'description' => 'س', 'icon' => 'plus', 'color' => 'primary']);
                $back('activity_logs', $log->id, 'created_at', '2019-01-01 10:00:00');
            },
            2020 => function (Business $b) use ($back) {
                $m = InventoryMovement::create(['business_id' => $b->id, 'product_name' => 'ص', 'type' => StockLedger::RECIPE, 'quantity' => '-1']);
                $back('inventory_movements', $m->id, 'created_at', '2020-01-01 10:00:00');
            },
        ];

        $this->assertCount(count(ReportingPeriod::SOURCES), $sources, 'مصدرٌ جديدٌ في `SOURCES` بلا حالةٍ هنا');

        foreach ($sources as $year => $seed) {
            $b = $shop();
            $seed($b);

            $this->assertSame(range(2026, $year), ReportingPeriod::years($b->id), 'سنةُ '.$year.' غابت عن المنتقي');
        }
    }

    public function test_a_year_opened_by_link_is_shown_in_the_picker_even_without_data(): void
    {
        $screen = $this->props('admin.reports.activity', ['period' => 'year', 'year' => '2015']);

        $this->assertContains(2015, $screen['period']['years'], 'سنةٌ تُفتح بالرابط ولا يعرضها المنتقي');
        $this->assertSame(2026, $screen['period']['years'][0]);

        $range = $this->props('admin.reports.orders', ['period' => 'month_range', 'month_from' => '2012-11', 'month_to' => '2013-02']);
        $this->assertSame(range(2026, 2012), $range['period']['years']);
    }
}
