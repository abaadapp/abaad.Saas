<?php

namespace App\Console\Commands;

use App\Models\PurgeRun;
use App\Support\BusinessPurge;
use App\Support\Purge\Cipher;
use App\Support\Purge\Offsite;
use App\Support\Purge\Worker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * أجاهزٌ الحذفُ النهائيُّ لأن يُفتح؟ — يُسأل قبل الفتح لا بعده.
 *
 *     php artisan purge:check
 *
 * ═══ ولمَ أمرٌ يُشغَّل باليد وقد كُتبت البوّابات في الكود ═══
 *
 * البوّاباتُ تردُّ الحذفَ إن لم يكن التخزينُ جاهزًا — وذاك جوابٌ يُقرأ يومَ
 * يُطلب المحو. ومن يُهيّئ خادمًا يريد الجوابَ **قبل** ذلك: أضبطتُ المفتاح؟
 * أتصل الدلوُ؟ أهو خاصٌّ فعلًا؟ أفيه ملفّاتُ أحدٍ آخر؟ أيعمل العامل؟
 *
 * فيُسأل هنا بلا شركةٍ ولا حذفٍ ولا صفٍّ يُمسّ: يُكتب جسمُ فحصٍ صغيرٌ ثمّ
 * يُقرأ ثمّ يُحذف، ويُطلب رابطُه العامُّ بلا تخويلٍ ليُرى أيُعطى أم يُردّ.
 *
 * ═══ ولا سرَّ في المخرَج ═══
 *
 * لا مفتاحَ ولا كلمةَ سرٍّ ولا اسمَ دلوٍ ولا نقطةَ وصولٍ يُطبع — فالمخرَجُ
 * يُلصق في محادثةٍ أو يُحفظ في سجلِّ نشر. ومن المفتاح تُطبع **بصمتُه**
 * وحدَها: بها يُقارَن مفتاحُ الخادم بالنسخة المحفوظة بعيدًا بلا أن يُكشف
 * أيُّهما.
 *
 * والخروجُ بـ1 عند أيّ عائقٍ حقيقيّ — فيصلح لمراقبةٍ آليّة.
 */
class PurgeCheck extends Command
{
    protected $signature = 'purge:check';

    protected $description = 'فحص جاهزية الحذف النهائي: المفتاح والتخزين المستقلّ وخصوصيّته وعامل الطابور';

    /** @var list<string> */
    private array $fail = [];

    /** @var list<string> */
    private array $warn = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>جاهزيّة الحذف النهائيّ — أبعاد</>');
        $this->newLine();

        $on = BusinessPurge::enabled();

        $this->line('  الميزة: '.($on
            ? '<fg=yellow;options=bold>مفتوحة</> (BUSINESS_PURGE_ENABLED=true)'
            : '<fg=green>مغلقة</> — لا زرَّ ولا مسار. وهذا الفحصُ يقول ما يلزم قبل فتحها.'));
        $this->newLine();

        $this->key();
        $this->storage();
        $this->queue();
        $this->runs();

