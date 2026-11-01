<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * نقلُ مراجع صورةٍ إلى نسختها الأخفّ — بسجلٍّ يُكتب قبل أيّ تغيير.
 *
 * ═══ لمَ ═══
 *
 * النسخةُ إلى جوار الأصل تُخفّف ما يُنزله الزائر، لكنّ القرصَ يحمل الاثنين.
 * ولا يُستغنى عن أصلٍ يشير إليه صفّ. فتُنقل المراجعُ الحيّة — عمودُ المنتج،
 * وصورُ معرضه، وبطاقةُ القسم، وروابطُ الموقع في الإعدادات — ثمّ يُسأل: هل
 * بقي ما يشير إلى الأصل؟
 *
 * ═══ والسجلُّ قبل القاعدة ═══
 *
 * لو غُيّرت الصفوفُ ثمّ كُتب السجلّ، لَترك قرصٌ ممتلئ أو خادمٌ انقطع صفوفًا
 * تغيّرت ولا شيءَ يقول كيف كانت. فالترتيبُ أربعُ خطوات:
 *
 *  1. **الخطّة** (`plan`): يُقرأ كلُّ صفٍّ سيتغيّر، قبله وبعده.
 *  2. **السجلّ** (`journal`): يُكتب «معلّقًا» في ملفٍّ مؤقّتٍ ويُدفع إلى القرص
 *     (`fsync`) ثمّ يُسمّى باسمه (`rename` ذرّيّ) ويُقرأ ويُطابَق. فإن تعذّر
 *     شيءٌ من ذلك وقف كلُّ شيءٍ ولم يُمسّ صفّ.
 *  3. **التطبيق** (`apply`): معاملةٌ واحدة تقفل الصفوف، وكلُّ صفٍّ يجب أن يكون
 *     كما قرأته الخطّة حرفًا بحرف — وإلّا رُجع عن المعاملة كلِّها. فلا نصفَ نقل.
 *  4. **الختم** (`seal`): يُكتب السجلُّ «تمّ».
 *
 * وما انقطع بين خطوةٍ وأخرى يبقى سجلُّه «معلّقًا»، و`recover` يقرأ الصفوف
 * ويقول أين وقف: كلُّها على «بعد» ← تمّ، كلُّها على «قبل» ← لم يقع. والرجوعُ
 * (`rollback`) يصلح للحالين: يُعيد ما كان على «بعد» وحده، ولا يكتب فوق صفٍّ
 * غيّره صاحبُه.
 *
 * ═══ وما يُحرس غيرُ ذلك ═══
 *
 *  • لا يُنقل مرجعٌ إلى نسخةٍ لا تُقرأ من القرص صورةً بأبعادها.
 *  • يُستبدل اسمُ الملفّ وحده في نصّ الصفّ — اسمٌ عشوائيٌّ بأربعين حرفًا لا
 *    يتكرّر — فيُصاب بشرطةٍ مهرَّبةٍ في JSON أو بدونها، ولا يُمسّ غيرُه.
 *  • متجرٌ واحدٌ في كلّ مرّة: صفوفُ غيره لا تُقرأ.
 *  • الأصلُ لا يُمحى هنا أبدًا.
 */
final class ImageSwap
{
    private const LOG = 'image-swaps';

    public const PENDING = 'pending';

    public const DONE = 'committed';

    public const ABORTED = 'aborted';

    public const ROLLED_BACK = 'rolled_back';

