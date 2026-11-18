<?php

namespace Tests\Feature;

use App\Support\ProductImages;
use App\Support\Store\StoreImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الصورةُ المضغوطة عند الرفع هي الصورةُ نفسُها: بنسبتها وشفافيّتها، ولا تُكبَّر،
 * وأخفُّ فعلًا، وفرقُها عن أصلها يُقاس لا يُفترض.
 *
 * والاتّجاهُ والـGIF والملفُّ الذي لا يُفكّ في `ANewProductPhotoIsSavedLightTest`.
 */
class AnUploadedPhotoKeepsItsShapeAndClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** صورةٌ كصورة بضاعة: تدرّجٌ وخطوطٌ رفيعة ونصٌّ صغير */
    private function photo(int $w, int $h, bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, $alpha ? 127 : 0));

        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                if ($alpha && ($x < $w / 4 || $y < $h / 4)) {
                    continue; // ‏ربعٌ شفّافٌ تمامًا
                }

                imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocatealpha($im,
                    (int) (40 + 180 * $x / $w), (int) (60 + 150 * $y / $h), (int) (128 + 100 * sin(($x + $y) / 37)), 0));
            }
        }

        imagestring($im, 2, (int) ($w / 2), (int) ($h / 2), 'Rose 12.500 OMR', imagecolorallocatealpha($im, 255, 255, 255, 0));

        $file = tempnam(sys_get_temp_dir(), 'shape-').'.png';
        imagepng($im, $file, 9);

        return $file;
    }

    private function upload(string $file, string $name = 'photo.png'): string
    {
        return ProductImages::putAll([new UploadedFile($file, $name, 'image/png', null, true)])[0];
    }

    /** نسبةُ الإشارة إلى الضجيج بين صورتين بأبعادٍ واحدة — بالديسيبل */
    private function psnr(\GdImage $a, \GdImage $b): float
    {
        $sum = 0.0;
        $n = 0;

        for ($y = 0; $y < imagesy($a); $y += 3) {
            for ($x = 0; $x < imagesx($a); $x += 3) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);

                foreach ([16, 8, 0] as $shift) {
                    $d = (($p >> $shift) & 0xFF) - (($q >> $shift) & 0xFF);
                    $sum += $d * $d;
                    $n++;
                }
            }
        }

        return $sum === 0.0 ? 99.0 : 10 * log10(255 * 255 / ($sum / $n));
    }

    public function test_a_large_photo_is_lighter_keeps_its_ratio_and_stays_clear(): void
    {
        $file = $this->photo(3000, 2000);
        $path = $this->upload($file);
        $stored = Storage::disk('public')->path($path);
        [$w, $h, $type] = getimagesize($stored);

        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame([ProductImages::MAX_SIDE, 1067], [$w, $h], 'الضلعُ الأطول ١٦٠٠ والنسبةُ ٣:٢ كما هي');
        $this->assertLessThan(filesize($file), filesize($stored), 'أخفُّ من أصلها فعلًا');

        // ‏والفرقُ يُقاس على المرجع نفسِه: الأصلُ مصغَّرًا بالدالّة التي تصغّر بها (`fitted`) بلا ضغط
        $reference = StoreImageUpload::fitted(imagecreatefrompng($file), ProductImages::MAX_SIDE);
        $this->assertGreaterThan(30.0, $this->psnr($reference, imagecreatefromwebp($stored)), 'ضغطٌ لا يُرى فيه فرق');
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $path = $this->upload($this->photo(320, 240));

        $this->assertSame([320, 240], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));
    }

    public function test_transparency_survives_the_conversion(): void
    {
        $path = $this->upload($this->photo(1200, 900, alpha: true));
        $stored = Storage::disk('public')->path($path);
        $image = str_ends_with($path, '.webp') ? imagecreatefromwebp($stored) : imagecreatefrompng($stored);

        $this->assertSame(127, (imagecolorat($image, 5, 5) >> 24) & 0x7F, 'الربعُ الشفّافُ باقٍ شفّافًا');
        $this->assertSame(0, (imagecolorat($image, (int) (imagesx($image) * 0.75), (int) (imagesy($image) * 0.75)) >> 24) & 0x7F, 'والباقي معتم');
    }
}
