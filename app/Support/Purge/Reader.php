<?php

namespace App\Support\Purge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * قراءةُ أرشيفٍ وإعادتُه إلى قاعدةٍ — الطرفُ الآخرُ من الحذف.
 *
 * ═══ ولمَ هذا لازمٌ لا زائد ═══
 *
 * الأرشيفُ كان يُتحقَّق منه بأن يُفكَّ ويُفتح ويُقرأ بيانُه. وذاك يُثبت أنّ
 * الملفَّ سليمٌ، ولا يُثبت أنّ **الدفاترَ تُستعاد**: أعمدةٌ نقصت، أو صفٌّ
 * كُتب بفاصلةٍ داخلَ حقلٍ فانكسر السطر، أو حقلٌ فارغٌ حيث لا يُقبل الفراغ —
 * كلُّها تمرّ في «الملفُّ يُفتح» وتسقط يومَ تُطلب الدفاترُ فعلًا.
 *
 * ويومُ تُطلب فيه هو يومُ مراجعةٍ ضريبيّةٍ بعد سنين، ولا شركةَ في النظام
 * تُقارَن بها. فيُقرأ الأرشيفُ **الآن** ويُعاد إلى قاعدةٍ فارغةٍ ويُقارَن
 * بالأصل — وهذا هو الفرقُ بين نسخةٍ احتياطيّةٍ وملفٍّ نظنُّه نسخة.
 *
 * ═══ ولا تُكتب هذه في قاعدةٍ عاملة ═══
 *
 * الإعادةُ تُدخل صفوفًا بمعرِّفاتها الأصليّة. وفي قاعدةٍ فيها بياناتٌ تصطدم
 * بما فيها أو تُفسده. فالحكمُ على الهدف في `assertEmptyTarget` — وقاعدةُ
 * الإنتاج تُردّ قبل ذلك في الأمر نفسِه.
 */
