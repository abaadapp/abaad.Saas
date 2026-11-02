<?php

namespace App\Console\Commands;

use App\Support\ImageGate;
use App\Support\ImageMemory;
use App\Support\ImageReferences;
use App\Support\ImageSwap;
use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * صورُ النظام كلُّها: جردٌ، ونسخٌ أخفّ، ونقلُ مراجع، وخطّةُ استغناءٍ عن الأصول.
 *
 *   images:optimize                          جردٌ وتقديرٌ — لا يكتب شيئًا
 *   images:optimize --apply                  يصنع نسخ دفعةٍ (٥٠) ثمّ يقف
 *   images:optimize --swap --business=ID     ينقل مراجعَ متجرٍ إلى نسخها، بسجلّ
 *   images:optimize --rollback=RUN           يُعيد ما نقلته جولة
 *   images:optimize --recover                يقرأ كلَّ نقلٍ انقطع ويقول أين وقف
 *   images:optimize --retire-plan            ما يمكن الاستغناءُ عنه من الأصول — لا يمحو
 *
 * ═══ ولا يُمحى أصلٌ هنا أبدًا ═══
 *
 * النسخُ ملفّاتٌ جديدةٌ إلى جوار أصولها (`ImageVariants`). والنقلُ يكتب في
 * صفوفٍ حيّةٍ ويحفظ ما كانت عليه (`ImageSwap`). والخطّةُ تقول ما لم يبقَ ما
 * يشير إليه — والمحوُ قرارٌ لاحق.
 *
 * ═══ وما لا يُعالج ═══
 *
 * المرفقاتُ الخاصّة (القرص `local`): إيصالاتٌ وفواتيرُ موردين ومستنداتٌ
 * وصورُ محادثات. هي بيّنةٌ تُقرأ كما أُرسلت — تُجرد أحجامُها وحدها. والشعاراتُ
 * وأغلفةُ المستندات والصورُ الرمزيّة تُجرد ولا تُنسخ: تدخل الفواتير والمستندات.
 */
class ImagesOptimize extends Command
{
    protected $signature = 'images:optimize
        {--apply : يصنع النسخ فعلًا — وبدونه جردٌ وتقديرٌ لا يكتب شيئًا}
        {--limit=50 : أكثرُ ما يُعالج من الصور في هذه الدفعة}
        {--business= : متجرٌ واحد}
        {--sample=30 : في الجرد: كم صورةً تُضغط في الذاكرة لتقدير التوفير}
        {--retry-failed : يُعيد ما فشل ثلاثَ مرّات}
        {--swap : ينقل المراجعَ الحيّة إلى النسخ — يحتاج --business}
        {--rollback= : معرّفُ جولة نقلٍ يُعاد ما فيها}
        {--recover : يقرأ كلَّ نقلٍ انقطع ويختمه بما وجد}
        {--retire-plan : ما يمكن الاستغناءُ عنه من الأصول، بلا محو}';

    protected $description = 'جرد صور النظام وصنع نسخها الأخفّ ونقل مراجعها تدريجيًّا دون محو أيّ أصل';

    /** حالُ ما عولج — على القرص الخاصّ، يكتبه هذا الأمر وحده تحت قفله */
    private const STATE = 'image-variants/state.json';

    /** مرّاتُ المحاولة قبل أن يُترك فاشلًا حتى يُطلب */
    private const ATTEMPTS = 3;

    public function handle(): int
    {
        $lock = Cache::lock('images:optimize', 3600);

        if (! $lock->get()) {
            $this->error('جولةٌ أخرى تعمل الآن — لا تُعالج الصور مرّتين معًا.');

            return self::FAILURE;
        }

        try {
            // ‏أولويّةٌ أدنى من البيع والدفع وواتساب على معالجٍ واحد — والجردُ يفكّ عيّنته أيضًا
            if (function_exists('proc_nice')) {
                @proc_nice(10);
            }

            return match (true) {
                filled($this->option('rollback')) => $this->rollback((string) $this->option('rollback')),
                (bool) $this->option('recover') => $this->recover(),
                (bool) $this->option('swap') => $this->swap(),
                (bool) $this->option('retire-plan') => $this->retirePlan(),
                (bool) $this->option('apply') => $this->apply(),
                default => $this->inventory(),
            };
        } finally {
            $lock->release();
        }
    }

    /* ═══════════ الجرد ═══════════ */

