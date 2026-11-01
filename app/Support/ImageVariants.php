<?php

namespace App\Support;

use App\Support\Store\StoreImageUpload;
use Illuminate\Support\Facades\Storage;

/**
 * نسخٌ أخفُّ من صورةٍ محفوظة — إلى جوارها، والأصلُ لا يُمسّ.
 *
 * ═══ نسختان لكلّ صورة ═══
 *
 *  • **المصغّرة** (`THUMB`): للقوائم والبطاقات — رفُّ متجرٍ بأربعين صنفًا لا
 *    يُنزِل أربعين صورةً بعرض ١٦٠٠ ليرسمها في مربّعاتٍ بعرض ثلاثمئة.
 *  • **نسخةُ العرض** (`DISPLAY`): لصورةٍ قديمةٍ رُفعت قبل أن يُضغط الرفع —
 *    JPEG أو PNG بأبعاد الهاتف — تُعرض WebP بالحدّ الذي تُرفع به الصورُ اليوم.
 *
 * ═══ والأصلُ وصفُّه لا يُمسّان ═══
 *
 * النسخةُ ملفٌّ جديدٌ اسمُه اسمُ الأصل وبعده ضلعُها: `products/abc.jpg.w640.webp`.
 * فلا يُكتب فوق ملفٍّ، ولا يتغيّر عمودٌ في القاعدة، ولا تُعرف النسخةُ إلّا
 * من وجودها على القرص (`url`). فالرجوعُ محوُ النسخ وحدها — وتعود كلُّ صفحةٍ
 * إلى الأصل كما كانت.
 *
 * ولا تُحفظ نسخةٌ لا توفّر: صورةٌ مضغوطةٌ أصلًا لا يُكتب إلى جوارها مثلُها.
 */
final class ImageVariants
{
    /** ضلعُ المصغّرة — بطاقةٌ بعرض ثلاثمئة في شاشةٍ بكثافةٍ مضاعفة */
    public const THUMB = 640;

    /** ضلعُ نسخة العرض — الحدُّ نفسُه الذي تُحفظ به صورةُ منتجٍ تُرفع اليوم */
    public const DISPLAY = ProductImages::MAX_SIDE;

    private const QUALITY = [self::THUMB => 75, self::DISPLAY => 80];

    /** نسخةٌ لا تنقص عن أصلها عُشرًا لا تستحقّ مكانها على القرص */
    private const WORTH = 0.9;

    private const DISK = 'public';

    /** المجلّداتُ التي تُعرض صورُها للزبائن وتُصنع لها نسخ — صورُ المنتجات */
    public const FOLDERS = ['products'];

    public static function path(string $path, int $side): string
    {
        return $path.'.w'.$side.'.webp';
    }

    /**
     * علامةُ «عولجت ولا مصغّرةَ لها» — ملفٌّ فارغٌ إلى جوار الأصل.
     *
     * صورةٌ صغيرةٌ أصلًا، أو GIF، أو ما لا يُفكّ: لا تُصنع لها مصغّرة. وبلا
     * علامةٍ تُفكّ في كلّ جولةٍ من جديد، وتأخذ مكانَ غيرها في كلّ دفعة — فلا
     * تتقدّم الجولاتُ أبدًا. ومحوُها يُعيد الصورةَ إلى الدور.
     */
    public static function marker(string $path): string
    {
        return $path.'.w'.self::THUMB.'.none';
    }

    public static function isVariant(string $path): bool
    {
        return (bool) preg_match('/\.w\d+\.(webp|none)$/', $path);
    }

    /** هل انتهى أمرُ هذه الصورة؟ — مصغّرتُها، أو علامةُ أنّها لا تحتاجها، أو لا أصل */
    public static function handled(string $path): bool
    {
        $disk = Storage::disk(self::DISK);

        return ! $disk->exists($path) || $disk->exists(self::path($path, self::THUMB)) || $disk->exists(self::marker($path));
    }

    /**
     * رابطُ أخفِّ نسخةٍ تكفي لهذا الضلع — أو رابطُ الأصل إن لم تُصنع.
     *
     * والمصغّرةُ إن غابت فنسخةُ العرض أولى من الأصل: أخفُّ منه وبالأبعاد نفسها.
     */
    public static function url(?string $raw, int $side): string
    {
        if (blank($raw)) {
            return '';
        }

        if (str_starts_with($raw, 'http')) {
            return $raw;
        }

        $disk = Storage::disk(self::DISK);

        foreach ($side === self::THUMB ? [self::THUMB, self::DISPLAY] : [self::DISPLAY] as $s) {
            if ($disk->exists(self::path($raw, $s))) {
                return Storage::url(self::path($raw, $s));
            }
        }

        return Storage::url($raw);
    }

    /** نسخُ ملفٍّ ما — لمن يمحوه، فلا تبقى نسخةٌ لا أصلَ لها */
    public static function forget(?string $raw): void
    {
        if (blank($raw) || str_starts_with($raw, 'http')) {
            return;
        }

        Storage::disk(self::DISK)->delete([self::path($raw, self::THUMB), self::path($raw, self::DISPLAY), self::marker($raw)]);
    }

