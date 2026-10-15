<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Signup;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * من سجّل بنفسه يبدأ على الأساسيّة أربعةَ عشر يومًا — ويُختم بابُه.
 *
 * ═══ ما كان ═══
 *
 * التسجيلُ الذاتيّ يقرأ إعدادَي المنصّة اللذين يقرؤهما زرُّ «إضافة شركة»:
 * `default_plan` بالاسم (وافتراضيُّه فارغ — فيُفتح المتجرُ بلا باقة) و
 * `trial_days`. فمن ضبطهما لمن يُضيفه بيده غيّر عقدَ كلِّ من يسجّل بنفسه.
 * ولا شيء في صفّ الشركة يقول أيُّ البابين فتحها.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. المسجِّلُ بنفسه: شركةٌ واحدة، مختومةٌ `self_signup`، على الباقة
 *     الأساسيّة **برمزها**، تجربتُها أربعة عشر يومًا بقراءة `Tenancy`.
 *  ٢. الإعدادان لا يمسّانه، وتغييرُ اسم الأساسيّة لا يمسّه.
 *  ٣. بلا باقةٍ برمز `basic`: لا شركةَ ولا مالك — رفضٌ صريح.
 *  ٤. زرُّ المنصّة كما كان (باقتُه ومدّتُه من الإعدادين) ومختومٌ
 *     `platform_manual` — ولا يُختم من الطلب.
 *  ٥. القديمُ يبقى فارغًا، والتثبيتُ الأوّل للرمز لا يخمّن.
 *  ٦. لا CRM ولا بريد ولا طابور ولا اتّصالٌ خارجيّ.
 */
