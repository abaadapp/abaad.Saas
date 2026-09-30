<?php

namespace App\Support;

use App\Support\Document\Branding;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الاستعادةُ تكتب في متجرها وحدَه — مهما حمل الملفّ.
 *
 * ═══ ما كان يقع ═══
 *
 * ملفُّ النسخة يرفعه صاحبُ المتجر بيده، و`BackupController` كان يثق بكلّ ما
 * فيه إلّا `business_id` (يُكتب فوقه) و`meta.business_id` (يُقارَن). فملفٌّ
 * مصنوعٌ كان يكتب في متاجرَ أخرى:
 *
 *   ١) **حساباتُ غيره:** المستخدمُ يُطابَق بالبريد على المنصّة كلّها ثمّ
 *      تُكتب أعمدةُ الملفّ فوقه — فيُعطى مالكُ متجرٍ آخر بريدَ استرجاعٍ
 *      «موثَّقًا» يملكه المهاجم، ويُسترجع الحسابُ منه. ويُنشأ من الملفّ
 *      حسابٌ دورُه `super_admin`.
 *   ٢) **دفاترُ غيره:** سطرُ طلبٍ أو قيدٍ أو عنوانٍ يُدرج تحت طلبِ متجرٍ آخر
 *      أو قيدِه أو زبونه، وصفٌّ يُشير إلى فرعِ غيره أو زبونه.
 *   ٣) **ملفّاتُ غيره:** مسارُ مرفقٍ يُكتب إلى نسخةِ متجرٍ آخر الاحتياطيّة ثمّ
 *      يُنزَّل من «مرفق المصروف»؛ ومسارُ شعارٍ أو صورةٍ يُكتب إلى ملفّ غيره
 *      ثمّ يُحذف حين يُبدَّل أو يُمحى.
 *   ٤) **نطاقُ غيره:** صفٌّ في `website_domains` «مفعَّل» بلا موافقة.
 *
 * ═══ والقاعدة ═══
 *
 * نسخةُ المتجر الصادقة لا تحمل إلّا ما هو له. فكلُّ ما يُشير خارجه **يُسقط
 * الاستعادةَ كلَّها** قبل أن تُثبَّت (`RestoreCrossesTenant`) — لا يُصحَّح
 * صامتًا فتُستعاد نسخةٌ غيرُ التي رُفعت. إلّا ما لا يُفسد دفترًا: مسارُ ملفٍّ
 * لا يملكه يُفرَّغ، وحسابُ غيره يُترك كما هو.
 */
final class RestoreIsolation
{
    /**
     * مراجعُ داخليّة لا يعلنها المخطّط مفتاحًا أجنبيًّا — تُفحص كالمعلَنة.
     *
     * المعلَنةُ تُقرأ من `Schema::getForeignKeys`؛ وهذه أعمدةٌ تحمل معرّفَ صفٍّ
     * في جدول متجرٍ بلا قيد. والمعرّفاتُ الخارجيّة (ميتا، Google، Paymob)
     * والمتعدّدةُ الأشكال (`sourceable_id`، `subject_id`) ليست منها.
     */
    public const UNDECLARED = [
        'categories' => ['parent_id' => 'categories'],
        'import_batches' => ['user_id' => 'users'],
        'point_transactions' => ['customer_id' => 'customers', 'order_id' => 'orders'],
        'activity_logs' => ['user_id' => 'users'],
        'branch_stocks' => ['branch_id' => 'branches', 'product_id' => 'products'],
        'bank_statement_lines' => ['transaction_id' => 'transactions'],
        'transactions' => ['order_id' => 'orders'],
        'inventory_movements' => ['product_id' => 'products'],
        'purchase_order_items' => ['product_id' => 'products'],
        'orders' => ['user_id' => 'users', 'branch_id' => 'branches', 'shift_id' => 'shifts'],
        'order_items' => [
            'product_id' => 'products', 'season_id' => 'seasons',
            'boutique_id' => 'boutiques', 'boutique_settlement_id' => 'boutique_settlements',
        ],
        'order_edits' => ['order_item_id' => 'order_items', 'user_id' => 'users'],
        'coupon_redemptions' => ['customer_id' => 'customers'],
        'order_prep_checks' => ['user_id' => 'users'],
        'shifts' => ['user_id' => 'users', 'branch_id' => 'branches'],
        'shift_movements' => ['user_id' => 'users'],
    ];