    /**
     * يصنع ما ينقص من نسخ ملفٍّ محفوظ — ويقول ما صنع، أو لماذا لم يصنع.
     *
     * `$write = false` يحسب ولا يكتب: لجردٍ يقدّر التوفير قبل أن يُصنع شيء.
     *
     * @return array{status: string, before: int, display: int, thumb: int, ms: float, peak: int}
     */
    public static function make(string $path, bool $write = true): array
    {
        // ‏ومفتاحُ WebP نفسُه يطفئها: خادمٌ تُفسد مكتبتُه الصورَ لا تُصنع عليه نسخ
        if (! config('images.product_webp', true)) {
            return ['status' => 'off', 'before' => 0, 'display' => 0, 'thumb' => 0, 'ms' => 0.0, 'peak' => 0];
        }

        $out = self::build($path, $write);

        if ($write && ! in_array($out['status'], ['missing', 'done'], true)
            && ! Storage::disk(self::DISK)->exists(self::path($path, self::THUMB))) {
            Storage::disk(self::DISK)->put(self::marker($path), '');
        }

        return $out;
    }

    /** @return array{status: string, before: int, display: int, thumb: int, ms: float, peak: int} */
    private static function build(string $path, bool $write): array
    {
        $out = ['status' => 'skipped', 'before' => 0, 'display' => 0, 'thumb' => 0, 'ms' => 0.0, 'peak' => 0];
        $disk = Storage::disk(self::DISK);

        if (str_starts_with($path, 'http') || self::isVariant($path) || ! $disk->exists($path)) {
            return ['status' => 'missing'] + $out;
        }

        $have = [self::DISPLAY => $disk->exists(self::path($path, self::DISPLAY)), self::THUMB => $disk->exists(self::path($path, self::THUMB))];

        if ($have[self::DISPLAY] && $have[self::THUMB]) {
            return ['status' => 'done'] + $out;
        }

        $started = hrtime(true);
        $source = $disk->path($path);
        $out['before'] = (int) @filesize($source);
        $info = @getimagesize($source);

        // ‏وGIF قد تتحرّك، وGD يفكّ إطارها الأوّل وحده — فتبقى كما هي
        $decode = is_array($info) ? match ($info[2]) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        } : null;

        if ($decode === null || ! function_exists($decode) || ! function_exists('imagewebp')
            || $info[0] * $info[1] > StoreImageUpload::MAX_PIXELS || ! StoreImageUpload::roomFor($info[0] * $info[1])) {
            return ['status' => 'unsupported'] + $out;
        }

        try {
            $image = @$decode($source);

            if (! $image instanceof \GdImage) {
                return ['status' => 'unreadable'] + $out;
            }

            if ($info[2] === IMAGETYPE_JPEG) {
                $image = StoreImageUpload::oriented($image, StoreImageUpload::orientation($source));
            }

            $display = StoreImageUpload::fitted($image, self::DISPLAY);
            $best = $out['before'];
            $wrote = false;

            // ‏نسخةُ العرض لما ليس WebP بحدّها: صورةٌ تُرفع اليوم لا تحتاجها
            if (! $have[self::DISPLAY] && ($info[2] !== IMAGETYPE_WEBP || max($info[0], $info[1]) > self::DISPLAY)) {
                $bytes = self::encode($display, self::DISPLAY);

                if ($bytes !== null && strlen($bytes) < $out['before'] * self::WORTH) {
                    $out['display'] = strlen($bytes);
                    $best = strlen($bytes);
                    $wrote = self::put(self::path($path, self::DISPLAY), $bytes, $write) || $wrote;
                }
            }

            if (! $have[self::THUMB] && max(imagesx($display), imagesy($display)) > self::THUMB) {
                $bytes = self::encode(StoreImageUpload::fitted($display, self::THUMB), self::THUMB);

                if ($bytes !== null && strlen($bytes) < $best * self::WORTH) {
                    $out['thumb'] = strlen($bytes);
                    $wrote = self::put(self::path($path, self::THUMB), $bytes, $write) || $wrote;
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'failed'] + $out;
        }

        $out['ms'] = round((hrtime(true) - $started) / 1e6, 1);
        $out['peak'] = memory_get_peak_usage(true);
        $out['status'] = ($out['display'] + $out['thumb']) === 0 ? 'small' : ($write ? ($wrote ? 'made' : 'failed') : 'estimated');

        return $out;
    }

    /** يصنع النسخ ولا يُسقط ما ناداه — رفعٌ نجح لا يُردّ لأنّ مصغّرته تعذّرت */
    public static function makeQuietly(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            self::make($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** WebP مقروءةً بأبعادها — أو `null` فلا تُحفظ نسخةٌ تالفة */
    private static function encode(\GdImage $image, int $side): ?string
    {
        ob_start();

        try {
            $written = imagewebp($image, null, self::QUALITY[$side]);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        $check = $written && $bytes !== '' ? @getimagesizefromstring($bytes) : false;

        return is_array($check) && $check[2] === IMAGETYPE_WEBP
            && $check[0] === imagesx($image) && $check[1] === imagesy($image) ? $bytes : null;
    }

    private static function put(string $path, string $bytes, bool $write): bool
    {
        return $write && Storage::disk(self::DISK)->put($path, $bytes);
    }
}
