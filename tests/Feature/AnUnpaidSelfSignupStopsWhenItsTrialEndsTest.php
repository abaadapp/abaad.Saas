<?php

namespace Tests\Feature;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Billing;
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
 * تجربةٌ ذاتيّةٌ لم تُدفع تقف حيث تنتهي — بلا مهلة.
 *
 * ═══ ما كان ═══
 *
 * `Tenancy::locksAt` يضيف مهلةَ المنصّة (`grace_days`، سبعةٌ افتراضيًّا) إلى
 * كلّ متجر. والمهلةُ لعميلٍ يدفع كي لا يقف صندوقُه لتأخّر حوالة — فمن فتح
 * متجره من `/register` بأربعة عشر يومًا مجّانًا كان يعمل واحدًا وعشرين.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. التجربةُ أربعةَ عشر يومًا كما كانت، ويومُ الانتهاء نفسُه يعمل
 *     (`Tenancy::expired`). وفي اليوم الذي يليه يُقفل — ولو كانت المهلةُ
 *     العامّة سبعةً أو ثلاثين.
 *  ٢. يدخل ببريده وكلمة مروره ويُحجز عند صفحة التجديد: لا لوحة ولا صندوق،
 *     ولا يُطرد، ولا يُعطَّل متجرُه ولا يُمحى منه شيء.
 *  ٣. من سجّل بنفسه ثمّ سُدِّد له اشتراكٌ صار عميلًا يدفع: مهلتُه مهلةُ
 *     العملاء. وحزمةُ رسائلَ مدفوعة ليست اشتراكًا مدفوعًا.
 *  ٤. اليدويّ والقديم والتجريبيّ، و`auto_suspend`، والدخولُ باسم التاجر —
 *     كما كانت بحرفها.
 */
