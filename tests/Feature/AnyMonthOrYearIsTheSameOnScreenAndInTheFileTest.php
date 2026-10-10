<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\SalesChannel;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * شهرٌ مضى أو سنةٌ أو نطاق — تقرؤه الشاشةُ ويحمله ملفُّها بعينه.
 *
 * ═══ ما كان ═══
 *
 * التقاريرُ لا تعرف إلّا «هذا الشهر» و«هذه السنة» و«الكل». فمن أراد سبتمبر
 * الماضي لم يجد بابًا، والملفُّ يتبع الشاشة فلا يخرج منه ما لا تعرضه.
 *
 * ═══ ما يُحرس هنا — لكلّ سطحٍ مهمّ ═══
 *
 *   - داخلَ الفترة يُعدّ، وقبلها وبعدها ومتجرُ الجار لا يُعدّ — والحدّان عند
 *     منتصف الليل: ٢٣:٥٩:٥٩ من آخر يومٍ داخل، و٠٠:٠٠ من اليوم التالي خارج.
 *   - الشاشةُ والملفُّ برقمٍ واحد، والملفُّ يقول فترتَه في رأسه واسمِه.
 *   - الفرعُ والحالةُ والقناةُ تبقى مع الفترة، و«كل الفترات» لا تمحوها.
 *   - الصلاحيةُ والباقةُ كما كانتا، والروابطُ القديمةُ تفتح كما كانت.
 *   - المقارنةُ بالفترة السابقة بشكلها: سبتمبر بأغسطس، و٢٠٢٤ بـ٢٠٢٣.
 */
