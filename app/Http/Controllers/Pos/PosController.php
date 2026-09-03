<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderMail;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\AddonStock;
use App\Support\Books;
use App\Support\Customers;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\Loyalty;
use App\Support\OrderStatus;
use App\Support\PlanFeatures;
use App\Support\PosCashier;
use App\Support\PosTerminal;
use App\Support\ProductAddons;
use App\Support\ReceiptVisibility;
use App\Support\Recipe;
use App\Support\Stock;
use App\Support\StockLedger;
use App\Support\Vat;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    /** صرفُ النقاط إلى مال — من بابه الواحد (انظر Support\Loyalty) */
    private const POINTS_PER_UNIT = Loyalty::POINTS_PER_UNIT;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * نسبة ضريبة القيمة المضافة: إعداد النشاط، ثم الإعداد العام، ثم 5%.
     *
     * والمفتاح يُقرأ أوّلًا. كان في الإعدادات مقبضٌ يقول «تفعيل ضريبة القيمة
     * المضافة — تُحتسب على كل فاتورة بيع» ولا يقرؤه شيء: يُطفئه من لا ضريبة
     * عليه — ومعظم من يبيع في عُمان دون حدّ التسجيل كذلك — فتبقى الضريبة
     * تُضاف إلى كل فاتورة، ويُقرّ بها في التقرير الضريبي، ويجبيها من زبائنه
     * وهو غير مخوَّلٍ بجبايتها. مقبضٌ يطمئن ولا يفعل، في أخطر موضع.
     */
    private function vatRate(): float
    {
        $bid = $this->bid();

        if (! Vat::enabled($bid)) {
            return 0.0;
        }

        $v = Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value')
            ?? Setting::whereNull('business_id')->where('key', 'vat_rate')->value('value');

        return max(0.0, (float) ($v ?? 5));
    }

    /**
     * ضريبة الفاتورة بنسبة كل صنف على حدة.
     *
     * كانت نسبةً واحدة على المجموع كلّه، فيدفع بائع الخبز ضريبةً على صنفٍ
     * صفريّ. والصنف الذي لا نسبة له يأخذ نسبة المتجر، فلا يتغيّر شيء لمن
     * لم يلمس الحقل.
     *
     * وخصم الفاتورة (كوبونًا كان أو نقاطًا) يُوزَّع على البنود بنسبة قيمتها:
     * حسمُه من وعاءٍ واحد يُنقص ضريبة الصنف الصفريّ ويُبقيها كاملةً على
     * الخاضع — وهو خطأٌ في الإقرار لا في الشاشة وحدها.
     */
    private function taxFor(array $lines, float $subtotal, float $discount): float
    {
        /*
         * الإطفاء يسبق كل نسبة — نسبة المتجر ونسبة الصنف معًا.
         *
         * ولا يكفي أن تصير نسبة المتجر صفرًا: الصنف الذي كُتبت له نسبةٌ خاصّة
         * لا يقرأ نسبة المتجر أصلًا، فيبقى يُضرَّب بضريبته وحده في متجرٍ
         * أطفأ الضريبة كلّها.
         */
        if (! Vat::enabled($this->bid())) {
            return 0.0;
        }

        $default = $this->vatRate();
        $inclusive = Vat::inclusive($this->bid());
        $tax = 0.0;

        foreach ($lines as $l) {
            /*
             * الإضافةُ داخل وعاء الضريبة كما هي داخل المجموع.
             *
             * «الإضافات جزءٌ من ثمن البند لا سطرٌ منفصل» — هكذا يُبنى المجموع
             * الفرعيّ (price × qty + addons_total) وهكذا تُطبع الفاتورة. وكان
             * الوعاء وحده يقرأ سعر الصنف ويُسقط إضافته: بيعةُ باقةٍ بعشرةٍ
             * وشوكولاتةٍ بخمسة تُحتسب ضريبتُها على عشرة.
             *
             * وليس هذا خطأً في شاشة: هو ضريبةٌ لم تُجبَ من الزبون ولم تُقرّ
             * للدولة، عن كلّ إضافةٍ باعها المحلّ.
             *
             * وحصّةُ البند من الخصم كانت تُقاس بالرقم الناقص نفسه، فتجمع
             * الحصصُ أقلّ من واحدٍ صحيح: يُطرح من الوعاء بعضُ الخصم ويبقى
             * باقيه محسوبًا عليه.
             */
            $net = $l['price'] * $l['qty'] + ($l['addons_total'] ?? 0);
            $share = $subtotal > 0 ? $net / $subtotal : 0;
            $taxable = $net - ($discount * $share);
            $rate = $l['product'] ? $l['product']->taxRate($default) : $default;

            /*
             * «مشمولة» تُستخرَج ولا تُضاف: ما على الرفّ هو ما يدفعه الزبون،
             * فالضريبة جزءٌ منه — ١٠٥ بنسبة ٥٪ ضريبتها ٥ لا ٥.٢٥.
             */
            $tax += $inclusive
                ? ($taxable * $rate) / (100 + $rate)
                : ($taxable * $rate) / 100;
        }

        return round($tax, 3);
    }

    private function setting(string $key, $default = null)
    {
        return Setting::where('business_id', $this->bid())->where('key', $key)->value('value') ?? $default;
    }

    /**
     * تغذية المخزون — الكميات وحدها، تُستطلَع من شاشة البيع كل بضع ثوانٍ.
     *
     * بعد بيعِه هو يحدّث الكاشير قائمته بـreload جزئي، لكن بيع زميله على
     * جهاز آخر (أو تعديل المخزون من اللوحة، أو استلام أمر شراء) كان يبقى
     * خفيًّا حتى تُحدَّث الصفحة — فيَعِد الزبون بصنف نفد ثم يُرفض عند الدفع.
     *
     * لا يُعيد المنتجات كاملة عن قصد: الاسم والسعر والصورة لا تتغيّر كل
     * عشرين ثانية، والكمية هي وحدها المتحرّكة. حمولة أخفّ عشرات المرّات
     * على شبكة متجر قد تكون بطيئة.
     */
    public function stockFeed()
    {
        // نفس مصدر الشاشة عند فتحها: رصيد الفرع النشط. تغذيةٌ تقيس شيئًا
        // آخر غير ما عُرض أول مرّة تجعل الرقم يقفز بلا سبب ظاهر.
        $available = Stock::availabilityResolver($this->bid(), Demo::activeBranchId());

        $products = Product::where('business_id', $this->bid())
            ->orderBy('id')->get(['id', 'quantity', 'alert_qty'])
            ->map(function ($p) use ($available) {
                $qty = $available($p->id, (int) $p->quantity);

                return [
                    'id' => $p->id,
                    'qty' => $qty,
                    'stock_status' => Product::statusFor($qty, (int) $p->alert_qty),
                ];
            })->values();

        return response()->json([
            'products' => $products,
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * يُسعّر بنود السلة من قاعدة البيانات لا من الطلب.
     *
     * سعر العميل مُدخَل غير موثوق: قبولُه كما يأتي كان يسمح ببيع منتج حقيقي
     * بـ0.001 أو بسعر سالب يقيّد "دخلًا" سالبًا في المالية. كل بند هنا يجب أن
     * يطابق منتجًا أو إضافة ضمن نفس النشاط، وإلا رُفض الطلب كله.
     *
     * @param  bool  $lock  يقفل صفوف المنتجات حتى نهاية المعاملة.
     *
     * الكمية كانت تُقرأ بلا قفل ثم يُحكم عليها في assertStock ثم تُخصم —
     * وبين القراءة والخصم نافذة. كاشيران يبيعان آخر قطعة في اللحظة نفسها
     * يقرآن كلاهما «المتوفر 1»، فيمرّان معًا ويصير المخزون سالبًا وقد بيعت
     * قطعة لا وجود لها.
     *
     * لم يظهر ذلك على SQLite لأنها تقفل القاعدة كلها عند الكتابة فتُسلسِل
     * العمليات — القفل من المحرّك لا من الكود. وعلى PostgreSQL (وجهة النقل)
     * القراءات لا تتعارض، فالنافذة مفتوحة على مصراعيها.
     */
    private function priceItems(array $items, bool $lock = false): array
    {
        $bid = $this->bid();
        $query = Product::where('business_id', $bid)
            ->whereIn('id', collect($items)->pluck('id')->filter()->unique()->all());

        if ($lock) {
            // ترتيب ثابت: قفل الصفوف بترتيب مختلف بين عمليتين يُنتج تعارضًا دائريًا
            $query->orderBy('id')->lockForUpdate();
        }

        $products = $query->get()->keyBy('id');
        $addons = Addon::where('business_id', $bid)->get();

        // تُحمَّل مرّةً واحدة لكلّ السلّة: بلا هذا كان كلّ بندٍ يستعلم عن
        // مقاساته ووصفته وإضافاته المسموحة — أربعة استعلامات في كلّ بند
        $variants = ProductVariant::where('business_id', $bid)
            ->whereIn('product_id', $products->keys())->get()->keyBy('id');
        $recipes = RecipeItem::where('business_id', $bid)
            ->whereIn('product_id', $products->keys())
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
        $addonMap = ProductAddons::map($bid);
        $componentCosts = Product::where('business_id', $bid)
            ->whereIn('id', $recipes->flatten()->pluck('component_product_id')->unique())
            ->pluck('cost', 'id')->map(fn ($c) => (float) $c)->all();

        $lines = [];
        $errors = [];

        foreach ($items as $idx => $i) {
            $qty = max(1, (int) $i['qty']);

            if (! empty($i['id'])) {
                $product = $products->get((int) $i['id']);
                if (! $product) {
                    $errors["items.$idx.id"] = __('صنف غير موجود في هذا المتجر.');

                    continue;
                }

                /*
                 * وصنفٌ أُوقف لا يُباع.
                 *
                 * مفتاح «نشِط/معطّل» في شاشة المنتجات — وله إجراءٌ جماعيّ
                 * كامل — لم يكن يمنع بيعًا قطّ: لا الشاشة تُخفي الموقوف ولا
                 * الخادم يردّه. فمن أوقف صنفًا انتهى موسمه، أو صنفًا سُحب،
                 * ظنّ أنّه رفعه عن الرفّ وهو يُباع في كلّ وردية. ومقبضٌ
                 * موصولٌ بلا شيء أسوأ من غيابه، لأنّه يطمئن.
                 *
                 * والحارس هنا لا في الشاشة وحدها: سلّةٌ عُلّقت قبل الإيقاف
                 * تُستأنف بعده، وماسحٌ يقرأ الباركود لا يسأل الشاشة.
                 */
                if (! $product->active) {
                    $errors["items.$idx.id"] = __('«:name» موقوف عن البيع.', ['name' => $product->name]);

                    continue;
                }

                /*
                 * المقاس يُقرأ من القاعدة لا من الطلب.
                 *
                 * منتجٌ له مقاسات لا يُباع بنفسه: سعرُه عمودٌ لا معنى له بعد
                 * أن صار لكلّ مقاسٍ سعره. وقبولُ بندٍ بلا مقاس كان يبيع
                 * «بوكيه الحب» بسعر الصفر — أو بسعرٍ قديمٍ في العمود.
                 */
                $choices = $product->relationLoaded('variants')
                    ? $product->variants
                    : $variants->where('product_id', $product->id);
                $variant = null;

                if (! empty($i['variant_id'])) {
                    $variant = $variants->get((int) $i['variant_id']);
                    if (! $variant || (int) $variant->product_id !== (int) $product->id) {
                        $errors["items.$idx.variant_id"] = __('مقاس غير موجود لهذا الصنف.');

                        continue;
                    }
                    // مقاسٌ أُطفئ لا يُباع من جديد — والفواتير القديمة تبقى تعرضه
                    if (! $variant->active) {
                        $errors["items.$idx.variant_id"] = __('هذا المقاس غير متاح للبيع.');

                        continue;
                    }
                } elseif ($choices->where('active', true)->isNotEmpty()) {
                    $errors["items.$idx.variant_id"] = __('اختر مقاس :name.', ['name' => $product->name]);

                    continue;
                }

                $preloaded = $recipes->get($product->id) ?? collect();
                $hasRecipe = Recipe::has($product, $variant, $preloaded);

                /*
                 * تكلفة البند: من الوصفة إن كانت له، ومن عمود المنتج إن لم تكن.
                 *
                 * وتُلتقط هنا لا في التقرير: تكلفة الورد تتغيّر مع كلّ شحنة،
                 * وقراءتها لاحقًا تجعل ربح الشهر الماضي يتحرّك بلا سبب.
                 */
                $cost = $hasRecipe
                    ? Recipe::unitCost($product, $variant, $preloaded, $componentCosts)
                    : (float) $product->cost;

                [$chosen, $addonError] = $this->pickAddons($product, $i['addons'] ?? [], $addons, $addonMap);
                if ($addonError) {
                    $errors["items.$idx.addons"] = $addonError;

                    continue;
                }

                $price = $variant
                    ? round((float) $variant->price, 3)
                    : $product->sellingPrice();

                $lines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'name' => $product->name,
                    'price' => $price,
                    'list_price' => $variant ? (float) $variant->price : (float) $product->price,
                    'cost' => $cost,
                    'has_recipe' => $hasRecipe,
                    'recipe' => $preloaded,
                    'qty' => $qty,
                    'note' => $i['note'] ?? null,
                    'addons' => $chosen,
                    'addons_total' => round(collect($chosen)->sum('total'), 3),
                ];

                continue;
            }

            // إضافة: بالمعرّف إن أُرسل، وإلا بالاسم — لطلبات مؤجَّلة رُفعت من نسخة أقدم من الواجهة
            $addon = ! empty($i['addon_id'])
                ? $addons->firstWhere('id', (int) $i['addon_id'])
                : $addons->first(fn ($a) => $a->name === ($i['name'] ?? null) || $a->name_en === ($i['name'] ?? null));

            if (! $addon || ! $addon->active) {
                $errors["items.$idx.name"] = __('صنف غير متاح للبيع.');

                continue;
            }
            // تكلفتُها تكلفةُ ما تأكله: إضافةٌ بيعت بندًا مستقلًّا كانت
            // تُسجَّل بتكلفة صفر، فيظهر ربحُ الدبّ كاملًا وهو مشترًى
            $standaloneCost = $addon->inventory_product_id
                ? round((float) (Product::find($addon->inventory_product_id)?->cost ?? 0)
                    * AddonStock::each($addon), 3)
                : 0.0;

            $lines[] = ['product' => null, 'variant' => null, 'name' => $addon->name,
                'price' => (float) $addon->price, 'list_price' => (float) $addon->price,
                'cost' => $standaloneCost, 'has_recipe' => false, 'recipe' => collect(),
                'qty' => $qty, 'note' => $i['note'] ?? null,
                // إضافةٌ مستقلّة في السلّة (سلوك ما قبل الربط) — رصيدها يُخصم كما لو رُبطت ببند
                'addons' => [], 'addons_total' => 0.0,
                'standalone_addon' => $addon];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * الإضافات المختارة على بند — بأسعار القاعدة وبإذن المنتج.
     *
     * السعر لا يُقرأ من الطلب أبدًا، والإذن يُسأل في الخادم: شاشةٌ قديمة —
     * أو مُلاعَبة — قد ترسل دبًّا مع منتجٍ لا يسمح به، أو بأربعةٍ بدل خمسة.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    private function pickAddons(Product $product, array $requested, $addons, array $addonMap): array
    {
        $chosen = [];

        foreach ($requested as $r) {
            $id = (int) ($r['addon_id'] ?? $r['id'] ?? 0);
            $addon = $id ? $addons->firstWhere('id', $id) : null;

            if (! $addon || ! ProductAddons::allows($product, $addon, $addonMap, $addons)) {
                return [[], __('إضافة غير متاحة مع هذا الصنف.')];
            }

            $qty = max(1, (int) ($r['qty'] ?? 1));
            $price = round((float) $addon->price, 3);

            /*
             * ما تأكله الإضافةُ الواحدة — لا ما يُرسَل من الشاشة.
             *
             * «زيادة ثلاث وردات» تأكل ثلاثًا لا واحدة، واثنتان منها ستًّا.
             * والرقم يُقرأ من القاعدة كالسعر تمامًا: شاشةٌ تقول واحدة تجعل
             * الرفّ ينقص ثلاثًا والنظام يقول واحدة.
             */
            $each = AddonStock::each($addon);

            $chosen[] = [
                'addon' => $addon,
                'qty' => $qty,
                'price' => $price,
                'total' => round($price * $qty, 3),
                'inventory_product_id' => $addon->inventory_product_id ? (int) $addon->inventory_product_id : null,
                'each' => $each,
                // تكلفةٌ للإضافة المرتبطة ببضاعة، وفراغٌ لخدمةٍ لا رصيد لها —
                // وخلطُ الاثنين يجعل الربح يبدو أعلى ممّا هو.
                // وهي تكلفةُ الإضافة الواحدة: ثمن الوردة في ثلاث، لا ثمن وردة
                'cost' => $addon->inventory_product_id
                    ? round((float) (Product::find($addon->inventory_product_id)?->cost ?? 0) * $each, 3)
                    : null,
            ];
        }

        return [$chosen, null];
    }

    /**
     * ما يُخصم فعلًا من الرفّ مقابل هذه السلّة — بالأعداد الصحيحة.
     *
     * ثلاثة مصادر تجتمع في خريطةٍ واحدة:
     *
     *   - بندٌ بلا وصفة  →  الصنف نفسه ينقص، كما كان قبل هذا كلّه
     *   - بندٌ بوصفة     →  مكوّناتُه تنقص وهو لا ينقص (وإلا خُصم مرّتين:
     *                       مرّةً باقةً ومرّةً وردًا)
     *   - إضافةٌ لها رصيد →  بضاعتُها تنقص
     *
     * والجمع قبل التقريب: انظر Recipe::units.
     *
     * @return array<int, int> [معرّف المنتج => الكمية المطلوبة]
     */
    private function demand(array $lines): array
    {
        $exact = [];
        $whole = [];

        // الإضافات تُجمع على حدة ثم تُرفع مرّةً واحدة — بالقاعدة نفسها التي
        // يُرفع بها استهلاك الوصفة، وبنفس الفصل الذي يفصل حركتيهما في الدفتر
        $addonExact = self::addonConsumption($lines);

        foreach ($lines as $l) {

            if (! $l['product']) {
                continue;
            }

            if (! ($l['has_recipe'] ?? false)) {
                $whole[$l['product']->id] = ($whole[$l['product']->id] ?? 0) + $l['qty'];

                continue;
            }

            foreach (Recipe::consumptionFor($l['product'], $l['variant'] ?? null, $l['qty'], $l['recipe']) as $pid => $q) {
                $exact[$pid] = ($exact[$pid] ?? 0.0) + $q;
            }
        }

        foreach ($exact as $pid => $q) {
            $whole[$pid] = ($whole[$pid] ?? 0) + Recipe::units($q);
        }

        foreach (AddonStock::units($addonExact) as $pid => $u) {
            $whole[$pid] = ($whole[$pid] ?? 0) + $u;
        }

        return $whole;
    }

    /**
     * ما تأكله إضافات السلّة من الرفّ — بالكسر قبل الرفع.
     *
     * موضعٌ واحد يقرأه الفحصُ قبل البيع والخصمُ بعده: لو حُسب مرّتين لجاز
     * أن يفحص أحدهما خمسةَ عشر ويخصم الآخر ستّةَ عشر، فيُقبل بيعٌ لا رصيد
     * له — أو يُردّ بيعٌ له رصيد.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, float>
     */
    private static function addonConsumption(array $lines): array
    {
        $out = [];

        foreach ($lines as $l) {
            // إضافةٌ بيعت بندًا مستقلًّا في السلّة (سلوك ما قبل الربط):
            // كميّتُها كميّةُ البند نفسه
            $standalone = $l['standalone_addon'] ?? null;
            if ($standalone) {
                $each = AddonStock::each($standalone);
                if ($each > 0) {
                    $pid = (int) $standalone->inventory_product_id;
                    $out[$pid] = ($out[$pid] ?? 0.0) + $each * (int) $l['qty'];
                }
            }

            /*
             * وإضافةُ البند كميّتُها مطلقة لا مضروبةٌ في كميّته.
             *
             * «شوكولاتة ×١» على بندٍ كميّتُه اثنتان تبقى واحدة — وهو ما
             * يُحسب به ثمنُها في الفاتورة منذ البدء (addons_total تُضاف مرّةً
             * للبند لا لكلّ قطعة). فضربُها في الكمية هنا كان سيجعل الرفّ
             * ينقص ضِعفَ ما دُفع ثمنُه.
             */
            foreach ($l['addons'] ?? [] as $a) {
                $each = (float) ($a['each'] ?? 0);
                $pid = $a['inventory_product_id'] ?? null;
                if ($pid && $each > 0) {
                    $out[(int) $pid] = ($out[(int) $pid] ?? 0.0) + $each * (int) $a['qty'];
                }
            }
        }

        return $out;
    }

    /** يمنع البيع بما يتجاوز المتوفر — إلا إذا سمح النشاط بالمخزون السالب صراحةً */
    private function assertStock(array $lines, ?int $branchId = null): void
    {
        if ((string) $this->setting('allow_negative_stock', '0') === '1') {
            return;
        }

        // نفس المنتج قد يرد في أكثر من بند — ومكوّنٌ واحد قد يدخل في باقاتٍ
        // شتّى — فالحكم على المجموع لا على كل بند وحده
        $needed = $this->demand($lines);

        if (! $needed) {
            return;
        }

        $byId = Product::where('business_id', $this->bid())->whereIn('id', array_keys($needed))->get()->keyBy('id');

        // الحكم على رصيد الفرع الذي سيُخصم منه. الحكم على مجموع الشركة كان
        // يُجيز بيع خمس قطع من صلالة ورصيدها صفر لأن في مسقط عشرًا.
        $available = Stock::availabilityResolver(
            $this->bid(), $branchId, array_keys($needed), lock: true,
        );

        $short = [];
        foreach ($needed as $pid => $want) {
            $product = $byId->get($pid);
            if (! $product) {
                continue;
            }
            $have = $available($pid, (int) $product->quantity);
            if ($have < $want) {
                $short[] = __(':name — المتوفر :have والمطلوب :want', [
                    'name' => $product->name, 'have' => $have, 'want' => $want,
                ]);
            }
        }

        if ($short) {
            throw ValidationException::withMessages(['items' => $short]);
        }
    }

    /**
     * رقم متسلسل لكل نشاط.
     *
     * كان random_int(78900, 99999) بلا قيد فريد: 21,100 قيمة فقط تعني احتمال
     * تصادم ≈61% خلال 200 فاتورة — أي فاتورتين مختلفتين تحملان الرقم نفسه.
     */
    private function nextNumber(string $prefix, int $start = 1): string
    {
        $offset = strlen($prefix) + 1; // عدد صحيح من strlen، فلا خطر حقن هنا

        // SQLite تتساهل مع CAST لنصّ غير رقمي فتُرجع 0، أما PostgreSQL فترفع
        // «invalid input syntax for type integer». ولأن هذا السطر يجري مع كل
        // بيعة، رقمٌ واحد شاذّ — من نسخة مستعادة أو إدخال يدوي — كان يكفي
        // لتعطيل الصندوق كلّه بعد النقل إلى PostgreSQL.
        $driver = DB::connection()->getDriverName();
        $suffix = match ($driver) {
            'pgsql' => "NULLIF(regexp_replace(SUBSTRING(number FROM {$offset}), '\\D', '', 'g'), '')::bigint",
            'mysql', 'mariadb' => "CAST(SUBSTRING(number, {$offset}) AS UNSIGNED)",
            default => "CAST(SUBSTR(number, {$offset}) AS INTEGER)",
        };

        $last = Order::where('business_id', $this->bid())
            ->where('number', 'like', $prefix.'%')
            ->orderByRaw("{$suffix} DESC")
            ->value('number');

        // أوّل فاتورةٍ بهذه البادئة تبدأ من الرقم الذي اختاره التاجر، وما بعدها
        // يتبع الأخير. ولذلك تغيير البادئة يفتح تسلسلًا جديدًا لا يصطدم بالقديم.
        $n = $last ? (int) substr($last, strlen($prefix)) : max(0, $start - 1);

        return $prefix.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * بادئة رقم الفاتورة كما اختارها التاجر.
     *
     * كانت 'INV-' مثبّتةً في الكود بينما الإعدادات تعرض حقلًا يُحفظ ولا يُقرأ.
     * والتنقية ليست زينة: البادئة تدخل شرط LIKE، فـ«%» فيها تجعل كل فاتورةٍ
     * مطابقةً فيقفز العدّاد إلى رقمٍ لا يخصّها.
     */
    private function salePrefix(): string
    {
        $raw = trim((string) $this->setting('inv_prefix', 'INV-'));
        $clean = str_replace(['%', '_', '\\'], '', $raw);

        return $clean === '' ? 'INV-' : mb_substr($clean, 0, 12);
    }

    /**
     * طرق الدفع التي أذن بها التاجر.
     *
     * كانت مفاتيح pay_* تُحفظ ولا يقرؤها أحد: يُطفئ التاجر «بطاقة» فتبقى
     * معروضةً في الصندوق ومقبولةً — ثم يحاسب موظفًا قبِل ما ظنّ أنه منعه.
     *
     * ولا تعود فارغةً أبدًا: من أطفأ الثلاث لا يُراد به أن يقف البيع، فيبقى
     * النقد. حجبُ وسيلةٍ إعدادٌ، وإيقافُ الصندوق عطل.
     */
    public static function enabledPaymentMethods(array $settings): array
    {
        $all = ['نقدي' => 'pay_cash', 'بطاقة' => 'pay_card', 'تحويل بنكي' => 'pay_transfer'];
        $on = [];
        foreach ($all as $label => $key) {
            if (($settings[$key] ?? '1') !== '0') {
                $on[] = $label;
            }
        }

        return $on ?: ['نقدي'];
    }

    /**
     * وسيلة الدفع تُختار ولا تُخمَّن.
     *
     * كانت تُردّ إلى أوّل المأذون حين تغيب أو لا تُعرف — أي أنّ بيعةً بالبطاقة
     * وصلت بوسيلةٍ خاطئة تُقيَّد «نقدي»، وبيعةً بلا وسيلةٍ أصلًا تُقيَّد نقدًا.
     * وأثرُ ذلك في الدرج لا في الشاشة: إقفال الوردية يطلب مالًا لم يدخل
     * الصندوق، فيقف الكاشير أمام عجزٍ لم يُحدثه ويُعوّضه من جيبه أو يُسجّله
     * فرقًا. وهو أسوأ صنف من العطب: كلّ ما يُرى منه سليم.
     *
     * فصارت مطلوبةً في التحقّق، وهذه تحرس ما بعده: قيمةٌ خارج المأذون تُردّ
     * بخطأ تحقّقٍ لا بتخمين.
     */
    private function paymentMethod(?string $requested): string
    {
        $allowed = self::enabledPaymentMethods(Demo::businessSettings());

        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages([
                'payment_method' => __('اختر وسيلة الدفع.'),
            ]);
        }

        return $requested;
    }

    /** ينشئ الطلب برقم فريد، ويعيد المحاولة إن سبقه كاشير آخر إلى الرقم نفسه */
    private function createNumbered(array $attrs, string $prefix, int $start = 1): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::create($attrs + ['number' => $this->nextNumber($prefix, $start)]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('تعذّر توليد رقم فاتورة فريد.');
    }

    /** فرع الطلب: الفرع المختار حاليًا، وإلا أول فرع للنشاط — حتى يظهر الطلب تحت فلتر الفروع */
    private function branch(): array
    {
        $branch = Branch::where('business_id', $this->bid())
            ->find(Demo::activeBranchId());

        return [
            'id' => $branch?->id,
            'name' => $branch?->name ?? 'الفرع الرئيسي',
        ];
    }

    /** بحث خادمي في كل فواتير المتجر (رقم/عميل/هاتف) — يغطّي كامل التاريخ لا آخر 30 فقط */
    public function searchReceipts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['receipts' => []]);
        }

        // نفس التجريد المطبَّق على القائمة: البحث بلا هذا يُبطل الحجب كلّه،
        // لأنه يتجاوز الثلاثين إلى تاريخ الفرع كلّه
        return response()->json([
            'receipts' => ReceiptVisibility::filter(Demo::receipts($q, 50)),
        ]);
    }

    /**
     * فاتورة واحدة بتفاصيلها — تُفتح بالنقر على رقمها.
     *
     * تفصيلُ فاتورةٍ بعينها متاح للجميع: الزبون يستلمها مطبوعة على أي حال،
     * والكاشير يحتاجها عند الإرجاع. الممنوع هو الاطّلاع بالجملة.
     */
    public function showReceipt(string $number)
    {
        $receipt = collect(Demo::receipts($number, 50))->firstWhere('number', $number);

        abort_if($receipt === null, 404);

        return response()->json(['receipt' => $receipt]);
    }

    /** إتمام البيع وحفظ الطلب */
    /**
     * كوبون النشاط بالكود (غير حسّاس لحالة الأحرف).
     *
     * $lock: يُقرأ بقفلٍ عند الدفع — الفحص «هل بقيت مرّة؟» وزيادةُ العدّاد
     * يجب أن يقعا على صفٍّ لا يتغيّر تحتهما. وبلا ذلك يمرّ صندوقان معًا على
     * كوبونٍ محدودٍ بمرّةٍ واحدة فيستهلكانه مرّتين، ويخرج الخصم مرّتين من
     * كوبونٍ بيع مرّة.
     */
    private function findCoupon(?string $code, bool $lock = false): ?Coupon
    {
        if (empty($code)) {
            return null;
        }

        return Coupon::where('business_id', $this->bid())
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /** التحقق من كود الخصم وتطبيقه (يُستدعى من السلة قبل الدفع) */
    public function applyCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = $this->findCoupon($data['code']);
        $subtotal = (float) $data['subtotal'];

        $error = match (true) {
            ! $coupon => __('كود الخصم غير صحيح'),
            ! $coupon->active => __('هذا الكوبون موقوف'),
            $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
            $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Demo::money($coupon->min_order)]),
            default => null,
        };

        if ($error) {
            return response()->json(['ok' => false, 'error' => $error], 422);
        }

        return response()->json([
            'ok' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount' => $coupon->discountFor($subtotal),
            'message' => __('تم تطبيق الكوبون: :code', ['code' => $coupon->code]),
        ]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer'],
            // المقاس والإضافات يُتحقّق منهما في priceItems: هناك وحده تُعرف
            // مقاسات المنتج وإضافاته المسموحة، والقاعدة لا تُكتب مرّتين
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.addons' => ['nullable', 'array'],
            'items.*.addons.*.addon_id' => ['required_with:items.*.addons', 'integer'],
            'items.*.addons.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string'],
            // السعر يُقرأ من القاعدة لا من الطلب؛ يُقبل الحقل للتوافق ويُتجاهل
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            // المعرّف هو ما تتبعه النقاط؛ والهاتف مرجعٌ ثانٍ حين يغيب
            'customer_id' => ['nullable', 'integer'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            // مطلوبة: انظر `paymentMethod` أدناه لأثر تخمينها في إقفال الوردية
            'payment_method' => ['required', 'string'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'resume_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            /*
             * تفاصيل طلب الورد — اختياريّةٌ كلّها.
             *
             * بيعةُ المارّ يجب أن تبقى ثلاث نقرات: يضع الباقة، يضغط الدفع،
             * ينتهي. وإلزامُ المستلِم والموعد على كلّ بيعةٍ يجعل الكاشير يملأ
             * حقولًا لا معنى لها في نصف بيعات اليوم — فيملؤها بأيّ شيء،
             * وتصير البيانات أسوأ من غيابها.
             */
        ] + FlowerOrder::rules(), [
            'payment_method.required' => __('اختر وسيلة الدفع.'),
        ] + FlowerOrder::messages());

        // والتوصيل وحده يُسأل عن مستلِمه وعنوانه — شرطٌ بين حقول لا على حقل
        if ($flowerErrors = FlowerOrder::afterValidation($data)) {
            throw ValidationException::withMessages($flowerErrors);
        }

        // صمود الانقطاع: لو أُعيد رفع نفس الطلب (بعد عودة الاتصال) نعيد الفاتورة الأصلية بدل تكراره
        if (! empty($data['client_uuid'])) {
            $existing = Order::where('business_id', $this->bid())
                ->where('client_uuid', $data['client_uuid'])
                ->first();
            if ($existing) {
                return response()->json(['ok' => true, 'invoice' => $existing->number, 'duplicate' => true]);
            }
        }

        /*
         * ومن خسر السباق يُردّ إليه رقمُ الفاتورة الأولى — لا خطأُ خادم.
         *
         * الفحص أعلاه يسبق الكتابة، وبينهما فرجة. والقيد في القاعدة هو ما
         * يمنع فعلًا (انظر الهجرة one_uuid_one_invoice): فحين يصل طلبان
         * بالمفتاح نفسه معًا يكتب الأوّل ويُردّ الثاني بانتهاك القيد — وهو
         * ليس عطبًا بل الحارس يعمل. فيُقرأ كما يُقرأ الفحص: بيعةٌ واحدة،
         * وفاتورةٌ واحدة تُطبع، ولا مخزونَ يُخصم مرّتين ولا دخلَ يُقيَّد مرّتين.
         */
        try {
            $result = $this->completeSale($data);
        } catch (QueryException $e) {
            // وأيّ صنفٍ كان الاستثناء: الشاهد وجودُ التوأم لا اسمُ الخطأ
            $twin = filled($data['client_uuid'] ?? null)
                ? Order::where('business_id', $this->bid())->where('client_uuid', $data['client_uuid'])->first()
                : null;

            if (! $twin) {
                throw $e;
            }

            return response()->json(['ok' => true, 'invoice' => $twin->number, 'duplicate' => true]);
        }

        $order = $result['order'];

        Activity::log('checkout', 'أتمّ بيعًا '.$order->number.' بقيمة '.number_format($order->total, 3).' ر.ع', ['subject_id' => $order->id]);

        // البريد خارج المعاملة: بطؤه أو فشله يجب ألّا يُبقي القفل أو يُلغي بيعًا تمّ
        $this->notifyNewOrder($order);

        return response()->json([
            'ok' => true,
            'invoice' => $order->number,
            'total' => (float) $order->total,
            'points_earned' => $result['loyalty']['earned'],
            'points_redeemed' => $result['loyalty']['redeemed'],
        ]);
    }

    /**
     * البيع سبع كتابات مترابطة (طلب، بنود، مخزون، حركات، معاملة، نقاط، تنظيف
     * المعلّق). انقطاعٌ في المنتصف كان يترك طلبًا بلا معاملة مالية أو مخزونًا
     * منقوصًا بلا فاتورة — فتُنفَّذ كلها أو لا تُنفَّذ أيٌّ منها.
     *
     * @return array{order: Order, loyalty: array{earned: int, redeemed: int}}
     */
    private function completeSale(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $bid = $this->bid();
            $branch = $this->branch();

            /*
             * والسلّة المعلّقة تُحجَز أوّلًا — لا تُحذف آخرًا وحسب.
             *
             * كانت تُقرأ في نهاية المعاملة لتُمحى. فجهازان يستأنفان السلّة
             * نفسها — وهي معروضةٌ على كلّ أجهزة الفرع — يقرآنها موجودةً
             * كلاهما، فتصير سلّةٌ واحدة فاتورتين: بضاعةٌ تُخصم مرّتين وزبونٌ
             * يُطالَب بضعف ثمنها.
             *
             * والقفل يُصفّهما: الثاني ينتظر، فلا يجدها، فيُقال له إنّها
             * أُتمّت — وهو ما حدث فعلًا.
             */
            if (! empty($data['resume_id'])) {
                $heldNow = Order::where('business_id', $bid)->where('is_held', true)
                    ->lockForUpdate()->find($data['resume_id']);

                if (! $heldNow) {
                    throw ValidationException::withMessages([
                        'resume_id' => __('هذه السلّة المعلّقة أُتمّت من جهازٍ آخر.'),
                    ]);
                }
            }

            // بقفل: الفحص والخصم يجب أن يقعا على كمية لا تتغيّر تحتهما،
            // وعلى رصيد الفرع الذي سيُخصم منه لا على مجموع الشركة
            $lines = $this->priceItems($data['items'], lock: true);
            $this->assertStock($lines, $branch['id']);

            // الإضافات جزءٌ من ثمن البند لا سطرٌ منفصل: «بوكيه + شوكولاتة»
            // بندٌ واحد يقرؤه الزبون على الفاتورة، ومجموعُه يدخل الحساب معه
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty'] + ($l['addons_total'] ?? 0)), 3);

            /*
             * الكوبون: يُعاد التحقق منه خادميًا وتُحتسب قيمته من أسعارنا نحن.
             *
             * وما لا يصلح يُقال لا يُبتلع. كان يسقط صمتًا — `couponApplied`
             * تصير false وتمضي البيعة — فيُقال للزبون سعرٌ عند السلّة ويُطبع
             * له غيره على الفاتورة، ولا شيء على شاشة الكاشير يقول لماذا.
             * وأكثر ما يقع بين اللحظتين: كوبونٌ نفدت مرّاته من صندوقٍ آخر،
             * أو انتهت صلاحيته عند منتصف الليل والوردية ما زالت مفتوحة.
             *
             * وبقفلٍ: العدّاد يُقرأ ويُزاد على صفٍّ لا يتغيّر تحته.
             */
            $couponCode = $data['coupon_code'] ?? null;
            $coupon = $this->findCoupon($couponCode, lock: true);

            if (filled($couponCode)) {
                $refusal = match (true) {
                    ! $coupon => __('كود الخصم غير صحيح'),
                    ! $coupon->active => __('هذا الكوبون موقوف'),
                    $coupon->isExpired() => __('انتهت صلاحية الكوبون'),
                    $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => __('انتهت مرات استخدام الكوبون'),
                    $subtotal < (float) $coupon->min_order => __('الحد الأدنى للطلب :amount', ['amount' => Demo::money($coupon->min_order)]),
                    default => null,
                };

                if ($refusal !== null) {
                    throw ValidationException::withMessages([
                        'coupon_code' => $refusal,
                    ]);
                }
            }

            $couponApplied = $coupon !== null;
            $couponDiscount = $couponApplied ? min((float) $coupon->discountFor($subtotal), $subtotal) : 0.0;

            // موعدٌ في المستقبل يعني طلبًا يُجهَّز لا بيعةً انتهت — انظر 'status' أدناه
            $scheduled = filled($data['scheduled_for'] ?? null);

            $customer = $this->customerFor($data['customer'] ?? null, $data['customer_id'] ?? null, $data['customer_phone'] ?? null);
            $redeem = $this->resolveRedemption($customer, $subtotal, $couponDiscount, (int) ($data['redeem_points'] ?? 0));

            $discount = round(min($couponDiscount + $redeem['discount'], $subtotal), 3);
            $delivery = (float) ($data['delivery_fee'] ?? 0);
            $tax = $this->taxFor($lines, $subtotal, $discount);

            /*
             * «مشمولة»: المعروض هو المستحقّ، فالمجموع الفرعي يُنقص منه ما
             * استُخرج ضريبةً — ويبقى `subtotal - discount + tax` مساويًا لما
             * قرأه الزبون على الشاشة. وبلا هذا تُجمع الضريبة مرّتين: مرّةً
             * داخل السعر ومرّةً فوقه.
             */
            if (Vat::inclusive($bid)) {
                $subtotal = round($subtotal - $tax, 3);
            }

            $total = round($subtotal - $discount + $tax + $delivery, 3);

            if ($couponApplied) {
                $coupon->increment('used_count');
            }

            $order = $this->createNumbered([
                'business_id' => $bid,
                'client_uuid' => $data['client_uuid'] ?? null,
                'customer_name' => $customer?->name ?? $data['customer'] ?? 'عميل نقدي',
                'customer_name_en' => $customer?->name_en,
                'customer_id' => $customer?->id,
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                /*
                 * الصندوق الذي خرجت منه الفاتورة.
                 *
                 * يُقرأ من الخادم لا من الطلب: القيمة الوحيدة الموثوقة هي
                 * رمز الجهاز في الكوكي الموقَّعة. وحين ينقص الدرج عشرين ريالًا
                 * في محلٍّ فيه ثلاثة صناديق، هذا العمود وحده يقول أيّها.
                 */
                'pos_device_id' => PosTerminal::current()?->id,
                'payment_method' => $this->paymentMethod($data['payment_method'] ?? null),
                'payment_status' => 'مدفوع',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_code' => $couponApplied ? $coupon->code : null,
                // ثمن العرض وحده: `discount` يجمعه مع نقاط الولاء فلا يُعرف
                // كم كلّف كوبونٌ بعينه — وهو ما يقرّر إعادته أو إيقافه
                'coupon_discount' => $couponDiscount,
                'tax' => $tax,
                'delivery_fee' => $delivery,
                'total' => $total,
                'ordered_at' => now(),
                /*
                 * الحالة تتبع الطلب لا العكس.
                 *
                 * بيعةُ المنضدة تُدفع وتُؤخذ في اللحظة نفسها فهي «مكتمل» كما
                 * كانت. أمّا ما له موعدٌ في المستقبل فلم يكتمل شيء منه بعد:
                 * يُسجَّل «جديد» ليدخل لوحة التجهيز. ولو بقي «مكتمل» لَما ظهر
                 * لعامل التجهيز أبدًا — فيُجهَّز الطلب بورقةٍ على الجدار كما
                 * كان قبل النظام.
                 */
                'status' => $scheduled
                    ? OrderStatus::PENDING
                    : OrderStatus::COMPLETED,
            ] + FlowerOrder::attributes($data),
                $this->salePrefix(), max(1, (int) $this->setting('inv_start', 1)));

            foreach ($lines as $l) {
                $item = $order->items()->create([
                    'product_id' => $l['product']?->id,
                    /*
                     * لقطة المقاس — لا علاقةٌ تُقرأ لاحقًا.
                     *
                     * مقاسٌ أُعيد تسميته «وسط فاخر» ورُفع سعره بعد شهر لا
                     * يجوز أن يغيّر فاتورةً طُبعت. والمعرّف يبقى للتجميع،
                     * والاسم والرمز هما ما يُعرض.
                     */
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'variant_sku' => $l['variant']?->sku,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    /*
                     * تكلفة القطعة تُلتقط يوم البيع لا تُقرأ يوم التقرير.
                     *
                     * تكلفة المنتج تُكتب فوقها عند كل استلامٍ بآخر سعر شراء،
                     * فقراءتُها لاحقًا تجعل ربح الشهر الماضي يتغيّر لأن المورّد
                     * رفع سعره اليوم. واللقطة تُثبّت ما مضى. ولذي الوصفة
                     * تكلفتُه مجموعُ مكوّناته بأسعار اليوم — انظر Recipe::unitCost.
                     */
                    'cost' => (float) ($l['cost'] ?? 0),
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                    'addons_total' => (float) ($l['addons_total'] ?? 0),
                ]);

                foreach ($l['addons'] ?? [] as $a) {
                    $item->addons()->create([
                        'addon_id' => $a['addon']->id,
                        'name' => $a['addon']->name,
                        'name_en' => $a['addon']->name_en,
                        'unit_price' => $a['price'],
                        'quantity' => $a['qty'],
                        'total' => $a['total'],
                        'cost' => $a['cost'],
                        /*
                         * لقطةُ ما أُخذ من الرفّ — لا علاقةٌ تُقرأ يوم الإلغاء.
                         *
                         * إضافةٌ كانت ثلاث ورداتٍ فصارت خمسًا تردّ خمسًا عن
                         * بيعةٍ أخذت ثلاثًا لو قُرئت اليوم — فيربح الرفّ
                         * وردتين لا وجود لهما.
                         */
                        'inventory_product_id' => $a['inventory_product_id'] ?? null,
                        'inventory_quantity' => ($a['inventory_product_id'] ?? null) ? $a['each'] : null,
                    ]);
                }
            }

            /*
             * المخزون يُخصم مرّةً واحدة للسلّة كلّها لا بندًا بندًا.
             *
             * لأنّ مكوّنًا واحدًا يدخل في باقاتٍ شتّى: خصمُه في كلّ بندٍ على
             * حدة يقرّب كسرَه مرّاتٍ — انظر Recipe::units — ويكتب أربع حركات
             * حيث تكفي واحدة، فيصير سجلّ التدقيق أطول وأقلّ إفادة.
             *
             * وذو الوصفة لا يُخصم هو: مكوّناته هي مخزونه. وخصمُه معها كان
             * سيُنقص الباقة والورد معًا عن بيعةٍ واحدة.
             */
            $sale = [];
            $recipeUse = [];
            // الخصم يقرأ ما قرأه الفحص قبله بالحرف — نفس الدالّة لا نسختها
            $addonUse = AddonStock::units(self::addonConsumption($lines));

            foreach ($lines as $l) {
                if (! $l['product']) {
                    continue;
                }

                if (! ($l['has_recipe'] ?? false)) {
                    $sale[$l['product']->id] = ($sale[$l['product']->id] ?? 0) + $l['qty'];

                    continue;
                }

                foreach (Recipe::consumptionFor($l['product'], $l['variant'] ?? null, $l['qty'], $l['recipe']) as $pid => $q) {
                    $recipeUse[$pid] = ($recipeUse[$pid] ?? 0.0) + $q;
                }
            }

            $cashier = PosCashier::name();

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -$q, $sale), 'بيع', $cashier);

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -Recipe::units($q), $recipeUse),
                StockLedger::RECIPE, $cashier, $order->number);

            StockLedger::move($bid, $branch['id'],
                array_map(fn ($q) => -$q, $addonUse),
                StockLedger::ADDON, $cashier, $order->number);

            // تسجيل البيع كمعاملة دخل في المالية تلقائيًا (لتظهر المبيعات في لوحات المالية فورًا)
            Transaction::create([
                'business_id' => $bid,
                'order_id' => $order->id,
                'reference' => $order->number,
                'description' => 'مبيعات نقطة البيع — '.($order->customer_name ?? 'عميل نقدي'),
                'method' => $order->payment_method ?? 'نقدي',
                'type' => 'دخل',
                // «دخل» وحدها لا تكفي: تقرأها التقارير مبيعاتٍ ويقرأها
                // إيداعُ المالك دخلًا كذلك — انظر Transaction::SALE
                'kind' => \App\Models\Transaction::SALE,
                'amount' => $order->total,
                'tax_amount' => $order->tax ?? 0,
                'employee_name' => PosCashier::name(),
                'occurred_at' => $order->ordered_at ?? now(),
            ]);

            // الطلب المعلّق الذي استُكمل لم يعد لازمًا بعد إتمام بيعه
            if (! empty($data['resume_id'])) {
                $held = Order::where('business_id', $bid)->where('is_held', true)->find($data['resume_id']);
                if ($held) {
                    $held->items()->delete();
                    $held->delete();
                }
            }

            $loyalty = $this->recordLoyalty($order, $customer, $redeem['points']);

            /*
             * والبيعة تصل إلى دفتر الأستاذ — لا إلى دفتر الصندوق وحده.
             *
             * وترحيلٌ يسقط لا يُسقط بيعةً وقعت: شجرةُ حساباتٍ عدّلها التاجر —
             * حسابٌ أُغلق أو صار له فرعٌ تحته — تجعل `Ledger::post` ترفض،
             * ولو رُبط بها البيع لتوقّف الصندوق عن العمل والزبون واقف. فيُقيَّد
             * الإخفاق في السجلّ باسمه ليُستدرَك بأمر finance:post-missing-sales.
             */
            try {
                Books::recordSale($order);
            } catch (\Throwable $e) {
                Activity::log('updated', 'تعذّر ترحيل قيد البيع '.$order->number.': '.$e->getMessage(), [
                    'subject_id' => $order->id, 'subject_type' => 'order',
                ]);
            }

            return ['order' => $order, 'loyalty' => $loyalty];
        });
