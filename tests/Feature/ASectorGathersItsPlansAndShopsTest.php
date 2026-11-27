<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\Sectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Support\SelfSignupOpens;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * القطاعُ يجمع باقاته وشركاته — والباقةُ من قطاع الشركة في كلّ باب.
 *
 * ما يُثبت هنا:
 *
 *   ١ · الترحيلُ يربط كلَّ باقةٍ وشركةٍ قائمة بالقطاع التجاريّ ولا يمسّ غيرَ عمودِه:
 *       لا معرّفًا ولا رمزًا ولا سعرًا ولا تاريخَ اشتراكٍ ولا فاتورة.
 *   ٢ · القطاعُ يُضاف ويُعدَّل اسمُه ورمزُه ثابت، ولا يُطفأ آخرُ نشط، ولمدير المنصّة وحده.
 *   ٣ · الباقةُ من صفحة القطاع له، ولا تنتقل إلى قطاعٍ آخر وعليها شركات.
 *   ٤ · الشركةُ تُضاف من صفحة القطاع أو من القائمة؛ والقطاعُ الوحيدُ يُختار وحده.
 *   ٥ · باقةٌ من قطاعٍ آخر تُردّ في الإنشاء والتعديل وتعديل الاشتراك والترقية —
 *       ولو عُدّل الطلبُ يدويًّا — وعند حفظ الصفّ نفسه من أيّ باب.
 *   ٦ · طلبٌ يُعاد إرسالُه لا يُنشئ شركةً ثانيةً ولا فاتورة.
 *   ٧ · التسجيلُ الذاتيّ كما هو، يُختم قطاعُه في الخادم، ولا قطاعَ في صفحاته العامّة.
 *   ٨ · صفحةُ القطاع تعرض شركاتِه وحدها بحال اشتراكها.
 */
class ASectorGathersItsPlansAndShopsTest extends TestCase
{
    use RefreshDatabase;
    use SelfSignupOpens;
    use SubscriptionAddons;

    private const MIGRATION = '2026_11_06_090000_a_sector_gathers_its_plans_and_shops.php';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');
        $this->seedPlans();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function retail(): Sector
    {
        return Sectors::retail();
    }

    private function sector(string $code, string $name, bool $active = true): Sector
    {
        return Sector::create(['code' => $code, 'name' => $name, 'icon' => 'store', 'sort' => 5, 'active' => $active]);
    }

    private function open(array $over = [])
    {
        return $this->actingAs($this->platform)->post(route('super-admin.businesses.store'), $over + [
            'name' => 'زهرة القطاع', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '+96891234567',
            'login_username' => 'zahra_sector', 'login_password' => 'secret12345',
        ]);
    }

    /* ═══════════════ ١ · الترحيل ═══════════════ */

