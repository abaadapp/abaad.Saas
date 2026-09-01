<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftMovement;
use App\Models\User;
use App\Support\Shifts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصندوق من طرفه إلى طرفه — والمنعُ عند الباب لا في الشاشة.
 *
 * شاشةُ البيع تُخفي ما ليس لفرعها وتُريح صاحبها، والطلبُ الذي يصل الخادم لا
 * يمرّ بشاشة. فكلُّ حدٍّ في هذا القسم يجب أن يُفحص مرّتين — مرّةً ليُريح
 * الكاشير، ومرّةً ليُلزم من لا يستعمل الشاشة.
 *
 * والدرجُ خاصّةً: ما يخرج منه لا يعود، ولا يُكتشف نقصُه إلا عند العدّ.
 */
class PosEndToEndAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 500, 'alert_qty' => 1, 'active' => true,
        ]);

        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => '0']);
    }

    private function at(Branch $branch, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->withSession(['current_branch' => $branch->id]);
    }

    private function held(Branch $branch): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'number' => 'HOLD-'.uniqid(), 'status' => 'معلّق', 'is_held' => true,
            'customer_name' => 'زبون', 'employee_name' => 'كاشير',
            'payment_method' => 'نقدي', 'subtotal' => 100, 'total' => 100, 'ordered_at' => now(),
        ]);
    }

    /* ============ السلّة المعلّقة تخصّ فرعها ============ */

    public function test_the_held_cart_list_shows_only_this_branchs_carts(): void
    {
        $this->held($this->muscat);
        $this->held($this->salalah);

        $this->at($this->muscat)->get(route('pos.orders'))
            ->assertInertia(fn ($p) => $p->has('heldOrders', 1));
    }

    public function test_another_branchs_held_cart_cannot_be_resumed_by_guessing_its_id(): void
    {
        $theirs = $this->held($this->salalah);

        $this->at($this->muscat)->get(route('pos.orders.resume', $theirs->id))->assertNotFound();
    }

    public function test_another_branchs_held_cart_cannot_be_discarded_by_guessing_its_id(): void
    {
        /*
         * والحذف أشدّ من الاطّلاع: القائمة تُخفي سلّة الفرع الآخر، فصاحبها
         * لا يعلم أنها ذهبت — يقف الزبون في صلالة، والسلّة التي جُمعت له
         * محاها كاشيرٌ في مسقط بمعرّفٍ مُخمَّن.
         */
        $theirs = $this->held($this->salalah);

        $this->at($this->muscat)->delete(route('pos.orders.discard', $theirs->id))->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $theirs->id]);
    }

    public function test_my_own_branchs_cart_is_still_mine_to_resume_and_discard(): void
    {
        $mine = $this->held($this->muscat);

        $this->at($this->muscat)->get(route('pos.orders.resume', $mine->id))->assertRedirect(route('pos.index'));
        $this->at($this->muscat)->delete(route('pos.orders.discard', $mine->id))->assertRedirect();

        $this->assertDatabaseMissing('orders', ['id' => $mine->id]);
    }

    public function test_a_neighbours_cart_is_out_of_reach_entirely(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعهم']);
        $theirs = $this->held($theirBranch);
        $theirs->update(['business_id' => $neighbour->id]);

        $this->at($this->muscat)->delete(route('pos.orders.discard', $theirs->id))->assertNotFound();
    }

    /* ============ الدرج ============ */

    private function openShift(float $float = 100): Shift
    {
        $this->at($this->muscat);
        session(['current_branch' => $this->muscat->id]);

        return Shifts::open($float, $this->owner->id);
    }

    public function test_the_drawer_never_pays_out_more_than_it_holds(): void
    {
        $this->openShift(60);

        $this->at($this->muscat)->post(route('pos.shift.move'), [
            'type' => 'out', 'amount' => 50, 'reason' => 'شراء عاجل',
        ])->assertRedirect();

        $this->at($this->muscat)->post(route('pos.shift.move'), [
            'type' => 'out', 'amount' => 50, 'reason' => 'شراء ثانٍ',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(10.0, round(Shifts::expectedCash(Shifts::current()), 3));
    }

    public function test_the_payout_is_weighed_against_the_drawer_under_a_lock(): void
    {
        /*
         * سحبان يقعان معًا لا يُصنعان في عمليّةٍ واحدة، فيُقرأ الحارس من
         * مصدره: القراءة والكتابة داخل معاملةٍ واحدة، والوردية مقفولة بينهما.
         * وبلا ذلك يقرأ السحبان «في الدرج ستّون» ويخرج منهما مئة.
         */
        $src = file_get_contents(app_path('Http/Controllers/Pos/ShiftController.php'));

        $move = strpos($src, 'public function move(');
        $this->assertNotFalse($move);

        $body = substr($src, $move, 2200);
        $this->assertStringContainsString('DB::transaction', $body, 'الفحص والكتابة خارج معاملةٍ واحدة');
        $this->assertStringContainsString('lockForUpdate', $body, 'الوردية تُقرأ بلا قفل بين الفحص والسحب');
    }

    public function test_the_refusal_does_not_read_the_drawer_out_to_someone_forbidden_to_see_it(): void
    {
        /*
         * العدّ أعمى عن قصد — «من يرى الرقم المتوقّع قبل العدّ يميل إلى
         * كتابته بدل ما عدّه». ورسالةُ الرفض كانت تقوله بالحرف: يجرّب
         * الكاشير سحبًا كبيرًا فيُردّ برقم الدرج كاملًا.
         */
        $this->openShift(60);

        $this->at($this->muscat, $this->cashier)->post(route('pos.shift.move'), [
            'type' => 'out', 'amount' => 500, 'reason' => 'تجربة',
        ])->assertSessionHasErrors('amount');

        $message = (string) session('errors')->first('amount');
        $this->assertStringNotContainsString('60', $message, 'أُفشي المتوقّع لمن لا يراه');
    }

    public function test_whoever_may_see_the_amounts_is_still_told_the_number(): void
    {
        $this->openShift(60);

        $this->at($this->muscat)->post(route('pos.shift.move'), [
            'type' => 'out', 'amount' => 500, 'reason' => 'تجربة',
        ])->assertSessionHasErrors('amount');

        $this->assertStringContainsString('60', (string) session('errors')->first('amount'));
    }

    public function test_a_deposit_is_never_refused_for_lack_of_cash(): void
    {
        $this->openShift(0);

        $this->at($this->muscat)->post(route('pos.shift.move'), [
            'type' => 'in', 'amount' => 25, 'reason' => 'إيداع',
        ])->assertSessionHasNoErrors();

        $this->assertSame(25.0, round(Shifts::expectedCash(Shifts::current()), 3));
    }

    public function test_a_movement_needs_a_reason_that_can_be_read_later(): void
    {
        $this->openShift(100);

        $this->at($this->muscat)->post(route('pos.shift.move'), ['type' => 'out', 'amount' => 10])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, ShiftMovement::count());
    }

    /* ============ الوردية تتبع فرعها ============ */

    public function test_a_shift_belongs_to_its_branch_not_to_the_shop(): void
    {
        $this->openShift(100);

        session(['current_branch' => $this->salalah->id]);
        $this->assertNull(Shifts::current(), 'وردية مسقط قُرئت درجًا لصلالة');
    }

    public function test_a_sale_is_counted_in_the_drawer_of_the_branch_that_made_it(): void
    {
        $shift = $this->openShift(0);

        $this->at($this->muscat)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('e', true),
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame($this->muscat->id, (int) $order->branch_id);
        $this->assertSame($shift->id, (int) $order->shift_id);
        $this->assertSame(100.0, round(Shifts::expectedCash($shift->fresh()), 3));
    }

    public function test_a_card_sale_puts_nothing_in_the_drawer(): void
    {
        $shift = $this->openShift(0);

        $this->at($this->muscat)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'بطاقة', 'client_uuid' => uniqid('e', true),
        ])->assertOk();

        $this->assertSame(0.0, round(Shifts::expectedCash($shift->fresh()), 3));
    }

    /* ============ الفاتورة تقع مرّةً واحدة ============ */

    public function test_the_same_send_twice_writes_one_invoice(): void
    {
        $uuid = uniqid('once', true);
        $payload = [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => $uuid,
        ];

        $first = $this->at($this->muscat)->postJson(route('pos.checkout'), $payload)->assertOk();
        $second = $this->at($this->muscat)->postJson(route('pos.checkout'), $payload)->assertOk();

        $this->assertSame(1, Order::where('is_held', false)->count(), 'إعادةُ إرسالٍ كتبت فاتورةً ثانية');
        $this->assertSame($first->json('invoice'), $second->json('invoice'));
        $this->assertSame(499, (int) $this->product->fresh()->quantity, 'خُصم المخزون مرّتين');
    }
}
