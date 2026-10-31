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
 * pg_dump وcomposer وnpm وphp وsystemctl وcurl. و`php artisan tinker` يجيب عن
 * «ما الذي جرى من الهجرات» من ملفٍّ يكتبه الاختبار — هو جدولُ migrations هنا.
 *
 * وفي نسخة الاختبار يتغيّر سطران وحدهما، ويُتحقَّق أنّ كلًّا منهما سطرٌ واحدٌ
 * بعينه قبل أن يُبدَّل: فحصُ root، ومسارُ ملفّ الإعداد (ثابتٌ في السكربت، فيُنقل
 * إلى مجلّد المختبر). وتجري بـ`DEPLOY_DETACHED=1` المكتوبةِ في السكربت: هي خارج
 * المستودع، فلا يمسّها السحب.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بلا إعداد، أو `on`: النسخة أوّلًا كما كانت، ولا يُسأل عن الهجرات.
 *   - `off`: لا pg_dump ولا ملفّ نسخة — ويُقال في السجلّ وفي ROLLBACK.txt — وكلُّ
 *     خطوةٍ أخرى تجري بترتيبها، وسطرُ «تمّ:» الذي يقرؤه Deploy Main.
 *   - و`off` مع هجرةٍ لم تجرِ على القاعدة يتوقّف قبل أن يتحرّك الكود: جديدةً كانت
 *     أو قديمةً لم يمسّها هذا النشر. وما لا يُقرأ من القاعدة يوقفه. ويُسأل ثانيةً
 *     بعد السحب، فدمجٌ وصل بين السؤالين لا يمرّ.
 *   - قيمةٌ غيرُ on/off، أو سطران، أو ملفٌّ أو مجلّدٌ يكتبه غيرُ مالكه، أو رابطٌ
 *     رمزيّ: يتوقّف قبل أن يتحرّك الكود. ومتغيّرُ بيئةٍ لا يُبدّل مسارَ الملفّ.
 *   - وفشلُ النسخة أو الهجرة أو الصحّة يوقف النشر حيث وقع، ولا يُعيد تحميل PHP بعد هجرةٍ سقطت.
 */
class ADeployDumpsUnlessTheOwnerWritesOffTest extends TestCase
{
    private const ROOT_CHECK = '[[ $EUID -eq 0 ]] || die "شغّله بـsudo: يحتاج التبديل إلى $APP_USER وإعادة تحميل php-fpm"';

    private const CONF_LINE = "\nDEPLOY_CONF=/etc/abaad/deploy.conf\n";

    private const A = '2026_01_01_000000_a';

    private const B = '2026_02_01_000000_b';

    private string $root = '';

    private string $from = '';

    private string $to = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            @chmod($this->root.'/etc', 0700);
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

