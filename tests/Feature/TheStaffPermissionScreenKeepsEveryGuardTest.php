<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ الصلاحيات تبدّل شكلُها — والأبوابُ خلفها كما هي.
 *
 * لوحةُ البطاقات والبحث و«تحديد الكل» (`PermissionBoard`) عرضٌ في الواجهة
 * وحدها. وهذا يقيس ما لا تملكه الواجهةُ أصلًا: ما يقبله الخادمُ ممّن يرسل
 * الطلبَ بيده، من متجره أو من متجرٍ غيره. لا شيء هنا يُصلَح — يُثبَّت.
 *
 * والواجهةُ تُحرس في `tests/js/the-staff-permission-board-is-simple-and-safe.test.tsx`.
 */
class TheStaffPermissionScreenKeepsEveryGuardTest extends TestCase
{
    use RefreshDatabase;

    private Business $shopA;

    private Business $shopB;

    private User $ownerA;

    private User $ownerB;

    private User $cashierA;

    private User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shopA = Business::create(['name' => 'متجر أ', 'status' => 'نشط']);
        $this->shopB = Business::create(['name' => 'متجر ب', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shopA->id, 'name' => 'فرع أ']);
        Branch::create(['business_id' => $this->shopB->id, 'name' => 'فرع ب']);

        foreach ([[$this->shopA, 'صاحب النشاط', 'admin'], [$this->shopA, 'كاشير أ', 'cashier'], [$this->shopA, 'محاسب أ', 'accountant'],
            [$this->shopA, 'مدير أ', 'manager'], [$this->shopB, 'صاحب النشاط', 'admin'], [$this->shopB, 'كاشير ب', 'cashier'],
            [$this->shopB, 'مدير ب', 'manager']] as [$shop, $name, $role]) {
            JobTitle::create(['business_id' => $shop->id, 'name' => $name, 'role' => $role]);
        }

