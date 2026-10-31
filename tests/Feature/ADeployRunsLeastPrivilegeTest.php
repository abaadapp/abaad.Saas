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
        // قاعدةُ www-data: أوامرُ artisan السبعةُ بأعيانها، لا نجمةٌ عامّة
        $this->assertMatchesRegularExpression('/\(www-data\)\s+NOPASSWD:\s*ABAAD_ARTISAN/', $body);
        foreach (['migrate --force', 'config\:cache --quiet', 'route\:cache --quiet', 'view\:cache --quiet',
            'package\:discover --quiet', 'queue\:restart --quiet', 'abaad\:preflight'] as $cmd) {
            $this->assertStringContainsString('/usr/bin/php /var/www/abaad/artisan '.$cmd, $body, "أمرُ artisan غيرُ مثبّت: $cmd");
        }
        // الأسطرُ المنفَّذة وحدها (لا التعليقُ الذي يذكر الممنوعَ كمثال)
        $active = implode("\n", array_filter(
            file(base_path(self::SUDOERS), FILE_IGNORE_NEW_LINES),
            fn ($l) => ! str_starts_with(ltrim($l), '#')
        ));
        $this->assertStringNotContainsString('/var/www/abaad/artisan *', $active, 'artisan عامٌّ بنجمة');
        foreach (['tinker', 'db:wipe', 'migrate:fresh', 'migrate:reset'] as $danger) {
            $this->assertStringNotContainsString($danger, $active, "sudoers يبيح أمرًا خطرًا: $danger");
        }
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

    /** حارسُ الانحراف: كلُّ أمر artisan في السكربتين مثبّتٌ في sudoers، فلا يُضاف أمرٌ بلا قاعدة */
    public function test_every_artisan_call_in_the_scripts_is_pinned_in_sudoers(): void
    {
        $body = (string) file_get_contents(base_path(self::SUDOERS));
        $calls = [];
        foreach (self::SCRIPTS as $script) {
            foreach (file(base_path($script), FILE_IGNORE_NEW_LINES) as $line) {
                // استدعاءُ art (لا تعريفُها)، وتُؤخذ وسائطُه حتى إعادةِ التوجيه أو ||
                if (preg_match('/^\s*art ((?!\(\)).+)$/', $line, $m)) {
                    $args = trim(preg_replace('/\s*(\|\||>|2>|<).*$/', '', $m[1]));
                    $calls[$args] = true;
                }
            }
        }
        $this->assertNotEmpty($calls, 'لم يُعثر على استدعاءات art');
        foreach (array_keys($calls) as $args) {
            // الـ: في sudoers مُهرَّبٌ بـ\
            $needle = '/usr/bin/php /var/www/abaad/artisan '.str_replace(':', '\\:', $args);
            $this->assertStringContainsString($needle, $body, "أمرٌ في السكربت بلا قاعدة sudoers: artisan $args");
        }
    }

    /* ═══ ٣ب · مسحُ الشجرة: الأعماق والروابط والصلاحيّة الفعليّة ═══ */

    public function test_the_audit_scans_subdirectories_symlinks_and_effective_permissions(): void
    {
        $this->root = sys_get_temp_dir().'/abaad-tree-'.bin2hex(random_bytes(4));
        $app = $this->root.'/app';
        foreach (['app/Http/Controllers', 'routes', 'config', 'scripts', 'storage/logs', 'storage/app', 'bootstrap/cache', 'public', '.git/hooks'] as $d) {
            mkdir($app.'/'.$d, 0755, true);
        }
        foreach (['app/Http/Controllers/Deep.php', 'routes/web.php', 'config/app.php', 'scripts/deploy.sh', '.git/config', '.git/hooks/pre-commit'] as $f) {
            file_put_contents($app.'/'.$f, "x\n");
            chmod($app.'/'.$f, 0644);
        }
        // رابطٌ داخليّ سليم (كـpublic/storage)
        symlink('../storage/app', $app.'/public/storage');

        $run = fn () => $this->auditTree($app);

        // نظيفة: لا شيء يكتبه www-data، ولا رابطَ يهرب
        [$rc, $out] = $run();
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('لا مسارَ في الشجرة يكتبه', $out);
        $this->assertStringContainsString('كلُّ رابطٍ داخل الشجرة', $out);

        // ملفٌّ عميقٌ قابلٌ للكتابة للجميع (o+w) يُكتشف
        chmod($app.'/app/Http/Controllers/Deep.php', 0646);
        [$rc, $out] = $run();
        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('app/Http/Controllers/Deep.php', $out);
        chmod($app.'/app/Http/Controllers/Deep.php', 0644);

        // ما تحت storage قابلٌ للكتابة — ويُتجاهَل (مقصود)
        file_put_contents($app.'/storage/logs/laravel.log', "x\n");
        chmod($app.'/storage/logs/laravel.log', 0666);
        [$rc, $out] = $run();
        $this->assertSame(0, $rc, 'عُدَّ ما تحت storage خطأً: '.$out);

        // رابطٌ يهرب خارج الشجرة يُكتشف
        symlink('/etc/passwd', $app.'/public/evil');
        [$rc, $out] = $run();
        $this->assertSame(1, $rc, $out);
        $this->assertStringContainsString('public/evil -> /etc/passwd', $out);
    }

    /** @return array{0:int,1:string} */
    private function auditTree(string $app): array
    {
        $proc = proc_open(
            ['bash', base_path('scripts/audit-deploy-permissions.sh')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['AUDIT_ONLY' => 'tree', 'APP_DIR' => $app, 'APP_USER' => 'nobody', 'PATH' => getenv('PATH')],
        );
        $out = (string) preg_replace('/\e\[[0-9;]*m/', '', stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));

        return [proc_close($proc), $out];
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
