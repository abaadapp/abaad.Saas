<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة المدفوعات في نقطة البيع لصاحب النشاط لا للكاشير.
 *
 * تعرض حصيلة الصندوق وتوزيع طرق الدفع — معلومة إدارية لا يحتاجها من
 * يقف على الصندوق. وإخفاء الرابط من الشريط وحده لا يكفي: بدون حارس على
 * الخادم يفتحها الكاشير بكتابة العنوان. فتُسقَط على صلاحية «finance»
 * القائمة بدل قاعدة جديدة تُنسى.
 */
class PosPaymentsAccessTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->business->id);
    }

    private function user(string $role): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => $role, 'email' => $role . '@pos.local',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    public function test_the_payments_screen_maps_to_the_finance_ability_not_pos(): void
    {
        // لو عادت 'pos' لانفتحت للجميع: allows() تسمح بـpos دائمًا
        $this->assertSame('finance', Permissions::sectionFromRoute('pos.payments'));
        $this->assertSame('pos', Permissions::sectionFromRoute('pos.index'));
        $this->assertSame('pos', Permissions::sectionFromRoute('pos.receipts'));
    }

    public static function allowedRoles(): array
    {
        return [['admin'], ['manager'], ['accountant']];
    }

    #[DataProvider('allowedRoles')]
    public function test_roles_that_handle_money_can_open_it(string $role): void
    {
        $this->actingAs($this->user($role))
            ->get(route('pos.payments'))
            ->assertOk();
    }

    public static function deniedRoles(): array
    {
        return [['cashier'], ['sales'], ['inventory'], ['delivery']];
    }

    #[DataProvider('deniedRoles')]
    public function test_a_cashier_cannot_open_it_even_by_typing_the_url(string $role): void
    {
        $this->actingAs($this->user($role))
            ->get(route('pos.payments'))
            ->assertForbidden();
    }

    public function test_the_rest_of_the_pos_stays_open_to_the_cashier(): void
    {
        $cashier = $this->user('cashier');

        foreach (['pos.index', 'pos.orders', 'pos.receipts', 'pos.customers'] as $name) {
            $this->actingAs($cashier)->get(route($name))->assertOk();
        }
    }

    public function test_the_shared_abilities_tell_the_menu_what_to_hide(): void
    {
        $owner = $this->actingAs($this->user('admin'))
            ->get(route('pos.index'))->viewData('page')['props']['auth']['abilities'];
        $cashier = $this->actingAs($this->user('cashier'))
            ->get(route('pos.index'))->viewData('page')['props']['auth']['abilities'];

        $this->assertContains('finance', $owner);
        $this->assertNotContains('finance', $cashier);
    }
}