    private function inventory(): int
    {
        $started = microtime(true);
        $files = $this->files();
        $refs = ImageReferences::scan();
        $rows = [];
        $todo = [];
        $over = 0;

        foreach ($files as $path => $bytes) {
            $kind = $this->kind($path, $refs[$path] ?? []);
            $rows[$kind] ??= ['n' => 0, 'bytes' => 0];
            $rows[$kind]['n']++;
            $rows[$kind]['bytes'] += $bytes;
            $need = in_array($kind, ['product', 'category', 'website'], true) ? ImageVariants::needs($path) : null;

            if ($need !== null && ($need['display'] || $need['thumb'])) {
                $todo[$kind][] = $path;
                $over += $need['w'] * $need['h'] > ImageMemory::maxPixels() ? 1 : 0;
            }
        }

        $estimate = $this->estimate($todo);
        $labels = [
            'product' => 'صور المنتجات', 'category' => 'صور الأقسام', 'website' => 'صور الموقع والمحرّر والشريط',
            'copy' => 'نسخٌ مصنوعة', 'logo' => 'الشعارات', 'cover' => 'أغلفة المستندات', 'avatar' => 'الصور الرمزيّة',
            'other_refs' => 'يشير إليها سجلٌّ أو طلبٌ قديم فقط', 'unreferenced' => 'لا يشير إليها شيء', 'other' => 'غير ذلك',
        ];

        $this->info('الصور العامّة (public):');
        $this->table(['الفئة', 'ملفّات', 'الحجم', 'تنتظر', 'بعد التحسين (تقدير)'], collect($rows)->map(fn ($r, $kind) => [
            $labels[$kind] ?? $kind, $r['n'], $this->mb($r['bytes']), count($todo[$kind] ?? []),
            isset($estimate['ratio'][$kind]) ? $this->mb((int) ($r['bytes'] * $estimate['ratio'][$kind])).' (−'.round((1 - $estimate['ratio'][$kind]) * 100).'%)' : '—',
        ])->values()->all());

        $this->private();

        $this->line('');
        $this->measured($estimate);
        $cap = ImageMemory::maxPixels() / 1_000_000;
        $this->line($over > 0
            ? 'فوق سقف '.$cap.' ميغابكسل: '.$over.' صورة — تُترك في الجولة ويُكتب سببُها في السجلّ.'
            : 'لا صورةَ تنتظر فوق سقف '.$cap.' ميغابكسل.');

        if ($estimate['n'] > 0) {
            $all = array_sum(array_map('count', $todo));
            $this->line('متوسّطُ المعالجة '.round($estimate['ms'] / $estimate['n']).' ملّي‌ثانية للصورة. كلُّ ما ينتظر ('.$all.'): نحو '
                .round($estimate['ms'] / $estimate['n'] * $all / 1000 / 60, 1).' دقيقة معالجة — '.(int) ceil($all / max(1, (int) $this->option('limit'))).' دفعة.');
        }

        // ‏ذاكرةُ العمليّة كما يراها النظام — مكتبةُ الصور تحجز خارج حساب PHP فلا تُصدَّق ذروتُه
        $available = ImageMemory::available();
        $this->line('أقصى ذاكرةٍ مقيمةٍ للعمليّة: '.$this->mb($this->rss())
            .($available === null ? '' : '، والمتاحُ في الخادم الآن '.$this->mb($available)).'.');

        $this->scanCost();
        $this->line('الجردُ كلُّه: '.round(microtime(true) - $started, 1).' ثانية. لم يُكتب شيء.');

        return self::SUCCESS;
    }

