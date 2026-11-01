<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أين يُشار إلى كلّ صورةٍ في القاعدة — في كلّ جدولٍ وكلّ عمود نصّ.
 *
 * ═══ ولمَ لا تكفي قائمةٌ بالأعمدة ═══
 *
 * صورةُ المنتج في عموده، لكنّ صورةَ الموقع رابطٌ داخل JSON في الإعدادات —
 * في الشريط والمجموعات والصفحات والواجهة — وقد تُنسخ إلى سطر طلبٍ أو سجلّ.
 * وقائمةٌ تنسى موضعًا واحدًا تقول «لا يشير إليها شيء» عن صورةٍ تُعرض. فيُمسح
 * كلُّ عمود نصٍّ في كلّ جدول، ويُقرأ منه كلُّ مسارٍ يشبه ما يحفظه الرفع.
 *
 * ═══ والحيّ غيرُ المحفوظ ═══
 *
 * `LIVE` هي المواضع التي تُعرض منها الصورةُ اليوم ويجوز نقلُها إلى نسختها
 * (`ImageSwap`). وما سواها — سطرُ طلبٍ قديم أو سجلٌّ — يُترك كما كُتب، ويُبقي
 * أصلَه على القرص: فاتورةٌ تُعاد طباعتُها لا تفقد صورتها.
 *
 * والمسارُ يُقرأ كما يُكتب في JSON بشرطةٍ مهرَّبة أو بدونها (`\/`).
 */
final class ImageReferences
{
    /** المواضعُ الحيّة — جدولٌ وعمودُه، ومعرّفُ المتجر فيه */
    public const LIVE = [
        'products.image', 'product_images.path', 'categories.card_image', 'settings.value',
    ];

    /** جداولُ لا تحمل ما يُعرض — طوابيرُ وجلساتٌ وذاكرةٌ مؤقّتة */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private const TEXT = ['text', 'varchar', 'character varying', 'char', 'bpchar', 'json', 'jsonb', 'longtext', 'mediumtext', 'tinytext', 'string', 'clob'];

    /** مسارُ ملفٍّ كما يحفظه الرفع — في مجلّدٍ عامّ، باسمه العشوائيّ وامتداده */
    private const FILE = '#(products|website(?:\\\\?/)\d+|logos|covers|avatars)(?:\\\\?/)([A-Za-z0-9_-]{16,}\.(?:jpe?g|png|webp|gif|avif)(?:\.w\d+\.webp)*)#i';

    /** ما كلّفه آخرُ مسح — يقرؤه الجرد ليقول ثمنه */
    public static array $stats = [];

    /** المجلّداتُ التي يُبحث عنها في النصّ — كلُّ ما يحفظه الرفعُ على القرص العامّ */
    private const FOLDERS = ['products', 'website', 'logos', 'covers', 'avatars'];

    private const ID_TYPES = ['integer', 'int', 'int4', 'int8', 'bigint', 'smallint', 'serial', 'bigserial'];

    /**
     * كلُّ مسارٍ يُشار إليه في أيّ جدول، وأين — للجرد وخطّة الاستغناء.
     *
     * ═══ ولا يُثقل القاعدة ═══
     *
     *  • **مرّةٌ لكلّ جدول** لا لكلّ عمود: أعمدةُ النصّ كلُّها في سؤالٍ واحد.
     *  • **بمدى المعرّف** لا بالجدول كلّه: كلُّ سؤالٍ يقرأ `images.scan_batch`
     *    صفًّا بمفتاحه الأساسيّ على الأكثر — فجدولُ سجلّاتٍ بمليون صفٍّ مئتا
     *    سؤالٍ قصير، لا سؤالٌ واحدٌ يمسك القاعدة دقيقة.
     *  • **استراحةٌ** بين سؤالٍ وآخر، و**سقفٌ لزمن السؤال** على PostgreSQL
     *    (`statement_timeout`) — سؤالٌ تجاوزه يُوقف المسحَ ويُقال، لا يطول.
     *  • لا يُحمَل إلى الذاكرة إلّا صفٌّ فيه اسمُ مجلّد، ويُقرأ ويُترك.
     *
     * @return array<string, array<string, int>> المسار ← (جدول.عمود ← عدد الصفوف)
     */
    public static function scan(): array
    {
        $found = [];
        $stats = ['queries' => 0, 'rows' => 0, 'max_ms' => 0.0, 'ms' => 0.0, 'tables' => 0];
        $cpu = self::cpu();
        $started = hrtime(true);
        $pg = DB::getDriverName() === 'pgsql';
        $batch = max(100, (int) config('images.scan_batch', 5000));
        $pause = max(0, (int) config('images.scan_pause_ms', 20)) * 1000;

        if ($pg) {
            DB::statement('SET statement_timeout = '.max(1000, (int) config('images.scan_timeout_ms', 15000)));
        }

        try {
            foreach (self::tables() as $table => [$columns, $keyed]) {
                $stats['tables']++;
                $ranges = $keyed ? self::ranges($table, $batch) : [null];

                foreach ($ranges as $range) {
                    $t = hrtime(true);
                    $rows = self::query($table, $columns, $range)->get();
                    $ms = (hrtime(true) - $t) / 1e6;
                    $stats['queries']++;
                    $stats['rows'] += $rows->count();
                    $stats['max_ms'] = max($stats['max_ms'], round($ms, 1));

                    foreach ($rows as $row) {
                        foreach ($columns as $i => $column) {
                            foreach (self::paths((string) ($row->{'c'.$i} ?? '')) as $path) {
                                $found[$path][$table.'.'.$column] = ($found[$path][$table.'.'.$column] ?? 0) + 1;
                            }
                        }
                    }

                    unset($rows);

                    if ($pause > 0) {
                        usleep($pause);
                    }
                }
            }
        } finally {
            if ($pg) {
                DB::statement('RESET statement_timeout');
            }
        }

        $stats['ms'] = round((hrtime(true) - $started) / 1e6, 1);
        $stats['cpu_ms'] = round(self::cpu() - $cpu, 1);
        $stats['peak'] = memory_get_peak_usage(true);
        self::$stats = $stats;

        return $found;
    }

