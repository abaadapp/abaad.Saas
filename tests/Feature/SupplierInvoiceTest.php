<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سند المورّد هو الباب الذي تدخل منه الذمّة إلى الدفتر.
 *
 * أمر الشراء يُحرّك المخزون ولا يُنشئ التزامًا — قد يُلغى قبل أن يُفوتَر —
 * والسند يُنشئه. وما يُحرَس هنا هو ألّا يتضاعف الدَّين ولا يضيع: سندٌ يُدخَل
 * مرّتين يُظهر على المتجر ضعف ما عليه، وسندٌ يُحذف بعد سداده يترك مالًا خرج
 * من الصندوق بلا مقابل في الدفتر.
 */
class SupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الجملة']);

        $this->actingAs($owner);
        // فتح الشاشة يبني الشجرة — والترحيل يقصد حساباتها بمفاتيحها
        $this->get(route('admin.purchases.invoices'));
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function record(array $overrides = [])
    {
        return $this->post(route('admin.purchases.invoices.store'), array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'INV-77',
            'issued_at' => now()->toDateString(),
            'subtotal' => 500,
            'tax' => 0,
        ], $overrides));
    }

    /* ----------------------------- التسجيل ----------------------------- */

    public function test_recording_an_invoice_creates_the_debt_in_the_ledger(): void
    {
        $this->record()->assertSessionHasNoErrors();

        $this->assertSame(500.0, Ledger::account($this->bid(), 'payable')->balance(), 'لم تُقيَّد الذمّة');
        $this->assertSame(500.0, Ledger::account($this->bid(), 'inventory')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_the_same_invoice_twice_is_refused_with_a_message_not_a_crash(): void
    {
        /*
         * أكثر أخطاء الإدخال شيوعًا: يُدخِله المحاسب ثم يُدخِله من بعده ظنًّا
         * أنه لم يُسجَّل. والقيد الفريد في القاعدة يمنعه بخطأ ٥٠٠ لا برسالة —
         * فيبدو النظام معطوبًا ولا يُقال له ما وقع.
         */
        $this->record()->assertSessionHasNoErrors();
        $this->record()->assertSessionHasErrors('supplier_ref');

        $this->assertSame(1, SupplierInvoice::where('business_id', $this->bid())->count());
        $this->assertSame(500.0, Ledger::account($this->bid(), 'payable')->balance(), 'تضاعف الدَّين');
    }

    public function test_two_suppliers_may_share_a_reference_number(): void
    {
        // الرقم رقمُ المورّد لا رقمُنا، فتشابهُه بين موردين واقعٌ لا خطأ
        $other = Supplier::create(['business_id' => $this->bid(), 'name' => 'مورّد آخر']);

        $this->record()->assertSessionHasNoErrors();
        $this->record(['supplier_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame(2, SupplierInvoice::where('business_id', $this->bid())->count());
    }

    public function test_the_tax_enters_the_cost_and_the_entry_balances(): void
    {
        $this->record(['subtotal' => 100, 'tax' => 5])->assertSessionHasNoErrors();

        $this->assertSame(105.0, (float) SupplierInvoice::first()->total);
        $this->assertSame(105.0, Ledger::account($this->bid(), 'payable')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_an_invoice_with_no_amount_is_not_recorded(): void
    {
        $this->record(['subtotal' => 0, 'tax' => 0])->assertSessionHasErrors('subtotal');

        $this->assertSame(0, SupplierInvoice::count());
    }

    /* ------------------------------ السداد ------------------------------ */

    public function test_paying_writes_a_second_entry_not_an_edit_of_the_first(): void
    {
        /*
         * الأوّل يقول «عليّ» والثاني يقول «دفعتُ». ودمجُهما يُخفي متى نشأ
         * الدَّين ومتى انتهى، فلا يُعرف كم مكث المتجر مدينًا.
         */
        $this->record();
        $invoice = SupplierInvoice::first();

        $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 200, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, JournalEntry::where('business_id', $this->bid())->count());
        $this->assertSame(300.0, Ledger::account($this->bid(), 'payable')->balance());
        $this->assertSame(-200.0, Ledger::account($this->bid(), 'cash')->balance());
        $this->assertSame('جزئي', $invoice->fresh()->status);
    }

    public function test_paying_it_in_full_closes_it(): void
    {
        $this->record();
        $invoice = SupplierInvoice::first();

        $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 500, 'paid_at' => now()->toDateString(), 'from' => 'bank',
        ])->assertSessionHasNoErrors();

        $this->assertSame('مدفوع', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
        $this->assertSame(0.0, Ledger::account($this->bid(), 'payable')->balance());
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        /*
         * الزيادة تجعل المستحقّ سالبًا فيُقرأ في مجموع الذمم كأنّ المورّد
         * يدين لنا. وهو خطأ إدخالٍ لا حالةٌ واقعية.
         */
        $this->record();
        $invoice = SupplierInvoice::first();

        $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 900, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0.0, (float) $invoice->fresh()->paid);
        $this->assertSame(1, JournalEntry::where('business_id', $this->bid())->count());
    }

    /* ------------------------------ الحذف ------------------------------ */

    public function test_deleting_an_untouched_invoice_takes_its_entry_with_it(): void
    {
        // قيدٌ يتيم يُبقي الدَّين في الدفتر بلا مستند يُراجَع
        $this->record();
        $invoice = SupplierInvoice::first();

        $this->delete(route('admin.purchases.invoices.destroy', $invoice->id));

        $this->assertNull(SupplierInvoice::find($invoice->id));
        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
        $this->assertSame(0.0, Ledger::account($this->bid(), 'payable')->balance());
    }

    public function test_an_invoice_that_was_paid_is_not_deleted(): void
    {
        $this->record();
        $invoice = SupplierInvoice::first();

        $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 100, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        $this->delete(route('admin.purchases.invoices.destroy', $invoice->id));

        $this->assertNotNull(SupplierInvoice::find($invoice->id), 'حُذف سندٌ خرج مقابله مال');
        $this->assertSame(2, JournalEntry::where('business_id', $this->bid())->count());
    }

    public function test_another_stores_invoice_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = SupplierInvoice::create([
            'business_id' => $other->id, 'supplier_id' => $theirSupplier->id,
            'supplier_ref' => 'X-1', 'issued_at' => now()->toDateString(), 'total' => 90,
        ]);

        $this->post(route('admin.purchases.invoices.pay', $theirs->id), [
            'amount' => 10, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertNotFound();

        $this->delete(route('admin.purchases.invoices.destroy', $theirs->id))->assertNotFound();
        $this->assertNotNull(SupplierInvoice::find($theirs->id));
    }

    /* -------------------------- قائمة المشتريات -------------------------- */

    public function test_the_register_does_not_count_a_linked_purchase_twice(): void
    {
        /*
         * أمرٌ استُلم ثمّ فُوتر هو شراءٌ واحد بورقتين. وعدّهما يضاعف مشتريات
         * الشهر في وجه التاجر، ويجعل كلّ نسبةٍ تُحسب عليها خاطئة.
         */
        $order = PurchaseOrder::create([
            'business_id' => $this->bid(), 'number' => 'PO-1', 'supplier_id' => $this->supplier->id,
            'status' => 'مستلم', 'total' => 500, 'ordered_at' => now(),
        ]);

        $this->record(['purchase_order_id' => $order->id])->assertSessionHasNoErrors();

        $props = $this->get(route('admin.purchases.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['summary']['count'], 'عُدّ الشراء الواحد مرّتين');
        $this->assertSame(500.0, $props['summary']['total']);
    }

    public function test_the_register_shows_a_direct_purchase_that_has_no_order(): void
    {
        // شراءٌ عاجل من السوق بلا أمر — قائمةٌ تعرض الأوامر وحدها تُخفيه
        $this->record(['supplier_ref' => 'DIRECT-9', 'subtotal' => 75]);

        $props = $this->get(route('admin.purchases.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['summary']['count']);
        $this->assertSame(75.0, $props['summary']['total']);
        $this->assertSame('سند مورّد', $props['rows'][0]['source']);
    }

    public function test_the_register_totals_the_shown_month_only(): void
    {
        $this->record(['supplier_ref' => 'A-1', 'subtotal' => 100]);
        $this->record(['supplier_ref' => 'A-2', 'subtotal' => 400, 'issued_at' => now()->subMonths(2)->toDateString()]);

        $props = $this->get(route('admin.purchases.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(100.0, $props['summary']['total'], 'تسرّب شراء شهرٍ آخر');
        // وما على المتجر لا يخصّ شهرًا: الدَّين يبقى حتى يُسدَّد
        $this->assertSame(500.0, $props['summary']['outstanding']);
    }
}
