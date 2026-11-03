<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * نسخةُ الصورة تُرى كاملةً أو لا تُرى — ولو انقطعت كتابتُها، ولو كتبها اثنان معًا.
 *
 * ═══ لمَ ═══
 *
 * كانت النسخةُ تُكتب باسمها مباشرةً: عمليّةٌ تنقطع في منتصف الكتابة تترك نصفَ
 * ملفٍّ تعرضه الصفحات، و«هل هي موجودة؟» يقول نعم فلا تُصلَح أبدًا. ورأسُ
 * الملفّ سليمٌ فيها، فـ`getimagesize` يصدّقها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كتابةٌ سقطت في منتصفها لا تترك بالاسم شيئًا، والصفحةُ تعرض الأصل، والجولةُ
 *     التالية تصنع النسخة.
 *   - عمليّةٌ قُتلت لا تترك إلّا ملفًّا مخفيًّا لا يُعرض، يُمحى حين يقدُم.
 *   - نسخةٌ ناقصةٌ من قبل الإصلاح تُعرف وتُصنع من جديد.
 *   - نسخةٌ سليمةٌ لا تُستبدل — ولا حين يسبق رفعٌ الجولةَ إليها، ولا حين تتسابق
 *     عمليّتان حقيقيّتان.
 *   - الأصلُ وصفُّه لا يُمسّان في أيٍّ من ذلك.
 */
class ACopyIsNeverSeenHalfWrittenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0]);

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(7);

        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $n = mt_rand(-20, 20);
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, imagecolorallocate($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)),
                    max(0, min(255, (int) (160 * $y / $h) + 50 + $n)),
                    max(0, min(255, (int) (120 + 80 * sin(($x + $y) / 70)) + $n))));
            }
        }

        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    private function webp(\GdImage $image, int $quality): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** @return array{0: Product, 1: string} منتجٌ قديمٌ وملفُّ صورته */
    private function oldProduct(): array
    {
        $file = 'products/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($file, $this->jpeg(2400, 1800));

        return [Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $file,
        ]), $file];
    }

    /** نسخةٌ كاملة: رأسُ WebP يقول حجمَ الملفّ كما هو */
    private function assertWhole(string $path): void
    {
        $bytes = (string) Storage::disk('public')->get($path);

        $this->assertSame('RIFF', substr($bytes, 0, 4), $path.' ليست WebP');
        $this->assertSame(strlen($bytes), unpack('V', substr($bytes, 4, 4))[1] + 8, $path.' ناقصة');
        $this->assertIsArray(getimagesizefromstring($bytes));
    }

    /** قرصٌ يمرّر كتابة النسخة المؤقّتة إلى `$hook` قبل أن يكتبها — ويُعيد القرصَ كما كان */
    private function diskThat(callable $hook): FilesystemAdapter
    {
        $fake = Storage::disk('public');

        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $hook) extends FilesystemAdapter
        {
            /** @var callable */
            private $hook;

            public function __construct($driver, $adapter, array $config, callable $hook)
            {
                parent::__construct($driver, $adapter, $config);
                $this->hook = $hook;
            }

            public function put($path, $contents, $options = [])
            {
                if (str_starts_with(basename($path), '.')) {
                    ($this->hook)($this, $path, $contents);
                }

                return parent::put($path, $contents, $options);
            }
        });

        return $fake;
    }

    public function test_a_write_cut_off_midway_leaves_nothing_a_page_can_show(): void
    {
        [$product, $file] = $this->oldProduct();
        $original = md5((string) Storage::disk('public')->get($file));

        // ‏القرصُ يموت في منتصف كتابة نسخة العرض: نصفُ الملفّ ثمّ انقطاع
        $fake = $this->diskThat(function ($disk, $path, $contents) {
            if (str_ends_with($path, '.w1600.webp')) {
                file_put_contents($disk->path($path), substr($contents, 0, intdiv(strlen($contents), 2)));

                throw new \RuntimeException('disk died');
            }
        });

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('و1 فشلت')->assertFailed();

        $this->assertSame([$file], Storage::disk('public')->allFiles('products'), 'بقي نصفُ نسخةٍ أو ملفٌّ مؤقّت');
        $this->assertSame(Storage::url($file), $product->fresh()->image, 'الصفحةُ لا تعرض الأصل');

        // ‏وحين يعود القرص تُصنع كاملة — والأصلُ وصفُّه كما كانا
        Storage::set('public', $fake);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();
        $this->assertWhole((string) ImageVariants::display($file));
        $this->assertWhole(ImageVariants::path($file, ImageVariants::THUMB));
        $this->assertSame($original, md5((string) Storage::disk('public')->get($file)));
        $this->assertSame($file, DB::table('products')->where('id', $product->id)->value('image'));
    }

    public function test_a_process_killed_while_writing_leaves_only_a_hidden_file_that_is_cleared_once_old(): void
    {
        [$product, $file] = $this->oldProduct();
        $display = (string) ImageVariants::display($file);
        $dir = dirname(Storage::disk('public')->path($display));

        // ‏عمليّةٌ قُتلت قبل عشرين دقيقة وأخرى ما زالت تكتب الآن
        $dead = $dir.'/.aaaaaaaaaaaa.'.basename($display);
        $live = $dir.'/.bbbbbbbbbbbb.'.basename($display);
        file_put_contents($dead, 'RIFF half');
        file_put_contents($live, 'RIFF writing');
        touch($dead, time() - 1200);

        $this->assertSame(Storage::url($file), $product->fresh()->image, 'عُرض ملفٌّ مؤقّت');
        $this->assertTrue(ImageVariants::needs($file)['display'], 'ملفٌّ مؤقّتٌ حُسب نسخة');

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        $this->assertWhole($display);
        $this->assertFileDoesNotExist($dead, 'بقي ما تركته عمليّةٌ ميّتة');
        $this->assertFileExists($live, 'مُحي ملفُّ كاتبٍ لم ينتهِ');
        $this->assertSame(Storage::url($display), $product->fresh()->image);
    }

    public function test_a_copy_cut_short_before_this_fix_is_found_and_made_again(): void
    {
        [$product, $file] = $this->oldProduct();
        ImageVariants::make($file);
        $display = (string) ImageVariants::display($file);
        $thumb = ImageVariants::path($file, ImageVariants::THUMB);
        $thumbBefore = md5((string) Storage::disk('public')->get($thumb));
        $original = md5((string) Storage::disk('public')->get($file));

        // ‏انقطاعٌ قديمٌ ترك نصفَ نسخة العرض باسمها — ورأسُها سليمٌ يُصدَّق
        $bytes = (string) Storage::disk('public')->get($display);
        Storage::disk('public')->put($display, substr($bytes, 0, intdiv(strlen($bytes), 2)));
        $this->assertIsArray(getimagesize(Storage::disk('public')->path($display)));

        $this->assertTrue(ImageVariants::needs($file)['display'], 'النسخةُ الناقصة عُدّت كاملة');
        $this->assertFalse(ImageVariants::needs($file)['thumb'], 'المصغّرةُ السليمة طُلبت من جديد');
        $this->assertFalse(ImageVariants::needs($file)['mid'], 'الوسطى السليمة طُلبت من جديد');

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        $this->assertWhole($display);
        $this->assertSame($thumbBefore, md5((string) Storage::disk('public')->get($thumb)), 'استُبدلت مصغّرةٌ سليمة');
        $this->assertSame($original, md5((string) Storage::disk('public')->get($file)));
        $this->assertSame($file, DB::table('products')->where('id', $product->id)->value('image'));
        $expected = [$file, $display, ImageVariants::path($file, ImageVariants::MID), $thumb];
        $files = Storage::disk('public')->allFiles('products');
        sort($expected);
        sort($files);
        $this->assertSame($expected, $files, 'بقي ملفٌّ مؤقّت أو ضاع ملف');
    }

    public function test_a_sound_copy_is_never_replaced_and_a_broken_one_is(): void
    {
        $image = imagecreatetruecolor(800, 600);
        imagefilledrectangle($image, 0, 0, 799, 599, imagecolorallocate($image, 200, 80, 120));
        [$first, $second] = [$this->webp($image, 40), $this->webp($image, 90)];
        $copy = 'products/'.Str::random(40).'.jpg.w640.webp';

        $this->assertSame('published', ImageVariants::publish($copy, $first, $image));
        $this->assertSame('kept', ImageVariants::publish($copy, $second, $image));
        $this->assertSame(md5($first), md5((string) Storage::disk('public')->get($copy)), 'استُبدلت نسخةٌ سليمة');

        Storage::disk('public')->put($copy, substr($first, 0, 100));
        $this->assertSame('replaced', ImageVariants::publish($copy, $second, $image));
        $this->assertSame(md5($second), md5((string) Storage::disk('public')->get($copy)));

        // ‏وما لا يُقرأ بأبعاده لا يُنشر أصلًا
        $wrong = imagecreatetruecolor(10, 10);
        $this->assertSame('failed', ImageVariants::publish('products/'.Str::random(40).'.jpg.w640.webp', $first, $wrong));

        $this->assertSame([$copy], Storage::disk('public')->allFiles('products'), 'بقي ملفٌّ مؤقّت');
    }

    public function test_an_upload_that_makes_the_same_copy_first_is_kept_not_overwritten(): void
    {
        [$product, $file] = $this->oldProduct();
        $original = md5((string) Storage::disk('public')->get($file));
        $theirs = $this->webp(imagecreatetruecolor(1600, 1200), 60);

        // ‏بين قرار الجولة أن تكتب ونشرِها، يُنهي رفعٌ آخرُ النسخةَ نفسها
        $raced = false;
        $this->diskThat(function ($disk, $path) use (&$raced, $theirs) {
            if (! $raced && str_ends_with($path, '.w1600.webp')) {
                $raced = true;
                file_put_contents($disk->path(dirname($path).'/'.substr(basename($path), 14)), $theirs);
            }
        });

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        $this->assertTrue($raced);
        $this->assertSame(md5($theirs), md5((string) Storage::disk('public')->get((string) ImageVariants::display($file))), 'الجولةُ كتبت فوق نسخة الرفع');
        $this->assertWhole(ImageVariants::path($file, ImageVariants::THUMB));
        $this->assertCount(4, Storage::disk('public')->allFiles('products'), 'بقي ملفٌّ مؤقّت — أو نقصت نسخة (الأصلُ ونسخُه الثلاث)');
        $this->assertSame($original, md5((string) Storage::disk('public')->get($file)));
        $this->assertSame($file, DB::table('products')->where('id', $product->id)->value('image'));
    }

    public function test_a_copy_is_never_published_under_an_original_name(): void
    {
        [, $file] = $this->oldProduct();
        $original = md5((string) Storage::disk('public')->get($file));
        $image = imagecreatetruecolor(2400, 1800);
        $bytes = $this->webp($image, 80);

        $this->assertSame('failed', ImageVariants::publish($file, $bytes, $image), 'نُشرت نسخةٌ باسم الأصل');
        $this->assertSame('failed', ImageVariants::publish('logos/'.Str::random(40).'.png.w640.webp', $bytes, $image), 'نُشرت نسخةٌ في مجلّدٍ لا نسخَ له');
        $this->assertSame($original, md5((string) Storage::disk('public')->get($file)));
        $this->assertSame([$file], Storage::disk('public')->allFiles('products'));
    }

    public function test_the_slow_path_waits_for_the_copy_lock_and_gives_up_without_writing(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $image = imagecreatetruecolor(800, 600);
        $bytes = $this->webp($image, 80);
        $copy = 'products/'.Str::random(40).'.jpg.w640.webp';

        // ‏كاتبٌ آخرُ يقرّر في هذه النسخة الآن — والقرصُ بلا روابط صلبة، أو اسمُها مأخوذٌ بنسخةٍ ناقصة
        $held = Cache::lock('images:publish:'.md5($copy), 30);
        $this->assertTrue($held->get());

        config(['images.hard_links' => false]);
        $this->assertSame('failed', ImageVariants::publish($copy, $bytes, $image), 'نُشرت والقفلُ بيد غيرها');
        config(['images.hard_links' => true]);
        Storage::disk('public')->put($copy, 'RIFF short');
        $this->assertSame('failed', ImageVariants::publish($copy, $bytes, $image), 'استُبدلت والقفلُ بيد غيرها');
        $this->assertSame('RIFF short', Storage::disk('public')->get($copy));
        $this->assertSame([$copy], Storage::disk('public')->allFiles('products'), 'بقي ملفٌّ مؤقّت');

        $held->release();
        $this->assertSame('replaced', ImageVariants::publish($copy, $bytes, $image));
        $this->assertWhole($copy);
    }

    public function test_two_processes_racing_for_one_copy_leave_exactly_one_whole_copy(): void
    {
        $this->race(fn ($copy) => Storage::disk('public')->delete($copy), ['kept', 'published']);
    }

    public function test_without_hard_links_two_processes_still_leave_exactly_one_whole_copy(): void
    {
        // ‏قرصٌ لا يعرف الروابط الصلبة، أو `link` معطّلةٌ في الخادم — فالنشرُ بالقفل وحده
        config(['images.hard_links' => false]);

        $this->race(fn ($copy) => Storage::disk('public')->delete($copy), ['kept', 'published']);
    }

    public function test_two_processes_replacing_a_short_copy_replace_it_once(): void
    {
        $this->race(fn ($copy, $payloads) => Storage::disk('public')->put($copy, substr($payloads[0], 0, 100)), ['kept', 'replaced']);
    }

    /**
     * عمليّتان حقيقيّتان تنشران النسخةَ نفسها في اللحظة نفسها، جولةً بعد جولة.
     *
     * والقفلُ في ملفّات (`cache.default=file`) ليراه الطرفان — كما يرى رفعٌ
     * وجولةٌ على الخادم قفلَ القاعدة.
     *
     * @param  callable(string, list<string>): void  $before  ما يسبق كلَّ جولةٍ على اسم النسخة
     * @param  list<string>  $expected  نتيجتا العمليّتين مرتّبتين
     */
    private function race(callable $before, array $expected): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl وposix غير متاحين هنا');
        }

        $locks = sys_get_temp_dir().'/abaad-race-'.Str::random(8);
        config(['cache.stores.file.path' => $locks, 'cache.stores.file.lock_path' => $locks]);
        Cache::purge('file');
        Cache::setDefaultDriver('file');

        $image = imagecreatetruecolor(800, 600);
        imagefilledrectangle($image, 0, 0, 799, 599, imagecolorallocate($image, 40, 140, 90));
        $payloads = [$this->webp($image, 40), $this->webp($image, 90)];
        $copy = 'products/'.Str::random(40).'.jpg.w640.webp';
        $flag = (string) tempnam(sys_get_temp_dir(), 'race');

        foreach (range(1, 8) as $round) {
            $before($copy, $payloads);
            file_put_contents($flag, '');
            $go = microtime(true) + 0.2;
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                try {
                    time_sleep_until($go);
                    file_put_contents($flag, ImageVariants::publish($copy, $payloads[0], $image));
                } finally {
                    // ‏العمليّةُ الأخرى لا تُكمل الاختبارات ولا تُغلق ما فتحه الأصل
                    posix_kill(getmypid(), SIGKILL);
                }
            }

            time_sleep_until($go);
            $mine = ImageVariants::publish($copy, $payloads[1], $image);
            pcntl_waitpid($pid, $status);

            $results = [$mine, (string) file_get_contents($flag)];
            sort($results);
            $this->assertSame($expected, $results, 'الجولة '.$round);
            $this->assertContains(md5((string) Storage::disk('public')->get($copy)), array_map('md5', $payloads));
            $this->assertWhole($copy);
            $this->assertSame([$copy], Storage::disk('public')->allFiles('products'), 'بقي ملفٌّ مؤقّت في الجولة '.$round);
        }

        @unlink($flag);
        (new Filesystem)->deleteDirectory($locks);
    }
}
