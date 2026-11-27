<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ما عُرف عن صورٍ بعينها — على القرص الخاصّ، يكتبه كلُّ من يصنع النسخ تحت قفلٍ واحد.
 *
 * ═══ سجلّان ═══
 *
 *  • **الحال** (`state.json`): صورةٌ لا تُصنع لها نسخ (`skipped`)، أو فشلت وعددُ
 *    محاولاتها (`failed`)، أو صُنعت وتُرك منها ما لم يستحقّ مكانه (`made` و`skips`).
 *    والمكتملةُ لا سطرَ لها: نسخُها على القرص هي الدليل — فانقطاعٌ في منتصف
 *    جولةٍ لا يُضيع شيئًا، والجولةُ التالية تبدأ ممّا لم يُصنع.
 *  • **الانتظار** (`backlog.json`): صورٌ رُفعت والخادمُ مشغول أو المعالجةُ موقوفة —
 *    حُفظ أصلُها، وتُصنع نسخها في جولةٍ قصيرة بعد دقائق (`images:optimize --backlog`).
 *    سطرٌ واحدٌ لكلّ صورةٍ مهما رُفعت أو أُجّلت — فلا تتكرّر.
 *
 * ═══ ولا يُفقد سطرٌ بين كاتبين ═══
 *
 * رفعُ تاجرٍ وجولةُ الصور القديمة قد يكتبان معًا: كلُّ كتابةٍ تقرأ الملفَّ وتعدّل
 * سطرَها وتكتبه تحت قفلٍ في القاعدة (`images:state`). والكتابةُ إلى ملفٍّ مؤقّتٍ
 * بجواره ثمّ إعادةُ تسميةٍ تحلّ محلّه (`write`): كتابةٌ انقطعت تترك الملفَّ القديمَ
 * كاملًا، لا نصفَ ملف.
 *
 * ═══ وملفٌّ تالفٌ لا يُقرأ قائمةً فارغة ═══
 *
 * كان ما لا يُقرأ JSON يُعدّ «لا شيء»، فتكتب الكتابةُ التالية فوقه قائمةً من سطرٍ
 * واحد وتضيع الحالاتُ كلُّها. الآن يُحفظ كما هو إلى جواره (`.corrupt-…`)، ويُستنقذ
 * منه كلُّ سطرٍ سليم، ويُكتب في سجلّ الصور بمستوى خطأ (`quarantine`).
 *
 * ═══ ورفعٌ لا يُكتب انتظارُه لا يضيع ═══
 *
 * القائمةُ ملأى (`MAX_WAITING`)، أو القفلُ طال، أو القرصُ لم يكتب، أو القائمةُ تالفةٌ
 * لم يُستنقذ منها كلُّ شيء: يُكتب علَمٌ صغير (`missed.json`) — منذ متى فات رفعٌ،
 * وكم مرّة، ولماذا — بقفل ملفٍّ لا بالقاعدة التي قد يكون عطلُها السبب. وجولةُ
 * `--backlog` (كلَّ عشر دقائق، مفتوحةٌ ولو أُطفئت الصورُ القديمة) تستدرك من القرص
 * نفسه: كلُّ صورةٍ حيّةٍ أصلُها أحدثُ من أوّل ما فات وينقصها ما يستحقّ. فلا تمسّ
 * صورةً قديمة، ولا تعتمد على سطرٍ لم يُكتب.
 */
final class ImageState
{
    private const STATE = 'image-variants/state.json';

    private const BACKLOG = 'image-variants/backlog.json';

    /** رفعٌ لم يُكتب انتظارُه: منذ متى، وكم مرّة، ولماذا — يستدركه `images:optimize --backlog` */
    private const MISSED = 'image-variants/missed.json';

    /** أوّلُ صورةٍ انتظرت على هذا الخادم — حدُّ ما يُستدرك حين لا يُعرف أوّلُ ما فات */
    private const HORIZON = 'image-variants/horizon.json';

    /** أطولُ قائمة انتظار افتراضًا (`images.backlog_max`) — رفعٌ متواصلٌ ومعالجةٌ موقوفةٌ أيّامًا لا تملأ القرص */
    public const MAX_WAITING = 10000;

    /** @return array<string, array{status: string, reason?: ?string, attempts?: int, skips?: list<string>, at: string}> */
    public static function all(): array
    {
        return self::read(self::STATE);
    }

