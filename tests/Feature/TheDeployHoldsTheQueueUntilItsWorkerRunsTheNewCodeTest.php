<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * النشرُ يوقف الطابورَ قبل أن يتبدّل الكود، ويعيد العاملَ بالكود الجديد بعد الهجرات، ثمّ يُطلقه.
 *
 * ═══ ما كان ═══
 *
 * `scripts/deploy.sh` لا يمسّ العامل: يعيش بالكود القديم حتى يُعاد كلَّ ساعة
 * (`--max-time=3600`). فطوالَ النشر — سحبٌ ثمّ بناءٌ دقائقَ ثمّ هجرات — يسحب
 * العاملُ مهامَّ وكودُ القرص جديدٌ والقاعدةُ قديمة، ثمّ ساعةً بعده بكودٍ قديمٍ
 * على قاعدةٍ جديدة: فاتورةُ طلب واتساب تُصدر بلا لقطة البوتيك (#271).
 *
 * ═══ ما صار ═══
 *
 *   ١. `queue:pause` للطابورين قبل السحب: لا يسحب العاملُ مهمّةً جديدة، ومهمّتُه
 *      الجارية تُكمل — لا تُقطع، فلا تُعاد، فلا رسالةَ تخرج مرّتين.
 *   ٢. بعد الهجرات والذاكرة وإعادة تحميل PHP: `queue:restart` — يُفحص بين المهامّ
 *      لا في أثنائها (هو مخرجُ `--max-time` نفسُه كلَّ ساعة)، فيخرج العاملُ ويعيده
 *      systemd بالكود الجديد.
 *   ٣. ينتظر دورةَ نومٍ أو اثنتين ليخرج القديم، ثمّ `queue:resume` للطابورين.
 *
 * وإن سقط النشرُ في أيّ خطوةٍ أُطلق الطابورُ كما كان (لا يبقى موقوفًا بلا أحد)،
 * وقيل أين سقط وما الحال. وإن لم تُثبَّت قاعدةُ sudo الجديدة بعد على الخادم
 * رُفض الإيقافُ — فيُقال ذلك ويمضي النشرُ كما كان، لا يسقط.
 *
 * ═══ والهجراتُ تُعاد حين يكون سقوطُها قفلًا ═══
 *
 * كلُّ هجرةٍ تنتظر قفلها ثلاثَ ثوانٍ (`MigrationLockTimeout`) ثمّ تسقط بـ55P03 وما
 * قبلها باقٍ. فيُعاد `migrate` مرّاتٍ محدودة لهذا الخطأ وحده — وأيُّ خطأٍ آخر
 * يوقف النشرَ من أوّل مرّة كما كان.
 */
class TheDeployHoldsTheQueueUntilItsWorkerRunsTheNewCodeTest extends TestCase
{
    private const B = '2026_02_01_000000_b';

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

    /* ═══ المختبر: خادمٌ في مجلّدٍ مؤقّت، وبدائلُ تسجّل ما طُلب منها ═══ */

    private function git(string $command, string $in): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($in).' && git -c user.name=t -c user.email=t@t -c init.defaultBranch=main '.$command.' 2>&1'));
    }

    private function server(): void
    {
        $this->root = sys_get_temp_dir().'/abaad-queue-deploy-test-'.bin2hex(random_bytes(4));
        foreach (['bin', 'home', 'state', 'seed/database/migrations'] as $dir) {
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
        $this->from = $this->git('rev-parse --short HEAD', $this->root.'/app');

        file_put_contents($seed.'/database/migrations/'.self::B.'.php', "<?php\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm b', $seed);
        $this->git('push -q origin HEAD:main', $seed);
        $this->to = $this->git('rev-parse --short HEAD', $seed);

        $log = 'echo "$(basename "$0") $*" >> "$CALLS"';
        // sudo -n يُرفض لأمرٍ لم تُثبَّت قاعدتُه بعد — كما يردّ على الخادم
        $sudo = 'u=root; while [ $# -gt 0 ]; do case "$1" in -n) shift;; -u) u="$2"; shift 2;; *) break;; esac; done; '
            .'if [ "${SUDOERS_OLD:-}" = 1 ]; then case "$*" in *"queue:pause"*|*"queue:resume"*) echo "sudo: a password is required" >&2; exit 1;; esac; fi; '
            .'echo "sudo[$u] $*" >> "$CALLS"; exec "$@"';
        // migrate: قفلٌ لم يُتَح في المحاولات الأولى (LOCKED=n)، أو خطأٌ آخر (FAIL_MIGRATE=1)
        $migrate = 'n=$(cat "$ROOT/migrate.count" 2>/dev/null || echo 0); n=$((n+1)); echo $n > "$ROOT/migrate.count"; '
            .'if [ "${FAIL_MIGRATE:-}" = 1 ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 0.08ms FAIL\n\n  SQLSTATE[23505]: duplicate\n"; exit 1; fi; '
            .'if [ "$n" -le "${LOCKED:-0}" ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 3001ms FAIL\n\n   Illuminate\\\\Database\\\\QueryException\n\n  SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout\n"; exit 1; fi; '
            .'printf "\n INFO Running migrations.\n\n '.self::B.' .. 2.34ms DONE\n"';
        $stubs = [
            'sudo' => $sudo,
            'composer' => $log.'; [ "${FAIL_COMPOSER:-}" = 1 ] && exit 1; true',
            'npm' => $log.'; case "$*" in *"run build"*) [ "${FAIL_BUILD:-}" = 1 ] && exit 0; for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *"migrate --force"*) '.$migrate.';; *"queue:restart"*) [ "${FAIL_RESTART:-}" = 1 ] && exit 1;; esac; true',
            'systemctl' => $log.'; [ "${FAIL_RELOAD:-}" = 1 ] && exit 1; true',
            'curl' => $log.'; printf "%s" "${HEALTH_CODE:-200}"',
            // النومُ يُسجَّل ولا يُنتظر
            'sleep' => $log,
            // وgit الحقيقيُّ يجري — ويُسجَّل قبله، فيُعرف أنّ الطابور وقف قبل أن يتبدّل الكود
            'git' => $log.'; exec '.escapeshellarg(trim((string) shell_exec('command -v git'))).' "$@"',
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->root.'/bin/'.$name, "#!/usr/bin/env bash\n".$body."\n");
            chmod($this->root.'/bin/'.$name, 0700);
        }
    }

    /** @return array{rc: int, out: string, calls: list<string>} */
    private function script(string $script, array $args, array $env = []): array
    {
        $proc = proc_open(array_merge(['bash', base_path($script)], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HOME' => $this->root.'/home',
            'TMPDIR' => $this->root,
            'ROOT' => $this->root,
            'APP_DIR' => $this->root.'/app',
            'APP_USER' => 'www-data',
            'STATE_DIR' => $this->root.'/state',
            'HEALTH_URL' => 'http://health.test',
            'CALLS' => $this->root.'/calls.log',
            'PHP_BIN' => 'php',
            'SYSTEMCTL' => 'systemctl',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'DEPLOY_DETACHED' => '1',
            'ROLLBACK_DETACHED' => '1',
        ]);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $rc = proc_close($proc);

        return [
            'rc' => $rc,
            'out' => (string) preg_replace('/\e\[[0-9;]*m/', '', $out),
            'calls' => is_file($this->root.'/calls.log') ? file($this->root.'/calls.log', FILE_IGNORE_NEW_LINES) : [],
        ];
    }

    private function deploy(array $env = []): array
    {
        return $this->script('scripts/deploy.sh', [], $env);
    }

    /** مواضعُ كلّ نداءٍ يحوي هذا */
    private function where(array $calls, string $needle): array
    {
        return array_keys(array_filter($calls, fn ($c) => str_contains($c, $needle)));
    }

    private function first(array $calls, string $needle): int
    {
        return $this->where($calls, $needle)[0] ?? -1;
    }

    private function last(array $calls, string $needle): int
    {
        $all = $this->where($calls, $needle);

        return $all === [] ? -1 : end($all);
    }

    private function wwwData(string $command): string
    {
        return 'sudo[www-data] php '.$this->root.'/app/artisan '.$command;
    }

    /* ═══ ١ · النشرُ الناجح: إيقافٌ قبل السحب، وإعادةٌ بعد إعادة التحميل، ثمّ إطلاق ═══ */

    public function test_the_queue_is_paused_before_the_code_changes_and_resumed_after_the_worker_restarts_on_it(): void
    {
        $this->server();

        $run = $this->deploy();

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        foreach (['queue:pause whatsapp', 'queue:pause default', 'queue:restart --quiet', 'queue:resume whatsapp', 'queue:resume default'] as $cmd) {
            $this->assertCount(1, $this->where($calls, $this->wwwData($cmd)), "artisan $cmd لم يجرِ مرّةً واحدةً بحساب www-data:\n".implode("\n", $calls));
        }

        // الإيقافُ قبل أن يتبدّل شيء على القرص: قبل الجلب والسحب، وقبل composer
        foreach (['git fetch', 'git pull', 'composer install'] as $change) {
            $this->assertGreaterThanOrEqual(0, $this->first($calls, $change), "لم يجرِ: $change");
            $this->assertLessThan($this->first($calls, $change), $this->first($calls, 'queue:pause whatsapp'), "أُوقف طابورُ واتساب بعد $change");
            $this->assertLessThan($this->first($calls, $change), $this->first($calls, 'queue:pause default'), "أُوقف الطابورُ الافتراضيّ بعد $change");
        }
        // والإعادةُ بعد الهجرات والذاكرة وإعادة تحميل PHP — فيبدأ العاملُ الجديد على ما يبدأ عليه الويب
        foreach (['artisan migrate --force', 'artisan config:cache', 'artisan route:cache', 'artisan view:cache', 'systemctl reload'] as $before) {
            $this->assertLessThan($this->first($calls, 'queue:restart'), $this->last($calls, $before), "queue:restart جرى قبل $before");
        }
        // والإطلاقُ بعد الإعادة وبعد انتظارٍ يخرج فيه العاملُ القديم — ثمّ الفحصُ والصحّة
        $restart = $this->first($calls, 'queue:restart');
        $wait = $this->first($calls, 'sleep ');
        $this->assertGreaterThan($restart, $wait, 'لا انتظارَ بين الإعادة والإطلاق');
        $this->assertGreaterThan($wait, $this->first($calls, 'queue:resume whatsapp'));
        $this->assertGreaterThan($wait, $this->first($calls, 'queue:resume default'));
        $this->assertLessThan($this->first($calls, 'abaad:preflight'), $this->last($calls, 'queue:resume'));

        $this->assertStringContainsString('تمّ: '.$this->from.' → '.$this->to, $run['out']);
    }

    /* ═══ ٢ · السقوطُ في أيّ خطوةٍ يُطلق الطابورَ ويقول أين وقع ═══ */

    public function test_a_deploy_that_fails_mid_way_releases_the_queue_and_never_restarts_the_worker_on_half_a_deploy(): void
    {
        foreach ([
            'composer' => ['FAIL_COMPOSER' => '1'],
            'build' => ['FAIL_BUILD' => '1'],
            'migrate' => ['FAIL_MIGRATE' => '1'],
            'reload' => ['FAIL_RELOAD' => '1'],
        ] as $where => $env) {
            $this->server();

            $run = $this->deploy($env);

            $this->assertNotSame(0, $run['rc'], "$where: نجح النشر");
            $calls = $run['calls'];
            $this->assertCount(1, $this->where($calls, $this->wwwData('queue:pause whatsapp')), "$where: لم يُوقف الطابور");
            // أُطلق الطابوران — لا يبقى موقوفًا بلا أحد
            $this->assertCount(1, $this->where($calls, $this->wwwData('queue:resume whatsapp')), "$where: بقي طابورُ واتساب موقوفًا\n".$run['out']);
            $this->assertCount(1, $this->where($calls, $this->wwwData('queue:resume default')), "$where: بقي الطابورُ الافتراضيّ موقوفًا");
            // ولا يُعاد العاملُ على نشرٍ لم يكتمل: يبقى بكوده القديم كما يبقى الويب
            $this->assertSame([], $this->where($calls, 'queue:restart'), "$where: أُعيد العاملُ على نصف نشر");
            $this->assertStringContainsString('سقط النشرُ عند', $run['out'], "$where: لم يُقل أين سقط");
            $this->assertStringContainsString('أُطلق الطابور', $run['out'], $where);
            $this->assertStringNotContainsString('تمّ:', $run['out']);
            $this->assertStringNotContainsString('unbound variable', $run['out'], $where);

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    /* ═══ ٣ · قاعدةُ sudo لم تُثبَّت بعد: يُقال ذلك ويمضي النشرُ كما كان ═══ */

    public function test_without_the_new_sudo_rule_the_deploy_says_so_and_still_restarts_the_worker(): void
    {
        $this->server();

        $run = $this->deploy(['SUDOERS_OLD' => '1']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringContainsString('لم يُوقف الطابور', $run['out']);
        $this->assertSame([], $this->where($run['calls'], 'queue:pause'));
        $this->assertSame([], $this->where($run['calls'], 'queue:resume'));
        // والإعادةُ قاعدتُها قائمةٌ منذ #205 — فيبدأ العاملُ بالكود الجديد على كلّ حال
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:restart --quiet')));
        $this->assertStringContainsString('تمّ: ', $run['out']);
    }

    /* ═══ ٤ · قفلٌ لم يُتَح: تُعاد الهجراتُ مرّاتٍ محدودة لهذا الخطأ وحده ═══ */

    public function test_a_migration_that_timed_out_on_its_lock_is_retried_and_the_deploy_completes(): void
    {
        $this->server();

        $run = $this->deploy(['LOCKED' => '2']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertCount(3, $this->where($run['calls'], $this->wwwData('migrate --force')), 'لم تُعَد الهجراتُ بعد القفل');
        $this->assertStringContainsString('لم يُتَح القفل', $run['out']);
        // وخطأُ القاعدة لا يصل السجلَّ العامّ
        $this->assertStringNotContainsString('SQLSTATE', $run['out']);
        $this->assertStringContainsString("\n    ".self::B."  DONE\n", $run['out']);
        $this->assertCount(1, glob($this->root.'/state/migrate-*.log') ?: [], 'أكثرُ من ملفّ خرجٍ للهجرات');
    }

    public function test_a_lock_that_never_frees_stops_the_deploy_after_a_bounded_number_of_tries(): void
    {
        $this->server();

        $run = $this->deploy(['LOCKED' => '99']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertCount(5, $this->where($run['calls'], $this->wwwData('migrate --force')), 'محاولاتُ القفل ليست خمسًا');
        $this->assertStringContainsString('سقطت الهجرات', $run['out']);
        $this->assertStringContainsString('أعِد النشرَ في وقتٍ أهدأ', $run['out']);
        $this->assertSame(-1, $this->first($run['calls'], 'artisan config:cache'));
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:resume whatsapp')));
    }

    public function test_any_other_migration_error_is_not_retried(): void
    {
        $this->server();

        $run = $this->deploy(['FAIL_MIGRATE' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('migrate --force')), 'خطأٌ غيرُ القفل أُعيد');
    }

    /* ═══ ٥ · الرجوعُ بالكود يفعل مثلَه ═══ */

    public function test_the_code_rollback_also_pauses_restarts_and_resumes_the_queue_in_order(): void
    {
        $this->server();
        $app = $this->root.'/app';
        // الخادمُ على B (نُشر)، والرجوعُ إلى A بلا هجرةٍ بينهما
        file_put_contents($app.'/app.txt', "b\n");
        $this->git('commit -qam b2', $app);

        $run = $this->script('scripts/rollback-code.sh', [$this->from]);

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        foreach (['queue:pause whatsapp', 'queue:pause default', 'queue:restart --quiet', 'queue:resume whatsapp', 'queue:resume default'] as $cmd) {
            $this->assertCount(1, $this->where($calls, $this->wwwData($cmd)), "الرجوع: artisan $cmd\n".implode("\n", $calls));
        }
        // الإيقافُ قبل أن يُبدَّل الكود (`reset --hard`) وقبل composer
        $this->assertLessThan($this->first($calls, 'git reset --hard'), $this->first($calls, 'queue:pause whatsapp'));
        $this->assertLessThan($this->first($calls, 'composer install'), $this->first($calls, 'queue:pause whatsapp'));
        $this->assertGreaterThan($this->first($calls, 'systemctl reload'), $this->first($calls, 'queue:restart'));
        $this->assertGreaterThan($this->first($calls, 'queue:restart'), $this->first($calls, 'queue:resume whatsapp'));
    }

    public function test_a_failed_code_rollback_releases_the_queue_too(): void
    {
        $this->server();
        $app = $this->root.'/app';
        file_put_contents($app.'/app.txt', "b\n");
        $this->git('commit -qam b2', $app);

        $run = $this->script('scripts/rollback-code.sh', [$this->from], ['FAIL_COMPOSER' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:resume whatsapp')), $run['out']);
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:resume default')));
        $this->assertSame([], $this->where($run['calls'], 'queue:restart'));
    }
}