<<<<<<< HEAD

        $order = $result['order'];

        /*
         * البيعة تُرحَّل إلى دفتر الأستاذ — بعد أن تتمّ لا داخلها.
         *
         * خارج معاملة البيع عمدًا: البيع سبع كتابات مترابطة تُنفَّذ كلّها أو
         * لا تُنفَّذ أيّها، والدفتر ليس منها. حسابٌ أغلقه التاجر في شجرته يجب
         * ألّا يمنع بيعةً من أن تتمّ والزبون واقفٌ عند الصندوق — لكنّه لا يمرّ
         * صامتًا: يُكتب في السجلّ برقم الفاتورة ويُستدرَك بأمر
         * `sales:post-ledger` (انظر Books::trySale).
         *
         * ولا تُرحَّل مرّتين: الطلب المكرَّر يعود من فحص `client_uuid` أعلاه
         * قبل أن يصل إلى هنا، ولو وصل لَردّه سؤالُ الدفتر عن قيدٍ حيّ.
         */
        \App\Support\Books::trySale($order, PosCashier::id());

        \App\Support\Activity::log('checkout', 'أتمّ بيعًا ' . $order->number . ' بقيمة ' . number_format($order->total, 3) . ' ر.ع', ['subject_id' => $order->id]);

        // البريد خارج المعاملة: بطؤه أو فشله يجب ألّا يُبقي القفل أو يُلغي بيعًا تمّ
        $this->notifyNewOrder($order);

        return response()->json([
            'ok' => true,
            'invoice' => $order->number,
            'total' => (float) $order->total,
            'points_earned' => $result['loyalty']['earned'],
            'points_redeemed' => $result['loyalty']['redeemed'],
        ]);
