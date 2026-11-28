<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ما يسأله النشرُ عن التطبيق — قراءةً وحدها: أتجري مهمّةٌ الآن؟ وأيُّ هجرةٍ لم تجرِ؟
 *
 * ═══ لماذا من التطبيق ═══
 *
 * حسابُ النشر لا يقرأ القاعدة، ولا يشغّل التطبيق بنفسه (`scripts/deploy.sh`).
 * والجوابان في القاعدة: المهمّةُ الجارية صفٌّ محجوزٌ في `jobs` (مشغّلُ الطابور
 * database)، والهجرةُ التي لم تجرِ ملفٌّ على القرص لا اسمَ له في `migrations`.
 * فيسألهما بأمرٍ واحدٍ بحساب www-data (`abaad:deploy-state`)، مثبَّتٍ في sudoers
 * بلا وسائط، يقرأ ولا يكتب.
 *
 *   - **المهمّةُ الجارية:** بعد `queue:pause` لا يحجز العاملُ جديدًا، والمحجوزةُ
 *     تُكمل. فينتظر النشرُ حتى لا يبقى محجوزٌ — لا ثوانيَ معدودة — ثمّ يبدّل الكود.
 *     وحجزٌ جاوز مهلةَ مهمّته، أو لا عاملَ حيًّا بدأ قبله، تركه عاملٌ مات: لا يُنتظر.
 *   - **المهامُّ المجدولة الجارية:** `.deploy-hold` يمنع ما يبدأ بعده لا ما بدأ قبله — فتُنتظر.
 *   - **الهجرةُ المعلّقة:** كودُ القرص ينتظرها، والمهامُّ المجدولة لا تجري عليه
 *     حتى تجري (`DeployHold`).
 *   - **النشرُ الجاري:** قفلُ النشر (`flock`) في مجلّد حسابه، يُسأل بقفلٍ مشتركٍ لا
 *     ينتظر — فلا يُمسك شيئًا، ولا يقف نشرٌ خلفه.
 */
final class DeployState
{
    /** الطابوران اللذان يوقفهما النشرُ ويُطلقهما — طابورا `abaad-queue.service` */
    public const QUEUES = ['whatsapp', 'default'];

    /** مهلةُ العامل لمهمّةٍ لا تعلن مهلتها — `queue:work` بلا `--timeout` (وحدةُ الإنتاج كذلك) */
    public const WORKER_TIMEOUT = 60;

    /** هامشٌ فوق المهلة: العاملُ يقتل المهمّةَ عند مهلتها، والحجزُ يبقى لحظةً بعدها */
    public const GRACE = 30;

    /** أوامرُ المجدول نفسُه — تجري وفيها مهمّةٌ مجدولة، أو تُنهي مهمّةً جرت في الخلفيّة */
    private const SCHEDULER = ['schedule:run', 'schedule:finish', 'schedule:work'];

    /** @var list<array{pid: int, started: int, argv: list<string>}>|false|null بديلُ الاختبار: false = لا يُعرف */
    private static array|false|null $fakeProcesses = null;

