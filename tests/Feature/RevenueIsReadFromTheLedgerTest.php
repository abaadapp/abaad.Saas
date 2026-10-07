<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Profitability;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * صافي الإيرادات من الدفتر — رقمٌ واحد في اللوحة والمالية والتقارير.
 *
 * كان الربحُ يقرأ الطلبات (`orders.total − tax`) أو حركاتِ الدخل، وفاتورةُ
 * العميل اليدويّة لا طلبَ لها ولا حركة: تُرحَّل إلى «إيراد المبيعات» فتعدّها
 * الميزانيّة ويُسقطها صافي الربح. فصار يقرأ `Ledger::netRevenue` — كلَّ
 * حسابٍ نوعُه «إيراد»، دائنٌ ناقصَ مدين، وكلَّ بيعةٍ مرّةً بقيدها.
 */
class RevenueIsReadFromTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private Customer $ministry;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->business('متجري', 'o@abaad.om');
        [$this->other] = $this->business('الجار', 'x@other.om');

        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'customer_type' => 'جهة حكومية']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $a = Branch::create(['business_id' => $shop->id, 'name' => 'مسقط']);
        $b = Branch::create(['business_id' => $shop->id, 'name' => 'صحار']);
        Ledger::seedChart($shop->id);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner, $a, $b];
    }

    /** بيعةٌ كما تكتبها نقطةُ البيع أو الموقع — ثمّ قيدُها من `Books::recordSale` */
    private function sale(float $net, string $at, float $tax = 0, string $channel = SalesChannel::POS, float $cost = 0, ?Branch $branch = null, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;
        $product = Product::create(['business_id' => $shop->id, 'name' => 'باقة', 'price' => $net, 'cost' => $cost, 'quantity' => 50]);
        $order = Order::create([
            'business_id' => $shop->id, 'branch_id' => ($branch ?? $this->muscat)->id, 'channel' => $channel,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $net, 'discount' => 0, 'tax' => $tax, 'total' => $net + $tax,
            'ordered_at' => Carbon::parse($at),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة',
            'price' => $net, 'quantity' => 1, 'cost' => $cost, 'total' => $net]);
        Books::recordSale($order);

        return $order;
    }

    /** فاتورةُ عميلٍ يدويّة — مسودّةٌ ثمّ إصدار، وضريبتُها ٥٪ فوق السعر */
    private function invoice(float $price, ?Branch $branch = null, bool $issue = true): CustomerInvoice
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['branch_id' => $branch?->id], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 5],
        ], $this->owner->id);

        return $issue ? CustomerInvoices::issue($draft, $this->owner->id) : $draft;
    }

    private function revenue(?string $from = '2026-09-01', ?string $to = '2026-10-01', ?Branch $branch = null): float
    {
        return Ledger::netRevenue($this->shop->id, $from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null, $branch?->id);
    }

    /* ═══════════ كلُّ بيعةٍ مرّةً واحدة ═══════════ */

    public function test_a_pos_sale_counts_once_without_its_tax(): void
    {
        $order = $this->sale(100, '2026-09-10 14:00', tax: 5);
        // نداءٌ ثانٍ — أمرُ الاستدراك أو محاولةٌ مُعادة — لا يُضاعف
        Books::recordSale($order);

        $this->assertSame(100.0, $this->revenue());
        $this->assertSame(5.0, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));
    }

    public function test_a_website_sale_counts_once(): void
    {
        $order = $this->sale(80, '2026-09-11 09:00', tax: 4, channel: SalesChannel::WEBSITE);
        Books::recordSale($order);

        $this->assertSame(80.0, $this->revenue());
        $this->assertSame(80.0, (float) Ledger::revenueLines($this->shop->id)
            ->where('accounts.system_key', 'sales_website')->sum('journal_lines.credit'), 'بيعةُ الموقع لم تُقيَّد في ورقتها');
    }

    public function test_an_issued_manual_invoice_is_revenue_and_a_draft_is_not(): void
    {
        $this->invoice(200, issue: false);
        $this->assertSame(0.0, $this->revenue(), 'المسودّةُ دخلت الإيراد');

        $issued = $this->invoice(200);
        $this->assertSame(5.0 / 100 * 200, (float) $issued->tax_total);

        $this->assertSame(200.0, $this->revenue());
        $this->assertSame(10.0, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));
    }

    public function test_an_invoice_made_from_an_order_does_not_count_twice(): void
    {
        $order = $this->sale(150, '2026-09-12 12:00', tax: 7.5);
        $order->update(['customer_id' => $this->ministry->id]);
        $invoice = CustomerInvoices::fromOrder($order, [], $this->owner->id);
        if ($invoice->status !== CustomerInvoice::ISSUED) {
            CustomerInvoices::issue($invoice, $this->owner->id);
        }

        $this->assertTrue($invoice->fresh()->coversOrders());
        $this->assertSame(150.0, $this->revenue());
        $this->assertSame(7.5, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));
    }

    /* ═══════════ ما يُنقصه ═══════════ */

    public function test_a_credit_note_reduces_revenue_and_its_tax(): void
    {
        $invoice = $this->invoice(200);
        // ٥٢٫٥ منها ٢٫٥ ضريبة — صافيه خمسون في «مردودات المبيعات»
        CustomerInvoices::creditNote($invoice, 52.5, 2.5, 'مردود', $this->owner->id);

        $this->assertSame(150.0, $this->revenue());
        $this->assertSame(7.5, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));
    }

    public function test_cancelling_an_invoice_or_a_sale_reverses_its_revenue(): void
    {
        $invoice = $this->invoice(200);
        $order = $this->sale(100, '2026-09-13 10:00', tax: 5);

        CustomerInvoices::cancel($invoice, 'خطأ', $this->owner->id);
        Books::unpostSale($order, $this->owner->id, 'إلغاء');

        $this->assertSame(0.0, $this->revenue());
        $this->assertSame(0.0, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));
    }

    public function test_a_cancellation_in_a_later_month_reduces_that_month_not_the_one_sold_in(): void
    {
        $order = $this->sale(100, '2026-09-13 10:00');

        Carbon::setTestNow('2026-10-02 09:00:00');
        Books::unpostSale($order, $this->owner->id, 'إلغاء');

        $this->assertSame(100.0, $this->revenue('2026-09-01', '2026-10-01'));
        $this->assertSame(-100.0, $this->revenue('2026-10-01', '2026-11-01'));
        $this->assertSame(0.0, $this->revenue(null, null));
    }

    public function test_tax_is_not_revenue_and_paying_it_does_not_move_sales_tax(): void
    {
        $this->sale(1000, '2026-09-05 11:00', tax: 50);
        // سدادُ الضريبة للجهاز يُنقص «ضريبة مستحقّة» ولا يمسّ ما بيع
        Ledger::post($this->shop->id, 'سداد ضريبة', [
            ['account' => 'tax_payable', 'debit' => 50], ['account' => 'bank', 'credit' => 50],
        ], Carbon::parse('2026-09-18'), 'ضريبة');

        $this->assertSame(1000.0, $this->revenue());
        $this->assertSame(50.0, Ledger::outputTax($this->shop->id, Carbon::parse('2026-09-01')));

        $this->actingAs($this->owner);
        $report = Demo::reportSummary('month');
        $this->assertSame(1000.0, $report['net_revenue']);
        $this->assertSame(1050.0, (float) $report['sales']);
        $this->assertSame(50.0, (float) $report['tax']);
    }

    public function test_other_income_counts_and_an_unposted_draft_does_not(): void
    {
        Ledger::post($this->shop->id, 'تعويض', [
            ['account' => 'cash', 'debit' => 30], ['account' => 'other_income', 'credit' => 30],
        ], Carbon::parse('2026-09-08'), 'يدوي');

        $draft = Ledger::post($this->shop->id, 'مسودّة', [
            ['account' => 'cash', 'debit' => 999], ['account' => 'sales', 'credit' => 999],
        ], Carbon::parse('2026-09-08'), 'يدوي');
        $draft->update(['posted' => false]);

        $this->assertSame(30.0, $this->revenue());
    }

    /* ═══════════ الفترةُ والفرعُ والنشاط ═══════════ */

    public function test_the_period_branch_and_business_are_respected(): void
    {
        $this->sale(100, '2026-09-10 10:00', branch: $this->muscat);
        $this->sale(40, '2026-09-10 10:00', branch: $this->sohar);
        $this->invoice(200, $this->sohar);
        $this->sale(999, '2026-08-31 23:00');
        $this->sale(777, '2026-09-10 10:00', shop: $this->other);

        $this->assertSame(340.0, $this->revenue());
        $this->assertSame(100.0, $this->revenue(branch: $this->muscat));
        $this->assertSame(240.0, $this->revenue(branch: $this->sohar));
        $this->assertSame(999.0, $this->revenue('2026-08-01', '2026-09-01'));

        $this->actingAs($this->owner);
        $this->assertSame(240.0, Demo::reportSummary('month', null, $this->sohar->id)['net_revenue']);
        $this->assertSame(240.0, Profitability::summary($this->shop->id, Demo::rangeStart('month'), null, $this->sohar->id)['net_revenue']);
    }

    public function test_a_channel_report_stays_on_its_orders(): void
    {
        $this->sale(80, '2026-09-11 09:00', tax: 4, channel: SalesChannel::WEBSITE);
        $this->invoice(200);

        $this->actingAs($this->owner);
        $web = Demo::reportSummary('month', SalesChannel::WEBSITE);

        // القناةُ من `orders.channel` — الفاتورةُ اليدويّة ليست بيعةَ موقع
        $this->assertSame(80.0, $web['net_revenue']);
        $this->assertSame('gross', $web['profit_kind']);
    }

    /* ═══════════ رقمٌ واحد في كلّ شاشة ═══════════ */

    public function test_net_profit_matches_on_the_dashboard_finance_and_reports(): void
    {
        $this->sale(1000, '2026-09-10 14:00', tax: 50, cost: 400);
        $this->sale(300, '2026-09-11 20:00', tax: 15, channel: SalesChannel::WEBSITE, cost: 100);
        $invoice = $this->invoice(200);
        CustomerInvoices::creditNote($invoice, 52.5, 2.5, 'مردود', $this->owner->id);
        Ledger::post($this->shop->id, 'إيجار', [
            ['account' => 'rent', 'debit' => 250], ['account' => 'cash', 'credit' => 250],
        ], Carbon::parse('2026-09-05'), Books::EXPENSE);
        // وما خارج الشهر لا يدخل
        $this->sale(5000, '2026-08-15 10:00');

        // ١٠٠٠ + ٣٠٠ + ٢٠٠ − ٥٠ = ١٤٥٠؛ − تكلفة ٥٠٠ − مصروف ٢٥٠ = ٧٠٠
        $revenue = 1450.0;
        $profit = 700.0;

        $this->actingAs($this->owner);
        $this->assertSame($revenue, Ledger::netRevenue($this->shop->id, now()->startOfMonth()));

        // التقارير
        $report = Demo::reportSummary('month');
        $this->assertSame($revenue, $report['net_revenue']);
        $this->assertSame($profit, (float) $report['profit']);

        // صافي الربح وصفوفُه
        $summary = Profitability::summary($this->shop->id, Demo::rangeStart('month'));
        $this->assertSame($revenue, $summary['net_revenue']);
        $this->assertSame($profit, $summary['net_profit']);
        $rows = Profitability::rows($this->shop->id, 'month');
        $this->assertSame($revenue, round(array_sum(array_column($rows, 'net_revenue')), 3));
        $this->assertSame($profit, round(array_sum(array_column($rows, 'net_profit')), 3));

        $page = $this->get(route('admin.reports.profit', ['range' => 'month']))->assertOk()->viewData('page')['props'];
        $this->assertSame($profit, (float) $page['summary']['net_profit']);

        // المالية
        $period = $this->get(route('admin.finance.summary', ['range' => 'month']))->assertOk()
            ->viewData('page')['props']['period'];
        $this->assertSame($profit, (float) $period['profit']);
        $this->assertSame(round($revenue - 500, 3), (float) $period['gross_profit']);

        $finance = collect(Demo::financeStats('month'));
        $this->assertSame(Demo::money($revenue), $finance->firstWhere('label', 'صافي الإيرادات (بلا ضريبة)')['value']);

        $this->assertSame($profit, Demo::profitStats('month')['net_profit']);

        // اللوحة
        $stats = collect(Demo::adminStats());
        $this->assertSame(Demo::money($profit), $stats->firstWhere('label', 'صافي الأرباح')['value']);
    }

    public function test_today_rows_place_a_sale_in_its_hour_and_an_invoice_at_the_start_of_the_day(): void
    {
        Carbon::setTestNow('2026-09-20 18:00:00');
        $this->sale(100, '2026-09-20 14:30', tax: 5);
        $this->invoice(200);

        $rows = collect(Profitability::rows($this->shop->id, 'today'))->keyBy('key');

        $this->assertSame(100.0, $rows['14']['net_revenue']);
        $this->assertSame(105.0, $rows['14']['sales']);
        $this->assertSame(200.0, $rows['00']['net_revenue']);
        $this->assertSame(300.0, round($rows->sum('net_revenue'), 3));
    }

    public function test_the_daily_summary_net_is_net_profit(): void
    {
        $this->sale(1000, '2026-09-20 09:00', tax: 50, cost: 400);
        $this->invoice(200);
        Ledger::post($this->shop->id, 'إيجار', [
            ['account' => 'rent', 'debit' => 100], ['account' => 'cash', 'credit' => 100],
        ], Carbon::parse('2026-09-20'), Books::EXPENSE);

        $day = Demo::dailySummaryFor($this->shop->id, Carbon::parse('2026-09-20'));

        // ١٠٠٠ + ٢٠٠ − ٤٠٠ − ١٠٠ — لا «١٠٥٠ − ١٠٠»
        $this->assertSame(700.0, $day['net']);
    }
}
