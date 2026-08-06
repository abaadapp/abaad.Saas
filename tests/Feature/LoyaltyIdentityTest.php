<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نقاط الولاء تتبع الشخص لا اسمه.
 *
 * كان العميل يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود من الجدول. والاسم ليس
 * مفتاحًا: متجرٌ فيه ثلاثة باسم «محمد» كان يمنح نقاط شراء كلٍّ منهم لأوّلهم،
 * ويخصم رصيده هو عند استبدال غيره.
 *
 * والنقاط مالٌ فعلي: الخلط فيها خسارةٌ لصاحبها وهبةٌ لسواه — ولا يظهر منه
 * شيء في أي شاشة، لأن كل سجلّ صحيح على حدة.
 */
class LoyaltyIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'quantity' => 100, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'loyalty_earn_rate', 'value' => '5']);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
        $this->openShiftFor($this->business->id, $this->branch->id);
    }

    private function customer(string $name, string $phone, int $points = 0): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id, 'name' => $name,
            'phone' => $phone, 'points' => $points,
        ]);
    }

    private function sell(array $extra = [])
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ], $extra));
    }

    /* ----------------------- الاكتساب لصاحبه ----------------------- */

    /**
     * محمّدان بالاسم نفسه: نقاط كلٍّ منهما له.
     *
     * هذا هو العطب بعينه — كانت النقاط تذهب كلّها إلى الأوّل في الجدول.
     */
    public function test_two_customers_with_the_same_name_keep_their_own_points(): void
    {
        $first = $this->customer('محمد', '9111');
        $second = $this->customer('محمد', '9222');

        $this->sell(['customer' => 'محمد', 'customer_id' => $second->id])->assertOk();

        $this->assertSame(0, (int) $first->fresh()->points, 'أخذ الأوّل نقاطًا ليست له');
        $this->assertSame(50, (int) $second->fresh()->points, 'لم تصل النقاط إلى المشتري');
    }

    public function test_the_order_is_linked_to_the_right_customer(): void
    {
        $this->customer('محمد', '9111');
        $second = $this->customer('محمد', '9222');

        $this->sell(['customer' => 'محمد', 'customer_id' => $second->id]);

        $this->assertSame($second->id, Order::where('is_held', false)->firstOrFail()->customer_id);
    }

    /** والهاتف يعرّفه حين لا يصل المعرّف — كطلبٍ من طابور غير متصل */
    public function test_the_phone_identifies_the_person_when_no_id_is_sent(): void
    {
        $first = $this->customer('محمد', '9111');
        $second = $this->customer('محمد', '9222');

        $this->sell(['customer' => 'محمد', 'customer_phone' => '9222'])->assertOk();

        $this->assertSame(0, (int) $first->fresh()->points);
        $this->assertSame(50, (int) $second->fresh()->points);
    }

    /**
     * وحين يبقى الاسم وحده ويطابق أكثر من واحد: لا يُربط أحد.
     *
     * بيعةٌ بلا نقاط يشتكي منها العميل فتُصحَّح، ونقاطٌ تذهب لغير صاحبها لا
     * يلحظها أحد.
     */
    public function test_an_ambiguous_name_awards_nobody(): void
    {
        $first = $this->customer('محمد', '9111');
        $second = $this->customer('محمد', '9222');

        $this->sell(['customer' => 'محمد'])->assertOk();

        $this->assertSame(0, (int) $first->fresh()->points);
        $this->assertSame(0, (int) $second->fresh()->points);
        $this->assertNull(Order::where('is_held', false)->firstOrFail()->customer_id);
    }

    /** والاسم الفريد يبقى كافيًا — لا نكسر ما كان يعمل */
    public function test_a_unique_name_still_works(): void
    {
        $c = $this->customer('سالم', '9333');

        $this->sell(['customer' => 'سالم'])->assertOk();

        $this->assertSame(50, (int) $c->fresh()->points);
    }

    /* ---------------------- الاستبدال من رصيده ---------------------- */

    public function test_redeeming_takes_from_the_right_balance(): void
    {
        $rich = $this->customer('محمد', '9111', 500);
        $poor = $this->customer('محمد', '9222', 0);

        $this->sell(['customer' => 'محمد', 'customer_id' => $poor->id, 'redeem_points' => 200])->assertOk();

        $this->assertSame(500, (int) $rich->fresh()->points, 'خُصم من رصيد شخصٍ لم يشترِ');
    }

    public function test_a_customer_cannot_redeem_more_than_they_have(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'loyalty_redeem_min', 'value' => '10']);
        $c = $this->customer('سالم', '9333', 50);

        $this->sell(['customer' => 'سالم', 'customer_id' => $c->id, 'redeem_points' => 9999])->assertOk();

        $this->assertGreaterThanOrEqual(0, (int) $c->fresh()->points, 'صار الرصيد سالبًا');
    }

    /* ------------------------- عزل المتاجر ------------------------- */

    public function test_a_neighbours_customer_id_is_refused(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirCustomer = Customer::create(['business_id' => $theirs->id, 'name' => 'جارهم', 'phone' => '9444']);

        $this->sell(['customer' => 'جارهم', 'customer_id' => $theirCustomer->id])->assertOk();

        $this->assertSame(0, (int) $theirCustomer->fresh()->points, 'وصلت نقاط إلى عميل متجرٍ آخر');
    }

    /* -------------------- الهاتف مفتاح لا زينة -------------------- */

    /**
     * سجلّان بالهاتف نفسه شخصٌ واحد برصيدَي نقاط: يشتري فتُضاف إلى أحدهما،
     * ويأتي ليستبدل فيُقرأ الآخر فيُقال له «رصيدك صفر» وقد اشترى للتوّ.
     */
    public function test_a_duplicate_phone_is_refused(): void
    {
        $this->customer('محمد', '9111');

        $this->post(route('admin.customers.store'), ['name' => 'محمد آخر', 'phone' => '9111'])
            ->assertSessionHasErrors('phone');

        $this->postJson(route('pos.customers.store'), ['name' => 'ثالث', 'phone' => '9111'])
            ->assertStatus(422);

        $this->assertSame(1, Customer::where('phone', '9111')->count());
    }

    /** والفراغ مسموح: عابرٌ بلا هاتف عميلٌ صحيح */
    public function test_customers_without_a_phone_are_still_allowed(): void
    {
        $this->post(route('admin.customers.store'), ['name' => 'عابر أول'])->assertSessionHasNoErrors();
        $this->post(route('admin.customers.store'), ['name' => 'عابر ثانٍ'])->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::whereNull('phone')->count());
    }

    /** والرقم نفسه في متجرٍ آخر شخصٌ آخر — القيد داخل المتجر لا عبره */
    public function test_the_same_phone_may_exist_in_another_business(): void
    {
        $this->customer('محمد', '9111');
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        Customer::create(['business_id' => $theirs->id, 'name' => 'زبونهم', 'phone' => '9111']);

        $this->assertSame(2, Customer::where('phone', '9111')->count());
    }
}
