<?php

namespace Tests\Support;

use App\Models\AddonOrder;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\Billing;
use App\Support\PlanFeatures;
use App\Support\SeedData;

/**
 * أدواتُ اختبار إضافات الاشتراك — الباقاتُ الثلاث كما تُزرع، والكتالوجُ مربوطًا
 * بها كما يربطه الترحيل، والإطلاقُ يُفتح بسعرٍ معتمد.
 */
trait SubscriptionAddons
{
    /** @var array<string, Plan> */
    protected array $plans = [];

    protected User $platform;

    protected function seedPlans(): void
    {
        foreach (SeedData::plans() as $spec) {
            [$branches, $employees, $products] = $spec['limits'];
            $plan = Plan::create([
                'name' => $spec['name'], 'code' => $spec['code'] ?? null,
                'monthly_price' => $spec['monthly'], 'yearly_price' => $spec['yearly'],
                'features' => $spec['features'], 'capabilities' => $spec['capabilities'],
                'max_branches' => $branches, 'max_employees' => $employees, 'max_products' => $products,
            ]);
            $this->plans[match ($plan->code) {
                Plan::CODE_BASIC => 'basic',
                Plan::CODE_ENTERPRISE => 'enterprise',
                default => 'professional',
            }] = $plan;
        }

        /*
         * الكتالوجُ زرعه الترحيلُ قبل أن تكون باقات — يُربط هنا كما يربطه هناك.
         * ويُزرع من ثابته إن غاب: اختبارُ تفريغٍ قبله في القسم نفسه يمحو بذورَ الترحيل.
         */
        $migration = require database_path('migrations/2026_10_30_090000_future_merchants_grow_their_subscription_with_addons.php');
        foreach ($migration::CATALOG as $row) {
            SubscriptionAddon::firstOrCreate(['code' => $row['code']], [
                'name' => $row['name'], 'name_en' => $row['name_en'], 'capability' => $row['capability'],
                'pricing' => $row['pricing'], 'unit' => $row['unit'], 'monthly_price' => $row['monthly'],
                'yearly_price' => $row['yearly'], 'for_sale' => true, 'for_new_merchants' => true, 'sort' => $row['sort'],
            ]);
        }
        foreach ($migration::PLANS as $which => $modes) {
            foreach ($modes as $mode => $codes) {
                foreach ($codes as $code) {
                    $this->addon($code)->plans()->syncWithoutDetaching([$this->plans[$which]->id => ['mode' => $mode]]);
                }
            }
        }
        Entitlements::flush();

        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'platform@addons.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    protected function addon(string $code): SubscriptionAddon
    {
        return SubscriptionAddon::where('code', $code)->firstOrFail();
    }

    /** يعتمد سعرَ الأساسيّة في سياسة الإضافات ويفتح الإطلاق */
    protected function launch(float $monthly = 4.900, float $yearly = 49.000): void
    {
        PricingPolicy::setPlanPrice($this->plans['basic'], PricingPolicy::ADDONS, $monthly, $yearly);
        PricingPolicy::setEnabled(true);
    }

    protected function shutLaunch(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => PricingPolicy::SWITCH], ['value' => '0']);
    }

    /** متجرٌ بباقته ومالكِه وفرعِه — وسياستُه كما يختمها الإنشاءُ ساعتَها */
    protected function shop(string $plan = 'basic', string $email = 'owner@shop.om'): array
    {
        $business = Business::create([
            'name' => 'متجر '.$email, 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '9'.random_int(1000000, 9999999),
            'plan_id' => $this->plans[$plan]->id, 'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString(),
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$business->fresh(), $owner];
    }

    /** يسأل من جديد — كما يسأل كلُّ طلبٍ حقيقيّ بنسخةٍ جديدة من المتجر */
    protected function allows(Business $business, string $key): bool
    {
        return PlanFeatures::allows($business->fresh(), $key);
    }

    protected function pay(AddonOrder $order): void
    {
        Billing::markPaid($order->fresh()->invoice);
    }

    protected function capabilityOf(string $code): ?string
    {
        return $this->addon($code)->capability;
    }

    protected function soldKeys(): array
    {
        return Catalog::FEATURE_KEYS;
    }
}
