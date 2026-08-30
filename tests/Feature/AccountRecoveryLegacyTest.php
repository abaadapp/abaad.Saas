<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * المتاجر القديمة — ومنعُ الاستيلاء عليها.
 *
 * هذا الملفّ يحرس القرار المركزيّ كلّه: أنّ من يعرف اسم دخول محلٍّ **لا**
 * يستطيع أن يربط بريده هو ثمّ يأخذ الحساب. ولو مرّ فحصٌ واحد هنا وهو معطّل
 * لَكان كلُّ متجرٍ في المنصّة مفتوحًا لمن يقرأ فاتورةً.
 */
class AccountRecoveryLegacyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();

        $this->business = Business::create(['name' => 'محل قديم', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        // متجرٌ قديم: لا بريد استعادة إطلاقًا
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'qadeem@abaadapp.om',
            'password' => bcrypt('old-password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function codeFromMail(): string
    {
        $code = null;
        Mail::assertSent(RecoveryOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    /* ==================== لا استيلاء على متجرٍ قديم ==================== */

    /**
     * من عرف اسم الدخول لا يأخذ الحساب.
     *
     * هذا هو الفحص الذي تقوم عليه المهمّة: لا شيء في هذا الطلب — ولا في أيّ
     * تركيبةٍ منه — يُرسل رمزًا إلى بريدٍ يملكه الطالب.
     */
    public function test_knowing_the_login_email_does_not_let_anyone_attach_their_own_inbox(): void
    {
        foreach ([
            ['email' => 'qadeem@abaadapp.om', 'recovery_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@abaadapp.om', 'target_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@abaadapp.om', 'new_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@abaadapp.om'],
        ] as $payload) {
            $this->post(route('recovery.start'), $payload);
        }

        Mail::assertNothingSent();
        $this->assertNull($this->owner->fresh()->recovery_email);
        $this->assertSame(0, PasswordRecoveryChallenge::count());
        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    /** والجواب عامّ لا يقول إنّ الحساب موجودٌ بلا وسيلة استعادة */
    public function test_the_answer_stays_generic_for_a_legacy_account(): void
    {
        $known = $this->post(route('recovery.start'), ['email' => 'qadeem@abaadapp.om']);
        $unknown = $this->post(route('recovery.start'), ['email' => 'nobody@abaadapp.om']);

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'جوابان مختلفان يجعلان الشاشة أداة جردٍ للحسابات',
        );
    }

    /* ==================== طريق مدير المنصّة ==================== */

    /** التاجر لا يبلغ باب المنصّة */
    public function test_a_merchant_cannot_reach_the_platform_recovery_routes(): void
    {
        $this->actingAs($this->owner)
            ->post(route('super-admin.businesses.recovery.set', $this->business->id), [
                'recovery_email' => 'attacker@evil.com',
            ])->assertForbidden();

        $this->assertNull($this->owner->fresh()->recovery_email);
    }

    /**
     * مدير المنصّة يكتب العنوان — **ولا يختمه**.
     *
     * ولو مَلَك ختمَه بيده لَصار كلُّ حسابٍ مفتوحًا لمن يجلس على تلك الشاشة:
     * يكتب بريده، يختمه، يطلب استعادة، يدخل.
     */
    public function test_the_platform_admin_sets_the_email_but_cannot_stamp_it_verified(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.recovery.set', $this->business->id), [
                'recovery_email' => 'Owner@Gmail.com',
                // محاولةُ ختمٍ مباشر مع الطلب — لا قاعدة تقبلها
                'recovery_email_verified_at' => now()->toDateTimeString(),
                'verified' => true,
            ])->assertRedirect();

        $fresh = $this->owner->fresh();
        $this->assertSame('owner@gmail.com', $fresh->recovery_email, 'يُسوّى إلى أحرفٍ صغيرة');
        $this->assertNull($fresh->recovery_email_verified_at, 'لا ختمَ إلا برمزٍ عاد من الصندوق');

        Mail::assertSent(RecoveryOtpMail::class, fn ($m) => $m->hasTo('owner@gmail.com'));
    }

    /** والعنوان الداخليّ يُرفض: لا صندوق خلفه */
    public function test_an_internal_pseudo_email_is_refused(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.recovery.set', $this->business->id), [
                'recovery_email' => 'someshop@abaadapp.om',
            ])->assertSessionHasErrors('recovery_email');

        $this->assertNull($this->owner->fresh()->recovery_email);
    }

    /**
     * ثمّ يُكمل صاحبُ المتجر بالرمز — فيصير الحساب ذاتيَّ الاستعادة.
     *
     * وهذه هي المرّة الواحدة التي يمرّ فيها المتجر القديم بإنسان: بعدها
     * يستعيد نفسه إلى الأبد.
     */
    public function test_the_owner_completes_the_otp_and_the_account_becomes_self_service(): void
    {
        $this->actingAs($this->super)->post(
            route('super-admin.businesses.recovery.set', $this->business->id),
            ['recovery_email' => 'owner@gmail.com'],
        );

        // يبدأ من شاشة الاستعادة وهو خارج حسابه — نسي كلمته أصلًا
        Mail::fake();
        $this->post(route('recovery.start'), ['email' => 'qadeem@abaadapp.om'])->assertRedirect();

        // والرمز ذهب إلى العنوان الذي كتبه المدير، لا إلى ما يكتبه الطالب
        Mail::assertSent(RecoveryOtpMail::class, fn ($m) => $m->hasTo('owner@gmail.com'));

        $challenge = PasswordRecoveryChallenge::where('user_id', $this->owner->id)->latest('id')->firstOrFail();

        $this->post(route('recovery.check'), [
            'challenge' => $challenge->token,
            'code' => $this->codeFromMail(),
        ])->assertRedirect();

        // خُتم العنوان
        $this->assertNotNull($this->owner->fresh()->recovery_email_verified_at);

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token,
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-9', $this->owner->fresh()->password));
    }

    /** وأفعال مدير المنصّة تُقيَّد كلّها */
    public function test_every_platform_recovery_action_is_logged(): void
    {
        $this->actingAs($this->super)->post(
            route('super-admin.businesses.recovery.set', $this->business->id),
            ['recovery_email' => 'owner@gmail.com'],
        );
        $this->actingAs($this->super)->delete(
            route('super-admin.businesses.recovery.clear', $this->business->id),
        );

        $logs = ActivityLog::pluck('description')->implode(' | ');
        $this->assertStringContainsString('ضبط بريد استعادة', $logs);
        $this->assertStringContainsString('أزال بريد الاستعادة', $logs);
        $this->assertStringContainsString('غير موثّق', $logs, 'السجلّ يقول صراحةً إنّه لم يُختم');
    }

    /** ومحو البريد ينزع الختم معه */
    public function test_clearing_removes_the_stamp_too(): void
    {
        $this->owner->forceFill([
            'recovery_email' => 'owner@gmail.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $this->actingAs($this->super)->delete(
            route('super-admin.businesses.recovery.clear', $this->business->id),
        )->assertRedirect();

        $fresh = $this->owner->fresh();
        $this->assertNull($fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);
    }

    /**
     * وتغييرُ عنوانٍ مختوم من شاشة المنصّة ينزع ختمه.
     *
     * ولو ورث العنوانُ الجديد ثقةَ الذي قبله لَكان تغييره من تلك الشاشة
     * استيلاءً كاملًا على الحساب في نقرتين.
     */
    public function test_replacing_a_verified_email_drops_the_stamp(): void
    {
        $this->owner->forceFill([
            'recovery_email' => 'owner@gmail.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $this->actingAs($this->super)->post(
            route('super-admin.businesses.recovery.set', $this->business->id),
            ['recovery_email' => 'someone-else@gmail.com'],
        )->assertRedirect();

        $fresh = $this->owner->fresh();
        $this->assertSame('someone-else@gmail.com', $fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);

        // ومن يطلب الاستعادة الآن يتلقّى رمز توثيقٍ لا رمز تعيين كلمة مرور
        Mail::fake();
        $this->post(route('recovery.start'), ['email' => 'qadeem@abaadapp.om']);
        $challenge = PasswordRecoveryChallenge::where('user_id', $this->owner->id)->latest('id')->firstOrFail();

        $this->assertSame(
            \App\Models\PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION,
            \App\Models\PasswordRecoveryOtp::where('challenge_id', $challenge->id)->latest('id')->value('purpose'),
        );
    }
}
