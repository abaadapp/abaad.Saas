<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * نقلُ مراجع صورةٍ إلى نسختها الأخفّ — بسجلٍّ يُرجع به حرفًا بحرف.
 *
 * ═══ لمَ ═══
 *
 * النسخةُ إلى جوار الأصل تُخفّف ما يُنزله الزائر، لكنّ القرصَ يحمل الاثنين.
 * ولا يُستغنى عن أصلٍ يشير إليه صفّ. فتُنقل المراجعُ الحيّة أوّلًا — عمودُ
 * المنتج، وصورُ معرضه، وبطاقةُ القسم، وروابطُ الموقع في الإعدادات — ثمّ
 * يُسأل: هل بقي ما يشير إلى الأصل؟
 *
 * ═══ وما يُحرس ═══
 *
 *  • لا يُنقل مرجعٌ إلى نسخةٍ لا تُقرأ من القرص صورةً بأبعادها.
 *  • يُستبدل اسمُ الملفّ وحده في نصّ الصفّ — اسمٌ عشوائيٌّ بأربعين حرفًا لا
 *    يتكرّر — فيُصاب بشرطةٍ مهرَّبةٍ في JSON أو بدونها، ولا يُمسّ غيرُه.
 *  • متجرٌ واحدٌ في كلّ مرّة: صفوفُ غيره لا تُقرأ.
 *  • كلُّ صفٍّ تغيّر يُكتب قبل تغييره وبعده في سجلٍّ خاصّ (`image-swaps/`)،
 *    والرجوعُ يُعيده — ولا يكتب فوق صفٍّ غيّره صاحبُه بعد النقل.
 *  • الأصلُ لا يُمحى هنا أبدًا.
 */
final class ImageSwap
{
    private const LOG = 'image-swaps';

    /**
     * ينقل مراجعَ هذه الأصول في متجرٍ واحد — ويردّ معرّفَ السجلّ وما نُقل.
     *
     * @param  list<string>  $originals
     * @return array{run: string, swapped: list<string>, refused: array<string, string>, rows: int}
     */
    public static function swap(int $businessId, array $originals): array
    {
        $run = now()->format('Ymd-His').'-'.$businessId.'-'.Str::lower(Str::random(6));
        $entries = [];
        $swapped = [];
        $refused = [];

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

            $rows = DB::transaction(fn () => self::rewrite($businessId, $original, $copy));

            if ($rows === []) {
                $refused[$original] = 'no_live_reference';

                continue;
            }

            array_push($entries, ...$rows);
            $swapped[] = $original;
        }

        if ($entries !== []) {
            Storage::disk('local')->put(self::LOG.'/'.$run.'.json', json_encode(
                ['run' => $run, 'business_id' => $businessId, 'at' => now()->toIso8601String(), 'entries' => $entries],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));
            MarketingSettings::forget($businessId);
        }

        return ['run' => $run, 'swapped' => $swapped, 'refused' => $refused, 'rows' => count($entries)];
    }

    /**
     * يُعيد ما نقلته جولةٌ — صفًّا صفًّا، ما لم يتغيّر بعدها.
     *
     * @return array{restored: int, conflicts: list<string>}
     */
    public static function rollback(string $run): array
    {
        $file = self::LOG.'/'.basename($run).'.json';

        if (! Storage::disk('local')->exists($file)) {
            throw new \RuntimeException('لا سجلّ بهذا المعرّف: '.$run);
        }

        $log = json_decode((string) Storage::disk('local')->get($file), true);
        $restored = 0;
        $conflicts = [];

        DB::transaction(function () use ($log, &$restored, &$conflicts) {
            foreach (array_reverse($log['entries']) as $e) {
                $now = DB::table($e['table'])->where('id', $e['id'])->where('business_id', $log['business_id'])->value($e['column']);

                // ‏صفٌّ غيّره صاحبُه بعد النقل لا يُكتب فوقه — يُقال ويُترك
                if ($now !== $e['after']) {
                    $conflicts[] = $e['table'].'#'.$e['id'];

                    continue;
                }

                DB::table($e['table'])->where('id', $e['id'])->update([$e['column'] => $e['before']]);
                $restored++;
            }
        });

        MarketingSettings::forget((int) $log['business_id']);
        Storage::disk('local')->move($file, self::LOG.'/rolled-back/'.basename($file));

        return ['restored' => $restored, 'conflicts' => $conflicts];
    }

    /**
     * يكتب الاسمَ الجديد في كلّ موضعٍ حيٍّ لهذا المتجر — ويردّ ما تغيّر.
     *
     * @return list<array{table: string, id: int, column: string, before: string, after: string}>
     */
    private static function rewrite(int $businessId, string $original, string $copy): array
    {
        $name = basename($original);
        $pattern = '/'.preg_quote($name, '/').'(?!\.w\d)/';
        $after = basename($copy);
        $rows = [];

        foreach (ImageReferences::LIVE as $live) {
            [$table, $column] = explode('.', $live);

            $found = DB::table($table)->where('business_id', $businessId)
                ->where($column, 'like', '%'.$name.'%')->lockForUpdate()->get(['id', $column]);

            foreach ($found as $row) {
                $before = (string) $row->{$column};
                $new = (string) preg_replace($pattern, $after, $before);

                if ($new === $before || ! in_array($original, ImageReferences::paths($before), true)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $new]);
                $rows[] = ['table' => $table, 'id' => (int) $row->id, 'column' => $column, 'before' => $before, 'after' => $new];
            }
        }

        return $rows;
    }

    /** نسخةٌ تُقرأ من القرص صورةَ WebP بأبعاد — لا اسمُ ملفٍّ فقط */
    private static function readable(string $path): bool
    {
        $file = Storage::disk('public')->path($path);
        $info = is_file($file) ? @getimagesize($file) : false;

        return is_array($info) && $info[2] === IMAGETYPE_WEBP && $info[0] > 0 && $info[1] > 0;
    }
}
