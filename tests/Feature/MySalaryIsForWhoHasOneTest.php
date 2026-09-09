<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «حسابي وراتبي» صفحةُ موظّف — ومديرُ المنصّة ليس موظّفًا.
 *
 * ═══ ما كان ═══
 *
 * الشرط في شريط اللوحة: «دورُه ليس `admin`» — ومديرُ المنصّة دورُه
 * `super_admin`، فيمرّ. ويملك كلّ قسمٍ بحكم دوره، فتمرّ معه صلاحيةُ «نقطة
 * البيع» أيضًا. فيرى في قائمة حسابه «حسابي وراتبي» ويفتحها.
 *
 * فإن ضغطه قذفه `RequiresBusiness` إلى لوحة المنصّة برسالةٍ عن «لوحة النشاط
 * تخصّ متجرًا بعينه» — لا عن راتب. بابٌ مرسومٌ لا يؤدّي إلى شيء.
 *
 * ═══ والخادمُ كان يردّه أصلًا ═══
 *
 * وقِسنا الأدوار الثلاثة على المسار قبل الإصلاح:
 *
 *   super_admin → ٣٠٢ إلى لوحة المنصّة   ·   admin → ٢٠٠   ·   cashier → ٢٠٠
 *
 * فلا ثغرةَ في الخادم: `RequiresBusiness` تردّ مديرَ المنصّة قبل المتحكّم.
 * العطبُ في الشاشة وحدها — بندٌ يُرسم لمن يُردّ عنه.
 *
 * ═══ ولماذا يبقى البابُ مفتوحًا لصاحب النشاط ═══
 *
 * البندُ يُخفى عنه — ملفُّه في الغالب ليس ملفَّ موظّف — ولا يُحرَس المسار
 * عنه: `payableEmployees` لا ترشّح بالدور، وشاشةُ الموظفين تستثني
 * `super_admin` وحده. فصاحبُ نشاطٍ سجّل لنفسه راتبًا له سطرٌ في المسيرة
 * فعلًا، وحجبُ الصفحة عنه يُخفي عنه مسيرتَه هو.
 *
 * ومديرُ الفرع موظّفٌ يُستثنى من الاستثناء: له راتبٌ ومسيرة.
 */
class MySalaryIsForWhoHasOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function person(string $role, ?int $businessId): User
    {
        return User::create([
            'business_id' => $businessId, 'name' => $role,
            'email' => $role.'-'.($businessId ?? 'x').'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function operator(): User
    {
        return $this->person('super_admin', null);
    }

    private function owner(): User
    {
        return $this->person('admin', $this->shop->id);
    }

    private function clerk(string $role = 'cashier'): User
    {
        return $this->person($role, $this->shop->id);
    }

    /* ═══════════ من يفتحها ومن يُردّ ═══════════ */

    public function test_the_platform_admin_never_reaches_the_page(): void
    {
        $operator = $this->operator();

        // ويملك «نقطة البيع» بحكم دوره — فالصلاحية وحدها لم تكن حارسًا
        $this->assertTrue($operator->allows('pos'));

        // ويردّه `RequiresBusiness` قبل المتحكّم: لا متجرَ له
        $this->actingAs($operator)->get(route('pos.me'))
            ->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_the_owner_keeps_the_page_but_not_the_link(): void
    {
        /*
         * ولم يُحجَب عنه: صاحبُ نشاطٍ سجّل لنفسه راتبًا له سطرٌ في المسيرة —
         * `payableEmployees` لا ترشّح بالدور. فحجبُ الصفحة يُخفي عنه مسيرتَه.
         * والبندُ يبقى مخفيًّا عنه كما كان.
         */
        $owner = $this->owner();
        $owner->update(['basic_salary' => 900]);

        $props = $this->actingAs($owner)->get(route('pos.me'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(900.0, $props['salary']['monthly']);
        $this->assertFalse($owner->isEmployee(), 'ولا يُرسم له البند');
    }

    public function test_an_employee_opens_his_own_page(): void
    {
        $clerk = $this->clerk();
        $clerk->update(['basic_salary' => 300, 'allowances' => 50]);

        $props = $this->actingAs($clerk)->get(route('pos.me'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(350.0, $props['salary']['monthly']);
    }

    public function test_a_branch_manager_is_an_employee(): void
    {
        // يُستثنى من الاستثناء: `isAdmin()` تشمله، وهو مع ذلك ذو راتبٍ ومسيرة
        $this->actingAs($this->clerk('manager'))->get(route('pos.me'))->assertOk();
    }

    public function test_every_shop_role_that_is_not_the_owner_may_open_it(): void
    {
        foreach (['cashier', 'sales', 'inventory', 'accountant', 'delivery'] as $role) {
            $this->assertTrue($this->person($role, $this->shop->id)->isEmployee(), $role);
        }
    }

    /* ═══════════ والتعريفُ واحد ═══════════ */

    public function test_who_is_an_employee_is_asked_in_one_place(): void
    {
        $this->assertFalse($this->operator()->isEmployee(), 'مدير المنصّة');
        $this->assertFalse($this->owner()->isEmployee(), 'صاحب النشاط');
        $this->assertTrue($this->clerk()->isEmployee(), 'الكاشير');
        $this->assertTrue($this->clerk('manager')->isEmployee(), 'مدير الفرع');

        // ودورُ متجرٍ بلا متجر ليس موظّفًا: تردّه `RequiresBusiness` أصلًا
        $this->assertFalse($this->person('cashier', null)->isEmployee(), 'بلا نشاط مربوط');
    }

    /* ═══════════ وما لا يُفتح لا يُرسم ═══════════ */

    public function test_the_platform_admin_is_not_offered_the_link(): void
    {
        $props = $this->actingAs($this->operator())
            ->get(route('super-admin.dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['auth']['isEmployee'], 'عُرض لمدير المنصّة بابٌ يُردّ عنه');
    }

    public function test_the_employee_is_offered_the_link(): void
    {
        $props = $this->actingAs($this->clerk('sales'))
            ->get(route('pos.index'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['auth']['isEmployee']);
    }

    public function test_the_screen_asks_the_server_not_the_role(): void
    {
        /*
         * وعلى شكل الشفرة: تعليقي في الملفّ يذكر `admin` شرحًا لِما تغيّر،
         * فالقياس على الشرط نفسه لا على ورود الكلمة.
         */
        $topbar = file_get_contents(resource_path('js/Components/Topbar.tsx'));

        $this->assertStringContainsString("{auth?.isEmployee && auth?.abilities.includes('pos') && (", $topbar);
        $this->assertStringNotContainsString("{auth?.user.role !== 'admin' && auth?.abilities.includes('pos')", $topbar);
    }
}
