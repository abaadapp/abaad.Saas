<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * بنودُ البيعة — تسعيرُها ووعاءُ ضريبتها ورفُّها، لكلّ بابٍ يبيع.
 *
 * ═══ لمَ خرجت من متحكّم الصندوق ═══
 *
 * كانت هذه الدوالُّ خاصّةً في `PosController`، والصندوقُ البابَ الوحيد الذي
 * يبيع. فلمّا صار للموقع سلّةٌ وإتمامُ طلب (انظر `Store\WebCheckout`) صار
 * السؤالُ: أتُكتب قاعدةُ السعر والمقاس والوصفة والضريبة والرفّ مرّةً ثانية؟
 * وقاعدتان لرقمٍ واحد تفترقان يومًا — فتُقرأ الضريبةُ على الفاتورة غيرَ ما
 * قُرئت على الموقع عن البيعة نفسها.
 *
 * فنُقلت **بالحرف** — لا سطرَ تغيّر فيها — وصار الصندوقُ ينادي عليها كما
 * كان ينادي على نفسه. وكلُّ شرحٍ فيها كُتب يومَ كُتبت هناك، ويبقى صادقًا.
 */
final class SaleLines
{
    public function __construct(private readonly int $businessId) {}

    private function bid(): int
    {
        return $this->businessId;
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
    public function vatRate(): float
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
    public function taxFor(array $lines, float $subtotal, float $discount): float
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

    public function setting(string $key, $default = null)
    {
        return Setting::where('business_id', $this->bid())->where('key', $key)->value('value') ?? $default;
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
    public function priceItems(array $items, bool $lock = false): array
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

            /*
             * ═══ الطلبُ المخصَّص يسبق الصنف: لا معرّفَ له يُبحث عنه ═══
             *
             * وسعرُه يُقرأ من الطلب — وهو الوحيد في هذه الدالّة. لأنّه لا
             * صنفَ له يُقرأ منه، وذاك معنى «مخصَّص».
             *
             * وحارسُه صلاحيةُ «نقطة البيع» نفسُها التي تحرس كلّ بيعةٍ في هذا
             * المسار — ولا صلاحيةَ «تعديل سعر» في النظام تُحترم أو تُتجاوَز:
             * الصندوقُ لا يسمح بتغيير سعرٍ أصلًا، فهذه قدرةٌ تُضاف لا قيدٌ
             * يُلتَفّ عليه. ومن لم يُمنح الصندوقَ لا يبلغ هذا السطر.
             *
             * وتكلفتُه ليست كذلك: تُحسب من `products.cost` لموادّه، ولو
             * أرسلها المتصفّح أُهملت — كما تُهمل تكلفةُ أيّ بندٍ آخر.
             */
            if (! empty($i['custom'])) {
                $custom = $i['custom'];

                /*
                 * ═══ البابُ يُقفل من الخادم لا من الشاشة ═══
                 *
                 * إخفاءُ زرٍّ لا يمنع طلبًا: من أطفأ الميزةَ أطفأها لسببٍ —
                 * كاشيرٌ يكتب أسعارًا بيده — ومن يعرف شكلَ الحمولة يرسلها.
                 * فالفحصُ هنا قبل أن يُقرأ سعرٌ أو تُحجز بضاعة.
                 */
                if (! CustomArrangement::enabled($bid)) {
                    $errors["items.$idx.custom"] = __('الطلبات المخصصة غير مفعّلة في هذا المتجر.');

                    continue;
                }

                $template = CustomArrangement::template($bid, $custom['template_id'] ?? 0, "items.$idx.custom.template_id");

                /*
                 * والوضعُ يُفحص بالقالب لا بقائمة النظام.
                 *
                 * قالبٌ يسمح بالميزانية وحدها يُرسَل إليه «قيمة أساسية»
                 * فيُقبل لولا هذا: فيخرج طلبٌ بسعرٍ يزيد بالإضافات من قالبٍ
                 * كتب صاحبُه أنّ سعره نهائيّ.
                 */
                if (! $template->allowsMode($custom['mode'] ?? null)) {
                    $errors["items.$idx.custom.mode"] = __('طريقة التسعير غير مسموحة في هذا القالب.');

                    continue;
                }

                /*
                 * ═══ وقالبٌ لا يقبل موادَّ يردّها ولا يبتلعها ═══
                 *
                 * كانت تُطرح صامتةً: يُباع الطلبُ بسعره، ولا يَنقص الرفُّ
                 * شيئًا، وتُكتب تكلفتُه صفرًا — فيخرج الوردُ من الدلو ويبقى
                 * في الدفتر، ويُقرأ ربحُ البيعة كاملًا بلا تكلفة. وهو
                 * بالضبط العطبُ الذي وُجدت هذه الميزةُ كلُّها لمنعه.
                 *
                 * ويقع حين يُطفئ صاحبُ النشاط «الموادّ» في قالبه وفي
                 * الصناديق سلالٌ عُلّقت به قبل الإطفاء — تُستأنف بموادّها،
                 * فتُدفع ناقصةَ ما أُخذ ولا أحدَ يعلم.
                 *
                 * والردُّ يُقال للكاشير فيُعيد بناءَ السلّة، ولا يُقال
                 * للرفّ بعد شهرٍ في الجرد.
                 */
                if (! $template->allow_components && ! empty($custom['components'])) {
                    $errors["items.$idx.custom.components"] = __('هذا القالب لا يقبل موادّ — أعد بناء الطلب.');

                    continue;
                }

                $components = CustomArrangement::components($bid, $custom['components'] ?? [], "items.$idx.custom.components", $template);
                $materialCost = CustomArrangement::materialCost($components);
                $fields = CustomArrangement::fieldValues($template, $custom['fields'] ?? [], "items.$idx.custom.fields");

                $lines[] = [
                    'product' => null,
                    'variant' => null,
                    // لقطةُ اسم القالب — لا يُقرأ الحيُّ يوم الطباعة
                    'name' => CustomArrangement::label($template),
                    'price' => round((float) $custom['price'], 3),
                    'list_price' => round((float) $custom['price'], 3),
                    'cost' => $materialCost,
                    'has_recipe' => false,
                    'recipe' => collect(),
                    'qty' => $qty,
                    'note' => $i['note'] ?? null,
                    /*
                     * وإضافاتُه لا تُسأل عن منتجٍ يأذن بها.
                     *
                     * `pickAddons` تسأل `ProductAddons::map()` أيَّ إضافةٍ
                     * يسمح بها هذا المنتج — ولا منتجَ هنا. فالمسموحُ هو ما
                     * مداه «مع الجميع» (`Addon::SCOPE_ALL`)، وهو المدى الذي
                     * تُعرض به الإضافةُ العامّة على كلّ صنفٍ في الصندوق.
                     */
                    'addons' => $template->allow_addons
                        ? $this->freeAddons($i['addons'] ?? [], $addons, $idx)
                        : [],
                    'addons_total' => 0.0,
                    'custom' => $custom,
                    'template' => $template,
                    'fields' => $fields,
                    'components' => $components,
                ];

                /*
                 * و«الميزانية النهائيّة» لا تزيد بالإضافات.
                 *
                 * الزبون قال «ثلاثون للطلب كلّه»، فالكرتُ والشريطةُ داخلَها
                 * لا فوقها: يُخصمان من الرفّ ويدخلان التكلفة، ولا يُضافان
                 * إلى ما يدفع. وفي وضع «قيمة الورد + الإضافات» يُضافان.
                 */
                $last = array_key_last($lines);
                if ($custom['mode'] === CustomArrangement::MODE_VALUE) {
                    $lines[$last]['addons_total'] = round(collect($lines[$last]['addons'])->sum('total'), 3);
                }

                continue;
            }

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
                    // كما أُرسل — لا يُصدَّق هنا؛ `SeasonSales::attribute` تقرّر
                    'season_id' => $i['season_id'] ?? null,
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
    public function pickAddons(Product $product, array $requested, $addons, array $addonMap): array
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
     * إضافاتُ الطلب المخصَّص — ما مداه «مع الجميع» وحدَه.
     *
     * ═══ ولمَ لا تُنادى `pickAddons` ═══
     *
     * تلك تسأل `ProductAddons::allows($product, …)`: أيَّ إضافةٍ يأذن بها
     * **هذا المنتج**. والطلبُ المخصَّص بلا منتج، فلا مالكَ يُسأل.
     *
     * والجوابُ ليس «كلُّ إضافة»: إضافةٌ مداها `SCOPE_SELECTED` اختِيرت
     * لمنتجاتٍ بعينها، وإضافةٌ مملوكةٌ لمنتج (`product_id`) لا تُعرض إلا
     * معه. فالمسموحُ هنا هو `SCOPE_ALL` — وهو المدى الذي تُعرض به الإضافةُ
     * على كلّ صنفٍ في الصندوق اليوم.
     *
     * وما عدا الإذن فالقاعدةُ واحدة: السعرُ من القاعدة، والاستهلاكُ من
     * `AddonStock::each`، والتكلفةُ من الصنف. ولا يُقرأ رقمٌ من الطلب.
     *
     * @return array<int, array<string, mixed>>
     */
    public function freeAddons(array $requested, $addons, int $idx): array
    {
        $chosen = [];

        foreach ($requested as $r) {
            $id = (int) ($r['addon_id'] ?? $r['id'] ?? 0);
            $addon = $id ? $addons->firstWhere('id', $id) : null;

            if (! $addon || ! $addon->active || $addon->scopeName() !== Addon::SCOPE_ALL) {
                throw ValidationException::withMessages([
                    "items.$idx.addons" => __('إضافة غير متاحة مع هذا الصنف.'),
                ]);
            }

            $qty = max(1, (int) ($r['qty'] ?? 1));
            $price = round((float) $addon->price, 3);
            $each = AddonStock::each($addon);

            $chosen[] = [
                'addon' => $addon,
                'qty' => $qty,
                'price' => $price,
                'total' => round($price * $qty, 3),
                'inventory_product_id' => $addon->inventory_product_id ? (int) $addon->inventory_product_id : null,
                'each' => $each,
                'cost' => $addon->inventory_product_id
                    ? round((float) (Product::find($addon->inventory_product_id)?->cost ?? 0) * $each, 3)
                    : null,
            ];
        }

        return $chosen;
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
    public function demand(array $lines): array
    {
        $exact = [];
        $whole = [];

        // الإضافات تُجمع على حدة ثم تُرفع مرّةً واحدة — بالقاعدة نفسها التي
        // يُرفع بها استهلاك الوصفة، وبنفس الفصل الذي يفصل حركتيهما في الدفتر
        $addonExact = self::addonConsumption($lines);

        foreach ($lines as $l) {

            /*
             * وموادُّ الطلب المخصَّص تدخل الوعاءَ الكسريّ نفسَه.
             *
             * قبل الرفع لا بعده: وردٌ أبيضُ في طلبٍ مخصَّص وفي باقةٍ عاديّةٍ
             * في السلّة نفسِها يُجمعان ثمّ يُرفعان مرّةً — ولو رُفع كلٌّ
             * وحدَه لَنقص الرفُّ وردةً لم تخرج منه.
             */
            foreach (CustomArrangement::consumption($l['components'] ?? [], (int) $l['qty']) as $pid => $q) {
                $exact[$pid] = ($exact[$pid] ?? 0.0) + $q;
            }

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
    public static function addonConsumption(array $lines): array
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
    public function assertStock(array $lines, ?int $branchId = null): void
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

        /*
         * بقفل — والمكوّنات معها لا الأصناف المبيعة وحدها.
         *
         * `priceItems` يقفل ما في السلّة، لكنّ مكوّنَ وصفةٍ أو مادّةَ تنسيقٍ أو
         * إضافةً مخزنيّة لم تُوزَّع بعدُ على الفروع لا صفَّ لها في
         * `branch_stocks` يُقفل، فيُقرأ إجماليُّها هنا كما هو. جهازان يبيعان
         * آخرَ قطعةٍ منها في اللحظة نفسها يمرّان كلاهما، ثمّ يخصم كلاهما —
         * فيصير الرفُّ ‎−١‎. القفلُ يُوقف الثاني حتى يكتب الأوّل، فيقرأ صفرًا.
         * وبترتيب المعرّف كما في كلّ قفلٍ هنا.
         */
        $byId = Product::where('business_id', $this->bid())->whereIn('id', array_keys($needed))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // الحكم على رصيد الفرع الذي سيُخصم منه. الحكم على مجموع الشركة كان
        // يُجيز بيع خمس قطع من صلالة ورصيدها صفر لأن في مسقط عشرًا.
        $available = Stock::availabilityResolver(
            $this->bid(), $branchId, array_keys($needed), lock: true,
        );

        $short = [];
        foreach ($needed as $pid => $want) {
            $product = $byId->get($pid);
            /* وما لا يُعدّ على رفٍّ لا ينفد */
            if (! $product || ! $product->tracksStock()) {
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
}
