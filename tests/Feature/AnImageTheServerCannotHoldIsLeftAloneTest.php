<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ImageMemory;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use App\Support\Store\StoreImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        $this->meminfo = (string) tempnam(sys_get_temp_dir(), 'meminfo');
        config(['images.pause_ms' => 0, 'images.meminfo' => $this->meminfo, 'images.reserve_mb' => 200]);
        $this->available(4096);

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    protected function tearDown(): void
    {
        @unlink($this->meminfo);
        parent::tearDown();
    }

    /** ما يقوله النظامُ عن ذاكرته المتاحة — بالميغابايت */
    private function available(int $mb): void
    {
        file_put_contents($this->meminfo, "MemTotal:        984064 kB\nMemFree:          160768 kB\nMemAvailable:   ".($mb * 1024)." kB\n");
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
        Log::spy();

        $this->artisan('images:optimize', ['--apply' => true])
            ->expectsOutputToContain('وقفت الدفعة')->expectsOutputToContain('deferred=1')->assertSuccessful();

        $this->assertCount(2, Storage::disk('public')->allFiles('products'), 'كُتبت نسخةٌ والذاكرةُ لا تكفي');
        $this->assertSame([], json_decode((string) Storage::disk('local')->get('image-variants/state.json'), true), 'المؤجَّلُ عُدّ فاشلًا أو متروكًا');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'images.low_memory' && $c['path'] === $file && $c['available_mb'] === 150);

        $this->available(2048);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=2')->assertSuccessful();
        Storage::disk('public')->assertExists((string) ImageVariants::display($file));
    }

    public function test_an_image_above_the_pixel_cap_is_left_once_and_its_reason_logged(): void
    {
        config(['images.max_megapixels' => 2]);
        $big = $this->oldProduct(2400, 1800);
        $small = $this->oldProduct(1400, 1000);
        Log::spy();

        $this->artisan('images:optimize')->expectsOutputToContain('فوق سقف 2 ميغابكسل: 1 صورة')
            ->expectsOutputToContain('لم تُقس 1 من العيّنة: too_large')->assertSuccessful();
        // ‏سطرٌ واحدٌ يُطابق توقّعًا واحدًا — فالحالان في توقّعٍ واحد
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('skipped=1، made=1')->assertSuccessful();

        $this->assertSame([$big], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $big))));
        Storage::disk('public')->assertExists((string) ImageVariants::display($small));
        $this->assertSame('too_large', json_decode((string) Storage::disk('local')->get('image-variants/state.json'), true)[$big]['reason']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'images.too_large' && $c['path'] === $big && $c['megapixels'] === 4.3);

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
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
        $this->assertSame(0, ImageVariants::make($banners[0], false)['thumb'], 'صورةُ الشريط صُنعت لها مصغّرة');

        $this->artisan('images:optimize', ['--sample' => 6])
            ->expectsOutputToContain('صورة مقيسة')
            ->expectsOutputToContain('2 — '.round(array_sum($thumbs) / 2 / 1024).' KB')
            ->expectsOutputToContain('0 — —')
            ->doesntExpectOutputToContain('المصغّرةُ في القوائم')
            ->expectsOutputToContain('أقصى ذاكرةٍ مقيمةٍ للعمليّة')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles(), 'الجردُ كتب على القرص الخاصّ');
        $this->assertSame(0, DB::table('products')->where('image', 'like', '%.webp')->count());
    }
}
