<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سلطة المنصّة على مستأجريها.
 *
 * كانت لوحة المنصة عارضةً لا لوحة تحكّم: تقرأ وتعدّل، ولا تفرض ما بِيع، ولا
 * تقطع عمّن لم يدفع، ولا تُعين على دعم تاجرٍ اتصل. «موقوف» و«معطل» و«منتهي»
 * كلماتٌ في أعمدة لا يقرؤها أحد، و`max_branches` رقمٌ لا وجود له خارج البذور.
 */
class PlatformControlTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function tenant(array $business = [], array $user = []): array
    {
        $biz = Business::create(array_merge([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط',
        ], $business));

        JobTitle::create(['business_id' => $biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $owner = User::create(array_merge([
            'business_id' => $biz->id, 'name' => 'المالك', 'email' => 'o'.uniqid().'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ], $user));

        return [$biz, $owner];
    }

    /* ------------------- ١· الإيقاف يوقف فعلًا ------------------- */

    public function test_a_suspended_user_cannot_log_in(): void
    {
        [, $owner] = $this->tenant(user: ['status' => 'موقوف', 'email' => 's@abaad.om']);

        $this->post(route('login.attempt'), ['email' => 's@abaad.om', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_disabled_business_locks_its_people_out(): void
    {
        [, $owner] = $this->tenant(['status' => 'معطل'], ['email' => 'd@abaad.om']);

        $this->post(route('login.attempt'), ['email' => 'd@abaad.om', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_expired_subscription_locks_them_out(): void
    {
        [, $owner] = $this->tenant(['ends_at' => now()->subMonth()], ['email' => 'e@abaad.om']);

        $this->post(route('login.attempt'), ['email' => 'e@abaad.om', 'password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    /**
     * وجلسةٌ فُتحت قبل الإيقاف تُقطع.
     *
     * المنع عند الباب وحده يعني أن الإيقاف لا يسري إلا بعد أن يخرج المستخدم
     * بنفسه — وهو ما لن يفعله.
     */
    public function test_an_open_session_is_cut_when_the_business_is_disabled(): void
    {
        [$biz, $owner] = $this->tenant();

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();

        $biz->update(['status' => 'معطل']);

        // fresh(): علاقة business محمَّلة في الكائن من الطلب الأول، وفي
        // الإنتاج يُقرأ المستخدم من الجلسة في كل طلبٍ فلا تبقى قديمة
        $this->actingAs($owner->fresh())->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($owner->fresh())->get(route('pos.index'))->assertRedirect(route('login'));
    }

    /** ويوم الانتهاء نفسه يبقى مسموحًا: من دفع حتى اليوم يعمل اليوم كاملًا */
    public function test_the_last_day_of_the_subscription_still_works(): void
    {
        [, $owner] = $this->tenant(['ends_at' => now()]);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }

    /** وبلا تاريخ انتهاء لا يُقفل أحد — حسابٌ قديم لا يُعاقَب على حقلٍ فارغ */
    public function test_a_business_without_an_end_date_is_never_blocked(): void
    {
        [, $owner] = $this->tenant(['ends_at' => null]);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }

    /** ومدير المنصة لا يُفحص: هو من يوقف لا من يُوقَف */
    public function test_the_platform_admin_is_never_blocked(): void
    {
        $this->actingAs($this->super)->get(route('super-admin.dashboard'))->assertOk();
    }

    /* ------------------- ٤· الانتهاء التلقائي ------------------- */

    public function test_the_daily_command_flips_expired_businesses(): void
    {
        $expired = Business::create([
            'name' => 'منتهية', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->subDay(),
        ]);
        $living = Business::create([
            'name' => 'حيّة', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->addMonth(),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame('منتهي', $expired->fresh()->status);
        $this->assertSame('نشط', $living->fresh()->status);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $b = Business::create([
            'name' => 'منتهية', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire --dry')->assertSuccessful();

        $this->assertSame('نشط', $b->fresh()->status);
    }

    /** والتاجر يُحذَّر قبل الانتهاء لا بعده */
    public function test_the_merchant_is_warned_before_the_end(): void
    {
        [, $owner] = $this->tenant(['ends_at' => now()->addDays(3)]);

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('context.subscription.daysLeft', 3));
    }

    /* -------------------- ٣· حدود الباقة -------------------- */

    private function planned(array $limits): array
    {
        $plan = Plan::create(array_merge([
            'name' => 'الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 1, 'max_products' => 1,
        ], $limits));

        return $this->tenant(['plan_id' => $plan->id]);
    }

    public function test_the_branch_limit_is_enforced(): void
    {
        [$biz, $owner] = $this->planned(['max_branches' => 1]);
        Branch::create(['business_id' => $biz->id, 'name' => 'الفرع الرئيسي']);

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'فرع ثانٍ'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Branch::where('business_id', $biz->id)->count());
    }

    public function test_the_employee_limit_is_enforced(): void
    {
        // المالك نفسه مستخدمٌ في المتجر، فسقف واحدٍ مبلوغٌ سلفًا
        [$biz, $owner] = $this->planned(['max_employees' => 1]);

        $this->actingAs($owner)->post(route('admin.employees.store'), [
            'name' => 'موظف', 'email' => 'new@abaad.om', 'job_title' => 'كاشير',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    public function test_the_product_limit_is_enforced(): void
    {
        [$biz, $owner] = $this->planned(['max_products' => 1]);
        Product::create([
            'business_id' => $biz->id, 'name' => 'وردة', 'price' => 5, 'quantity' => 1, 'active' => true,
        ]);

        $this->actingAs($owner)->post(route('admin.products.store'), ['name' => 'ثانية', 'price' => 5])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Product::where('business_id', $biz->id)->count());
    }

    /** وتحت السقف يمرّ — الحدّ لا يمنع من لم يبلغه */
    public function test_below_the_cap_creation_still_works(): void
    {
        [$biz, $owner] = $this->planned(['max_branches' => 3]);

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'فرع'])
            ->assertSessionHasNoErrors();
    }

    /** ومتجرٌ بلا باقة بلا سقف: لا يُقفل لأن حقلًا فيه فارغ */
    public function test_a_business_without_a_plan_has_no_cap(): void
    {
        [$biz, $owner] = $this->tenant();

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'فرع'])
            ->assertSessionHasNoErrors();
    }

    /* -------------------- ٢· الدخول كتاجر -------------------- */

    public function test_the_platform_admin_can_enter_a_tenant(): void
    {
        [$biz, $owner] = $this->tenant();

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.impersonate', $biz->id))
            ->assertRedirect();

        $this->assertAuthenticatedAs($owner);
        $this->assertTrue(session()->has('impersonator_id'));
    }

    public function test_leaving_returns_to_the_platform_admin(): void
    {
        [$biz] = $this->tenant();

        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $biz->id));
        $this->post(route('impersonate.stop'))->assertRedirect(route('super-admin.businesses.index'));

        $this->assertAuthenticatedAs($this->super);
        $this->assertFalse(session()->has('impersonator_id'));
    }

    /** والانتحال يعمل داخل متجرٍ معطَّل: منعُه هناك يمنع الإصلاح نفسه */
    public function test_impersonation_works_inside_a_disabled_business(): void
    {
        [$biz] = $this->tenant(['status' => 'معطل']);

        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $biz->id));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    /** ولا يفتحه تاجر: لو فتحه لدخل متاجر جيرانه */
    public function test_a_merchant_cannot_impersonate(): void
    {
        [$biz, $owner] = $this->tenant();

        $this->actingAs($owner)
            ->post(route('super-admin.businesses.impersonate', $biz->id))
            ->assertForbidden();
    }

    /** ويُسجَّل في سجلّ النشاط: صلاحيةٌ بلا أثرٍ بابٌ خلفي */
    public function test_entering_a_tenant_is_logged(): void
    {
        [$biz] = $this->tenant();

        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $biz->id));

        $this->assertDatabaseHas('activity_logs', ['description' => 'دخل كتاجر: متجري']);
    }

    /* -------------------- ٦· التجديد والفوترة -------------------- */

    public function test_renewing_extends_the_end_date_and_issues_an_invoice(): void
    {
        [$biz] = $this->planned([]);
        $biz->update(['ends_at' => now()->addDays(5)]);

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.renew', $biz->id), ['cycle' => 'monthly'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addDays(5)->addMonth()));
        $this->assertSame(1, Invoice::where('business_id', $biz->id)->count());
        $this->assertSame(1, Subscription::where('business_id', $biz->id)->count());
    }

    /**
     * والتجديد المبكر لا يُهدر ما تبقّى.
     *
     * لو مُدّد من اليوم لخسر من جدّد قبل أسبوع ذلك الأسبوع — فيصير التجديد
     * المبكّر عقوبةً، ولا يجدّد أحدٌ إلا متأخّرًا.
     */
    public function test_renewing_early_keeps_the_remaining_days(): void
    {
        [$biz] = $this->planned([]);
        $biz->update(['ends_at' => now()->addDays(20)]);

        Billing::renew($biz->fresh(), 'monthly');

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addDays(20)->addMonth()));
    }

    /** والمنتهي يُجدَّد من اليوم لا من ماضٍ مضى */
    public function test_renewing_an_expired_one_starts_today(): void
    {
        [$biz] = $this->planned([]);
        $biz->update(['ends_at' => now()->subMonths(3), 'status' => 'منتهي']);

        Billing::renew($biz->fresh(), 'monthly');

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addMonth()));
        $this->assertSame('نشط', $biz->fresh()->status);
    }

    public function test_invoice_numbers_do_not_repeat(): void
    {
        [$biz] = $this->planned([]);

        Billing::renew($biz->fresh(), 'monthly');
        Billing::renew($biz->fresh(), 'monthly');
        Invoice::latest('id')->first()->delete();
        Billing::renew($biz->fresh(), 'monthly');

        $this->assertSame(
            Invoice::count(),
            Invoice::distinct()->count('number'),
            'تكرّر رقم فاتورة بعد حذف واحدة',
        );
    }

    public function test_marking_an_invoice_paid_records_it(): void
    {
        [$biz] = $this->planned([]);
        $invoice = Billing::renew($biz->fresh(), 'monthly')['invoice'];

        $this->actingAs($this->super)->post(route('super-admin.invoices.pay', $invoice->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('مدفوعة', $invoice->fresh()->status);
        $this->assertSame('مدفوع', Subscription::latest('id')->first()->payment_status);
    }

    /** ولا يُجدَّد متجرٌ بلا باقة: لا سعر له فتُصدَر فاتورةٌ بصفر */
    public function test_renewing_without_a_plan_is_refused(): void
    {
        [$biz] = $this->tenant();

        $this->actingAs($this->super)->post(route('super-admin.businesses.renew', $biz->id), ['cycle' => 'monthly']);

        $this->assertSame(0, Invoice::count());
    }

    /* ---------------- تعديل الاشتراكات ---------------- */

    /**
     * الاشتراك يُعدَّل: خصمٌ متّفق عليه، وتاريخٌ أُدخل خطأً.
     *
     * وبلا تعديلٍ لا يبقى إلا أن يُكتب الصواب في ورقةٍ أو رسالة، فيفترق ما في
     * النظام عمّا اتُّفق عليه.
     */
    public function test_a_subscription_can_be_edited(): void
    {
        [$biz] = $this->planned([]);
        $sub = Billing::renew($biz->fresh(), 'monthly')['subscription'];

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $sub->id), [
            'plan_id' => $biz->plan_id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'amount' => '75.500',
            'payment_status' => 'مدفوع',
            'status' => 'نشط',
        ])->assertSessionHasNoErrors();

        $fresh = $sub->fresh();
        $this->assertSame('75.500', (string) $fresh->amount);
        $this->assertSame('مدفوع', $fresh->payment_status);
        $this->assertSame('2026-12-31', $fresh->ends_at->format('Y-m-d'));
    }

    /**
     * وتعديل أحدث دورةٍ يُحدّث المتجر معها.
     *
     * الحارس يقرأ `businesses.ends_at` والشاشة تعرض `subscriptions.ends_at` —
     * وتركُهما منفصلين يعني لوحةً تقول «نشط حتى ٢٠٢٧» وبابًا يُقفل اليوم.
     */
    public function test_editing_the_latest_cycle_moves_the_business_with_it(): void
    {
        [$biz] = $this->planned([]);
        $sub = Billing::renew($biz->fresh(), 'monthly')['subscription'];

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $sub->id), [
            'plan_id' => $biz->plan_id,
            'starts_at' => now()->format('Y-m-d'),
            'ends_at' => now()->addYear()->format('Y-m-d'),
            'amount' => '100',
            'payment_status' => 'مدفوع',
            'status' => 'نشط',
        ]);

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addYear()));
    }

    /** ودورةٌ قديمة لا تُحرّك المتجر: تصحيحُ الماضي ليس تمديدًا */
    public function test_editing_an_older_cycle_leaves_the_business_alone(): void
    {
        [$biz] = $this->planned([]);
        $old = Billing::renew($biz->fresh(), 'monthly')['subscription'];
        Billing::renew($biz->fresh(), 'yearly');
        $endsAt = $biz->fresh()->ends_at;

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $old->id), [
            'plan_id' => $biz->plan_id,
            'starts_at' => '2020-01-01',
            'ends_at' => '2020-02-01',
            'amount' => '5',
            'payment_status' => 'مدفوع',
            'status' => 'منتهي',
        ]);

        $this->assertTrue($biz->fresh()->ends_at->isSameDay($endsAt));
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        [$biz] = $this->planned([]);
        $sub = Billing::renew($biz->fresh(), 'monthly')['subscription'];

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $sub->id), [
            'starts_at' => '2026-12-01',
            'ends_at' => '2026-01-01',
            'amount' => '10',
            'payment_status' => 'مدفوع',
            'status' => 'نشط',
        ])->assertSessionHasErrors('ends_at');
    }

    /**
     * والحذف يُرجع المتجر إلى الدورة الباقية.
     *
     * وإلا بقي `ends_at` على ما كتبته الدورة المحذوفة: تُحذف دورةٌ جُدّدت
     * بالخطأ فيظلّ المتجر يعمل سنةً لم يدفعها أحد.
     */
    public function test_deleting_a_cycle_rolls_the_business_back(): void
    {
        [$biz] = $this->planned([]);
        Billing::renew($biz->fresh(), 'monthly');
        $first = $biz->fresh()->ends_at;
        $second = Billing::renew($biz->fresh(), 'yearly')['subscription'];

        $this->actingAs($this->super)
            ->delete(route('super-admin.subscriptions.destroy', $second->id))
            ->assertSessionHasNoErrors();

        $this->assertTrue($biz->fresh()->ends_at->isSameDay($first));
        $this->assertSame(1, Subscription::where('business_id', $biz->id)->count());
    }

    /** ولا يعدّلها تاجر */
    public function test_a_merchant_cannot_edit_subscriptions(): void
    {
        [$biz, $owner] = $this->planned([]);
        $sub = Billing::renew($biz->fresh(), 'monthly')['subscription'];

        $this->actingAs($owner)
            ->put(route('super-admin.subscriptions.update', $sub->id), ['amount' => '0'])
            ->assertForbidden();
    }

    /* -------------------- ٥· دمج محلات الورود -------------------- */

    public function test_the_old_flower_shop_links_land_on_the_businesses_screen(): void
    {
        [$biz] = $this->tenant(['type' => 'محل ورود']);

        $this->actingAs($this->super)->get(route('super-admin.flower-shops.index'))
            ->assertRedirect(route('super-admin.businesses.index', ['type' => 'محل ورود']));

        $this->actingAs($this->super)->get(route('super-admin.flower-shops.show', $biz->id))
            ->assertRedirect(route('super-admin.businesses.show', $biz->id));
    }

    /** والتصفية تعرف الأنواع المكتوبة يدويًّا لا الستّة المعروفة وحدها */
    public function test_the_type_filter_knows_hand_written_types(): void
    {
        $this->tenant(['type' => 'مغسلة ملابس']);

        $this->actingAs($this->super)->get(route('super-admin.businesses.index'))
            ->assertInertia(fn ($page) => $page->where(
                'options.types',
                fn ($types) => collect($types)->contains('مغسلة ملابس'),
            ));
    }
}
