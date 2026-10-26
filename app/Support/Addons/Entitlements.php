<?php

namespace App\Support\Addons;

use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * ما يستحقّه المتجر من الإضافات اليوم — ويُسأل عنه كلُّ حارس.
 *
 * ═══ ثلاثةُ مصادر ═══
 *
 *   1. **مشمولةٌ في باقته** (`subscription_addon_plans.mode = included`) — لمتاجر
 *      السياسة الجديدة وحدها: شمولُ السابقة يبقى في `plans.capabilities` كما كان،
 *      فتعديلُ الكتالوج لا يفتح على متجرٍ قائمٍ شيئًا ولا يُغلق.
 *   2. **إضافةٌ ساريةٌ اشتراها** — بدأت ولم تنقضِ ولم تُلغَ.
 *   3. **منحةٌ ساريةٌ** من صاحب المنصّة — صفٌّ كالشراء، مصدرُه `grant` وسببُه مكتوب.
 *
 * والانقضاءُ يُقرأ من التاريخ: لا مهمّةَ تُغلقه ولا صفَّ يُحذف، ولا يُمحى بانقضائه
 * شيءٌ ممّا بناه المتجر. والمصدرُ الرابع — المتطلّباتُ التقنيّة (ربطُ رقمٍ، بوّابةٌ
 * مكتملة) — يبقى في حارس كلّ ميزةٍ كما كان.
 *
 * ═══ ويُقرأ مرّةً للمتجر في الطلب ═══
 *
 * الواجهةُ تسأل عن كلّ قدرةٍ في كلّ صفحة. فالسارياتُ تُحمَّل مرّةً لنسخة المتجر
 * وتُحفظ معها (`WeakMap`)، وكلُّ كتابةٍ في الاستحقاقات تُبطلها (`flush`).
 */
final class Entitlements
{
    private static ?WeakMap $rows = null;

    private static ?WeakMap $included = null;

    private static int $epoch = 0;

    /** كتابةٌ في الاستحقاقات أو الكتالوج — ما حُفظ قبلها لا يُقرأ */
    public static function flush(): void
    {
        self::$epoch++;
    }

    /**
     * هل يملك المتجر هذه القدرة من الإضافات؟
     *
     * للسياسة الجديدة: مشمولةٌ في باقته أو ساريةٌ له. وللسابقة: ساريةٌ له وحدها —
     * منحةٌ من صاحب المنصّة تفتح فوق باقته ولا تُغلق ما فيها.
     */
    public static function has(Business $business, string $capability): bool
    {
        if (PricingPolicy::isAddons($business) && self::included($business->plan, $capability)) {
            return true;
        }

        return self::active($business, $capability);
    }

    /** إضافةٌ ساريةٌ لهذه القدرة — شراءٌ أو منحة */
    public static function active(Business $business, string $capability): bool
    {
        return self::rows($business)->contains(fn (BusinessAddon $row) => $row->addon?->capability === $capability);
    }

    /** هل تشمل هذه الباقةُ القدرةَ في السياسة الجديدة؟ */
    public static function included(?Plan $plan, string $capability): bool
    {
        if ($plan === null) {
            return false;
        }

        self::$included ??= new WeakMap;
        $hit = self::$included[$plan] ?? null;

        if ($hit === null || $hit['epoch'] !== self::$epoch) {
            $hit = ['epoch' => self::$epoch, 'keys' => DB::table('subscription_addon_plans as p')
                ->join('subscription_addons as a', 'a.id', '=', 'p.subscription_addon_id')
                ->where('p.plan_id', $plan->id)->where('p.mode', Catalog::INCLUDED)
                ->whereNotNull('a.capability')->pluck('a.capability')->all()];
            self::$included[$plan] = $hit;
        }

        return in_array($capability, $hit['keys'], true);
    }

    /** الفروعُ فوق سقف الباقة — مجموعُ وحدات الإضافات السارية */
    public static function extraBranches(Business $business): int
    {
        return (int) self::rows($business)
            ->filter(fn (BusinessAddon $row) => $row->addon?->capability === Catalog::BRANCHES)
            ->sum('quantity');
    }

    /**
     * سارياتُ المتجر اليوم — محمَّلةٌ مرّةً لنسخته.
     *
     * @return Collection<int, BusinessAddon>
     */
    public static function rows(Business $business): Collection
    {
        if (! $business->exists) {
            return collect();
        }

        self::$rows ??= new WeakMap;
        $today = now()->toDateString();
        $hit = self::$rows[$business] ?? null;

        if ($hit === null || $hit['epoch'] !== self::$epoch || $hit['day'] !== $today) {
            $hit = [
                'epoch' => self::$epoch,
                'day' => $today,
                'rows' => BusinessAddon::with('addon')->where('business_id', $business->id)->active()->get(),
            ];
            self::$rows[$business] = $hit;
        }

        return $hit['rows'];
    }
}
