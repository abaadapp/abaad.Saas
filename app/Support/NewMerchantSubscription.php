<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Catalog;
use App\Support\Addons\PricingPolicy;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * اشتراكُ تاجرٍ يفتحه مديرُ المنصّة — بإحدى ثلاث طرقٍ صريحة.
 *
 *   - `trial`: تجربةُ الأساسيّة كما في التسجيل الذاتيّ — بلا فاتورة، وتنتهي بمدّتها.
 *   - `paid`: باقةٌ ودورة: تصدر فاتورتُها وفواتيرُ إضافاته (`AddonOrders::orderFor`)،
 *     والحسابُ «بانتظار السداد» لا يُفتح حتى تُسدَّد فاتورةُ الباقة (`Billing::markPaid`).
 *   - `grant`: منحةٌ إداريّة — حسابٌ مفتوحٌ بلا سداد حتى تاريخٍ مكتوب، وبسببٍ مكتوب.
 *     ومعها فاتورةٌ إن اختيرت دورة: يعمل حتى السداد، ثمّ تبدأ دورتُه المدفوعة.
 *
 * والسياسةُ سياسةُ المتجر يومَ يُنشأ (`PricingPolicy::forNewMerchant`) — يحكم بها الخادم
 * لا الشاشة: سعرُ الباقة سعرُها فيها، والإضافاتُ لا تُطلب لمتجر السياسة السابقة.
 */
final class NewMerchantSubscription
{
    public const TRIAL = 'trial';

    public const PAID = 'paid';

    public const GRANT = 'grant';

    public const MODES = [self::TRIAL, self::PAID, self::GRANT];

    /** ما تحتاجه شاشةُ الإنشاء: السياسةُ، والأسعارُ بدوراتها، والإضافاتُ لكلّ باقة */
    public static function options(): array
    {
        $policy = PricingPolicy::forNewMerchant();
        $probe = new Business(['pricing_policy' => $policy]);
        $plans = Plan::orderBy('monthly_price')->get();

        $addons = $policy === PricingPolicy::ADDONS
            ? SubscriptionAddon::with('plans')->where('for_sale', true)->where('for_new_merchants', true)->orderBy('sort')->get()
            : collect();

        return [
            'policy' => $policy,
            'trial_days' => self::trialDays(),
            'basic_plan_id' => Plan::where('code', Plan::CODE_BASIC)->value('id'),
            'cycles' => [
                ['value' => 'monthly', 'label' => 'شهري'],
                ['value' => 'semiannual', 'label' => 'نصف سنوي'],
                ['value' => 'yearly', 'label' => 'سنوي'],
            ],
            'plans' => $plans->map(function (Plan $plan) use ($probe, $addons) {
                $modes = $addons->mapWithKeys(fn (SubscriptionAddon $a) => [
                    $a->id => $a->plans->firstWhere('id', $plan->id)?->pivot?->mode,
                ]);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'prices' => self::prices($probe, $plan),
                    'included' => $addons->filter(fn ($a) => $modes[$a->id] === Catalog::INCLUDED)
                        ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
                    'eligible' => $addons->filter(fn ($a) => $modes[$a->id] === Catalog::ELIGIBLE)
                        ->map(fn ($a) => [
                            'id' => $a->id,
                            'name' => $a->name,
                            'pricing' => $a->pricing,
                            'counted' => Catalog::counted($a->unit),
                            'prices' => $a->pricing === Catalog::PAID
                                ? ['monthly' => Catalog::unitPrice($a, 'monthly'), 'semiannual' => Catalog::unitPrice($a, 'semiannual'), 'yearly' => Catalog::unitPrice($a, 'yearly')]
                                : null,
                        ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * يفتح اشتراكَ المتجر الجديد بالطريقة المختارة — في معاملة الإنشاء نفسِها.
     *
     * @return array{invoices: list<string>}
     */
    public static function start(Business $business, array $data, User $by): array
    {
        $mode = $data['subscription_mode'];
        $invoices = [];

        if ($mode === self::TRIAL) {
            return ['invoices' => []];
        }

        if (! empty($data['cycle'])) {
            $invoices[] = Billing::renew($business, $data['cycle'])['invoice']->number;
        }

        foreach ($data['addons'] ?? [] as $row) {
            $order = AddonOrders::orderFor(
                $business,
                SubscriptionAddon::findOrFail($row['addon_id']),
                $data['cycle'],
                (int) ($row['quantity'] ?? 1),
                $by,
                isset($row['unit_price']) && $row['unit_price'] !== '' ? (float) $row['unit_price'] : null,
                $row['note'] ?? null,
            );
            if ($order->invoice) {
                $invoices[] = $order->invoice->number;
            }
        }

        if ($mode === self::GRANT) {
            Activity::log('created', 'منح «'.$business->name.'» اشتراك «'.$business->plan?->name.'» بلا سداد حتى '.$business->ends_at?->format('Y-m-d').' — '.mb_substr(trim((string) $data['grant_reason']), 0, 150), [
                'business_id' => null, 'subject_id' => $business->id, 'subject_type' => 'business',
            ]);
        }

        return ['invoices' => $invoices];
    }

    /**
     * ما يُكتب على صفّ المتجر قبل إنشائه — باقتُه وحالتُه وتاريخاه بحسب الطريقة.
     *
     * والتجربةُ على الأساسيّة أيًّا كانت الباقةُ المختارة — ومنصّةٌ بلا باقةٍ أساسيّةٍ
     * (قبل زرعها) تُبقي باقتَها الافتراضيّة كما كانت. والمدفوعُ بلا منحةٍ «بانتظار
     * السداد» بلا أيّامٍ قبل السداد؛ والمنحةُ حتى تاريخها.
     */
    public static function shape(array $data, mixed $planId = null): array
    {
        $today = now()->startOfDay();

        return match ($data['subscription_mode']) {
            self::TRIAL => [
                'plan_id' => Plan::where('code', Plan::CODE_BASIC)->value('id') ?? ($planId ?: null),
                'status' => 'نشط',
                'starts_at' => $today->toDateString(),
                'ends_at' => $today->copy()->addDays(self::trialDays())->toDateString(),
            ],
            self::PAID => [
                'status' => Business::AWAITING_PAYMENT,
                'starts_at' => $today->toDateString(),
                'ends_at' => $today->toDateString(),
            ],
            self::GRANT => [
                'status' => 'نشط',
                'starts_at' => $today->toDateString(),
                'ends_at' => Carbon::parse($data['grant_until'])->toDateString(),
            ],
        };
    }

    /** سعرُ الباقة بدوراتها في السياسة — وما لم يُعتمد سعرُه فيها فارغ */
    private static function prices(Business $probe, Plan $plan): ?array
    {
        try {
            return [
                'monthly' => PricingPolicy::planPriceFor($probe, $plan, 'monthly'),
                'semiannual' => PricingPolicy::planPriceFor($probe, $plan, 'semiannual'),
                'yearly' => PricingPolicy::planPriceFor($probe, $plan, 'yearly'),
            ];
        } catch (DomainException) {
            return null;
        }
    }

    public static function trialDays(): int
    {
        return max(1, (int) Tenancy::platform('trial_days', Signup::TRIAL_DAYS));
    }
}
