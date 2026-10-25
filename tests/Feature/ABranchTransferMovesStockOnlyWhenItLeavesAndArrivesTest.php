<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchOperationEvent;
use App\Models\BranchStock;
use App\Models\BranchTransfer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\BranchTransfers;
use App\Support\StockLosses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EnterpriseShop;
use Tests\TestCase;

/**
 * سندُ النقل يحرّك الرفوف عند الإرسال والاستلام وحدهما — ولا قطعةَ تُخلق أو تضيع.
 *
 *   الطلبُ والاعتمادُ والتحضير ← لا شيء على الرفوف
 *   الإرسال ← تخرج من رفّ المصدر ومن الإجماليّ، وتصير قيدَ النقل
 *   الاستلام ← تدخل رفَّ المستلم والإجماليّ؛ وما نقص يبقى فرقًا معلّقًا
 *   التسوية ← كلُّ قطعةٍ معلّقة إلى بابٍ موثَّق
 *
 * وفي كلّ لحظة: ما على الرفوف + ما قيدَ النقل + ما فُقد = ما كان.
 */
class ABranchTransferMovesStockOnlyWhenItLeavesAndArrivesTest extends TestCase
{
    use EnterpriseShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->makeEnterpriseShop();
    }

    private function request(array $items, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->muscatManager)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id,
            'to_branch_id' => $this->salalah->id,
            'items' => $items,
        ] + $extra);
    }

    private function latest(): BranchTransfer
    {
        return BranchTransfer::latest('id')->firstOrFail();
    }

    private function step(string $name, BranchTransfer $t, User $as, array $data = [])
    {
        return $this->actingAs($as)->post(route('admin.inventory.transfers.'.$name, $t->id), $data);
    }

    /** يُطلب ويُعتمد ويُجهَّز — فيبقى الإرسال */
    private function readyToSend(array $items): BranchTransfer
    {
        $this->request($items)->assertSessionHasNoErrors();
        $t = $this->latest();
        $this->step('approve', $t, $this->owner)->assertSessionHasNoErrors();
        $this->step('prepare', $t, $this->muscatManager)->assertSessionHasNoErrors();

        return $t->fresh();
    }

    public function test_one_document_carries_several_products_and_asking_moves_nothing(): void
    {
        $this->request([
            ['product_id' => $this->rose->id, 'quantity' => 10],
            ['product_id' => $this->wrap->id, 'quantity' => 5],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $t = $this->latest();
        $this->assertSame(BranchTransfer::PENDING, $t->status);
        $this->assertSame(2, $t->lines()->count());
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose), 'تحرّك المخزونُ بمجرّد الطلب');
        $this->assertSame([20, 20, 0, 0], $this->shelves($this->wrap));

        $this->step('approve', $t, $this->owner)->assertSessionHasNoErrors();
        $this->step('prepare', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose), 'تحرّك المخزونُ بالاعتماد أو التحضير');
        $this->assertSame(0, InventoryMovement::where('reference', $t->number)->count());
    }

    public function test_dispatch_takes_from_the_source_and_holds_it_in_transit_then_receipt_shelves_it(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 10], ['product_id' => $this->wrap->id, 'quantity' => 5]]);

        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::IN_TRANSIT, $t->fresh()->status);
        $this->assertSame([44, 40, 4, 0], $this->shelves($this->rose), 'لم تخرج الكمية من المصدر — أو دخلت المستلم قبل استلامها');
        $this->assertSame([15, 15, 0, 0], $this->shelves($this->wrap));
        $this->assertBalanced($this->rose);

        $moving = BranchTransfers::inTransit($this->shop->id);
        $this->assertSame(15, $moving['quantity'], 'ما قيدَ النقل لا يساوي ما خرج');
        $this->assertEqualsWithDelta(10 * 1.5 + 5 * 0.25, $moving['value'], 0.001);
        // الرفوف + قيد النقل = ما كان
        $this->assertSame(54, (int) $this->rose->fresh()->quantity + $moving['products'][$this->rose->id]);

        $lines = $t->lines()->get();
        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => $lines->pluck('sent_qty', 'id')->all()])
            ->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::RECEIVED, $t->fresh()->status);
        $this->assertNotNull($t->fresh()->closed_at);
        $this->assertSame([54, 40, 14, 0], $this->shelves($this->rose));
        $this->assertSame([20, 15, 5, 0], $this->shelves($this->wrap));
        $this->assertBalanced($this->rose);
        $this->assertBalanced($this->wrap);
        $this->assertSame(0, BranchTransfers::inTransit($this->shop->id)['quantity']);
        $this->assertSame(4, InventoryMovement::where('reference', $t->number)->count(), 'حركتان لكلّ صنف: إرسالٌ واستلام');
    }

    public function test_the_source_sends_no_more_than_was_approved_and_no_more_than_its_shelf(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 10]]);
        $line = $t->lines()->first();

        $this->step('dispatch', $t, $this->muscatManager, ['quantities' => [$line->id => 11]])
            ->assertSessionHasErrors('transfer');
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);

        // بيعٌ أخذ من الرفّ بعد الاعتماد: لم يبقَ في مسقط إلّا ثمانٍ
        BranchStock::draw($this->muscat->id, $this->rose->id, 42);
        $this->rose->decrement('quantity', 42);

        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasErrors('transfer');
        $this->assertSame([12, 8, 4, 0], $this->shelves($this->rose), 'أُرسل ما ليس على الرفّ');
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);

        // يُرسل ما وجد — أقلّ من المعتمد
        $this->step('dispatch', $t, $this->muscatManager, ['quantities' => [$line->id => 8]])->assertSessionHasNoErrors();
        $this->assertSame([4, 0, 4, 0], $this->shelves($this->rose));
        $this->assertSame(8, (int) $line->fresh()->sent_qty);
    }

    public function test_a_short_line_rolls_the_whole_dispatch_back(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 10], ['product_id' => $this->wrap->id, 'quantity' => 15]]);
        // الورقُ نقص بعد الاعتماد — والوردُ وُجد كاملًا
        BranchStock::draw($this->muscat->id, $this->wrap->id, 10);
        $this->wrap->decrement('quantity', 10);

        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasErrors('transfer');

        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose), 'خرج الوردُ وحده من سندٍ رُدّ إرساله');
        $this->assertSame([10, 10, 0, 0], $this->shelves($this->wrap));
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);
        $this->assertNull($t->lines()->first()->sent_qty);
        $this->assertSame(0, InventoryMovement::where('reference', $t->number)->count());
    }

    public function test_dispatch_and_receipt_happen_once(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 10]]);
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasErrors('transfer');
        $this->assertSame([44, 40, 4, 0], $this->shelves($this->rose), 'أُرسل السندُ مرّتين');

        $line = $t->lines()->first();
        $this->step('receive', $t, $this->salalahManager, ['quantities' => [$line->id => 10]])->assertSessionHasNoErrors();
        $this->step('receive', $t, $this->salalahManager, ['quantities' => [$line->id => 10]])->assertSessionHasErrors('transfer');
        $this->assertSame([54, 40, 14, 0], $this->shelves($this->rose), 'استُلم السندُ مرّتين');
    }

    public function test_steps_cannot_be_skipped_or_reversed(): void
    {
        $this->request([['product_id' => $this->rose->id, 'quantity' => 3]])->assertSessionHasNoErrors();
        $t = $this->latest();
        $line = $t->lines()->first();

        $this->step('dispatch', $t, $this->owner)->assertSessionHasErrors('transfer');
        $this->step('prepare', $t, $this->owner)->assertSessionHasErrors('transfer');
        $this->step('receive', $t, $this->owner, ['quantities' => [$line->id => 3]])->assertSessionHasErrors('transfer');
        $this->assertSame(BranchTransfer::PENDING, $t->fresh()->status);

        $this->step('approve', $t, $this->owner)->assertSessionHasNoErrors();
        $this->step('approve', $t, $this->owner)->assertSessionHasErrors('transfer');
        // لا يُرسَل سندٌ لم يُجهَّز
        $this->step('dispatch', $t, $this->owner)->assertSessionHasErrors('transfer');

        $this->step('prepare', $t, $this->owner)->assertSessionHasNoErrors();
        $this->step('dispatch', $t, $this->owner)->assertSessionHasNoErrors();

        // بعد الإرسال لا إلغاءَ عاديّ — يُغلق بالاستلام أو بتسوية «أُعيد»
        $this->step('cancel', $t, $this->owner, ['reason' => 'تراجعنا'])->assertSessionHasErrors('transfer');
        $this->assertSame(BranchTransfer::IN_TRANSIT, $t->fresh()->status);
        $this->assertSame([51, 47, 4, 0], $this->shelves($this->rose));
    }

    public function test_a_request_is_cancelled_before_approval_and_an_approved_one_before_dispatch(): void
    {
        $this->request([['product_id' => $this->rose->id, 'quantity' => 3]])->assertSessionHasNoErrors();
        $mine = $this->latest();
        $this->step('cancel', $mine, $this->muscatManager, ['reason' => 'طلبتُه خطأً'])->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::CANCELLED, $mine->fresh()->status);

        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 3]]);
        // مديرُ الفرع لا يُلغي ما اعتمده غيرُه
        $this->step('cancel', $t, $this->muscatManager, ['reason' => 'لا نحتاجه'])->assertSessionHasErrors('transfer');
        $this->step('cancel', $t, $this->owner, ['reason' => 'لا نحتاجه'])->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::CANCELLED, $t->fresh()->status);
        $this->assertSame('لا نحتاجه', $t->fresh()->decision_reason);
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose));
    }

    public function test_a_rejection_keeps_its_reason_and_moves_nothing(): void
    {
        $this->request([['product_id' => $this->rose->id, 'quantity' => 30]])->assertSessionHasNoErrors();
        $t = $this->latest();

        $this->step('reject', $t, $this->owner, ['reason' => 'ك'])->assertSessionHasErrors('reason');
        $this->step('reject', $t, $this->owner, ['reason' => 'صلالة لا تحتاج ثلاثين'])->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::REJECTED, $t->fresh()->status);
        $this->assertSame('صلالة لا تحتاج ثلاثين', $t->fresh()->decision_reason);
        $this->assertSame(['requested', 'rejected'], $t->events()->pluck('action')->all());
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose));
    }

    public function test_a_shortfall_stays_in_transit_until_a_documented_resolution(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 20]]);
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $line = $t->lines()->first();

        // أُرسلت عشرون ووصلت ثماني عشرة
        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => [$line->id => 18], 'note' => 'كرتونٌ ممزّق'])
            ->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::DISCREPANCY, $t->fresh()->status);
        $this->assertNull($t->fresh()->closed_at, 'أُغلق سندٌ فيه فرقٌ لم يُسوَّ');
        $this->assertSame([52, 30, 22, 0], $this->shelves($this->rose), 'أُضيف الناقصُ للمستلم أو حُذف صامتًا');
        $this->assertSame(2, $line->fresh()->outstanding());
        $this->assertSame(2, BranchTransfers::inTransit($this->shop->id)['quantity'], 'سقطت القطعتان من المخزون بلا معالجة');

        // مديرُ الفرع لا يسوّي — التسويةُ فعلٌ يُمنح
        $this->step('resolve', $t, $this->salalahManager, ['line_id' => $line->id, 'kind' => 'lost', 'quantity' => 1, 'reason' => 'تلفت في الطريق'])
            ->assertSessionHasErrors('transfer');
        $this->step('resolve', $t, $this->owner, ['line_id' => $line->id, 'kind' => 'lost', 'quantity' => 3, 'reason' => 'أكثر من المعلّق'])
            ->assertSessionHasErrors('transfer');

        $this->step('resolve', $t, $this->owner, ['line_id' => $line->id, 'kind' => 'lost', 'quantity' => 1, 'reason' => 'تلفت في الطريق'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::DISCREPANCY, $t->fresh()->status);

        // الخسارةُ بقاعدة التلف نفسِها: صفُّ مصروفٍ وقيدٌ في الدفتر بتكلفة يوم الإرسال
        $loss = Expense::where('business_id', $this->shop->id)->where('type', BranchTransfers::LOSS_TYPE)->first();
        $this->assertNotNull($loss, 'لم تُكتب الخسارة');
        $this->assertEqualsWithDelta(1.5, (float) $loss->amount, 0.001);
        $this->assertSame($this->muscat->id, (int) $loss->branch_id);
        $this->assertSame(1, JournalEntry::where('business_id', $this->shop->id)->where('source', StockLosses::SOURCE)->count());

        $this->step('resolve', $t, $this->owner, ['line_id' => $line->id, 'kind' => 'returned', 'quantity' => 1, 'reason' => 'رجعت مع السائق'])
            ->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::RECEIVED, $t->fresh()->status);
        $this->assertNotNull($t->fresh()->closed_at);
        $this->assertSame([53, 31, 22, 0], $this->shelves($this->rose));
        $this->assertBalanced($this->rose);
        $this->assertSame(0, BranchTransfers::inTransit($this->shop->id)['quantity']);
        // على الرفوف ٥٣ + فُقدت ١ = ٥٤ كما كان
        $this->assertSame(54, (int) $this->rose->fresh()->quantity + $line->fresh()->lost_qty);
        $this->assertSame(['requested', 'approved', 'preparing', 'dispatched', 'discrepancy', 'resolved', 'resolved'], $t->events()->pluck('action')->all());
    }

    public function test_a_late_arrival_enters_the_receiving_shelf(): void
    {
        $t = $this->readyToSend([['product_id' => $this->wrap->id, 'quantity' => 6]]);
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $line = $t->lines()->first();
        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => [$line->id => 4]])->assertSessionHasNoErrors();

        $this->step('resolve', $t, $this->owner, ['line_id' => $line->id, 'kind' => 'late', 'quantity' => 2, 'reason' => 'وصلت مع الشحنة التالية'])
            ->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::RECEIVED, $t->fresh()->status);
        $this->assertSame([20, 14, 6, 0], $this->shelves($this->wrap));
        $this->assertSame(0, Expense::where('business_id', $this->shop->id)->count(), 'كُتبت خسارةٌ لبضاعةٍ وصلت');
    }

    public function test_more_than_was_sent_cannot_be_received(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 5]]);
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $line = $t->lines()->first();

        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => [$line->id => 6]])->assertSessionHasErrors('transfer');
        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => [$line->id => -1]])->assertSessionHasErrors();
        $this->assertSame([49, 45, 4, 0], $this->shelves($this->rose));
        $this->assertSame(BranchTransfer::IN_TRANSIT, $t->fresh()->status);
    }

    public function test_bad_lines_are_refused(): void
    {
        $service = Product::create([
            'business_id' => $this->shop->id, 'name' => 'رسم توصيل', 'price' => 2, 'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);
        $service->forceFill(['tracks_stock' => false])->save();
        $bouquet = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 20, 'cost' => 0, 'quantity' => 0, 'active' => true]);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $bouquet->id, 'component_product_id' => $this->rose->id, 'quantity' => 3, 'sort_order' => 0]);
        $theirs = $this->stocked('ورد الجار', 1, [], $this->neighbour);

        foreach ([$service, $bouquet, $theirs] as $p) {
            $this->request([['product_id' => $p->id, 'quantity' => 1]])->assertSessionHasErrors('transfer');
        }
        $this->request([['product_id' => $this->rose->id, 'quantity' => 0]])->assertSessionHasErrors('items.0.quantity');

        // المصدرُ والمستلمُ واحد
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->muscat->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('to_branch_id');

        // فرعُ الجار
        $theirBranch = Branch::create(['business_id' => $this->neighbour->id, 'name' => 'فرع الجار']);
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $theirBranch->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('transfer');

        $this->assertSame(0, BranchTransfer::count());
    }

    public function test_the_same_submission_twice_writes_one_document(): void
    {
        $items = [['product_id' => $this->rose->id, 'quantity' => 2]];
        $this->request($items, null, ['client_key' => 'tap-once'])->assertSessionHasNoErrors();
        $this->request($items, null, ['client_key' => 'tap-once'])->assertSessionHasNoErrors();

        $this->assertSame(1, BranchTransfer::count());
        $this->assertSame('TRF-000001', BranchTransfer::first()->number);
    }

    public function test_the_numbering_continues_after_the_old_transfer_log(): void
    {
        StockTransfer::create([
            'business_id' => $this->shop->id, 'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'from_branch_name' => 'مسقط', 'to_branch_name' => 'صلالة', 'product_id' => $this->rose->id,
            'number' => 'TRF-000007', 'quantity' => 1, 'transferred_at' => now()->subYear(),
        ]);

        $this->request([['product_id' => $this->rose->id, 'quantity' => 2]])->assertSessionHasNoErrors();

        $this->assertSame('TRF-000008', $this->latest()->number, 'تكرّر رقمُ سندٍ قديم في سجلّ المخزون');
        $this->assertSame(1, StockTransfer::count(), 'مُسّ سِجلُّ النقل القديم');
    }

    public function test_the_owner_executes_directly_and_a_failure_leaves_no_document(): void
    {
        $this->request([['product_id' => $this->rose->id, 'quantity' => 60]], $this->owner, ['execute' => 1, 'self_approve' => 1])
            ->assertSessionHasErrors('transfer');
        $this->assertSame(0, BranchTransfer::count(), 'بقي سندٌ نصفُ منفَّذ بعد ضغطةٍ سقطت');
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose));

        $this->request([['product_id' => $this->rose->id, 'quantity' => 6]], $this->owner, ['execute' => 1, 'self_approve' => 1])
            ->assertSessionHasNoErrors();
        $t = $this->latest();
        $this->assertSame(BranchTransfer::IN_TRANSIT, $t->status);
        $this->assertTrue($t->self_approved);
        $this->assertSame([48, 44, 4, 0], $this->shelves($this->rose));
        $this->assertSame(['requested', 'approved', 'preparing', 'dispatched'], $t->events()->pluck('action')->all());
        $this->assertSame(1, BranchOperationEvent::where('subject_id', $t->id)->where('action', 'approved')
            ->where('note', 'like', '%بنفسه%')->count(), 'لم يُوثَّق الاعتمادُ الذاتيّ');
    }

    public function test_no_one_approves_their_own_request_except_the_owner_by_explicit_choice(): void
    {
        // مديرٌ مُنح الاعتماد باسمه — ولا يعتمد ما طلبه هو
        $this->muscatManager->update(['permissions' => ['inventory', 'transfer.create', 'transfer.approve', 'transfer.dispatch']]);
        $this->request([['product_id' => $this->rose->id, 'quantity' => 2]])->assertSessionHasNoErrors();
        $t = $this->latest();
        $this->step('approve', $t, $this->muscatManager->fresh())->assertSessionHasErrors('transfer');
        $this->assertSame(BranchTransfer::PENDING, $t->fresh()->status);

        $this->request([['product_id' => $this->rose->id, 'quantity' => 2]], $this->owner)->assertSessionHasNoErrors();
        $own = $this->latest();
        $this->step('approve', $own, $this->owner)->assertSessionHasErrors('transfer');
        $this->step('approve', $own, $this->owner, ['self_approve' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($own->fresh()->self_approved);
    }

    public function test_staff_act_only_with_their_permission_and_in_their_branches(): void
    {
        // البائعُ لا فعلَ له في التحويلات
        $this->request([['product_id' => $this->rose->id, 'quantity' => 1]], $this->seller)->assertForbidden();
        $this->actingAs($this->seller)->get(route('admin.inventory.transfers'))->assertForbidden();

        // مديرُ مسقط لا يطلب نقلًا بين صلالة وصحار
        $this->actingAs($this->muscatManager)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->salalah->id, 'to_branch_id' => $this->sohar->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('transfer');

        // ولا يعتمد بدوره
        $this->request([['product_id' => $this->rose->id, 'quantity' => 2]], $this->salalahManager)->assertSessionHasNoErrors();
        $t = $this->latest();
        $this->step('approve', $t, $this->muscatManager)->assertSessionHasErrors('transfer');
        $this->step('approve', $t, $this->owner)->assertSessionHasNoErrors();

        // أمينُ مخزن صلالة لا يُجهّز ما يخرج من مسقط
        $this->step('prepare', $t, $this->salalahKeeper)->assertSessionHasErrors('transfer');
        $this->step('prepare', $t, $this->muscatManager)->assertSessionHasNoErrors();
        $this->step('dispatch', $t, $this->muscatManager)->assertSessionHasNoErrors();

        // ومديرُ مسقط لا يستلم في صلالة
        $line = $t->lines()->first();
        $this->step('receive', $t, $this->muscatManager, ['quantities' => [$line->id => 2]])->assertSessionHasErrors('transfer');
        $this->step('receive', $t, $this->salalahKeeper, ['quantities' => [$line->id => 2]])->assertSessionHasNoErrors();

        // ولا يرى مديرُ صحار سندَ مسقط وصلالة
        $soharManager = $this->staff('مدير صحار', 'manager', [$this->sohar]);
        $this->actingAs($soharManager)->get(route('admin.inventory.transfers.show', $t->id))->assertForbidden();
    }

    public function test_another_shop_cannot_touch_this_shops_documents(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 4]]);
        $theirOwner = $this->staff('مالك الجار', 'admin', [], $this->neighbour);

        foreach (['approve', 'reject', 'prepare', 'dispatch', 'cancel'] as $step) {
            $this->step($step, $t, $theirOwner, ['reason' => 'محاولة'])->assertNotFound();
        }
        $this->actingAs($theirOwner)->get(route('admin.inventory.transfers.show', $t->id))->assertNotFound();
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose));
    }

    public function test_the_screens_open_for_the_owner_and_the_manager(): void
    {
        $t = $this->readyToSend([['product_id' => $this->rose->id, 'quantity' => 4]]);

        $this->actingAs($this->owner)->get(route('admin.inventory.transfers'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.inventory.transfers.show', $t->id))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Inventory/Transfers/Show')
                ->where('transfer.status', BranchTransfer::PREPARING)
                ->where('can.dispatch', true)
                ->where('can.receive', false));
        $this->actingAs($this->muscatManager)->get(route('admin.inventory.transfers'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('transfers', 1));
        $this->actingAs($this->muscatManager)->getJson(route('admin.inventory.transfers.products', ['q' => 'ورد', 'branch' => $this->muscat->id]))
            ->assertOk()->assertJsonPath('products.0.available', 50);
    }
}
