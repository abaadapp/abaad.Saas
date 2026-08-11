<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغة واجهة مدير المنصة.
 *
 * كان المبدّل صفًّا في القائمة التي تُفتح بمرور الماوس تحت الصورة، فحُذف من
 * هناك — تبديل اللغة يقلب اتجاه المستند ويعيد تحميل الصفحة، وهذا ليس ما
 * يُتوقَّع من صفٍّ يمرّ عليه المؤشّر. وموضعه الآن زرٌّ ظاهر في الشريط العلوي،
 * وتبويبٌ في الإعدادات معه: الإعدادات موضعُ ما يُضبط مرّة، واللغة يبدّلها
 * الموظّف كل يوم — ومن لا يقرأ العربية لا يقرأ كلمة «الإعدادات» ليصل إليها.
 *
 * وأهمّ ما يُختبر أنها **تفضيل شخصي**: تُكتب في حساب من بدّلها وحده.
 */
class PlatformLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'platform@abaad.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);
    }

    public function test_the_platform_admin_can_switch_their_own_language(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.language.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $this->platform->fresh()->locale);
    }

    /**
     * ولكل لوحةٍ مسارُها.
     *
     * المسار واحدُ الاسم في ثلاث مجموعات — لوحة المنصة ولوحة التاجر ونقطة
     * البيع — ولكلٍّ حارسه. وزرُّ الشريط يختار بحسب من يقف عليه، فلو أرسل
     * إلى مسارٍ واحد لردَّ ٤٠٣ لأحدهما. وهذا يثبت أن البابين مفتوحان.
     */
    public function test_a_merchant_switches_through_their_own_route(): void
    {
        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('admin.language.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $owner->fresh()->locale);
    }

    public function test_it_refuses_a_language_the_system_does_not_have(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.language.update'), ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        $this->assertNotSame('fr', $this->platform->fresh()->locale);
    }

    public function test_it_changes_no_one_elses_language(): void
    {
        /*
         * كانت اللغة تُكتب في إعدادات النشاط المشتركة، فمن يبدّلها يسلب
         * غيرَه لغته. والمنصة أخطر: مديرها يبدّل فيطال التبديلُ تجّارًا لا
         * علاقة لهم به.
         */
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'merchant@abaad.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
            'locale' => 'ar',
        ]);

        $this->actingAs($this->platform)
            ->post(route('super-admin.language.update'), ['locale' => 'en']);

        $this->assertSame('ar', $merchant->fresh()->locale);
    }

    public function test_a_merchant_cannot_reach_the_platform_language_route(): void
    {
        $business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $merchant = User::create([
            'business_id' => $business->id,
            'name' => 'تاجر',
            'email' => 'merchant2@abaad.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);

        $this->actingAs($merchant)
            ->post(route('super-admin.language.update'), ['locale' => 'en'])
            ->assertForbidden();
    }
}
