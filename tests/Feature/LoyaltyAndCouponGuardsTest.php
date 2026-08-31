<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حدود النقاط والكوبونات تُفرض في الخادم لا في المتصفّح.
 *
 * الشاشة تعرض السقف وتمنع تجاوزه، والطلب يُرسَل من المتصفّح: من يعرف كيف
 * يُرسل طلبًا يتجاوزها. وحدٌّ يُرسَم في الواجهة ولا يُفحص عند الاستقبال ليس
 * حدًّا — وهذا الباب يخرج منه مالٌ لا بضاعة، فلا يظهر نقصُه في جرد.
 */
class LoyaltyAndCouponGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $branch;
    private Customer $customer;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000,
        ]);

        $this->p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->set('loyalty_enabled', '1');
        $this->set('loyalty_redeem_max_pct', '50');
        $this->set('loyalty_redeem_min', '100');
        $this->set('vat_enabled', '0');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }

    private function sell(array $extra = [], int $qty = 1): Order
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->p->id, 'name' => $this->p->name, 'qty' => $qty]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('t', true),
            ], $extra))->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** طلبٌ يطلب استبدال كلّ نقاطه: يُحصر بسقف النسبة لا يمرّ */
    public function test_redeeming_beyond_the_cap_is_capped_by_the_server(): void
    {
        $order = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 999999]);

        // السقف ٥٠٪ من مئة ريال = خمسون
        $this->assertLessThanOrEqual(50.0, round((float) $order->discount, 3), 'استُبدل فوق السقف');
        $this->assertGreaterThanOrEqual(0.0, (float) $order->total, 'فاتورةٌ بإجماليٍّ سالب');
        $this->assertGreaterThanOrEqual(0, (int) $this->customer->fresh()->points, 'نقاطٌ سالبة');
    }

    /** ولا تُخصم من رصيده أكثر ممّا يملك */
    public function test_a_customer_never_spends_points_he_does_not_have(): void
    {
        $this->customer->update(['points' => 300]);
        $order = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 999999]);

        $this->assertLessThanOrEqual(300, (int) $order->redeemed_points);
        $this->assertGreaterThanOrEqual(0, (int) $this->customer->fresh()->points);
    }

    /** ورصيدٌ دون الحدّ الأدنى لا يُستبدل منه شيء */
    public function test_below_the_minimum_nothing_is_redeemed(): void
    {
        $this->customer->update(['points' => 50]);   // والحدّ مئة
        $order = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 50]);

        $this->assertSame(0, (int) $order->redeemed_points);
        // ولا يُنقص رصيده — والاكتساب من البيعة يزيده، وهو غير الاستبدال
        $this->assertGreaterThanOrEqual(50, (int) $this->customer->fresh()->points);
    }

    /** والنقاط تُطفأ فلا تُكتسب ولا تُستبدل */
    public function test_loyalty_off_earns_and_redeems_nothing(): void
    {
        $this->set('loyalty_enabled', '0');
        $order = $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 1000]);

        $this->assertSame(0, (int) $order->points_earned);
        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertSame(100000, (int) $this->customer->fresh()->points);
    }

    /** كوبونٌ بلغ حدّ استعماله لا يُقبل مهما أُرسل */
    public function test_a_spent_coupon_is_refused(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'X10', 'type' => 'نسبة',
            'value' => 10, 'max_uses' => 1, 'used_count' => 1, 'active' => true,
        ]);

        $order = $this->sell(['coupon_code' => $coupon->code]);

        $this->assertSame(0.0, round((float) $order->coupon_discount, 3), 'كوبونٌ استُهلك ومُنح خصمًا');
        $this->assertSame(1, (int) $coupon->fresh()->used_count, 'ازداد عدّاد كوبونٍ مرفوض');
    }

    /** وكوبونٌ منتهٍ كذلك */
    public function test_an_expired_coupon_is_refused(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'OLD', 'type' => 'نسبة',
            'value' => 25, 'active' => true, 'expires_at' => now()->subDay(),
        ]);

        $order = $this->sell(['coupon_code' => $coupon->code]);

        $this->assertSame(0.0, round((float) $order->coupon_discount, 3), 'كوبونٌ منتهٍ مُنح خصمًا');
    }

    /** وكوبون متجرٍ آخر لا يُصرف هنا */
    public function test_a_coupon_of_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $coupon = Coupon::create([
            'business_id' => $other->id, 'code' => 'THEIRS', 'type' => 'نسبة',
            'value' => 50, 'active' => true,
        ]);

        $order = $this->sell(['coupon_code' => $coupon->code]);

        $this->assertSame(0.0, round((float) $order->coupon_discount, 3), 'صُرف كوبون متجرٍ آخر');
    }

    /**
     * ونسبةٌ فوق المئة لا تُكتب أصلًا.
     *
     * `discountFor` تقصّها عند المجموع فلا تصير الفاتورة سالبة — لكنّ من
     * كتب «١٥٠٪» يظنّه يعمل ويقرؤه في القائمة كذلك. حدٌّ يُقصّ بصمت وعدٌ
     * مكسور.
     */
    public function test_a_percentage_coupon_above_a_hundred_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.coupons.store'), [
            'code' => 'HALF150', 'type' => 'نسبة', 'value' => 150,
        ])->assertSessionHasErrors('value');

        $this->assertSame(0, \App\Models\Coupon::where('code', 'HALF150')->count());
    }

    /** والمبلغ لا يُحدّ بمئة — «خصم ٢٠٠ ريال» عرضٌ مشروع */
    public function test_a_fixed_amount_coupon_may_exceed_a_hundred(): void
    {
        $this->actingAs($this->owner)->post(route('admin.coupons.store'), [
            'code' => 'FLAT200', 'type' => 'مبلغ', 'value' => 200,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\Coupon::where('code', 'FLAT200')->count());
    }
}