    /** سطرُ صورةٍ في الحال — و`null` يمحوه (اكتملت). و`false` حين لم يُكتب */
    public static function put(string $path, ?array $entry): bool
    {
        return self::update(self::STATE, function (array $rows) use ($path, $entry) {
            if ($entry === null) {
                if (! isset($rows[$path])) {
                    return null;
                }

                unset($rows[$path]);
            } else {
                $rows[$path] = $entry + ['at' => now()->toIso8601String()];
            }

            return $rows;
        });
    }

    /**
     * صورٌ تنتظر نسخها بترتيب وصولها.
     *
     * @return array<string, array{since: string, reason: string, attempts: int}>
     */
    public static function waiting(): array
    {
        return self::read(self::BACKLOG);
    }

    /**
     * تنتظر هذه الصورةُ نسخها — سطرٌ واحدٌ لها مهما تكرّر الطلب، ووقتُ وصولها الأوّل.
     *
     * و`false` حين لم يُكتب السطر: القائمةُ ملأى، أو القفلُ طال، أو القرصُ لم يكتب.
     * ولا تضيع الصورةُ بذلك — يُكتب أنّ رفعًا فات ومتى (`miss`)، فيستدركه `--backlog`.
     */
    public static function wait(string $path, string $reason): bool
    {
        $full = false;

        $kept = self::update(self::BACKLOG, function (array $rows) use ($path, $reason, &$full) {
            if (! isset($rows[$path]) && count($rows) >= self::limit()) {
                $full = true;

                return null;
            }

            // ‏وأوّلُ ما ينتظر على هذا الخادم يُكتب مرّةً — تحت القفل نفسه فلا يسبقه غيرُه،
            // ‏ولا يمنع سطرَ الصورة إن لم يُكتب
            if (! Storage::disk('local')->exists(self::HORIZON)) {
                try {
                    self::write(self::HORIZON, ['since' => self::mtime($path) ?? time()]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $rows[$path] = ['since' => $rows[$path]['since'] ?? now()->toIso8601String(), 'reason' => $reason, 'attempts' => (int) ($rows[$path]['attempts'] ?? 0)];

            return $rows;
        });

        if ($kept && ! $full) {
            return true;
        }

        self::miss($path, $full ? 'backlog_full' : 'backlog_unwritten');

        return false;
    }

    /** لم تعد تنتظر — صُنعت، أو تُركت بسببٍ يُحفظ في الحال. و`false` حين لم يُكتب */
    public static function release(string $path): bool
    {
        return self::update(self::BACKLOG, function (array $rows) use ($path) {
            if (! isset($rows[$path])) {
                return null;
            }

            unset($rows[$path]);

            return $rows;
        });
    }

    /** محاولةٌ فشلت لصورةٍ تنتظر — ويُردّ عددُ محاولاتها، أو `null` حين لم يُكتب */
    public static function retried(string $path, string $reason): ?int
    {
        $attempts = 0;

        $kept = self::update(self::BACKLOG, function (array $rows) use ($path, $reason, &$attempts) {
            if (! isset($rows[$path])) {
                return null;
            }

            $attempts = (int) $rows[$path]['attempts'] + 1;
            $rows[$path] = ['attempts' => $attempts, 'reason' => $reason] + $rows[$path];

            return $rows;
        });

        return $kept ? $attempts : null;
    }

    /**
     * أفات رفعٌ لم يُكتب انتظارُه؟ — منذ متى (ثانيةُ أقدم أصلٍ فات)، وكم مرّة، ولماذا.
     *
     * وعلَمٌ لا يُقرأ يُعدّ فواتًا لا يُعرف أوّلُه: من أوّل ما انتظر على هذا الخادم.
     *
     * @return array{since: int, count: int, reasons: array<string, int>, at: ?string}|null
     */
    public static function missed(): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::MISSED)) {
            return null;
        }

        $row = json_decode((string) self::raw(self::MISSED, quiet: true), true);

        if (is_array($row) && is_int($row['since'] ?? null) && is_int($row['count'] ?? null)) {
            return ['since' => $row['since'], 'count' => $row['count'], 'reasons' => (array) ($row['reasons'] ?? []), 'at' => $row['at'] ?? null];
        }

        return ['since' => self::horizon() ?? (int) @filemtime($disk->path(self::MISSED)), 'count' => -1, 'reasons' => ['marker_corrupt' => 1], 'at' => null];
    }

