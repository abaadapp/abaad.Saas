<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يُكتب دورٌ بيدٍ لا تُسنده — لا في صفّ موظّف ولا في وظيفةٍ تُسنده غدًا.
 *
 * ═══ ١ · «أصاحبُ النشاطِ هذا؟» — سؤالٌ واحدٌ كان يُسأل بثلاث صيغ ═══
 *
 * `Permissions::beyond` تسأله تامًّا: دورُه `admin` **وصلاحياتُه غير مخصّصة**.
 * وبابا `EmployeeController` كانا يسألان نصفَه: «أدورُه `admin`؟».
 *
 * وبينهما يقع الشريكُ المُضيَّق — صفٌّ دورُه `admin` وقائمتُه مخصّصةٌ بيد
 * المالك. تراه الشاشةُ مُضيَّقًا، ويراه بابا المنح مالكًا. فكان:
 *
 *   ١ — يُنشئ حسابًا بوظيفةٍ دورُها «مدير فرع» — وهي `'*'` — بكلمة مرورٍ
 *       يكتبها بيده، ثمّ يدخل بها فيملك ما نُزع عنه.
 *   ٢ — ويبدّل راتبَ نفسِه ووظيفتَها، ولا يردّه شيء.
 *
 * ═══ ٢ · ودورُ الوظيفة كان يُكتب بلا قياس ═══
 *
 * `JobTitleController` تقبل `role` من الطلب ولا تسأل عنها أحدًا — ولا شاشةَ
 * ترسلها أصلًا. فالمحاسبُ يكتب في وظيفة «كاشير» دورَ «مدير فرع»، ولا يقع
 * شيءٌ في الحال. ثمّ يصحّح صاحبُ المتجر رقمَ هاتف كاشيره بعد شهر —
 * و`EmployeeController::update` تكتب `role = $title->role` في كلّ حفظة —
 * فيصير الكاشيرُ مديرَ فرع. رفعةٌ تقع بيد المالك نفسِه وهو لا يعلم.
 */