final class Reader
{
    /**
     * البيانُ وما فيه — ويُتحقَّق من بصمةِ كلِّ دفترٍ قبل أن يُقرأ.
     *
     * @return array{manifest:array<string,mixed>, books:array<string,array{rows:int,sha256:string,intact:bool,csv:string}>, files:list<array{path:string,bytes:int,sha256:string,intact:bool}>}
     */
    public static function read(string $zipAbs): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipAbs, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('الأرشيف لا يُفتح — تحقّق من الملفّ.'));
        }

        try {
            $raw = $zip->getFromName('manifest.json');
            $manifest = $raw === false ? null : json_decode((string) $raw, true);

            if (! is_array($manifest)) {
                throw new RuntimeException(__('الأرشيف بلا بيانٍ يُقرأ (manifest.json).'));
            }

            $books = [];

            foreach ((array) ($manifest['books'] ?? []) as $table => $said) {
                $csv = $zip->getFromName("books/{$table}.csv");

                if ($csv === false) {
                    $books[(string) $table] = [
                        'rows' => (int) ($said['rows'] ?? 0),
                        'sha256' => (string) ($said['sha256'] ?? ''),
                        'intact' => false,
                        'csv' => '',
                    ];

                    continue;
                }

                $books[(string) $table] = [
                    'rows' => (int) ($said['rows'] ?? 0),
                    'sha256' => (string) ($said['sha256'] ?? ''),
                    'intact' => hash_equals((string) ($said['sha256'] ?? ''), hash('sha256', $csv)),
                    'csv' => $csv,
                ];
            }

            $files = [];

            foreach ((array) ($manifest['files'] ?? []) as $said) {
                $path = (string) ($said['path'] ?? '');
                $body = $path === '' ? false : $zip->getFromName('files/'.$path);

                $files[] = [
                    'path' => $path,
                    'bytes' => (int) ($said['bytes'] ?? 0),
                    'sha256' => (string) ($said['sha256'] ?? ''),
                    'intact' => $body !== false && hash_equals((string) ($said['sha256'] ?? ''), hash('sha256', $body)),
                ];
            }

            return ['manifest' => $manifest, 'books' => $books, 'files' => $files];
        } finally {
            $zip->close();
        }
    }

    /**
     * صفوفُ دفترٍ من نصِّ CSV — رؤوسٌ ثمّ صفوف.
     *
     * و`\xEF\xBB\xBF` في الصدر يُنزع: كُتب ليُقرأ العربيُّ في Excel، ولو
     * بقي صار جزءًا من اسمِ العمود الأوّل فلم يُعرف.
     *
     * @return list<array<string,string>>
     */
    public static function rows(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;

        if (trim($csv) === '') {
            return [];
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $head = fgetcsv($fh);

        if ($head === false) {
            fclose($fh);

            return [];
        }

        $head = array_map('strval', $head);
        $out = [];

        while (($row = fgetcsv($fh)) !== false) {
            /* سطرٌ فارغٌ في ذيل الملفّ يعود `[null]` — ولا يُحسب صفًّا */
            if ($row === [null] || $row === []) {
                continue;
            }

            if (count($row) !== count($head)) {
                fclose($fh);

                throw new RuntimeException(__('صفٌّ في الأرشيف عددُ أعمدته لا يطابق رؤوسَه — الملفّ معطوب.'));
            }

            $out[] = array_combine($head, array_map('strval', $row));
        }

        fclose($fh);

        return $out;
    }

    /**
     * أهدفٌ صالحٌ للإعادة؟ — جداولُه موجودةٌ وفارغة.
     *
     * والفراغُ شرطٌ لا احتياط: الصفوفُ تُعاد بمعرِّفاتها الأصليّة، فجدولٌ
     * فيه صفٌّ بالمعرّف نفسِه يُسقط الإعادةَ في منتصفها، أو — وهو الأسوأ —
     * يُخالط أرشيفَ شركةٍ ببياناتِ أخرى.
     *
     * @param  list<string>  $tables
     * @return array{missing:list<string>, filled:list<string>}
     */
    public static function inspectTarget(string $connection, array $tables): array
    {
        $schema = Schema::connection($connection);
        $missing = [];
        $filled = [];

        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            if (DB::connection($connection)->table($table)->exists()) {
                $filled[] = $table;
            }
        }

        return ['missing' => $missing, 'filled' => $filled];
    }

    /**
     * يُعاد دفترٌ إلى جدوله — ويُردُّ عددُ ما أُدخل.
     *
     * ═══ والفراغُ يُقرأ على حسب العمود ═══
     *
     * CSV لا يفرّق بين `null` ونصٍّ فارغ: كلاهما حقلٌ خالٍ. والأرشيفُ كتب
     * `null` فراغًا، فلا سبيلَ إلى استرجاع الفرق من الملفّ وحدَه.
     *
     * فيُسأل المخطَّط: عمودٌ يقبل `null` يأخذ `null` عن الفراغ، وعمودٌ لا
     * يقبله يأخذ النصَّ الفارغ. وهذا أقربُ ما يُستعاد به: عمودٌ إلزاميٌّ ما
     * كان فارغًا في الأصل أصلًا، وعمودٌ اختياريٌّ الغالبُ فيه أنّه كان
     * `null` لا `''`.
     *
     * وأعمدةٌ في الملفّ لا وجودَ لها في الجدول تُترك — مخطَّطٌ تبدّل بعد
     * سنينَ لا يمنع استعادةَ ما بقي، والمتروكُ يُقال في المخرَج.
     *
     * @param  list<array<string,string>>  $rows
     * @return array{inserted:int, dropped:list<string>}
     */
    public static function into(string $connection, string $table, array $rows): array
    {
        if ($rows === []) {
            return ['inserted' => 0, 'dropped' => []];
        }

        $nullable = [];

        foreach (Schema::connection($connection)->getColumns($table) as $col) {
            $nullable[(string) $col['name']] = (bool) $col['nullable'];
        }

        $dropped = array_values(array_diff(array_keys($rows[0]), array_keys($nullable)));
        $db = DB::connection($connection);
        $inserted = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $ready = [];

            foreach ($chunk as $row) {
                $out = [];

                foreach ($row as $col => $value) {
                    if (! array_key_exists($col, $nullable)) {
                        continue;
                    }

                    $out[$col] = $value === '' && $nullable[$col] ? null : $value;
                }

                $ready[] = $out;
            }

            $db->table($table)->insert($ready);
            $inserted += count($ready);
        }

        return ['inserted' => $inserted, 'dropped' => $dropped];
    }
}
