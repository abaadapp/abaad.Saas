<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بابٌ لا يفتح يُقفل ويُقال لماذا.
 *
 * `log` و`array` مُرسلان صالحان في نظر لارافيل: Mail::send تنجح ولا ترمي
 * شيئًا، فتقول الشاشة «أرسلنا الرابط» والرسالة في ملفّ سجلٍّ على الخادم.
 * ينتظر المستخدم رسالةً لن تأتي، ويعيد المحاولة، ويظنّ الخطأ منه.
 *
 * وإخفاء الرابط من شاشة الدخول وحده لا يكفي: من يعرف العنوان — أو حفظه
 * متصفّحه — يصل إلى النموذج نفسه. فالقفل في الخادم، والإخفاء في الواجهة.
 */
class PasswordRecoveryDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $business->id, 'name' => 'مدير', 'role' => 'admin']);

        User::create([
            'business_id' => $business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'secret-pass',
        ]);
    }

    private function withMail(?string $driver, ?string $host = 'smtp.example.com'): void
    {
        config(['mail.default' => $driver, 'mail.mailers.smtp.host' => $host]);
    }

    /* --------------------------- البريد معطّل --------------------------- */

    public function test_the_login_screen_hides_the_recovery_link(): void
    {
        $this->withMail('log');

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canRecover', false));
    }

    public function test_the_recovery_form_is_closed_even_to_someone_who_knows_the_url(): void
    {
        $this->withMail('log');

        $this->get(route('password.request'))->assertNotFound();
    }

    public function test_posting_the_form_directly_is_refused_too(): void
    {
        // النموذج مقفل، فالمنفذ الوحيد الباقي هو POST مباشرةً — ويُقفل معه
        $this->withMail('log');

        $this->post(route('password.email'), ['email' => 'salem@abaad.om'])->assertNotFound();
    }

    public function test_an_array_mailer_counts_as_no_mail(): void
    {
        // array تُستعمل في الاختبارات وقد تُترك في .env سهوًا: تنجح ولا تُوصل
        $this->withMail('array');

        $this->get(route('password.request'))->assertNotFound();
    }

    public function test_smtp_without_a_host_counts_as_no_mail(): void
    {
        /*
         * أخبث الحالات: MAIL_MAILER=smtp مضبوط والمضيف فارغ. الفحص القديم
         * كان يمرّرها لأنه يقارن بـ'log' وحدها، ثم يفشل الإرسال وقت التنفيذ
         * — أي عند المستخدم لا عند من ضبط الخادم.
         */
        $this->withMail('smtp', null);

        $this->get(route('password.request'))->assertNotFound();
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRecover', false));
    }

    public function test_an_api_mailer_without_a_key_counts_as_no_mail(): void
    {
        /*
         * نظير حالة smtp بلا مضيف، في مزوّدٍ يرسل عبر HTTPS: MAIL_MAILER=resend
         * مضبوط والمفتاح فارغ. لارافيل تقبل الإعداد وتبني المُرسِل، ثم يرفض
         * المزوّد الطلب وقت الإرسال — أي عند المستخدم لا عند من ضبط الخادم.
         */
        config(['mail.default' => 'resend', 'services.resend.key' => null]);

        $this->get(route('password.request'))->assertNotFound();
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRecover', false));
    }

    /* --------------------------- البريد مضبوط --------------------------- */

    public function test_an_api_mailer_with_a_key_opens_the_door(): void
    {
        // ولا مضيفَ ولا منفذ هنا: المزوّد يُنادى على 443، والمفتاح وحده اعتماده
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);

        $this->get(route('password.request'))->assertOk();
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRecover', true));
    }

    public function test_a_real_mailer_reopens_the_door_by_itself(): void
    {
        /*
         * لا تدخّل يدويّ يوم يُضبط SMTP: الشاشة والمسار يقرآن الإعداد نفسه،
         * فيعود الرابط وحده. ولولا ذلك لبقي الباب مقفلًا بعد إصلاح السبب.
         */
        $this->withMail('smtp');

        $this->get(route('password.request'))->assertOk();
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRecover', true));
    }

    public function test_signing_in_never_depended_on_mail_anyway(): void
    {
        // الدخول لا يرسل بريدًا إطلاقًا — فتعطيل البريد لا يمسّه
        $this->withMail('log');

        $this->post(route('login.attempt'), [
            'email' => 'salem@abaad.om', 'password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }
}
