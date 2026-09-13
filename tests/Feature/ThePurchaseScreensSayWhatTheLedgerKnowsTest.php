<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما تقوله شاشاتُ المشتريات هو ما يعرفه الدفتر — لا أكثر.
 *
 * ═══ ثلاثةُ أعطابٍ في بابٍ واحد ═══
 *
 *  • **بطاقةُ «مستحقّ للموردين»** كانت تجمع كلَّ سندٍ في الجدول أيًّا كانت
 *    حالُه: المرفوضُ لا قيدَ له، والملغى عُكس قيدُه، والمعلَّقُ لم يوقّعه أحدٌ
 *    ولا يُسدَّد — وثلاثتُها كانت تُقرأ دَينًا على المتجر. وتقريرُ الإقرار
 *    الضريبيّ أُصلح من هذا العطب نفسِه قبلها (`ReportData::vat`) وبقيت
 *    الشاشتان على حالهما — وحقلان يقولان الشيء نفسه يفترقان يومًا.
 *
 *  • **حذفُ سندٍ أُلغي** كان يمحو قيدَه الأصلَ وقيدَ عكسِه معًا: الحارسُ كان
 *    يسأل عن الحالة («معتمد») والملغى ليس معتمدًا. فيختفي من الدفتر أنّ
 *    ذمّةً نشأت يومًا وأنّها عُكست — وهو الأثرُ الذي بُني `cancel` ليُبقيَه.
 *    والميزانُ يبقى متّزنًا بعدها، ولذلك لا يشكو أحد.
 *
 *  • **حذفُ مورّدٍ له سند** كان يردّ خمسمئة: القيدُ في القاعدة يمنعه
 *    (`restrictOnDelete`) والزرُّ مرسومٌ على كلّ صفّ. ومن له أوامرُ شراءٍ
 *    بلا سند كان يُحذف صامتًا فتُفرَّغ مراجعُ أوامره.
 */
