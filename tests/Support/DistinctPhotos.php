<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * صورٌ مختلفةُ المحتوى لاختبارٍ يرفع أكثرَ من صورة.
 *
 * `UploadedFile::fake()->image()` يرسم صورةً سوداء: صورتان بالأبعاد نفسها بايتاتُهما
 * واحدة، فيعرفهما المتجرُ صورةً واحدة (`ImageFingerprints`) ولا يحفظهما مرّتين. ومن
 * يختبر معرضًا بخمس صورٍ يريد خمسَ صورٍ حقًّا — فلكلّ صورةٍ هنا لونٌ لا يتكرّر.
 */
final class DistinctPhotos
{
    private static int $next = 0;

    public static function make(string $name, int $w = 10, int $h = 10): UploadedFile
    {
        $n = ++self::$next;
        $image = imagecreatetruecolor($w, $h);
        // ‏لونٌ كاملٌ لكلّ صورة — بكسلٌ واحدٌ داكنٌ يمحوه ضغطُ JPEG فتعود الصورتان واحدة
        imagefill($image, 0, 0, imagecolorallocate($image, ($n * 53) % 256, ($n * 97 + 40) % 256, ($n * 29 + 80) % 256));

        $png = str_ends_with(strtolower($name), '.png');
        $file = tempnam(sys_get_temp_dir(), 'photo-').($png ? '.png' : '.jpg');
        $png ? imagepng($image, $file) : imagejpeg($image, $file, 100);

        return new UploadedFile($file, $name, $png ? 'image/png' : 'image/jpeg', null, true);
    }
}
