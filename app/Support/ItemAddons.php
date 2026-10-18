<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use Illuminate\Support\Collection;

/**
 * إضافاتُ صنفٍ كما بيعت — لتُطبع تحته، ومالُها مرّةً واحدة.
 *
 * ═══ العطب ═══
 *
 * الإيصالُ الحراريّ كان يطبع الصنفَ بثمنه وكميّته ويسكت عن إضافاته: باقةٌ
 * بيعت بشوكولاتة وبطاقة تخرج على الشريط «باقة ورد» وحدها، وعمودُ «الإجمالي»
 * يقول ثمنَ الباقة دون إضافاتها — فلا تجمع أسطرُه إلى «المجموع الفرعي» الذي
 * تحتها، ولا يعرف من يجهّز ماذا يضع في الكيس.
 *
 * ═══ ومن أين تُقرأ ═══
 *
 * من لقطة البيع (`order_item_addons`): الاسمُ والكميّةُ وثمنُ الواحدة
 * والمجموعُ ساعةَ البيع. إضافةٌ غُيّر ثمنُها أو حُذفت من الكتالوج بعدها لا
 * تُعيد كتابة إيصالٍ مضى — ولا يُخترع لها اسمٌ ولا ثمنٌ لم يُحفظ.
 *
 * ═══ ومالُها مرّةً واحدة — بقاعدة الفاتورة نفسِها ═══
 *
 * القاعدةُ التي تكتب بها `CustomerInvoices::orderItemLines` إضافاتِ الطلب
 * أسطرًا في فاتورة العميل، فلا تفترق ورقتان في معنى واحد:
 *
 *   - **`priced`**: جُبيت فوق ثمن الصنف (`addons_total`) ولقطاتُها تقول كم
 *     بالضبط — سطرٌ لكلّ إضافةٍ بثمنها وكميّتها ومجموعها.
 *   - **`bundled`**: جُبيت ولا تطابق لقطاتُها ما جُبي (صفٌّ قديمٌ أو مصحَّح) —
 *     سطرٌ واحدٌ بما جُبي فعلًا يسمّي ما فيه. لا يُوزَّع المبلغُ بالظنّ.
 *   - **`included`**: داخل ثمن الصنف (طلبٌ مخصَّصٌ بميزانيّة) — تُسمّى بلا مال.
 *   - **`none`**: لا إضافات.
 *
 * فمجموعُ أسطر الصنف = `OrderItem::lineTotal()` — ثمنُه وإضافاتُه، لا أكثر.
 */
final class ItemAddons
{
    /**
     * @return array{mode: 'none'|'priced'|'bundled'|'included', rows: list<array{name: string, qty: int, unit: float, total: float}>, names: string, total: float}
     */
    public static function of(OrderItem $item): array
    {
        $addons = self::addons($item);

        if ($addons->isEmpty()) {
            return ['mode' => 'none', 'rows' => [], 'names' => '', 'total' => 0.0];
        }

        $rows = $addons->map(fn (OrderItemAddon $a) => [
            'name' => (string) $a->name,
            'qty' => (int) $a->quantity,
            'unit' => round((float) $a->unit_price, 3),
            'total' => round((float) $a->total, 3),
        ])->values()->all();

        $charged = round((float) $item->addons_total, 3);
        $snapshots = round((float) array_sum(array_column($rows, 'total')), 3);

        return [
            'mode' => match (true) {
                $charged > 0 && abs($charged - $snapshots) < 0.0005 => 'priced',
                $charged > 0 => 'bundled',
                default => 'included',
            },
            'rows' => $rows,
            'names' => self::names($rows),
            'total' => $charged,
        ];
    }

    /**
     * «شوكولاتة × 2، بطاقة تهنئة» — كما تُقرأ بعين من يجهّز.
     *
     * @param  list<array{name: string, qty: int}>  $rows
     */
    public static function names(array $rows): string
    {
        return collect($rows)->map(fn (array $r) => $r['qty'] > 1 ? $r['name'].' × '.$r['qty'] : $r['name'])->implode('، ');
    }

    /**
     * إضافاتُ الصنف — المحمَّلةُ إن حُمّلت، ولا سؤالَ لصنفٍ لم يُحفظ.
     *
     * صنفُ المعاينة في محرّر القوالب (`DocumentRenderer::sampleOrder`) لا صفَّ
     * له، فلا يُسأل عنه.
     *
     * @return Collection<int, OrderItemAddon>
     */
    private static function addons(OrderItem $item): Collection
    {
        if ($item->relationLoaded('addons')) {
            return $item->addons;
        }

        return $item->exists ? $item->addons : collect();
    }
}
