<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\StorePaymentIntent;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;

/**
 * إيصالُ الدفع الإلكترونيّ للزبون — متى يُعطى.
 *
 * ═══ ما هو ═══
 *
 * زبونٌ دفع ببطاقته على موقع المتجر يرى في صفحة الشكر زرًّا يفتح إيصالَه
 * الحراريّ — **الورقةُ نفسُها** التي يطبعها الصندوق (`PdfController::thermal`):
 * قالبُ البيع بإعدادات متجره، لا ورقةٌ ثانيةٌ تفترق عنها مع الوقت.
 *
 * ═══ ولمن ═══
 *
 * لمتجرٍ فتحه صاحبُه (`store_paid_receipt` في مجموعة `website`). ومغلقٌ
 * افتراضًا: متجرٌ لم يطلبه لا يتبدّل شيءٌ في صفحة شكره.
 *
 * ═══ ومتى يُقال «مدفوع» ═══
 *
 * حين تقول ذلك **نيّةُ الدفع** لا المتصفّح: نيّةٌ لهذا المتجر ولهذا الطلب،
 * ثبّتها إشعارُ Paymob الموقَّع (`PaymobController::settle` يكتب `paid` ومعرّفَ
 * العمليّة معًا)، ولم يُطلب لها ردٌّ ولم يُردّ منها شيء. ولا يُقرأ هنا شيءٌ
 * من الرابط: `success=true` يكتبه من شاء.
 *
 * والطلبُ الملغى لا إيصالَ له وإن دُفع: الإلغاءُ يُعيد البضاعة، وإيصالٌ يقول
 * «مدفوع» لطلبٍ أُلغي ورقةٌ تكذب في يد من يحملها.
 */
final class PaidReceipt
{
    public const KEY = 'store_paid_receipt';

    /** فتحه صاحبُ المتجر؟ */
    public static function enabled(int $businessId): bool
    {
        return (MarketingSettings::group($businessId, 'website')[self::KEY] ?? '0') === '1';
    }

    /**
     * دُفع هذا الطلبُ إلكترونيًّا دفعًا ثبت — ولم يُردّ؟
     *
     * والنيّةُ تُسأل بمتجر الطلب ومعرّفه معًا: نيّةُ متجرٍ آخر تحمل الرقمَ
     * نفسَه لا تشهد لطلبٍ هنا.
     */
    public static function settled(Order $order): bool
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return false;
        }

        return StorePaymentIntent::where('business_id', $order->business_id)
            ->where('order_id', $order->id)
            ->where('status', StorePaymentIntent::PAID)
            ->whereNotNull('provider_transaction_id')
            ->whereNull('refund_status')
            ->whereNull('refunded_at')
            ->exists();
    }

    /** يُعطى الزبونُ إيصالَه؟ — الإعدادُ والدفعُ معًا */
    public static function offered(Order $order): bool
    {
        return self::enabled((int) $order->business_id) && self::settled($order);
    }
}
