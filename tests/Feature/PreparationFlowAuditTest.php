<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة التجهيز بوصفها تدفّقًا لا شاشة: من يضغط الزرّ، وماذا يقع تحته،
 * وماذا يُقال له إن لم يقع.
 *
 * واللوحة معروضةٌ على كلّ شاشات المحلّ في وقتٍ واحد، ولا تتحدّث من نفسها.
 * فما يراه العامل قد تجاوزه غيرُه قبل لحظة — وهذا هو موضع كلّ ما هنا.
 */
class PreparationFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->travelTo(today()->setTime(9, 0));
        $this->actingAs($this->owner);
    }

    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 2, 'total' => 50,
        ]);

        return $order;
    }

    private function move(Order $order, string $to)
    {
        return $this->post(route('admin.preparation.move', $order->number), ['status' => $to]);
    }

    /* ------------------- الرفض يُرى ------------------- */

    /**
     * كان يُردّ بـ`withErrors` وحدها، واللوحة لا تعرض إلّا `flash.toast`.
     *
     * فالضغطة لا تفعل شيئًا ولا تقول شيئًا — فتُعاد، ثمّ يُسأل صاحبُ المحلّ
     * عن لوحةٍ «لا تعمل».
     */
    public function test_a_refused_move_says_so_where_the_screen_can_show_it(): void
    {
        $order = $this->order(['status' => OrderStatus::CANCELLED]);

        $this->move($order, OrderStatus::READY)
            ->assertSessionHasErrors('status')
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'danger'
                && str_contains($toast['msg'], 'لا يمكن نقل الطلب'));
    }

    public function test_the_refusal_names_both_ends(): void
    {
        $order = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->move($order, OrderStatus::PREPARING);

        $msg = session('toast')['msg'];
        $this->assertStringContainsString(OrderStatus::DELIVERED, $msg);
        $this->assertStringContainsString(OrderStatus::PREPARING, $msg);
    }

    public function test_an_accepted_move_still_toasts_success(): void
    {
        $order = $this->order();

        $this->move($order, OrderStatus::READY)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success');

        $this->assertSame(OrderStatus::READY, $order->fresh()->status);
    }

    /* ------------------- الانتقالات غير القانونية ------------------- */

    public function test_a_delivered_order_cannot_go_back_to_the_workbench(): void
    {
        $order = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->move($order, OrderStatus::PREPARING)->assertSessionHasErrors('status');

        // وإلّا جُهِّزت الباقة مرّتين وحُسبت مرّتين
        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_revived(): void
    {
        $order = $this->order(['status' => OrderStatus::CANCELLED]);

        $this->move($order, OrderStatus::READY)->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_an_unknown_status_never_reaches_the_column(): void
    {
        $order = $this->order();

        $this->move($order, 'قيد الشحن الفضائي')->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    /* ------------------- اللوحة والشاشة بابٌ واحد ------------------- */

    /**
     * حارسان يفترقان عند أوّل تعديل — فالعامل يستطيع من لوحته ما لا يستطيعه
     * صاحبُ المحلّ من شاشته.
     */
    public function test_both_screens_refuse_the_same_move(): void
    {
        $a = $this->order(['status' => OrderStatus::DELIVERED]);
        $b = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->move($a, OrderStatus::PREPARING)->assertSessionHasErrors('status');
        $this->post(route('admin.orders.status', $b->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::DELIVERED, $a->fresh()->status);
        $this->assertSame(OrderStatus::DELIVERED, $b->fresh()->status);
    }

    public function test_neither_screen_writes_the_move_by_hand(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/PreparationController.php',
            'app/Http/Controllers/Admin/OrderDetailController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringContainsString('OrderTransition::apply', $source);
            $this->assertStringNotContainsString('OrderStatus::canMove', $source);
        }
    }

    /* ------------------- شاشتان تتحرّكان معًا ------------------- */

    /**
     * موظّفان على اللوحة نفسها: أحدهما يُلغي والآخر ينقل إلى «جاهز».
     *
     * كلٌّ منهما يحمل نسخته من الصفّ قُرئت قبل الضغطة. ولولا القفل لكتبت
     * الكتابةُ الأخرى «جاهز» فوق الإلغاء: طلبٌ حيٌّ على اللوحة، بضاعتُه
     * عادت إلى الرفّ ولا قيدَ دخلٍ له.
     */
    public function test_a_stale_move_cannot_overwrite_a_cancellation(): void
    {
        $order = $this->order();
        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'type' => 'دخل', 'amount' => 25, 'occurred_at' => now(),
            'reference' => 'TRX-'.uniqid(), 'description' => 'بيع',
        ]);

        $stale = Order::findOrFail($order->id);   // شاشةُ الموظّف الثاني

        \App\Support\OrderTransition::apply($order, OrderStatus::CANCELLED);
        $error = \App\Support\OrderTransition::apply($stale, OrderStatus::READY);

        $this->assertNotNull($error);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
    }

    public function test_a_stale_move_does_not_return_the_stock_twice(): void
    {
        $order = $this->order();
        $before = (int) $this->product->fresh()->quantity;

        $stale = Order::findOrFail($order->id);
        \App\Support\OrderTransition::apply($order, OrderStatus::CANCELLED);
        \App\Support\OrderTransition::apply($stale, OrderStatus::CANCELLED);

        $this->assertSame($before + 2, (int) $this->product->fresh()->quantity);
    }

    /* ------------------- حدّ المتجر والفرع ------------------- */

    public function test_a_neighbours_order_cannot_be_moved_from_this_board(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $theirs = Order::create([
            'business_id' => $other->id, 'branch_id' => $theirBranch->id,
            'number' => 'INV-THEIRS', 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => 5, 'total' => 5,
            'scheduled_for' => now()->addHour(),
        ]);

        $this->move($theirs, OrderStatus::READY)->assertNotFound();

        $this->assertSame(OrderStatus::PREPARING, $theirs->fresh()->status);
    }

    public function test_an_order_of_another_branch_is_not_on_this_board(): void
    {
        $second = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $mine = $this->order();
        $elsewhere = $this->order(['branch_id' => $second->id]);

        session(['current_branch' => $this->branch->id]);

        $numbers = [];
        $this->get(route('admin.preparation.index'))
            ->assertInertia(function ($p) use (&$numbers) {
                $numbers = array_column($p->toArray()['props']['orders'], 'number');
            });

        $this->assertContains($mine->number, $numbers);
        $this->assertNotContains($elsewhere->number, $numbers);
    }

    public function test_a_branch_bound_board_refuses_to_move_another_branchs_order(): void
    {
        $second = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $elsewhere = $this->order(['branch_id' => $second->id]);

        session(['current_branch' => $this->branch->id]);

        $this->move($elsewhere, OrderStatus::READY)->assertNotFound();
    }

    /* ------------------- الصلاحية ------------------- */

    private function staff(string $role, array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => $role.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    public function test_the_board_belongs_to_whoever_prepares(): void
    {
        $this->actingAs($this->staff('sales', ['preparation']));

        $this->get(route('admin.preparation.index'))->assertOk();
    }

    /**
     * والتجهيز قسمٌ مستقلّ لا جزءٌ من «المبيعات».
     *
     * «المبيعات» تفتح الفواتير وإجماليّاتها ومجموع ما رُشّح — وهي أوسع بكثير
     * ممّا يحتاجه من يصنع الباقة.
     */
    public function test_sales_alone_does_not_open_the_board(): void
    {
        $this->actingAs($this->staff('sales', ['orders']));

        $this->get(route('admin.preparation.index'))->assertForbidden();
        $this->move($this->order(), OrderStatus::READY)->assertForbidden();
    }

    /* ------------------- ما لا يُعرض على الطاولة ------------------- */

    public function test_the_card_never_carries_money(): void
    {
        $this->order();

        $this->get(route('admin.preparation.index'))
            ->assertInertia(function ($p) {
                $card = $p->toArray()['props']['orders'][0];
                foreach (['price', 'cost', 'total', 'subtotal'] as $money) {
                    $this->assertArrayNotHasKey($money, $card);
                    $this->assertArrayNotHasKey($money, $card['items'][0]);
                }
            });
    }
}
