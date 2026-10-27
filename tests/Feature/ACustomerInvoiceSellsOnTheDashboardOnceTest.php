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
use App\Support\AlertMetrics;
use App\Support\Books;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DashboardMetrics;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\StandaloneInvoiceSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فاتورةُ العميل المستقلّة بيعٌ في اللوحة — مرّةً واحدة، يومَ صدورها.
 *
 * ═══ العطب ═══
 *
 * «مبيعات اليوم» و«مبيعات الشهر» ومخطّطُ السنة وتوزيعُ الدفع كانت تجمع
 * `orders` وحدها. ففاتورةٌ تصدر من «فواتير العملاء» وتُسدَّد وتصل لوحةَ
 * التجهيز لا تظهر في «مبيعات اليوم» — و«صافي الأرباح» تحتها يعدّها من الدفتر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بيعةُ الصندوق مرّة، والفاتورةُ المستقلّة مرّة، والمولودةُ من طلبٍ لا تُضاعفه.
 *   - يومُ البيع يومُ الصدور: السدادُ — جزئيًّا أو كاملًا، اليومَ أو غدًا —
 *     والتجهيزُ وانتقالاتُه لا تحرّك المبيعات.
 *   - الإلغاءُ وإشعارُ الدائن يُنقصان في يومهما كما في الدفتر.
 *   - متجرٌ لا يرى فاتورةَ متجر، وفرعٌ لا يرى فاتورةَ فرع، والفاتورةُ بلا فرعٍ
 *     في «كل الفروع» وحده — كـ«صافي الأرباح».
 *   - الأرقامُ تساوي الدفترَ وتقريرَ المبيعات، والنبضةُ تقول ما تقوله الصفحة.
 */
