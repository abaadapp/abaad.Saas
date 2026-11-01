<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Support\ImageReferences;
use App\Support\ImageSwap;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use App\Support\Store\StoreImageUpload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * كلُّ صورةٍ في النظام تحمل نسخةً أخفّ — للتاجر الحاليّ والجديد، والأصلُ لا يُمسّ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الرفعُ الجديد يصنع مصغّرةً للقوائم بلا أيّ خطوة، والشعارُ يُصغَّر بنوعه وشفافيّته.
 *   - الصورةُ القديمة لا تُعالج إلّا بالأمر، دفعةً محدودة، ولا يُكتب فوق أصلها
 *     ولا يتغيّر صفُّها — والرجوعُ محوُ النسخ وحدها. والجردُ لا يكتب شيئًا.
 *   - لا تُعدّ صورةٌ ناجحةً وكتابةُ نسختها فشلت: يُمحى ما كُتب، وتُعاد.
 *   - صورُ الموقع والأقسام في الإعدادات تُجرد وتُنسخ، وتُنقل مراجعُها بسجلٍّ
 *     يُرجع به — ولا يُكتب فوق ما غيّره صاحبُه بعد النقل.
 *   - مرجعٌ في جدولٍ غير حيّ (طلبٌ قديم) يُبقي أصلَه، وخطّةُ الاستغناء لا تمحو.
 *   - المتجرُ الواحد لا يمسّ غيرَه.
 *
 * والقاعدةُ في `ImageVariants` و`ImageReferences` و`ImageSwap` و`images:optimize`.
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
        Storage::fake('local');
        config(['images.pause_ms' => 0]);

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

    /** اسمٌ كما يعطيه الرفع — أربعون حرفًا عشوائيًّا */
    private function fileName(string $ext = 'jpg'): string
    {
        return Str::random(40).'.'.$ext;
    }

    /** منتجٌ قديمٌ بصورةٍ رُفعت قبل الضغط — ملفٌّ على القرص وصفٌّ يشير إليه */
    private function oldProduct(?Business $shop = null, int $w = 2400, int $h = 1800): Product
    {
        $file = 'products/'.$this->fileName();
        Storage::disk('public')->put($file, $this->jpeg($w, $h));

        return Product::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $file,
        ]);
    }

    private function raw(Product $p): string
    {
        return (string) DB::table('products')->where('id', $p->id)->value('image');
    }

    /** @return array<string, string> كلُّ ملفٍّ على القرص العامّ وبصمتُه */
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
        $size = getimagesizefromstring((string) Storage::disk('public')->get($thumb));
        $this->assertSame([ImageVariants::THUMB, 427, IMAGETYPE_WEBP], [$size[0], $size[1], $size[2]]);

        // ‏والقائمةُ تقرأ المصغّرة، وصفحةُ الصنف الصورةَ بحجمها — لا نسخةَ عرضٍ لصورةٍ ضُغطت عند رفعها
        $this->assertSame(Storage::url($thumb), ImageVariants::url($path, ImageVariants::THUMB));
        Storage::disk('public')->assertMissing((string) ImageVariants::display($path));
        $this->assertSame(Storage::url($path), ImageVariants::url($path, ImageVariants::DISPLAY));
    }

    public function test_an_old_photo_waits_for_the_command_and_its_original_and_row_are_untouched(): void
    {
        $product = $this->oldProduct();
        $file = $this->raw($product);
        $before = $this->disk();

        $this->artisan('images:optimize')->expectsOutputToContain('لم يُكتب شيء')->assertSuccessful();
        $this->assertSame($before, $this->disk(), 'الجردُ كتب على القرص');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'الجردُ كتب على القرص الخاصّ');

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        $this->assertSame($before[$file], $this->disk()[$file], 'الأصلُ تغيّر');
        $this->assertSame($file, $this->raw($product), 'الصفُّ تغيّر');

        $display = (string) ImageVariants::display($file);
        $info = getimagesize(Storage::disk('public')->path($display));
        $this->assertSame([ImageVariants::SIDES['products'], 1200, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
        $this->assertLessThan(Storage::disk('public')->size($file), Storage::disk('public')->size($display));
        $this->assertSame(Storage::url($display), $product->fresh()->image, 'صفحةُ الصنف لا تقرأ النسخةَ الأخفّ');

        // ‏والرجوعُ محوُ النسخ وحدها
        ImageVariants::forget($file);
        $this->assertSame($before, $this->disk());
        $this->assertSame(Storage::url($file), $product->fresh()->image);
    }

    public function test_the_backfill_goes_in_small_batches_and_never_repeats_itself(): void
    {
        $products = collect(range(1, 3))->map(fn () => $this->oldProduct(null, 1000, 800));
        ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $products[0]->id, 'path' => $this->raw($products[1]), 'sort_order' => 1]);

        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('بقي 1 صورةً')->assertSuccessful();
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('بقي 0 صورةً')->assertSuccessful();

        $done = $this->disk();
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
        $this->assertSame($done, $this->disk(), 'جولةٌ ثالثة أعادت ما صُنع');
    }

    public function test_a_copy_that_could_not_be_written_is_not_counted_and_is_tried_again(): void
    {
        $product = $this->oldProduct(null, 1400, 1000);
        $file = $this->raw($product);
        $fake = Storage::disk('public');

        // ‏قرصٌ يقبل نسخةَ العرض ويرفض المصغّرة — كقرصٍ امتلأ في منتصف الصورة
        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                return str_ends_with($path, '.w640.webp') ? false : parent::put($path, $contents, $options);
            }
        });

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('و1 فشلت')->assertFailed();

        $this->assertSame([$file], Storage::disk('public')->allFiles('products'), 'بقيت نسخةُ عرضٍ من صورةٍ لم تكتمل');
        $this->assertSame(Storage::url($file), $product->fresh()->image);

        Storage::set('public', $fake);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();
        Storage::disk('public')->assertExists(ImageVariants::path($file, ImageVariants::THUMB));
        Storage::disk('public')->assertExists((string) ImageVariants::display($file));
    }

    public function test_a_gif_is_left_as_it_is_and_not_decoded_again_every_round(): void
    {
        $gif = imagecreatetruecolor(900, 900);
        ob_start();
        imagegif($gif);
        $file = 'products/'.$this->fileName('gif');
        Storage::disk('public')->put($file, (string) ob_get_clean());
        Product::create(['business_id' => $this->shop->id, 'name' => 'متحرّكة', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $file]);
        $small = ProductImages::putAll([UploadedFile::fake()->createWithContent('small.jpg', $this->jpeg(300, 300))])[0];
        Product::create(['business_id' => $this->shop->id, 'name' => 'صغيرة', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $small]);
        $bytes = md5((string) Storage::disk('public')->get($file));

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('skipped=1')->assertSuccessful();

        $this->assertSame($bytes, md5((string) Storage::disk('public')->get($file)));
        $this->assertSame([$file], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $file))));
        $this->assertSame(Storage::url($file), ImageVariants::url($file, ImageVariants::THUMB));

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    public function test_website_and_category_pictures_get_a_copy_and_their_references_move_with_a_way_back(): void
    {
        $id = $this->shop->id;
        $hero = 'website/'.$id.'/'.$this->fileName();
        $card = 'website/'.$id.'/'.$this->fileName();
        Storage::disk('public')->put($hero, $this->jpeg(2000, 1000));
        Storage::disk('public')->put($card, $this->jpeg(1200, 900));

        // ‏رابطٌ بشرطةٍ مهرَّبة في JSON، وآخرُ عاديّ، وبطاقةُ قسم
        $bar = Setting::create(['business_id' => $id, 'key' => 'store_visual_bar', 'value' => json_encode([['image' => '/storage/'.$hero, 'title' => 'ورد']])]);
        $hero2 = Setting::create(['business_id' => $id, 'key' => 'store_hero_image', 'value' => '/storage/'.$hero]);
        $cat = Category::create(['business_id' => $id, 'name' => 'ورد', 'card_image' => '/storage/'.$card]);
        $product = $this->oldProduct(null, 1400, 1000);
        $was = [$bar->fresh()->value, $hero2->fresh()->value, $cat->fresh()->card_image, $this->raw($product)];

        $this->artisan('images:optimize')->expectsOutputToContain('صور الموقع والمحرّر والشريط')->expectsOutputToContain('صور الأقسام')->assertSuccessful();
        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        // ‏صورُ الموقع نسخةُ عرضٍ وحدها — لا قائمةَ تقرأ مصغّرتها
        Storage::disk('public')->assertExists((string) ImageVariants::display($hero));
        Storage::disk('public')->assertMissing(ImageVariants::path($hero, ImageVariants::THUMB));
        $this->assertSame($was, [$bar->fresh()->value, $hero2->fresh()->value, $cat->fresh()->card_image, $this->raw($product)], 'النسخُ غيّرت صفًّا');

        $this->artisan('images:optimize', ['--swap' => true, '--business' => $id])->expectsOutputToContain('نُقلت مراجعُ 3 صورة')->assertSuccessful();

        $heroCopy = basename((string) ImageVariants::display($hero));
        $this->assertStringContainsString($heroCopy, (string) $bar->fresh()->value);
        $this->assertSame('/storage/'.ImageVariants::display($hero), $hero2->fresh()->value);
        $this->assertSame('/storage/'.ImageVariants::display($card), $cat->fresh()->card_image);
        $this->assertSame(ImageVariants::display($was[3]), $this->raw($product));
        $this->assertSame(Storage::url((string) ImageVariants::display($was[3])), $product->fresh()->image);
        Storage::disk('public')->assertExists($hero);

        // ‏ولا أصلَ يشير إليه شيءٌ بعد النقل — والخطّةُ تعدّه ولا تمحوه
        $this->assertArrayNotHasKey($hero, ImageReferences::scan());
        $this->artisan('images:optimize', ['--retire-plan' => true])->expectsOutputToContain('لم يُمحَ شيء')->assertSuccessful();
        Storage::disk('public')->assertExists($hero);

        // ‏والرجوعُ يُعيد كلَّ صفٍّ كما كان — إلّا ما غيّره صاحبُه بعد النقل
        $hero2->update(['value' => '/storage/website/'.$id.'/'.$this->fileName()]);
        $run = basename(Storage::disk('local')->files('image-swaps')[0], '.json');
        $this->artisan('images:optimize', ['--rollback' => $run])->expectsOutputToContain('تُرك (تغيّر بعد النقل): settings#'.$hero2->id)->assertFailed();

        $this->assertSame([$was[0], $was[2], $was[3]], [$bar->fresh()->value, $cat->fresh()->card_image, $this->raw($product)]);
        $this->assertNotSame($was[1], $hero2->fresh()->value, 'الرجوعُ كتب فوق ما غيّره صاحبُه');
    }

    public function test_a_picture_an_old_order_still_points_to_keeps_its_original(): void
    {
        Schema::create('old_order_snapshots', function (Blueprint $t) {
            $t->id();
            $t->text('lines');
        });
        $product = $this->oldProduct(null, 1400, 1000);
        $file = $this->raw($product);
        DB::table('old_order_snapshots')->insert(['lines' => json_encode([['image' => '/storage/'.$file]])]);

        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();
        $this->artisan('images:optimize', ['--swap' => true, '--business' => $this->shop->id])->assertSuccessful();

        $this->assertSame(['old_order_snapshots.lines' => 1], ImageReferences::scan()[$file], 'الطلبُ القديم لم يُقرأ مرجعًا');
        $this->artisan('images:optimize', ['--retire-plan' => true])
            ->expectsOutputToContain('أصولٌ نُقلت ويبقيها طلبٌ أو سجلٌّ قديم')->assertSuccessful();
        Storage::disk('public')->assertExists($file);
        $this->assertSame(json_encode([['image' => '/storage/'.$file]]), DB::table('old_order_snapshots')->value('lines'), 'النقلُ كتب في طلبٍ قديم');
    }

    /**
     * متجرٌ بصورة منتجٍ وصورةِ موقعٍ وبطاقةِ قسم، ونسخُها مصنوعة — جاهزٌ للنقل.
     *
     * @return array{0: list<string>, 1: array<string, array{0: string, 1: int, 2: string}>}
     */
    private function readyToSwap(): array
    {
        $id = $this->shop->id;
        $hero = 'website/'.$id.'/'.$this->fileName();
        $card = 'website/'.$id.'/'.$this->fileName();
        Storage::disk('public')->put($hero, $this->jpeg(1600, 800));
        Storage::disk('public')->put($card, $this->jpeg(1200, 900));
        $bar = Setting::create(['business_id' => $id, 'key' => 'store_visual_bar', 'value' => json_encode([['image' => '/storage/'.$hero], ['image' => '/storage/'.$card]])]);
        $cat = Category::create(['business_id' => $id, 'name' => 'ورد', 'card_image' => '/storage/'.$card]);
        $product = $this->oldProduct(null, 1400, 1000);
        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();

        return [[$hero, $card, $this->raw($product)], [
            'bar' => ['settings', $bar->id, (string) $bar->fresh()->value],
            'cat' => ['categories', $cat->id, (string) $cat->fresh()->card_image],
            'product' => ['products', $product->id, $this->raw($product)],
        ]];
    }

    /** @param  array<string, array{0: string, 1: int, 2: string}>  $rows */
    private function assertRowsAre(array $rows, string $why): void
    {
        $col = ['settings' => 'value', 'categories' => 'card_image', 'products' => 'image'];

        foreach ($rows as $name => [$table, $id, $value]) {
            $this->assertSame($value, (string) DB::table($table)->where('id', $id)->value($col[$table]), $why.' — '.$name);
        }
    }

    public function test_a_journal_that_cannot_be_written_stops_the_swap_before_any_row_changes(): void
    {
        [, $rows] = $this->readyToSwap();

        // ‏قرصٌ لا يُنشأ عليه مجلّدُ السجلّات — كقرصٍ ممتلئ أو بلا إذن
        Storage::disk('local')->put('image-swaps', 'not a folder');

        $this->artisan('images:optimize', ['--swap' => true, '--business' => $this->shop->id])
            ->expectsOutputToContain('تعذّرت كتابة سجلّ النقل — لم يُمسّ صفّ')->assertFailed();

        $this->assertRowsAre($rows, 'تغيّر صفٌّ وسجلُّه لم يُكتب');
    }

    public function test_a_server_that_stops_after_the_journal_and_before_the_rows_changes_nothing(): void
    {
        [$images, $rows] = $this->readyToSwap();
        $plan = ImageSwap::plan($this->shop->id, $images);
        ImageSwap::journal('crash-before', $this->shop->id, $plan['entries']);

        // ‏انقطع الخادمُ هنا: السجلُّ معلّق، والصفوفُ كما كانت
        $this->assertRowsAre($rows, 'تغيّر صفٌّ قبل التطبيق');
        $this->assertSame('crash-before', ImageSwap::pendingFor($this->shop->id));

        $this->artisan('images:optimize', ['--recover' => true])->expectsOutputToContain('crash-before: aborted')->assertSuccessful();
        $this->assertSame(ImageSwap::ABORTED, ImageSwap::read('crash-before')['state']);
        $this->assertSame(['restored' => 0, 'conflicts' => []], ImageSwap::rollback('crash-before'));
        $this->assertRowsAre($rows, 'الرجوعُ عن نقلٍ لم يقع غيّر صفًّا');
    }

    public function test_a_server_that_stops_after_the_rows_and_before_the_seal_is_recovered_and_rolled_back(): void
    {
        [$images, $rows] = $this->readyToSwap();
        $plan = ImageSwap::plan($this->shop->id, $images);
        ImageSwap::journal('crash-after', $this->shop->id, $plan['entries']);
        ImageSwap::apply($this->shop->id, $plan['entries']);

        // ‏انقطع الخادمُ هنا: الصفوفُ تغيّرت، والسجلُّ معلّقٌ لم يُختم
        $this->assertNotSame($rows['product'][2], $this->raw(Product::find($rows['product'][1])));
        $this->artisan('images:optimize', ['--swap' => true, '--business' => $this->shop->id])->assertSuccessful();
        $this->assertSame(ImageSwap::DONE, ImageSwap::read('crash-after')['state'], 'النقلُ التالي لم يقرأ ما انقطع قبله');

        $this->assertSame(3, ImageSwap::rollback('crash-after')['restored']);
        $this->assertRowsAre($rows, 'الرجوعُ لم يُعد الصفوف');
    }

    public function test_a_seal_that_fails_leaves_a_journal_that_recovers(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('مديرُ النظام يكتب في مجلّدٍ للقراءة فقط');
        }

        [$images, $rows] = $this->readyToSwap();
        $plan = ImageSwap::plan($this->shop->id, $images);
        ImageSwap::journal('seal-fails', $this->shop->id, $plan['entries']);
        $dir = Storage::disk('local')->path('image-swaps');
        chmod($dir, 0555);

        try {
            ImageSwap::apply($this->shop->id, $plan['entries']);

            try {
                ImageSwap::seal('seal-fails', ImageSwap::DONE);
                $this->fail('خُتم السجلّ في مجلّدٍ لا يُكتب فيه');
            } catch (\RuntimeException) {
                // ‏هذا المقصود: الختمُ تعذّر بعد أن تغيّرت الصفوف
            }

            $this->assertSame(ImageSwap::PENDING, ImageSwap::read('seal-fails')['state'], 'السجلُّ ضاع أو انقطع نصفُه');
        } finally {
            chmod($dir, 0755);
        }

        $this->artisan('images:optimize', ['--recover' => true])->expectsOutputToContain('seal-fails: committed')->assertSuccessful();
        ImageSwap::rollback('seal-fails');
        $this->assertRowsAre($rows, 'الرجوعُ بعد ختمٍ فاشل لم يُعد الصفوف');
    }

    public function test_a_row_changed_after_the_plan_aborts_the_whole_swap(): void
    {
        [$images, $rows] = $this->readyToSwap();
        $plan = ImageSwap::plan($this->shop->id, $images);
        ImageSwap::journal('stale', $this->shop->id, $plan['entries']);

        // ‏غيّر التاجرُ صورةَ منتجه بين الخطّة والتطبيق — وهي آخرُ ما يُطبَّق
        $last = end($plan['entries']);
        DB::table($last['table'])->where('id', $last['id'])->update([$last['column'] => $last['before'].' ']);
        $changed = (string) DB::table($last['table'])->where('id', $last['id'])->value($last['column']);

        try {
            ImageSwap::apply($this->shop->id, $plan['entries']);
            $this->fail('طُبّق نقلٌ على صفٍّ تغيّر بعد خطّته');
        } catch (\RuntimeException) {
            // ‏المقصود: لا نصفَ نقل
        }

        $name = collect($rows)->search(fn ($r) => $r[0] === $last['table'] && $r[1] === $last['id']);
        $rows[$name][2] = $changed;
        $this->assertRowsAre($rows, 'بقي نصفُ نقلٍ بعد أن رُجع عنه');
    }

    public function test_two_pictures_in_one_row_are_one_entry_and_come_back_together(): void
    {
        [$images, $rows] = $this->readyToSwap();

        $this->artisan('images:optimize', ['--swap' => true, '--business' => $this->shop->id])->assertSuccessful();

        $run = basename(Storage::disk('local')->files('image-swaps')[0], '.json');
        $entries = collect(ImageSwap::read($run)['entries']);
        $this->assertCount(1, $entries->where('table', 'settings'), 'صفُّ الشريط سُجّل مرّتين');
        $bar = (string) DB::table('settings')->where('id', $rows['bar'][1])->value('value');
        $this->assertStringContainsString(basename((string) ImageVariants::display($images[0])), $bar);
        $this->assertStringContainsString(basename((string) ImageVariants::display($images[1])), $bar);

        ImageSwap::rollback($run);
        $this->assertRowsAre($rows, 'الرجوعُ لم يُعد الصفَّ ذا الصورتين');
    }

    public function test_the_reference_scan_reads_a_big_table_in_bounded_batches(): void
    {
        config(['images.scan_batch' => 100, 'images.scan_pause_ms' => 0]);
        Schema::create('big_log', function (Blueprint $t) {
            $t->id();
            $t->text('body')->nullable();
            $t->json('meta')->nullable();
        });
        $file = 'products/'.$this->fileName();
        $rows = [];

        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = ['body' => $i % 250 === 0 ? 'صورة /storage/'.$file : 'سطرٌ عاديّ رقم '.$i, 'meta' => $i === 7 ? json_encode(['img' => '/storage/'.$file]) : null];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('big_log')->insert($chunk);
        }

        $found = ImageReferences::scan();

        $where = $found[$file];
        ksort($where);
        $this->assertSame(['big_log.body' => 4, 'big_log.meta' => 1], $where);
        $s = ImageReferences::$stats;
        $this->assertGreaterThanOrEqual(10, $s['queries'], 'جدولُ الألف قُرئ في سؤالٍ واحد');
        $this->assertArrayHasKey('cpu_ms', $s);

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('0', (string) DB::selectOne('SHOW statement_timeout')->statement_timeout, 'بقي سقفُ زمن السؤال بعد المسح');
        }
    }

    public function test_one_shop_is_processed_and_swapped_without_touching_another(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $mine = $this->oldProduct(null, 1200, 900);
        $theirs = $this->oldProduct($other, 1200, 900);
        $theirFile = $this->raw($theirs);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id])->assertSuccessful();
        $this->artisan('images:optimize', ['--swap' => true, '--business' => $this->shop->id])->assertSuccessful();

        Storage::disk('public')->assertExists(ImageVariants::path($this->raw($mine), ImageVariants::THUMB));
        $this->assertSame($theirFile, $this->raw($theirs));
        $this->assertSame([$theirFile], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $theirFile))));
    }

    public function test_removing_a_photo_takes_its_copies_with_it(): void
    {
        $product = $this->oldProduct(null, 1200, 900);
        $side = 'products/'.$this->fileName();
        Storage::disk('public')->put($side, $this->jpeg(1200, 900));
        $row = ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $product->id, 'path' => $side, 'sort_order' => 1]);
        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();
        Storage::disk('public')->assertExists(ImageVariants::path($side, ImageVariants::THUMB));

        ProductImages::remove($row);

        $this->assertSame([], array_values(array_filter(array_keys($this->disk()), fn ($f) => str_starts_with($f, $side))));
        Storage::disk('public')->assertExists(ImageVariants::path($this->raw($product), ImageVariants::THUMB));
    }

    public function test_a_logo_is_shrunk_in_its_own_format_and_keeps_its_transparency(): void
    {
        $im = imagecreatetruecolor(3000, 1500);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 1500, 750, 1400, 1400, imagecolorallocate($im, 200, 30, 60));
        ob_start();
        imagepng($im);

        $path = StoreImageUpload::storeShrunk(UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean()), 'logos', 1024);

        $this->assertStringStartsWith('logos/', $path);
        $this->assertStringEndsWith('.png', $path);
        $info = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame([1024, 512, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        $out = imagecreatefrompng(Storage::disk('public')->path($path));
        $this->assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F, 'ضاعت شفافيّةُ الشعار');
    }

    public function test_the_webp_switch_off_makes_no_copies(): void
    {
        config(['images.product_webp' => false]);
        $this->oldProduct();
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
        $this->assertStringNotContainsString('--swap', (string) $event->command, 'الجولةُ المجدولة تنقل المراجع');

        config(['images.backfill' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['images.backfill' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
