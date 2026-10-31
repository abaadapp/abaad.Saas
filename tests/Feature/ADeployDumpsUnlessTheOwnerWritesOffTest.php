<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نسخةُ القاعدة قبل النشر — تُطفأ بسطرٍ يكتبه المالكُ على الخادم، ولا يُطفأ معها شيءٌ آخر.
 *
 * ═══ كيف يُختبر سكربتُ نشرٍ بلا خادم ═══
 *
 * `scripts/deploy.sh` نفسُه يجري في مجلّدٍ مؤقّت: مستودعٌ حقيقيّ ومنبعُه، وgit
 * الحقيقيّ يسحب منه. وما يمسّ الخادم بدائلُ تكتب ما طُلب منها في سجلّ:
 * pg_dump وcomposer وnpm وphp وsystemctl وcurl. والسطرُ الوحيد الذي يتغيّر في
 * نسخة الاختبار فحصُ root — يُتحقَّق أنّه سطرٌ واحدٌ بعينه قبل أن يُعطَّل. ونسخةُ
 * الاختبار تجري بـ`DEPLOY_DETACHED=1` المكتوبةِ في السكربت: هي خارج المستودع، فلا
 * يمسّها السحب.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بلا إعداد، أو `on`: النسخة أوّلًا كما كانت.
 *   - `off`: لا pg_dump ولا ملفّ نسخة — ويُقال في السجلّ وفي ROLLBACK.txt، وتُسمّى
 *     الهجرات — وكلُّ خطوةٍ أخرى تجري بترتيبها، وسطرُ «تمّ:» الذي يقرؤه Deploy Main.
 *   - قيمةٌ غيرُهما، أو سطران، أو ملفٌّ يكتبه غيرُ مالكه: يتوقّف قبل أن يتحرّك الكود.
 *   - وفشلُ النسخة أو الهجرة أو الصحّة يوقف النشر حيث وقع، ولا يُعيد تحميل PHP بعد هجرةٍ سقطت.
 */
class ADeployDumpsUnlessTheOwnerWritesOffTest extends TestCase
{
    private const ROOT_CHECK = '[[ $EUID -eq 0 ]] || die "شغّله بـsudo: يحتاج التبديل إلى $APP_USER وإعادة تحميل php-fpm"';

    private string $root = '';

    private string $from = '';

