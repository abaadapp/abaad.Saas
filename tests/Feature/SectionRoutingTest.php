<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كلّ مسارٍ محروسٍ يقع على قسمٍ يمكن منحه.
 *
 * الحارس يشتقّ القسم من اسم المسار. وحين يفترق الاسم عن مفتاح الصلاحية يُنتج
 * مفتاحًا لا وجود له في SECTIONS: لا يملكه أحد، فلا يفتحه إلا المالك والمدير
 * (لهما '*'). والنتيجة صفحةٌ في القائمة لا تُفتح، أو علامةٌ يرفعها صاحب النشاط
 * ولا تغيّر شيئًا — وهو أسوأ من المنع الصريح لأنه لا يُعلن عن نفسه.
 *
 * أوضحها «الفروع»: مساره admin.branches والمفتاح 'branch'.
 */
class SectionRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function userWith(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظف',
            'email' => 'e'.uniqid().'@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /* ----------------------- الحارس والمسارات ----------------------- */

    /**
     * الفحص الشامل: لا مسار محروسًا يسقط على قسمٍ لا يُمنح.
     *
     * هذا يمسك المسار التالي الذي يُضاف باسمٍ لا يطابق مفتاحه، فلا يتكرّر
     * اكتشافُ العطب بالصدفة بعد شهر.
     */
    public function test_every_guarded_route_maps_to_a_grantable_section(): void
    {
        $orphans = [];

        foreach (Route::getRoutes() as $r) {
            $name = $r->getName();
            if (! $name || Permissions::isShell($name)) {
                continue;
            }
            if (! collect($r->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'ability'))) {
                continue;
            }

            $section = Permissions::sectionFromRoute($name);
            if (! in_array($section, Permissions::SECTIONS, true)) {
                $orphans[] = "{$name} → {$section}";
            }
        }

        $this->assertSame([], $orphans, 'مسارات تسقط على أقسام لا تُمنح: '.implode(', ', $orphans));
    }

    /** والفروع بعينها: مُنحت فانفتحت */
    public function test_granting_branches_actually_opens_them(): void
    {
        $this->actingAs($this->userWith(['branch']))
            ->get(route('admin.branches.index'))
            ->assertOk();
    }

    /** ومن لم يُمنحها لا تنفتح له — الإصلاح لم يفتح البابَ للجميع */
    public function test_not_granting_branches_still_closes_them(): void
    {
        $this->actingAs($this->userWith(['inventory']))
            ->get(route('admin.branches.index'))
            ->assertForbidden();
    }

    /** والإضافات تتبع المنتجات، وأوامر الشراء قسمها */
    public function test_addons_follow_products(): void
    {
        $this->assertSame('products', Permissions::sectionFromRoute('admin.addons.index'));
        $this->assertSame('employees', Permissions::sectionFromRoute('admin.jobTitles.store'));
        $this->assertSame('marketing', Permissions::sectionFromRoute('admin.coupons.store'));
        $this->assertSame('finance', Permissions::sectionFromRoute('admin.bank.import'));
        $this->assertSame('expenses', Permissions::sectionFromRoute('admin.expenseTypes.store'));
    }

    /** والتصدير يتبع ما يُصدَّر لا كلمة «تصدير» */
    public function test_export_follows_what_is_exported(): void
    {
        $this->assertSame('orders', Permissions::sectionFromRoute('admin.export.orders'));
        $this->assertSame('customers', Permissions::sectionFromRoute('admin.export.customers'));
        $this->assertSame('finance', Permissions::sectionFromRoute('admin.export.transactions'));
    }

    /** ولا يُصدّر من لا يملك القسم */
    public function test_exporting_a_section_needs_that_section(): void
    {
        $this->actingAs($this->userWith(['products']))
            ->get(route('admin.export.customers'))
            ->assertForbidden();
    }

    /* ------------------------- هيكل اللوحة ------------------------- */

    /**
     * الجرس ومبدّل اللغة يعملان لكل من دخل اللوحة.
     *
     * ليست أقسامًا، ونسبتُها إلى «لوحة التحكم» كانت ستُعطّلها عند من مُنح
     * المخزون وحده — فيدخل لوحةً بلا جرسٍ ولا لغة.
     */
    public function test_the_shell_works_for_anyone_inside_the_panel(): void
    {
        $user = $this->userWith(['inventory']);

        $this->actingAs($user)->getJson(route('admin.notifications.feed'))->assertOk();
        $this->actingAs($user)->post(route('admin.language.update'), ['locale' => 'en'])->assertRedirect();
    }

    /**
     * والبحث لا يُسرّب ما لا يُفتح.
     *
     * المسار مفتوح لأنه أداة الشريط العلوي — لكن نتائجه كانت تعرض أسماء
     * العملاء وأرقامهم لمن لا يملك قسمهم: البيانات تصل قبل الباب المغلق.
     */
    public function test_search_returns_only_the_sections_the_user_holds(): void
    {
        \App\Models\Customer::create([
            'business_id' => $this->business->id, 'name' => 'خالد المسعودي', 'phone' => '9911',
        ]);
        \App\Models\Product::create([
            'business_id' => $this->business->id, 'name' => 'خالدية', 'price' => 5,
            'quantity' => 1, 'active' => true,
        ]);

        $response = $this->actingAs($this->userWith(['products']))
            ->getJson(route('admin.search', ['q' => 'خالد']))
            ->assertOk();

        $labels = collect($response->json('groups'))->pluck('items')->flatten(1)->pluck('label');

        $this->assertContains('خالدية', $labels);
        $this->assertNotContains('خالد المسعودي', $labels, 'ظهر عميلٌ لمن لا يملك قسم العملاء');
    }

    /* --------------------- صفحات قسم التقارير --------------------- */

    /**
     * الثلاثة تُفتح من داخل التقارير لا من القائمة الجانبية.
     *
     * «تحليلات متقدمة» عرضٌ من عروض التقارير فتتبع صلاحيتها. والربحية
     * والضريبة قسمان قائمان بذاتهما يُمنحان على حدة.
     */
    public function test_analytics_follows_the_reports_permission(): void
    {
        $this->actingAs($this->userWith(['reports']))
            ->get(route('admin.analytics.index'))
            ->assertOk();
    }

    public function test_profitability_and_vat_are_granted_on_their_own(): void
    {
        $reader = $this->userWith(['reports']);

        $this->actingAs($reader)->get(route('admin.profitability.index'))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.vat.index'))->assertForbidden();

        $this->actingAs($this->userWith(['profitability']))
            ->get(route('admin.profitability.index'))
            ->assertOk();
    }
}