class ThePurchaseScreensSayWhatTheLedgerKnowsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'ورد الخليج']);
    }

    private function invoice(string $ref, float $total, ?string $due = null): SupplierInvoice
    {
        return SupplierInvoices::create($this->shop->id, [
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => $ref,
            'issued_at' => now()->subDays(30)->toDateString(),
            'due_at' => $due,
            'subtotal' => $total,
            'tax' => 0,
        ], $this->owner);
    }

    /** ما يقوله حسابُ الموردين في الدفتر — دائنًا */
    private function payableInLedger(): float
    {
        $ids = JournalEntry::where('business_id', $this->shop->id)->pluck('id');
        $account = Account::where('business_id', $this->shop->id)
            ->where('system_key', 'payable')->firstOrFail();

        $lines = JournalLine::whereIn('journal_entry_id', $ids)
            ->where('account_id', $account->id)->get();

        return round((float) $lines->sum('credit') - (float) $lines->sum('debit'), 3);
    }

    /* ═════════════ الدَّينُ على الشاشة هو الدَّين في الدفتر ═════════════ */

    /** أربعُ حالاتٍ في الجدول، واحدةٌ منها دَين */
    private function fourInvoices(): void
    {
        $approved = $this->invoice('S-OK', 100);
        SupplierInvoices::approve($approved, $this->owner);

        SupplierInvoices::reject($this->invoice('S-REJ', 500), 'مكرّر', $this->owner);

        $cancelled = $this->invoice('S-CAN', 300);
        SupplierInvoices::approve($cancelled, $this->owner);
        SupplierInvoices::cancel($cancelled->fresh(), 'خطأ إدخال', $this->owner);

        $this->invoice('S-PEND', 70);
    }

    public function test_the_purchase_register_says_what_the_ledger_says(): void
    {
        $this->fourInvoices();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.index'))->viewData('page')['props'];

        $this->assertSame(100.0, round((float) $props['summary']['outstanding'], 3),
            'بطاقةُ «مستحقّ للموردين» تقول غيرَ ما يقوله الدفتر');
        $this->assertSame($this->payableInLedger(), round((float) $props['summary']['outstanding'], 3),
            'الشاشةُ والدفترُ يفترقان في الدَّين نفسِه');
    }

    public function test_the_invoices_screen_says_what_the_ledger_says(): void
    {
        $this->fourInvoices();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices'))->viewData('page')['props'];

        $this->assertSame(100.0, round((float) $props['summary']['outstanding'], 3));
        $this->assertSame($this->payableInLedger(), round((float) $props['summary']['outstanding'], 3));
        // والعددُ يبقى عددَ الأوراق كلِّها — البطاقةُ تقول «السندات» لا «الدَّين»
        $this->assertSame(4, $props['summary']['count']);
    }

    public function test_a_rejected_invoice_is_never_overdue(): void
    {
        $rejected = $this->invoice('S-REJ', 500, now()->subDays(5)->toDateString());
        SupplierInvoices::reject($rejected, 'مكرّر', $this->owner);

        $this->assertFalse($rejected->fresh()->isOverdue(),
            'ورقةٌ قيل عنها «لا تُدفع» تُرسم مطالبةً متأخّرة');

        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices'))->viewData('page')['props'];

        $this->assertSame(0, $props['summary']['overdue']);
        $this->assertSame(0.0, round((float) $props['summary']['overdue_value'], 3));
    }

    public function test_an_approved_invoice_past_its_day_is_overdue(): void
    {
        $due = $this->invoice('S-DUE', 90, now()->subDays(5)->toDateString());
        SupplierInvoices::approve($due, $this->owner);

        $this->assertTrue($due->fresh()->isOverdue(), 'دَينٌ مضى موعدُه لا يُقال عنه شيء');

        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices'))->viewData('page')['props'];

        $this->assertSame(1, $props['summary']['overdue']);
        $this->assertSame(90.0, round((float) $props['summary']['overdue_value'], 3));
    }

    public function test_what_a_supplier_is_owed_follows_the_same_rule(): void
    {
        $this->fourInvoices();

        $this->assertSame(100.0, $this->supplier->fresh()->outstanding(),
            'ذمّةُ المورّد تُحسب بقاعدةٍ غير قاعدة الشاشة');
    }

    public function test_a_paid_approved_invoice_leaves_nothing_owed(): void
    {
        $inv = $this->invoice('S-PAY', 100);
        SupplierInvoices::approve($inv, $this->owner);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.pay', $inv->id), [
            'amount' => 100, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.index'))->viewData('page')['props'];

        $this->assertSame(0.0, round((float) $props['summary']['outstanding'], 3));
        $this->assertSame($this->payableInLedger(), round((float) $props['summary']['outstanding'], 3));
    }

    /* ═════════════ ما بلغ الدفترَ لا يُمحى منه ═════════════ */

    public function test_a_cancelled_invoice_keeps_both_of_its_entries(): void
    {
        $inv = $this->invoice('S-CAN', 300);
        SupplierInvoices::approve($inv, $this->owner);
        SupplierInvoices::cancel($inv->fresh(), 'خطأ إدخال', $this->owner);

        $before = JournalEntry::where('business_id', $this->shop->id)->count();
        $this->assertSame(2, $before, 'الإلغاءُ لم يكتب قيدَ عكسٍ أصلًا');

        $this->actingAs($this->owner)
            ->delete(route('admin.purchases.invoices.destroy', $inv->id))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(SupplierInvoice::find($inv->id), 'سندٌ بلغ الدفترَ مُحي');
        $this->assertSame($before, JournalEntry::where('business_id', $this->shop->id)->count(),
            'قيدُ الذمّة وقيدُ عكسِها مُحيا معًا — فلا أثرَ لأنّها نشأت يومًا');
    }

    public function test_a_pending_invoice_that_was_never_posted_is_still_deletable(): void
    {
        $inv = $this->invoice('S-PEND', 70);

        $this->actingAs($this->owner)
            ->delete(route('admin.purchases.invoices.destroy', $inv->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(SupplierInvoice::find($inv->id), 'ورقةٌ لم تبلغ الدفترَ صارت لا تُرفع');
    }

    public function test_the_screen_draws_no_delete_on_a_paper_that_reached_the_ledger(): void
    {
        $cancelled = $this->invoice('S-CAN', 300);
        SupplierInvoices::approve($cancelled, $this->owner);
        SupplierInvoices::cancel($cancelled->fresh(), 'خطأ', $this->owner);

        $pending = $this->invoice('S-PEND', 70);

        $rows = collect($this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices'))->viewData('page')['props']['invoices'])
            ->keyBy('reference');

        $this->assertFalse($rows['S-CAN']['can_delete'], 'زرُّ حذفٍ على سندٍ قيدُه في الدفتر');
        $this->assertTrue($rows['S-PEND']['can_delete'], 'ورقةٌ تُحذف ولا يُعرض حذفُها');
        $this->assertSame($pending->id, $rows['S-PEND']['id']);
    }

    /* ═════════════ من اشتُري منه مرّةً يبقى ═════════════ */

    private function order(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $this->shop->id,
            'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'number' => 'PO-000001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => 50, 'ordered_at' => now(),
        ]);
    }

    public function test_a_supplier_with_an_invoice_is_refused_not_five_hundred(): void
    {
        $this->invoice('S-1', 100);

        $this->actingAs($this->owner)
            ->delete(route('admin.suppliers.destroy', $this->supplier->id))
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertNotNull(Supplier::find($this->supplier->id));
    }

    public function test_a_supplier_with_an_order_keeps_it_linked(): void
    {
        $po = $this->order();

        $this->actingAs($this->owner)
            ->delete(route('admin.suppliers.destroy', $this->supplier->id))->assertStatus(302);

        $this->assertNotNull(Supplier::find($this->supplier->id), 'مورّدٌ له أوامرُ شراءٍ حُذف');
        $this->assertSame($this->supplier->id, (int) $po->fresh()->supplier_id,
            'أمرُ شراءٍ فُرّغ مرجعُه فلا يُعرف من ورّده');
    }

    public function test_a_supplier_nobody_bought_from_is_still_deletable(): void
    {
        $fresh = Supplier::create(['business_id' => $this->shop->id, 'name' => 'أُضيف خطأً']);

        $this->actingAs($this->owner)
            ->delete(route('admin.suppliers.destroy', $fresh->id))->assertStatus(302);

        $this->assertNull(Supplier::find($fresh->id), 'مورّدٌ بلا تاريخِ شراءٍ صار لا يُحذف');
    }

    public function test_the_screen_draws_no_delete_on_a_supplier_that_was_bought_from(): void
    {
        $this->order();
        $fresh = Supplier::create(['business_id' => $this->shop->id, 'name' => 'أُضيف خطأً']);

        $this->actingAs($this->owner);
        $rows = collect(Demo::suppliers())->keyBy('id');

        $this->assertFalse($rows[$this->supplier->id]['can_delete'], 'بابٌ يُعرض ولا يُفتح');
        $this->assertTrue($rows[$fresh->id]['can_delete'], 'بابٌ يُفتح ولا يُعرض');
    }

    /**
     * وسندٌ بلا أمرٍ يمنع الحذفَ كذلك.
     *
     * ولو اشتُقّت القاعدةُ في الشاشة من عدد الأوامر وحده — وهو العمودُ
     * الظاهر — لَرُسم الزرُّ على مورّدٍ يردّه الخادم.
     */
    public function test_an_invoice_alone_hides_the_delete_handle(): void
    {
        $this->invoice('S-1', 100);

        $this->actingAs($this->owner);
        $row = collect(Demo::suppliers())->firstWhere('id', $this->supplier->id);

        $this->assertSame(0, $row['orders_count']);
        $this->assertFalse($row['can_delete'], 'مورّدٌ بلا أمرٍ وله سندٌ رُسم عليه الحذف');
    }
}