    private string $to = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }
        parent::tearDown();
    }

    /* ═══ المختبر ═══ */

    private function sh(string $command, string $in): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($in).' && '.$command.' 2>&1'));
    }

    private function git(string $command, string $in): string
    {
        return $this->sh('git -c user.name=t -c user.email=t@t -c init.defaultBranch=main '.$command, $in);
    }

    /** مستودعٌ ومنبعُه: الخادمُ على A، ومنبعُه على B وفيه هجرةٌ جديدة */
    private function server(): void
    {
        $this->root = sys_get_temp_dir().'/abaad-deploy-test-'.bin2hex(random_bytes(4));
        foreach (['bin', 'home', 'seed/database/migrations'] as $dir) {
            mkdir($this->root.'/'.$dir, 0700, true);
        }

        $this->git('init -q --bare origin.git', $this->root);
        $seed = $this->root.'/seed';
        file_put_contents($seed.'/app.txt', "a\n");
        file_put_contents($seed.'/database/migrations/2026_01_01_000000_a.php', "<?php\n");
        $this->git('init -q', $seed);
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm a', $seed);
        $this->git('remote add origin '.escapeshellarg($this->root.'/origin.git'), $seed);
        $this->git('push -q origin HEAD:main', $seed);

        $this->git('clone -q '.escapeshellarg($this->root.'/origin.git').' app', $this->root);
        file_put_contents($this->root.'/app/.env', "DB_CONNECTION=pgsql\nDB_DATABASE=abaad\n");
        $this->from = $this->git('rev-parse --short HEAD', $this->root.'/app');

        file_put_contents($seed.'/database/migrations/2026_02_01_000000_b.php', "<?php\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm b', $seed);
        $this->git('push -q origin HEAD:main', $seed);
        $this->to = $this->git('rev-parse --short HEAD', $seed);

        $log = 'echo "$(basename "$0") $*" >> "$CALLS"';
        $stubs = [
            'sudo' => '[ "$1" = "-u" ] && shift 2; exec "$@"',
            'pg_dump' => $log.'; [ "${FAIL_DUMP:-}" = 1 ] && exit 1; echo dumped',
            'composer' => $log,
            'npm' => $log.'; case "$*" in *"run build"*) for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *tinker*) echo pgsql;; *"migrate --force"*) [ "${FAIL_MIGRATE:-}" = 1 ] && { echo "SQLSTATE: boom"; exit 1; }; echo migrated;; esac',
            'systemctl' => $log,
            'curl' => $log.'; printf "%s" "${HEALTH_CODE:-200}"',
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->root.'/bin/'.$name, "#!/usr/bin/env bash\n".$body."\n");
            chmod($this->root.'/bin/'.$name, 0700);
        }

        // نسخةُ الاختبار: فحصُ root وحده يُعطَّل — والباقي حرفًا بحرف
        $script = file_get_contents(base_path('scripts/deploy.sh'));
        $this->assertSame(1, substr_count($script, self::ROOT_CHECK), 'تغيّر فحصُ root — راجع المختبر');
        file_put_contents($this->root.'/deploy.sh', str_replace(self::ROOT_CHECK, ': # فحصُ root مُعطَّلٌ في المختبر وحده', $script));
    }

    private function conf(string $text, int $mode = 0600): void
    {
        file_put_contents($this->root.'/deploy.conf', $text);
        chmod($this->root.'/deploy.conf', $mode);
    }

    /** @return array{rc: int, out: string, calls: list<string>, head: string} */
    private function deploy(array $env = []): array
    {
        $proc = proc_open(['bash', $this->root.'/deploy.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HOME' => $this->root.'/home',
            'APP_DIR' => $this->root.'/app',
            'APP_USER' => (string) get_current_user(),
            'BACKUP_DIR' => $this->root.'/pre-deploy',
            'DEPLOY_CONF' => $this->root.'/deploy.conf',
            'HEALTH_URL' => 'http://health.test',
            'CALLS' => $this->root.'/calls.log',
            'GIT_CONFIG_NOSYSTEM' => '1',
            // السكربتُ ينسخ نفسَه إلى /tmp قبل السحب — ونسخةُ المختبر خارجَ المستودع أصلًا، فلا يمسّها السحب
            'DEPLOY_DETACHED' => '1',
        ]);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $rc = proc_close($proc);

        return [
            'rc' => $rc,
            'out' => (string) preg_replace('/\e\[[0-9;]*m/', '', $out),
            'calls' => is_file($this->root.'/calls.log') ? file($this->root.'/calls.log', FILE_IGNORE_NEW_LINES) : [],
            'head' => $this->git('rev-parse --short HEAD', $this->root.'/app'),
        ];
    }

    /** موضعُ أوّل نداءٍ يبدأ بهذا — أو -1 */
    private function at(array $calls, string $prefix): int
    {
        foreach ($calls as $i => $call) {
            if (str_starts_with($call, $prefix)) {
                return $i;
            }
        }

        return -1;
    }

    private function dumps(): array
    {
        return glob($this->root.'/pre-deploy/*.dump') ?: [];
    }

    /** كلُّ خطوةٍ بعد النسخة جرت، بترتيبها، والكودُ على B */
    private function assertDeployedInOrder(array $run): void
    {
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertSame($this->to, $run['head'], 'لم يُسحب الكود');

        $order = ['composer install', 'npm ci', 'npm run build', 'php artisan migrate --force', 'php artisan config:cache',
            'php artisan route:cache', 'php artisan view:cache', 'systemctl reload', 'php artisan abaad:preflight', 'curl'];
        $positions = array_map(fn ($step) => $this->at($run['calls'], $step), $order);
        foreach ($order as $i => $step) {
            $this->assertGreaterThanOrEqual(0, $positions[$i], "لم تجرِ: $step");
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'تبدّل ترتيبُ الخطوات');

        // السطرُ الذي يقرأ منه Deploy Main البصمةَ التي تعمل
        $this->assertMatchesRegularExpression('/تمّ: '.$this->from.' → '.$this->to.' /u', $run['out']);
    }

    /* ═══ ١ · النسخةُ كما كانت ═══ */

    public function test_without_a_setting_or_with_on_it_dumps_before_anything_moves(): void
    {
        $this->server();
        foreach ([null, "PRE_DEPLOY_BACKUP=on\n"] as $i => $setting) {
            if ($i === 1) {
                // نشرٌ ثانٍ من A — بإعدادٍ صريح
                $this->git('reset -q --hard '.$this->from, $this->root.'/app');
                @unlink($this->root.'/calls.log');
                $this->conf($setting);
            }
            $run = $this->deploy();

            $this->assertDeployedInOrder($run);
            $this->assertCount($i + 1, $this->dumps(), 'لا ملفَّ نسخة');
            $dump = $this->at($run['calls'], 'pg_dump');
            $this->assertGreaterThanOrEqual(0, $dump, 'لم تجرِ النسخة');
            $this->assertLessThan($this->at($run['calls'], 'composer install'), $dump, 'جرت خطوةٌ قبل النسخة');
            $this->assertStringNotContainsString('لا نسخة', $run['out']);
            $this->assertStringContainsString('.dump', (string) file_get_contents($this->root.'/pre-deploy/ROLLBACK.txt'));
        }
    }

    /* ═══ ٢ · off: لا نسخة — والباقي كلُّه ═══ */

    public function test_off_skips_only_the_dump_and_says_so_in_the_log_and_the_rollback_note(): void
    {
        $this->server();
        $this->conf("# قرار المالك\nOTHER=1\nPRE_DEPLOY_BACKUP = off \n");

        $run = $this->deploy();

        $this->assertDeployedInOrder($run);
        $this->assertSame(-1, $this->at($run['calls'], 'pg_dump'), 'جرت النسخةُ وهي مُطفأة');
        $this->assertSame([], $this->dumps());
        $this->assertStringNotContainsString('du:', $run['out'], 'قيس حجمُ نسخةٍ لم تُكتب');
        $this->assertStringContainsString('لا نسخة لهذا النشر: PRE_DEPLOY_BACKUP=off في '.$this->root.'/deploy.conf', $run['out']);
        $this->assertStringContainsString("هجراتٌ تجري بلا نسخة:\n    database/migrations/2026_02_01_000000_b.php", $run['out']);
        $this->assertStringContainsString('نُشر بلا نسخة: PRE_DEPLOY_BACKUP=off', $run['out']);

        $note = (string) file_get_contents($this->root.'/pre-deploy/ROLLBACK.txt');
        $this->assertStringContainsString('النسخة: لا نسخة لهذا النشر (PRE_DEPLOY_BACKUP=off', $note);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $note);
    }

    /* ═══ ٣ · إعدادٌ لا يُفهم: يتوقّف قبل أي لمسة ═══ */

    public function test_a_setting_that_is_not_plainly_on_or_off_stops_before_the_code_moves(): void
    {
        $this->server();
        $cases = [
            ["PRE_DEPLOY_BACKUP=of\n", 0600, 'on أو off وحدهما'],
            ["PRE_DEPLOY_BACKUP=OFF\n", 0600, 'on أو off وحدهما'],
            ["PRE_DEPLOY_BACKUP=\n", 0600, 'on أو off وحدهما'],
            ["PRE_DEPLOY_BACKUP=off\nPRE_DEPLOY_BACKUP=on\n", 0600, 'مرّات'],
            ["PRE_DEPLOY_BACKUP=off\n", 0664, 'يكتبه غيرُ مالكه'],
        ];

        foreach ($cases as [$text, $mode, $says]) {
            $this->conf($text, $mode);
            @unlink($this->root.'/calls.log');

            $run = $this->deploy();

            $this->assertNotSame(0, $run['rc'], "مضى النشر بإعداد: $text");
            $this->assertStringContainsString($says, $run['out']);
            $this->assertSame($this->from, $run['head'], 'تحرّك الكودُ قبل أن يُفهم الإعداد');
            $this->assertSame([], $run['calls'], 'جرت خطوةٌ قبل أن يُفهم الإعداد');
            $this->assertSame([], $this->dumps());
        }
    }

    /* ═══ ٤ · الفشلُ يوقفه حيث وقع ═══ */

    public function test_a_failed_dump_stops_before_the_code_moves(): void
    {
        $this->server();

        $run = $this->deploy(['FAIL_DUMP' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertSame($this->from, $run['head']);
        $this->assertSame(-1, $this->at($run['calls'], 'composer'));
    }

    public function test_a_failed_migration_without_a_dump_stops_before_the_caches_and_the_reload(): void
    {
        $this->server();
        $this->conf("PRE_DEPLOY_BACKUP=off\n");

        $run = $this->deploy(['FAIL_MIGRATE' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertGreaterThanOrEqual(0, $this->at($run['calls'], 'php artisan migrate --force'));
        // والكودُ الحيّ لم يُبدَّل: لا ذاكرةَ مؤقّتة ولا إعادةَ تحميلٍ لـPHP بعد هجرةٍ سقطت
        $this->assertSame(-1, $this->at($run['calls'], 'php artisan config:cache'));
        $this->assertSame(-1, $this->at($run['calls'], 'systemctl'));
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }

    public function test_a_failed_health_check_without_a_dump_points_to_the_code_rollback_and_names_no_dump(): void
    {
        $this->server();
        $this->conf("PRE_DEPLOY_BACKUP=off\n");

        $run = $this->deploy(['HEALTH_CODE' => '502']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertStringContainsString('الموقع يردّ 502', $run['out']);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $run['out']);
        $this->assertStringContainsString('ولا نسخةَ لهذا النشر (PRE_DEPLOY_BACKUP=off)', $run['out']);
        $this->assertStringNotContainsString('unbound variable', $run['out']);
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }
}
