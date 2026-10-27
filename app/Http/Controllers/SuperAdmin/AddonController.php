<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Plan;
use App\Models\SubscriptionAddon;
use App\Support\Activity;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\AddonViews;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\Demo;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «إضافات الاشتراكات» في لوحة المنصّة — الكتالوجُ والطلباتُ والمشتركون والإطلاق.
 *
 * داخل `role:super_admin`. وكلُّ تغييرٍ في استحقاق متجرٍ يمرّ بـ`AddonOrders` —
 * لا كتابةَ مباشرةً في جداولها من هنا.
 */
class AddonController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Subscriptions/Addons', [
            'currency' => Demo::displayCurrency(),
            'catalog' => AddonViews::catalog(),
            'plans' => AddonViews::plans(),
            'orders' => AddonViews::openOrders(),
            'subscribers' => AddonViews::subscribers(),
            'launch' => AddonViews::launch(),
            'capabilityOptions' => Catalog::capabilityOptions(),
            'options' => ['pricing' => Catalog::PRICING, 'units' => Catalog::UNITS, 'modes' => Catalog::MODES],
        ]);
    }

    /**
     * إضافةٌ جديدة — والرمزُ يولّده الخادم (`Catalog::newCode`).
     *
     * لا يُقرأ من الطلب ولو أُرسل: الرمزُ مفتاحٌ تقرؤه الطلباتُ والاستحقاقات،
     * ورمزٌ يختاره المرسلُ قد يكون رمزَ إضافةٍ أُخرى يومًا. وتصادمٌ نادرٌ بين
     * الفحص والكتابة (طلبان في اللحظة نفسها) يُعاد برمزٍ آخر.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        for ($try = 1; ; $try++) {
            try {
                // في معاملةٍ وحدها: كتابةٌ ترتدّ على PostgreSQL لا تُفسد ما بعدها
                $addon = DB::transaction(fn () => SubscriptionAddon::create(['code' => Catalog::newCode()] + $data));
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 3 || SubscriptionAddon::where('capability', $data['capability'] ?? '')->whereNotNull('capability')->exists()) {
                    throw $e;
                }
            }
        }
        Entitlements::flush();
        Activity::log('created', 'أضاف إضافة اشتراك: '.$addon->name.' ('.$addon->code.')', ['business_id' => null, 'subject_id' => $addon->id]);

        return back()->with('toast', ['msg' => __('أُضيفت الإضافة'), 'type' => 'success']);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $addon = SubscriptionAddon::findOrFail($id);
        $data = $this->validated($request, $addon);

        /*
         * وما تفتحه لا يتبدّل تحت من اشتراها ولا تحت من طلبها: استحقاقٌ دُفع —
         * أو فاتورةٌ صدرت — ليفتح نقطةَ البيع لا يصير يفتح غيرَها. تُنشأ إضافةٌ
         * أخرى بدلها.
         */
        if (($data['capability'] ?? null) !== $addon->capability
            && ($addon->entitlements()->exists() || AddonOrder::where('subscription_addon_id', $addon->id)->whereIn('status', AddonOrders::OPEN)->exists())) {
            return back()->withErrors(['capability' => __('لهذه الإضافة استحقاقاتٌ أو طلباتٌ مفتوحة — لا يتغيّر ما تفتحه. أنشئ إضافةً أخرى.')]);
        }

        // والرمزُ ثابت: لا يُقرأ من الطلب أصلًا (`validated`)، فلا يتبدّل باسمٍ ولا بتعديل
        $refused = $this->keepingTheLaunch('addon', function () use ($addon, $data) {
            $addon->update($data);
            Activity::log('updated', 'عدّل إضافة الاشتراك: '.$addon->name, ['business_id' => null, 'subject_id' => $addon->id]);
        });
        if ($refused) {
            return $refused;
        }

        return back()->with('toast', ['msg' => __('حُفظت الإضافة — الطلبات والفواتير الصادرة بأسعارها'), 'type' => 'success']);
    }

    /**
     * أين تُباع الإضافة وأين تُشمل — باقةً باقة.
     *
     * والشمولُ لا يُسحب من باقةٍ عليها متاجرُ السياسة الجديدة: تفتح لهم ما
     * اعتمدوا عليه، وسحبُه يُغلقه عليهم في صمت.
     */
    public function plans(Request $request, int $id): RedirectResponse
    {
        $addon = SubscriptionAddon::findOrFail($id);
        $data = $request->validate([
            'plans' => ['present', 'array'],
            'plans.*' => ['nullable', Rule::in(Catalog::MODES)],
        ]);

        $wanted = collect($data['plans'])->filter()->mapWithKeys(fn ($mode, $planId) => [(int) $planId => $mode]);
        $known = Plan::whereIn('id', $wanted->keys())->pluck('id')->all();
        if (count($known) !== $wanted->count()) {
            return back()->withErrors(['plans' => __('باقةٌ غير موجودة.')]);
        }

        $current = DB::table('subscription_addon_plans')->where('subscription_addon_id', $addon->id)->pluck('mode', 'plan_id');

        foreach ($current as $planId => $mode) {
            if ($mode === Catalog::INCLUDED && ($wanted[$planId] ?? null) !== Catalog::INCLUDED
                && Business::where('plan_id', $planId)->where('pricing_policy', PricingPolicy::ADDONS)->exists()) {
                return back()->withErrors(['plans' => __('«:n» مشمولةٌ في باقةٍ عليها متاجرُ السياسة الجديدة — لا يُسحب شمولُها.', ['n' => $addon->name])]);
            }
        }

        $refused = $this->keepingTheLaunch('plans', function () use ($addon, $wanted) {
            $addon->plans()->sync($wanted->map(fn ($mode) => ['mode' => $mode])->all());
            Activity::log('updated', 'عدّل باقات إضافة الاشتراك: '.$addon->name, ['business_id' => null, 'subject_id' => $addon->id]);
        });
        if ($refused) {
            return $refused;
        }

        return back()->with('toast', ['msg' => __('حُفظت باقات الإضافة'), 'type' => 'success']);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => AddonOrders::approve(
            AddonOrder::findOrFail($id),
            isset($data['unit_price']) ? (float) $data['unit_price'] : null,
            $data['note'] ?? null,
        ), __('اعتُمد الطلب — وتسري الإضافة بتسجيل سداد فاتورتها'));
    }

    public function cancelOrder(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => AddonOrders::cancel(AddonOrder::findOrFail($id), $data['note'] ?? null), __('أُلغي الطلب'));
    }

    public function grant(Request $request, int $businessId): RedirectResponse
    {
        $data = $request->validate([
            'addon_id' => ['required', 'integer', 'exists:subscription_addons,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Catalog::MAX_QUANTITY],
            'ends_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return $this->attempt(fn () => AddonOrders::grant(
            Business::findOrFail($businessId),
            SubscriptionAddon::findOrFail($data['addon_id']),
            (int) ($data['quantity'] ?? 1),
            $data['ends_at'] ?? null,
            $data['reason'],
            $request->user(),
        ), __('مُنحت الإضافة'));
    }

    public function renew(int $id): RedirectResponse
    {
        return $this->attempt(fn () => AddonOrders::renew(BusinessAddon::findOrFail($id)), __('صدرت فاتورة التجديد — تُمدّ الإضافة بتسجيل سدادها'));
    }

    public function renews(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['renews' => ['required', 'boolean']]);

        return $this->attempt(fn () => AddonOrders::setRenews(BusinessAddon::findOrFail($id), (bool) $data['renews']), __('حُفظ'));
    }

    public function end(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->attempt(fn () => AddonOrders::end(BusinessAddon::findOrFail($id), $data['reason']), __('أُنهيت الإضافة — ولم يُحذف شيءٌ من بيانات المتجر'));
    }

    /**
     * يفتح الإطلاق أو يطفئه — والفتحُ بموافقةٍ صريحة تصل مع الطلب.
     *
     * والشروطُ تُفحص في `PricingPolicy::setEnabled`؛ والموافقةُ هنا تقول إنّ
     * صاحب المنصّة قرأ أثرَ الفتح في نافذته ووافق عليه، لا إنّ زرًّا ضُغط.
     */
    public function launch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'acknowledged' => ['exclude_unless:enabled,1,true', 'accepted'],
        ], [
            'acknowledged.accepted' => __('أكّد أنك قرأت أثر التفعيل قبل تفعيل نظام الإضافات.'),
        ]);

        return $this->attempt(fn () => PricingPolicy::setEnabled((bool) $data['enabled']), $data['enabled']
            ? __('فُعّل نظام الإضافات للتسجيلات الجديدة — المتاجر القائمة كما هي')
            : __('أُوقف نظام الإضافات للتسجيلات الجديدة — ولم يُمسّ متجرٌ قائم ولا استحقاقه'));
    }

    public function planPrice(Request $request, int $planId): RedirectResponse
    {
        $data = $request->validate([
            'monthly' => ['required', 'numeric', 'min:0.001', 'max:100000'],
            'yearly' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
        ]);

        return $this->attempt(fn () => PricingPolicy::setPlanPrice(Plan::findOrFail($planId), PricingPolicy::ADDONS, (float) $data['monthly'], (float) $data['yearly']), __('اعتُمد سعر الباقة في سياسة الإضافات'));
    }

    /**
     * يحفظ تعديلَ الكتالوج — ويردّه كلَّه إن ترك الإطلاقَ المفتوحَ ناقصًا.
     *
     * والإطلاقُ لا يُفتح إلّا مكتملًا (`PricingPolicy::setEnabled`)؛ وهذا يُبقيه
     * مكتملًا ما دام مفتوحًا: نقطةُ بيعٍ تُشمل في الأساسيّة، أو تُرفع من بيعها،
     * أو إضافةٌ مدفوعةٌ بلا سعر — كلُّها تُرجع كلَّ متجرٍ جديدٍ إلى السابقة في
     * صمت. فإن أُريد شيءٌ منها أُطفئ الإطلاقُ أوّلًا، وهذا قرارٌ يُرى.
     */
    private function keepingTheLaunch(string $field, callable $change): ?RedirectResponse
    {
        try {
            DB::transaction(function () use ($change) {
                $change();
                Entitlements::flush();

                if (PricingPolicy::enabled() && ($blockers = PricingPolicy::blockers()) !== []) {
                    throw new DomainException(__('الإطلاق مفتوح وهذا التعديل يتركه ناقصًا: :list — أطفئ الإطلاق أوّلًا إن أردته.', ['list' => implode(' · ', $blockers)]));
                }
            });
        } catch (DomainException $e) {
            return back()->withErrors([$field => $e->getMessage()])->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        } finally {
            Entitlements::flush();
        }

        return null;
    }

    /** يُنفّذ الفعلَ ويقول نتيجتَه — وسببُ الرفض يُرى في الشاشة */
    private function attempt(callable $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger'])->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $ok, 'type' => 'success']);
    }

    private function validated(Request $request, ?SubscriptionAddon $addon): array
    {
        $data = $request->validate([
            // والرمزُ لا قاعدةَ له هنا: لا يُكتب باليد ولا يُقرأ من الطلب — `store`
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'capability' => ['nullable', Rule::in(array_keys(Catalog::CAPABILITIES)), Rule::unique('subscription_addons', 'capability')->ignore($addon?->id)],
            'pricing' => ['required', Rule::in(Catalog::PRICING)],
            'unit' => ['required', Rule::in(Catalog::UNITS)],
            'monthly_price' => ['nullable', 'required_if:pricing,'.Catalog::PAID, 'numeric', 'min:0.001', 'max:100000'],
            'yearly_price' => ['nullable', 'required_if:pricing,'.Catalog::PAID, 'numeric', 'min:0.001', 'max:1000000'],
            'for_sale' => ['required', 'boolean'],
            'for_new_merchants' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ], [], [
            'name' => __('الاسم'), 'capability' => __('ما تفتحه'),
            'monthly_price' => __('السعر الشهري'), 'yearly_price' => __('السعر السنوي'),
        ]);

        // والفرعُ الإضافيّ يُباع بالفرع — والقدرةُ بالمتجر
        if (($data['capability'] ?? null) === Catalog::BRANCHES && $data['unit'] !== Catalog::UNIT_BRANCH) {
            throw ValidationException::withMessages(['unit' => __('الفروع الإضافيّة تُباع بالفرع.')]);
        }

        if ($data['pricing'] !== Catalog::PAID) {
            $data['monthly_price'] = null;
            $data['yearly_price'] = null;
        }

        $data['sort'] = (int) ($data['sort'] ?? 0);

        return $data;
    }
}
