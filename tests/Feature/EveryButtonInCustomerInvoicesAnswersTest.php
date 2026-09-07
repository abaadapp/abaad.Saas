<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كلُّ ضغطةٍ في قسم فواتير العملاء تصل إلى جواب.
 *
 * وبابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض: زرٌّ يردّ ٤٠٤ أو ٥٠٠ يجعل
 * التاجر يظنّ العطبَ في بياناته فيعيد كتابتَها. والمسارُ الذي لا زرَّ له
 * ميزةٌ لا يعرف بها أحد.
 *
 * ولا تُفحص العناوينُ وحدها: الضغطةُ تُتبَع بأثرها — أصدرت؟ حصّلت؟ ألغت؟
 */
class EveryButtonInCustomerInvoicesAnswersTest extends TestCase
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
            'phone' => '91234567', 'allow_credit_sales' => true,
        ]);
    }

    private function invoice(bool $issued = true): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ], $this->owner->id);

        return $issued ? CustomerInvoices::issue($invoice, $this->owner->id) : $invoice;
    }

    /* ------------------------- كلُّ عنوانٍ يُفتح ------------------------- */

    /**
     * الشاشاتُ الأربع تُفتح — ولا واحدةٌ منها تُخرج خطأ خادم.
     *
     * وتُقرأ من جدول المسارات لا من قائمةٍ تُكتب باليد: قائمةٌ تُكتب باليد
     * تنسى التاليَ دائمًا، وأوّلُ شاشةٍ تُضاف تبقى خارج الفحص.
     */
    public function test_every_get_route_in_the_section_opens(): void
    {
        $invoice = $this->invoice();

        $bindings = ['id' => $invoice->id, 'customer' => $this->customer->id];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';

            if (! str_starts_with($name, 'admin.customerInvoices.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $params = [];
            foreach ($route->parameterNames() as $p) {
                $params[$p] = $bindings[$p] ?? 1;
            }

            $this->actingAs($this->owner)->get(route($name, $params))
                ->assertOk();
            $checked++;
        }

        // ثلاثة: القائمة، والإنشاء، والعرض — ورابعٌ للـPDF
        $this->assertGreaterThanOrEqual(4, $checked);
    }

    /** وشاشتا الذمم وكشف الحساب معهما — مصدرُ أرقامهما واحد */
    public function test_the_receivables_screens_open(): void
    {
        $this->invoice();

        $this->actingAs($this->owner)->get('/admin/finance/receivables')->assertOk();
        $this->actingAs($this->owner)
            ->get('/admin/finance/receivables/'.$this->customer->id.'/statement')->assertOk();
        $this->actingAs($this->owner)
            ->get('/admin/finance/receivables/'.$this->customer->id.'/statement/pdf')->assertOk();
    }

    /* --------------------- كلُّ زرٍّ يفعل ما يقوله --------------------- */

    /** «إصدار الفاتورة» — يُصدر */
    public function test_the_issue_button_issues(): void
    {
        $invoice = $this->invoice(issued: false);

        $this->actingAs($this->owner)
            ->post('/admin/customer-invoices/'.$invoice->id.'/issue')
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
    }

    /** «تسجيل دفعة» — يُحصّل ويُنقص الباقي */
    public function test_the_pay_button_collects(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 40,
            'method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(40.0, $invoice->fresh()->paidTotal());
        $this->assertSame(65.0, $invoice->fresh()->outstanding());
    }

    /** والتحصيل بتحويلٍ بنكيّ يُقيَّد على الحساب لا على الصندوق */
    public function test_a_transfer_lands_in_the_bank_not_the_till(): void
    {
        $invoice = $this->invoice();
        $account = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك مسقط',
            'account_name' => 'الحساب الرئيسي', 'opening_balance' => 0,
        ]);

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 105,
            'method' => 'تحويل',
            'bank_account_id' => $account->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(105.0, Ledger::balance($this->business->id, 'bank'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
    }

    /** «إلغاء التحصيل» — يُلغي، ويُعيد المبلغ إلى الذمّة، ويُبقي الأثر */
    public function test_the_cancel_payment_button_cancels_and_keeps_the_trail(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 40, 'method' => 'نقدي',
        ]);

        $payment = CustomerPayment::firstOrFail();

        $this->actingAs($this->owner)
            ->post('/admin/customer-payments/'.$payment->id.'/cancel', ['reason' => 'خطأ في المبلغ'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $invoice->fresh()->paidTotal());
        $this->assertSame(105.0, $invoice->fresh()->outstanding());
        // والتخصيصُ يبقى مكتوبًا: مستندٌ أُلغي يُقرأ ولا يُمحى
        $this->assertDatabaseHas('customer_payment_allocations', ['customer_payment_id' => $payment->id]);
    }

    /** «إشعار دائن» — يُنقص المطلوب */
    public function test_the_credit_note_door_reduces_what_is_owed(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', [
            'amount' => 30, 'tax_amount' => 0, 'reason' => 'بضاعة مردودة',
        ])->assertSessionHasNoErrors();

        $this->assertSame(75.0, $invoice->fresh()->outstanding());
    }

    /** «إلغاء الفاتورة» — بسببٍ مكتوب، ولا تُمحى */
    public function test_the_cancel_button_cancels_with_a_reason(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)
            ->post('/admin/customer-invoices/'.$invoice->id.'/cancel', ['reason' => 'أُلغي أمر الشراء'])
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
    }

    /** «تذكير بالسداد» — يُخرج رابطًا يُفتح، لا وعدًا بإرسال */
    public function test_the_remind_button_hands_back_a_link(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)
            ->post('/admin/customer-invoices/'.$invoice->id.'/remind')
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('wa.me/', session('toast')['link']['url']);
        $this->assertStringContainsString($invoice->number, urldecode(session('toast')['link']['url']));
    }

    /** ولا تذكيرَ لفاتورةٍ لا مستحقَّ عليها — الزرُّ نفسُه لا يُرسم */
    public function test_a_settled_invoice_refuses_the_reminder(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 105, 'method' => 'نقدي',
        ]);

        $this->actingAs($this->owner)
            ->post('/admin/customer-invoices/'.$invoice->id.'/remind')
            ->assertSessionHasErrors('remind');
    }

    /* ------------------------ والدفترُ يوافق ------------------------ */

    /**
     * وبعد كلّ هذا يبقى ما تقوله الشاشة هو ما يقوله الدفتر.
     *
     * إصدارٌ وتحصيلٌ وإشعارُ دائنٍ في مسارٍ واحد — وكلٌّ منها له مالكُ ترحيلٍ
     * واحد. فلو رحّل أحدُهم مرّتين لافترق الرقمان هنا.
     */
    public function test_the_screen_and_the_ledger_still_agree_after_every_click(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 40, 'method' => 'نقدي',
        ]);

        $this->actingAs($this->owner)->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', [
            'amount' => 15, 'tax_amount' => 0, 'reason' => 'خصم متأخر',
        ]);

        $this->actingAs($this->owner)->get('/admin/finance/receivables')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Finance/Receivables')
                ->where('reconciliation.balanced', true)
                ->where('totals.total', 50));
    }
}
