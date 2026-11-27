<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * النشرُ بحساب abaad-deploy لا root، وبلا نسخةٍ للقاعدة — والهجراتُ تجري بحساب www-data.
 *
 * قرارُ المالك (2026-10-31): لا `pg_dump`، ولا نسخةَ شرطًا للهجرات. وفصلُ الصلاحيّات
 * (المرحلة 1): النشرُ بحساب يملك الكود، وأوامرُ Laravel وحدها تُبدَّل إلى www-data
 * عبر sudo، وإعادةُ تحميل php-fpm وحدها بصلاحية root.
 *
 * ═══ كيف يُختبر سكربتُ نشرٍ بلا خادم ═══
 *
 * `scripts/deploy.sh` نفسُه يجري في مجلّدٍ مؤقّت: مستودعٌ حقيقيّ ومنبعُه، وgit
 * الحقيقيّ يسحب منه بحساب المختبِر (الذي يملك الشجرة، كـabaad-deploy على الخادم).
 * وما يُبدَّل إلى حسابٍ آخر يمرّ ببديلِ `sudo` الذي يسجّل الحسابَ المقصود:
 * `sudo[www-data] …` و`sudo[root] …`. وبدائلُ php وsystemctl وcomposer وnpm وcurl
 * تسجّل ما طُلب منها. و`migrate` البديلُ يطبع ما يطبعه Laravel حرفيًّا.
 *
 * ولا يُعطَّل فحصُ root: المختبِر ليس root، فالسكربتُ يمضي كما يمضي بحساب abaad-deploy.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا pg_dump ولا ملفّ نسخة، ولا إعدادَ يُعيدها.
 *   - أوامرُ Laravel كلُّها بحساب www-data عبر sudo، وsystemctl بصلاحية root وحده،
 *     وgit وcomposer وnpm بحساب النشر مباشرةً — لا sudo حولها.
 *   - composer بـ--no-scripts، ثمّ package:discover بحساب www-data بعده.
 *   - مخبأُ npm في بيت حساب النشر لا في /tmp المشترك.
 *   - الهجراتُ تُسمّى بحالها، وخرجُها في ملفٍّ لا يقرؤه غيرُ مالكه؛ وخطأُ القاعدة لا يصل السجلَّ العامّ.
 *   - وفشلُ خطوةٍ أو هجرةٍ أو فحصِ الصحّة يوقف النشر حيث وقع، ويدلّ على الرجوع بالكود.
 */
class ADeployMigratesWithoutADumpTest extends TestCase
{
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
        foreach (['bin', 'home', 'pre-deploy', 'state', 'seed/database/migrations'] as $dir) {
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

        // نسخةٌ من قبل القرار في مجلّدٍ لا يعرفه السكربت: دليلٌ أنّ القديم لا يُمسّ
        file_put_contents($this->root.'/pre-deploy/abaad-20261029-033620.dump', 'old dump');

        $log = 'echo "$(basename "$0") $*" >> "$CALLS"';
        // بديلُ sudo يسجّل الحسابَ المقصود ثمّ ينفّذ — فيُعرف أنّ artisan جرى بحساب www-data وsystemctl بصلاحية root
        $sudo = 'u=root; while [ $# -gt 0 ]; do case "$1" in -n) shift;; -u) u="$2"; shift 2;; *) break;; esac; done; '
            .'echo "sudo[$u] $*" >> "$CALLS"; exec "$@"';
        // خرجُ `php artisan migrate` كما يطبعه Laravel
        $migrate = 'if [ "${FAIL_MIGRATE:-}" = 1 ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 0.08ms FAIL\n\n   Illuminate\\\\Database\\\\QueryException\n\n  SQLSTATE[23505]: Key (email)=('.self::SECRET.')\n"; exit 1; fi; '
            .'case "${MIGRATE_SAYS:-ran}" in nothing) printf "\n INFO Nothing to migrate.\n";; garbled) printf "ok\n";; '
            .'*) printf "\n INFO Running migrations.\n\n '.self::B.' .. 2.34ms DONE\n";; esac';
        $stubs = [
            'sudo' => $sudo,
            'pg_dump' => $log.'; echo dumped',
            'composer' => $log.'; [ "${FAIL_COMPOSER:-}" = 1 ] && exit 1; true',
            'npm' => $log.'; echo "npmcache ${npm_config_cache:-?}" >> "$CALLS"; case "$*" in *"run build"*) for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *"migrate --force"*) '.$migrate.';; esac',
            'systemctl' => $log,
            'curl' => $log.'; printf "%s" "${HEALTH_CODE:-200}"',
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->root.'/bin/'.$name, "#!/usr/bin/env bash\n".$body."\n");
            chmod($this->root.'/bin/'.$name, 0700);
        }

