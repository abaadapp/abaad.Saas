<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * حسابٌ واحد وقفلٌ واحد — ولا بابَ إليه أضعفُ من بابه.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * بابُ التسجيل يشترط ثمانيةً فيها حرفٌ ورقم، ويقول شرطَه على الشاشة
 * (`RegisterController`). وبابُ الملفّ الشخصيّ كان يشترط `min:6` وحدها:
 * لا حرفَ ولا رقم.
 *
 * **والحسابُ هو هو.** فصاحبُ النشاط يسجّل بكلمةٍ قويّة كما فُرض عليه، ثمّ
 * يفتح ملفَّه الشخصيّ ويضعها `123456` بضغطتين — فيُلتفّ على الشرط من داخل
 * النظام لا من خارجه. بابٌ يُقفل، وآخرُ إلى الغرفة نفسِها مفتوح.
 *
 * ═══ وما يحرسه أيضًا ═══
 *
 * الشاشةُ كلُّها لم يكن يقيسها اختبارٌ واحد: تغييرُ كلمة المرور — وهو أخطرُ
 * ما في الملفّ الشخصيّ — بلا حارس. فهذا يقفل البابَ ويقيس ما كان يعمل بلا
 * قياس: التعمية، وكلمةُ المرور الحالية، والتأكيد، وحفظُ الاسم وحده.
 */
class OneAccountOneLockTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'OldPass123';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'سعود', 'email' => 'o@abaad.om',
            'password' => bcrypt(self::OLD), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** كما ترسلها الشاشة: النموذجُ كلُّه في كلّ حفظة */
    private function save(array $extra = [])
    {
        return $this->actingAs($this->owner)->put(route('profile.update'), array_merge([
            'name' => 'سعود', 'email' => 'o@abaad.om', 'phone' => '96899110001',
        ], $extra));
    }

    private function unchanged(): bool
    {
        return Hash::check(self::OLD, $this->owner->fresh()->password);
    }

    /* ═════════════ القفلُ واحد ═════════════ */

    /** و«123456» لا تُقبل هنا كما لا تُقبل في التسجيل */
    public function test_a_weak_password_is_refused_here_as_it_is_at_the_door(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => '123456', 'password_confirmation' => '123456'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->unchanged(), 'كلمةُ مرورٍ ضعيفة دخلت من بابٍ جانبيّ');
    }

    /** وثمانيةٌ بلا رقم لا تكفي */
    public function test_letters_alone_are_not_enough(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->unchanged());
    }

    /** وثمانيةُ أرقامٍ بلا حرفٍ كذلك */
    public function test_digits_alone_are_not_enough(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => '12345678', 'password_confirmation' => '12345678'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->unchanged());
    }

    /** وسبعةٌ فيها حرفٌ ورقم لا تكفي — الحدُّ ثمانية */
    public function test_seven_is_still_short(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'abcdef1', 'password_confirmation' => 'abcdef1'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->unchanged());
    }

    /** والشرطُ يُقال لا يُفرض صامتًا: نصُّ الردّ يسمّي ما ينقص */
    public function test_the_refusal_says_what_is_missing(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])
            ->assertSessionHasErrors(['password' => 'كلمة المرور تحتاج حرفًا ورقمًا على الأقل.']);
    }

    /* ═════════════ وما يُقبل يُقبل ═════════════ */

    public function test_a_password_that_meets_the_rule_is_taken(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertSessionHasNoErrors();

        $fresh = $this->owner->fresh();
        $this->assertTrue(Hash::check('NewPass456', $fresh->password), 'الكلمةُ الجديدة لا تفتح الحساب');
        $this->assertFalse(Hash::check(self::OLD, $fresh->password), 'القديمةُ ما زالت تفتحه');
    }

    /** ولا تُحفظ كما كُتبت: النصُّ الصريح لا يُخزَّن */
    public function test_the_password_is_never_stored_as_written(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456']);

        $raw = (string) \DB::table('users')->where('id', $this->owner->id)->value('password');
        $this->assertNotSame('NewPass456', $raw);
        $this->assertStringStartsWith('$2y$', $raw);
    }

    /* ═════════════ ولا تُبدَّل بلا إذن صاحبها ═════════════ */

    public function test_the_current_password_must_be_right(): void
    {
        $this->save(['current_password' => 'ليست هي', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($this->unchanged());
    }

    public function test_the_current_password_is_not_optional(): void
    {
        $this->save(['password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($this->unchanged());
    }

    /** ويُطابَق التأكيد — وإلّا كُتب خطأٌ مطبعيّ قفلًا لا يعرفه صاحبه */
    public function test_a_mistyped_confirmation_refuses(): void
    {
        $this->save(['current_password' => self::OLD, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass457'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->unchanged());
    }

    /* ═════════════ وحفظةٌ لا تطلب التغيير لا تغيّر ═════════════ */

    /**
     * والشاشةُ ترسل النموذجَ كلَّه في كلّ حفظة — وحقولُ كلمة المرور فيه
     * فارغةٌ لا غائبة. فحفظُ اسمٍ أو هاتفٍ لا يمسّ القفل.
     */
    public function test_saving_the_name_leaves_the_password_alone(): void
    {
        $this->save([
            'name' => 'سعود الجديد',
            'current_password' => '', 'password' => '', 'password_confirmation' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('سعود الجديد', $this->owner->fresh()->name);
        $this->assertTrue($this->unchanged(), 'حفظةُ اسمٍ مسّت كلمةَ المرور');
    }

    /** والكاشيرُ لا يبدّلها من هنا أصلًا — بابُه أضيق */
    public function test_a_cashier_cannot_change_it_from_this_screen(): void
    {
        $cashier = User::create([
            'business_id' => $this->owner->business_id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt(self::OLD), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->put(route('profile.update'), [
            'name' => 'كاشير', 'email' => 'c@abaad.om',
            'current_password' => self::OLD, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
        ]);

        $this->assertTrue(Hash::check(self::OLD, $cashier->fresh()->password));
    }
}
