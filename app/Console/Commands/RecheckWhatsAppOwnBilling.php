<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
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

        $this->line('أُوقف: '.$stopped);

        return self::SUCCESS;
    }
}
