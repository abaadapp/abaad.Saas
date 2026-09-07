<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البضاعةُ لا تصل الرفَّ قبل أن يراها من يملكه.
 *
 * كان الاستلامُ يزيد المخزونَ لحظةَ كتابته — من يكتب هو من يعتمد. وفي
 * متجرٍ فيه موظّفان هذا بابٌ مفتوح: كميّةٌ تُكتب أكبر ممّا وصل فتدخل الرفَّ
 * ورقيًّا ولا توجد فيه، ومتوسّطُ التكلفة يُرجَّح بها فيُفسد تسعيرَ كلّ
 * بيعةٍ بعدها. ولا يكشفه إلّا الجرد، بعد شهر.
 */
class GoodsDoNotReachTheShelfUnseenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $clerk;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        // أمينُ مخزنٍ يملك المشتريات ولا يملك الاعتماد
        $this->clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'k@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['purchases', 'inventory'],
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 10,
            'cost' => 4, 'quantity' => 100,
        ]);
    }

    private function order(int $qty = 100, float $cost = 9): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-000125', 'status' => 'مُرسل',
            'total' => $qty * $cost, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'cost' => $cost, 'quantity' => $qty,
        ]);

        return $po->refresh();
    }

    /* ------------------------- الكتابةُ لا تمسّ رفًّا ------------------------- */

    /**
     * ما كُتب ولم يُعتمد لا يوجد في المخزون.
     *
     * ولا في `received_quantity` ولا في حالة الأمر: ثلاثةُ مواضع كانت تتحرّك
     * لحظةَ الكتابة، وكلُّها تنتظر الآن.
     */
    public function test_recording_a_receipt_touches_neither_stock_nor_the_order(): void
    {
        $po = $this->order();

        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.receive', $po->id))
            ->assertSessionHasNoErrors();

        $note = GoodsReceiptNote::firstOrFail();

        $this->assertSame(GoodsReceipts::PENDING, $note->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'دخلت البضاعة قبل الاعتماد');
        $this->assertSame(4.0, (float) $this->product->fresh()->cost, 'تحرّك متوسّط التكلفة قبل الاعتماد');
        $this->assertSame(0, (int) $po->items()->first()->received_quantity);
        $this->assertSame('مُرسل', $po->fresh()->status);
        $this->assertSame(0, InventoryMovement::where('product_id', $this->product->id)->count());
    }

    /** والورقةُ تحمل من كتبها — لا اسمًا حرًّا وحده */
    public function test_the_paper_carries_who_wrote_it(): void
    {
        $po = $this->order();

        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame($this->clerk->id, (int) GoodsReceiptNote::firstOrFail()->submitted_by);
    }

    /* ------------------------- والاعتمادُ يمسّ ------------------------- */

    /** الاعتمادُ يُدخل الكمية، ويرجّح التكلفة، ويُقفل الأمر */
    public function test_approval_moves_the_stock_the_cost_and_the_order(): void
    {
        $po = $this->order(qty: 100, cost: 9);

        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.approve', $note->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::APPROVED, $note->fresh()->status);
        $this->assertSame($this->owner->id, (int) $note->fresh()->approved_by);
        $this->assertNotNull($note->fresh()->approved_at);

        $this->assertSame(200, (int) $this->product->fresh()->quantity);
        // (100×4 + 100×9) ÷ 200 = 6.5
        $this->assertSame(6.5, (float) $this->product->fresh()->cost);
        $this->assertSame(100, (int) $po->items()->first()->received_quantity);
        $this->assertSame('مستلم', $po->fresh()->status);
        $this->assertSame(1, InventoryMovement::where('product_id', $this->product->id)->count());
    }

    /**
     * واعتمادان لا يُدخلان الشحنة مرّتين.
     *
     * مديران يضغطان «اعتماد» حين يبطؤ الردّ — الثاني يجدها معتمدةً فيخرج.
     */
    public function test_two_approvals_do_not_double_the_shipment(): void
    {
        $po = $this->order(qty: 100, cost: 9);
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.approve', $note->id));
        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.approve', $note->id));

        $this->assertSame(200, (int) $this->product->fresh()->quantity);
        $this->assertSame(100, (int) $po->items()->first()->received_quantity);
        $this->assertSame(1, InventoryMovement::where('product_id', $this->product->id)->count());
    }

    /* --------------------------- والرفضُ لا يمسّ --------------------------- */

    /** الرفضُ يُوسم بسببٍ مكتوب، ولا يتحرّك به شيء، ولا تُمحى الورقة */
    public function test_rejection_moves_nothing_and_deletes_nothing(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'الشحنة تالفة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
        $this->assertSame('الشحنة تالفة', $note->fresh()->rejection_reason);
        $this->assertSame($this->owner->id, (int) $note->fresh()->rejected_by);

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, (int) $po->items()->first()->received_quantity);
        $this->assertSame('مُرسل', $po->fresh()->status);
        $this->assertDatabaseHas('goods_receipt_notes', ['id' => $note->id]);
    }

    /** ولا رفضَ بلا سبب */
    public function test_rejection_needs_a_written_reason(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.reject', GoodsReceiptNote::firstOrFail()->id), [])
            ->assertSessionHasErrors('reason');
    }

    /** وما اعتُمد لا يُرفض بعدها: بضاعتُه على الرفّ */
    public function test_an_approved_receipt_cannot_be_rejected(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.approve', $note->id));

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'تراجعت']);

        $this->assertSame(GoodsReceipts::APPROVED, $note->fresh()->status);
    }

    /** والمرفوضُ لا يُعتمد بعد رفضه */
    public function test_a_rejected_receipt_cannot_be_approved(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'تالفة']);

        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.approve', $note->id));

        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
    }

    /* ---------------------------- الصلاحية ---------------------------- */

    /**
     * من يكتب ليس من يعتمد.
     *
     * أمينُ المخزن يملك «المشتريات» فيكتب ما وصله — والاعتمادُ فعلٌ مستقلّ
     * لا يُمنح مع القسم.
     */
    public function test_the_one_who_writes_cannot_approve(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $note = GoodsReceiptNote::firstOrFail();

        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.receipts.approve', $note->id))->assertForbidden();
        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
    }

    /** ومن مُنح الفعل باسمه يعتمد وإن لم يكن مالكًا */
    public function test_a_clerk_granted_the_action_may_approve(): void
    {
        $this->clerk->update(['permissions' => ['purchases', 'inventory', Permissions::RECEIPT_APPROVE]]);
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->actingAs($this->clerk)
            ->post(route('admin.purchases.receipts.approve', GoodsReceiptNote::firstOrFail()->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(200, (int) $this->product->fresh()->quantity);
    }

    /* ------------------------- الطابور والاستلام الجزئي ------------------------- */

    /**
     * ورقتان معلّقتان لا تتجاوزان المطلوب.
     *
     * `remaining` لا يتحرّك إلّا بالاعتماد — فورقتان تقرآن المتبقّي نفسَه.
     * فيُحسب المتاحُ ناقصًا ما هو معلّقٌ في أوراقٍ أخرى.
     */
    public function test_two_pending_papers_cannot_together_exceed_the_order(): void
    {
        $po = $this->order(qty: 100);
        $line = $po->items()->first();

        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 60]]])->assertSessionHasNoErrors();

        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 60]]])->assertSessionHasErrors('receive');

        $this->assertSame(1, GoodsReceiptNote::count());
    }

    /** والاستلامُ الجزئيّ يتراكم باعتماداتٍ متتالية حتى يُقفل الأمر */
    public function test_partial_receipts_accumulate_until_the_order_closes(): void
    {
        $po = $this->order(qty: 100);
        $line = $po->items()->first();

        foreach ([40, 30, 30] as $qty) {
            $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id),
                ['items' => [['id' => $line->id, 'quantity' => $qty]]])->assertSessionHasNoErrors();
            $this->approvePendingReceipts($this->business->id, $this->owner);
        }

        $this->assertSame(100, (int) $line->fresh()->received_quantity);
        $this->assertSame('مستلم', $po->fresh()->status);
        $this->assertSame(200, (int) $this->product->fresh()->quantity);
    }

    /** والمرفوضُ لا يزيد المستلَم — فيبقى في الأمر متّسع */
    public function test_a_rejected_receipt_leaves_room_in_the_order(): void
    {
        $po = $this->order(qty: 100);
        $line = $po->items()->first();

        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 100]]]);
        $this->actingAs($this->owner)->post(
            route('admin.purchases.receipts.reject', GoodsReceiptNote::firstOrFail()->id),
            ['reason' => 'تالفة'],
        );

        // ولأنّ المرفوض لا يحجز شيئًا، تُكتب ورقةٌ ثانيةٌ بالكمية كلّها
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 100]]])->assertSessionHasNoErrors();

        $this->approvePendingReceipts($this->business->id, $this->owner);

        $this->assertSame(100, (int) $line->fresh()->received_quantity);
        $this->assertSame(200, (int) $this->product->fresh()->quantity);
    }

    /**
     * والاعتمادُ يفحص المتبقّي بنفسه — لا يثق بما فحصته الكتابة.
     *
     * الكتابةُ تحجز المعلَّق، فلا يقع التجاوزُ من بابها اليوم. لكنّ الحجز
     * نصيحةٌ لا حارس: صفٌّ كُتب قبل هذا الإصدار، أو استُعيد من نسخة، أو
     * كتبه بابٌ ثانٍ يُضاف غدًا — يصل الاعتمادَ بكميّةٍ لم يعد لها موضع.
     *
     * ونقطةُ الأثر هي التي تحرس نفسها: هنا يدخل الرفَّ ما يدخل، وهنا يجب
     * أن يُقاس. فالحالةُ تُصنع صنعًا لأنّ الطريق إليها أُغلق — والحارسُ
     * يبقى لأنّ إغلاقَ الطريق ليس ضمانًا لأنّه لن يُفتح.
     */
    public function test_approval_measures_the_remainder_itself(): void
    {
        $po = $this->order(qty: 100);
        $line = $po->items()->first();

        // ورقةٌ بالمئة كلّها، تُعتمد فيمتلئ الأمر
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));
        $this->actingAs($this->owner)->post(route('admin.purchases.receipts.approve', GoodsReceiptNote::firstOrFail()->id));
        $this->assertSame(100, (int) $line->fresh()->received_quantity);

        // وورقةٌ ثانيةٌ تصل الاعتمادَ بعشرين لا موضعَ لها — تُكتب صنعًا
        $stray = GoodsReceiptNote::create([
            'business_id' => $this->business->id,
            'branch_id' => $po->branch_id,
            'purchase_order_id' => $po->id,
            'number' => 'GRN-999999',
            'status' => GoodsReceipts::PENDING,
            'received_at' => now()->toDateString(),
        ]);
        $stray->items()->create([
            'purchase_order_item_id' => $line->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 20, 'cost' => 9,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.approve', $stray->id))
            ->assertSessionHasErrors('receive');

        $this->assertSame(GoodsReceipts::PENDING, $stray->fresh()->status);
        $this->assertSame(100, (int) $line->fresh()->received_quantity, 'تجاوز المستلَمُ المطلوب');
        $this->assertSame(200, (int) $this->product->fresh()->quantity, 'دخل الرفَّ ما لم يُطلب');
    }

    /* ---------------------------- الشاشة ---------------------------- */

    /** والطابورُ يُعرض ويُعدّ */
    public function test_the_queue_is_listed_and_counted(): void
    {
        $po = $this->order();
        $this->actingAs($this->clerk)->post(route('admin.purchases.receive', $po->id));

        $this->actingAs($this->owner)->get(route('admin.inventory.receipts'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Inventory/Receipts')
                ->where('pendingCount', 1)
                ->where('canApprove', true)
                ->where('notes.0.status', GoodsReceipts::PENDING)
                ->where('notes.0.items.0.ordered', 100)
                ->where('notes.0.items.0.remaining', 100));

        // ومن لا يملك الفعل لا يُرسم له زرُّه
        $this->actingAs($this->clerk)->get(route('admin.inventory.receipts'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('canApprove', false));
    }

    /** وورقةُ متجرٍ آخر لا تُعتمد من هنا */
    public function test_a_neighbours_receipt_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = GoodsReceiptNote::create([
            'business_id' => $other->id, 'number' => 'GRN-000001',
            'status' => GoodsReceipts::PENDING, 'received_at' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.approve', $theirs->id))->assertNotFound();

        $this->assertSame(GoodsReceipts::PENDING, $theirs->fresh()->status);
    }
}