    /**
     * ينقل مراجعَ هذه الأصول في متجرٍ واحد.
     *
     * @param  list<string>  $originals
     * @return array{run: ?string, state: string, swapped: list<string>, refused: array<string, string>, rows: int, error: ?string}
     */
    public static function swap(int $businessId, array $originals): array
    {
        $plan = self::plan($businessId, $originals);
        $out = ['run' => null, 'state' => 'nothing', 'swapped' => [], 'refused' => $plan['refused'], 'rows' => 0, 'error' => null];

        if ($plan['entries'] === []) {
            return $out;
        }

        $run = now()->format('Ymd-His').'-'.$businessId.'-'.Str::lower(Str::random(6));

        try {
            self::journal($run, $businessId, $plan['entries']);
        } catch (\Throwable $e) {
            report($e);

            return ['state' => 'journal_failed', 'error' => $e->getMessage()] + $out;
        }

        try {
            self::apply($businessId, $plan['entries']);
        } catch (\Throwable $e) {
            report($e);
            self::trySeal($run, self::ABORTED, $e->getMessage());

            return ['run' => $run, 'state' => self::ABORTED, 'error' => $e->getMessage()] + $out;
        }

        MarketingSettings::forget($businessId);
        $sealed = self::trySeal($run, self::DONE);

        return ['run' => $run, 'state' => $sealed ? self::DONE : self::PENDING, 'swapped' => $plan['swapped'],
            'rows' => count($plan['entries']), 'error' => $sealed ? null : 'unsealed'] + $out;
    }

    /**
     * الخطّة: كلُّ صفٍّ حيٍّ سيتغيّر في هذا المتجر — قبله وبعده. لا يكتب شيئًا.
     *
     * والبحثُ بالمطابقة لا بـ LIKE: العمودُ في المنتج ومعرضه المسارُ نفسُه،
     * وفي بطاقة القسم رابطُه. والإعداداتُ نصٌّ فيه الرابط — تُقرأ صفوفُ هذا
     * المتجر وحده (بضعُ مئاتٍ على الأكثر) ويُبحث فيها هنا.
     *
     * @param  list<string>  $originals
     * @return array{entries: list<array{table: string, id: int, column: string, before: string, after: string}>, swapped: list<string>, refused: array<string, string>}
     */
    public static function plan(int $businessId, array $originals): array
    {
        $entries = [];
        $swapped = [];
        $refused = [];
        $settings = null;

        foreach ($originals as $original) {
            $copy = ImageVariants::display($original);

            if ($copy === null || ImageVariants::isVariant($original) || ! self::readable($copy)) {
                $refused[$original] = 'no_copy';

                continue;
            }

            if (str_starts_with($original, 'website/') && ! str_starts_with($original, 'website/'.$businessId.'/')) {
                $refused[$original] = 'other_shop';

                continue;
            }

            $settings ??= DB::table('settings')->where('business_id', $businessId)->whereNotNull('value')->get(['id', 'value']);
            $rows = [];

            foreach ([['products', 'image', $original], ['product_images', 'path', $original], ['categories', 'card_image', Storage::url($original)]] as [$table, $column, $value]) {
                foreach (DB::table($table)->where('business_id', $businessId)->where($column, $value)->get(['id', $column]) as $row) {
                    $rows[] = [$table, $row, $column];
                }
            }

            foreach ($settings as $row) {
                if (str_contains((string) $row->value, basename($original))) {
                    $rows[] = ['settings', $row, 'value'];
                }
            }

            $added = 0;

            foreach ($rows as [$table, $row, $column]) {
                $added += self::add($entries, $table, $row, $column, $original, $copy) ? 1 : 0;
            }

            if ($added === 0) {
                $refused[$original] = 'no_live_reference';

                continue;
            }

            $swapped[] = $original;
        }

        return ['entries' => $entries, 'swapped' => $swapped, 'refused' => $refused];
    }

    /**
     * يكتب السجلّ «معلّقًا» ويتأكّد أنّه على القرص كما كُتب — أو يرمي.
     *
     * @param  list<array{table: string, id: int, column: string, before: string, after: string}>  $entries
     */
    public static function journal(string $run, int $businessId, array $entries): void
    {
        self::write($run, [
            'run' => $run, 'business_id' => $businessId, 'state' => self::PENDING,
            'at' => now()->toIso8601String(), 'entries' => $entries,
        ]);

        $back = self::read($run);

        if (($back['state'] ?? null) !== self::PENDING || ($back['entries'] ?? null) !== $entries) {
            throw new \RuntimeException('سجلُّ النقل لم يُقرأ كما كُتب — لم يُمسّ صفّ.');
        }
    }

