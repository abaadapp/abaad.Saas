<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ إنشاء الفاتورة — بابُها ومدخلاتُها وما يقع خلفها.
 *
 * وكان النموذج لوحةً تنطوي فوق الجدول: نصفُ حقوله خلف زرّ، ولا موضعَ فيه
 * لضريبةِ بندٍ ولا لطريقة سداد. وما لا يسع الشاشةَ لا يُملأ أبدًا.
 */
class TheInvoiceFormIsAScreenNotAPanelTest extends TestCase
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
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
            'customer_type' => 'جهة حكومية', 'allow_credit_sales' => true, 'payment_terms_days' => 45,
        ]);
    }

    /* ------------------------------ البابُ ------------------------------ */

    /**
     * الشاشةُ تُفتح ومعها ما تحتاجه: العملاءُ بشروط سدادهم، والكتالوج،
     * ونسبةُ الضريبة، ورقمُ الفاتورة معاينةً.
     *
     * وأنّها لا تُقرأ معرّفَ فاتورةٍ يحرسه `Route::pattern('id', '[0-9]+')`
     * لا ترتيبُ التسجيل — أثبتَ ذلك أنّ تأخيرَها خلف `{id}` لم يكسر شيئًا.
     */
    public function test_the_create_screen_opens_and_is_not_read_as_an_invoice_id(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 12, 'cost' => 5, 'quantity' => 3,
        ]);

        $this->actingAs($this->owner)->get('/admin/customer-invoices/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/CustomerInvoices/Create')
                ->where('customers.0.name', 'وزارة الثقافة')
                ->where('customers.0.payment_terms_days', 45)
                ->where('products.0.name', 'باقة ورد')
                ->where('tax_rate', 5)
                ->has('next_number'));
    }

    /** ومن لا يملك المبيعات لا يفتحها */
    public function test_a_cashier_cannot_open_the_create_screen(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get('/admin/customer-invoices/create')->assertForbidden();
    }

    /* --------------------------- ضريبةُ البند --------------------------- */

    /**
     * نسبةُ البند تُكتب في السطر — والإعفاءُ صفرٌ مقصود.
     *
     * فاتورةُ جهةٍ فيها توريدٌ خاضع وخدمةٌ معفاة، ونسبةٌ واحدةٌ لكلّ الورقة
     * تجعل التاجر يفصلها ورقتين أو يدفع ضريبةً عن معفًى.
     */
    public function test_a_line_carries_its_own_tax_rate(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5],
                ['description' => 'خدمة معفاة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame(5.0, (float) $invoice->tax_total);
        $this->assertSame(205.0, (float) $invoice->total);
    }

    /**
     * ونسبةٌ لم تُرسَل تسقط إلى نسبة المتجر — لا إلى صفر.
     *
     * `compute` تقرأ **وجود** المفتاح لا قيمتَه، لأنّ الصفر إعفاء. فبندٌ يصل
     * بـ`tax_rate: null` — وهو ما ترسله واجهةٌ لم تُملأ خانتُها — كان يُعفى
     * في صمت، فتخرج فاتورةُ جهةٍ حكوميّة بلا ضريبةٍ ولا شيء يقول لماذا.
     */
    public function test_a_missing_line_rate_falls_back_to_the_shop_rate_not_to_zero(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => null]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(5.0, (float) CustomerInvoice::firstOrFail()->tax_total);
    }

    /* --------------------------- طريقةُ السداد --------------------------- */

    /**
     * فاتورةٌ تُسدَّد لحظةَ إصدارها تُسجَّل إيصالًا — لا وسمًا في عمود.
     *
     * مسارٌ ثانٍ للسداد يعني مالًا في الصندوق لا يعرف به الدفتر، وفاتورةً
     * تقول مدفوعةً بلا إيصالٍ يقابلها.
     */
    public function test_a_paid_method_records_a_receipt_and_leaves_nothing_outstanding(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => 'نقدي',
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();
        $payment = CustomerPayment::firstOrFail();

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame('نقدي', $payment->method);
        $this->assertSame(105.0, (float) $payment->amount);
        $this->assertSame(105.0, $invoice->paidTotal());
        $this->assertSame(0.0, $invoice->outstanding());
    }

    /** و«آجل» لا يُسجَّل لها إيصال — الذمّةُ هي الحاصل */
    public function test_credit_leaves_the_amount_outstanding_and_writes_no_receipt(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => 'آجل',
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(105.0, CustomerInvoice::firstOrFail()->outstanding());
    }

    /**
     * ولا تحصيلَ على مسودّة.
     *
     * التخصيصُ يقع على فاتورةٍ صادرة، فلو قُبل هنا لبقي المبلغ معلّقًا بلا ما
     * يقابله — أو ذهب في التوزيع التلقائيّ إلى فاتورةٍ أخرى لم يقصدها أحد.
     */
    public function test_a_paid_method_is_refused_on_a_draft(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'payment_method' => 'بطاقة',
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(0, CustomerPayment::count());
    }

    /** وطريقةٌ ليست من المعروف تُردّ عند الباب */
    public function test_an_unknown_method_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => 'ذهب',
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSessionHasErrors('payment_method');
    }

    /* ------------------------- عميلٌ من الشاشة ------------------------- */

    /**
     * عميلٌ جديد يُضاف ولا تُغادَر الصفحة — ويُقال أيُّهم.
     *
     * من كتب خمسةَ بنودٍ ثمّ اكتشف أنّ الجهة ليست مسجَّلة كان يفقد ما كتب.
     */
    public function test_a_customer_is_added_from_the_invoice_screen_and_comes_back_named(): void
    {
        $this->actingAs($this->owner)
            ->from('/admin/customer-invoices/create')
            ->post('/admin/customer-invoices/customers', [
                'name' => 'شركة الزهور الحديثة',
                'customer_type' => 'شركة',
                'phone' => '91234567',
                'tax_number' => '123456789',
            ])
            ->assertRedirect('/admin/customer-invoices/create')
            ->assertSessionHasNoErrors();

        $fresh = Customer::where('name', 'شركة الزهور الحديثة')->firstOrFail();

        $this->assertSame($this->business->id, (int) $fresh->business_id);
        $this->assertSame('شركة', $fresh->customer_type);
        // ويصل معرّفُه إلى الشاشة كي يُختار وحده
        $this->assertSame($fresh->id, session('new_customer_id'));
    }

    /** ولا عميلَ بلا اسم */
    public function test_a_nameless_customer_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices/customers', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Customer::count());
    }

    /** والكاشير لا يفتح هذا الباب أيضًا */
    public function test_a_cashier_cannot_add_a_customer_through_this_door(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'k@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->post('/admin/customer-invoices/customers', ['name' => 'جهة'])
            ->assertForbidden();

        $this->assertSame(1, Customer::count());
    }

    /* --------------------------- الأصلُ محفوظ --------------------------- */

    /** والإجماليُّ ما زال يُحسب في الخادم مهما أُرسل من الواجهة */
    public function test_the_server_still_recomputes_the_totals(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'total' => 1, 'subtotal' => 1, 'tax_total' => 999,
            'items' => [['description' => 'توريد', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(105.0, (float) CustomerInvoice::firstOrFail()->total);
    }

    /** ومدّةُ السداد المرسلة تُحسب منها مدّةُ الاستحقاق */
    public function test_the_due_date_follows_the_terms_that_were_sent(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', [
            'customer_id' => $this->customer->id,
            'issued_at' => '2026-09-01',
            'payment_terms_days' => 60,
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame('2026-10-31', $invoice->due_at->toDateString());
        $this->assertSame(60, (int) $invoice->payment_terms_days);
    }
}
