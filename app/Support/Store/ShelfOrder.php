<?php

namespace App\Support\Store;

use App\Models\Product;
use App\Support\MarketingSettings;
use App\Support\ProductImages;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ترتيبُ المنتجات في رفّ المتجر بيد صاحبه — «كل المنتجات» وكلُّ فئةٍ على حدة.
 *
 * ═══ ما كان ═══
 *
 * الرفُّ بالاسم أبدًا (`RibbonController::shop`). فصاحبُ محلّ الورد يريد
 * الأحمرَ أوّلًا ثمّ الأبيضَ ثمّ الورديّ، فيجدها بحروف أسمائها — ولا يملك
 * إلّا أن يُعيد تسميةَ أصنافه ليُقدّم واحدًا.
 *
 * ═══ قوائمُ منفصلة، والفراغُ «ما كان» ═══
 *
 * `{"all": [ids], "categories": {"<فئة>": [ids]}}`. قائمةُ «كل المنتجات»
 * غيرُ قائمة الفئة: من رتّب الورد في فئته لا يتبدّل رفُّ «كل المنتجات»، ولا
 * العكس. وقائمةٌ غائبةٌ تعني الأبجديّةَ كما كانت — فلا يتحرّك رفٌّ لم يمسّه.
 *
 *   - ما ليس في القائمة يقع بعدها، بترتيب إضافته: المنتجُ الجديدُ آخرًا.
 *   - المعرّفُ يُحفظ ولو أُخفي صنفُه: يعود إلى موضعه إن نشره ثانية.
 *   - وقائمةُ الفئة لأصنافها وحدها: صنفٌ نُقل إلى فئةٍ أخرى يقع آخرَ فئته
 *     الجديدة حتّى يرتّبه، ويُطوى من قائمة القديمة عند حفظها التالي.
 *
 * ولا يمسّ «الأكثر مبيعًا» (محسوبٌ من البيع) ولا المجموعات (ترتيبُها في
 * `StoreCollections`) ولا البحث إلّا أنّه يحفظ ترتيبَ ما يُبحث فيه. ولا
 * نقطةَ البيع: الإعدادُ إعدادُ الموقع، لا عمودٌ في المنتج.
 *
 * ═══ مسوّدةٌ تُنشر ═══
 *
 * في `StoreContent::VERSIONED` كأخواته: ترتيبٌ يراه الزائر ولا يمسّ ما
 * يدفعه. فيرتّب صاحبُه رفَّه على مهل ثمّ ينشره مرّةً — كما يرتّب مجموعاته.
 *
 * ═══ لمن ═══
 *
 * لمن في `storefront.ribbon_shelf_order_businesses` وحده. ومن ليس فيها
 * يبقى رفُّه بالاسم ولو كُتب المفتاحُ في جدوله، ويُردّ حفظُه بـ403.
 */
final class ShelfOrder
{
    public const KEY = 'store_shelf_order';

    /** قائمةُ «كل المنتجات» */
    public const ALL = 'all';

    /** قوائمُ الفئات — بمعرّف الفئة */
    public const CATEGORIES = 'categories';

    /** أطولُ قائمة — رفٌّ أطولُ من هذا لا يُرتَّب بسهمين */
    public const MAX = 1000;

    /** حدُّ النصّ كلِّه قبل أن يُفكّ */
    public const RAW_MAX = 200000;

    /** أيُرتَّب الرفُّ باليد في هذا المتجر؟ — من القائمة وحدها */
    public static function allowed(int $businessId): bool
    {
        $list = array_map('intval', (array) config('storefront.ribbon_shelf_order_businesses', []));

        return in_array($businessId, $list, true);
    }

    /**
     * يرتّب ما يعرضه الرفّ — و`$category` صفرٌ لـ«كل المنتجات».
     *
     * ومن لم يُفتح له أو لم يرتّب هذا الرفّ يبقى على ما وصل (بالاسم).
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public static function apply(int $businessId, Collection $products, int $category): Collection
    {
        if (! self::allowed($businessId)) {
            return $products;
        }

        $order = self::fromJson((string) (MarketingSettings::group($businessId, 'website')[self::KEY] ?? ''));
        $ids = self::listFor($order, $category);

        return $ids === null ? $products : self::sort($products, $ids);
    }

    /**
     * المرتَّبُ بموضعه، ثمّ ما ليس في القائمة بترتيب إضافته.
     *
     * @param  Collection<int, Product>  $products
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public static function sort(Collection $products, array $ids): Collection
    {
        $at = array_flip($ids);
        $after = count($ids);

        return $products
            ->sortBy(fn ($p) => $at[(int) $p->id] ?? $after + (int) $p->id)
            ->values();
    }

    /**
     * قائمةُ هذا الرفّ — أو `null` إن لم يُرتَّب فيبقى أبجديًّا.
     *
     * @param  array{all: list<int>, categories: array<int, list<int>>}  $order
     * @return list<int>|null
     */
    public static function listFor(array $order, int $category): ?array
    {
        $ids = $category > 0 ? ($order[self::CATEGORIES][$category] ?? []) : $order[self::ALL];

        return $ids === [] ? null : $ids;
    }

    /**
     * المحفوظُ مقروءًا — وما لا يُفهم يُقرأ فراغًا لا خطأ.
     *
     * @return array{all: list<int>, categories: array<int, list<int>>}
     */
    public static function fromJson(string $raw): array
    {
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : [];

        $categories = [];
        foreach (is_array($data[self::CATEGORIES] ?? null) ? $data[self::CATEGORIES] : [] as $id => $ids) {
            if (is_numeric($id) && (int) $id > 0 && ($clean = self::ids($ids)) !== []) {
                $categories[(int) $id] = $clean;
            }
        }

        return [self::ALL => self::ids($data[self::ALL] ?? []), self::CATEGORIES => $categories];
    }

