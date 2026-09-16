<?php

namespace App\Support;

use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * حركةُ مخزونٍ واحدة — أربع كتاباتٍ تقع معًا أو لا تقع.
 *
 * كلّ خصمٍ في هذا النظام يكرّر الرقصة نفسها: يضمن توزيع المنتج على الفروع،
 * ثم يغيّر الكمية الإجمالية، ثم رصيد الفرع، ثم يقيّد الحركة للتدقيق. وكانت
 * مكتوبةً بيدها في ستّة مواضع — ومن نسي سطرًا منها في موضعٍ أخرج الجدولين
 * عن التوازن بلا أثرٍ يُقرأ.
 *
 * والوصفة ضاعفت الحاجة: بيعُ باقةٍ واحدة صار أربع حركاتٍ لا واحدة.
 */
class StockLedger
{
    /**
     * يطبّق فروقًا على أصنافٍ عدّة — السالب خصمٌ والموجب ردّ.
     *
     * @param  array<int, int>  $deltas  [معرّف المنتج => الفرق]
     */
    public static function move(
        int $businessId,
        ?int $branchId,
        array $deltas,
        string $type,
        ?string $employeeName = null,
        ?string $note = null,
    ): void {
        $deltas = array_filter($deltas, fn ($d) => (int) $d !== 0);
        if (! $deltas) {
            return;
        }

        /*
         * والحارس يُنادى — لا يُكتب ويُترك.
         *
         * `assertInTransaction` كانت معرَّفةً ولا يستدعيها أحد: تعليقٌ يقول
         * «لا يُستدعى إلا داخل معاملة» وسطرٌ لا يفرضه. وحركةُ مخزونٍ خارج
         * معاملة تعني كتابتين من أربع تنجحان واثنتين تسقطان — رصيدُ فرعٍ
         * نقص وإجماليٌّ لم ينقص، أو حركةٌ مقيَّدة لبضاعةٍ لم تتحرّك.
         */
        self::assertInTransaction();

        // ترتيبٌ ثابت بالمعرّف: قفل الصفوف بترتيبٍ مختلف بين عمليتين
        // متزامنتين يُنتج تعارضًا دائريًّا يوقف الاثنتين
        ksort($deltas);

        $products = Product::where('business_id', $businessId)
            ->whereIn('id', array_keys($deltas))
            ->get()->keyBy('id');

        foreach ($deltas as $productId => $delta) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            // التوزيع أوّلًا ثم الخصم — وإلّا بدأ صفّ الفرع من صفر فصار سالبًا
            BranchStock::ensureAllocated($businessId, (int) $product->id, (int) $product->quantity);
            $product->increment('quantity', $delta);
            BranchStock::adjust($businessId, $branchId, (int) $product->id, (int) $delta);

            InventoryMovement::create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'type' => $type,
                'quantity' => ($delta > 0 ? '+' : '').$delta,
                'employee_name' => $employeeName,
                'note' => $note,
            ]);
        }
    }

    /**
     * أثرُ المكوّنات المستهلَكة في وصفةٍ خلال مدّة — بالكمية والتكلفة.
     *
     * يقرأ حركات المخزون من نوع «استهلاك وصفة» لا بنودَ الطلبات: بيعُ باقةٍ
     * ليس بيعَ وردة، وحسابُ الاستهلاك من كميّة الباقات يخلط وحداتٍ لا
     * تُخلط. وهذا المقام هو المقارن الصحيح لمقدار الهالك.
     *
     * @return array<int, float> [معرّف المنتج => الكمية المستهلَكة]
     */
    public static function consumedBetween(int $businessId, ?int $branchId, string $from, string $to): array
    {
        return InventoryMovement::where('business_id', $businessId)
            ->where('type', self::RECIPE)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from, $to])
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($rows) => round($rows->sum(fn ($r) => abs((float) $r->quantity)), 3))
            ->all();
    }

    /** رصيدٌ كُتب مع إنشاء الصنف — لا شحنةَ وردٍ دخلت، بل رقمٌ بدأ به صاحبُه */
    public const OPENING = 'رصيد افتتاحي';

    /** كميةٌ غُيّرت بيدٍ من شاشة المنتج — لا بيعٌ ولا جردٌ ولا شحنة */
    public const MANUAL = 'تعديل يدوي';

    /**
     * تقييدُ حركةٍ وقعت بيدِ مُنادٍ حرّك الرصيدَ بنفسه.
     *
     * ═══ ولمَ بابٌ ثانٍ إلى جانب `move` ═══
     *
     * `move` تفعل الرقصةَ كاملةً: توزّع، وتزيد الإجماليّ، وتُعدّل رصيدَ
     * الفرع، ثمّ تُقيّد. وشاشةُ المنتج تكتب **كميةً مطلقة** لا فرقًا، وتقفل
     * الصفَّ بنفسها لتحسب الفرق. فلو نادت `move` لَزِيد الرصيدُ مرّتين.
     *
     * فهذه تُقيّد وحدَها — ولا تمسّ رصيدًا. ومن يناديها قد حرّكه قبلها.
     *
     * ═══ والعطبُ الذي فتحه غيابُها ═══
     *
     * كانت شاشةُ المنتج تُغيّر الكمية في ثلاثة مواضع بلا سطرٍ في
     * `inventory_movements`: عند الإنشاء، وعند التعديل، وفي التعديل السريع.
     * فيرى التاجرُ رصيدَه تغيّر ولا يجد في «حركات المخزون» ما يقول متى ولا
     * بيدِ من. ومخزونٌ يتغيّر بلا أثرٍ يُقرأ بابٌ مفتوحٌ على سرقةٍ لا تُكتشف.
     *
     * ═══ والدفترُ الثاني كان ما يزال مفتوحًا ═══
     *
     * سُدّ الأثرُ في `inventory_movements` وبقي الأستاذُ لا يعلم: بضاعةٌ
     * تدخل الرفَّ بقيمتها ولا تُقيَّد أصلًا، ثمّ تخرج بالبيع فتُنقص المخزونَ
     * بتكلفتها. وقيس على الإنتاج: رصيدُ حساب المخزون سالب.
     *
     * فصار التقييدُ هنا — في المضيق الذي تمرّ منه الأبواب الخمسة (إنشاءُ
     * صنفٍ، وتعديلُه، والتعديلُ السريع، والاستيراد، والتراجعُ عنه) لا في
     * خمسة مواضعَ تُنسى السادسة:
     *
     *  • **رصيدٌ افتتاحيّ** → مخزونٌ مدين / **رأس المال** دائن. بضاعةٌ
     *    يملكها صاحبُها قبل النظام، لا ربحٌ حقّقه هذا الشهر — ولو قُيّدت
     *    إيرادًا لَقرأ متجرٌ يُدخل أصنافَه أوّلَ يومٍ أنّه ربح ثمنَ مخزونه.
     *
     *  • **تعديلٌ يدويّ** → تسويةُ مخزونٍ كما في شاشة التسويات، بوصفة
     *    `StockLosses` نفسِها: نقصٌ يُكتب خسارةً وصفَّ مصروف، وزيادةٌ تُردّ.
     *    ولا قاعدةٌ ثانيةٌ للشاشة الثانية.
     *
     * ومرجعُ القيد صفُّ الحركة لا الصنف: لصنفٍ واحد حركاتٌ كثيرة، ومرجعٌ
     * يشير إليه يجعل تعديلَ اليوم وتعديلَ أمسٍ قيدًا واحدًا في عين من
     * يستدرك أو يبحث عن تكرار.
     */
    public static function note(
        int $businessId,
        ?int $branchId,
        Product $product,
        int $delta,
        string $type,
        ?string $employeeName = null,
        ?string $note = null,
    ): void {
        // وصفرٌ ليس حركة: من حفظ الشاشة بلا تغيير لا يُقيَّد له شيء
        if ($delta === 0) {
            return;
        }

        $movement = InventoryMovement::create([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'type' => $type,
            /* والإشارةُ تُكتب كما في كلّ حركةٍ هنا — انظر `move` */
            'quantity' => ($delta > 0 ? '+' : '').$delta,
            'employee_name' => $employeeName,
            'note' => $note,
        ]);

        $value = round(abs($delta) * (float) $product->cost, 3);

        /* وصنفٌ بلا تكلفةٍ لا قيدَ له: لا مبلغَ يُقيَّد — كما في شاشة التسويات */
        if ($value <= 0) {
            return;
        }

        $label = $product->name.($note !== null && trim($note) !== '' ? ' — '.$note : '');

        if ($type === self::OPENING) {
            Ledger::post(
                $businessId,
                __('رصيد افتتاحي: ').$label,
                [
                    ['account' => 'inventory', 'debit' => $value],
                    ['account' => 'capital', 'credit' => $value],
                ],
                now(),
                self::OPENING,
                $branchId,
                auth()->id(),
                $movement,
            );

            return;
        }

        if ($type === self::MANUAL) {
            StockLosses::record(
                $businessId,
                $value,
                $delta < 0,
                __('تعديل يدوي: ').$label,
                now(),
                $branchId,
                auth()->id(),
                $employeeName,
                self::MANUAL,
                $movement,
            );
        }
    }

    /** نوع الحركة حين تُستهلك مكوّناتٌ لصنع باقة */
    public const RECIPE = 'استهلاك وصفة';

    /** نوع الحركة حين تُباع إضافةٌ لها رصيدٌ في الرفّ */
    public const ADDON = 'بيع إضافة';

    /** ردُّ ما لم يُبَع بعد تصحيح فاتورة */
    public const CORRECTION = 'تعديل فاتورة';

    /** يجمع فرقين على الصنف نفسه بدل أن يطمس أحدهما الآخر */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $id => $delta) {
            $a[$id] = ($a[$id] ?? 0) + $delta;
        }

        return $a;
    }

    /** لا يُستدعى إلا داخل معاملة — يُبقي الفحص والخصم على رقمٍ لا يتغيّر تحتهما */
    public static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException('حركة المخزون تُكتب داخل معاملة أو لا تُكتب.');
        }
    }
}
