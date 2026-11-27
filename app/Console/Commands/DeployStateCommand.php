<?php

namespace App\Console\Commands;

use App\Support\DeployState;
use Illuminate\Console\Command;
use Throwable;

/**
 * ما يسأله النشرُ قبل أن يبدّل الكود وبعد أن يسقط — قراءةً وحدها.
 *
 *   php artisan abaad:deploy-state
 *
 * يُشغَّل بحساب www-data عبر sudo، مثبَّتًا في deploy/abaad-deploy.sudoers بلا وسائط.
 * لا يكتب شيئًا. والخرجُ سطورٌ يقرؤها `scripts/deploy.sh`:
 *
 *   running: <عدد المهامّ الجارية على whatsapp وdefault>
 *   job: #<رقم> <الطابور> <اسم المهمّة> · <ثوانٍ> · attempt <المحاولة>
 *   abandoned: <حجوزٌ تركها عاملٌ مات — لا تجري>
 *   pending-migrations: <عدد الهجرات على القرص التي لم تجرِ، أو ?>
 *   migration: <اسمها>
 *
 * وكلُّ سطرٍ يبدأ باسمه (`job:`، `migration:`) لا بمسافات: Laravel يطوي المسافاتِ في أوّل السطر.
 *
 * والخروجُ: 0 لا مهمّةَ تجري، 3 مهمّةٌ تجري، 2 الطابورُ ليس في القاعدة فلا يُعرف، 1 خطأ.
 * ولا يُطبع من المهمّة إلّا اسمُها: حمولتُها قد تحمل رقمَ زبونٍ أو نصَّ رسالة، وسجلُّ النشر عامّ.
 */
class DeployStateCommand extends Command
{
    public const IDLE = 0;

    public const BUSY = 3;

    public const UNKNOWN_QUEUE = 2;

    protected $signature = 'abaad:deploy-state';

    protected $description = 'ما يسأله النشر: المهامّ الجارية على الطابور والهجرات التي لم تجرِ (قراءة فقط)';

    public function handle(): int
    {
        try {
            $running = DeployState::runningJobs();
            $abandoned = DeployState::abandonedReservations();
        } catch (Throwable $e) {
            $this->line('running: ?');
            $this->line('error: '.class_basename($e));
            $running = false;
            $abandoned = 0;
        }

        if (is_array($running)) {
            $this->line('running: '.count($running));
            foreach ($running as $job) {
                $this->line(sprintf('job: #%d %s %s · %ds · attempt %d', $job['id'], $job['queue'], $job['job'], $job['seconds'], $job['attempts']));
            }
            $this->line('abandoned: '.$abandoned);
        } elseif ($running === null) {
            $this->line('running: ? (queue connection is not database)');
        }

        $pending = DeployState::pendingMigrations();
        $this->line('pending-migrations: '.($pending === null ? '?' : count($pending)));
        foreach ($pending ?? [] as $name) {
            $this->line('migration: '.$name);
        }

        return match (true) {
            $running === false => self::FAILURE,
            $running === null => self::UNKNOWN_QUEUE,
            $running !== [] => self::BUSY,
            default => self::IDLE,
        };
    }
}
