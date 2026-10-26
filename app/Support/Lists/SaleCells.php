<?php

namespace App\Support\Lists;

use App\Models\Order;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use App\Support\Store\ShopClock;
use Carbon\CarbonInterface;
use Illuminate\Translation\MessageSelector;

/**
 * خلايا «المبيعات» التي تُقرأ جملًا — المؤكِّد، والمنتجات، والتسليم.
 *
 * تُكتب هنا مرّةً لصفّ الطلب وصفّ التحصيل والشاشة والملفّات: ما تقوله
 * الشاشة «منتجان — 5 قطع» يقوله ملفُّها حرفًا بحرف، ولا تعدّ الواجهةُ شيئًا.
 */
final class SaleCells
{
    /**
     * ما اشتُري: بنودُ المنتجات المختلفة وقطعُها — والإضافاتُ المدفوعة وحدَها.
     *
     * منتجان، الأوّلُ ثلاثُ قطعٍ والثاني قطعتان: «منتجان — 5 قطع»، لا «5
     * منتجات». والإضافةُ التي تُباع سطرًا (`standalone_addon_id`) أو بندًا في
     * فاتورة (`addon_id`) لا تُعدّ منتجًا رئيسًا: تُقال في سطرها.
     *
     * @return array{lines: int, pieces: int, addons: int, label: string, addons_label: ?string}
     */
    public static function products(int $lines, float|int $pieces, int $addons = 0): array
    {
        $pieces = (int) round((float) $pieces);

        return [
            'lines' => $lines,
            'pieces' => $pieces,
            'addons' => $addons,
            'label' => $lines === 0
                ? __('لا منتجات')
                : self::count('منتج', $lines).' — '.self::count('قطعة', $pieces),
            'addons_label' => $addons > 0 ? '+ '.self::count('إضافة', $addons) : null,
        ];
    }

    /** «منتج واحد» و«منتجان» و«3 منتجات» و«11 منتجًا» — وبالإنجليزيّة عددُها */
    public static function count(string $noun, int $n): string
    {
        $forms = [
            'منتج' => '{0} لا منتجات|{1} منتج واحد|{2} منتجان|[3,10] :count منتجات|[11,*] :count منتجًا',
            'قطعة' => '{0} لا قطع|{1} قطعة واحدة|{2} قطعتان|[3,10] :count قطع|[11,*] :count قطعة',
            'إضافة' => '{0} لا إضافات|{1} إضافة واحدة|{2} إضافتان|[3,10] :count إضافات|[11,*] :count إضافةً',
        ];

        /*
         * لا `trans_choice`: مفتاحٌ عربيٌّ لا سطرَ له في لغة الصفحة العربيّة،
         * فتقرؤه بلغة الاحتياط (الإنجليزيّة). فتُترجم الصيغةُ بلغة الصفحة —
         * وهي نفسُها بالعربيّة — ثمّ يُختار منها بعددها.
         */
        $line = (new MessageSelector)->choose(__($forms[$noun]), $n, app()->getLocale());

        return str_replace(':count', (string) $n, $line);
    }

    /**
     * «التسليم / الاستلام» — نوعُ التنفيذ دائمًا، ثمّ موعدُه أو أنّه لم يُحدَّد.
     *
     * «توصيل — 28/10/2026، 5:00 م»، وفترةُ الجدول بطرفيها لا ببدايتها
     * («5:00 م – 7:00 م»)، و«استلام من المحل — لم يُحدد موعد». والموعدُ من
     * `scheduled_for`/`scheduled_until` بساعة المتجر — لا يومُ الطلب ولا يومُ
     * الدفع. وبيعةُ المنضدة بلا نوعٍ ولا موعدٍ لا جملةَ لها: `null`.
     *
     * @return array{kind: ?string, type: ?string, when: ?string, label: string}|null
     */
    public static function handover(?string $type, ?CarbonInterface $from, ?CarbonInterface $until = null): ?array
    {
        $kind = in_array($type, [FlowerOrder::DELIVERY, FlowerOrder::PICKUP], true) ? $type : null;

        if ($kind === null && $from === null) {
            return null;
        }

        $when = $from !== null ? self::window($from, $until) : null;
        $name = $kind !== null ? FlowerOrder::fulfillmentLabel($kind) : null;

        return [
            'kind' => $kind,
            'type' => $name,
            'when' => $when,
            'label' => match (true) {
                $name !== null && $when !== null => $name.' — '.$when,
                $name !== null => $name.' — '.__('لم يُحدد موعد'),
                default => (string) $when,
            },
        ];
    }

