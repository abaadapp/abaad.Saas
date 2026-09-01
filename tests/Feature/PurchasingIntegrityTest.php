<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المشتريات: ما دخل الرفّ دخل مرّةً، وما خرج من الصندوق خرج مرّةً.
 *
 * وكلّ ما هنا فحصٌ يسبق كتابةً على نسخةٍ قُرئت قبل المعاملة — أو حذفٌ يمحو
 * سببَ وجود بضاعةٍ قائمة. ولا يكشف شيءٌ منه إلّا الجرد أو كشفُ المورّد،
 * بعد أن يكون قد دخل في تسعير شهر.
 */
class PurchasingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدي']);

        $this->actingAs($this->owner);
    }

    private function product(int $qty = 0, float $cost = 0): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 10, 'cost' => $cost, 'quantity' => $qty, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    private function order(Product $product, int $qty = 100, float $cost = 4, ?Supplier $supplier = null): PurchaseOrder
    {
        $supplier ??= $this->supplier;

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'PO-'.random_int(10000, 99999),
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'status' => 'مُرسل', 'total' => $qty * $cost, 'ordered_at' => now(),
        ]);

        $po->items()->create([
            'product_id' => $product->id, 'name' => $product->name,
            'cost' => $cost, 'quantity' => $qty,
        ]);

        return $po->refresh();
    }

    private function receive(PurchaseOrder $po, array $payload = [])
    {
        return $this->post(route('admin.purchases.receive', $po->id), $payload);
    }

    /* ------------------- الاستلام يقع مرّة ------------------- */

    /**
     * ضغطتان على «استلام الكل» — أو موظّفان يفتحان الأمر نفسه.
     *
     * وكانت الثانية تمرّ لأنّ حالة الأمر تُقرأ قبل المعاملة: فتدخل مئتان
     * لأمرٍ من مئة، ويتجاوز `received_quantity` المطلوب، ويُرجَّح متوسّط
     * التكلفة بكمّيةٍ لم تصل.
     */
    public function test_receiving_the_same_order_twice_adds_the_goods_once(): void
    {
        $product = $this->product();
        $po = $this->order($product);

        $this->receive($po)->assertSessionHasNoErrors();
        $this->receive($po);

        $this->assertSame(100, (int) $product->fresh()->quantity);
        $this->assertSame(100, (int) $po->items()->first()->received_quantity);
        $this->assertSame(1, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());
    }

    public function test_a_second_receipt_cannot_exceed_the_remaining(): void
    {
        $product = $this->product();
        $po = $this->order($product);
        $line = $po->items()->first();

        // من كتب ستّين مرّتين على أمرٍ من مئة: الثانية تتجاوز المتبقّي
        $this->receive($po, ['items' => [['id' => $line->id, 'quantity' => 60]]])->assertSessionHasNoErrors();
        $this->receive($po, ['items' => [['id' => $line->id, 'quantity' => 60]]])
            ->assertSessionHasErrors('receive');

        $this->assertSame(60, (int) $product->fresh()->quantity);
        $this->assertSame(60, (int) $line->fresh()->received_quantity);
    }

    public function test_a_partial_receipt_still_completes_on_the_second_run(): void
    {
        $product = $this->product();
        $po = $this->order($product);
        $line = $po->items()->first();

        $this->receive($po, ['items' => [['id' => $line->id, 'quantity' => 60]]])->assertSessionHasNoErrors();
        $this->receive($po, ['items' => [['id' => $line->id, 'quantity' => 40]]])->assertSessionHasNoErrors();

        $this->assertSame(100, (int) $product->fresh()->quantity);
        $this->assertSame('مستلم', $po->fresh()->status);
        $this->assertSame(2, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());
    }

    public function test_the_weighted_cost_counts_only_what_arrived(): void
    {
        $product = $this->product(qty: 100, cost: 4);
        $po = $this->order($product, qty: 100, cost: 6);
        $line = $po->items()->first();

        $this->receive($po, ['items' => [['id' => $line->id, 'quantity' => 100]]]);
        // (100×4 + 100×6) / 200 = 5 — ولو دخلت الدفعة مرّتين لصارت ٥٫٣٣٣
        $this->assertSame('5.000', (string) $product->fresh()->cost);

        $this->receive($po);
        $this->assertSame('5.000', (string) $product->fresh()->cost);
    }

    /* ------------------- الحذف لا يمحو سببًا قائمًا ------------------- */

    public function test_an_order_whose_goods_arrived_cannot_be_deleted(): void
    {
        $product = $this->product();
        $po = $this->order($product);
        $this->receive($po);

        $this->delete(route('admin.purchases.destroy', $po->id));

        // البضاعة على الرفّ، فلا يُمحى ما يقول من أين جاءت
        $this->assertNotNull(PurchaseOrder::find($po->id));
        $this->assertSame(1, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());
    }

    public function test_an_invoiced_order_cannot_be_deleted(): void
    {
        $po = $this->order($this->product());
        SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id, 'supplier_ref' => 'S-1',
            'issued_at' => now(), 'subtotal' => 400, 'tax' => 0, 'total' => 400,
        ]);

        $this->delete(route('admin.purchases.destroy', $po->id));

        $this->assertNotNull(PurchaseOrder::find($po->id));
    }

    public function test_an_untouched_order_is_still_deletable(): void
    {
        $po = $this->order($this->product());

        $this->delete(route('admin.purchases.destroy', $po->id));

        $this->assertNull(PurchaseOrder::find($po->id));
    }

    /* ------------------- السند: أمرٌ واحد ومورّدٌ واحد ------------------- */

    private function invoicePayload(array $over = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'INV-'.random_int(1000, 9999),
            'issued_at' => now()->toDateString(),
            'subtotal' => 400, 'tax' => 20,
        ], $over);
    }

    public function test_one_order_cannot_carry_two_supplier_invoices(): void
    {
        $po = $this->order($this->product());

        $this->post(route('admin.purchases.invoices.store'), $this->invoicePayload([
            'purchase_order_id' => $po->id,
        ]))->assertSessionHasNoErrors();

        // وإلّا عُدّ الشراء مرّتين: دَينٌ مضاعف وتكلفةُ مخزونٍ مضاعفة
        $this->post(route('admin.purchases.invoices.store'), $this->invoicePayload([
            'purchase_order_id' => $po->id,
        ]))->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(1, SupplierInvoice::where('purchase_order_id', $po->id)->count());
    }

    public function test_an_order_of_another_supplier_cannot_be_attached(): void
    {
        $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدٌ آخر']);
        $theirOrder = $this->order($this->product(), supplier: $other);

        $this->post(route('admin.purchases.invoices.store'), $this->invoicePayload([
            'purchase_order_id' => $theirOrder->id,
        ]))->assertSessionHasErrors('purchase_order_id');
    }

    public function test_a_free_order_still_attaches(): void
    {
        $po = $this->order($this->product());

        $this->post(route('admin.purchases.invoices.store'), $this->invoicePayload([
            'purchase_order_id' => $po->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, SupplierInvoice::where('purchase_order_id', $po->id)->count());
    }

    /* ------------------- السداد لا يتجاوز المستحقّ ------------------- */

    private function invoice(float $total = 420): SupplierInvoice
    {
        $this->post(route('admin.purchases.invoices.store'), $this->invoicePayload([
            'subtotal' => $total, 'tax' => 0,
        ]))->assertSessionHasNoErrors();

        return SupplierInvoice::latest('id')->firstOrFail();
    }

    private function pay(SupplierInvoice $invoice, float $amount)
    {
        return $this->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => $amount, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);
    }

    public function test_paying_the_full_amount_twice_pays_it_once(): void
    {
        $invoice = $this->invoice(420);

        $this->pay($invoice, 420)->assertSessionHasNoErrors();
        $this->pay($invoice, 420)->assertSessionHasErrors('amount');

        // المدفوع فوق الإجمالي يجعل المستحقّ سالبًا — يُقرأ كأنّ المورّد يدين لنا
        $this->assertSame('420.000', (string) $invoice->fresh()->paid);
        $this->assertSame(0.0, round($invoice->fresh()->outstanding(), 3));
    }

    public function test_a_second_payment_cannot_exceed_what_is_left(): void
    {
        $invoice = $this->invoice(420);

        $this->pay($invoice, 300)->assertSessionHasNoErrors();
        $this->pay($invoice, 300)->assertSessionHasErrors('amount');

        $this->assertSame('300.000', (string) $invoice->fresh()->paid);
    }

    /**
     * والقفل نفسه لا يُثبته اختبارٌ متسلسل — وأقولها كما هي.
     *
     * الطلب الثاني في الاختبار يقرأ الصفّ من جديد، فيرى ما كتبه الأول ويُردّ
     * بالفحص وحده. والحال التي يحرسها القفل طلبان **يجريان معًا** على عاملَي
     * PHP مختلفين: كلاهما يقرأ قبل أن يكتب الآخر. وذاك لا يُصنَع في عمليةٍ
     * واحدة، فيبقى الحارس مقروءًا من مصدره كي لا يُرفع سهوًا.
     */
    public function test_the_writes_that_move_money_and_stock_read_under_a_lock(): void
    {
        $receive = file_get_contents(base_path('app/Http/Controllers/Admin/PurchaseOrderController.php'));
        $invoice = file_get_contents(base_path('app/Http/Controllers/Admin/Purchasing/SupplierInvoiceController.php'));

        $this->assertStringContainsString('->lockForUpdate()->findOrFail($po->id)', $receive);
        $this->assertStringContainsString("items()->lockForUpdate()", $receive);
        $this->assertStringContainsString('lockForUpdate()->findOrFail($invoice->id)', $invoice);
    }

    public function test_partial_payments_still_add_up(): void
    {
        $invoice = $this->invoice(420);

        $this->pay($invoice, 200)->assertSessionHasNoErrors();
        $this->pay($invoice, 220)->assertSessionHasNoErrors();

        $this->assertSame('420.000', (string) $invoice->fresh()->paid);
        $this->assertSame(0.0, round($invoice->fresh()->outstanding(), 3));
    }

    /* ------------------- حدّ المتجر ------------------- */

    public function test_a_neighbours_order_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = PurchaseOrder::create([
            'business_id' => $other->id, 'number' => 'PO-THEIRS',
            'supplier_id' => $theirSupplier->id, 'supplier_name' => 'مورّدهم',
            'status' => 'مُرسل', 'total' => 100, 'ordered_at' => now(),
        ]);

        $this->receive($theirs)->assertNotFound();
        $this->delete(route('admin.purchases.destroy', $theirs->id))->assertNotFound();

        $this->assertNotNull(PurchaseOrder::find($theirs->id));
    }

    public function test_a_neighbours_invoice_cannot_be_paid_from_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = SupplierInvoice::create([
            'business_id' => $other->id, 'supplier_id' => $theirSupplier->id,
            'supplier_ref' => 'S-THEIRS', 'issued_at' => now(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100,
        ]);

        $this->pay($theirs, 100)->assertNotFound();
        $this->delete(route('admin.purchases.invoices.destroy', $theirs->id))->assertNotFound();

        $this->assertSame('0.000', (string) $theirs->fresh()->paid);
    }
}
