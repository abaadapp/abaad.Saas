<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * النشرُ بلا نسخةٍ للقاعدة قبله — والهجراتُ تجري كما كانت، ويُسمّى ما جرى منها.
 *
 * قرارُ المالك (2026-10-31): لا `pg_dump` قبل النشر، ولا تكون النسخةُ شرطًا للهجرات.
 * فما يُحرس هنا هو ما بقي: كلُّ خطوةٍ بترتيبها، والتوقّفُ عند أوّل خطأ، والرجوعُ
 * بالكود وحده — وأنّ السجلّ يقول ما جرى وما لا يُرجَع.
 *
 * ═══ كيف يُختبر سكربتُ نشرٍ بلا خادم ═══
 *
 * `scripts/deploy.sh` نفسُه يجري في مجلّدٍ مؤقّت: مستودعٌ حقيقيّ ومنبعُه، وgit
 * الحقيقيّ يسحب منه. وما يمسّ الخادم بدائلُ تكتب ما طُلب منها في سجلّ:
 * pg_dump وcomposer وnpm وphp وsystemctl وcurl. و`migrate` البديلُ يطبع ما يطبعه
 * Laravel حرفيًّا (` <الهجرة> .. 2.34ms DONE`). والسطرُ الوحيد الذي يتغيّر في نسخة
 * الاختبار فحصُ root — يُتحقَّق أنّه سطرٌ واحدٌ بعينه قبل أن يُعطَّل. وتجري
 * بـ`DEPLOY_DETACHED=1` المكتوبةِ في السكربت: هي خارج المستودع، فلا يمسّها السحب.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا pg_dump ولا ملفّ نسخة، ولا إعدادَ يُعيدها؛ والنسخُ القديمة في المجلّد لا تُمسّ.
 *   - كلُّ خطوةٍ بترتيبها، وسطرُ «تمّ:» الذي يقرؤه Deploy Main.
 *   - الهجراتُ تُسمّى في السجلّ بحالها، ومعها أنّ ما تحذفه لا يُرجَع وأنّ CI لا تضمنها.
 *     وخرجُها كاملًا في ملفٍّ لا يقرؤه غيرُ مالكه — وخطأُ القاعدة لا يصل السجلَّ العامّ.
 *   - وفشلُ خطوةٍ أو هجرةٍ أو فحصِ الصحّة يوقف النشر حيث وقع، ويدلّ على الرجوع بالكود.
 */
class ADeployMigratesWithoutADumpTest extends TestCase
{
    private const ROOT_CHECK = '[[ $EUID -eq 0 ]] || die "شغّله بـsudo: يحتاج التبديل إلى $APP_USER وإعادة تحميل php-fpm"';

    private const B = '2026_02_01_000000_b';

