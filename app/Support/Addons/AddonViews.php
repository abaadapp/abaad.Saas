<?php

namespace App\Support\Addons;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Plan;
use App\Models\SubscriptionAddon;
use App\Support\Billing;
use Illuminate\Support\Facades\DB;

/**
 * ما تقرؤه شاشاتُ الإضافات — لوحةُ المنصّة وشاشةُ التاجر.
 *
 * والتاجرُ لا يرى إلّا متجره: كلُّ استعلامٍ هنا بمعرّف متجرٍ يمرّره المتحكّم
 * من الجلسة. ولا يرى سعرَ تكلفةٍ ولا سببَ منحةٍ كتبه صاحبُ المنصّة.
 */
final class AddonViews
{
    /** صفُّ إضافةٍ في الكتالوج — بأماكن بيعها وعدد مشتركيها */
    public static function catalogRow(SubscriptionAddon $addon, array $subscribers): array
    {
        return [
            'id' => $addon->id,
            'code' => $addon->code,
            'name' => $addon->name,
            'name_en' => $addon->name_en,
            'description' => $addon->description,
            'description_en' => $addon->description_en,
            'capability' => $addon->capability,
            'pricing' => $addon->pricing,
            'unit' => $addon->unit,
            'monthly_price' => $addon->monthly_price === null ? null : (float) $addon->monthly_price,
            'yearly_price' => $addon->yearly_price === null ? null : (float) $addon->yearly_price,
            'for_sale' => (bool) $addon->for_sale,
            'for_new_merchants' => (bool) $addon->for_new_merchants,
            'sort' => (int) $addon->sort,
            'plans' => $addon->plans->mapWithKeys(fn (Plan $p) => [(string) $p->id => $p->pivot->mode])->all(),
            'subscribers' => (int) ($subscribers[$addon->id] ?? 0),
        ];
    }

