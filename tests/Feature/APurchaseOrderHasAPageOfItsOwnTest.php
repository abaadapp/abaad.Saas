<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\PurchaseOrders;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * لأمر الشراء صفحةٌ تقول قصّته.
 *
 * القائمةُ تقول «مستلم جزئيًا» ولا تقول أيُّ صنفٍ بقي، ولا مَن وقّع على ما
 * دخل الرفّ، ولا أيَّ سندٍ حُرّر عليه. وثلاثةُ مستنداتٍ تشير إلى أمرٍ واحد
 * كانت تُقرأ في ثلاث شاشاتٍ لا يجمعها رابط.
 *
 * وهذه الصفحةُ قراءةٌ محضة: لا تكتب صفًّا ولا تحرّك رفًّا. فما يُسأل عنه
 * هنا شيئان — أنّها تقول الحقيقة كما هي في الصفوف، وأنّ مقابضها لا تُرسم
 * إلّا على ما يقبله الخادم.
 */
class APurchaseOrderHasAPageOfItsOwnTest extends TestCase
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

    private function order(int $qty = 10, float $cost = 9, array $extra = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => PurchaseOrders::nextNumber($this->business->id),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => PurchaseOrders::SENT,
            'items_subtotal' => $qty * $cost, 'total' => $qty * $cost,
            'ordered_at' => now(),
        ], $extra));

        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'purchase_unit' => 'صندوق', 'units_per_purchase_unit' => 12,
            'cost' => $cost, 'quantity' => $qty, 'line_total' => $qty * $cost,
        ]);

        return $po->refresh();
    }

    /* ==================== الصفحةُ تُفتح وتقول ما فيها ==================== */

    /** تُفتح الصفحة، وتحمل الأمرَ وبنودَه وإجماليّاته */
    public function test_the_page_opens_and_carries_the_order(): void
    {
        $po = $this->order(10, 9, [
            'supplier_discount' => 5, 'shipping_cost' => 3, 'tax' => 0, 'tax_rate' => 0,
            'supplier_reference' => 'REF-77', 'expected_delivery_at' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Purchases/Show')
                ->where('order.number', $po->number)
                ->where('order.supplier', 'ورد الخليج')
                ->where('order.branch', 'الرئيسي')
                ->where('order.supplier_reference', 'REF-77')
                ->where('order.items_subtotal', fn ($x) => (float) $x === 90.0)
                ->where('order.supplier_discount', fn ($x) => (float) $x === 5.0)
                ->where('order.shipping_cost', fn ($x) => (float) $x === 3.0)
                ->has('order.items', 1)
                ->where('order.items.0.name', 'باقة ورد')
                ->where('order.items.0.quantity', 10)
                ->where('order.items.0.remaining', 10)
                ->where('order.items.0.line_total', fn ($x) => (float) $x === 90.0)
                ->etc());
    }

    /**
     * والكميّةُ بوحدة الشراء، ومحتواها معها.
     *
     * «عشرةُ صناديق» لا تقول كم يدخل الرفّ — و`base_quantity` هي ما يراه من
     * يجرد المخزون. وحسابُها في الواجهة كان يعني صيغتين تفترقان يومًا.
     */
    public function test_the_line_says_its_purchase_unit_and_what_it_holds(): void
    {
        $po = $this->order(10);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('order.items.0.purchase_unit', 'صندوق')
                ->where('order.items.0.units_per_purchase_unit', fn ($x) => (float) $x === 12.0)
                ->where('order.items.0.base_quantity', fn ($x) => (float) $x === 120.0)
                ->etc());
    }

    /**
     * وأمرٌ لم يقع منه شيء: خطُّه حدثٌ واحد، ولا أوراقَ ولا سندات.
     *
     * والصفحةُ لا تخترع أحداثًا لتملأ فراغًا — «أُنشئ» ختمُ وقتٍ مكتوب،
     * و«أُرسل إلى المورّد» ليس له عمودٌ فلا يُقال.
     */
    public function test_an_untouched_order_has_one_event_and_no_papers(): void
    {
        $po = $this->order();

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->has('timeline', 1)
                ->where('timeline.0.kind', 'created')
                ->has('receipts', 0)
                ->has('invoices', 0)
                ->etc());
    }

    /** والمسودّةُ تُقال مسودّةً في خطّها */
    public function test_a_draft_says_so_in_its_timeline(): void
    {
        $po = $this->order(10, 9, ['status' => PurchaseOrders::DRAFT]);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('timeline.0.kind', 'draft')->etc());
    }

    /* ==================== الخطُّ الزمنيّ ==================== */

    /**
     * ورقةُ استلامٍ تُسجَّل ثمّ تُعتمد — حدثان بترتيبهما، ولكلٍّ من وقّعه.
     *
     * والاسمُ مقروءٌ من `users`: «اعتُمد» بلا من اعتمد نصفُ خبر.
     */
    public function test_a_receipt_writes_two_events_with_their_signatories(): void
    {
        $po = $this->order(10);
        $note = GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);
        GoodsReceipts::approve($note, $this->owner);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->has('timeline', 3)
                ->where('timeline.1.kind', 'receipt')
                ->where('timeline.1.actor', 'المالك')
                ->where('timeline.2.kind', 'approved')
                ->where('timeline.2.actor', 'المالك')
                ->has('receipts', 1)
                ->where('receipts.0.number', $note->number)
                ->where('receipts.0.status', GoodsReceipts::APPROVED)
                ->etc());
    }

    /** والمرفوضُ يقول سببَه — لا «رُفض» وحدها */
    public function test_a_rejected_receipt_carries_its_reason(): void
    {
        $po = $this->order(10);
        $note = GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);
        GoodsReceipts::reject($note, 'الشحنة تالفة', $this->owner);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('timeline.2.kind', 'rejected')
                ->where('timeline.2.detail', 'الشحنة تالفة')
                ->where('receipts.0.rejection_reason', 'الشحنة تالفة')
                ->etc());
    }

    /**
     * وسندٌ اعتُمد ثمّ أُلغي يُقال «أُلغي» لا «رُفض».
     *
     * الإلغاءُ يُكتب في عمود الرفض نفسِه — والحالةُ وحدها تفرّق. ولولا
     * قراءتُها لَقال الخطُّ عن سندٍ قُيّد في الدفتر ثمّ عُكس قيدُه إنّه
     * رُفض، وهما واقعتان مختلفتان.
     */
    public function test_a_cancelled_invoice_is_not_called_rejected(): void
    {
        $po = $this->order(10);
        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'supplier_ref' => 'S-9001',
            'issued_at' => now()->toDateString(),
            'subtotal' => 90, 'tax' => 0, 'total' => 90, 'paid' => 0,
            'status' => 'غير مدفوع', 'approval_status' => SupplierInvoices::PENDING,
        ]);

        SupplierInvoices::approve($invoice, $this->owner, 'اعتمادُ اختبار');
        SupplierInvoices::cancel($invoice->fresh(), 'أُلغيت الشحنة', $this->owner);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('timeline.3.kind', 'cancelled')
                ->where('timeline.3.detail', 'أُلغيت الشحنة')
                ->where('invoices.0.approval_status', SupplierInvoices::CANCELLED)
                ->etc());
    }

    /* ==================== ما ينتظر الاعتماد ==================== */

    /**
     * وورقةٌ سُجّلت ولم تُعتمد تُنقص المتاح — لا المتبقّي.
     *
     * `received_quantity` لا يتحرّك إلّا بالاعتماد، فمن يقرأ المتبقّي وحده
     * يسجّل الشحنةَ نفسَها مرّتين — وتدخل الرفَّ مرّتين حين تُعتمد الورقتان.
     */
    public function test_what_waits_for_approval_is_told_apart_from_what_remains(): void
    {
        $po = $this->order(10);
        GoodsReceipts::record($po, [$po->items()->first()->id => 4], [], $this->owner);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('order.items.0.received', 0)
                ->where('order.items.0.pending', fn ($x) => (float) $x === 4.0)
                ->where('order.items.0.remaining', 10)
                ->etc());
    }

    /* ==================== المقابض ==================== */

    /** ومقبضُ الحذف لا يُرسم على أمرٍ استُلمت بضاعتُه — كما يردّه الخادم */
    public function test_delete_is_not_offered_where_the_server_refuses_it(): void
    {
        $po = $this->order(10);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('can.delete', true)->etc());

        GoodsReceipts::record($po, [$po->items()->first()->id => 4], [], $this->owner);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('can.delete', false)->etc());

        // والخادمُ يقول القولَ نفسَه
        $this->actingAs($this->owner)->delete(route('admin.purchases.destroy', $po->id));
        $this->assertNotNull(PurchaseOrder::find($po->id));
    }

    /** ومن لا يملك تسجيل الاستلام لا يُعرض عليه مقبضُه */
    public function test_receiving_is_not_offered_to_who_may_not_receive(): void
    {
        $po = $this->order(10);

        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'كاتب', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases'],
        ]);

        $this->actingAs($clerk)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('can.receive', false)->etc());

        $receiver = User::create([
            'business_id' => $this->business->id, 'name' => 'مستلِم', 'email' => 'r@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases', Permissions::RECEIPT_CREATE],
        ]);

        $this->actingAs($receiver)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('can.receive', true)->etc());
    }

    /** وأمرٌ اكتمل استلامُه لا يُعرض عليه مقبضُ استلام */
    public function test_a_fully_received_order_is_not_offered_receiving(): void
    {
        $po = $this->order(10, 9, ['status' => PurchaseOrders::RECEIVED]);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p->where('can.receive', false)->etc());
    }

    /* ==================== المرفق ==================== */

    /**
     * ومرفقٌ لا يُقرأ يُقال موجودًا ولا يُبنى له رابط.
     *
     * وصمتُ الشاشة عنه يجعل من لا يملك فتحَ المرفقات يظنّ الأمرَ بلا مستند —
     * وهي الحفرةُ نفسُها التي وقع فيها إيصالُ أمر الشراء.
     */
    public function test_an_attachment_you_may_not_read_is_not_offered_as_absent(): void
    {
        $po = $this->order();
        $po->update(['attachment' => 'purchase-orders/1/a.pdf', 'attachment_name' => 'عرض سعر.pdf']);

        $blind = User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => 'b@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases'],
        ]);

        $this->actingAs($blind)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('order.attachment', null)
                ->where('order.has_attachment', true)
                ->etc());

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))
            ->assertInertia(fn ($p) => $p
                ->where('order.attachment', route('admin.purchases.attachment', $po->id))
                ->where('order.has_attachment', true)
                ->etc());
    }

    /* ==================== الأبوابُ والحدود ==================== */

    /** وأمرُ الجار لا يُفتح برقمٍ يُكتب في العنوان */
    public function test_a_neighbours_order_does_not_open(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirs = PurchaseOrder::create([
            'business_id' => $other->id, 'number' => 'PO-000999',
            'supplier_id' => $theirSupplier->id, 'supplier_name' => 'مورّدهم',
            'status' => PurchaseOrders::SENT, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $theirs->id))->assertNotFound();
    }

    /**
     * والأبوابُ المسمّاة تبقى مفتوحةً بجوار `{id}`.
     *
     * وليس هذا حارسَ ترتيب: جرّبتُ نقلَ `{id}` فوق `/purchases/orders` فبقيت
     * القائمةُ تُفتح — المُطابِقُ يقدّم الثابتَ على المتغيّر. فيُقال الحارسُ
     * بما يحرسه: أنّ المسارَ الجديد لم يُطفئ الثلاثةَ القائمة.
     */
    public function test_the_named_doors_stay_open_beside_the_id_route(): void
    {
        $this->order();

        $this->actingAs($this->owner)->get(route('admin.purchases.orders'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Purchases/Index')->etc());

        $this->actingAs($this->owner)->get(route('admin.purchases.create'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.purchases.invoices'))->assertOk();
    }

    /**
     * ومعرّفٌ ليس رقمًا بابٌ مغلق لا خطأُ خادم.
     *
     * ولا يُنسب هذا إلى `whereNumber`: نزعتُه فبقي السلوك — على المحرّكين
     * معًا. فالمُثبَتُ هنا ما تراه العين لا السطرُ الذي يظنّ أنّه يفعله.
     */
    public function test_a_non_numeric_id_is_a_closed_door_not_a_crash(): void
    {
        $this->actingAs($this->owner)->get('/admin/purchases/abc')->assertNotFound();
    }

    /**
     * وحذفٌ من صفحة الأمر يعود إلى القائمة لا إلى صفحةٍ محاها.
     *
     * `back()` كان يردّ الحاذفَ إلى حيث ضغط — فيهبط في `404` على أمرٍ حذفه هو.
     */
    public function test_deleting_lands_on_the_list_not_on_the_grave(): void
    {
        $po = $this->order();

        $this->actingAs($this->owner)
            ->from(route('admin.purchases.show', $po->id))
            ->delete(route('admin.purchases.destroy', $po->id))
            ->assertRedirect(route('admin.purchases.orders'));

        $this->assertNull(PurchaseOrder::find($po->id));
    }

    /**
     * وشاشةُ إشعارات الاستلام تقول تاريخَ الاعتماد — وكانت تكتمه.
     *
     * `approved_at` لم يكن مصبوبًا، و`optional()` على نصٍّ تردّ `null` بلا
     * أن ترمي — فكان العمودُ فارغًا على كلّ ورقةٍ معتمَدة منذ كُتبت الشاشة،
     * ولا خطأ يقول لماذا. وهذا الحارسُ هنا لا هناك لأنّه ظهر من بناء هذه
     * الصفحة: الخطُّ الزمنيّ أوّلُ من طلب الختمَ كائنًا.
     */
    public function test_the_receipts_screen_says_when_it_was_approved(): void
    {
        $po = $this->order(10);
        $note = GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);
        GoodsReceipts::approve($note, $this->owner);

        $this->actingAs($this->owner)->get(route('admin.inventory.receipts'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('notes.0.number', $note->number)
                ->where('notes.0.approved_at', now()->format('Y-m-d'))
                ->etc());
    }

    /**
     * ورقمُ الأمر في القائمة بابٌ إلى صفحته.
     *
     * حارسٌ يقرأ مصدرًا — ووجودُ رابطٍ في عمودِ جدولٍ تركيبُ صفحةٍ لا سلوكُ
     * مكوّن. ويُقال إنّه ضعيف.
     */
    public function test_the_number_in_the_list_is_a_door(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Purchases/Index.tsx'));

        $this->assertStringContainsString('routeName="admin.purchases.show"', $screen);
        $this->assertStringContainsString("route('admin.purchases.show', o.id)", $screen);
    }
}
