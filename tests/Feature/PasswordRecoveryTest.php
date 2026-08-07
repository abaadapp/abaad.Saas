<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * بابان كانا مفتوحَين على الجهتين.
 *
 * الأول: دخول البريد بلا حدّ محاولات — بينما دخول الرمز محروسٌ بحدَّين وقفل.
 * والثاني: لا استعادة لكلمة المرور إطلاقًا، فكل تاجرٍ نسي كلمته يمرّ بالدعم.
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:owner@abaadapp.om|127.0.0.1');
        RateLimiter::clear('login-hour:owner@abaadapp.om|127.0.0.1');

        $this->business = Business::create([
            'name' => 'متجر الاختبار', 'type' => 'عام', 'status' => 'نشط',
            'email' => 'real@gmail.com', 'owner_name' => 'سالم',
        ]);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'سالم', 'email' => 'owner'.MerchantAccount::DOMAIN,
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط',
            'password' => 'correct-horse',
        ]);
    }

    /* ------------------------ خانق الدخول ------------------------ */

    public function test_the_email_door_locks_after_five_wrong_tries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), [
                'email' => $this->owner->email, 'password' => 'wrong'.$i,
            ])->assertSessionHasErrors('email');
        }

        // السادسة تُرفض قبل أن تُقارَن كلمة المرور أصلًا
        $this->post(route('login.attempt'), [
            'email' => $this->owner->email, 'password' => 'wrong-again',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(RateLimiter::tooManyAttempts('login:'.$this->owner->email.'|127.0.0.1', 5));

        // وحتى الكلمة الصحيحة لا تمرّ ما دام القفل قائمًا — وإلا كان الخانق زينة
        $this->post(route('login.attempt'), [
            'email' => $this->owner->email, 'password' => 'correct-horse',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_correct_password_clears_the_counter(): void
    {
        $this->post(route('login.attempt'), ['email' => $this->owner->email, 'password' => 'nope']);

        $this->post(route('login.attempt'), [
            'email' => $this->owner->email, 'password' => 'correct-horse',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($this->owner);
        $this->assertFalse(RateLimiter::tooManyAttempts('login:'.$this->owner->email.'|127.0.0.1', 1));
    }

    public function test_one_shop_does_not_lock_another(): void
    {
        // المفتاح بريدٌ وعنوان: قفلُ حسابٍ لا يقفل بقية من خلف الموجّه نفسه
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login.attempt'), ['email' => 'other@abaadapp.om', 'password' => 'x']);
        }

        $this->post(route('login.attempt'), [
            'email' => $this->owner->email, 'password' => 'correct-horse',
        ])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_the_failed_attempt_is_logged_without_the_password(): void
    {
        $this->post(route('login.attempt'), ['email' => $this->owner->email, 'password' => 'super-secret']);

        $log = ActivityLog::where('action', 'login_failed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString($this->owner->email, $log->description);
        $this->assertStringNotContainsString('super-secret', $log->description);
    }

    /* --------------------- استعادة كلمة المرور --------------------- */

    public function test_the_link_goes_to_the_shop_contact_email_not_the_login_one(): void
    {
        Mail::fake();

        $this->post(route('password.email'), ['email' => $this->owner->email])
            ->assertSessionHas('status');

        /*
         * @abaadapp.om نطاقُ دخولٍ داخلي لا صندوق خلفه. لو أُرسل إليه لقال
         * النظام «أرسلنا» ولم يصل شيء — انتظارٌ بلا نهاية.
         */
        Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('real@gmail.com'));
    }

    public function test_a_real_email_receives_it_directly(): void
    {
        Mail::fake();

        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'احمد',
            'email' => 'ahmed@gmail.com', 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'password' => 'x',
        ]);

        $this->post(route('password.email'), ['email' => $cashier->email]);

        Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('ahmed@gmail.com'));
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Mail::fake();

        $this->post(route('password.email'), ['email' => 'nobody@nowhere.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        // الفرق بين الجوابين يجعل هذه الشاشة أداة جردٍ لحسابات المنصة
        Mail::assertNothingSent();
    }

    public function test_a_shop_without_a_contact_email_is_flagged_for_support(): void
    {
        Mail::fake();
        $this->business->update(['email' => null]);

        $this->post(route('password.email'), ['email' => $this->owner->email])
            ->assertSessionHas('status');

        Mail::assertNothingSent();

        // لا يُقال لطالب الاستعادة، لكنه يُقيَّد وإلا انتظر بلا سبب يعرفه أحد
        $this->assertDatabaseHas('activity_logs', [
            'business_id' => $this->business->id,
            'action' => 'login_failed',
        ]);
    }

    public function test_a_suspended_account_gets_no_link(): void
    {
        Mail::fake();
        $this->owner->update(['status' => 'موقوف']);

        $this->post(route('password.email'), ['email' => $this->owner->email]);

        // الاستعادة تعيد كلمة المرور لا الصلاحية؛ رابطٌ ينتهي إلى رفضٍ أسوأ من صمت
        Mail::assertNothingSent();
    }

    public function test_repeated_requests_are_throttled(): void
    {
        Mail::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => $this->owner->email])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => $this->owner->email])
            ->assertSessionHasErrors('email');

        Mail::assertSentCount(3);
    }

    public function test_the_link_actually_changes_the_password(): void
    {
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($this->owner);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->owner->email,
            'password' => 'new-strong-pass',
            'password_confirmation' => 'new-strong-pass',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-strong-pass', $this->owner->fresh()->getRawOriginal('password')));

        $this->post(route('login.attempt'), [
            'email' => $this->owner->email, 'password' => 'new-strong-pass',
        ])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_the_link_works_once(): void
    {
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($this->owner);
        $payload = [
            'token' => $token, 'email' => $this->owner->email,
            'password' => 'new-strong-pass', 'password_confirmation' => 'new-strong-pass',
        ];

        $this->post(route('password.update'), $payload);
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
    }

    public function test_a_forged_token_is_refused(): void
    {
        $this->post(route('password.update'), [
            'token' => str_repeat('a', 64), 'email' => $this->owner->email,
            'password' => 'new-strong-pass', 'password_confirmation' => 'new-strong-pass',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-horse', $this->owner->fresh()->getRawOriginal('password')));
    }

    public function test_a_short_password_is_refused(): void
    {
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($this->owner);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $this->owner->email,
            'password' => '123', 'password_confirmation' => '123',
        ])->assertSessionHasErrors('password');
    }

    public function test_resetting_invalidates_the_remember_cookie(): void
    {
        $before = $this->owner->fresh()->remember_token;

        $this->post(route('password.update'), [
            'token' => \Illuminate\Support\Facades\Password::broker()->createToken($this->owner),
            'email' => $this->owner->email,
            'password' => 'new-strong-pass', 'password_confirmation' => 'new-strong-pass',
        ]);

        // من نسي كلمته قد يكون فقد جهازه — وإبقاء جلسةٍ مفتوحة يجعل الاستعادة شكليّة
        $this->assertNotSame($before, $this->owner->fresh()->remember_token);
    }

    public function test_the_screens_render(): void
    {
        $this->get(route('password.request'))->assertOk();
        $this->get(route('password.reset', ['token' => str_repeat('a', 64)]))->assertOk();
    }
}
