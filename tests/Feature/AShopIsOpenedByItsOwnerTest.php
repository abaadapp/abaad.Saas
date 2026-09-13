<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Support\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * التاجرُ يفتح متجرَه بنفسه — وكان لا بابَ له إلّا مكالمة.
 *
 * ═══ ما كان قبل هذا الملفّ ═══
 *
 * لا تسجيلَ عامَّ في النظام إطلاقًا: `Business::create` لا تُستدعى إلّا من
 * لوحة المنصّة ومن بذرة الديمو. فمن أراد أبعادًا يتّصل، ويُنشئ له المشغّلُ
 * متجرًا بيده ويسلّمه اسمَ مستخدمٍ شفاهًا.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. أنّ التسجيل يُنشئ **الثلاثة**: متجرًا ومالكًا وتصنيفاتِ بداية.
 *  ٢. أنّها تقع مرّةً واحدة — لا متجرين من ضغطتين.
 *  ٣. أنّ النوعَ من سجلّ الأنواع لا من نصٍّ يُرسَل.
 *  ٤. أنّ المسجِّل يدخل فورًا ولا يُردّ إلى بابٍ يكتب فيه ما كتبه للتوّ.
 *  ٥. أنّ الدخولَ القديم لم ينكسر.
 */
class AShopIsOpenedByItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');
    }

    /** بيانٌ كاملٌ صالح — وما يفترق بين اختبارٍ وآخر يُمرَّر */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'سالم بن راشد',
            'phone' => '+96891234567',
            'type' => 'محل ورود',
            'shop' => 'زهور الخوير',
            'team_size' => '2–5 موظفين',
            'address' => 'مسقط — الخوير',
            'email' => 'salem@example.com',
            'password' => 'abaad2026',
        ];
    }

    /* ═══════════════ الثلاثةُ معًا ═══════════════ */

    /**
     * التسجيلُ يُنشئ متجرًا ومالكًا وتصنيفاتِ بداية — ويدخل صاحبَه.
     *
     * والتصنيفاتُ ليست زينة: متجرٌ يفتح لوحتَه على صفحةٍ بيضاء لا يعرف من
     * أين يبدأ، فيُبذَر له ما يخصّ نشاطه.
     */
    public function test_registering_opens_a_shop_an_owner_and_a_shelf(): void
    {
        $this->post(route('register.store'), $this->form())
            ->assertRedirect(route('admin.setup.index'));

        $business = Business::firstOrFail();

        $this->assertSame('زهور الخوير', $business->name);
        $this->assertSame('محل ورود', $business->type);
        $this->assertSame('سالم بن راشد', $business->owner_name);
        $this->assertSame('مسقط — الخوير', $business->address);

        $owner = User::firstOrFail();

        $this->assertSame('salem@example.com', $owner->email);
        $this->assertSame('admin', $owner->role);
        $this->assertSame((int) $business->id, (int) $owner->business_id);
        $this->assertNotSame('abaad2026', $owner->password, 'كلمةُ المرور محفوظةٌ كما كُتبت');

        // تصنيفاتُ «محل ورود» — من `BusinessTypes` لا من قائمةٍ هنا
        $this->assertGreaterThan(0, Category::where('business_id', $business->id)->count());

        /* ويدخل فورًا: لا يُردّ إلى بابٍ يكتب فيه ما كتبه للتوّ */
        $this->assertAuthenticatedAs($owner);
    }

    /** والتجربةُ تبدأ وتنتهي: متجرٌ بلا تاريخٍ يعمل إلى الأبد */
    public function test_a_new_shop_starts_a_trial_that_ends(): void
    {
        Setting::create(['business_id' => null, 'key' => 'trial_days', 'value' => '14']);

        $this->post(route('register.store'), $this->form());

        $business = Business::firstOrFail();

        $this->assertNotNull($business->starts_at);
        $this->assertNotNull($business->ends_at, 'متجرٌ بلا تاريخ انتهاء — لا تجربةَ تنتهي ولا مطالبةَ تحلّ');
        $this->assertSame(14, (int) $business->starts_at->diffInDays($business->ends_at));
    }

    /* ═══════════════ ولا متجرَ يُفتح مرّتين ═══════════════ */

    /**
     * ضغطتان على «ابدأ» لا تفتحان متجرين.
     *
     * والحارسُ قيدُ القاعدة لا فحصٌ في الشاشة: `users.email` فريد، فالطلبُ
     * الثاني يُردّ قبل أن يُكتب شيء. وزرٌّ يُعطَّل في المتصفّح لا يحرس من
     * شبكةٍ بطيئةٍ أرسلت الطلبَ مرّتين.
     */
    public function test_a_second_press_does_not_open_a_second_shop(): void
    {
        $this->post(route('register.store'), $this->form());

        /*
         * والثانيةُ من زائر: من سجّل صار داخلًا، وحارسُ `guest` يردّه قبل
         * التحقّق — فلا يُختبر قيدُ البريد أصلًا. والقيدُ هو المحروس هنا.
         */
        $this->post(route('logout'));

        $this->post(route('register.store'), $this->form())->assertSessionHasErrors('email');

        $this->assertSame(1, Business::count(), 'فُتح متجرٌ ثانٍ');
        $this->assertSame(1, User::count());
    }

    /** وبريدٌ مسجَّلٌ من قبل يُردّ بجوابٍ يقول ماذا يفعل */
    public function test_a_known_email_is_sent_to_the_door_it_already_has(): void
    {
        $this->post(route('register.store'), $this->form());
        $this->post(route('logout'));

        $this->post(route('register.store'), $this->form(['shop' => 'محلٌّ آخر']))
            ->assertSessionHasErrors(['email' => 'هذا البريد مسجَّل من قبل — سجّل الدخول به.']);
    }

    /* ═══════════════ ولا شيءَ يُكتب حين يسقط شيء ═══════════════ */

    /**
     * بيانٌ ناقصٌ لا يترك متجرًا نصفَ مبنيّ.
     *
     * والمعاملةُ هي الحارس: متجرٌ بلا مالكٍ بابٌ لا يُفتح أبدًا ولا يعرف به
     * أحد، ومالكٌ بلا متجرٍ حسابٌ يدخل إلى لا شيء.
     */
    public function test_a_refused_signup_leaves_nothing_behind(): void
    {
        $this->post(route('register.store'), $this->form(['password' => 'short']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Business::count());
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /**
     * والنشاطُ من سجلّه — لا نصًّا يُرسَل بيد.
     *
     * نوعٌ لا تعرفه `BusinessTypes::provision` يخرج بمتجرٍ على تصنيفاتٍ
     * عامّةٍ لا تخصّ شيئًا، والعمودُ يحمل كلمةً لا يقرؤها شيءٌ في النظام.
     */
    public function test_an_activity_outside_the_registry_is_refused(): void
    {
        $this->post(route('register.store'), $this->form(['type' => 'مصنع طائرات']))
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Business::count());
    }

    /** وكلمةُ المرور حرفٌ ورقمٌ وثمانيةٌ — كما تقوله الشاشة حرفًا بحرف */
    public function test_the_password_rules_the_screen_announces_are_the_rules_enforced(): void
    {
        foreach (['abaad123' => false, 'abaadabaad' => true, '12345678' => true, 'ab12' => true] as $password => $bad) {
            // من نجح صار داخلًا، وحارسُ `guest` يردّ التالية قبل التحقّق
            $this->post(route('logout'));
            RateLimiter::clear('signup:127.0.0.1');

            $response = $this->post(route('register.store'), $this->form([
                'email' => 'p'.random_int(1000, 99999).'@example.com',
                'password' => $password,
            ]));

            $bad
                ? $response->assertSessionHasErrors('password')
                : $response->assertSessionHasNoErrors();
        }
    }

    /* ═══════════════ وحجمُ الفريق إعدادٌ لا عمود ═══════════════ */

    /**
     * حجمُ الفريق يُحفظ مفتاحًا في `settings` — لا عمودًا في `businesses`.
     *
     * لا يقرؤه شيءٌ في المنتج اليوم، وعمودٌ لحقلٍ لا يقرؤه شيءٌ دَينٌ على
     * الجدول. و`settings` يقبله بلا تغييرِ بنية، فإن صار له استعمالٌ يومًا
     * كان محفوظًا من أوّل يوم.
     */
    public function test_the_team_size_is_a_setting_not_a_column(): void
    {
        $this->post(route('register.store'), $this->form());

        $business = Business::firstOrFail();

        $this->assertSame('2–5 موظفين', Setting::where('business_id', $business->id)
            ->where('key', Signup::TEAM_SIZE)->value('value'));

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('businesses', 'team_size'),
            'أُضيف عمودٌ لحقلٍ لا يقرؤه شيء',
        );
    }

    /** وهو اختياريٌّ: من تخطّى الخطوة يفتح متجرَه كما يفتحه من أجابها */
    public function test_a_skipped_step_does_not_hold_the_door(): void
    {
        $this->post(route('register.store'), $this->form(['team_size' => '', 'address' => '']))
            ->assertRedirect(route('admin.setup.index'));

        $this->assertSame(1, Business::count());
        $this->assertNull(Business::firstOrFail()->address);
    }

    /* ═══════════════ والبابُ يُقفل ويُخنق ═══════════════ */

    /**
     * بابٌ مقفلٌ من إعدادات المنصّة يردّ ٤٠٤ — عرضًا وإرسالًا معًا.
     *
     * وإخفاءُ الرابط وحده ليس إقفالًا: من يعرف العنوان يكتبه.
     */
    public function test_a_closed_door_is_closed_from_both_sides(): void
    {
        Setting::create(['business_id' => null, 'key' => Signup::SWITCH, 'value' => '0']);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), $this->form())->assertNotFound();

        $this->assertSame(0, Business::count());
    }

    /**
     * ويُخنق: بابٌ يكتب في القاعدة بلا حسابٍ سابق أسهلُ ما يُستنزف.
     *
     * سكربتٌ يفتح ألفَ متجرٍ في دقيقة — والمفتاحُ العنوانُ وحده، إذ يبدّل
     * من يُغرق البابَ البريدَ في كلّ محاولة.
     */
    public function test_the_door_is_throttled_by_address_not_by_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->post(route('register.store'), $this->form([
                'email' => "flood{$i}@example.com",
                'password' => 'x',
            ]));
        }

        $this->flushSession();
        $this->post(route('register.store'), $this->form(['email' => 'flood9@example.com']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Business::count(), 'مرّ طلبٌ بعد الخنق');
    }

    /* ═══════════════ ولم ينكسر ما كان يعمل ═══════════════ */

    /**
     * والدخولُ بالحساب الجديد يعمل — ومعه الخروجُ ثمّ الدخول.
     *
     * حسابٌ يُنشأ ولا يُدخَل به أسوأُ من حسابٍ لا يُنشأ: صاحبُه يظنّ أنّه
     * سجّل، ويقف أمام بابٍ يرفض بياناته الصحيحة.
     */
    public function test_the_new_account_can_log_out_and_back_in(): void
    {
        $this->post(route('register.store'), $this->form());
        $this->assertAuthenticated();

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.attempt'), [
            'email' => 'salem@example.com',
            'password' => 'abaad2026',
        ])->assertRedirect();

        $this->assertAuthenticatedAs(User::firstOrFail());
    }

    /** ومن دخل لا يفتح معالجَ تسجيلٍ ثانٍ فيخرج بمتجرٍ لا يقصده */
    public function test_a_signed_in_merchant_is_not_offered_a_second_shop(): void
    {
        $this->post(route('register.store'), $this->form());

        $this->get(route('register'))->assertRedirect();
        $this->post(route('register.store'), $this->form(['email' => 'other@example.com']))->assertRedirect();

        $this->assertSame(1, Business::count());
    }
}