    /**
     * يطبّق الخطّة في معاملةٍ واحدة — وكلُّ صفٍّ كما قرأته، وإلّا لا شيء.
     *
     * @param  list<array{table: string, id: int, column: string, before: string, after: string}>  $entries
     */
    public static function apply(int $businessId, array $entries): void
    {
        DB::transaction(function () use ($businessId, $entries) {
            foreach ($entries as $e) {
                $now = DB::table($e['table'])->where('id', $e['id'])->where('business_id', $businessId)->lockForUpdate()->value($e['column']);

                if ($now === null || (string) $now !== $e['before']) {
                    throw new \RuntimeException('تغيّر '.$e['table'].'#'.$e['id'].' بعد الخطّة — لم يُنقل شيء.');
                }

                DB::table($e['table'])->where('id', $e['id'])->update([$e['column'] => $e['after']]);
            }
        });
    }

    /** يختم السجلّ بحاله — ويرمي إن تعذّر */
    public static function seal(string $run, string $state, ?string $note = null): void
    {
        $log = self::read($run);
        $log['state'] = $state;
        $log['sealed_at'] = now()->toIso8601String();

        if ($note !== null) {
            $log['note'] = mb_substr($note, 0, 300);
        }

        self::write($run, $log);
    }

    /**
     * يقرأ كلَّ سجلٍّ معلّق ويقول أين وقف — ويختمه بما وجد.
     *
     * @return list<array{run: string, state: string, after: int, before: int, other: int}>
     */
    public static function recover(): array
    {
        $out = [];

        foreach (self::runs() as $run) {
            $log = self::read($run);

            if (($log['state'] ?? null) !== self::PENDING) {
                continue;
            }

            $count = self::where($log);
            $state = match (true) {
                $count['after'] > 0 && $count['before'] === 0 => self::DONE,
                $count['after'] === 0 && $count['other'] === 0 => self::ABORTED,
                default => self::PENDING,
            };

            if ($state !== self::PENDING) {
                self::seal($run, $state, 'recovered');
                MarketingSettings::forget((int) $log['business_id']);
            }

            $out[] = ['run' => $run, 'state' => $state] + $count;
        }

        return $out;
    }

    /**
     * يُعيد ما نقلته جولةٌ — صفًّا صفًّا، ما كان على «بعد» وحده.
     *
     * @return array{restored: int, conflicts: list<string>}
     */
    public static function rollback(string $run): array
    {
        $log = self::read($run);

        if ($log === null) {
            throw new \RuntimeException('لا سجلّ بهذا المعرّف: '.$run);
        }

        $restored = 0;
        $conflicts = [];

        DB::transaction(function () use ($log, &$restored, &$conflicts) {
            foreach (array_reverse($log['entries']) as $e) {
                $now = DB::table($e['table'])->where('id', $e['id'])->where('business_id', $log['business_id'])->lockForUpdate()->value($e['column']);

                // ‏ما لم يُنقل أصلًا لا يُعاد، وما غيّره صاحبُه بعد النقل لا يُكتب فوقه
                if ($now !== null && (string) $now === $e['before']) {
                    continue;
                }

                if ($now === null || (string) $now !== $e['after']) {
                    $conflicts[] = $e['table'].'#'.$e['id'];

                    continue;
                }

                DB::table($e['table'])->where('id', $e['id'])->update([$e['column'] => $e['before']]);
                $restored++;
            }
        });

        MarketingSettings::forget((int) $log['business_id']);
        self::seal($run, self::ROLLED_BACK, $conflicts === [] ? null : 'conflicts: '.implode(', ', $conflicts));

        return ['restored' => $restored, 'conflicts' => $conflicts];
    }

