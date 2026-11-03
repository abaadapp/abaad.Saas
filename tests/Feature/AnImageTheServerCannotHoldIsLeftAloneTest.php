<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ImageMemory;
use App\Support\ImageState;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use App\Support\Store\StoreImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صورةٌ لا يتّسع لها الخادمُ تُترك بأمان — ولا يسقط رفعٌ ولا بيع.
 *
 * ═══ لمَ ═══
 *
 * مكتبةُ الصور على الإنتاج تحجز ذاكرةَ البكسلات خارج حساب PHP، فلا يحرسها
 * `memory_limit`. وخادمٌ بغيغابايتٍ يبيع ويُرسل واتساب ويستقبل الدفع في الوقت
 * نفسه. فالحارسُ يقرأ ذاكرةَ النظام المتاحة (`ImageMemory`).
 *
 * ═══ ما يُحرس ═══
 *
 *   - ذاكرةٌ لا تكفي: الجولةُ تقف وتؤجّل، ولا تكتب ولا تَعُدّها فاشلة — وتُكمل
 *     حين تعود الذاكرة.
 *   - صورةٌ فوق سقف البكسلات: تُترك مرّةً ويُكتب سببُها في السجلّ ولا تُعاد.
 *   - رفعٌ والذاكرةُ لا تكفي: يُحفظ الأصلُ كما وصل، والرفعُ ينجح.
 *   - التقديرُ: متوسّطُ المصغّرة من الصور التي صُنعت لها وحدها.
 */
