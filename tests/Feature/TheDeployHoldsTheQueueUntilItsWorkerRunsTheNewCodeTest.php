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

    private function server(bool $migration = true): void
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

        // B: هجرةٌ جديدة — أو تعديلُ كودٍ وحده بلا هجرة
        $migration
            ? file_put_contents($seed.'/database/migrations/'.self::B.'.php', "<?php\n")
            : file_put_contents($seed.'/app.txt', "b\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm b', $seed);
        $this->git('push -q origin HEAD:main', $seed);
        $this->to = $this->git('rev-parse --short HEAD', $seed);

        // كلُّ نداءٍ يُسجَّل ومعه: أكان ملفُّ إيقاف المهامّ المجدولة قائمًا ساعتَها؟
        $hold = 'hold=$([ -e "$APP_DIR/.deploy-hold" ] && echo 1 || echo 0)';
        // وأيرث وصفَ القفل (9)؟ ما يعيش بعد النشر — `git gc --auto` في الخلفية — يُمسك القفلَ إن ورثه
        $log = 'echo "$(basename "$0") $* fd9=$([ -e /dev/fd/9 ] && echo 1 || echo 0) '.$hold.'" >> "$CALLS"';
        // sudo -n يُرفض لأمرٍ لم تُثبَّت قاعدتُه بعد — كما يردّ على الخادم. SUDOERS_OLD: قاعدةُ main
        // (لا إيقاف ولا إطلاق)، SUDOERS_HALF: نصفُ تركيب (الإيقافُ بلا الإطلاق). و-l يسأل ولا يُجري
        $sudo = 'u=root; list=0; while [ $# -gt 0 ]; do case "$1" in -n) shift;; -l) list=1; shift;; -u) u="$2"; shift 2;; *) break;; esac; done; '
            .'refused=0; if [ "${SUDOERS_OLD:-}" = 1 ]; then case "$*" in *"queue:pause"*|*"queue:resume"*|*"abaad:deploy-state"*) refused=1;; esac; fi; '
            .'if [ "${SUDOERS_R14:-}" = 1 ]; then case "$*" in *"abaad:deploy-state"*) refused=1;; esac; fi; '
            .'if [ "${SUDOERS_HALF:-}" = 1 ]; then case "$*" in *"queue:resume"*) refused=1;; esac; fi; '
            .'if [ $list = 1 ]; then echo "sudo-l[$u] $* refused=$refused" >> "$ROOT/sudo-l.log"; exit $refused; fi; '
            .'if [ $refused = 1 ]; then echo "sudo: a password is required" >&2; exit 1; fi; '
            .'echo "sudo[$u] $* '.$hold.'" >> "$CALLS"; exec "$@"';
        // migrate: قفلٌ لم يُتَح في المحاولات الأولى (LOCKED=n)، أو خطأٌ آخر (FAIL_MIGRATE=1)
        $migrate = 'n=$(cat "$ROOT/migrate.count" 2>/dev/null || echo 0); n=$((n+1)); echo $n > "$ROOT/migrate.count"; '
            .'if [ "${FAIL_MIGRATE:-}" = 1 ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 0.08ms FAIL\n\n  SQLSTATE[23505]: duplicate\n"; exit 1; fi; '
            .'if [ "$n" -le "${LOCKED:-0}" ]; then printf "\n INFO Running migrations.\n\n '.self::B.' .. 3001ms FAIL\n\n   Illuminate\\\\Database\\\\QueryException\n\n  SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout\n"; exit 1; fi; '
            .'touch "$ROOT/migrated"; printf "\n INFO Running migrations.\n\n '.self::B.' .. 2.34ms DONE\n"';
        // abaad:deploy-state كما يجيب التطبيق: مهمّةٌ تجري BUSY_FOR سؤالًا، ثمّ لا شيء — والهجرةُ B
        // لم تجرِ ما دام ملفُّها على القرص ولم يُهاجَر. STATE_FAIL: التطبيقُ لا يجيب
        $state = 'n=$(cat "$ROOT/state.count" 2>/dev/null || echo 0); n=$((n+1)); echo $n > "$ROOT/state.count"; '
            .'[ "${STATE_FAIL:-}" = 1 ] && { echo "running: ?"; exit 1; }; '
            .'p=0; [ -e "$APP_DIR/database/migrations/'.self::B.'.php" ] && [ ! -e "$ROOT/migrated" ] && p=1; '
            .'if [ "$n" -le "${BUSY_FOR:-0}" ]; then echo "running: 1"; echo "job: #7 whatsapp App\\Jobs\\SendWhatsAppMessage · 41s · attempt 1"; fi; '
            .'[ "$n" -le "${BUSY_FOR:-0}" ] || echo "running: 0"; echo "abandoned: 0"; echo "pending-migrations: $p"; '
            .'[ $p = 1 ] && echo "migration: '.self::B.'"; [ "$n" -le "${BUSY_FOR:-0}" ] && exit 3; exit 0';
        $stubs = [
            'sudo' => $sudo,
            'composer' => $log.'; [ "${FAIL_COMPOSER:-}" = 1 ] && exit 1; true',
            // BLOCK_BUILD: البناءُ ينتظر إشارةً — نشرٌ جارٍ يُمسك القفل، فيُجرَّب فوقه نشرٌ ثانٍ
            // AGE_HOLD: البناءُ طال عشرين دقيقة — فملفُّ الإيقاف قديمٌ حين يسقط النشرُ بعده
            'npm' => $log.'; case "$*" in *"run build"*) if [ "${AGE_HOLD:-}" = 1 ] && [ -e "$APP_DIR/.deploy-hold" ]; then perl -e \'utime time-1200, time-1200, $ARGV[0]\' "$APP_DIR/.deploy-hold"; fi; if [ "${BLOCK_BUILD:-}" = 1 ]; then touch "$ROOT/building"; while [ ! -e "$ROOT/go" ]; do /bin/sleep 0.1; done; fi; [ "${FAIL_BUILD:-}" = 1 ] && exit 0; for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *"migrate --force"*) '.$migrate.';; *"abaad:deploy-state"*) '.$state.';; *"queue:restart"*) [ "${FAIL_RESTART:-}" = 1 ] && exit 1;; esac; true',
            'systemctl' => $log.'; [ "${FAIL_RELOAD:-}" = 1 ] && exit 1; true',
            'curl' => $log.'; printf "%s" "${HEALTH_CODE:-200}"',
            // النومُ يُسجَّل ولا يُنتظر
            'sleep' => $log,
            // وgit الحقيقيُّ يجري — ويُسجَّل قبله، فيُعرف أنّ الطابور وقف قبل أن يتبدّل الكود
            'git' => $log.'; [ "${FAIL_PULL:-}" = 1 ] && [ "$1" = pull ] && exit 1; exec '.escapeshellarg(trim((string) shell_exec('command -v git'))).' "$@"',
        ];
        // flock من util-linux على الخادم وفي CI. وماك بلا util-linux: بديلٌ يقفل الوصفَ الموروث
        // نفسَه بـflock(2) كما يفعل الأصل — قفلٌ حقيقيٌّ من النواة، لا تظاهر
        if (trim((string) shell_exec('command -v flock')) === '') {
            $stubs['flock'] = 'w=0; case "$1" in -n) shift;; -w) w="$2"; shift 2;; esac; exec perl -MFcntl=:flock -MTime::HiRes=sleep,time -e \'open(my $l, ">&=", $ARGV[0]) or exit 2; my $until = time + $ARGV[1]; until (flock($l, LOCK_EX | LOCK_NB)) { exit 1 if time >= $until; sleep 0.1 } exit 0\' "$1" "$w"';
        }
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
            'calls' => is_file($calls = $env['CALLS'] ?? $this->root.'/calls.log') ? file($calls, FILE_IGNORE_NEW_LINES) : [],
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
        // النومُ الذي بعد الإعادة (وقبل الإيقاف نومٌ آخر تُكمل فيه المهمّةُ الجارية)
        $wait = min(array_filter($this->where($calls, 'sleep '), fn ($i) => $i > $restart) ?: [-1]);
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

    /* ═══ ٦ · المهامُّ المجدولة تقف من قبل السحب حتى يجري الكودُ الجديد على القاعدة المهاجَرة ═══ */

    /** أكان ملفُّ الإيقاف قائمًا عند أوّل نداءٍ يحوي هذا؟ */
    private function holdAt(array $calls, string $needle): string
    {
        $i = $this->first($calls, $needle);
        $this->assertGreaterThanOrEqual(0, $i, "لم يجرِ: $needle\n".implode("\n", $calls));

        return str_ends_with($calls[$i], 'hold=1') ? 'held' : 'free';
    }

    private function holdFile(): string
    {
        return $this->root.'/app/.deploy-hold';
    }

    public function test_scheduled_tasks_are_held_from_before_the_pull_until_the_new_code_runs_on_the_migrated_database(): void
    {
        $this->server();

        $run = $this->deploy();

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        // من قبل أن يتبدّل شيء على القرص حتى تُبنى الذاكرةُ ويُعاد تحميل PHP
        foreach (['git fetch', 'git pull', 'composer install', 'run build', 'artisan migrate --force', 'artisan config:cache', 'artisan view:cache', 'systemctl reload'] as $inside) {
            $this->assertSame('held', $this->holdAt($calls, $inside), "المهامُّ المجدولة تجري أثناء: $inside");
        }
        // ثمّ تُطلق — قبل إعادة العامل وفحص ما بعد النشر
        foreach (['queue:restart', 'queue:resume whatsapp', 'abaad:preflight', 'curl'] as $after) {
            $this->assertSame('free', $this->holdAt($calls, $after), "المهامُّ المجدولة بقيت موقوفةً عند: $after");
        }
        $this->assertFileDoesNotExist($this->holdFile());
        $this->assertStringContainsString('المهامّ المجدولة', $run['out']);
    }

    public function test_a_failed_deploy_frees_the_schedule_when_the_code_on_disk_matches_its_database(): void
    {
        foreach ([
            // السحبُ سقط: الكودُ كما كان
            'pull' => [true, ['FAIL_PULL' => '1']],
            // كودٌ جديدٌ بلا هجرة: يجري على قاعدته كما بعد أيّ نشر
            'build, no migration' => [false, ['FAIL_BUILD' => '1']],
            // الهجراتُ تمّت، وسقطت إعادةُ تحميل PHP
            'reload' => [true, ['FAIL_RELOAD' => '1']],
        ] as $where => [$migration, $env]) {
            $this->server($migration);

            $run = $this->deploy($env);

            $this->assertNotSame(0, $run['rc'], "$where: نجح النشر");
            $this->assertFileDoesNotExist($this->holdFile(), "$where: بقيت المهامُّ المجدولة موقوفة\n".$run['out']);
            $this->assertStringContainsString('أُطلقت المهامّ المجدولة', $run['out'], $where);
            $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:resume whatsapp')), "$where: بقي الطابور موقوفًا");

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    public function test_a_failed_deploy_keeps_the_schedule_held_while_new_code_waits_on_unrun_migrations(): void
    {
        foreach ([
            'build' => ['FAIL_BUILD' => '1', 'AGE_HOLD' => '1'],
            'migrate' => ['FAIL_MIGRATE' => '1', 'AGE_HOLD' => '1'],
            'lock' => ['LOCKED' => '99', 'AGE_HOLD' => '1'],
        ] as $where => $env) {
            $this->server();

            $run = $this->deploy($env);

            $this->assertNotSame(0, $run['rc'], "$where: نجح النشر");
            // الكودُ الجديد على القرص وهجراتُه لم تجرِ: تشغيلُ المهامّ الآن يجري الجديدَ على القديم
            $this->assertFileExists($this->holdFile(), "$where: أُطلقت المهامُّ المجدولة على قاعدةٍ لم تُهاجَر\n".$run['out']);
            $this->assertStringContainsString('تبقى المهامّ المجدولة موقوفة', $run['out'], $where);
            $this->assertStringContainsString('::warning::', $run['out'], "$where: لم يُعلَن في سجلّ Deploy Main");
            // والملفُّ حديث: تُحسب نصفُ الساعة من السقوط لا من بدء النشر
            $this->assertLessThan(60, time() - filemtime($this->holdFile()));
            // والطابورُ يُطلق كما كان، على عامله القديم
            $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:resume whatsapp')), $where);

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    public function test_a_retry_after_a_deploy_that_left_migrations_unrun_keeps_the_hold_until_they_run(): void
    {
        $this->server();

        // الأوّل: سقط عند الهجرات — الكودُ الجديد على القرص، والهجرةُ لم تجرِ
        $this->assertNotSame(0, $this->deploy(['FAIL_MIGRATE' => '1'])['rc']);
        // الثاني: لا جديدَ يُسحب (الكودُ مسحوبٌ أصلًا)، وسقط قبل الهجرات
        $retry = $this->deploy(['FAIL_BUILD' => '1']);
        $this->assertNotSame(0, $retry['rc']);
        $this->assertFileExists($this->holdFile(), "إعادةٌ سقطت قبل الهجرات أطلقت المهامّ على قاعدةٍ لم تُهاجَر\n".$retry['out']);

        // الثالث: اكتمل — فتُطلق
        $done = $this->deploy();
        $this->assertSame(0, $done['rc'], $done['out']);
        $this->assertFileDoesNotExist($this->holdFile());
        $this->assertFileDoesNotExist($this->root.'/state/MIGRATIONS_PENDING');
    }

    public function test_a_failed_migration_keeps_the_hold_even_when_git_shows_nothing_new(): void
    {
        $this->server();
        // الكودُ مسحوبٌ أصلًا (نشرٌ قديمٌ سقط قبل هذا السكربت) — فلا جديدَ بين ما قبل وما بعد
        $this->git('pull -q --ff-only origin main', $this->root.'/app');

        $run = $this->deploy(['FAIL_MIGRATE' => '1']);

        $this->assertNotSame(0, $run['rc']);
        $this->assertFileExists($this->holdFile(), "سقطت الهجراتُ فأُطلقت المهامّ\n".$run['out']);
        $this->assertFileExists($this->root.'/state/MIGRATIONS_PENDING');
    }

    /** ما يعيش بعد النشر لا يرث القفل — وإلّا ردّ النشرَ التالي وهو لا يجري */
    public function test_no_child_of_the_deploy_or_the_rollback_inherits_the_lock(): void
    {
        $this->server();
        $deploy = $this->deploy();
        $this->assertSame(0, $deploy['rc'], $deploy['out']);

        $app = $this->root.'/app';
        file_put_contents($app.'/app.txt', "c\n");
        $this->git('commit -qam c', $app);
        $back = $this->script('scripts/rollback-code.sh', [$this->to]);
        $this->assertSame(0, $back['rc'], $back['out']);

        foreach (['git ', 'composer ', 'npm '] as $child) {
            foreach ([$deploy['calls'], $back['calls']] as $calls) {
                $this->assertNotEmpty($this->where($calls, $child), "لم يجرِ: $child");
                $this->assertSame([], $this->where(array_filter($calls, fn ($c) => str_starts_with($c, $child)), 'fd9=1'), "$child ورث وصفَ القفل");
            }
        }
    }

    /* ═══ ٧ · نشرٌ واحدٌ في كلّ مرّة — ولا رجوعٌ فوقه ═══ */

    public function test_a_second_deploy_or_a_rollback_while_one_runs_is_refused_and_touches_nothing(): void
    {
        $this->server();
        $env = [
            'PATH' => $this->root.'/bin:'.getenv('PATH'), 'HOME' => $this->root.'/home', 'TMPDIR' => $this->root,
            'ROOT' => $this->root, 'APP_DIR' => $this->root.'/app', 'APP_USER' => 'www-data',
            'STATE_DIR' => $this->root.'/state', 'HEALTH_URL' => 'http://health.test', 'CALLS' => $this->root.'/calls.log',
            'PHP_BIN' => 'php', 'SYSTEMCTL' => 'systemctl', 'GIT_CONFIG_NOSYSTEM' => '1',
            'DEPLOY_DETACHED' => '1', 'BLOCK_BUILD' => '1',
        ];
        $first = proc_open(['bash', base_path('scripts/deploy.sh')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env);
        // ويُطلق النشرُ الأوّل مهما سقط من هذا الاختبار — لا يبقى بعده ينتظر إشارةً لا تأتي
        try {
            for ($i = 0; $i < 300 && ! is_file($this->root.'/building'); $i++) {
                usleep(100_000);
            }
            $this->assertFileExists($this->root.'/building', 'لم يبلغ النشرُ الأوّل البناء');
            $rollbackNote = file_get_contents($this->root.'/state/ROLLBACK.txt');
            $hold = is_file($this->holdFile()) ? (string) file_get_contents($this->holdFile()) : '';

            // يُنتظر القفلُ ثانيةً لا خمسًا — ثمّ يُردّ: نشرٌ جارٍ حقًّا يمسكه أطولَ منها
            $second = $this->deploy(['CALLS' => $this->root.'/calls-2.log', 'DEPLOY_LOCK_WAIT' => '1']);
            $back = $this->script('scripts/rollback-code.sh', [$this->from], ['CALLS' => $this->root.'/calls-3.log', 'DEPLOY_LOCK_WAIT' => '1']);
        } finally {
            touch($this->root.'/go');
            $out = (string) preg_replace('/\e\[[0-9;]*m/', '', stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            $rc = proc_close($first);
        }
        $this->assertSame(0, $rc, "النشرُ الأوّل لم يكتمل:\n".$out);

        foreach (['نشرٌ ثانٍ' => $second, 'رجوع' => $back] as $who => $run) {
            $this->assertNotSame(0, $run['rc'], "$who فوق نشرٍ جارٍ مضى");
            $this->assertStringContainsString('يجري الآن', $run['out'], $who);
            // ولم يلمس شيئًا: لا طابورَ ولا سحبَ ولا تبديلَ ولا ملفَّ الآخر
            $this->assertSame([], $run['calls'], "$who لمس شيئًا:\n".implode("\n", $run['calls']));
        }
        $this->assertSame($rollbackNote, file_get_contents($this->root.'/state/ROLLBACK.txt'), 'كُتب ROLLBACK.txt فوق نشرٍ جارٍ');
        // وملفُّ الإيقاف بقي للنشر الجاري حتى أتمّه هو
        $this->assertNotSame('', $hold);
        $this->assertFileDoesNotExist($this->holdFile());
        $this->assertStringContainsString('تمّ: '.$this->from.' → '.$this->to, $out);

        // والقفلُ يُفلت بانتهاء صاحبه: نشرٌ بعده يجري
        $this->assertSame(0, $this->deploy(['CALLS' => $this->root.'/calls-4.log'])['rc']);
    }

    /* ═══ ٨ · قاعدةُ sudo نصفُ مثبّتة: لا يُوقف ما لا يُطلق ═══ */

    public function test_with_only_half_of_the_queue_rules_installed_the_queue_is_never_paused(): void
    {
        $this->server();

        $run = $this->deploy(['SUDOERS_HALF' => '1']);

        // كان يُوقف الطابورَ ثمّ يردّ sudo إطلاقَه — فيبقى موقوفًا ولا تخرج رسالة
        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertSame([], $this->where($run['calls'], 'queue:pause'), 'أُوقف طابورٌ لا يُطلق');
        $this->assertStringContainsString('قاعدةُ إيقاف الطابور غيرُ مثبّتة', $run['out']);
        $this->assertStringContainsString('::warning::', $run['out']);
        $this->assertCount(1, $this->where($run['calls'], $this->wwwData('queue:restart --quiet')));
        // والسؤالُ يسبق كلَّ إيقاف، ولا يُجري شيئًا
        $this->assertFileExists($this->root.'/sudo-l.log');
    }

    public function test_the_old_rules_say_so_loudly_and_pause_nothing(): void
    {
        $this->server();

        $run = $this->deploy(['SUDOERS_OLD' => '1']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertStringContainsString('قاعدةُ إيقاف الطابور غيرُ مثبّتة', $run['out']);
        $this->assertStringContainsString('::warning::', $run['out']);
        // ولا انتظارَ قبل السحب: طابورٌ لم يقف لا يُنتظر
        $this->assertSame([], array_filter($this->where($run['calls'], 'sleep '), fn ($i) => $i < $this->first($run['calls'], 'git fetch')));
        // والمهامُّ المجدولة تقف على كلّ حال: ملفٌّ لا قاعدةَ sudo له
        $this->assertSame('held', $this->holdAt($run['calls'], 'git pull'));
    }

    /* ═══ ٩ · المهمّةُ الجارية تُنتظر حتى تنتهي قبل أن يتبدّل الكود — لا ثوانيَ معدودة ═══ */

    /** نداءاتُ «أتجري مهمّة؟» قبل أن يتبدّل شيءٌ على القرص */
    private function asksBefore(array $calls, string $needle): array
    {
        $until = $this->first($calls, $needle);

        return array_values(array_filter($this->where($calls, $this->wwwData('abaad:deploy-state')), fn ($i) => $until === -1 || $i < $until));
    }

    /** ما نامه السكربتُ بين موضعين: «sleep 2»، لا السطرُ كلُّه */
    private function sleepsBetween(array $calls, int $after, int $before): array
    {
        return array_values(array_map(
            fn ($i) => explode(' fd9', $calls[$i])[0],
            array_filter($this->where($calls, 'sleep '), fn ($i) => $i > $after && ($before === -1 || $i < $before))
        ));
    }

    public function test_after_pausing_the_deploy_waits_until_no_job_runs_before_the_code_changes(): void
    {
        $this->server();

        // مهمّةٌ تجري ثلاثةَ أسئلة (ستّ ثوانٍ هنا، وقد تكون دقائق): لا تُقطع، ولا يتبدّل الكودُ تحتها
        $run = $this->deploy(['BUSY_FOR' => '3', 'QUEUE_IDLE_POLL' => '2']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        $asks = $this->asksBefore($calls, 'git fetch');
        $this->assertCount(4, $asks, "لم يُسأل التطبيقُ حتى انتهت المهمّة:\n".implode("\n", $calls));
        $this->assertGreaterThan($this->last($calls, 'queue:pause'), $asks[0], 'سُئل قبل أن يقف الطابور');
        // وبين كلّ سؤالٍ وما بعده نومُ الاستطلاع — لا إمهالٌ ثابت
        $this->assertSame(['sleep 2', 'sleep 2', 'sleep 2'], $this->sleepsBetween($calls, $this->last($calls, 'queue:pause'), $this->first($calls, 'git fetch')));
        $this->assertStringContainsString('App\Jobs\SendWhatsAppMessage', $run['out'], 'لم تُسمَّ المهمّةُ المنتظَرة');
        $this->assertStringContainsString('لا مهمّةَ تجري على الطابور (انتُظرت 6 ثانية)', $run['out']);
    }

    public function test_a_job_still_running_at_the_limit_stops_the_deploy_before_it_touches_the_code(): void
    {
        $this->server();

        $run = $this->deploy(['BUSY_FOR' => '999', 'QUEUE_IDLE_TIMEOUT' => '6', 'QUEUE_IDLE_POLL' => '2']);

        $this->assertNotSame(0, $run['rc'], 'مضى النشرُ ومهمّةٌ تجري');
        foreach (['git fetch', 'git pull', 'composer install', 'run build', 'migrate --force', 'config:cache', 'systemctl reload', 'queue:restart'] as $never) {
            $this->assertSame([], $this->where($run['calls'], $never), "لمس النشرُ الكودَ ومهمّةٌ تجري: $never");
        }
        $this->assertSame($this->from, $this->git('rev-parse --short HEAD', $this->root.'/app'));
        foreach (['queue:resume whatsapp', 'queue:resume default'] as $cmd) {
            $this->assertCount(1, $this->where($run['calls'], $this->wwwData($cmd)), "بقي الطابورُ موقوفًا: $cmd");
        }
        $this->assertFileDoesNotExist($this->holdFile(), 'بقيت المهامُّ موقوفةً والكودُ لم يتغيّر');
        $this->assertStringContainsString('ما زالت مهمّةٌ تجري على الطابور بعد 6 ثانية', $run['out']);
        $this->assertStringContainsString('  #7 whatsapp App\Jobs\SendWhatsAppMessage', $run['out'], 'لم تُسمَّ المهمّة');
        // وحالُ الخادم: الكودُ كما كان، والطابورُ أُطلق، والمهامُّ تجري
        $block = $this->block($run['out']);
        foreach (['سقط عند: انتظار المهمّة الجارية قبل تبديل الكود', 'الكود على القرص: '.$this->from.' (كان '.$this->from.')',
            'الطابور: أُطلق كما كان', 'المهامّ المجدولة: تجري'] as $says) {
            $this->assertStringContainsString($says, $block);
        }
    }

    public function test_without_the_state_rule_the_deploy_waits_the_fixed_time_as_before_and_says_so(): void
    {
        $this->server();

        // sudoers الجولة ١٤: أسطرُ الطابور الأربعة بلا سؤال الحال
        $run = $this->deploy(['SUDOERS_R14' => '1', 'QUEUE_DRAIN_WAIT' => '7']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        $this->assertSame(['sleep 7'], $this->sleepsBetween($calls, $this->last($calls, 'queue:pause'), $this->first($calls, 'git fetch')));
        $this->assertSame([], $this->where($calls, $this->wwwData('abaad:deploy-state')), 'سُئل التطبيقُ بقاعدةٍ لم تُثبَّت');
        $this->assertStringContainsString('قاعدةُ abaad:deploy-state غيرُ مثبّتة', $run['out']);
        $this->assertStringContainsString('::warning::', $run['out']);
    }

    public function test_when_the_app_cannot_say_the_deploy_waits_the_fixed_time(): void
    {
        $this->server();

        $run = $this->deploy(['STATE_FAIL' => '1', 'QUEUE_DRAIN_WAIT' => '7']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $calls = $run['calls'];
        $this->assertSame(['sleep 7'], $this->sleepsBetween($calls, $this->last($calls, 'queue:pause'), $this->first($calls, 'git fetch')));
        $this->assertStringContainsString('تعذّر سؤالُ التطبيق عن المهامّ الجارية', $run['out']);
    }

    /* ═══ ٩ب · حالُ الخادم بعد السقوط: تُقال، وتبقى على الخادم، ويطويها النشرُ الذي يُكمل ═══ */

    /** ما بين علامتَي حال الخادم في الخرج */
    private function block(string $out): string
    {
        $start = strpos($out, '=== حال الخادم بعد سقوط النشر ===');
        $this->assertNotFalse($start, "لم تُطبع حالُ الخادم:\n".$out);
        $end = strpos($out, '=== نهاية حال الخادم ===', $start);
        $this->assertNotFalse($end);

        return substr($out, $start, $end - $start + strlen('=== نهاية حال الخادم ==='));
    }

    public function test_a_failed_deploy_says_the_state_of_the_server_and_the_safe_steps_and_keeps_them_there(): void
    {
        foreach ([
            'migrate' => [['FAIL_MIGRATE' => '1'], [
                'سقط عند: الهجرات', 'الويب: على ما حُمّل قبل النشر',
                'هجراتٌ جرت في هذا النشر: لا شيء', 'هجراتٌ لم تجرِ: 1 (من القاعدة)', self::B,
                'الطابور: أُطلق كما كان', 'المهامّ المجدولة: موقوفة',
            ]],
            'reload' => [['FAIL_RELOAD' => '1'], [
                'سقط عند: الذاكرة المؤقتة', 'هجراتٌ جرت في هذا النشر: '.self::B, 'هجراتٌ لم تجرِ: 0 (من القاعدة)',
                'المهامّ المجدولة: تجري',
            ]],
            // قبل تثبيت قاعدة السؤال: تُقدَّر الهجراتُ من git، ويُقال إنّها تقدير
            'no state rule' => [['FAIL_MIGRATE' => '1', 'SUDOERS_R14' => '1'], [
                'هجراتٌ لم تجرِ: '.self::B.' (تقديرٌ من git', 'المهامّ المجدولة: موقوفة',
            ]],
        ] as $case => [$env, $says]) {
            $this->server();

            $run = $this->deploy($env);

            $this->assertNotSame(0, $run['rc'], "$case: نجح النشر");
            $this->assertStringContainsString('::error title=سقط النشر::', $run['out'], "$case: لم يُعلَن السقوطُ في Deploy Main");
            $block = $this->block($run['out']);
            foreach ($says as $line) {
                $this->assertStringContainsString($line, $block, "$case: $line");
            }
            $this->assertStringContainsString('الكود على القرص: '.$this->to.' (كان '.$this->from.')', $block, $case);
            $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $block, "$case: لا خطوةَ رجوعٍ آمنة");
            $this->assertStringContainsString('أعِد تشغيلَ Deploy Main', $block, $case);
            // وتبقى على الخادم بعد أن يُطوى السجلّ
            $this->assertSame(trim($block), trim((string) file_get_contents($this->root.'/state/DEPLOY-FAILED.txt')), "$case: لم تُكتب على الخادم كما قيلت");

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    public function test_the_next_deploy_that_completes_resolves_the_failure_and_keeps_its_record(): void
    {
        $this->server();
        $this->assertNotSame(0, $this->deploy(['FAIL_MIGRATE' => '1'])['rc']);
        $record = (string) file_get_contents($this->root.'/state/DEPLOY-FAILED.txt');
        $this->assertNotSame('', $record);

        $run = $this->deploy(['CALLS' => $this->root.'/calls-2.log']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertFileDoesNotExist($this->root.'/state/DEPLOY-FAILED.txt');
        $resolved = glob($this->root.'/state/DEPLOY-FAILED.resolved-*.txt');
        $this->assertCount(1, $resolved, 'مُحي أثرُ السقوط');
        $this->assertSame($record, file_get_contents($resolved[0]));
        $this->assertStringContainsString('نشرٌ سابقٌ سقط وأكمله هذا النشر', $run['out']);
    }

    public function test_the_apps_brief_look_at_the_lock_never_refuses_a_deploy(): void
    {
        $this->server();
        touch($this->root.'/state/deploy.lock');
        // كما يسأل التطبيقُ (`DeployState::deployRunning`): قفلٌ مشتركٌ يُمسك لحظةً ثمّ يُفلت — هنا ثانيةً ونصفًا
        $probe = proc_open(['perl', '-MFcntl=:flock', '-MTime::HiRes=sleep', '-e',
            '$|=1; open(my $l, "<", $ARGV[0]) or exit 2; flock($l, LOCK_SH) or exit 3; print "held\n"; sleep 1.5;', $this->root.'/state/deploy.lock'],
            [1 => ['pipe', 'w']], $pipes);
        $this->assertSame("held\n", fgets($pipes[1]));

        $run = $this->deploy(['DEPLOY_LOCK_WAIT' => '5']);
        proc_close($probe);

        $this->assertSame(0, $run['rc'], "ردّ سؤالُ التطبيق نشرًا:\n".$run['out']);
    }

    /* ═══ ١٠ · الرجوعُ بالكود: يقف ويُطلق مثلَه، ويُطلق دائمًا (لا يغيّر القاعدة) ═══ */

    public function test_the_code_rollback_holds_the_schedule_while_it_swaps_the_code_and_always_frees_it(): void
    {
        foreach (['ok' => [], 'composer' => ['FAIL_COMPOSER' => '1']] as $case => $env) {
            $this->server();
            $app = $this->root.'/app';
            file_put_contents($app.'/app.txt', "b\n");
            $this->git('commit -qam b2', $app);

            $run = $this->script('scripts/rollback-code.sh', [$this->from], $env);

            $this->assertSame('held', $this->holdAt($run['calls'], 'git reset --hard'), $case);
            $this->assertSame('held', $this->holdAt($run['calls'], 'composer install'), $case);
            $this->assertFileDoesNotExist($this->holdFile(), "$case: بقيت المهامُّ موقوفةً بعد الرجوع\n".$run['out']);
            if ($case === 'ok') {
                $this->assertSame(0, $run['rc'], $run['out']);
                $this->assertSame('held', $this->holdAt($run['calls'], 'artisan config:cache'));
                $this->assertSame('free', $this->holdAt($run['calls'], 'queue:resume whatsapp'));
            }

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    public function test_the_code_rollback_waits_for_the_running_job_and_stops_before_the_reset_at_the_limit(): void
    {
        foreach (['waits' => ['BUSY_FOR' => '2'], 'limit' => ['BUSY_FOR' => '999', 'QUEUE_IDLE_TIMEOUT' => '4']] as $case => $env) {
            $this->server();
            $app = $this->root.'/app';
            file_put_contents($app.'/app.txt', "b\n");
            $this->git('commit -qam b2', $app);

            $run = $this->script('scripts/rollback-code.sh', [$this->from], $env);

            $calls = $run['calls'];
            if ($case === 'waits') {
                $this->assertSame(0, $run['rc'], $run['out']);
                $this->assertCount(3, $this->asksBefore($calls, 'git reset --hard'), 'لم يُنتظر حتى انتهت المهمّة');
                $this->assertGreaterThan($this->last($calls, 'queue:pause'), $this->asksBefore($calls, 'git reset --hard')[0]);
            } else {
                $this->assertNotSame(0, $run['rc'], 'مضى الرجوعُ ومهمّةٌ تجري');
                $this->assertSame([], $this->where($calls, 'git reset --hard'), 'تبدّل الكودُ ومهمّةٌ تجري');
                $this->assertCount(1, $this->where($calls, $this->wwwData('queue:resume whatsapp')));
                $this->assertFileDoesNotExist($this->holdFile());
                $this->assertStringContainsString('App\Jobs\SendWhatsAppMessage', $run['out']);
            }

            exec('rm -rf '.escapeshellarg($this->root));
            $this->root = '';
        }
    }

    public function test_the_code_rollback_never_pauses_a_queue_it_cannot_resume(): void
    {
        $this->server();
        $app = $this->root.'/app';
        file_put_contents($app.'/app.txt', "b\n");
        $this->git('commit -qam b2', $app);

        $run = $this->script('scripts/rollback-code.sh', [$this->from], ['SUDOERS_HALF' => '1']);

        $this->assertSame(0, $run['rc'], $run['out']);
        $this->assertSame([], $this->where($run['calls'], 'queue:pause'), 'أُوقف طابورٌ لا يُطلق');
        $this->assertStringContainsString('قاعدةُ إيقاف الطابور غيرُ مثبّتة', $run['out']);
    }

    /* ═══ ١١ · حارسُ الانحراف: كلُّ أمرٍ يُسأل عنه sudo هو أمرٌ يُجرى ومثبَّتٌ في sudoers ═══ */

    public function test_every_command_the_scripts_ask_sudo_about_is_one_they_run_and_is_pinned(): void
    {
        $sudoers = (string) file_get_contents(base_path('deploy/abaad-deploy.sudoers'));
        // سؤالُ الحال مثبَّتٌ بلا وسيطٍ بعده: sudo يطابق السطرَ كما كُتب، فلا يُمرَّر خيار
        $this->assertMatchesRegularExpression('/^\s*\/usr\/bin\/php \/var\/www\/abaad\/artisan abaad\\\\:deploy-state, \\\\$/m', $sudoers, 'abaad:deploy-state غيرُ مثبَّتٍ بلا وسائط');
        foreach (['scripts/deploy.sh', 'scripts/rollback-code.sh'] as $script) {
            $code = (string) file_get_contents(base_path($script));
            preg_match_all('/\bmay ((?:queue:\w+) \w+)/', $code, $m);
            $this->assertCount(4, array_unique($m[1]), "$script: لا يسأل عن أوامر الطابور الأربعة");
            foreach (array_unique($m[1]) as $args) {
                $this->assertMatchesRegularExpression('/^\s*art '.preg_quote($args, '/').'\b/m', $code, "$script يسأل عن $args ولا يُجريه");
                $this->assertStringContainsString('/usr/bin/php /var/www/abaad/artisan '.str_replace(':', '\\:', $args), $sudoers, "بلا قاعدة: $args");
            }
            // وسؤالُ الحال: يُسأل عنه قبل أن يُجرى، ويُجرى بلا وسائط
            $this->assertMatchesRegularExpression('/\bmay abaad:deploy-state &&/', $code, "$script لا يسأل sudo عن abaad:deploy-state");
            $this->assertMatchesRegularExpression('/^\s*art abaad:deploy-state > "\$STATE_OUT"/m', $code, "$script لا يجري abaad:deploy-state");
        }
    }
}
