<?php

namespace Tests\Feature;

use App\Models\BranchSupplyRequest;
use App\Models\BranchTransfer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EnterpriseShop;
use Tests\TestCase;

/**
 * طلبُ التزويد: الفرعُ يطلب، والإدارةُ تعتمد وتختار من يزوّد، فيصير سندَ نقل.
 *
 * والطلبُ والاعتمادُ ورقٌ لا يحرّك رفًّا: البضاعةُ تخرج عند إرسال السند وحده.
 */
class ABranchAsksForStockAndTheHeadOfficeSendsItTest extends TestCase
{
    use EnterpriseShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->makeEnterpriseShop();
    }

    private function ask(array $items, ?User $as = null, ?int $branch = null)
    {
        return $this->actingAs($as ?? $this->salalahManager)->post(route('admin.inventory.supply.store'), [
            'branch_id' => $branch ?? $this->salalah->id,
            'items' => $items,
        ]);
    }

    private function latest(): BranchSupplyRequest
    {
        return BranchSupplyRequest::latest('id')->firstOrFail();
    }

    public function test_a_branch_manager_asks_for_stock_and_nothing_moves(): void
    {
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 25], ['product_id' => $this->wrap->id, 'quantity' => 5]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $r = $this->latest();
        $this->assertSame(BranchSupplyRequest::PENDING, $r->status);
        $this->assertSame('صلالة', $r->branch_name);
        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose));
        $this->assertSame(0, BranchTransfer::count());
    }

    public function test_a_manager_cannot_ask_in_the_name_of_a_branch_that_is_not_theirs(): void
    {
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]], $this->salalahManager, $this->sohar->id)
            ->assertSessionHasErrors('supply');
        $this->assertSame(0, BranchSupplyRequest::count());

        // والبائعُ لا يطلب أصلًا
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]], $this->seller, $this->muscat->id)->assertForbidden();
    }

    public function test_approval_writes_one_transfer_per_supplying_branch_and_still_moves_nothing(): void
    {
        $this->stocked('شريط', 0.1, [$this->sohar->id => 30]);
        $ribbon = Product::where('name', 'شريط')->firstOrFail();
        $this->ask([
            ['product_id' => $this->rose->id, 'quantity' => 25],
            ['product_id' => $ribbon->id, 'quantity' => 10],
            ['product_id' => $this->wrap->id, 'quantity' => 3],
        ])->assertSessionHasNoErrors();
        $r = $this->latest();
        [$roseLine, $ribbonLine, $wrapLine] = $r->lines()->get()->all();

        $this->actingAs($this->owner)->get(route('admin.inventory.supply.show', $r->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('supply.lines.0.balances.'.$this->muscat->id, 50)
                ->where('supply.lines.1.balances.'.$this->sohar->id, 30)
                ->where('can.approve', true));

        $this->actingAs($this->owner)->post(route('admin.inventory.supply.approve', $r->id), ['supply' => [
            $roseLine->id => ['source_branch_id' => $this->muscat->id, 'quantity' => 20],
            $ribbonLine->id => ['source_branch_id' => $this->sohar->id, 'quantity' => 10],
            // لا يُزوَّد الورقُ هذه المرّة
            $wrapLine->id => ['source_branch_id' => null, 'quantity' => 0],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(BranchSupplyRequest::APPROVED, $r->fresh()->status);
        $transfers = BranchTransfer::orderBy('id')->get();
        $this->assertCount(2, $transfers, 'سندٌ لكلّ فرعٍ مزوِّد');
        $this->assertSame([$this->muscat->id, $this->sohar->id], $transfers->pluck('from_branch_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($transfers->every(fn ($t) => $t->status === BranchTransfer::APPROVED && (int) $t->to_branch_id === $this->salalah->id));
        $this->assertTrue($transfers->every(fn ($t) => (int) $t->branch_supply_request_id === $r->id));
        $this->assertSame((int) $this->salalahManager->id, (int) $transfers[0]->requested_by, 'السندُ باسم من طلب التزويد');
        $this->assertSame((int) $this->owner->id, (int) $transfers[0]->approved_by);
        $this->assertSame(20, (int) $transfers[0]->lines()->first()->approved_qty);

        $this->assertSame([54, 50, 4, 0], $this->shelves($this->rose), 'الاعتمادُ أخرج بضاعةً من رفّ');
        $this->assertSame('in_progress', $r->fresh()->progress());

        // يُرسَل السندان ويُستلمان — فيكتمل الطلب
        foreach ($transfers as $t) {
            $from = (int) $t->from_branch_id === $this->muscat->id ? $this->muscatManager : $this->owner;
            $this->actingAs($from)->post(route('admin.inventory.transfers.prepare', $t->id))->assertSessionHasNoErrors();
            $this->actingAs($from)->post(route('admin.inventory.transfers.dispatch', $t->id))->assertSessionHasNoErrors();
            $line = $t->lines()->first();
            $this->actingAs($this->salalahKeeper)->post(route('admin.inventory.transfers.receive', $t->id), ['quantities' => [$line->id => $line->fresh()->sent_qty]])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('fulfilled', $r->fresh()->progress());
        $this->assertSame([54, 30, 24, 0], $this->shelves($this->rose));
        $this->assertBalanced($this->rose);
        $this->assertBalanced($ribbon);
    }

    public function test_approval_is_refused_when_it_would_ask_too_much_or_supply_itself(): void
    {
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]])->assertSessionHasNoErrors();
        $r = $this->latest();
        $line = $r->lines()->first();

        $approve = fn (array $row) => $this->actingAs($this->owner)->post(route('admin.inventory.supply.approve', $r->id), ['supply' => [$line->id => $row]]);

        $approve(['source_branch_id' => $this->muscat->id, 'quantity' => 6])->assertSessionHasErrors('supply');
        $approve(['source_branch_id' => $this->salalah->id, 'quantity' => 5])->assertSessionHasErrors('supply');
        $approve(['source_branch_id' => null, 'quantity' => 5])->assertSessionHasErrors('supply');
        $approve(['source_branch_id' => $this->muscat->id, 'quantity' => 0])->assertSessionHasErrors('supply');

        $this->assertSame(BranchSupplyRequest::PENDING, $r->fresh()->status);
        $this->assertSame(0, BranchTransfer::count());
    }

    public function test_rejection_and_cancellation_keep_their_reasons(): void
    {
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]])->assertSessionHasNoErrors();
        $r = $this->latest();

        // مديرُ الفرع لا يرفض طلبَ غيره — ولا طلبَه: يُلغيه
        $this->actingAs($this->muscatManager)->post(route('admin.inventory.supply.reject', $r->id), ['reason' => 'لا داعي'])
            ->assertSessionHasErrors('supply');
        $this->actingAs($this->owner)->post(route('admin.inventory.supply.reject', $r->id), ['reason' => 'المخزون يكفي صلالة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BranchSupplyRequest::REJECTED, $r->fresh()->status);
        $this->assertSame('المخزون يكفي صلالة', $r->fresh()->decision_reason);

        $this->ask([['product_id' => $this->rose->id, 'quantity' => 2]])->assertSessionHasNoErrors();
        $mine = $this->latest();
        $this->actingAs($this->salalahManager)->post(route('admin.inventory.supply.cancel', $mine->id), ['reason' => 'وصلتنا من مورّد'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BranchSupplyRequest::CANCELLED, $mine->fresh()->status);
        $this->assertSame(['requested', 'cancelled'], $mine->events()->pluck('action')->all());
    }

    public function test_the_requester_does_not_approve_their_own_request(): void
    {
        $this->salalahManager->update(['permissions' => ['inventory', 'supply.create', 'transfer.approve']]);
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]], $this->salalahManager->fresh())->assertSessionHasNoErrors();
        $r = $this->latest();
        $line = $r->lines()->first();

        $this->actingAs($this->salalahManager->fresh())->post(route('admin.inventory.supply.approve', $r->id), ['supply' => [
            $line->id => ['source_branch_id' => $this->muscat->id, 'quantity' => 5],
        ]])->assertSessionHasErrors('supply');
        $this->assertSame(BranchSupplyRequest::PENDING, $r->fresh()->status);
    }

    public function test_another_shop_cannot_read_or_decide_this_shops_requests(): void
    {
        $this->ask([['product_id' => $this->rose->id, 'quantity' => 5]])->assertSessionHasNoErrors();
        $r = $this->latest();
        $theirs = $this->staff('مالك الجار', 'admin', [], $this->neighbour);

        $this->actingAs($theirs)->get(route('admin.inventory.supply.show', $r->id))->assertNotFound();
        $this->actingAs($theirs)->post(route('admin.inventory.supply.reject', $r->id), ['reason' => 'محاولة'])->assertNotFound();
        $this->actingAs($theirs)->get(route('admin.inventory.supply'))->assertOk()->assertInertia(fn ($p) => $p->has('requests', 0));
        $this->assertSame(BranchSupplyRequest::PENDING, $r->fresh()->status);
    }
}
