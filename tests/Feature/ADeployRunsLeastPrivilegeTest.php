<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * النشرُ والرجوعُ بأقلِّ صلاحية — لا root، ولا git بصلاحية root، وsudo قاعدتان لا ثالثة.
 *
 * المرحلة 1 من تأمين النشر (فحصُ المرحلة 0، 2026-10-31): كان النشرُ يجري بحساب root
 * في شجرةٍ يملكها www-data، فمن يملك ملفًّا فيها يوجّه أمرَ root. صار النشرُ بحساب
 * abaad-deploy الذي يملك الكود، وأوامرُ Laravel وحدها إلى www-data عبر sudo،
 * وإعادةُ تحميل php-fpm وحدها بصلاحية root.
 *
 * يُحرَس هنا: رفضُ root، وغيابُ git بصلاحية root وsafe.directory، وأنّ ملفَّ sudoers
 * قاعدتان محدّدتان لا ثالثةَ لهما ولا شيءَ لـwww-data، وأنّ فحصَ الصلاحيّات قرائيّ.
 */
class ADeployRunsLeastPrivilegeTest extends TestCase
{
    private const SCRIPTS = ['scripts/deploy.sh', 'scripts/rollback-code.sh'];

    private const SUDOERS = 'deploy/abaad-deploy.sudoers';

    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }
        parent::tearDown();
    }

    /** الأسطرُ المنفَّذة — بلا تعليق */
    private function code(string $path): string
    {
        $lines = file(base_path($path), FILE_IGNORE_NEW_LINES);

        return implode("\n", array_filter($lines, fn ($l) => ! str_starts_with(ltrim($l), '#')));
    }

    /** الأسطرُ المنفَّذة التي تحوي كلمةً */
    private function linesWith(string $path, string $needle): array
    {
        $lines = file(base_path($path), FILE_IGNORE_NEW_LINES);

        return array_values(array_filter(
            $lines,
            fn ($l) => ! str_starts_with(ltrim($l), '#') && str_contains($l, $needle)
        ));
    }

    /* ═══ ١ · يرفض أن يجري بحساب root ═══ */

    public function test_both_scripts_refuse_to_run_as_root(): void
    {
        $this->root = sys_get_temp_dir().'/abaad-lp-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/bin', 0700, true);
        mkdir($this->root.'/app/.git', 0700, true);
        // بديلُ id يقول root (0) — فيُختبر الرفضُ دون أن نكون root فعلًا
        file_put_contents($this->root.'/bin/id', "#!/usr/bin/env bash\ncase \"\$1\" in -u) echo 0;; -un|-n) echo root;; *) echo 0;; esac\n");
        chmod($this->root.'/bin/id', 0700);

        foreach ([
            ['scripts/deploy.sh', [], 'DEPLOY_DETACHED'],
            ['scripts/rollback-code.sh', ['deadbeef'], 'ROLLBACK_DETACHED'],
        ] as [$script, $args, $detach]) {
            $proc = proc_open(
                array_merge(['bash', base_path($script)], $args),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root,
                [
                    'PATH' => $this->root.'/bin:'.getenv('PATH'),
                    'APP_DIR' => $this->root.'/app',
                    'HOME' => $this->root,
                    $detach => '1',
                    'DRY_RUN' => '0',
                ],
            );
            $out = (string) preg_replace('/\e\[[0-9;]*m/', '', stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            $rc = proc_close($proc);

            $this->assertNotSame(0, $rc, "$script مضى بحساب root");
            $this->assertStringContainsString('لا تشغّله بحساب root', $out, $script);
        }
    }

    /* ═══ ٢ · لا git بصلاحية root، ولا safe.directory، ولا chown/chmod ═══ */

    public function test_the_scripts_never_run_git_as_root_or_touch_global_git_config(): void
    {
        foreach (self::SCRIPTS as $script) {
            $code = $this->code($script);
            foreach (['git config --global', 'safe.directory', 'chown', 'chmod', 'sudo -i', 'sudo bash', 'sudo -u root', 'as_app'] as $banned) {
                $this->assertStringNotContainsString($banned, $code, "$script يحوي $banned");
            }
            // حارسُ أنّ حساب النشر يملك الشجرة، وأنّ الرفضَ على id لا EUID (ليُختبر)
            $this->assertStringContainsString('-O "$APP_DIR/.git"', $code, "$script بلا حارس ملكيّة الشجرة");
            $this->assertStringContainsString('id -u', $code, "$script لا يفحص المستخدم بـid");
        }
    }

    public function test_the_only_root_privileged_command_is_the_fpm_reload(): void
    {
        foreach (self::SCRIPTS as $script) {
            foreach ($this->linesWith($script, 'sudo ') as $line) {
                $ok = str_contains($line, 'sudo -n -u "$APP_USER"')   // إلى www-data
                    || str_contains($line, 'sudo -n "$SYSTEMCTL" reload'); // إعادةُ تحميل php-fpm وحدها
                $this->assertTrue($ok, "$script: sudo بصلاحيةٍ غيرِ متوقّعة: ".trim($line));
            }
            // أوامرُ Laravel عبر الدالّة art وحدها، بمسارٍ مطلقٍ لتطابق sudoers
            $this->assertStringContainsString('art() { sudo -n -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan"', $this->code($script), "$script: art ليست كما تتوقّعها sudoers");
            // composer بلا سكربتات، وpackage:discover بحساب www-data (art)
            $this->assertStringContainsString('--no-scripts', $this->code($script), "$script بلا --no-scripts");
            $this->assertStringContainsString('art package:discover', $this->code($script), "$script بلا package:discover بحساب www-data");
        }
    }

    /* ═══ ٣ · ملفُّ sudoers: قاعدتان محدّدتان، ولا شيء لـwww-data ═══ */

    public function test_the_sudoers_file_grants_exactly_two_narrow_rules(): void
    {
        $path = base_path(self::SUDOERS);
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        // قواعدُ المنح وحدها: فيها =(runas)، بخلاف Cmnd_Alias NAME = cmd
        $rules = array_values(array_filter(
            file($path, FILE_IGNORE_NEW_LINES),
            fn ($l) => ! str_starts_with(ltrim($l), '#') && preg_match('/^\s*\S+\s+\S+\s*=\s*\(/', $l)
        ));

        // قاعدتان اثنتان لحساب النشر، لا ثالثة
        $this->assertCount(2, $rules, 'عددُ قواعد sudo ليس اثنتين: '.implode(' | ', $rules));
        foreach ($rules as $r) {
            $this->assertStringContainsString('abaad-deploy', $r, "قاعدةٌ لغير حساب النشر: $r");
            $this->assertStringContainsString('NOPASSWD', $r);
        }
        // قاعدةُ www-data: php artisan وحده، بمسارٍ مطلق
        $this->assertMatchesRegularExpression('/\(www-data\)\s+NOPASSWD:\s*ABAAD_ARTISAN/', $body);
        $this->assertStringContainsString('/usr/bin/php /var/www/abaad/artisan *', $body);
        // قاعدةُ root: إعادةُ تحميل php-fpm وحدها، بلا أيّ نجمة
        $this->assertMatchesRegularExpression('/\(root\)\s+NOPASSWD:\s*ABAAD_FPM_RELOAD/', $body);
        $this->assertStringContainsString('ABAAD_FPM_RELOAD = /usr/bin/systemctl reload php8.4-fpm', $body);
        $this->assertStringNotContainsString('systemctl *', $body, 'systemctl عامّ');
        // لا قاعدةَ لـwww-data، ولا shell بصلاحية root
        $this->assertStringNotContainsString('www-data ALL', $body, 'قاعدةُ sudo لـwww-data');
        foreach (['/bin/bash', '/bin/sh', 'ALL=(ALL', '(ALL:ALL)'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "sudoers يحوي $forbidden");
        }
    }

    public function test_the_sudoers_file_passes_visudo_when_available(): void
    {
        exec('command -v visudo 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('visudo غير موجود هنا — يُفحص على الخادم في الانتقال');
        }
        exec('visudo -cf '.escapeshellarg(base_path(self::SUDOERS)).' 2>&1', $vout, $vrc);
        $this->assertSame(0, $vrc, 'visudo رفض الملفّ: '.implode("\n", $vout));
    }

    /* ═══ ٤ · فحصُ الصلاحيّات قرائيّ ويحتاج root ═══ */

    public function test_the_permission_audit_parses_and_requires_root(): void
    {
        exec('bash -n '.escapeshellarg(base_path('scripts/audit-deploy-permissions.sh')).' 2>&1', $o, $rc);
        $this->assertSame(0, $rc, 'سكربت الفحص لا يُحلَّل: '.implode("\n", $o));

        // لا يكتب شيئًا: لا أمرَ كتابةٍ خارج test/stat/grep
        $code = $this->code('scripts/audit-deploy-permissions.sh');
        foreach (["\n    rm ", 'chown', 'chmod', '> "$', 'install '] as $w) {
            $this->assertStringNotContainsString($w, $code, "سكربت الفحص قد يكتب: $w");
        }

        // مشغَّلًا بغير root يرفض ولا يفحص
        exec('bash '.escapeshellarg(base_path('scripts/audit-deploy-permissions.sh')).' 2>&1; echo rc=$?', $r);
        $this->assertStringContainsString('شغّله بـsudo', implode("\n", $r));
    }

    /* ═══ ٥ · ملفُّ Deploy Main يتّصل بحساب النشر لا root ═══ */

    public function test_the_workflow_connects_as_the_deploy_account_not_root(): void
    {
        $wf = (string) file_get_contents(base_path('.github/workflows/deploy-main.yml'));
        $this->assertStringContainsString('abaad-deploy@165.227.145.219', $wf);
        $this->assertStringNotContainsString('root@165.227.145.219', $wf);
    }
}
