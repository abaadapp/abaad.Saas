<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * المالُ يقول أيَّ بنكٍ دخل — لا «بنكًا» وحدها.
 *
 * ═══ العطب ═══
 *
 * `customer_payments.bank_account_id` عمودٌ موجودٌ منذ كُتب الجدول، و`store`
 * و`pay` يقبلانه منذ كُتبا، و`CustomerPayments::record` تتحقّق من ملكيّته.
 * ولم تكن في `resources/js` كلِّها **شاشةٌ واحدة ترسله**.
 *
 * فكلُّ تحصيلٍ ببطاقةٍ أو تحويلٍ يُكتب بحسابٍ فارغ. والدفترُ لا يشكو —
 * `sideFor` تقول «bank» وتكفيه — لكنّ مطابقةَ كشف الحساب (`bank/rematch`)
 * تسأل **أيَّ** بنك: الكشفُ يصل من حسابٍ بعينه، وسطرٌ لا يعرف حسابَه لا يجد
 * ما يُطابقه أبدًا. ومتجرٌ بحسابين يقرأ رصيدين لا يعرف أيُّهما استقبل ماذا.
 *
 * ═══ والاختيارُ في `accountFor` لا في المتحكّم ═══
 *
 * بابان يسجّلان تحصيلًا: نموذجُ الإنشاء بطريقةِ سدادٍ مقبوضة، ونافذةُ
 * التحصيل في صفحة الفاتورة. وقاعدةٌ تُكتب في كلٍّ منهما تفترقُ يومًا، وبابٌ
 * ثالثٌ يُكتب غدًا لا يعرف بهما.
 */
class TheMoneySaysWhichBankItEnteredTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private BankAccount $primary;

    private BankAccount $second;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة التراث', 'phone' => '90000001',
        ]);

        // والرئيسيُّ ليس أوّلَ صفٍّ عمدًا: ترتيبُ الجدول لا يقول أيُّها الرئيسيّ
        $this->second = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك ظفار',
            'label' => 'حساب التشغيل', 'active' => true, 'is_primary' => false,
        ]);
        $this->primary = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك مسقط',
            'label' => 'الحساب الرئيسي', 'active' => true, 'is_primary' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'items' => [['description' => 'تنسيق قاعة', 'quantity' => 1, 'unit_price' => 100]],
        ], $extra);
    }

    private function issued(): CustomerInvoice
    {
        $invoice = CustomerInvoices::create(
            $this->business->id, $this->customer, [],
            [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 60]],
            $this->owner->id,
        );

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    // ───────────────────────── ما تحمله الشاشة ─────────────────────────

    /** والشاشةُ تحمل حسابات المتجر — والرئيسيُّ أوّلها فهو ما تختاره وحدَها */
    public function test_the_create_screen_carries_the_accounts_primary_first(): void
    {
        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('bank_accounts', 2)
                ->where('bank_accounts.0.id', $this->primary->id)
                ->where('bank_accounts.0.is_primary', true)
                ->where('bank_accounts.0.name', 'الحساب الرئيسي')
                ->where('bank_accounts.1.id', $this->second->id)
                ->etc());
    }

    /** ونافذةُ التحصيل في صفحة الفاتورة تسأل السؤال نفسه */
    public function test_the_invoice_screen_carries_the_accounts_too(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('bank_accounts', 2)->etc());
    }

    /** وحسابٌ أُغلق لا يُوجَّه إليه تحصيلٌ جديد */
    public function test_a_closed_account_is_not_offered(): void
    {
        $this->second->update(['active' => false]);

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->has('bank_accounts', 1)
                ->where('bank_accounts.0.id', $this->primary->id)->etc());
    }

    /** وحساباتُ متجرٍ آخر ليست من قائمته */
    public function test_another_shops_accounts_are_not_offered(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        BankAccount::create([
            'business_id' => $other->id, 'bank_name' => 'بنك غريب',
            'active' => true, 'is_primary' => true,
        ]);

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->has('bank_accounts', 2)->etc());
    }

    // ───────────────────── التحصيل من نموذج الإنشاء ─────────────────────

    /** وحسابٌ سُمّي يُكتب كما سُمّي — لا الرئيسيَّ فوقه */
    public function test_a_named_account_is_what_gets_written(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'تحويل', 'bank_account_id' => $this->second->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->second->id, (int) CustomerPayment::firstOrFail()->bank_account_id);
    }

    /**
     * وحين لا يُسمَّى يسقط إلى الرئيسيّ — لا إلى أوّل صفٍّ في الجدول.
     *
     * والصفُّ الأوّل هنا «بنك ظفار» عمدًا، فلو قُرئ الترتيبُ بدل الوسم لمرّ
     * الاختبارُ على الحساب الخطأ.
     */
    public function test_an_unnamed_transfer_falls_back_to_the_primary(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'تحويل',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->primary->id, (int) CustomerPayment::firstOrFail()->bank_account_id);
    }

    /** والبطاقةُ مثلُها: مالٌ يدخل بنكًا فيُقال أيَّ بنك */
    public function test_a_card_payment_names_its_account_too(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'بطاقة',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->primary->id, (int) CustomerPayment::firstOrFail()->bank_account_id);
    }

    /**
     * والنقدُ لا حسابَ بنكيًّا له مهما أُرسل.
     *
     * الشاشةُ تُخفي القائمة عند «نقدي»، لكنّ من بدّل الوسيلةَ بعد اختيار
     * الحساب كان يُرسل الاثنين — فيدخل المالُ الصندوقَ في الدفتر ويحمل اسمَ
     * بنكٍ في الصفّ، ولا يُطابقه كشفُ الحساب أبدًا لأنّه لم يمرّ به.
     */
    public function test_cash_never_carries_a_bank_account(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'نقدي', 'bank_account_id' => $this->primary->id,
        ]))->assertSessionHasNoErrors();

        $this->assertNull(CustomerPayment::firstOrFail()->bank_account_id);
    }

    /** ومتجرٌ بلا حسابٍ مسجَّل لا يُخترع له حساب */
    public function test_a_shop_with_no_accounts_writes_none(): void
    {
        BankAccount::where('business_id', $this->business->id)->delete();

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'تحويل',
        ]))->assertSessionHasNoErrors();

        $this->assertNull(CustomerPayment::firstOrFail()->bank_account_id);
    }

    /**
     * وحسابُ متجرٍ آخر يُردّ — والخطأُ على حقله لا على البنود.
     *
     * `catch (RuntimeException)` في `store` تكتب رسالتَها تحت `items`، فكان
     * التاجرُ يقرأ عطبًا في بنوده وعطبُه في حقلٍ آخر.
     */
    public function test_a_foreign_account_is_refused_on_its_own_field(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = BankAccount::create([
            'business_id' => $other->id, 'bank_name' => 'بنك غريب', 'active' => true,
        ]);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'تحويل', 'bank_account_id' => $stranger->id,
        ]))->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, CustomerPayment::count());
    }

    // ─────────────────── التحصيل من نافذة الفاتورة ───────────────────

    /** والبابُ الثاني يتبع القاعدةَ نفسها */
    public function test_the_pay_door_falls_back_to_the_primary(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id,
            'amount' => 60, 'method' => 'تحويل',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->primary->id, (int) CustomerPayment::firstOrFail()->bank_account_id);
    }

    /** ونقدًا من البابِ الثاني كذلك: لا حساب */
    public function test_the_pay_door_writes_no_account_for_cash(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id,
            'amount' => 60, 'method' => 'نقدي', 'bank_account_id' => $this->primary->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(CustomerPayment::firstOrFail()->bank_account_id);
    }

    /** والشيكُ يدخل بنكًا فيُنسَب إليه — `sideFor` تعدّه بنكيًّا */
    public function test_a_cheque_is_a_bank_side_payment(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id,
            'amount' => 60, 'method' => 'شيك',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->primary->id, (int) CustomerPayment::firstOrFail()->bank_account_id);
    }

    /** وحسابٌ غريبٌ من البابِ الثاني يُردّ كذلك */
    public function test_the_pay_door_refuses_a_foreign_account(): void
    {
        $invoice = $this->issued();
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = BankAccount::create([
            'business_id' => $other->id, 'bank_name' => 'بنك غريب', 'active' => true,
        ]);

        $this->actingAs($this->owner)->post('/admin/customer-payments', [
            'customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id,
            'amount' => 60, 'method' => 'تحويل', 'bank_account_id' => $stranger->id,
        ])->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, CustomerPayment::count());
    }

    /**
     * والملكيّةُ تُتحقَّق في `record` نفسها لا في المتحكّم وحده.
     *
     * `Rule::exists` في البابين يردّ الطلبَ ويكتب الخطأ على حقله — وهو
     * لأجل الرسالة. والحارسُ الذي يمنع الكتابةَ فعلًا هنا: بابٌ ثالثٌ يُكتب
     * غدًا ولا يمرّ على `validate` يمرّ على هذه.
     */
    public function test_record_refuses_a_foreign_account_directly(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = BankAccount::create([
            'business_id' => $other->id, 'bank_name' => 'بنك غريب', 'active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        CustomerPayments::record($this->business->id, $this->customer, 10.0, [
            'method' => 'تحويل', 'bank_account_id' => $stranger->id,
        ]);
    }

    // ───────────────────────── وما لم يتغيّر ─────────────────────────

    /**
     * والقيدُ لم يتبدّل: الجانبُ «bank» لا يتبع أيَّ حسابٍ بعينه.
     *
     * `bank_account_id` بيانُ مطابقةٍ لا وجهةُ قيد — ولو تبع القيدُ الحسابَ
     * لاحتاج كلُّ حسابٍ ورقةً في شجرة الحسابات، وهذا تغييرٌ آخر لم يُطلب.
     */
    public function test_the_ledger_side_is_unchanged(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issue' => true, 'payment_method' => 'تحويل', 'bank_account_id' => $this->second->id,
        ]))->assertSessionHasNoErrors();

        // والمقارنةُ بإجمالي الورقة لا برقمٍ مكتوب: النسبةُ إعدادٌ يتبدّل
        $total = (float) CustomerInvoice::firstOrFail()->total;

        $this->assertEqualsWithDelta($total, Ledger::balance($this->business->id, 'bank'), 0.001);
    }
}