class ACustomerInvoiceSellsOnTheDashboardOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->business('ورود', 'owner@sells-once.om');
        [$this->other] = $this->business('الجار', 'owner@next-door.om');
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم الحارثي', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

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

    /** بيعةٌ كما يكتبها الصندوق — ثمّ قيدُها من `Books::recordSale` */
    private function sale(float $net, string $at, float $tax = 0, ?Branch $branch = null, float $cost = 0, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;
        $product = Product::create(['business_id' => $shop->id, 'name' => 'باقة', 'price' => $net, 'cost' => $cost, 'quantity' => 50]);
        $order = Order::create([
            'business_id' => $shop->id, 'branch_id' => ($branch ?? $this->muscat)->id, 'channel' => 'pos',
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $net, 'discount' => 0, 'tax' => $tax, 'total' => $net + $tax,
            'ordered_at' => Carbon::parse($at),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة',
            'price' => $net, 'quantity' => 1, 'cost' => $cost, 'total' => $net]);
        Books::recordSale($order);

        return $order;
    }

    /** فاتورةٌ يدويّة بلا طلب — وضريبتُها ٥٪ فوق السعر: مئةٌ تصير ١٠٥ */
    private function invoice(float $price, string $issuedAt = '2026-10-20', ?Branch $branch = null, bool $issue = true, ?Business $shop = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $customer = $shop->is($this->shop)
            ? $this->customer
            : Customer::firstOrCreate(['business_id' => $shop->id, 'name' => 'زبون الجار'], ['allow_credit_sales' => true]);

        $draft = CustomerInvoices::create($shop->id, $customer, ['issued_at' => $issuedAt, 'branch_id' => $branch?->id], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 5],
        ], $this->owner->id);

        return $issue ? CustomerInvoices::issue($draft, $this->owner->id) : $draft;
    }

    private function pay(CustomerInvoice $invoice, float $amount, string $on, string $method = 'نقدي'): void
    {
        CustomerPayments::record($this->shop->id, $this->customer, $amount, ['method' => $method, 'occurred_at' => $on], [$invoice->id => $amount], $this->owner->id);
    }

    private function card(string $label): string
    {
        return collect(Demo::adminStats())->firstWhere('label', $label)['value'];
    }

    private function assertCard(float $amount, string $label, string $why = ''): void
    {
        $this->assertSame(Demo::money($amount), $this->card($label), $why ?: $label);
    }

    /** المبيعاتُ الشاملةُ للضريبة في الدفتر — صافي الإيرادات وضريبتُه */
    private function ledgerGross(string $from, string $to, ?Branch $branch = null): float
    {
        return round(Ledger::netRevenue($this->shop->id, Carbon::parse($from), Carbon::parse($to), $branch?->id)
            + Ledger::outputTax($this->shop->id, Carbon::parse($from), Carbon::parse($to), $branch?->id), 3);
    }

    /* ═══════════ كلُّ بيعةٍ مرّةً واحدة ═══════════ */

    public function test_a_pos_sale_shows_once(): void
    {
        $this->sale(100, '2026-10-20 09:00', tax: 5);

        $this->assertCard(105, 'مبيعات اليوم');
        $this->assertCard(105, 'مبيعات الشهر');
        $this->assertSame('1', $this->card('عدد الطلبات'));
        $this->assertCard(105, 'متوسط قيمة الطلب');
    }

    public function test_a_standalone_invoice_issued_today_is_todays_sale_once(): void
    {
        $draft = $this->invoice(100, issue: false);
        $this->assertCard(0, 'مبيعات اليوم', 'المسودّةُ دخلت المبيعات');

        CustomerInvoices::issue($draft, $this->owner->id);
        // وإصدارٌ ثانٍ — ضغطتان على الزرّ — لا يكتب قيدًا ثانيًا
        CustomerInvoices::issue($draft->fresh(), $this->owner->id);

        $this->assertCard(105, 'مبيعات اليوم', 'الفاتورةُ المستقلّة غابت عن «مبيعات اليوم»');
        $this->assertCard(105, 'مبيعات الشهر');

        // ليست طلبًا: لا تُعدّ في الطلبات ولا تنفخ متوسّطَها
        $this->assertSame('0', $this->card('عدد الطلبات'));
        $this->assertCard(0, 'متوسط قيمة الطلب');

        $snapshot = DashboardMetrics::snapshot();
        $this->assertEquals(105, $snapshot['salesSeries']['data'][9], 'عمودُ أكتوبر لم يعدّ الفاتورة');
        $this->assertSame(0, $snapshot['salesSeries']['counts'][9], 'الفاتورةُ عُدّت طلبًا في المخطّط');
        $this->assertSame([__('فواتير العملاء')], $snapshot['paymentDistribution']['labels']);
        $this->assertSame([105.0], $snapshot['paymentDistribution']['series']);
    }

    public function test_an_invoice_made_from_an_order_does_not_double_it(): void
    {
        $order = $this->sale(150, '2026-10-20 09:00', tax: 7.5);
        $order->update(['customer_id' => $this->customer->id]);
        $invoice = CustomerInvoices::fromOrder($order, [], $this->owner->id);
        if ($invoice->status !== CustomerInvoice::ISSUED) {
            CustomerInvoices::issue($invoice, $this->owner->id);
        }
        $this->assertTrue($invoice->fresh()->coversOrders());

        $this->assertCard(157.5, 'مبيعات اليوم', 'فاتورةُ الطلب عُدّت فوق طلبها');
        $this->assertCard(157.5, 'مبيعات الشهر');
        $this->assertSame(0.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')));
        $this->assertNotContains(__('فواتير العملاء'), DashboardMetrics::paymentDistribution()['labels']);

        // وإشعارُ دائنٍ يدويٌّ عليها يُنقص اليومَ مرّةً — كما يُنقص الدفتر
        CustomerInvoices::creditNote($invoice->fresh(), 21, 1, 'مردود', $this->owner->id);
        $this->assertCard(136.5, 'مبيعات اليوم');
        $this->assertSame(136.5, $this->ledgerGross('2026-10-20', '2026-10-21'));
    }

    /* ═══════════ يومُ البيع يومُ الصدور ═══════════ */

    public function test_issued_yesterday_and_paid_today_stays_yesterdays_sale(): void
    {
        $invoice = $this->invoice(100, '2026-10-19');
        $this->pay($invoice, 105, '2026-10-20');

        $this->assertCard(0, 'مبيعات اليوم', 'سدادُ اليوم صار بيعَ اليوم');
        $this->assertSame(105.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-20')));
        $this->assertCard(105, 'مبيعات الشهر');
    }

    public function test_issued_today_and_paid_tomorrow_is_counted_once(): void
    {
        $invoice = $this->invoice(100);
        $this->assertCard(105, 'مبيعات اليوم');

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->pay($invoice, 105, '2026-10-21', 'Paymob');

        $this->assertCard(0, 'مبيعات اليوم', 'السدادُ غدًا كرّر البيع');
        $this->assertCard(105, 'مبيعات الشهر', 'الشهرُ عدّ الفاتورةَ مرّتين');
        $this->assertSame(105.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-20'), Carbon::parse('2026-10-21')));
        $this->assertEquals(105, DashboardMetrics::salesYear()['data'][9]);
    }

    public function test_a_partial_then_full_payment_adds_no_sale(): void
    {
        $invoice = $this->invoice(100);
        // والسدادُ يُقال في «مقبوضات اليوم» و«مستحقات العملاء» وحدهما — والبيعُ لا يمسّه
        $sales = function () {
            $snapshot = DashboardMetrics::snapshot();
            $snapshot['stats'] = array_values(array_filter($snapshot['stats'],
                fn ($c) => ! in_array($c['label'], ['مقبوضات اليوم', 'مستحقات العملاء'], true)));

            return $snapshot;
        };
        $before = $sales();

        $this->pay($invoice, 40, '2026-10-20');
        $this->assertEquals($before, $sales(), 'الدفعةُ الجزئيّة غيّرت اللوحة');
        $this->assertCard(40, 'مقبوضات اليوم');
        $this->assertCard(65, 'مستحقات العملاء');

        $this->pay($invoice, 65, '2026-10-20', 'تحويل بنكي');
        $this->assertSame(0.0, (float) $invoice->fresh()->outstanding());
        $this->assertEquals($before, $sales(), 'السدادُ الكامل غيّر اللوحة');
        $this->assertCard(105, 'مقبوضات اليوم');
        $this->assertCard(0, 'مستحقات العملاء');

        // ولا وسيلةَ تُخمَّن للفاتورة من تحصيلها
        $this->assertSame([__('فواتير العملاء')], $before['paymentDistribution']['labels']);
    }

    public function test_the_preparation_board_and_its_moves_do_not_change_sales(): void
    {
        $invoice = $this->invoice(100);
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'delivery', 'branch_id' => $this->muscat->id,
            'scheduled_for' => '2026-10-20 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير، شارع ٤٥',
        ], $this->owner->id);
        $this->pay($invoice, 105, '2026-10-20');
        $before = DashboardMetrics::snapshot();

        $this->assertNull(CustomerInvoicePreparations::activateByHand($invoice->fresh(), $this->owner->id));
        $prep = $invoice->fresh()->preparation;
        $this->assertTrue($prep->isActive(), 'المهمّةُ لم تصل لوحةَ التجهيز');
        $this->assertEquals($before, DashboardMetrics::snapshot(), 'الوصولُ إلى التجهيز غيّر المبيعات');

        // جاهزٌ ثمّ في الطريق ثمّ تمّ التسليم — والمبيعاتُ هي هي في كلّ خطوة
        $seen = [];
        while (! in_array($prep->status, CustomerInvoicePreparations::CLOSED, true) && count($seen) < 6) {
            $next = CustomerInvoicePreparations::next($prep);
            $to = collect([OrderStatus::READY, OrderStatus::DELIVERED, OrderStatus::OUT_FOR_DELIVERY])
                ->first(fn ($s) => in_array($s, $next, true)) ?? $next[0];
            $this->assertNull(CustomerInvoicePreparations::move($this->shop->id, $prep->id, null, $to));
            $prep = $prep->fresh();
            $seen[] = $prep->status;
            $this->assertEquals($before, DashboardMetrics::snapshot(), 'الانتقالُ إلى «'.$to.'» غيّر المبيعات');
        }

        $this->assertContains(OrderStatus::READY, $seen);
        $this->assertSame(OrderStatus::DELIVERED, $prep->status);
        $this->assertCard(105, 'مبيعات اليوم');
    }

    /* ═══════════ الإلغاءُ وإشعارُ الدائن ═══════════ */

    public function test_a_cancellation_and_a_credit_note_reverse_in_their_own_day(): void
    {
        // تصدر وتُلغى في يومها: لا شيء
        CustomerInvoices::cancel($this->invoice(100), 'خطأ', $this->owner->id);
        $this->assertCard(0, 'مبيعات اليوم', 'الفاتورةُ الملغاة بقيت بيعًا');

        // إشعارُ دائنٍ ٥٢٫٥ (منها ٢٫٥ ضريبة) يُنقص يومَه بقيمته الشاملة
        $kept = $this->invoice(100);
        CustomerInvoices::creditNote($kept, 52.5, 2.5, 'مردود', $this->owner->id);
        $this->assertCard(52.5, 'مبيعات اليوم');

        // وفاتورةُ أمسِ تُلغى اليوم: أمسُ يبقى كما بِيع، واليومُ يحمل العكس — كالدفتر
        $yesterday = $this->invoice(200, '2026-10-19');
        CustomerInvoices::cancel($yesterday, 'خطأ', $this->owner->id);

        $this->assertSame(210.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-20')));
        $this->assertCard(52.5 - 210, 'مبيعات اليوم');
        $this->assertCard(52.5, 'مبيعات الشهر');
        $this->assertSame(52.5, $this->ledgerGross('2026-10-01', '2026-11-01'));
    }

    /* ═══════════ المتجرُ والفرع ═══════════ */

    public function test_shops_and_branches_are_kept_apart(): void
    {
        $this->invoice(100, branch: $this->muscat);
        $this->invoice(200, branch: $this->sohar);
        $this->invoice(300);
        $this->sale(50, '2026-10-20 09:00', branch: $this->muscat);
        $this->invoice(999, shop: $this->other);

        $this->assertCard(105 + 210 + 315 + 50, 'مبيعات اليوم', 'متجرٌ آخر أو فرعٌ ضاع من «كل الفروع»');

        session(['current_branch' => $this->muscat->id]);
        $this->assertCard(105 + 50, 'مبيعات اليوم', 'فرعُ مسقط قرأ فاتورةَ غيره');
        $split = DashboardMetrics::paymentDistribution();
        $this->assertSame([__('فواتير العملاء'), Demo::methodLabel('نقدي')], $split['labels']);
        $this->assertSame([105.0, 50.0], $split['series']);

        session(['current_branch' => $this->sohar->id]);
        $this->assertCard(210, 'مبيعات اليوم', 'فرعُ صحار قرأ فاتورةَ غيره');
        $this->assertEquals(210, DashboardMetrics::salesYear()['data'][9]);

        // والسياسةُ سياسةُ «صافي الأرباح» نفسِها: فرعُ القيد، وما بلا فرعٍ للمتجر كلِّه
        $this->assertSame(200.0, Ledger::netRevenue($this->shop->id, Carbon::parse('2026-10-01'), null, $this->sohar->id));
    }

    /* ═══════════ رقمٌ واحدٌ مع الدفتر والتقارير ═══════════ */

    public function test_sales_and_net_profit_agree_with_the_ledger_and_the_report(): void
    {
        $this->sale(1000, '2026-10-20 09:00', tax: 50, cost: 400);
        $this->invoice(200);
        $order = $this->sale(150, '2026-10-20 11:00', tax: 7.5);
        $order->update(['customer_id' => $this->customer->id]);
        $fromOrder = CustomerInvoices::fromOrder($order, [], $this->owner->id);
        if ($fromOrder->status !== CustomerInvoice::ISSUED) {
            CustomerInvoices::issue($fromOrder, $this->owner->id);
        }

        // ١٠٥٠ + ٢١٠ + ١٥٧٫٥ — والفاتورةُ من الطلب لا تُعدّ ثانيةً
        $gross = 1417.5;
        $this->assertCard($gross, 'مبيعات اليوم');
        $this->assertCard($gross, 'مبيعات الشهر');
        $this->assertSame($gross, $this->ledgerGross('2026-10-01', '2026-11-01'), 'اللوحةُ والدفترُ افترقا');
        $this->assertSame($gross, (float) Demo::reportSummary('month')['sales'], 'اللوحةُ وتقريرُ المبيعات افترقا');

        // صافي الأرباح: ١٠٠٠ + ٢٠٠ + ١٥٠ صافيًا بلا ضريبة − تكلفة ٤٠٠ — كلُّ إيرادٍ مرّة
        $this->assertCard(950, 'صافي الأرباح');

        // ومجموعُ أشرطة الدفع بطاقةُ الشهر
        $this->assertSame($gross, round(array_sum(DashboardMetrics::paymentDistribution()['series']), 3));
    }

    public function test_the_month_holds_its_own_invoices_only(): void
    {
        $this->invoice(100, '2026-09-30');
        $this->invoice(40, '2026-10-01');
        // تاريخٌ في الشهر القادم ليس من مبيعات هذا الشهر
        $this->invoice(500, '2026-11-01');

        $this->assertCard(42, 'مبيعات الشهر');
        $stats = collect(Demo::adminStats())->firstWhere('label', 'مبيعات الشهر');
        // والاتجاهُ يقيس الشهرَ الماضي بالمقياس نفسه: ٤٢ مقابل ١٠٥
        $this->assertSame('−60%', $stats['trend']);
        $this->assertEquals([105, 42], array_slice(DashboardMetrics::salesYear()['data'], 8, 2));
    }

    /* ═══════════ النبضةُ والملخّص ═══════════ */

    public function test_the_live_pulse_shows_what_the_page_shows(): void
    {
        $this->invoice(100);

        $page = $this->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertSame(Demo::money(105), collect($page['stats'])->firstWhere('label', 'مبيعات اليوم')['value']);

        // فاتورةٌ ثانيةٌ والصفحةُ مفتوحة
        $this->invoice(50);
        $pulse = $this->getJson(route('admin.dashboard.stats'))->assertOk()->json();

        $this->assertSame(Demo::money(157.5), collect($pulse['stats'])->firstWhere('label', 'مبيعات اليوم')['value'], 'النبضةُ لم تقرأ الفاتورةَ الجديدة');
        $this->assertEquals(157.5, $pulse['salesSeries']['data'][9]);
        $this->assertEquals([157.5], $pulse['paymentDistribution']['series']);

        $again = $this->get(route('admin.dashboard'))->viewData('page')['props'];
        $this->assertEquals($again['salesSeries'], $pulse['salesSeries']);
        $this->assertEquals($again['paymentDistribution'], $pulse['paymentDistribution']);
    }

    public function test_the_daily_summary_and_the_sales_alert_count_the_invoice(): void
    {
        $this->invoice(100);
        $this->sale(50, '2026-10-20 09:00');
        $this->invoice(10, '2026-10-19');

        $summary = Demo::dailySummaryFor($this->shop->id);
        $this->assertSame(155.0, $summary['sales']);
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(50.0, $summary['avg'], 'الفاتورةُ نفخت متوسّطَ الطلب');
        // ١٥٥ مقابل ١٠٫٥ أمس
        $this->assertTrue($summary['trend']['up']);

        $this->assertSame(155.0, AlertMetrics::value('daily_sales', $this->shop->id));
    }

    /* ═══════════ توزيعُ الدفع لا يُخفي ما أنقص الشهر ═══════════ */

    /** مجموعُ الأشرطة كما يجمعه من يقرؤها */
    private function splitTotal(): float
    {
        return round(array_sum(DashboardMetrics::paymentDistribution()['series']), 3);
    }

    /** المبلغُ في بطاقة «مبيعات الشهر» رقمًا — من المصدرين اللذين تجمعهما */
    private function monthCard(): float
    {
        $orders = (float) Order::where('business_id', $this->shop->id)->sold()->where('ordered_at', '>=', '2026-10-01')->sum('total');

        return round($orders + StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')), 3);
    }

    public function test_the_payment_split_adds_up_to_the_month_through_every_reduction(): void
    {
        // ١ — فاتورةٌ بمئة (١٠٥)
        $kept = $this->invoice(100);
        $this->assertSame([__('فواتير العملاء')], DashboardMetrics::paymentDistribution()['labels']);
        $this->assertSame(105.0, $this->splitTotal());

        // ٢ — إشعارُ دائنٍ جزئيّ ٥٢٫٥
        CustomerInvoices::creditNote($kept, 52.5, 2.5, 'مردود', $this->owner->id);
        $this->assertSame(52.5, $this->splitTotal());

        // ٣ — فاتورةُ يومٍ سابقٍ من الشهر تُلغى اليوم: صدورُها وعكسُها في الشهر نفسه
        CustomerInvoices::cancel($this->invoice(200, '2026-10-19'), 'خطأ', $this->owner->id);
        $this->assertSame(52.5, $this->splitTotal());

        // ٤ — فاتورةُ الشهر الماضي بخمسين تُلغى اليوم: صافي الفواتير صفر — فلا شريط ولا فرق
        CustomerInvoices::cancel($this->invoice(50, '2026-09-30'), 'خطأ', $this->owner->id);
        $this->assertSame(0.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')));
        $this->assertSame([], DashboardMetrics::paymentDistribution()['labels']);
        $this->assertCard(0, 'مبيعات الشهر');

        // ٥ — وفاتورةٌ أخرى من الماضي بمئتين تُلغى: الصافي سالب، ويُقال سالبًا
        CustomerInvoices::cancel($this->invoice(200, '2026-09-29'), 'خطأ', $this->owner->id);
        $split = DashboardMetrics::paymentDistribution();
        $this->assertSame([__('فواتير العملاء')], $split['labels'], 'صافي الفواتير السالب أُخفي من التوزيع');
        $this->assertSame([-210.0], $split['series']);
        $this->assertCard(-210, 'مبيعات الشهر');

        // ٦ — ومعها بيعةُ صندوق: كلُّ رقمٍ في مكانه، والمجموعُ بطاقةُ الشهر والدفتر
        $this->sale(50, '2026-10-20 09:00');
        $split = DashboardMetrics::paymentDistribution();
        $this->assertSame([Demo::methodLabel('نقدي'), __('فواتير العملاء')], $split['labels']);
        $this->assertSame([50.0, -210.0], $split['series']);
        $this->assertSame(-160.0, $this->splitTotal());
        $this->assertSame($this->monthCard(), $this->splitTotal(), 'الأشرطةُ لا تجمع بطاقةَ الشهر');
        $this->assertCard(-160, 'مبيعات الشهر');
        $this->assertSame(-160.0, $this->ledgerGross('2026-10-01', '2026-11-01'));

        // ولا يصير تحصيلٌ وسيلةَ بيع
        $this->pay($kept->fresh(), 52.5, '2026-10-20', 'بطاقة');
        $this->assertSame($split, DashboardMetrics::paymentDistribution(), 'التحصيلُ حرّك التوزيع');
    }

    /* ═══════════ تاريخُ اللوحة تاريخُ القيد ═══════════ */

    public function test_every_day_and_the_month_read_the_entry_date(): void
    {
        $this->invoice(10, '2026-10-01');
        $paidLater = $this->invoice(20, '2026-10-19');
        $this->pay($paidLater, 21, '2026-10-20');
        CustomerInvoices::creditNote($this->invoice(30, '2026-10-18'), 10.5, 0.5, 'مردود', $this->owner->id);
        // فاتورةٌ بتاريخٍ لاحقٍ من الشهر نفسه: قيدُها في أكتوبر
        $this->invoice(40, '2026-10-25');

        foreach (['2026-10-01', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-25'] as $day) {
            $next = Carbon::parse($day)->addDay()->toDateString();
            $this->assertSame(
                $this->ledgerGross($day, $next),
                StandaloneInvoiceSales::total($this->shop->id, Carbon::parse($day), Carbon::parse($next)),
                'يومُ '.$day.' في اللوحة غيرُ يومه في الدفتر',
            );
        }

        // اليوم: إشعارُ الدائن وحده (−١٠٫٥)؛ لا سدادُ فاتورة أمس
        $this->assertCard(-10.5, 'مبيعات اليوم');

        // الشهر: ١٠٫٥ + ٢١ + ٣١٫٥ − ١٠٫٥ + ٤٢ — والمخطّطُ يقول ما تقوله البطاقة
        $this->assertCard(94.5, 'مبيعات الشهر');
        $this->assertSame(94.5, $this->ledgerGross('2026-10-01', '2026-11-01'));
        $this->assertEquals(94.5, DashboardMetrics::salesYear()['data'][9], 'عمودُ الشهر الجاري أسقط فاتورةً مؤرّخةً بعد اليوم وبطاقتُه تعدّها');
        $this->assertSame(94.5, $this->splitTotal());
    }

    public function test_invoices_alone_with_no_order_at_all(): void
    {
        $this->invoice(100);
        $this->invoice(60, '2026-10-02');

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
        $this->assertCard(105, 'مبيعات اليوم');
        $this->assertCard(168, 'مبيعات الشهر');
        $this->assertSame('0', $this->card('عدد الطلبات'));
        $this->assertCard(0, 'متوسط قيمة الطلب');
        $this->assertSame(168.0, $this->ledgerGross('2026-10-01', '2026-11-01'));
        $this->assertSame(168.0, (float) Demo::reportSummary('month')['sales']);
        $this->assertSame(168.0, $this->splitTotal());
        $this->assertSame(0, DashboardMetrics::salesYear()['counts'][9]);
        // صافي الأرباح: ١٦٠ إيرادًا بلا ضريبة ولا تكلفة
        $this->assertCard(160, 'صافي الأرباح');
    }

    /* ═══════════ ثلاثةُ متاجر، لكلٍّ فرعان ═══════════ */

    public function test_three_shops_with_two_branches_each_never_meet(): void
    {
        [$third, $thirdOwner, $thirdA, $thirdB] = $this->business('الثالث', 'owner@third.om');
        $otherOwner = User::where('business_id', $this->other->id)->firstOrFail();
        [$otherA, $otherB] = Branch::where('business_id', $this->other->id)->orderBy('id')->get()->all();

        // لكلّ متجر: فاتورةٌ في فرعه الأوّل، وأخرى في الثاني، وثالثةٌ بلا فرع، وبيعةُ صندوق
        $plan = [
            [$this->shop, $this->owner, $this->muscat, $this->sohar, 100],
            [$this->other, $otherOwner, $otherA, $otherB, 1000],
            [$third, $thirdOwner, $thirdA, $thirdB, 10000],
        ];
        foreach ($plan as [$shop, , $a, $b, $unit]) {
            $this->invoice($unit, branch: $a, shop: $shop);
            $this->invoice($unit * 2, branch: $b, shop: $shop);
            $this->invoice($unit * 3, shop: $shop);
            $this->sale($unit / 2, '2026-10-20 09:00', branch: $a, shop: $shop);
        }

        $stockBefore = [DB::table('products')->orderBy('id')->get(['id', 'quantity'])->all(), DB::table('branch_stocks')->orderBy('id')->get()->all()];

        foreach ($plan as [$shop, $owner, $a, $b, $unit]) {
            $this->actingAs($owner);
            session()->forget('current_branch');

            // الشركةُ: ١٫٠٥ × (١ + ٢ + ٣) وحدات + نصفُ وحدةٍ بيعةً — كلُّ فاتورةٍ مرّة
            $company = round($unit * 1.05 * 6 + $unit / 2, 3);
            $this->assertCard($company, 'مبيعات اليوم', $shop->name.': المتجرُ كلُّه');
            $this->assertSame($company, round((float) Demo::reportSummary('month')['sales'], 3), $shop->name.': تقريرُ المبيعات');
            $this->assertSame($company, round(Ledger::netRevenue($shop->id, Carbon::parse('2026-10-01')) + Ledger::outputTax($shop->id, Carbon::parse('2026-10-01')), 3));
            $this->assertSame($company, round(array_sum(DashboardMetrics::paymentDistribution()['series']), 3));
            $this->assertSame($company, Demo::dailySummaryFor($shop->id)['sales']);

            foreach ([[$a, $unit * 1.05 + $unit / 2], [$b, $unit * 2 * 1.05]] as [$branch, $expected]) {
                session(['current_branch' => $branch->id]);
                $expected = round($expected, 3);
                $this->assertCard($expected, 'مبيعات الشهر', $shop->name.' / '.$branch->name);
                $this->assertSame($expected, round((float) Demo::reportSummary('month', null, $branch->id)['sales'], 3), $shop->name.' / '.$branch->name.': التقرير');
                $this->assertEquals($expected, DashboardMetrics::salesYear()['data'][9]);
                $this->assertSame($expected, round(array_sum(DashboardMetrics::paymentDistribution()['series']), 3));
            }

            // والفاتورةُ بلا فرع للشركة وحدها: الفرعان معًا ينقصان عنها بمقدارها
            $this->assertSame(round($unit * 3 * 1.05, 3), round($company - ($unit * 1.05 + $unit / 2) - $unit * 2 * 1.05, 3));

            // وفرعُ متجرٍ آخر في الجلسة لا يفتح شيئًا منه — يعود إلى المتجر كلِّه
            session(['current_branch' => $thirdA->is($a) ? $this->muscat->id : $thirdA->id]);
            $this->assertCard($company, 'مبيعات اليوم', $shop->name.': فرعُ متجرٍ آخر رشّح اللوحة');
        }

        $stockAfter = [DB::table('products')->orderBy('id')->get(['id', 'quantity'])->all(), DB::table('branch_stocks')->orderBy('id')->get()->all()];
        $this->assertEquals($stockBefore, $stockAfter, 'قراءةُ اللوحة حرّكت مخزونًا');
    }
}
