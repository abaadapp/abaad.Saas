<?php

namespace App\Support\Addons;

use App\Models\Business;
use App\Models\Plan;
use App\Support\Activity;
use App\Support\PlanFeatures;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ما يفتحه تعديلٌ يدويٌّ بلا فاتورة — يُردّ، أو يُكتب منحةً بسببها.
 *
 * شاشتا التعديل القديمتان (`BusinessController::update` و`SubscriptionController::update`)
 * تكتبان في المتجر باقتَه وتاريخَ انتهائه وحالتَه مباشرةً. ولمتجر السياسة الجديدة
 * الباقةُ تفتح ما تشمله (`Entitlements::included`) — فقائمةٌ منسدلة كانت ترقيةً
 * بلا فاتورة، والطريقُ الذي بُني في `Billing::upgrade` يُتجاوز من بابٍ جانبيّ.
 *
 * فما يفتح يُسمّى للمشغّل ويُردّ:
 *  • حسابٌ «بانتظار السداد» يُفتح أو يُعطَّل (لكلّ سياسة — الحالةُ لا تأتي إلّا من
 *    الإنشاء المدفوع، والتعطيلُ ثمّ إعادةُ التشغيل يفتحانه بلا سداد).
 *  • ولمتجر السياسة الجديدة وحدها: باقةٌ تفتح ما لم يكن له، ومدّةٌ أطول.
 *
 * إلّا منحةً صريحة: سببٌ مكتوب، وتاريخُ انتهاءٍ للحساب، وسطرٌ في سجلّ النشاط.
 * وما لا يفتح شيئًا يمرّ كما كان — تصحيحُ مبلغٍ أو تاريخٍ أقصر أو تنزيلٌ.
 * والسابقةُ لا يُسأل عنها شيء: أسعارُها وتعديلاتُها كما كانت.
 */
final class AdminGrants
{
    /** أسقفُ الباقة التي يفتح رفعُها شيئًا (`PlanLimits::LIMITS`) */
    private const CAPS = ['max_branches', 'max_employees', 'max_products'];

    /**
     * أيُسأل هذا المتجرُ عن منحة؟ — فتعرض الشاشتان زرَّها قبل الإرسال.
     *
     * السابقةُ لا يُسأل عنها شيءٌ إلّا حسابًا بانتظار السداد، فلا زرَّ لها.
     */
    public static function asked(Business $business): bool
    {
        return PricingPolicy::isAddons($business) || $business->status === Business::AWAITING_PAYMENT;
    }

    /**
     * ما يفتحه هذا التعديل — قائمةٌ تُقال للمشغّل، وفراغُها «لا شيء يُفتح».
     *
     * @param  array{plan_id?: mixed, ends_at?: mixed, status?: mixed}  $after
     */
    public static function opens(Business $business, array $after): array
    {
        $opens = [];

        if ($business->status === Business::AWAITING_PAYMENT && array_key_exists('status', $after)
            && $after['status'] !== Business::AWAITING_PAYMENT) {
            $opens[] = __('حساب بانتظار السداد');
        }

        if (! PricingPolicy::isAddons($business)) {
            return $opens;
        }

        if (! empty($after['plan_id']) && (int) $after['plan_id'] !== (int) $business->plan_id
            && ($to = Plan::find($after['plan_id'])) && self::planOpens($business->plan, $to)) {
            $opens[] = __('باقة «:plan»', ['plan' => $to->name]);
        }

        if (array_key_exists('ends_at', $after) && $business->ends_at !== null) {
            $until = $after['ends_at'] ? Carbon::parse($after['ends_at'])->startOfDay() : null;

            if ($until === null || $until->gt($business->ends_at->copy()->startOfDay())) {
                $opens[] = $until ? __('مدة حتى :date', ['date' => $until->toDateString()]) : __('مدة بلا تاريخ انتهاء');
            }
        }

        return $opens;
    }

    /**
     * يُردّ ما يفتح بلا سبب، ويُكتب منحةً ما فتح بسببه.
     *
     * يُنادى قبل الكتابة أو داخل معاملتها: الرميُ يُرجع ما كُتب.
     */
    public static function authorize(Business $business, array $after, ?string $reason): void
    {
        $opens = self::opens($business, $after);
        if ($opens === []) {
            return;
        }

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'grant_reason' => __('هذا التعديل يفتح بلا سداد: :what. رقِّ الباقة أو جدّدها من صفحة المتجر بفاتورتها، أو اكتب سبب المنحة الإدارية.', [
                    'what' => implode('، ', $opens),
                ]),
            ]);
        }

        // والمنحةُ مؤرّخة — حسابٌ يُفتح بلا سداد يُفتح إلى يومٍ مكتوب
        $until = array_key_exists('ends_at', $after) ? $after['ends_at'] : $business->ends_at;
        if (! $until || Carbon::parse($until)->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['ends_at' => __('المنحة تحتاج تاريخ انتهاء لم يمضِ.')]);
        }

        Activity::log('updated', 'منحة إدارية لـ«'.$business->name.'» بلا سداد: '.implode('، ', $opens).' — حتى '.Carbon::parse($until)->toDateString().' — '.mb_substr($reason, 0, 150), [
            'business_id' => null, 'subject_id' => $business->id, 'subject_type' => 'business',
        ]);
    }

    /**
     * أتفتح هذه الباقةُ ما لم تفتحه تلك؟ مشمولٌ أو قدرةٌ أو سقفٌ أعلى.
     *
     * والتنزيلُ لا يفتح شيئًا فيمرّ — يحرسه `PlanLimits::exceededBy` وحده.
     */
    public static function planOpens(?Plan $from, Plan $to): bool
    {
        $included = fn (?Plan $plan) => $plan === null ? [] : DB::table('subscription_addon_plans as p')
            ->join('subscription_addons as a', 'a.id', '=', 'p.subscription_addon_id')
            ->where('p.plan_id', $plan->id)->where('p.mode', Catalog::INCLUDED)
            ->whereNotNull('a.capability')->pluck('a.capability')->all();

        if (array_diff($included($to), $included($from)) !== []) {
            return true;
        }

        foreach ([...array_keys(PlanFeatures::CAPABILITIES), ...PlanFeatures::ENTERPRISE] as $key) {
            if (PlanFeatures::allowedBy($to, $key) && ! PlanFeatures::allowedBy($from, $key)) {
                return true;
            }
        }

        foreach (self::CAPS as $cap) {
            // الفراغُ والصفرُ «بلا سقف» كما يقرؤهما `PlanLimits::cap` — فلا يرتفع ما لا سقف له
            $was = (int) ($from?->{$cap} ?? 0);
            $now = (int) ($to->{$cap} ?? 0);

            if ($from !== null && $was > 0 && ($now === 0 || $now > $was)) {
                return true;
            }
        }

        return false;
    }
}