    /**
     * المراجعُ الحيّة وحدها — للجولة المجدولة: أربعةُ أعمدةٍ لا القاعدةُ كلُّها.
     *
     * @return array<string, array<string, int>>
     */
    public static function live(?int $businessId = null): array
    {
        $found = [];

        foreach (self::LIVE as $live) {
            [$table, $column] = explode('.', $live);
            $text = 'CAST('.DB::getQueryGrammar()->wrap($column).' AS TEXT)';

            $rows = DB::table($table)->selectRaw($text.' AS v')
                ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
                ->whereNotNull($column)
                ->where(function ($q) use ($text) {
                    foreach (self::FOLDERS as $folder) {
                        $q->orWhereRaw($text.' LIKE ?', ['%'.$folder.'%']);
                    }
                })
                ->orderBy('id')->lazy(1000);

            foreach ($rows as $row) {
                foreach (self::paths((string) $row->v) as $path) {
                    $found[$path][$live] = ($found[$path][$live] ?? 0) + 1;
                }
            }
        }

        return $found;
    }

    /**
     * المساراتُ في نصٍّ واحد — بلا تكرار، وبشرطاتٍ عاديّة.
     *
     * @return list<string>
     */
    public static function paths(string $text): array
    {
        if (! preg_match_all(self::FILE, $text, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($x) => str_replace('\\', '', $x[1]).'/'.$x[2], $m)));
    }

    /** هل كلُّ ما يشير إليها حيٌّ يُنقل؟ — وإلّا بقي أصلُها لما حفظه */
    public static function onlyLive(array $where): bool
    {
        return array_diff(array_keys($where), self::LIVE) === [];
    }

    /**
     * كلُّ جدولٍ فيه عمودُ نصّ — وأعمدتُه، وهل له معرّفٌ رقميٌّ يُمشى عليه.
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    private static function tables(): array
    {
        $out = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::SKIP, true)) {
                continue;
            }

            $columns = [];
            $keyed = false;

            foreach (Schema::getColumns($table) as $column) {
                $type = strtolower((string) $column['type_name']);

                if (in_array($type, self::TEXT, true)) {
                    $columns[] = $column['name'];
                }

                if ($column['name'] === 'id' && in_array($type, self::ID_TYPES, true)) {
                    $keyed = true;
                }
            }

            if ($columns !== []) {
                $out[$table] = [$columns, $keyed];
            }
        }

        return $out;
    }

    /**
     * مدى المعرّف دفعاتٍ — بحدود الفهرس، لا بحساب الأرقام.
     *
     * الحدُّ التالي هو المعرّفُ رقمُ `batch` بعد الحدّ السابق (`OFFSET` على
     * المفتاح الأساسيّ — يُقرأ من الفهرس وحده). فجدولٌ بمعرّفاتٍ متباعدة لا
     * يصير آلافَ سؤالٍ فارغ، وكلُّ دفعةٍ `batch` صفًّا بالضبط.
     *
     * @return \Generator<array{0: ?int, 1: ?int}> (بعد هذا، حتى هذا)
     */
    private static function ranges(string $table, int $batch): \Generator
    {
        $after = null;

        while (true) {
            $to = DB::table($table)->when($after !== null, fn ($q) => $q->where('id', '>', $after))
                ->orderBy('id')->offset($batch - 1)->limit(1)->value('id');

            yield [$after, $to === null ? null : (int) $to];

            if ($to === null) {
                return;
            }

            $after = (int) $to;
        }
    }

    /**
     * سؤالُ دفعةٍ واحدة: أعمدةُ النصّ نصًّا، وما فيه اسمُ مجلّدٍ وحده.
     *
     * و`CAST` لأنّ PostgreSQL لا يقارن `json` بـ LIKE، وهو يقرأ القاعدتين.
     *
     * @param  list<string>  $columns
     * @param  array{0: ?int, 1: ?int}|null  $range  (بعد هذا، حتى هذا)
     */
    private static function query(string $table, array $columns, ?array $range): Builder
    {
        $grammar = DB::getQueryGrammar();
        $texts = array_map(fn ($c) => 'CAST('.$grammar->wrap($c).' AS TEXT)', $columns);

        return DB::table($table)
            ->selectRaw(implode(', ', array_map(fn ($t, $i) => $t.' AS c'.$i, $texts, array_keys($texts))))
            ->when($range !== null && $range[0] !== null, fn ($q) => $q->where('id', '>', $range[0]))
            ->when($range !== null && $range[1] !== null, fn ($q) => $q->where('id', '<=', $range[1]))
            ->where(function ($q) use ($texts) {
                foreach ($texts as $text) {
                    foreach (self::FOLDERS as $folder) {
                        $q->orWhereRaw($text.' LIKE ?', ['%'.$folder.'%']);
                    }
                }
            });
    }

    /** زمنُ المعالج الذي أخذه هذا العمل — بالملّي‌ثانية */
    private static function cpu(): float
    {
        $u = getrusage();

        return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000;
    }
}