class ASelfSignupStartsOnTheBasicPlanForFourteenDaysTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');

        $this->basic = Plan::create(['name' => 'الباقة الأساسية', 'code' => Plan::CODE_BASIC, 'monthly_price' => 9.9, 'yearly_price' => 99]);
        $this->pro = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299]);

        // إعداداتُ المنصّة لزرّ «إضافة شركة» — لا تخصّ من يسجّل بنفسه
        $this->platform('default_plan', 'الباقة الاحترافية');
        $this->platform('trial_days', '30');
    }

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'سعود الهاشمي',
            'phone' => '+96891234567',
            'type' => 'محل ورود',
            'shop' => 'Ribbon Flowers',
            'team_size' => '2–5 موظفين',
            'address' => 'مسقط — القرم',
            'email' => 'saud@example.com',
            'password' => 'abaad2026',
        ];
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'مدير المنصة', 'email' => 'boss@abaad.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* ═══════════════ ١ · المسجِّلُ بنفسه ═══════════════ */

    public function test_a_signup_opens_one_business_stamped_self_signup_on_basic_for_fourteen_days(): void
    {
        $this->post(route('register.store'), $this->form())->assertRedirect(route('admin.setup.index'));

        $this->assertSame(1, Business::count(), 'شركةٌ واحدة لا أكثر');
        $business = Business::firstOrFail();

        $this->assertSame(Business::SOURCE_SELF_SIGNUP, $business->registration_source);
        $this->assertSame($this->basic->id, (int) $business->plan_id, 'الأساسيّةُ برمزها — لا `default_plan`');

        // أربعة عشر يومًا من يوم التسجيل — لا `trial_days` (ثلاثون)
        $this->assertSame(now()->toDateString(), $business->starts_at->toDateString());
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $business->ends_at->toDateString());
        $this->assertSame(14, Tenancy::daysLeft($business));

        // والمالكُ نفسُه كما كان، وحجمُ الفريق إعدادٌ كما كان
        $owner = User::where('business_id', $business->id)->firstOrFail();
        $this->assertSame('admin', $owner->role);
        $this->assertSame('saud@example.com', $owner->email);
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('2–5 موظفين', Setting::where('business_id', $business->id)->where('key', Signup::TEAM_SIZE)->value('value'));
        $this->assertNotNull($owner->fresh()->last_login_at, 'دخولُ التسجيل يُختم كدخول كلمة المرور');

        // وسطرُ السجلّ كما كان — وهو ما يعدّه اختبارُ الرفض صفرًا
        $this->assertSame(1, ActivityLog::where('description', 'like', 'سجّل متجرًا جديدًا%')->count());
    }

    /** واليومُ الرابعَ عشرَ يعمل كاملًا، ويُقفل بعده — بقراءة `Tenancy` نفسِها */
    public function test_the_trial_ends_where_tenancy_says_every_trial_ends(): void
    {
        $this->post(route('register.store'), $this->form());
        $business = Business::firstOrFail();

        $this->travelTo(now()->addDays(14)->setTime(23, 0));
        $this->assertFalse(Tenancy::expired($business->fresh()), 'يومُ الانتهاء نفسُه مفتوح');
        $this->assertSame(0, Tenancy::daysLeft($business->fresh()));

        $this->travelTo(now()->addDay()->startOfDay()->addHour());
        $this->assertTrue(Tenancy::expired($business->fresh()));
    }

    public function test_renaming_the_basic_plan_does_not_move_new_signups(): void
    {
        $this->basic->update(['name' => 'باقة البداية']);
        // واسمُها القديم على باقةٍ أخرى لا رمزَ لها — فلا يُقرأ الاسم
        $decoy = Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 49, 'yearly_price' => 490]);

        $this->post(route('register.store'), $this->form());

        $this->assertSame($this->basic->id, (int) Business::firstOrFail()->plan_id);
        $this->assertNotSame($decoy->id, (int) Business::firstOrFail()->plan_id);
    }

    public function test_without_a_basic_code_nothing_is_opened(): void
    {
        $this->basic->update(['code' => null]);
        $users = User::count();

        $this->post(route('register.store'), $this->form())->assertSessionHasErrors('signup');

        $this->assertSame(0, Business::count(), 'لا شركةَ على باقةٍ غير مؤكَّدة');
        $this->assertSame($users, User::count(), 'ولا مالك');
        $this->assertGuest();
        $this->assertSame(0, ActivityLog::where('description', 'like', 'سجّل متجرًا جديدًا%')->count());
    }

    /** والشاشةُ تقول التجربةَ من العقد — وتسكت حين لا باقةَ مؤكَّدة */
    public function test_the_screen_announces_the_trial_from_the_contract(): void
    {
        $this->get(route('register'))->assertInertia(fn ($page) => $page
            ->where('trial.days', 14)
            ->where('trial.plan', 'الباقة الأساسية'));

        $this->basic->update(['code' => null]);

        $this->get(route('register'))->assertInertia(fn ($page) => $page->where('trial.plan', null));
    }

    /** ولا يُختم البابُ من الطلب: حقلٌ يُرسَل بيدٍ لا يُكتب */
    public function test_a_signup_cannot_stamp_itself_otherwise(): void
    {
        $this->post(route('register.store'), $this->form([
            'registration_source' => Business::SOURCE_PLATFORM_MANUAL,
            'plan_id' => $this->pro->id,
        ]));

        $business = Business::firstOrFail();
        $this->assertSame(Business::SOURCE_SELF_SIGNUP, $business->registration_source);
        $this->assertSame($this->basic->id, (int) $business->plan_id);
    }

    /* ═══════════════ ٢ · زرُّ المنصّة كما كان ═══════════════ */

    public function test_a_platform_created_business_keeps_its_own_plan_and_trial_and_is_stamped_manual(): void
    {
        $this->actingAs($this->superAdmin())->post(route('super-admin.businesses.store'), [
            'name' => 'متجر يدوي', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'manualshop', 'login_password' => 'secret123',
            // ولا يُختم من الطلب هنا أيضًا
            'registration_source' => Business::SOURCE_SELF_SIGNUP,
        ])->assertRedirect(route('super-admin.businesses.index'));

        $business = Business::where('name', 'متجر يدوي')->firstOrFail();

        $this->assertSame(Business::SOURCE_PLATFORM_MANUAL, $business->registration_source);
        // باقتُه ومدّتُه من الإعدادين كما كانتا — لا الأساسيّة ولا الأربعة عشر
        $this->assertSame($this->pro->id, (int) $business->plan_id);
        $this->assertSame(now()->addDays(30)->toDateString(), $business->ends_at->toDateString());
    }

    public function test_editing_a_business_does_not_restamp_it(): void
    {
        $this->post(route('register.store'), $this->form());
        $business = Business::firstOrFail();
        $this->post(route('logout'));

        $this->actingAs($this->superAdmin())->put(route('super-admin.businesses.update', $business->id), [
            'name' => $business->name, 'type' => $business->type, 'status' => 'نشط',
            'registration_source' => Business::SOURCE_PLATFORM_MANUAL,
        ]);

        $this->assertSame(Business::SOURCE_SELF_SIGNUP, $business->fresh()->registration_source);
    }

    /* ═══════════════ ٣ · القديمُ والتثبيتُ الأوّل ═══════════════ */

    public function test_a_business_from_before_the_stamp_stays_unstamped(): void
    {
        $legacy = Business::create(['name' => 'متجر قديم', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertNull($legacy->fresh()->registration_source);
        $this->assertSame(0, Business::selfSignup()->count());
    }

    /**
     * التثبيتُ الأوّل من الاسم — بشرط أن تكون واحدةً بالضبط.
     *
     * يُعاد الترحيلُ على باقاتٍ قائمة كما يقع على الإنتاج: واحدةٌ ← يُكتب
     * الرمز؛ لا شيء أو اثنتان ← يبقى فارغًا، ولا يُختار سعرٌ أدنى ولا أوّلُ رقم.
     */
    public function test_the_first_code_is_written_only_when_exactly_one_plan_carries_the_basic_name(): void
    {
        $migration = require database_path('migrations/2026_10_15_090100_a_plan_has_a_code_that_its_name_cannot_change.php');

        $cases = [
            'واحدة' => [['الباقة الأساسية', 9.9], ['الباقة الاحترافية', 29.9]],
            'لا شيء' => [['باقة البداية', 1.0], ['الباقة الاحترافية', 29.9]],
            'اثنتان' => [['الباقة الأساسية', 9.9], [' الباقة الأساسية ', 5.0]],
        ];

        foreach ($cases as $case => $plans) {
            $migration->down();
            DB::table('businesses')->update(['plan_id' => null]);
            DB::table('plans')->delete();

            $ids = [];
            foreach ($plans as [$name, $price]) {
                $ids[] = DB::table('plans')->insertGetId(['name' => $name, 'monthly_price' => $price, 'yearly_price' => $price * 10, 'created_at' => now(), 'updated_at' => now()]);
            }

            $migration->up();

            $coded = DB::table('plans')->whereNotNull('code')->pluck('code', 'id')->all();

            match ($case) {
                'واحدة' => $this->assertSame([$ids[0] => Plan::CODE_BASIC], $coded, $case),
                default => $this->assertSame([], $coded, "{$case}: لا تخمين"),
            };
        }
    }

    /* ═══════════════ ٤ · لا CRM ولا اتّصالٌ خارجيّ ═══════════════ */

    public function test_a_signup_writes_nothing_to_crm_and_sends_nothing_outside(): void
    {
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::fake();

        $this->post(route('register.store'), $this->form())->assertRedirect();

        $this->assertSame(1, Business::count());
        $this->assertSame(0, CrmLead::count(), 'المسجِّلُ شركةٌ لا عميلٌ محتمل');
        $this->assertSame(0, CrmNote::count());

        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
