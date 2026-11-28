<?php

namespace App\Console\Commands;

use App\Support\ImageGate;
use App\Support\ImageMemory;
use App\Support\ImageReferences;
use App\Support\ImageState;
use App\Support\ImageSwap;
use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * صورُ النظام كلُّها: جردٌ، ونسخٌ أخفّ، ونقلُ مراجع، وخطّةُ استغناءٍ عن الأصول.
 *
 *   images:optimize                          جردٌ وتقديرٌ — لا يكتب شيئًا
 *   images:optimize --apply                  يصنع نسخ دفعةٍ (٥٠) ثمّ يقف — بالدور بين المتاجر
 *   images:optimize --backlog                يصنع نسخ ما رُفع والخادمُ مشغول (`ImageState::waiting`)
 *   images:optimize --halt | --resume        يوقف كلَّ معالجةٍ للصور قبل الصورة التالية، ويعيدها
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
 *
 * ═══ ولكلّ متجرٍ دورُه ═══
 *
 * الجولةُ العامّة (بلا `--business`) لا تمشي بترتيب الجداول — فكان أكبرُ المتاجر
 * صورًا يأخذ الدفعاتِ كلَّها وينتظر غيرُه أيّامًا. بل صورةٌ من كلّ متجرٍ ينتظر ثمّ
 * الثانيةُ من كلٍّ (`fair`)، ويبدأ الدورُ بعد آخر متجرٍ عولج في الجولة السابقة.
 * ومتجرٌ يُنشأ غدًا يدخل الدورَ في جولته الأولى بلا إعداد.
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
        {--retire-plan : ما يمكن الاستغناءُ عنه من الأصول، بلا محو}
        {--path=* : صورةٌ بعينها من صور المتجر الحيّة (يتكرّر، وبترتيبه) — مع --apply و--business وحدهما}
        {--backlog : يصنع نسخ الصور المرفوعة التي تنتظر — بلا جردٍ ولا مسح}
        {--halt : يوقف كلَّ معالجةٍ للصور قبل الصورة التالية — الجولات والرفع}
        {--resume : يعيد معالجة الصور بعد --halt}';

    protected $description = 'جرد صور النظام وصنع نسخها الأخفّ ونقل مراجعها تدريجيًّا دون محو أيّ أصل';

    /** مرّاتُ المحاولة قبل أن يُترك فاشلًا حتى يُطلب */
    private const ATTEMPTS = 3;

    /** آخرُ متجرٍ عولجت صورتُه في الجولة العامّة — يبدأ الدورُ التالي بعده (`fair`) */
    private const CURSOR = 'images:cursor';

    public function handle(): int
    {
        /*
         * وصورٌ بعينها لا تُطلب إلّا لصنع نسخها في متجرٍ واحد: لا جردَ بها،
         * ولا نقلَ مراجع، ولا رجوع — فلا يُظنّ أنّ `--swap --path` ينقل صورةً واحدة.
         * و`--path` فارغٌ ليس «بلا --path»: لو أُسقط لصارت الدفعةُ عامّةً
         * بحدّها (خمسون صورة) — فيردّ الأمرَ كلَّه.
         */
        if (collect((array) $this->option('path'))->contains(fn ($p) => trim((string) $p) === '')) {
            $this->error('--path فارغ — سمِّ كلَّ صورةٍ بمسارها. لم تُعالج صورة.');

            return self::FAILURE;
        }

        if ($this->chosen() !== [] && (! $this->option('apply') || $this->option('business') === null
            || $this->option('swap') || filled($this->option('rollback')) || $this->option('recover') || $this->option('retire-plan') || $this->option('backlog'))) {
            $this->error('--path يُستعمل مع --apply و--business وحدهما — لم تُعالج صورة.');

            return self::FAILURE;
        }

        // ‏والمفتاحُ لا ينتظر قفلَ الجولة: يُرى قبل الصورة التالية في أيّ جولةٍ تعمل الآن
        if ($this->option('halt') || $this->option('resume')) {
            return $this->toggle((bool) $this->option('halt'));
        }

        $lock = Cache::lock('images:optimize', 3600);

        if (! $lock->get()) {
            $this->error('جولةٌ أخرى تعمل الآن — لا تُعالج الصور مرّتين معًا.');

            return self::FAILURE;
        }

        try {
            // ‏أولويّةٌ أدنى من البيع والدفع وواتساب على معالجٍ واحد — والجردُ يفكّ عيّنته أيضًا
            $this->yieldToOthers();

            return match (true) {
                filled($this->option('rollback')) => $this->rollback((string) $this->option('rollback')),
                (bool) $this->option('recover') => $this->recover(),
                (bool) $this->option('swap') => $this->swap(),
                (bool) $this->option('retire-plan') => $this->retirePlan(),
                (bool) $this->option('backlog') => $this->backlog(),
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
        $state = ImageState::all();
        $owners = $this->owners();
        $byShop = [];

        foreach ($files as $path => $bytes) {
            $kind = $this->kind($path, $refs[$path] ?? []);
            $rows[$kind] ??= ['n' => 0, 'bytes' => 0];
            $rows[$kind]['n']++;
            $rows[$kind]['bytes'] += $bytes;
            $need = in_array($kind, ['product', 'category', 'website'], true) ? ImageVariants::needs($path) : null;

            if ($need !== null && $this->wants($need, $state[$path] ?? null)) {
                $todo[$kind][] = $path;
                $over += $need['w'] * $need['h'] > ImageMemory::maxPixels() ? 1 : 0;
                $shop = preg_match('#^website/(\d+)/#', $path, $m) ? (int) $m[1] : (int) ($owners[$path] ?? 0);
                $byShop[$shop] = ($byShop[$shop] ?? 0) + 1;
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

        // ‏ما ينتظر في كلّ متجر — والجولةُ العامّة تأخذ منها بالدور (`fair`)
        if ($byShop !== []) {
            arsort($byShop);
            $this->info('تنتظر في '.count($byShop).' متجرًا (أكثرُها):');
            $this->table(['المتجر', 'تنتظر'], collect($byShop)->take(15)->map(fn ($n, $shop) => [$shop ?: '—', $n])->values()->all());
        }

        $waiting = count(ImageState::waiting());
        $failed = count(array_filter($state, fn ($s) => ($s['status'] ?? null) === 'failed'));
        $this->line('مرفوعةٌ تنتظر نسخها: '.$waiting.'. فشلت: '.$failed.'. موقوفة: '.(ImageGate::halted() === null ? 'لا' : 'نعم منذ '.ImageGate::halted()).'.');

        if (($missed = ImageState::missed()) !== null) {
            $this->warn('فات انتظارُ رفعٍ '.max(1, $missed['count']).' مرّةً منذ '.Carbon::createFromTimestamp($missed['since'], config('app.timezone'))->toDateTimeString()
                .' ('.implode('، ', array_keys($missed['reasons'])).') — تستدركه جولةُ --backlog من القرص.');
        }

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
            $k = ['n' => 0, 'before' => 0, 'after' => 0, 'display_n' => 0, 'display' => 0, 'mid_n' => 0, 'mid' => 0, 'thumb_n' => 0, 'thumb' => 0];

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

                if ($r['mid'] > 0) {
                    $k['mid_n']++;
                    $k['mid'] += $r['mid'];
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
            $this->table(['الفئة', 'مقيسة', 'متوسّطُ الأصل', 'نسخةُ العرض', 'الوسطى', 'المصغّرة'], collect($estimate['kinds'])->map(fn ($k, $kind) => [
                $labels[$kind] ?? $kind, $k['n'], $avg($k['before'], $k['n']),
                $k['display_n'].' — '.$avg($k['display'], $k['display_n']),
                $k['mid_n'].' — '.$avg($k['mid'], $k['mid_n']),
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
        $started = hrtime(true);
        $limit = max(1, (int) $this->option('limit'));
        $state = ImageState::all();
        $count = [];
        $saved = 0;
        $done = 0;
        $shops = [];
        $last = null;
        $candidates = $this->candidates();

        /*
         * وصورٌ سمّاها صاحبُ القرار بمساراتها — بترتيبه هو لا بترتيب الجداول:
         * رئيسيّةُ متجرٍ أوّلًا، لا أقدمُ أصنافه. ولا تُقبل إلّا من صور هذا
         * المتجر الحيّة نفسها (`candidates`): مسارٌ لمتجرٍ آخر، أو لا يشير
         * إليه صفّ، أو نسخةٌ — يردّ الدفعةَ كلَّها قبل أن تُفكّ صورة. وكذا
         * ما لا يُقرأ صورةً على القرص، وما يعرضه متجرٌ آخر أيضًا: نسختُه
         * تُخدم هناك كذلك، وتجربةُ متجرٍ لا تغيّر ما يراه زبائنُ غيره.
         */
        if (($chosen = $this->chosen()) !== []) {
            $business = (int) $this->option('business');
            $mine = ImageReferences::live($business);
            $all = ImageReferences::live();

            foreach ([
                'ليست من صور المتجر '.$business.' الحيّة' => array_diff($chosen, $candidates),
                'لا تُقرأ صورةً على القرص' => array_filter($chosen, fn ($p) => ImageVariants::needs($p) === null),
                'يعرضها متجرٌ آخر أيضًا' => array_filter($chosen, fn ($p) => array_sum($all[$p] ?? []) > array_sum($mine[$p] ?? [])),
            ] as $why => $bad) {
                if ($bad !== []) {
                    $this->error($why.': '.implode('، ', $bad).' — لم تُعالج صورة.');

                    return self::FAILURE;
                }
            }

            $candidates = $chosen;
        }

        foreach ($this->fair($candidates, $state) as [$path, $shop]) {
            if ($done >= $limit) {
                break;
            }

            $was = $state[$path] ?? null;

            /*
             * والخادمُ يُسأل قبل كلّ صورة — ذاكرتُه وحِملُه وقرصُه وطوابيرُه، ومفتاحُ
             * الإيقاف (`ImageGate`). وما لا يتّسع تقف عنده الدفعةُ قبل أن تُفكّ صورة،
             * وتُعاد في الجولة التالية من حيث وقفت.
             */
            if (($why = ImageGate::blocked()) !== null) {
                $count['paused'] = 1;
                $this->warn('وقفت الدفعة قبل '.$path.': '.$why['reason'].' — الآن '.($why['value'] ?? 'لا يُقرأ').' والحدّ '.$why['limit'].'. لم تُفكّ صورة، وتُعاد في الجولة التالية.');
                $this->paused($why, $path);

                break;
            }

            $r = $this->made($path);
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
            $done++;
            $shops[$shop] = true;
            $last = $shop;

            // ‏ذاكرةُ الخادم لا تكفي الآن، أو صورةٌ أخرى تُفكّ: تقف الدفعةُ كلُّها — ما بعدها لن يجد ما لم تجده
            if ($r['status'] === 'deferred') {
                $this->warn('وقفت الدفعة قبل فكّ '.$path.': '.$r['reason'].' — تُعاد في الجولة التالية.');

                break;
            }

            // ‏وكلُّ صورةٍ تُكتب حالُها حين تنتهي — فانقطاعٌ بعدها لا يُضيعه. وحالٌ لا تُكتب
            // ‏تقف عندها الدفعة: فشلٌ لا يُعدّ يُعاد كلَّ جولةٍ بلا نهاية
            if (! ImageState::put($path, $this->outcome($r, $was))) {
                $this->warn('وقفت الدفعة: لم تُكتب حالُ '.$path.' — تُعاد في الجولة التالية.');

                break;
            }
            $saved += $r['display'] > 0 ? $r['before'] - $r['display'] : 0;

            // ‏ويُترك المعالجُ لغيره بين صورةٍ وأخرى
            usleep(max(0, (int) config('images.pause_ms', 150)) * 1000);
        }

        // ‏والدورُ التالي يبدأ بعد آخر متجرٍ عولج — في الجولة العامّة وحدها
        if ($last !== null && $this->option('business') === null) {
            Cache::forever(self::CURSOR, $last);
        }

        $state = ImageState::all();
        $left = count(array_filter($candidates, fn ($p) => $this->eligible($p, $state)));
        $failed = count(array_filter($state, fn ($s) => ($s['status'] ?? null) === 'failed'));

        $this->info('عولجت '.$done.' صورة: '.(collect($count)->map(fn ($n, $s) => $s.'='.$n)->implode('، ') ?: 'لا شيء').'.');
        $this->line('نسخُ العرض أخفُّ من أصولها بـ '.$this->mb($saved).'. والأصولُ باقيةٌ كما هي.');
        $this->line('بقي '.$left.' صورةً تنتظر، و'.$failed.' فشلت (تُعاد حتى '.self::ATTEMPTS.' مرّات، ثمّ بـ --retry-failed).');
        $this->summary('apply', $count, $done, count($shops), $left, $started);

        return ($count['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * سطرُ الحال بعد صنع صورة — و`null` لما اكتمل (نسخُه على القرص هي الدليل).
     *
     * وصورةٌ صُنع بعضُها وتُرك منها ما لم يستحقّ مكانه تُحفظ بما تُرك (`skips`):
     * فلا تُفكّ لها مرّةً أخرى لتُترك مرّةً أخرى.
     *
     * @param  array<string, mixed>  $r
     */
    private function outcome(array $r, ?array $was): ?array
    {
        return match ($r['status']) {
            'made', 'ready' => $r['skips'] === [] ? null : ['status' => 'made', 'skips' => $r['skips']],
            'skipped' => ['status' => 'skipped', 'reason' => $r['reason']] + ($r['skips'] === [] ? [] : ['skips' => $r['skips']]),
            'failed' => ['status' => 'failed', 'reason' => $r['reason'], 'attempts' => (int) ($was['attempts'] ?? 0) + 1],
            default => null,
        };
    }

    /**
     * صورٌ رُفعت والخادمُ مشغولٌ أو المعالجةُ موقوفة (`ImageVariants::makeQuietly`) —
     * تُصنع نسخها الآن بالحرّاس أنفسهم، بترتيب وصولها. لا جردَ ولا مسحَ للقاعدة.
     *
     * وما فشل يُعاد حتى `ATTEMPTS` مرّات ثمّ يُترك في الحال فاشلًا — تعيده الجولةُ
     * العامّة بـ`--retry-failed`. وما مُحي أصلُه أو لم يعد ينقصه شيء يخرج من القائمة.
     */
    private function backlog(): int
    {
        $started = hrtime(true);
        $limit = max(1, (int) $this->option('limit'));
        $count = [];
        $done = 0;
        $recovered = $this->recoverMissed();

        foreach (ImageState::waiting() as $path => $entry) {
            if ($done >= $limit) {
                break;
            }

            if (($why = ImageGate::blocked()) !== null) {
                $count['paused'] = 1;
                $this->warn('وقفت الدفعة قبل '.$path.': '.$why['reason'].' — تنتظر الجولة التالية.');
                $this->paused($why, $path);

                break;
            }

            $r = $this->made((string) $path);
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
            $done++;

            if ($r['status'] === 'deferred') {
                break;
            }

            if ($r['status'] === 'failed') {
                $attempts = ImageState::retried((string) $path, (string) $r['reason']);

                // ‏محاولةٌ لا تُعدّ لا تُعاد: تقف الدفعة، فلا تدور صورةٌ تفشل بلا نهاية
                if ($attempts === null) {
                    $this->warn('وقفت الدفعة: لم تُكتب محاولةُ '.$path.' — تُعاد في الجولة التالية.');

                    break;
                }

                if ($attempts < self::ATTEMPTS) {
                    continue;
                }
            }

            /*
             * تُكتب حالُها قبل أن تخرج من القائمة: انقطاعٌ بينهما يتركها تنتظر ولا يُضيعها.
             * وما لا يُكتب تقف عنده الدفعة.
             */
            $row = $this->outcome($r, ['attempts' => self::ATTEMPTS - 1]);

            if (($row !== null && ! ImageState::put((string) $path, $row)) || ! ImageState::release((string) $path)) {
                $this->warn('وقفت الدفعة: لم تُكتب حالُ '.$path.' — تُعاد في الجولة التالية.');

                break;
            }

            usleep(max(0, (int) config('images.pause_ms', 150)) * 1000);
        }

        $left = count(ImageState::waiting());
        $this->info('عولجت '.$done.' صورة مرفوعة: '.(collect($count)->map(fn ($n, $s) => $s.'='.$n)->implode('، ') ?: 'لا شيء').'. وتنتظر '.$left.'.'
            .($recovered > 0 ? ' واستُدرك '.$recovered.' رفعًا فات انتظارُه.' : ''));
        $this->summary('backlog', $count, $done, 0, $left, $started);

        return ($count['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * يصنع نسخ صورة — واستثناءٌ يُعدّ فشلًا لها تُحسب محاولتُه، لا يُسقط الجولة
     * فتبدأ التاليةُ بالصورة نفسها وتسقط عندها بلا نهاية.
     *
     * @return array<string, mixed>
     */
    private function made(string $path): array
    {
        try {
            return ImageVariants::make($path);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'failed', 'reason' => 'exception: '.mb_substr($e->getMessage(), 0, 140),
                'before' => 0, 'display' => 0, 'mid' => 0, 'thumb' => 0, 'skips' => [], 'ms' => 0.0, 'peak' => 0];
        }
    }

    /**
     * رفعٌ فات انتظارُه (`ImageState::missed`) — يُستدرك من القرص لا من سطرٍ لم يُكتب:
     * كلُّ صورةٍ حيّةٍ أصلُها أحدثُ من أوّل ما فات، وينقصها ما يستحقّ، ولم تُترك ولم تفشل
     * مرّاتها، ولا تنتظر — تُكتب في القائمة فتُصنع بدورها. وما قبل أوّل ما فات لا يُمسّ:
     * صورٌ قديمة تنتظر جولتها (`IMAGES_BACKFILL`) لا هذه.
     *
     * ويُمحى العلَمُ حين يُكتب كلُّ ما وُجد ولم يفت رفعٌ آخرُ أثناء الاستدراك. وقائمةٌ لا
     * تتّسع تُبقيه، فتُكمل الجولةُ التالية.
     */
    private function recoverMissed(): int
    {
        if (($missed = ImageState::missed()) === null) {
            return 0;
        }

        $state = ImageState::all();
        $waiting = ImageState::waiting();
        $disk = Storage::disk('public');
        $queued = 0;

        foreach ($this->candidates() as $path) {
            $file = $disk->path($path);

            if (isset($waiting[$path]) || ! is_file($file) || filemtime($file) < $missed['since'] || ! $this->eligible($path, $state)) {
                continue;
            }

            if (! ImageState::wait($path, 'recovered')) {
                $this->warn('استُدرك '.$queued.' رفعًا وبقي غيرُه — قائمةُ الانتظار لا تتّسع الآن، وتُكمل الجولةُ التالية.');

                return $queued;
            }

            $queued++;
        }

        $cleared = ImageState::recovered($missed['count']);

        try {
            Log::channel('images')->notice('images.recovered', ['queued' => $queued, 'since' => $missed['since'], 'cleared' => $cleared]);
        } catch (\Throwable) {
            // ‏السجلُّ لا يُسقط الجولة
        }

        return $queued;
    }

    /** يوقف معالجةَ الصور كلَّها أو يعيدها — ويُكتب في سجلّ الصور من فعل ومتى */
    private function toggle(bool $halt): int
    {
        if ($halt) {
            Cache::forever(ImageGate::HALT, now()->toIso8601String());
        } else {
            Cache::forget(ImageGate::HALT);
        }

        try {
            Log::channel('images')->warning($halt ? 'images.halted' : 'images.resumed');
        } catch (\Throwable) {
            // ‏المفتاحُ قُلب — والسجلُّ لا يستحقّ أكثر
        }

        $this->info($halt
            ? 'أُوقفت معالجةُ الصور: الجولاتُ تقف قبل الصورة التالية، والرفعُ يحفظ الأصلَ وتنتظر نسخُه. أعدها بـ --resume.'
            : 'عادت معالجةُ الصور — وما انتظر يُصنع في الجولة التالية.');

        return self::SUCCESS;
    }

    /**
     * أدنى أولويّةٍ على المعالج والقرص — البيعُ والدفعُ وواتساب أولى بهما.
     *
     * `proc_nice` للمعالج، و`ionice` للقرص حيث وُجد (لينكس) — وإلّا فالمعالجُ وحده.
     */
    private function yieldToOthers(): void
    {
        if (function_exists('proc_nice')) {
            @proc_nice(19);
        }

        if (function_exists('exec') && function_exists('getmypid') && is_executable('/usr/bin/ionice')) {
            @exec('/usr/bin/ionice -c3 -p '.(int) getmypid().' 2>/dev/null');
        }
    }

    /**
     * سطرٌ في سجلّ الصور لكلّ جولة: ما عولج وبأيّ حال، وكم متجرًا، وما بقي، وكم أخذت.
     *
     * @param  array<string, int>  $count
     */
    private function summary(string $mode, array $count, int $done, int $shops, int $left, int $started): void
    {
        try {
            Log::channel('images')->notice('images.batch', ['mode' => $mode, 'done' => $done, 'shops' => $shops, 'left' => $left,
                'ms' => (int) round((hrtime(true) - $started) / 1e6)] + $count);
        } catch (\Throwable) {
            // ‏قيل على الشاشة — والسجلُّ لا يُسقط الجولة
        }
    }

    /**
     * دورٌ بين المتاجر: أوّلُ ما ينتظر في كلّ متجر، ثمّ ثانيه في كلٍّ — فلا ينتظر
     * متجرٌ صغيرٌ حتى ينتهي أكبرُها. ويبدأ الدورُ بعد آخر متجرٍ عولج (`CURSOR`).
     *
     * وما اكتمل أو تُرك يُتخطّى داخل متجره بلا دور. وصاحبُ الصورة متجرُ مجلّدها
     * (`website/{id}/`) أو متجرُ أصنافها (`owners`).
     *
     * @param  list<string>  $paths
     * @return \Generator<int, array{0: string, 1: int}>
     */
    private function fair(array $paths, array $state): \Generator
    {
        $owners = $this->option('business') !== null ? [] : $this->owners();
        $queues = [];

        foreach ($paths as $path) {
            $shop = $this->option('business') !== null ? (int) $this->option('business')
                : (preg_match('#^website/(\d+)/#', $path, $m) ? (int) $m[1] : (int) ($owners[$path] ?? 0));
            $queues[$shop][] = $path;
        }

        ksort($queues);
        $shops = array_keys($queues);
        $after = (int) Cache::get(self::CURSOR, 0);
        $first = count(array_filter($shops, fn ($id) => $id <= $after));
        $shops = $first < count($shops) ? [...array_slice($shops, $first), ...array_slice($shops, 0, $first)] : $shops;

        while ($shops !== []) {
            foreach ($shops as $i => $shop) {
                do {
                    $path = array_shift($queues[$shop]);
                } while ($path !== null && ! $this->eligible($path, $state));

                if ($path === null) {
                    unset($shops[$i]);

                    continue;
                }

                yield [$path, $shop];
            }
        }
    }

    /**
     * صاحبُ كلّ صورة منتج — أوّلُ متجرٍ تشير إليها صفوفُ أصنافه.
     *
     * @return array<string, int>
     */
    private function owners(): array
    {
        $out = [];

        foreach (['products' => 'image', 'product_images' => 'path'] as $table => $column) {
            $rows = DB::table($table)->select('id', 'business_id', $column)->whereNotNull($column)
                ->where($column, 'like', 'products/%')->orderBy('id')->lazyById(1000);

            foreach ($rows as $row) {
                $out[(string) $row->{$column}] ??= (int) $row->business_id;
            }
        }

        return $out;
    }

    /** أتُصنع لهذه الصورة نسخٌ في هذه الجولة؟ — لا تُركت، ولا فشلت مرّاتها كلَّها */
    private function eligible(string $path, array $state): bool
    {
        $was = $state[$path] ?? null;

        if (($was['status'] ?? null) === 'skipped') {
            return false;
        }

        if (($was['status'] ?? null) === 'failed' && (int) ($was['attempts'] ?? 0) >= self::ATTEMPTS && ! $this->option('retry-failed')) {
            return false;
        }

        return $this->pending($path, $was);
    }

    /**
     * المساراتُ التي سُمّيت بـ`--path`، بلا تكرارٍ وبترتيبها — كما كُتبت حرفًا:
     * مسافةٌ حول مسارٍ تجعله غيرَ مسارِ المتجر فيُردّ، لا يُصحَّح.
     *
     * @return list<string>
     */
    private function chosen(): array
    {
        return array_values(array_unique(array_map(fn ($p) => (string) $p, (array) $this->option('path'))));
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

    /** أينقصها ما يستحقّ أن يُصنع؟ — وما تُرك منها لأنّه لا يوفّر (`skips`) لا يُطلب ثانيةً */
    private function pending(string $path, ?array $was = null): bool
    {
        $need = ImageVariants::needs($path);

        return $need !== null && $this->wants($need, $was);
    }

    /** @param  array{display: bool, mid: bool, thumb: bool}  $need */
    private function wants(array $need, ?array $was): bool
    {
        $skips = (array) ($was['skips'] ?? []);

        foreach (['display', 'mid', 'thumb'] as $copy) {
            if ($need[$copy] && ! in_array($copy, $skips, true)) {
                return true;
            }
        }

        return false;
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
