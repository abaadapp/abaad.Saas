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
 * تبويب «رمز الموظف» لا يظهر إلا على جهازٍ يُعرف.
 *
 * كان زرًّا ثابتًا على كل متصفّح في العالم يفتح الرابط: بابٌ لا يعني الزائر،
 * يصل منه إلى شاشة أرقامٍ تسأله رمزًا لا يملكه — أو يجرّب.
 *
 * والجهاز في المحل واحد (الآيباد على الطاولة)، يُعرَف بعد أول دخولٍ ببريد
 * وكلمة مرور، فيصير له بابان: للمالك بريده وللكاشير رمزه.
 */
class LoginTabsTest extends TestCase
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

    public function test_a_fresh_browser_sees_no_pin_tab(): void
    {
        /*
         * متجرٌ واحد في القاعدة — وPosTerminal::businessId تسقط عليه تسهيلًا.
         * لو بُني عرض التبويب عليها لظهر هنا، على متصفّحٍ لم يُعرف بعد.
         */
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('pin', null));
    }

    public function test_the_pin_tab_appears_after_the_first_email_login(): void
    {
        $this->post(route('login.attempt'), [
            'email' => 'salem@abaad.om', 'password' => 'secret-pass',
        ])->assertSessionHasNoErrors();

        // أول دخولٍ يكتب كوكي المتجر — وهي أثر «هذا الجهاز يخصّ هذا المحل»
        $this->withCookie(PosTerminal::LEGACY_COOKIE, (string) $this->business->id)
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('pin.business', 'متجر الورد'));
    }

    public function test_an_activated_device_shows_its_branch_and_register(): void
    {
        $device = $this->activatePosDevice($this->business->id);

        // الموظف يعرف بنظرةٍ أنه على الجهاز الصحيح: رمزه مقيَّد بهذا الفرع
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page
                ->where('pin.business', 'متجر الورد')
                ->where('pin.branch', 'الخوير')
                ->where('pin.device', $device->name));
    }

    public function test_a_stale_cookie_pointing_nowhere_shows_no_tab(): void
    {
        // متجرٌ حُذف أو كوكي عُبث بها: لا تبويب بدل لوحة أرقامٍ بلا متجر خلفها
        $this->withCookie(PosTerminal::LEGACY_COOKIE, '99999')
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('pin', null));
    }

    public function test_the_standalone_pin_screen_still_exists(): void
    {
        // قفل نقطة البيع والخروج بالخمول يقودان إليها، فلا تُلغى مع التبويب
        $this->withCookie(PosTerminal::LEGACY_COOKIE, (string) $this->business->id)
            ->get(route('pin.form'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Pin'));
    }

    public function test_a_pin_sent_from_the_login_tab_answers_in_the_page_language(): void
    {
        $this->activatePosDevice($this->business->id);

        /*
         * شاشة الرمز المستقلّة إنجليزية دائمًا — يقف أمامها موظفون لا يقرؤون
         * العربية. أما التبويب فداخل صفحةٍ عربية بكاملها، فخطأٌ إنجليزي فيه
         * سطرٌ غريب وسط عربية.
         */
        session(['locale' => 'ar']);
        app()->setLocale('ar');

        $this->post(route('pin.attempt'), ['pin' => '9999', 'from' => 'login'])
            ->assertSessionHasErrors('pin');

        $errors = session('errors')->getBag('default')->get('pin');
        $this->assertSame('رمز غير صحيح أو غير مسموح في هذا الفرع.', $errors[0]);
    }

    public function test_a_pin_sent_from_the_standalone_screen_stays_english(): void
    {
        $this->activatePosDevice($this->business->id);
        session(['locale' => 'ar']);
        app()->setLocale('ar');

        $this->post(route('pin.attempt'), ['pin' => '9999'])
            ->assertSessionHasErrors('pin');

        $errors = session('errors')->getBag('default')->get('pin');
        $this->assertNotSame('رمز غير صحيح أو غير مسموح في هذا الفرع.', $errors[0]);
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
            ->assertInertia(fn ($page) => $page->where('pin.activated', false));

        $this->activatePosDevice($this->business->id);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('pin.activated', true));
    }

    public function test_there_is_no_forget_link_on_an_unknown_device(): void
    {
        // لا متجر يُنسى، فلا زرّ — والواجهة تقرأ pin وحدها
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('pin', null));
    }
}
