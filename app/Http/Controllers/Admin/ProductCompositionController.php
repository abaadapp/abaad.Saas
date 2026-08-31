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

        $variant = ProductVariant::create(\App\Support\Lexicon::fill($data) + [
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

        $variant->update(\App\Support\Lexicon::fill($data));

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
            // إضافةُ متجرٍ أو إضافةُ هذا المنتج — لا إضافةَ منتجٍ آخر ولو كانت
            // في المتجر نفسه؛ صاحبُها هو من يقرّر أين تُعرض
            'addon_ids.*' => [Rule::exists('addons', 'id')->where('business_id', $bid)
                ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))],
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
     * حذفُ إضافةٍ خاصّةٍ بهذا المنتج — ولا تُحذف من هنا إضافةُ متجر.
     *
     * الأخيرة تُعرض مع كلّ منتجات المحلّ، وحذفُها من شاشة منتجٍ واحد يمسّ
     * ما لا يراه من ضغط الزرّ. بابُها في شاشة المنتج نفسها بجانب القسم.
     *
     * والحذف نهائيّ ولا يمسّ فاتورة: بند الطلب يحمل لقطته (الاسم والسعر)،
     * و`addon_id` يُفرَّغ ولا يُسقط الصفّ — انظر الهجرة.
     */
    public function destroyAddon(int $id, int $addonId)
    {
        $product = $this->product($id);

        $addon = Addon::where('business_id', $this->bid())
            ->where('product_id', $product->id)
            ->findOrFail($addonId);

        DB::table('product_addons')->where('addon_id', $addon->id)->delete();
        $addon->delete();

        \App\Support\Activity::log('deleted', 'حذف إضافة «'.$addon->name.'» من '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('حُذفت الإضافة'), 'type' => 'warning']);
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
            'components' => self::componentOptions((int) $product->business_id, $product->id),
            'addons' => self::addonOptions((int) $product->business_id, $product->id),
            'stock_items' => self::stockItemOptions((int) $product->business_id),
            'products' => self::productOptions((int) $product->business_id),
            'addon_ids' => DB::table('product_addons')->where('product_id', $product->id)
                ->orderBy('sort_order')->pluck('addon_id')->map(fn ($i) => (int) $i)->all(),
        ];
    }

    /**
     * ما تعرضه الأقسام نفسها لمنتجٍ لم يُنشأ بعد.
     *
     * لأنّ التركيب صار يُملأ وهو يُكتب المنتج لا بعد حفظه: من يُدخل باقةً
     * يُدخل مقاساتها ومكوّناتها في جلسةٍ واحدة، ومطالبتُه بالحفظ ثم العودة
     * إلى الشاشة نفسها خطوةٌ لا تفيد شيئًا.
     */
    public static function blank(int $businessId): array
    {
        $empty = ['items' => [], 'cost' => 0.0, 'price' => 0.0, 'margin' => 0.0, 'margin_pct' => null];

        return [
            'variants' => [],
            'recipe' => $empty,
            'components' => self::componentOptions($businessId, null),
            'addons' => self::addonOptions($businessId, null),
            'stock_items' => self::stockItemOptions($businessId),
            'products' => self::productOptions($businessId),
            'addon_ids' => [],
        ];
    }

    /**
     * الأصناف الصالحة لأن تكون مكوّنات — بلا باقاتٍ لها وصفتها.
     */
    private static function componentOptions(int $businessId, ?int $exclude): array
    {
        return Product::where('business_id', $businessId)
            ->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))
            ->whereNotIn('id', RecipeItem::where('business_id', $businessId)->distinct()->pluck('product_id'))
            ->orderBy('name')->get(['id', 'name', 'sku', 'cost', 'quantity'])
            ->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                'cost' => (float) $p->cost,
                'quantity' => (int) $p->quantity,
            ])->all();
    }

    /**
     * إضافات المتجر، وإضافات هذا المنتج وحده — لا إضافات جيرانه.
     */
    private static function addonOptions(int $businessId, ?int $productId): array
    {
        return Addon::where('business_id', $businessId)
            ->where(fn ($q) => $q->whereNull('product_id')->when($productId, fn ($w) => $w->orWhere('product_id', $productId)))
            ->orderBy('id')->get()
            ->map(fn (Addon $a) => CatalogQuickAddController::addonPayload($a))
            ->all();
    }

    /**
     * الأصناف الصالحة لأن تكون مخزونَ إضافة — بالرمز والرصيد.
     *
     * الرصيد يُعرض لأنّ من يربط «دبًّا» يريد أن يعرف أعنده دببة. والرمز
     * لأنّ في المحلّ «ورد أحمر» و«ورد أحمر مستورد» — والاسم وحده لا يفرّق.
     *
     * وما له وصفةٌ يُستثنى: باقةٌ تُصنع من غيرها ليست قطعةً على رفّ، وربطُ
     * إضافةٍ بها كان سيُنقص رصيدًا لا وجود له بينما يبقى ورُدها كاملًا.
     */
    private static function stockItemOptions(int $businessId): array
    {
        return Product::where('business_id', $businessId)
            ->whereNotIn('id', RecipeItem::where('business_id', $businessId)->distinct()->pluck('product_id'))
            ->orderBy('name')->get(['id', 'name', 'sku', 'quantity'])
            ->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
                'quantity' => (int) $p->quantity,
            ])->all();
    }

    /** منتجات المتجر — لاختيار «تظهر مع: منتجات محدّدة» */
    private static function productOptions(int $businessId): array
    {
        return Product::where('business_id', $businessId)
            ->orderBy('name')->get(['id', 'name', 'sku'])
            ->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->sku ? $p->name.' — '.$p->sku : $p->name,
            ])->all();
    }

    /**
     * قواعد التركيب المرسَل مع نموذج الإنشاء.
     *
     * الأسماء تُرسل كمسوّدة لا كصفوف: لا معرّف للمنتج بعد، فالمقاس يُشار
     * إليه برقم موضعه في القائمة (`variant_index`) ويُترجَم إلى معرّفه
     * الحقيقيّ بعد الحفظ.
     *
     * @return array<string, mixed>
     */
    public static function draftRules(int $businessId): array
    {
        return [
            'composition' => ['nullable', 'array'],
            'composition.variants' => ['nullable', 'array', 'max:50'],
            'composition.variants.*.name' => ['required', 'string', 'max:100'],
            'composition.variants.*.name_en' => ['nullable', 'string', 'max:100'],
            'composition.variants.*.sku' => ['nullable', 'string', 'max:100'],
            'composition.variants.*.price' => ['required', 'numeric', 'min:0'],
            'composition.recipe' => ['nullable', 'array', 'max:200'],
            'composition.recipe.*.component_product_id' => ['required',
                Rule::exists('products', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'composition.recipe.*.quantity' => ['required', 'numeric', 'gt:0'],
            'composition.recipe.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'composition.recipe.*.variant_index' => ['nullable', 'integer', 'min:0'],
            'composition.addon_ids' => ['nullable', 'array'],
            'composition.addon_ids.*' => [Rule::exists('addons', 'id')->where('business_id', $businessId)->whereNull('product_id')],
            'composition.new_addons' => ['nullable', 'array', 'max:50'],
            'composition.new_addons.*.name' => ['required', 'string', 'max:100'],
            'composition.new_addons.*.price' => ['required', 'numeric', 'min:0'],
            'composition.new_addons.*.private' => ['nullable', 'boolean'],
            // والمسوّدة تربط بالمخزون كما تربط الشاشة المحفوظة: من يكتب
            // «زيادة ثلاث وردات» وهو ينشئ الباقة لا يُطالَب بحفظها ثم
            // العودة إليها ليقول ممّ تُخصم
            'composition.new_addons.*.inventory_product_id' => ['nullable',
                Rule::exists('products', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'composition.new_addons.*.inventory_quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
        ];
    }

    /**
     * يكتب مسوّدة التركيب على منتجٍ حُفظ لتوّه.
     *
     * وما لا يصحّ يُسقَط بصمت لا يُسقِط الحفظ: مكوّنٌ صار له وصفةٌ بين
     * لحظة فتح الشاشة ولحظة الحفظ لا يستحقّ أن يضيع معه المنتج كلّه —
     * والوصفة تُراجَع في شاشة التعديل على أي حال.
     */
    public static function applyDraft(Product $product, array $composition): void
    {
        $bid = (int) $product->business_id;

        DB::transaction(function () use ($product, $bid, $composition) {
            $variantIds = [];

            foreach (array_values($composition['variants'] ?? []) as $i => $v) {
                $variantIds[$i] = ProductVariant::create(\App\Support\Lexicon::fill([
                    'name' => $v['name'],
                    'name_en' => $v['name_en'] ?? null,
                ]) + [
                    'business_id' => $bid,
                    'product_id' => $product->id,
                    'sku' => $v['sku'] ?: null,
                    'price' => $v['price'],
                    'active' => true,
                    'sort_order' => $i,
                ])->id;
            }

            // الأصناف التي لها وصفةٌ خاصّة لا تصلح مكوّنات — نفس قاعدة storeRecipeItem
            $withRecipes = RecipeItem::where('business_id', $bid)->distinct()->pluck('product_id')
                ->map(fn ($i) => (int) $i)->all();

            $seen = [];
            $order = 0;

            foreach (array_values($composition['recipe'] ?? []) as $r) {
                $componentId = (int) $r['component_product_id'];
                $index = $r['variant_index'] ?? null;
                $variantId = $index === null ? null : ($variantIds[(int) $index] ?? null);

                if ($componentId === (int) $product->id || in_array($componentId, $withRecipes, true)) {
                    continue;
                }

                // الصفّ المكرّر يُجمع لا يُضاف — نفس قاعدة الشاشة المحفوظة
                $key = ($variantId ?? 0).':'.$componentId;
                if (isset($seen[$key])) {
                    $seen[$key]->update(['quantity' => (float) $seen[$key]->quantity + (float) $r['quantity']]);

                    continue;
                }

                $seen[$key] = RecipeItem::create([
                    'business_id' => $bid,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'component_product_id' => $componentId,
                    'quantity' => $r['quantity'],
                    'wastage_percent' => $r['wastage_percent'] ?? 0,
                    'sort_order' => $order++,
                ]);
            }

            $ids = array_map('intval', $composition['addon_ids'] ?? []);

            foreach ($composition['new_addons'] ?? [] as $a) {
                $private = filter_var($a['private'] ?? true, FILTER_VALIDATE_BOOLEAN);

                // إضافةُ متجرٍ بالاسم نفسه تُعاد لا تُكرَّر: اسمان متطابقان في
                // قائمة الكاشير يجعلانه يختار عشوائيًا
                $stock = ($a['inventory_product_id'] ?? null) ? (int) $a['inventory_product_id'] : null;
                $each = $stock && ($a['inventory_quantity'] ?? null) !== null
                    ? (float) $a['inventory_quantity']
                    : null;

                $addon = $private
                    ? Addon::create(\App\Support\Lexicon::fill(['name' => $a['name']]) + [
                        'business_id' => $bid, 'product_id' => $product->id,
                        'price' => $a['price'], 'active' => true,
                        'inventory_product_id' => $stock, 'inventory_quantity' => $each,
                    ])
                    : Addon::firstOrCreate(
                        ['business_id' => $bid, 'product_id' => null, 'name' => $a['name']],
                        ['price' => $a['price'], 'active' => true,
                            'inventory_product_id' => $stock, 'inventory_quantity' => $each],
                    );

                $ids[] = (int) $addon->id;
            }

            $ids = array_values(array_unique($ids));

            foreach ($ids as $i => $addonId) {
                DB::table('product_addons')->insert([
                    'business_id' => $bid,
                    'product_id' => $product->id,
                    'addon_id' => $addonId,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