    /** متجران بباقتين وفواتيرَ واشتراكاتٍ — ولقطةُ كلِّ ما ليس قطاعًا */
    private function shopsWithMoney(): \Closure
    {
        [$shop] = $this->shop('professional', 'old@shop.om');
        [$second] = $this->shop('basic', 'second@shop.om');
        Billing::renew($shop, 'monthly');
        Billing::markPaid(Invoice::where('business_id', $shop->id)->firstOrFail());
        Billing::renew($second, 'yearly');

        return fn () => [
            'plans' => DB::table('plans')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('sector_id')->all())->all(),
            'businesses' => DB::table('businesses')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('sector_id')->all())->all(),
            'invoices' => DB::table('invoices')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'subscriptions' => DB::table('subscriptions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function assertAllLinkedToRetail(): void
    {
        $retail = Sector::where('code', Sector::CODE_RETAIL)->sole();
        $this->assertSame(['التجارة والتجزئة', true, 'abaad'], [$retail->name, $retail->active, $retail->system]);
        $this->assertSame(1, Sector::count(), 'لا قطاعَ افتراضيٌّ غيرُ التجاريّ');
        $this->assertSame(0, DB::table('plans')->whereNull('sector_id')->count());
        $this->assertSame(0, DB::table('businesses')->whereNull('sector_id')->count());
        $this->assertSame(DB::table('plans')->count(), DB::table('plans')->where('sector_id', $retail->id)->count());
        $this->assertSame(DB::table('businesses')->count(), DB::table('businesses')->where('sector_id', $retail->id)->count());
        $this->assertSame(['basic', 'enterprise'], DB::table('plans')->whereNotNull('code')->orderBy('code')->pluck('code')->all());
    }

    public function test_the_migration_links_every_existing_plan_and_shop_to_retail_and_changes_nothing_else(): void
    {
        $snapshot = $this->shopsWithMoney();

        // كما كانت قبلها: صفوفٌ بلا قطاع، ولا قطاع
        DB::table('businesses')->update(['sector_id' => null]);
        DB::table('plans')->update(['sector_id' => null]);
        DB::table('sectors')->delete();
        $before = $snapshot();
        $this->assertNotEmpty($before['invoices']);
        $this->assertNotEmpty($before['subscriptions']);

        (require database_path('migrations/'.self::MIGRATION))->up();

        $this->assertEquals($before, $snapshot(), 'غيّر الترحيلُ عمودًا غيرَ القطاع');
        $this->assertAllLinkedToRetail();

        // وتُعاد بلا ضرر: لا قطاعٌ ثانٍ ولا صفٌّ يتبدّل
        (require database_path('migrations/'.self::MIGRATION))->up();
        $this->assertEquals($before, $snapshot());
        $this->assertAllLinkedToRetail();
    }

    public function test_on_postgresql_the_migration_rolls_back_and_forward_without_touching_any_money(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('الرجوعُ يُجرَّب على PostgreSQL — قاعدةِ الإنتاج؛ وSQLite يرفضه ومعه صفوف (الاختبارُ التالي).');
        }

        $snapshot = $this->shopsWithMoney();
        $before = $snapshot();

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('businesses', 'sector_id'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('sectors'));
        $this->assertEquals($before, $snapshot(), 'محا الرجوعُ ما ليس قطاعًا');

        $migration->up();
        $this->assertEquals($before, $snapshot());
        $this->assertAllLinkedToRetail();
    }

    public function test_on_sqlite_rolling_back_with_shops_in_place_is_refused_and_destroys_nothing(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('الرفضُ يخصّ SQLite وحده — PostgreSQL يرجع (الاختبارُ السابق).');
        }

        $snapshot = $this->shopsWithMoney();
        $before = $snapshot();

