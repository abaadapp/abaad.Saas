<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Support\Activity;
use App\Support\BoutiqueDues;
use App\Support\Vat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «منتجاتُ البوتيك في هذه الباقة» — قسمٌ في تسعير المنتج.
 *
 * يختار صاحبُ المتجر صنفًا من بوتيكٍ قائم، ويقول هل هو **مشمولٌ** في سعر
 * الباقة (يُكتب مكوّنًا في وصفتها) أو **إضافةٌ اختياريّة** تزيد السعر (تُكتب
 * إضافةً مربوطةً برفّ البوتيك، لهذا المنتج، بقنواتها). ثمّ الكمّيّة. ويرى
 * فورًا ما للبوتيك وما يبقى له — والحسابُ نفسُه الذي يُكتب ساعة البيع
 * (`BoutiqueDues`): ثمنُ الصنف في كمّيّته، بلا عمولة ولا خصم.
 *
 * ولا شيءَ هنا إلّا لمتجرٍ فُتح له المفتاح ولا يحتسب الضريبة. وما يُكتب
 * هو وصفةٌ وإضافةٌ عاديّتان: الصندوقُ والموقعُ يبيعانهما كما يبيعان
 * غيرهما، ومستحقُّ البوتيك يُحسب عند البيع لا هنا.
 */
class BoutiquePartsController extends Controller
{
    public const INCLUDED = 'included';

    public const OPTIONAL = 'optional';

