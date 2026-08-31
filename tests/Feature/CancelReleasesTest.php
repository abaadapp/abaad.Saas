<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإلغاء يردّ ما أخذه البيع.
 *
 * كان يقلب الحالة وحدها. والتقارير تستثني الملغى (`Order::scopeSold`) —
 * فيبدو الأمر سليمًا في الشاشة، والخلل تحتها في أربعة مواضع:
 *
 *   - المخزون: خمسُ ورداتٍ خرجت من الدفتر ولم تخرج من المحلّ، فيقول
 *     الجرد إنّها نقصت ولا أحد يعرف أين ذهبت.
 *   - النقاط: يكسبها العميل على طلبٍ لم يقع، ويستبدلها بضاعةً تقع.
 *   - الكوبون: «مرّة واحدة» يُحرَق على طلبٍ أُلغي — ولا باب لتعديل
 *     الكوبونات أصلًا، فلا سبيل إلى ردّه.
 *   - المالية: قيدُ دخلٍ يبقى على بيعةٍ لم تكن.
 *
 * وبيعةُ المنضدة لا يبلغها هذا: تُغلق «مكتمل» ولا يجوز نقلها إلى «ملغي»
 * أصلًا — تُصحَّح من بابها حيث يُطلب السبب.
 */
class CancelReleasesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل', 'email' => 'c@t.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'o@c.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'k@c.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'خالد', 'phone' => '91234567', 'points' => 0,
        ]);

        $this->openShiftFor($this->business->id);
    }

    private function sellAndCancel(array $extra = []): Order
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 5]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد',
            'client_uuid' => uniqid('c', true),
            // طلبٌ له موعد: يبدأ «جديد» فيجوز إلغاؤه — خلافًا لبيعة المنضدة
            'fulfillment_type' => 'pickup',
            'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ], $extra))->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $order->fresh();
    }

    public function test_what_left_the_shelf_comes_back_to_it(): void
    {
        $order = $this->sellAndCancel();

        $this->assertSame('ملغي', $order->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'المخزون لم يُعَد بعد الإلغاء');
        $this->assertSame(100, (int) \App\Models\BranchStock::where('product_id', $this->product->id)->sum('quantity'));
    }

    public function test_points_earned_on_an_order_that_never_happened_are_taken_back(): void
    {
        $this->sellAndCancel();

        $this->assertSame(0, (int) $this->customer->fresh()->points, 'النقاط بقيت بعد الإلغاء');
        $this->assertSame(0, (int) Order::latest('id')->firstOrFail()->points_earned);
    }

    /** وما استُبدل يُردّ: تلك دفعةٌ لم تُقابلها بضاعة */
    public function test_redeemed_points_are_returned(): void
    {
        $this->customer->update(['points' => 1000]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 5]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد',
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
            'client_uuid' => uniqid('r', true),
            'fulfillment_type' => 'pickup',
            'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $spent = (int) $order->redeemed_points;
        $this->assertGreaterThan(0, $spent, 'لم يُستبدل شيء — الاختبار لا يقيس ما يدّعيه');

        $before = (int) $this->customer->fresh()->points;

        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $earned = (int) $order->points_earned;
        $this->assertSame($before + $spent - min($earned, $before), (int) $this->customer->fresh()->points);
    }

    public function test_a_single_use_coupon_is_given_back(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'ONCE', 'type' => 'مبلغ',
            'value' => 2, 'min_order' => 0, 'max_uses' => 1, 'used_count' => 0, 'active' => true,
        ]);

        $this->sellAndCancel(['coupon_code' => 'ONCE']);

        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'استعمال الكوبون لم يُحرَّر');
    }

    public function test_no_income_stays_on_a_sale_that_did_not_happen(): void
    {
        $order = $this->sellAndCancel();

        $this->assertSame(0, Transaction::where('order_id', $order->id)->count(),
            'معاملة الدخل بقيت بعد الإلغاء');
    }

    /**
     * ولا يُردّ المخزون مرّتين.
     *
     * نقلٌ ثانٍ إلى «ملغي» — من زرٍّ ضُغط مرّتين أو من لوحةٍ أخرى — كان
     * سيُضيف خمسًا لا وجود لها.
     */
    public function test_cancelling_twice_returns_the_stock_once(): void
    {
        $order = $this->sellAndCancel();

        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي']);

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(1, \App\Models\OrderEdit::where('order_id', $order->id)->count());
    }

    /** ومن لوحة التجهيز كذلك — البابان يمرّان بالقاعدة نفسها */
    public function test_the_preparation_board_releases_the_same_way(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 5]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد',
            'client_uuid' => uniqid('p', true),
            'fulfillment_type' => 'pickup',
            'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.preparation.move', $order->number), ['status' => 'ملغي'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('ملغي', $order->fresh()->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
    }

    /**
     * وذو الوصفة تعود مكوّناته لا هو.
     *
     * بيعُ الباقة أنقص الورد ولم يمسّ الباقة، فالإلغاء يسلك الطريق نفسه —
     * وردُّ «الباقة» إلى الرفّ يخلق رصيدًا لمنتجٍ لا رصيد له، ويترك الورد
     * منقوصًا إلى الأبد.
     */
    public function test_a_recipe_returns_its_components_not_itself(): void
    {
        $bouquet = \App\Models\Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 30, 'cost' => 12, 'quantity' => 0, 'active' => true,
        ]);
        \App\Models\RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'component_product_id' => $this->product->id,
            'quantity' => 6, 'wastage_percent' => 0, 'sort_order' => 0,
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $bouquet->id, 'name' => 'باقة', 'qty' => 2]],
            'payment_method' => 'نقدي',
            'customer' => 'خالد',
            'client_uuid' => uniqid('b', true),
            'fulfillment_type' => 'pickup',
            'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertSame(88, (int) $this->product->fresh()->quantity);   // 100 − 12

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, (int) $bouquet->fresh()->quantity, 'رُدّت الباقة نفسها إلى الرفّ');
    }

    /**
     * وبيعةُ المنضدة لا تُلغى أصلًا — تُصحَّح من بابها حيث يُطلب السبب.
     */
    public function test_a_completed_counter_sale_still_cannot_be_cancelled(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 5]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('w', true),
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('مكتمل', $order->status);

        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي'])
            ->assertSessionHasErrors('status');

        $this->assertSame(95, (int) $this->product->fresh()->quantity);
    }
}
