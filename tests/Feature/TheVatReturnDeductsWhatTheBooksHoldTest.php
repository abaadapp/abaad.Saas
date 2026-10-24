<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الإقرارُ يخصم من ضريبة المدخلات ما رحّله الدفترُ إليها — لا المكتوبَ على السند.
 *
 * متجرٌ غير مسجَّلٍ في الضريبة تدخل ضريبةُ مورّده في كلفة بضاعته، ولا يُقيَّد
 * منها شيءٌ في «ضريبة المدخلات» (`SupplierInvoices::approve` يسأل
 * `Vat::enabled` يومَ الاعتماد). وكان الإقرارُ يجمع `supplier_invoices.tax`
 * أيًّا كان، فوجد تدقيقُ ماليّة RIBBON (2026-10-24) الإقرارَ ٣٩٫٠١١ والدفترَ
 * ٣٠٫٦٦١: الفرقُ ضريبةُ أربعة سنداتٍ اعتُمدت بعد إطفاء الضريبة.
 */
class TheVatReturnDeductsWhatTheBooksHoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->business = Business::create(['name' => 'متجر الإقرار', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'vat@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الجملة']);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vat(string $on): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => $on]);
    }

    /** سندُ مورّد بـ١٠٠ وضريبة ٥، يُعتمد بحال الضريبة يومَه */
    private function bill(string $ref): SupplierInvoice
    {
        $this->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => $ref,
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 5,
        ])->assertSessionHasNoErrors();
        $this->approvePendingSupplierInvoices($this->business->id);

        return SupplierInvoice::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    private function summary(): array
    {
        return $this->get(route('admin.reports.vat'))->assertOk()->viewData('page')['props']['summary'];
    }

    public function test_with_vat_off_nothing_is_deducted_the_books_do_not_hold(): void
    {
        $this->vat('0');
        $this->bill('S-1');

        $this->assertSame([105.0, 0.0], [Ledger::balance($this->business->id, 'inventory'), Ledger::balance($this->business->id, 'tax_input')]);
        $this->assertSame([0.0, 0.0], [$this->summary()['input'], $this->summary()['due']]);
    }

    public function test_with_vat_on_the_tax_posted_is_the_tax_deducted(): void
    {
        $this->vat('1');
        $this->bill('S-1');

        $this->assertSame(5.0, Ledger::balance($this->business->id, 'tax_input'));
        $this->assertSame(5.0, $this->summary()['input']);
    }

    /** والمتجرُ الذي أطفأ الضريبة في منتصف الشهر — كحال RIBBON — يُخصم ما قبل الإطفاء وحده */
    public function test_a_month_that_switched_off_deducts_only_what_was_posted(): void
    {
        $this->vat('1');
        $this->bill('S-1');
        $this->vat('0');
        $this->bill('S-2');

        $this->assertSame(5.0, Ledger::balance($this->business->id, 'tax_input'));
        $this->assertSame(5.0, $this->summary()['input'], 'خُصمت ضريبةُ سندٍ اعتُمد والضريبةُ مطفأة');
    }

    /** وضريبةُ ما ينتظر الاعتماد لا تُذكر والضريبةُ مطفأة — لن تُخصم يومَ يُعتمد */
    public function test_pending_tax_is_not_promised_while_vat_is_off(): void
    {
        $this->vat('0');
        $this->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'S-9',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoices::PENDING, SupplierInvoice::where('business_id', $this->business->id)->value('approval_status'));
        $this->assertSame([0, 0.0], [$this->summary()['pending'], $this->summary()['pendingTax']]);
    }

    /** ولا يُخصم في إقرار متجرٍ ما رحّله دفترُ جاره */
    public function test_a_neighbours_posted_tax_is_not_deducted_here(): void
    {
        $this->vat('0');
        $this->bill('S-1');

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        Ledger::post($other->id, 'سندُ الجار', [
            ['account' => 'tax_input', 'debit' => 50],
            ['account' => 'cash', 'credit' => 50],
        ], now());

        $this->assertSame(0.0, $this->summary()['input']);
    }
}
