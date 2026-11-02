<?php

namespace App\Support;

use App\Support\Store\StoreImageUpload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * نسخٌ أخفُّ من صورةٍ محفوظة — إلى جوارها، والأصلُ لا يُمسّ.
 *
 * ═══ نسختان لكلّ صورة ═══
 *
 *  • **المصغّرة** (`THUMB`): للقوائم والبطاقات — رفُّ متجرٍ بأربعين صنفًا لا
 *    يُنزِل أربعين صورةً بعرض ١٦٠٠ ليرسمها في مربّعاتٍ بعرض ثلاثمئة.
 *  • **نسخةُ العرض**: WebP بالضلع الذي تُعرض به صورُ مجلّدها (`SIDES`) —
 *    لصورةٍ قديمةٍ رُفعت JPEG أو PNG بأبعاد الهاتف قبل أن يُضغط الرفع.
 *
 * والشفافيّةُ باقية (WebP يحملها)، والنسبةُ باقية (لا قصَّ ولا مطّ)، وGIF
 * تُترك لأنّها قد تتحرّك.
 *
 * ═══ والأصلُ وصفُّه لا يُمسّان ═══
 *
 * النسخةُ ملفٌّ جديدٌ اسمُه اسمُ الأصل وبعده ضلعُها: `products/abc.jpg.w640.webp`.
 * فلا يُكتب فوق ملفٍّ ولا يتغيّر عمود. والرجوعُ محوُ النسخ وحدها، فتعود كلُّ
 * صفحةٍ إلى الأصل كما كانت. ونقلُ المراجع إلى النسخة — ليُستغنى عن الأصل —
 * خطوةٌ أخرى بقرارٍ وسجلٍّ يُرجع به (`ImageSwap`).
 *
 * ═══ ولا تُعدّ نسخةٌ ناجحةً قبل أن تُقرأ من القرص ═══
 *
 * كلُّ ما كُتب يُعاد فتحُه من القرص ويُفحص حجمُه ونوعُه وأبعادُه. وما تعذّر
 * منه يُمحى ما كُتب معه في الجولة نفسها، فلا تبقى صورةٌ بنصف نسخها — وتُعاد
 * المحاولةُ في الجولة التالية.
 *
 * ═══ ولا تُرى نسخةٌ نصفَ مكتوبة ═══
 *
 * النسخةُ تُكتب في ملفٍّ مؤقّتٍ مخفيٍّ ثمّ تُنشر باسمها دفعةً واحدة
 * (`publish`) — فانقطاعٌ في منتصف الكتابة لا يترك بالاسم الذي تقرؤه الصفحات
 * إلّا لا شيء. وما بقي ناقصًا من قبلُ يُعرف من رأسه (`sound`) ويُعاد صنعُه.
 */
final class ImageVariants
{
    /** ضلعُ المصغّرة — بطاقةٌ بعرض ثلاثمئة في شاشةٍ بكثافةٍ مضاعفة */
    public const THUMB = 640;

    /**
     * ضلعُ نسخة العرض لكلّ مجلّد — الحدُّ نفسُه الذي تُرفع به صورُه اليوم.
     *
     * صورُ المنتجات في إطار صفحة الصنف (`ProductImages::MAX_SIDE`)، وصورُ
     * الموقع عريضةٌ في الشريط والواجهة (`StoreImageUpload::MAX_SIDE`).
     */
    public const SIDES = ['products' => ProductImages::MAX_SIDE, 'website' => StoreImageUpload::MAX_SIDE];

    /** ما يُطلب من `url` لصفحة الصنف — نسخةُ العرض بضلع مجلّدها، لا ضلعٌ بعينه */
    public const DISPLAY = 0;

    /**
     * المجلّداتُ التي تُصنع لها مصغّرة — صورُ المنتجات تُعرض في القوائم.
     *
     * وصورُ الموقع روابطُ في الإعدادات تُرسم بحجمها في الشريط والواجهة: لا
     * قائمةَ تقرأ مصغّرتها، فلا تُكتب على القرص.
     */
    private const THUMBS = ['products'];