    /** أعمدةُ مساراتِ الملفّات — يُقبل منها ما كان لهذا المتجر قبل الاستعادة */
    public const FILES = [
        'products' => ['image'],
        'product_images' => ['path'],
        'expenses' => ['attachment'],
        'purchase_orders' => ['receipt', 'attachment'],
        'supplier_invoices' => ['attachment'],
        'goods_receipt_notes' => ['attachment'],
        'orders' => ['card_file'],
        'customer_invoice_attachments' => ['path'],
        'import_batches' => ['file'],
        'users' => ['avatar'],
    ];

    /** وإعداداتٌ قيمتُها مسارُ ملفّ — غلافُ المستندات يُحذف القديمُ منه حين يُبدَّل */
    public const FILE_SETTINGS = [Branding::COVER];

    /**
     * ما يُستعاد من صفّ الموظّف — بياناتُ عمله لا مفاتيحُ حسابه.
     *
     * لا البريدُ (مفتاحُ المطابقة)، ولا بريدُ الاسترجاع ولا توثيقُه، ولا توثيقُ
     * البريد، ولا آخرُ دخول، ولا المتجر. والدورُ يُقبل إلّا دورَ المنصّة.
     */
    public const USER_FIELDS = [
        'name', 'phone', 'role', 'avatar', 'branch', 'status', 'monthly_target',
        'job_title', 'locale', 'permissions', 'basic_salary', 'allowances', 'deleted_at',
    ];

    /** دورٌ لا يصنعه ملفّ — مديرُ المنصّة يُنشأ من المنصّة وحدها */
    public const PLATFORM_ROLE = 'super_admin';

    /* ═══════════ قبل المحو: ما يملكه المتجر ═══════════ */

    /**
     * مساراتُ الملفّات التي يملكها المتجر الآن — قبل أن يُمحى شيء.
     *
     * نسختُه الصادقة تُعيد مساراتِه هو؛ ومسارٌ ليس بينها لم يكن له.
     *
     * @return array<string, true>
     */
    public static function ownedPaths(int $bid): array
    {
        $paths = [];

        foreach (self::FILES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = $table === 'users'
                ? DB::table('users')->where('business_id', $bid)
                : TenantTables::scope($table, $bid);

            foreach ($columns as $column) {
                foreach ((clone $query)->whereNotNull($column)->pluck($column) as $path) {
                    $paths[(string) $path] = true;
                }
            }
        }

        foreach (DB::table('settings')->where('business_id', $bid)->whereIn('key', self::FILE_SETTINGS)->pluck('value') as $path) {
            if (filled($path)) {
                $paths[(string) $path] = true;
            }
        }

        if ($logo = DB::table('businesses')->where('id', $bid)->value('logo')) {
            $paths[(string) $logo] = true;
        }

        return $paths;
    }

    /** نطاقاتُ المتجر الآن — لا يُستعاد نطاقٌ لم يكن له @return array<string, true> */
    public static function ownedHosts(int $bid): array
    {
        if (! Schema::hasTable('website_domains')) {
            return [];
        }

        return array_fill_keys(
            DB::table('website_domains')->where('business_id', $bid)->pluck('normalized_hostname')->map(fn ($h) => (string) $h)->all(),
            true,
        );
    }

    /* ═══════════ أثناء الإدراج ═══════════ */

