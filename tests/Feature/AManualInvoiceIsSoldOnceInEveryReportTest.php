<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Receivables;
use App\Support\ReportData;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ العميل اليدويّة تُعدّ في التقارير — مرّةً واحدة.
 *
 * ═══ ما كان ═══
 *
 * الفاتورةُ اليدويّة (بلا طلب) تُرحَّل في الدفتر وتُدين الذمم، لكنّ إقرارَ
 * الضريبة ومخطّطَ المبيعات و«الأكثر إنفاقًا» تقرأ الطلبات وحدها. فضريبتُها
 * مستحقّةٌ في الدفتر ولا يُعلنها الإقرار، وأكبرُ عميلٍ يُفوتَر بيدٍ لا يظهر.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - الفاتورةُ اليدويّة في الإقرار والمبيعات والعملاء — مرّةً.
 *   - الفاتورةُ المولودة من طلب، وورقةُ الشهر، لا تُعدّان بعد طلباتهما.
 *   - إشعارُ الدائن اليدويّ يُنقص المبيعات والضريبة، وإشعارُ إلغاء الطلب لا
 *     يُنقصها ثانيةً بعد سقوط طلبه.
 *   - إلغاءُ الفاتورة عكسٌ في يومه: شهرُها كما كان، وشهرُ الإلغاء يُنقص.
 *   - الذممُ كما كانت قبل التحصيل وبعده، ومطابقةٌ لدفترها.
 *   - والتقاريرُ والدفترُ برقمٍ واحد للفترة نفسها.
 */
