<?php

namespace App\Support;

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
 *     وحجزٌ أقدمُ من `retry_after` تركه عاملٌ مات: لا يجري، فلا يُنتظر.
 *   - **الهجرةُ المعلّقة:** كودُ القرص ينتظرها، والمهامُّ المجدولة لا تجري عليه
 *     حتى تجري (`DeployHold`).
 *   - **النشرُ الجاري:** قفلُ النشر (`flock`) في مجلّد حسابه، يُسأل بقفلٍ مشتركٍ لا
 *     ينتظر — فلا يُمسك شيئًا، ولا يقف نشرٌ خلفه.
 */
final class DeployState
{
    /** الطابوران اللذان يوقفهما النشرُ ويُطلقهما — طابورا `abaad-queue.service` */
    public const QUEUES = ['whatsapp', 'default'];

    /**
     * المهامُّ الجارية الآن على الطابورين — أو null إن لم يكن الطابورُ في القاعدة فلا يُعرف.
     *
     * @return list<array{id: int, queue: string, job: string, seconds: int, attempts: int}>|null
     */
    public static function runningJobs(): ?array
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.$connection.driver") !== 'database') {
            return null;
        }

        return self::reserved($connection)
            ->filter(fn ($row) => self::age($row) <= self::retryAfter($connection))
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'queue' => (string) $row->queue,
                'job' => self::name((string) $row->payload),
                'seconds' => self::age($row),
                'attempts' => (int) $row->attempts,
            ])->values()->all();
    }

    /** حجوزٌ تركها عاملٌ مات — أقدمُ من `retry_after`، فلا تجري ولا تُنتظر */
    public static function abandonedReservations(): int
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.$connection.driver") !== 'database') {
            return 0;
        }

        return self::reserved($connection)->filter(fn ($row) => self::age($row) > self::retryAfter($connection))->count();
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

    /** اسمُ المهمّة من حمولتها — `displayName` وحده، لا بياناتُها */
    private static function name(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? (string) ($decoded['displayName'] ?? $decoded['job'] ?? '?') : '?';
    }
}
