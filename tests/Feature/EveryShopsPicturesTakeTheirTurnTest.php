<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageGate;
use App\Support\ImageState;
use App\Support\ImageVariants;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صورُ كلّ المتاجر القديمة والجديدة — بالدور، وبلا أن يمسّ متجرٌ متجرًا، وبمفتاح إيقاف.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * الجولةُ العامّة كانت تمشي بترتيب الجداول: أكبرُ المتاجر صورًا يأخذ كلَّ دفعة،
 * ومتجرٌ صغيرٌ أو جديدٌ ينتظر حتى ينتهي. وصورةٌ رُفعت والذاكرةُ ناقصة بقيت بلا نسخٍ
 * إلى أن تُفتح الجولةُ الكبرى. ولا مفتاحَ يوقف المعالجةَ كلَّها بلا تعديل البيئة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - دورٌ بين المتاجر: لا يأخذ متجرٌ ثانيتَه قبل أن يأخذ كلُّ متجرٍ ينتظر أولاه،
 *     ويبدأ الدورُ التالي بعد آخر متجرٍ عولج، والمتجرُ الجديدُ يدخل الدورَ بلا إعداد.
 *   - متجرٌ بلا صور، وصورةٌ مُحي أصلُها، وصورةٌ مشتركةٌ بين متجرين (تُصنع مرّة).
 *   - `--business` لا يمسّ غيرَ متجره.
 *   - `--halt` يوقف الجولات والرفع (يُحفظ الأصلُ وتنتظر نسخُه)، و`--resume` يعيدها.
 *   - جولتان لا تعملان معًا، وكتابةٌ تفشل تُعاد ثلاثًا ثمّ تُترك حتى تُطلب.
 *   - نوعٌ لا يُفكّ يُترك مرّةً بسببه، والأصولُ والمراجعُ في القاعدة لا تتغيّر.
 */
class EveryShopsPicturesTakeTheirTurnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0]);
    }

    private function shop(string $name): Business
    {
        return Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
    }

    /** صورةُ منتجٍ قديمة (JPEG بأبعاد الهاتف) لهذا المتجر — أو المسارُ نفسُه إن سُمّي */
    private function picture(Business $shop, ?string $path = null, string $type = 'jpeg'): string
    {
        if ($path === null) {
            $path = 'products/'.Str::random(40).($type === 'gif' ? '.gif' : '.jpg');
            $im = imagecreatetruecolor(1700, 1000);
            mt_srand(crc32($path));

            for ($y = 0; $y < 1000; $y += 2) {
                imagefilledrectangle($im, 0, $y, 1700, $y + 1, imagecolorallocate($im, (int) (80 + 140 * $y / 1000), 110, (int) (210 - 100 * $y / 1000)));
            }

            for ($i = 0; $i < 30; $i++) {
                imagefilledellipse($im, mt_rand(0, 1700), mt_rand(0, 1000), $r = mt_rand(30, 260), $r, imagecolorallocate($im, mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230)));
            }

            ob_start();
            $type === 'gif' ? imagegif($im) : imagejpeg($im, null, 92);
            Storage::disk('public')->put($path, (string) ob_get_clean());
        }

        Product::create(['business_id' => $shop->id, 'name' => 'باقة '.Str::random(4), 'price' => 5, 'cost' => 1, 'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $path]);

        return $path;
    }

    /** @param  list<string>  $paths */
    private function done(array $paths): int
    {
        return count(array_filter($paths, fn ($p) => Storage::disk('public')->exists(ImageVariants::path($p, ImageVariants::THUMB))));
    }

    /** بصمةُ كلّ أصلٍ وكلّ مرجعٍ حيٍّ في القاعدة */
    private function fingerprint(): string
    {
        $disk = Storage::disk('public');
        $originals = array_values(array_filter($disk->allFiles(), fn ($f) => ! ImageVariants::isVariant($f)));
        sort($originals);

        return md5(json_encode([
            array_map(fn ($f) => [$f, md5((string) $disk->get($f))], $originals),
            DB::table('products')->orderBy('id')->pluck('image'),
            DB::table('categories')->orderBy('id')->pluck('card_image'),
        ]));
    }

    public function test_each_shop_gets_a_turn_before_any_shop_gets_a_second(): void
    {
        [$a, $b, $c] = [$this->shop('أ'), $this->shop('ب'), $this->shop('ج')];
        $pa = array_map(fn () => $this->picture($a), range(1, 5));
        $pb = array_map(fn () => $this->picture($b), range(1, 2));
        $pc = [$this->picture($c)];

        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 3])->expectsOutputToContain('made=3')->assertSuccessful();
        $this->assertSame([1, 1, 1], [$this->done($pa), $this->done($pb), $this->done($pc)], 'أخذ متجرٌ ثانيتَه قبل أن يأخذ غيرُه أولاه');

        // ‏والدورُ الثاني يبدأ بعد آخر متجرٍ عولج (ج): أ ثمّ ب، ثمّ أ — لا شيءَ بقي لـ ج
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 3])->assertSuccessful();
        $this->assertSame([3, 2, 1], [$this->done($pa), $this->done($pb), $this->done($pc)]);
    }

    public function test_a_large_shop_does_not_hold_back_a_small_one_or_a_new_one(): void
    {
        $big = $this->shop('كبير');
        $many = array_map(fn () => $this->picture($big), range(1, 6));
        $small = $this->shop('صغير');
        $one = $this->picture($small);

        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->assertSuccessful();
        $this->assertSame([1, 1], [$this->done($many), $this->done([$one])], 'انتظر المتجرُ الصغير الكبير');

        // ‏ومتجرٌ يُنشأ بعد الجولة يدخل الدورَ في الجولة التالية — بلا إعداد
        $new = $this->shop('جديد');
        $fresh = $this->picture($new);
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 2])->assertSuccessful();
        $this->assertSame(1, $this->done([$fresh]), 'المتجرُ الجديد لم يأخذ دوره');
    }

    public function test_an_empty_shop_a_missing_file_and_a_shared_picture_do_not_break_the_turn(): void
    {
        $this->shop('بلا صور');
        $a = $this->shop('أ');
        $b = $this->shop('ب');
        $shared = $this->picture($a);
        $this->picture($b, $shared);
        $this->picture($b, 'products/'.Str::random(40).'.jpg');

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 1 صورة: made=1')->assertSuccessful();
        Storage::disk('public')->assertExists(ImageVariants::path($shared, ImageVariants::MID));
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    public function test_one_shop_mode_touches_no_other_shop(): void
    {
        $a = $this->shop('أ');
        $b = $this->shop('ب');
        $mine = $this->picture($a);
        $theirs = $this->picture($b);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $a->id])->assertSuccessful();

        $this->assertSame([1, 0], [$this->done([$mine]), $this->done([$theirs])]);
        $this->assertSame([$theirs], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $theirs))));
    }

    public function test_halt_stops_every_batch_and_upload_until_resumed(): void
    {
        $shop = $this->shop('أ');
        $old = $this->picture($shop);

        $this->artisan('images:optimize', ['--halt' => true])->assertSuccessful();
        $this->assertNotNull(ImageGate::halted());

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('halted')->assertSuccessful();
        $this->assertSame(0, $this->done([$old]), 'عولجت صورةٌ والمعالجةُ موقوفة');

        // ‏والرفعُ ينجح ويحفظ الأصل — ونسخُه تنتظر، مرّةً واحدة
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o'.Str::random(5).'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $category = Category::create(['business_id' => $shop->id, 'name' => 'باقات']);
        $url = $this->actingAs($owner)
            ->post(route('admin.products.categories.cardImage', $category->id), ['image' => UploadedFile::fake()->image('roses.jpg', 2000, 1500)], ['Accept' => 'application/json'])
            ->assertOk()->json('category.card_image');
        $uploaded = substr($url, strlen('/storage/'));
        Storage::disk('public')->assertExists($uploaded);
        Storage::disk('public')->assertMissing(ImageVariants::path($uploaded, ImageVariants::THUMB));
        $this->assertSame([$uploaded], array_keys(ImageState::waiting()));
        $this->artisan('images:optimize', ['--backlog' => true])->expectsOutputToContain('halted')->assertSuccessful();

        $this->artisan('images:optimize', ['--resume' => true])->assertSuccessful();
        $this->assertNull(ImageGate::halted());
        $this->artisan('images:optimize', ['--backlog' => true])->expectsOutputToContain('made=1')->assertSuccessful();
        Storage::disk('public')->assertExists(ImageVariants::path($uploaded, ImageVariants::MID));
        $this->assertSame([], ImageState::waiting());
        $this->artisan('images:optimize', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, $this->done([$old]));
    }

    public function test_two_batches_never_run_together(): void
    {
        $this->picture($this->shop('أ'));
        $lock = Cache::lock('images:optimize', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('جولةٌ أخرى تعمل الآن')->assertFailed();
            $this->artisan('images:optimize', ['--backlog' => true])->expectsOutputToContain('جولةٌ أخرى تعمل الآن')->assertFailed();
        } finally {
            $lock->release();
        }
    }

    public function test_a_write_that_fails_is_tried_three_times_then_left_until_asked(): void
    {
        $path = $this->picture($this->shop('أ'));
        $fake = Storage::disk('public');
        $original = md5((string) $fake->get($path));

        // ‏قرصٌ يرفض كتابةَ النسخ (ممتلئٌ أو بلا إذن) — والأصلُ يُقرأ كما هو
        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                return str_starts_with(basename($path), '.') ? false : parent::put($path, $contents, $options);
            }
        });

        foreach ([1, 2, 3] as $attempt) {
            $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('failed=1')->assertFailed();
            $this->assertSame($attempt, ImageState::all()[$path]['attempts']);
        }

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
        $this->artisan('images:optimize', ['--apply' => true, '--retry-failed' => true])->expectsOutputToContain('failed=1')->assertFailed();

        $this->assertSame([$path], Storage::disk('public')->allFiles('products'), 'بقيت نسخةٌ ناقصة أو ملفٌّ مؤقّت');
        $this->assertSame($original, md5((string) Storage::disk('public')->get($path)));
    }

    public function test_an_unsupported_picture_is_left_once_with_its_reason(): void
    {
        $gif = $this->picture($this->shop('أ'), type: 'gif');

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('skipped=1')->assertSuccessful();
        $this->assertSame(['skipped', 'type'], [ImageState::all()[$gif]['status'], ImageState::all()[$gif]['reason']]);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
        $this->assertSame([$gif], Storage::disk('public')->allFiles('products'));
    }

    public function test_a_whole_run_changes_no_original_and_no_reference(): void
    {
        foreach (['أ', 'ب', 'ج'] as $name) {
            $shop = $this->shop($name);
            $this->picture($shop);
            $this->picture($shop);
        }

        $before = $this->fingerprint();
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=6')->assertSuccessful();

        $this->assertSame($before, $this->fingerprint(), 'تغيّر أصلٌ أو مرجع');
        $this->assertSame(18, count(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => ImageVariants::isVariant($f))), 'ستُّ صورٍ بثلاث نسخٍ لكلٍّ');
    }
}
