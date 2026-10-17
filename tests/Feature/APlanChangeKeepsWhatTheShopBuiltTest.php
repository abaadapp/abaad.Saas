<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\GoogleBusinessAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\Billing;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use App\Support\PlanFeatures;
use App\Support\SeedData;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تبديلُ الباقة يُغلق ويفتح — ولا يمحو، ولا يُنزل تحت الاستهلاك.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - التنزيلُ إلى الأساسيّة يُبقي كلَّ ما بناه المتجر: مفتاحَ Google وربطَ
 *     الفروع وربطَ Google Business، ووصلةَ واتساب الخاصّة وسجلَّ رسائلها،
 *     والموقعَ وإعداداتِه. والترقيةُ تُعيد البابَ على ما كان.
 *   - محرّرُ الباقة يحفظ `[]` كما هي — لا `null` (وهي «كلُّ شيءٍ مفتوح»).
 *   - نافذةُ الاشتراك لا تُنزل متجرًا إلى باقةٍ أضيقَ من فروعه أو موظّفيه
 *     النشطين أو منتجاته — كنموذج الشركة تمامًا.
 */
class APlanChangeKeepsWhatTheShopBuiltTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private Plan $basic;

    private Plan $pro;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::fake();

        foreach (SeedData::plans() as $spec) {
            [$branches, $employees, $products] = $spec['limits'];
            $plan = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'], 'yearly_price' => $spec['yearly'],
                'features' => $spec['features'], 'capabilities' => $spec['capabilities'],
                'max_branches' => $branches, 'max_employees' => $employees, 'max_products' => $products,
            ]);
            match ($spec['name']) {
                'الباقة الأساسية' => $this->basic = $plan,
                'الباقة الاحترافية' => $this->pro = $plan,
                default => null,
            };
        }

        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@plans.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->shop = Business::create([
            'name' => 'متجر نامٍ', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567', 'plan_id' => $this->pro->id,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@plans.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function moveTo(Plan $plan)
    {
        return $this->actingAs($this->platform)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط', 'plan_id' => $plan->id,
        ]);
    }

    /** ما بناه المتجر في كلّ جدولٍ يمسّه التبديل — صفوفًا كما هي */
    private function built(): array
    {
        $bid = $this->shop->id;
        $branches = Branch::where('business_id', $bid)->pluck('id');

        return [
            'settings' => DB::table('settings')->where('business_id', $bid)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'branch_google_places' => DB::table('branch_google_places')->whereIn('branch_id', $branches)->get()->map(fn ($r) => (array) $r)->all(),
            'google_business_accounts' => DB::table('google_business_accounts')->where('business_id', $bid)->get()->map(fn ($r) => (array) $r)->all(),
            'whatsapp_connections' => DB::table('whatsapp_connections')->where('business_id', $bid)->get()->map(fn ($r) => (array) $r)->all(),
            'whatsapp_messages' => DB::table('whatsapp_messages')->where('business_id', $bid)->get()->map(fn ($r) => (array) $r)->all(),
            'site' => DB::table('businesses')->where('id', $bid)->value('site_slug'),
        ];
    }

    /* ═══════════ ١ · التنزيلُ لا يمحو، والترقيةُ تُعيد ═══════════ */

    public function test_a_downgrade_to_basic_deletes_nothing_and_an_upgrade_brings_it_back(): void
    {
        $bid = $this->shop->id;
        $branch = Branch::where('business_id', $bid)->firstOrFail();

        GoogleReviews::storeKey($bid, 'AIza-the-shops-own-key-0123');
        BranchGooglePlace::create(['branch_id' => $branch->id, 'place_id' => 'ChIJ-shop', 'place_name' => 'المحل', 'linked_at' => now()]);
        GoogleBusinessAccount::create([
            'business_id' => $bid, 'account_name' => 'accounts/1', 'account_email' => 'owner@gmail.com',
            'access_token' => 'access-token-value', 'refresh_token' => 'refresh-token-value', 'token_expires_at' => now()->addHour(),
        ]);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS, 'business_id' => $bid,
            'phone_number_id' => 'SHOP-PN', 'display_phone_number' => '+96891234567',
            'access_token' => 'shop-token-0123456789', 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
        WhatsAppMessage::create([
            'business_id' => $bid, 'source_mode' => 'abaad_shared', 'event_type' => 'order_ready', 'direction' => 'outbound',
            'recipient_phone' => '96897778888', 'dedupe_key' => 'kept-1', 'status' => 'sent',
        ]);
        $this->shop->forceFill(['site_slug' => 'growing', 'whatsapp_enabled' => true])->save();
        MarketingSettings::save($bid, 'website', ['store_on' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppFeature::GLOBAL_KEY], ['value' => '1']);

        $before = $this->built();

        $this->moveTo($this->basic)->assertSessionHasNoErrors();
        $this->assertSame($this->basic->id, (int) $this->shop->fresh()->plan_id);
        $this->assertSame($before, $this->built(), 'التنزيلُ مسّ ما بناه المتجر');

        // مغلقٌ ما دام على الأساسيّة
        $this->actingAs($this->owner->fresh())->get(route('admin.integrations.google'))->assertForbidden();
        $this->actingAs($this->owner->fresh())->get(route('admin.website.index'))->assertForbidden();
        $this->assertSame(WhatsAppStatus::SKIP_PLAN, WhatsAppFeature::blockReason($this->shop->fresh()));

        $this->moveTo($this->pro)->assertSessionHasNoErrors();
        $this->assertSame($before, $this->built());

        // والترقيةُ تفتح البابَ على ما كان: المفتاحُ نفسُه، والموقعُ، وواتساب
        $this->actingAs($this->owner->fresh())->get(route('admin.integrations.google'))->assertOk();
        $this->assertSame('AIza-the-shops-own-key-0123', GoogleReviews::apiKey($bid));
        $this->actingAs($this->owner->fresh())->get(route('admin.website.index'))->assertOk();
        $this->assertNotSame(WhatsAppStatus::SKIP_PLAN, WhatsAppFeature::blockReason($this->shop->fresh()));
    }

    /* ═══════════ ٢ · المنتقي يقرأ الباقةَ نفسَها ═══════════ */

    /**
     * نموذجُ الشركة ونافذةُ الاشتراك يستلمان كلَّ باقةٍ بتفاصيلها — من صفّها.
     *
     * نصوصُها وسعراها وسقوفُها، ولا قائمةَ قدرات: المعاينةُ تقول ما في الباقة
     * لا ما في النظام. ويعدّل مديرُ المنصّة الباقةَ فتتبعه المعاينة.
     */
    public function test_both_plan_choosers_receive_each_plans_own_details(): void
    {
        $this->basic->update(['features' => ['إدارة متجرك اليومية', 'سطرٌ أضافه المدير اليوم']]);

        $business = $this->actingAs($this->platform)->get(route('super-admin.businesses.create'))->viewData('page')['props']['options']['plans'];
        $subscription = $this->actingAs($this->platform)->get(route('super-admin.subscriptions.index'))->viewData('page')['props']['planOptions'];

        foreach (['نموذج الشركة' => $business, 'نافذة الاشتراك' => $subscription] as $where => $plans) {
            $basic = collect($plans)->firstWhere('value', $this->basic->id);

            $this->assertSame([
                'label' => 'الباقة الأساسية', 'value' => $this->basic->id, 'monthly' => 9.9, 'yearly' => 99.0,
                'features' => ['إدارة متجرك اليومية', 'سطرٌ أضافه المدير اليوم'],
                'max_branches' => 1, 'max_employees' => 3, 'max_products' => 500,
            ], $basic, $where);
            $this->assertArrayNotHasKey('capabilities', $basic, "{$where}: قائمةُ القدرات أُرسلت مع الباقة");
        }
    }

    /* ═══════════ ٣ · `[]` تُحفظ `[]` ═══════════ */

    public function test_the_plan_editor_saves_an_empty_list_as_empty_not_null(): void
    {
        $plan = Plan::create(['name' => 'مفتوحة', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => null]);

        $this->actingAs($this->platform)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'مغلقة', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('[]', DB::table('plans')->where('id', $plan->id)->value('capabilities'));
        $this->assertSame([], $plan->fresh()->capabilities);
        $this->assertFalse(PlanFeatures::allowedBy($plan->fresh(), 'integrations'), '`[]` قُرئت مفتوحة');

        // والإنشاءُ كذلك
        $this->actingAs($this->platform)->post(route('super-admin.plans.store'), [
            'name' => 'جديدة مغلقة', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => [],
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertSame([], Plan::where('name', 'جديدة مغلقة')->firstOrFail()->capabilities);
    }

    /* ═══════════ ٤ · نافذةُ الاشتراك لا تُنزل تحت الاستهلاك ═══════════ */

    private function editSubscription(Subscription $sub, Plan $plan)
    {
        return $this->actingAs($this->platform)->put(route('super-admin.subscriptions.update', $sub->id), [
            'plan_id' => $plan->id,
            'starts_at' => $sub->starts_at->toDateString(),
            'ends_at' => $sub->ends_at->toDateString(),
            // والمبلغُ يُكتب كما اتُّفق — الباقةُ تقترحه ولا تفرضه
            'amount' => '5.000',
            'payment_status' => 'مدفوع',
            'status' => 'نشط',
        ]);
    }

    public function test_a_subscription_is_not_moved_below_the_shops_usage(): void
    {
        $sub = Billing::renew($this->shop->fresh(), 'monthly')['subscription'];

        foreach ([
            'فرعان' => fn () => Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']),
            'أربعةُ موظّفين نشطين' => fn () => collect(range(1, 3))->each(fn ($n) => User::create([
                'business_id' => $this->shop->id, 'name' => 'موظف '.$n, 'email' => "e{$n}@plans.om",
                'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
            ])),
            'خمسُمئةٍ وصنف' => fn () => collect(range(1, 501))->each(fn ($n) => Product::create([
                'business_id' => $this->shop->id, 'name' => 'صنف '.$n, 'price' => 1,
            ])),
        ] as $label => $grow) {
            $this->refreshDatabaseState($sub);
            $grow();

            $this->editSubscription($sub, $this->basic)->assertSessionHasErrors('plan_id');

            $this->assertSame($this->pro->id, (int) $this->shop->fresh()->plan_id, "{$label}: نُزّل المتجر");
            $this->assertSame($this->pro->id, (int) $sub->fresh()->plan_id, "{$label}: حُفظ الاشتراكُ بالباقة المردودة");
        }
    }

    /** يعيد المتجرَ إلى استهلاكٍ يسع الأساسيّة قبل كلّ حالة — فلا تختلط الأسباب */
    private function refreshDatabaseState(Subscription $sub): void
    {
        Branch::where('business_id', $this->shop->id)->where('name', '!=', 'الرئيسي')->delete();
        User::where('business_id', $this->shop->id)->where('role', 'cashier')->delete();
        Product::where('business_id', $this->shop->id)->delete();
        $this->assertSame($this->pro->id, (int) $sub->fresh()->plan_id);
    }

    public function test_a_subscription_within_the_smaller_plan_moves_and_keeps_its_agreed_amount(): void
    {
        $sub = Billing::renew($this->shop->fresh(), 'monthly')['subscription'];

        $this->editSubscription($sub, $this->basic)->assertSessionHasNoErrors();

        $this->assertSame($this->basic->id, (int) $this->shop->fresh()->plan_id);
        $this->assertSame(5.0, (float) $sub->fresh()->amount, 'المبلغُ المتّفق عليه استُبدل بسعر الباقة');
    }

    /** واشتراكٌ قديمٌ لا ينقل باقةَ المتجر — فلا يُردّ تعديلُه */
    public function test_an_older_subscription_that_does_not_move_the_shop_is_not_refused(): void
    {
        $old = Billing::renew($this->shop->fresh(), 'monthly')['subscription'];
        Billing::renew($this->shop->fresh(), 'monthly');
        Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);

        $this->editSubscription($old, $this->basic)->assertSessionHasNoErrors();

        $this->assertSame($this->pro->id, (int) $this->shop->fresh()->plan_id);
    }
}
