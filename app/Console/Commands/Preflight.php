<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PlatformEmailDomain;
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
        /*
         * صار مانعًا لا تنبيهًا.
         *
         * كان `log` يعني أن التنبيهات والتقارير لا تصل — مزعج ولا يُوقف أحدًا.
         * ثم صارت استعادة كلمة المرور تمرّ من هنا: فمع `log` يقول النظام
         * للتاجر «أرسلنا الرابط» ولا يُرسل شيئًا، ولا باب له غير هذا.
         */
        // نفس الحكم الذي تقرؤه شاشة الدخول ومسار الاستعادة — مصدرٌ واحد،
        // فلا يتخلّف موضعٌ يوم يُضبط SMTP. ويشمل smtp بلا مضيف، وهي حالةٌ
        // كان الفحص القديم يمرّرها ثم تفشل عند المستخدم لا عند من ضبطها
        $this->check(
            'البريد يصل فعلًا (لا سجلّ ولا مُرسِل صامت)',
            \App\Support\Mailer::configured(),
            'لا مُرسِل بريد حقيقي — لا تصل التنبيهات، ولا رابط استعادة كلمة المرور: يقول النظام «أرسلنا» ولا يُرسل'
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

        /*
         * القاعدة تُفرض عند الكتابة لا عند الدخول، فحسابٌ أُنشئ قبلها يبقى
         * عاملًا — ولو مُنع الدخول لأُغلقت اللوحة في وجه صاحبها لحظة النشر.
         * فيُبلَّغ عنه هنا: يُرى ولا يُطرد.
         */
        $outside = User::where('role', 'super_admin')->pluck('email')
            ->reject(fn ($e) => PlatformEmailDomain::matches($e));
        $this->check(
            'بريد مدراء المنصة على نطاق '.PlatformEmailDomain::DOMAIN,
            $outside->isEmpty(),
            'حسابات مدير منصة على بريد خارجي: '.$outside->implode('، ')
                .' — مفتاح المنصّة كلّها معلَّقٌ على حسابٍ لا تملكه',
            // تنبيه لا مانع: قرارٌ يخصّ المالك، ولا يُوقف نشرةً بسببه
            warnOnly: true
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

        /*
         * كان هذا تحذيرًا ثابتًا يُطبع دائمًا: «المجدول لا يعمل من نفسه».
         *
         * وعلى خادم الإنتاج كان المجدول يعمل فعلًا من /etc/cron.d/abaad —
         * فيقرأ الناظر تحذيرًا كاذبًا كل مرّة حتى يتعوّد تخطّيه، ويمرّ معه
         * الصادقُ يومًا. فيُبحث عن السطر حيث يُكتب عادةً بدل افتراض غيابه.
         */
        $hook = $this->schedulerHook();
        if ($hook !== null) {
            $this->check('المجدول موصولٌ بـcron', true, '');
        } else {
            $this->warn2(
                'لم أجد سطر المجدول في cron — تأكّد منه بنفسك:',
                '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1'
            );
        }

        /*
         * الجدولة لا تُثبت أن شيئًا جرى.
         *
         * `backup:run` مجدولٌ يوميًا منذ شهور، ولم يكن في النظام ما يقول إن
         * cron يعمل أصلًا. فيبقى السطر في routes/console.php شاهدَ نيّةٍ لا
         * شاهدَ نسخة — ولا يُكتشف الفرق إلا يوم تُطلب النسخة.
         */
        /*
         * الطابور بلا عامل هو صمتٌ آخر.
         *
         * إشعار «طلب جديد» صار يُوضع في الطابور كي لا ينتظره الكاشير — وهذا
         * يعني أن لا شيء يُرسَل إن لم يكن هناك عاملٌ يسحب. فيتحوّل بطءٌ ظاهر
         * إلى غيابٍ صامت، وهو أسوأ.
         */
        if (config('queue.default') === 'database') {
            $stuck = \Illuminate\Support\Facades\DB::table('jobs')
                ->where('created_at', '<', now()->subMinutes(15)->timestamp)->count();
            $this->check(
                'لا مهام عالقة في الطابور',
                $stuck === 0,
                $stuck.' مهمة عالقة منذ أكثر من ربع ساعة — شغّل عاملًا دائمًا: php artisan queue:work (عبر supervisor أو systemd)',
            );

            /*
             * وجود العامل يُفحص وحده، لا يُستنتج من خلوّ الطابور.
             *
             * كان الفحص السابق يمرّ ✓ على خادمٍ لا عامل فيه إطلاقًا — لا
             * systemd ولا supervisor — لأن الطابور فارغ. وطابورٌ فارغ ليس
             * دليلًا على أن أحدًا يسحب منه، بل على أن شيئًا لم يُصفَّ بعد:
             * أوّل مهمةٍ تُصفّ تبقى إلى الأبد، والإشعار الذي وُضع في الطابور
             * كي لا ينتظره الكاشير لا يصل أحدًا.
             *
             * وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: الثاني يُزعج، والأول يُنيم.
             */
            $worker = $this->queueWorkerRunning();
            if ($worker === false) {
                $this->check(
                    'عاملٌ دائم يسحب من الطابور',
                    false,
                    'لا عامل يعمل على هذا الخادم — أي مهمة تُصفّ ستبقى بلا تنفيذ. '
                    .'شغّله دائمًا عبر systemd أو supervisor: php artisan queue:work --sleep=3 --tries=3',
                );
            } elseif ($worker === null) {
                $this->warn2(
                    'تعذّر التحقّق من عامل الطابور (لا تنفيذ أوامر) — تأكّد بنفسك:',
                    'pgrep -fa "artisan queue:work"',
                );
            } else {
                $this->check('عاملٌ دائم يسحب من الطابور', true, '');
            }

            $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
            $this->check(
                'لا مهام فاشلة في الطابور',
                $failed === 0,
                $failed.' مهمة فاشلة — راجعها: php artisan queue:failed',
                warnOnly: true,
            );
        }

        $stamp = $this->lastBackup();
        $this->check(
            'نسخة احتياطية خلال آخر ٤٨ ساعة',
            $stamp !== null && $stamp['fresh'] && empty($stamp['failed']),
            $stamp === null
                ? 'لم تُنشأ أي نسخة احتياطية قط — تأكّد من cron، وشغّل الآن: php artisan backup:run'
                : (! $stamp['fresh']
                    ? 'آخر نسخة: ' . $stamp['at'] . ' — المجدول متوقّف على الأرجح'
                    : 'آخر تشغيل فشل في ' . count($stamp['failed']) . ' متجرًا — شغّل: php artisan backup:run'),
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

    /** بصمة آخر نسخة احتياطية — يكتبها backup:run */
    private function lastBackup(): ?array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        if (! $disk->exists(BackupRun::STAMP)) {
            return null;
        }

        $data = json_decode((string) $disk->get(BackupRun::STAMP), true);
        $at = $data['finished_at'] ?? null;

        if (! is_array($data) || ! $at) {
            return null;
        }

        return [
            'at' => \Illuminate\Support\Carbon::parse($at)->diffForHumans(),
            // ٤٨ لا ٢٤: تشغيلٌ واحد يتأخّر أو يفوت لا يستحق منعَ إطلاق
            'fresh' => \Illuminate\Support\Carbon::parse($at)->gt(now()->subHours(48)),
            'failed' => $data['failed'] ?? [],
        ];
    }

    /**
     * أين كُتب سطر المجدول — أو null إن لم يُوجد.
     *
     * ثلاثة مواضع لا موضعٌ واحد: `crontab -l` يقرأ جدول المستخدم الحالي
     * وحده، والمجدول يُكتب عادةً في /etc/cron.d باسم المشروع ليعمل بمستخدم
     * الخادم (www-data). فحصٌ يقرأ الأول وحده يُبلّغ عن غيابٍ لا وجود له.
     */
    private function schedulerHook(): ?string
    {
        // الملفات تُقرأ بلا تنفيذ أي أمر — تعمل حتى لو مُنع shell_exec
        foreach (array_merge(['/etc/crontab'], glob('/etc/cron.d/*') ?: []) as $file) {
            if (is_readable($file) && str_contains((string) @file_get_contents($file), 'schedule:run')) {
                return $file;
            }
        }

        foreach (['crontab -l 2>/dev/null', 'systemctl list-timers --all --no-pager 2>/dev/null'] as $cmd) {
            $out = $this->shell($cmd);
            if ($out !== null && str_contains($out, 'schedule')) {
                return $cmd;
            }
        }

        return null;
    }

    /**
     * هل يعمل عاملُ طابورٍ الآن؟ null = تعذّر الفحص، فلا يُدَّعى نفيٌ ولا إثبات.
     *
     * والعدّ بـwc لا قراءة مخرج pgrep مباشرةً: shell_exec يعيد null عند
     * غياب المخرج كما يعيدها عند الفشل، فـ«لا عامل» — وهي الحالة التي كُتب
     * هذا الفحص لأجلها — كانت ستُقرأ «تعذّر الفحص» وتمرّ. وwc يطبع رقمًا
     * دائمًا، فيفترق الصمتان.
     *
     * و[a]rtisan لا artisan: النمط يبحث في أسطر أوامر العمليات، وسطرُ الأمر
     * الذي يبحث يحمل النمط نفسه — فيلتقط pgrep نفسه ويعدّ واحدًا ويُقرأ
     * «يوجد عامل» على خادمٍ لا عامل فيه. القوس يكسر المطابقة الذاتية دون أن
     * يغيّر ما يُطابَق: «[a]rtisan» في سطر البحث ليس «artisan».
     *
     * وقعتُ فيها فعلًا في أول نسخةٍ من هذا الفحص، وهي نفس الطمأنينة الكاذبة
     * التي كُتب لإزالتها.
     */
    private function queueWorkerRunning(): ?bool
    {
        $out = $this->shell('pgrep -f "[a]rtisan queue:(work|listen)" 2>/dev/null | wc -l');

        return $out === null || trim($out) === '' ? null : ((int) trim($out)) > 0;
    }

    /** تنفيذ أمر قراءةٍ — null إن كان shell_exec ممنوعًا في هذه البيئة */
    private function shell(string $cmd): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return null;
        }

        return @shell_exec($cmd);
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