=======
>>>>>>> origin/main
    }

    /** تعليق الطلب */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.addon_id' => ['nullable', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.addons' => ['nullable', 'array'],
            'items.*.addons.*.addon_id' => ['required_with:items.*.addons', 'integer'],
            'items.*.addons.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string'],
            'items.*.price' => ['nullable', 'numeric'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string'],
            'total' => ['nullable', 'numeric'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // معلّق = بانتظار الاستكمال الآن · محفوظ = مسودّة للرجوع إليها لاحقًا
            'kind' => ['nullable', 'in:hold,save'],
        ]);
        $saved = ($data['kind'] ?? 'hold') === 'save';

        // المعلّق يُستكمل لاحقًا فيصير فاتورة، فأسعاره تُقرأ من القاعدة أيضًا.
        // ولا حارس مخزون هنا: التعليق لا يخصم شيئًا، والحارس يعمل عند الدفع.
        return DB::transaction(function () use ($data, $saved) {
            $lines = $this->priceItems($data['items']);
            // الإضافات جزءٌ من ثمن البند لا سطرٌ منفصل: «بوكيه + شوكولاتة»
            // بندٌ واحد يقرؤه الزبون على الفاتورة، ومجموعُه يدخل الحساب معه
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['price'] * $l['qty'] + ($l['addons_total'] ?? 0)), 3);
            $branch = $this->branch();

            $order = $this->createNumbered([
                'business_id' => $this->bid(),
                'customer_name' => $data['customer'] ?? 'عميل نقدي',
                'employee_name' => PosCashier::name(),
                // المعرّف لا الاسم وحده: لوحة أداء الموظفين تجمع المبيعات
                // على user_id، وكان لا يُكتب أصلًا فتظهر الأرقام أصفارًا
                'user_id' => PosCashier::id(),
                'branch_id' => $branch['id'],
                'branch' => $branch['name'],
                'status' => $saved ? 'محفوظ' : 'معلّق',
                'is_held' => true,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                // الكود وحده يُحفظ لا قيمة خصمه: الطلب قد يُستكمل غدًا وقد
                // يكون الكوبون انتهى أو نفدت مرات استخدامه، فيُعاد التحقق
                // منه وقت الدفع لا وقت التعليق.
                'coupon_code' => $data['coupon_code'] ?? null,
                'ordered_at' => now(),
            ], $saved ? 'SAVE-' : 'HOLD-');

            // حفظ الأصناف — بدونها لا يمكن استكمال الطلب لاحقًا. والمقاس
            // والإضافات معها: طلبٌ عُلّق بمقاسٍ وشوكولاتة يجب أن يعود كما
            // عُلّق، لا مجرّدًا من نصف اختيار الزبون
            foreach ($lines as $l) {
                $item = $order->items()->create([
                    'product_id' => $l['product']?->id,
                    'variant_id' => $l['variant']?->id,
                    'variant_name' => $l['variant']?->name,
                    'variant_sku' => $l['variant']?->sku,
                    'name' => $l['name'],
                    'price' => $l['price'],
                    // لقطة التكلفة — انظر التعليق في إتمام البيع
                    'cost' => (float) ($l['cost'] ?? 0),
                    'quantity' => $l['qty'],
                    'note' => $l['note'],
                    'total' => round($l['price'] * $l['qty'], 3),
                    'addons_total' => (float) ($l['addons_total'] ?? 0),
                ]);

                foreach ($l['addons'] ?? [] as $a) {
                    $item->addons()->create([
                        'addon_id' => $a['addon']->id,
                        'name' => $a['addon']->name,
                        'name_en' => $a['addon']->name_en,
                        'unit_price' => $a['price'],
                        'quantity' => $a['qty'],
                        'total' => $a['total'],
                        'cost' => $a['cost'],
                        /*
                         * لقطةُ ما أُخذ من الرفّ — لا علاقةٌ تُقرأ يوم الإلغاء.
                         *
                         * إضافةٌ كانت ثلاث ورداتٍ فصارت خمسًا تردّ خمسًا عن
                         * بيعةٍ أخذت ثلاثًا لو قُرئت اليوم — فيربح الرفّ
                         * وردتين لا وجود لهما.
                         */
                        'inventory_product_id' => $a['inventory_product_id'] ?? null,
                        'inventory_quantity' => ($a['inventory_product_id'] ?? null) ? $a['each'] : null,
                    ]);
                }
            }

            return response()->json(['ok' => true, 'number' => $order->number]);
        });
    }

    /** استكمال طلب معلّق/محفوظ: يعيد أصنافه إلى السلة */
    /**
     * السلّة المعلّقة يقيّدها فرعُها كما تقيّده قائمتُها.
     *
     * `Demo::heldOrders` تعرض سلال الفرع الحالي وحدها، والاستئنافُ والحذف
     * كانا يقرآن بالمعرّف على المتجر كلّه: منعٌ في الشاشة لا وجود له عند
     * الباب. والحذف أشدّ من الاطّلاع — القائمة تُخفي سلّة الفرع الآخر،
     * فصاحبُها لا يعلم أنها ذهبت: يقف الزبون في صلالة، والسلّةُ التي جُمعت
     * له محاها كاشيرٌ في مسقط بمعرّفٍ مُخمَّن.
     *
     * و«كل الفروع» يبقى بلا قيد كما في القائمة: هو عرضُ الشركة لا موضعُ بيع.
     */
    private function heldOrder(int $id): Order
    {
        return Order::where('business_id', $this->bid())->where('is_held', true)
            ->when(Demo::currentBranchId(), fn ($q) => $q->where('branch_id', Demo::currentBranchId()))
            ->with('items.addons')->findOrFail($id);
    }

    public function resume($id)
    {
        $order = $this->heldOrder((int) $id);

        session()->flash('resume_cart', [
            'id' => $order->id,
            'customer' => $order->customer_name,
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->product_id,
                // المقاس يعود بمعرّفه: السلّة تُسعَّر من جديد عند الدفع،
                // والاسم وحده لا يكفي الخادم ليعرف أيّ صفٍّ يقرأ
                'variant_id' => $i->variant_id,
                'variant_name' => $i->variant_name,
                'name' => $i->name,
                'price' => (float) $i->price,
                'qty' => (int) $i->quantity,
                'note' => $i->note ?? '',
                'addons' => $i->addons->map(fn ($a) => [
                    'addon_id' => $a->addon_id,
                    'name' => $a->name,
                    'price' => (float) $a->unit_price,
                    'qty' => (int) $a->quantity,
                ])->all(),
            ])->all(),
            // يعود الكود إلى السلة لتُعيد الواجهة تطبيقه، فيراه الكاشير
            // ويُحتسب عند الدفع. لا نُعيد قيمة الخصم — تُحسب من جديد.
            'coupon_code' => $order->coupon_code,
        ]);

        return redirect()->route('pos.index');
    }

    /** حذف طلب معلّق/محفوظ */
    public function discard($id)
    {
        $order = $this->heldOrder((int) $id);
        $number = $order->number;
        $order->items()->delete();
        $order->delete();

        return back()->with('toast', ['msg' => __('تم حذف الطلب :number', ['number' => $number]), 'type' => 'warning']);
    }

    /** إضافة عميل سريع من نقطة البيع */
    /**
     * إضافة مناسبةٍ للمتجر من نافذة الدفع.
     *
     * لا شاشة إعداداتٍ لها: من لم يجد مناسبته يجدها وهو واقفٌ أمام الزبون،
     * لا بعد أن يخرج من الصندوق ويفتح الإعدادات ويعود. والزبون ينتظر.
     */
    public function storeOccasion(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:'.FlowerOrder::CUSTOM_LABEL_MAX],
        ], [
            'label.required' => __('اكتب اسم المناسبة.'),
            'label.min' => __('اسم المناسبة قصير جدًّا.'),
        ]);

        try {
            $added = FlowerOrder::addOccasion($data['label'], $this->bid());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        Activity::log('created', 'أضاف مناسبة: '.$added['value']);

        return response()->json(['ok' => true] + $added);
    }

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            // لا name_en: localizeName أدناه يشتقّه من الاسم المُدخَل
            'name' => ['required', 'string', 'max:255'],
            'phone' => Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
        ]);
        $data['business_id'] = $this->bid();
        $data = Customers::localizeName($data);
        $customer = Customer::create($data);
        Activity::log('created', 'أضاف عميلًا من نقطة البيع: '.$data['name']);

        // طلب AJAX من السلة: نُعيد العميل ليُحدَّد تلقائيًا للطلب الجاري بلا إعادة تحميل
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'label' => (app()->getLocale() === 'en' && filled($customer->name_en)) ? $customer->name_en : $customer->name,
                    'phone' => $customer->phone ?? '',
                ],
            ]);
        }

        return back()->with('toast', ['msg' => __('تم إضافة العميل'), 'type' => 'success']);
    }

    /**
     * نقاط الولاء للعميل المسجّل: تستبدل النقاط المطلوبة (خصم) ثم تمنح نقاط الشراء.
     * تحترم إعداد التفعيل والمعدّل، وتربط الطلب بالعميل. تُرجِع ['earned'=>x, 'redeemed'=>y].
     */
    /**
     * عميل النشاط — بالمعرّف، ثم بالهاتف، ثم بالاسم إن كان فريدًا.
     *
     * كان يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود. والاسم ليس مفتاحًا: متجرٌ
     * فيه ثلاثة باسم «محمد» كان يمنح نقاط شراء كلٍّ منهم لأوّلهم في الجدول،
     * ويخصم رصيده هو عند استبدال غيره. النقاط مالٌ فعلي، فالخلط فيها خسارة
     * لصاحبها وهبةٌ لسواه — ولا يظهر شيء من ذلك في أي شاشة.
     *
     * والهاتف هو ما يعرّف الشخص عند التاجر فعلًا، فهو المرجع الثاني.
     *
     * وحين يبقى الاسم وحده ويطابق أكثر من واحد: لا يُربط أحد. بيعةٌ بلا نقاط
     * يشتكي منها العميل فتُصحَّح، ونقاطٌ تذهب لغير صاحبها لا يلحظها أحد.
     */
    private function customerFor(?string $name, ?int $id = null, ?string $phone = null): ?Customer
    {
        $scope = fn () => Customer::where('business_id', $this->bid());

        if ($id) {
            return $scope()->find($id);
        }

        if (filled($phone)) {
            $found = $scope()->where('phone', $phone)->first();
            if ($found) {
                return $found;
            }
        }

        if (empty($name) || $name === 'عميل نقدي') {
            return null;
        }

        // الكاشير الإنجليزي يرى name_en ويرسله، فنطابق العمودين معًا:
        // المطابقة بالعربي وحده كانت تُسقط ربط العميل ونقاط ولائه.
        $matches = $scope()
            ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $name))
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * هل الولاء عاملٌ في هذا المتجر الآن؟ — مفتاحُ التاجر وباقتُه معًا.
     *
     * والباقة تُسأل هنا لا في الشاشة وحدها: النقاط تُمنح عند إتمام البيعة
     * لا عند فتح شاشة الولاء، فقفلٌ في اللوحة يترك الرصيد ينمو لمن لم يشترِ
     * البرنامج — ثمّ يُستبدَل. والنقاط مالٌ لا عدّاد.
     */
    private function loyaltyOn(): bool
    {
        if ((string) $this->setting('loyalty_enabled', '1') === '0') {
            return false;
        }

        return PlanFeatures::allows(auth()->user()?->business, 'loyalty');
    }

    /**
     * كم نقطة تُستبدَل فعلًا وكم تساوي خصمًا — يُحتسب قبل بناء الفاتورة.
     *
     * يطابق سقف usePosCart: نسبة من المجموع الفرعي، ولا يتجاوز المتبقّي بعد الكوبون،
     * ولا رصيد العميل. النقاط مالٌ فعلي، فلا تُؤخذ قيمة الخصم من العميل.
     */
    private function resolveRedemption(?Customer $customer, float $subtotal, float $couponDiscount, int $requested): array
    {
        $none = ['points' => 0, 'discount' => 0.0];

        if (! $customer || $requested <= 0 || ! $this->loyaltyOn()) {
            return $none;
        }

        // الحد الأدنى لبدء الاستبدال: تحته تتراكم النقاط فقط
        $redeemMin = max(0, (int) $this->setting('loyalty_redeem_min', 100));
        if ((int) $customer->points < $redeemMin) {
            return $none;
        }

        $maxPct = max(0, min(100, (int) $this->setting('loyalty_redeem_max_pct', 50)));
        $cap = min($subtotal * $maxPct / 100, max(0.0, $subtotal - $couponDiscount));

        $points = min($requested, (int) $customer->points, (int) floor($cap * self::POINTS_PER_UNIT));
        if ($points <= 0) {
            return $none;
        }

        return ['points' => $points, 'discount' => $points / self::POINTS_PER_UNIT];
    }

    /** يقيّد الاستبدال والاكتساب على العميل بعد اكتمال الفاتورة */
    private function recordLoyalty(Order $order, ?Customer $customer, int $redeemPoints): array
    {
        if (! $customer || ! $this->loyaltyOn()) {
            return ['earned' => 0, 'redeemed' => 0];
        }

        if ($redeemPoints > 0) {
            /*
             * الرصيد يُخصم بشرطه لا بطرحٍ أعمى.
             *
             * `resolveRedemption` تقرأ الرصيد ثمّ يُخصم هنا، وبين القراءة
             * والخصم بيعةٌ أخرى للزبون نفسه على صندوقٍ آخر: كلتاهما ترى خمسمئة
             * نقطة، وكلتاهما تطرح خمسمئة — فيصير رصيده سالبًا، ويكون قد اشترى
             * بخصمٍ لم يدفع ثمنه. والنقاط مالٌ لا عدّاد.
             *
             * فالشرط في جملة التحديث نفسها: من سبق أخذ، ومن تأخّر يُردّ بلا
             * فاتورةٍ ناقصة الثمن.
             */
            $taken = Customer::whereKey($customer->id)
                ->where('points', '>=', $redeemPoints)
                ->update(['points' => \DB::raw('points - '.$redeemPoints), 'updated_at' => now()]);

            if (! $taken) {
                throw ValidationException::withMessages([
                    'redeem_points' => __('تغيّر رصيد نقاط العميل — أعد احتساب الاستبدال.'),
                ]);
            }

            $customer->refresh();
            PointTransaction::record($customer, 'redeem', $redeemPoints, (int) $customer->points, $order->id, 'استبدال عند البيع — فاتورة '.$order->number);
        }

        // اكتساب نقاط الشراء (على الإجمالي بعد الخصم)
        $earned = 0;
        $rate = (float) $this->setting('loyalty_earn_rate', 5);
        if ($rate > 0) {
            $earned = (int) floor((float) $order->total * $rate);
            if ($earned > 0) {
                $customer->increment('points', $earned);
                PointTransaction::record($customer, 'earn', $earned, (int) $customer->points, $order->id, 'اكتساب من الشراء — فاتورة '.$order->number);
            }
        }

        $order->points_earned = $earned;
        $order->redeemed_points = $redeemPoints;
        $order->save();

        return ['earned' => $earned, 'redeemed' => $redeemPoints];
    }

    /** إشعار صاحب المتجر بطلب جديد عبر البريد (غير مُعطِّل عند الفشل، ويحترم إعداد التفعيل) */
    private function notifyNewOrder(Order $order): void
    {
        $business = Business::find($this->bid());
        if (! $business || ! $business->email) {
            return;
        }
        $enabled = Setting::where('business_id', $this->bid())->where('key', 'notify_new_order')->value('value');
        if ($enabled === '0') {
            return;
        }
        try {
            Mail::to($business->email)->send(new NewOrderMail($order));
        } catch (\Throwable $e) {
            report($e); // لا نُفشل عملية البيع بسبب البريد
        }
    }
}
