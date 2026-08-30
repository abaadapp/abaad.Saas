<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * الصمت — وما لا يجوز أن يُقال في شاشةٍ عامّة.
 *
 * كلُّ فرقٍ في الردّ بين حسابٍ موجود وحسابٍ لا وجود له يجعل هذه الشاشة أداةَ
 * جردٍ للمنصّة: تُجرَّب العناوين حتى تُعرف الحسابات، ثمّ يُنتقل بها إلى شاشة
 * الدخول. والفروق تتسرّب من ثلاثة مواضع: نصُّ الردّ، ورمزُ الحالة، وتحويلةُ
 * الوجهة.
 */
class AccountRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();
    }

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $login, string $status = 'نشط', string $userStatus = 'نشط'): array
    {
        $business = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => $status]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $user = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $login,
            'password' => bcrypt('old-password'), 'role' => 'admin', 'status' => $userStatus,
            'recovery_email' => 'owner@gmail.com', 'recovery_email_verified_at' => now(),
        ]);

        return [$business, $user];
    }

    /** الردّ الذي يراه العالَم: نصُّه ورمزُه معًا */
    private function fingerprint(string $login): string
    {
        $response = $this->post(route('recovery.start'), ['email' => $login]);

        return $response->getStatusCode().'|'.(string) $response->getSession()->get('status');
    }

    /**
     * الحسابات الميّتة كلّها تُجيب كما يُجيب العدم.
     *
     * وهذه أربع حالاتٍ تُقارَن ببصمةٍ واحدة: لو اختلفت واحدةٌ منها في حرفٍ
     * لَصار الفرق دلالةً تُقرأ.
     */
    public function test_unknown_suspended_disabled_and_deleted_all_answer_alike(): void
    {
        $this->shop('موقوف', 'suspended@abaadapp.om', userStatus: 'موقوف');
        $this->shop('معطل', 'disabled@abaadapp.om', status: 'معطل');

        [, $deleted] = $this->shop('محذوف', 'deleted@abaadapp.om');
        $deleted->delete();

        $baseline = $this->fingerprint('nobody-at-all@abaadapp.om');

        foreach (['suspended@abaadapp.om', 'disabled@abaadapp.om', 'deleted@abaadapp.om'] as $login) {
            $this->assertSame($baseline, $this->fingerprint($login), 'تسرّبت حالُ الحساب: '.$login);
        }

        Mail::assertNothingSent();
    }

    /** ولا يُفتح حسابٌ موقوف من هذا الباب */
    public function test_a_suspended_account_cannot_be_recovered(): void
    {
        [, $user] = $this->shop('موقوف', 'suspended@abaadapp.om', userStatus: 'موقوف');

        $this->post(route('recovery.start'), ['email' => 'suspended@abaadapp.om']);

        $this->assertSame(0, PasswordRecoveryChallenge::count());
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /**
     * والاشتراك المنتهي **لا** يمنع الاستعادة.
     *
     * هي ما يحتاجه ليدخل ويجدّد؛ ومنعُها يعني تاجرًا لا يستطيع أن يدفع لنا.
     */
    public function test_an_expired_subscription_does_not_block_recovery(): void
    {
        [$business, $user] = $this->shop('منتهٍ', 'expired@abaadapp.om');
        $business->update(['ends_at' => now()->subMonth()]);

        $this->post(route('recovery.start'), ['email' => 'expired@abaadapp.om'])->assertRedirect();

        Mail::assertSent(RecoveryOtpMail::class, fn ($m) => $m->hasTo('owner@gmail.com'));
        $this->assertSame(1, PasswordRecoveryChallenge::where('user_id', $user->id)->count());
    }

    /* ------------------------------ حدّ المحاولات ------------------------------ */

    public function test_starting_recovery_is_rate_limited(): void
    {
        $this->shop('محل', 'shop@abaadapp.om');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om']);
        }

        $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om'])
            ->assertSessionHasErrors('email');
    }

    /** والحدّ لا يخلط بين حسابين: إغراقُ حسابٍ لا يُقفل باب غيره */
    public function test_the_rate_limit_does_not_spill_across_accounts(): void
    {
        $this->shop('أول', 'first@abaadapp.om');
        $this->shop('ثاني', 'second@abaadapp.om');

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('recovery.start'), ['email' => 'first@abaadapp.om']);
        }

        $this->post(route('recovery.start'), ['email' => 'second@abaadapp.om'])
            ->assertSessionHasNoErrors();
    }

    /* -------------------------------- الأسرار -------------------------------- */

    /** لا اعتماداتِ بريدٍ في أيّ شاشة */
    public function test_no_smtp_secret_reaches_any_recovery_screen(): void
    {
        config(['mail.mailers.smtp.password' => 'the-gmail-app-password']);

        [, $user] = $this->shop('محل', 'shop@abaadapp.om');
        $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om']);
        $challenge = PasswordRecoveryChallenge::where('user_id', $user->id)->latest('id')->firstOrFail();

        foreach ([route('password.request'), route('recovery.verify', ['challenge' => $challenge->token])] as $url) {
            $body = $this->get($url)->getContent();
            $this->assertStringNotContainsString('the-gmail-app-password', $body);
            $this->assertStringNotContainsString('smtp.gmail.com', $body);
        }
    }

    /**
     * ولا رمزَ ولا كلمةَ مرورٍ في سجلّ النشاط.
     *
     * السجلّ يُقرأ ويُصدَّر ويُنسخ في نسخةٍ احتياطية — وما دخله خرج معه.
     */
    public function test_neither_the_code_nor_the_password_reaches_the_activity_log(): void
    {
        [, $user] = $this->shop('محل', 'shop@abaadapp.om');

        $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om']);
        $challenge = PasswordRecoveryChallenge::where('user_id', $user->id)->latest('id')->firstOrFail();

        $code = null;
        Mail::assertSent(RecoveryOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->post(route('recovery.check'), ['challenge' => $challenge->token, 'code' => $code]);
        $this->post(route('recovery.password.store'), [
            'challenge' => $challenge->token,
            'password' => 'a-very-secret-password',
            'password_confirmation' => 'a-very-secret-password',
        ]);

        $logs = \App\Models\ActivityLog::pluck('description')->implode(' | ');
        $this->assertStringNotContainsString((string) $code, $logs);
        $this->assertStringNotContainsString('a-very-secret-password', $logs);
        // ولا رمزُ المحاولة نفسه
        $this->assertStringNotContainsString($challenge->token, $logs);
    }

    /**
     * ورمزُ المحاولة لا يُشتقّ من صاحبه.
     *
     * لو اشتُقّ من المعرّف أو البريد لَأمكن بناؤه لأيّ حساب. والفحص أنّ
     * محاولتين للحساب نفسه لا تتشابهان: مشتقٌّ من ثابتٍ يُنتج الثابت نفسه.
     */
    public function test_the_challenge_token_is_random_not_derived(): void
    {
        [, $user] = $this->shop('محل', 'shop@abaadapp.om');

        $tokens = [];
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om']);
            $tokens[] = PasswordRecoveryChallenge::where('user_id', $user->id)->latest('id')->value('token');
        }

        $this->assertCount(3, array_unique($tokens), 'رمزان متطابقان يعنيان اشتقاقًا لا عشوائية');

        foreach ($tokens as $token) {
            $this->assertGreaterThanOrEqual(32, strlen($token));
            $this->assertStringNotContainsString('shop', $token);
        }
    }

    /** ومحاولةٌ مخترَعة لا تفتح شيئًا */
    public function test_a_forged_challenge_token_opens_nothing(): void
    {
        [, $user] = $this->shop('محل', 'shop@abaadapp.om');

        $this->get(route('recovery.password', ['challenge' => str_repeat('a', 48)]))
            ->assertRedirect(route('password.request'));

        $this->post(route('recovery.password.store'), [
            'challenge' => str_repeat('a', 48),
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /** وبلا بريدٍ مضبوط على الخادم يُقفل الباب كلّه — لا يَعِد بما لا يفعل */
    public function test_with_mail_unconfigured_the_whole_door_is_shut(): void
    {
        config(['mail.default' => 'log']);
        $this->shop('محل', 'shop@abaadapp.om');

        $this->get(route('password.request'))->assertNotFound();
        $this->post(route('recovery.start'), ['email' => 'shop@abaadapp.om'])->assertNotFound();
    }
}