    private const QUALITY = ['thumb' => 75, 'display' => 82];

    /** نسخةٌ لا تنقص عن أصلها عُشرًا لا تستحقّ مكانها على القرص */
    private const WORTH = 0.9;

    private const DISK = 'public';

    /** ملفٌّ مؤقّتٌ أقدمُ من هذا تركته عمليّةٌ انقطعت — لا كاتبٌ ينتظره (بالثواني) */
    private const STALE = 600;

    /** ضلعُ نسخة العرض لهذا الملفّ — أو `null` لملفٍّ في مجلّدٍ لا تُصنع له نسخ */
    public static function side(string $path): ?int
    {
        return self::SIDES[strtok($path, '/')] ?? null;
    }

    /** الأصلُ الذي صُنعت منه هذه النسخة — والأصلُ نفسُه لنفسه */
    public static function base(string $path): string
    {
        return (string) preg_replace('/(\.w\d+\.webp)+$/', '', $path);
    }

    public static function path(string $path, int $side): string
    {
        return self::base($path).'.w'.$side.'.webp';
    }

    public static function display(string $path): ?string
    {
        $side = self::side($path);

        return $side === null ? null : self::path($path, $side);
    }

    public static function isVariant(string $path): bool
    {
        return (bool) preg_match('/\.w\d+\.webp$/', $path);
    }

    /**
     * رابطُ أخفِّ نسخةٍ تكفي — أو رابطُ ما في الصفّ إن لم تُصنع.
     *
     * والمصغّرةُ إن غابت فنسخةُ العرض أولى من الأصل: أخفُّ منه بالأبعاد نفسها.
     * وصفٌّ نُقل إلى نسخة العرض يُعرض بها كما هي، ومصغّرتُه مصغّرةُ أصلها.
     *
     * ═══ وكلفتُه ═══
     *
     * سؤالُ القرص هنا `is_file` مباشرةً لا `Storage::exists` — أرخصُ منه ثلاثَ
     * مرّاتٍ بلا طبقة Flysystem (قِيس: ألفُ سؤالٍ في نصف ملّي‌ثانية). وصفحةُ
     * قائمةٍ بخمسين صنفًا تسأل مئةَ سؤالٍ على الأكثر. ولا ذاكرةَ بين الطلبات:
     * عاملُ طوابير يعيش ساعةً يحفظ «النسخةُ موجودة» بعد أن مُحيت — فيرسل رابطًا
     * إلى لا شيء.
     */
    public static function url(?string $raw, int $side): string
    {
        if (blank($raw)) {
            return '';
        }

        if (str_starts_with($raw, 'http')) {
            return $raw;
        }

        $display = self::display($raw);
        $wanted = $side === self::THUMB ? [self::path($raw, self::THUMB), $display] : [$display];

        foreach (array_filter($wanted) as $candidate) {
            if ($candidate === $raw || is_file(Storage::disk(self::DISK)->path($candidate))) {
                return Storage::url($candidate);
            }
        }

        return Storage::url($raw);
    }

    /** نسخُ ملفٍّ ما — لمن يمحوه، فلا تبقى نسخةٌ لا أصلَ لها */
    public static function forget(?string $raw): void
    {
        if (blank($raw) || str_starts_with($raw, 'http') || ($display = self::display($raw)) === null) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        $copies = array_values(array_diff([self::path($raw, self::THUMB), $display], [$raw]));

        // ‏وما تركته كتابةٌ انقطعت إلى جوار نسخه — لا يبقى بعد أصله
        foreach ($copies as $copy) {
            foreach (self::leftovers($disk->path($copy)) as $tmp) {
                @unlink($tmp);
            }
        }

        $disk->delete($copies);
    }

