<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * كلمةُ مرور الموظّف ليست رقمًا سرّيًّا من أربعة.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * `EmployeeController` كان يقبل `min:4` بلا أيّ شرطٍ آخر — في الإنشاء
 * والتعديل معًا. وخلف ذلك الحساب: نقطةُ البيع، والبيعُ باسم المتجر، وأسماءُ
 * الزبائن وأرقامُهم، وتصحيحُ فاتورةٍ صدرت إن مُنح `order.edit`.
 *
 * وأربعةٌ بلا شرطٍ تعني ما يكتبه الناس فعلًا: `1234`، `0000`، سنةَ ميلاد.
 *
 * ═══ ولمَ لا يُحتجّ بسرعة الكاشير ═══
 *
 * بابُ الدخول واحدٌ للجميع على الإنترنت العامّ: الموظّفُ يدخل من صفحة
 * الدخول نفسِها التي يدخل منها صاحبُ المتجر (`login_username` + النطاق)،
 * لا من لوحة أرقامٍ على الجهاز. وكلمتُه تُكتب **مرّةً في الوردية** لا مرّةً
 * في كلّ بيعة — ومبدّلُ الكاشير داخل نقطة البيع لا يسألها أصلًا
 * (`PosCashier`: لافتةٌ لا بوّابة).
 *
 * فالأربعةُ لا تشتري راحةً تُذكر، وتبيع أمانًا حقيقيًّا.
 *
 * ═══ ولمن لا يريد أن يخترعها ═══
 *
 * يتركُ الحقلَ فارغًا فتُولَّد عشرةَ أحرفٍ وتُعرض مرّةً — وهي أقوى ممّا
 * يكتبه أحدٌ بيده. والبابُ الأسهل صار الأقوى، وهذا هو المقصود.
 */
class AStaffPasswordIsNotAFourDigitPinTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('OwnerSecret1'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function hire(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.employees.store'), $overrides + [
            'name' => 'سالم', 'login_username' => 'salim', 'job_title' => 'كاشير',
            'manual_permissions' => false,
        ]);
    }

    private function hired(): ?User
    {
        return User::where('email', 'salim@abaadapp.om')->first();
    }

    /* ═════════════ لا يُكتب رقمٌ سرّيّ ═════════════ */

    public function test_a_four_digit_pin_is_refused(): void
    {
        $this->hire(['password' => '1234'])->assertSessionHasErrors('password');

        $this->assertNull($this->hired(), 'وُظِّف بكلمةِ مرورٍ من أربعة أرقام');
    }

    public function test_six_digits_are_still_refused(): void
    {
        $this->hire(['password' => '123456'])->assertSessionHasErrors('password');
    }

    /** وثمانيةُ أرقامٍ بلا حرف لا تكفي */
    public function test_digits_alone_are_not_enough(): void
    {
        $this->hire(['password' => '12345678'])->assertSessionHasErrors('password');
    }

    /** وثمانيةُ حروفٍ بلا رقمٍ كذلك */
    public function test_letters_alone_are_not_enough(): void
    {
        $this->hire(['password' => 'abcdefgh'])->assertSessionHasErrors('password');
    }

    /** والردُّ يسمّي ما ينقص — لا «غير صالح» */
    public function test_the_refusal_says_what_is_missing(): void
    {
        $this->hire(['password' => 'abcdefgh'])
            ->assertInvalid(['password' => 'كلمة المرور تحتاج حرفًا ورقمًا على الأقل.']);
    }

    /* ═════════════ وما يستوفي الشرط يُقبل ═════════════ */

    public function test_a_password_that_meets_the_rule_is_taken(): void
    {
        $this->hire(['password' => 'Salim2027'])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Salim2027', (string) $this->hired()?->password));
    }

    /** والبابُ الأسهل يبقى الأقوى: فارغًا تُولَّد عشرةَ أحرف */
    public function test_an_empty_field_still_generates_a_strong_one(): void
    {
        $this->hire()->assertSessionHasNoErrors();

        $issued = (string) session('issued_password');
        $this->assertSame(10, strlen($issued), 'المولَّدةُ ليست عشرةَ أحرف');
        $this->assertTrue(Hash::check($issued, (string) $this->hired()?->password));
    }

    /* ═════════════ وبابُ التعديل كبابِ الإنشاء ═════════════ */

    /** ولا يُلتفّ على الشرط بتعديلِ موظّفٍ بعد إنشائه */
    public function test_the_edit_door_holds_the_same_rule(): void
    {
        $this->hire(['password' => 'Salim2027']);
        $staff = $this->hired();
        $this->assertNotNull($staff);

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $staff->id), [
                'name' => 'سالم', 'login_username' => 'salim', 'job_title' => 'كاشير',
                'manual_permissions' => false, 'password' => '1234',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Salim2027', (string) $staff->fresh()->password),
            'كلمةُ المرور بُدّلت إلى أربعةٍ من باب التعديل');
    }

    /** وحفظُ الموظّف بلا كلمةِ مرورٍ لا يمسّ كلمتَه */
    public function test_saving_without_a_password_leaves_it_alone(): void
    {
        $this->hire(['password' => 'Salim2027']);
        $staff = $this->hired();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $staff->id), [
                'name' => 'سالم المهري', 'login_username' => 'salim', 'job_title' => 'كاشير',
                'manual_permissions' => false, 'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('سالم المهري', $staff->fresh()->name);
        $this->assertTrue(Hash::check('Salim2027', (string) $staff->fresh()->password));
    }
}
