<?php

namespace App\Support;

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

    /**
     * كلُّ مسارٍ يُشار إليه، وأين.
     *
     * @return array<string, array<string, int>> المسار ← (جدول.عمود ← عدد الصفوف)
     */
    public static function scan(): array
    {
        $found = [];

        foreach (self::columns() as [$table, $column]) {
            // ‏نصًّا: PostgreSQL لا يقارن `json` بـ LIKE، و`CAST` يقرأ القاعدتين
            $text = 'CAST('.DB::getQueryGrammar()->wrap($column).' AS TEXT)';
            $rows = DB::table($table)->selectRaw($text.' AS v')
                ->where(function ($q) use ($text) {
                    foreach (['products', 'website', 'logos', 'covers', 'avatars'] as $folder) {
                        $q->orWhereRaw($text.' LIKE ?', ['%'.$folder.'%']);
                    }
                })
                ->cursor();

            foreach ($rows as $row) {
                foreach (self::paths((string) $row->v) as $path) {
                    $found[$path][$table.'.'.$column] = ($found[$path][$table.'.'.$column] ?? 0) + 1;
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

    /** @return list<array{0: string, 1: string}> كلُّ عمود نصٍّ في كلّ جدول */
    private static function columns(): array
    {
        $out = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::SKIP, true)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                if (in_array(strtolower((string) $column['type_name']), self::TEXT, true)) {
                    $out[] = [$table, $column['name']];
                }
            }
        }

        return $out;
    }
}
