<?php

namespace App\Support\Addons;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlanPolicyPrice;
use App\Models\Setting;
use App\Models\SubscriptionAddon;
use App\Support\Activity;
use App\Support\Tenancy;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * سياسةُ تسعير المتجر — تُختم عليه يومَ يُنشأ، ولا تتبدّل بعده إلّا بقرار.
 *
 * ═══ سياستان ═══
 *
 *   - `legacy`: كلُّ متجرٍ قائمٍ قبل الإطلاق، وكلُّ متجرٍ يُنشأ والإطلاقُ
 *     مطفأ. باقتُه وقدراتُها وأسعارُها كما كانت، ونقطةُ البيع مفتوحةٌ له كما
 *     كانت لكلّ باقة. لا يُطلب منه شيء ولا يُضاف عليه شيء.
 *   - `addons_v1`: متجرٌ أُنشئ بعد فتح الإطلاق. باقتُه بسعرها في هذه السياسة
 *     (`plan_policy_prices`)، وقدراتُ الكتالوج (`Catalog::FEATURE_KEYS`) تُفتح
 *     بشمولها في باقته أو بإضافةٍ ساريةٍ له — ونقطةُ البيع منها.
 *
 * ═══ ولمَ عمودٌ لا تاريخ ═══
 *
 * مقارنةُ `created_at` بيوم الإطلاق في كلّ طلبٍ تتبدّل بتعديل يوم الإطلاق،
 * وبمتجرٍ يُنقل بين قواعد، وبإطلاقٍ يُطفأ ثمّ يُفتح. والعمودُ يقول ما خُتم
 * يومها ولا يتبعه شيء — لا التجديدُ ولا الترقيةُ ولا مرورُ السنة.
 *
 * ═══ والإطلاقُ مقبضٌ لا تاريخ ═══
 *
 * `new_merchant_addon_pricing_enabled` في إعدادات المنصّة. مطفأٌ افتراضًا،
 * ولا يُفتح ما بقي شرطٌ ناقص (`blockers`). وإطفاؤه يُعيد التسجيلَ الجديد إلى
 * السابقة ولا يمسّ متجرًا خُتمت سياستُه.
 */
final class PricingPolicy
{
    public const LEGACY = 'legacy';

    public const ADDONS = 'addons_v1';

    public const POLICIES = [self::LEGACY, self::ADDONS];

    /** مفتاحُ الإطلاق في إعدادات المنصّة */
    public const SWITCH = 'new_merchant_addon_pricing_enabled';

    public static function enabled(): bool
    {
        return (string) Tenancy::platform(self::SWITCH, '0') === '1';
    }

    /**
     * سياسةُ متجرٍ يُنشأ الآن.
     *
     * والإطلاقُ مفتوحٌ وشرطُه ناقص — بعد أن فُتح ثمّ حُذف سعرٌ مثلًا — يُنشئ
     * على السابقة ويُكتب في السجلّ: متجرٌ على سياسةٍ بلا سعرٍ لا تُصدر له فاتورة.
     */
    public static function forNewMerchant(): string
    {
        try {
            if (! self::enabled()) {
                return self::LEGACY;
            }

            $blockers = self::blockers();
            if ($blockers !== []) {
                Log::warning('pricing policy: الإطلاق مفتوح وشرطُه ناقص — أُنشئ المتجر على السياسة السابقة', ['blockers' => $blockers]);

                return self::LEGACY;
            }

            return self::ADDONS;
        } catch (\Throwable) {
            // قاعدةٌ لم تُهاجَر بعد (أوّلُ تنصيب): السابقة
            return self::LEGACY;
        }
    }

    public static function of(?Business $business): string
    {
        return in_array($business?->pricing_policy, self::POLICIES, true) ? $business->pricing_policy : self::LEGACY;
    }

    public static function isAddons(?Business $business): bool
    {
        return self::of($business) === self::ADDONS;
    }

    /**
     * ما يمنع فتحَ الإطلاق — قائمةٌ تُقرأ، وفارغةٌ إن اكتمل.
     *
     * @return list<string>
     */
    public static function blockers(): array
    {
        $out = [];
        $basic = Plan::where('code', Plan::CODE_BASIC)->first();

        if (! $basic) {
            return [__('لا باقة أساسيّة برمز basic.')];
        }

        if (! self::price($basic, self::ADDONS)) {
            $out[] = __('سعر الباقة الأساسيّة في السياسة الجديدة لم يُعتمد.');
        }

        if (! is_array($basic->capabilities)) {
            $out[] = __('قدرات الباقة الأساسيّة غير محدّدة — حدّدها في شاشة الباقات.');
        }

        $pos = SubscriptionAddon::where('capability', Catalog::POS)->whereHas('plans', fn ($q) => $q->whereKey($basic->id))->exists();
        if (! $pos) {
            $out[] = __('نقطة البيع ليست إضافةً تُشترى أو تُشمل في الباقة الأساسيّة.');
        }

        $unpriced = SubscriptionAddon::where('for_sale', true)->where('for_new_merchants', true)
            ->where('pricing', Catalog::PAID)
            ->where(fn ($q) => $q->whereNull('monthly_price')->orWhereNull('yearly_price')->orWhere('monthly_price', '<=', 0)->orWhere('yearly_price', '<=', 0))
            ->pluck('name');
        foreach ($unpriced as $name) {
            $out[] = __('الإضافة المدفوعة «:n» بلا سعرٍ شهريّ وسنويّ.', ['n' => $name]);
        }

        return $out;
    }

