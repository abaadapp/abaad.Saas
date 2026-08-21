<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\PlanLimits;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * لوحة المنصّة: ما تقوله أرقامُها وما تفعله مقابضُها.
 *
 * كلّ اختبارٍ هنا يحرس تطابقًا بين شاشتين تتكلّمان عن الشيء نفسه، أو مقبضًا
 * يُحفَظ ويُقرأ فعلًا. والرقمان المفترقان أسوأ من الرقم الخاطئ: أحدهما يبدو
 * صحيحًا دائمًا.
 */
class PlatformAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function demoStore(): Business
    {
        return Business::create(['name' => 'المتجر التجريبي', 'status' => 'نشط', 'is_demo' => true]);
    }

    /* ------------------ الضريبة الافتراضية للمنصّة ------------------ */

    public function test_the_platform_tax_mode_reaches_a_store_that_never_set_one(): void
    {
        /*
         * كانت النسبة ترجع إلى افتراضيّ المنصّة والطريقةُ لا ترجع — وهما في
         * بطاقةٍ واحدة تعد بأنها «تُطبَّق على متجرٍ لم يضبط ضريبته».
         */
        Setting::create(['business_id' => null, 'key' => 'tax_mode', 'value' => 'inclusive']);

        $this->assertTrue(Vat::inclusive($this->business->id));
    }

    public function test_a_store_that_set_its_own_mode_is_not_overridden(): void
    {
        Setting::create(['business_id' => null, 'key' => 'tax_mode', 'value' => 'inclusive']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'tax_mode', 'value' => 'exclusive']);

        $this->assertFalse(Vat::inclusive($this->business->id));
    }

    /* --------------------------- سقوف الباقة --------------------------- */

    public function test_a_plan_made_from_the_screen_can_carry_the_ceilings_it_sells(): void
    {
        // كانت الحقول غائبة عن النافذة، فتُولد كل باقةٍ بلا حدّ مهما كُتب في مزاياها
        $this->actingAs($this->super)->post(route('super-admin.plans.store'), [
            'name' => 'باقة الفحص', 'monthly_price' => 9.9, 'yearly_price' => 99,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
        ])->assertSessionHasNoErrors();

        $plan = Plan::where('name', 'باقة الفحص')->first();
        $this->business->update(['plan_id' => $plan->id]);

        $this->assertSame(1, PlanLimits::cap($this->business->fresh()->load('plan'), 'branches'));
    }

    public function test_editing_a_plans_price_does_not_wipe_its_ceilings(): void
    {
        $plan = Plan::create([
            'name' => 'باقة', 'monthly_price' => 9.9, 'yearly_price' => 99,
            'max_branches' => 2, 'max_employees' => 5, 'max_products' => 50,
        ]);

        $this->actingAs($this->super)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة', 'monthly_price' => 12, 'yearly_price' => 120,
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(2, (int) $plan->max_branches);
        $this->assertSame(5, (int) $plan->max_employees);
        $this->assertSame(50, (int) $plan->max_products);
    }

    public function test_a_ceiling_below_one_is_refused(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.plans.store'), [
            'name' => 'باقة صفر', 'monthly_price' => 1, 'yearly_price' => 10, 'max_branches' => 0,
        ])->assertSessionHasErrors('max_branches');
    }

    /* ------------------------ إعدادات المنصّة ------------------------ */

    public function test_a_trial_length_that_is_not_a_number_is_refused_not_silently_zeroed(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.settings.update'), ['trial_days' => 'أسبوعان'])
            ->assertSessionHasErrors('trial_days');

        $this->assertDatabaseMissing('settings', ['business_id' => null, 'key' => 'trial_days']);
    }

    public function test_a_key_the_screen_does_not_own_is_not_written(): void
    {
        // كان كل مفتاحٍ في الطلب يُكتب في جدول الإعدادات العامّ بلا قائمة
        $this->actingAs($this->super)->post(route('super-admin.settings.update'), [
            'app_name' => 'أبعاد', 'مفتاح_مخترع' => 'قيمة',
        ]);

        $this->assertDatabaseHas('settings', ['business_id' => null, 'key' => 'app_name']);
        $this->assertDatabaseMissing('settings', ['key' => 'مفتاح_مخترع']);
    }

    public function test_a_default_plan_that_matches_nothing_is_refused_at_the_door(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $this->actingAs($this->super)
            ->post(route('super-admin.settings.update'), ['default_plan' => 'الباقه الاساسيه'])
            ->assertSessionHasErrors('default_plan');

        $this->actingAs($this->super)
            ->post(route('super-admin.settings.update'), ['default_plan' => 'الباقة الأساسية'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_settings_screen_offers_the_plans_rather_than_asking_them_typed(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $this->actingAs($this->super)->get(route('super-admin.settings.index'))
            ->assertInertia(fn (Assert $p) => $p->has('plans', 1));
    }

    /* -------------------- أرقامٌ لا تفترق عن بعضها -------------------- */

    public function test_the_dashboard_counts_the_users_its_own_list_shows(): void
    {
        $demo = $this->demoStore();
        foreach (range(1, 4) as $i) {
            User::create([
                'business_id' => $demo->id, 'name' => "تجريبي {$i}", 'email' => "d{$i}@abaadapp.om",
                'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
            ]);
        }
        User::create([
            'business_id' => $this->business->id, 'name' => 'حقيقي', 'email' => 'real@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $card = collect(Demo::superStats())->firstWhere('label', __('المستخدمون'));

        $listed = null;
        $this->actingAs($this->super)->get(route('super-admin.users.index'))
            ->assertInertia(function (Assert $p) use (&$listed) {
                $listed = count($p->toArray()['props']['users']);
            });

        $this->assertSame((string) $listed, $card['value']);
    }

    public function test_the_platform_report_counts_orders_as_the_profile_screen_does(): void
    {
        $order = fn (string $status, int $n) => Order::create([
            'business_id' => $this->business->id, 'number' => $n, 'customer_name' => 'عميل نقدي',
            'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => $status, 'is_held' => false, 'ordered_at' => now(),
        ]);
        foreach ([1, 2, 3] as $n) { $order('مكتمل', $n); }
        $order(Order::CANCELLED, 4);

        $row = collect(Demo::businessPerformance())->firstWhere('id', $this->business->id);
        $counts = Demo::businessCounts($this->business->id);

        $this->assertSame($counts['orders'], $row['orders']);
        $this->assertSame(3, $row['orders']);
    }

    public function test_the_demo_store_is_out_of_the_platform_report(): void
    {
        $demo = $this->demoStore();

        $ids = collect(Demo::businessPerformance())->pluck('id')->all();

        $this->assertContains($this->business->id, $ids);
        $this->assertNotContains($demo->id, $ids);
    }

    /* ------------------- صفُّ المتجر لأوراق الطباعة ------------------- */

    public function test_a_demo_store_still_prints_under_its_own_name(): void
    {
        /*
         * `Demo::business()` كانت تُلتقط من قائمةٍ مقصورة على `real()`،
         * فترجع فارغةً لمتجرٍ تجريبيّ — وكلُّ ورقةٍ يطبعها تخرج باسم
         * «Abad POS» لأن القوالب تسقط إلى ذلك عند غياب الاسم.
         */
        $demo = $this->demoStore();

        $row = Demo::business($demo->id);

        $this->assertSame($demo->name, $row['name'] ?? null);
    }

    public function test_the_tax_invoice_qr_names_the_actual_seller(): void
    {
        $demo = $this->demoStore();
        $order = Order::create([
            'business_id' => $demo->id, 'number' => 1, 'customer_name' => 'عميل نقدي',
            'subtotal' => 10, 'tax' => 0.5, 'discount' => 0, 'total' => 10.5,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);

        $qr = \App\Support\EInvoice::forOrder($order, ['number' => 'OM1234567'], Demo::business($demo->id));

        $this->assertStringContainsString($demo->name, base64_decode($qr));
    }

    public function test_reading_one_store_does_not_read_them_all(): void
    {
        foreach (range(1, 10) as $i) {
            Business::create(['name' => "متجر {$i}", 'status' => 'نشط']);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Demo::business($this->business->id);
        $rows = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "businesses"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $rows);
    }

    public function test_the_platform_list_still_hides_the_demo_store(): void
    {
        // القائمة تبقى على `real()` — الإصلاح للصفّ الواحد لا للقائمة
        $demo = $this->demoStore();

        $this->assertNotContains($demo->id, collect(Demo::businesses())->pluck('id')->all());
    }

    public function test_a_profile_shows_its_own_subscription_not_a_namesake(): void
    {
        // كانت المطابقة بالاسم: متجران بالاسم نفسه يتبادلان اشتراكيهما
        $twin = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        \App\Models\Subscription::create([
            'business_id' => $twin->id, 'starts_at' => now(), 'ends_at' => now()->addYear(),
            'amount' => 99, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);
        \App\Models\Subscription::create([
            'business_id' => $this->business->id, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
            'amount' => 9.9, 'payment_status' => 'غير مدفوع', 'status' => 'نشط',
        ]);

        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->business->id))
            ->assertInertia(fn (Assert $p) => $p->where('subscription.business_id', $this->business->id));
    }
}
