<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * أيّ الإضافات تُعرض مع أيّ منتج — وأيّها يُقبل عند البيع.
 *
 * قبل هذا الملفّ كانت إضافات المتجر كلّها تظهر مع كلّ منتج: يُعرض «بالون»
 * مع كيس السماد. والربط وحده لا يكفي — يلزم أن يكون الغيابُ معناه «الكلّ»
 * لا «لا شيء»، وإلّا اختفت الإضافات عن كلّ منتجٍ لحظة الترقية ولا أحد ربط
 * شيئًا بعد.
 *
 * فالقاعدة على حالتين:
 *
 *   - للمنتج صفوفٌ في product_addons  →  تلك وحدها
 *   - لا صفوف له                      →  إضافات المتجر كلّها (سلوك اليوم)
 *
 * ثم صار للإضافة مدًى تُقرَّر به من جهتها لا من جهة المنتج: «مع الجميع»
 * وهو الفراغ ومدى كلّ إضافةٍ قائمة، و«منتجات محدّدة» وهي صفوفها في الجدول
 * نفسه. فحمل الجدولُ معنيين — وفصلُهما في legacyList أدناه، وإلّا صار
 * اختيار منتجٍ لإضافةٍ محدّدة يحصر ذلك المنتج فيها وحدها.
 *
 * ويسبق الحالتين حاجزٌ واحد: الإضافة المملوكة لمنتجٍ (addons.product_id)
 * لا تخرج عنه. صُنعت لباقةٍ بعينها فلا تُعرض مع كيس السماد ولو لم يُربط
 * لكيس السماد شيء — وغيابُ الربط عنه لا يعني أنّه يقبل كلّ ما صُنع لغيره.
 */
class ProductAddons
{
    /**
     * الإضافات المسموحة لمنتجٍ بعينه — الفعّالة منها فقط.
     *
     * @param  Collection<int, Addon>|null  $all  إضافات المتجر إن كانت محمّلة
     * @param  array<int, int[]>|null  $map  [معرّف المنتج => معرّفات إضافاته] إن كانت محمّلة
     * @return Collection<int, Addon>
     */
    public static function for(Product $product, ?Collection $all = null, ?array $map = null): Collection
    {
        $all ??= Addon::where('business_id', $product->business_id)->orderBy('id')->get();
        $map ??= self::map((int) $product->business_id);

        $rows = $map[$product->id] ?? null;
        $legacy = self::legacyList($rows, $all);

        return $all
            ->filter(fn (Addon $a) => (bool) $a->active && self::owned($a, $product))
            ->filter(fn (Addon $a) => self::passes($a, $rows, $legacy))
            ->values();
    }

    /**
     * قائمةُ المنتج القديمة — بلا صفوف الإضافات ذات المدى المحدّد.
     *
     * الصفّ في product_addons يحمل معنيين منذ أن صار للإضافة مدى: كان
     * «هذه قائمةُ هذا المنتج»، وصار أيضًا «هذا أحد منتجات تلك الإضافة».
     * فلو حُسب الثاني ضمن الأوّل لصار اختيارُ منتجٍ لإضافةٍ محدّدة يحصر
     * ذلك المنتج فيها وحدها — فيفقد كلّ إضافات المتجر بضغطةٍ لا تقول ذلك.
     *
     * والفراغُ فراغ: منتجٌ كلُّ صفوفه لإضافاتٍ محدّدة لا قائمة له.
     *
     * @param  int[]|null  $rows
     * @param  Collection<int, Addon>  $all
     * @return int[]|null
     */
    private static function legacyList(?array $rows, Collection $all): ?array
    {
        if ($rows === null) {
            return null;
        }

        $selected = $all->filter(fn (Addon $a) => $a->scopeName() === Addon::SCOPE_SELECTED)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        $legacy = array_values(array_filter($rows, fn ($id) => ! in_array((int) $id, $selected, true)));

        return $legacy ?: null;
    }

    /**
     * هل يجتاز المدى؟ — للإضافة المرتبطة بالمتجر لا بالمنتج.
     *
     * «محدّدة» تُعرض حيث رُبطت وحدها. و«مع الجميع» تُعرض ما لم يكن للمنتج
     * قائمةٌ قديمة تستثنيها. وإضافةُ المنتج نفسه لا يضيّقها شيء: صُنعت له.
     *
     * @param  int[]|null  $rows  صفوف هذا المنتج كلّها
     * @param  int[]|null  $legacy  قائمته القديمة منها
     */
    private static function passes(Addon $addon, ?array $rows, ?array $legacy): bool
    {
        if ($addon->product_id !== null) {
            return true;
        }

        if ($addon->scopeName() === Addon::SCOPE_SELECTED) {
            return in_array((int) $addon->id, array_map('intval', $rows ?? []), true);
        }

        return $legacy === null || in_array((int) $addon->id, array_map('intval', $legacy), true);
    }

    /**
     * خريطة الربط للنشاط كلّه — استعلامٌ واحد لشاشةٍ فيها مئة منتج.
     *
     * @return array<int, int[]>  [معرّف المنتج => معرّفات إضافاته]
     */
    public static function map(int $businessId): array
    {
        return \Illuminate\Support\Facades\DB::table('product_addons')
            ->where('business_id', $businessId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['product_id', 'addon_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('addon_id')->map(fn ($i) => (int) $i)->all())
            ->all();
    }

    /**
     * هل تُقبل هذه الإضافة على هذا المنتج؟
     *
     * يُسأل في الخادم لا في الشاشة: طلبُ الدفع يصل من جهازٍ قد تكون شاشته
     * قديمة — أو مُلاعَبة. ومنتجٌ لا يسمح بالدبّ لا يُباع معه دبّ لأنّ
     * الطلب حمل معرّفه.
     */
    public static function allows(Product $product, Addon $addon, ?array $map = null, ?Collection $all = null): bool
    {
        if ((int) $addon->business_id !== (int) $product->business_id || ! $addon->active) {
            return false;
        }

        if (! self::owned($addon, $product)) {
            return false;
        }

        $map ??= self::map((int) $product->business_id);
        $rows = $map[$product->id] ?? null;

        // القائمة القديمة تُحسب من إضافات المتجر كلّها لا من هذه وحدها:
        // معرفةُ أيّ الصفوف «مدًى» وأيّها «قائمة» تحتاج مدى كلّ إضافةٍ فيها.
        // وتُمرَّر محمّلةً من نقطة البيع: بلا ذلك استعلامٌ لكلّ إضافةٍ في السلّة
        $all ??= Addon::where('business_id', $product->business_id)->get();

        return self::passes($addon, $rows, self::legacyList($rows, $all));
    }

    /**
     * إضافةُ المتجر لكلٍّ، وإضافةُ المنتج لصاحبها وحده.
     */
    public static function owned(Addon $addon, Product $product): bool
    {
        return $addon->product_id === null || (int) $addon->product_id === (int) $product->id;
    }
}