    /** يفتح الإطلاق أو يطفئه — والفتحُ يُردّ ما بقي شرطٌ ناقص */
    public static function setEnabled(bool $on): void
    {
        if ($on && ($blockers = self::blockers()) !== []) {
            throw new DomainException(__('لا يُفتح الإطلاق قبل اكتماله: :list', ['list' => implode(' · ', $blockers)]));
        }

        Setting::updateOrCreate(['business_id' => null, 'key' => self::SWITCH], ['value' => $on ? '1' : '0']);

        Activity::log('updated', $on
            ? 'فتح إطلاق سياسة الإضافات للمتاجر الجديدة'
            : 'أطفأ إطلاق سياسة الإضافات — المتاجر الجديدة على السياسة السابقة، ولا يُمسّ من خُتمت سياسته', ['business_id' => null]);
    }

    public static function price(Plan $plan, string $policy): ?PlanPolicyPrice
    {
        return PlanPolicyPrice::where('plan_id', $plan->id)->where('policy', $policy)->first();
    }

    /** يعتمد سعرَ باقةٍ في سياسة — والفواتيرُ الصادرة قبله بمبالغها */
    public static function setPlanPrice(Plan $plan, string $policy, float $monthly, float $yearly): PlanPolicyPrice
    {
        if ($policy !== self::ADDONS) {
            throw new DomainException(__('سعر السياسة السابقة هو سعر الباقة نفسها — يُعدَّل من شاشة الباقات.'));
        }
        if ($monthly <= 0 || $yearly <= 0) {
            throw new DomainException(__('السعر الشهريّ والسنويّ أكبر من صفر.'));
        }

        $row = PlanPolicyPrice::updateOrCreate(
            ['plan_id' => $plan->id, 'policy' => $policy],
            ['monthly_price' => round($monthly, 3), 'yearly_price' => round($yearly, 3)],
        );

        Activity::log('updated', 'اعتمد سعر «'.$plan->name.'» في سياسة الإضافات: '.number_format($monthly, 3).' شهريًّا — '.number_format($yearly, 3).' سنويًّا', ['business_id' => null, 'subject_id' => $plan->id]);

        return $row;
    }

    /**
     * سعرُ دورةٍ من باقة المتجر في سياسته.
     *
     * السابقة: سعرُ الباقة كما كان. والجديدة: سعرُها المعتمد فيها؛ والأساسيّةُ
     * بلا سعرٍ معتمد لا تُفوتَر (سعرُها القديم يساوي سعرَ إضافة نقطة البيع، فلا
     * يُفترض أنّه هو). والباقاتُ الأخرى بلا سعرٍ فيها تُفوتَر بسعرها كما هو.
     */
    public static function planPrice(Business $business, string $cycle): float
    {
        $plan = $business->plan;
        if (! $plan) {
            return 0.0;
        }

        [$monthly, $yearly] = [(float) $plan->monthly_price, (float) $plan->yearly_price];

        if (self::isAddons($business)) {
            $row = self::price($plan, self::ADDONS);

            if ($row) {
                [$monthly, $yearly] = [(float) $row->monthly_price, (float) $row->yearly_price];
            } elseif ($plan->code === Plan::CODE_BASIC) {
                throw new DomainException(__('سعر الباقة الأساسيّة في السياسة الجديدة لم يُعتمد — لا تُصدر فاتورة قبل اعتماده.'));
            }
        }

        return match ($cycle) {
            'yearly' => $yearly,
            'semiannual' => round($monthly * 6, 3),
            default => $monthly,
        };
    }

    /**
     * نقلُ متجرٍ بين السياستين — صريحٌ بيد صاحب المنصّة وبسبب، ولا يُنادى من نفسه.
     *
     * لا يمسّ باقةً ولا استحقاقًا ولا فاتورة: يبدّل الختمَ وحده ويكتب لماذا.
     * ولا بابَ له في الشاشات بعد — يُفتح يومَ يُقرَّر الانتقالُ وشروطُه.
     */
    public static function migrate(Business $business, string $to, string $reason): Business
    {
        if (! in_array($to, self::POLICIES, true)) {
            throw new DomainException(__('سياسة تسعيرٍ غير معروفة.'));
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException(__('اكتب سبب النقل.'));
        }

        $from = self::of($business);
        if ($from === $to) {
            return $business;
        }

        $business->forceFill(['pricing_policy' => $to])->save();
        Entitlements::flush();

        Activity::log('updated', 'نقل «'.$business->name.'» من سياسة '.$from.' إلى '.$to.' — '.mb_substr(trim($reason), 0, 150), [
            'business_id' => null, 'subject_id' => $business->id, 'subject_type' => 'business',
        ]);

        return $business;
    }
}