        try {
            (require database_path('migrations/'.self::MIGRATION))->down();
            $this->fail('رجع الترحيلُ على SQLite وفيها متاجر');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('businesses', 'sector_id'));
        $this->assertEquals($before, $snapshot());
    }

    /* ═══════════════ ٢ · القطاع ═══════════════ */

    public function test_a_sector_is_added_and_renamed_while_its_code_stays(): void
    {
        $this->actingAs($this->platform)->post(route('super-admin.sectors.store'), [
            'code' => 'services', 'name' => 'الخدمات', 'name_en' => 'Services', 'icon' => 'briefcase', 'sort' => 3, 'active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $services = Sector::where('code', 'services')->sole();
        $this->assertSame(['الخدمات', 'Services', 'briefcase', 3, true], [$services->name, $services->name_en, $services->icon, $services->sort, $services->active]);

        $this->actingAs($this->platform)->put(route('super-admin.sectors.update', $services->id), [
            'name' => 'الخدمات المهنية', 'name_en' => 'Professional services', 'icon' => 'wrench', 'sort' => 2, 'active' => false,
        ])->assertSessionHasNoErrors();

        $services->refresh();
        $this->assertSame(['services', 'الخدمات المهنية', 'wrench', 2, false], [$services->code, $services->name, $services->icon, $services->sort, $services->active]);
    }

    public function test_a_sector_refuses_a_changed_code_an_unknown_icon_and_losing_the_last_active_one(): void
    {
        $retail = $this->retail();

        $this->actingAs($this->platform)->put(route('super-admin.sectors.update', $retail->id), [
            'code' => 'commerce', 'name' => $retail->name, 'icon' => 'store',
        ])->assertSessionHasErrors('code');
        $this->assertSame('retail', $retail->fresh()->code);

        $this->actingAs($this->platform)->post(route('super-admin.sectors.store'), [
            'code' => 'Bad Code', 'name' => 'قطاع', 'icon' => 'rocket',
        ])->assertSessionHasErrors(['code', 'icon']);

        $this->actingAs($this->platform)->post(route('super-admin.sectors.store'), [
            'code' => 'retail', 'name' => 'التجارة والتجزئة', 'icon' => 'store',
        ])->assertSessionHasErrors(['code', 'name']);

        $this->actingAs($this->platform)->put(route('super-admin.sectors.update', $retail->id), [
            'name' => $retail->name, 'icon' => 'store', 'active' => false,
        ])->assertSessionHasErrors('active');
        $this->assertTrue($retail->fresh()->active, 'أُطفئ آخرُ قطاعٍ نشط');
        $this->assertSame(1, Sector::count());
    }

    public function test_only_the_platform_reaches_the_sectors(): void
    {
        [, $owner] = $this->shop('basic', 'merchant@shop.om');
        $retail = $this->retail();

        $this->actingAs($owner)->get(route('super-admin.sectors.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('super-admin.sectors.show', $retail->id))->assertForbidden();
        $this->actingAs($owner)->post(route('super-admin.sectors.store'), ['code' => 'x_y', 'name' => 'س', 'icon' => 'store'])->assertForbidden();
        $this->actingAs($owner)->put(route('super-admin.sectors.update', $retail->id), ['name' => 'س', 'icon' => 'store'])->assertForbidden();
        $this->assertSame(1, Sector::count());

        $this->actingAs($this->platform)->get(route('super-admin.sectors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/Sectors/Index')
                ->where('sectors.0.code', 'retail')
                ->where('sectors.0.plans_count', 3)
                ->where('sectors.0.businesses_count', 1)
                ->etc());
    }

    /* ═══════════════ ٣ · الباقات ═══════════════ */

    public function test_a_plan_added_from_the_sector_page_belongs_to_it_and_its_sector_never_moves(): void
    {
        $services = $this->sector('services', 'الخدمات');

        $this->actingAs($this->platform)->post(route('super-admin.plans.store'), [
            'name' => 'باقة الخدمات', 'monthly_price' => 12, 'yearly_price' => 120, 'sector_id' => $services->id,
        ])->assertSessionHasNoErrors();
        $plan = Plan::where('name', 'باقة الخدمات')->sole();
        $this->assertSame($services->id, (int) $plan->sector_id);

        // ومن صفحة الباقات بقطاعين نشطين: يُطلب القطاع
        $this->actingAs($this->platform)->post(route('super-admin.plans.store'), [
            'name' => 'باقة بلا قطاع', 'monthly_price' => 1, 'yearly_price' => 10,
        ])->assertSessionHasErrors('sector_id');
        $this->assertFalse(Plan::where('name', 'باقة بلا قطاع')->exists());

        // والتعديلُ لا يقبل القطاع: يُعدَّل السعرُ ويبقى القطاع
        $this->actingAs($this->platform)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة الخدمات', 'monthly_price' => 15, 'yearly_price' => 150, 'sector_id' => $this->retail()->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame([$services->id, '15.000'], [(int) $plan->fresh()->sector_id, $plan->fresh()->monthly_price]);

        // ولا تُنقل باقةٌ عليها شركات — ولو من غير الشاشة
        $pro = $this->plans['professional'];
        $this->shop('professional', 'on-pro@shop.om');
        $this->expectException(\DomainException::class);
        $pro->update(['sector_id' => $services->id]);
    }

    public function test_the_plans_page_alone_still_adds_a_plan_to_the_only_active_sector(): void
    {
        $this->actingAs($this->platform)->post(route('super-admin.plans.store'), [
            'name' => 'باقة جديدة', 'monthly_price' => 5, 'yearly_price' => 50,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->retail()->id, (int) Plan::where('name', 'باقة جديدة')->value('sector_id'));
    }

    public function test_a_sectors_table_emptied_after_the_migration_still_takes_a_new_plan_and_shop_into_retail(): void
    {
        // كما يتركه اختبارُ تفريغٍ قبله في قسم PostgreSQL: الترحيلُ زرع القطاع ثمّ مُحي
        DB::table('businesses')->update(['sector_id' => null]);
        DB::table('plans')->update(['sector_id' => null]);
        DB::table('sectors')->delete();

        $this->actingAs($this->platform)->post(route('super-admin.plans.store'), [
            'name' => 'باقة بعد التفريغ', 'monthly_price' => 5, 'yearly_price' => 50,
        ])->assertSessionHasNoErrors();
        $this->open()->assertSessionHasNoErrors()->assertRedirect();

        $retail = Sector::where('code', Sector::CODE_RETAIL)->sole();
        $this->assertSame($retail->id, (int) Plan::where('name', 'باقة بعد التفريغ')->value('sector_id'));
        $this->assertSame($retail->id, (int) Business::where('name', 'زهرة القطاع')->value('sector_id'));
    }

    /* ═══════════════ ٤ · إضافة شركة ═══════════════ */

    public function test_a_shop_added_from_the_main_menu_takes_the_only_active_sector(): void
    {
        $this->actingAs($this->platform)->get(route('super-admin.businesses.create'))
            ->assertInertia(fn ($page) => $page->where('sectorId', $this->retail()->id)
                ->has('options.sectors', 1)
                ->where('options.plans.0.sector_id', $this->retail()->id)
                ->etc());

        $this->open(['subscription_mode' => 'paid', 'plan_id' => $this->plans['professional']->id, 'cycle' => 'monthly'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $shop = Business::where('name', 'زهرة القطاع')->sole();
        $this->assertSame([$this->retail()->id, $this->plans['professional']->id, Business::AWAITING_PAYMENT], [(int) $shop->sector_id, (int) $shop->plan_id, $shop->status]);
        $this->assertSame(1, Invoice::where('business_id', $shop->id)->count());
    }

    public function test_a_shop_added_from_the_sector_page_joins_that_sector_and_only_its_plans_are_offered(): void
    {
        $services = $this->sector('services', 'الخدمات');
        $plan = Plan::create(['name' => 'باقة الخدمات', 'monthly_price' => 12, 'yearly_price' => 120, 'sector_id' => $services->id]);

        $this->actingAs($this->platform)->get(route('super-admin.businesses.create', ['sector' => $services->id]))
            ->assertInertia(fn ($page) => $page->where('sectorId', $services->id)->has('options.sectors', 2)->etc());

        $this->open(['sector_id' => $services->id, 'subscription_mode' => 'grant', 'plan_id' => $plan->id,
            'grant_until' => '2026-12-31', 'grant_reason' => 'شريك تجريبيّ للقطاع'])
            ->assertSessionHasNoErrors();

        $shop = Business::where('name', 'زهرة القطاع')->sole();
        $this->assertSame([$services->id, $plan->id, 'نشط', '2026-12-31'], [(int) $shop->sector_id, (int) $shop->plan_id, $shop->status, $shop->ends_at->toDateString()]);

        // وصفحةُ القطاع تعرضها هي، وباقاتِه وحدها
        $this->actingAs($this->platform)->get(route('super-admin.sectors.show', $services->id))
            ->assertInertia(fn ($page) => $page->component('Platform/Sectors/Show')
                ->has('plans', 1)->where('plans.0.name', 'باقة الخدمات')
                ->has('businesses', 1)->where('businesses.0.name', 'زهرة القطاع')
                ->etc());
    }

    public function test_with_two_active_sectors_the_sector_must_be_chosen_and_an_inactive_one_is_refused(): void
    {
        $this->sector('services', 'الخدمات');
        $closed = $this->sector('closed', 'قطاع موقوف', active: false);

        $this->actingAs($this->platform)->get(route('super-admin.businesses.create'))
            ->assertInertia(fn ($page) => $page->where('sectorId', null)->has('options.sectors', 2)->etc());

        $this->open()->assertSessionHasErrors('sector_id');
        $this->open(['sector_id' => $closed->id])->assertSessionHasErrors('sector_id');
        $this->assertFalse(Business::where('name', 'زهرة القطاع')->exists());
        $this->assertFalse(User::where('email', 'like', 'zahra_sector@%')->exists());
    }

    /* ═══════════════ ٥ · باقةٌ من قطاعٍ آخر ═══════════════ */

    public function test_the_server_refuses_a_plan_from_another_sector_on_create_edit_subscription_and_upgrade(): void
    {
        $services = $this->sector('services', 'الخدمات');
        $foreign = Plan::create(['name' => 'باقة الخدمات', 'monthly_price' => 12, 'yearly_price' => 120, 'sector_id' => $services->id]);
        $invoicesBefore = Invoice::count();

        // الإنشاءُ في التجاريّ بباقة الخدمات — طلبٌ عُدّل باليد
        $this->open(['sector_id' => $this->retail()->id, 'subscription_mode' => 'paid', 'plan_id' => $foreign->id, 'cycle' => 'monthly'])
            ->assertSessionHasErrors('plan_id');
        $this->assertFalse(Business::where('name', 'زهرة القطاع')->exists());

        [$shop] = $this->shop('basic', 'retail@shop.om');

        // تعديلُ الشركة
        $this->actingAs($this->platform)->put(route('super-admin.businesses.update', $shop->id), [
            'name' => $shop->name, 'type' => $shop->type, 'status' => 'نشط', 'plan_id' => $foreign->id,
        ])->assertSessionHasErrors('plan_id');

        // ونقلُها إلى قطاعٍ لا تنتمي إليه باقتُها
        $this->actingAs($this->platform)->put(route('super-admin.businesses.update', $shop->id), [
            'name' => $shop->name, 'type' => $shop->type, 'status' => 'نشط', 'sector_id' => $services->id,
        ])->assertSessionHasErrors('plan_id');

        // تعديلُ الاشتراك
        $cycle = Subscription::create([
            'business_id' => $shop->id, 'plan_id' => $shop->plan_id, 'starts_at' => $shop->starts_at, 'ends_at' => $shop->ends_at,
            'amount' => 0, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);
        $this->actingAs($this->platform)->put(route('super-admin.subscriptions.update', $cycle->id), [
            'plan_id' => $foreign->id, 'starts_at' => $shop->starts_at->toDateString(), 'ends_at' => $shop->ends_at->toDateString(),
            'amount' => 0, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ])->assertSessionHasErrors('plan_id');
        $this->assertSame((int) $shop->plan_id, (int) $cycle->fresh()->plan_id, 'حُفظت دورةٌ بباقة قطاعٍ آخر');

        // والترقية — قبل أن تصدر فاتورتُها
        $this->actingAs($this->platform)->post(route('super-admin.businesses.upgrade', $shop->id), [
            'plan_id' => $foreign->id, 'cycle' => 'monthly',
        ])->assertSessionHasErrors('plan_id');

        $shop->refresh();
        $this->assertSame([$this->plans['basic']->id, $this->retail()->id], [(int) $shop->plan_id, (int) $shop->sector_id]);
        $this->assertSame($invoicesBefore, Invoice::count(), 'صدرت فاتورةٌ لباقةٍ من قطاعٍ آخر');

        // وصفحةُ الشركة لا تعرضها بين الترقيات
        $this->actingAs($this->platform)->get(route('super-admin.businesses.show', $shop->id))
            ->assertInertia(fn ($page) => $page->where('renewal.upgrades', fn ($ups) => collect($ups)->pluck('id')->doesntContain($foreign->id))->etc());
    }

    public function test_the_shop_row_itself_refuses_a_plan_from_another_sector_from_any_door(): void
    {
        $services = $this->sector('services', 'الخدمات');
        $foreign = Plan::create(['name' => 'باقة الخدمات', 'monthly_price' => 12, 'yearly_price' => 120, 'sector_id' => $services->id]);
        [$shop] = $this->shop('basic', 'door@shop.om');

        try {
            $shop->update(['plan_id' => $foreign->id]);
            $this->fail('حُفظت باقةُ قطاعٍ آخر على الصفّ');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan_id', $e->errors());
        }

        // وشركةٌ تُنشأ ببابٍ لا يعرف القطاع تأخذ قطاعَ باقتها
        $direct = Business::create(['name' => 'باب قديم', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $foreign->id]);
        $this->assertSame($services->id, (int) $direct->sector_id);
        $this->assertSame($this->plans['basic']->id, (int) $shop->fresh()->plan_id);
    }

    public function test_a_trial_in_a_sector_without_the_basic_plan_is_refused(): void
    {
        $services = $this->sector('services', 'الخدمات');

        $this->open(['sector_id' => $services->id, 'subscription_mode' => 'trial'])->assertSessionHasErrors('subscription_mode');
        $this->assertFalse(Business::where('name', 'زهرة القطاع')->exists());
    }

    /* ═══════════════ ٦ · لا تكرار ═══════════════ */

    public function test_a_repeated_submission_creates_one_shop_and_one_invoice(): void
    {
        $paid = ['subscription_mode' => 'paid', 'plan_id' => $this->plans['professional']->id, 'cycle' => 'yearly'];

        $this->open($paid)->assertSessionHasNoErrors();
        $this->open($paid)->assertSessionHasErrors('login_username');

        $this->assertSame(1, Business::where('name', 'زهرة القطاع')->count());
        $shop = Business::where('name', 'زهرة القطاع')->sole();
        $this->assertSame(1, User::where('business_id', $shop->id)->count());
        $this->assertSame(1, Invoice::where('business_id', $shop->id)->count());
        $this->assertSame(1, Subscription::where('business_id', $shop->id)->count());
    }

    /* ═══════════════ ٧ · التسجيلُ الذاتيّ والصفحاتُ العامّة ═══════════════ */

    public function test_a_self_signup_joins_retail_and_the_public_pages_show_no_sector(): void
    {
        $this->openSelfSignup();
        $this->sector('services', 'الخدمات');

        $page = $this->get(route('register'))->assertOk();
        $page->assertInertia(fn ($p) => $p->missing('sectors')->missing('sectorId')->etc());
        $page->assertDontSee('التجارة والتجزئة')->assertDontSee('الخدمات');
        $this->get(route('login'))->assertOk()->assertDontSee('التجارة والتجزئة');

        $this->post(route('register.store'), [
            'name' => 'مسجّل بنفسه', 'phone' => '+96891234500', 'type' => 'محل ورود', 'shop' => 'Self Signup Flowers',
            'team_size' => '2–5 موظفين', 'address' => 'مسقط', 'email' => 'self@example.com', 'password' => 'abaad2026',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $business = Business::where('registration_source', Business::SOURCE_SELF_SIGNUP)->sole();
        $this->assertSame([$this->plans['basic']->id, $this->retail()->id], [(int) $business->plan_id, (int) $business->sector_id]);
    }

    /* ═══════════════ ٨ · صفحةُ القطاع ═══════════════ */

    public function test_the_sector_page_lists_only_its_own_shops_with_their_subscription_state(): void
    {
        $services = $this->sector('services', 'الخدمات');
        $plan = Plan::create(['name' => 'باقة الخدمات', 'monthly_price' => 12, 'yearly_price' => 120, 'sector_id' => $services->id]);

        [$trial] = $this->shop('basic', 'trial@shop.om');
        [$paid] = $this->shop('professional', 'paid@shop.om');
        Billing::renew($paid, 'monthly');
        Billing::markPaid(Invoice::where('business_id', $paid->id)->firstOrFail());
        $expired = Business::create(['name' => 'متجر منتهٍ', 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $this->plans['basic']->id,
            'starts_at' => '2026-09-01', 'ends_at' => '2026-10-01']);
        $other = Business::create(['name' => 'مكتب خدمات', 'type' => 'خدمات', 'status' => 'نشط', 'plan_id' => $plan->id,
            'starts_at' => '2026-11-01', 'ends_at' => '2026-12-01']);

        $rows = fn (array $query = []) => collect($this->actingAs($this->platform)
            ->get(route('super-admin.sectors.show', ['id' => $this->retail()->id] + $query))
            ->assertOk()->viewData('page')['props']['businesses']);

        $all = $rows(['tab' => 'businesses']);
        $this->assertEqualsCanonicalizing([$trial->id, $paid->id, $expired->id], $all->pluck('id')->all(), 'تسرّبت شركةُ قطاعٍ آخر');
        $this->assertSame('trial', $all->firstWhere('id', $trial->id)['state']);
        $this->assertSame('subscribed', $all->firstWhere('id', $paid->id)['state']);
        $this->assertSame('expired', $all->firstWhere('id', $expired->id)['state']);

        $this->assertSame([$paid->id], $rows(['state' => 'subscribed'])->pluck('id')->all());
        $this->assertSame([$expired->id], $rows(['state' => 'expired'])->pluck('id')->all());
        $this->assertSame([$expired->id], $rows(['q' => 'منتهٍ'])->pluck('id')->all());
        $this->assertSame([$paid->id], $rows(['plan' => $this->plans['professional']->name])->pluck('id')->all());

        $this->assertSame([$other->id], collect($this->actingAs($this->platform)->get(route('super-admin.sectors.show', $services->id))
            ->viewData('page')['props']['businesses'])->pluck('id')->all());

        // وقائمةُ الشركات تُرشَّح بالقطاع أيضًا
        $this->actingAs($this->platform)->get(route('super-admin.businesses.index', ['sector' => $services->id]))
            ->assertInertia(fn ($page) => $page->has('businesses', 1)->where('businesses.0.id', $other->id)->etc());
    }
}