        return $this->verdict($on);
    }

    /* ═══════════════════ المفتاح ═══════════════════ */

    private function key(): void
    {
        $this->section('مفتاح التشفير');

        try {
            $key = Cipher::key();
        } catch (RuntimeException $e) {
            $this->bad('مفتاح أرشيف مستقلّ', $e->getMessage());

            return;
        }

        /*
         * والبصمةُ تُطبع، لا المفتاح.
         *
         * من حفظ نسخةً من المفتاح خارج الخادم يحتاج أن يعرف أنّها **هي** —
         * قبل أن يعتمد عليها في استعادةٍ بعد سنوات. ومقارنةُ بصمتين تُجيب
         * ذلك بلا أن يُنقل المفتاحُ في شاشةٍ أو سجلّ.
         *
         * واثنتا عشرة خانةً من sha256 لاثنين وثلاثين بايتًا عشوائيّةً لا
         * تُقرَّب من المفتاح: لا عكسَ للدالّة ولا مجالَ يُستقصى.
         */
        $this->good('مفتاح أرشيف مستقلّ عن APP_KEY', 'بصمته: '.substr(hash('sha256', $key), 0, 12));
    }

    /* ═══════════════════ التخزين ═══════════════════ */

    private function storage(): void
    {
        $this->section('التخزين المستقلّ');

        $name = Offsite::disk();

        if ($name === null) {
            $this->bad('قرصٌ مستقلٌّ مهيَّأ', 'اضبط BUSINESS_PURGE_OFFSITE_DISK — وبلا ذلك لا يبدأ الحذف.');

            return;
        }

        $driver = (string) config("filesystems.disks.{$name}.driver");
        $this->line("  القرص: <options=bold>{$name}</> (محوّل: {$driver})");

        if ($driver === 's3') {
            $this->conf($name, 'bucket', 'اسم الدلو');
            $this->conf($name, 'endpoint', 'نقطة الوصول');
            $this->conf($name, 'key', 'مفتاح الوصول');
            $this->conf($name, 'secret', 'كلمة سرّ الوصول');

            $private = (string) config("filesystems.disks.{$name}.visibility") === 'private';
            $private
                ? $this->good('الرفعُ خاصٌّ افتراضيًّا', 'ما يُرفع لا يُقرأ بلا تخويل')
                : $this->bad('الرفعُ خاصٌّ افتراضيًّا', "اضبط visibility=private للقرص {$name} — وإلّا رُفع الأرشيفُ مقروءًا للعامّة.");
        }

        try {
            Offsite::assertReady();
            $this->good('اتّصالٌ وصلاحيّةُ كتابةٍ وقراءةٍ وحذف', 'كُتب جسمُ فحصٍ ثمّ قُرئ ثمّ حُذف');
        } catch (RuntimeException $e) {
            $this->bad('اتّصالٌ وصلاحيّةُ كتابةٍ وقراءةٍ وحذف', $e->getMessage());

            return;
        }

        $this->exposure($name);
        $this->tenants($name);
    }

    /** أيُطبع اسمُ إعدادٍ مضبوطٍ؟ — لا، بل حضورُه وحدَه */
    private function conf(string $disk, string $key, string $label): void
    {
        $set = trim((string) config("filesystems.disks.{$disk}.{$key}")) !== '';

        $set
            ? $this->good($label.' مضبوط', '')
            : $this->bad($label.' مضبوط', "ناقصٌ في إعدادات القرص {$disk} — راجع .env على الخادم.");
    }

    /**
     * أيُعطى الأرشيفُ لمن طلبه بلا تخويل؟ — يُسأل التخزينُ نفسُه لا إعداداتُه.
     *
     * ═══ ولمَ لا يكفي `visibility=private` ═══
     *
     * ذاك إعدادٌ يقول ما **نطلبه** عند الرفع. وسياسةُ الدلو تعلوه: دلوٌ
     * ضُبط عامًّا في لوحة المزوّد يُعطي كلَّ ما فيه لمن عرف الرابط، ولو
     * رفعنا بـprivate. والجوابُ الوحيدُ الصادقُ أن يُطلب الجسمُ من الشبكة
     * بلا مفتاحٍ ويُرى ما يعود.
     *
     * وجسمُ الفحص نصٌّ لا معنى له، ويُحذف بعده — فلا يُخاطر بشيء.
     */
    private function exposure(string $name): void
    {
        $path = Offsite::prefix().'/health/public-probe-'.bin2hex(random_bytes(8));
        $disk = Storage::disk($name);

        try {
            $disk->put($path, 'abaad-purge-public-probe');
        } catch (Throwable) {
            $this->warn2('تعذّر تهيئة فحص الخصوصيّة — أعد المحاولة.');

            return;
        }

        try {
            $url = $disk->url($path);
        } catch (Throwable) {
            $url = null;
        }

        try {
            if ($url === null || ! str_starts_with($url, 'http')) {
                $this->warn2('لا رابطَ عامًّا يُبنى لهذا القرص — تحقّق من خصوصيّة الدلو في لوحة المزوّد بنفسك.');

                return;
            }

            $status = null;

            try {
                $status = Http::timeout(10)->withoutRedirecting()->get($url)->status();
            } catch (Throwable) {
                /* تعذّرُ الوصولِ شبكيًّا ليس جوابًا — يُقال ذلك ولا يُقرأ نجاحًا */
            }

            if ($status === null) {
                $this->warn2('تعذّر سؤالُ الشبكة عن خصوصيّة الدلو — تحقّق بنفسك أنّ الدلو ليس عامًّا.');

                return;
            }

            $status === 200
                ? $this->bad('الدلو غيرُ متاحٍ للعامّة', 'جسمُ الفحص قُرئ من الشبكة بلا تخويل (200) — الدلو عامّ. اجعله خاصًّا قبل أيّ حذف.')
                : $this->good('الدلو غيرُ متاحٍ للعامّة', "طلبٌ بلا تخويل رُدّ بـ{$status}");
        } finally {
            try {
                $disk->delete($path);
            } catch (Throwable) {
                $this->warn2('بقي ملفُّ فحصٍ في التخزين — احذفه يدويًّا من مجلّد health/.');
            }
        }
    }

    /**
     * أفي هذه المساحة شيءٌ غيرُ أرشيفنا؟
     *
     * ═══ ولمَ يُسأل ═══
     *
     * مساحةٌ تحمل نسخًا احتياطيّةً أخرى أو ملفّاتِ تجّارٍ تجمع مصيرَين في
     * سلّةٍ واحدة: مفتاحُ وصولٍ واحدٌ يُقرأ به كلُّ شيء، وخطأٌ في تنظيفٍ
     * يأخذ الاثنين. وأرشيفُ محوٍ لا تراجعَ فيه يسكن مساحتَه وحدَه.
     *
     * وهو تحذيرٌ لا منعٌ: الحكمُ على ما في المساحة لمن هيّأها، والكودُ لا
     * يعرف أيُّ ملفٍّ غريبٍ مقصودٌ وأيُّه سهو.
     */
    private function tenants(string $name): void
    {
        $disk = Storage::disk($name);
        $prefix = Offsite::prefix();

        try {
            $inside = array_values(array_filter(
                $disk->files($prefix),
                fn ($f) => ! str_ends_with($f, '.zip.enc'),
            ));

            $outside = array_values(array_filter(
                array_merge($disk->files(''), $disk->directories('')),
                fn ($f) => trim($f, '/') !== $prefix,
            ));
        } catch (Throwable) {
            $this->warn2('تعذّر سردُ ما في المساحة — تحقّق بنفسك أنّها مخصّصةٌ لهذا الأرشيف وحدَه.');

            return;
        }

        $inside === []
            ? $this->good('مجلّد الأرشيف لا يحمل غيرَ نسخٍ مشفَّرة', '')
            : $this->warn2(count($inside).' ملفًّا في مجلّد الأرشيف ليس نسخةً مشفَّرة — راجعها.');

        $outside === []
            ? $this->good('المساحة مخصّصةٌ لهذا الأرشيف وحدَه', '')
            : $this->warn2(count($outside).' عنصرًا في المساحة خارج مجلّد الأرشيف — استخدم مساحةً لا تشارك غيرَها.');
    }

    /* ═══════════════════ الطابور ═══════════════════ */

    private function queue(): void
    {
        $this->section('عامل الطابور');

        Worker::queued()
            ? $this->good('الطابور ليس sync', 'المهامُّ تُنفَّذ في الخلفيّة')
            : $this->bad('الطابور ليس sync', 'QUEUE_CONNECTION=sync يعني تنفيذًا داخل الطلب — يموت عند المهلة. اضبطه على database.');

        $running = Worker::running();

        if ($running === true) {
            $this->good('عاملٌ يسحب من الطابور', '');
        } elseif ($running === false) {
            $this->bad('عاملٌ يسحب من الطابور', 'لا عامل يعمل — شغّله دائمًا: انظر deploy/README.md');
        } else {
            $this->warn2('تعذّر السؤالُ عن العامل على هذا الخادم — تحقّق بنفسك: pgrep -fa "artisan queue:work"');
        }
    }

    /* ═══════════════════ ما عَلِق ═══════════════════ */

    /**
     * أثمّ محوٌ بقي يقول «يعمل» ولا أحدَ يعمل؟
     *
     * مهمّةٌ قُتل عاملُها قبل أن تُكتب `failed` تترك صفَّها `running` أبدًا،
     * والفهرسُ الفريدُ يمنع محاولةً ثانيةً على الشركة نفسِها — فيقف البابُ
     * مغلقًا بلا سببٍ ظاهر. فيُقال هنا صريحًا ومعه ما يُقرأ به.
     */
    private function runs(): void
    {
        $this->section('عمليّات المحو');

        if (! Schema::hasTable('business_purges')) {
            $this->warn2('جدول business_purges غير موجود — شغّل الهجرات.');

            return;
        }

        $stale = PurgeRun::query()
            ->whereIn('status', [PurgeRun::PENDING, PurgeRun::RUNNING])
            ->where('updated_at', '<', now()->subHours(2))
            ->count();

        $stale === 0
            ? $this->good('لا عمليّةَ محوٍ عالقة', '')
            : $this->warn2($stale.' عمليّةَ محوٍ لم تتبدّل حالتُها منذ ساعتين — اقرأها في شاشة الأرشيف قبل أيّ إعادة.');
    }

    /* ═══════════════════ الحكم ═══════════════════ */

    private function verdict(bool $on): int
    {
        $this->newLine();

        if ($this->fail === []) {
            $this->warn === []
                ? $this->line('  <fg=green;options=bold>✔ لا عائق.</> والفتحُ قرارٌ لك بعد المراجعة القانونيّة والمحاسبيّة.')
                : $this->line('  <fg=yellow;options=bold>✔ لا عائقَ حاسمًا</> — و'.count($this->warn).' ملاحظةً تُقرأ أعلاه.');

            return self::SUCCESS;
        }

        $this->line('  <fg=red;options=bold>✖ '.count($this->fail).' عائقًا يمنع الحذف:</>');

        foreach ($this->fail as $line) {
            $this->line('    • '.$line);
        }

        /*
         * والسجلُّ يُكتب إن كانت الميزةُ مفتوحةً وحدَها.
         *
         * ميزةٌ مغلقةٌ بلا تخزينٍ مهيَّأٍ ليست عطبًا — تلك حالُها المقصودة،
         * وتحذيرٌ يوميٌّ عنها يُعلّم من يقرأ السجلَّ أن يتجاهله. وحين تُفتح
         * يصير السقوطُ خبرًا: أرشيفٌ لا يُرفع وشركاتٌ تنتظر المحو.
         */
        if ($on) {
            Log::warning('purge:check — عوائق تمنع الحذف النهائي', ['عوائق' => $this->fail]);
        }

        return self::FAILURE;
    }

    /* ═══════════════════ الطباعة ═══════════════════ */

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold,underscore>'.$title.'</>');
    }

    private function good(string $label, string $note): void
    {
        $this->line('  <fg=green>✔</> '.$label.($note === '' ? '' : " <fg=gray>— {$note}</>"));
    }

    private function bad(string $label, string $hint): void
    {
        $this->fail[] = $label.($hint === '' ? '' : ' — '.$hint);
        $this->line('  <fg=red>✖</> '.$label);

        if ($hint !== '') {
            $this->line('     <fg=gray>'.$hint.'</>');
        }
    }

    private function warn2(string $note): void
    {
        $this->warn[] = $note;
        $this->line('  <fg=yellow>!</> '.$note);
    }
}