class ARoleIsNotWrittenByAHandThatCannotAssignItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /** دورُه `admin` وقائمتُه مخصّصة — شريكٌ ضيّقه المالكُ بيده */
    private User $narrowed;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        foreach ([['صاحب النشاط', 'admin'], ['مدير فرع', 'manager'], ['محاسب', 'accountant'],
            ['بائع', 'sales'], ['كاشير', 'cashier']] as [$name, $role]) {
            JobTitle::create(['business_id' => $this->shop->id, 'name' => $name, 'role' => $role]);
        }

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin',
            'job_title' => 'صاحب النشاط', 'status' => 'نشط',
        ]);

        $this->narrowed = User::create([
            'business_id' => $this->shop->id, 'name' => 'شريك مُضيَّق', 'email' => 'p@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin',
            'job_title' => 'صاحب النشاط', 'status' => 'نشط',
            'permissions' => ['employees', 'orders'],
        ]);

        $this->accountant = User::create([
            'business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'accountant',
            'job_title' => 'محاسب', 'status' => 'نشط',
        ]);
    }

    /* ══════════════ ١ · الشريكُ المُضيَّق ليس صاحبَ النشاط ══════════════ */

    /** والسؤالُ يُجاب في موضعٍ واحد تقرؤه الأبوابُ الثلاثة */
    public function test_the_owner_is_the_role_and_the_absence_of_a_manual_list(): void
    {
        $this->assertTrue(Permissions::isOwner($this->owner));
        $this->assertFalse(Permissions::isOwner($this->narrowed), 'صفٌّ مخصَّصٌ دورُه admin ليس مالكًا');
        $this->assertFalse(Permissions::isOwner($this->accountant));
        $this->assertFalse(Permissions::isOwner(null));
    }

    /** ولا يصنع مديرَ فرعٍ يدخل به — وهي `'*'` كاملة */
    public function test_a_narrowed_admin_does_not_mint_a_manager(): void
    {
        $this->actingAs($this->narrowed)
            ->post(route('admin.employees.store'), [
                'name' => 'حساب جديد', 'login_username' => 'newone', 'password' => 'password12345',
                'job_title' => 'مدير فرع', 'manual_permissions' => true, 'permissions' => ['employees'],
            ])->assertForbidden();

        $this->assertNull(User::where('email', 'newone@abaadapp.om')->first());
    }

    /** ولا يرفع وظيفةَ نفسِه */
    public function test_a_narrowed_admin_does_not_raise_his_own_job_title(): void
    {
        $this->actingAs($this->narrowed)
            ->put(route('admin.employees.update', $this->narrowed->id), [
                'name' => 'شريك مُضيَّق', 'job_title' => 'مدير فرع',
            ]);

        $this->assertSame('admin', $this->narrowed->fresh()->role);
        $this->assertSame('صاحب النشاط', $this->narrowed->fresh()->job_title);
    }

    /**
     * ولا راتبَ نفسِه — والبابُ الثاني يُجرَّب وحدَه.
     *
     * قائمةٌ مخصّصةٌ **كاملة**: فيمرّ حارسُ المنح (لا شيء يُمنح فوق ما يملك)
     * ولا يبقى إلا `refuseRaisingMyself`. ولولا ذلك لَقتل الأوّلُ الطفرةَ عن
     * الثاني، فيُقال إنّه محروسٌ وهو مكشوف.
     */
    public function test_a_narrowed_admin_does_not_raise_his_own_salary(): void
    {
        $full = User::create([
            'business_id' => $this->shop->id, 'name' => 'شريك واسع', 'email' => 'w@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin',
            'job_title' => 'صاحب النشاط', 'status' => 'نشط', 'basic_salary' => 100,
            'permissions' => [...Permissions::sections(), ...Permissions::actions()],
        ]);

        $this->actingAs($full)
            ->put(route('admin.employees.update', $full->id), [
                'name' => 'شريك واسع', 'job_title' => 'صاحب النشاط', 'basic_salary' => 9999,
            ])->assertSessionHasErrors('job_title');

        $this->assertSame(100.0, (float) $full->fresh()->basic_salary);
    }

    /** وصاحبُ النشاط — دورُه `admin` بلا تخصيص — يصنع ما شاء كما كان */
    public function test_the_real_owner_still_mints_a_manager(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), [
                'name' => 'حساب جديد', 'login_username' => 'newone', 'password' => 'password12345',
                'job_title' => 'مدير فرع', 'manual_permissions' => true, 'permissions' => ['employees'],
            ])->assertRedirect();

        $this->assertSame('manager', User::where('email', 'newone@abaadapp.om')->firstOrFail()->role);
    }

    /* ══════════════ ٢ · دورُ الوظيفة يُقاس كما يُقاس دورُ الموظّف ══════════════ */

    /** المحاسبُ لا يصنع وظيفةً دورُها فوقه */
    public function test_an_accountant_does_not_mint_a_job_title_above_him(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.jobTitles.store'), ['name' => 'مشرف عام', 'role' => 'manager'])
            ->assertForbidden();

        $this->assertNull(JobTitle::where('name', 'مشرف عام')->first());
    }

    /** ولا يُبدّل دورَ وظيفةٍ قائمة */
    public function test_an_accountant_does_not_repoint_an_existing_job_title(): void
    {
        $cashier = JobTitle::where('name', 'كاشير')->firstOrFail();

        $this->actingAs($this->accountant)
            ->put(route('admin.jobTitles.update', $cashier->id), ['name' => 'كاشير', 'role' => 'manager'])
            ->assertForbidden();

        $this->assertSame('cashier', $cashier->fresh()->role);
    }

    /**
     * والرفعةُ المؤجَّلة لا تقع — وهي أخطرُ ما في البابين.
     *
     * يكتب المحاسبُ الدورَ في الوظيفة اليوم، ويصحّح المالكُ رقمَ هاتفٍ غدًا،
     * فتقع الرفعةُ بيد المالك وباسمه. فيُقاس الطريقُ كلُّه لا بابُه الأوّل.
     */
    public function test_a_phone_correction_by_the_owner_does_not_promote_a_cashier(): void
    {
        $cashier = JobTitle::where('name', 'كاشير')->firstOrFail();

        $this->actingAs($this->accountant)
            ->put(route('admin.jobTitles.update', $cashier->id), ['name' => 'كاشير', 'role' => 'manager']);

        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاتب', 'email' => 'clerk1@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier',
            'job_title' => 'كاشير', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $clerk->id), [
                'name' => 'كاتب', 'job_title' => 'كاشير', 'phone' => '99887766',
            ])->assertRedirect();

        $this->assertSame('cashier', $clerk->fresh()->role, 'تصحيحُ هاتفٍ رفع كاشيرًا إلى مدير فرع');
    }

    /** والمسكوتُ عنه لا يُقاس: مسمًّى يُضاف بلا دورٍ يأخذ أدناها */
    public function test_a_plain_job_title_is_still_added_by_whoever_owns_the_section(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.jobTitles.store'), ['name' => 'منسّق طلبات'])
            ->assertRedirect();

        $this->assertSame('cashier', JobTitle::where('name', 'منسّق طلبات')->firstOrFail()->role);
    }

    /** واسمُ الوظيفة يُصحَّح بلا أن يُمسّ دورُها */
    public function test_renaming_a_job_title_keeps_its_role(): void
    {
        $sales = JobTitle::where('name', 'بائع')->firstOrFail();

        $this->actingAs($this->accountant)
            ->put(route('admin.jobTitles.update', $sales->id), ['name' => 'بائع أول'])
            ->assertRedirect();

        $this->assertSame('sales', $sales->fresh()->role);
        $this->assertSame('بائع أول', $sales->fresh()->name);
    }

    /** وصاحبُ النشاط يكتب الدورَ صراحةً — الحدُّ يضيّق ولا يُقفل */
    public function test_the_owner_still_writes_a_role_on_a_job_title(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.jobTitles.store'), ['name' => 'مشرف عام', 'role' => 'manager'])
            ->assertRedirect();

        $this->assertSame('manager', JobTitle::where('name', 'مشرف عام')->firstOrFail()->role);
    }

    /* ══════════════ ٣ · ولا يعود السؤالُ يُسأل بصيغتين ══════════════ */

    /**
     * «أدورُه admin؟» لا تُكتب خارج `Permissions::isOwner`.
     *
     * الصيغةُ الناقصة هي العطبُ نفسُه. ومن كتبها غدًا في بابٍ ثالث أعاده —
     * فيسقط هذا الحارس قبل أن يُنشر.
     */
    public function test_no_door_asks_the_half_question_again(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/EmployeeController.php',
            'app/Http/Controllers/Admin/JobTitleController.php',
            'app/Support/Permissions.php',
        ] as $file) {
            /*
             * والشروحُ تُطرح: أكثرُ هذه الملفّات يروي العطبَ بنصّه، وحارسٌ
             * يقتله تعليقٌ حارسٌ لا يُوثق به. فيُقرأ الكودُ وحده.
             */
            $src = collect(token_get_all(file_get_contents(base_path($file))))
                ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($t) => is_array($t) ? $t[1] : $t)
                ->implode('');

            // ما في `isOwner` نفسِها هو الجواب — يُطرح بجسمه
            $src = str_replace(
                "return \$actor !== null && \$actor->role === 'admin' && ! \$actor->hasManualPermissions();",
                '',
                $src,
            );

            $this->assertStringNotContainsString(
                "role === 'admin'",
                $src,
                "{$file}: سؤالُ المالك يُسأل خارج `Permissions::isOwner`",
            );
        }
    }
}