class AnUnpaidSelfSignupStopsWhenItsTrialEndsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');

        Plan::create(['name' => 'الباقة الأساسية', 'code' => Plan::CODE_BASIC, 'monthly_price' => 9.9, 'yearly_price' => 99]);
        $this->platform('grace_days', '7');
    }

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    /** تسجيلٌ ذاتيّ من الباب نفسِه الذي يُرسل إليه المعالج — ثمّ خروج */
    private function signup(): User
    {
        $this->post(route('register.store'), [
            'name' => 'سعود الهاشمي', 'phone' => '+96891234567', 'type' => 'محل ورود',
            'shop' => 'Ribbon Flowers', 'email' => 'saud@example.com', 'password' => 'abaad2026',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        return User::where('email', 'saud@example.com')->firstOrFail();
    }

    /** متجرٌ بلا تسجيلٍ ذاتيّ — يدويٌّ أو قديم — انتهى منذ :ago يومًا */
    private function shop(?string $source, int $ago): User
    {
        $b = Business::create([
            'name' => 'متجر الورود', 'type' => 'عام', 'status' => 'نشط', 'email' => 'shop@abaadapp.om',
            'ends_at' => now()->subDays($ago)->toDateString(), 'registration_source' => $source,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'owner-'.$b->id.'@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** في اليوم الذي يُعدّ من يوم التسجيل */
    private function onDay(int $day): void
    {
        $this->travelTo(today()->addDays($day)->setTime(10, 0));
    }

    /* ═══════════════ ١ · التجربةُ تقف حيث تنتهي ═══════════════ */

    public function test_an_unpaid_self_signup_works_through_its_last_trial_day_and_stops_the_next_day(): void
    {
        $start = today();
        $owner = $this->signup();
        $business = $owner->business;

        $this->assertSame($start->copy()->addDays(Signup::TRIAL_DAYS)->toDateString(), $business->ends_at->toDateString(), 'التجربةُ أربعة عشر يومًا كما كانت');
        $this->assertSame(Business::SOURCE_SELF_SIGNUP, $business->registration_source);
        $this->assertSame(7, Tenancy::graceDays(), 'المهلةُ العامّة لم تُمسّ');
        $this->assertSame(0, Tenancy::graceDaysFor($business));

        /* يومُ الانتهاء نفسُه يعمل — كما تقول `expired` */
        $this->onDay(14);
        $this->assertFalse(Tenancy::expired($business->fresh()));
        $this->assertFalse(Tenancy::locked($business->fresh()));
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();

        /* والذي يليه مقفل — لا سبعةُ أيّامٍ بعده */
        $this->onDay(15);
        $fresh = $business->fresh();
        $this->assertTrue(Tenancy::expired($fresh));
        $this->assertTrue(Tenancy::locked($fresh));
        $this->assertTrue(Tenancy::locksAt($fresh)->equalTo($fresh->ends_at->copy()->endOfDay()), 'الإقفالُ عند نهاية يوم الانتهاء');
        $this->assertSame(0, Tenancy::graceLeft($fresh), 'لا «بقي سبعة أيّام» لمن هو مقفل');
        $this->assertSame(Tenancy::SUBSCRIPTION_EXPIRED, Tenancy::blockReason($owner->fresh()));

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
        $this->actingAs($owner)->get(route('pos.index'))->assertRedirect(route('subscription.expired'));

        /* حجزٌ لا طرد ولا تعطيل ولا محو */
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('نشط', $fresh->status);
        $this->assertNotNull(Business::find($business->id));
    }

    public function test_a_self_signup_in_its_trial_does_not_ask_the_invoices_on_every_request(): void
    {
        /* الحارسُ يسأل `locked` مع كلّ طلب — وما لم ينتهِ لا تُسأل فواتيرُه */
        $business = $this->signup()->business->fresh();

        DB::enableQueryLog();
        $this->assertFalse(Tenancy::locked($business));
        $asked = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"invoices"'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $asked);
    }

    public function test_a_longer_platform_grace_still_does_not_reach_an_unpaid_self_signup(): void
    {
        $owner = $this->signup();
        $this->platform('grace_days', '30');

        $this->onDay(15);

        $this->assertSame(30, Tenancy::graceDays());
        $this->assertTrue(Tenancy::locked($owner->business->fresh()));
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
    }

    /* ═══════════════ ٢ · يدخل ويُحجز عند صفحة التجديد ═══════════════ */

    public function test_an_expired_self_signup_signs_in_and_is_held_at_the_renewal_page(): void
    {
        Mail::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        $this->signup();
        $invoices = Invoice::count();

        $this->onDay(15);

        $this->post(route('login.attempt'), ['email' => 'saud@example.com', 'password' => 'abaad2026'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();

        $this->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
        $this->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/SubscriptionExpired')->where('business', 'Ribbon Flowers'));
        $this->assertAuthenticated();

        /* ولا شيءَ يُكتب أو يُرسَل لأنّه أُقفل: لا CRM ولا فاتورة ولا بريد ولا طابور */
        $this->assertSame(0, CrmLead::count());
        $this->assertSame($invoices, Invoice::count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    /* ═══════════════ ٣ · من دفع صار عميلًا يدفع ═══════════════ */

    public function test_a_self_signup_whose_subscription_was_paid_gets_the_platform_grace_when_it_ends(): void
    {
        $owner = $this->signup();
        $business = $owner->business;

        /* انتهت تجربتُه، فجدّد له مديرُ المنصّة — والفاتورةُ لم تُسدَّد بعد */
        $this->onDay(15);
        ['invoice' => $invoice] = Billing::renew($business->fresh(), 'monthly');
        $this->assertSame(0, Tenancy::graceDaysFor($business->fresh()), 'فاتورةٌ صدرت ولم تُسدَّد ليست دفعًا');

        Billing::markPaid($invoice);
        $paid = $business->fresh();
        $this->assertTrue(Billing::hasPaidSubscription($paid));
        $this->assertSame(7, Tenancy::graceDaysFor($paid));

        /*
         * وحين ينتهي اشتراكُه المدفوع يعمل في مهلة العملاء — ثمّ يُقفل بعدها.
         * والمالكُ يُقرأ من جديد كما يُقرأ في كلّ طلب: `$owner->business` ما
         * زال يحمل تاريخ التجربة من قبل التجديد.
         */
        $ends = $paid->ends_at->copy();
        $this->travelTo($ends->copy()->addDays(3)->setTime(10, 0));
        $this->assertFalse(Tenancy::locked($paid->fresh()));
        $this->assertSame(4, Tenancy::graceLeft($paid->fresh()));
        $this->actingAs($owner->fresh())->get(route('admin.dashboard'))->assertOk();

        $this->travelTo($ends->copy()->addDays(8)->setTime(10, 0));
        $this->assertTrue(Tenancy::locked($paid->fresh()));
        $this->actingAs($owner->fresh())->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
    }

    public function test_a_paid_messages_pack_is_not_a_paid_subscription(): void
    {
        $owner = $this->signup();
        Invoice::create([
            'number' => 'INV-P-0001', 'business_id' => $owner->business_id, 'amount' => 5,
            'issued_at' => now(), 'status' => Billing::PAID, 'kind' => Billing::KIND_MESSAGES,
        ]);

        $this->onDay(15);

        $this->assertFalse(Billing::hasPaidSubscription($owner->business->fresh()));
        $this->assertTrue(Tenancy::locked($owner->business->fresh()));
    }

    /* ═══════════════ ٤ · وما سواه كما كان ═══════════════ */

    public function test_a_manual_business_keeps_the_platform_grace(): void
    {
        $working = $this->shop(Business::SOURCE_PLATFORM_MANUAL, 3);
        $this->assertSame(7, Tenancy::graceDaysFor($working->business));
        $this->assertSame(4, Tenancy::graceLeft($working->business));
        $this->actingAs($working)->get(route('admin.dashboard'))->assertOk();

        $locked = $this->shop(Business::SOURCE_PLATFORM_MANUAL, 9);
        $this->actingAs($locked)->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
    }

    public function test_a_legacy_business_without_a_source_keeps_the_platform_grace(): void
    {
        $working = $this->shop(null, 3);
        $this->assertSame(7, Tenancy::graceDaysFor($working->business));
        $this->actingAs($working)->get(route('admin.dashboard'))->assertOk();

        $locked = $this->shop(null, 9);
        $this->actingAs($locked)->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));
    }

    public function test_a_demo_business_keeps_the_platform_grace(): void
    {
        $owner = $this->shop(Business::SOURCE_SELF_SIGNUP, 3);
        $owner->business->update(['is_demo' => true]);

        $this->assertSame(7, Tenancy::graceDaysFor($owner->business->fresh()));
        $this->assertFalse(Tenancy::locked($owner->business->fresh()));
    }

    public function test_auto_suspend_off_keeps_its_policy_for_everyone(): void
    {
        $owner = $this->signup();
        $this->platform('auto_suspend', '0');

        $this->onDay(15);

        /* المقبضُ مطفأ: لا إقفالَ تلقائيّ لأحد — والشريطُ لا يعدّ مهلةً لا وجود لها */
        $this->assertFalse(Tenancy::locked($owner->business->fresh()));
        $props = $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertSame(0, $props['context']['subscription']['graceLeft']);

        $manual = $this->shop(Business::SOURCE_PLATFORM_MANUAL, 30);
        $this->actingAs($manual)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_platform_still_enters_a_locked_self_signup_as_its_merchant(): void
    {
        $owner = $this->signup();
        $admin = User::create([
            'name' => 'مدير المنصة', 'email' => 'boss@abaad.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->onDay(15);

        $this->actingAs($owner)->withSession(['impersonator_id' => $admin->id])
            ->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_existing_lock_notice_arrives_the_day_after_the_trial_and_no_grace_countdown(): void
    {
        Mail::fake();
        $this->signup();

        /* لا إنذارَ جديد: إنذارُ «الإقفال» القائم، في يومه — اليوم الذي يلي آخر يوم عمل */
        $this->onDay(15);
        $this->artisan('subscriptions:notify')->assertSuccessful();
        Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => $m->stage === 'locked');
        Mail::assertNotSent(SubscriptionNoticeMail::class, fn ($m) => $m->stage === 'grace');
    }
}
