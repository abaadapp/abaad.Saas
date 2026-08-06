<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\PosDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * رمز الدخول يخصّ متجره.
 *
 * كان فريدًا على مستوى المنصة كلّها، والرموز عشرة آلاف لا غير. مئة متجرٍ
 * بعشرين موظفًا تشغل خُمس الفضاء، فتخمينٌ عشوائي واحد يصيب بنسبة واحدٍ من
 * خمسة — ويُدخل متجرًا ما، أيًّا كان. أي أن نجاح المنصة نفسه هو ما كان يفتح
 * الباب: كلّما زاد عملاؤك سهُل اقتحام أحدهم.
 *
 * وثمن الحصر أن الرمز وحده لم يعد يعرّف صاحبه، فالجهاز يتذكّر متجره.
 */
class PinScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('pin-login:127.0.0.1');
        RateLimiter::clear('pin-login-hour:127.0.0.1');

        $this->a = Business::create(['name' => 'متجر أ', 'type' => 'عام', 'status' => 'نشط']);
        $this->b = Business::create(['name' => 'متجر ب', 'type' => 'عام', 'status' => 'نشط']);

        foreach ([$this->a, $this->b] as $biz) {
            JobTitle::create(['business_id' => $biz->id, 'name' => 'كاشير', 'role' => 'cashier']);
        }

        $this->ownerA = $this->owner($this->a, 'a@abaad.om');
        $this->ownerB = $this->owner($this->b, 'b@abaad.om');
    }

    private function owner(Business $biz, string $email): User
    {
        return User::create([
            'business_id' => $biz->id, 'name' => 'مالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function addCashier(User $owner, string $email, string $pin)
    {
        return $this->actingAs($owner)->post(route('admin.employees.store'), [
            'name' => 'كاشير', 'email' => $email, 'job_title' => 'كاشير', 'pin' => $pin,
        ]);
    }

    /* --------------------- الرمز فريد داخل المتجر --------------------- */

    public function test_two_businesses_may_use_the_same_pin(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1234')->assertSessionHasNoErrors();
        $this->addCashier($this->ownerB, 'cb@abaad.om', '1234')->assertSessionHasNoErrors();

        $this->assertSame(2, User::where('email', 'like', 'c%@abaad.om')->count());
    }

    /** ولا يتكرّر داخل المتجر الواحد: الرمز لا يعود يعرّف أحدًا */
    public function test_the_same_pin_is_still_refused_inside_one_business(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1234');

        $this->addCashier($this->ownerA, 'ca2@abaad.om', '1234')
            ->assertSessionHasErrors('pin');
    }

    /* ------------------- الدخول محصورٌ في متجر الجهاز ------------------- */

    /** الجهاز يتذكّر متجره من أول دخولٍ بالبريد */
    public function test_signing_in_with_email_binds_the_device(): void
    {
        $this->post(route('login.attempt'), ['email' => 'a@abaad.om', 'password' => 'password'])
            ->assertCookie(PosDevice::COOKIE, (string) $this->a->id);
    }

    /**
     * ورمز متجر ب لا يفتح جهاز متجر أ.
     *
     * هذا هو العطب بعينه: كان البحث يمرّ على المستخدمين كلّهم، فيدخل صاحب
     * الرمز أيًّا كان متجره — دخولٌ إلى متجرٍ ليس متجرك.
     */
    public function test_a_neighbours_pin_does_not_open_this_device(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1111');
        $this->addCashier($this->ownerB, 'cb@abaad.om', '2222');
        $this->post(route('logout'));

        $this->withCookie(PosDevice::COOKIE, (string) $this->a->id)
            ->post(route('pin.attempt'), ['pin' => '2222'])
            ->assertSessionHasErrors('pin');

        $this->assertGuest();
    }

    /** ورمز متجرها يفتحه */
    public function test_its_own_pin_opens_it(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1111');
        $this->post(route('logout'));

        $this->withCookie(PosDevice::COOKIE, (string) $this->a->id)
            ->post(route('pin.attempt'), ['pin' => '1111'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->a->id, auth()->user()->business_id);
    }

    /**
     * والرمز نفسه على جهازين يفتح كلٌّ منهما متجره.
     *
     * الاختبار الحاسم: «1234» واحدة، وشخصان مختلفان.
     */
    public function test_the_same_pin_opens_a_different_person_on_each_device(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1234');
        $this->addCashier($this->ownerB, 'cb@abaad.om', '1234');
        $this->post(route('logout'));

        $this->withCookie(PosDevice::COOKIE, (string) $this->a->id)
            ->post(route('pin.attempt'), ['pin' => '1234']);
        $this->assertSame('ca@abaad.om', auth()->user()->email);

        $this->post(route('logout'));

        $this->withCookie(PosDevice::COOKIE, (string) $this->b->id)
            ->post(route('pin.attempt'), ['pin' => '1234']);
        $this->assertSame('cb@abaad.om', auth()->user()->email);
    }

    /** وجهازٌ غير مربوط في منصةٍ بها متاجر لا يقبل رمزًا */
    public function test_an_unbound_device_refuses_the_pin(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1111');
        $this->post(route('logout'));

        $this->post(route('pin.attempt'), ['pin' => '1111'])
            ->assertSessionHasErrors('pin');

        $this->assertGuest();
    }

    /**
     * وفي منصةٍ بمتجرٍ واحد يعمل بلا ربط.
     *
     * التركيبة المفردة لا يُخلط فيها بأحد، فلا معنى لأن تُطالَب بخطوةٍ
     * إضافية — والاشتراط عليها كان سيُعطّل كل تركيبٍ قائم اليوم.
     */
    public function test_a_single_business_platform_needs_no_binding(): void
    {
        User::where('business_id', $this->b->id)->delete();
        $this->b->delete();

        $this->addCashier($this->ownerA, 'ca@abaad.om', '1111');
        $this->post(route('logout'));

        $this->post(route('pin.attempt'), ['pin' => '1111'])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /** وشاشة الرمز تقول أي متجر تقرأ رموزه */
    public function test_the_pin_screen_names_its_business(): void
    {
        $this->withCookie(PosDevice::COOKIE, (string) $this->b->id)
            ->get(route('pin.form'))
            ->assertInertia(fn ($page) => $page->where('deviceBusiness', 'متجر ب'));
    }

    /* ------------------- البريد اختياري لمن يدخل بالرمز ------------------- */

    /**
     * موظفٌ بلا بريد: الكاشير يدخل برمزه.
     *
     * كان البريد إلزاميًّا وفريدًا على المنصة كلّها، فأوّل متجرين يريدان
     * `cashier@` يصطدمان — ويخترع الثاني بريدًا وهميًّا لا يقرأه أحد.
     */
    public function test_an_employee_may_have_no_email_at_all(): void
    {
        $this->actingAs($this->ownerA)->post(route('admin.employees.store'), [
            'name' => 'كاشير بلا بريد', 'job_title' => 'كاشير', 'pin' => '5555',
        ])->assertRedirect();

        $emp = User::where('name', 'كاشير بلا بريد')->first();
        $this->assertNotNull($emp, 'لم يُنشأ الموظف أصلًا');
        $this->assertNull($emp->email);
    }

    /** ومتجران يضيفان كاشيرَين بلا بريد بلا تصادم */
    public function test_two_businesses_may_both_add_emailless_staff(): void
    {
        $this->actingAs($this->ownerA)->post(route('admin.employees.store'), [
            'name' => 'كاشير أ', 'job_title' => 'كاشير', 'pin' => '5555',
        ])->assertRedirect();

        $this->actingAs($this->ownerB)->post(route('admin.employees.store'), [
            'name' => 'كاشير ب', 'job_title' => 'كاشير', 'pin' => '5555',
        ])->assertRedirect();

        $this->assertSame(2, User::whereNull('email')->count());
    }

    /**
     * ولا بريد ولا رمز: مرفوض.
     *
     * حسابٌ سليمٌ في القاعدة لا سبيل إلى الدخول به — يُحفظ بنجاح ثم يقف
     * صاحبه أمام شاشة الدخول ولا يجد بابًا.
     */
    public function test_an_employee_with_neither_email_nor_pin_is_refused(): void
    {
        $this->actingAs($this->ownerA)->post(route('admin.employees.store'), [
            'name' => 'بلا باب', 'job_title' => 'كاشير',
        ])->assertSessionHasErrors('pin');

        $this->assertNull(User::where('name', 'بلا باب')->first());
    }

    /** ولا يُمحى البريد عن موظفٍ لا رمز له */
    public function test_clearing_the_email_of_a_pinless_employee_is_refused(): void
    {
        $this->actingAs($this->ownerA)->post(route('admin.employees.store'), [
            'name' => 'ببريد', 'email' => 'x@abaad.om', 'job_title' => 'كاشير', 'pin' => '',
        ]);
        $emp = User::where('name', 'ببريد')->firstOrFail();

        $this->actingAs($this->ownerA)->put(route('admin.employees.update', $emp->id), [
            'name' => 'ببريد', 'email' => '', 'job_title' => 'كاشير',
        ])->assertSessionHasErrors('pin');

        $this->assertSame('x@abaad.om', $emp->fresh()->email);
    }

    /* ------------------ لا تخمين لمتجرٍ لمن لا متجر له ------------------ */

    /**
     * `Demo::bid()` كانت تُرجع أوّل متجرٍ في القاعدة لمن لا متجر له.
     *
     * وهو أخطر ما في الملف: لا يفشل، بل ينجح على بيانات شخصٍ آخر. أيّ مسارٍ
     * جديد يُكتب خارج حارس RequiresBusiness كان سيعرض متجر غيره بلا أن
     * يطلبه أحد، وبلا أي علامةٍ على الشاشة.
     */
    public function test_it_no_longer_guesses_a_business_for_the_businessless(): void
    {
        $this->assertSame(0, \App\Support\Demo::bid());

        $platformAdmin = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->actingAs($platformAdmin);

        $this->assertSame(0, \App\Support\Demo::bid(), 'خمّن متجرًا لمدير المنصة');
    }

    /** والصفر لا يطابق متجرًا، فتخرج الاستعلامات فارغة لا مليئة ببيانات غيرك */
    public function test_the_zero_fallback_returns_nothing(): void
    {
        \App\Models\Product::create([
            'business_id' => $this->a->id, 'name' => 'منتج أ', 'price' => 5,
            'quantity' => 1, 'active' => true,
        ]);

        $this->assertSame(0, \App\Models\Product::where('business_id', \App\Support\Demo::bid())->count());
    }

    /* --------------------------- حدّ المحاولات --------------------------- */

    /**
     * حدٌّ ساعيّ فوق الدقيقيّ.
     *
     * الدقيقيّ وحده يُبطئ ولا يمنع: من يصبر يجرّب سبعة آلاف رمز في اليوم،
     * وهو أكثر من فضاء الرموز كلّه.
     */
    public function test_repeated_failures_lock_out_for_the_hour(): void
    {
        $this->addCashier($this->ownerA, 'ca@abaad.om', '1111');
        $this->post(route('logout'));

        for ($i = 0; $i < 31; $i++) {
            RateLimiter::clear('pin-login:127.0.0.1');
            $this->withCookie(PosDevice::COOKIE, (string) $this->a->id)
                ->post(route('pin.attempt'), ['pin' => '0000']);
        }

        // الرمز الصحيح لا يُقبل بعد الحدّ الساعيّ
        $this->withCookie(PosDevice::COOKIE, (string) $this->a->id)
            ->post(route('pin.attempt'), ['pin' => '1111'])
            ->assertSessionHasErrors('pin');

        $this->assertGuest();
    }
}