    /** مستودعٌ ومنبعُه: الخادمُ على A وفيه هجرة a، ومنبعُه على B — بهجرةٍ جديدة أو بكودٍ وحده */
    private function server(bool $newMigration = true): void
    {
        $this->root = sys_get_temp_dir().'/abaad-deploy-test-'.bin2hex(random_bytes(4));
        foreach (['bin', 'home', 'etc', 'seed/database/migrations'] as $dir) {
            mkdir($this->root.'/'.$dir, 0700, true);
        }

        $this->git('init -q --bare origin.git', $this->root);
        $seed = $this->root.'/seed';
        file_put_contents($seed.'/app.txt', "a\n");
        file_put_contents($seed.'/database/migrations/'.self::A.'.php', "<?php\n");
        $this->git('init -q', $seed);
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm a', $seed);
        $this->git('remote add origin '.escapeshellarg($this->root.'/origin.git'), $seed);
        $this->git('push -q origin HEAD:main', $seed);

        $this->git('clone -q '.escapeshellarg($this->root.'/origin.git').' app', $this->root);
        file_put_contents($this->root.'/app/.env', "DB_CONNECTION=pgsql\nDB_DATABASE=abaad\n");
        $this->from = $this->git('rev-parse --short HEAD', $this->root.'/app');

        if ($newMigration) {
            file_put_contents($seed.'/database/migrations/'.self::B.'.php', "<?php\n");
        } else {
            file_put_contents($seed.'/app.txt', "b\n");
        }
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm b', $seed);
        $this->git('push -q origin HEAD:main', $seed);
        $this->to = $this->git('rev-parse --short HEAD', $seed);

        $log = 'echo "$(basename "$0") $*" >> "$CALLS"';
        // «ما الذي جرى؟» يُجاب من $RAN كما يجيب جدولُ migrations. وAFTER_FIRST_ASK
        // يجري مرّةً بعد أوّل سؤال: دمجٌ يصل بين السؤال والسحب
        $ask = '[ "${FAIL_RAN:-}" = 1 ] && { echo "<<ran"; echo "SQLSTATE[08006] password=s3cret-in-the-error"; exit 1; }; '
            .'echo "<<ran"; [ -n "${PKG_PATH:-}" ] && echo "path:$PKG_PATH"; cat "$RAN" 2>/dev/null; echo "ran>>"; '
            .'if [ -n "${AFTER_FIRST_ASK:-}" ] && [ ! -e "$CALLS.asked" ]; then touch "$CALLS.asked"; bash -c "$AFTER_FIRST_ASK" >/dev/null 2>&1; fi';
        $stubs = [
            'sudo' => '[ "$1" = "-u" ] && shift 2; exec "$@"',
            'pg_dump' => $log.'; [ "${FAIL_DUMP:-}" = 1 ] && exit 1; echo dumped',
            'composer' => $log,
            'npm' => $log.'; case "$*" in *"run build"*) for a in "$@"; do [ "$p" = "--outDir" ] && { mkdir -p "$a"; touch "$a/manifest.json"; }; p="$a"; done;; esac',
            'php' => $log.'; case "$*" in *getRan*) '.$ask.';; *tinker*) echo pgsql;; *"migrate --force"*) [ "${FAIL_MIGRATE:-}" = 1 ] && { echo "SQLSTATE: boom"; exit 1; }; echo migrated;; esac',
            'systemctl' => $log,
            'curl' => $log.'; printf "%s" "${HEALTH_CODE:-200}"',
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->root.'/bin/'.$name, "#!/usr/bin/env bash\n".$body."\n");
            chmod($this->root.'/bin/'.$name, 0700);
        }

