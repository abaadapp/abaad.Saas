<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppTemplates;
use Illuminate\Console\Command;

/**
 * نسألُ ميتا: أيَّ قوالبنا اعتمدتِ؟
 *
 * ═══ ولمَ أمرٌ دوريّ لا فحصٌ عند الإرسال ═══
 *
 * سؤالُ ميتا مع كلّ رسالة يعني نداءً شبكيًّا زائدًا على كلّ طلبٍ يُؤكَّد،
 * وتوقّفَ الإشعارات كلِّها إن تأخّرت ميتا لحظةً. والاعتمادُ يتبدّل مرّةً في
 * عمر القالب لا مرّةً في الساعة — فيُقرأ من جدولنا، ويُحدَّث من هنا.
 *
 * ولا يُطفأ شيءٌ عند فشل النداء: انقطاعُ شبكةٍ ليس رفضًا من ميتا، والحالُ
 * القديم أصدقُ من حالٍ يُخترع من فشلِ اتّصال.
 */
class SyncWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'قراءة حال قوالب واتساب من ميتا — أيّها معتمَدٌ وأيّها ينتظر';

    public function handle(): int
    {
        $connections = WhatsAppConnection::where('status', WhatsAppConnection::ACTIVE)
            /* ورقمُ المبيعات لا قوالبَ له: نصٌّ حرٌّ داخل نافذة الساعات الأربع والعشرين */
            ->where('purpose', WhatsAppMode::PURPOSE_NOTIFICATIONS)
            ->get();

        if ($connections->isEmpty()) {
            $this->line('لا وصلةَ نشطة — لا شيء يُسأل عنه.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $platform = $connection->owner_type === WhatsAppMode::OWNER_PLATFORM;

            $result = WhatsAppTemplates::sync(
                $connection,
                $platform ? WhatsAppMode::OWNER_PLATFORM : WhatsAppMode::OWNER_BUSINESS,
                $platform ? null : $connection->business_id,
            );

            $who = $platform ? 'أبعاد' : ('متجر #'.$connection->business_id);

            if (! $result['ok']) {
                /*
                 * ويُقال الفشلُ ولا يُبتلع — ويُردّ نجاحًا على كلّ حال.
                 *
                 * وصلةٌ واحدة تعذّر سؤالها لا توقف سؤال الباقيات، وأمرٌ
                 * مجدولٌ يرجع فشلًا يملأ سجلّ الخادم بإنذارٍ لا فعلَ له.
                 */
                $this->warn("تعذّر سؤال ميتا عن قوالب {$who}: ".$result['message']);

                continue;
            }

            $this->line("قوالب {$who}: {$result['approved']} معتمَدٌ من {$result['checked']}");
        }

        return self::SUCCESS;
    }
}