class AnyMonthOrYearIsTheSameOnScreenAndInTheFileTest extends TestCase
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

        $this->shop = Business::create(['name' => 'متجر الفترات', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        Branch::create(['business_id' => $this->other->id, 'name' => 'فرع الجار']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@periods.om',
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

    private function order(string $when, float $total, array $over = []): Order
    {
        static $n = 0;

        return Order::create($over + [
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id,
            'number' => 'P-'.(++$n), 'customer_name' => 'زبونة', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'is_held' => false, 'subtotal' => $total, 'discount' => 0,
            'tax' => 0, 'total' => $total, 'ordered_at' => $when,
        ]);
    }

    /** طلباتٌ على حدود سبتمبر ٢٠٢٥ — واثنان داخله، وطلبُ الجار في منتصفه */
    private function septemberEdges(): void
    {
        $this->order('2025-08-31 23:59:59', 1);
        $this->order('2025-09-01 00:00:00', 10);
        $this->order('2025-09-30 23:59:59', 20);
        $this->order('2025-10-01 00:00:00', 100);
        Order::create([
            'business_id' => $this->other->id, 'number' => 'N-1', 'status' => 'مكتمل', 'is_held' => false,
            'subtotal' => 5000, 'total' => 5000, 'ordered_at' => '2025-09-15 10:00:00',
        ]);
    }

    private function trx(float $amount, string $when, ?Business $shop = null): void
    {
        $shop ??= $this->shop;
        Transaction::create([
            'business_id' => $shop->id, 'reference' => Transaction::nextReference($shop->id),
            'description' => 'حركة', 'method' => 'نقدي', 'type' => 'دخل', 'kind' => 'income',
            'amount' => $amount, 'employee_name' => 'المالك', 'occurred_at' => $when,
        ]);
    }

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
        $path = tempnam(sys_get_temp_dir(), 'per').'.xlsx';
        file_put_contents($path, $this->body($res));
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false);
        @unlink($path);

        return $rows;
    }

    /* ═══════════ ١ · شهرٌ تاريخيّ: سبتمبر ٢٠٢٥ ═══════════ */

    public function test_september_2025_is_read_on_the_report_and_carried_by_its_three_files(): void
    {
        $this->septemberEdges();
        $sep = ['period' => 'month', 'month' => '2025-09'];

        $screen = $this->props('admin.reports.orders', $sep);
        $this->assertSame(2, $screen['summary']['count'], 'حدّا سبتمبر لم يُقرآ عند منتصف الليل');
        $this->assertEquals(30.0, $screen['summary']['total']);
        $this->assertNull($screen['range'], 'أُضيء زرُّ «الشهر» وهو سبتمبر الماضي');
        $this->assertSame('سبتمبر 2025', $screen['period']['label']);
        $this->assertSame($sep, $screen['period']['params']);
        $this->assertSame($sep + ['status' => null, 'branch_id' => null, 'payment_method' => null, 'fulfillment' => null], $screen['filters']);

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'orders'] + $sep));
        $text = $this->body($csv);
        $this->assertStringContainsString('سبتمبر 2025', $text);
        $this->assertStringNotContainsString('5000', $text, 'دخل طلبُ الجار ملفَّ سبتمبر');
        $this->assertSame('report-orders-2025-09.csv', $this->filename($csv));

        $xlsx = $this->get(route('admin.reports.export.xlsx', ['report' => 'orders'] + $sep));
        $this->assertSame('report-orders-2025-09.xlsx', $this->filename($xlsx));
        $flat = json_encode($this->sheet($xlsx), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('سبتمبر 2025', $flat);
        $this->assertStringContainsString('P-2', $flat);
        $this->assertStringContainsString('P-3', $flat);
        $this->assertStringNotContainsString('P-1"', $flat, 'دخل طلبُ ٣١ أغسطس');
        $this->assertStringNotContainsString('P-4', $flat, 'دخل طلبُ أوّل أكتوبر');
    }

    /* ═══════════ ٢ · الشهر السابق عبر رأس السنة ═══════════ */

    public function test_the_previous_month_in_january_is_last_december(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $this->order('2025-11-30 10:00:00', 1);
        $this->order('2025-12-15 10:00:00', 7);
        $this->order('2026-01-02 10:00:00', 40);

        $screen = $this->props('admin.reports.orders', ['period' => 'previous_month']);

        $this->assertSame(1, $screen['summary']['count']);
        $this->assertEquals(7.0, $screen['summary']['total']);
        $this->assertSame('ديسمبر 2025', $screen['period']['label']);
    }

    /* ═══════════ ٣ · نطاقُ أشهرٍ عبر السنة — قائمةُ الحركة المالية ═══════════ */

    public function test_a_cross_year_month_range_is_the_same_on_the_transactions_screen_and_its_files(): void
    {
        $this->trx(1, '2024-10-31 23:59:59');
        $this->trx(2, '2024-11-01 00:00:00');
        $this->trx(3, '2025-01-15 12:00:00');
        $this->trx(4, '2025-02-28 23:59:59');
        $this->trx(5, '2025-03-01 00:00:00');
        $this->trx(900, '2025-01-15 12:00:00', $this->other);
        $range = ['period' => 'month_range', 'month_from' => '2024-11', 'month_to' => '2025-02'];

        $screen = $this->props('admin.finance.transactions', $range + ['per_page' => 2, 'page' => 2]);
        $this->assertSame(3, $screen['pagination']['total']);
        $this->assertEquals(9.0, $screen['summary']['in']);
        $this->assertSame('نوفمبر 2024 – فبراير 2025', $screen['period']['label']);

        // والملفُّ يتجاهل الصفحة ويحمل كلَّ ما طابق — من الصفحة الثانية
        $csv = $this->get(route('admin.export.transactions', $range + ['page' => 2, 'per_page' => 2]));
        $lines = array_values(array_filter(explode("\n", trim($this->body($csv))), fn ($l) => str_contains($l, 'TRX-')));
        $this->assertCount(3, $lines);
        $this->assertSame('transactions-2024-11-to-2025-02.csv', $this->filename($csv));

        $xlsx = $this->get(route('admin.finance.xlsx', $range + ['page' => 2]));
        $this->assertSame('transactions-2024-11-to-2025-02.xlsx', $this->filename($xlsx));
        $rows = array_filter($this->sheet($xlsx), fn ($r) => is_string($r[0] ?? null) && str_starts_with($r[0], 'TRX-'));
        $this->assertCount(3, $rows, 'عددُ صفوف Excel غيرُ عددِ CSV والشاشة');
        $this->assertStringContainsString('نوفمبر 2024 – فبراير 2025', json_encode($this->sheet($this->get(route('admin.finance.xlsx', $range))), JSON_UNESCAPED_UNICODE));
    }

    /* ═══════════ ٤ · سنةٌ تاريخيّة: صافي الربح ٢٠٢٤ ═══════════ */

    public function test_2024_profit_reads_only_2024_and_compares_with_2023(): void
    {
        $sale = fn (float $v, string $on) => Ledger::post($this->shop->id, 'بيع', [
            ['account' => 'cash', 'debit' => $v], ['account' => 'sales', 'credit' => $v],
        ], Carbon::parse($on), 'يدوي');
        $rent = fn (float $v, string $on) => Ledger::post($this->shop->id, 'إيجار', [
            ['account' => 'rent', 'debit' => $v], ['account' => 'cash', 'credit' => $v],
        ], Carbon::parse($on), 'يدوي');

        $sale(300, '2023-06-01');
        $sale(1000, '2024-01-01');
        $sale(500, '2024-12-31');
        $sale(7000, '2025-01-01');
        $rent(200, '2024-05-05');
        $rent(50, '2023-05-05');
        Ledger::post($this->other->id, 'بيع الجار', [
            ['account' => 'cash', 'debit' => 9999], ['account' => 'sales', 'credit' => 9999],
        ], Carbon::parse('2024-06-01'), 'يدوي');

        $year = ['period' => 'year', 'year' => '2024'];
        $screen = $this->props('admin.reports.profit', $year);

        $this->assertEquals(1500.0, $screen['summary']['net_revenue']);
        $this->assertEquals(200.0, $screen['summary']['expenses']);
        $this->assertEquals(1300.0, $screen['summary']['net_profit']);
        $this->assertSame('2024', $screen['period']['label']);
        // والمقارنةُ بالسنة قبلها لا بما قبل اليوم
        $previous = collect($screen['comparison'])->keyBy('key');
        $this->assertEquals(300.0, $previous['net_revenue']['previous']);
        $this->assertEquals(250.0, $previous['net_profit']['previous']);
        // ومحورُ السنة أشهرُها الاثنا عشر — ومجموعُها جملتُها
        $this->assertCount(12, $screen['rows']);
        $this->assertEquals(1500.0, round(array_sum(array_column($screen['rows'], 'net_revenue')), 3));

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'profit'] + $year));
        $this->assertSame('report-profit-2024.csv', $this->filename($csv));
        $text = $this->body($csv);
        $this->assertStringContainsString('2024 — النشاط بالكامل', $text);
        $this->assertStringNotContainsString('9999', $text);
        $this->assertStringNotContainsString('7000', $text);
    }

    /* ═══════════ ٥ · فترةٌ مخصّصة عبر السنة — المصروفات ═══════════ */

    public function test_custom_dates_across_new_year_on_the_expenses_screen_and_its_file(): void
    {
        $expense = fn (float $v, string $on) => Expense::create([
            'business_id' => $this->shop->id, 'type' => 'تشغيلية', 'description' => 'مصروف '.$v,
            'amount' => $v, 'method' => 'نقدي', 'status' => Expense::PAID, 'employee_name' => 'المالك', 'spent_at' => $on,
        ]);
        $expense(1, '2025-12-27');
        $expense(2, '2025-12-28');
        $expense(4, '2026-01-03');
        $expense(8, '2026-01-04');
        $custom = ['period' => 'custom', 'from' => '2025-12-28', 'to' => '2026-01-03'];

        $screen = $this->props('admin.expenses.index', $custom);
        $this->assertSame(2, $screen['monthCount']);
        $this->assertEquals(6.0, $screen['monthTotal']);
        $this->assertSame('28 ديسمبر 2025 – 3 يناير 2026', $screen['period']['label']);

        $xlsx = $this->get(route('admin.expenses.xlsx', $custom));
        $this->assertSame('expenses-2025-12-28-to-2026-01-03.xlsx', $this->filename($xlsx));
        $flat = json_encode($this->sheet($xlsx), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مصروف 2', $flat);
        $this->assertStringContainsString('مصروف 4', $flat);
        $this->assertStringNotContainsString('مصروف 1"', $flat);
        $this->assertStringNotContainsString('مصروف 8', $flat);
    }

    /* ═══════════ ٦ · كل الفترات — والمرشّحاتُ الأخرى باقية ═══════════ */

    public function test_all_periods_removes_the_date_and_nothing_else(): void
    {
        $this->order('2019-03-01 10:00:00', 11, ['branch_id' => $this->muscat->id]);
        $this->order('2026-10-01 10:00:00', 13, ['branch_id' => $this->muscat->id]);
        $this->order('2026-10-02 10:00:00', 17, ['branch_id' => $this->sohar->id]);
        $this->order('2024-10-02 10:00:00', 19, ['branch_id' => $this->muscat->id, 'status' => Order::CANCELLED]);

        $query = ['period' => 'all', 'branch_id' => (string) $this->muscat->id, 'status' => 'مكتمل'];
        $screen = $this->props('admin.reports.orders', $query);
        $this->assertSame(2, $screen['summary']['count']);
        $this->assertEquals(24.0, $screen['summary']['total']);

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'orders'] + $query));
        $rows = array_values(array_filter(explode("\n", $this->body($csv)), fn ($l) => str_contains($l, ',P-')));
        // ٢٠١٩ وأكتوبر ٢٠٢٦ من مسقط مكتملين — لا صحار، ولا الملغى
        $this->assertCount(2, $rows, '«كل الفترات» أسقطت الفرعَ أو الحالة');
        $this->assertStringStartsWith('2026-10-01', $rows[0]);
        $this->assertStringStartsWith('2019-03-01', $rows[1]);
        $this->assertSame('report-orders-all.csv', $this->filename($csv));
    }

    /* ═══════════ ٧ · فترةٌ فارغة ═══════════ */

    public function test_an_empty_period_opens_and_exports_nothing(): void
    {
        $this->order('2026-10-01 10:00:00', 13);
        $empty = ['period' => 'year', 'year' => '2019'];

        $this->assertSame(0, $this->props('admin.reports.orders', $empty)['summary']['count']);
        $this->assertStringNotContainsString('P-', $this->body($this->get(route('admin.reports.export.csv', ['report' => 'orders'] + $empty))));
        $this->get(route('admin.reports.export.pdf', ['report' => 'orders'] + $empty))->assertOk();
    }

    /* ═══════════ ٨ · فرعٌ وفترة — قائمةُ الطلبات ═══════════ */

    public function test_a_branch_and_a_month_narrow_the_orders_list_and_its_file_together(): void
    {
        $this->order('2025-09-05 10:00:00', 10, ['branch_id' => $this->muscat->id]);
        $this->order('2025-09-06 10:00:00', 20, ['branch_id' => $this->sohar->id]);
        $this->order('2025-10-06 10:00:00', 40, ['branch_id' => $this->muscat->id]);
        $sep = ['period' => 'month', 'month' => '2025-09'];

        $screen = $this->withSession(['current_branch' => $this->muscat->id])->props('admin.orders.index', $sep);
        $this->assertSame(1, $screen['totalCount']);
        $this->assertSame('سبتمبر 2025', $screen['period']['label']);

        $xlsx = $this->withSession(['current_branch' => $this->muscat->id])->get(route('admin.orders.xlsx', $sep));
        $this->assertSame('orders-2025-09.xlsx', $this->filename($xlsx));
        $rows = array_filter($this->sheet($xlsx), fn ($r) => is_string($r[0] ?? null) && str_starts_with($r[0], 'P-'));
        $this->assertCount(1, $rows);
    }

    /* ═══════════ ٩ · المقارنةُ بشكلها — التكاليف والخسائر ═══════════ */

    public function test_a_whole_month_of_costs_is_compared_with_the_whole_month_before(): void
    {
        $rent = fn (float $v, string $on) => Ledger::post($this->shop->id, 'إيجار', [
            ['account' => 'rent', 'debit' => $v], ['account' => 'cash', 'credit' => $v],
        ], Carbon::parse($on), 'يدوي');
        $rent(5, '2025-08-01');
        $rent(7, '2025-08-31');
        $rent(30, '2025-09-15');

        $screen = $this->props('admin.reports.costs', ['period' => 'month', 'month' => '2025-09']);

        $this->assertSame(['2025-09-01', '2025-09-30'], [$screen['filters']['from'], $screen['filters']['to']]);
        $this->assertSame(['from' => '2025-08-01', 'to' => '2025-08-31'], array_intersect_key($screen['scope']['previous'], ['from' => 1, 'to' => 1]));
        $this->assertEquals(30.0, $screen['summary']['total']);
        // أوّلُ أغسطس داخلَ المقارنة — كان يسقط منها
        $this->assertEquals(12.0, $screen['previousSummary']['total']);
        $this->assertSame('سبتمبر 2025', $screen['period']['label']);

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'costs', 'period' => 'month', 'month' => '2025-09']));
        $this->assertStringContainsString('سبتمبر 2025 (2025-09-01 → 2025-09-30)', $this->body($csv));
    }

    /* ═══════════ ١٠ · ضريبة القيمة المضافة — ربعُ سنة ═══════════ */

    public function test_a_vat_quarter_reads_its_three_months_and_nothing_outside(): void
    {
        $supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'مورّد']);
        $invoice = fn (string $on, float $tax, string $approval) => SupplierInvoice::create([
            'business_id' => $this->shop->id, 'supplier_id' => $supplier->id, 'supplier_ref' => 'SI-'.$on.$tax,
            'issued_at' => $on, 'subtotal' => $tax * 20, 'tax' => $tax, 'total' => $tax * 21,
            'approval_status' => $approval, 'status' => 'غير مدفوعة',
        ]);
        $this->order('2024-12-31 23:59:59', 100, ['tax' => 5, 'subtotal' => 95]);
        $this->order('2025-01-01 00:00:00', 100, ['tax' => 5, 'subtotal' => 95]);
        $this->order('2025-03-31 23:00:00', 200, ['tax' => 10, 'subtotal' => 190]);
        $this->order('2025-04-01 00:00:00', 400, ['tax' => 20, 'subtotal' => 380]);
        $invoice('2024-12-31', 1, SupplierInvoices::APPROVED);
        $invoice('2025-02-10', 2, SupplierInvoices::APPROVED);
        $invoice('2025-03-31', 3, SupplierInvoices::PENDING);
        $invoice('2025-04-01', 4, SupplierInvoices::PENDING);

        $q1 = ['period' => 'month_range', 'month_from' => '2025-01', 'month_to' => '2025-03'];
        $screen = $this->props('admin.reports.vat', $q1);

        $this->assertEquals(15.0, $screen['summary']['output']);
        $this->assertEquals(2.0, $screen['summary']['input']);
        $this->assertSame(1, $screen['summary']['pending'], 'سندٌ معلَّقٌ خارج الربع دخل ملاحظتَه');
        $this->assertEquals(3.0, $screen['summary']['pendingTax']);
        $this->assertCount(3, $screen['rows'], 'دخل الإقرارَ شهرٌ خارج الربع');
        $this->assertSame('report-vat-2025-01-to-2025-03.csv', $this->filename($this->get(route('admin.reports.export.csv', ['report' => 'vat'] + $q1))));
    }

    /* ═══════════ ١١ · ملخّصُ المبيعات: الفترة والقناة معًا ═══════════ */

    public function test_the_sales_report_keeps_the_month_and_the_channel_on_screen_feed_and_files(): void
    {
        $this->order('2025-09-10 10:00:00', 40, ['channel' => SalesChannel::WEBSITE]);
        $this->order('2025-09-11 10:00:00', 60, ['channel' => SalesChannel::POS]);
        $this->order('2025-10-10 10:00:00', 900, ['channel' => SalesChannel::WEBSITE]);
        $query = ['period' => 'month', 'month' => '2025-09', 'channel' => SalesChannel::WEBSITE];

        $screen = $this->props('admin.reports.sales', $query);
        $this->assertEquals(40.0, $screen['summary']['sales']);
        $this->assertSame(SalesChannel::WEBSITE, $screen['channel']);
        $this->assertNull($screen['range']);
        $this->assertCount(30, $screen['salesSeries']['labels'], 'محورُ سبتمبر غيرُ أيّامه');

        $feed = $this->getJson(route('admin.reports.feed', $query))->assertOk()->json();
        $this->assertEquals(40.0, $feed['summary']['sales'], 'التغذيةُ قلبت سبتمبر إلى الشهر الجاري');

        $csv = $this->get(route('admin.export.reports', $query));
        $text = $this->body($csv);
        $this->assertStringContainsString('سبتمبر 2025', $text);
        $this->assertStringContainsString('2025-09', $this->filename($csv));

        $xlsx = $this->get(route('admin.reports.xlsx', $query));
        $this->assertSame('sales-report-2025-09.xlsx', $this->filename($xlsx));
        $this->assertStringContainsString('سبتمبر 2025', json_encode($this->sheet($xlsx), JSON_UNESCAPED_UNICODE));
    }

    /** وبلا قناة: الإيرادُ من الدفتر (`Ledger::netRevenue`) بحدَّي سبتمبر — لا حتى اليوم */
    public function test_the_whole_shop_sales_summary_reads_the_ledger_inside_the_month(): void
    {
        foreach (['2025-08-31' => 1, '2025-09-01' => 100, '2025-09-30' => 200, '2025-10-01' => 4000] as $on => $v) {
            Ledger::post($this->shop->id, 'بيع', [
                ['account' => 'cash', 'debit' => $v], ['account' => 'sales', 'credit' => $v],
            ], Carbon::parse($on), 'يدوي');
        }
        $rent = Ledger::post($this->shop->id, 'إيجار', [
            ['account' => 'rent', 'debit' => 50], ['account' => 'cash', 'credit' => 50],
        ], Carbon::parse('2025-10-02'), 'يدوي');

        $summary = $this->props('admin.reports.sales', ['period' => 'month', 'month' => '2025-09'])['summary'];

        $this->assertEquals(300.0, $summary['net_revenue']);
        $this->assertEquals(300.0, $summary['sales']);
        $this->assertEquals(0.0, $summary['expenses'], 'دخل إيجارُ أكتوبر مصروفاتِ سبتمبر');
        $this->assertSame('net', $summary['profit_kind']);
    }

    /* ═══════════ ١٢ · الصلاحيةُ والباقة كما كانتا ═══════════ */

    public function test_a_period_opens_no_door_that_was_closed(): void
    {
        $sep = ['period' => 'month', 'month' => '2025-09'];
        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => 'clerk@periods.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'reports'],
        ]);

        $this->actingAs($clerk)->get(route('admin.reports.profit', $sep))->assertOk();
        // «الحركة المالية» تحت «المالية» — والفترةُ لا تفتحها
        $this->actingAs($clerk)->get(route('admin.reports.finance', $sep))->assertForbidden();
        $this->actingAs($clerk)->get(route('admin.reports.export.csv', ['report' => 'finance'] + $sep))->assertForbidden();

        // والتصديرُ فوق الباقة الأساسيّة كما كان
        $plan = Plan::create(['name' => 'أساسية', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => []]);
        $this->shop->update(['plan_id' => $plan->id]);
        $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'orders'] + $sep))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.reports.orders', $sep))->assertOk();
    }

    /* ═══════════ ١٣ · الجارُ لا يُقرأ ولا يُختار ═══════════ */

    public function test_a_business_id_in_the_link_never_widens_a_period(): void
    {
        $this->septemberEdges();
        $sep = ['period' => 'month', 'month' => '2025-09', 'business_id' => $this->other->id];

        $this->assertSame(2, $this->props('admin.reports.orders', $sep)['summary']['count']);
        $this->assertStringNotContainsString('5000', $this->body($this->get(route('admin.reports.export.csv', ['report' => 'orders'] + $sep))));
    }

    /* ═══════════ ١٤ · والخطأُ يُقال — لا يُصدَّر الشهرُ الجاري بدله ═══════════ */

    public function test_a_wrong_period_is_refused_on_screen_and_in_the_file(): void
    {
        $this->order('2026-10-05 10:00:00', 13);
        $bad = ['period' => 'custom', 'from' => '2025-10-17', 'to' => '2025-09-10'];

        $this->get(route('admin.reports.orders', $bad))->assertStatus(422);
        $this->get(route('admin.reports.export.csv', ['report' => 'orders'] + $bad))->assertStatus(422);
        $this->get(route('admin.orders.xlsx', ['period' => 'month', 'month' => '2025-13']))->assertStatus(422);
        $this->get(route('admin.expenses.xlsx', ['month' => 'سبتمبر']))->assertStatus(422);
    }

    /* ═══════════ ١٥ · الروابطُ القديمة تفتح كما كانت ═══════════ */

    public function test_old_links_open_as_they_did(): void
    {
        $this->order('2026-10-05 10:00:00', 13);
        $this->order('2026-03-05 10:00:00', 17);

        $month = $this->props('admin.reports.orders', ['range' => 'month']);
        $this->assertSame('month', $month['range']);
        $this->assertSame(1, $month['summary']['count']);
        $this->assertSame(2, $this->props('admin.reports.orders', ['range' => 'year'])['summary']['count']);

        // من/إلى في الطلبات كما كانتا
        $this->assertSame(1, $this->props('admin.orders.index', ['from' => '2026-03-01', 'to' => '2026-03-31'])['totalCount']);
        // و`range` في الحركة، وشهرُ المصروفات
        $this->trx(5, '2026-10-02 10:00:00');
        $this->trx(6, '2025-10-02 10:00:00');
        $this->assertSame(1, $this->props('admin.finance.transactions', ['range' => 'month'])['pagination']['total']);
        $this->assertSame('expenses-2026-09.xlsx', $this->filename($this->get(route('admin.expenses.xlsx', ['month' => '2026-09']))));
        $this->assertSame('expenses-all.xlsx', $this->filename($this->get(route('admin.expenses.xlsx', ['month' => 'all']))));
    }

    /* ═══════════ ١٦ · الرصيدُ الحالي بلا فترة ═══════════ */

    public function test_the_current_stock_report_has_no_period_to_pretend_with(): void
    {
        $inventory = $this->props('admin.reports.inventory', ['period' => 'month', 'month' => '2025-09']);

        $this->assertNull($inventory['period'], 'رُسم منتقي فترةٍ فوق رصيد اليوم');
        $this->assertStringContainsString('الرصيد الحالي', $this->body($this->get(route('admin.reports.export.csv', ['report' => 'inventory', 'period' => 'month', 'month' => '2025-09']))));
    }
}
