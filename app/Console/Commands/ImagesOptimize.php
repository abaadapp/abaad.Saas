<?php

namespace App\Console\Commands;

use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * جردُ الصور ونسخُها الأخفّ — دفعةً صغيرةً في كلّ مرّة.
 *
 *   php artisan images:optimize                 جردٌ وتقديرٌ — لا يكتب شيئًا
 *   php artisan images:optimize --apply         يصنع نسخ ٥٠ صورةً ثمّ يقف
 *
 * ═══ ولا يُمسّ أصلٌ ولا صفّ ═══
 *
 * النسخُ ملفّاتٌ جديدةٌ إلى جوار أصولها (`ImageVariants`)، والقاعدةُ لا تُكتب.
 * فما صُنع يُرجع عنه بمحو النسخ وحدها، وما بقي على حاله يُعرض كما كان.
 *
 * والدفعةُ محدودة: صورٌ قديمةٌ كثيرةٌ تُفكّ معًا تأخذ ذاكرةَ الخادم ومعالجَه
 * من الزبائن. وما صُنع لا يُعاد، فالجولةُ التالية تبدأ حيث وقفت السابقة.
 */
class ImagesOptimize extends Command
{
    protected $signature = 'images:optimize
        {--apply : يصنع النسخ فعلًا — وبدونه جردٌ وتقديرٌ لا يكتب شيئًا}
        {--limit=50 : أكثرُ ما يُعالج من الصور في هذه الدفعة}
        {--business= : متجرٌ واحد}
        {--sample=30 : في الجرد: كم صورةً تُضغط في الذاكرة لتقدير التوفير}';

    protected $description = 'جرد الصور وصنع نسخها المصغّرة والمضغوطة تدريجيًّا دون المساس بالأصول';

    public function handle(): int
    {
        $lock = Cache::lock('images:optimize', 3600);

        if (! $lock->get()) {
            $this->error('جولةٌ أخرى تعمل الآن — لا تُفكّ الصور مرّتين معًا.');

            return self::FAILURE;
        }

        try {
            $business = $this->option('business') !== null ? (int) $this->option('business') : null;
            $paths = $this->paths($business);

            $this->disk($business);

            return $this->option('apply')
                ? $this->apply($paths, max(1, (int) $this->option('limit')))
                : $this->estimate($paths, max(1, (int) $this->option('sample')));
        } finally {
            $lock->release();
        }
    }

    /**
     * صورُ المنتجات التي يشير إليها صفّ — الرئيسية والمعرض، بلا تكرار.
     *
     * من القاعدة لا من القرص: ملفٌّ لا يشير إليه شيءٌ لا يُعرض، فلا تُصنع له
     * نسخ. والمنتجُ في السلّة يُترك: لا يُعرض، وقد يُمحى.
     *
     * @return list<string>
     */
    private function paths(?int $business): array
    {
        $main = DB::table('products')->whereNull('deleted_at')
            ->when($business !== null, fn ($q) => $q->where('business_id', $business))
            ->whereNotNull('image')->where('image', '!=', '')->where('image', 'not like', 'http%')
            ->orderBy('id')->pluck('image');

        $gallery = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->whereNull('products.deleted_at')
            ->when($business !== null, fn ($q) => $q->where('product_images.business_id', $business))
            ->whereNotNull('product_images.path')->where('product_images.path', 'not like', 'http%')
            ->orderBy('product_images.id')->pluck('product_images.path');

        return $main->merge($gallery)->map(fn ($p) => (string) $p)
            ->filter(fn ($p) => collect(ImageVariants::FOLDERS)->contains(fn ($f) => str_starts_with($p, $f.'/')))
            ->unique()->values()->all();
    }

