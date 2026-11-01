<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كلُّ صورة منتجٍ تحمل نسخًا أخفّ — للتاجر الحاليّ والجديد، والأصلُ لا يُمسّ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الرفعُ الجديد يصنع مصغّرةً للقوائم بلا أيّ خطوة.
 *   - الصورةُ القديمة لا تُعالج إلّا بالأمر، دفعةً محدودة، ولا يُكتب فوق
 *     أصلها ولا يتغيّر صفُّها — والرجوعُ محوُ النسخ وحدها.
 *   - الجردُ لا يكتب شيئًا.
 *   - ما لا يحتاج نسخة (GIF، صغيرة) يُعلَّم فلا يأخذ مكانَ غيره في كلّ دفعة.
 *   - المتجرُ الواحد لا يمسّ غيرَه، والمحوُ يأخذ النسخ مع أصلها.
 *
 * والقاعدةُ في `ImageVariants` و`images:optimize`.
 */
class EveryProductPhotoGetsALighterCopyTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    /** صورةُ بضاعةٍ مصنوعة — تدرّجٌ وحبيبات، لا لونٌ واحدٌ يُضغط إلى لا شيء */
    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(11);

        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $n = mt_rand(-20, 20);
                $c = imagecolorallocate($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)),
                    max(0, min(255, (int) (160 * $y / $h) + 50 + $n)),
                    max(0, min(255, (int) (120 + 80 * sin(($x + $y) / 70)) + $n)));
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, $c);
            }
        }

        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    /** منتجٌ قديمٌ بصورةٍ رُفعت قبل الضغط — ملفٌّ على القرص وصفٌّ يشير إليه */
    private function oldProduct(string $file, ?Business $shop = null, int $w = 2400, int $h = 1800): Product
    {
        Storage::disk('public')->put($file, $this->jpeg($w, $h));

        return Product::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $file,
        ]);
    }

    /** @return array<string, string> كلُّ ملفٍّ على القرص وبصمتُه */
    private function disk(): array
    {
        $out = [];

        foreach (Storage::disk('public')->allFiles() as $f) {
            $out[$f] = md5((string) Storage::disk('public')->get($f));
        }

        ksort($out);

        return $out;
    }

    public function test_a_new_upload_gets_its_thumbnail_without_any_step(): void
    {
        $path = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $this->jpeg(3000, 2000))])[0];

        $thumb = ImageVariants::path($path, ImageVariants::THUMB);
        Storage::disk('public')->assertExists($thumb);
        $size = getimagesizefromstring((string) Storage::disk('public')->get($thumb));
        $this->assertSame([ImageVariants::THUMB, 427, IMAGETYPE_WEBP], [$size[0], $size[1], $size[2]]);

        // ‏والقائمةُ تقرأ المصغّرة، وصفحةُ الصنف الصورةَ بحجمها — لا نسخةَ عرضٍ لصورةٍ ضُغطت عند رفعها
        $this->assertSame(Storage::url($thumb), ImageVariants::url($path, ImageVariants::THUMB));
        Storage::disk('public')->assertMissing(ImageVariants::path($path, ImageVariants::DISPLAY));
        $this->assertSame(Storage::url($path), ImageVariants::url($path, ImageVariants::DISPLAY));
    }

    public function test_an_old_photo_waits_for_the_command_and_its_original_and_row_are_untouched(): void
    {
        $product = $this->oldProduct('products/old.jpg');
        $before = $this->disk();
        $row = DB::table('products')->where('id', $product->id)->value('image');

        // ‏الجردُ يقدّر ولا يكتب
        $this->artisan('images:optimize')->expectsOutputToContain('لم يُكتب شيء')->assertSuccessful();
        $this->assertSame($before, $this->disk(), 'الجردُ كتب على القرص');
        $this->assertSame(Storage::url('products/old.jpg'), $product->fresh()->image);

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        $after = $this->disk();
        $this->assertSame($before['products/old.jpg'], $after['products/old.jpg'], 'الأصلُ تغيّر');
        $this->assertSame($row, DB::table('products')->where('id', $product->id)->value('image'), 'الصفُّ تغيّر');

        $display = ImageVariants::path('products/old.jpg', ImageVariants::DISPLAY);
        $info = getimagesizefromstring((string) Storage::disk('public')->get($display));
        $this->assertSame([ImageVariants::DISPLAY, 1200, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
        $this->assertLessThan(Storage::disk('public')->size('products/old.jpg'), Storage::disk('public')->size($display));
        $this->assertSame(Storage::url($display), $product->fresh()->image, 'صفحةُ الصنف لا تقرأ النسخةَ الأخفّ');

        // ‏والرجوعُ محوُ النسخ وحدها
        ImageVariants::forget('products/old.jpg');
        $this->assertSame($before, $this->disk());
        $this->assertSame(Storage::url('products/old.jpg'), $product->fresh()->image);
    }

    public function test_the_backfill_goes_in_small_batches_and_never_repeats_itself(): void
    {
        $products = collect(['products/a.jpg', 'products/b.jpg', 'products/c.jpg'])->map(fn ($f) => $this->oldProduct($f, null, 1000, 800));
        ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $products[0]->id, 'path' => 'products/b.jpg', 'sort_order' => 1]);

        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('بقي 1 صورةً')->assertSuccessful();
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('بقي 0 صورةً')->assertSuccessful();

        $done = $this->disk();
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
        $this->assertSame($done, $this->disk(), 'جولةٌ ثالثة أعادت ما صُنع');
    }

    public function test_a_gif_or_a_small_photo_is_marked_once_and_left_as_it_is(): void
    {
        $gif = imagecreatetruecolor(900, 900);
        ob_start();
        imagegif($gif);
        Storage::disk('public')->put('products/moving.gif', (string) ob_get_clean());
        Product::create(['business_id' => $this->shop->id, 'name' => 'متحرّكة', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => 'products/moving.gif']);
        $small = ProductImages::putAll([UploadedFile::fake()->createWithContent('small.jpg', $this->jpeg(300, 300))])[0];
        Product::create(['business_id' => $this->shop->id, 'name' => 'صغيرة', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $small]);
        $gifBytes = md5((string) Storage::disk('public')->get('products/moving.gif'));

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        $this->assertSame($gifBytes, md5((string) Storage::disk('public')->get('products/moving.gif')));
        Storage::disk('public')->assertMissing(ImageVariants::path('products/moving.gif', ImageVariants::THUMB));
        $this->assertTrue(ImageVariants::handled('products/moving.gif'));
        $this->assertTrue(ImageVariants::handled($small));
        $this->assertSame(Storage::url('products/moving.gif'), ImageVariants::url('products/moving.gif', ImageVariants::THUMB));

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    public function test_one_shop_is_processed_without_touching_another(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->oldProduct('products/mine.jpg', null, 1200, 900);
        $this->oldProduct('products/theirs.jpg', $other, 1200, 900);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id])->assertSuccessful();

        Storage::disk('public')->assertExists(ImageVariants::path('products/mine.jpg', ImageVariants::THUMB));
        Storage::disk('public')->assertMissing(ImageVariants::path('products/theirs.jpg', ImageVariants::THUMB));
        Storage::disk('public')->assertMissing(ImageVariants::marker('products/theirs.jpg'));
    }

    public function test_removing_a_photo_takes_its_copies_with_it(): void
    {
        $product = $this->oldProduct('products/main.jpg', null, 1200, 900);
        Storage::disk('public')->put('products/side.jpg', $this->jpeg(1200, 900));
        $side = ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $product->id, 'path' => 'products/side.jpg', 'sort_order' => 1]);
        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();
        Storage::disk('public')->assertExists(ImageVariants::path('products/side.jpg', ImageVariants::THUMB));

        ProductImages::remove($side);

        $this->assertSame([], array_values(array_filter(array_keys($this->disk()), fn ($f) => str_starts_with($f, 'products/side.jpg'))));
        Storage::disk('public')->assertExists('products/main.jpg');
        Storage::disk('public')->assertExists(ImageVariants::path('products/main.jpg', ImageVariants::THUMB));
    }

    public function test_the_webp_switch_off_makes_no_copies(): void
    {
        config(['images.product_webp' => false]);
        $this->oldProduct('products/old.jpg');
        $before = $this->disk();

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        $this->assertSame($before, $this->disk());
    }

    public function test_the_hourly_backfill_is_off_until_it_is_opened(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'images:optimize'));

        $this->assertNotNull($event, 'لا جولةَ مجدولة');
        $this->assertStringContainsString('--apply', (string) $event->command);

        config(['images.backfill' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['images.backfill' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
