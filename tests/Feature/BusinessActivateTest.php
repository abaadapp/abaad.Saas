<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التعطيل بابٌ يُفتح كما يُغلق.
 *
 * كان في لوحة المنصة مسارٌ واحد يكتب «معطل» ولا مسار يردّها. فمن عطّل شركةً
 * بالخطأ — أو عطّلها لتأخّر دفعةٍ ثم وصلت — سبيله الوحيد نموذجُ التعديل
 * الكامل: يعيد كتابة الاسم والنوع والباقة ليغيّر كلمة، وأيّ حقلٍ يسقط من
 * الطلب يمحو ما في القاعدة.
 */
class BusinessActivateTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function business(string $status = 'معطل', ?string $endsAt = null): Business
    {
        $b = Business::create([
            'name' => 'متجر الورود', 'type' => 'محل ورود', 'status' => $status,
            'ends_at' => $endsAt,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
    }

    public function test_it_brings_a_disabled_store_back(): void
    {
        $b = $this->business();

        $this->actingAs($this->platform)
            ->post(route('super-admin.businesses.activate', $b->id))
            ->assertRedirect();

        $this->assertSame('نشط', $b->fresh()->status);
    }

    public function test_the_owner_can_log_in_again_afterwards(): void
    {
        /*
         * أهمّ ما في الملفّ: لا يكفي أن تُكتب كلمةٌ في عمود، يجب أن يمرّ
         * صاحبها من الحارس. فالحالة التي تُرضي الشاشة ولا تُرضي `Tenancy`
         * تترك التاجر خارج بابه والمشغّل يظنّ أنه فتحه.
         */
        $b = $this->business();
        $owner = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->assertSame(Tenancy::BUSINESS_DISABLED, Tenancy::blockReason($owner));

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));

        $this->assertNull(Tenancy::blockReason($owner->fresh()));
    }

    public function test_an_expired_store_comes_back_as_expired_not_active(): void
    {
        /*
         * الحارس يقرأ تاريخ الانتهاء لا الكلمة المكتوبة. فلو أُعيدت «نشط»
         * لشركةٍ انقضى اشتراكها لقالت الشاشة إنها تعمل ولم يستطع التاجر
         * الدخول — ثم يقلبها المجدول ليلًا فيظنّ المشغّل أن أحدًا عطّلها.
         */
        $b = $this->business('معطل', now()->subMonth()->toDateString());

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));

        $this->assertSame('منتهي', $b->fresh()->status);
    }

    public function test_the_night_scheduler_does_not_undo_the_decision(): void
    {
        // اشتراكٌ سارٍ: تبقى «نشط» بعد مرور المجدول، فلا يبدو الأمر تراجعًا
        $b = $this->business('معطل', now()->addMonth()->toDateString());

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));
        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame('نشط', $b->fresh()->status);
    }

    public function test_a_store_that_is_not_disabled_is_left_alone(): void
    {
        // «منتهي» ليست تعطيلًا: إعادةُ تشغيلها تجديدٌ مزيَّف يفتح بابًا لم يُدفع له
        $b = $this->business('منتهي', now()->subWeek()->toDateString());

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));

        $this->assertSame('منتهي', $b->fresh()->status);
    }

    public function test_the_spelling_with_shadda_is_recognised_too(): void
    {
        // الكلمة تُكتب بالشدّة وبدونها في القاعدة — ونظيرُها Tenancy::BLOCKED_BUSINESS
        $b = $this->business('معطّل');

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));

        $this->assertSame('نشط', $b->fresh()->status);
    }

    public function test_a_merchant_cannot_reopen_their_own_store(): void
    {
        $b = $this->business();
        $owner = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'o2@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('super-admin.businesses.activate', $b->id))
            ->assertForbidden();

        $this->assertSame('معطل', $b->fresh()->status);
    }

    public function test_it_leaves_a_trace_of_who_reopened_it(): void
    {
        $b = $this->business();

        $this->actingAs($this->platform)->post(route('super-admin.businesses.activate', $b->id));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'status',
            'subject_id' => $b->id,
            'user_name' => 'مدير المنصة',
        ]);
    }
}