    /** «28/10/2026، 5:00 م» — أو بطرفي الفترة: «28/10/2026، 5:00 م – 7:00 م» */
    public static function window(CarbonInterface $from, ?CarbonInterface $until = null): string
    {
        $zone = ShopClock::zone();
        $from = $from->copy()->setTimezone($zone);
        $out = self::moment($from);

        if ($until !== null && $until->greaterThan($from)) {
            $until = $until->copy()->setTimezone($zone);
            $out .= ' – '.($until->isSameDay($from) ? ShopClock::time($until, app()->getLocale()) : self::moment($until));
        }

        return $out;
    }

    /** «28/10/2026، 5:00 م» بلغة الشاشة */
    public static function moment(CarbonInterface $at): string
    {
        $lang = app()->getLocale();
        $at = $at->copy()->setTimezone(ShopClock::zone());

        return $at->format('d/m/Y').($lang === 'en' ? ', ' : '، ').ShopClock::time($at, $lang);
    }

    /**
     * من أكّد الطلب — أوّلُ قبولٍ له (`OrderTransition::confirms`).
     *
     * يُقال لطلب الموقع دائمًا (لا بائعَ له، فالمؤكِّدُ هو من يُسأل عنه)،
     * ولغيره حين يُنتظر تأكيدُه أو سُجّل. وطلبٌ أُكّد قبل أن يُحفظ المؤكِّد لا
     * يُخترع له اسم: «مؤكّد — الموظف غير معروف».
     *
     * @return array{state: string, by: ?string, by_id: ?int, at: ?string, label: string}|null
     */
    public static function confirmation(Order $o): ?array
    {
        $pending = $o->status === OrderStatus::PENDING;

        if ($o->confirmed_at === null && ! $pending && $o->channel !== SalesChannel::WEBSITE) {
            return null;
        }

        if ($o->confirmed_at !== null) {
            $at = $o->confirmed_at->copy()->setTimezone(ShopClock::zone());
            $by = $o->confirmed_by_name !== null && trim($o->confirmed_by_name) !== '' ? $o->confirmed_by_name : null;
            // الوقتُ وحده ليومٍ هو يومُ الطلب، وإلّا يومُه معه
            $when = $o->ordered_at !== null && $at->isSameDay($o->ordered_at->copy()->setTimezone(ShopClock::zone()))
                ? ShopClock::time($at, app()->getLocale())
                : self::moment($at);

            return [
                'state' => 'confirmed',
                'by' => $by,
                'by_id' => $o->confirmed_by !== null ? (int) $o->confirmed_by : null,
                'at' => $at->format('Y-m-d H:i'),
                'label' => $by !== null
                    ? __('أكّده: :name — :when', ['name' => $by, 'when' => $when])
                    : __('مؤكّد — :when، الموظف غير معروف', ['when' => $when]),
            ];
        }

        [$state, $label] = match (true) {
            $pending => ['pending', __('بانتظار التأكيد')],
            // أُلغي ولا تأكيدَ محفوظًا له — لا يُقال «بانتظار» ولا «مؤكّد»
            $o->status === OrderStatus::CANCELLED => ['none', __('لا تأكيد مسجَّل')],
            default => ['unknown', __('مؤكّد — الموظف غير معروف')],
        };

        return ['state' => $state, 'by' => null, 'by_id' => null, 'at' => null, 'label' => $label];
    }
}