    /**
     * ما أرسلته اللوحةُ مفحوصًا ومكتوبًا بصيغةٍ واحدة — أو سببُ ردّه.
     *
     * والإذنُ أوّلًا: الشاشةُ التي لا تعرض اللوحةَ لا تحرس الباب. ثمّ:
     *
     *   - كلُّ معرّفٍ صنفٌ **من هذا المتجر**، أيًّا كان حالُه — فالمخفيُّ يحفظ
     *     موضعَه. وما سواه يُطوى بصمت: صنفٌ حُذف بين فتح اللوحة وحفظها لا
     *     يردّ الحفظَ كلَّه، وصنفُ متجرٍ آخر لا يُكتب أبدًا.
     *   - وقائمةُ الفئة لأصنافها الآن — ففئةُ متجرٍ آخر لا يبقى فيها شيء.
     *   - والقائمةُ الفارغة تُحذف: الرفُّ يعود أبجديًّا.
     */
    public static function validated(int $businessId, mixed $raw): string
    {
        abort_unless(self::allowed($businessId), 403);

        if ($raw === null || $raw === '') {
            return '';
        }

        $fail = fn () => throw ValidationException::withMessages([
            self::KEY => __('تعذّرت قراءة ترتيب المنتجات — أعد ترتيبها.'),
        ]);

        if (! is_string($raw) || strlen($raw) > self::RAW_MAX) {
            $fail();
        }

        $data = json_decode($raw, true);

        // كائنٌ بمفتاحيه — لا قائمةٌ عارية ولا نصّ
        if (! is_array($data) || ($data !== [] && array_is_list($data)) || ! is_array($data[self::ALL] ?? []) || ! is_array($data[self::CATEGORIES] ?? [])) {
            $fail();
        }

        $all = self::strict($data[self::ALL] ?? [], $fail);
        $lists = [];
        foreach ($data[self::CATEGORIES] ?? [] as $id => $ids) {
            if (! is_numeric($id) || (int) $id <= 0 || ! is_array($ids)) {
                $fail();
            }
            $lists[(int) $id] = self::strict($ids, $fail);
        }

        // ومعرّفُ الفئة لا يُسأل عنه وحده: قائمتُها لأصنافها، وأصنافُها من متجره — ففئةُ غيره تخلو وتُحذف
        $mine = Product::where('business_id', $businessId)
            ->whereIn('id', array_values(array_unique(array_merge($all, ...array_values($lists)))))
            ->pluck('category_id', 'id');

        $out = [];
        $all = array_values(array_filter($all, fn (int $id) => $mine->has($id)));
        if ($all !== []) {
            $out[self::ALL] = $all;
        }

        $kept = [];
        ksort($lists);
        foreach ($lists as $category => $ids) {
            $ids = array_values(array_filter($ids, fn (int $id) => $mine->has($id) && (int) $mine[$id] === $category));
            if ($ids !== []) {
                $kept[(string) $category] = $ids;
            }
        }
        if ($kept !== []) {
            $out[self::CATEGORIES] = (object) $kept;
        }

        return $out === [] ? '' : json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    /**
     * حمولةُ اللوحة في المحرّر — و`null` لمن لم تُفتح له.
     *
     * الأصنافُ المعروضةُ بترتيب الرفّ الأبجديّ نفسِه (`orderBy('name')`) —
     * فما تقوله اللوحةُ «أبجديّ» هو ما يراه الزبون حرفًا. ومعها المخفيُّ
     * الذي له موضعٌ في قائمة: يُرسم موسومًا ليبقى موضعُه.
     *
     * @param  array<string, string>  $draft  ما يحرّره الآن (`StoreContent::draft`)
     * @return array{value: string, products: list<array{id: int, name: string, image: ?string, category_id: ?int, shown: bool}>}|null
     */
    public static function forEditor(int $businessId, array $draft): ?array
    {
        if (! self::allowed($businessId)) {
            return null;
        }

        $value = (string) ($draft[self::KEY] ?? '');
        $order = self::fromJson($value);
        $placed = array_values(array_unique(array_merge($order[self::ALL], ...array_values($order[self::CATEGORIES]))));

        $products = Product::where('business_id', $businessId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('active', true)->where('published', true))
                ->orWhereIn('id', $placed))
            ->orderBy('name')
            ->get(['id', 'name', 'image', 'category_id', 'active', 'published']);

        return [
            'value' => $value,
            'products' => $products->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'image' => ProductImages::hasRealMain($p) ? $p->image : null,
                'category_id' => $p->category_id === null ? null : (int) $p->category_id,
                'shown' => (bool) $p->active && (bool) $p->published,
            ])->values()->all(),
        ];
    }

    /**
     * معرّفاتٌ موجبةٌ فريدةٌ بترتيبها — قراءةٌ متسامحة.
     *
     * @return list<int>
     */
    private static function ids(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0 && ! in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
        }

        return array_slice($out, 0, self::MAX);
    }

    /**
     * والقراءةُ الصارمة عند الحفظ: أرقامٌ موجبةٌ لا غير، بحدٍّ، والمكرَّرُ يُطوى.
     *
     * @return list<int>
     */
    private static function strict(mixed $raw, \Closure $fail): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MAX) {
            $fail();
        }

        $out = [];
        foreach ($raw as $id) {
            if (! is_int($id) || $id <= 0) {
                $fail();
            }
            $out[$id] = true;
        }

        return array_keys($out);
    }
}
