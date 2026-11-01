<?php

namespace App\Support\Store;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * صورةٌ يرفعها صاحبُ المتجر من محرّر واجهته — تُقرأ من محتواها، وتُحفظ كاملة.
 *
 * ═══ ولمَ لا يكفي الامتداد ═══
 *
 * اسمُ الملفّ في يد من يرفعه: صفحةُ HTML تُسمّى `.jpg`، وملفُّ SVG فيه سطرُ
 * كودٍ يُنفَّذ إن فُتح من نطاق المتجر. فالنوعُ يُقرأ من رأس الملفّ نفسِه
 * (`getimagesize`) ويُقبل ما في القائمة وحده: JPEG وPNG وWebP وGIF وAVIF.
 * وما سواها يُردّ برسالةٍ تقول ما يُقبل.
 *
 * ═══ والصورةُ تُصحَّح ولا تُقصّ ═══
 *
 *  • **الاتّجاه:** الهاتفُ يحفظ الصورةَ مائلةً ويكتب في رأسها «أدِرها»
 *    (EXIF). ومتصفّحٌ يقرؤه وآخرُ لا — فتظهر الباقةُ على جنبها. فتُدار مرّةً
 *    هنا ويُمحى الرأس.
 *  • **الحجم:** صورةُ هاتفٍ بستّة آلاف بكسل تُثقل صفحةَ كلّ زائرٍ وهي تُعرض
 *    في إطارٍ بألفٍ ومئتين. فتُصغَّر حتّى يبلغ ضلعُها الأطول `MAX_SIDE`
 *    **بنسبتها** — لا قصَّ ولا مطّ.
 *  • **الرأس:** وإعادةُ الكتابة تمحو ما في الرأس من موقعٍ وجهاز — صورةٌ
 *    صُوّرت في البيت لا تحمل عنوانَه إلى كلّ من يفتح المتجر.
 *
 * ═══ والأصلُ لا يُستبدل بتالف ═══
 *
 * كلُّ معالجةٍ تُفحص نتيجتُها قبل أن تُحفظ: صورةٌ لا تُقرأ بعد المعالجة، أو
 * ذاكرةٌ لا تتّسع لفكّها، أو مكتبةٌ غائبة — يُحفظ الملفُّ كما رُفع بعد أن
 * ثبت أنّه صورةٌ من القائمة. ولا يُكتب فوق ملفٍّ قائم: كلُّ رفعٍ اسمٌ جديد.
 *
 * ═══ وHEIC — صورُ الآيفون ═══
 *
 * تُحوَّل إلى JPEG إن كان في الخادم ما يقرؤها (Imagick بقارئ HEIC)، وإلّا
 * تُردّ برسالةٍ تقول كيف تُرسل بصيغةٍ تُقبل. ولا يُدّعى قبولُ ما لا يُعرض:
 * المتصفّحاتُ — عدا سفاري — لا ترسم HEIC.
 *
 * والملفُّ في مجلّد المتجر وحده (`website/{id}/`) — وهو ما يقبله `StoreMedia::owned`.
 */
final class StoreImageUpload
{
    /** أكبرُ ملفٍّ يُقبل — صورةُ هاتفٍ حديث، ويُضيّقه حدُّ الخادم إن كان أصغر (`maxBytes`) */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** أطولُ ضلعٍ يُحفظ — ضعفُ أعرض إطارٍ في الواجهة، لشاشاتٍ بكثافةٍ مضاعفة */
    public const MAX_SIDE = 2560;

    /** أكثرُ ما يُفكّ من البكسلات — ما فوقه صورةٌ لا تُعرض في متجرٍ، أو ملفٌّ يُراد به الخادم */
    public const MAX_PIXELS = 50_000_000;

    /** جودةُ ما يُعاد كتابتُه — لا يُرى فرقُها في صورة بضاعة، ويُخفّف الملفّ */
    private const QUALITY = 88;

