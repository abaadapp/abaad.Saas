<?php

namespace App\Support;

use App\Models\OrderItemComponent;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * الطلبُ المخصَّص — باقةٌ تُركَّب على الطاولة، ومكوّناتُها تُعرف.
 *
 * ═══ ولمَ لا يكفي أن يُكتب السعرُ وحده ═══
 *
 * «ورد بعشرين ريالًا» تقول كم يدفع الزبون، ولا تقول كم وردةً خرجت من
 * الدلو. والرفُّ لا يُخصم بالمال — يُخصم بالعدد. فالقيمةُ للفاتورة،
 * والمكوّناتُ للمخزون، ولا تُستنبط إحداهما من الأخرى.
 *
 * ═══ وضعان للتسعير، ومصدرٌ واحد للخصم ═══
 *
 *   `VALUE`  — قيمةُ الورد + أثمانُ الإضافات. الزبون يدفع مجموعَهما.
 *   `BUDGET` — ميزانيةٌ نهائيّة. الزبون يدفع ما قاله، والإضافاتُ لا تزيده.
 *
 * والفرقُ بينهما في **السعر وحده**. أمّا ما يُخصم من الرفّ وما يُحسب تكلفةً
 * فواحدٌ في الوضعين: من أخذ ثماني ورداتٍ أخذها سواءٌ قال «بعشرين» أو
 * «بثلاثين للطلب كلّه».
 *
 * ═══ وما هنا وما ليس هنا ═══
 *
 * هنا: قراءةُ ما أُرسل، والتحقّقُ منه، وحسابُ التكلفة، وقولُ ما يُستهلك.
 * وليس هنا: الخصمُ نفسُه — `StockLedger` يفعله؛ ولا فحصُ التوفّر —
 * `PosController::assertStock` يفعله بالدالّة التي تقرأ ما تقرؤه هذه.
 *
 * ولا تُقرأ من هذا الملفّ قيمةُ سعرٍ ولا تكلفةٍ أرسلها المتصفّح: الأسعارُ
 * والتكاليفُ تُقرأ من صفوف المتجر، كما تفعل `PosController::priceItems`
 * لكلّ بندٍ عاديّ.
 */
class CustomArrangement
{
    /** قيمةُ الورد + الإضافات — الإضافةُ تزيد ما يدفعه الزبون */
    public const MODE_VALUE = 'value';

    /** ميزانيةٌ نهائيّة — الإضافةُ لا تزيد ما يدفعه الزبون */
    public const MODE_BUDGET = 'budget';

    public const MODES = [self::MODE_VALUE, self::MODE_BUDGET];

    /** أكثرُ ما يُقبل من موادّ في طلبٍ واحد — حدٌّ يمنع حمولةً مصنوعة */
    public const MAX_COMPONENTS = 60;

    /** أقصى كميّةٍ لمادّةٍ واحدة — ولا يحرس مخزونًا، إنّما يردّ رقمًا لا معنى له */
    public const MAX_QUANTITY = 9999;

    /** الاسمُ الذي يُقرأ على الفاتورة وفي التقارير */
    public static function label(): string
    {
        return __('تنسيق ورد مخصص');
    }