    /**
     * المهامُّ الجارية الآن على الطابورين — أو null إن لم يكن الطابورُ في القاعدة فلا يُعرف.
     *
     * ═══ متى يجري الحجز ═══
     *
     * كان الحدُّ `retry_after` (90 ثانية) لكلّ مهمّة: ما حُجز قبلها تركه عاملٌ مات. لكنّ
     * مهمّةً تعلن مهلةَ ساعة (`BuildBusinessArchive`، `PurgeBusiness`) تجري بعد 90 ثانيةً
     * حقًّا — فكان النشرُ يحسبها متروكةً ويبدّل الكودَ تحتها. فصار:
     *   - **حدُّ كلّ حجزٍ مهلتُه هو** من حمولته (`timeout`)، وإلّا مهلةُ العامل — والعاملُ
     *     يقتل المهمّةَ عندها، فما بعدها لا يجري. ومهلةٌ صفرٌ بلا حدّ: تجري ما دام عاملُها حيًّا.
     *   - **وعاملُها حيّ:** إن عُرفت عمليّاتُ الخادم فحجزٌ لا عاملَ حيًّا بدأ قبله تركه عاملٌ
     *     مات — أعاده systemd بعده. ولا يُعدّ جاريًا مهما صغُر.
     *
     * @return list<array{id: int, queue: string, job: string, seconds: int, attempts: int}>|null
     */
    public static function runningJobs(): ?array
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.$connection.driver") !== 'database') {
            return null;
        }
        $workers = self::workerStarts();

        return self::reserved($connection)
            ->filter(fn ($row) => self::live($row, $connection, $workers))
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'queue' => (string) $row->queue,
                'job' => self::name((string) $row->payload),
                'seconds' => self::age($row),
                'attempts' => (int) $row->attempts,
            ])->values()->all();
    }

    /** حجوزٌ تركها عاملٌ مات — جاوزت مهلتَها، أو لا عاملَ حيًّا بدأ قبلها. لا تجري ولا تُنتظر */
    public static function abandonedReservations(): int
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.$connection.driver") !== 'database') {
            return 0;
        }
        $workers = self::workerStarts();

        return self::reserved($connection)->reject(fn ($row) => self::live($row, $connection, $workers))->count();
    }

    /**
     * المهامُّ المجدولة الجارية الآن — أو null إن لم تُقرأ عمليّاتُ الخادم.
     *
     * `.deploy-hold` يمنع ما يبدأ بعده، لا ما بدأ قبله: نسخةٌ احتياطيّةٌ بدأت في 02:00
     * تجري دقائقَ والكودُ يتبدّل تحتها. فينتظرها النشرُ كما ينتظر مهمّةَ الطابور.
     * تُعرف من عمليّاتها: `artisan schedule:run` (تجري فيه المهمّة)، أو عمليّةُ أمرٍ مجدولٍ
     * باسمه (ما يجري في الخلفيّة، أو ما شغّله أحدٌ بيده). ولا يُحسب العاملُ ولا هذا السؤال.
     *
     * @return list<array{pid: int, command: string, seconds: int}>|null
     */
    public static function runningTasks(): ?array
    {
        $processes = self::processes();
        if ($processes === null) {
            return null;
        }
        $scheduled = array_merge(self::SCHEDULER, self::scheduledCommands());
        $self = getmypid();

        return collect($processes)
            ->filter(fn (array $p) => $p['pid'] !== $self && in_array(self::artisanCommand($p['argv']), $scheduled, true))
            ->map(fn (array $p) => ['pid' => $p['pid'], 'command' => (string) self::artisanCommand($p['argv']), 'seconds' => max(0, time() - $p['started'])])
            ->sortBy('pid')->values()->all();
    }

    /**
     * عمّالُ الطابور الأحياء الآن: رقمُ العمليّة ومتى بدأت — أو null إن لم تُقرأ العمليّات.
     *
     * @return list<array{pid: int, started: int}>|null
     */
    public static function workers(): ?array
    {
        $processes = self::processes();
        if ($processes === null) {
            return null;
        }

        return collect($processes)
            ->filter(fn (array $p) => in_array(self::artisanCommand($p['argv']), ['queue:work', 'queue:listen'], true))
            ->map(fn (array $p) => ['pid' => $p['pid'], 'started' => $p['started']])
            ->sortBy('pid')->values()->all();
    }

    /**
     * الطابوران الموقوفان الآن (`queue:pause`) — أو null إن تعذّر السؤال.
     *
     * يُسأل قبل أن يوقفهما النشر: طابورٌ كان موقوفًا قبله — تركه نشرٌ سقط وكودُه لم يكتمل —
     * لا يُطلقه سقوطُ هذا النشر. يُطلقه نشرٌ يكتمل أو رجوعٌ يكتمل.
     *
     * @return list<string>|null
     */
    public static function pausedQueues(): ?array
    {
        try {
            $connection = (string) config('queue.default');
            $manager = app('queue');

            return array_values(array_filter(self::QUEUES, fn (string $q) => $manager->isPaused($connection, $q)));
        } catch (Throwable) {
            return null;
        }
    }

    /** للاختبار: عمليّاتٌ مزروعة بدل /proc — أو false «لا تُقرأ»، أو null للحقيقيّة */
    public static function fakeProcesses(array|false|null $processes): void
    {
        self::$fakeProcesses = $processes;
    }

    /**
     * هجراتٌ على القرص لم تجرِ على القاعدة — أو null إن تعذّر السؤال (فلا يُفترض أنّها لا شيء).
     *
     * @return list<string>|null
     */
    public static function pendingMigrations(): ?array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return null;
            }
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * أيجري نشرٌ أو رجوعٌ الآن؟ — true أو false، أو null إن لم يُقرأ قفلُه.
     *
     * قفلٌ مشتركٌ لا ينتظر (`LOCK_SH|LOCK_NB`) يُفلت في الحال: لا يُمسك شيئًا يقف
     * خلفه نشر. والسكربتان ينتظران قفلَهما ثوانيَ (`flock -w`) لا لحظة، فلا يردّهما
     * هذا السؤالُ إن وافق بدءَهما.
     */
    public static function deployRunning(): ?bool
    {
        $path = (string) config('app.deploy_lock_file');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            $wouldBlock = 0;
            if (flock($handle, LOCK_SH | LOCK_NB, $wouldBlock)) {
                flock($handle, LOCK_UN);

                return false;
            }

            return $wouldBlock ? true : null;
        } finally {
            fclose($handle);
        }
    }

    private static function reserved(string $connection)
    {
        return DB::connection(config("queue.connections.$connection.connection"))
            ->table((string) config("queue.connections.$connection.table", 'jobs'))
            ->whereIn('queue', self::QUEUES)
            ->whereNotNull('reserved_at')
            ->orderBy('id')
            ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at']);
    }

    private static function age(object $row): int
    {
        return max(0, time() - (int) $row->reserved_at);
    }

    private static function retryAfter(string $connection): int
    {
        return (int) config("queue.connections.$connection.retry_after", 90);
    }

    /**
     * أيجري هذا الحجز؟ — في مهلته، وعاملُه حيّ.
     *
     * @param  list<int>|null  $workers  متى بدأ كلُّ عاملٍ حيّ، أو null إن لم يُعرف
     */
    private static function live(object $row, string $connection, ?array $workers): bool
    {
        if ($workers !== null) {
            // ثانيتان هامشًا: وقتُ بدء العمليّة من النواة بالثانية، ووقتُ الحجز كذلك
            $holder = array_filter($workers, fn (int $started) => $started <= (int) $row->reserved_at + 2);
            if ($holder === []) {
                return false;
            }
        }

        $timeout = self::timeout((string) $row->payload);
        if ($timeout === 0) {
            return true;
        }

        return self::age($row) <= max(self::retryAfter($connection), $timeout ?? self::WORKER_TIMEOUT) + self::GRACE;
    }

    /** مهلةُ المهمّة كما في حمولتها — null إن لم تعلنها (فمهلةُ العامل)، و0 بلا حدّ */
    private static function timeout(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $timeout = is_array($decoded) ? ($decoded['timeout'] ?? null) : null;

        return is_numeric($timeout) ? (int) $timeout : null;
    }

    /** @return list<int>|null */
    private static function workerStarts(): ?array
    {
        $workers = self::workers();

        return $workers === null ? null : array_column($workers, 'started');
    }

    /** اسمُ أمر artisan في سطر العمليّة — ما بعد `artisan`، أو null إن لم يكن أمرَ artisan */
    private static function artisanCommand(array $argv): ?string
    {
        $argv = array_values($argv);
        foreach ($argv as $i => $arg) {
            if ($arg === 'artisan' || str_ends_with((string) $arg, '/artisan')) {
                return isset($argv[$i + 1]) ? (string) $argv[$i + 1] : null;
            }
        }

        return null;
    }

    /** @return list<string> أسماءُ الأوامر المجدولة كما في routes/console.php */
    private static function scheduledCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => preg_match("/artisan'?\\s+'?([\\w:-]+)/", (string) ($event->command ?? ''), $m) ? $m[1] : null)
            ->filter()->unique()->values()->all();
    }

    /**
     * عمليّاتُ artisan على الخادم كما تقولها النواة (/proc) — أو null إن لم تُقرأ.
     *
     * بلا shell: سطرُ كلّ عمليّة من `cmdline`، وبدؤها من `stat` (الحقلُ 22، بدقّات الساعة
     * منذ الإقلاع — 100 في الثانية لبرامج المستخدم على لينكس) و`btime` من /proc/stat.
     *
     * @return list<array{pid: int, started: int, argv: list<string>}>|null
     */
    private static function processes(): ?array
    {
        if (self::$fakeProcesses !== null) {
            return self::$fakeProcesses === false ? null : self::$fakeProcesses;
        }
        $stat = @file_get_contents('/proc/stat');
        if ($stat === false || ! preg_match('/^btime (\d+)$/m', $stat, $boot)) {
            return null;
        }

        $out = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $cmdline = @file_get_contents($dir.'/cmdline');
            if ($cmdline === false || ! str_contains($cmdline, 'artisan')) {
                continue;
            }
            $raw = @file_get_contents($dir.'/stat');
            $close = $raw === false ? false : strrpos($raw, ')');
            if ($close === false) {
                continue;
            }
            // ما بعد «) » يبدأ بالحقل الثالث، فالحقلُ 22 (starttime) في الموضع 19
            $fields = explode(' ', substr($raw, $close + 2));
            if (! isset($fields[19])) {
                continue;
            }
            $out[] = [
                'pid' => (int) basename($dir),
                'started' => (int) $boot[1] + intdiv((int) $fields[19], 100),
                'argv' => explode("\0", rtrim($cmdline, "\0")),
            ];
        }

        return $out;
    }

    /** اسمُ المهمّة من حمولتها — `displayName` وحده، لا بياناتُها */
    private static function name(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? (string) ($decoded['displayName'] ?? $decoded['job'] ?? '?') : '?';
    }
}