    /**
     * حمولةُ القسم — أو `null` لمن لم يُفتح له، فلا يُرسم شيء.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(Product $product): ?array
    {
        $bid = (int) $product->business_id;
        if (! BoutiqueDues::active($bid)) {
            return null;
        }

        $options = Product::where('business_id', $bid)->whereNotNull('boutique_id')
            ->whereKeyNot($product->id)
            ->with('boutique:id,name')->withCount('variants')
            ->orderBy('name')->get()
            ->map(fn (Product $p) => [
                'value' => (int) $p->id,
                'label' => $p->name,
                'boutique_id' => (int) $p->boutique_id,
                'boutique' => $p->boutique?->name,
                'price' => round((float) $p->price, 3),
                'quantity' => (int) $p->quantity,
                // ما لا يُحسب مستحقُّه بدقّة يُعرض ولا يُختار — انظر `BoutiqueDues::refusal`
                'blocked' => $p->variants_count > 0
                    ? __('له مقاسات')
                    : ((float) $p->price <= 0 ? __('بلا سعر') : null),
            ])->values()->all();

        return [
            'taxed' => Vat::enabled($bid),
            'own_boutique' => $product->boutique_id !== null,
            'options' => $options,
            'parts' => self::parts($product),
        ];
    }

    /** ما في هذا المنتج اليوم من أصناف البوتيك — مشمولًا ثمّ اختياريًّا */
    private static function parts(Product $product): array
    {
        $bid = (int) $product->business_id;
        $owned = fn (array $ids) => Product::where('business_id', $bid)->whereIn('id', $ids)
            ->whereNotNull('boutique_id')->with('boutique:id,name')->get()->keyBy('id');

        $recipe = RecipeItem::where('business_id', $bid)->where('product_id', $product->id)
            ->whereNull('variant_id')->orderBy('sort_order')->orderBy('id')->get();
        $addonIds = DB::table('product_addons')->where('product_id', $product->id)->pluck('addon_id');
        $addons = Addon::where('business_id', $bid)
            ->where(fn ($q) => $q->where('product_id', $product->id)->orWhereIn('id', $addonIds))
            ->whereNotNull('inventory_product_id')->orderBy('id')->get();

        $goods = $owned(array_merge(
            $recipe->pluck('component_product_id')->all(),
            $addons->pluck('inventory_product_id')->all(),
        ));

        $out = [];
        foreach ($recipe as $r) {
            if ($p = $goods->get($r->component_product_id)) {
                $out[] = [
                    'id' => (int) $r->id, 'kind' => self::INCLUDED, 'product_id' => (int) $p->id,
                    'name' => $p->name, 'boutique_id' => (int) $p->boutique_id, 'boutique' => $p->boutique?->name,
                    'unit_price' => round((float) $p->price, 3), 'quantity' => (float) $r->quantity,
                    'wastage' => (float) $r->wastage_percent,
                ];
            }
        }
        foreach ($addons as $a) {
            if ($p = $goods->get($a->inventory_product_id)) {
                $out[] = [
                    'id' => (int) $a->id, 'kind' => self::OPTIONAL, 'product_id' => (int) $p->id,
                    'name' => $a->name, 'boutique_id' => (int) $p->boutique_id, 'boutique' => $p->boutique?->name,
                    'unit_price' => round((float) $p->price, 3), 'quantity' => (float) ($a->inventory_quantity ?? 1),
                    'addon_price' => round((float) $a->price, 3),
                    'active' => (bool) $a->active,
                    'pos_enabled' => (bool) ($a->pos_enabled ?? true), 'web_enabled' => (bool) ($a->web_enabled ?? false),
                ];
            }
        }

        return $out;
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $product = $this->product($id);
        $bid = (int) $product->business_id;

        $data = $request->validate([
            'component_product_id' => ['required', 'integer'],
            'mode' => ['required', 'in:'.self::INCLUDED.','.self::OPTIONAL],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'addon_price' => ['nullable', 'required_if:mode,'.self::OPTIONAL, 'numeric', 'min:0', 'max:100000'],
            'pos_enabled' => ['nullable', 'boolean'],
            'web_enabled' => ['nullable', 'boolean'],
        ]);

        $component = Product::where('business_id', $bid)->whereNotNull('boutique_id')
            ->withCount('variants')->find((int) $data['component_product_id']);

        $why = match (true) {
            Vat::enabled($bid) => __('مستحقُّ البوتيك لا يُحسب بعد في متجرٍ يحتسب الضريبة.'),
            $product->boutique_id !== null => __('هذا الصنف نفسُه ملكُ بوتيك — لا يحمل صنفَ بوتيكٍ آخر.'),
            $component === null => __('اختر صنفًا من بوتيكٍ قائم.'),
            (int) $component->id === (int) $product->id => __('لا يدخل الصنفُ في نفسه.'),
            $component->variants_count > 0 => __('«:name» له مقاسات — لا يدخل باقةً ولا إضافةً حتى يُحفظ سعرُ مقاسه.', ['name' => $component->name]),
            (float) $component->price <= 0 => __('«:name» بلا سعر — لا يُعرف مستحقُّه.', ['name' => $component->name]),
            default => null,
        };
        if ($why !== null) {
            throw ValidationException::withMessages(['component_product_id' => $why]);
        }

        DB::transaction(function () use ($product, $component, $data, $bid) {
            if ($data['mode'] === self::INCLUDED) {
                // صفٌّ واحدٌ للمكوّن في الوصفة: تكرارُه يحدّث كمّيّتَه — والفاقدُ صفرٌ دائمًا لمكوّن البوتيك
                RecipeItem::updateOrCreate(
                    ['business_id' => $bid, 'product_id' => $product->id, 'variant_id' => null, 'component_product_id' => $component->id],
                    ['quantity' => (int) $data['quantity'], 'wastage_percent' => 0],
                );
                Activity::log('updated', 'أُدخل «'.$component->name.'» من بوتيكه في وصفة '.$product->name, ['subject_id' => $product->id]);

                return;
            }

            $addon = Addon::create([
                'business_id' => $bid, 'name' => $component->name, 'name_en' => $component->name_en,
                'price' => round((float) $data['addon_price'], 3), 'active' => true,
                'scope' => Addon::SCOPE_SELECTED,
                'inventory_product_id' => $component->id, 'inventory_quantity' => (int) $data['quantity'],
                'pos_enabled' => (bool) ($data['pos_enabled'] ?? true),
                'web_enabled' => (bool) ($data['web_enabled'] ?? false),
            ]);
            DB::table('product_addons')->insert([
                'business_id' => $bid, 'product_id' => $product->id, 'addon_id' => $addon->id,
                'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Activity::log('created', 'أُضيف «'.$component->name.'» من بوتيكه إضافةً اختياريّة لـ'.$product->name, ['subject_id' => $product->id]);
        });

        return response()->json(['ok' => true, 'parts' => self::parts($product)]);
    }

    public function destroy(int $id, string $kind, int $part): JsonResponse
    {
        $product = $this->product($id);
        $bid = (int) $product->business_id;

        if ($kind === self::INCLUDED) {
            $gone = RecipeItem::where('business_id', $bid)->where('product_id', $product->id)->whereKey($part)
                ->whereIn('component_product_id', Product::where('business_id', $bid)->whereNotNull('boutique_id')->select('id'))
                ->delete();
        } else {
            // الإضافةُ تُفكّ عن هذا المنتج وحده — ما بِيع بها يبقى بلقطته
            $gone = DB::table('product_addons')->where('business_id', $bid)->where('product_id', $product->id)->where('addon_id', $part)->delete();
        }
        // وما ليس في هذا المنتج لا يُقال عنه «أُزيل»
        abort_if($gone === 0, 404);

        return response()->json(['ok' => true, 'parts' => self::parts($product)]);
    }

    private function product(int $id): Product
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        abort_unless(BoutiqueDues::active((int) $product->business_id), 404);

        return $product;
    }

    private function bid(): int
    {
        return (int) auth()->user()->business_id;
    }
}
