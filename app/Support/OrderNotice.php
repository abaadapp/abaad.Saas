<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Order;

/**
 * إبلاغُ الزبون بحالة طلبه — بيدِ التاجر حين لا تعمل اليد الآليّة.
 *
 * ═══ ولمَ يُبنى هذا وعندنا مُرسِلٌ آليّ ═══
 *
 * المُرسِلُ الآليّ يمرّ بميتا، وميتا تُوقِف: حظرٌ على التطبيق، أو قالبٌ لم
 * يُعتمَد، أو حصّةٌ نفدت. وفي كلّ واحدةٍ منها يبقى الزبون بلا خبرٍ أيّامًا
 * — لا يعرف أنّ طلبه جاهز، ولا أنّه في الطريق.
 *
 * وهذا الطريقُ لا يمرّ بميتا أصلًا: يُفتح واتساب التاجر بنصٍّ مكتوب، ويضغط
 * هو «إرسال». لا مفتاحَ ولا قالبَ ولا إذن.
 *
 * ═══ وما لا يدّعيه ═══
 *
 * لا يُقال «أُرسلت» ولا تُكتب رسالةٌ في `whatsapp_messages`: ذاك الجدولُ
 * سجلُّ ما مرّ بميتا، وصفٌّ فيه بلا معرّفٍ منها يكذب على كلّ من يقرؤه —
 * على التاجر في شاشته، وعلى `WhatsAppHealth` في حسابها. فما يُختَم هنا
 * **إعدادُ** الإشعار، وهو ما جرى فعلًا.
 *
 * ═══ ولا حدثَ يُخترع ═══
 *
 * الأحداثُ من `WhatsAppEvent::forStatus` نفسِها التي يقرؤها المُرسِلُ
 * الآليّ. ولو كُتبت خريطةٌ ثانيةٌ هنا لَافترقتا يومًا: يُضاف حدثٌ هناك فلا
 * يجده الزرُّ، أو تُبدَّل حالةٌ فيُبلَّغ الزبون بما لا تعرضه الشاشة.
 */
class OrderNotice
{
    /**
     * سطرُ الإشعار لكلّ حدث — سطرٌ واحدٌ لا يفترض شيئًا.
     *
     * و«جاهز» لا تقول «بانتظارك في المحلّ»: منها ما يُوصَّل. و«سُلّم» لا
     * تسأل تقييمًا: لذاك زرُّه وشرطُه. وكلُّ جملةٍ زائدةٍ احتمالُ كذبةٍ في
     * طلبٍ لم نتصوّره.
     */
    private const LINES = [
        WhatsAppEvent::ORDER_CONFIRMED => 'استلمنا طلبك رقم :number، وجارٍ تجهيزه.',
        WhatsAppEvent::ORDER_READY => 'طلبك رقم :number جاهز. 🌷',
        WhatsAppEvent::ORDER_OUT_FOR_DELIVERY => 'طلبك رقم :number في الطريق إليك. 🚗',
        WhatsAppEvent::ORDER_DELIVERED => 'تم تسليم طلبك رقم :number. شكرًا لاختيارك لنا. 🌷',
    ];

    /** حدثُ الحالة الراهنة، أو لا شيء إن كانت حالةً لا يُبلَّغ عنها */
    public static function event(Order $order): ?string
    {
        $event = WhatsAppEvent::forStatus($order->status);

        return $event !== null && isset(self::LINES[$event]) ? $event : null;
    }

    /** رقمُ واتساب لهذا الطلب — رقمُ العميل، وإلّا رقمُ المستلِم */
    public static function phone(Order $order): ?string
    {
        return WhatsAppPhone::normalize($order->customer?->phone ?: $order->recipient_phone);
    }

    /**
     * نصُّ الإشعار — يُبنى في الخادم لا في الشاشة.
     *
     * واسمُ المحلّ سطرٌ أوّل لا زينة: الرسالة تخرج من رقم التاجر نفسِه، لكن
     * زبونًا يشتري من ثلاثة محلّاتٍ لا يعرف صاحبَ الرقم من صاحبه.
     */
    public static function text(Order $order, string $event): string
    {
        $shop = Business::find($order->business_id)?->name ?: __('متجر');

        return $shop."\n".__(self::LINES[$event], ['number' => (string) $order->number]);
    }

    /**
     * حالُ الزرّ في الشاشة — وكلُّ حقلٍ فيه يُقرأ.
     *
     * `event` يحكم العرضَ أصلًا، و`show` يفرّق الزرَّ العاملَ من المعطَّل،
     * و`reason` هو **نصُّ** المعطَّل لا وصفٌ يُهمَل، و`preparedAt` يفرّق
     * «أبلغ» من «أبلغ مجددًا». وحقلٌ لا يقرؤه شيءٌ يُحذَف.
     *
     * @return array{event:?string, show:bool, reason:?string, preparedAt:?string}
     */
    public static function state(?Order $order): array
    {
        $empty = ['event' => null, 'show' => false, 'reason' => null, 'preparedAt' => null];

        if ($order === null) {
            return $empty;
        }

        $event = self::event($order);

        if ($event === null) {
            return $empty;
        }

        /*
         * وإطفاءُ التاجر يُحترَم.
         *
         * من أطفأ «تسليم الطلب» في إعداداته لا يُعرض له زرٌّ يرسله: مقبضٌ
         * يخالف إعدادًا كتبه صاحبُه بيده يجعل الإعدادَ كذبًا.
         */
        if (! self::enabled($order->business_id, $event)) {
            return ['event' => $event, 'show' => false, 'preparedAt' => null,
                'reason' => __('إشعار :event مُطفأ في إعداداتك', ['event' => WhatsAppEvent::label($event)])];
        }

        if (self::phone($order) === null) {
            return ['event' => $event, 'show' => false, 'preparedAt' => null,
                'reason' => __('لا رقم واتساب لهذا الطلب')];
        }

        /* والختمُ لا يُقرأ إلّا لحدثه: ختمُ «جاهز» لا يقول شيئًا عن «في الطريق» */
        $stamped = $order->status_notice_event === $event
            ? optional($order->status_notice_at)->toIso8601String()
            : null;

        return ['event' => $event, 'show' => true, 'reason' => null, 'preparedAt' => $stamped];
    }

    /** مفاتيح `wa_on_*` نفسُها التي يقرؤها المُرسِلُ الآليّ — لا نسخةٌ عنها */
    private static function enabled(int $businessId, string $event): bool
    {
        $key = WhatsAppEvent::SETTING_KEYS[$event] ?? null;

        return $key !== null && (MarketingSettings::group($businessId, 'whatsapp')[$key] ?? '0') !== '0';
    }
}
