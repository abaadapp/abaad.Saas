<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteOrder;
use App\Models\FinancialCorrection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\FinancialCorrections;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إشعارُ إرجاعٍ يسمّي طلبه (#285) وهو ينتظر الاعتماد الثنائيّ (#196).
 *
 * الطلبُ المسمّى يُحمل في طلب الاعتماد، ويُسأل عن سعته عند الطلب وعند الاعتماد، ويُسمّى
 * عند الإصدار — فلا يضيع الاسمُ بين الطلب والاعتماد، ولا يُسمّى عن طلبٍ امتلأ في الأثناء.
 */
class AnApprovedReturnNamesItsOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $approver;

    private Customer $company;

    private Product $roses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Business::create(['name' => 'زهور الاعتماد والتسمية', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@approved-return.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->approver = User::create(['business_id' => $this->shop->id, 'name' => 'المحاسب', 'email' => 'acc@approved-return.om', 'password' => bcrypt('x'), 'role' => 'accountant', 'status' => 'نشط',
            'permissions' => ['dashboard', 'orders', Permissions::FINANCIAL_CORRECTION_APPROVE]]);
        $this->roses = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true]);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة الاعتماد', 'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30]);
        $this->actingAs($this->owner);
    }

    private function sell(int $qty): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => [['id' => $this->roses->id, 'name' => 'باقة ورد', 'qty' => $qty, 'price' => 10]],
            'payment_method' => 'نقدي', 'credit' => true, 'customer_id' => $this->company->id,
        ])->assertOk();

        return Order::latest('id')->first();
    }

    public function test_a_named_return_waits_then_is_issued_and_named_once(): void
    {
        [$a, $b] = [$this->sell(8), $this->sell(12)];
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => FinancialCorrections::SETTING, 'value' => '1']);

        $this->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => 50, 'tax_amount' => 0, 'reason' => 'رُدّت خمس باقات', 'basis' => CustomerCreditNote::RETURN, 'token' => 'ret-1', 'order_id' => $a->id,
        ])->assertSessionHasNoErrors();

        $row = FinancialCorrection::sole();
        $this->assertSame([(int) $a->id, 0, 0], [(int) $row->payload['order_id'], CustomerCreditNote::count(), CustomerCreditNoteOrder::count()]);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertSessionHasNoErrors();

        $note = CustomerCreditNote::sole();
        $naming = CustomerCreditNoteOrder::sole();
        $this->assertSame([(int) $note->id, (int) $a->id, CustomerCreditNoteOrder::AT_ISSUE], [(int) $naming->customer_credit_note_id, (int) $naming->order_id, $naming->mode]);
    }

    public function test_an_order_filled_while_the_request_waited_is_not_named_past_its_total(): void
    {
        [$a, $b] = [$this->sell(8), $this->sell(12)];
        $invoice = CustomerInvoices::consolidate($this->company, [$a->id, $b->id], [], $this->owner->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => FinancialCorrections::SETTING, 'value' => '1']);

        // طلبٌ يسمّي خمسين عن «أ» (ثمانون) — ثمّ يملأ إشعارٌ بخمسين قبل الاعتماد ما بقي منه
        $this->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => 50, 'tax_amount' => 0, 'reason' => 'رُدّت خمس باقات', 'basis' => CustomerCreditNote::RETURN, 'token' => 'ret-2', 'order_id' => $a->id,
        ])->assertSessionHasNoErrors();
        $row = FinancialCorrection::sole();
        $row->forceFill(['status' => FinancialCorrection::WITHDRAWN, 'decided_by' => $this->owner->id, 'decided_at' => now()])->save();
        Setting::where('business_id', $this->shop->id)->where('key', FinancialCorrections::SETTING)->update(['value' => '0']);
        $this->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => 50, 'tax_amount' => 0, 'reason' => 'إرجاعٌ آخر', 'basis' => CustomerCreditNote::RETURN, 'token' => 'ret-3', 'order_id' => $a->id,
        ])->assertSessionHasNoErrors();

        // ثمّ يُطلب ثانيةً بالاعتماد: لا يسعه «أ» (بقي ثلاثون) — يُردّ عند الطلب
        Setting::where('business_id', $this->shop->id)->where('key', FinancialCorrections::SETTING)->update(['value' => '1']);
        $this->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => 50, 'tax_amount' => 0, 'reason' => 'إرجاعٌ ثالث', 'basis' => CustomerCreditNote::RETURN, 'token' => 'ret-4', 'order_id' => $a->id,
        ])->assertSessionHasErrors();

        $this->assertSame(1, FinancialCorrection::count(), 'كُتب طلبٌ لا يسعه طلبُه');
        $this->assertSame(1, CustomerCreditNoteOrder::count());
    }
}
