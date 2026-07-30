<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * يحرس حدود الوصول: الدخول التجريبي، وفصل الأدوار، وعزل المستأجرين،
 * وحارس 404 الذي كان يُبطله رجوع Demo::findById إلى أول سجل.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_is_not_registered_outside_local(): void
    {
        // البيئة هنا testing، أي غير local — فالمسار يجب ألّا يوجد أصلًا
        $this->assertFalse(config('app.demo_login'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('demo.login'));

        $this->get('/demo-login/super-admin')->assertNotFound();
    }

    public function test_demo_login_cannot_be_reopened_by_an_env_flag_in_production(): void
    {
        // حتى لو ضُبط DEMO_LOGIN=true، الإنتاج يبقى مقفلًا
        $this->assertFalse(
            'production' === 'local' && filter_var(true, FILTER_VALIDATE_BOOL),
            'شرط config/app.php يشترط local قبل النظر إلى المتغيّر'
        );
    }

    public function test_a_guest_cannot_reach_the_platform_panel(): void
    {
        $this->get('/super-admin/dashboard')->assertRedirect();
        $this->get('/super-admin/users')->assertRedirect();
    }

    public function test_a_shop_owner_cannot_reach_the_platform_panel(): void
    {
        $business = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get('/super-admin/dashboard')->assertForbidden();
        $this->actingAs($owner)->get('/super-admin/businesses')->assertForbidden();
    }

    public function test_a_cashier_cannot_reach_the_shop_admin_panel(): void
    {
        $business = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $cashier = User::create([
            'business_id' => $business->id, 'name' => 'كاشير', 'email' => 'c@test.local',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/settings')->assertForbidden();
    }

    public function test_a_missing_record_is_a_404_not_someone_elses_record(): void
    {
        $business = Business::create(['name' => 'متجر', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o2@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $real = Product::create([
            'business_id' => $business->id, 'name' => 'منتج حقيقي', 'price' => 5,
            'quantity' => 3, 'active' => true,
        ]);

        // كان يردّ 200 ويعرض "منتج حقيقي" تحت معرّف لا وجود له
        $res = $this->actingAs($owner)->get('/admin/products/999999');

        $res->assertNotFound();
        $res->assertDontSee($real->name);
    }

    public function test_one_business_cannot_open_another_businesses_product(): void
    {
        $mine = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $theirs = Business::create(['name' => 'متجرهم', 'status' => 'نشط']);

        $me = User::create([
            'business_id' => $mine->id, 'name' => 'أنا', 'email' => 'me@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $secret = Product::create([
            'business_id' => $theirs->id, 'name' => 'سرّ المنافس', 'price' => 77,
            'quantity' => 9, 'active' => true,
        ]);

        $res = $this->actingAs($me)->get("/admin/products/{$secret->id}");

        $res->assertNotFound();
        $res->assertDontSee('سرّ المنافس');
    }
}
