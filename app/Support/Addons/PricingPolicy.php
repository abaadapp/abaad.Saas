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
     * رسائلُ ما لم يكتمل من `checks` بترتيبها: الشاشةُ تعرض الشروطَ واحدًا
     * واحدًا، والقفلُ يقرأ ما بقي منها — والاثنان من مصدرٍ واحد.
     *
     * @param  list<array{problems: list<string>}>|null  $checks  ما قُرئ للتوّ — لا يُسأل مرّتين في طلب
     * @return list<string>
     */
    public static function blockers(?array $checks = null): array
    {
        return array_values(array_merge(...array_column($checks ?? self::checks(), 'problems')));
    }

    /**
     * شروطُ الإطلاق واحدًا واحدًا — تقرؤها «التسعير والإعدادات» قائمةً تُؤشَّر.
     *
     * @return list<array{key: string, label: string, ok: bool, problems: list<string>}>
     */
    public static function checks(): array
    {
        $check = fn (string $key, string $label, array $problems) => ['key' => $key, 'label' => __($label), 'ok' => $problems === [], 'problems' => $problems];
        $basic = Plan::where('code', Plan::CODE_BASIC)->first();

        if (! $basic) {
            return [$check('basic_plan', 'الباقة الأساسيّة موجودة', [__('لا باقة أساسيّة برمز basic.')])];
        }

        $price = self::price($basic, self::ADDONS) ? [] : [__('سعر الباقة الأساسيّة في السياسة الجديدة لم يُعتمد.')];

        $capabilities = is_array($basic->capabilities) ? [] : [__('قدرات الباقة الأساسيّة غير محدّدة — حدّدها في شاشة الباقات.')];

        /*
         * ونقطةُ البيع في الأساسيّة الجديدة **تُشترى** — هذا ما تفصله السياسة.
         * فمشمولةً فيها لا تُفصل، وغيرَ معروضةٍ للبيع لا يبلغها تاجرٌ جديد.
         */
        $pos = SubscriptionAddon::where('capability', Catalog::POS)->first();
        $mode = $pos ? $pos->plans()->whereKey($basic->id)->value('subscription_addon_plans.mode') : null;
        $separate = match (true) {
            $mode === Catalog::INCLUDED => [__('نقطة البيع مشمولةٌ في الباقة الأساسيّة — والسياسة الجديدة تفصلها عنها. اجعلها «تُشترى» في الأساسيّة قبل الإطلاق.')],
            $mode !== Catalog::ELIGIBLE => [__('نقطة البيع ليست إضافةً تُشترى في الباقة الأساسيّة.')],
            ! $pos->for_sale || ! $pos->for_new_merchants => [__('نقطة البيع لا تُعرض للبيع للمتاجر الجديدة — اعرضها قبل الإطلاق.')],
            default => [],
        };

        $unpriced = SubscriptionAddon::where('for_sale', true)->where('for_new_merchants', true)
            ->where('pricing', Catalog::PAID)
            ->where(fn ($q) => $q->whereNull('monthly_price')->orWhereNull('yearly_price')->orWhere('monthly_price', '<=', 0)->orWhere('yearly_price', '<=', 0))
            ->pluck('name')
            ->map(fn ($name) => __('الإضافة المدفوعة «:n» بلا سعرٍ شهريّ وسنويّ.', ['n' => $name]))->all();

        return [
            $check('basic_price', 'سعر الباقة الأساسيّة الجديدة معتمد', $price),
            $check('basic_capabilities', 'قدرات الباقة الأساسيّة محدّدة', $capabilities),
            $check('pos_separate', 'نقطة البيع منفصلة عن الأساسيّة الجديدة ومعروضة للبيع', $separate),
            $check('catalog_priced', 'كل إضافة مدفوعة معروضة لها سعر شهري وسنوي', $unpriced),
        ];
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
        return self::planPriceFor($business, $business->plan, $cycle);
    }

    /**
     * سعرُ دورةٍ من باقةٍ بعينها لهذا المتجر — بسياسته هو.
     *
     * لترقيةٍ تُفوتَر قبل أن ينتقل المتجرُ إلى باقتها (`Billing::upgrade`)، ولمتجرٍ
     * يُنشأ من لوحة المنصّة فيُعرض سعرُه قبل حفظه.
     */
    public static function planPriceFor(Business $business, ?Plan $plan, string $cycle): float
    {
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
