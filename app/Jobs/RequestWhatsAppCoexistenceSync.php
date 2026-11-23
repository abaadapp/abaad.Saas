<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Support\WhatsAppCoexistence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ‏‏يطلب من Meta جهاتِ اتّصال التاجر ثمّ محادثاتِه السابقة — بعد ربط رقمٍ بقي في تطبيق واتساب للأعمال.
 *
 * Meta تقبل كلًّا منهما **مرّةً واحدة خلال ٢٤ ساعة من الربط**. فيُعاد ما فشل بتباعدٍ يبقى داخل
 * اليوم (نحو سبع ساعات ونصف في المجموع)، ولا يُعاد ما قُبل، ولا يُطلب شيءٌ بعد اليوم
 * (`WhatsAppCoexistence::request`).
 */
class RequestWhatsAppCoexistenceSync implements ShouldQueue
{
    use Queueable;

    public $tries = 8;

    public array $backoff = [60, 300, 900, 1800, 3600, 7200, 14400];

    public function __construct(public int $connectionId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $connection = WhatsAppConnection::find($this->connectionId);

        if (! WhatsAppCoexistence::applies($connection) || $connection->status === WhatsAppConnection::INACTIVE) {
            return;
        }

        foreach (WhatsAppCoexistence::SYNC_ORDER as $type) {
            $outcome = WhatsAppCoexistence::request($connection, $type);

            if ($outcome === WhatsAppCoexistence::EXPIRED) {
                return;
            }

            // ‏‏والتاريخُ بعد الجهات — فما لم تُقبل الجهاتُ بعد لا يُطلب التاريخ قبلها
            if ($outcome === WhatsAppCoexistence::FAILED || $outcome === WhatsAppCoexistence::BUSY) {
                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
                }

                return;
            }
        }
    }
}
