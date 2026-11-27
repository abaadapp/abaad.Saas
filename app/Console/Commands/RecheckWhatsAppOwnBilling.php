<?php

namespace App\Console\Commands;

use App\Models\WhatsAppBilling;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppBillingLock;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppOwnApp;
use Illuminate\Console\Command;

/**
 * أما زالت فوترةُ كلِّ وصلةٍ بتطبيق تاجرٍ كما رُوجعت؟ — قراءةٌ عند ميتا كلّ يوم.
 *
 * الدليلُ يُراجَع مرّة، والحسابُ يتبدّل بعده: تُشارَك مع أبعاد، أو تُزال
 * وسيلةُ الدفع، أو تُبدَّل. فوصلةٌ نقضت قراءةُ اليوم دليلَها تقف حتى يُراجَع
 * دليلٌ جديد (`WhatsAppOwnApp::recheckBilling`).
 *
 * قراءاتٌ لا غير — لا اشتراكَ يُنادى ولا شيءَ يُكتب عند ميتا. وما لم يُقرأ
 * (انقطاعٌ، `#10`) لا يوقف وصلة.
 */
class RecheckWhatsAppOwnBilling extends Command
{
    protected $signature = 'whatsapp:own-billing';

    protected $description = 'إعادة قراءة فوترة وصلات تطبيقات التجّار عند ميتا — وإيقاف ما نقضت قراءتُه دليلَه';

    public function handle(): int
    {
        $stopped = 0;

        WhatsAppConnection::where('link_type', WhatsAppOwnApp::TYPE)
            ->where('status', '!=', WhatsAppConnection::INACTIVE)
            ->each(function (WhatsAppConnection $connection) use (&$stopped) {
                if (WhatsAppOwnApp::recheckBilling($connection) !== null) {
                    $stopped++;
                    $this->warn('أُوقفت وصلة المتجر '.$connection->business_id);
                }
            });

        // ‏‏وما ثبت من وصلات تطبيق أبعاد يُقرأ كلَّ يوم — ما نقضته القراءةُ وقف (`WhatsAppBillingLock`)
        WhatsAppBilling::where('state', WhatsAppBillingLock::PROVEN)->each(function (WhatsAppBilling $row) use (&$stopped) {
            $connection = WhatsAppConnections::forBusiness((int) $row->business_id);

            if ($connection === null || (string) $connection->waba_id !== $row->waba_id || ! WhatsAppBillingLock::reviewable($connection)) {
                return;
            }

            try {
                if (WhatsAppBillingLock::daily($connection)?->state !== WhatsAppBillingLock::PROVEN && WhatsAppBillingLock::applies($connection)) {
                    $stopped++;
                    $this->warn('أُوقفت وصلة المتجر '.$row->business_id);
                }
            } catch (\Throwable $e) {
                // ‏‏وعطبٌ في قراءة متجرٍ لا يوقف ما بعده
                report($e);
            }
        });

        $this->line('أُوقف: '.$stopped);

        return self::SUCCESS;
    }
}
