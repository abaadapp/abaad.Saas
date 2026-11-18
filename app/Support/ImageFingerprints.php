<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * بصمةُ الصورة — لتُعرف الصورةُ نفسُها إن رُفعت ثانيةً في المتجر نفسه.
 *
 * ═══ «نفسُها» لا «تشبهها» ═══
 *
 *  • **مطابقةٌ تمامًا** (`EXACT`): بصمةُ SHA-256 لبايتات الملفّ كما وصل الخادم،
 *    أو لأصله قبل أن يصغّره المتصفّح (يحسبها المتصفّحُ قبل التصغير ويرسلها).
 *  • **الصورةُ نفسُها بصيغةٍ أخرى** (`PIXELS`): ملفّان مختلفان بكسلاتُهما واحدةٌ
 *    كلُّها بعد إدارة الاتّجاه — PNG وWebP بلا فقد، أو JPEG نُزع رأسُه. البصمةُ
 *    على البكسلات كلِّها بحجمها الأصليّ، لا على مصغّرة: صورتان تختلفان في بكسلٍ
 *    واحد بصمتاهما مختلفتان.
 *  • **ما يشبهها** — صورةٌ أُعيد ضغطُها أو صُغّرت أو عُدّل لونُها — ليست هي،
 *    ولا يُفعل بها شيء. فلا تحلّ صورةٌ محلَّ أخرى بتقدير.
 *
 * ═══ البصمةُ لما رُفع، لا لما حُفظ ═══
 *
 * البصمتان تُحسبان لما وصل من المتجر قبل أيّ ضغط: بايتاتُه، وبكسلاتُه بعد إدارة
 * الاتّجاه. وما يُحفظ على القرص شيءٌ آخر، ويُكتب في سطره (`stored`):
 *  • `as_uploaded` — الملفُّ كما رُفع بايتًا ببايت (لم يُضغط: GIF، أو لم يصغر،
 *    أو لم تكفِ الذاكرة);
 *  • `converted` — نسخةُ عرضٍ أُعيد ترميزُها (WebP بجودة 80 للمنتج، وصورةُ الموقع
 *    بلا رأسها): **فاقدةٌ للجودة**، فلا يُقال إنّ ملفَّها يطابق ما رُفع، ولا إنّ
 *    بكسلاتِها هي بكسلاتُه. يُقال إنّ الصورة رُفعت من قبل، وإنّ نسختَها المحفوظة
 *    استُعملت.
 *
 * ═══ في المتجر نفسه، وفي مجلّده ═══
 *
 * لكلّ متجرٍ فهرسُه في القرص الخاصّ (`image-fingerprints/{id}/index.json`)، فلا
 * يسأل متجرٌ عن صور غيره ولا يعرف أنّها موجودة. وصورةُ منتجٍ لا تُطابَق إلّا بصورة
 * منتجٍ في متجرها (`products/`)، وصورةُ الموقع بما في مجلّد موقعه (`website/{id}/`):
 * لكلٍّ منهما قاعدةُ حذفٍ غيرُ الأخرى.
 *
 * وكلُّ سطرٍ في الفهرس يُسأل قبل أن يُعتمد: الملفُّ على القرص، صورةٌ تُقرأ،
 * ويشير إليه صفٌّ في المتجر نفسه — فسطرٌ لصورةٍ حُذفت يُطرح ولا يُعاد استعمالُه.
 *
 * ═══ والفهرسُ لا يُسقط رفعًا ═══
 *
 * هو دليلٌ لا مصدرُ حقيقة: يُكتب بقفل ملفٍّ (لا بالقاعدة) ثمّ إلى ملفٍّ مؤقّتٍ
 * فإعادةِ تسمية. وقفلٌ مشغول، أو قرصٌ لا يكتب، أو ملفٌّ تالف: لا يُكتب السطرُ
 * ويُحفظ الرفعُ كما كان يُحفظ قبل البصمة — وأسوأُ ما يقع نسخةٌ مكرّرة، لا صورةٌ ضائعة.
 */
final class ImageFingerprints
{
    public const EXACT = 'exact';

    public const PIXELS = 'pixels';

    /** مجلّدا الصور اللذان يُطابَق فيهما */
    public const PRODUCTS = 'products';

    public const WEBSITE = 'website';

    private const DISK = 'local';

    private const ROOT = 'image-fingerprints';

