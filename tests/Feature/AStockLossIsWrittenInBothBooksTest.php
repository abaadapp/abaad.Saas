<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\ReportData;
use App\Support\StockLosses;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خسارةُ المخزون تُكتب في الدفترين — والضريبةُ تتبع تسجيل المتجر.
 *
 * ═══ العطبان ═══
 *
 * للنقص بابان: «تعديل مخزون» يدويٌّ، و«تسوية جرد». وكلٌّ كان يكتب في دفترٍ
 * ويترك الآخر — اليدويُّ يُرحّل ولا يكتب مصروفًا، والجردُ يكتب المصروف ولا
 * يُرحّل. فخسارةُ التلف لا تُنقص الربح الذي يقرؤه التاجر (وهو يُقرأ من جدول
 * المصروفات)، وفاقدُ الجرد لا يُنقص المخزون في الميزانية.
 *
 * وقياسُه في متجرٍ حيّ: أحدَ عشرَ صفَّ «فاقد جرد» بمئتين وسبعةٍ وتسعين ريالًا
 * في جدول المصروفات، وصفرٌ في ميزان المراجعة يقابلها.
 *
 * والثاني: ضريبةُ سند المورّد كانت تدخل تكلفةَ المخزون دائمًا. وهذا صوابٌ
 * لغير المسجَّل؛ أمّا المسجَّل فتقريرُ إقراره يخصمها — فتُعدّ مرّتين: أصلًا
 * في المخزون وخصمًا في الإقرار.
 */
class AStockLossIsWrittenInBothBooksTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'sku' => 'ROSE-01',
            'price' => 10, 'cost' => 4, 'quantity' => 10, 'active' => true,
        ]);

        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $this->product->id, 'quantity' => 10,
        ]);

        $this->actingAs($this->owner);
    }

    private function inventory(): float
    {
        return Ledger::account($this->business->id, 'inventory')->balance();
    }

    private function expenses(): float
    {
        return (float) Expense::where('business_id', $this->business->id)->paid()->sum('amount');
    }

    /* ───────────────── الجردُ يُرحّل كما يكتب المصروف ───────────────── */

    /** فاقدُ الجرد يُنقص المخزون في الميزانية — لا في الشاشة وحدها */
    public function test_a_stocktake_shortage_leaves_the_ledger_too(): void
    {
        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->branch->id,
            'counts' => [$this->product->id => 7],
        ])->assertRedirect();

        // ثلاثُ قطعٍ بتكلفة أربعة
        $this->assertSame(12.0, $this->expenses(), 'الفاقد لم يُكتب مصروفًا');
        $this->assertSame(
            -12.0,
            $this->inventory(),
            'الفاقد لم يخرج من المخزون في الأستاذ — فيقرأ المتجر نفسه أغنى ممّا هو'
        );
        $this->assertSame(12.0, Ledger::account($this->business->id, 'other_expenses')->balance());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** وزيادةُ الجرد تعود إلى المخزون ولا تُكتب مصروفًا سالبًا */
    public function test_a_stocktake_surplus_returns_to_the_ledger_without_an_expense(): void
    {
        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->branch->id,
            'counts' => [$this->product->id => 13],
        ])->assertRedirect();

        $this->assertSame(0, Expense::count(), 'كُتبت الزيادةُ مصروفًا');
        $this->assertSame(12.0, $this->inventory(), 'بضاعةٌ وُجدت ولم تدخل الميزانية');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** وجردٌ بلا فرقٍ لا يكتب شيئًا في أيّ دفتر */
    public function test_a_stocktake_that_matches_writes_nothing(): void
    {
        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->branch->id,
            'counts' => [$this->product->id => 10],
        ])->assertRedirect();

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::where('source', StockLosses::SOURCE)->count());
    }

    /* ───────────────── والتعديلُ اليدويُّ يكتب المصروف ───────────────── */

    /** تلفٌ سُجّل يدويًّا يُنقص الربح الذي يقرؤه التاجر */
    public function test_a_manual_loss_reaches_the_expenses_table_too(): void
    {
        $this->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity_delta' => 3,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(-12.0, $this->inventory());
        $this->assertSame(
            12.0,
            $this->expenses(),
            'التلفُ لم يُكتب مصروفًا — والربح يُقرأ من جدول المصروفات لا من الأستاذ'
        );
        $this->assertSame('تلف', Expense::sole()->type, 'ضاع سببُ الهالك في المصروف');
    }

    /** والبابان يقولان الرقم نفسه عن الحدث نفسه */
    public function test_both_doors_move_both_books_by_the_same_amount(): void
    {
        $this->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity_delta' => 2,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $afterManual = [$this->inventory(), $this->expenses()];

        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->branch->id,
            'counts' => [$this->product->id => 6],
        ])->assertRedirect();

        // قطعتان يدويًّا ثمّ قطعتان بالجرد — بتكلفة أربعة لكلٍّ
        $this->assertSame([-8.0, 8.0], $afterManual);
        $this->assertSame(-16.0, $this->inventory(), 'بابان يُنقصان المخزون بمقدارين');
        $this->assertSame(16.0, $this->expenses(), 'بابان يكتبان المصروف بمقدارين');
    }

    /** ومنتجٌ بلا تكلفة لا قيد له ولا مصروف — لا مبلغ يُقيَّد */
    public function test_a_product_with_no_cost_writes_nothing(): void
    {
        $this->product->update(['cost' => 0]);

        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->branch->id,
            'counts' => [$this->product->id => 4],
        ])->assertRedirect();

        $this->assertSame(0, Expense::count());
        $this->assertSame(0.0, $this->inventory());
        $this->assertSame(4, (int) $this->product->fresh()->quantity, 'لم تتحرّك الكمية أصلًا');
    }

    /* ───────────────── وضريبةُ الشراء تتبع تسجيل المتجر ───────────────── */

    private function invoice(float $subtotal, float $tax): SupplierInvoice
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);

        return SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'supplier_ref' => 'R-1',
            'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'approval_status' => SupplierInvoices::PENDING,
        ]);
    }

    /** المسجَّلُ يستردّ ضريبتَه، فلا تُحمَّل على المخزون */
    public function test_a_registered_shop_keeps_the_tax_out_of_the_stock_cost(): void
    {
        SupplierInvoices::approve($this->invoice(25, 5), $this->owner);

        $this->assertSame(25.0, $this->inventory(), 'ضريبةٌ تُستردّ حُمّلت على تكلفة البضاعة');
        $this->assertSame(
            -5.0,
            Ledger::account($this->business->id, 'tax_payable')->balance(),
            'الضريبةُ المستردّة لا تُنقص ما يُدفع للجهة'
        );
        // والذمّةُ كاملةً: المورّد يُطالب بالمبلغ مع ضريبته
        $this->assertSame(30.0, Ledger::account($this->business->id, 'payable')->balance());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /**
     * وغيرُ المسجَّل: الضريبةُ عنده ثمنٌ لا يُستردّ.
     *
     * فصلُها له يُنشئ أصلًا لا يعود أبدًا ويُنقص تكلفةَ مخزونه.
     */
    public function test_an_unregistered_shop_keeps_the_tax_in_the_stock_cost(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '0'],
        );

        SupplierInvoices::approve($this->invoice(25, 5), $this->owner);

        $this->assertSame(30.0, $this->inventory(), 'ضريبةٌ لا تُستردّ خرجت من تكلفة البضاعة');
        $this->assertSame(0.0, Ledger::account($this->business->id, 'tax_payable')->balance());
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** وسندٌ بلا ضريبة لا يكتب سطرًا فارغًا */
    public function test_an_invoice_with_no_tax_writes_two_lines(): void
    {
        SupplierInvoices::approve($this->invoice(25, 0), $this->owner);

        $entry = JournalEntry::where('source', 'سند مورّد')->sole();

        $this->assertCount(2, $entry->lines);
        $this->assertSame(25.0, $this->inventory());
    }

    /**
     * والحسابُ الضريبيُّ يقول ما يقوله الإقرار.
     *
     * ضريبةُ المبيعات دائنةً وضريبةُ المشتريات مدينةً في الحساب نفسه، فرصيدُه
     * صافي ما يُدفع — وهو الرقم الذي يقوله تقرير الإقرار. حسابٌ لا يفترق عن
     * تقريره.
     */
    public function test_the_tax_account_reads_what_the_return_reads(): void
    {
        SupplierInvoices::approve($this->invoice(25, 5), $this->owner);

        $due = ReportData::vat($this->business->id, ['range' => 'all'])['summary']['due'];
        $account = Ledger::account($this->business->id, 'tax_payable')->balance();

        $this->assertSame(-5.0, $due, 'الإقرار لا يقرأ ضريبة المشتريات');
        $this->assertSame($due, $account, 'الحسابُ الضريبيُّ والإقرارُ يقولان قولين');
        $this->assertSame(Demo::money($due), Demo::money($account));
    }
}