        copy(base_path('scripts/deploy.sh'), $this->root.'/deploy.sh');
    }

    /** @return array{rc: int, out: string, calls: list<string>, head: string} */
    private function deploy(array $env = []): array
    {
        $proc = proc_open(['bash', $this->root.'/deploy.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HOME' => $this->root.'/home',
            'APP_DIR' => $this->root.'/app',
            // بديلُ sudo يسجّل الحسابَ وينفّذ بلا تبديلٍ فعليّ، فيصحُّ أن يكون www-data حرفيًّا
            'APP_USER' => 'www-data',
            'STATE_DIR' => $this->root.'/state',
            'HEALTH_URL' => 'http://health.test',
            'CALLS' => $this->root.'/calls.log',
            'PHP_BIN' => 'php',
            'SYSTEMCTL' => 'systemctl',
            'GIT_CONFIG_NOSYSTEM' => '1',
            // السكربتُ ينسخ نفسَه قبل السحب — ونسخةُ المختبر خارجَ المستودع أصلًا، فلا يمسّها السحب
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

    /** موضعُ أوّل نداءٍ يحوي هذا — أو -1 */
    private function at(array $calls, string $needle): int
    {
        foreach ($calls as $i => $call) {
            if (str_contains($call, $needle)) {
                return $i;
            }
        }

        return -1;
    }

    private function hasLine(array $calls, string $pattern): bool
    {
        foreach ($calls as $call) {
            if (preg_match($pattern, $call)) {
                return true;
            }
        }

        return false;
    }

    /** خرجُ migrate كاملًا كما حُفظ على الخادم */
    private function migrateLog(): string
    {
        $logs = glob($this->root.'/state/migrate-*.log') ?: [];
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

    /* ═══ ١ · لا نسخة، وكلُّ خطوةٍ بترتيبها وبحسابها ═══ */

    public function test_it_takes_no_dump_and_runs_every_step_in_order_and_under_the_right_account(): void
    {
        $this->server();
        $code = $this->code();
        foreach (['pg_dump', 'PRE_DEPLOY_BACKUP', 'deploy.conf', '.dump'] as $gone) {
            $this->assertStringNotContainsString($gone, $code, "عاد إلى السكربت: $gone");
        }

        $run = $this->deploy();

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertSame($this->to, $run['head'], 'لم يُسحب الكود');
        $order = ['composer install', 'artisan package:discover', 'npm ci', 'npm run build', 'artisan migrate --force',
            'artisan config:cache', 'artisan route:cache', 'artisan view:cache', 'systemctl reload', 'artisan abaad:preflight', 'curl'];
        $positions = array_map(fn ($step) => $this->at($run['calls'], $step), $order);
        foreach ($order as $i => $step) {
            $this->assertGreaterThanOrEqual(0, $positions[$i], "لم تجرِ: $step");
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'تبدّل ترتيبُ الخطوات');
        $this->assertMatchesRegularExpression('/تمّ: '.$this->from.' → '.$this->to.' /u', $run['out']);

        // لا نسخة، ولا ملفَّ نسخة، والقديمُ لم يُمسّ
        $this->assertSame(-1, $this->at($run['calls'], 'pg_dump'), 'جرت نسخة');
        $this->assertSame([], glob($this->root.'/state/*.dump'), 'كُتبت نسخةٌ جديدة');
        $this->assertSame('old dump', file_get_contents($this->root.'/pre-deploy/abaad-20261029-033620.dump'), 'مُسّت نسخةٌ قديمة');

        // ═══ الحسابات ═══
        // كلُّ أمر artisan بحساب www-data
        foreach (['migrate --force', 'package:discover', 'config:cache', 'route:cache', 'view:cache', 'abaad:preflight'] as $sub) {
            $this->assertTrue($this->hasLine($run['calls'], '/^sudo\[www-data\] php .*artisan '.preg_quote($sub, '/').'/'),
                "artisan $sub لم يجرِ بحساب www-data");
        }
        // systemctl بصلاحية root وحده، وأمرًا واحدًا مثبّتًا
        $this->assertTrue($this->hasLine($run['calls'], '/^sudo\[root\] systemctl reload php8\.4-fpm$/'), 'إعادةُ تحميل php-fpm لم تجرِ بصلاحية root وأمرًا مثبّتًا');
        // ولا sudo يحمل root لغير systemctl reload
        $this->assertFalse($this->hasLine($run['calls'], '/^sudo\[root\] (?!systemctl reload php8\.4-fpm$)/'), 'sudo بصلاحية root لأمرٍ آخر');
        // composer وnpm وgit بحساب النشر مباشرةً — لا sudo حولها
        $this->assertFalse($this->hasLine($run['calls'], '/^sudo\[[^]]+\] (composer|npm|git)\b/'), 'أمرُ بناءٍ مرّ عبر sudo');

        $note = (string) file_get_contents($this->root.'/state/ROLLBACK.txt');
        $this->assertStringContainsString('commit قبل النشر: '.$this->from, $note);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $note);
        $this->assertStringContainsString('الرجوعُ بالكود لا يُرجع ما غيّرته الهجرات', $note);
    }

    /* ═══ ٢ · composer --no-scripts، ثمّ package:discover بحساب www-data بعده ═══ */

    public function test_composer_runs_without_scripts_and_package_discover_runs_as_www_data_after_it(): void
    {
        $this->server();

        $run = $this->deploy();

        $this->assertTrue($this->hasLine($run['calls'], '/^composer install .*--no-scripts/'), 'composer بلا --no-scripts');
        $composer = $this->at($run['calls'], 'composer install');
        $discover = $this->at($run['calls'], 'sudo[www-data] php');
        $this->assertGreaterThan($composer, $this->at($run['calls'], 'artisan package:discover'), 'package:discover لم يأتِ بعد composer');
        $this->assertGreaterThanOrEqual(0, $discover, 'لم يُبدَّل إلى www-data');
    }

    public function test_the_npm_cache_lives_in_the_accounts_home_not_a_shared_tmp(): void
    {
        $this->server();

        $run = $this->deploy();

        $cacheLine = '';
        foreach ($run['calls'] as $call) {
            if (str_starts_with($call, 'npmcache ')) {
                $cacheLine = $call;
                break;
            }
        }
        // بيتُ الحساب نفسُه — لا يُفحص «لا يحوي /tmp»: مجلّدُ المختبر المؤقّت يقع تحت /tmp على لينكس
        $this->assertSame('npmcache '.$this->root.'/home/.npm-cache', $cacheLine, 'مخبأُ npm ليس في بيت الحساب');
        $this->assertStringNotContainsString('/tmp/npm-cache', $cacheLine, 'مخبأُ npm في /tmp/npm-cache المشترك');
    }

    /* ═══ ٣ · الهجراتُ تُسمّى، ومعها ما لا يُرجَع ═══ */

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
        array_map('unlink', glob($this->root.'/state/migrate-*.log') ?: []);
        $run = $this->deploy(['MIGRATE_SAYS' => 'garbled']);
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringNotContainsString('لا هجرةَ جديدة', $run['out']);
        $this->assertStringContainsString('لم يُقرأ من خرج migrate ما جرى', $run['out']);
    }

    /* ═══ ٤ · الفشلُ يوقفه حيث وقع ═══ */

    public function test_a_failed_migration_stops_before_the_caches_and_keeps_the_database_error_off_the_public_log(): void
    {
        $this->server();

        $run = $this->deploy(['FAIL_MIGRATE' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertStringContainsString("\n    ".self::B."  FAIL\n", $run['out']);
        $this->assertStringContainsString('سقطت الهجرات', $run['out']);
        $this->assertStringContainsString('ما جرى قبل السقوط يبقى في القاعدة، ولا نسخةَ تُرجعه', $run['out']);
        // الكودُ الحيّ لم يُبدَّل: لا ذاكرةَ مؤقّتة ولا إعادةَ تحميلٍ لـPHP بعد هجرةٍ سقطت
        $this->assertSame(-1, $this->at($run['calls'], 'artisan config:cache'));
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
        foreach (['npm', 'artisan migrate', 'systemctl'] as $step) {
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