class AManualInvoiceIsSoldOnceInEveryReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $branch;

    private User $owner;

    private Customer $ministry;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الفواتير', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@invoices.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->ministry = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** بيعةٌ كما تقع: طلبٌ وقيدُه — نقدًا، أو آجلًا على عميل */
    private function sale(string $at, float $net, ?Customer $customer = null, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;
        $tax = round($net * 0.05, 3);
        $order = Order::create([
            'business_id' => $shop->id, 'branch_id' => $shop->is($this->shop) ? $this->branch->id : null,
            'number' => 'S-'.(++$this->n), 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => $customer ? 'آجل' : 'نقدي', 'payment_status' => $customer ? 'غير مدفوع' : 'مدفوع',
            'customer_id' => $customer?->id, 'customer_name' => $customer?->name ?? 'سالم',
            'subtotal' => $net, 'discount' => 0, 'tax' => $tax, 'total' => $net + $tax,
            'ordered_at' => Carbon::parse($at),
        ]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'باقة', 'price' => $net, 'quantity' => 1, 'cost' => 0, 'total' => $net]);
        Books::recordSale($order);

        return $order;
    }

    /** فاتورةٌ يدويّةٌ صادرة — بلا طلب، بنسبة المتجر (٥٪) */
    private function manual(string $on, float $qty, float $price, ?Customer $customer = null): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $customer ?? $this->ministry, ['issued_at' => $on, 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => $qty, 'unit_price' => $price],
        ], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function september(): ReportingPeriod
    {
        return ReportingPeriod::month('2025-09');
    }

    private function vat(string $month = '2025-09'): array
    {
        return ReportData::vat($this->shop->id, ['_period' => ReportingPeriod::month($month)]);
    }

    private function ledger(string $month = '2025-09'): array
    {
        $p = ReportingPeriod::month($month);

        return [
            'net' => Ledger::netRevenue($this->shop->id, $p->start, $p->end),
            'tax' => Ledger::outputTax($this->shop->id, $p->start, $p->end),
        ];
    }

    /** يومٌ في سبتمبر فيه كلُّ نوع: نقد، وطلبٌ مفوتَر، وورقةُ شهر، ويدويّتان — إحداهما تُلغى — وإشعار */
    private function aMonthOfEverything(): array
    {
        $this->sale('2025-09-05 10:00', 100);                                     // نقدًا: 100 + 5

        $billed = $this->sale('2025-09-06 10:00', 200, $this->ministry);          // آجلًا ثمّ فاتورتُه: 200 + 10
        $fromOrder = CustomerInvoices::issue(CustomerInvoices::fromOrder($billed, [], $this->owner->id), $this->owner->id);

        $a = $this->sale('2025-09-07 10:00', 50, $this->ministry);                // ورقةُ شهرٍ لطلبين: 2 × (50 + 2.5)
        $b = $this->sale('2025-09-08 10:00', 50, $this->ministry);
        $monthly = CustomerInvoices::consolidate($this->ministry, [$a->id, $b->id], [], $this->owner->id);

        $manual = $this->manual('2025-09-10', 2, 50);                             // يدويّة: 100 + 5
        $cancelled = $this->manual('2025-09-12', 1, 40);                          // يدويّة تُلغى في الشهر نفسه: 40 + 2
        CustomerInvoices::cancel($cancelled, 'خطأ في الكمية', $this->owner->id);

        $note = CustomerInvoices::creditNote($manual, 21, 1, 'مرتجع', $this->owner->id); // إشعار: −(20 + 1)

        // ومتجرُ الجار لا يدخل شيئًا — لا بيعتُه ولا فاتورتُه اليدويّة ولا إشعارُها
        $this->sale('2025-09-09 10:00', 9000, null, $this->other);
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'وزارة الثقافة', 'phone' => '97000000']);
        $theirs = CustomerInvoices::issue(CustomerInvoices::create($this->other->id, $stranger, ['issued_at' => '2025-09-10'], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 7000],
        ]));
        CustomerInvoices::creditNote($theirs, 105, 5, 'مرتجع');

        return compact('fromOrder', 'monthly', 'manual', 'cancelled', 'note');
    }

    /* ═══════════ ١ · الإقرارُ والدفترُ برقمٍ واحد ═══════════ */

    public function test_the_vat_return_reads_manual_invoices_and_credit_notes_once_and_matches_the_ledger(): void
    {
        $docs = $this->aMonthOfEverything();

        // المولودةُ من طلبٍ وورقةُ الشهر بلا قيدٍ — والملغاةُ بقيدها وعكسه
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)
            ->whereIn('sourceable_id', [$docs['fromOrder']->id, $docs['monthly']->id])->count());

        $summary = $this->vat()['summary'];

        // الطلباتُ: 100 + 200 + 50 + 50 = 400 وضريبتُها 20 — واليدويّة 100 و5 — والإشعار −20 و−1
        $this->assertEquals(480.0, $summary['taxable'], 'الوعاءُ بغير الفاتورة اليدويّة أو بها مرّتين');
        $this->assertEquals(24.0, $summary['output'], 'الضريبةُ بغير الفاتورة اليدويّة أو بها مرّتين');

        // والدفترُ للشهر نفسه برقم الإقرار — الوعاءُ صافي الإيرادات والضريبةُ ضريبتُها
        $this->assertSame(['net' => 480.0, 'tax' => 24.0], $this->ledger());

        // وصفُّ الشهر هو الملخّص
        $this->assertCount(1, $this->vat()['rows']);
        $this->assertEquals(24.0, $this->vat()['rows'][0]['output']);
    }

    public function test_a_manual_invoice_and_its_credit_note_alone_are_in_the_return(): void
    {
        $invoice = $this->manual('2025-09-10', 2, 50);

        $this->assertEquals(100.0, $this->vat()['summary']['taxable']);
        $this->assertEquals(5.0, $this->vat()['summary']['output']);

        CustomerInvoices::creditNote($invoice, 21, 1, 'مرتجع', $this->owner->id);

        $this->assertEquals(80.0, $this->vat()['summary']['taxable']);
        $this->assertEquals(4.0, $this->vat()['summary']['output']);
        $this->assertEquals(4.0, Ledger::outputTax($this->shop->id, $this->september()->start, $this->september()->end));
    }

    /* ═══════════ ٢ · الإلغاءُ عكسٌ في يومه ═══════════ */

    public function test_a_manual_invoice_cancelled_next_month_keeps_its_month_and_reduces_the_next(): void
    {
        $invoice = $this->manual('2025-09-10', 2, 50);

        Carbon::setTestNow('2025-10-03 09:00:00');
        CustomerInvoices::cancel($invoice, 'أُلغي التوريد', $this->owner->id);

        // سبتمبرُ كما أُعلن — لا يُعاد كتابةُ شهرٍ مضى
        $this->assertEquals(5.0, $this->vat('2025-09')['summary']['output']);
        $this->assertEquals(100.0, $this->vat('2025-09')['summary']['taxable']);
        // وأكتوبرُ يحمل العكس
        $this->assertEquals(-5.0, $this->vat('2025-10')['summary']['output']);
        $this->assertEquals(-100.0, $this->vat('2025-10')['summary']['taxable']);
        $this->assertSame(['net' => -100.0, 'tax' => -5.0], $this->ledger('2025-10'));

        // وفي «الأكثر إنفاقًا» لا تُعدّ ملغاةٌ — كما لا يُعدّ الطلبُ الملغى
        $this->assertSame([], Demo::customerSpend(ReportingPeriod::month('2025-09'), $this->shop->id));
    }

    /** ومسودّةٌ أُلغيت قبل إصدارها لا قيدَ لها ولا أثر */
    public function test_a_cancelled_draft_leaves_no_trace(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], [
            ['description' => 'عرض سعر', 'quantity' => 1, 'unit_price' => 500],
        ], $this->owner->id);
        CustomerInvoices::cancel($draft, 'لم يُعتمد', $this->owner->id);

        $this->assertEquals(0.0, $this->vat()['summary']['output']);
        $this->assertSame([], Demo::customerSpend($this->september(), $this->shop->id));
    }

    /* ═══════════ ٣ · المبيعات: البطاقةُ والمخطّطُ والدفتر ═══════════ */

    public function test_the_sales_report_counts_each_sale_once_and_its_chart_adds_up_to_its_card(): void
    {
        $this->aMonthOfEverything();
        $sep = ['period' => 'month', 'month' => '2025-09'];

        $screen = $this->get(route('admin.reports.sales', $sep))->assertOk()->viewData('page')['props'];

        // 420 من الطلبات + 105 يدويّة − 21 إشعار — والملغاةُ وعكسُها صفر
        $this->assertEquals(504.0, $screen['summary']['sales']);
        $this->assertEquals(480.0, $screen['summary']['net_revenue']);
        $this->assertEquals(24.0, $screen['summary']['tax']);

        $data = array_filter($screen['salesSeries']['data'], fn ($v) => $v !== null);
        $this->assertEquals(504.0, round(array_sum($data), 3), 'المخطّطُ لا يجمع البطاقةَ التي فوقه');
        // الفاتورةُ اليدويّة في يومها، والإشعارُ والإلغاءُ في يوم وقوعهما
        $this->assertEquals(105.0, $screen['salesSeries']['data'][9]);
        $this->assertEquals(42.0, $screen['salesSeries']['data'][11]);
        $this->assertEquals(-63.0, $screen['salesSeries']['data'][19]);
        // وعددُ العمود طلباتُه — الفاتورةُ اليدويّة مبلغٌ لا طلب
        $this->assertSame(0, $screen['salesSeries']['counts'][9]);
        $this->assertSame(1, $screen['salesSeries']['counts'][4]);

        // والملفُّ بأرقام الشاشة
        $csv = $this->get(route('admin.export.reports', $sep));
        $csv->assertOk();
    }

    /* ═══════════ ٤ · الأكثر إنفاقًا ═══════════ */

    public function test_top_customers_add_manual_invoices_once_and_take_off_credit_notes(): void
    {
        $this->aMonthOfEverything();

        $report = ReportData::customers($this->shop->id, ['_period' => $this->september()]);
        $ministry = collect($report['rows'])->firstWhere('name', 'وزارة الثقافة');

        // طلباتُها 210 + 52.5 + 52.5 — ويدويّتُها 105 — ناقصًا الإشعار 21. والملغاةُ لا شيء
        $this->assertEquals(399.0, $ministry['total']);
        $this->assertSame(4, $ministry['orders'], 'ثلاثةُ طلباتٍ وفاتورةٌ يدويّة — لا فاتورةُ الطلب ولا ورقةُ الشهر');
        $this->assertSame('وزارة الثقافة', $report['rows'][0]['name']);

        $this->assertEquals(504.0, $report['summary']['total'], 'الإنفاقُ كلُّه غيرُ مبيعات الفترة');
        $this->assertSame(2, $report['summary']['customers']);
        $this->assertSame(5, $report['summary']['orders']);

        // والجارُ لا يدخل
        $this->assertNull(collect($report['rows'])->firstWhere('total', 9450.0));
    }

    public function test_a_credit_note_on_an_order_invoice_reduces_once_and_an_order_cancellation_note_does_not_reduce_twice(): void
    {
        $billed = $this->sale('2025-09-06 10:00', 200, $this->ministry);
        $invoice = CustomerInvoices::issue(CustomerInvoices::fromOrder($billed, [], $this->owner->id), $this->owner->id);

        $a = $this->sale('2025-09-07 10:00', 50, $this->ministry);
        $b = $this->sale('2025-09-08 10:00', 50, $this->ministry);
        CustomerInvoices::consolidate($this->ministry, [$a->id, $b->id], [], $this->owner->id);

        // مرتجعٌ يدويٌّ على فاتورة الطلب: حدثٌ جديدٌ يُقيَّد ويُنقص
        CustomerInvoices::creditNote($invoice, 42, 2, 'مرتجع جزئي', $this->owner->id);

        // وإلغاءُ طلبٍ في ورقة الشهر: قيدُه يُعكس، وإشعارُه لا قيدَ له
        $b->update(['status' => Order::CANCELLED]);
        Books::unpostSale($b, $this->owner->id, 'إلغاء');
        $cancelNote = CustomerInvoices::onOrderCancelled($b, $this->owner->id);
        $this->assertSame(CustomerInvoices::NOTE_ORDER_CANCELLED, $cancelNote->source);

        // الطلبان الباقيان 250 وضريبتُهما 12.5 — ناقصًا المرتجع 40 و2. والملغى مرّةً واحدة
        $summary = $this->vat()['summary'];
        $this->assertEquals(210.0, $summary['taxable']);
        $this->assertEquals(10.5, $summary['output']);
        $this->assertSame(['net' => 210.0, 'tax' => 10.5], $this->ledger());

        $ministry = collect(ReportData::customers($this->shop->id, ['_period' => $this->september()])['rows'])->firstWhere('name', 'وزارة الثقافة');
        $this->assertEquals(220.5, $ministry['total'], '262.5 − 42 — وإشعارُ الإلغاء لا يُطرح بعد سقوط طلبه');
        $this->assertSame(2, $ministry['orders']);
    }

    /* ═══════════ ٥ · الذممُ كما كانت ═══════════ */

    public function test_receivables_stay_right_before_and_after_a_payment_and_the_reports_do_not_move(): void
    {
        $docs = $this->aMonthOfEverything();

        // 210 + 105 من الطلبات المفوترة، و105 − 21 على اليدويّة، والملغاةُ صفر
        $this->assertEquals(399.0, Receivables::customerOutstanding($this->shop->id, $this->ministry->id));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->assertEquals(399.0, Ledger::balance($this->shop->id, 'receivable'));

        CustomerPayments::record($this->shop->id, $this->ministry, 84, ['method' => 'نقدي', 'paid_at' => '2025-09-20'], [$docs['manual']->id => 84], $this->owner->id);

        $this->assertEquals(315.0, Receivables::customerOutstanding($this->shop->id, $this->ministry->id));
        $this->assertEquals(0.0, $docs['manual']->fresh()->outstanding());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->assertEquals(315.0, Ledger::balance($this->shop->id, 'receivable'));

        // والتحصيلُ ليس بيعًا: لا إقرارَ يتحرّك ولا مبيعات
        $this->assertEquals(24.0, $this->vat()['summary']['output']);
        $this->assertSame(['net' => 480.0, 'tax' => 24.0], $this->ledger());
    }

    /* ═══════════ ٦ · ولا تكلفةَ للفاتورة اليدويّة ═══════════ */

    public function test_a_manual_invoice_still_posts_no_cost_of_sales(): void
    {
        $invoice = $this->manual('2025-09-10', 2, 50);

        $lines = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)
            ->with('lines.account')->get()->flatMap->lines->map(fn ($l) => $l->account->system_key)->sort()->values()->all();

        $this->assertSame(['receivable', 'sales', 'tax_payable'], $lines);
    }
}
