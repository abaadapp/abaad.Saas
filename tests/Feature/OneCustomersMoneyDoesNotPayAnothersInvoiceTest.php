<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مالُ عميلٍ لا يسدّد فاتورةَ عميلٍ آخر.
 *
 * ═══ العطب ═══
 *
 * التحصيلُ يحمل `customer_id` و`customer_invoice_id` كليهما من الطلب،
 * و`CustomerPayments::allocate` كانت تقفل الفاتورةَ بمتجرها وحدَه ولا تسأل
 * لمن هي. فطلبٌ يقول «من الوزارة، على فاتورة الشركة» كان يمرّ: تُوسَم
 * فاتورةُ الشركة مسدَّدةً بمالٍ لم تدفعه، ويُكتب للوزارة رصيدٌ دائن لم
 * تقصده — وكشفا الحسابين يكذبان معًا.
 *
 * ═══ ومعه: ضريبةُ إشعار الدائن لا تفوق مبلغَه ═══
 *
 * كان يُقبل «مبلغ ٥ منه ضريبة ٦» فيسقط على ميزان الدفتر برسالة «القيد لا
 * يتوازن» — صحيحةٌ ولا تدلّ التاجر على الحقل الذي أخطأ فيه.
 */
class OneCustomersMoneyDoesNotPayAnothersInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $ministry;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->ministry = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة التراث', 'phone' => '90000001']);
        $this->company = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الخليج', 'phone' => '90000002']);
    }

    private function issued(Customer $customer, float $price = 60): CustomerInvoice
    {
        $invoice = CustomerInvoices::create(
            $this->business->id, $customer, [],
            [['description' => 'باقة', 'quantity' => 1, 'unit_price' => $price]],
            $this->owner->id,
        );

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    public function test_a_payment_named_for_one_customer_is_not_allocated_to_anothers_invoice(): void
    {
        $companys = $this->issued($this->company);

        $this->actingAs($this->owner)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->ministry->id, 'customer_invoice_id' => $companys->id,
            'amount' => $companys->total, 'method' => 'نقدي',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, CustomerPaymentAllocation::count(), 'خُصّص مالُ الوزارة على فاتورة الشركة');
        $this->assertSame(0, CustomerPayment::count(), 'كُتب تحصيلٌ لم يُقبل');
        $this->assertSame((float) $companys->total, $companys->fresh()->outstanding(), 'فاتورةُ الشركة سُدِّدت بمالٍ لم تدفعه');
    }

    public function test_the_same_customers_invoice_is_still_paid(): void
    {
        $ministrys = $this->issued($this->ministry);

        $this->actingAs($this->owner)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->ministry->id, 'customer_invoice_id' => $ministrys->id,
            'amount' => $ministrys->total, 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $ministrys->fresh()->outstanding());
    }

    public function test_a_credit_notes_tax_cannot_exceed_its_amount(): void
    {
        $invoice = $this->issued($this->ministry);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => 5, 'tax_amount' => 6, 'reason' => 'رُدّت قطعة',
        ])->assertSessionHasErrors('tax_amount');

        $this->assertSame(0.0, $invoice->fresh()->creditedTotal());
    }
}
