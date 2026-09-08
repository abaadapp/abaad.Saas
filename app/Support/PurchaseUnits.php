<?php

namespace App\Support;

use App\Models\PurchaseOrderItem;

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
 */
final class PurchaseUnits
{
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

        return array_values(array_unique([...$used, ...self::SUGGESTED]));
    }
}
