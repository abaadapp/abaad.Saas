<?php

namespace App\Console\Commands;

use App\Models\PurgeRun;
use App\Support\Purge\Cipher;
use App\Support\Purge\Reader;
use App\Support\Purge\Vault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * استعادةُ أرشيفِ شركةٍ محذوفة — قراءةً، أو إعادةً إلى قاعدةٍ فارغة.
 *
 *     php artisan purge:restore 3                      # يقرأ ويتحقّق ولا يكتب
 *     php artisan purge:restore --archive=/path/a.zip.enc
 *     php artisan purge:restore 3 --into=restore_test  # يُعيد الدفاتر فعلًا
 *
 * ═══ ولمَ أمرٌ في السطر لا شاشةٌ في اللوحة ═══
 *
 * هذا لا يُطلب في دوامٍ عاديّ. يُطلب مرّتين: مرّةً **قبل** أن يُفتح الحذفُ
 * — ليُثبت أنّ النسخةَ تُستعاد لا أنّها تُفتح؛ ومرّةً بعد سنينَ حين يسأل
 * محاسبٌ أو مفتّشٌ ضريبيٌّ عن دفاترِ شركةٍ زالت. وكلتاهما بيدِ من يملك
 * الخادمَ، لا بزرٍّ في شاشةٍ يُضغط سهوًا.
 *
 * ═══ ولا يُكتب في قاعدةٍ عاملة ═══
 *
 * الإعادةُ تُدخل صفوفًا بمعرِّفاتها الأصليّة. ثلاثةُ حرّاسٍ قبلها:
 * لا في الإنتاج بحال، ولا في اتّصالٍ يُشير إلى قاعدةِ الافتراض، ولا في
 * جدولٍ فيه صفٌّ واحد. وبلا `--into` لا يُكتب حرفٌ أصلًا.
 */
class PurgeRestore extends Command
{
    protected $signature = 'purge:restore
        {run? : رقم عمليّة المحو كما في شاشة الأرشيف}
        {--archive= : مسارُ ملفّ أرشيفٍ صريح (.zip أو .zip.enc)}
        {--into= : اسمُ اتّصالٍ من config/database.php تُعاد فيه الدفاتر}
        {--keep : أبقِ الملفّ المفكوك وقل مساره}';

    protected $description = 'قراءة أرشيف شركة محذوفة والتحقّق منه، وإعادته إلى قاعدة اختبارية فارغة';

