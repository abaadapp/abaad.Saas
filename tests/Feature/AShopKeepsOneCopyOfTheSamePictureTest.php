<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ImageFingerprints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * الصورةُ نفسُها تُحفظ مرّةً واحدةً في المتجر — ولا تُعرف إلّا إن كانت هي هي.
 *
 * ═══ ما يُحرس ═══
 *
 *   · صورةٌ رُفعت مرّتين: ملفٌّ واحد، والمنتجان يعرضانه، ويُقال للتاجر أين وُجدت.
 *   · الصورةُ نفسُها بصيغةٍ أخرى (بكسلاتٌ واحدةٌ وبايتاتٌ مختلفة): تُعرف أيضًا.
 *   · صورةٌ تشبهها (بكسلٌ واحدٌ مختلف)، أو اسمٌ واحدٌ ومحتوى آخر: صورتان.
 *   · متجران: لا يُطابَق متجرٌ بصور غيره، ولا يُقال له عنها شيء.
 *   · حذفُ صورةٍ يعرضها منتجان من أحدهما يُبقي الملفَّ للآخر — ومن الأخير يمحوه.
 *   · صورةٌ في المنتج نفسه لا تُضاف إليه ثانيةً.
 *   · بصمةُ الأصل من المتصفّح تلميحٌ لا يُعتمد وحده.
 *   · فهرسٌ تالفٌ أو مقفل، وطلبٌ يسقط بعد الحفظ: لا رفعَ يسقط، ولا ملفَّ غيرِه يُمحى.
 */