    /** الكتالوجُ كلُّه لصاحب المنصّة */
    public static function catalog(): array
    {
        $subscribers = BusinessAddon::active()->selectRaw('subscription_addon_id, COUNT(DISTINCT business_id) as n')
            ->groupBy('subscription_addon_id')->pluck('n', 'subscription_addon_id')->map(fn ($n) => (int) $n)->all();

        return SubscriptionAddon::with('plans:id,name')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (SubscriptionAddon $a) => self::catalogRow($a, $subscribers))->all();
    }

    /** الباقاتُ وسعرُ كلٍّ في السياستين */
    public static function plans(): array
    {
        $prices = DB::table('plan_policy_prices')->where('policy', PricingPolicy::ADDONS)->get()->keyBy('plan_id');

        return Plan::orderBy('id')->get()->map(fn (Plan $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'legacy' => ['monthly' => (float) $p->monthly_price, 'yearly' => (float) $p->yearly_price],
            'addons' => isset($prices[$p->id])
                ? ['monthly' => (float) $prices[$p->id]->monthly_price, 'yearly' => (float) $prices[$p->id]->yearly_price]
                : null,
            'capabilities_set' => is_array($p->capabilities),
            'businesses' => [
                'legacy' => Business::where('plan_id', $p->id)->where('pricing_policy', PricingPolicy::LEGACY)->count(),
                'addons' => Business::where('plan_id', $p->id)->where('pricing_policy', PricingPolicy::ADDONS)->count(),
            ],
        ])->all();
    }

    /** طلباتٌ تنتظر صاحبَ المنصّة — الاعتمادَ أو السداد */
    public static function openOrders(): array
    {
        return AddonOrder::with(['addon:id,name,pricing,unit', 'business:id,name', 'invoice:id,number,status'])
            ->whereIn('status', AddonOrders::OPEN)->orderBy('id')->limit(200)->get()
            ->map(fn (AddonOrder $o) => self::orderRow($o) + ['business' => ['id' => $o->business_id, 'name' => $o->business?->name]])
            ->all();
    }

    /** المشتركون في الإضافات — الساري اليوم */
    public static function subscribers(): array
    {
        return BusinessAddon::with(['addon:id,name,code', 'business:id,name'])->active()
            ->orderBy('ends_at')->limit(500)->get()
            ->map(fn (BusinessAddon $e) => self::entitlementRow($e) + ['business' => ['id' => $e->business_id, 'name' => $e->business?->name]])
            ->all();
    }

    /** الإطلاقُ وما ينقصه */
    public static function launch(): array
    {
        return [
            'enabled' => PricingPolicy::enabled(),
            'blockers' => PricingPolicy::blockers(),
            'counts' => [
                'legacy' => Business::where('pricing_policy', PricingPolicy::LEGACY)->count(),
                'addons' => Business::where('pricing_policy', PricingPolicy::ADDONS)->count(),
            ],
        ];
    }

    /** إضافاتُ متجرٍ في صفحته بلوحة المنصّة — كلُّ ما كان وما هو */
    public static function forBusiness(Business $business): array
    {
        return [
            'policy' => PricingPolicy::of($business),
            'entitlements' => BusinessAddon::with('addon:id,name,code,unit')->where('business_id', $business->id)
                ->latest('id')->limit(100)->get()
                ->map(fn (BusinessAddon $e) => self::entitlementRow($e) + ['reason' => $e->reason])->all(),
            'orders' => AddonOrder::with(['addon:id,name,pricing,unit', 'invoice:id,number,status'])
                ->where('business_id', $business->id)->latest('id')->limit(50)->get()
                ->map(fn (AddonOrder $o) => self::orderRow($o))->all(),
            'grantable' => SubscriptionAddon::orderBy('sort')->get(['id', 'name', 'unit'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'counted' => Catalog::counted($a->unit)])->all(),
        ];
    }

    /**
     * شاشةُ «إضافات متجرك» — باقتُه وما يشمله، وما يُشترى بسعره، وما يسري له.
     *
     * والسعرُ والشمولُ من القاعدة: الأزرارُ ترسم ما يقبله الخادم، ولا يقبل
     * الخادمُ إلّا ما يقرّره هو (`AddonOrders::request`).
     */
    public static function forMerchant(Business $business): array
    {
        $policy = PricingPolicy::of($business);
        $plan = $business->plan;
        $modes = $plan ? DB::table('subscription_addon_plans')->where('plan_id', $plan->id)->pluck('mode', 'subscription_addon_id')->all() : [];
        $active = BusinessAddon::with('addon:id,name,name_en,code,unit,capability')->where('business_id', $business->id)->active()->get();
        $open = AddonOrder::with(['addon:id,name,name_en,pricing,unit', 'invoice:id,number,status'])
            ->where('business_id', $business->id)->whereIn('status', AddonOrders::OPEN)->latest('id')->get();

        $addons = $policy === PricingPolicy::ADDONS
            ? SubscriptionAddon::whereIn('id', array_keys($modes))->orderBy('sort')->orderBy('id')->get()
            : collect();

        $planPrice = null;
        if ($plan && $policy === PricingPolicy::ADDONS) {
            try {
                $planPrice = ['monthly' => PricingPolicy::planPrice($business, 'monthly'), 'yearly' => PricingPolicy::planPrice($business, 'yearly')];
            } catch (\DomainException) {
                $planPrice = null;
            }
        }

        $paid = $active->where('source', BusinessAddon::PURCHASE)->filter(fn ($e) => $e->cycle);

        return [
            'policy' => $policy,
            'plan' => $plan ? ['name' => $plan->name, 'price' => $planPrice] : null,
            'included' => $addons->filter(fn ($a) => ($modes[$a->id] ?? null) === Catalog::INCLUDED)
                ->map(fn ($a) => self::merchantAddon($a))->values()->all(),
            'available' => $addons->filter(fn ($a) => ($modes[$a->id] ?? null) === Catalog::ELIGIBLE && $a->for_sale && $a->for_new_merchants)
                ->map(fn ($a) => self::merchantAddon($a) + [
                    'active' => ! Catalog::counted($a->unit) && $active->contains('subscription_addon_id', $a->id),
                    'pending' => $open->contains(fn ($o) => $o->subscription_addon_id === $a->id && $o->kind === AddonOrders::KIND_NEW),
                ])->values()->all(),
            'active' => $active->map(fn (BusinessAddon $e) => self::entitlementRow($e))->values()->all(),
            'orders' => $open->map(fn (AddonOrder $o) => self::orderRow($o))->values()->all(),
            // ما تدفعه الإضافاتُ السارية في دورتها — يُقرأ بجوار سعر الباقة، ولكلٍّ فاتورتُها
            'addons_total' => [
                'monthly' => round((float) $paid->where('cycle', 'monthly')->sum(fn ($e) => (float) $e->unit_price * $e->quantity), 3),
                'yearly' => round((float) $paid->where('cycle', 'yearly')->sum(fn ($e) => (float) $e->unit_price * $e->quantity), 3),
            ],
            'may_request' => $policy === PricingPolicy::ADDONS,
        ];
    }

    private static function merchantAddon(SubscriptionAddon $a): array
    {
        $en = app()->getLocale() === 'en';

        return [
            'code' => $a->code,
            'name' => $en && filled($a->name_en) ? $a->name_en : $a->name,
            'description' => $en && filled($a->description_en) ? $a->description_en : $a->description,
            'pricing' => $a->pricing,
            'unit' => $a->unit,
            'counted' => Catalog::counted($a->unit),
            'monthly_price' => $a->monthly_price === null ? null : (float) $a->monthly_price,
            'yearly_price' => $a->yearly_price === null ? null : (float) $a->yearly_price,
        ];
    }

    public static function entitlementRow(BusinessAddon $e): array
    {
        return [
            'id' => $e->id,
            'addon' => ['id' => $e->subscription_addon_id, 'name' => $e->addon?->name, 'code' => $e->addon?->code],
            'quantity' => (int) $e->quantity,
            'source' => $e->source,
            'cycle' => $e->cycle,
            'unit_price' => (float) $e->unit_price,
            'starts_at' => $e->starts_at?->format('Y-m-d'),
            'ends_at' => $e->ends_at?->format('Y-m-d'),
            'renews' => (bool) $e->renews,
            'cancelled_at' => $e->cancelled_at?->format('Y-m-d'),
            'active' => $e->isActive(),
        ];
    }

    public static function orderRow(AddonOrder $o): array
    {
        return [
            'id' => $o->id,
            // نوعُ التسعير كما ثُبّت في الطلب — لا كما صار في الكتالوج
            'addon' => ['id' => $o->subscription_addon_id, 'name' => $o->addon?->name, 'pricing' => $o->pricing],
            'kind' => $o->kind,
            'cycle' => $o->cycle,
            'quantity' => (int) $o->quantity,
            'unit_price' => $o->unit_price === null ? null : (float) $o->unit_price,
            'amount' => $o->amount === null ? null : (float) $o->amount,
            'status' => $o->status,
            'invoice' => $o->invoice ? ['id' => $o->invoice->id, 'number' => $o->invoice->number, 'paid' => $o->invoice->status === Billing::PAID] : null,
            'requested_by' => $o->requested_by_name,
            'created_at' => $o->created_at?->format('Y-m-d'),
        ];
    }
}
