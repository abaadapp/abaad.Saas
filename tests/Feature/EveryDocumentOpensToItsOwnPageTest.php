<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السجلُّ يُفتح، والمستندُ يُقرأ، والورقةُ إلى جانبه — بلا طريقٍ مسدود.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * الأوراقُ كانت تُنشأ ولا تُقرأ. أمرُ الشراء له صفحةٌ وفيها زرٌّ يفتح PDF في
 * لسانٍ آخر — فيخرج المراجعُ من اللوحة ليرى ورقتَه. وسندُ الاستلام **لا
 * صفحةَ له أصلًا**: رقمُه في شاشة الأمر يقود إلى ملفٍّ، و«مراجعة» في قائمته
 * تفتح نافذةً تقول نصفَ ما فيه. وفاتورةُ العميل مثلُهما.
 *
 * فصار لكلٍّ صفحةٌ تحمل ورقتَه مرسومةً إلى جانب تفاصيله، ورقمٌ في كلّ قائمةٍ
 * يفتح صاحبَه.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 *  ١. أنّ لكلّ مستندٍ صفحةً تُفتح، وأنّها تحمل ورقتَه مرسومةً.
 *  ٢. أنّ الورقةَ المعروضة هي المطبوعة — لا نسخةٌ ثانية.
 *  ٣. أنّ رقمًا مُخمَّنًا في العنوان لا يفتح ورقةَ متجرٍ آخر.
 *  ٤. أنّ القراءةَ نفسَها فعلٌ يُمنح.
 */
class EveryDocumentOpensToItsOwnPageTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ————————————————— أمرُ الشراء ————————————————— */

    /** صفحةُ الأمر تحمل ورقتَه مرسومةً — لا رابطًا يخرج من اللوحة */
    public function test_the_purchase_order_page_carries_its_drawn_paper(): void
    {
        $po = $this->purchaseOrder();

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.show', $po->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Purchases/Show')
                ->where('paper.size', 'A4')
                ->where('paper.html', fn (string $html) => str_contains($html, 'PO-000001')
                    && str_contains($html, 'باقة ورد'))
                ->etc());
    }

    /* ————————————————— سندُ الاستلام ————————————————— */

    /** وللسند صفحةٌ تُفتح — وكانت نافذةً فوق القائمة */
    public function test_the_goods_receipt_has_a_page_of_its_own(): void
    {
        $note = $this->receipt();

        $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts.show', $note->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Inventory/ReceiptShow')
                ->where('note.number', 'GRN-000001')
                ->where('paper.size', 'A4')
                ->where('paper.html', fn (string $html) => str_contains($html, 'GRN-000001'))
                ->etc());
    }

    /** ومنه طريقٌ يعود إلى أمره — لا رقمٌ يُقرأ ولا يُفتح */
    public function test_the_goods_receipt_names_the_order_it_came_from(): void
    {
        $note = $this->receipt();

        $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts.show', $note->id))
            ->assertInertia(fn ($p) => $p
                ->where('order.id', $note->purchase_order_id)
                ->where('order.number', 'PO-000001')
                ->etc());
    }

    /** وقائمةُ السندات تحمل مفتاحَ الأمر — والرقمُ وحده لا يفتح شيئًا */
    public function test_the_receipts_list_carries_the_key_that_opens_the_order(): void
    {
        $note = $this->receipt();

        $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts'))
            ->assertInertia(fn ($p) => $p
                ->where('notes.0.order', 'PO-000001')
                ->where('notes.0.order_id', $note->purchase_order_id)
                ->etc());
    }

    /* ————————————————— فاتورةُ العميل ————————————————— */

    /** وفاتورةُ العميل مثلُهما — والورقةُ إلى جانبها */
    public function test_the_customer_invoice_page_carries_its_drawn_paper(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/CustomerInvoices/Show')
                ->where('paper.size', 'A4')
                ->where('paper.html', fn (string $html) => str_contains($html, 'CI-000001'))
                ->etc());
    }

    /* ————————————————— عزلُ المتاجر ————————————————— */

    /**
     * ورقمٌ مُخمَّن في العنوان لا يفتح ورقةَ جار.
     *
     * وسندُ الاستلام أثمنُ ما في الباب: فيه تكلفةُ كلّ صنفٍ اشتراه المتجر
     * — أثمنُ ما عند منافسه. والقيدُ في الاستعلام لا في الشاشة.
     */
    public function test_a_guessed_id_does_not_open_a_neighbours_document(): void
    {
        $note = $this->receipt();
        $po = $note->purchaseOrder;
        $invoice = $this->invoice();

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'بقالة', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'n@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)
            ->get(route('admin.inventory.receipts.show', $note->id))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('admin.purchases.show', $po->id))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('admin.customerInvoices.show', $invoice->id))->assertNotFound();
    }

    /**
     * والقراءةُ نفسُها فعلٌ يُمنح.
     *
     * قسمُ «المخزون» يُمنح لمن يعدّ الرفوف، لا لمن يقرأ بكم اشتُريت.
     */
    public function test_reading_a_receipt_is_an_action_that_is_granted(): void
    {
        $note = $this->receipt();

        $this->actingAs($this->staff(['inventory']))
            ->get(route('admin.inventory.receipts.show', $note->id))->assertForbidden();

        $this->actingAs($this->staff(['inventory', Permissions::RECEIPT_VIEW]))
            ->get(route('admin.inventory.receipts.show', $note->id))->assertOk();
    }

    /** موظّفٌ بما يملكه وحده — كما تبنيه بقيّة حرّاس الصلاحيات */
    private function staff(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'موظّف', 'email' => 'e'.random_int(1000, 999999).'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    /* ————————————————— الورقةُ هي المطبوعة ————————————————— */

    /**
     * والمعروضُ في الصفحة هو المطبوعُ من الباب — لا نسخةٌ ثانية.
     *
     * نسختان تفترقان يومًا، فيرى التاجرُ في اللوحة غيرَ ما يقرؤه مورّدُه في
     * يده. والقياسُ على ما لا يُصادَف: رقمُ المستند واسمُ الصنف معًا في
     * المرسوم وفي المطبوع.
     */
    public function test_what_the_page_shows_is_what_the_door_prints(): void
    {
        $po = $this->purchaseOrder();

        $shown = '';
        $this->actingAs($this->owner)
            ->get(route('admin.purchases.show', $po->id))
            ->assertInertia(function ($p) use (&$shown) {
                $shown = $p->toArray()['props']['paper']['html'];

                return $p->etc();
            });

        $printed = $this->actingAs($this->owner)
            ->get(route('admin.purchases.pdf', $po->id));

        $printed->assertOk();

        // والورقةُ المرسومة تحمل ما تحمله المطبوعة من تعريفٍ لا يُصادَف
        $this->assertStringContainsString('PO-000001', $shown);
        $this->assertStringContainsString('باقة ورد', $shown);
        $this->assertStringContainsString('مشتل الربيع', $shown);
    }

    /* ————————————————— البناء ————————————————— */

    private function purchaseOrder(): PurchaseOrder
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الربيع']);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'number' => 'PO-000001', 'status' => 'مسودة',
            'items_subtotal' => 12.0, 'total' => 12.0, 'ordered_at' => now(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'name' => 'باقة ورد',
            'quantity' => 3, 'cost' => 4.0, 'line_total' => 12.0,
        ]);

        return $po->load('items', 'supplier');
    }

    private function receipt(): GoodsReceiptNote
    {
        $po = $this->purchaseOrder();

        $note = GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $po->supplier_id, 'purchase_order_id' => $po->id,
            'number' => 'GRN-000001', 'received_at' => now()->toDateString(),
            'receiver' => 'سالم', 'status' => 'بانتظار الاعتماد',
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'name' => 'باقة ورد',
            'quantity' => 3, 'cost' => 4.0,
        ]);

        return $note->load('items', 'purchaseOrder');
    }

    private function invoice(): CustomerInvoice
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركة الواحة', 'phone' => '91234567',
        ]);

        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'number' => 'CI-000001', 'status' => 'صادرة',
            'issued_at' => now(), 'due_at' => now()->addDays(30),
            'subtotal' => 10.0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 10.0,
        ]);
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => 'تنسيق قاعة',
            'quantity' => 1, 'unit_price' => 10.0, 'discount' => 0, 'tax_rate' => 0, 'line_total' => 10.0,
        ]);

        return $invoice->load('items');
    }
}
