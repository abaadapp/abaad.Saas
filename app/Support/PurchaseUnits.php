<?php

namespace App\Support;

use App\Models\PurchaseOrderItem;
use App\Models\Setting;

/**
 * وحداتُ الشراء — ما اشترى به المتجرُ فعلًا، ثمّ ما نقترحه عليه.
 *
 * ═══ ولا جدولَ وحدات ═══
 *
 * القائمةُ تُقرأ من بنود أوامر الشراء نفسها: ما كُتب مرّةً يظهر في المرّة
 * التالية مرتَّبًا بكثرة استعماله. فمن اشترى «شتلة» وجدها في انتظاره، ومن
 * لم يشترِ بها قطُّ لا يراها. وجدولُ وحداتٍ يُملأ يدويًّا قبل أوّل أمرِ شراء
 * بابٌ ثانٍ يُنسى ملؤه — والقائمةُ المكتوبة باليد تنسى التاليَ دائمًا.
 *
 * ═══ والمقترَحاتُ ذيلُ القائمة لا رأسُها ═══
 *
 * تُعرض لمن لم يشترِ بعد، وتُزاح إلى الأسفل حالما يصير للمتجر عادةٌ في
 * الشراء. ورأسُ القائمة هو ما تفتح به الشاشةُ حقلَها — فلا يُرسل «الافتراضيُّ»
 * حقلًا ثانيًا يقول ما تقوله القائمة.
 *
 * ═══ وما يُرفع منها يُرفع ولا يُمحى ═══
 *
 * الحذفُ يرفع الوحدةَ من **القائمة** لا من الأوامر: أمرٌ قديمٌ اشترى
 * «بالرول» يبقى يقول «رول» — وإلا صار حذفُ خيارٍ من شاشةٍ يُعيد كتابة تاريخ
 * الشراء. فتُحفظ المرفوعات في مفتاحٍ للمتجر وتُطرح عند القراءة.
 *
 * وما استُعمل عاد: وحدةٌ رُفعت ثمّ كُتبت في أمرٍ جديد تُرفع من المرفوعات —
 * وإلّا كتبها التاجرُ فلم يجدها في السطر التالي، ولا يعرف لماذا.
 */
final class PurchaseUnits
{
    /** مفتاحُ ما رفعه التاجرُ من قائمته */
    public const HIDDEN = 'purchase_units_hidden';

    /** ما يُقترح على متجرٍ لم يشترِ بعد — اقتراحٌ لا حصر */
    public const SUGGESTED = ['حبة', 'ربطة', 'باقة', 'صندوق', 'كرتون', 'رول', 'عبوة', 'كيلو', 'متر', 'وحدة'];

    /**
     * وحداتُ هذا المتجر: المستعملةُ أوّلًا بترتيب كثرتها، ثمّ باقي المقترَحات.
     *
     * @return list<string>
     */
    public static function forBusiness(int $businessId): array
    {
        $used = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.business_id', $businessId)
            ->whereNotNull('purchase_order_items.purchase_unit')
            ->where('purchase_order_items.purchase_unit', '!=', '')
            ->groupBy('purchase_order_items.purchase_unit')
            ->orderByRaw('count(*) desc')
            ->orderBy('purchase_order_items.purchase_unit')
            ->pluck('purchase_order_items.purchase_unit')
            ->map(fn ($u) => trim((string) $u))
            ->filter()
            ->all();

        // ‏والمطروحُ يُطرح آخرًا: وحدةٌ رُفعت وما زالت في أوامرَ قديمة لا تعود بها
        return array_values(array_diff(
            array_unique([...$used, ...self::SUGGESTED]),
            self::hidden($businessId),
        ));
    }

    /**
     * ما رفعه التاجرُ من قائمته.
     *
     * @return list<string>
     */
    public static function hidden(int $businessId): array
    {
        $saved = json_decode((string) Setting::where('business_id', $businessId)
            ->where('key', self::HIDDEN)->value('value'), true);

        if (! is_array($saved)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($u) => trim((string) $u), $saved)));
    }

    /** يرفع وحدةً من قائمة المتجر — ولا يمسّ أمرًا كُتبت فيه */
    public static function hide(int $businessId, string $unit): void
    {
        $unit = trim($unit);

        if ($unit === '') {
            return;
        }

        self::remember($businessId, [...self::hidden($businessId), $unit]);
    }

    /**
     * وما استُعمل عاد.
     *
     * @param  array<int, string|null>  $units
     */
    public static function unhide(int $businessId, array $units): void
    {
        $hidden = self::hidden($businessId);
        $used = array_filter(array_map(fn ($u) => trim((string) $u), $units));
        $rest = array_values(array_diff($hidden, $used));

        if (count($rest) !== count($hidden)) {
            self::remember($businessId, $rest);
        }
    }

    /** @param  array<int, string>  $units */
    private static function remember(int $businessId, array $units): void
    {
        Setting::updateOrCreate(
            ['business_id' => $businessId, 'key' => self::HIDDEN],
            ['value' => json_encode(array_values(array_unique($units)), JSON_UNESCAPED_UNICODE)],
        );
    }
}
