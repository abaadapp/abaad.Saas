<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
 * سطرَها وتكتبه تحت قفلٍ في القاعدة (`images:state`). وما لم يُكتب لأنّ القفل طال
 * لا يُسقط رفعًا ولا جولة — يُكتب في سجلّ الصور، والصورةُ تبقى تنتظر الجولةَ الكبرى.
 */
final class ImageState
{
    private const STATE = 'image-variants/state.json';

    private const BACKLOG = 'image-variants/backlog.json';

    /** أطولُ قائمة انتظار — رفعٌ متواصلٌ ومعالجةٌ موقوفةٌ أيّامًا لا تملأ القرص */
    public const MAX_WAITING = 10000;

    /** @return array<string, array{status: string, reason?: ?string, attempts?: int, skips?: list<string>, at: string}> */
    public static function all(): array
    {
        return self::read(self::STATE);
    }

    /** سطرُ صورةٍ في الحال — و`null` يمحوه (اكتملت) */
    public static function put(string $path, ?array $entry): void
    {
        self::update(self::STATE, function (array $rows) use ($path, $entry) {
            if ($entry === null) {
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

    /** تنتظر هذه الصورةُ نسخها — سطرٌ واحدٌ لها مهما تكرّر الطلب، ووقتُ وصولها الأوّل */
    public static function wait(string $path, string $reason): void
    {
        self::update(self::BACKLOG, function (array $rows) use ($path, $reason) {
            if (! isset($rows[$path]) && count($rows) >= self::MAX_WAITING) {
                return null;
            }

            $rows[$path] = ['since' => $rows[$path]['since'] ?? now()->toIso8601String(), 'reason' => $reason, 'attempts' => (int) ($rows[$path]['attempts'] ?? 0)];

            return $rows;
        });
    }

    /** لم تعد تنتظر — صُنعت، أو تُركت بسببٍ يُحفظ في الحال */
    public static function release(string $path): void
    {
        self::update(self::BACKLOG, function (array $rows) use ($path) {
            if (! isset($rows[$path])) {
                return null;
            }

            unset($rows[$path]);

            return $rows;
        });
    }

    /** محاولةٌ فشلت لصورةٍ تنتظر — ويُردّ عددُ محاولاتها */
    public static function retried(string $path, string $reason): int
    {
        $attempts = 0;

        self::update(self::BACKLOG, function (array $rows) use ($path, $reason, &$attempts) {
            if (! isset($rows[$path])) {
                return null;
            }

            $attempts = (int) $rows[$path]['attempts'] + 1;
            $rows[$path] = ['attempts' => $attempts, 'reason' => $reason] + $rows[$path];

            return $rows;
        });

        return $attempts;
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(string $file): array
    {
        $disk = Storage::disk('local');
        $raw = $disk->exists($file) ? $disk->get($file) : null;
        $rows = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($rows) ? $rows : [];
    }

    /** @param  callable(array): ?array  $change  — و`null` لا يكتب شيئًا */
    private static function update(string $file, callable $change): void
    {
        try {
            Cache::lock('images:state', 10)->block(5, function () use ($file, $change) {
                $rows = $change(self::read($file));

                if ($rows !== null) {
                    Storage::disk('local')->put($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            });
        } catch (LockTimeoutException) {
            try {
                Log::channel('images')->warning('images.state_busy', ['file' => $file]);
            } catch (\Throwable) {
                // ‏سطرُ السجلّ لا يستحقّ رفعًا مردودًا
            }
        }
    }
}
