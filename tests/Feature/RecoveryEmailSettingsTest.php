<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ضبط بريد الاستعادة من داخل الحساب — الطريق الصحيح.
 *
 * يُضبط والحساب مفتوح، قبل أن يُحتاج إليه. ومن ضبطه اليوم لا يحتاج إلى أحدٍ
 * يوم ينسى كلمته.
 *
 * وجلسةٌ مفتوحة وحدها لا تكفي: جهازٌ تُرك دقيقتين يكفي لكتابة بريدٍ غريب —
 * ثمّ يملك صاحبُه الحسابَ إلى الأبد بلا كلمة مرورٍ ولا شيء.
 */
class RecoveryEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'ward@abaadapp.om',
            'password' => bcrypt('my-password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('my-password'), 'role' => 'cashier', 'status' => 'نشط',
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

    /* ------------------------------ الإضافة ------------------------------ */

    /** كلمةُ المرور الحالية شرطٌ — الجلسة وحدها لا تكفي */
    public function test_an_open_session_alone_cannot_set_the_recovery_email(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'attacker@evil.com',
            'current_password' => 'not-my-password',
        ])->assertSessionHasErrors('current_password');

        Mail::assertNothingSent();
        $this->assertNull($this->owner->fresh()->recovery_email);
    }

    /** والعنوان لا يُختم بمجرّد كتابته — يُرسَل إليه رمزٌ أوّلًا */
    public function test_the_email_is_not_verified_until_the_code_comes_back(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com',
            'current_password' => 'my-password',
        ])->assertRedirect();

        // الرمز أُرسل، ولا شيء خُتم بعد
        Mail::assertSent(RecoveryOtpMail::class, fn ($m) => $m->hasTo('owner@gmail.com'));
        $this->assertNull($this->owner->fresh()->recovery_email_verified_at);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.confirm'), [
            'code' => $this->codeFromMail(),
        ])->assertRedirect();

        $fresh = $this->owner->fresh();
        $this->assertSame('owner@gmail.com', $fresh->recovery_email);
        $this->assertNotNull($fresh->recovery_email_verified_at);
    }

    /** ورمزٌ خاطئ لا يختم شيئًا */
    public function test_a_wrong_code_verifies_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com', 'current_password' => 'my-password',
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($this->owner->fresh()->recovery_email_verified_at);
    }

    /**
     * والعنوان يُقرأ من صفّ الرمز لا من الطلب.
     *
     * ولو قُرئ من الطلب لَكفى أن يُطلب رمزٌ إلى بريدٍ يملكه الطالب ثمّ يُرسَل
     * مع الرمز عنوانٌ آخر — فيُختم عنوانٌ لم يصل إليه شيء.
     */
    public function test_the_confirmed_address_comes_from_the_code_row_not_the_request(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com', 'current_password' => 'my-password',
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.confirm'), [
            'code' => $this->codeFromMail(),
            'recovery_email' => 'attacker@evil.com',
            'target_email' => 'attacker@evil.com',
        ])->assertRedirect();

        $this->assertSame('owner@gmail.com', $this->owner->fresh()->recovery_email);
    }

    /** والعنوان الداخليّ يُرفض: لا صندوق خلفه */
    public function test_an_internal_pseudo_email_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'ward@abaadapp.om', 'current_password' => 'my-password',
        ])->assertSessionHasErrors('recovery_email');

        Mail::assertNothingSent();
    }

    /* ------------------------------ التغيير ------------------------------ */

    /** وتغييرُ عنوانٍ مختوم يُنبّه العنوان القديم */
    public function test_changing_a_verified_email_warns_the_old_inbox(): void
    {
        $this->owner->forceFill([
            'recovery_email' => 'old@gmail.com', 'recovery_email_verified_at' => now(),
        ])->save();

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'new@gmail.com', 'current_password' => 'my-password',
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.confirm'), [
            'code' => $this->codeFromMail(),
        ])->assertRedirect();

        $this->assertSame('new@gmail.com', $this->owner->fresh()->recovery_email);
        Mail::assertSent(\App\Mail\RecoveryEmailChangedMail::class, fn ($m) => $m->hasTo('old@gmail.com'));
    }

    /* ------------------------------ العزل ------------------------------ */

    /** ومحاولةُ غيره لا تُكمَّل من حسابه */
    public function test_one_user_cannot_complete_another_users_verification(): void
    {
        // المالك يبدأ
        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com', 'current_password' => 'my-password',
        ]);
        $code = $this->codeFromMail();

        /*
         * والكاشير يحاول إكمالها برمزها — ويُردّ عند الباب.
         *
         * حارسان لا واحد: صلاحية «الإعدادات» تمنعه من المسار أصلًا، ولو
         * مُنحها لَما وجد محاولةً باسمه — الخادم يقرأ صاحبها من الجلسة.
         */
        $this->actingAs($this->cashier)->post(route('admin.settings.recovery.confirm'), ['code' => $code])
            ->assertForbidden();

        $this->assertNull($this->owner->fresh()->recovery_email_verified_at);
        $this->assertNull($this->cashier->fresh()->recovery_email);
    }

    /**
     * ومديرٌ ثانٍ في المتجر نفسه لا يُكمل محاولة الأوّل.
     *
     * الحارس الأوّل صلاحيةٌ، وهذا الثاني: للمتجر الواحد قد يكون مديران،
     * وكلاهما يملك «الإعدادات». والخادم يقرأ صاحب المحاولة من الجلسة، فرمزُ
     * أحدهما لا يختم بريد الآخر.
     */
    public function test_a_second_admin_cannot_complete_the_first_admins_verification(): void
    {
        $second = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير ثانٍ', 'email' => 'admin2@abaadapp.om',
            'password' => bcrypt('my-password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com', 'current_password' => 'my-password',
        ]);

        $this->actingAs($second)->post(route('admin.settings.recovery.confirm'), [
            'code' => $this->codeFromMail(),
        ])->assertSessionHasErrors('code');

        $this->assertNull($this->owner->fresh()->recovery_email_verified_at);
        $this->assertNull($second->fresh()->recovery_email);
    }

    /** والتاجر لا يكتب بريد استعادة متجرٍ آخر */
    public function test_a_user_cannot_write_another_businesss_recovery_email(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@abaadapp.om',
            'password' => bcrypt('my-password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com',
            'current_password' => 'my-password',
            // معرّفاتٌ مُرسَلة مع الطلب — الخادم يقرأ الجلسة لا الطلب
            'user_id' => $stranger->id,
            'business_id' => $other->id,
        ]);

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.confirm'), [
            'code' => $this->codeFromMail(),
        ]);

        $this->assertSame('owner@gmail.com', $this->owner->fresh()->recovery_email);
        $this->assertNull($stranger->fresh()->recovery_email);
    }

    /* ---------------------------- رمز الكاشير ---------------------------- */

    /**
     * ولا رمزَ كاشيرٍ يُرسل بالبريد ولا يُعرض.
     *
     * الرمز مبصومٌ في القاعدة (`pin` => hashed) فلا يُستخرج أصلًا؛ وهذا
     * الفحص يحرس ألّا يتسرّب من هذا الباب: من نسي رمزه يُعطيه مديرُه رمزًا
     * جديدًا، ولا يُقرأ القديم لأحد.
     */
    public function test_no_cashier_pin_is_ever_mailed_or_exposed(): void
    {
        $this->cashier->forceFill(['pin' => '1234'])->save();

        $this->actingAs($this->owner)->post(route('admin.settings.recovery.start'), [
            'recovery_email' => 'owner@gmail.com', 'current_password' => 'my-password',
        ]);

        Mail::assertSent(RecoveryOtpMail::class, function ($mail) {
            $rendered = $mail->render();

            $this->assertStringNotContainsString('1234', $rendered);
            $this->assertStringNotContainsString('pin', mb_strtolower($rendered));

            return true;
        });

        $this->assertArrayNotHasKey('pin', $this->cashier->fresh()->toArray());
    }
}
