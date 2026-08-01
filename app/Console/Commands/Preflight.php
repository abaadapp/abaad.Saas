<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * فحص ما قبل الإطلاق — يقرأ الحالة الفعلية للنظام ولا يفترض شيئًا.
 *
 *   php artisan abaad:preflight
 *
 * كل بند يقول ماذا فحص وبماذا خرج، حتى يكون الفشل قابلًا للتصرّف لا مجرّد ✗.
 * الخروج بـ1 عند وجود أي خطأ حرج، ليصلح للاستخدام في خط نشر آلي.
 */
class Preflight extends Command
{
    protected $signature = 'abaad:preflight';

    protected $description = 'فحص جاهزية النظام للإطلاق: الإعدادات والأمان والأصول والبيانات';

    private array $fail = [];

    private array $warn = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>فحص ما قبل الإطلاق — أبعاد</>');
        $this->newLine();

        $this->section('البيئة والإعدادات');
        $this->check(
            'APP_ENV = production',
            app()->environment('production'),
            'القيمة الحالية: ' . app()->environment() . ' — اضبط APP_ENV=production في .env'
        );
        $this->check(
            'APP_DEBUG مُطفأ',
            ! config('app.debug'),
            'التصحيح مفتوح: أي خطأ يعرض مسارات الملفات وقيم .env للزائر. اضبط APP_DEBUG=false'
        );
        $this->check('APP_KEY مضبوط', ! empty(config('app.key')), 'شغّل: php artisan key:generate');
        $this->check(
            'APP_URL نطاق حقيقي بـhttps',
            str_starts_with((string) config('app.url'), 'https://') && ! str_contains((string) config('app.url'), 'localhost'),
            'القيمة الحالية: ' . config('app.url') . ' — الروابط في الفواتير والبريد تُبنى منها'
        );
        $this->check(
            'كوكي الجلسة محصور بـhttps',
            (bool) config('session.secure'),
            'اضبط SESSION_SECURE_COOKIE=true وإلا انتقلت الجلسة على http مكشوفة',
            warnOnly: ! str_starts_with((string) config('app.url'), 'https://')
        );

        $this->section('الأبواب المفتوحة');
        $this->check(
            'الدخول التجريبي غير مسجَّل',
            ! Route::has('demo.login'),
            'مسار /demo-login يمنح جلسة مدير منصة بلا كلمة مرور — يجب ألّا يوجد في الإنتاج'
        );
        $this->check(
            'البريد ليس على السجلّ فقط',
            config('mail.default') !== 'log',
            'MAIL_MAILER=log يعني أن التنبيهات والتقارير لا تصل أحدًا',
            warnOnly: true
        );

        $this->section('الحسابات');
        $weak = User::whereIn('role', ['super_admin', 'admin'])->get()
            ->filter(fn ($u) => Hash::check('password', $u->password) || Hash::check('test1234', $u->password))
            ->pluck('email');
        $this->check(
            'لا حساب إداري بكلمة مرور افتراضية',
            $weak->isEmpty(),
            'حسابات بكلمة مرور معروفة: ' . $weak->implode('، ')
        );
        $this->check(
            'يوجد مدير منصة واحد على الأقل',
            User::where('role', 'super_admin')->exists(),
            'لا يمكن الدخول إلى لوحة المنصة — أنشئ حسابًا عبر البذرة أو tinker'
        );

        $this->section('البيانات');
        // بالبريد لا بالاسم: اسم المتجر معروض للعملاء وقابل للتغيير من اللوحة
        $test = TestStore::find();
        $this->check(
            'لا متجر تجريبي في القاعدة',
            ! $test,
            'المتجر التجريبي ما زال موجودًا (id=' . ($test->id ?? '?') . ') — احذفه: php artisan abaad:test-store --drop'
        );

        $this->section('الأصول والذاكرة المؤقتة');
        $manifest = public_path('build/manifest.json');
        $this->check('أصول الواجهة مبنيّة', file_exists($manifest), 'شغّل: npm ci && npm run build');
        $this->check(
            'رابط التخزين العام موجود',
            file_exists(public_path('storage')),
            'شغّل: php artisan storage:link — بدونه لا تظهر الشعارات وصور المنتجات'
        );
        $this->check(
            'الإعدادات مُخزَّنة مؤقتًا',
            file_exists(base_path('bootstrap/cache/config.php')),
            'شغّل: php artisan config:cache route:cache view:cache',
            warnOnly: true
        );

        $this->section('المهام المجدولة');
        $this->warn2(
            'المجدول لا يعمل من نفسه — تأكّد من سطر cron:',
            '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1'
        );

        /* ------------------------------- الخلاصة ------------------------------- */
        $this->newLine();
        if ($this->fail) {
            $this->line('  <fg=red;options=bold>✗ غير جاهز — ' . count($this->fail) . ' مانع:</>');
            foreach ($this->fail as $f) {
                $this->line('    • ' . $f);
            }
        } else {
            $this->line('  <fg=green;options=bold>✓ جاهز للإطلاق</>');
        }
        if ($this->warn) {
            $this->newLine();
            $this->line('  <fg=yellow>تنبيهات (' . count($this->warn) . ') — لا تمنع الإطلاق:</>');
            foreach ($this->warn as $w) {
                $this->line('    • ' . $w);
            }
        }
        $this->newLine();

        return $this->fail ? self::FAILURE : self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line("  <options=bold>{$title}</>");
    }

    private function check(string $label, bool $ok, string $fix, bool $warnOnly = false): void
    {
        if ($ok) {
            $this->line("    <fg=green>✓</> {$label}");

            return;
        }

        if ($warnOnly) {
            $this->line("    <fg=yellow>!</> {$label}");
            $this->warn[] = $fix;

            return;
        }

        $this->line("    <fg=red>✗</> {$label}");
        $this->fail[] = $fix;
    }

    private function warn2(string $label, string $detail): void
    {
        $this->line("    <fg=yellow>!</> {$label}");
        $this->line("      <fg=gray>{$detail}</>");
    }
}
