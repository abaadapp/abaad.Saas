<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * «المستخدمون» في لوحة المنصّة — الشاشة التي تُقرأ منها الحسابات ويُوثق بها.
 *
 * ما تحرسه هذه الاختبارات ليس الأزرار بل ما تقوله الشاشة: أن تبويب
 * الصلاحيات يعرض ما يُفرض فعلًا، وأن دورًا مجهولًا لا يفتح شيئًا، وأن
 * الحساب المعطوب لا يُعرض سليمًا، وأن الحذف قابل للتراجع.
 */
class PlatformUsersTest extends TestCase
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

    private function staff(array $attrs = []): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'موظف',
            'email' => 'staff@abaadapp.om', 'password' => bcrypt('secret12345'),
            'role' => 'cashier', 'status' => 'نشط',
        ], $attrs));
    }

    /* ------------------------ الصلاحيات المعروضة ------------------------ */

    public function test_the_permissions_tab_shows_what_is_enforced_not_what_the_role_suggests(): void
    {
        // كاشيرٌ مُنح «المخزون» يدويًّا: خريطةُ دوره تُلغى بالكامل
        $user = $this->staff(['permissions' => ['inventory']]);

        $this->actingAs($this->super)
            ->get(route('super-admin.users.show', $user->id))
            ->assertInertia(function (Assert $page) {
                $granted = collect($page->toArray()['props']['permissions'])
                    ->filter(fn ($p) => $p['granted'])->pluck('label')->all();

                $this->assertSame([__('المخزون')], $granted);
            });
    }

    public function test_a_manual_grant_is_named_so_the_screen_does_not_claim_it_follows_the_role(): void
    {
        $manual = $this->staff(['permissions' => ['inventory']]);
        $plain = $this->staff(['email' => 'plain@abaadapp.om']);

        $this->actingAs($this->super)->get(route('super-admin.users.show', $manual->id))
            ->assertInertia(fn (Assert $p) => $p->where('permissions_manual', true));

        $this->actingAs($this->super)->get(route('super-admin.users.show', $plain->id))
            ->assertInertia(fn (Assert $p) => $p->where('permissions_manual', false));
    }

    /* --------------------------- الدور المجهول --------------------------- */

    public function test_an_unknown_role_opens_nothing_rather_than_everything(): void
    {
        // كان `?? ['*']` — فخطأٌ مطبعيّ يصنع حسابًا مفتوحًا على كل قسم
        $this->assertSame([], Permissions::abilities('ghost'));
        $this->assertFalse(Permissions::allows('ghost', 'orders'));
        $this->assertFalse(Permissions::allows('ghost', 'settings'));
    }

    public function test_the_known_roles_are_untouched_by_that_tightening(): void
    {
        $this->assertTrue(Permissions::allows('admin', 'settings'));
        $this->assertTrue(Permissions::allows('manager', 'finance'));
        $this->assertTrue(Permissions::allows('cashier', 'pos'));
        $this->assertFalse(Permissions::allows('cashier', 'finance'));
    }

    public function test_a_role_outside_the_list_is_refused_at_the_door(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مخترَع', 'email' => 'ghost@abaadapp.om', 'role' => 'owner',
            'business_id' => $this->business->id, 'password' => 'secret12345',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'ghost@abaadapp.om']);
    }

    /* ---------------------------- قائمة الأدوار --------------------------- */

    public function test_every_role_the_system_runs_is_offered_on_the_screen(): void
    {
        // كان `inventory` و`delivery` يعملان ولا يظهران: تعديل هاتفٍ يُنقص صلاحيات
        foreach (array_keys(Permissions::MAP) as $role) {
            $this->assertContains($role, Roles::keys(), "الدور {$role} يعمل ولا يُعرض");
        }

        $this->actingAs($this->super)->get(route('super-admin.users.index'))
            ->assertInertia(fn (Assert $p) => $p->has('roles', count(Roles::keys())));
    }

    public function test_the_owner_and_the_branch_manager_are_told_apart(): void
    {
        // كانت `roleLabel()` تكتب «مدير» لكليهما، والمرشّح فوقهما يكتب غير ذلك
        $this->assertNotSame(Roles::label('admin'), Roles::label('manager'));

        $owner = $this->staff(['email' => 'owner@abaadapp.om', 'role' => 'admin']);
        $this->assertSame(Roles::label('admin'), $owner->roleLabel());
    }

    /* --------------------------- كلمة المرور --------------------------- */

    public function test_a_new_account_cannot_be_born_with_the_word_password(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'بلا كلمة', 'email' => 'blank@abaadapp.om', 'role' => 'cashier',
            'business_id' => $this->business->id,
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'blank@abaadapp.om']);
    }

    public function test_creating_demands_the_same_eight_characters_editing_does(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'قصيرة', 'email' => 'short@abaadapp.om', 'role' => 'cashier',
            'business_id' => $this->business->id, 'password' => 'abc1',
        ])->assertSessionHasErrors('password');
    }

    /* ------------------------- الربط بالنشاط ------------------------- */

    public function test_a_staff_account_must_be_tied_to_a_business(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'يتيم', 'email' => 'orphan@abaadapp.om', 'role' => 'admin',
            'password' => 'secret12345',
        ])->assertSessionHasErrors('business_id');
    }

    public function test_a_platform_admin_is_stored_with_no_business_even_if_one_is_sent(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'مدير ثانٍ', 'email' => 'second@abaadapp.om', 'role' => 'super_admin',
            'business_id' => $this->business->id, 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'second@abaadapp.om')->value('business_id'));
    }

    public function test_a_misfiled_account_can_be_moved_to_its_real_business(): void
    {
        // لم يكن في نافذة التعديل حقلٌ للنشاط أصلًا: يُثبَّت عند الإنشاء ولا يُصلح
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $user = $this->staff();

        $this->actingAs($this->super)->put(route('super-admin.users.update', $user->id), [
            'name' => 'موظف', 'email' => 'staff@abaadapp.om', 'role' => 'cashier',
            'business_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($other->id, $user->fresh()->business_id);
    }

    /* ------------------------- الحساب المعطوب ------------------------- */

    public function test_an_account_that_cannot_log_in_is_not_shown_as_healthy(): void
    {
        $broken = $this->staff(['email' => null]);

        $this->actingAs($this->super)->get(route('super-admin.users.index'))
            ->assertInertia(function (Assert $page) use ($broken) {
                $row = collect($page->toArray()['props']['users'])->firstWhere('id', $broken->id);

                $this->assertNotNull($row['blocked'], 'حسابٌ بلا بريد يُعرض سليمًا');
            });
    }

    public function test_a_healthy_account_carries_no_warning(): void
    {
        $user = $this->staff();

        $this->actingAs($this->super)->get(route('super-admin.users.index'))
            ->assertInertia(function (Assert $page) use ($user) {
                $row = collect($page->toArray()['props']['users'])->firstWhere('id', $user->id);

                $this->assertNull($row['blocked']);
            });
    }

    /* ----------------------------- الحذف ----------------------------- */

    public function test_a_user_can_be_deleted_and_restored(): void
    {
        $user = $this->staff();

        $this->actingAs($this->super)
            ->delete(route('super-admin.users.destroy', $user->id))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNull(User::find($user->id));

        $this->actingAs($this->super)->post(route('super-admin.users.restore', $user->id));
        $this->assertNotNull(User::find($user->id));
    }

    public function test_the_deleted_are_out_of_the_list_and_behind_one_filter(): void
    {
        $user = $this->staff();
        $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $user->id));

        $this->actingAs($this->super)->get(route('super-admin.users.index'))
            ->assertInertia(fn (Assert $p) => $this->assertNull(
                collect($p->toArray()['props']['users'])->firstWhere('id', $user->id)
            ));

        $this->actingAs($this->super)->get(route('super-admin.users.index', ['status' => 'محذوف']))
            ->assertInertia(fn (Assert $p) => $this->assertNotNull(
                collect($p->toArray()['props']['users'])->firstWhere('id', $user->id)
            ));
    }

    public function test_a_deleted_user_cannot_log_in(): void
    {
        $user = $this->staff();
        $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $user->id));

        $this->assertFalse(auth()->attempt(['email' => 'staff@abaadapp.om', 'password' => 'secret12345']));
    }

    public function test_no_one_deletes_their_own_account(): void
    {
        $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $this->super->id));

        $this->assertNotNull(User::find($this->super->id));
    }

    public function test_the_last_platform_admin_is_not_deletable(): void
    {
        // حذفُه يُغلق اللوحة على الجميع ولا يبقى حسابٌ يستعيده
        $other = User::create([
            'business_id' => null, 'name' => 'مدير آخر', 'email' => 'second@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($other)->delete(route('super-admin.users.destroy', $this->super->id));
        $this->assertNull(User::find($this->super->id));

        $this->actingAs($other)->delete(route('super-admin.users.destroy', $other->id));
        $this->assertNotNull(User::find($other->id));
    }

    public function test_an_email_held_by_a_deleted_account_says_so(): void
    {
        $user = $this->staff();
        $this->actingAs($this->super)->delete(route('super-admin.users.destroy', $user->id));

        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'جديد', 'email' => 'staff@abaadapp.om', 'role' => 'cashier',
            'business_id' => $this->business->id, 'password' => 'secret12345',
        ])->assertSessionHasErrors(['email' => __('هذا البريد يخصّ مستخدمًا محذوفًا — استعِده من مرشّح «المحذوفون» أو اختر بريدًا غيره.')]);
    }

    /* ------------------------- الملف الشخصي ------------------------- */

    public function test_one_profile_is_read_without_loading_every_user(): void
    {
        // كان يُحمَّل جدول المستخدمين كلُّه بشركاتهم ليُلتقط منه صفٌّ واحد
        foreach (range(1, 12) as $i) {
            $this->staff(['email' => "s{$i}@abaadapp.om"]);
        }
        $target = User::where('email', 's7@abaadapp.om')->first();

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($this->super)->get(route('super-admin.users.show', $target->id))->assertOk();
        $reads = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "users"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $reads, 'فتحُ ملفٍّ واحد يقرأ جدول المستخدمين مرارًا');
    }

    public function test_the_profile_carries_the_business_link_so_it_can_be_fixed(): void
    {
        $user = $this->staff();

        $this->actingAs($this->super)->get(route('super-admin.users.show', $user->id))
            ->assertInertia(fn (Assert $p) => $p->where('user.business_id', $this->business->id)->has('businesses'));
    }

    public function test_hashing_still_holds_on_create(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.users.store'), [
            'name' => 'جديد', 'email' => 'fresh@abaadapp.om', 'role' => 'cashier',
            'business_id' => $this->business->id, 'password' => 'secret12345',
        ]);

        $stored = User::where('email', 'fresh@abaadapp.om')->value('password');
        $this->assertNotSame('secret12345', $stored);
        $this->assertTrue(Hash::check('secret12345', $stored));
    }
}
