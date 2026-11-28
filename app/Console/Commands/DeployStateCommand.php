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
 *   tasks: <عدد المهامّ المجدولة الجارية، أو ?>
 *   task: <رقم العمليّة> <الأمر> · <ثوانٍ>
 *   worker: <رقم العمليّة> started <ثانية البدء>
 *   paused: <الطابوران الموقوفان، أو -، أو ?>
 *   pending-migrations: <عدد الهجرات على القرص التي لم تجرِ، أو ?>
 *   migration: <اسمها>
 *
 * وكلُّ سطرٍ يبدأ باسمه (`job:`، `migration:`) لا بمسافات: Laravel يطوي المسافاتِ في أوّل السطر.
 *
 * والخروجُ: 0 لا شيءَ يجري، 3 مهمّةُ طابورٍ أو مهمّةٌ مجدولةٌ تجري، 2 لا يُعرف (الطابورُ ليس
 * في القاعدة، أو عمليّاتُ الخادم لا تُقرأ)، 1 خطأ. والنشرُ لا يمضي على 2 ولا 1 بعد تركيب صلاحياته.
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

        try {
            $tasks = DeployState::runningTasks();
        } catch (Throwable $e) {
            $this->line('error: '.class_basename($e));
            $tasks = false;
        }
        $this->line('tasks: '.(is_array($tasks) ? count($tasks) : '?'));
        foreach (is_array($tasks) ? $tasks : [] as $task) {
            $this->line(sprintf('task: %d %s · %ds', $task['pid'], $task['command'], $task['seconds']));
        }

        foreach (DeployState::workers() ?? [] as $worker) {
            $this->line(sprintf('worker: %d started %d', $worker['pid'], $worker['started']));
        }

        $paused = DeployState::pausedQueues();
        $this->line('paused: '.($paused === null ? '?' : ($paused === [] ? '-' : implode(',', $paused))));

        $pending = DeployState::pendingMigrations();
        $this->line('pending-migrations: '.($pending === null ? '?' : count($pending)));
        foreach ($pending ?? [] as $name) {
            $this->line('migration: '.$name);
        }

        return match (true) {
            $running === false, $tasks === false => self::FAILURE,
            $running === null, $tasks === null => self::UNKNOWN_QUEUE,
            $running !== [], $tasks !== [] => self::BUSY,
            default => self::IDLE,
        };
    }
}