    /** سجلٌّ معلّقٌ لهذا المتجر؟ — لا تبدأ جولةٌ وأخرى لم يُعرف أين وقفت */
    public static function pendingFor(int $businessId): ?string
    {
        foreach (self::runs() as $run) {
            $log = self::read($run);

            if (($log['state'] ?? null) === self::PENDING && (int) ($log['business_id'] ?? 0) === $businessId) {
                return $run;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function read(string $run): ?array
    {
        $file = self::file($run);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $log = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($log) ? $log : null;
    }

    /* ═══════════ داخليّ ═══════════ */

    /**
     * يضيف الصفَّ إلى الخطّة — وصفٌّ فيه صورتان يُبنى على ما نُقل منه قبلُ.
     *
     * «قبل» ما في القاعدة دائمًا، و«بعد» الصورتان منقولتان: فالتطبيقُ يقارن
     * بما قرأه، والرجوعُ يُعيد الصفَّ كلَّه كما كان.
     *
     * @param  list<array{table: string, id: int, column: string, before: string, after: string}>  $entries
     */
    private static function add(array &$entries, string $table, object $row, string $column, string $original, string $copy): bool
    {
        foreach ($entries as $i => $e) {
            if ($e['table'] === $table && $e['id'] === (int) $row->id) {
                $after = self::replaced($e['after'], $original, $copy);

                if ($after === null) {
                    return false;
                }

                $entries[$i]['after'] = $after;

                return true;
            }
        }

        $before = (string) $row->{$column};
        $after = self::replaced($before, $original, $copy);

        if ($after === null) {
            return false;
        }

        $entries[] = ['table' => $table, 'id' => (int) $row->id, 'column' => $column, 'before' => $before, 'after' => $after];

        return true;
    }

    /** النصُّ واسمُ الأصل فيه اسمُ نسخته — أو `null` إن لم يكن فيه مسارُه حقًّا */
    private static function replaced(string $text, string $original, string $copy): ?string
    {
        if (! in_array($original, ImageReferences::paths($text), true)) {
            return null;
        }

        $after = (string) preg_replace('/'.preg_quote(basename($original), '/').'(?!\.w\d)/', basename($copy), $text);

        return $after === $text ? null : $after;
    }

    /** @return array{after: int, before: int, other: int} */
    private static function where(array $log): array
    {
        $count = ['after' => 0, 'before' => 0, 'other' => 0];

        foreach ($log['entries'] as $e) {
            $now = DB::table($e['table'])->where('id', $e['id'])->where('business_id', $log['business_id'])->value($e['column']);
            $key = match (true) {
                $now !== null && (string) $now === $e['after'] => 'after',
                $now !== null && (string) $now === $e['before'] => 'before',
                default => 'other',
            };
            $count[$key]++;
        }

        return $count;
    }

    private static function trySeal(string $run, string $state, ?string $note = null): bool
    {
        try {
            self::seal($run, $state, $note);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * كتابةٌ لا تترك نصفَ ملفّ: مؤقّتٌ يُدفع إلى القرص ثمّ يُسمّى باسمه.
     *
     * `rename` على القرص نفسه ذرّيّ: إمّا السجلُّ القديم كاملًا وإمّا الجديد
     * كاملًا — لا ملفّ JSON مقطوعٌ بعد انقطاع الكهرباء.
     */
    private static function write(string $run, array $log): void
    {
        $file = self::file($run);
        $dir = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('تعذّر إنشاء مجلّد سجلّات النقل.');
        }

        $json = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tmp = $file.'.'.Str::random(8).'.tmp';
        $handle = @fopen($tmp, 'xb');

        if ($handle === false) {
            throw new \RuntimeException('تعذّر فتح سجلّ النقل للكتابة.');
        }

        try {
            $ok = @fwrite($handle, $json) === strlen($json) && @fflush($handle) && (! function_exists('fsync') || @fsync($handle));
        } finally {
            @fclose($handle);
        }

        if (! $ok || ! @rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException('تعذّرت كتابة سجلّ النقل على القرص.');
        }
    }

    private static function file(string $run): string
    {
        return Storage::disk('local')->path(self::LOG.'/'.basename($run).'.json');
    }

    /** @return list<string> */
    private static function runs(): array
    {
        $dir = Storage::disk('local')->path(self::LOG);

        return is_dir($dir) ? array_map(fn ($f) => basename($f, '.json'), glob($dir.'/*.json') ?: []) : [];
    }

    /** نسخةٌ تُقرأ من القرص صورةَ WebP بأبعاد — لا اسمُ ملفٍّ فقط */
    private static function readable(string $path): bool
    {
        $file = Storage::disk('public')->path($path);
        $info = is_file($file) ? @getimagesize($file) : false;

        return is_array($info) && $info[2] === IMAGETYPE_WEBP && $info[0] > 0 && $info[1] > 0;
    }
}
