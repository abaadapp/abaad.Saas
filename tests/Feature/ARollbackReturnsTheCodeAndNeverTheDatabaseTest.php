<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الرجوعُ بعد نشرٍ سيّئ رجوعُ كود — والقاعدةُ الحيّة لا تُستعاد فوقها.
 *
 * ═══ ما كان ═══
 *
 * حين يفشل فحصُ الموقع بعد النشر كان `deploy.sh` يطبع «أرجِع فورًا» وتحتها
 * `pg_restore -c -d abaad <نسخة ما قبل النشر>`. من اتّبعها في عجلةٍ محا كلَّ
 * طلبٍ ودفعةٍ وقيدٍ وحركةِ مخزونٍ بعد النسخة، وأعاد عدّادات الفواتير فتكرّرت
 * أرقامُها، وبقي مالُ Paymob المحصَّل بلا طلب. وكانت الذاكرةُ في سطرٍ واحد
 * (`config:cache route:cache view:cache`) يُرفض، ولا بناءَ للواجهة.
 *
 * ═══ ما صار ═══
 *
 *   - `scripts/rollback-code.sh <commit>`: يُرجع الكود ويبني الواجهة جانبًا
 *     والذاكرةَ أمرًا أمرًا ويعيد تحميل php-fpm — ولا يمسّ القاعدة. ويرفض
 *     حين تكون بين الحاليّ والهدف هجرة، أو في الشجرة تعديلٌ غير مودَع.
 *     و`DRY_RUN=1` يفحص ويقول ما سيفعل.
 *   - `deploy.sh` و`ROLLBACK.txt` يدلّان عليه وحده.
 *
 * والفحصُ هنا على السكربت نفسه في مستودعٍ مؤقّت — بلا خادمٍ ولا sudo.
 */
class ARollbackReturnsTheCodeAndNeverTheDatabaseTest extends TestCase
{
    private const SCRIPTS = ['scripts/deploy.sh', 'scripts/rollback-code.sh'];

    private string $repo;

    protected function tearDown(): void
    {
        if (isset($this->repo) && is_dir($this->repo)) {
            exec('rm -rf '.escapeshellarg($this->repo));
        }

        parent::tearDown();
    }

    /** ما يُنفَّذ أو يُطبع — بلا أسطر التعليق */
    private function code(string $path): string
    {
        $lines = file(base_path($path), FILE_IGNORE_NEW_LINES);

        return implode("\n", array_filter($lines, fn ($l) => ! str_starts_with(ltrim($l), '#')));
    }

    public function test_no_script_restores_or_rolls_back_the_live_database(): void
    {
        foreach (self::SCRIPTS as $script) {
            $code = $this->code($script);
            foreach (['pg_restore', 'migrate:rollback', 'migrate:fresh', 'migrate:reset', 'db:wipe'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "$script يأمر بـ$forbidden");
            }
        }
    }

    public function test_both_scripts_parse(): void
    {
        foreach (self::SCRIPTS as $script) {
            exec('bash -n '.escapeshellarg(base_path($script)).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "$script: ".implode("\n", $out));
        }
    }

    public function test_a_failed_deploy_and_its_rollback_note_point_to_the_code_rollback(): void
    {
        $deploy = $this->code('scripts/deploy.sh');

        $this->assertSame(2, substr_count($deploy, 'scripts/rollback-code.sh'), 'رسالةُ الفشل وROLLBACK.txt كلتاهما');
        $this->assertStringContainsString('Revert', $deploy);
    }

    /* ═══════════ السكربت نفسُه في مستودعٍ مؤقّت ═══════════ */

    /** @return array{0: int, 1: string} */
    private function rollback(string ...$args): array
    {
        return $this->rollbackWith([], ...$args);
    }

