<?php

namespace App\Support\Addons;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Plan;
use App\Models\SubscriptionAddon;
use App\Support\Billing;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use App\Support\Store\GiftOrders;
use App\Support\Tenancy;
use App\Support\WhatsAppFeature;
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
            'semiannual_price' => $addon->monthly_price === null ? null : round((float) $addon->monthly_price * 6, 3),
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
            'legacy' => self::cycles((float) $p->monthly_price, (float) $p->yearly_price),
            'addons' => isset($prices[$p->id])
                ? self::cycles((float) $prices[$p->id]->monthly_price, (float) $prices[$p->id]->yearly_price)
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

    /** الإطلاقُ وشروطُه وما ينقصه — يُقرأ ولا يُكتب: زيارةُ الشاشة لا تمسّ المفتاح */
    public static function launch(): array
    {
        $checks = PricingPolicy::checks();

        return [
            'enabled' => PricingPolicy::enabled(),
            'checks' => $checks,
            'blockers' => PricingPolicy::blockers($checks),
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
            /*
             * ما يطلبه فريقُ أبعاد للتاجر بفاتورة — ما يُباع في باقته وحدها، ولمتجر
             * سياسة الإضافات وحده (`AddonOrders::orderFor` يحكم بها هو أيضًا).
             */
            'orderable' => PricingPolicy::isAddons($business) && $business->plan_id
                ? SubscriptionAddon::where('for_sale', true)->orderBy('sort')->get()
                    ->filter(fn (SubscriptionAddon $a) => AddonOrders::modeFor($business, $a) === Catalog::ELIGIBLE)
                    ->map(fn (SubscriptionAddon $a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'pricing' => $a->pricing,
                        'counted' => Catalog::counted($a->unit),
                        'prices' => $a->pricing === Catalog::PAID
                            ? ['monthly' => Catalog::unitPrice($a, 'monthly'), 'semiannual' => Catalog::unitPrice($a, 'semiannual'), 'yearly' => Catalog::unitPrice($a, 'yearly')]
                            : null,
                    ])->values()->all()
                : [],
        ];
    }

    /**
     * شاشةُ «إضافات متجرك» — باقتُه وما يشمله، وما يُشترى بسعره، وما يسري.
     *
     * والسعرُ والشمولُ من القاعدة: الأزرارُ ترسم ما يقبله الخادم، ولا يقبل
     * الخادمُ إلّا ما يقرّره هو (`AddonOrders::request`).
     *
     * ═══ وللسياسة السابقة كتالوجٌ يُقرأ لا يُشترى ═══
     *
     * كانت تُعطى قائمةً فارغة، فيرى التاجرُ القديمُ صفحةً فيها سطرٌ واحد.
     * فصارت تُعطى `showcase`: خدماتُ أبعاد المعروضة باسمها ووصفها وحالتها
     * عنده — **بلا سعرٍ ولا رمزٍ ولا نوعِ تسعير**، فلا شيءَ في الصفحة يُشترى به.
     * والشراءُ مغلقٌ في الخادم لا في الصفحة (`AddonOrders::request`).
     */
    public static function forMerchant(Business $business): array
    {
        $policy = PricingPolicy::of($business);
        $plan = $business->plan;
        // التجربةُ المجّانيّة للعرض والاستفسار — التفعيلُ عبر فريق أبعاد، لا طلبٌ من هنا
        $trial = Tenancy::isUnpaidSelfSignup($business);
        $base = [
            'policy' => $policy,
            'trial' => $trial,
            // حالةُ الاشتراك كما يقرؤها `Business::scopeLive` — لا تخمينٌ من الشاشة
            'subscription' => [
                'status' => filled($business->status) ? __($business->status) : null,
                'live' => ! in_array($business->status, ['معطل', 'معطّل'], true)
                    && ($business->ends_at === null || $business->ends_at->gte(now()->startOfDay())),
                'ends_at' => $business->ends_at?->format('Y-m-d'),
            ],
        ];

        if ($policy !== PricingPolicy::ADDONS) {
            return $base + [
                'plan' => $plan ? ['name' => $plan->name, 'price' => null] : null,
                'showcase' => self::showcase($business),
                'included' => [],
                'available' => [],
                'active' => [],
                'orders' => [],
                'addons_total' => ['monthly' => 0, 'semiannual' => 0, 'yearly' => 0],
                'may_request' => false,
            ];
        }

        $modes = $plan ? DB::table('subscription_addon_plans')->where('plan_id', $plan->id)->pluck('mode', 'subscription_addon_id')->all() : [];
        $active = BusinessAddon::with('addon:id,name,name_en,code,unit,capability')->where('business_id', $business->id)->active()->get();
        $open = AddonOrder::with(['addon:id,name,name_en,pricing,unit', 'invoice:id,number,status'])
            ->where('business_id', $business->id)->whereIn('status', AddonOrders::OPEN)->latest('id')->get();

        $addons = SubscriptionAddon::whereIn('id', array_keys($modes))->orderBy('sort')->orderBy('id')->get();

        $planPrice = null;
        if ($plan) {
            try {
                $planPrice = ['monthly' => PricingPolicy::planPrice($business, 'monthly'), 'semiannual' => PricingPolicy::planPrice($business, 'semiannual'), 'yearly' => PricingPolicy::planPrice($business, 'yearly')];
            } catch (\DomainException) {
                $planPrice = null;
            }
        }

        $paid = $active->where('source', BusinessAddon::PURCHASE)->filter(fn ($e) => $e->cycle);

        return $base + [
            'plan' => $plan ? ['name' => $plan->name, 'price' => $planPrice] : null,
            'showcase' => [],
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
                'semiannual' => round((float) $paid->where('cycle', 'semiannual')->sum(fn ($e) => (float) $e->unit_price * $e->quantity), 3),
                'yearly' => round((float) $paid->where('cycle', 'yearly')->sum(fn ($e) => (float) $e->unit_price * $e->quantity), 3),
            ],
            'may_request' => ! $trial,
        ];
    }

    /** سعرُ الدورات الثلاث — ونصفُ السنة ستّةُ أشهرٍ بسعر الشهر، بلا عمودٍ ولا خصم */
    public static function cycles(float $monthly, float $yearly): array
    {
        return ['monthly' => $monthly, 'semiannual' => round($monthly * 6, 3), 'yearly' => $yearly];
    }

    /** في الاشتراك — يقوله حارسُ القدرة نفسُه */
    public const SHOWN_INCLUDED = 'included';

    /** لا يُعرف من مصدرٍ موثوق — «تعرّف على الخدمة» */
    public const SHOWN_LEARN = 'learn';

    /** باقتُه لا تفتحها — يقوله حارسُ القدرة نفسُه */
    public const SHOWN_NOT_INCLUDED = 'not_included';

    /**
     * خدماتُ أبعاد لمتجر السياسة السابقة — تعريفٌ بلا شراء.
     *
     * المعروضةُ للبيع (`for_sale`)، وما يملكه المتجرُ منها بمنحةٍ ساريةٍ ولو
     * أُوقف بيعُها. وحالةُ كلٍّ من الحارس الذي يفتح بابَها أو يُغلقه (`state`)،
     * لا من غياب صفٍّ في `business_addons`: متجرٌ قديمٌ لم يشترِ شيئًا يملك
     * نقطةَ البيع وكلَّ ما في باقته.
     *
     * @return list<array{id: int, capability: ?string, name: string, description: ?string, state: string, note: ?string}>
     */
    public static function showcase(Business $business): array
    {
        $held = Entitlements::rows($business)->pluck('subscription_addon_id')->unique()->all();
        $en = app()->getLocale() === 'en';

        return SubscriptionAddon::query()
            ->where(fn ($q) => $q->where('for_sale', true)->orWhereIn('id', $held))
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'name_en', 'description', 'description_en', 'capability'])
            ->map(function (SubscriptionAddon $a) use ($business, $held, $en) {
                [$state, $note] = self::state($business, $a, in_array($a->id, $held, true));

                return [
                    'id' => $a->id,
                    'capability' => $a->capability,
                    'name' => $en && filled($a->name_en) ? $a->name_en : $a->name,
                    'description' => Catalog::describe($a, $en),
                    'state' => $state,
                    'note' => $note,
                ];
            })->values()->all();
    }

    /**
     * أهذه الخدمةُ في اشتراك متجر السياسة السابقة؟ — من بابها لا من اسمها.
     *
     *   - منحةٌ ساريةٌ لها: في اشتراكه.
     *   - قدراتُ الباقة (`Catalog::FEATURE_KEYS`): `PlanFeatures::allows` — ما
     *     يفتح الصفحةَ أو يردّها بـ403. فـ«في اشتراكك» و«ليست في باقتك» كلاهما
     *     من الحارس نفسه. ونقطةُ البيع مفتوحةٌ للسابقة دائمًا.
     *   - الهدايا: عمودُها أو إضافتُها (`GiftOrders::on`). ومغلقًا لا يُقال
     *     «ليست في باقتك»: الطلباتُ المخصّصة في الإضافة نفسها مفتوحةٌ للسابقة.
     *   - الرقمُ الخاصّ: `canUseOwnNumber` وإشعاراتُ واتساب معًا.
     *   - الفروع: سقفٌ لا قدرة — يُقال حدُّ الباقة كما يحسبه `PlanLimits`.
     *   - خدمةٌ بلا باب: لا مصدرَ يقول، فـ«تعرّف على الخدمة».
     *
     * @return array{0: string, 1: ?string}
     */
    private static function state(Business $business, SubscriptionAddon $addon, bool $held): array
    {
        $capability = $addon->capability;

        if ($held) {
            return [self::SHOWN_INCLUDED, null];
        }

        if (in_array($capability, Catalog::FEATURE_KEYS, true)) {
            return [PlanFeatures::allows($business, $capability) ? self::SHOWN_INCLUDED : self::SHOWN_NOT_INCLUDED, null];
        }

        return match ($capability) {
            Catalog::GIFT_ORDERS => [GiftOrders::on($business->id) ? self::SHOWN_INCLUDED : self::SHOWN_LEARN, null],
            Catalog::OWN_NUMBER => [WhatsAppFeature::canUseOwnNumber($business) && PlanFeatures::allows($business, 'whatsapp')
                ? self::SHOWN_INCLUDED : self::SHOWN_LEARN, null],
            Catalog::BRANCHES => [self::SHOWN_LEARN, ($cap = PlanLimits::cap($business, 'branches')) === null
                ? __('باقتك بلا حدٍّ لعدد الفروع.')
                : __('حدّ الفروع في باقتك: :n', ['n' => $cap])],
            default => [self::SHOWN_LEARN, null],
        };
    }

    private static function merchantAddon(SubscriptionAddon $a): array
    {
        $en = app()->getLocale() === 'en';

        return [
            'code' => $a->code,
            'capability' => $a->capability,
            'name' => $en && filled($a->name_en) ? $a->name_en : $a->name,
            'description' => Catalog::describe($a, $en),
            'pricing' => $a->pricing,
            'unit' => $a->unit,
            'counted' => Catalog::counted($a->unit),
            'monthly_price' => $a->monthly_price === null ? null : (float) $a->monthly_price,
            'semiannual_price' => $a->monthly_price === null ? null : round((float) $a->monthly_price * 6, 3),
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