    /**
     * قواعدُ ما يصل من الصندوق.
     *
     * والسعرُ يُقرأ من الطلب هنا — خلافًا لكلّ بندٍ آخر — لأنّه **لا صنفَ
     * له يُقرأ منه**. وهذا هو معنى «مخصَّص». ولذلك يُحرَس بصلاحيةٍ في
     * `PosController`، لا يُترك مفتوحًا لأنّ الشاشة ترسله.
     */
    public static function rules(string $prefix): array
    {
        $p = $prefix.'.';

        /*
         * و«مطلوبٌ **مع**» لا «مطلوب».
         *
         * القاعدةُ تُكتب على `items.*.custom.mode`، والنجمةُ تعني كلَّ بند.
         * فـ`required` المجرّدة كانت تُطالب **كلّ** بندٍ في السلّة بوضعٍ
         * وسعرٍ مخصَّصين — أي أنّ بيعةَ باقةٍ عاديّة تُردّ بـ٤٢٢. أمسكها
         * ٤٨ اختبارًا قائمًا قبل أن تصل أحدًا.
         *
         * والشرطُ على وجود `custom` نفسِه: من أرسله أرسل معه ما يُعرّفه،
         * ومن لم يرسله لا يُسأل.
         */
        $withCustom = 'required_with:'.$prefix;

        return [
            $prefix => ['nullable', 'array'],
            $p.'mode' => [$withCustom, 'string', 'in:'.implode(',', self::MODES)],
            // السعرُ الذي يدفعه الزبون — موجبٌ دائمًا
            $p.'price' => [$withCustom, 'numeric', 'min:0.001'],
            // قيمةُ الورد وحدها — تُعرض وتُحفظ، ولا تُخصم منها بضاعة
            $p.'flower_value' => ['nullable', 'numeric', 'min:0'],
            $p.'colors' => ['nullable', 'array', 'max:20'],
            $p.'colors.*' => ['string', 'max:40'],
            $p.'packaging_label' => ['nullable', 'string', 'max:120'],
            $p.'florist_notes' => ['nullable', 'string', 'max:1000'],
            $p.'components' => ['nullable', 'array', 'max:'.self::MAX_COMPONENTS],
            $p.'components.*.product_id' => ['required_with:'.$p.'components', 'integer'],
            $p.'components.*.quantity' => ['required_with:'.$p.'components', 'numeric', 'min:0.001', 'max:'.self::MAX_QUANTITY],
            $p.'components.*.kind' => ['nullable', 'string', 'in:'.implode(',', OrderItemComponent::KINDS)],
        ];
    }

    /**
     * يقرأ المكوّنات من الطلب ويردّها لقطاتٍ جاهزةً للكتابة.
     *
     * ═══ والصنفُ يُقرأ من متجرِ البائع لا من الطلب ═══
     *
     * معرّفٌ يصل من المتصفّح لا يُوثق به: قد يشير إلى صنفِ متجرٍ آخر. فالبحثُ
     * محصورٌ بـ`business_id`، وما لم يُوجد فيه يُردّ باسمه — لا يُتخطّى
     * صامتًا. وتخطّيه كان يعني بيعًا يخصم أقلَّ ممّا أُخذ.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>> لقطاتٌ: product · name · sku · kind · quantity · unit_cost · total_cost
     *
     * @throws ValidationException
     */
    public static function components(int $businessId, array $rows, string $field = 'custom.components'): array
    {
        if (! $rows) {
            return [];
        }

        $ids = collect($rows)->pluck('product_id')->filter()->map(fn ($i) => (int) $i)->unique()->all();

        /** @var Collection<int, Product> $products */
        $products = Product::where('business_id', $businessId)
            ->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        $errors = [];

        foreach ($rows as $idx => $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));

            if (! $product) {
                // صنفُ متجرٍ آخر، أو صنفٌ حُذف — يُقال ولا يُبتلع
                $errors["{$field}.{$idx}.product_id"] = __('صنف غير موجود في هذا المتجر.');

                continue;
            }

            if (! $product->active) {
                $errors["{$field}.{$idx}.product_id"] = __('«:name» موقوف عن البيع.', ['name' => $product->name]);

                continue;
            }

            $qty = round((float) $row['quantity'], 3);
            // التكلفةُ من صفّ الصنف لا من الطلب — ولو أرسلها المتصفّح أُهملت
            $unit = round((float) $product->cost, 3);