    /** ما على القرص الآن — بالمجلّد، والنسخُ وحدها */
    private function disk(?int $business): void
    {
        $disk = Storage::disk('public');
        $rows = [];

        foreach ($business === null ? $disk->directories() : ['website/'.$business] as $folder) {
            $files = $disk->allFiles($folder);
            $variants = array_filter($files, fn ($f) => ImageVariants::isVariant($f));
            $size = fn (array $list) => array_sum(array_map(fn ($f) => (int) @filesize($disk->path($f)), $list));

            $rows[] = [$folder, count($files) - count($variants), $this->mb($size(array_diff($files, $variants))), count($variants), $this->mb($size($variants))];
        }

        $this->info('ما على القرص (public):');
        $this->table(['المجلّد', 'ملفّات', 'حجمها', 'نسخٌ مصنوعة', 'حجمها'], $rows);
    }

    /** @param  list<string>  $paths */
    private function estimate(array $paths, int $sample): int
    {
        $todo = array_values(array_filter($paths, fn ($p) => ! ImageVariants::handled($p)));
        $before = array_sum(array_map(fn ($p) => (int) @filesize(Storage::disk('public')->path($p)), $paths));
        $tried = ['n' => 0, 'before' => 0, 'after' => 0, 'thumb' => 0, 'ms' => 0.0, 'peak' => 0];

        foreach (array_slice($todo, 0, $sample) as $path) {
            $r = ImageVariants::make($path, false);

            if ($r['before'] === 0) {
                continue;
            }

            $tried['n']++;
            $tried['before'] += $r['before'];
            $tried['after'] += $r['display'] ?: $r['before'];
            $tried['thumb'] += $r['thumb'];
            $tried['ms'] += $r['ms'];
            $tried['peak'] = max($tried['peak'], $r['peak']);
        }

        $this->info('صورُ المنتجات المعروضة: '.count($paths).' صورةً بـ '.$this->mb($before).' — تنتظر منها '.count($todo).'.');

        if ($tried['n'] === 0) {
            $this->line('لا شيءَ يُقدَّر: كلُّ صورةٍ لها نسخُها، أو لا صور.');

            return self::SUCCESS;
        }

        $ratio = $tried['after'] / $tried['before'];
        $this->table(['', 'العيّنة ('.$tried['n'].')', 'تقديرُ الكلّ'], [
            ['الأصول', $this->mb($tried['before']), $this->mb($before)],
            ['ما يُنزله الزائر في صفحة الصنف', $this->mb($tried['after']).' ('.round((1 - $ratio) * 100).'% أقلّ)', $this->mb((int) ($before * $ratio))],
            ['المصغّراتُ للقوائم', $this->mb($tried['thumb']), $this->mb((int) ($tried['thumb'] / $tried['n'] * count($todo)))],
        ]);
        $this->line('متوسّطُ المعالجة: '.round($tried['ms'] / $tried['n']).' ملّي‌ثانية للصورة، وذروةُ الذاكرة '.$this->mb($tried['peak']).'.');
        $this->line('لم يُكتب شيء. للصنع: --apply (دفعةٌ من '.(int) $this->option('limit').').');

        return self::SUCCESS;
    }

    /** @param  list<string>  $paths */
    private function apply(array $paths, int $limit): int
    {
        $count = [];
        $saved = 0;
        $done = 0;

        foreach ($paths as $path) {
            if ($done >= $limit) {
                break;
            }

            if (ImageVariants::handled($path)) {
                continue;
            }

            $r = ImageVariants::make($path);
            $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
            $saved += $r['display'] > 0 ? $r['before'] - $r['display'] : 0;
            $done++;
        }

        $left = count(array_filter($paths, fn ($p) => ! ImageVariants::handled($p)));

        $this->info('عولجت '.$done.' صورة: '.collect($count)->map(fn ($n, $s) => $s.'='.$n)->implode('، ').'.');
        $this->line('نسخُ العرض أخفُّ من أصولها بـ '.$this->mb($saved).'. والأصولُ باقيةٌ كما هي.');
        $this->line('بقي '.$left.' صورةً لم تُعالج.');

        return in_array('failed', array_keys($count), true) ? self::FAILURE : self::SUCCESS;
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