    /**
     * يقدّر التوفير بعيّنةٍ من كلّ فئة — تُضغط في الذاكرة ولا تُكتب.
     *
     * وكلُّ متوسّطٍ من الصور التي قيس لها هو وحده: نسخةُ العرض ممّا صُنعت له
     * نسخةُ عرض، والمصغّرةُ ممّا صُنعت له مصغّرة. وصورُ الشريط والأقسام لا
     * مصغّرةَ لها، فلا تدخل متوسّطَ المصغّرات — كان يُحسب الأصلُ مكانها فيقول
     * «١٢٣١ KB» لمصغّرةٍ حجمُها أربعون.
     *
     * وما لم يُفكّ (فوق السقف، أو ذاكرةٌ لا تكفي) لا يُقاس — يُعدّ بسببه.
     *
     * @param  array<string, list<string>>  $todo
     * @return array{n: int, ms: float, ratio: array<string, float>, kinds: array<string, array<string, int>>, unmeasured: array<string, int>}
     */
    private function estimate(array $todo): array
    {
        $out = ['n' => 0, 'ms' => 0.0, 'ratio' => [], 'kinds' => [], 'unmeasured' => []];
        $per = max(1, intdiv(max(1, (int) $this->option('sample')), max(1, count($todo))));

        foreach ($todo as $kind => $paths) {
            $k = ['n' => 0, 'before' => 0, 'after' => 0, 'display_n' => 0, 'display' => 0, 'thumb_n' => 0, 'thumb' => 0];

            foreach (array_slice($paths, 0, $per) as $path) {
                $r = ImageVariants::make($path, false);
                $measured = $r['status'] === 'estimated' || ($r['status'] === 'skipped' && $r['reason'] === 'no_saving');

                if (! $measured) {
                    $why = (string) ($r['reason'] ?? $r['status']);
                    $out['unmeasured'][$why] = ($out['unmeasured'][$why] ?? 0) + 1;

                    continue;
                }

                $out['n']++;
                $out['ms'] += $r['ms'];
                $k['n']++;
                $k['before'] += $r['before'];
                $k['after'] += $r['display'] ?: $r['before'];

                if ($r['display'] > 0) {
                    $k['display_n']++;
                    $k['display'] += $r['display'];
                }

                if ($r['thumb'] > 0) {
                    $k['thumb_n']++;
                    $k['thumb'] += $r['thumb'];
                }
            }

            if ($k['before'] > 0) {
                $out['ratio'][$kind] = $k['after'] / $k['before'];
                $out['kinds'][$kind] = $k;
            }
        }

        return $out;
    }

    /** جدولُ العيّنة: كم قيس، ومتوسّطُ الأصل ونسخة العرض والمصغّرة كلٌّ من صوره */
    private function measured(array $estimate): void
    {
        $labels = ['product' => 'صور المنتجات', 'category' => 'صور الأقسام', 'website' => 'صور الموقع والمحرّر والشريط'];
        $avg = fn (int $sum, int $n) => $n > 0 ? round($sum / $n / 1024).' KB' : '—';

        if ($estimate['kinds'] !== []) {
            $this->info('العيّنة ('.$estimate['n'].' صورة مقيسة):');
            $this->table(['الفئة', 'مقيسة', 'متوسّطُ الأصل', 'نسخةُ العرض', 'المصغّرة'], collect($estimate['kinds'])->map(fn ($k, $kind) => [
                $labels[$kind] ?? $kind, $k['n'], $avg($k['before'], $k['n']),
                $k['display_n'].' — '.$avg($k['display'], $k['display_n']),
                $k['thumb_n'].' — '.$avg($k['thumb'], $k['thumb_n']),
            ])->values()->all());
        }

        foreach ($estimate['unmeasured'] as $why => $n) {
            $this->line('لم تُقس '.$n.' من العيّنة: '.$why.'.');
        }
    }

    /** أقصى ذاكرةٍ مقيمةٍ لهذه العمليّة — بالبايت، كما يعدّها النظام */
    private function rss(): int
    {
        $max = (int) (getrusage()['ru_maxrss'] ?? 0);

        // ‏لينكس يعدّها كيلوبايتاتٍ، وماك بايتات
        return PHP_OS_FAMILY === 'Darwin' ? $max : $max * 1024;
    }

    /** القرصُ الخاصّ — بالمجلّد، حجمًا وعددًا. لا يُفتح فيه ملفّ */
    private function private(): void
    {
        $disk = Storage::disk('local');
        $rows = [];

        foreach ($disk->directories() as $folder) {
            if (in_array($folder, ['image-variants', 'image-swaps'], true)) {
                continue;
            }

            $files = $disk->allFiles($folder);
            $rows[] = [$folder, count($files), $this->mb(array_sum(array_map(fn ($f) => (int) @filesize($disk->path($f)), $files)))];
        }

        if ($rows !== []) {
            $this->info('المرفقاتُ الخاصّة (local) — تُجرد ولا تُعالج:');
            $this->table(['المجلّد', 'ملفّات', 'الحجم'], $rows);
        }
    }

    /* ═══════════ النسخ ═══════════ */