            $out[] = [
                'product' => $product,
                'name' => $product->name,
                'sku' => $product->sku,
                'kind' => in_array($row['kind'] ?? null, OrderItemComponent::KINDS, true)
                    ? $row['kind']
                    : OrderItemComponent::FLOWER,
                'quantity' => $qty,
                'unit_cost' => $unit,
                'total_cost' => round($unit * $qty, 3),
                /*
                 * وردٌ قُصّ ورُكّب لا يُردّ إلى الدلو، وتغليفٌ لم يُفتح يُردّ.
                 *
                 * والسياسةُ تُكتب لحظةَ البيع لا تُخمَّن يوم الإلغاء: نوعُ
                 * المادّة هو ما يقرّرها، وهو محفوظٌ في الصفّ.
                 */
                'restockable' => ($row['kind'] ?? null) === OrderItemComponent::PACKAGING,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * ما تأكله موادُّ الطلب من الرفّ — بالكسر قبل الرفع.
     *
     * تُنادى من فحصِ التوفّر ومن الخصم معًا. ولو حُسبت في موضعين لجاز أن
     * يفحص أحدهما خمسةَ عشر ويخصم الآخر ستّةَ عشر — فيُقبل بيعٌ لا رصيد له.
     * وهي القاعدةُ نفسُها التي تحرس `PosController::addonConsumption`.
     *
     * ═══ والموادُّ لوحدةٍ واحدة، فتُضرب في الكميّة ═══
     *
     * اللقطةُ تصف باقةً واحدة: «ثماني ورداتٍ وكيس». فإن ضغط الكاشير «+»
     * في السلّة صار الثمنُ ثمنين — ووجب أن يصير الوردُ ستّةَ عشر. ولولا
     * الضربُ لَباع باقتين وخصم واحدة، ويظلّ الرفُّ يقول ما ليس فيه حتى
     * الجرد. وهو الضربُ نفسه الذي تفعله `Recipe::consumptionFor` بكميّة
     * البند.
     *
     * و`$units` لا قيمةَ افتراضية له: مناداةٌ تنساه تُنقص الرفّ صامتةً،
     * والتوقيعُ الذي يُجبر على ذكره لا يُنسى.
     *
     * @param  array<int, array<string, mixed>>  $components  لقطاتٌ من `components()`
     * @param  int  $units  كميّةُ البند — عددُ الباقات المتماثلة
     * @return array<int, float> [معرّف الصنف => الكمية العشرية]
     */
    public static function consumption(array $components, int $units): array
    {
        if ($units < 1) {
            return [];
        }

        $out = [];

        foreach ($components as $c) {
            $pid = (int) ($c['product']?->id ?? $c['product_id'] ?? 0);
            $qty = (float) ($c['quantity'] ?? 0);

            if ($pid > 0 && $qty > 0) {
                $out[$pid] = ($out[$pid] ?? 0.0) + $qty * $units;
            }
        }

        return $out;
    }

    /**
     * تكلفةُ الموادّ — مجموعُ لقطاتها.
     *
     * ولا تُقرأ من الطلب: الموظّفُ يرى الرقم في الشاشة ولا يكتبه، والخادمُ
     * يحسبه من `products.cost` كما يحسب تكلفةَ كلّ بندٍ ذي وصفة.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public static function materialCost(array $components): float
    {
        return round(array_sum(array_column($components, 'total_cost')), 3);
    }

    /**
     * وصفُ الطلب كما يُحفظ في `order_items.custom_details`.
     *
     * ما لا يُخصم من الرفّ: الوضعُ، وقيمةُ الورد، وتفضيلاتُ ألوانٍ لا صنفَ
     * لها، واسمُ التغليف كما اختاره الموظّف، وملاحظاتُ المنسّق.
     *
     * وملاحظاتُ المنسّق **داخليّة**: تُقرأ في لوحة التجهيز ولا تُطبع على
     * فاتورة العميل — انظر `DocumentPaper`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function details(array $data, float $materialCost): array
    {
        return array_filter([
            'mode' => $data['mode'],
            'flower_value' => isset($data['flower_value']) ? round((float) $data['flower_value'], 3) : null,
            'colors' => array_values(array_filter(array_map(
                fn ($c) => trim((string) $c),
                $data['colors'] ?? [],
            ), fn ($c) => $c !== '')),
            'packaging_label' => filled($data['packaging_label'] ?? null) ? trim((string) $data['packaging_label']) : null,
            'florist_notes' => filled($data['florist_notes'] ?? null) ? trim((string) $data['florist_notes']) : null,
            /*
             * وتكلفةُ الموادّ تُحفظ هنا **وفي `order_items.cost` معًا** — ولا
             * تناقض: العمودُ هو ما تقرؤه التقاريرُ والربحيّة كما تقرؤه لكلّ
             * بند، وهذه لقطةٌ تُعرض في بطاقة الطلب بجانب موادّها.
             */
            'material_cost' => $materialCost,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
