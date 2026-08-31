<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Support\Demo;
use App\Support\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ما يتركّب منه المنتج: مقاساتُه ووصفتُه وإضافاتُه المسموحة.
 *
 * ثلاثة أبوابٍ في متحكّمٍ واحد لأنّها فعلٌ واحد في نظر التاجر: «أُعدّ هذا
 * المنتج للبيع». وتوزيعُها على ثلاثة متحكّمات كان سيكرّر حارس النشاط
 * وحارس الصلاحية ثلاث مرّات — ومن نسيها في واحدٍ فتح ثغرة.
 *
 * ولا صلاحيةَ جديدة: من يملك إدارة المنتجات يملك تركيبها. الكاشير يختار
 * مقاسًا وإضافةً وهو يبيع، ولا يعدّل وصفة — وهذا يتحقّق بحارس القسم نفسه
 * الذي يحرس شاشة المنتجات.
 */
class ProductCompositionController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** المنتج من هذا النشاط أو لا شيء — الحارس الأوّل في كلّ باب */
    private function product(int $id): Product
    {
        return Product::where('business_id', $this->bid())->findOrFail($id);
    }

    /* ------------------------------ المقاسات ------------------------------ */

    public function storeVariant(Request $request, int $id)
    {
        $product = $this->product($id);
        $bid = $this->bid();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            // التفرّد على مستوى النشاط لا على مستوى النظام: رمزُ متجرٍ لا
            // يعارض رمز متجرٍ آخر. وهو نمط products نفسه — انظر ProductController
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->where('business_id', $bid)->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->where('business_id', $bid)->whereNull('deleted_at')],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $variant = ProductVariant::create($data + [
            'business_id' => $bid,
            'product_id' => $product->id,
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) $product->variants()->max('sort_order') + 1),
        ]);

        \App\Support\Activity::log('created', 'أضاف مقاس «'.$variant->name.'» إلى '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('أُضيف المقاس'), 'type' => 'success']);
    }

    public function updateVariant(Request $request, int $id, int $variantId)
    {
        $product = $this->product($id);
        $bid = $this->bid();
        $variant = ProductVariant::where('business_id', $bid)->where('product_id', $product->id)->findOrFail($variantId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->where('business_id', $bid)->whereNull('deleted_at')->ignore($variant->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->where('business_id', $bid)->whereNull('deleted_at')->ignore($variant->id)],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $variant->update($data);

        return back()->with('toast', ['msg' => __('حُفظ المقاس'), 'type' => 'success']);
    }

    /**
     * حذفٌ ناعم — والفواتير القديمة تبقى تقرأ لقطتها لا صفَّه.
     */
    public function destroyVariant(int $id, int $variantId)
    {
        $product = $this->product($id);
        $variant = ProductVariant::where('business_id', $this->bid())->where('product_id', $product->id)->findOrFail($variantId);

        $variant->delete();

        \App\Support\Activity::log('deleted', 'حذف مقاس «'.$variant->name.'» من '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('حُذف المقاس'), 'type' => 'warning']);
    }

    /* ------------------------------- الوصفة ------------------------------- */

    public function storeRecipeItem(Request $request, int $id)
    {
        $product = $this->product($id);
        $bid = $this->bid();

        $data = $request->validate([
            // المكوّن من هذا النشاط أو لا يُقبل: القاعدة في القاعدة نفسها لا
            // في الشاشة، فطلبٌ مصنوع بيدٍ لا يمرّ
            'component_product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at')],
            'variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('business_id', $bid)->where('product_id', $product->id)->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        /*
         * لا وصفةَ داخل وصفة.
         *
         * الإصدار الأوّل يقصر المكوّنات على أصنافٍ مخزنيّة مباشرة: باقةٌ
         * داخل باقةٍ تفتح بابَ حلقةٍ (أ يحوي ب، وب يحوي أ) تُدخل الخصمَ في
         * تكرارٍ لا ينتهي، ويلزمها كشفُ حلقاتٍ في كلّ حفظ. والقصرُ هنا يمنع
         * الحلقة من أصلها بدل أن يطاردها.
         */
        if ((int) $data['component_product_id'] === (int) $product->id) {
            throw ValidationException::withMessages([
                'component_product_id' => __('المنتج لا يكون مكوّنًا في وصفته.'),
            ]);
        }

        if (RecipeItem::where('business_id', $bid)->where('product_id', $data['component_product_id'])->exists()) {
            throw ValidationException::withMessages([
                'component_product_id' => __('هذا الصنف له وصفته الخاصة — المكوّنات أصنافُ مخزونٍ مباشرة لا باقات.'),
            ]);
        }

        // الصفّ المكرّر يُجمع لا يُضاف: مكوّنان بالاسم نفسه في وصفةٍ واحدة
        // يجعلان القارئ يشكّ أيّهما المعتبَر
        $existing = RecipeItem::where('product_id', $product->id)
            ->where('variant_id', $data['variant_id'] ?? null)
            ->where('component_product_id', $data['component_product_id'])->first();

        if ($existing) {
            $existing->update(['quantity' => (float) $existing->quantity + (float) $data['quantity']]);
        } else {
            RecipeItem::create($data + [
                'business_id' => $bid,
                'product_id' => $product->id,
                'wastage_percent' => $data['wastage_percent'] ?? 0,
                'sort_order' => (int) RecipeItem::where('product_id', $product->id)->max('sort_order') + 1,
            ]);
        }

        \App\Support\Activity::log('updated', 'عدّل وصفة '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('أُضيف المكوّن'), 'type' => 'success']);
    }

    public function updateRecipeItem(Request $request, int $id, int $itemId)
    {
        $product = $this->product($id);
        $item = RecipeItem::where('business_id', $this->bid())->where('product_id', $product->id)->findOrFail($itemId);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $item->update($data + ['wastage_percent' => $data['wastage_percent'] ?? 0]);

        return back()->with('toast', ['msg' => __('حُفظ المكوّن'), 'type' => 'success']);
    }

    public function destroyRecipeItem(int $id, int $itemId)
    {
        $product = $this->product($id);
        RecipeItem::where('business_id', $this->bid())->where('product_id', $product->id)->findOrFail($itemId)->delete();

        return back()->with('toast', ['msg' => __('حُذف المكوّن'), 'type' => 'warning']);
    }

    /* ------------------------------ الإضافات ------------------------------ */

    /**
     * يستبدل قائمة الإضافات المسموحة كاملةً.
     *
     * والقائمة الفارغة تعني «كلّ إضافات المتجر» لا «لا شيء» — وهو سلوك ما
     * قبل هذه الشاشة. ومن أراد منعها كلّها فذاك بإطفاء الإضافات نفسها.
     */
    public function syncAddons(Request $request, int $id)
    {
        $product = $this->product($id);
        $bid = $this->bid();

        $data = $request->validate([
            'addon_ids' => ['present', 'array'],
            'addon_ids.*' => [Rule::exists('addons', 'id')->where('business_id', $bid)],
        ]);

        DB::transaction(function () use ($product, $bid, $data) {
            DB::table('product_addons')->where('product_id', $product->id)->delete();

            foreach (array_values(array_unique($data['addon_ids'])) as $i => $addonId) {
                DB::table('product_addons')->insert([
                    'business_id' => $bid,
                    'product_id' => $product->id,
                    'addon_id' => (int) $addonId,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        \App\Support\Activity::log('updated', 'عدّل إضافات '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('حُفظت الإضافات'), 'type' => 'success']);
    }

    /**
     * ما تعرضه أقسام «المقاسات» و«الوصفة» و«الإضافات» في شاشة المنتج.
     *
     * الأرقام تُشتقّ في الخادم لا في المتصفّح: هامشٌ يُحسب في الشاشة يفترق
     * عن هامشٍ يُحسب في التقرير عند أوّل تقريبٍ مختلف.
     */
    public static function payload(Product $product): array
    {
        $variants = $product->variants()->get();
        $rows = RecipeItem::where('product_id', $product->id)->with('component')
            ->orderBy('sort_order')->orderBy('id')->get();

        $costs = Product::whereIn('id', $rows->pluck('component_product_id')->unique())
            ->pluck('cost', 'id')->map(fn ($c) => (float) $c)->all();

        $recipeFor = function (?ProductVariant $variant) use ($product, $rows, $costs) {
            $lines = Recipe::forLine($product, $variant, $rows);
            $cost = Recipe::unitCost($product, $variant, $rows, $costs);
            $price = $variant ? (float) $variant->price : (float) $product->price;

            return [
                'items' => $lines->map(fn (RecipeItem $r) => [
                    'id' => $r->id,
                    'component_product_id' => $r->component_product_id,
                    'component' => $r->component?->name ?? '—',
                    'quantity' => (float) $r->quantity,
                    'wastage_percent' => (float) $r->wastage_percent,
                    'unit_cost' => $costs[$r->component_product_id] ?? 0.0,
                    'line_cost' => round($r->effectiveQuantity() * ($costs[$r->component_product_id] ?? 0.0), 3),
                    // الصفّ الموروث من وصفة المنتج يُعلَّم: من يراه على المقاس
                    // ولا يعرف مصدره يظنّه يعدّله وهو يعدّل الأساس للجميع
                    'inherited' => $variant && $r->variant_id === null,
                ])->all(),
                'cost' => $cost,
                'price' => round($price, 3),
                'margin' => round($price - $cost, 3),
                'margin_pct' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
            ];
        };

        return [
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'name_en' => $v->name_en,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => (float) $v->price,
                'active' => (bool) $v->active,
                'sort_order' => (int) $v->sort_order,
                'recipe' => $recipeFor($v),
            ])->all(),
            'recipe' => $recipeFor(null),
            'components' => Product::where('business_id', $product->business_id)
                ->where('id', '!=', $product->id)
                ->whereNotIn('id', RecipeItem::where('business_id', $product->business_id)->distinct()->pluck('product_id'))
                ->orderBy('name')->get(['id', 'name', 'sku', 'cost', 'quantity'])
                ->map(fn ($p) => [
                    'value' => $p->id,
                    'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                    'cost' => (float) $p->cost,
                    'quantity' => (int) $p->quantity,
                ])->all(),
            'addons' => Addon::where('business_id', $product->business_id)->orderBy('id')
                ->get()->map(fn ($a) => [
                    'value' => $a->id,
                    'label' => $a->name,
                    'price' => (float) $a->price,
                    'active' => (bool) $a->active,
                    'inventory_product_id' => $a->inventory_product_id,
                ])->all(),
            'addon_ids' => DB::table('product_addons')->where('product_id', $product->id)
                ->orderBy('sort_order')->pluck('addon_id')->map(fn ($i) => (int) $i)->all(),
        ];
    }
}