        // نسخةُ الاختبار: فحصُ root ومسارُ الإعداد وحدهما يتبدّلان — والباقي حرفًا بحرف
        $script = file_get_contents(base_path('scripts/deploy.sh'));
        $this->assertSame(1, substr_count($script, self::ROOT_CHECK), 'تغيّر فحصُ root — راجع المختبر');
        $this->assertSame(1, substr_count($script, self::CONF_LINE), 'تغيّر سطرُ مسار الإعداد — راجع المختبر');
        $this->assertSame(1, substr_count($script, 'DEPLOY_CONF='), 'يُعاد تعيينُ مسار الإعداد في موضعٍ آخر');
        $script = str_replace(self::ROOT_CHECK, ': # فحصُ root مُعطَّلٌ في المختبر وحده', $script);
        $script = str_replace(self::CONF_LINE, "\nDEPLOY_CONF=".$this->confPath()."\n", $script);
        file_put_contents($this->root.'/deploy.sh', $script);
    }

    private function confPath(): string
    {
        return $this->root.'/etc/deploy.conf';
    }

    private function conf(string $text, int $mode = 0600): void
    {
        $this->clearConf();
        file_put_contents($this->confPath(), $text);
        chmod($this->confPath(), $mode);
    }

    private function clearConf(): void
    {
        $path = $this->confPath();
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            rmdir($path);
        }
    }

    /** ما جرى على «القاعدة» — جدولُ migrations في المختبر */
    private function ran(string ...$names): void
    {
        file_put_contents($this->root.'/ran.txt', $names === [] ? '' : implode("\n", $names)."\n");
    }

    /** @return array{rc: int, out: string, calls: list<string>, head: string} */
    private function deploy(array $env = []): array
    {
        @unlink($this->root.'/calls.log');
        @unlink($this->root.'/calls.log.asked');
        $proc = proc_open(['bash', $this->root.'/deploy.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HOME' => $this->root.'/home',
            'APP_DIR' => $this->root.'/app',
            'APP_USER' => (string) get_current_user(),
            'BACKUP_DIR' => $this->root.'/pre-deploy',
            'HEALTH_URL' => 'http://health.test',
            'CALLS' => $this->root.'/calls.log',
            'RAN' => $this->root.'/ran.txt',
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

    /** مواضعُ السؤال عن الهجرات التي جرت */
    private function asks(array $calls): array
    {
        return array_keys(array_filter($calls, fn ($call) => str_contains($call, 'getRan')));
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

    /** توقّف قبل أن يتحرّك الكود: لا سحبَ ولا composer ولا بناءَ ولا هجرةَ ولا نسخة */
    private function assertNothingMoved(array $run, string $why): void
    {
        $this->assertNotSame(0, $run['rc'], "مضى النشر: $why\n".$run['out']);
        $this->assertSame($this->from, $run['head'], "تحرّك الكود: $why");
        foreach (['pg_dump', 'composer', 'npm', 'php artisan migrate', 'systemctl'] as $step) {
            $this->assertSame(-1, $this->at($run['calls'], $step), "جرت $step: $why");
        }
        $this->assertSame([], $this->dumps());
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }

    /* ═══ ١ · النسخةُ كما كانت ═══ */

    public function test_without_a_setting_or_with_on_it_dumps_before_anything_moves(): void
    {
        $this->server();
        foreach ([null, "PRE_DEPLOY_BACKUP=on\n"] as $i => $setting) {
            if ($i === 1) {
                // نشرٌ ثانٍ من A — بإعدادٍ صريح. ونسخةُ الأوّل تُزال: اسمُ النسخة
                // بالثانية، ونشران في ثانيةٍ واحدة (CI سريعة) يكتبان الملفَّ نفسه
                $this->git('reset -q --hard '.$this->from, $this->root.'/app');
                array_map('unlink', $this->dumps());
                $this->conf($setting);
            }
            $run = $this->deploy();

            $this->assertDeployedInOrder($run);
            $this->assertCount(1, $this->dumps(), 'لا ملفَّ نسخة');
            $dump = $this->at($run['calls'], 'pg_dump');
            $this->assertGreaterThanOrEqual(0, $dump, 'لم تجرِ النسخة');
            $this->assertLessThan($this->at($run['calls'], 'composer install'), $dump, 'جرت خطوةٌ قبل النسخة');
            // ومع النسخة تجري الهجرةُ الجديدة كما كانت — لا يُسأل عنها
            $this->assertSame([], $this->asks($run['calls']), 'سُئل عن الهجرات والنسخةُ قائمة');
            $this->assertStringNotContainsString('لا نسخة', $run['out']);
            $this->assertStringContainsString('.dump', (string) file_get_contents($this->root.'/pre-deploy/ROLLBACK.txt'));
        }
    }

    /* ═══ ٢ · off: لا نسخة — والباقي كلُّه ═══ */

    public function test_off_skips_only_the_dump_and_says_so_in_the_log_and_the_rollback_note(): void
    {
        $this->server(newMigration: false);
        $this->ran(self::A);
        $this->conf("# قرار المالك\nOTHER=1\nPRE_DEPLOY_BACKUP = off \n");

        $run = $this->deploy();

        $this->assertDeployedInOrder($run);
        $this->assertSame(-1, $this->at($run['calls'], 'pg_dump'), 'جرت النسخةُ وهي مُطفأة');
        $this->assertSame([], $this->dumps());
        $this->assertStringNotContainsString('du:', $run['out'], 'قيس حجمُ نسخةٍ لم تُكتب');
        $this->assertStringContainsString('لا نسخة لهذا النشر: PRE_DEPLOY_BACKUP=off في '.$this->confPath(), $run['out']);
        $this->assertStringContainsString('نُشر بلا نسخة: PRE_DEPLOY_BACKUP=off', $run['out']);

        // سُئلت القاعدةُ مرّتين: قبل السحب، وبعد composer قبل البناء
        $asks = $this->asks($run['calls']);
        $this->assertCount(2, $asks);
        $this->assertLessThan($this->at($run['calls'], 'composer install'), $asks[0]);
        $this->assertGreaterThan($this->at($run['calls'], 'composer install'), $asks[1]);
        $this->assertLessThan($this->at($run['calls'], 'npm ci'), $asks[1]);
        $this->assertStringContainsString('لا هجرةَ معلّقة: القاعدةُ على كلّ هجرات '.$this->to, $run['out']);

        $note = (string) file_get_contents($this->root.'/pre-deploy/ROLLBACK.txt');
        $this->assertStringContainsString('النسخة: لا نسخة لهذا النشر (PRE_DEPLOY_BACKUP=off', $note);
        $this->assertStringContainsString('scripts/rollback-code.sh '.$this->from, $note);
    }

    /* ═══ ٣ · off وهجرةٌ لم تجرِ: يتوقّف قبل أن يتحرّك شيء ═══ */

    public function test_off_stops_before_anything_moves_when_the_new_code_brings_a_migration(): void
    {
        $this->server();
        $this->ran(self::A);
        $this->conf("PRE_DEPLOY_BACKUP=off\n");

        $run = $this->deploy();

        $this->assertNothingMoved($run, 'هجرةٌ جديدة والنسخةُ مطفأة');
        $this->assertStringContainsString("هجراتٌ لم تجرِ على القاعدة، والنسخةُ مطفأة (PRE_DEPLOY_BACKUP=off في {$this->confPath()}):\n    ".self::B."\n", $run['out']);
        $this->assertStringNotContainsString('    '.self::A, $run['out'], 'سُمّيت هجرةٌ جرت');
        $this->assertStringContainsString('راجِع هذه الهجرات، ثمّ اكتب PRE_DEPLOY_BACKUP=on وأعِد النشر', $run['out']);
    }

    public function test_off_stops_for_a_migration_that_never_ran_even_when_this_deploy_does_not_touch_its_file(): void
    {
        $this->server(newMigration: false);
        $this->ran();
        $this->conf("PRE_DEPLOY_BACKUP=off\n");

        // ما يراه git diff: لا هجرةَ في هذا النشر
        $this->assertSame('', $this->git("diff --name-only {$this->from} {$this->to} -- database/migrations", $this->root.'/seed'));

        $run = $this->deploy();

        $this->assertNothingMoved($run, 'هجرةٌ قديمة لم تجرِ قطّ');
        $this->assertStringContainsString(":\n    ".self::A."\n", $run['out']);
    }

    public function test_off_stops_when_the_database_cannot_say_what_ran_or_a_package_adds_migrations(): void
    {
        $this->server(newMigration: false);
        $this->ran(self::A);
        $this->conf("PRE_DEPLOY_BACKUP=off\n");

        $run = $this->deploy(['FAIL_RAN' => '1']);
        $this->assertNothingMoved($run, 'القاعدةُ لا تُقرأ');
        $this->assertStringContainsString('تعذّر أن تُقرأ من القاعدة الهجراتُ التي جرت', $run['out']);
        // سجلُّ Deploy Main عامّ: خطأُ الاتّصال لا يُطبع
        $this->assertStringNotContainsString('s3cret-in-the-error', $run['out']);

        $run = $this->deploy(['PKG_PATH' => '/var/www/abaad/vendor/x/migrations']);
        $this->assertNothingMoved($run, 'حزمةٌ تسجّل هجرات');
        $this->assertStringContainsString('حزمةٌ تسجّل هجراتٍ من خارج database/migrations', $run['out']);
    }

    public function test_off_asks_again_after_the_pull_so_a_merge_that_lands_meanwhile_cannot_slip_a_migration_through(): void
    {
        $this->server(newMigration: false);
        $this->ran(self::A);
        $this->conf("PRE_DEPLOY_BACKUP=off\n");
        $seed = escapeshellarg($this->root.'/seed');

        // يُدمج C بهجرته بعد أن سُئل عن B وقبل أن يُسحب
        $run = $this->deploy(['AFTER_FIRST_ASK' => "cd $seed && printf '<?php\\n' > database/migrations/2026_03_01_000000_c.php"
            .' && git add -A && git -c user.name=t -c user.email=t@t commit -qm c && git push -q origin HEAD:main']);
        $c = $this->git('rev-parse --short HEAD', $this->root.'/seed');

        $this->assertNotSame(0, $run['rc'], $run['out']);
        $this->assertSame($c, $run['head'], 'لم يقع الدمجُ بين السؤالين — المختبر لم يختبر شيئًا');
        $this->assertCount(2, $this->asks($run['calls']));
        $this->assertStringContainsString("سُحب ما لم يُسأل عنه — هجراتٌ لم تجرِ والنسخةُ مطفأة:\n    2026_03_01_000000_c\n", $run['out']);
        foreach (['npm', 'php artisan migrate', 'php artisan config:cache', 'systemctl'] as $step) {
            $this->assertSame(-1, $this->at($run['calls'], $step), "جرت $step بعد هجرةٍ لم يُسأل عنها");
        }
        $this->assertStringNotContainsString('تمّ:', $run['out']);
    }

    /* ═══ ٤ · إعدادٌ لا يُفهم أو لا يُوثق به: يتوقّف قبل أي لمسة ═══ */

    public function test_a_setting_that_is_not_plainly_on_or_off_stops_before_the_code_moves(): void
    {
        $this->server();
        $real = $this->root.'/elsewhere.conf';
        $cases = [
            'of' => [fn () => $this->conf("PRE_DEPLOY_BACKUP=of\n"), 'on أو off وحدهما'],
            'OFF' => [fn () => $this->conf("PRE_DEPLOY_BACKUP=OFF\n"), 'on أو off وحدهما'],
            'فارغ' => [fn () => $this->conf("PRE_DEPLOY_BACKUP=\n"), 'on أو off وحدهما'],
            'سطران' => [fn () => $this->conf("PRE_DEPLOY_BACKUP=off\nPRE_DEPLOY_BACKUP=on\n"), 'مرّات'],
            'ملفٌّ يكتبه غيرُه' => [fn () => $this->conf("PRE_DEPLOY_BACKUP=off\n", 0664), 'يكتبه غيرُ مالكه'],
            'مجلّدٌ يكتبه غيرُه' => [function () {
                $this->conf("PRE_DEPLOY_BACKUP=off\n");
                chmod($this->root.'/etc', 0775);
            }, $this->root.'/etc يكتبه غيرُ مالكه'],
            'رابطٌ رمزيّ' => [function () use ($real) {
                $this->clearConf();
                file_put_contents($real, "PRE_DEPLOY_BACKUP=off\n");
                chmod($real, 0600);
                symlink($real, $this->confPath());
            }, 'رابطٌ رمزيّ'],
            'رابطٌ إلى لا شيء' => [function () {
                $this->clearConf();
                symlink($this->root.'/nothing.conf', $this->confPath());
            }, 'رابطٌ رمزيّ'],
            'مجلّدٌ لا ملفّ' => [function () {
                $this->clearConf();
                mkdir($this->confPath(), 0700);
            }, 'ليس ملفًّا عاديًّا'],
        ];

        foreach ($cases as $name => [$arrange, $says]) {
            chmod($this->root.'/etc', 0700);
            $arrange();

            $run = $this->deploy();

            $this->assertNotSame(0, $run['rc'], "مضى النشر بإعداد: $name");
            $this->assertStringContainsString($says, $run['out'], $name);
            $this->assertSame($this->from, $run['head'], "تحرّك الكودُ قبل أن يُفهم الإعداد: $name");
            $this->assertSame([], $run['calls'], "جرت خطوةٌ قبل أن يُفهم الإعداد: $name");
            $this->assertSame([], $this->dumps());
        }
    }

    public function test_no_environment_variable_moves_the_setting_so_none_can_turn_the_dump_off(): void
    {
        $this->server();
        $elsewhere = $this->root.'/elsewhere.conf';
        file_put_contents($elsewhere, "PRE_DEPLOY_BACKUP=off\n");
        chmod($elsewhere, 0600);

        $run = $this->deploy(['DEPLOY_CONF' => $elsewhere, 'CONF_DIR' => $this->root, 'BACKUP_MODE' => 'off']);

        $this->assertDeployedInOrder($run);
        $this->assertCount(1, $this->dumps(), 'أطفأ متغيّرُ بيئةٍ النسخة');
        $this->assertStringNotContainsString('لا نسخة', $run['out']);
    }

    /* ═══ ٥ · الفشلُ يوقفه حيث وقع ═══ */

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
        $this->server(newMigration: false);
        $this->ran(self::A);
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
        $this->server(newMigration: false);
        $this->ran(self::A);
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
