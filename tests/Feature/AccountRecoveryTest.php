<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PasswordRecoveryChallenge;
use App\Models\PasswordRecoveryOtp;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\RecoveryEmail;
use App\Support\RecoveryOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * استعادة الحساب — الباب الذي يجب ألّا يمرّ منه غير صاحبه.
 *
 * وأخطر ما يُحرَس هنا ليس الرمز ولا مهلته، بل **إلى أين يذهب**: نظامٌ يقبل
 * أن يكتب الطالب عنوان الإرسال يُثبت أنّ الطالب يملك صندوقًا أنشأه قبل
 * ثانية — لا أنّه يملك المتجر. فمن عرف اسم دخول محلٍّ أخذه.
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // البريد مضبوطٌ في الاختبار: بلا ذلك تُردّ المسارات بـ404 (انظر Mailer)
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();
        RateLimiter::clear('');

        [$this->business, $this->owner] = $this->shop('محل ورد', 'ward@abaadapp.om', 'owner@gmail.com');
    }

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $login, ?string $recovery, bool $verified = true): array
    {
        $business = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $user = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $login,
            'password' => bcrypt('old-password'), 'role' => 'admin', 'status' => 'نشط',
            'recovery_email' => $recovery,
            'recovery_email_verified_at' => $recovery && $verified ? now() : null,
        ]);

        return [$business, $user];
    }

    /** الرمز الخام — يُقرأ من الرسالة كما يقرؤه صاحبه، لا من القاعدة */
    private function codeFromMail(): string
    {
        $code = null;

        Mail::assertSent(RecoveryOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function startFor(User $user): PasswordRecoveryChallenge
    {
        $this->post(route('recovery.start'), ['email' => $user->email]);

        return PasswordRecoveryChallenge::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /* ========================= الحالة أ: بريدٌ موثّق ========================= */

    public function test_the_code_goes_to_the_verified_recovery_email(): void
    {
        $this->post(route('recovery.start'), ['email' => 'ward@abaadapp.om'])->assertRedirect();

        Mail::assertSent(RecoveryOtpMail::class, fn ($mail) => $mail->hasTo('owner@gmail.com'));
    }

    /**
     * ولا يُقبل عنوانٌ يكتبه الطالب — لا حقلَ له أصلًا، ولو أُرسل يُتجاهَل.
     *
     * وهذا هو الفحص الذي تقوم عليه المهمّة كلّها.
     */
    public function test_a_destination_email_in_the_request_is_ignored(): void
    {
        $this->post(route('recovery.start'), [
            'email' => 'ward@abaadapp.om',
            'recovery_email' => 'attacker@evil.com',
            'target_email' => 'attacker@evil.com',
        ])->assertRedirect();

        Mail::assertSent(RecoveryOtpMail::class, fn ($mail) => $mail->hasTo('owner@gmail.com'));
        Mail::assertNotSent(RecoveryOtpMail::class, fn ($mail) => $mail->hasTo('attacker@evil.com'));
    }

    public function test_a_valid_code_authorizes_and_the_password_changes(): void
    {
        $challenge = $this->startFor($this->owner);

        $this->post(route('recovery.check'), [
            'challenge' => $challenge->token,
            'code' => $this->codeFromMail(),
        ])->assertRedirect(route('recovery.password', ['challenge' => $challenge->token]));

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token,
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertRedirect(route('login'));

        $fresh = $this->owner->fresh();
        $this->assertTrue(Hash::check('new-password-9', $fresh->password), 'الكلمة الجديدة تعمل');
        $this->assertFalse(Hash::check('old-password', $fresh->password), 'القديمة لم تعد تعمل');
    }

    public function test_a_wrong_code_grants_nothing(): void
    {
        $challenge = $this->startFor($this->owner);

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($challenge->fresh()->canSetPassword());
    }

    public function test_an_expired_code_grants_nothing(): void
    {
        $challenge = $this->startFor($this->owner);
        $code = $this->codeFromMail();

        PasswordRecoveryOtp::where('challenge_id', $challenge->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        $challenge = $this->startFor($this->owner);
        $code = $this->codeFromMail();

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $code]);

        // نفس الرمز مرّةً ثانية — على محاولةٍ جديدة
        $second = $this->startFor($this->owner);

        $this->post(route('recovery.check'), ['challenge' => $second->token, 'code' => $code])
            ->assertSessionHasErrors('code');
    }

    /* ============================== الرمز نفسه ============================== */

    /** ستّة أرقامٍ من مولّدٍ آمن */
    public function test_the_code_is_six_digits(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', RecoveryOtp::generate());
        }
    }

    /** ولا يُخزَّن نصًّا: من قرأ صفًّا في القاعدة لا يملك شيئًا */
    public function test_the_code_is_stored_hashed_only(): void
    {
        $challenge = $this->startFor($this->owner);
        $code = $this->codeFromMail();

        $stored = DB::table('password_recovery_otps')->where('challenge_id', $challenge->id)->first();

        $this->assertNotSame($code, $stored->otp_hash);
        $this->assertTrue(Hash::check($code, $stored->otp_hash));
        // ولا يظهر في أيّ عمودٍ آخر من الصفّ
        $this->assertStringNotContainsString($code, json_encode($stored));
    }

    /** ولا يعود إلى الشاشة أبدًا */
    public function test_the_code_never_reaches_the_screen(): void
    {
        $challenge = $this->startFor($this->owner);
        $code = $this->codeFromMail();

        $props = $this->get(route('recovery.verify', ['challenge' => $challenge->token]))
            ->viewData('page')['props'];

        $this->assertStringNotContainsString($code, json_encode($props));
        // والعنوان مُقنَّع لا كامل
        $this->assertSame(RecoveryEmail::mask('owner@gmail.com'), $props['masked']);
        $this->assertStringNotContainsString('owner@gmail.com', json_encode($props));
    }

    /** وخمس محاولاتٍ خاطئة تُبطله */
    public function test_five_wrong_attempts_kill_the_code(): void
    {
        $challenge = $this->startFor($this->owner);
        $code = $this->codeFromMail();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => '111111']);
        }

        // والرمز الصحيح نفسه لم يعد يعمل
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse($challenge->fresh()->canSetPassword());
    }

    /** وإعادة الإرسال تُبطل ما قبله — أحدثُ رمزٍ وحده يعمل */
    public function test_resending_invalidates_the_previous_code(): void
    {
        $challenge = $this->startFor($this->owner);
        $first = $this->codeFromMail();

        // المهلة تُقاس من زمن الرمز — نُقدّمه إلى الماضي بدل الانتظار
        PasswordRecoveryOtp::where('challenge_id', $challenge->id)
            ->update(['created_at' => now()->subMinutes(5)]);
        Mail::fake();

        $this->post(route('recovery.resend'), ['challenge' => $challenge->token])->assertRedirect();
        $second = $this->codeFromMail();

        $this->assertNotSame($first, $second);

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $first])
            ->assertSessionHasErrors('code');

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $second])
            ->assertSessionHasNoErrors();
    }

    /** والمهلة بين إرسالين في الخادم لا في عدّاد الشاشة */
    public function test_resending_too_soon_is_refused_by_the_server(): void
    {
        $challenge = $this->startFor($this->owner);

        $this->post(route('recovery.resend'), ['challenge' => $challenge->token])
            ->assertSessionHasErrors('code');

        Mail::assertSentCount(1);
    }

    /* ========================== الإذن بتعيين الكلمة ========================== */

    /** لا كلمةَ جديدة بلا رمزٍ اجتيز — ولو نودي المسار مباشرةً */
    public function test_the_password_route_refuses_an_unverified_challenge(): void
    {
        $challenge = $this->startFor($this->owner);

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token,
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    /** والإذن يُستهلك مرّةً واحدة */
    public function test_the_authorization_is_one_time(): void
    {
        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'first-password-1',
            'password_confirmation' => 'first-password-1',
        ])->assertRedirect(route('login'));

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'second-password-2',
            'password_confirmation' => 'second-password-2',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('first-password-1', $this->owner->fresh()->password));
    }

    /** وينتهي بمهلته */
    public function test_the_authorization_expires(): void
    {
        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);

        $challenge->fresh()->forceFill([
            'authorized_at' => now()->subSeconds((int) config('recovery.authorization_ttl') + 60),
        ])->save();

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertSessionHasErrors('password');
    }

    /** والكلمة الضعيفة تُرفض بالسياسة المركزية */
    public function test_a_weak_password_is_refused(): void
    {
        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);

        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'abc', 'password_confirmation' => 'abc',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    /* ======================== الجلسات وجهاز نقطة البيع ======================== */

    /**
     * الجلسات القائمة تُحذف — حذفًا من الجدول لا وعدًا.
     *
     * من نسي كلمته قد يكون فقد جهازه: جلسةٌ مفتوحة على جهازٍ آخر تجعل
     * الاستعادة إجراءً شكليًّا — الكلمة تتغيّر ومن دخل بها لا يزال داخلًا.
     */
    public function test_old_sessions_are_actually_deleted(): void
    {
        config(['session.driver' => 'database']);

        DB::table('sessions')->insert([
            'id' => 'sess-of-owner', 'user_id' => $this->owner->id,
            'ip_address' => '1.1.1.1', 'user_agent' => 'x',
            'payload' => 'x', 'last_activity' => now()->timestamp,
        ]);
        DB::table('sessions')->insert([
            'id' => 'sess-of-someone-else', 'user_id' => null,
            'ip_address' => '2.2.2.2', 'user_agent' => 'x',
            'payload' => 'x', 'last_activity' => now()->timestamp,
        ]);

        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);
        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ]);

        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-of-owner')->count());
        // وجلسةُ غيره لا تُمسّ
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-of-someone-else')->count());
    }

    /** وبصمة «تذكّرني» تتبدّل فتسقط الكوكيّات المحفوظة */
    public function test_remember_me_is_invalidated(): void
    {
        $this->owner->forceFill(['remember_token' => 'the-old-remember-token'])->save();

        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);
        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ]);

        $this->assertNotSame('the-old-remember-token', $this->owner->fresh()->remember_token);
    }

    /**
     * وجهاز نقطة البيع يبقى مفعَّلًا.
     *
     * تفعيلُه كوكيّةٌ على الجهاز لا جلسةُ مستخدم، وإسقاطُه يعني كاشيرًا يقف
     * أمام شاشة إعدادٍ صباحًا لأنّ صاحب المحلّ نسي كلمته ليلًا.
     */
    public function test_the_pos_device_stays_activated(): void
    {
        $device = PosDevice::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'name' => 'كاشير', 'token_hash' => hash('sha256', 'raw'),
            'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);

        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);
        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token, 'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ]);

        $fresh = $device->fresh();
        $this->assertSame(PosDevice::ACTIVE, $fresh->status);
        $this->assertNotNull($fresh->activated_at);
    }

    /* ============================== عزل المتاجر ============================== */

    /** محاولةُ متجرٍ لا تُغيّر كلمة مرور متجرٍ آخر */
    public function test_one_businesss_challenge_cannot_reset_another(): void
    {
        [, $stranger] = $this->shop('ورد آخر', 'other@abaadapp.om', 'other@gmail.com');

        $challenge = $this->startFor($this->owner);
        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $this->codeFromMail()]);

        // معرّفُ مستخدمٍ آخر مُرسَلٌ مع الطلب — يُتجاهَل، والخادم يقرأ صاحب المحاولة
        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token,
            'user_id' => $stranger->id,
            'business_id' => $stranger->business_id,
            'email' => $stranger->email,
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('old-password', $stranger->fresh()->password), 'حساب الغريب لم يُمسّ');
        $this->assertTrue(Hash::check('new-password-9', $this->owner->fresh()->password));
    }

    /** ورمزُ متجرٍ لا يعمل على محاولة متجرٍ آخر */
    public function test_one_businesss_code_does_not_work_on_another(): void
    {
        [, $stranger] = $this->shop('ورد آخر', 'other@abaadapp.om', 'other@gmail.com');

        $mine = $this->startFor($this->owner);
        $myCode = $this->codeFromMail();

        Mail::fake();
        $theirs = $this->startFor($stranger);

        $this->post(route('recovery.check'), ['challenge' => $theirs->token, 'code' => $myCode])
            ->assertSessionHasErrors('code');

        $this->assertFalse($theirs->fresh()->canSetPassword());
        $this->assertNotNull($mine->id);
    }
}
