<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ProductImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صورةُ المنتج الجديدة تُحفظ خفيفة — WebP مُصغَّرةً مُدارة، أو كما رُفعت.
 *
 * ═══ لمَ ═══
 *
 * صورةُ هاتفٍ بأربعة ميغابايت، وخمسٌ لكلّ صنف، ومئاتُ الأصناف: القرصُ والنسخُ
 * الاحتياطيّة وهاتفُ الزبون يدفعون ثمنَ بكسلاتٍ لا تُرى في إطار الصفحة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - صورةٌ كبيرة تُحفظ WebP صالحةً تُفكّ، أطولُ ضلعها `MAX_SIDE` بنسبتها،
 *     وأصغرَ من أصلها.
 *   - صورةُ الهاتف المائلة تُدار كما يقول رأسُها قبل أن يُمحى.
 *   - المفتاحُ المطفأ، وما لا يُفكّ، وGIF، وما لا يصغر: يُحفظ الأصلُ بايتًا
 *     ببايت وبامتداده — لا ملفّ JPEG باسم WebP.
 *   - الصورُ القائمة لا تُمسّ: لا تُحوَّل ولا يُعاد ضغطُها.
 *
 * والقاعدةُ في `ProductImages::put`.
 */
class ANewProductPhotoIsSavedLightTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->business = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@light.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /**
     * صورةُ بضاعةٍ مصنوعة — تدرّجٌ وألوانٌ وحبيبات، لا مربّعٌ أسود يُضغط إلى لا شيء.
     */
    private function jpeg(int $w, int $h, int $quality = 90): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(7);

        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $n = mt_rand(-18, 18);
                $c = imagecolorallocate($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)),
                    max(0, min(255, (int) (160 * $y / $h) + 50 + $n)),
                    max(0, min(255, (int) (120 + 80 * sin(($x + $y) / 90)) + $n)));
                imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, $c);
            }
        }

        ob_start();
        imagejpeg($im, null, $quality);

        return (string) ob_get_clean();
    }

    /** يُضيف صنفًا بصورةٍ رئيسيةٍ واحدة ويردّ مسارها المحفوظ */
    private function saved(UploadedFile $photo): string
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة', 'price' => 10, 'image' => $photo,
        ])->assertSessionHasNoErrors();

        return (string) Product::where('business_id', $this->business->id)->latest('id')->firstOrFail()->getRawOriginal('image');
    }

    private function bytes(string $path): string
    {
        return (string) Storage::disk('public')->get($path);
    }

    /* ═══════════ تُحفظ خفيفة ═══════════ */

    public function test_a_large_photo_is_saved_as_a_smaller_valid_webp_within_the_side(): void
    {
        // نحو ٩٠٠ كيلوبايت — دون حدّ الملفّ على أضيق خادم (٢ ميغا افتراضُ PHP)
        $original = $this->jpeg(2400, 1800, 80);
        $path = $this->saved(UploadedFile::fake()->createWithContent('bouquet.jpg', $original));

        $this->assertStringEndsWith('.webp', $path);
        $stored = $this->bytes($path);
        $info = getimagesizefromstring($stored);

        $this->assertIsArray($info, 'المحفوظُ لا يُقرأ صورة');
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame([ProductImages::MAX_SIDE, 1200], [$info[0], $info[1]], 'لم تُصغَّر بنسبتها');
        $this->assertInstanceOf(\GdImage::class, imagecreatefromstring($stored), 'المحفوظُ لا يُفكّ');
        $this->assertLessThan(strlen($original), strlen($stored));
        // صورةُ بضاعةٍ نموذجيّة: بضع مئاتٍ من الكيلوبايت لا ميغابايت
        $this->assertLessThan(450 * 1024, strlen($stored), 'الضغطُ لم يبلغ ما يُرجى منه');
    }

    public function test_a_gallery_photo_is_saved_light_the_same_way(): void
    {
        // البابُ نفسه للمعرض: لا تُضغط الرئيسيةُ وحدها
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => 'products/old-main.jpg',
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.images.store', $product->id), [
            'images' => [UploadedFile::fake()->createWithContent('side.jpg', $this->jpeg(2000, 1000))],
        ])->assertSessionHasNoErrors();

        $path = ProductImage::where('product_id', $product->id)->value('path');
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame([ProductImages::MAX_SIDE, 800], array_slice(getimagesizefromstring($this->bytes($path)), 0, 2));
    }

    public function test_a_sideways_phone_photo_is_turned_upright(): void
    {
        /*
         * الهاتفُ يحفظ الصورةَ بعرضها ويكتب في رأسها «أدِرها ربعًا مع
         * العقارب» (الاتّجاه ٦). والضغطُ يمحو الرأس — فلو لم تُدر قبله
         * لَظهرت الباقةُ على جنبها في كلّ متصفّح.
         */
        $im = imagecreatetruecolor(300, 200);
        imagefilledrectangle($im, 0, 0, 149, 199, imagecolorallocate($im, 230, 30, 40));
        ob_start();
        imagejpeg($im, null, 92);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $app1 = "Exif\0\0".$tiff;
        $sideways = "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);

        $path = $this->saved(UploadedFile::fake()->createWithContent('phone.jpg', $sideways));

        $this->assertStringEndsWith('.webp', $path);
        $upright = imagecreatefromstring($this->bytes($path));
        $this->assertSame([200, 300], [imagesx($upright), imagesy($upright)], 'لم تُدر الصورة');

        // والنصفُ الأحمرُ — يسارُ المحفوظ — صار أعلاها: ربعٌ مع العقارب لا ضدّها
        $top = imagecolorsforindex($upright, imagecolorat($upright, 100, 60));
        $bottom = imagecolorsforindex($upright, imagecolorat($upright, 100, 240));
        $this->assertGreaterThan(150, $top['red']);
        $this->assertLessThan(80, $bottom['red']);
    }

    /* ═══════════ أو كما رُفعت ═══════════ */

    public function test_with_the_switch_off_the_original_is_kept_as_uploaded(): void
    {
        config(['images.product_webp' => false]);
        $original = $this->jpeg(1200, 900);

        $path = $this->saved(UploadedFile::fake()->createWithContent('bouquet.jpg', $original));

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(md5($original), md5($this->bytes($path)), 'تغيّر الملفُّ والضغطُ مطفأ');
    }

    public function test_a_file_that_does_not_decode_is_kept_unchanged_and_not_renamed(): void
    {
        // ملفٌّ يدّعي أنّه JPEG ولا يُفكّ — لا يُكتب مكانه شيءٌ تالف ولا يُسمّى WebP
        $fake = UploadedFile::fake()->create('broken.jpg', 40, 'image/jpeg');
        $original = (string) file_get_contents($fake->getRealPath());

        $path = $this->saved($fake);

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(md5($original), md5($this->bytes($path)));
    }

    public function test_a_gif_is_kept_as_it_was(): void
    {
        // قد تتحرّك، وGD يفكّ إطارها الأوّل وحده
        $gif = UploadedFile::fake()->image('spinner.gif', 120, 120);
        $original = (string) file_get_contents($gif->getRealPath());

        $path = $this->saved($gif);

        $this->assertStringEndsWith('.gif', $path);
        $this->assertSame(md5($original), md5($this->bytes($path)));
    }

    public function test_a_photo_that_would_not_shrink_is_kept(): void
    {
        /*
         * JPEG مضغوطٌ بقسوةٍ أصلًا يكبر إن أُعيد بجودة WebP المعتادة —
         * فيُحفظ هو، بامتداده، لا الأثقلُ منه.
         */
        $im = imagecreatetruecolor(300, 300);
        mt_srand(3);
        for ($y = 0; $y < 300; $y++) {
            for ($x = 0; $x < 300; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagejpeg($im, null, 5);
        $harsh = (string) ob_get_clean();

        $path = $this->saved(UploadedFile::fake()->createWithContent('tiny.jpg', $harsh));

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(md5($harsh), md5($this->bytes($path)));
    }

    /* ═══════════ والقائمُ لا يُمسّ ═══════════ */

    public function test_existing_photos_are_never_converted(): void
    {
        $old = $this->jpeg(1800, 1200);
        Storage::disk('public')->put('products/old-main.jpg', $old);
        Storage::disk('public')->put('products/old-side.jpg', $old);

        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'قديم', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => 'products/old-main.jpg',
        ]);
        $side = ProductImage::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'path' => 'products/old-side.jpg', 'sort_order' => 1]);

        $this->actingAs($this->owner);
        $this->post(route('admin.products.images.store', $product->id), [
            'images' => [UploadedFile::fake()->createWithContent('new.jpg', $this->jpeg(900, 600))],
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.products.images.promote', [$product->id, $side->id]))->assertSessionHasNoErrors();
        $this->put(route('admin.products.update', $product->id), ['name' => 'قديم', 'price' => 6])->assertSessionHasNoErrors();

        foreach (['products/old-main.jpg', 'products/old-side.jpg'] as $path) {
            $this->assertSame(md5($old), md5($this->bytes($path)), "أُعيد ضغطُ صورةٍ قائمة: $path");
        }
        $this->assertSame('products/old-side.jpg', $product->fresh()->getRawOriginal('image'));
    }
}
