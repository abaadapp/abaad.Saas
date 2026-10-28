<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Receivables;
use App\Support\ReportData;
use App\Support\ReportingPeriod;
use App\Support\StandaloneInvoiceSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ العميل تُعدَّل بعد كتابتها — مسودّةً وصادرةً ومدفوعة — ولا يضيع شيء.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - المسودّةُ تُعدَّل بلا قيد، والصادرةُ يُعكس قيدُها ويُرحَّل قيدٌ بالمبلغ الجديد.
 *   - المدفوعةُ: المقبوضُ لا يُمسّ — الزيادةُ باقٍ على العميل، والنقصُ رصيدٌ دائنٌ له.
 *   - الفترةُ المقفلة لا تُكتب: التصحيحُ في الفترة الحاليّة، وتقريرُها كما أُعلن.
 *   - المولودةُ من طلبٍ لا تُعدَّل بنودُها ولا يتحرّك مخزونُها.
 *   - الإقرارُ والمبيعاتُ والذممُ والدفترُ برقمٍ واحد بعد التعديل.
 *   - الصلاحيةُ بحال الورقة، ومتجرٌ لا يبلغ ورقةَ غيره.
 *   - النموذجُ يُحفظ مرّةً، والتعديلُ المتزامنُ لا يمحو ما سبقه.
 *   - والتاجرُ الجديدُ يجد هذا كلَّه بلا إعداد.
 */
