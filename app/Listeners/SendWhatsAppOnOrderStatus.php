<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;

/**
 * الحالة → الحدث → القرار.
 *
 * ولا قرارَ هنا: هذا مقبسٌ يترجم حالةً إلى حدثٍ ويمرّره. كلّ ما عداه في
 * `WhatsAppAutomation` — موضعٌ واحد يُقرأ ويُختبَر، لا شرطٌ في مستمعٍ وشرطٌ
 * في متحكّم.
 */
class SendWhatsAppOnOrderStatus
{
    public function handle(OrderStatusChanged $event): void
    {
        $type = WhatsAppEvent::forStatus($event->to);

        if ($type === null) {
            return;
        }

        WhatsAppAutomation::handle($event->order, $type);
    }
}