    /**
     * ما تحتاجه هذه الصورة — من رأس الملفّ وحده، بلا فكّ.
     *
     * نسخةُ عرضٍ لما ليس WebP بحدّه، ومصغّرةٌ لما هو أعرضُ منها. وما عنده
     * نسختُه لا يُطلب مرّةً أخرى — ما دامت كاملة: نسخةٌ قطعها انقطاعٌ قبل هذا
     * الإصلاح تُطلب من جديد، فلا تبقى معروضةً ناقصةً أبدًا.
     *
     * @return array{display: bool, thumb: bool, type: int, w: int, h: int}|null
     */
    public static function needs(string $path): ?array
    {
        $disk = Storage::disk(self::DISK);
        $side = self::side($path);
        $info = $side === null || self::isVariant($path) ? false : @getimagesize($disk->path($path));

        if (! is_array($info)) {
            return null;
        }

        [$w, $h, $type] = $info;
        $long = max($w, $h);

        return [
            'display' => ($type !== IMAGETYPE_WEBP || $long > $side) && ! self::sound($disk->path((string) self::display($path))),
            'thumb' => in_array(strtok($path, '/'), self::THUMBS, true) && $long > self::THUMB
                && ! self::sound($disk->path(self::path($path, self::THUMB))),
            'type' => $type, 'w' => $w, 'h' => $h,
        ];
    }

    /**
     * يصنع ما ينقص من نسخ ملفٍّ محفوظ — ويقول ما صنع، أو لماذا لم يصنع.
     *
     *  • `made`: كُتب كلُّ ما يُحتاج وقُرئ من القرص صحيحًا.
     *  • `ready`: لا ينقصه شيء.
     *  • `skipped`: لا تُصنع له نسخة — GIF أو نوعٌ لا يُفكّ، أو نسخةٌ لا توفّر، أو
     *    أكبرُ من سقف البكسلات (`ImageMemory::maxPixels`).
     *  • `deferred`: لا تتّسع ذاكرةُ الخادم المتاحة لفكّها الآن (`ImageMemory`) — تُعاد لاحقًا.
     *  • `failed`: تعذّر شيءٌ — ومُحي ما كُتب في هذه الجولة، فيُعاد لاحقًا.
     *  • `missing`: لا أصلَ على القرص.
     *  • `estimated`: `$write = false` — حُسب ولم يُكتب، لجردٍ يقدّر قبل أن يُصنع شيء.
     *
     * @return array{status: string, reason: ?string, before: int, display: int, thumb: int, ms: float, peak: int}
     */
    public static function make(string $path, bool $write = true): array
    {
        $out = ['status' => 'ready', 'reason' => null, 'before' => 0, 'display' => 0, 'thumb' => 0, 'ms' => 0.0, 'peak' => 0];
        $disk = Storage::disk(self::DISK);

        // ‏ومفتاحُ WebP نفسُه يطفئها: خادمٌ تُفسد مكتبتُه الصورَ لا تُصنع عليه نسخ
        if (! config('images.product_webp', true)) {
            return ['status' => 'skipped', 'reason' => 'switched_off'] + $out;
        }

        if (str_starts_with($path, 'http') || ! is_file($disk->path($path))) {
            return ['status' => 'missing'] + $out;
        }

        $need = self::needs($path);
        $out['before'] = (int) @filesize($disk->path($path));

        if ($need === null) {
            return ['status' => 'skipped', 'reason' => 'not_an_image'] + $out;
        }

        if (! $need['display'] && ! $need['thumb']) {
            return $out;
        }

        $decode = match ($need['type']) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };

        if ($decode === null || ! function_exists($decode) || ! function_exists('imagewebp')) {
            return ['status' => 'skipped', 'reason' => 'type'] + $out;
        }

        $pixels = $need['w'] * $need['h'];

        // ‏فوق السقف تُترك أبدًا ويُقال لماذا — صورةٌ لا يتّسع لها خادمٌ بغيغابايت
        if ($pixels > StoreImageUpload::MAX_PIXELS || $pixels > ImageMemory::maxPixels()) {
            ImageMemory::note('variants', 'too_large', $need['w'], $need['h'], $path);

            return ['status' => 'skipped', 'reason' => 'too_large'] + $out;
        }

