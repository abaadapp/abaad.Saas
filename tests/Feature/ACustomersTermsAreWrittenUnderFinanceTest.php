<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * شروطُ سداد العميل — مدّتُه وفوترتُه الشهريّة — تُكتب من كشف حسابه.
 *
 * ولا إذنَ آجلٍ ولا حدَّ ائتمانٍ بعد اليوم (انظر `CreditSales`): العمودان
 * رُفعا، والفعلُ `credit.override` رُفع معهما. والبابُ تحت «المالية» كشاشته.
 */
class ACustomersTermsAreWrittenUnderFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'phone' => '96890000000']);
    }

    private function staff(string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => $role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    public function test_the_owner_saves_terms_and_monthly_billing(): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.finance.customerTerms', $this->customer->id), ['payment_terms_days' => 30, 'monthly_billing' => true])
            ->assertSessionHasNoErrors();

        $fresh = $this->customer->fresh();
        $this->assertSame(30, (int) $fresh->payment_terms_days);
        $this->assertTrue((bool) $fresh->monthly_billing);
    }

    public function test_the_screen_and_its_save_ask_for_the_same_section(): void
    {
        $this->assertSame('finance', Permissions::sectionFromRoute('admin.finance.customerStatement'));
        $this->assertSame('finance', Permissions::sectionFromRoute('admin.finance.customerTerms'));
    }

    public function test_a_seller_without_finance_cannot_write_terms(): void
    {
        $this->actingAs($this->staff('sales'))
            ->put(route('admin.finance.customerTerms', $this->customer->id), ['payment_terms_days' => 90])
            ->assertForbidden();

        $this->assertNull($this->customer->fresh()->payment_terms_days);
    }

    public function test_whoever_opens_the_statement_saves_its_terms(): void
    {
        $keeper = $this->staff('accountant', ['finance']);

        $this->actingAs($keeper)->get(route('admin.finance.customerStatement', $this->customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->missing('customer.allow_credit_sales')
                ->missing('customer.credit_limit')
                ->missing('summary.headroom')
                ->missing('may.credit'));

        $this->actingAs($keeper)
            ->put(route('admin.finance.customerTerms', $this->customer->id), ['payment_terms_days' => 15])
            ->assertSessionHasNoErrors();
        $this->assertSame(15, (int) $this->customer->fresh()->payment_terms_days);
    }

    /** والفاتورةُ اليدويّة «آجل» لعميلٍ جديدٍ بقيمه الافتراضيّة — ذمّةٌ بلا إذن */
    public function test_a_manual_credit_invoice_for_a_default_customer_owes(): void
    {
        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->missing('customers.0.allow_credit_sales'));

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'issue' => true,
            'issued_at' => now()->toDateString(),
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame(400.0, $invoice->outstanding());
        $this->assertSame(400.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /** والعمودان والفعلُ رُفعوا — والشروطُ الباقيةُ باقية */
    public function test_the_credit_gate_is_gone_and_the_terms_remain(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'allow_credit_sales'));
        $this->assertFalse(Schema::hasColumn('customers', 'credit_limit'));
        $this->assertTrue(Schema::hasColumn('customers', 'payment_terms_days'));
        $this->assertTrue(Schema::hasColumn('customers', 'monthly_billing'));

        $this->assertArrayNotHasKey('credit.override', Permissions::ACTIONS);
        $this->assertFalse(Route::has('admin.finance.customerCredit'));
    }
}