    /** الأنواعُ المقبولة وامتداداتُها — ما ترسمه المتصفّحاتُ كلُّها */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_AVIF => 'avif',
    ];

    /** ماركاتُ HEIC وHEIF في صندوق `ftyp` */
    private const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /**
     * أكبرُ ما يُرسل — حدُّنا، أو حدُّ الخادم إن كان أصغر.
     *
     * والمحرّرُ يقرؤه قبل أن يرفع: ملفٌّ فوق حدّ PHP لا يبلغنا أصلًا، فيُردّ
     * بخطأٍ عامٍّ لا يقول شيئًا. فيُقال له قبلُ، أو تُصغَّر الصورةُ في متصفّحه.
     */
    public static function maxBytes(): int
    {
        $limits = [self::MAX_BYTES];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::bytes((string) ini_get($key));

            if ($bytes > 0) {
                // والنموذجُ يحمل مع الملفّ رمزَه وحدودَه — هامشٌ صغير
                $limits[] = $key === 'post_max_size' ? max(0, $bytes - 64 * 1024) : $bytes;
            }
        }

        return min($limits);
    }

    /**
     * يحفظ الصورةَ في مجلّد المتجر — ويردّ رابطَها وأبعادَها.
     *
     * @return array{url: string, w: int, h: int}
     */
    public static function store(int $businessId, UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        $head = (string) @file_get_contents($path, false, null, 0, 64);

        if (self::isHeif($head)) {
            [$bytes, $w, $h] = self::fromHeif($path);

            return self::put($businessId, $bytes, 'jpg', $w, $h);
        }

        $info = @getimagesize($path);

        if (! is_array($info) || ! isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
            self::refuse(__('هذا الملفّ ليس صورةً نقبلها — ارفع صورة JPG أو PNG أو WebP.'));
        }

        [$w, $h, $type] = $info;

        if ($w * $h > self::MAX_PIXELS) {
            self::refuse(__('الصورة كبيرة الأبعاد جدًّا — صغّرها إلى أقلّ من :mp ميغابكسل ثمّ ارفعها.', ['mp' => self::MAX_PIXELS / 1_000_000]));
        }

        $original = (string) file_get_contents($path);
        $done = self::kept($path, $type, $w, $h, self::MAX_SIDE);

        if ($done !== null) {
            [$bytes, $w, $h] = $done;

            return self::put($businessId, $bytes, self::TYPES[$type], $w, $h);
        }

        return self::put($businessId, $original, self::TYPES[$type], $w, $h);
    }

    /**
     * يحفظ صورةً في مجلّدٍ عامّ بنوعها — مُصغَّرةً مُدارة، أو كما رُفعت.
     *
     * للشعار وغلاف المستند والصورة الرمزيّة: تدخل الفاتورةَ والمستندَ وإيصالَ
     * الطابعة، فتبقى بنوعها — PNG بشفافيّتها، وJPEG — ولا تصير WebP. وتُصغَّر
     * إلى ما تُطبع به لا إلى ما صوّرها به الهاتف.
     *
     * ولا يُردّ رفعٌ هنا: ما لا يُفكّ (SVG أو نوعٌ آخر) يُحفظ كما رُفع، كما
     * كان قبلُ — فالتحقّقُ من نوعه بابُ من يناديه.
     */
    public static function storeShrunk(UploadedFile $file, string $folder, int $side): string|false
    {
        $path = (string) $file->getRealPath();
        $info = $path === '' ? false : @getimagesize($path);

        if (is_array($info) && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            && $info[0] * $info[1] <= self::MAX_PIXELS) {
            $done = self::kept($path, $info[2], $info[0], $info[1], $side);

            if ($done !== null) {
                $target = $folder.'/'.Str::random(40).'.'.self::TYPES[$info[2]];

                if (Storage::disk('public')->put($target, $done[0])) {
                    return $target;
                }
            }
        }

        return $file->store($folder, 'public');
    }

    /**
     * المعالجةُ تحت قفل الفكّ — أو `null` فيُحفظ الأصل.
     *
     * وتُعاد الكتابةُ ولو لم تُصغَّر الصورة: هي التي تمحو من رأسها موقعَ
     * التصوير والجهاز (EXIF). وPNG قد يخرج أثقلَ قليلًا — ثمنٌ لا تُدفع به
     * خصوصيّةُ صاحب الصورة.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function kept(string $path, int $type, int $w, int $h, int $side): ?array
    {
        return \App\Support\ImageVariants::exclusive(fn () => self::normalized($path, $type, $w, $h, $side));
    }

    /* ═══════════ المعالجة ═══════════ */

    /**
     * الصورةُ مُدارةً ومُصغَّرةً بلا رأسها — أو `null` فيُحفظ الأصل.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function normalized(string $path, int $type, int $w, int $h, int $side = self::MAX_SIDE): ?array
    {
        // وGIF وAVIF كما رُفعتا: الأولى قد تتحرّك، والثانيةُ خفيفةٌ أصلًا
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || ! self::decodes($type)) {
            return null;
        }

        if (! self::roomFor($w * $h)) {
            return null;
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };

        if (! $image instanceof \GdImage) {
            return null;
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::oriented($image, self::orientation($path));
        }

        $image = self::fitted($image, $side);
        $w = imagesx($image);
        $h = imagesy($image);

        ob_start();

        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, self::QUALITY),
            IMAGETYPE_PNG => imagepng($image, null, 7),
            IMAGETYPE_WEBP => imagewebp($image, null, self::QUALITY),
        };

        $bytes = (string) ob_get_clean();

        // وما كُتب يُقرأ قبل أن يُحفظ — صورةٌ تالفةٌ لا تحلّ محلّ الأصل
        $check = $written && $bytes !== '' ? @getimagesizefromstring($bytes) : false;

        if (! is_array($check) || $check[2] !== $type || $check[0] !== $w || $check[1] !== $h) {
            return null;
        }

        return [$bytes, $w, $h];
    }

    /** أيفكّ هذا الخادمُ هذا النوع؟ — GD قد يكون بلا WebP */
    private static function decodes(int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * أتتّسع الذاكرةُ لفكّها؟ — وتُوسَّع لهذا الطلب وحده إن لزم.
     *
     * فكُّ صورةٍ بخمسين ميغابكسل يأخذ نحو مئتي ميغابايت. ونفادُ الذاكرة في
     * PHP لا يُلتقط — يسقط الطلبُ كلُّه بخطأٍ أبيض. فيُحسب قبل أن يُفكّ،
     * ويُحفظ الأصلُ إن لم يتّسع.
     *
     * وعامّةٌ لأنّ صور المنتجات تُضغط بالحساب نفسه (`ProductImages::put`).
     */
    public static function roomFor(int $pixels): bool
    {
        // البكسلُ أربعةُ بايتات، ونسخةٌ للتصغير، وهامشٌ للمكتبة
        $need = memory_get_usage() + (int) ($pixels * 4 * 2.2) + 32 * 1024 * 1024;
        $limit = self::bytes((string) ini_get('memory_limit'));

        if ($limit <= 0 || $need <= $limit) {
            return true;
        }

        $raise = (int) min($need, 1024 * 1024 * 1024);

        return $raise >= $need && @ini_set('memory_limit', (string) $raise) !== false
            && self::bytes((string) ini_get('memory_limit')) >= $need;
    }

    /**
     * يُصغَّر حتّى يبلغ ضلعُه الأطول `$side` — بنسبته، والشفافيّةُ باقية.
     *
     * والضلعُ يُمرَّر: صورةُ واجهة المتجر تُعرض عريضةً (`MAX_SIDE`)، وصورةُ
     * المنتج في مربّعٍ أصغر — فتُصغَّر أكثر (`ProductImages::MAX_SIDE`).
     */
    public static function fitted(\GdImage $image, int $side = self::MAX_SIDE): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $long = max($w, $h);

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($long <= $side) {
            return $image;
        }

        $scale = $side / $long;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    /**
     * الصورةُ مُدارةً كما يقول رأسُها — والقيمُ الثماني لـEXIF.
     *
     * و`imagerotate` تُدير عكسَ عقارب الساعة: ٢٧٠ ربعٌ مع العقارب.
     */
    public static function oriented(\GdImage $image, int $orientation): \GdImage
    {
        $rotate = fn (\GdImage $im, int $deg) => imagerotate($im, $deg, 0) ?: $im;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $rotate($image, 270);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = $rotate($image, 270);
                break;
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }

    /**
     * قيمةُ «الاتّجاه» في رأس JPEG — أو ١ إن لم تكن.
     *
     * تُقرأ هنا بلا امتداد `exif`: قد لا يكون في الخادم، والقيمةُ عددٌ واحدٌ
     * في موضعٍ معروف من رأس TIFF داخل قطعة APP1.
     */
    public static function orientation(string $path): int
    {
        $data = (string) @file_get_contents($path, false, null, 0, 256 * 1024);

        if (! str_starts_with($data, "\xFF\xD8")) {
            return 1;
        }

        $pos = 2;
        $len = strlen($data);

        while ($pos + 4 <= $len) {
            if ($data[$pos] !== "\xFF") {
                return 1;
            }

            $marker = ord($data[$pos + 1]);

            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $pos += 2;

                continue;
            }

            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }

            $size = unpack('n', substr($data, $pos + 2, 2))[1];

            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($data, $pos + 10, max(0, $size - 8)));
            }

            $pos += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        [$u16, $u32] = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => [null, null],
        };

        if ($u16 === null) {
            return 1;
        }

        $ifd = unpack($u32, substr($tiff, 4, 4))[1];

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $count = unpack($u16, substr($tiff, $ifd, 2))[1];

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }

            if (unpack($u16, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($u16, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /* ═══════════ HEIC ═══════════ */

    /**
     * أهذا ملفُّ HEIC/HEIF؟ — صندوقُ `ftyp` في أوّله وماركاتُه.
     *
     * وAVIF من العائلة نفسِها وبالصندوق نفسِه (`mif1` بين ماركاته)، وهو يُقبل
     * كما هو — فماركةُ `avif` تُخرجه من هذا الباب.
     */
    private static function isHeif(string $head): bool
    {
        if (strlen($head) < 16 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        $size = min(strlen($head), max(16, unpack('N', substr($head, 0, 4))[1]));
        // الماركةُ الكبرى، ثمّ رقمُ الإصدار، ثمّ الماركاتُ المتوافقة
        $brands = [strtolower(substr($head, 8, 4))];

        for ($at = 16; $at + 4 <= $size; $at += 4) {
            $brands[] = strtolower(substr($head, $at, 4));
        }

        if (array_intersect($brands, ['avif', 'avis']) !== []) {
            return false;
        }

        return array_intersect($brands, self::HEIF_BRANDS) !== [];
    }

    /** أيقرأ الخادمُ HEIC؟ */
    public static function readsHeif(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return \Imagick::queryFormats('HEIC') !== [] || \Imagick::queryFormats('HEIF') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * HEIC ← JPEG مُدارًا ومُصغَّرًا — أو ردٌّ يقول كيف تُرسل.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function fromHeif(string $path): array
    {
        $refusal = __('صور HEIC لا تُقبل على هذا الخادم بعد — اختر الصورة بصيغة JPG أو PNG (في الآيفون: الإعدادات ← الكاميرا ← التنسيقات ← الأكثر توافقًا).');

        if (! self::readsHeif()) {
            self::refuse($refusal);
        }

        try {
            $im = new \Imagick($path);

            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }

            if (max($im->getImageWidth(), $im->getImageHeight()) > self::MAX_SIDE) {
                $im->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
            }

            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::QUALITY);
            $im->stripImage();
            $bytes = $im->getImagesBlob();
        } catch (\Throwable) {
            self::refuse($refusal);
        }

        $check = @getimagesizefromstring($bytes);

        if (! is_array($check) || $check[2] !== IMAGETYPE_JPEG) {
            self::refuse($refusal);
        }

        return [$bytes, (int) $check[0], (int) $check[1]];
    }

    /* ═══════════ أدوات ═══════════ */

    /** @return array{url: string, w: int, h: int} */
    private static function put(int $businessId, string $bytes, string $ext, int $w, int $h): array
    {
        $path = 'website/'.$businessId.'/'.Str::random(40).'.'.$ext;
        Storage::disk('public')->put($path, $bytes);

        return ['url' => Storage::url($path), 'w' => $w, 'h' => $h];
    }

    /** `8M` ← بايتات */
    private static function bytes(string $ini): int
    {
        $ini = trim($ini);

        if ($ini === '' || $ini === '-1') {
            return -1;
        }

        $n = (int) $ini;

        return match (strtolower(substr($ini, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
