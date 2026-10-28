<?php

namespace Tests\Support;

use App\Models\Plan;
use App\Models\SubscriptionAddon;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;

/**
 * التسجيلُ الذاتيّ مفتوح: الإطلاقُ مفتوحٌ وشرطُه مكتمل — كما على الإنتاج منذ 2026-10-27.
 *
 * بلا هذا يُردّ كلُّ تسجيلٍ ذاتيّ (`SelfSignupPolicyNotReady`): لا متجرَ على السياسة
 * السابقة يناقض الباقات المعلنة. ويُنادى بعد زرع الباقة الأساسيّة برمزها.
 *
 * والكتالوجُ يُزرع من ثابت الترحيل إن غاب: اختبارُ تفريغٍ قبله في القسم نفسه يمحو بذورَه.
 */
trait SelfSignupOpens
{
    protected function openSelfSignup(float $monthly = 9.900, float $yearly = 99.000): void
    {
        $basic = Plan::where('code', Plan::CODE_BASIC)->firstOrFail();
        if (! is_array($basic->capabilities)) {
            $basic->forceFill(['capabilities' => []])->save();
        }

        $migration = require database_path('migrations/2026_10_30_090000_future_merchants_grow_their_subscription_with_addons.php');
        foreach ($migration::CATALOG as $row) {
            SubscriptionAddon::firstOrCreate(['code' => $row['code']], [
                'name' => $row['name'], 'name_en' => $row['name_en'], 'capability' => $row['capability'],
                'pricing' => $row['pricing'], 'unit' => $row['unit'], 'monthly_price' => $row['monthly'],
                'yearly_price' => $row['yearly'], 'for_sale' => true, 'for_new_merchants' => true, 'sort' => $row['sort'],
            ]);
        }
        foreach ($migration::PLANS['basic']['eligible'] as $code) {
            SubscriptionAddon::where('code', $code)->firstOrFail()->plans()->syncWithoutDetaching([$basic->id => ['mode' => Catalog::ELIGIBLE]]);
        }
        SubscriptionAddon::where('code', Catalog::POS)->update(['for_sale' => true, 'for_new_merchants' => true]);
        Entitlements::flush();

        PricingPolicy::setPlanPrice($basic, PricingPolicy::ADDONS, $monthly, $yearly);
        PricingPolicy::setEnabled(true);
    }
}