    private const SECRET = 'customer-secret@x.om';

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
        foreach (['bin', 'home', 'pre-deploy', 'seed/database/migrations'] as $dir) {
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

        file_put_contents($seed.'/database/migrations/'.self::B.'.php', "<?php\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm b', $seed);
        $this->git('push -q origin HEAD:main', $seed);
        $this->to = $this->git('rev-parse --short HEAD', $seed);

        // نسخةٌ من قبل القرار: تبقى كما هي
        file_put_contents($this->root.'/pre-deploy/abaad-20261029-033620.dump', 'old dump');

        $log = 'echo "$(basename "$0") $*" >> "$CALLS"';
        // خرجُ `php artisan migrate` كما يطبعه Laravel
        $migrate = 'if [ "${FAIL_MIGRATE:-}" = 1 ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 0.08ms FAIL\n\n   Illuminate\\\\Database\\\\QueryException\n\n  SQLSTATE[23505]: Key (email)=('.self::SECRET.')\n"; exit 1; fi; '
            .'case "${MIGRATE_SAYS:-ran}" in nothing) printf "\n INFO Nothing to migrate.\n";; garbled) printf "ok\n";; '
            .'*) printf "\n INFO Running migrations.\n\n '.self::B.' .. 2.34ms DONE\n";; esac';
        $stubs = [
            'sudo' => '[ "$1" = "-u" ] && shift 2; exec "$@"',
            'pg_dump' => $log.'; echo dumped',
            'composer' => $log.'; [ "${FAIL_COMPOSER:-}" = 1 ] && exit 1; true',
            'npm' => $log.'; case "$*" in *"run build"*) for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *"migrate --force"*) '.$migrate.';; esac',
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

    /** @return array{rc: int, out: string, calls: list<string>, head: string} */
    private function deploy(array $env = []): array
    {
        $proc = proc_open(['bash', $this->root.'/deploy.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HOME' => $this->root.'/home',
            'APP_DIR' => $this->root.'/app',
            'APP_USER' => (string) get_current_user(),
            'BACKUP_DIR' => $this->root.'/pre-deploy',
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

    /** خرجُ migrate كاملًا كما حُفظ على الخادم */
    private function migrateLog(): string
    {
        $logs = glob($this->root.'/pre-deploy/migrate-*.log') ?: [];
        $this->assertCount(1, $logs, 'لم يُحفظ خرجُ migrate');
        $this->assertSame(0600, fileperms($logs[0]) & 0777, 'خرجُ migrate يقرؤه غيرُ مالكه');

        return (string) file_get_contents($logs[0]);
    }

    /** ما يُنفَّذ أو يُطبع — بلا أسطر التعليق */
    private function code(): string
    {
        $lines = file(base_path('scripts/deploy.sh'), FILE_IGNORE_NEW_LINES);

        return implode("\n", array_filter($lines, fn ($l) => ! str_starts_with(ltrim($l), '#')));
    }

    /* ═══ ١ · لا نسخة — وكلُّ خطوةٍ أخرى بترتيبها ═══ */

    public function test_it_takes_no_dump_and_runs_every_other_step_in_order(): void
    {
        $this->server();
        $code = $this->code();
        foreach (['pg_dump', 'PRE_DEPLOY_BACKUP', 'deploy.conf', '.dump'] as $gone) {
            $this->assertStringNotContainsString($gone, $code, "عاد إلى السكربت: $gone");
        }

        $run = $this->deploy();

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

        $this->assertSame(-1, $this->at($run['calls'], 'pg_dump'), 'جرت نسخة');
        $this->assertSame([$this->root.'/pre-deploy/abaad-20261029-033620.dump'], glob($this->root.'/pre-deploy/*.dump'), 'كُتبت نسخةٌ جديدة');
        $this->assertSame('old dump', file_get_contents($this->root.'/pre-deploy/abaad-20261029-033620.dump'), 'مُسّت نسخةٌ قديمة');

        $note = (string) file_get_contents($this->root.'/pre-deploy/ROLLBACK.txt');
        $this->assertStringContainsString('commit قبل النشر: '.$this->from, $note);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $note);
        $this->assertStringContainsString('الرجوعُ بالكود لا يُرجع ما غيّرته الهجرات', $note);
    }

    /* ═══ ٢ · الهجراتُ تُسمّى، ومعها ما لا يُرجَع ═══ */

    public function test_the_log_names_each_migration_that_ran_and_says_a_code_rollback_cannot_undo_it(): void
    {
        $this->server();

        $run = $this->deploy();

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringContainsString("\n    ".self::B."  DONE\n", $run['out']);
        $this->assertStringContainsString('جرت بلا نسخةٍ قبلها: ما حذفته هجرةٌ أو أعادت تسميتَه أو حوّلته لا يُرجعه الرجوعُ بالكود، ونجاحُ CI لا يضمن سلامتها', $run['out']);
        $this->assertStringContainsString(self::B.' .. 2.34ms DONE', $this->migrateLog());
    }

    public function test_a_deploy_without_a_migration_says_so_and_unreadable_output_is_not_taken_for_none(): void
    {
        $this->server();

        $run = $this->deploy(['MIGRATE_SAYS' => 'nothing']);
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringContainsString('لا هجرةَ جديدة', $run['out']);
        $this->assertStringNotContainsString('جرت بلا نسخةٍ قبلها', $run['out']);

        $this->git('reset -q --hard '.$this->from, $this->root.'/app');
        array_map('unlink', glob($this->root.'/pre-deploy/migrate-*.log') ?: []);
        $run = $this->deploy(['MIGRATE_SAYS' => 'garbled']);
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringNotContainsString('لا هجرةَ جديدة', $run['out']);
        $this->assertStringContainsString('لم يُقرأ من خرج migrate ما جرى', $run['out']);
    }

    /* ═══ ٣ · الفشلُ يوقفه حيث وقع ═══ */

    public function test_a_failed_migration_stops_before_the_caches_and_keeps_the_database_error_off_the_public_log(): void
    {
        $this->server();

        $run = $this->deploy(['FAIL_MIGRATE' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertStringContainsString("\n    ".self::B."  FAIL\n", $run['out']);
        $this->assertStringContainsString('سقطت الهجرات', $run['out']);
        $this->assertStringContainsString('ما جرى قبل السقوط يبقى في القاعدة، ولا نسخةَ تُرجعه', $run['out']);
        // الكودُ الحيّ لم يُبدَّل: لا ذاكرةَ مؤقّتة ولا إعادةَ تحميلٍ لـPHP بعد هجرةٍ سقطت
        $this->assertSame(-1, $this->at($run['calls'], 'php artisan config:cache'));
        $this->assertSame(-1, $this->at($run['calls'], 'systemctl'));
        $this->assertStringNotContainsString('تمّ:', $run['out']);
        // وخطأُ القاعدة على الخادم وحده: سجلُّ Deploy Main عامّ
        $this->assertStringNotContainsString(self::SECRET, $run['out']);
        $this->assertStringNotContainsString('SQLSTATE', $run['out']);
        $this->assertStringContainsString(self::SECRET, $this->migrateLog());
    }

    public function test_a_failed_step_before_the_migrations_stops_the_deploy_there(): void
    {
        $this->server();

        $run = $this->deploy(['FAIL_COMPOSER' => '1']);

        $this->assertNotSame(0, $run['rc']);
        foreach (['npm', 'php artisan migrate', 'systemctl'] as $step) {
            $this->assertSame(-1, $this->at($run['calls'], $step), "جرت $step بعد composer سقط");
        }
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }

    public function test_a_failed_health_check_points_to_the_code_rollback_and_says_the_database_stays(): void
    {
        $this->server();

        $run = $this->deploy(['HEALTH_CODE' => '502']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertStringContainsString('الموقع يردّ 502', $run['out']);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $run['out']);
        $this->assertStringContainsString('ولا نسخةَ للقاعدة قبل هذا النشر: ما غيّرته هجراتُه يبقى', $run['out']);
        $this->assertStringNotContainsString('unbound variable', $run['out']);
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }
}
