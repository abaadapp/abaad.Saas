<?php

namespace App\Console\Commands;

use App\Mail\QueueDownMail;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * سقط عاملُ الطابور — يُقال لمن يملك الخادم، لا لسجلٍّ لا يُقرأ.
 *
 *     php artisan abaad:queue-alert abaad-queue.service
 *
 * ═══ ولمَ أمرٌ لهذا ═══
 *
 * `Restart=always` يُعيد العاملَ من سقوطٍ عابر، وهذا يكفي أكثرَ الأحيان.
 * لكنّ سقوطًا يتكرّر خمسَ مرّاتٍ في دقيقةٍ يُترك ساقطًا عن قصد — وحينها لا
 * أحدَ يسحب من الطابور، ولا شيءَ في النظام يقول ذلك حتّى يُفتح `preflight`
 * أو تُقرأ شاشةٌ عالقةٌ على «قيد التنفيذ».
 *
 * و`OnFailure=` في وحدة systemd يشغّل هذا الأمر، فيصل الخبرُ في بريد.
 *
 * ═══ وإلى من ═══
 *
 * إلى مديري المنصّة — وهم في القاعدة، فلا يُضاف عنوانٌ في `.env` يُنسى
 * تحديثُه. ومن لا بريدَ له لا يُراسَل، والسجلُّ يُكتب على كلّ حال: بريدٌ
 * قد لا يُرسَل إن كان المُرسِلُ نفسُه معطوبًا، والسجلُّ يبقى.
 */
class QueueAlert extends Command
{
    protected $signature = 'abaad:queue-alert {unit=abaad-queue.service}';

    protected $description = 'تنبيه مديري المنصّة بسقوط عامل الطابور';

    public function handle(): int
    {
        $unit = (string) $this->argument('unit');
        $when = now()->toDateTimeString();

        Log::error('سقط عامل الطابور', ['unit' => $unit, 'host' => gethostname()]);

        $to = User::where('role', 'super_admin')->whereNotNull('email')->pluck('email')->all();

        if ($to === [] || ! Mailer::configured()) {
            $this->warn('لا بريدَ يُرسَل منه أو إليه — كُتب في السجلّ وحدَه.');

            return self::SUCCESS;
        }

        try {
            Mail::to($to)->send(new QueueDownMail($unit, (string) gethostname(), $when));
        } catch (Throwable $e) {
            /* وتعذُّرُ الإرسالِ لا يُخفي الخبر — السجلُّ كُتب أوّلًا */
            Log::error('تعذّر إرسال تنبيه سقوط العامل', ['type' => class_basename($e)]);

            $this->warn('تعذّر إرسال البريد — كُتب في السجلّ.');

            return self::SUCCESS;
        }

        $this->info('أُرسل التنبيه إلى '.count($to).' مدير منصّة.');

        return self::SUCCESS;
    }
}
