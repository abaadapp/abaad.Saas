<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفتاحٌ يَعِد ببريدٍ يقول متى لا يستطيع.
 *
 * ═══ العطب ═══
 *
 * ثلاثةُ مفاتيح في «الإشعارات» تَعِد صراحةً: «يُرسل إلى بريد صاحب النشاط عند
 * إتمام أي عملية بيع»، و«يصل آخر اليوم بمبيعات اليوم». وكلُّها يمرّ بـ
 * `Mail::to(...)->send()` — **وهي تنجح مهما كان المُرسِل**.
 *
 * وعلى الخادم الحيّ كان `MAIL_MAILER=log`: تُكتب الرسالة في ملفٍّ على الخادم
 * ولا تغادره. فلا استثناءَ يُرفع، ولا سطرَ في السجلّ يقول «لم تُرسَل»، ولا
 * شيءَ في الشاشة. يفتح التاجر المفاتيحَ الثلاثة ويطمئنّ، ولا يصله شيءٌ أبدًا،
 * ولا يعرف لماذا — «مقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض».
 *
 * ولا تُطفأ المفاتيح ولا تُخفى: الإعدادُ اختيارُ صاحبه ويبقى محفوظًا حتى
 * يُضبط البريد. إنّما تُقال الحقيقةُ فوقه.
 */
class ASwitchThatPromisesMailSaysWhenItCannotTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط',
            'email' => 'shop@abaad.om',
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك',
            'email' => 'owner@abaad.om', 'password' => bcrypt('password'),
            'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function props(): array
    {
        return $this->get(route('admin.settings.index'))->assertOk()->viewData('page')['props'];
    }

    /* ───────────────── ما تقوله الشاشة ───────────────── */

    /** مُرسِلٌ لا يغادر بريدُه الخادم: تقول الشاشة ذلك فوق المفاتيح */
    public function test_a_dead_mailer_is_said_on_the_screen(): void
    {
        config(['mail.default' => 'log']);

        $mail = $this->props()['mail'];

        $this->assertFalse($mail['deliverable']);
        $this->assertNotNull($mail['reason'], 'مفاتيحُ بريدٍ لا يصل تُعرض بلا كلمةٍ تقول ذلك');
        $this->assertStringContainsString('غير مضبوط', $mail['reason']);
    }

    /** و«array» مثله — يُحفظ في الذاكرة ولا يخرج */
    public function test_the_array_mailer_is_dead_too(): void
    {
        config(['mail.default' => 'array']);

        $this->assertFalse(Mailer::configured());
        $this->assertFalse($this->props()['mail']['deliverable']);
    }

    /** ومتجرٌ بلا بريدٍ مسجَّل: لا مُرسَلَ إليه، ويُقال له أين يكتبه */
    public function test_a_shop_with_no_email_is_told_where_to_write_it(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.abaad.om']);
        $this->business->update(['email' => null]);

        $mail = $this->props()['mail'];

        $this->assertFalse($mail['deliverable']);
        $this->assertStringContainsString('بيانات النشاط', $mail['reason'], 'قيل «لا بريد» ولم يُقل أين يُكتب');
    }

    /** وبريدٌ فارغٌ بمسافاتٍ ليس بريدًا */
    public function test_a_blank_email_is_not_an_email(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.abaad.om']);
        $this->business->update(['email' => '   ']);

        $this->assertFalse(Mailer::deliverable($this->business->fresh()));
    }

    /** وحين يصل البريد فعلًا: لا تحذير يُقلق بلا سبب */
    public function test_a_working_mailer_shows_no_warning(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.abaad.om']);

        $mail = $this->props()['mail'];

        $this->assertTrue($mail['deliverable']);
        $this->assertNull($mail['reason'], 'حُذّر التاجر من عطبٍ لا وجود له');
    }

    /* ───────────────── والمفاتيحُ تبقى تُحفظ ───────────────── */

    /**
     * ولا يُمنع الحفظ ولا يُطفأ المفتاح.
     *
     * الإعدادُ اختيارُ صاحبه: يفتحه اليوم ويضبط بريدَه غدًا فيجد اختيارَه
     * كما تركه. وإطفاؤه نيابةً عنه يجعله يفتحه مرّتين ولا يعرف لماذا انطفأ.
     */
    public function test_the_switch_is_still_saved_while_mail_is_dead(): void
    {
        config(['mail.default' => 'log']);

        $this->post(route('admin.settings.update'), [
            'notify_daily_summary' => true,
            'notify_new_order' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1', Setting::where('business_id', $this->business->id)
            ->where('key', 'notify_daily_summary')->value('value'));
        $this->assertSame('0', Setting::where('business_id', $this->business->id)
            ->where('key', 'notify_new_order')->value('value'));
    }

    /* ───────────────── والقاعدة في موضعٍ واحد ───────────────── */

    /**
     * والقاعدةُ تُقرأ من `Mailer` لا تُكتب في كلّ بابٍ يرسل.
     *
     * أربعةُ مواضع تقرؤها: شاشةُ استعادة كلمة المرور، وحارسُ مسارها،
     * و`preflight` قبل الإطلاق، وهذه المفاتيح. وهي أدقُّ من «أليس log؟»:
     * `smtp` بلا مضيف و«resend» بلا مفتاح يبنيهما لارافيل بلا شكوى ثمّ
     * يسقط الإرسالُ عند المستخدم لا عند من ضبط الخادم.
     */
    public function test_the_rule_lives_in_one_place(): void
    {
        foreach (['log', 'array', 'null'] as $dead) {
            config(['mail.default' => $dead]);
            $this->assertFalse(Mailer::configured(), $dead.' يُقرأ مُرسِلًا حيًّا');
        }

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
        $this->assertFalse(Mailer::configured(), 'smtp بلا مضيفٍ يُقرأ حيًّا');

        config(['mail.default' => 'resend', 'services.resend.key' => '']);
        $this->assertFalse(Mailer::configured(), 'مزوّدٌ بلا مفتاحٍ يُقرأ حيًّا');

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.abaad.om']);
        $this->assertTrue(Mailer::configured(), 'smtp بمضيفه يُقرأ ميّتًا');

        config(['mail.default' => 'resend', 'services.resend.key' => 'k']);
        $this->assertTrue(Mailer::configured(), 'مزوّدٌ بمفتاحه يُقرأ ميّتًا');
    }
}