    private function apply(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $state = $this->state();
        $count = [];
        $saved = 0;
        $done = 0;
        $candidates = $this->candidates();

        foreach ($candidates as $path) {
            if ($done >= $limit) {
                break;
            }

            $was = $state[$path] ?? null;

            if (! $this->pending($path) || ($was['status'] ?? null) === 'skipped'
                || (($was['status'] ?? null) === 'failed' && $was['attempts'] >= self::ATTEMPTS && ! $this->option('retry-failed'))) {
                continue;
            }

            /*
             * والخادمُ يُسأل قبل كلّ صورة — ذاكرتُه وحِملُه وقرصُه وطوابيرُه
             * (`ImageGate`). وما لا يتّسع تقف عنده الدفعةُ قبل أن تُفكّ صورة،
             * وتُعاد في الجولة التالية من حيث وقفت.
             */
            if (($why = ImageGate::blocked()) !== null) {
                $count['paused'] = 1;
                $this->warn('وقفت الدفعة قبل '.$path.': '.$why['reason'].' — الآن '.($why['value'] ?? 'لا يُقرأ').' والحدّ '.$why['limit'].'. لم تُفكّ صورة، وتُعاد في الجولة التالية.');
                $this->paused($why, $path);

                break;
            }

            $r = ImageVariants::make($path);
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
            $done++;

            // ‏ذاكرةُ الخادم لا تكفي الآن: تقف الدفعةُ كلُّها — ما بعدها لن يجد ما لم تجده
            if ($r['status'] === 'deferred') {
                $this->warn('وقفت الدفعة: الذاكرةُ المتاحة لا تتّسع لفكّ '.$path.' مع الهامش — تُعاد في الجولة التالية.');

                break;
            }

            if ($r['status'] === 'made' || $r['status'] === 'ready') {
                unset($state[$path]);
                $saved += $r['display'] > 0 ? $r['before'] - $r['display'] : 0;
            } elseif (in_array($r['status'], ['skipped', 'failed'], true)) {
                $state[$path] = [
                    'status' => $r['status'], 'reason' => $r['reason'], 'at' => now()->toIso8601String(),
                    'attempts' => ($r['status'] === 'failed' ? ($was['attempts'] ?? 0) : 0) + ($r['status'] === 'failed' ? 1 : 0),
                ];
            }

            // ‏ويُترك المعالجُ لغيره بين صورةٍ وأخرى
            usleep(max(0, (int) config('images.pause_ms', 150)) * 1000);
        }

        $this->saveState($state);
        $left = count(array_filter($candidates, fn ($p) => $this->pending($p) && ! in_array($state[$p]['status'] ?? null, ['skipped'], true)
            && ! (($state[$p]['status'] ?? null) === 'failed' && $state[$p]['attempts'] >= self::ATTEMPTS)));
        $failed = count(array_filter($state, fn ($s) => $s['status'] === 'failed'));

        $this->info('عولجت '.$done.' صورة: '.(collect($count)->map(fn ($n, $s) => $s.'='.$n)->implode('، ') ?: 'لا شيء').'.');
        $this->line('نسخُ العرض أخفُّ من أصولها بـ '.$this->mb($saved).'. والأصولُ باقيةٌ كما هي.');
        $this->line('بقي '.$left.' صورةً تنتظر، و'.$failed.' فشلت (تُعاد حتى '.self::ATTEMPTS.' مرّات، ثمّ بـ --retry-failed).');

        return ($count['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * سطرٌ في سجلّ الصور لدفعةٍ وقفت — والسجلُّ لا يُسقط الجولة.
     *
     * @param  array{reason: string, value: float|int|null, limit: float|int}  $why
     */
    private function paused(array $why, string $path): void
    {
        try {
            Log::channel('images')->warning('images.batch_paused', $why + ['path' => $path]);
        } catch (\Throwable) {
            // ‏الوقفةُ قيلت على الشاشة — والسجلُّ لا يستحقّ أكثر
        }
    }

    /**
     * ما تُصنع له نسخ: صورُ المنتجات وصورُ الموقع التي يشير إليها صفٌّ حيّ.
     *
     * والمسحُ من القاعدة لا من القرص: ملفٌّ لا يُعرض لا تُصنع له نسخة.
     *
     * @return list<string>
     */
    private function candidates(): array
    {
        $business = $this->option('business') !== null ? (int) $this->option('business') : null;
        $owners = $business === null ? null : $this->owned($business);
        $out = [];

        // ‏الأعمدةُ الحيّة وحدها — الجولةُ المجدولة لا تمسح القاعدةَ كلَّها كلَّ ساعة
        foreach (ImageReferences::live($business) as $path => $where) {
            if (ImageVariants::side($path) === null || ImageVariants::isVariant($path) || array_intersect(array_keys($where), ImageReferences::LIVE) === []) {
                continue;
            }

            if ($owners !== null && ! isset($owners[$path])) {
                continue;
            }

            $out[] = $path;
        }

        return $out;
    }

    /**
     * مساراتُ متجرٍ واحد — صورُ منتجاته ومجلّدُ موقعه.
     *
     * @return array<string, true>
     */
    private function owned(int $business): array
    {
        $out = [];

        foreach (['products' => 'image', 'product_images' => 'path'] as $table => $column) {
            foreach (DB::table($table)->where('business_id', $business)->whereNotNull($column)->pluck($column) as $p) {
                $out[(string) $p] = true;
            }
        }

        foreach (Storage::disk('public')->allFiles('website/'.$business) as $p) {
            $out[$p] = true;
        }

        return $out;
    }

    /* ═══════════ النقل والرجوع ═══════════ */

    private function swap(): int
    {
        if ($this->option('business') === null) {
            $this->error('النقلُ لمتجرٍ واحدٍ في كلّ مرّة — أضف --business=ID.');

            return self::FAILURE;
        }

        $business = (int) $this->option('business');

        // ‏نقلٌ انقطع قبلُ يُقرأ أوّلًا — ولا يبدأ آخرُ قبل أن يُعرف أين وقف
        ImageSwap::recover();

        if (($pending = ImageSwap::pendingFor($business)) !== null) {
            $this->error('نقلٌ سابقٌ لهذا المتجر لم يُعرف أين وقف ('.$pending.') — راجعه بـ --rollback قبل نقلٍ جديد.');

            return self::FAILURE;
        }

        $ready = array_slice(array_values(array_filter($this->candidates(), fn ($p) => ! ImageVariants::isVariant($p)
            && ($copy = ImageVariants::display($p)) !== null && is_file(Storage::disk('public')->path($copy)))), 0, max(1, (int) $this->option('limit')));

        $r = ImageSwap::swap($business, $ready);

        foreach (collect($r['refused'])->countBy() as $reason => $n) {
            $this->line('لم تُنقل '.$n.': '.$reason);
        }

        return match ($r['state']) {
            'nothing' => tap(self::SUCCESS, fn () => $this->info('لا شيءَ يُنقل.')),
            'journal_failed' => tap(self::FAILURE, fn () => $this->error('تعذّرت كتابة سجلّ النقل — لم يُمسّ صفّ. '.$r['error'])),
            ImageSwap::ABORTED => tap(self::FAILURE, fn () => $this->error('رُجع عن النقل كلِّه — لم يُمسّ صفّ ('.$r['run'].'). '.$r['error'])),
            ImageSwap::PENDING => tap(self::FAILURE, fn () => $this->warn('نُقلت المراجع ولم يُختم السجلّ ('.$r['run'].') — شغّل --recover.')),
            default => tap(self::SUCCESS, function () use ($r) {
                $this->info('نُقلت مراجعُ '.count($r['swapped']).' صورة ('.$r['rows'].' صفًّا). معرّفُ الجولة: '.$r['run']);
                $this->line('للرجوع: php artisan images:optimize --rollback='.$r['run']);
            }),
        };
    }

    private function recover(): int
    {
        $runs = ImageSwap::recover();

        if ($runs === []) {
            $this->info('لا نقلَ معلّق.');

            return self::SUCCESS;
        }

        foreach ($runs as $r) {
            $this->line($r['run'].': '.$r['state'].' (بعد='.$r['after'].'، قبل='.$r['before'].'، غيرهما='.$r['other'].')');
        }

        return collect($runs)->contains(fn ($r) => $r['state'] === ImageSwap::PENDING) ? self::FAILURE : self::SUCCESS;
    }

    private function rollback(string $run): int
    {
        $r = ImageSwap::rollback($run);

        $this->info('أُعيد '.$r['restored'].' صفًّا.');

        foreach ($r['conflicts'] as $c) {
            $this->warn('تُرك (تغيّر بعد النقل): '.$c);
        }

        return $r['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ما يمكن الاستغناءُ عنه — ولا يُمحى شيء.
     *
     * أصلٌ نُقلت مراجعُه ولم يبقَ ما يشير إليه في أيّ جدول، ونسختُه تُقرأ.
     * وما لم يُشر إليه شيءٌ قطّ يُعدّ وحده: قد يكون صورةً رُفعت ولم تُحفظ —
     * أو صورةً يحتاجها صاحبُها.
     */
    private function retirePlan(): int
    {
        $refs = ImageReferences::scan();
        $swapped = ['n' => 0, 'bytes' => 0];
        $orphans = ['n' => 0, 'bytes' => 0];
        $kept = 0;

        foreach ($this->files() as $path => $bytes) {
            if (ImageVariants::isVariant($path) || ImageVariants::side($path) === null) {
                continue;
            }

            $copy = (string) ImageVariants::display($path);
            $copyUsed = isset($refs[$copy]);

            if (isset($refs[$path])) {
                $kept += $copyUsed ? 1 : 0;

                continue;
            }

            if ($copyUsed && is_file(Storage::disk('public')->path($copy))) {
                $swapped['n']++;
                $swapped['bytes'] += $bytes;
            } else {
                $orphans['n']++;
                $orphans['bytes'] += $bytes;
            }
        }

        $this->table(['', 'ملفّات', 'الحجم'], [
            ['أصولٌ نُقلت مراجعُها ولا يشير إليها شيء', $swapped['n'], $this->mb($swapped['bytes'])],
            ['أصولٌ لا يشير إليها شيءٌ قطّ', $orphans['n'], $this->mb($orphans['bytes'])],
            ['أصولٌ نُقلت ويبقيها طلبٌ أو سجلٌّ قديم', $kept, '—'],
        ]);
        $this->scanCost();
        $this->line('لم يُمحَ شيء. الاستغناءُ عن أيّ أصلٍ بعد نسخةٍ احتياطيّةٍ وقرارٍ منفصل.');

        return self::SUCCESS;
    }

    /* ═══════════ أدوات ═══════════ */

    /** @return array<string, int> كلُّ ملفٍّ على القرص العامّ وحجمُه */
    private function files(): array
    {
        $disk = Storage::disk('public');
        $out = [];

        foreach ($disk->allFiles() as $f) {
            if (! str_starts_with(basename($f), '.')) {
                $out[$f] = (int) @filesize($disk->path($f));
            }
        }

        return $out;
    }

    /** @param  array<string, int>  $where */
    private function kind(string $path, array $where): string
    {
        $folder = strtok($path, '/');

        return match (true) {
            ImageVariants::isVariant($path) => 'copy',
            $folder === 'logos' => 'logo',
            $folder === 'covers' => 'cover',
            $folder === 'avatars' => 'avatar',
            isset($where['products.image']) || isset($where['product_images.path']) => 'product',
            isset($where['categories.card_image']) => 'category',
            isset($where['settings.value']) => 'website',
            $where !== [] => 'other_refs',
            in_array($folder, ['products', 'website'], true) => 'unreferenced',
            default => 'other',
        };
    }

    private function pending(string $path): bool
    {
        $need = ImageVariants::needs($path);

        return $need !== null && ($need['display'] || $need['thumb']);
    }

    /** @return array<string, array{status: string, reason: ?string, attempts: int, at: string}> */
    private function state(): array
    {
        $raw = Storage::disk('local')->exists(self::STATE) ? Storage::disk('local')->get(self::STATE) : null;

        return is_string($raw) ? (array) json_decode($raw, true) : [];
    }

    private function saveState(array $state): void
    {
        Storage::disk('local')->put(self::STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** ما كلّفه مسحُ المراجع — يُقال ليُعرف ثمنُه على القاعدة */
    private function scanCost(): void
    {
        $s = ImageReferences::$stats;

        if ($s !== []) {
            $this->line('مسحُ المراجع: '.$s['tables'].' جدولًا في '.$s['queries'].' سؤالًا، أطولُها '.$s['max_ms'].' ملّي‌ثانية، و'
                .round($s['ms'] / 1000, 1).' ثانية كلُّه ('.round($s['cpu_ms']).' ملّي‌ثانية معالج، ذروةُ الذاكرة '.$this->mb($s['peak']).').');
        }
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
