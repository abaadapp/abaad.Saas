<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
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

    /* ============ البيعة تقع في فرعها ============ */

    /**
     * الفرع يُقرأ من الجهاز لا من تبويبٍ مفتوح.
     *
     * كانت هذه الحالة تفحص الدرج مع الفرع، ورُفعت الوردية من نقطة البيع
     * فبقي ما يهمّ: بيعةُ مسقط تُكتب على مسقط. وخطؤها لا يُكتشف إلا في جرد
     * آخر الشهر — حين يَنقص فرعٌ ويَزيد آخر بالمقدار نفسه.
     */
    public function test_a_sale_is_counted_in_the_branch_that_made_it(): void
    {
        session(['current_branch' => $this->muscat->id]);

        $this->at($this->muscat)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('e', true),
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame($this->muscat->id, (int) $order->branch_id);
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
