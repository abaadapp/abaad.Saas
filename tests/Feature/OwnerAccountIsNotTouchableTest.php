<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * حساب صاحب النشاط لا يُمَسّ إلا بيده.
 *
 * قسم «الموظفون» يُمنح للمدير وللمحاسب — وهو في الأصل باب رواتبَ وأدوارٍ
 * وأرقام هواتف. لكنّ أبوابه الأربعة كانت تفتح على صفّ صاحب النشاط نفسه:
 * كلمة مروره تُغيَّر من شاشة التعديل، وتُعاد بزرّ «إعادة تعيين» الذي يعرض
 * الكلمة الجديدة على الشاشة، وحسابه يُوقَف من الشاشة أو من المفتاح.
 *
 * والنتيجة استيلاءٌ كامل: يُوقف المحاسبُ صاحبَه فتُنهى جلستُه ويُردّ إلى
 * شاشة الدخول، أو يأخذ كلمة مروره فيدخل باسمه. ولا يستعيدها صاحب المحل إلا
 * بالبريد — وهو ما يُعطَّل عند أوّل مزوّدٍ لا يُضبط.
 *
 * والقراءة تبقى مفتوحة: الصفّ يظهر في القائمة كما كان، ولا يُكتب فيه.
 */
class OwnerAccountIsNotTouchableTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير عام', 'role' => 'manager']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'owner@abaad.om',
            'password' => bcrypt('ownerpass'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);
        $this->accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);
    }

    private function ownerPasswordIntact(): bool
    {
        return Hash::check('ownerpass', $this->owner->fresh()->password);
    }

    /* ----------------------------- الأبواب الأربعة ----------------------------- */

    public function test_a_manager_cannot_change_the_owners_password(): void
    {
        $this->actingAs($this->manager)->put(route('admin.employees.update', $this->owner->id), [
            'name' => 'صاحب النشاط', 'email' => 'owner@abaad.om', 'job_title' => 'مدير عام',
            'status' => 1, 'password' => 'hijacked123',
        ])->assertForbidden();

        $this->assertTrue($this->ownerPasswordIntact(), 'مديرٌ استولى على كلمة مرور صاحب النشاط');
    }

    public function test_a_manager_cannot_suspend_the_owner_from_the_form(): void
    {
        $this->actingAs($this->manager)->put(route('admin.employees.update', $this->owner->id), [
            'name' => 'صاحب النشاط', 'email' => 'owner@abaad.om', 'job_title' => 'مدير عام', 'status' => 0,
        ])->assertForbidden();

        $this->assertSame('نشط', $this->owner->fresh()->status, 'مديرٌ أوقف صاحب النشاط');
    }

    public function test_a_manager_cannot_toggle_the_owner_off(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.toggle', $this->owner->id))->assertForbidden();

        $this->assertSame('نشط', $this->owner->fresh()->status, 'مديرٌ عطّل صاحب النشاط بالمفتاح');
    }

    public function test_a_manager_cannot_reset_the_owners_password(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.resetPassword', $this->owner->id))->assertForbidden();

        $this->assertTrue($this->ownerPasswordIntact());
    }

    /** والمحاسب أدنى من المدير، وقسم «الموظفون» مفتوحٌ له كذلك */
    public function test_an_accountant_cannot_reset_the_owners_password(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.resetPassword', $this->owner->id))->assertForbidden();

        $this->assertTrue($this->ownerPasswordIntact());
    }

    public function test_an_accountant_cannot_toggle_the_owner_off(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('admin.employees.toggle', $this->owner->id))->assertForbidden();

        $this->assertSame('نشط', $this->owner->fresh()->status);
    }

    /* ------------------------- وما لم يُقفَل يبقى مفتوحًا ------------------------- */

    /** القراءة لم تُمنع: الصفّ يُرى في القائمة كما كان */
    public function test_a_manager_still_sees_the_owner_in_the_list(): void
    {
        $this->actingAs($this->manager)->get(route('admin.employees.index'))->assertSuccessful();
    }

    /** وصاحب النشاط يفعل بحسابه ما شاء — الإغلاق على غيره لا عليه */
    public function test_the_owner_still_edits_his_own_account(): void
    {
        $this->actingAs($this->owner)->put(route('admin.employees.update', $this->owner->id), [
            'name' => 'صاحب النشاط', 'email' => 'owner@abaad.om', 'job_title' => 'مدير عام',
            'status' => 1, 'password' => 'newpass123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('newpass123', $this->owner->fresh()->password),
            'صاحب النشاط لم يعد يغيّر كلمة مروره بنفسه');
    }

    /** والمدير يعمل على الموظفين العاديّين كما كان */
    public function test_a_manager_still_manages_ordinary_staff(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.resetPassword', $this->accountant->id))->assertRedirect();

        $this->assertFalse(Hash::check('password', $this->accountant->fresh()->password),
            'المدير لم يعد يدير موظفيه العاديّين');
    }

    public function test_a_manager_still_toggles_ordinary_staff(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.employees.toggle', $this->accountant->id))->assertRedirect();

        $this->assertNotSame('نشط', $this->accountant->fresh()->status);
    }
}
