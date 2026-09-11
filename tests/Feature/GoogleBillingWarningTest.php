<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleBilling;
use App\Support\GoogleReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * تنبيهُ فوترة Google — لأنّ الصمتَ هنا يُطفئ الميزة عن الجميع.
 *
 * مفتاحُ المنصّة واحدٌ يخدم كلَّ التجّار. وتجربةُ Google تسعون يومًا ثمّ
 * يتوقّف المشروع ما لم يُرفع الحساب بيد صاحبه — Google لا تُحوّله من نفسها.
 *
 * ويومَ تنتهي لا يقول شيءٌ لماذا: يرى التاجر «رفضت Google المفتاح» فيظنّ
 * العطبَ عندنا، ويراه مديرُ المنصّة بعد أن يشكو أوّلُ تاجر. وبين اليومين
 * أسبوعٌ من ميزةٍ ميّتةٍ لا يعرف أحدٌ أنّها ماتت.
 */
class GoogleBillingWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->boss = User::create([
            'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function withKey(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => GoogleReviews::PLATFORM_KEY],
            ['value' => Crypt::encryptString('AIzaPLATFORM')],
        );
    }

    /* ==================== متى يُنبَّه ==================== */

    /**
     * بلا مفتاحٍ لا تنبيه.
     *
     * لا شيء يُحمى. وتحذيرٌ لا يقابل خطرًا يُقرأ مرّتين ثمّ يُتخطّى — ويمرّ
     * معه الصادقُ يومًا.
     */
    public function test_a_platform_without_a_key_is_not_nagged(): void
    {
        GoogleBilling::store(GoogleBilling::UNKNOWN, null);

        $this->assertNull(GoogleBilling::alert());
    }

    /** ومفتاحٌ بلا حالٍ مسجَّلة يُنبَّه — وهي الحالُ التي يُراد كشفُها */
    public function test_a_key_with_no_recorded_billing_is_warned_about(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::UNKNOWN, null);

        $alert = GoogleBilling::alert();

        $this->assertNotNull($alert, 'مفتاحٌ لا يعرف أحدٌ متى يموت ولا تنبيه');
        $this->assertSame('warning', $alert['level']);
        // والنصُّ يقول أيَّ حالٍ هي: «لم تُسجَّل» غير «تجربةٌ بلا موعد» وغير «انتهت»
        $this->assertStringContainsString('لم تُسجَّل حال فوترته', $alert['text']);
    }

    /** والحسابُ المرفوع لا يُنبَّه أبدًا — لا تجربةَ تنتهي */
    public function test_a_paid_account_is_never_warned_about(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::PAID, null);

        $this->assertNull(GoogleBilling::alert());
    }

    /** وتجربةٌ بعيدةٌ لا تُنبَّه — التنبيهُ المبكّر جدًّا ضجيج */
    public function test_a_far_trial_is_quiet(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(60)->toDateString());

        $this->assertNull(GoogleBilling::alert());
        $this->assertSame(60, GoogleBilling::daysLeft());
    }

    /**
     * وتُنبَّه قبل أسبوعين.
     *
     * لا يومًا واحدًا: رفعُ الحساب يحتاج بطاقةً تعمل وقد يحتاج مراجعةَ بنك،
     * ومن يكتشف أنّ بطاقته منتهيةٌ في اليوم الأخير لا يُدرك.
     */
    public function test_the_warning_starts_a_fortnight_before(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(GoogleBilling::WARN_DAYS)->toDateString());

        $alert = GoogleBilling::alert();

        $this->assertNotNull($alert, 'لم يُنبَّه عند حدّ الأسبوعين');
        $this->assertStringContainsString('14', $alert['text']);
    }

    /** ويشتدّ في الأيّام الأخيرة — لونٌ آخر لأنّ الوقت صار قصيرًا */
    public function test_the_last_days_are_louder(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(2)->toDateString());

        $this->assertSame('danger', GoogleBilling::alert()['level']);
    }

    /** وما مضى يُنبَّه أشدَّ ولا يصمت — الخطرُ وقع لا اقترب */
    public function test_an_expired_trial_keeps_shouting(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->subDays(9)->toDateString());

        $alert = GoogleBilling::alert();

        $this->assertNotNull($alert, 'تجربةٌ منتهيةٌ وصمت');
        $this->assertSame('danger', $alert['level']);
        // «انتهت» لا «تنتهي بعد -٩ يومًا»: الخطرُ وقع، ولا يُقرأ كأنّه اقترب
        $this->assertStringContainsString('انتهت تجربة Google', $alert['text']);
        $this->assertSame(-9, GoogleBilling::daysLeft());
    }

    /** و«تجربة» بلا موعدٍ حالٌ لا تُنبّه عن شيء — فتُطلب كتابةُ الموعد */
    public function test_a_trial_without_a_date_asks_for_one(): void
    {
        $this->withKey();
        // كتابةٌ مباشرةٌ في الجدول: البابُ نفسه يمنعها، والحارسُ يحرس ما وراء الباب
        Setting::updateOrCreate(['business_id' => null, 'key' => GoogleBilling::STATE_KEY], ['value' => GoogleBilling::TRIAL]);
        Setting::updateOrCreate(['business_id' => null, 'key' => GoogleBilling::ENDS_KEY], ['value' => '']);

        $alert = GoogleBilling::alert();

        $this->assertNotNull($alert, 'تجربةٌ بلا موعدٍ ولا تنبيه');
        $this->assertStringContainsString('ولا موعدَ انتهاءٍ مسجَّل', $alert['text']);
    }

    /* ==================== الباب ==================== */

    public function test_the_boss_records_the_trial_end(): void
    {
        $ends = now()->addDays(30)->toDateString();

        $this->actingAs($this->boss)
            ->post(route('super-admin.settings.googleBilling'), ['state' => 'trial', 'ends_at' => $ends])
            ->assertRedirect();

        $this->assertSame(GoogleBilling::TRIAL, GoogleBilling::state());
        $this->assertSame($ends, GoogleBilling::endsAt()?->toDateString());
    }

    /** و«تجربة» بلا موعدٍ تُرفض عند الباب */
    public function test_a_trial_without_a_date_is_refused(): void
    {
        $this->actingAs($this->boss)
            ->post(route('super-admin.settings.googleBilling'), ['state' => 'trial', 'ends_at' => ''])
            ->assertSessionHasErrors('ends_at');

        $this->assertSame(GoogleBilling::UNKNOWN, GoogleBilling::state());
    }

    /**
     * وحالٌ مخترَعةٌ تُرفض عند الباب — لا تُصحَّح بصمت.
     *
     * و`store` تُطبّع ما لا تعرفه إلى «لم يُحدَّد»، وهي شبكةُ أمانٍ أخيرة.
     * لكنّ التصحيحَ الصامت يعني أنّ من أرسل حالًا خاطئةً — شاشةٌ قديمةٌ في
     * تبويبٍ لم يُحدَّث، أو كتابةٌ بـcurl — يرى «حُفظت» ويمضي، والمحفوظُ
     * غيرُ ما أراد. فيُردّ عند الباب بخطأٍ يُقرأ.
     */
    public function test_an_invented_state_is_refused_at_the_door(): void
    {
        $this->actingAs($this->boss)
            ->post(route('super-admin.settings.googleBilling'), ['state' => 'free_forever'])
            ->assertSessionHasErrors('state');

        $this->assertSame(GoogleBilling::UNKNOWN, GoogleBilling::state());
    }

    /**
     * والرجوعُ إلى «مدفوع» يمحو الموعد.
     *
     * تاريخٌ باقٍ تحت «مدفوع» يعود يُنبّه يومَ يُعاد الحال إلى «تجربة» بخطأ
     * — فيقرأ الناظر موعدًا من سنةٍ مضت ويظنّه صادقًا.
     */
    public function test_going_paid_erases_the_date(): void
    {
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(5)->toDateString());

        /*
         * والتاريخُ يُرسَل مع الطلب عمدًا: الشاشةُ تُخفي الحقل تحت «مدفوع»
         * ولا تمحو ما فيه، فيصل مع النموذج. ومن يكتب بـcurl يرسله كيف شاء.
         */
        $this->actingAs($this->boss)
            ->post(route('super-admin.settings.googleBilling'), [
                'state' => 'paid',
                'ends_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertNull(GoogleBilling::endsAt(), 'بقي موعدٌ تحت حسابٍ مدفوع');
    }

    /** ولا يفتحه تاجر — هذه فوترةُ المنصّة كلّها */
    public function test_a_merchant_cannot_touch_it(): void
    {
        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('super-admin.settings.googleBilling'), ['state' => 'paid'])
            ->assertForbidden();
    }

    /* ==================== حيث يُقرأ ==================== */

    public function test_the_platform_screen_carries_the_alert(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(3)->toDateString());

        $this->actingAs($this->boss)->get(route('super-admin.settings.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('googleBilling.state', 'trial')
                ->where('googleBilling.days_left', 3)
                ->where('googleBilling.alert.level', 'danger')
                ->etc());
    }

    /** ولا يخرج المفتاح مع حال الفوترة */
    public function test_the_key_does_not_ride_along(): void
    {
        $this->withKey();
        GoogleBilling::store(GoogleBilling::TRIAL, now()->addDays(3)->toDateString());

        $this->actingAs($this->boss)->get(route('super-admin.settings.index'))
            ->assertOk()
            ->assertDontSee('AIzaPLATFORM');
    }

    /** ويُقال في كلّ نشر، لا في شاشةٍ تُفتح شهريًّا */
    public function test_the_deploy_check_says_it_too(): void
    {
        $source = file_get_contents(app_path('Console/Commands/Preflight.php'));

        $this->assertStringContainsString('GoogleBilling::alert()', $source, 'النشر لا يفحص فوترة Google');
    }
}
