<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * لوحة النشاط ونقطة البيع لا تُفتحان لمن لا متجر له.
 *
 * مدير المنصة كان يمرّ: CheckRole يستثنيه من كل فحص، و/pos لا تفحص الدور.
 * فتنهار الصفحة (context غير مُرسَل) — أو الأسوأ، تصمد وتعرض بيانات متجر
 * آخر لأن Demo::bid() ترجع أول نشاط لمن لا يملك واحدًا.
 */
class RequiresBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->mine->id);
    }

    private function superAdmin(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'مدير المنصة',
            'email' => 'super@abaad.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    public static function merchantRoutes(): array
    {
        return [
            ['admin.dashboard'], ['admin.products.index'], ['admin.orders.index'],
            ['admin.customers.index'], ['admin.settings.index'], ['admin.employees.index'],
            ['pos.index'], ['pos.orders'], ['pos.receipts'], ['pos.customers'],
        ];
    }

    #[DataProvider('merchantRoutes')]
    public function test_the_platform_admin_is_sent_back_to_their_own_panel(string $name): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route($name))
            ->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_no_other_business_data_leaks_on_the_way(): void
    {
        Product::create([
            'business_id' => $this->theirs->id, 'name' => 'سرّ المتجر الآخر',
            'price' => 10, 'quantity' => 5, 'active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.products.index'));

        $response->assertRedirect(route('super-admin.dashboard'));
        $response->assertDontSee('سرّ المتجر الآخر');
    }

    public function test_a_user_orphaned_from_every_business_is_refused_outright(): void
    {
        $orphan = User::create([
            'business_id' => null, 'name' => 'بلا متجر',
            'email' => 'orphan@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($orphan)->get(route('pos.index'))->assertForbidden();
    }

    #[DataProvider('merchantRoutes')]
    public function test_a_real_merchant_user_passes_through_untouched(string $name): void
    {
        $owner = User::create([
            'business_id' => $this->mine->id, 'name' => 'المالك',
            'email' => 'owner@abaad.om', 'password' => bcrypt('password'),
            'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route($name))->assertOk();
    }

    public function test_the_platform_panel_still_belongs_to_the_platform_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.dashboard'))
            ->assertOk();
    }
}