    /**
     * يُفرغ من الصفّ كلَّ مسارٍ لا يملكه المتجر — ويُسقط نطاقًا ليس له.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $paths
     * @param  array<string, true>  $hosts
     * @return array<string, mixed>|null `null` = لا يُدرج
     */
    public static function clean(string $table, array $row, array $paths, array $hosts): ?array
    {
        foreach (self::FILES[$table] ?? [] as $column) {
            if (filled($row[$column] ?? null) && ! isset($paths[(string) $row[$column]])) {
                $row[$column] = null;
            }
        }

        if ($table === 'settings' && in_array($row['key'] ?? null, self::FILE_SETTINGS, true)
            && filled($row['value'] ?? null) && ! isset($paths[(string) $row['value']])) {
            $row['value'] = null;
        }

        if ($table === 'website_domains' && ! isset($hosts[(string) ($row['normalized_hostname'] ?? '')])) {
            return null;
        }

        return $row;
    }

    /**
     * سطرُ الابن يُدرج تحت أبٍ لهذا المتجر — أو لا تُستعاد النسخة.
     *
     * الآباءُ أُدرجت قبله (`TenantTables::ORDER`)، فأبٌ ليس في نطاق المتجر
     * الآن أبُ متجرٍ آخر أو لا وجود له.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function assertParents(string $table, array $rows, int $bid): void
    {
        if (! isset(TenantTables::THROUGH[$table]) || $rows === []) {
            return;
        }

        [$parent, $key] = TenantTables::THROUGH[$table];
        $wanted = array_values(array_unique(array_filter(array_map(fn ($r) => $r[$key] ?? null, $rows), fn ($v) => $v !== null)));

        if ($wanted === []) {
            return;
        }

        $mine = TenantTables::scope($parent, $bid)->whereIn('id', $wanted)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $stray = array_diff(array_map('intval', $wanted), $mine);

        if ($stray !== []) {
            throw new RestoreCrossesTenant("{$table}.{$key}");
        }
    }

    /* ═══════════ بعد الإدراج: قبل التثبيت ═══════════ */

    /**
     * كلُّ صفٍّ استُعيد يُشير إلى صفوفِ متجره — أو لا تُثبَّت الاستعادة.
     *
     * يُسأل كلُّ مفتاحٍ أجنبيٍّ (المعلَنُ في المخطّط وما في `UNDECLARED`) إلى
     * جدولٍ من جداول المتجر: هل في صفوف هذا المتجر ما يُشير إلى صفٍّ **موجودٍ
     * لغيره**؟ والمرجعُ المعلَّق (صفٌّ حُذف نهائيًّا) ليس عبورًا — يبقى كما كان
     * في النسخة. وحسابُ المنصّة (`business_id` فارغ) ليس «متجرًا آخر».
     */
    public static function assertReferences(int $bid): void
    {
        $tenant = array_flip(array_merge(TenantTables::all(), ['users', 'businesses']));

        foreach (TenantTables::all() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (self::references($table) as $column => $foreign) {
                if (! isset($tenant[$foreign]) || ! Schema::hasTable($foreign)) {
                    continue;
                }

                $rows = $table === 'users'
                    ? DB::table('users')->where('business_id', $bid)
                    : TenantTables::scope($table, $bid);

                $crossing = $rows->whereNotNull($column)
                    ->whereIn($column, self::foreignRows($foreign, $bid))
                    ->exists();

                if ($crossing) {
                    throw new RestoreCrossesTenant("{$table}.{$column} → {$foreign}");
                }
            }
        }
    }

    /** @return array<string, string> [العمود => الجدول الذي يُشير إليه] */
    private static function references(string $table): array
    {
        $out = self::UNDECLARED[$table] ?? [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1) {
                $out[$fk['columns'][0]] = $fk['foreign_table'];
            }
        }

        // `business_id` كُتب فوقه بمعرّف هذا المتجر — لا يُسأل
        unset($out['business_id']);

        return $out;
    }

    /** معرّفاتُ صفوفٍ موجودةٍ في `$table` ليست لهذا المتجر */
    private static function foreignRows(string $table, int $bid): Builder
    {
        return match ($table) {
            'businesses' => DB::table('businesses')->select('id')->where('id', '!=', $bid),
            'users' => DB::table('users')->select('id')->whereNotNull('business_id')->where('business_id', '!=', $bid),
            default => DB::table($table)->select('id')->whereNotIn('id', TenantTables::scope($table, $bid)->select('id')),
        };
    }
}
