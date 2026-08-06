<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلبات المعلّقة والمحفوظة.
 *
 * كانت القائمة تُرشَّح على `employee_name == auth()->user()->name` — صحيحًا
 * يوم كان اسم الموظف هو اسم الحساب المسجَّل. ثم صار البيع يُنسب إلى الكاشير
 * المختار على الصندوق، فصار الطلب يُحفظ باسم «أحمد» ويُبحث عنه باسم «مالك
 * النشاط»: يُخزَّن بنجاح، وتظهر رسالة «تم تعليق الطلب»، ولا يظهر أبدًا.
 */
class HeldOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'quantity' => 100, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0']);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
        $this->openShiftFor($this->business->id, $this->branch->id);
    }

    private function hold(string $kind = 'hold')
    {
        return $this->postJson(route('pos.hold'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 2]],
            'kind' => $kind,
        ]);
    }

    /* -------------------------- التعليق -------------------------- */

    /**
     * الطلب المعلّق باسم الكاشير المختار يظهر لمن فتح الشاشة.
     *
     * هذا هو العطب بعينه: المالك يختار «أحمد» على الصندوق، فيُحفظ الطلب
     * باسمه، ثم تبحث القائمة باسم المالك فلا تجد شيئًا.
     */
    public function test_an_order_held_under_the_selected_cashier_still_appears(): void
    {
        session(['pos_cashier_id' => $this->cashier->id]);

        $this->hold()->assertOk();

        $held = Demo::heldOrders();
        $this->assertCount(1, $held, 'عُلّق الطلب ولم يظهر في القائمة');
        $this->assertSame('أحمد', $held[0]['employee']);
        $this->assertSame(1, $held[0]['items']);
    }

    public function test_holding_and_saving_are_told_apart(): void
    {
        $this->hold('hold')->assertOk();
        $this->hold('save')->assertOk();

        $held = collect(Demo::heldOrders());
        $this->assertCount(2, $held);
        $this->assertCount(1, $held->where('saved', true), 'المحفوظ لا يُميَّز عن المعلّق');
        $this->assertCount(1, $held->where('saved', false));
    }

    /** والمعلّق لا يدخل حساب الصندوق: لم يُقبض بعد */
    public function test_a_held_order_is_not_a_sale(): void
    {
        $this->hold()->assertOk();

        $this->assertSame(1, Order::where('is_held', true)->count());
        $this->assertSame(0, Order::where('is_held', false)->count());
    }

    /* -------------------------- الاستكمال -------------------------- */

    public function test_a_colleague_can_resume_what_another_held(): void
    {
        session(['pos_cashier_id' => $this->cashier->id]);
        $this->hold();
        $order = Order::where('is_held', true)->firstOrFail();

        // تبدّلت الوردية وصار على الصندوق غيره — والزبون عاد
        session()->forget('pos_cashier_id');

        $this->get(route('pos.orders.resume', $order->id))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('resume_cart');
    }

    public function test_discarding_removes_it_and_its_lines(): void
    {
        $this->hold();
        $order = Order::where('is_held', true)->firstOrFail();

        $this->delete(route('pos.orders.discard', $order->id));

        $this->assertSame(0, Order::where('is_held', true)->count());
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
    }

    /* ---------------------------- العزل ---------------------------- */

    public function test_another_branchs_held_orders_are_not_listed(): void
    {
        $other = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        session(['current_branch' => $other->id]);
        $this->openShiftFor($this->business->id, $other->id);
        $this->hold();

        session(['current_branch' => $this->branch->id]);

        $this->assertCount(0, Demo::heldOrders(), 'ظهر معلّق فرعٍ آخر');
    }

    public function test_a_neighbours_held_order_is_not_mine(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Order::create([
            'business_id' => $theirs->id, 'number' => 'HOLD-JAR', 'customer_name' => 'زبونهم',
            'status' => 'معلّق', 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertCount(0, Demo::heldOrders());
    }

    /** ولا اسم تجريبي يتسرّب مكان اسمٍ غائب */
    public function test_a_missing_employee_name_does_not_become_a_demo_name(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'HOLD-X', 'customer_name' => 'نقدي', 'employee_name' => null,
            'status' => 'معلّق', 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertSame(__('غير محدّد'), Demo::heldOrders()[0]['employee']);
    }
}
