<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كلُّ ضغطةٍ في «المشتريات» تُجيب.
 *
 * القسمُ ثلاثُ شاشاتٍ على بابين وتسعةَ عشرَ مسارًا، وقد تبدّل تحته الكثير في
 * أربعة إصدارات: الاستلامُ صار يُعتمد، والسندُ صار يُطابَق ويُوقَّع، وأفعالُ
 * المال فُصّلت، والمرفقاتُ انتقلت إلى قرصٍ آخر. وأربعُ نقلاتٍ متتابعة تترك
 * زرًّا لا يُجيب إن لم يُسأل كلٌّ منها بحرفه.
 *
 * فهذا سؤالٌ لكلّ مسارٍ في القسم: يُفتح، ويُضغط، ولا يُردّ بخطأ.
 */
class EveryClickInPurchasesAnswersTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'sku' => 'FLW-014', 'price' => 12.5, 'cost' => 9, 'quantity' => 0,
        ]);
    }

    private function order(int $qty = 100, float $cost = 9): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-'.random_int(1000, 999999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => $qty * $cost, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'cost' => $cost, 'quantity' => $qty,
        ]);

        return $po->refresh();
    }

    /* ==================== الشاشاتُ الثلاث ==================== */

    /** الأبوابُ الثلاثة تُفتح ومعها ما ترسمه */
    public function test_the_three_screens_open(): void
    {
        $this->order();

        foreach (['admin.purchases.index', 'admin.purchases.orders', 'admin.purchases.invoices', 'admin.purchases.create'] as $name) {
            $this->actingAs($this->owner)->get(route($name))->assertOk();
        }
    }

    /**
     * وصفحةُ «أمر شراء جديد» لها بابُ رجوع.
     *
     * وهي صفحةٌ داخليّة بلا شريط تبويبات: من دخلها ولم يُرِد الحفظ لم يكن
     * أمامَه إلّا زرُّ المتصفّح — وهو يعود إلى ما كان أيًّا كان، أو إلى لا
     * شيء إن فُتحت من رابطٍ محفوظ.
     */
    public function test_the_new_order_page_has_a_way_back(): void
    {
        /*
         * وهذا آخرُ حارسٍ يقرأ مصدرًا في هذا القسم — عمدًا.
         *
         * وجودُ بابِ رجوعٍ على صفحةٍ بعينها تركيبُ صفحةٍ لا سلوكُ مكوّن،
         * ولا يُثبَت في jsdom إلّا بتركيب `AdminLayout` كلِّه — وهو نصفُ
         * النظام. فيُحرَس هكذا، ويُقال إنّه ضعيف.
         */
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Purchases/Create.tsx'));

        $this->assertStringContainsString("import BackLink from '@/Components/BackLink';", $screen);
        $this->assertStringContainsString('routeName="admin.purchases.orders"', $screen);

        // والوجهةُ بابٌ قائم لا اسمٌ مخترَع
        $this->actingAs($this->owner)->get(route('admin.purchases.orders'))->assertOk();
    }

    /* ==================== أمرُ الشراء ==================== */

    /** يُكتب الأمر، ويُطبع، ويُحذف */
    public function test_an_order_is_written_printed_and_removed(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'items' => [[
                'product_id' => $this->product->id, 'name' => $this->product->name,
                'cost' => 9, 'quantity' => 10,
            ]],
        ])->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();

        $this->actingAs($this->owner)->get(route('admin.purchases.pdf', $po->id))->assertOk();
        $this->actingAs($this->owner)->delete(route('admin.purchases.destroy', $po->id))
            ->assertSessionHasNoErrors();
    }

    /** وإيصالُ الدفع يُرفع ويُقرأ من بابه */
    public function test_the_payment_receipt_is_uploaded_and_read(): void
    {
        $po = $this->order();

        $this->actingAs($this->owner)->post(route('admin.purchases.receipt', $po->id), [
            'receipt' => UploadedFile::fake()->create('إيصال.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.receiptFile', $po->id))->assertOk();
    }

    /**
     * وإيصالٌ موجودٌ لا يُقرأ لا يُعرض «لا إيصال».
     *
     * المتحكّمُ يُفرّغ الرابط لمن لا يملك فتحَ المرفقات، والشاشةُ كانت تقرأ
     * الفراغَ «لا إيصال» فترسم زرَّ الرفع فوق إيصالٍ موجود — ورفعُ بديلٍ
     * يحذف الأوّل من القرص. فيصير من لا يُؤتمن على قراءته قادرًا على محوه.
     */
    public function test_a_receipt_you_may_not_read_is_not_offered_as_empty(): void
    {
        $po = $this->order();
        $po->update(['receipt' => 'purchase-receipts/1/a.pdf', 'receipt_name' => 'إيصال.pdf']);

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'b@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases'],
        ]);

        $this->actingAs($blind)->get(route('admin.purchases.orders'))
            ->assertInertia(fn ($p) => $p
                ->where('orders.0.receipt', null)
                ->where('orders.0.has_receipt', true)
                ->etc());

        $this->actingAs($this->owner)->get(route('admin.purchases.orders'))
            ->assertInertia(fn ($p) => $p
                ->where('orders.0.receipt', route('admin.purchases.receiptFile', $po->id))
                ->where('orders.0.has_receipt', true)
                ->etc());

        // ‏وأنّ الشاشة تفرّق بينهما يُثبته المتصفّح — tests/js/purchase-receipt-cell.test.tsx
    }

    /* ==================== الاستلامُ واعتمادُه ==================== */

    /** يُسجَّل الاستلام، فينتظر، ثمّ يُعتمد — والمخزون يتحرّك عند الاعتماد وحده */
    public function test_a_receipt_waits_then_is_approved(): void
    {
        $po = $this->order(10);
        $line = $po->items()->first()->id;

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line, 'quantity' => 10]],
            'attachment' => UploadedFile::fake()->create('بوليصة.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $note = GoodsReceiptNote::firstOrFail();
        $this->assertSame(GoodsReceipts::PENDING, $note->status);
        $this->assertSame(0, (int) $this->product->fresh()->quantity);

        $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts.attachment', $note->id))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.approve', $note->id))->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::APPROVED, $note->fresh()->status);
        $this->assertSame(10, (int) $this->product->fresh()->quantity);
    }

    /** ويُرفض بسببٍ مكتوب، ولا يتحرّك به شيء */
    public function test_a_receipt_is_rejected_with_a_written_reason(): void
    {
        $po = $this->order(10);
        $note = GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'وصلت ناقصة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
        $this->assertSame(0, (int) $this->product->fresh()->quantity);
    }

    /* ==================== سندُ المورّد ==================== */

    /** يُكتب السند، فينتظر، ثمّ يُعتمد فتنشأ الذمّة، ثمّ يُسدَّد */
    public function test_an_invoice_waits_is_approved_then_paid(): void
    {
        $po = $this->order(100);
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $po->items()->first()->id, 'quantity' => 100]],
        ])->assertSessionHasNoErrors();
        $this->approvePendingReceipts($this->business->id, $this->owner);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'supplier_ref' => 'S-1001',
            'issued_at' => now()->toDateString(),
            'subtotal' => 900, 'tax' => 0,
            'attachment' => UploadedFile::fake()->create('فاتورة.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::firstOrFail();
        $this->assertSame(SupplierInvoices::PENDING, $invoice->approval_status);

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.invoices.attachment', $invoice->id))->assertOk();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 900, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame(900.0, (float) $invoice->fresh()->paid);
    }

    /** ويُرفض السند، ويُحذف ما لم يُعتمد */
    public function test_an_invoice_is_rejected_and_an_unapproved_one_is_removed(): void
    {
        $first = $this->invoice('S-2001');
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.reject', $first->id), ['reason' => 'مكرّرة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoices::REJECTED, $first->fresh()->approval_status);

        $second = $this->invoice('S-2002');
        $this->actingAs($this->owner)
            ->delete(route('admin.purchases.invoices.destroy', $second->id))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('supplier_invoices', ['id' => $second->id]);
    }

    /**
     * والمعتمَدُ يُلغى بعكس قيده لا يُمحى.
     *
     * ومقبضُه في الشاشة مُختبَرٌ في متصفّح — tests/js/supplier-invoice-row-actions.
     */
    public function test_an_approved_invoice_is_cancelled_by_reversal(): void
    {
        $invoice = $this->invoice('S-3001');
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $invoice->id), ['override_reason' => 'اعتمادُ اختبار'])
            ->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.cancel', $invoice->id), ['reason' => 'أُلغيت الشحنة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SupplierInvoices::CANCELLED, $invoice->fresh()->approval_status);
    }

    /**
     * وسندٌ خرج مقابله مال لا يُلغى — والزرُّ لا يُعرض عليه أصلًا.
     *
     * عكسُ الذمّة وحدها يترك قيدَ السداد يتيمًا: نقدٌ خرج من الصندوق مقابل
     * دَينٍ لا وجود له في الدفتر. وطريقُه أن يُسترَدّ المال أو يُقيَّد إشعارٌ
     * دائن.
     *
     * والحارسان اثنان عمدًا: الشاشةُ لا تعرض الباب، والخادمُ يردّه لمن بلغه
     * بطلبٍ مباشر. وحارسُ الشاشة يُريح، وحارسُ الخادم يحرس.
     */
    public function test_a_partly_paid_invoice_is_not_cancelled(): void
    {
        $invoice = $this->invoice('S-4001');
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $invoice->id), ['override_reason' => 'اعتمادُ اختبار'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.pay', $invoice->id), [
            'amount' => 40, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.cancel', $invoice->id), ['reason' => 'أُلغيت الشحنة'])
            ->assertSessionHasErrors('approve');

        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);
    }

    /** والإلغاءُ بسببٍ مكتوب — وورقةٌ تُلغى بلا سببٍ تُسأل عنها بعد شهر */
    public function test_cancelling_needs_a_written_reason(): void
    {
        $invoice = $this->invoice('S-4002');
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.approve', $invoice->id), ['override_reason' => 'اعتمادُ اختبار'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.invoices.cancel', $invoice->id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);
    }

    /* ==================== لا خمسمئةَ في القسم ==================== */

    /**
     * وكلُّ صفحةٍ في القسم تُجيب لكلّ دورٍ يفتحها — أو تردّ ٤٠٣ صريحةً.
     *
     * والفرقُ بينهما هو المقصود: ٤٠٣ بابٌ مُقفَل يعرف قافلَه، و٥٠٠ عطبٌ.
     */
    public function test_no_screen_in_the_section_breaks_for_anyone(): void
    {
        $this->order();

        $actors = [
            'المالك' => $this->owner,
            'أمين المخزن' => User::create([
                'business_id' => $this->business->id, 'name' => 'أمين', 'email' => 'w@abaad.om',
                'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
            ]),
            'موظّفٌ بقائمةٍ يدوية' => User::create([
                'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'm@abaad.om',
                'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
                'permissions' => Permissions::withLegacyActions(['purchases']),
            ]),
        ];

        foreach ($actors as $who => $actor) {
            foreach (['admin.purchases.index', 'admin.purchases.orders', 'admin.purchases.invoices', 'admin.purchases.create'] as $name) {
                $status = $this->actingAs($actor)->get(route($name))->getStatusCode();

                $this->assertContains($status, [200, 302, 403], "{$who} على {$name} ردّ {$status}");
            }
        }
    }

    private function invoice(string $ref): SupplierInvoice
    {
        return SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => $ref,
            'issued_at' => now()->toDateString(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0,
            'status' => 'غير مدفوع', 'approval_status' => SupplierInvoices::PENDING,
        ]);
    }
}
