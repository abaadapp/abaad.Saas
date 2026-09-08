<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لا يُخترع وجهٌ لإنسان.
 *
 * `User::getAvatarAttribute` كانت تردّ رابطًا إلى `picsum.photos` — خدمةِ صورٍ
 * عشوائيّة على الإنترنت — لكلّ من لم يرفع صورته. فيظهر وجهُ إنسانٍ لا يعرفه
 * أحد في الشريط العلويّ فوق اسم صاحب المحلّ، وفي شاشة اختيار الكاشير، وفي
 * ملفّ كلّ موظّف. ولا يفرّق أحدٌ بينه وبين صورةٍ رُفعت.
 *
 * وكان يُبطل كلّ بديلٍ كُتب فوقه: `$u->avatar ?? …` لا تقع، ولا الشرطُ في
 * الشاشات — لأنّ العمود لا يُقرأ فارغًا قطّ.
 *
 * وبابان يكتبانه في القاعدة أيضًا: «إضافة موظّف» و«إضافة مستخدم للمنصّة».
 * والعملاء أسوأ من الجميع: لا عمودَ صورةٍ لهم أصلًا، فالوجهُ كان يُحسب من رقم
 * العميل عند كلّ قراءة.
 *
 * وهو العطبُ نفسه الذي رُفع من المنتجات — انظر `AProductWithoutAPhotoSaysSoTest`.
 */
class NoFaceIsInventedForAPersonTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_09_020000_no_face_is_invented_for_a_person.php';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);

        $this->actingAs($this->owner);
    }

    /* ---------------------------- المصدرُ الأعمق ---------------------------- */

    /**
     * العمودُ الفارغ يُقرأ فارغًا.
     *
     * وهذا هو الموضع الذي كان يُبطل كلّ البدائل المكتوبة فوقه.
     */
    public function test_a_user_without_a_photo_reads_as_none(): void
    {
        $this->assertSame('', $this->owner->avatar);
    }

    public function test_an_uploaded_photo_becomes_a_link(): void
    {
        $this->owner->forceFill(['avatar' => 'avatars/owner.jpg'])->save();

        $this->assertSame('/storage/avatars/owner.jpg', $this->owner->fresh()->avatar);
    }

    public function test_an_external_link_passes_through(): void
    {
        $this->owner->forceFill(['avatar' => 'https://cdn.example.om/o.jpg'])->save();

        $this->assertSame('https://cdn.example.om/o.jpg', $this->owner->fresh()->avatar);
    }

    /** والشريطُ العلويّ في كلّ شاشة يقرأ منه — فلا وجهَ مستعارًا فوق الاسم */
    public function test_the_shared_props_carry_no_invented_face(): void
    {
        $props = $this->get(route('admin.dashboard'))->viewData('page')['props'];

        $this->assertSame('', $props['auth']['user']['avatar']);
    }

    /* ---------------------------- عند الإنشاء ---------------------------- */

    public function test_a_new_employee_is_born_without_a_face(): void
    {
        $this->post(route('admin.employees.store'), [
            'name' => 'سالم', 'login_username' => 'salem', 'job_title' => 'كاشير',
        ])->assertSessionHasNoErrors();

        $id = DB::table('users')->where('name', 'سالم')->value('id');

        $this->assertNotNull($id);
        // القراءة خامٌ لا عبر النموذج: القارئُ نفسه هو ما نفحص
        $this->assertNull(DB::table('users')->where('id', $id)->value('avatar'),
            'الموظّف الجديد وُلد بوجهٍ لم يرفعه أحد');
    }

    public function test_a_new_platform_user_is_born_without_a_face(): void
    {
        $super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($super)->post(route('super-admin.users.store'), [
            'name' => 'مشغّل', 'email' => 'op@abaadapp.om', 'role' => 'admin',
            'business_id' => $this->business->id, 'password' => 'password123',
        ])->assertSessionHasNoErrors();

        $this->assertNull(DB::table('users')->where('email', 'op@abaadapp.om')->value('avatar'),
            'مستخدم المنصّة الجديد وُلد بوجهٍ لم يرفعه أحد');
    }

    /**
     * ولا رابطَ خارجيًّا في أيّ صفٍّ يُكتب.
     *
     * الفحص على العمود لا على البابين: من أعاد سطرًا يخترع وجهًا في بابٍ
     * ثالثٍ لا يمرّ من هناك — ويمرّ من هنا.
     */
    public function test_no_row_written_by_the_doors_carries_a_third_party_face(): void
    {
        $this->post(route('admin.employees.store'), [
            'name' => 'نورة', 'login_username' => 'noura', 'job_title' => 'كاشير',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('users')->where('avatar', 'like', '%picsum%')->count());
    }

    /* ----------------------------- عند القراءة ----------------------------- */

    public function test_the_employees_list_shows_no_invented_face(): void
    {
        User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $row = collect(Demo::employees())->firstWhere('name', 'سالم');

        $this->assertNotNull($row);
        $this->assertSame('', $row['avatar'], 'الموظّف بلا صورةٍ يُقرأ بوجهٍ مخترع');
    }

    public function test_the_employees_list_still_shows_an_uploaded_one(): void
    {
        User::create([
            'business_id' => $this->business->id, 'name' => 'هدى', 'email' => 'huda@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'avatar' => 'avatars/huda.jpg',
        ]);

        $row = collect(Demo::employees())->firstWhere('name', 'هدى');

        $this->assertSame('/storage/avatars/huda.jpg', $row['avatar'], 'الصورة المرفوعة ضاعت مع المخترعة');
    }

    public function test_the_customers_list_carries_no_face_at_all(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567']);

        $props = $this->get(route('admin.customers.index'))->viewData('page')['props'];

        $this->assertNotEmpty($props['customers']);
        $this->assertArrayNotHasKey('avatar', $props['customers'][0],
            'قائمة العملاء ما زالت تحمل حقل صورةٍ لا عمود له');
    }

    public function test_the_customer_profile_carries_no_face_either(): void
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567']);

        $props = $this->get(route('admin.customers.show', $customer->id))->viewData('page')['props'];

        $this->assertArrayNotHasKey('avatar', $props['customer']);
    }

    /** والشاشتان ترسمان الحرفَ الأول — لا صورةً ولا دائرةً صمّاء */
    public function test_both_customer_screens_draw_the_first_letter(): void
    {
        foreach (['Index', 'Show'] as $screen) {
            $source = file_get_contents(base_path("resources/js/Pages/Admin/Customers/{$screen}.tsx"));

            $this->assertStringNotContainsString('customer.avatar', $source);
            $this->assertStringNotContainsString('c.avatar', $source);
            $this->assertStringContainsString('.slice(0, 1)', $source, "شاشة {$screen} بلا بديلٍ مرسوم");
        }
    }

    /* ------------------------------ الهجرة ------------------------------ */

    private function raw(int $id): ?string
    {
        return DB::table('users')->where('id', $id)->value('avatar');
    }

    private function invent(array $over = []): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'مخترع', 'email' => 'i@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'avatar' => 'https://picsum.photos/seed/emp123/400/400',
        ], $over));
    }

    public function test_the_migration_empties_the_invented_faces(): void
    {
        $user = $this->invent();

        (require base_path(self::MIGRATION))->up();

        $this->assertNull($this->raw($user->id));
    }

    public function test_the_migration_does_not_touch_an_uploaded_photo(): void
    {
        $user = $this->invent(['avatar' => 'avatars/real.jpg', 'email' => 'r@abaadapp.om']);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('avatars/real.jpg', $this->raw($user->id));
    }

    /** المتجرُ التجريبيّ يبقى بوجوهه — بيانُ عرضٍ يُبذَر صراحةً في `SeedData` */
    public function test_the_demo_store_keeps_its_faces(): void
    {
        $demo = Business::create(['name' => 'متجر تجريبي', 'type' => 'محل ورود', 'status' => 'نشط', 'is_demo' => true]);
        $shown = $this->invent(['business_id' => $demo->id, 'email' => 'd@abaadapp.om']);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('https://picsum.photos/seed/emp123/400/400', $this->raw($shown->id));
    }

    /**
     * ومن لا نشاط له يُنظَّف معهم.
     *
     * `whereNotIn` وحدها تُسقط `business_id IS NULL` — لأنّ `null not in (…)`
     * مجهولةٌ لا صحيحة — فينجو مديرُ المنصّة بوجهه المخترع، وهو أظهرُ حسابٍ
     * في النظام.
     */
    public function test_a_platform_admin_is_cleaned_too(): void
    {
        Business::create(['name' => 'متجر تجريبي', 'type' => 'محل ورود', 'status' => 'نشط', 'is_demo' => true]);
        $super = $this->invent(['business_id' => null, 'role' => 'super_admin', 'email' => 'sa@abaadapp.om']);

        (require base_path(self::MIGRATION))->up();

        $this->assertNull($this->raw($super->id));
    }
}