class AShopKeepsOneCopyOfTheSamePictureTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');

        $this->openRibbon();

        foreach ((array) config('storefront') as $key => $value) {
            if (str_starts_with((string) $key, 'ribbon_') && is_array($value)) {
                config(['storefront.'.$key => []]);
            }
        }
    }

    /**
     * صورةٌ يرسمها GD من بذرةٍ — البذرةُ نفسُها تعطي البكسلاتِ نفسَها.
     *
     * و`$level` يغيّر بايتات PNG لا بكسلاته، و`$touch` يغيّر البكسلات.
     */
    private function picture(string $name, int $seed = 1, string $as = 'png', int $level = 6, ?\Closure $touch = null, int $w = 420, int $h = 300): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        mt_srand($seed);

        for ($i = 0; $i < 80; $i++) {
            $c = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($image, mt_rand(0, $w), mt_rand(0, $h), mt_rand(10, 200), mt_rand(10, 200), $c);
        }

        if ($touch !== null) {
            $touch($image);
        }

        $file = tempnam(sys_get_temp_dir(), 'fp-').'.'.$as;

        match ($as) {
            'png' => imagepng($image, $file, $level),
            'jpg' => imagejpeg($image, $file, $level),
            'webp' => imagewebp($image, $file, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : 100),
        };

        return new UploadedFile($file, $name, match ($as) {
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'
        }, null, true);
    }

    private function product(string $name, ?Business $in = null): Product
    {
        return Product::create([
            'business_id' => ($in ?? $this->shop)->id, 'name' => $name, 'price' => 10, 'cost' => 4,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function upload(Product $to, array $files, array $originals = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('admin.products.images.store', $to->id), array_filter(['images' => $files, 'originals' => $originals]));
    }

    /** ما على القرص من أصول صور المنتجات — بلا النسخ الأخفّ */
    private function stored(): array
    {
        return array_values(array_filter(
            Storage::disk('public')->files('products'),
            fn ($p) => preg_match('/\.w\d+\.webp$/', $p) !== 1 && ! str_starts_with(basename($p), '.'),
        ));
    }

    private function toast(): string
    {
        return (string) (session('toast')['msg'] ?? '');
    }

    private function mainOf(Product $product): ?string
    {
        return DB::table('products')->where('id', $product->id)->value('image');
    }

    public function test_the_same_picture_twice_is_one_file_shown_by_both(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');

        $this->upload($rose, [$this->picture('a.png', 7)])->assertSessionHasNoErrors();
        $this->upload($tulip, [$this->picture('copy-of-a.png', 7)])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->stored(), 'ملفٌّ واحد على القرص');
        $this->assertSame($this->mainOf($rose), $this->mainOf($tulip));
        $this->assertStringContainsString('موجودةٌ من قبل في «Rose»', $this->toast());
    }

    public function test_the_same_pixels_in_another_file_are_the_same_picture(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $lily = $this->product('Lily');

        $first = $this->picture('a.png', 11, 'png', 9);
        $second = $this->picture('a-again.png', 11, 'png', 0);
        $this->assertNotSame(hash_file('sha256', $first->getRealPath()), hash_file('sha256', $second->getRealPath()), 'بايتاتٌ مختلفة');

        $this->upload($rose, [$first]);
        $this->upload($tulip, [$second]);

        $this->assertCount(1, $this->stored());
        $this->assertSame($this->mainOf($rose), $this->mainOf($tulip));
        $this->assertStringContainsString('بصيغةٍ أخرى', $this->toast());

        // ‏وWebP بلا فقد للبكسلات نفسها
        if (defined('IMG_WEBP_LOSSLESS')) {
            $this->upload($lily, [$this->picture('a.webp', 11, 'webp')]);
            $this->assertCount(1, $this->stored());
            $this->assertSame($this->mainOf($rose), $this->mainOf($lily));
        }
    }

    public function test_a_picture_that_only_looks_the_same_is_kept_as_its_own(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');

        $this->upload($rose, [$this->picture('a.png', 5)]);
        // ‏بكسلٌ واحدٌ مختلف — تشبهها ولا تساويها
        $this->upload($tulip, [$this->picture('a.png', 5, touch: fn ($im) => imagesetpixel($im, 3, 3, imagecolorallocate($im, 1, 2, 3)))]);

        $this->assertCount(2, $this->stored());
        $this->assertNotSame($this->mainOf($rose), $this->mainOf($tulip));
        $this->assertStringNotContainsString('موجودة', $this->toast());
    }

    public function test_one_name_with_another_content_is_another_picture(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');

        $this->upload($rose, [$this->picture('IMG_0001.png', 1)]);
        $this->upload($tulip, [$this->picture('IMG_0001.png', 2)]);

        $this->assertCount(2, $this->stored());
        $this->assertNotSame($this->mainOf($rose), $this->mainOf($tulip));
    }

    /** @return array<string, array{bool}> */
    public static function conversions(): array
    {
        // ‏والأصلُ محفوظًا كما رُفع (بلا WebP) يطابق بايتاتِه — فلا يعصمه من المتجر الآخر إلّا الحصر
        return ['محوَّلةً إلى WebP' => [true], 'محفوظةً كما رُفعت' => [false]];
    }

    #[DataProvider('conversions')]
    public function test_one_shop_is_never_matched_with_another_shops_pictures(bool $webp): void
    {
        config(['images.product_webp' => $webp]);
        $mine = $this->product('Rose');
        $other = Business::create(['name' => 'Other', 'type' => 'محل ورد', 'status' => 'نشط']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'Other', 'email' => 'other@shop.local', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $theirs = $this->product('Their rose', $other);

        $this->upload($mine, [$this->picture('a.png', 9)]);
        $this->upload($theirs, [$this->picture('a.png', 9)], as: $stranger)->assertSessionHasNoErrors();

        $this->assertCount(2, $this->stored(), 'لكلّ متجرٍ ملفُّه');
        $this->assertNotSame($this->mainOf($mine), $this->mainOf($theirs));
        $this->assertStringNotContainsString('Rose', $this->toast(), 'لا يُقال له شيءٌ عن متجرٍ غيره');
        $this->assertStringNotContainsString('موجودة', $this->toast());

        // ‏وفهرسُ كلّ متجرٍ فيه صورُه وحدها
        $index = fn (Business $b) => array_keys((array) json_decode((string) Storage::disk('local')->get('image-fingerprints/'.$b->id.'/index.json'), true));
        $this->assertSame([$this->mainOf($mine)], $index($this->shop));
        $this->assertSame([$this->mainOf($theirs)], $index($other));
    }

    public function test_removing_a_shared_picture_from_one_product_keeps_it_for_the_other(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $this->upload($rose, [$this->picture('main.png', 3), $this->picture('extra.png', 4)]);
        $this->upload($tulip, [$this->picture('extra.png', 4)]);

        $shared = ProductImage::where('product_id', $rose->id)->value('path');
        $this->assertSame($shared, $this->mainOf($tulip), 'صورةُ معرض الأوّل رئيسيّةُ الثاني');

        // ‏من معرض الأوّل: يبقى للثاني
        $this->actingAs($this->owner)->delete(route('admin.products.images.destroy', [$rose->id, ProductImage::where('product_id', $rose->id)->value('id')]));
        Storage::disk('public')->assertExists($shared);

        // ‏ومن الأخير: لا شيء يعرضه فيُمحى
        $this->actingAs($this->owner)->delete(route('admin.products.images.destroyMain', $tulip->id));
        Storage::disk('public')->assertMissing($shared);
    }

    public function test_a_picture_already_in_the_product_is_not_added_again(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, [$this->picture('a.png', 6)]);
        $this->upload($rose, [$this->picture('a.png', 6)]);

        $this->assertSame(0, ProductImage::where('product_id', $rose->id)->count(), 'لا تُعرض مرّتين');
        $this->assertStringContainsString('موجودة في هذا المنتج', $this->toast());

        // ‏ومعها صورةٌ جديدة: تُضاف وحدها
        $this->upload($rose, [$this->picture('a.png', 6), $this->picture('b.png', 8)]);
        $this->assertSame(1, ProductImage::where('product_id', $rose->id)->count());
        $this->assertCount(2, $this->stored());
    }

    public function test_one_picture_picked_twice_in_one_batch_is_saved_once(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, [$this->picture('a.png', 12), $this->picture('a (1).png', 12)]);

        $this->assertCount(1, $this->stored());
        $this->assertSame(0, ProductImage::where('product_id', $rose->id)->count(), 'رئيسيّةٌ وحدها، لا هي ونسختُها في المعرض');
    }

    public function test_the_browsers_original_fingerprint_is_a_hint_not_a_proof(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $lily = $this->product('Lily');
        $original = str_repeat('ab', 32);

        // ‏المتصفّح صغّر الأصل نفسه مرّتين فخرجت بايتاتٌ مختلفةٌ بالأبعاد نفسها
        $this->upload($rose, [$this->picture('a.jpg', 21, 'jpg', 90)], [$original]);
        $this->upload($tulip, [$this->picture('a.jpg', 21, 'jpg', 85)], [$original]);
        $this->assertSame($this->mainOf($rose), $this->mainOf($tulip));

        // ‏والتلميحُ نفسُه على صورةٍ بأبعادٍ أخرى لا يُصدَّق
        $this->upload($lily, [$this->picture('b.jpg', 22, 'jpg', 90, w: 500, h: 500)], [$original]);
        $this->assertNotSame($this->mainOf($rose), $this->mainOf($lily));

        // ‏وما ليس بصمةً لا يُقرأ
        $this->assertNull(ImageFingerprints::hint('not-a-hash'));
    }

    public function test_a_broken_or_busy_index_never_fails_an_upload(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');

        Storage::disk('local')->put('image-fingerprints/'.$this->shop->id.'/index.json', '{"products/x.webp": {"sha": "trunc');
        $this->upload($rose, [$this->picture('a.png', 31)])->assertSessionHasNoErrors();
        $this->assertNotNull($this->mainOf($rose));
        $this->assertNotEmpty(Storage::disk('local')->files('image-fingerprints/'.$this->shop->id), 'التالفُ محفوظٌ إلى جواره');

        // ‏وقفلٌ يمسكه رفعٌ آخر: يُحفظ الرفع، ولا يُكتب سطرُه
        config(['images.fingerprint_lock_ms' => 0]);
        $lock = fopen(Storage::disk('local')->path('image-fingerprints/'.$this->shop->id.'/.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->upload($tulip, [$this->picture('b.png', 32)])->assertSessionHasNoErrors();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertNotNull($this->mainOf($tulip));
        $this->assertCount(2, $this->stored());
    }

    public function test_a_request_that_falls_after_saving_never_removes_a_picture_it_reused(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, [$this->picture('a.png', 41)]);
        $kept = $this->mainOf($rose);

        // ‏إضافةُ منتجٍ بالصورة نفسِها وبأخرى جديدة، ثمّ تسقط كتابتُه
        Product::creating(fn () => throw new \RuntimeException('القاعدةُ سقطت'));

        try {
            $this->withoutExceptionHandling()->actingAs($this->owner)->post(route('admin.products.store'), [
                'name' => 'Tulip', 'price' => 5, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1,
                'active' => true, 'published' => true, 'pos_enabled' => true,
                'image' => $this->picture('a.png', 41), 'images' => [$this->picture('new.png', 42)],
            ]);
            $this->fail('كان يجب أن تسقط');
        } catch (\RuntimeException) {
            // ‏متوقَّع
        }

        Storage::disk('public')->assertExists($kept);
        $this->assertSame([$kept], $this->stored(), 'الجديدةُ مُحيت، والموجودةُ باقيةٌ لمنتجها');
    }

    public function test_the_website_keeps_one_copy_of_the_same_picture_in_its_own_folder(): void
    {
        $first = $this->actingAs($this->owner)->postJson(route('admin.marketing.store.image'), ['image' => $this->picture('hero.png', 51)])->assertOk();
        $again = $this->actingAs($this->owner)->postJson(route('admin.marketing.store.image'), ['image' => $this->picture('hero-copy.png', 51, 'png', 0)])->assertOk();

        $this->assertSame($first->json('url'), $again->json('url'));
        $this->assertNull($first->json('found'));
        $this->assertSame(ImageFingerprints::PIXELS, $again->json('found'));
        $this->assertSame($first->json('meta'), $again->json('meta'));

        // ‏ومتجرٌ آخر برفع الصورة نفسِها: ملفُّه في مجلّده
        $other = Business::create(['name' => 'Other', 'type' => 'محل ورد', 'status' => 'نشط', 'tier' => 'gold']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'Other', 'email' => 'other@site.local', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $theirs = $this->actingAs($stranger)->postJson(route('admin.marketing.store.image'), ['image' => $this->picture('hero.png', 51)]);

        $theirs->assertOk();
        $this->assertNotSame($first->json('url'), $theirs->json('url'));
        $this->assertNull($theirs->json('found'));
        $this->assertStringContainsString('/website/'.$other->id.'/', (string) $theirs->json('url'));
    }

    public function test_finding_a_picture_in_a_big_shop_stays_cheap(): void
    {
        $rows = [];

        for ($i = 0; $i < 2000; $i++) {
            $rows[] = ['business_id' => $this->shop->id, 'name' => 'P'.$i, 'price' => 1, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 0,
                'active' => true, 'published' => true, 'image' => 'products/'.str_pad((string) $i, 40, 'x').'.webp',
                'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        $rose = $this->product('Rose');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = microtime(true);
        $this->upload($rose, [$this->picture('a.png', 61)])->assertSessionHasNoErrors();
        $ms = (microtime(true) - $started) * 1000;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(3000, $ms, 'رفعٌ في متجرٍ بألفي منتج');
        $this->assertLessThan(60, $queries, 'لا سؤالَ لكلّ منتج');
    }
}
