<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** أبوابُ فواتير العملاء — ما يُقبل منها وما يُردّ */
class CustomerInvoiceDoorsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة', 'allow_credit_sales' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        // و`$extra` أوّلًا: العامل `+` لا يستبدل مفتاحًا موجودًا
        return $extra + [
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'items' => [['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50]],
        ];
    }

    public function test_the_owner_creates_a_draft(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::DRAFT, CustomerInvoice::first()->status);
        // ١٠٠ + ٥٪ ضريبة — والنسبةُ من إعدادات المتجر لا من الطلب
        $this->assertSame(105.0, (float) CustomerInvoice::first()->total);
    }

    public function test_the_server_recomputes_and_ignores_a_tampered_total(): void
    {
        /*
         * من يفتح أدوات المتصفّح يستطيع إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة —
         * فتُرحَّل ذمّةٌ لا تساوي ورقتَها.
         */
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'total' => 1, 'subtotal' => 1, 'tax_total' => 999,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(105.0, (float) CustomerInvoice::first()->total);
        $this->assertSame(5.0, (float) CustomerInvoice::first()->tax_total);
    }

    public function test_a_customer_from_another_shop_is_refused_at_the_door(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'customer_id' => $theirs->id,
        ]))->assertSessionHasErrors('customer_id');

        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_a_product_from_another_shop_is_refused_at_the_door(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create(['business_id' => $other->id, 'name' => 'صنفهم', 'price' => 5, 'cost' => 2, 'quantity' => 1]);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'items' => [['product_id' => $theirs->id, 'description' => 'صنفهم', 'quantity' => 1, 'unit_price' => 5]],
        ])->assertSessionHasErrors('items');
    }

    public function test_another_shop_cannot_read_this_invoice(): void
    {
        $this->actingAs($this->owner);
        $invoice = CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ]);

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        Currency::create([
            'business_id' => $other->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'الجار', 'email' => 'j@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get('/admin/customer-invoices/'.$invoice->id)->assertNotFound();

        /*
         * والقراءةُ ليست وحدها: من يُمنع من الرؤية يجب أن يُمنع من الكتابة.
         * والمقياسُ ما وقع بالفاتورة لا رقمُ الاستجابة — بابٌ يردّ بتحويلٍ
         * يحمي كما يحمي بابٌ يردّ بـ404.
         */
        $this->actingAs($stranger)->post('/admin/customer-invoices/'.$invoice->id.'/issue');
        $this->assertSame(CustomerInvoice::DRAFT, $invoice->fresh()->status);

        $this->actingAs($stranger)->post('/admin/customer-invoices/'.$invoice->id.'/cancel', ['reason' => 'ألغيها']);
        $this->assertSame(CustomerInvoice::DRAFT, $invoice->fresh()->status);
    }

    public function test_an_issued_invoice_is_not_hard_deleted(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ]), $this->owner->id);

        $this->actingAs($this->owner)
            ->post('/admin/customer-invoices/'.$invoice->id.'/cancel', ['reason' => 'خطأ'])
            ->assertSessionHasNoErrors();

        // ولا يُمحى مستندٌ ماليٌّ صدر: يبقى مقروءًا موسومًا
        $this->assertDatabaseHas('customer_invoices', [
            'id' => $invoice->id, 'status' => CustomerInvoice::CANCELLED,
        ]);
    }

    public function test_cancelling_needs_a_written_reason(): void
    {
        $invoice = CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ]);

        $this->actingAs($this->owner)->post('/admin/customer-invoices/'.$invoice->id.'/cancel', [])
            ->assertSessionHasErrors('reason');
    }

    public function test_the_pdf_opens(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'توريد وتنسيق زهور لفعالية رسمية', 'quantity' => 1, 'unit_price' => 500],
        ]), $this->owner->id);

        $this->actingAs($this->owner)->get('/admin/customer-invoices/'.$invoice->id.'/pdf')->assertOk();
    }

    public function test_the_receivables_screen_and_statement_open(): void
    {
        CustomerInvoices::issue(CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ]), $this->owner->id);

        $this->actingAs($this->owner)->get('/admin/finance/receivables')
            ->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Finance/Receivables')
            ->where('reconciliation.balanced', true));

        $this->actingAs($this->owner)
            ->get('/admin/finance/receivables/'.$this->customer->id.'/statement')->assertOk();
        $this->actingAs($this->owner)
            ->get('/admin/finance/receivables/'.$this->customer->id.'/statement/pdf')->assertOk();
    }

    public function test_a_cashier_cannot_open_the_invoices_screen(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get('/admin/customer-invoices')->assertForbidden();
    }

    public function test_credit_settings_are_written_through_one_door(): void
    {
        $this->actingAs($this->owner)->put(route('admin.finance.customerCredit', $this->customer->id), [
            'allow_credit_sales' => true, 'credit_limit' => 500, 'payment_terms_days' => 45,
        ])->assertSessionHasNoErrors();

        $this->assertSame(45, (int) $this->customer->fresh()->payment_terms_days);
        $this->assertSame(500.0, (float) $this->customer->fresh()->credit_limit);
    }

    public function test_a_payment_reports_where_it_went(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 100],
        ]), $this->owner->id);

        // توزيعٌ صامت يجعل التاجر يكتشف بعد شهرٍ أنّ دفعتَه سدّت غيرَ ما قصد
        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id, 'amount' => 40, 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString($invoice->number, session('toast')['msg']);
    }
}