    public function handle(): int
    {
        try {
            [$zip, $temp] = $this->source();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->work($zip);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temp) {
                $this->option('keep')
                    ? $this->line('  الملفّ المفكوك: '.$zip)
                    : @unlink($zip);
            }
        }
    }

    /**
     * من أين يُقرأ — ومتى يكون المسارُ المعادُ مؤقّتًا يُمحى.
     *
     * @return array{0:string,1:bool}
     */
    private function source(): array
    {
        $path = (string) $this->option('archive');
        $run = $this->argument('run');

        if ($path !== '' && $run !== null) {
            throw new RuntimeException('اختر رقمَ عمليّةٍ أو مسارَ ملفّ — لا الاثنين.');
        }

        if ($path !== '') {
            if (! is_file($path)) {
                throw new RuntimeException('لا ملفَّ بهذا المسار: '.$path);
            }

            if (! str_ends_with($path, '.enc')) {
                return [$path, false];
            }

            $out = tempnam(sys_get_temp_dir(), 'abaad-restore-');

            if ($out === false) {
                throw new RuntimeException('تعذّر تهيئة ملفّ مؤقّت.');
            }

            /* والتوقيعُ يُقرأ قبل أن يُكتب نصٌّ صريح — انظر `Cipher::decrypt` */
            Cipher::decrypt($path, $out);

            return [$out, true];
        }

        if ($run === null) {
            throw new RuntimeException('قل رقمَ عمليّة المحو، أو --archive=مسار.');
        }

        $row = PurgeRun::find((int) $run);

        if ($row === null) {
            throw new RuntimeException('لا عمليّةَ محوٍ بهذا الرقم: '.$run);
        }

        /* `Vault::open` يُقدّم المحلّيَّ إن طابقت بصمتُه، وإلّا أنزل البعيد */
        return [Vault::open($row), true];
    }

    private function work(string $zip): int
    {
        $read = Reader::read($zip);
        $m = $read['manifest'];

        $this->newLine();
        $this->line('  <options=bold>أرشيف شركة محذوفة</>');
        $this->line('  الشركة: '.($m['business']['name'] ?? '—').' (#'.($m['business']['id'] ?? '—').')');
        $this->line('  مُحيت في: '.($m['purged_at'] ?? '—').' — بطلبِ: '.($m['purged_by']['name'] ?? '—'));
        $this->line('  حجم الملفّ: '.number_format((int) filesize($zip)).' بايت');

        $bad = $this->sheets($read);
        $this->files($read);

        if ($bad > 0) {
            $this->newLine();
            $this->error("  ✖ {$bad} دفترًا بصمتُه لا تطابق البيان — الأرشيف معطوب، ولا يُعاد منه شيء.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <fg=green>✔ كلُّ دفترٍ بصمتُه كما في البيان.</>');

        $into = trim((string) $this->option('into'));

        if ($into === '') {
            $this->line('  <fg=gray>ولم يُكتب شيء. وللإعادة الفعليّة: --into=اسم_اتّصال_اختباريّ</>');

            return self::SUCCESS;
        }

        return $this->restore($read, $into);
    }

    /** بصمةُ كلِّ دفترٍ تُقارَن بما قاله البيان */
    private function sheets(array $read): int
    {
        $this->newLine();
        $this->line('  <options=bold,underscore>الدفاتر</>');

        $bad = 0;
        $rows = 0;

        foreach ($read['books'] as $table => $book) {
            $rows += $book['rows'];

            if (! $book['intact']) {
                $bad++;
                $this->line(sprintf('  <fg=red>✖</> %-34s %6d صفًّا — بصمةٌ لا تطابق', $table, $book['rows']));

                continue;
            }

            if ($book['rows'] > 0) {
                $this->line(sprintf('  <fg=green>✔</> %-34s %6d صفًّا', $table, $book['rows']));
            }
        }

        $this->line(sprintf('  %-36s %6d صفًّا في %d دفترًا', 'المجموع:', $rows, count($read['books'])));

        return $bad;
    }

    private function files(array $read): void
    {
        if ($read['files'] === []) {
            return;
        }

        $broken = array_filter($read['files'], fn ($f) => ! $f['intact']);

        $this->newLine();
        $this->line('  <options=bold,underscore>المرفقات</>');
        $this->line('  '.count($read['files']).' ملفًّا، '
            .number_format(array_sum(array_column($read['files'], 'bytes'))).' بايت');

        $broken === []
            ? $this->line('  <fg=green>✔</> كلُّ مرفقٍ بصمتُه كما في البيان')
            : $this->line('  <fg=red>✖</> '.count($broken).' مرفقًا بصمتُه لا تطابق');
    }

    /* ═══════════════════ الإعادة ═══════════════════ */

    private function restore(array $read, string $into): int
    {
        $this->newLine();
        $this->line('  <options=bold,underscore>الإعادة إلى قاعدة اختباريّة</>');

        if (! $this->safeTarget($into)) {
            return self::FAILURE;
        }

        $tables = array_keys(array_filter($read['books'], fn ($b) => $b['rows'] > 0));
        $state = Reader::inspectTarget($into, $tables);

        if ($state['missing'] !== []) {
            $this->error('  جداولُ ناقصةٌ في الهدف ('.count($state['missing']).'): '
                .implode(', ', array_slice($state['missing'], 0, 6)).' …');
            $this->line('  <fg=gray>شغّل الهجرات على هذا الاتّصال أوّلًا: php artisan migrate --database='.$into.'</>');

            return self::FAILURE;
        }

        if ($state['filled'] !== []) {
            $this->error('  الهدفُ ليس فارغًا — جداولٌ فيها صفوف: '.implode(', ', array_slice($state['filled'], 0, 6)));
            $this->line('  <fg=gray>الإعادةُ تُدخل المعرِّفاتِ الأصليّة، فتصطدم بما هناك. استعمل قاعدةً فارغة.</>');

            return self::FAILURE;
        }

        $db = DB::connection($into);
        $done = [];

        try {
            $db->beginTransaction();

            foreach ($tables as $table) {
                $rows = Reader::rows($read['books'][$table]['csv']);
                $out = Reader::into($into, $table, $rows);
                $done[$table] = ['said' => $read['books'][$table]['rows'], 'got' => $out['inserted'], 'dropped' => $out['dropped']];
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $this->error('  تعذّرت الإعادة — أُلغيت كلُّها ولم يبقَ نصفُ استعادة.');
            $this->line('  <fg=gray>'.$e->getMessage().'</>');

            return self::FAILURE;
        }

        return $this->compare($into, $done);
    }

    /**
     * ثلاثةُ حرّاسٍ قبل أن يُكتب حرف.
     *
     * ═══ ولمَ هذا القدرُ من الحذر ═══
     *
     * أمرٌ يُدخل صفوفًا بمعرِّفاتٍ أصليّةٍ في قاعدةٍ يُسمّيها من كتب السطر.
     * وخطأُ حرفٍ في اسم الاتّصال — أو اتّصالٌ اختباريٌّ يشير إلى قاعدةِ
     * الإنتاج — يكتب أرشيفَ شركةٍ زالت فوق بياناتِ تجّارٍ عاملين.
     *
     * فيُردُّ الإنتاجُ بلا استثناء، ويُردُّ كلُّ اتّصالٍ قاعدتُه قاعدةُ
     * الافتراض ولو اختلف اسمُه. والفراغُ يُسأل بعد ذلك في `inspectTarget`.
     */
    private function safeTarget(string $into): bool
    {
        if (app()->isProduction()) {
            $this->error('  APP_ENV=production — لا تُعاد الدفاتر في قاعدةٍ على خادم الإنتاج. استعمل بيئةً معزولة.');

            return false;
        }

        if (! is_array(config("database.connections.{$into}"))) {
            $this->error("  لا اتّصالَ بالاسم «{$into}» في config/database.php.");

            return false;
        }

        $default = (string) config('database.default');

        if ($into === $default) {
            $this->error('  هذا هو اتّصالُ الافتراض — لا تُعاد الدفاتر في القاعدة العاملة.');

            return false;
        }

        $mine = $this->fingerprint($default);
        $theirs = $this->fingerprint($into);

        if ($mine === $theirs) {
            $this->error('  الاتّصالُ يشير إلى قاعدةِ الافتراض نفسِها باسمٍ آخر — مرفوض.');

            return false;
        }

        $this->line("  الهدف: <options=bold>{$into}</> — ليس قاعدةَ النظام العاملة ✔");

        return true;
    }

    /** بصمةُ اتّصال: مضيفُه ومنفذُه وقاعدتُه — بلا كلمةِ سرّ */
    private function fingerprint(string $name): string
    {
        $c = (array) config("database.connections.{$name}");

        return implode('|', [
            (string) ($c['driver'] ?? ''),
            (string) ($c['host'] ?? ''),
            (string) ($c['port'] ?? ''),
            (string) ($c['database'] ?? ''),
        ]);
    }

    /**
     * والمقارنةُ تُقرأ من القاعدة لا من الملفّ.
     *
     * عددُ ما أُدخل يقوله المُدخِل، وعددُ ما استُقرّ في الجدول يقوله الجدول.
     * والثاني هو الجواب: قيدٌ يرفض صفًّا، أو محرِّكٌ يُبدّل، لا يظهر في الأوّل.
     */
    private function compare(string $into, array $done): int
    {
        $this->newLine();
        $this->line('  <options=bold,underscore>المقارنة بالأصل</>');

        $bad = 0;
        $dropped = [];

        foreach ($done as $table => $d) {
            $live = DB::connection($into)->table($table)->count();
            $ok = $live === $d['said'];

            if (! $ok) {
                $bad++;
            }

            if ($d['dropped'] !== []) {
                $dropped[$table] = $d['dropped'];
            }

            $this->line(sprintf(
                '  %s %-34s البيان %6d · القاعدة %6d',
                $ok ? '<fg=green>✔</>' : '<fg=red>✖</>',
                $table,
                $d['said'],
                $live,
            ));
        }

        if ($dropped !== []) {
            $this->newLine();
            $this->line('  <fg=yellow>! أعمدةٌ في الأرشيف لا وجودَ لها في المخطَّط الحالي — تُركت:</>');

            foreach ($dropped as $table => $cols) {
                $this->line('    '.$table.': '.implode(', ', $cols));
            }
        }

        $this->newLine();

        if ($bad > 0) {
            $this->error("  ✖ {$bad} دفترًا عددُ صفوفه في القاعدة لا يطابق البيان.");

            return self::FAILURE;
        }

        $this->line('  <fg=green;options=bold>✔ كلُّ دفترٍ عادَ بعددِ صفوفه كما في البيان.</>');
        $this->line('  <fg=gray>والمرفقاتُ داخل الأرشيف نفسِه (files/) — تُفكّ بأيّ فاكِّ ZIP بعد فكّ التشفير.</>');

        return self::SUCCESS;
    }
}
