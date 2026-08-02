<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * عمليات الكتابة في لوحة المنصة.
 *
 * الصفحات كانت مغطّاة بالكنس، أما ما يكتب في القاعدة — إنشاء شركة، تعديل
 * باقة، إيقاف حساب — فلم يكن مغطّى بشيء. وهذه بالذات لا يكفي فيها أن
 * تُرجع الشاشة تنبيه نجاح.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'الباقة الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ الشركات ------------------------------ */

    public function test_it_creates_a_business(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.store'), [
            'name' => 'شركة الفحص', 'type' => 'عام', 'owner_name' => 'المالك',
            'email' => 'biz@abaad.om', 'phone' => '+96890000001',
            'country' => 'عُمان', 'city' => 'مسقط', 'plan_id' => $this->plan->id,
            'status' => 'نشط',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('businesses', ['name' => 'شركة الفحص', 'status' => 'نشط']);
    }

    public function test_it_updates_a_business(): void
    {
        $biz = Business::create(['name' => 'قديم', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->super)->put(route('super-admin.businesses.update', $biz->id), [
            'name' => 'جديد', 'type' => 'عام', 'status' => 'نشط',
        ])->assertSessionHasNoErrors();

        $this->assertSame('جديد', $biz->fresh()->name);
    }

    public function test_removing_a_business_disables_it_and_keeps_its_data(): void
    {
        // متعمَّد ولا يجوز أن ينقلب: محو مستأجر يأخذ معه طلباته وفواتيره
        // وسجلّه الضريبي. الواجهة تسمّيه «تعطيل» لهذا السبب.
        $biz = Business::create(['name' => 'للتعطيل', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->super)
            ->delete(route('super-admin.businesses.destroy', $biz->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('businesses', ['id' => $biz->id, 'status' => 'معطل']);
    }

    public function test_a_business_needs_a_name(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.store'), ['name' => '', 'status' => 'نشط'])
            ->assertSessionHasErrors('name');
    }

    /* ----------------------------- المستخدمون ---------------------------- */

    public function test_it_creates_a_platform_user(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مستخدم الفحص', 'email' => 'u@abaad.om',
            'role' => 'manager', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'u@abaad.om', 'role' => 'manager']);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مكرّر', 'email' => $this->super->email, 'role' => 'manager',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_stored_password_is_hashed_not_plain(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مستخدم', 'email' => 'hash@abaad.om',
            'role' => 'manager', 'password' => 'secret12345',
        ]);

        $stored = User::where('email', 'hash@abaad.om')->value('password');
        $this->assertNotSame('secret12345', $stored);
        $this->assertTrue(password_verify('secret12345', $stored));
    }

    public function test_toggling_a_user_flips_the_status_both_ways(): void
    {
        $user = User::create([
            'business_id' => null, 'name' => 'هدف', 'email' => 't@abaad.om',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $user->id));
        $this->assertSame('موقوف', $user->fresh()->status);

        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $user->id));
        $this->assertSame('نشط', $user->fresh()->status);
    }

    public function test_the_platform_admin_cannot_lock_themselves_out(): void
    {
        // لا يوجد باب خلفي لإعادة التفعيل — الإيقاف الذاتي يُقفل اللوحة للأبد
        $this->actingAs($this->super)->post(route('super-admin.users.toggle', $this->super->id));

        $this->assertSame('نشط', $this->super->fresh()->status);
    }

    /* ------------------------------ الباقات ------------------------------ */

    public function test_it_updates_a_plan_price(): void
    {
        $this->actingAs($this->super)->put(route('super-admin.plans.update', $this->plan->id), [
            'name' => 'الباقة الأساسية', 'monthly_price' => 25, 'yearly_price' => 250,
            'max_branches' => 2, 'max_employees' => 5, 'max_products' => 200,
        ])->assertSessionHasNoErrors();

        $this->assertSame(25.0, (float) $this->plan->fresh()->monthly_price);
    }

    /* ------------------------------ الحراسة ------------------------------ */

    public static function writeRoutes(): array
    {
        return [
            ['post', 'super-admin.businesses.store'],
            ['post', 'super-admin.users.store'],
            ['post', 'super-admin.settings.update'],
            ['post', 'super-admin.plans.store'],
        ];
    }

    #[DataProvider('writeRoutes')]
    public function test_a_merchant_owner_cannot_write_through_the_platform_panel(string $verb, string $name): void
    {
        $biz = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $biz->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->{$verb}(route($name), [])->assertForbidden();
    }

    #[DataProvider('writeRoutes')]
    public function test_a_guest_cannot_write_either(string $verb, string $name): void
    {
        $this->{$verb}(route($name), [])->assertRedirect(route('login'));
    }
}
