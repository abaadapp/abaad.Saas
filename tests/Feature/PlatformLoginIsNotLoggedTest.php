<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دخول مدير المنصة وخروجه لا يُقيَّدان.
 *
 * السجلّ يُفتح ليُقرأ فيه ما جرى للمتاجر، وكان مدير المنصة يزاحمه بأفعاله هو:
 * اثنان وأربعون سطر دخولٍ وأربعةٌ وعشرون خروجًا يدفعان ما يُراقَب حقًّا خارج
 * الصفحة الأولى — وهو يدخل كلّ يوم مرّاتٍ لأنّ عملَه هنا.
 *
 * والمحذوف دخولٌ وخروج لا غير: ما يمسّ متجرًا يبقى مقيَّدًا باسمه، ومحاولاتُ
 * الدخول الفاشلة تبقى لأنها إشارةُ أمنٍ لا ضجيجَ إدارة.
 */
class PlatformLoginIsNotLoggedTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'platform@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'مالك النشاط', 'email' => 'owner@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function logins(): int
    {
        return ActivityLog::whereIn('action', ['login', 'logout'])->count();
    }

    public function test_the_platform_managers_login_leaves_no_row(): void
    {
        $this->post(route('login.attempt'), ['email' => 'platform@abaad.om', 'password' => 'password']);
        $this->assertAuthenticated();

        $this->assertSame(0, $this->logins(), 'دخول مدير المنصة كُتب في السجلّ');
    }

    public function test_nor_does_his_logout(): void
    {
        $this->actingAs($this->platform)->post(route('logout'));

        $this->assertSame(0, $this->logins(), 'خروج مدير المنصة كُتب في السجلّ');
    }

    /** والتاجر يبقى مقيَّدًا — الإسكات على مدير المنصة وحده */
    public function test_a_merchants_login_is_still_recorded(): void
    {
        $this->post(route('login.attempt'), ['email' => 'owner@abaad.om', 'password' => 'password']);

        $this->assertSame(1, ActivityLog::where('action', 'login')->count(),
            'دخول التاجر لم يعد يُسجَّل');
    }

    /** ومحاولةٌ فاشلة تبقى: إشارةُ أمنٍ لا ضجيجَ إدارة */
    public function test_a_failed_attempt_is_still_recorded(): void
    {
        $this->post(route('login.attempt'), ['email' => 'platform@abaad.om', 'password' => 'خطأ']);

        $this->assertSame(1, ActivityLog::where('action', 'login_failed')->count(),
            'محاولة دخول فاشلة اختفت من السجلّ');
    }

    /**
     * و«دخل كتاجر» يبقى — وهو أهمّ سطرٍ في السجلّ كلّه.
     *
     * يُقيَّد بفعل `login` أيضًا، فإسكاتٌ يقيس اسم الفعل وحده كان يمحوه: به
     * يُعرف أنّ الدعم دخل متجر تاجرٍ ومتى. والشرط `self` هو ما يفصل بابَ
     * صاحبه عن باب غيره.
     */
    public function test_entering_a_merchant_is_still_recorded(): void
    {
        $this->actingAs($this->platform);
        Activity::log('login', 'دخل كتاجر: متجري', ['subject_id' => 1]);

        $this->assertSame(1, ActivityLog::where('action', 'login')->count(),
            'انتحالُ الدعم لمتجرٍ اختفى من السجلّ');
    }

    /** وكلّ فعلٍ آخر لمدير المنصة يبقى مقيَّدًا عليه باسمه */
    public function test_his_other_actions_are_still_recorded(): void
    {
        $this->actingAs($this->platform);
        Activity::log('deleted', 'حذف متجرًا');

        $this->assertSame(1, ActivityLog::where('action', 'deleted')->count(),
            'أفعال مدير المنصة على المتاجر اختفت من السجلّ');
    }
}