class AnInvoiceIsCorrectedAfterItIsWrittenTest extends TestCase
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
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = $this->user('admin', 'owner@edit.om');
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

    private function user(string $role, string $email, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $role.' '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** فاتورةٌ يدويّةٌ صادرة: بندٌ واحد، بنسبة المتجر (٥٪) */
    private function issued(string $on, float $qty, float $price, array $line = []): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => $on, 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => $qty, 'unit_price' => $price] + $line,
        ], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function pay(CustomerInvoice $invoice, float $amount, string $on = '2025-09-12'): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->ministry, $amount, [
            'method' => 'نقدي', 'occurred_at' => $on,
        ], [$invoice->id => $amount], $this->owner->id);
    }

    /** النموذجُ كما تُرسله الشاشة: البنودُ القائمةُ بمعرّفاتها — وما يُبدَّل فوقه */
    private function form(CustomerInvoice $invoice, array $over = [], ?array $items = null): array
    {
        $invoice = $invoice->fresh('items');

        return array_merge([
            'expected_revision' => (int) $invoice->revision,
            'edit_token' => 'tok-'.(++$this->n),
            'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id,
            'notes' => $invoice->notes,
            'reason' => 'تصحيح الكمية حسب أمر الشراء',
            'items' => $items ?? $invoice->items->map(fn ($i) => [
                'item_id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'tax_rate' => (float) $i->tax_rate,
            ])->all(),
        ], $over);
    }

    /** البندُ الأوّل بكمّيّةٍ أخرى */
    private function withQuantity(CustomerInvoice $invoice, float $qty, array $over = []): array
    {
        $form = $this->form($invoice, $over);
        $form['items'][0]['quantity'] = $qty;

        return $form;
    }

    private function edit(CustomerInvoice $invoice, array $form)
    {
        return $this->put(route('admin.customerInvoices.update', $invoice->id), $form);
    }

    private function vat(string $month = '2025-09'): array
    {
        return ReportData::vat($this->shop->id, ['_period' => ReportingPeriod::month($month)])['summary'];
    }

    private function ledger(string $month = '2025-09'): array
    {
        $p = ReportingPeriod::month($month);

        return [
            'net' => Ledger::netRevenue($this->shop->id, $p->start, $p->end),
            'tax' => Ledger::outputTax($this->shop->id, $p->start, $p->end),
        ];
    }

    private function entries(CustomerInvoice $invoice)
    {
        return JournalEntry::where('business_id', $this->shop->id)
            ->where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)
            ->orderBy('id')->get();
    }

    /* ═══════════ ١ · المسودّة ═══════════ */

    public function test_a_draft_is_rewritten_with_no_entry_and_its_history_kept(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], [
            ['description' => 'عرض سعر', 'quantity' => 1, 'unit_price' => 100],
        ], $this->owner->id);

        $form = $this->withQuantity($draft, 3, ['reason' => null, 'notes' => 'يُسلَّم صباحًا']);
        $form['items'][] = ['description' => 'تغليف', 'quantity' => 1, 'unit_price' => 10];

        $this->edit($draft, $form)->assertRedirect(route('admin.customerInvoices.show', $draft->id))->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertNull($draft->number, 'المسودّةُ لا تأخذ رقمًا بتعديلها');
        $this->assertEquals(325.5, (float) $draft->total);
        $this->assertSame('يُسلَّم صباحًا', $draft->notes);
        $this->assertCount(2, $draft->items);
        $this->assertSame(0, $this->entries($draft)->count(), 'المسودّةُ لا قيد لها ولو عُدّلت');

        $rev = $draft->revisions()->first();
        $this->assertSame(CustomerInvoiceRevision::DRAFT, $rev->kind);
        $this->assertSame(1, (int) $draft->revision);
        $this->assertEquals(105.0, (float) $rev->total_before);
        $this->assertEquals(325.5, (float) $rev->total_after);
    }

    /* ═══════════ ٢ · الصادرة في شهرٍ مفتوح ═══════════ */

    public function test_an_issued_invoice_in_an_open_month_is_reversed_and_posted_again_on_its_own_day(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 100 + 5
        $number = $invoice->number;

        $this->edit($invoice, $this->withQuantity($invoice, 3))->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame($number, $invoice->number, 'رقمُ الورقة الصادرة لا يتغيّر');
        $this->assertEquals(157.5, (float) $invoice->total);
        $this->assertEquals(7.5, (float) $invoice->tax_total);

        // القيدُ الأوّل باقٍ معكوسًا، والعكسُ والجديدُ بيوم الورقة — لا حذفَ ولا إعادةُ تأريخ
        [$first, $reversal, $fresh] = $this->entries($invoice)->all();
        $this->assertNotNull($first->fresh()->reversed_at);
        $this->assertSame($first->id, $reversal->reverses_id);
        $this->assertSame('2025-09-10', Carbon::parse($reversal->entry_date)->toDateString());
        $this->assertSame('2025-09-10', Carbon::parse($fresh->entry_date)->toDateString());
        $this->assertEquals(157.5, (float) $fresh->lines()->sum('debit'));

        // الإيرادُ مرّةً: الإقرارُ والدفترُ برقم الورقة الجديد
        $this->assertEquals(150.0, $this->vat()['taxable']);
        $this->assertEquals(7.5, $this->vat()['output']);
        $this->assertSame(['net' => 150.0, 'tax' => 7.5], $this->ledger());
        $this->assertEquals(157.5, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2025-09-01'), Carbon::parse('2025-10-01')));

        // والذمّةُ تتبع، والدفترُ يطابقها
        $this->assertEquals(157.5, $invoice->outstanding());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);

        $rev = $invoice->revisions()->first();
        $this->assertSame(CustomerInvoiceRevision::ISSUED, $rev->kind);
        $this->assertSame($reversal->id, $rev->reversal_entry_id);
        $this->assertSame($fresh->id, $rev->entry_id);
        $this->assertSame('same', $rev->period_mode);
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $rev->reason);
        $this->assertEquals(105.0, (float) $rev->before['total']);
        $this->assertEquals(157.5, (float) $rev->after['total']);
    }

    public function test_an_issued_invoice_needs_a_reason(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);

        $this->edit($invoice, $this->withQuantity($invoice, 3, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
        $this->assertSame(1, $this->entries($invoice)->count());
    }

    public function test_the_sales_report_reads_the_corrected_amount_once(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $this->edit($invoice, $this->withQuantity($invoice, 4))->assertSessionHasNoErrors();

        $screen = $this->get(route('admin.reports.sales', ['period' => 'month', 'month' => '2025-09']))
            ->assertOk()->viewData('page')['props'];

        $this->assertEquals(210.0, $screen['summary']['sales']);
        $this->assertEquals(200.0, $screen['summary']['net_revenue']);
        $this->assertEquals(10.0, $screen['summary']['tax']);
    }

    /* ═══════════ ٣ · المدفوعة ═══════════ */

    public function test_raising_a_fully_paid_invoice_leaves_the_receipt_and_owes_the_difference(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100); // 105
        $receipt = $this->pay($invoice, 105);
        $this->assertSame('مدفوعة', $invoice->fresh()->paymentState());

        $this->edit($invoice, $this->withQuantity($invoice, 2))->assertSessionHasNoErrors(); // 210

        $invoice->refresh();
        $this->assertEquals(210.0, (float) $invoice->total);
        $this->assertEquals(105.0, $invoice->paidTotal(), 'المقبوضُ كما قُبض');
        $this->assertEquals(105.0, $invoice->outstanding(), 'الفرقُ باقٍ على العميل');
        $this->assertSame('مدفوعة جزئيًا', $invoice->paymentState());
        $this->assertEquals(105.0, (float) $receipt->fresh()->amount, 'سندُ القبض لا يُعدَّل');
        $this->assertNull($receipt->fresh()->cancelled_at);
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->assertSame(CustomerInvoiceRevision::PAID, $invoice->revisions()->first()->kind);
    }

    public function test_lowering_a_paid_invoice_turns_the_excess_into_customer_credit_and_refunds_nothing(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100); // 105
        $this->pay($invoice, 105);
        $cash = Ledger::balance($this->shop->id, 'cash');

        $form = $this->form($invoice);
        $form['items'][0]['unit_price'] = 80; // 84

        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertEquals(84.0, (float) $invoice->total);
        $this->assertEquals(0.0, $invoice->outstanding());
        $this->assertEquals(21.0, Receivables::customerCredit($this->shop->id, $this->ministry->id), 'الفائضُ رصيدٌ دائنٌ للعميل');
        $this->assertEquals($cash, Ledger::balance($this->shop->id, 'cash'), 'لا مالَ يخرج من الصندوق تلقائيًّا');
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->assertEquals(21.0, (float) $invoice->revisions()->first()->changes['money']['customer_credit']);
    }

    public function test_a_paid_invoice_needs_its_own_permission(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $this->pay($invoice, 50);

        // يعدّل الصادرةَ ولا يملك المدفوعة
        $clerk = $this->user('accountant', 'clerk@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $this->actingAs($clerk);

        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertForbidden();
        $this->edit($invoice, $this->withQuantity($invoice, 2))->assertForbidden();
        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
    }

    /* ═══════════ ٤ · الفترةُ المقفلة ═══════════ */

    public function test_a_closed_month_is_never_rewritten_and_its_correction_lands_in_the_current_period(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 100 + 5
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-09-30']);
        Carbon::setTestNow('2025-10-05 10:00:00');

        // بلا اختيارٍ لموضع التصحيح لا يُكتب شيء
        $this->edit($invoice, $this->withQuantity($invoice, 3))->assertSessionHasErrors('period_mode');
        // وبتاريخ القيد الأصليّ في فترةٍ مقفلة: مردود
        $this->edit($invoice, $this->withQuantity($invoice, 3, ['period_mode' => BooksPeriod::ORIGINAL]))->assertSessionHasErrors('period_mode');
        // ولا تُنقل الورقةُ من فترةٍ مقفلة
        $this->edit($invoice, $this->withQuantity($invoice, 3, ['period_mode' => BooksPeriod::CURRENT, 'issued_at' => '2025-10-05']))->assertSessionHasErrors('edit');
        $this->assertSame(1, $this->entries($invoice)->count());

        $this->edit($invoice, $this->withQuantity($invoice, 3, ['period_mode' => BooksPeriod::CURRENT]))->assertSessionHasNoErrors();

        // سبتمبرُ كما أُعلن — والفرقُ في أكتوبر
        $this->assertEquals(100.0, $this->vat('2025-09')['taxable']);
        $this->assertEquals(5.0, $this->vat('2025-09')['output']);
        $this->assertSame(['net' => 100.0, 'tax' => 5.0], $this->ledger('2025-09'));
        $this->assertEquals(50.0, $this->vat('2025-10')['taxable']);
        $this->assertEquals(2.5, $this->vat('2025-10')['output']);
        $this->assertSame(['net' => 50.0, 'tax' => 2.5], $this->ledger('2025-10'));

        [, $reversal, $fresh] = $this->entries($invoice)->all();
        $this->assertSame('2025-10-05', Carbon::parse($reversal->entry_date)->toDateString());
        $this->assertSame('2025-10-05', Carbon::parse($fresh->entry_date)->toDateString());
        $this->assertStringContainsString('تصحيح الكمية حسب أمر الشراء', $fresh->description);

        $invoice->refresh();
        $this->assertSame('2025-09-10', $invoice->issued_at->toDateString(), 'تاريخُ الورقة في الفترة المقفلة باقٍ');
        $rev = $invoice->revisions()->first();
        $this->assertSame(BooksPeriod::CURRENT, $rev->period_mode);
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
    }

    public function test_the_ledger_itself_refuses_a_closed_date(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-09-15']);

        $this->expectException(ClosedPeriod::class);
        Ledger::post($this->shop->id, 'قيدٌ في فترةٍ مقفلة', [
            ['account' => 'receivable', 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ], Carbon::parse('2025-09-10'));
    }

    public function test_a_previous_month_needs_its_own_permission_and_a_choice(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        Carbon::setTestNow('2025-10-05 10:00:00');

        $clerk = $this->user('accountant', 'prior@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $this->actingAs($clerk);

        // يملك تعديلَ الصادرة — ولا يملك تصحيحَ فترةٍ سابقة
        $this->edit($invoice, $this->withQuantity($invoice, 3, ['period_mode' => BooksPeriod::CURRENT]))->assertForbidden();
        $this->assertSame(1, $this->entries($invoice)->count());

        // وما لا يعبر شهرًا يعدّله: الملاحظةُ لا تمسّ الدفتر
        $this->edit($invoice, $this->form($invoice, ['notes' => 'سُلّمت للمخزن']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->entries($invoice)->count());
    }

    public function test_the_original_date_is_the_owners_in_a_known_open_period(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-08-31']);
        Carbon::setTestNow('2025-10-05 10:00:00');

        $this->edit($invoice, $this->withQuantity($invoice, 3, ['period_mode' => BooksPeriod::ORIGINAL]))->assertSessionHasNoErrors();

        // سبتمبرُ مفتوحٌ معلوم: يُصحَّح في يومه
        $this->assertEquals(7.5, $this->vat('2025-09')['output']);
        $this->assertEquals(0.0, $this->vat('2025-10')['output']);
    }

    /* ═══════════ ٥ · المولودةُ من طلب، والمخزون ═══════════ */

    public function test_an_invoice_born_of_an_order_keeps_its_lines_its_stock_and_its_books(): void
    {
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 100, 'quantity' => 10]);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'number' => 'S-1', 'status' => 'مكتمل',
            'is_held' => false, 'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع',
            'customer_id' => $this->ministry->id, 'customer_name' => $this->ministry->name,
            'subtotal' => 100, 'discount' => 0, 'tax' => 5, 'total' => 105, 'ordered_at' => Carbon::parse('2025-09-06'),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $rose->id, 'name' => 'وردة', 'price' => 100, 'quantity' => 1, 'cost' => 0, 'total' => 100]);
        Books::recordSale($order);
        $invoice = CustomerInvoices::issue(CustomerInvoices::fromOrder($order, [], $this->owner->id), $this->owner->id);
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();

        $this->edit($invoice, $this->withQuantity($invoice, 5))->assertSessionHasErrors('edit');

        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
        $this->assertEquals(105.0, (float) $order->fresh()->total);
        $this->assertSame(10, (int) $rose->fresh()->quantity, 'لا حركة مخزون');
        $this->assertSame($entries, JournalEntry::where('business_id', $this->shop->id)->count(), 'لا قيد ثانٍ للبيعة');

        // وما لا مالَ فيه يُعدَّل
        $this->edit($invoice, $this->form($invoice, ['po_number' => 'PO-77', 'items' => null]))->assertSessionHasNoErrors();
        $this->assertSame('PO-77', $invoice->fresh()->po_number);
        $this->assertSame($entries, JournalEntry::where('business_id', $this->shop->id)->count());
    }

    public function test_a_manual_invoice_line_of_a_product_moves_no_stock_when_corrected(): void
    {
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'quantity' => 40]);
        $invoice = $this->issued('2025-09-10', 2, 10, ['product_id' => $rose->id]);

        $this->edit($invoice, $this->withQuantity($invoice, 7))->assertSessionHasNoErrors();
        $this->edit($invoice->fresh(), $this->withQuantity($invoice->fresh(), 1))->assertSessionHasNoErrors();

        $this->assertSame(40, (int) $rose->fresh()->quantity, 'الفاتورةُ اليدويّة لا تُخرج بضاعة — ولا تُرجع ما لم يخرج');
        $this->assertEquals(10.5, (float) $invoice->fresh()->total);
        $this->assertSame(['net' => 10.0, 'tax' => 0.5], $this->ledger());
    }

    /* ═══════════ ٥ب · البندُ القائمُ لقطتُه ═══════════ */

    public function test_a_catalog_addon_line_keeps_the_price_it_was_sold_at(): void
    {
        $chocolate = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 3, 'active' => true, 'scope' => Addon::SCOPE_ALL,
        ]);
        // بسعر الكتالوج كما يكتبها الإنشاء (`addonsFromCatalog`)
        $lines = CustomerInvoices::addonsFromCatalog($this->shop->id, [['addon_id' => $chocolate->id, 'quantity' => 2]]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], $lines, $this->owner->id), $this->owner->id);
        $this->assertEquals(6.3, (float) $invoice->total);

        // غُيّر ثمنُها في الكتالوج ثمّ أُوقفت — والبندُ الذي بيع بها لا يتبعها
        $chocolate->update(['price' => 9, 'active' => false]);

        $form = $this->withQuantity($invoice, 4);
        $form['items'][0]['unit_price'] = 999; // ثمنٌ مكتوبٌ بيد لإضافة كتالوج — لا يُقرأ
        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $line = $invoice->fresh('items')->items->first();
        $this->assertEquals(3.0, (float) $line->unit_price);
        $this->assertSame('شوكولاتة', $line->description);
        $this->assertEquals(12.6, (float) $invoice->fresh()->total);
    }

    public function test_an_item_leaves_with_its_paid_addons_or_not_at_all(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $parent = $invoice->items()->first();
        // إضافةٌ مدفوعةٌ تحت صنفها — كما تكتبها `InvoiceLineDetails::expand`
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'parent_item_id' => $parent->id, 'description' => 'تغليف',
            'quantity' => 1, 'unit_price' => 0, 'discount' => 0, 'tax_rate' => 5, 'tax_amount' => 0, 'line_total' => 0, 'sort_order' => 1,
        ]);
        $invoice = $invoice->fresh('items');
        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertOk();

        $form = $this->form($invoice);
        $child = $form['items'][1];
        $kept = $this->form($invoice, [], [$child, ['description' => 'بند يدوي', 'quantity' => 1, 'unit_price' => 50]]);
        $this->edit($invoice, $kept)->assertSessionHasErrors('items');
        $this->assertCount(2, $invoice->fresh('items')->items);

        $both = $this->form($invoice, [], [['description' => 'بند يدوي', 'quantity' => 1, 'unit_price' => 50]]);
        $this->edit($invoice, $both)->assertSessionHasNoErrors();
        $this->assertSame(['بند يدوي'], $invoice->fresh('items')->items->pluck('description')->all());
        $this->assertEquals(52.5, (float) $invoice->fresh()->total);
    }

    /* ═══════════ ٦ · الضريبةُ بقاعدة الورقة ═══════════ */

    public function test_an_untouched_line_keeps_its_tax_when_the_shop_changes_its_rule(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100); // مضافة: 100 + 5
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_inclusive'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_rate'], ['value' => '10']);

        $form = $this->form($invoice);
        $form['items'][] = ['description' => 'تغليف', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 5];

        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $invoice->refresh();
        // الأوّلُ كما صدر (105)، والجديدُ بقاعدة الورقة نفسِها: مضافة (21)
        $this->assertEquals(126.0, (float) $invoice->total);
        $this->assertEquals(6.0, (float) $invoice->tax_total);
    }

    /* ═══════════ ٧ · الصلاحيات والمتاجر ═══════════ */

    public function test_who_edits_what_is_decided_by_the_papers_state(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], [
            ['description' => 'عرض', 'quantity' => 1, 'unit_price' => 10],
        ], $this->owner->id);
        $invoice = $this->issued('2025-09-10', 1, 100);

        // البائعُ يعدّل المسودّة بدوره، ولا يعدّل الصادرة
        $seller = $this->user('sales', 'seller@edit.om');
        $this->actingAs($seller);
        $this->edit($draft, $this->withQuantity($draft, 2, ['reason' => null]))->assertSessionHasNoErrors();
        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertForbidden();
        $this->edit($invoice, $this->withQuantity($invoice, 2))->assertForbidden();

        // ومديرُ الفرع والمحاسب لا يعدّلان الصادرةَ بدورهما — يُمنحانها بالاسم
        foreach (['manager', 'accountant'] as $role) {
            $this->actingAs($this->user($role, $role.'@edit.om'));
            $this->edit($invoice, $this->withQuantity($invoice, 2))->assertForbidden();
        }
        $this->assertEquals(105.0, (float) $invoice->fresh()->total);

        // ومن مُنحها بالاسم يعدّل
        $this->actingAs($this->user('accountant', 'granted@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]));
        $this->edit($invoice, $this->withQuantity($invoice, 2))->assertSessionHasNoErrors();
        $this->assertEquals(210.0, (float) $invoice->fresh()->total);

        // والسائقُ يفتح «المبيعات» ولا يعدّل حتى المسودّة
        $this->actingAs($this->user('delivery', 'driver@edit.om'));
        $this->edit($draft, $this->withQuantity($draft->fresh(), 5, ['reason' => null]))->assertForbidden();
    }

    public function test_a_shop_never_reaches_another_shops_invoice(): void
    {
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '97000000']);
        $theirs = CustomerInvoices::issue(CustomerInvoices::create($this->other->id, $stranger, ['issued_at' => '2025-09-10'], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 700],
        ]));

        $this->get(route('admin.customerInvoices.edit', $theirs->id))->assertNotFound();
        $this->post(route('admin.customerInvoices.editPreview', $theirs->id), $this->form($theirs))->assertNotFound();
        $this->edit($theirs, $this->withQuantity($theirs, 9, ['customer_id' => $this->ministry->id]))->assertNotFound();

        $this->assertEquals((float) $theirs->total, (float) $theirs->fresh()->total);
        $this->assertSame(0, CustomerInvoiceRevision::count());

        // ولا يُكتب في ورقته عميلٌ أو فرعٌ أو بندٌ من متجرٍ آخر
        $mine = $this->issued('2025-09-10', 1, 100);
        $this->edit($mine, $this->form($mine, ['customer_id' => $stranger->id]))->assertSessionHasErrors('customer_id');
        $theirBranch = Branch::create(['business_id' => $this->other->id, 'name' => 'فرع الجار']);
        $this->edit($mine, $this->form($mine, ['branch_id' => $theirBranch->id]))->assertSessionHasErrors('branch_id');
        $theirItem = $theirs->items()->first();
        $this->edit($mine, $this->form($mine, [], [['item_id' => $theirItem->id, 'quantity' => 1, 'unit_price' => 1]]))->assertSessionHasErrors('items');
        $this->assertEquals(105.0, (float) $mine->fresh()->total);
    }

    /* ═══════════ ٨ · مرّةً واحدة، وبلا محوٍ لما سبق ═══════════ */

    public function test_the_same_form_sent_twice_corrects_once(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $form = $this->withQuantity($invoice, 3);

        $this->edit($invoice, $form)->assertSessionHasNoErrors();
        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(3, $this->entries($invoice)->count(), 'قيدٌ وعكسُه وقيدٌ جديد — لا أكثر');
        $this->assertSame(['net' => 150.0, 'tax' => 7.5], $this->ledger());
    }

    public function test_a_second_editor_with_an_old_copy_does_not_erase_the_first(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $first = $this->withQuantity($invoice, 3);
        $second = $this->withQuantity($invoice, 9); // فُتحت النسخةُ نفسُها قبل حفظ الأوّل

        $this->edit($invoice, $first)->assertSessionHasNoErrors();
        $this->edit($invoice, $second)->assertSessionHasErrors('edit');

        $this->assertEquals(157.5, (float) $invoice->fresh()->total);
        $this->assertSame(1, $invoice->revisions()->count());
    }

    /* ═══════════ ٩ · المالُ الذي لا يُمسّ ═══════════ */

    public function test_a_pending_payment_link_holds_the_amount_but_not_the_notes(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id,
            'reference' => 'ref-edit-1', 'amount' => 105, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING,
            'expires_at' => now()->addDays(3), 'is_live' => true,
        ]);

        $this->edit($invoice, $this->withQuantity($invoice, 2))->assertSessionHasErrors('edit');
        $this->assertEquals(105.0, (float) $invoice->fresh()->total);

        $this->edit($invoice, $this->form($invoice, ['notes' => 'تواصل مع المشتريات']))->assertSessionHasNoErrors();
        $this->assertSame('تواصل مع المشتريات', $invoice->fresh()->notes);
    }

    public function test_the_customer_changes_only_where_no_money_moves_with_it(): void
    {
        $other = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92222222', 'allow_credit_sales' => true]);

        // بلا تحصيل: تُنقل ولا يتحرّك الدفتر — الذمّةُ حسابٌ واحد
        $free = $this->issued('2025-09-10', 1, 100);
        $this->edit($free, $this->form($free, ['customer_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($other->id, $free->fresh()->customer_id);
        $this->assertSame('وزارة الصحة', $free->fresh()->customer_name);
        $this->assertSame(1, $this->entries($free)->count());
        $this->assertEquals(105.0, Receivables::customerOutstanding($this->shop->id, $other->id));

        // وعليها تحصيل: لا يُنقل مالُ عميلٍ إلى غيره في صمت
        $paid = $this->issued('2025-09-11', 1, 100);
        $this->pay($paid, 40);
        $this->edit($paid, $this->form($paid, ['customer_id' => $other->id]))->assertSessionHasErrors('edit');
        $this->assertSame($this->ministry->id, $paid->fresh()->customer_id);
    }

    public function test_credit_notes_set_a_floor_under_the_total(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        CustomerInvoices::creditNote($invoice, 63, 3, 'مرتجع جزئي', $this->owner->id);

        $form = $this->form($invoice);
        $form['items'][0]['unit_price'] = 50; // 52.5 < 63

        $this->edit($invoice, $form)->assertSessionHasErrors('edit');
        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
    }

    public function test_a_cancelled_invoice_is_not_edited_and_an_edited_one_cancels_cleanly(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $this->edit($invoice, $this->withQuantity($invoice, 3))->assertSessionHasNoErrors();

        CustomerInvoices::cancel($invoice->fresh(), 'أُلغي التوريد', $this->owner->id);

        // يُعكس القيدُ القائم وحده — لا الأوّلُ المعكوسُ ثانيةً
        $this->assertSame(['net' => 0.0, 'tax' => 0.0], $this->ledger());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);

        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertForbidden();
    }

    /* ═══════════ ١٠ · السجلّ ═══════════ */

    public function test_the_history_is_read_by_who_may_and_never_rewritten(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $this->edit($invoice, $this->withQuantity($invoice, 3))->assertSessionHasNoErrors();

        $page = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['mayEdit']);
        $this->assertCount(1, $page['revisions']);
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $page['revisions'][0]['reason']);
        $this->assertEquals(157.5, $page['revisions'][0]['total_after']);

        $this->actingAs($this->user('sales', 'reader@edit.om'));
        $page = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertFalse($page['mayEdit']);
        $this->assertNull($page['revisions'], 'السجلُّ لمن يُمنح قراءته');

        $rev = CustomerInvoiceRevision::first();
        try {
            $rev->update(['reason' => 'غيرُه']);
            $this->fail('سجلُّ التعديل عُدّل');
        } catch (\LogicException) {
        }
        try {
            $rev->delete();
            $this->fail('سجلُّ التعديل حُذف');
        } catch (\LogicException) {
        }
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $rev->fresh()->reason);
    }

    public function test_the_preview_says_what_will_happen_and_writes_nothing(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $this->pay($invoice, 105);
        $form = $this->form($invoice);
        $form['items'][0]['unit_price'] = 80;

        $plan = $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $form)->assertOk()->json();

        $this->assertTrue($plan['changed']);
        $this->assertSame('paid', $plan['kind']);
        $this->assertEquals(-21.0, $plan['money']['difference']);
        $this->assertEquals(21.0, $plan['money']['customer_credit']);
        $this->assertFalse($plan['stock']['moves']);
        $this->assertTrue($plan['ledger']['repost']);
        $this->assertSame([], $plan['blockers']);

        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
        $this->assertSame(0, CustomerInvoiceRevision::count());
    }

    /* ═══════════ ١١ · التاجرُ الجديد ═══════════ */

    public function test_a_new_merchant_finds_the_edits_and_their_permissions_with_no_setup(): void
    {
        $fresh = Business::create(['name' => 'متجرٌ جديد', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($fresh->id);
        $owner = $this->user('admin', 'new-owner@edit.om', null, $fresh);
        $staff = $this->user('sales', 'new-staff@edit.om', null, $fresh);
        $this->actingAs($owner);

        $page = $this->get(route('admin.employees.edit', $staff->id))->assertOk()->viewData('page')['props'];
        foreach ([
            Permissions::CUSTOMER_INVOICE_EDIT_DRAFT, Permissions::CUSTOMER_INVOICE_EDIT_ISSUED,
            Permissions::CUSTOMER_INVOICE_EDIT_PAID, Permissions::CUSTOMER_INVOICE_CORRECT_PERIOD,
            Permissions::CUSTOMER_INVOICE_HISTORY,
        ] as $action) {
            $this->assertArrayHasKey($action, $page['actions'], "«{$action}» لا يظهر في صلاحيات الموظفين");
            $this->assertContains($action, $page['grantable'], 'صاحبُ النشاط لا يمنحه');
        }

        $customer = Customer::create(['business_id' => $fresh->id, 'name' => 'أوّل عميل', 'phone' => '93333333']);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($fresh->id, $customer, ['issued_at' => '2025-09-15'], [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 30],
        ]));

        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertOk();
        $before = (float) $invoice->total;
        $form = $this->form($invoice);
        $form['items'][0]['quantity'] = 2;
        $this->edit($invoice, $form)->assertSessionHasNoErrors();
        // بضريبة متجره هو كما صدرت — لا بنسبة متجرٍ آخر
        $this->assertEquals(round(2 * $before, 3), (float) $invoice->fresh()->total);
        $this->assertTrue(Receivables::reconcile($fresh->id)['balanced']);
    }
}