        $started = hrtime(true);
        $written = [];

        try {
            // ‏و`$written` بالمرجع: ما كُتب قبل أن تسقط الجولةُ يُعرف فيُمحى
            $out = self::exclusive(function () use ($path, $decode, $write, $out, &$written, $pixels) {
                /*
                 * وما يُحتاج يُسأل مرّةً أخرى داخل القفل: رفعٌ وجولةٌ يطلبان
                 * الصورةَ نفسها، فينتظر أحدُهما دوره والآخرُ يصنع نسخها — فلا
                 * تُفكّ مرّتين.
                 */
                $need = self::needs($path);

                if ($need === null || (! $need['display'] && ! $need['thumb'])) {
                    return $out;
                }

                /*
                 * والذاكرةُ تُسأل داخل القفل لا قبله: فكٌّ آخرُ انتهى للتوّ لا
                 * يُحسب على هذه، ولا يبدأ غيرُها بين السؤال والفكّ.
                 *
                 * وما لا يتّسع الآن يُؤجَّل لا يُترك: الذاكرةُ تعود، والصورةُ
                 * باقيةٌ في الدور.
                 */
                if (ImageMemory::shortFor($pixels) !== null || ! StoreImageUpload::roomFor($pixels)) {
                    ImageMemory::note('variants', 'low_memory', $need['w'], $need['h'], $path);

                    return ['status' => 'deferred', 'reason' => 'low_memory'] + $out;
                }

                return self::build($path, $decode, $need, $write, $out, $written);
            });
        } catch (\Throwable $e) {
            report($e);
            $out = ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 160)] + $out;
        }

        // ‏ولا نصفَ صورة: ما كُتب في جولةٍ لم تكتمل يُمحى، فتُعاد كلُّها لاحقًا
        if ($out['status'] === 'failed' && $written !== []) {
            $disk->delete($written);
        }

        $out['ms'] = round((hrtime(true) - $started) / 1e6, 1);
        $out['peak'] = memory_get_peak_usage(true);

        return $out;
    }

    /**
     * @param  array{display: bool, thumb: bool, type: int, w: int, h: int}  $need
     * @param  list<string>  $written
     */
    private static function build(string $path, string $decode, array $need, bool $write, array $out, array &$written): array
    {
        $source = Storage::disk(self::DISK)->path($path);
        $image = @$decode($source);

        if (! $image instanceof \GdImage) {
            return ['status' => 'skipped', 'reason' => 'unreadable'] + $out;
        }

        if ($need['type'] === IMAGETYPE_JPEG) {
            $image = StoreImageUpload::oriented($image, StoreImageUpload::orientation($source));
        }

        $display = StoreImageUpload::fitted($image, (int) self::side($path));
        $best = $out['before'];
        $made = 0;

        if ($need['display']) {
            $bytes = self::encode($display, self::QUALITY['display']);

            if ($bytes === null) {
                return ['status' => 'failed', 'reason' => 'encode'] + $out;
            }

            if (strlen($bytes) < $out['before'] * self::WORTH) {
                $out['display'] = strlen($bytes);
                $best = strlen($bytes);

                if ($write && ! self::put((string) self::display($path), $bytes, $display, $written)) {
                    return ['status' => 'failed', 'reason' => 'write'] + $out;
                }

                $made++;
            }
        }

        if ($need['thumb'] && max(imagesx($display), imagesy($display)) > self::THUMB) {
            $thumb = StoreImageUpload::fitted($display, self::THUMB);
            $bytes = self::encode($thumb, self::QUALITY['thumb']);

            if ($bytes === null) {
                return ['status' => 'failed', 'reason' => 'encode'] + $out;
            }

            if (strlen($bytes) < $best * self::WORTH) {
                $out['thumb'] = strlen($bytes);

                if ($write && ! self::put(self::path($path, self::THUMB), $bytes, $thumb, $written)) {
                    return ['status' => 'failed', 'reason' => 'write'] + $out;
                }

                $made++;
            }
        }

        if ($made === 0) {
            return ['status' => 'skipped', 'reason' => 'no_saving'] + $out;
        }

        return ['status' => $write ? 'made' : 'estimated'] + $out;
    }

    /**
     * فكُّ صورةٍ واحدٍ في الخادم كلِّه في كلّ لحظة.
     *
     * صورةُ هاتفٍ باثني عشر ميغابكسل تأخذ في فكّها نحو مئة ميغابايت وثانيةً
     * من معالجٍ واحد. وخادمٌ بغيغابايتٍ واحدة يبيع ويُرسل واتساب ويستقبل
     * الدفع في الوقت نفسه: رفعُ خمس صورٍ معًا، أو رفعٌ وجولةُ الصور القديمة،
     * يأخذ ذاكرتَه كلَّها. فتنتظر الصورةُ دورَها — والقفلُ في القاعدة فيرى
     * كلُّ عاملٍ ما يحمله غيرُه.
     *
     * ولا يُردّ رفعٌ لأنّ الدور طال: بعد خمس عشرة ثانيةً يُفكّ على أيّ حال.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function exclusive(callable $work): mixed
    {
        try {
            return Cache::lock('images:decode', 60)->block(15, $work);
        } catch (LockTimeoutException) {
            return $work();
        }
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
    private static function encode(\GdImage $image, int $quality): ?string
    {
        ob_start();

        try {
            $written = imagewebp($image, null, $quality);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $written && self::fits(@getimagesizefromstring($bytes), $image) ? $bytes : null;
    }

    /**
     * ينشر النسخة — وما نشره هو وحده يُمحى إن سقطت الجولة بعده.
     *
     * @param  list<string>  $written
     */
    private static function put(string $path, string $bytes, \GdImage $image, array &$written): bool
    {
        $done = self::publish($path, $bytes, $image);

        if (in_array($done, ['published', 'replaced'], true)) {
            $written[] = $path;
        }

        return $done !== 'failed';
    }

    /**
     * يضع النسخةَ باسمها كاملةً أو لا يضعها — ولا يمسّ نسخةً سليمةً سبقته.
     *
     * ═══ لمَ لا تُكتب باسمها مباشرةً ═══
     *
     * الكتابةُ تأخذ وقتًا، وعمليّةٌ تنقطع في منتصفها — إيقافٌ أو انهيارٌ أو
     * انقطاعُ كهرباء — تترك نصفَ ملفٍّ بالاسم الذي تقرؤه الصفحات: يُعرض للزبون
     * مكسورًا، والجولةُ التالية تراه موجودًا فلا تُصلحه. فتُكتب أوّلًا في ملفٍّ
     * مخفيٍّ إلى جوارها، وتُثبَّت على القرص (`fsync`)، وتُفحص بحجمها ورأسها
     * وأبعادها — ثمّ تأخذ اسمَها دفعةً واحدة.
     *
     * ═══ ولا تُستبدل سليمة ═══
     *
     * النشرُ رابطٌ صلب (`link`): يفشل إن سبقه ملفٌّ بالاسم، في اللحظة التي
     * يُنشأ فيها — فلا نافذةَ بين «هل هي موجودة؟» والكتابة. رفعٌ وجولةٌ يصنعان
     * النسخةَ نفسها معًا: الأوّلُ يُنشر، والثاني يجدها سليمةً فيتركها كما هي.
     * وما سبق ناقصًا — من قبل هذا الإصلاح — يُستبدل باسمه دفعةً واحدة (`rename`).
     *
     * @return 'published'|'kept'|'replaced'|'failed'
     */
    public static function publish(string $path, string $bytes, \GdImage $image): string
    {
        // ‏باسم نسخةٍ وحده — فلا يُكتب فوق أصلٍ ولو نودي باسمه
        if (! self::isVariant($path) || self::side(self::base($path)) === null) {
            return 'failed';
        }

        $disk = Storage::disk(self::DISK);
        $final = $disk->path($path);
        $tmp = dirname($path).'/.'.Str::random(12).'.'.basename($path);
        $staged = $disk->path($tmp);

        foreach (self::leftovers($final) as $old) {
            if ((int) @filemtime($old) < time() - self::STALE) {
                @unlink($old);
            }
        }

        try {
            if (! $disk->put($tmp, $bytes) || ! self::flush($staged)) {
                return 'failed';
            }

            clearstatcache(true, $staged);

            if ((int) @filesize($staged) !== strlen($bytes) || ! self::sound($staged) || ! self::fits(@getimagesize($staged), $image)) {
                return 'failed';
            }

            if (self::hardLinks() && @link($staged, $final)) {
                return 'published';
            }

            /*
             * الاسمُ مأخوذ، أو قرصٌ لا يعرف الروابط الصلبة: يُقرَّر تحت قفلٍ
             * على هذه النسخة وحدها، ويُسأل من جديد داخله. فكاتبان لا يقرّران
             * معًا: الأوّلُ ينشر أو يستبدل الناقصة، والثاني يجدها سليمةً
             * فيتركها. و`rename` يضع الملفَّ باسمه دفعةً واحدة — قارئٌ يرى
             * القديمةَ أو الجديدة، لا نصفًا.
             */
            return Cache::lock('images:publish:'.md5($path), 30)->block(10, function () use ($staged, $final) {
                clearstatcache(true, $final);
                $taken = is_file($final);

                if ($taken && self::sound($final)) {
                    return 'kept';
                }

                return @rename($staged, $final) ? ($taken ? 'replaced' : 'published') : 'failed';
            });
        } catch (LockTimeoutException) {
            return 'failed';
        } finally {
            @unlink($staged);
        }
    }

    /**
     * هل يُنشر برابطٍ صلب؟ — والدالّةُ قد تُعطَّل على خادم (`disable_functions`)،
     * والقرصُ قد لا يعرفها: فيُنشر بالقفل وحده.
     */
    private static function hardLinks(): bool
    {
        return function_exists('link') && (bool) config('images.hard_links', true);
    }

    /**
     * نسخةٌ كاملةٌ على القرص — من رأسها وحده، بلا فكّ.
     *
     * رأسُ WebP يكتب حجمَ الملفّ: ما قُطع في منتصفه أقصرُ ممّا يقوله رأسُه.
     * و`getimagesize` لا يرى ذلك — يقرأ الرأسَ ويصدّقه.
     */
    private static function sound(string $file): bool
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        $head = is_int($size) && $size >= 12 ? @file_get_contents($file, false, null, 0, 12) : false;

        return is_string($head) && strlen($head) === 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'
            && unpack('V', substr($head, 4, 4))[1] + 8 === $size;
    }

    /** يثبّت ما كُتب على القرص قبل أن يأخذ اسمه — فانقطاعُ الكهرباء لا يترك الاسمَ على فراغ */
    private static function flush(string $file): bool
    {
        $handle = @fopen($file, 'r+');

        if ($handle === false) {
            return false;
        }

        try {
            return ! function_exists('fsync') || @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * ملفّاتٌ مؤقّتةٌ لهذه النسخة إلى جوارها — مخفيّة، فلا يعدّها الجرد ولا تُعرض.
     *
     * @return list<string>
     */
    private static function leftovers(string $final): array
    {
        return function_exists('glob') ? (glob(dirname($final).'/.*.'.basename($final)) ?: []) : [];
    }

    private static function fits(array|false $info, \GdImage $image): bool
    {
        return is_array($info) && $info[2] === IMAGETYPE_WEBP && $info[0] === imagesx($image) && $info[1] === imagesy($image);
    }
}
