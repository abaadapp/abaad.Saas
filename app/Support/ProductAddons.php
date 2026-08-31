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

        $allowed = $map[$product->id] ?? null;

        return $all
            ->filter(fn (Addon $a) => (bool) $a->active && self::owned($a, $product))
            ->when($allowed !== null, fn ($c) => $c->filter(fn (Addon $a) => in_array($a->id, $allowed, true)))
            ->values();
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
    public static function allows(Product $product, Addon $addon, ?array $map = null): bool
    {
        if ((int) $addon->business_id !== (int) $product->business_id || ! $addon->active) {
            return false;
        }

        if (! self::owned($addon, $product)) {
            return false;
        }

        $map ??= self::map((int) $product->business_id);
        $allowed = $map[$product->id] ?? null;

        return $allowed === null || in_array((int) $addon->id, $allowed, true);
    }

    /**
     * إضافةُ المتجر لكلٍّ، وإضافةُ المنتج لصاحبها وحده.
     */
    public static function owned(Addon $addon, Product $product): bool
    {
        return $addon->product_id === null || (int) $addon->product_id === (int) $product->id;
    }
}