class AnImageTheServerCannotHoldIsLeftAloneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private string $meminfo;

    /** سجلُّ الصور — كما يُكتب على الإنتاج، في ملفٍّ مؤقّت */
    private string $imageLog;

    /** سجلُّ النظام — للأخطاء وحدها، كما على الإنتاج */
    private string $systemLog;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        $this->meminfo = (string) tempnam(sys_get_temp_dir(), 'meminfo');
        $this->imageLog = (string) tempnam(sys_get_temp_dir(), 'images-log');
        $this->systemLog = (string) tempnam(sys_get_temp_dir(), 'system-log');
        config([
            'images.pause_ms' => 0, 'images.meminfo' => $this->meminfo, 'images.reserve_mb' => 200,
            'logging.channels.images' => ['driver' => 'single', 'path' => $this->imageLog, 'level' => 'warning'],
            'logging.channels.system' => ['driver' => 'single', 'path' => $this->systemLog, 'level' => 'error'],
            'logging.default' => 'system',
        ]);
        $this->available(4096);

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    protected function tearDown(): void
    {
        @unlink($this->meminfo);
        @unlink($this->imageLog);
        @unlink($this->systemLog);
        parent::tearDown();
    }

    /** ما يقوله النظامُ عن ذاكرته المتاحة — بالميغابايت */
    private function available(int $mb): void
    {
        file_put_contents($this->meminfo, "MemTotal:        984064 kB\nMemFree:          160768 kB\nMemAvailable:   ".($mb * 1024)." kB\n");
    }

    /**
     * سطورُ سجلّ الصور: السببُ وما معه.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function imageLines(): array
    {
        preg_match_all('/\.WARNING: (images\.[a-z_]+) (\{.*\})(?: \[\])?\s*$/m', (string) file_get_contents($this->imageLog), $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [$x[1], (array) json_decode($x[2], true)], $m);
    }

    /** صورةٌ بأبعادٍ في رأسها وحده — تُقرأ أبعادُها ولا تُفكّ، فلا تأخذ ذاكرة */
    private function hugeHeader(int $w, int $h): string
    {
        return "\xFF\xD8\xFF\xC0\x00\x11\x08".pack('n', $h).pack('n', $w)."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
    }

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(5);

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

    private function oldProduct(int $w = 2400, int $h = 1800): string
    {
        $file = 'products/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($file, $this->jpeg($w, $h));
        Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $file]);

        return $file;
    }

    public function test_the_need_is_never_below_what_was_measured(): void
    {
        // ‏قِيس بذاكرة العمليّة المقيمة: ١٢ ميغابكسل ٩٢ ميغابايت، و٢٤ ميغابكسل ١٣٩
        $this->assertGreaterThanOrEqual(92 * 1048576, ImageMemory::need(12_000_000));
        $this->assertGreaterThanOrEqual(139 * 1048576, ImageMemory::need(24_000_000));
        $this->assertSame(24_000_000, ImageMemory::maxPixels());

        $this->available(400);
        $this->assertNull(ImageMemory::shortFor(12_000_000), '١٢ ميغابكسل لا تتّسع لها ٤٠٠ ميغابايت');
        $this->available(300);
        $this->assertSame('low_memory', ImageMemory::shortFor(24_000_000), '٢٤ ميغابكسل تُفكّ وتترك أقلّ من الهامش');

        config(['images.meminfo' => '/no/such/meminfo']);
        $this->assertNull(ImageMemory::available());
        $this->assertNull(ImageMemory::shortFor(24_000_000), 'خادمٌ لا يُقرأ نظامُه مُنع من الفكّ');
    }

    public function test_a_batch_stops_when_memory_is_short_and_carries_on_when_it_comes_back(): void
    {
        $file = $this->oldProduct();
        $this->oldProduct();
        $this->available(150);

        $this->artisan('images:optimize', ['--apply' => true])
            ->expectsOutputToContain('وقفت الدفعة')->expectsOutputToContain('deferred=1')->assertSuccessful();

        $this->assertCount(2, Storage::disk('public')->allFiles('products'), 'كُتبت نسخةٌ والذاكرةُ لا تكفي');
        $this->assertSame([], ImageState::all(), 'المؤجَّلُ عُدّ فاشلًا أو متروكًا');
        [[$reason, $context]] = $this->imageLines();
        $this->assertSame('images.low_memory', $reason);
        $this->assertSame([$file, 150], [$context['path'], $context['available_mb']]);

        $this->available(2048);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=2')->assertSuccessful();
        Storage::disk('public')->assertExists((string) ImageVariants::display($file));
    }

    public function test_an_image_above_the_pixel_cap_is_left_once_and_its_reason_logged(): void
    {
        config(['images.max_megapixels' => 2]);
        $big = $this->oldProduct(2400, 1800);
        $small = $this->oldProduct(1400, 1000);

        $this->artisan('images:optimize')->expectsOutputToContain('فوق سقف 2 ميغابكسل: 1 صورة')
            ->expectsOutputToContain('لم تُقس 1 من العيّنة: too_large')->assertSuccessful();
        // ‏سطرٌ واحدٌ يُطابق توقّعًا واحدًا — فالحالان في توقّعٍ واحد
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('skipped=1، made=1')->assertSuccessful();

        $this->assertSame([$big], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $big))));
        Storage::disk('public')->assertExists((string) ImageVariants::display($small));
        $this->assertSame('too_large', json_decode((string) Storage::disk('local')->get('image-variants/state.json'), true)[$big]['reason']);
        // ‏الجردُ ثمّ الجولة يتركانها مرّتين — وسطرٌ واحدٌ في السجلّ لا اثنان
        $this->assertSame([['images.too_large', $big, 4.3]], array_map(fn ($l) => [$l[0], $l[1]['path'], $l[1]['megapixels']], $this->imageLines()));

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    public function test_a_reason_reaches_its_own_log_while_the_system_log_keeps_errors_only(): void
    {
        $this->available(120);
        $path = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $this->jpeg(2000, 1500))])[0];
        $this->assertStringEndsWith('.jpg', $path);

        $huge = $this->hugeHeader(8200, 6200);
        $big = ProductImages::putAll([UploadedFile::fake()->createWithContent('poster.jpg', $huge)])[0];
        $this->assertSame(md5($huge), md5((string) Storage::disk('public')->get($big)), 'صورةٌ فوق السقف لم تُحفظ كما رُفعت');

        // ‏الرفعُ يحفظ الأصل، ثمّ نسخُه تُطلب فتُترك هي أيضًا — وكلٌّ بموضعه وسببه
        $lines = $this->imageLines();
        $this->assertSame(
            [['images.low_memory', 'product_upload'], ['images.low_memory', 'variants'], ['images.too_large', 'product_upload'], ['images.too_large', 'variants']],
            array_map(fn ($l) => [$l[0], $l[1]['where']], $lines),
        );

        // ‏أبعادٌ وذاكرةٌ واسمُ ملفٍّ عشوائيّ — لا اسمَ زبونٍ ولا رقمَه ولا اسمَ الملفّ كما رُفع
        $keys = ['where', 'path', 'width', 'height', 'megapixels', 'need_mb', 'available_mb'];
        foreach ($lines as [, $context]) {
            $this->assertSame([], array_diff(array_keys($context), $keys));
        }
        $this->assertStringNotContainsString('rose', (string) file_get_contents($this->imageLog));
        $this->assertStringNotContainsString('poster', (string) file_get_contents($this->imageLog));
        $this->assertSame('', (string) file_get_contents($this->systemLog), 'سجلُّ النظام كتب ما ليس خطأً');
    }

    public function test_a_reason_repeated_in_a_burst_is_written_once_and_counted(): void
    {
        $this->available(120);
        $upload = fn () => ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $this->jpeg(900, 700))]);

        foreach (range(1, 5) as $i) {
            $upload();
        }

        // ‏سطرٌ للرفع وسطرٌ لنسخه — لا عشرة
        $where = fn () => array_map(fn ($l) => $l[1]['where'].'='.($l[1]['suppressed'] ?? 0), $this->imageLines());
        $this->assertSame(['product_upload=0', 'variants=0'], $where(), 'خمسةُ رفوعٍ والذاكرةُ ناقصة كتبت سطرًا لكلّ رفع');

        $this->travel(11)->minutes();
        $upload();

        $this->assertSame(['product_upload=0', 'variants=0', 'product_upload=4', 'variants=4'], $where(), 'السطرُ التالي لم يقل كم سُكت عنه');
    }

    public function test_the_images_log_is_a_daily_file_in_storage_at_notice(): void
    {
        // ‏كما يقرؤه الإنتاج: من ملفّ الضبط لا ممّا ضبطه هذا الاختبار
        $channel = (require config_path('logging.php'))['channels']['images'];

        // ‏وnotice لا warning: سطرُ كلّ جولة (`images.batch`) يُكتب — ما عولج وما بقي
        $this->assertSame(['daily', storage_path('logs/images.log'), 'notice'], [$channel['driver'], $channel['path'], $channel['level']]);
        $this->assertSame('error', config('logging.channels.system.level'), 'هذا الاختبار لا يُشبه سجلَّ الإنتاج');
    }

    public function test_a_log_that_cannot_be_written_never_stops_an_upload(): void
    {
        // ‏ملفُّ اليوم أنشأه غيرُ مستخدم التطبيق فلا يُكتب فيه — أو مجلّدُه لا يُفتح
        $blocked = (string) tempnam(sys_get_temp_dir(), 'not-a-dir');
        config(['logging.channels.images.path' => $blocked.'/images.log']);
        $this->available(120);
        $bytes = $this->jpeg(2000, 1500);

        $path = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $bytes)])[0];

        $this->assertSame(md5($bytes), md5((string) Storage::disk('public')->get($path)), 'الرفعُ سقط لأنّ السجلّ لا يُكتب');
        $this->assertSame('', (string) file_get_contents($this->systemLog));
        @unlink($blocked);
    }

    public function test_the_throttle_on_the_database_cache_keeps_an_open_transaction_usable(): void
    {
        // ‏الإنتاج يحفظ الذاكرةَ المؤقّتة في القاعدة — وسطرٌ فشل في PostgreSQL يُفسد معاملتَه كلَّها
        config(['cache.default' => 'database']);
        $this->available(120);

        DB::transaction(function () {
            foreach (range(1, 3) as $i) {
                ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $this->jpeg(900, 700))]);
            }

            $this->assertSame($this->shop->id, DB::table('businesses')->where('id', $this->shop->id)->value('id'), 'المعاملةُ فسدت');
        });

        $this->assertSame(['product_upload', 'variants'], array_map(fn ($l) => $l[1]['where'], $this->imageLines()));
        $this->assertSame(2, DB::table('cache')->where('key', 'like', '%images:note:%:low_memory')->count());
    }

    public function test_an_upload_when_memory_is_short_is_kept_as_it_came_and_still_succeeds(): void
    {
        $this->available(120);
        $bytes = $this->jpeg(2000, 1500);

        $path = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $bytes)])[0];

        $this->assertStringEndsWith('.jpg', $path, 'فُكّت الصورة والذاكرةُ لا تكفي');
        $this->assertSame(md5($bytes), md5((string) Storage::disk('public')->get($path)));
        Storage::disk('public')->assertMissing(ImageVariants::path($path, ImageVariants::THUMB));

        // ‏وصورُ الموقع كذلك: تُحفظ بأبعادها ولا يُردّ الرفع
        $saved = StoreImageUpload::store($this->shop->id, UploadedFile::fake()->createWithContent('hero.jpg', $this->jpeg(3200, 1600)));
        $this->assertSame([3200, 1600], [$saved['w'], $saved['h']]);

        // ‏وحين تعود الذاكرة يعود الضغطُ كما كان
        $this->available(2048);
        $this->assertStringEndsWith('.webp', ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $bytes)])[0]);
        $this->assertSame([2560, 1280], array_values(array_slice(StoreImageUpload::store($this->shop->id, UploadedFile::fake()->createWithContent('hero.jpg', $this->jpeg(3200, 1600))), 1)));
    }

    public function test_the_estimate_averages_each_copy_over_the_images_that_got_one(): void
    {
        $products = [$this->oldProduct(1600, 1200), $this->oldProduct(1400, 1050)];
        $id = $this->shop->id;
        $banners = [];

        foreach ([[1800, 900], [1600, 800]] as [$w, $h]) {
            $banners[] = $file = 'website/'.$id.'/'.Str::random(40).'.jpg';
            Storage::disk('public')->put($file, $this->jpeg($w, $h));
        }

        Setting::create(['business_id' => $id, 'key' => 'store_visual_bar', 'value' => json_encode(array_map(fn ($f) => ['image' => '/storage/'.$f], $banners))]);
        $card = 'website/'.$id.'/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($card, $this->jpeg(1200, 900));
        Category::create(['business_id' => $id, 'name' => 'ورد', 'card_image' => '/storage/'.$card]);

        $thumbs = array_map(fn ($p) => ImageVariants::make($p, false)['thumb'], $products);
        $this->assertNotContains(0, $thumbs);
        // ‏وصورُ الموقع لها مصغّرةٌ أيضًا — بطاقةُ القسم وبطاقةُ الشريط تُرسمان صغيرتين
        $web = array_map(fn ($p) => ImageVariants::make($p, false)['thumb'], $banners);
        $this->assertNotContains(0, $web, 'صورةُ الشريط بلا مصغّرة لبطاقتها');

        $this->artisan('images:optimize', ['--sample' => 6])
            ->expectsOutputToContain('صورة مقيسة')
            ->expectsOutputToContain('2 — '.round(array_sum($thumbs) / 2 / 1024).' KB')
            ->expectsOutputToContain('2 — '.round(array_sum($web) / 2 / 1024).' KB')
            ->doesntExpectOutputToContain('المصغّرةُ في القوائم')
            ->expectsOutputToContain('أقصى ذاكرةٍ مقيمةٍ للعمليّة')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles(), 'الجردُ كتب على القرص الخاصّ');
        $this->assertSame(0, DB::table('products')->where('image', 'like', '%.webp')->count());
    }
}