    /** @param  array<string, string>  $env */
    private function rollbackWith(array $env, string ...$args): array
    {
        $proc = proc_open(
            array_merge(['bash', base_path('scripts/rollback-code.sh')], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repo,
            $env + ['DRY_RUN' => '1', 'APP_DIR' => $this->repo, 'TMPDIR' => $this->repo, 'PATH' => getenv('PATH'), 'HOME' => $this->repo],
        );
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    private function git(string $command): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($this->repo).' && git -c user.name=t -c user.email=t@t '.$command.' 2>&1'));
    }

    /** @return array{a: string, b: string, c: string} */
    private function history(): array
    {
        $this->repo = sys_get_temp_dir().'/abaad-rollback-test-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/database/migrations', 0777, true);
        $this->git('init -q');

        file_put_contents($this->repo.'/app.txt', "v1\n");
        file_put_contents($this->repo.'/database/migrations/2026_01_01_000000_a.php', "<?php\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm a');
        $a = $this->git('rev-parse --short HEAD');

        file_put_contents($this->repo.'/app.txt', "v2\n");
        $this->git('commit -qam b');
        $b = $this->git('rev-parse --short HEAD');

        file_put_contents($this->repo.'/database/migrations/2026_02_01_000000_c.php', "<?php\n");
        $this->git('add -A && git -c user.name=t -c user.email=t@t commit -qm c');
        $c = $this->git('rev-parse --short HEAD');

        return compact('a', 'b', 'c');
    }

    public function test_without_a_migration_between_them_it_returns_the_code_only(): void
    {
        $h = $this->history();
        $this->git('checkout -q '.$h['b']);

        [$rc, $out] = $this->rollback($h['a']);

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('لا هجرةَ بين', $out);
        $this->assertStringContainsString('git reset --hard '.$h['a'], $out);
        $this->assertStringContainsString('تجربةٌ جافّة', $out);
        $this->assertSame($h['b'], $this->git('rev-parse --short HEAD'), 'التجربةُ الجافّة غيّرت الكود');
    }

    public function test_a_migration_between_them_stops_it(): void
    {
        $h = $this->history();

        [$rc, $out] = $this->rollback($h['b']);

        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('هجرات', $out);
        $this->assertStringContainsString('2026_02_01_000000_c.php', $out);
        $this->assertSame($h['c'], $this->git('rev-parse --short HEAD'));
    }

    /**
     * هجرةٌ إضافيّةٌ درسها صاحبُها فسمّاها — يُرجَع الكودُ وتبقى جداولُها.
     *
     * وتسميةُ غيرِها لا تفتح الباب: كلُّ هجرةٍ تُعبَر تُسمّى باسمها.
     */
    public function test_a_named_additive_migration_lets_the_code_go_back_and_keeps_its_tables(): void
    {
        $h = $this->history();

        [$rc, $out] = $this->rollbackWith(['ALLOW_MIGRATIONS' => '2026_02_01_000000_c.php'], $h['b']);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('سمّاها ALLOW_MIGRATIONS', $out);
        $this->assertStringContainsString('git reset --hard '.$h['b'], $out);
        $this->assertSame($h['c'], $this->git('rev-parse --short HEAD'), 'التجربةُ الجافّة غيّرت الكود');

        [$rc, $out] = $this->rollbackWith(['ALLOW_MIGRATIONS' => '2026_01_01_000000_a.php'], $h['b']);
        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('2026_02_01_000000_c.php', $out);
    }

    public function test_it_will_not_erase_an_uncommitted_change_or_guess_a_commit(): void
    {
        $h = $this->history();
        $this->git('checkout -q '.$h['b']);

        file_put_contents($this->repo.'/app.txt', "edited on the server\n");
        [$rc, $out] = $this->rollback($h['a']);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('تعديلٌ غير مودَع', $out);
        $this->assertSame("edited on the server\n", file_get_contents($this->repo.'/app.txt'));
        $this->git('checkout -q -- app.txt');

        $this->assertSame(1, $this->rollback('deadbeef')[0], 'إيداعٌ غير معروف');
        $this->assertSame(1, $this->rollback($h['b'])[0], 'الكودُ عليه أصلًا');
        $this->assertSame(1, $this->rollback()[0], 'بلا إيداع');
    }
}