    /**
     * استُدرك ما فات حتى العدّ `$count` — يُمحى العلَم. وإن فات رفعٌ آخرُ أثناء
     * الاستدراك تغيّر العدّ فيبقى العلَم للجولة التالية.
     */
    public static function recovered(int $count): bool
    {
        try {
            return self::exclusively(function () use ($count) {
                $was = self::missed();

                if ($was !== null && $was['count'] !== $count) {
                    return false;
                }

                return $was === null || Storage::disk('local')->delete(self::MISSED);
            });
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** أوّلُ ما انتظر على هذا الخادم (ثانية) — أو `null` */
    public static function horizon(): ?int
    {
        $row = json_decode((string) self::raw(self::HORIZON, quiet: true), true);

        return is_int($row['since'] ?? null) ? $row['since'] : null;
    }

    /**
     * يُكتب أنّ رفعًا فات انتظارُه — وأقدمُ ما فات يبقى أوّلَ العلَم.
     *
     * وبقفل ملفٍّ على القرص نفسه: قفلُ القاعدة الذي طال قد يكون هو السبب. ولا يُسقط
     * شيئًا — ما لا يُكتب هنا يُبلَّغ به.
     */
    private static function miss(?string $path, string $reason, ?int $since = null): void
    {
        try {
            $since ??= ($path === null ? null : self::mtime($path)) ?? time();

            self::exclusively(function () use ($reason, $since) {
                $was = self::missed();
                $reasons = $was['reasons'] ?? [];
                $reasons[$reason] = (int) ($reasons[$reason] ?? 0) + 1;

                if (! self::write(self::MISSED, [
                    'since' => min($since, $was['since'] ?? $since),
                    'count' => max(0, $was['count'] ?? 0) + 1,
                    'reasons' => $reasons,
                    'at' => now()->toIso8601String(),
                ])) {
                    throw new \RuntimeException('لم يُكتب علَمُ ما فات من الرفع');
                }
            });

            self::log('warning', 'images.upload_missed', array_filter(['path' => $path, 'reason' => $reason]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** ثانيةُ تعديل الأصل — والأصلُ يُحفظ قبل أن تُطلب نسخُه، فهي ثانيةُ رفعه */
    private static function mtime(string $path): ?int
    {
        $file = Storage::disk('public')->path($path);

        return is_file($file) ? min(time(), (int) filemtime($file)) : null;
    }

    private static function limit(): int
    {
        return max(1, (int) config('images.backlog_max', self::MAX_WAITING));
    }

    /**
     * للقراءة: ما في الملفّ — وتالفٌ يُقرأ منه ما سلم، لا قائمةً فارغة.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function read(string $file): array
    {
        $raw = self::raw($file, quiet: true);

        return $raw === null ? [] : (self::decode($raw) ?? self::salvage($raw));
    }

    /**
     * محتوى الملفّ، أو `null` حين لا يوجد. وموجودٌ لا يُقرأ يرمي — لا يُعدّ غائبًا
     * فتُكتب فوقه قائمةٌ جديدة. و`quiet` للقراءة وحدها: لا شيءَ يُكتب بعدها.
     */
    private static function raw(string $file, bool $quiet = false): ?string
    {
        $full = Storage::disk('local')->path($file);

        if (! is_file($full)) {
            return null;
        }

        $raw = @file_get_contents($full);

        if (! is_string($raw)) {
            if ($quiet) {
                return null;
            }

            throw new \RuntimeException('لا يُقرأ '.$file);
        }

        return $raw;
    }

    /**
     * سطورُ الملفّ — أو `null` حين لا يُقرأ قائمةً سطورُها كائنات.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function decode(string $raw): ?array
    {
        $rows = json_decode($raw, true);

        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $key => $row) {
            if (! is_string($key) || ! is_array($row)) {
                return null;
            }
        }

        return $rows;
    }

    /**
     * كلُّ سطرٍ سليمٍ في ملفٍّ تالف — السطورُ كائناتٌ مسطّحة (`skips` مصفوفةٌ بلا أقواس معقوفة)،
     * فسطرٌ قُطع في منتصفه لا يُطابَق ويُترك.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function salvage(string $raw): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)+)"\s*:\s*(\{[^{}]*\})/u', $raw, $found, PREG_SET_ORDER);
        $rows = [];

        foreach ($found as [, $key, $row]) {
            $key = json_decode('"'.$key.'"');
            $row = json_decode($row, true);

            if (is_string($key) && is_array($row)) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * ملفٌّ لا يُقرأ: يُحفظ كما هو إلى جواره قبل أن يُكتب فوقه شيء، ويُستنقذ منه كلُّ
     * سطرٍ سليم، ويُكتب في السجلّ بمستوى خطأ. ولا يُكتب فوقه ما لم تُحفظ نسختُه.
     *
     * وما لم يُستنقذ من قائمة الانتظار لا يُعرف — فيُكتب أنّ رفعًا فات منذ أوّل ما
     * انتظر على هذا الخادم، ويستدركه `--backlog` من القرص.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function quarantine(string $file, string $raw): array
    {
        $disk = Storage::disk('local');
        $copy = $file.'.corrupt-'.substr(md5($raw), 0, 12);

        if (! $disk->exists($copy) && ! $disk->put($copy, $raw)) {
            throw new \RuntimeException('لم تُحفظ نسخةُ '.$file.' التالفة — لا يُكتب فوقه');
        }

        $rows = self::salvage($raw);
        self::log('error', 'images.state_corrupt', ['file' => $file, 'kept_as' => $copy, 'bytes' => strlen($raw), 'salvaged' => count($rows)]);

        if ($file === self::BACKLOG) {
            $since = array_filter(array_map(fn ($r) => is_string($r['since'] ?? null) ? strtotime($r['since']) : false, $rows));
            self::miss(null, 'backlog_corrupt', self::horizon() ?? ($since === [] ? (int) @filemtime($disk->path($file)) : min($since)));
        }

        return $rows;
    }

    /** @param  callable(array): ?array  $change  — و`null` لا يكتب شيئًا. و`false` حين لم يُكتب ما طُلب */
    private static function update(string $file, callable $change): bool
    {
        try {
            return Cache::lock('images:state', 10)->block(5, function () use ($file, $change) {
                $raw = self::raw($file);
                $rows = $raw === null ? [] : self::decode($raw);
                $broken = $rows === null;
                $rows ??= self::quarantine($file, (string) $raw);
                $changed = $change($rows);

                // ‏وتالفٌ يُكتب بما استُنقذ منه ولو لم يتغيّر شيء — فلا يُعزل في كلّ كتابة
                if ($changed === null && ! $broken) {
                    return true;
                }

                return self::write($file, $changed ?? $rows);
            });
        } catch (LockTimeoutException) {
            self::log('warning', 'images.state_busy', ['file' => $file]);
        } catch (\Throwable $e) {
            report($e);
        }

        return false;
    }

    /**
     * يُكتب ملفٌّ مؤقّتٌ مخفيٌّ بجواره ثمّ يحلّ محلّه بإعادة تسمية — والقارئ يرى
     * القديمَ كاملًا أو الجديدَ كاملًا. وكتابةٌ لم تكتمل لا تُسمّى، ويُمحى ما تركته.
     */
    private static function write(string $file, array $rows): bool
    {
        $disk = Storage::disk('local');
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = dirname($file).'/.'.basename($file).'.'.Str::random(12);

        try {
            return $disk->put($tmp, $json) && $disk->size($tmp) === strlen($json) && $disk->move($tmp, $file);
        } finally {
            if (is_file($disk->path($tmp))) {
                @unlink($disk->path($tmp));
            }
        }
    }

    /** قفلُ ملفٍّ على القرص الخاصّ — لا يحتاج القاعدةَ ولا الذاكرةَ المؤقّتة */
    private static function exclusively(callable $work): mixed
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('image-variants');
        $handle = fopen($disk->path('image-variants/.missed.lock'), 'c');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new \RuntimeException('لا يُقفل علَمُ ما فات من الرفع');
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** سطرٌ في سجلّ الصور — والسجلُّ لا يستحقّ رفعًا مردودًا */
    private static function log(string $level, string $message, array $context): void
    {
        try {
            Log::channel('images')->{$level}($message, $context);
        } catch (\Throwable) {
            // ‏لا شيء
        }
    }
}