        $this->ownerA = $this->person($this->shopA, 'مالك أ', 'owner-a', 'admin', null, 'صاحب النشاط');
        $this->ownerB = $this->person($this->shopB, 'مالك ب', 'owner-b', 'admin', null, 'صاحب النشاط');
        $this->cashierA = $this->person($this->shopA, 'كاشير المتجر أ', 'cashier-a', 'cashier', ['pos'], 'كاشير أ');
        $this->cashierB = $this->person($this->shopB, 'كاشير المتجر ب', 'cashier-b', 'cashier', ['pos', 'inventory'], 'كاشير ب');
    }

    private const TITLES_A = ['صاحب النشاط', 'كاشير أ', 'محاسب أ', 'مدير أ'];

    /** ترتيبُ الأسماء العربيّة يختلف بين SQLite وPostgreSQL — فيُقارن المحتوى */
    private function sorted(iterable $names): array
    {
        return collect($names)->sort()->values()->all();
    }

    private function person(Business $shop, string $name, string $login, string $role, ?array $permissions, ?string $title = null): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => $name, 'email' => $login.'@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'job_title' => $title,
            'permissions' => $permissions, 'status' => 'نشط',
        ]);
    }

    /** ما يرسله النموذجُ حين يُحفظ — بالحقول نفسِها */
    private function form(User $employee, array $over = []): array
    {
        return array_merge([
            'name' => $employee->name, 'job_title' => $employee->job_title, 'branch' => $employee->branch,
            'phone' => $employee->phone, 'login_username' => strstr($employee->email, '@', true),
            'password' => '', 'status' => true, 'monthly_target' => '', 'basic_salary' => '', 'allowances' => '',
            'manual_permissions' => $employee->permissions !== null,
            'permissions' => $employee->permissions ?? Permissions::roleGrants((string) $employee->role),
        ], $over);
    }

    /* ══════════════ ١–٣ · متجرٌ لا يبلغ موظّفي متجرٍ آخر ══════════════ */

    public function test_a_shop_does_not_see_another_shops_staff(): void
    {
        $this->actingAs($this->ownerA)->get(route('admin.employees.index'))->assertOk()
            ->assertDontSee('كاشير المتجر ب')->assertDontSee('cashier-b@abaadapp.om');

        $this->actingAs($this->ownerA)->get(route('admin.employees.edit', $this->cashierB->id))->assertNotFound();
        $this->actingAs($this->ownerA)->get(route('admin.employees.show', $this->cashierB->id))->assertNotFound();
    }

    public function test_a_shop_does_not_rewrite_another_shops_permissions(): void
    {
        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $this->cashierB->id), $this->form($this->cashierB, [
                'job_title' => 'كاشير أ', 'permissions' => [...Permissions::SECTIONS, ...Permissions::actions()],
            ]))->assertNotFound();

        $this->assertSame(['pos', 'inventory'], $this->cashierB->fresh()->permissions);
    }

    public function test_swapping_the_id_in_the_url_reaches_nothing(): void
    {
        foreach ([
            fn () => $this->get(route('admin.employees.edit', $this->cashierB->id)),
            fn () => $this->put(route('admin.employees.update', $this->cashierB->id), $this->form($this->cashierB, ['manual_permissions' => false])),
            fn () => $this->post(route('admin.employees.toggle', $this->cashierB->id)),
            fn () => $this->post(route('admin.employees.resetPassword', $this->cashierB->id)),
        ] as $call) {
            $this->actingAs($this->ownerA);
            $call()->assertNotFound();
        }

        $b = $this->cashierB->fresh();
        $this->assertSame(['pos', 'inventory'], $b->permissions);
        $this->assertSame('نشط', $b->status);
    }

    /* ══════════════ ٤ و٧ · طلبٌ بيده لا يمنح ما لا يملكه ══════════════ */

    public function test_a_hand_written_request_grants_nothing_its_sender_lacks(): void
    {
        $manager = $this->person($this->shopA, 'مسؤول الموظفين', 'staff-lead', 'accountant', ['employees', 'pos'], 'محاسب أ');
        $grantable = Permissions::grantable($manager);
        $this->assertNotContains('finance', $grantable);
        $this->assertNotContains(Permissions::BUSINESS_EXPORT, $grantable);

        foreach (['finance', Permissions::BUSINESS_EXPORT, Permissions::CUSTOMER_PAYMENT_CANCEL] as $beyond) {
            $this->actingAs($manager)
                ->put(route('admin.employees.update', $this->cashierA->id), $this->form($this->cashierA, ['permissions' => ['pos', $beyond]]))
                ->assertForbidden();
        }
        $this->assertSame(['pos'], $this->cashierA->fresh()->permissions);

        $this->actingAs($manager)
            ->post(route('admin.employees.store'), $this->form($this->cashierA, [
                'name' => 'حساب جديد', 'login_username' => 'fresh-one', 'password' => 'password12345',
                'permissions' => ['pos', 'finance'],
            ]))->assertForbidden();
        $this->assertNull(User::where('email', 'fresh-one@abaadapp.om')->first());
    }

    /* ══════════════ ٥ · الوظائفُ والفروعُ من متجره وحده ══════════════ */

    public function test_the_screen_offers_only_the_shops_own_titles_and_branches(): void
    {
        $this->actingAs($this->ownerA)->get(route('admin.employees.edit', $this->cashierA->id))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('jobTitles', fn ($titles) => $this->sorted($titles) === $this->sorted(self::TITLES_A))
                ->where('titleGrants', fn ($grants) => $this->sorted(collect($grants)->keys()) === $this->sorted(self::TITLES_A))
                ->where('branchOptions', fn ($opts) => collect($opts)->pluck('label')->all() === ['فرع أ'])
                ->where('branches', fn ($branches) => collect($branches)->pluck('name')->all() === ['فرع أ'])
                ->where('blockedTitles', [])
                ->where('employee.permissions', ['pos']));

        $this->actingAs($this->ownerA)->get(route('admin.employees.create'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('jobTitles', fn ($titles) => $this->sorted($titles) === $this->sorted(self::TITLES_A))
                ->where('branchOptions', fn ($opts) => collect($opts)->pluck('label')->all() === ['فرع أ']));
    }

    /* ══════════════ ٦ · لا يكتب أحدٌ صلاحياتِ حسابه ══════════════ */

    public function test_no_one_writes_the_permissions_of_their_own_account(): void
    {
        // صاحبُ النشاط يُعفى من قياس المنح — فلا يردّه إلّا هذا الباب
        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $this->ownerA->id), $this->form($this->ownerA, [
                'manual_permissions' => true, 'permissions' => ['pos'],
            ]))->assertSessionHasErrors(['permissions' => 'لا يمكنك تعديل صلاحيات حسابك الخاص.']);
        $this->assertNull($this->ownerA->fresh()->permissions, 'صاحبُ النشاط بقي على دوره');

        // ومديرٌ مخصَّص يوسّع قائمتَه بيده: يُردّ قبل أن يبلغ ذلك الباب
        $lead = $this->person($this->shopA, 'مدير الفرع', 'branch-lead', 'manager', ['employees', 'pos'], 'مدير أ');
        $this->actingAs($lead)
            ->put(route('admin.employees.update', $lead->id), $this->form($lead, ['permissions' => ['employees', 'pos', 'finance', 'reports']]))
            ->assertForbidden();
        $this->assertSame(['employees', 'pos'], $lead->fresh()->permissions);
    }

    /* ══════════════ ٨ · الباقةُ التي لا تبيع التخصيص ══════════════ */

    public function test_a_plan_without_custom_permissions_keeps_them_closed(): void
    {
        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => []]);
        $this->shopA->update(['plan_id' => $plan->id]);
        $follower = $this->person($this->shopA, 'يتبع وظيفته', 'follower', 'cashier', null, 'كاشير أ');

        $this->actingAs($this->ownerA)->get(route('admin.employees.edit', $follower->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.planFeatures.custom_permissions', false));

        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $follower->id), $this->form($follower, [
                'manual_permissions' => true, 'permissions' => ['pos', 'inventory'],
            ]))->assertSessionHasErrors('permissions');

        $this->assertNull($follower->fresh()->permissions);
    }

    /* ══════════════ ٩ · «تحديد الكل» لا يحمل فعلًا حسّاسًا ══════════════ */

    /**
     * ما يرسله «تحديد الكل» في كلّ بطاقة: أقسامٌ وحدها.
     *
     * والخادمُ يحفظه كما وصل — فلا يملك الموظّفُ به فعلًا حسّاسًا واحدًا، والأفعالُ
     * تبقى بأبوابها (`EverySensitiveDoorAsksForItsOwnPermissionTest`).
     */
    public function test_every_section_at_once_still_opens_no_sensitive_action(): void
    {
        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $this->cashierA->id), $this->form($this->cashierA, ['permissions' => Permissions::SECTIONS]))
            ->assertSessionHasNoErrors();

        $saved = $this->cashierA->fresh();
        $this->assertSame(Permissions::SECTIONS, $saved->permissions);
        foreach (Permissions::actions() as $action) {
            $this->assertFalse($saved->may($action), $action.' — مُنح بلا اسمه');
        }
    }

    /* ══════════════ حفظُ الاسم والهاتف لا يمسّ الصلاحيات ══════════════ */

    public function test_saving_a_name_or_phone_keeps_the_permissions_as_they_were(): void
    {
        $custom = $this->person($this->shopA, 'موظّف مخصّص', 'custom-one', 'cashier', ['pos', 'inventory', Permissions::CUSTOMER_PAYMENT_CREATE], 'كاشير أ');
        $follower = $this->person($this->shopA, 'موظّف يتبع', 'follow-one', 'cashier', null, 'كاشير أ');

        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $custom->id), $this->form($custom, ['name' => 'اسم جديد', 'phone' => '96891234567']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $follower->id), $this->form($follower, ['phone' => '96897654321']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['pos', 'inventory', Permissions::CUSTOMER_PAYMENT_CREATE], $custom->fresh()->permissions);
        $this->assertSame('اسم جديد', $custom->fresh()->name);
        $this->assertNull($follower->fresh()->permissions);
    }

    /* ══════════════ ١٠ · محادثاتُ واتساب ليست في القائمة ══════════════ */

    public function test_the_whatsapp_inbox_grant_stays_out_of_the_permission_list(): void
    {
        $this->cashierA->forceFill(['whatsapp_inbox' => 'reply'])->save();

        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $this->cashierA->id), $this->form($this->cashierA, ['permissions' => ['pos', 'orders']]))
            ->assertSessionHasNoErrors();

        $saved = $this->cashierA->fresh();
        $this->assertSame('reply', $saved->whatsapp_inbox, 'حفظُ الصلاحيات لا يمسّ عمودَ المحادثات');
        $this->assertSame(['pos', 'orders'], $saved->permissions);

        $this->actingAs($this->ownerA)
            ->put(route('admin.employees.update', $this->cashierA->id), $this->form($this->cashierA, ['permissions' => ['pos', Permissions::INBOX_REPLY]]))
            ->assertSessionHasErrors('permissions.1');
        $this->assertSame(['pos', 'orders'], $this->cashierA->fresh()->permissions);
    }
}
