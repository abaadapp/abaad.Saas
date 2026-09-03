<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * شاشة الدخول: بابٌ واحد، وهويّةُ جهازٍ فوقه.
 *
 * كان لها بابٌ ثانٍ — تبويب «رمز الموظف» — يُفتح افتراضيًّا على كل جهازٍ سبق
 * أن دخل منه أحد، ويصل إلى لوحة أرقامٍ تفتح حسابًا بأربعة أرقام. رُفع الباب
 * كلّه، وبقيت هوية الجهاز: اسم المتجر وفرعه فوق البطاقة، وبابُ نسيانه تحتها.
 *
 * وهذه الاختبارات تحرس ما بقي: أن الرمز ذهب فعلًا، وأن الهوية تُعرض لمن
 * يُعرف جهازه وحده.
 */
class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'secret-pass',
        ]);

        RateLimiter::clear('login:salem@abaad.om|127.0.0.1');
        RateLimiter::clear('login-hour:salem@abaad.om|127.0.0.1');
    }

    public function test_a_fresh_browser_sees_no_device_identity(): void
    {
        /*
         * متجرٌ واحد في القاعدة — وPosTerminal::businessId تسقط عليه تسهيلًا.
         * لو بُني العرض عليها لظهر اسم المتجر لزائرٍ لا يعنيه.
         */
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('device', null));
    }

    /** ولا بابَ رمزٍ في الشاشة أصلًا: البروب الذي كان يفتحه لم يعد يُرسل */
    public function test_the_screen_offers_no_pin_door(): void
    {
        $this->activatePosDevice($this->business->id);

        $this->get(route('login'))->assertOk()->assertInertia(
            fn ($page) => $page->component('Auth/Login')->missing('pin')
        );
    }

    /** والمسار نفسه رُفع — لا شاشةً مستقلّة ولا محاولةً تُقبل */
    public function test_the_pin_routes_are_gone(): void
    {
        $this->get('/pin-login')->assertNotFound();
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }

    /** والخروج بالخمول يعود إلى شاشة الدخول لا إلى لوحة الأرقام */
    public function test_idle_logout_returns_to_the_login_screen(): void
    {
        $this->actingAs($this->owner)
            ->post(route('logout', ['to' => 'pin']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_device_identity_appears_after_the_first_email_login(): void
    {
        $this->post(route('login.attempt'), [
            'email' => 'salem@abaad.om', 'password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        // أول دخولٍ يكتب كوكي المتجر — وهي أثر «هذا الجهاز يخصّ هذا المحل»
        $this->withCookie(PosTerminal::LEGACY_COOKIE, (string) $this->business->id)
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('device.business', 'متجر الورد'));
    }

    public function test_an_activated_device_shows_its_branch_and_register(): void
    {
        $device = $this->activatePosDevice($this->business->id);

        // الموظف يعرف بنظرةٍ أنه على الجهاز الصحيح: هذا الجهاز يبيع لهذا الفرع
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page
                ->where('device.business', 'متجر الورد')
                ->where('device.branch', 'الخوير')
                ->where('device.device', $device->name));
    }

    public function test_a_stale_cookie_pointing_nowhere_shows_nothing(): void
    {
        // متجرٌ حُذف أو كوكي عُبث بها: لا اسم متجرٍ بلا متجرٍ خلفه
        $this->withCookie(PosTerminal::LEGACY_COOKIE, '99999')
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('device', null));
    }

    /* --------------------------- حدّ المحاولات --------------------------- */

    /**
     * خمس محاولاتٍ خاطئة تكفي على باب البريد.
     *
     * كان الحدّ محروسًا على بابين، ورُفع أحدهما — فصار هذا هو الباب الوحيد،
     * وخلفه حساب مدير المنصة لا درج صندوق. والسادسة تُرفض بالحدّ لا بالكلمة:
     * حتى كلمة المرور الصحيحة لا تمرّ.
     */
    public function test_repeated_bad_passwords_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), [
                'email' => 'salem@abaad.om', 'password' => 'خطأ',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.attempt'), [
            'email' => 'salem@abaad.om', 'password' => 'secret-pass',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------ المخرج: نسيان الجهاز ------------------------ */

    public function test_forgetting_clears_the_remembered_shop(): void
    {
        /*
         * شاشةٌ تتذكّر متجرًا وتعرض اسمه تلزمها بابُ خروج: جهازٌ بيع، أو نُقل
         * إلى محلٍّ آخر، أو رُبط يوم التركيب بالمتجر الخطأ. وبلا هذا لا حيلة
         * إلا مسح كوكي المتصفّح يدويًّا.
         */
        $response = $this->withCookie(PosTerminal::LEGACY_COOKIE, (string) $this->business->id)
            ->post(route('device.forget'))
            ->assertRedirect(route('login'));

        $response->assertCookieExpired(PosTerminal::LEGACY_COOKIE);
        $response->assertCookieExpired(PosTerminal::COOKIE);
    }

    public function test_forgetting_does_not_revoke_the_device_in_the_records(): void
    {
        $device = $this->activatePosDevice($this->business->id);

        $this->post(route('device.forget'))->assertRedirect(route('login'));

        // الكوكي وحدها تُمسح؛ الإبطال الحقيقي في الإعدادات خلف صلاحيته
        $this->assertSame(\App\Models\PosDevice::ACTIVE, $device->fresh()->status);
    }

    public function test_the_screen_says_whether_the_device_is_activated(): void
    {
        // ثمن النسيان يفترق: كوكي تُكتب من جديد، أو جهازٌ يحتاج مديرًا
        $this->withCookie(PosTerminal::LEGACY_COOKIE, (string) $this->business->id)
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('device.activated', false));

        $this->activatePosDevice($this->business->id);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('device.activated', true));
    }

    public function test_there_is_no_forget_link_on_an_unknown_device(): void
    {
        // لا متجر يُنسى، فلا زرّ — والواجهة تقرأ device وحدها
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('device', null));
    }
}
