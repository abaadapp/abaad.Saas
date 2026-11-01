<?php

namespace App\Jobs;

use App\Support\WhatsAppOrders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * فاتورةُ طلب واتساب ورابطُ دفعه — بعد أن وافق الزبون، خارجَ باب الإشعارات.
 *
 * محاولةٌ واحدة: ما تعذّر يُحوَّل لموظّفٍ بسببه ويُقال للزبون (`WhatsAppOrders::fail`)،
 * ولا يُعاد آليًّا. والإعادةُ لا تكتب ورقةً ثانيةً ولا رابطًا ثانيًا أصلًا
 * (`WhatsAppOrderCheckout::invoice` و`PaymentLinks::forInvoice`) — لكنّ رسالةَ
 * «تعذّر» التي وصلت الزبونَ لا تُتبَع بفاتورةٍ تظهر بعدها بلا علمه.
 */
class IssueWhatsAppOrderPayment implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $sessionId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        WhatsAppOrders::issue($this->sessionId);
    }
}
