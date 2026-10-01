<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * الذمّةُ التشغيليّة ورصيدُ الدفتر رقمٌ واحد — وإلّا فأحدُهما كاذب.
 */
class ACustomerOwesAndTheBooksAgreeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $ministry;

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
        $this->ministry = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
            'customer_type' => 'جهة حكومية', 'allow_credit_sales' => true, 'payment_terms_days' => 30,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $this->actingAs($this->owner);
    }

    private function govInvoice(float $price = 500): CustomerInvoice
    {
        return CustomerInvoices::create($this->business->id, $this->ministry, [
            'po_number' => 'PO-2026-774',
        ], [
            ['description' => 'توريد وتنسيق زهور لفعالية رسمية', 'quantity' => 1, 'unit_price' => $price],
        ], $this->owner->id);
    }

    // ————— السيناريو (أ): فاتورةٌ حكوميّةٌ يدويّة —————

    public function test_a_free_text_invoice_computes_its_own_totals(): void
    {
        $invoice = $this->govInvoice();

        $this->assertSame(500.0, (float) $invoice->subtotal);
        $this->assertSame(25.0, (float) $invoice->tax_total);
        $this->assertSame(525.0, (float) $invoice->total);
        $this->assertSame('PO-2026-774', $invoice->po_number);
        // وتاريخُ الاستحقاق من شروط سداد العميل — ثلاثون يومًا
        $this->assertSame(30, (int) $invoice->issued_at->diffInDays($invoice->due_at));
    }

    public function test_a_draft_posts_nothing(): void
    {
        $this->govInvoice();

        // المسودّةُ ليست التزامًا: لا ذمّة ولا إيراد حتّى تُصدَر
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'sales'));
    }

    public function test_issuing_posts_receivable_sales_and_tax(): void
    {
        CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        $this->assertSame(525.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(500.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(25.0, Ledger::balance($this->business->id, 'tax_payable'));
    }

    public function test_issuing_twice_does_not_post_twice(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);
        CustomerInvoices::issue($invoice, $this->owner->id);

        $this->assertSame(525.0, Ledger::balance($this->business->id, 'receivable'));
    }

    public function test_tax_disabled_issues_a_clean_invoice(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '0']);

        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        $this->assertSame(0.0, (float) $invoice->tax_total);
        $this->assertSame(500.0, (float) $invoice->total);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'tax_payable'));
    }

    public function test_an_issued_invoice_is_not_rewritten_by_a_later_tax_change(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        Setting::where('business_id', $this->business->id)->where('key', 'vat_rate')->update(['value' => '15']);

        // لقطةُ الضريبة في السطر — لا تُقرأ من الإعدادات كلَّ مرّة
        $this->assertSame(525.0, (float) $invoice->fresh()->total);
        $this->assertSame(5.0, (float) $invoice->items()->first()->tax_rate);
    }

    // ————— السيناريو (ب): سدادٌ جزئيّ —————

    public function test_a_partial_payment_leaves_the_right_outstanding(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        CustomerPayments::record($this->business->id, $this->ministry, 200, ['method' => 'تحويل'],
            [$invoice->id => 200], $this->owner->id);

        $this->assertSame(325.0, $invoice->fresh()->outstanding());
        $this->assertSame('مدفوعة جزئيًا', $invoice->fresh()->paymentState());
        $this->assertSame(200.0, Ledger::balance($this->business->id, 'bank'));
        $this->assertSame(325.0, Ledger::balance($this->business->id, 'receivable'));
    }

    public function test_many_partial_payments_close_the_invoice(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        foreach ([200, 200, 125] as $amount) {
            CustomerPayments::record($this->business->id, $this->ministry, $amount, ['method' => 'نقدي'],
                [$invoice->id => $amount], $this->owner->id);
        }

        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame('مدفوعة', $invoice->fresh()->paymentState());
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
    }

    public function test_an_overpayment_is_kept_not_lost(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        $payment = CustomerPayments::record($this->business->id, $this->ministry, 600, ['method' => 'نقدي'],
            [], $this->owner->id);

        // ولا تصير الفاتورةُ سالبة، ولا يضيع الفارق
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(75.0, $payment->fresh()->unallocated());
        $this->assertSame(75.0, Receivables::customerCredit($this->business->id, $this->ministry->id));
    }

    public function test_one_payment_covers_three_invoices_oldest_first(): void
    {
        $a = CustomerInvoices::issue($this->govInvoice(80), $this->owner->id);
        $b = CustomerInvoices::issue($this->govInvoice(120), $this->owner->id);
        $c = CustomerInvoices::issue($this->govInvoice(50), $this->owner->id);

        CustomerPayments::record($this->business->id, $this->ministry, 200, ['method' => 'تحويل'], [], $this->owner->id);

        // ٨٤ + ١٢٦ + ٥٢٫٥ بالضريبة. و٢٠٠ تسدّ الأولى كاملةً ثمّ ١١٦ من الثانية
        $this->assertSame(0.0, $a->fresh()->outstanding());
        $this->assertSame(10.0, $b->fresh()->outstanding());
        // ولا تقفز إلى الثالثة قبل أن تُغلق الثانية — الأقدمُ أوّلًا
        $this->assertSame(52.5, $c->fresh()->outstanding());
    }

    public function test_cancelling_a_payment_reverses_the_books(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);
        $payment = CustomerPayments::record($this->business->id, $this->ministry, 200, ['method' => 'نقدي'],
            [$invoice->id => 200], $this->owner->id);

        CustomerPayments::cancel($payment, 'شيك مرتجع', $this->owner->id);

        $this->assertSame(525.0, $invoice->fresh()->outstanding());
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
        // والصفُّ باقٍ مقروءًا — تاريخُ المال لا يُمحى
        $this->assertNotNull($payment->fresh()->cancelled_at);
        $this->assertSame('شيك مرتجع', $payment->fresh()->cancellation_reason);

        /*
         * والتخصيصُ باقٍ معه: «أين ذهبت دفعةُ الخميس؟» سؤالٌ يُسأل بعد شهر،
         * وحذفُ صفوفه يمحو الجواب. والاستبعادُ بقاعدةٍ واحدة لا بمحو.
         */
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertSame(0.0, $invoice->fresh()->paidTotal());
    }

    // ————— الإلغاء والإرجاع —————

    public function test_cancelling_an_unpaid_invoice_reverses_it(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);

        CustomerInvoices::cancel($invoice, 'أُلغيت الفعالية', $this->owner->id);

        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
        // ولا تُمحى: تبقى مقروءةً موسومة
        $this->assertDatabaseHas('customer_invoices', ['id' => $invoice->id]);
    }

    public function test_a_partly_paid_invoice_is_not_silently_cancelled(): void
    {
        $invoice = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);
        CustomerPayments::record($this->business->id, $this->ministry, 200, ['method' => 'نقدي'],
            [$invoice->id => 200], $this->owner->id);

        // والنصُّ يُترجَم تحت `locale=en` — فيُؤكَّد الأثرُ لا العبارة
        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::cancel($invoice, 'شطب', $this->owner->id);
        } finally {
            $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
        }
    }

    public function test_a_return_reduces_the_debt_without_rewriting_the_invoice(): void
    {
        // فاتورةٌ بمئة، سُدِّد ثلاثون، رُدّ عشرون → الباقي خمسون لا سبعون
        $invoice = CustomerInvoices::create($this->business->id, $this->ministry, [], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
        ], $this->owner->id);
        CustomerInvoices::issue($invoice, $this->owner->id);

        CustomerPayments::record($this->business->id, $this->ministry, 30, ['method' => 'نقدي'],
            [$invoice->id => 30], $this->owner->id);
        CustomerInvoices::creditNote($invoice, 20, 0, 'مرتجع', $this->owner->id);

        $this->assertSame(50.0, $invoice->fresh()->outstanding());
        // والإجماليُّ كما صدر — الورقةُ في يد الزبون لم تتغيّر
        $this->assertSame(100.0, (float) $invoice->fresh()->total);
        $this->assertSame(20.0, Ledger::balance($this->business->id, 'sales_returns'));
    }

    // ————— المطابقة —————

    public function test_the_operational_ledger_reconciles_with_the_general_ledger(): void
    {
        $a = CustomerInvoices::issue($this->govInvoice(500), $this->owner->id);
        CustomerInvoices::issue($this->govInvoice(300), $this->owner->id);
        CustomerPayments::record($this->business->id, $this->ministry, 200, ['method' => 'تحويل'],
            [$a->id => 200], $this->owner->id);
        CustomerInvoices::creditNote($a, 50, 0, 'مرتجع', $this->owner->id);

        $r = Receivables::reconcile($this->business->id);

        $this->assertTrue($r['balanced'], "التشغيلي {$r['operational']} والدفتر {$r['ledger']}");
    }

    // ————— الأعمار —————

    public function test_aging_reads_the_due_date_not_a_stored_state(): void
    {
        $old = CustomerInvoices::issue($this->govInvoice(100), $this->owner->id);
        $old->update(['due_at' => now()->subDays(45)->toDateString()]);

        $soon = CustomerInvoices::issue($this->govInvoice(200), $this->owner->id);
        $soon->update(['due_at' => now()->addDays(3)->toDateString()]);

        $aging = Receivables::aging($this->business->id);
        $buckets = collect($aging['buckets'])->keyBy('key');

        $this->assertSame(105.0, $buckets['31_60']['amount']);
        $this->assertSame(210.0, $buckets['not_due']['amount']);
        $this->assertSame(0.0, $buckets['1_30']['amount']);
    }

    public function test_a_cancelled_invoice_leaves_the_receivable(): void
    {
        $a = CustomerInvoices::issue($this->govInvoice(100), $this->owner->id);
        CustomerInvoices::issue($this->govInvoice(200), $this->owner->id);
        CustomerInvoices::cancel($a, 'خطأ في الإدخال', $this->owner->id);

        $this->assertSame(210.0, Receivables::totals($this->business->id)['total']);
        // ولا من الفاتورة نفسها: ملغاةٌ لا يُطالَب بها أحد
        $this->assertSame(0.0, $a->fresh()->outstanding());
    }

    // ————— التعدّد المستأجَر —————

    public function test_a_customer_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::create($this->business->id, $theirs, [], [
                ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
            ], $this->owner->id);
        } finally {
            $this->assertSame(0, CustomerInvoice::count());
        }
    }

    public function test_a_product_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create(['business_id' => $other->id, 'name' => 'صنفهم', 'price' => 5, 'cost' => 2, 'quantity' => 3]);

        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::create($this->business->id, $this->ministry, [], [
                ['product_id' => $theirs->id, 'description' => 'صنفهم', 'quantity' => 1, 'unit_price' => 5],
            ], $this->owner->id);
        } finally {
            $this->assertSame(0, CustomerInvoice::count());
        }
    }

    public function test_a_bank_account_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = BankAccount::create([
            'business_id' => $other->id, 'bank_name' => 'بنك الجار', 'account_name' => 'حسابهم',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            CustomerPayments::record($this->business->id, $this->ministry, 10,
                ['method' => 'تحويل', 'bank_account_id' => $theirs->id], [], $this->owner->id);
        } finally {
            $this->assertSame(0, CustomerPayment::count());
        }
    }

    public function test_a_payment_cannot_be_allocated_to_another_shops_invoice(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirCustomer = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);
        $theirInvoice = CustomerInvoices::issue(CustomerInvoices::create($other->id, $theirCustomer, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 90, 'tax_rate' => 0],
        ]), null);

        $payment = CustomerPayments::record($this->business->id, $this->ministry, 90, ['method' => 'نقدي'],
            [$theirInvoice->id => 90], $this->owner->id);

        // المال لا يضيع ولا يعبر الجدار: يبقى غيرَ مخصَّص
        $this->assertSame(90.0, $payment->fresh()->unallocated());
        $this->assertSame(90.0, $theirInvoice->fresh()->outstanding());
    }

    // ————— الترقيم —————

    /**
     * الترقيمُ متسلسلٌ في المتجر، مستقلٌّ بين المتاجر — ويقع عند الإصدار.
     *
     * وكان يقع عند الإنشاء، فمسودّةٌ تُهجر تأخذ رقمَها معها ويقفز التسلسل
     * في دفترٍ يُقرأ عند الضريبة.
     */
    public function test_numbers_are_sequential_per_shop_and_independent_between_shops(): void
    {
        $this->assertSame('CINV-000001', CustomerInvoices::issue($this->govInvoice(), $this->owner->id)->number);
        $this->assertSame('CINV-000002', CustomerInvoices::issue($this->govInvoice(), $this->owner->id)->number);

        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->assertSame('CINV-000001', CustomerInvoices::issue(CustomerInvoices::create($other->id, $theirs, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 5],
        ]))->number);
    }

    public function test_a_cancelled_number_is_not_reused(): void
    {
        $first = CustomerInvoices::issue($this->govInvoice(), $this->owner->id);
        CustomerInvoices::cancel($first, 'خطأ', $this->owner->id);

        $this->assertSame('CINV-000002', CustomerInvoices::issue($this->govInvoice(), $this->owner->id)->number);
    }
}