    /** أقصى سطور الفهرس لمتجر — والأقدمُ يُطرح أوّلًا */
    private const MAX_ROWS = 20000;

    /**
     * بصمةُ ما وصل: بايتاتُه، وتلميحُ أصله من المتصفّح، وأبعادُه.
     *
     * @return array{sha: ?string, orig: ?string, px: ?string, w: int, h: int, bytes: int}
     */
    public static function of(string $file, mixed $original = null): array
    {
        $sha = @hash_file('sha256', $file);
        $info = @getimagesize($file);

        return [
            'sha' => is_string($sha) ? $sha : null,
            'orig' => self::hint($original),
            'px' => null,
            'w' => is_array($info) ? (int) $info[0] : 0,
            'h' => is_array($info) ? (int) $info[1] : 0,
            'bytes' => (int) @filesize($file),
        ];
    }

    /** تلميحُ المتصفّح يُقبل بشكله الدقيق وحده — وما سواه لا شيء */
    public static function hint(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }

    /**
     * بصمةُ البكسلات — كلِّها، بعد إدارة الاتّجاه وقبل التصغير.
     *
     * تُكتب البكسلاتُ PNG بلا ضغطٍ ولا مرشّح (RGBA كما هي، فلا تختلف كتابتان
     * لبكسلاتٍ واحدة) إلى ملفٍّ مؤقّت — فلا تُحمَل الصورةُ مرّةً ثانية في الذاكرة —
     * ثمّ تُقرأ بصمتُه بـ`xxh128`: المقارنةُ بين صور متجرٍ واحد، فلا حاجة إلى
     * بصمةٍ تشفيريّة، وهي أسرعُ بعشرة أضعاف (قيس: 6 ميغابكسل 110ms لا 160ms).
     * والطريقةُ تُكتب قبل البصمة: بصمتان بطريقتين لا تُقارَنان.
     *
     * وما فوق `images.fingerprint_max_megapixels` (4) لا بصمةَ لبكسلاته (كتابتُه
     * زمنٌ وقرص)، وتبقى له البصمةُ التامّة. وصورُ الهاتف تصل مصغَّرةً إلى 1600
     * أصلًا (نحو 2 ميغابكسل — قرابةَ 50ms).
     */
    public static function pixels(\GdImage $image): ?string
    {
        $pixels = imagesx($image) * imagesy($image);

        if ($pixels < 1 || $pixels > (float) config('images.fingerprint_max_megapixels', 4) * 1_000_000) {
            return null;
        }

        // ‏وخادمٌ مشغول (ذاكرةٌ أو حِملٌ أو طوابير — `ImageGate`) لا يُثقَل بها: هي زيادةٌ لا ضرورة،
        // وتبقى المطابقةُ التامّة. فلا ينتظر صندوقٌ ولا دفعةٌ رفعَ صورة
        if (ImageGate::blocked() !== null) {
            return null;
        }

        $tmp = @tempnam(sys_get_temp_dir(), 'abaad-px-');

        if (! is_string($tmp)) {
            return null;
        }

        try {
            $copy = imageistruecolor($image) ? $image : self::truecolor($image);
            imagesavealpha($copy, true);

            if (! @imagepng($copy, $tmp, 0, PNG_NO_FILTER)) {
                return null;
            }

            $hash = @hash_file('xxh128', $tmp);

            return is_string($hash) ? 'png0-xxh128:'.$hash : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * أوّلُ صورةٍ في المتجر هي هذه — أو `null`.
     *
     * @param  self::PRODUCTS|self::WEBSITE  $folder
     * @param  array{sha?: ?string, orig?: ?string, px?: ?string, w?: int, h?: int}  $print
     * @return array{path: string, kind: string, stored: string}|null
     */
    public static function find(int $businessId, string $folder, array $print): ?array
    {
        try {
            $rows = self::read($businessId);
            $gone = [];
            $hit = null;

            foreach ($rows as $path => $row) {
                $kind = self::same($row, $print);

                if ($kind === null || ! self::inFolder($businessId, $folder, $path)) {
                    continue;
                }

                if (! self::usable($businessId, $folder, $path)) {
                    $gone[] = $path;

                    continue;
                }

                $hit = ['path' => $path, 'kind' => $kind, 'stored' => (string) ($row['stored'] ?? 'converted')];

                // ‏ومطابقةٌ تامّةٌ تُقدَّم على مطابقة البكسلات
                if ($kind === self::EXACT) {
                    break;
                }
            }

            if ($gone !== []) {
                self::change($businessId, function (array $rows) use ($gone) {
                    return array_diff_key($rows, array_flip($gone));
                });
            }

            return $hit ?? self::byBytes($businessId, $folder, $print);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * يُكتب سطرُ صورةٍ حُفظت — ولا يُرمى شيءٌ إلى من حفظها.
     *
     * @param  array{sha?: ?string, orig?: ?string, px?: ?string, w?: int, h?: int}  $print
     */
    public static function remember(int $businessId, string $path, array $print, string $stored = 'converted'): bool
    {
        if (($print['sha'] ?? null) === null && ($print['px'] ?? null) === null) {
            return false;
        }

        $row = array_filter([
            'sha' => $print['sha'] ?? null,
            'orig' => ($print['orig'] ?? null) !== ($print['sha'] ?? null) ? ($print['orig'] ?? null) : null,
            'px' => $print['px'] ?? null,
            'w' => (int) ($print['w'] ?? 0) ?: null,
            'h' => (int) ($print['h'] ?? 0) ?: null,
            // ‏ما على القرص: الملفُّ كما رُفع، أو نسخةُ عرضٍ فاقدة (`converted`)
            'stored' => $stored === 'as_uploaded' ? 'as_uploaded' : 'converted',
            'at' => time(),
        ], fn ($v) => $v !== null);

        return self::change($businessId, function (array $rows) use ($path, $row) {
            unset($rows[$path]);
            $rows[$path] = $row;

            return count($rows) > self::MAX_ROWS ? array_slice($rows, -self::MAX_ROWS, null, true) : $rows;
        });
    }

    /* ═══════════ المطابقة ═══════════ */

    /** هل هذا السطرُ هذه الصورة؟ وبأيّ معنى */
    private static function same(array $row, array $print): ?string
    {
        $sha = $print['sha'] ?? null;
        $orig = $print['orig'] ?? null;
        $px = $print['px'] ?? null;

        if ($sha !== null && ($row['sha'] ?? null) === $sha) {
            return self::EXACT;
        }

        /*
         * وأصلٌ قبل التصغير يقوله المتصفّح: لا يتحقّق منه الخادم، فلا يُعتمد إلّا
         * بأبعادٍ هي أبعادُ ما وصل يومها — والمطابقةُ في متجر صاحبه وحده، فمن كتب
         * تلميحًا كاذبًا لم يرَ إلّا صورةً من صوره.
         */
        if ($orig !== null && in_array($orig, [$row['orig'] ?? null, $row['sha'] ?? null], true)
            && (int) ($row['w'] ?? 0) === (int) ($print['w'] ?? -1) && (int) ($row['h'] ?? 0) === (int) ($print['h'] ?? -1)) {
            return self::EXACT;
        }

        return $px !== null && ($row['px'] ?? null) === $px ? self::PIXELS : null;
    }

    /**
     * صورةٌ قديمةٌ رُفعت قبل البصمة وحُفظت كما رُفعت: حجمُها أوّلًا، ثمّ بصمتُها.
     *
     * لا يُفكّ شيء ولا يُقرأ إلّا ما حجمُه حجمُ ما وصل — فمتجرٌ بألف صورة يُسأل
     * عن أحجامها وحدها.
     */
    private static function byBytes(int $businessId, string $folder, array $print): ?array
    {
        $sha = $print['sha'] ?? null;
        $disk = Storage::disk('public');

        if ($sha === null) {
            return null;
        }

        foreach (self::candidates($businessId, $folder) as $path) {
            $full = $disk->path($path);
            $size = @filesize($full);

            if (! is_int($size) || $size < 1 || $size !== self::sizeOf($print)) {
                continue;
            }

            // ‏وهنا وحدها يطابق الملفُّ المحفوظُ ما رُفع بايتًا ببايت
            if (@hash_file('sha256', $full) === $sha && self::usable($businessId, $folder, $path)) {
                return ['path' => $path, 'kind' => self::EXACT, 'stored' => 'as_uploaded'];
            }
        }

        return null;
    }

    /** حجمُ ما وصل — يُحمَل في البصمة لمن يقارن بالبايتات */
    private static function sizeOf(array $print): int
    {
        return (int) ($print['bytes'] ?? -1);
    }

    /**
     * صورُ المتجر في هذا المجلّد كما يُشار إليها اليوم.
     *
     * @return list<string>
     */
    private static function candidates(int $businessId, string $folder): array
    {
        if ($folder === self::WEBSITE) {
            return array_values(array_filter(
                Storage::disk('public')->files('website/'.$businessId),
                fn ($p) => preg_match('/\.w\d+\.webp$/', $p) !== 1 && ! str_starts_with(basename($p), '.'),
            ));
        }

        return DB::table('products')->where('business_id', $businessId)->whereNotNull('image')
            ->where('image', 'not like', 'http%')->pluck('image')
            ->merge(DB::table('product_images')->where('business_id', $businessId)->pluck('path'))
            ->filter(fn ($p) => is_string($p) && str_starts_with($p, 'products/'))
            ->unique()->values()->all();
    }

    private static function inFolder(int $businessId, string $folder, string $path): bool
    {
        return $folder === self::WEBSITE
            ? str_starts_with($path, 'website/'.$businessId.'/')
            : str_starts_with($path, 'products/');
    }

    /**
     * أيُستعمل هذا الملفّ لهذا المتجر؟ — على القرص، صورةٌ تُقرأ، ولصورة المنتج
     * منتجٌ حيٌّ في المتجر نفسه يعرضها.
     *
     * ومنتجٌ في سلّة المحذوفات لا تُعرض صورتُه لغيره: يُمحى نهائيًّا بعدُ، ومحوُه
     * يمحو ما لا يعرضه غيرُه (`ProductImages::files`) — فلا تُبنى صورةٌ جديدةٌ على
     * ملفٍّ ينتظر المحو.
     */
    private static function usable(int $businessId, string $folder, string $path): bool
    {
        $full = Storage::disk('public')->path($path);

        if (! is_file($full) || (int) @filesize($full) < 1 || ! is_array(@getimagesize($full))) {
            return false;
        }

        if ($folder === self::WEBSITE) {
            return true;
        }

        return DB::table('products')->where('business_id', $businessId)->whereNull('deleted_at')->where('image', $path)->exists()
            || DB::table('product_images')->join('products', 'products.id', '=', 'product_images.product_id')
                ->where('product_images.business_id', $businessId)->whereNull('products.deleted_at')
                ->where('product_images.path', $path)->exists();
    }

    /* ═══════════ الحجز: ملفٌّ يُعاد استعمالُه لا يُمحى قبل أن تُكتب صفوفُه ═══════════ */

    /**
     * يحجز ملفًّا موجودًا لرفعٍ سيعيد استعمالَه — ويتأكّد أنّه ما زال على القرص.
     *
     * بين أن يجد الرفعُ الصورةَ وأن تُكتب صفوفُه، قد يحذفها التاجرُ من منتجها
     * الأخير في تبويبٍ آخر — وصفُّ الرفع لم يُكتب بعد، فلا يراه الحذفُ مرجعًا،
     * فيُمحى الملفّ ويبقى المنتجُ الجديد بصورةٍ مكسورة. فالرفعُ يحجز الملفّ أوّلًا،
     * والحذفُ لا يمحو محجوزًا (`unlessClaimed`)، والاثنان تحت قفلٍ واحد.
     * و`false`: لم يُحجز (مُحي، أو القفلُ مشغول) — فيُحفظ للرفع ملفُّه.
     */
    public static function claim(string $path): bool
    {
        return self::striped($path, function () use ($path) {
            $full = Storage::disk('public')->path($path);

            if (! is_file($full) || (int) @filesize($full) < 1) {
                return false;
            }

            return Storage::disk(self::DISK)->put(self::claimOf($path), (string) time());
        }) === true;
    }

    /**
     * يُرفع الحجز — بعد أن كُتبت صفوفُ الرفع أو سقط.
     *
     * @param  list<string>  $paths
     */
    public static function settle(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            self::striped($path, fn () => Storage::disk(self::DISK)->delete(self::claimOf($path)));
        }
    }

    /**
     * يمحو بـ`$delete` ما لم يحجزه رفعٌ جارٍ — و`$still` يُسأل تحت القفل نفسه:
     * أما زال الملفُّ لا يعرضه أحد؟ (صفُّ الرفع قد كُتب بين السؤال الأوّل والقفل.)
     * وقفلٌ مشغولٌ أو حجزٌ قائم: لا محو — ملفٌّ زائدٌ خيرٌ من صورةٍ مكسورة.
     */
    public static function unlessClaimed(string $path, callable $delete, ?callable $still = null): bool
    {
        return self::striped($path, function () use ($path, $delete, $still) {
            if (self::claimed($path)) {
                self::note('images.delete_kept_claimed', null);

                return false;
            }

            if ($still !== null && ! $still()) {
                return false;
            }

            $delete();

            return true;
        }) === true;
    }

    /** حجزٌ قائمٌ لم تمضِ عليه عشرُ دقائق — وما بعدها رفعٌ مات ولم يرفع حجزه */
    private static function claimed(string $path): bool
    {
        $full = Storage::disk(self::DISK)->path(self::claimOf($path));
        $at = is_file($full) ? (int) @file_get_contents($full) : 0;

        return $at > 0 && time() - $at < 600;
    }

    private static function claimOf(string $path): string
    {
        return self::ROOT.'/claims/'.sha1($path);
    }

    /**
     * يعمل تحت قفل ملفٍّ لهذا المسار — أربعةٌ وستّون قفلًا للمسارات كلِّها، فلا
     * يتراكم ملفُّ قفلٍ لكلّ صورة. ولا يُنتظر أكثرَ من ثانيتين؛ و`null` حين لم يُقفل.
     */
    private static function striped(string $path, callable $work): mixed
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::ROOT.'/claims');
        $handle = @fopen($disk->path(self::ROOT.'/claims/.lock-'.(crc32($path) % 64)), 'c');

        if ($handle === false) {
            return null;
        }

        try {
            return self::locked($handle) ? $work() : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /* ═══════════ الفهرس ═══════════ */

    private static function file(int $businessId): string
    {
        return self::ROOT.'/'.$businessId.'/index.json';
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(int $businessId): array
    {
        $full = Storage::disk(self::DISK)->path(self::file($businessId));
        $raw = is_file($full) ? @file_get_contents($full) : false;
        $rows = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($rows) ? array_filter($rows, fn ($row, $path) => is_string($path) && is_array($row), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * يُبدَّل الفهرسُ تحت قفل ملفٍّ لمتجره، إلى ملفٍّ مؤقّتٍ ثمّ إعادةِ تسمية.
     *
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    private static function change(int $businessId, callable $change): bool
    {
        $disk = Storage::disk(self::DISK);
        $dir = self::ROOT.'/'.$businessId;
        $handle = false;

        try {
            $disk->makeDirectory($dir);
            $handle = @fopen($disk->path($dir.'/.lock'), 'c');

            if ($handle === false || ! self::locked($handle)) {
                self::note('images.fingerprint_busy', $businessId);

                return false;
            }

            $file = self::file($businessId);
            $full = $disk->path($file);
            $raw = is_file($full) ? @file_get_contents($full) : '';
            $rows = $raw === '' || $raw === false ? [] : json_decode((string) $raw, true);

            // ‏وفهرسٌ تالف يُحفظ إلى جواره ويُبدأ من جديد — هو دليلٌ، وما فيه يُستعاد بالرفع
            if (! is_array($rows)) {
                @copy($full, $full.'.corrupt-'.substr(md5((string) $raw), 0, 12));
                self::note('images.fingerprint_corrupt', $businessId);
                $rows = [];
            }

            $json = json_encode($change($rows), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $tmp = $disk->path($dir.'/.index.'.Str::random(12));

            try {
                return @file_put_contents($tmp, $json) === strlen($json) && @rename($tmp, $full);
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return false;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** قفلٌ لا ينتظر طويلًا: رفعٌ لا يقف على فهرس */
    private static function locked($handle): bool
    {
        $until = microtime(true) + max(0, (int) config('images.fingerprint_lock_ms', 2000)) / 1000;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            usleep(20_000);
        } while (microtime(true) < $until);

        return false;
    }

    /** نسخةٌ بألوانها كاملةً لصورةٍ ذات لوح — فلا تختلف بصمةُ صورتين بكسلاتُهما واحدة */
    private static function truecolor(\GdImage $image): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    private static function note(string $message, ?int $businessId): void
    {
        try {
            Log::channel('images')->warning($message, array_filter(['business' => $businessId]));
        } catch (\Throwable) {
            // ‏لا شيء
        }
    }
}
