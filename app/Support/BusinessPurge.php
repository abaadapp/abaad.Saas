<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * محوُ شركةٍ من النظام — أرشفةٌ أوّلًا، ثمّ حذف.
 *
 * ═══ ولمَ لا يُترك الأمرُ للتتالي ═══
 *
 * خمسةٌ وسبعون جدولًا تحمل `business_id`، سبعةٌ وستّون منها بقيد
 * `ON DELETE CASCADE`. ولو حُذف صفُّ الشركة وحدَه لبقي ثلاثةٌ ووقع رابع:
 *
 * ١) **ثمانيةُ جداولَ بلا قيدٍ أجنبيٍّ أصلًا** — `users` و`settings`
 *    و`activity_logs` و`branch_stocks` و`point_transactions`
 *    و`import_batches` و`order_prep_checks` و`notification_states`. تبقى
 *    صفوفُها يتيمةً تشير إلى شركةٍ لا وجودَ لها، وأخطرُها `users`: بابُ
 *    دخولٍ مفتوحٌ لحسابٍ بلا شركة.
 *
 * ٢) **`journal_lines → accounts` قيدُه RESTRICT** — وهو القيدُ الوحيد من
 *    نوعه في النظام. والحساباتُ وقيودُها تُحذفان بالتتالي معًا، ولا تضمن
 *    PostgreSQL ترتيبًا بينهما — فقد يقع `foreign key violation` ويُترك
 *    المحوُ ناقصًا. فتُحذف السطورُ قبل الحسابات صراحةً.
 *
 * ٣) **الملفّاتُ على القرص لا يمسّها قيد** — تبقى بلا صفٍّ يشير إليها، فلا
 *    تُقرأ ولا تُحذف بعدها أبدًا.
 *
 * ٤) **الأرشفة** لا يفعلها قيد. ودفاترُ التاجر تُحفظ عشر سنين بحكم قانون
 *    ضريبة القيمة المضافة وقانون ضريبة الدخل في عُمان — فلا تُمحى قبل أن
 *    تُكتب في أرشيفٍ يُتحقّق منه.
 *
 * ═══ والترتيب ═══
 *
 * أرشفةٌ ← تحقّقٌ من الأرشيف ← معاملةُ قاعدةٍ واحدة ← ملفّات ← سجلّ.
 *
 * والملفّاتُ بعد المعاملة لا داخلَها: تراجعُ المعاملة يُعيد الصفوف ولا
 * يُعيد ملفًّا مُحي. فما يُحذف منها بعد أن يستقرّ المحوُ في القاعدة، وما
 * يسقط منها يُقال في النتيجة ولا يُبتلع.
 */
final class BusinessPurge
{
    /** قرصُ الأرشيف ومجلّده — بعيدًا عن `public`، لا يُفتح برابط */
    public const DISK = 'local';

    public const DIR = 'business-purges';

    /**
     * دفاترُ تُؤرشَف قبل المحو.
     *
     * ═══ ولمَ هذه دون غيرها ═══
     *
     * ما يُقرأ في مراجعةٍ محاسبيّةٍ أو ضريبيّة: المستنداتُ وسطورُها، والقيودُ
     * وحساباتُها، وما يلزم لقراءتها (العملاءُ والمورّدون والأصنافُ والفروعُ
     * والعملة). وعدادُ صفوف **كلّ** جدولٍ مشمولٍ يُكتب في البيان على كلّ
     * حال — فالأرشيفُ يقول ما حُفظ وما مُحي.
     *
     * @var list<string>
     */
    public const BOOKS = [
        'businesses', 'branches', 'currencies',
        'customers', 'suppliers', 'products', 'product_variants',
        'orders', 'order_items',
        'customer_invoices', 'customer_invoice_items', 'customer_invoice_orders', 'customer_invoice_attachments',
        'customer_payments', 'customer_payment_allocations', 'customer_credit_notes',
        'purchase_orders', 'purchase_order_items', 'supplier_invoices',
        'goods_receipt_notes', 'goods_receipt_note_items',
        'delivery_notes', 'delivery_note_items',
        'accounts', 'journal_entries', 'journal_lines',
        'expenses', 'expense_types',
        'payroll_runs', 'payroll_lines',
        'bank_accounts', 'bank_statement_lines',
        'inventory_movements', 'stock_adjustments',
        'invoices', 'subscriptions', 'transactions',
    ];

    /**
     * مجلّداتُ الملفّات المملوكةُ لهذه الشركة وحدَها — تُؤرشَف ثمّ تُحذف.
     *
     * ومجلّدٌ باسم المعرّف لا نمطٌ يُطابَق: الحذفُ يقع على مسارٍ يُبنى من
     * رقمٍ صحيح، فلا يبلغ ملفَّ جارٍ بحال.
     *
     * @var list<string>
     */
    private const KEPT_DIRS = ['customer-invoices', 'expenses', 'supplier-invoices', 'receipts'];

    /** ملفّاتٌ تُحذف ولا تُؤرشَف — لا وزنَ محاسبيَّ لها */
    private const DROP_DIRS = ['gift-cards/kept'];

    /**
     * أعمدةُ ملفّاتٍ على قرص `public` — تُقرأ قيمُها فتُحذف ملفّاتُها.
     *
     * وهي مجلّداتٌ مشتركةٌ بين الشركات (`logos/`, `products/`)، فلا يُحذف
     * مجلّدٌ ولا نمط: يُقرأ مسارُ كلّ صفٍّ من صفوف هذه الشركة وحدَها.
     *
     * @var list<array{0:string,1:string}>
     */
    private const PUBLIC_FILES = [
        ['businesses', 'logo'],
        ['products', 'image'],
        ['product_images', 'path'],
        ['users', 'avatar'],
    ];

    public static function enabled(): bool
    {
        return (bool) config('purge.enabled', false);
    }

    /**
     * يُمحى المتجرُ ويُعاد بيانُ ما جرى.
     *
     * @return array{archive:string, sha256:string, bytes:int, rows:array<string,int>, users:int, files:int, failures:list<string>}
     */
    public static function run(Business $business, User $actor): array
    {
        if (! self::enabled()) {
            throw new RuntimeException(__('الحذف النهائي مُقفل على هذا الخادم.'));
        }

        $bid = (int) $business->id;
        $name = (string) $business->name;

        /*
         * ولا تُنفَّذ مرّتين بالتزامن.
         *
         * ضغطتان على الزرّ، أو طلبٌ يُعاد إرساله، يُشغّلان مساريَن على الصفوف
         * نفسِها: الثاني يقرأ ما محاه الأوّل فيكتب أرشيفًا ناقصًا ويسجّل
         * نجاحًا لم يقع. والقفلُ لا يُنتظر — الثاني يُردّ بكلمته.
         */
        $lock = Cache::lock('business-purge:'.$bid, 600);

        if (! $lock->get()) {
            throw new RuntimeException(__('حذفُ هذه الشركة جارٍ الآن — انتظر حتّى ينتهي.'));
        }

        try {
            $counts = self::counts($bid);
            $archive = self::archive($business, $actor, $counts);

            self::verify($archive['path']);

            /* مساراتُ `public` تُقرأ قبل المحو — بعده لا صفَّ يُقرأ منه مسار */
            $shared = self::publicPaths($bid);

            $users = self::wipe($bid);
            $files = self::files($bid, $shared);

            Activity::log('deleted', self::record($name, $bid, $archive, $counts, $users, $files), [
                /* خارجَ نطاق الشركة — صفٌّ يبقى بعد أن يزول صاحبُه */
                'business_id' => null,
                'subject_type' => Business::class,
                'subject_id' => $bid,
            ]);

            return [
                'archive' => $archive['path'],
                'sha256' => $archive['sha256'],
                'bytes' => $archive['bytes'],
                'rows' => $counts,
                'users' => $users,
                'files' => $files['deleted'],
                'failures' => $files['failures'],
            ];
        } finally {
            $lock->release();
        }
    }

    /* ═══════════════════ العدّ ═══════════════════ */

    /**
     * عددُ صفوف كلّ جدولٍ يحمل معرّف هذه الشركة.
     *
     * يُقرأ من المخطَّط لا من قائمةٍ مكتوبة: جدولٌ يُستحدَث غدًا بعمود
     * `business_id` يدخل العدَّ والمحوَ بلا سطرٍ يُكتب له هنا.
     *
     * @return array<string,int>
     */
    public static function counts(int $bid): array
    {
        $out = [];

        foreach (self::scoped() as $table) {
            $n = DB::table($table)->where('business_id', $bid)->count();

            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function scoped(): array
    {
        $skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens', 'businesses'];

        return collect(Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t)
            ->reject(fn ($t) => in_array($t, $skip, true) || str_starts_with($t, 'sqlite_'))
            ->filter(fn ($t) => Schema::hasColumn($t, 'business_id'))
            ->values()
            ->all();
    }

    /* ═══════════════════ الأرشيف ═══════════════════ */

    /**
     * ملفٌّ واحدٌ يحمل الدفاترَ والمرفقاتِ وبيانًا بما فيه.
     *
     * @param  array<string,int>  $counts
     * @return array{path:string, sha256:string, bytes:int, entries:int}
     */
    private static function archive(Business $business, User $actor, array $counts): array
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIR);

        $rel = self::DIR.'/'.$business->id.'-'.now()->format('Ymd-His').'.zip';
        $abs = $disk->path($rel);

        /*
         * والمجلَّدُ يُسأل عنه قبل الفتح.
         *
         * `ZipArchive::open` على مسارٍ لا مجلَّدَ له يرفع تحذيرَ PHP قبل أن
         * يردّ كذبًا — ويصير التحذيرُ استثناءً لا يقوله أحد. فيُسأل هنا
         * ويُقال بكلمةٍ تُقرأ.
         */
        if (! is_dir(dirname($abs))) {
            throw new RuntimeException(__('تعذّر تهيئة مجلّد الأرشيف — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($abs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('تعذّر إنشاء ملفّ الأرشيف — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        $bid = (int) $business->id;
        $sheets = [];

        foreach (self::BOOKS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = $table === 'businesses'
                ? DB::table($table)->where('id', $bid)->get()
                : (Schema::hasColumn($table, 'business_id')
                    ? DB::table($table)->where('business_id', $bid)->get()
                    : self::viaParent($table, $bid));

            if ($rows === null) {
                continue;
            }

            $csv = self::csv($rows->map(fn ($r) => (array) $r)->all());
            $zip->addFromString("books/{$table}.csv", $csv);
            $sheets[$table] = ['rows' => $rows->count(), 'sha256' => hash('sha256', $csv)];
        }

        $files = self::copyInto($zip, $bid);

        $manifest = [
            'schema' => 1,
            'business' => ['id' => $bid, 'name' => $business->name, 'created_at' => (string) $business->created_at],
            'purged_at' => now()->toIso8601String(),
            'purged_by' => ['id' => $actor->id, 'name' => $actor->name],
            'retention' => 'سجلات محاسبية — تُحفظ ولا تُحذف. انظر config/purge.php وتقرير المهمة.',
            'books' => $sheets,
            'files' => $files,
            /* وعدّادُ كلّ جدولٍ مشمولٍ — الأرشيفُ يقول ما حُفظ وما مُحي */
            'row_counts' => $counts,
        ];

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        return [
            'path' => $rel,
            'sha256' => hash_file('sha256', $abs),
            'bytes' => (int) filesize($abs),
            'entries' => count($sheets) + count($files) + 1,
        ];
    }

    /**
     * جدولٌ يُكتب نصًّا — رؤوسٌ ثمّ صفوف.
     *
     * وCSV لا JSON: يُفتح في أيّ برنامج جداولَ يفتحه محاسب، ويُقرأ بعد عشر
     * سنين بلا أداةٍ خاصّة. و`\xEF\xBB\xBF` في صدره ليُقرأ العربيُّ في
     * Excel — بدونها تصير أسماءُ الأصناف رموزًا.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    private static function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($out, array_map(
                fn ($v) => $v === null ? '' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)),
                $row,
            ));
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv;
    }

    /** بنودٌ لا تحمل المعرّف — تُقرأ عبر أبيها */
    private static function viaParent(string $table, int $bid): ?Collection
    {
        $links = [
            'order_items' => ['orders', 'order_id'],
            'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
            'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
            'goods_receipt_note_items' => ['goods_receipt_notes', 'goods_receipt_note_id'],
            'payroll_lines' => ['payroll_runs', 'payroll_run_id'],
            'journal_lines' => ['journal_entries', 'journal_entry_id'],
            'customer_invoice_items' => ['customer_invoices', 'customer_invoice_id'],
            'customer_invoice_orders' => ['customer_invoices', 'customer_invoice_id'],
            'customer_payment_allocations' => ['customer_payments', 'customer_payment_id'],
        ];

        if (! isset($links[$table])) {
            return null;
        }

        [$parent, $key] = $links[$table];

        if (! Schema::hasTable($parent) || ! Schema::hasColumn($table, $key)) {
            return null;
        }

        return DB::table($table)
            ->whereIn($key, DB::table($parent)->where('business_id', $bid)->pluck('id'))
            ->get();
    }

    /**
     * مرفقاتُ الدفاتر تُنسَخ إلى الأرشيف قبل أن تُحذف نسختُها العاملة.
     *
     * @return list<array{path:string, bytes:int, sha256:string}>
     */
    private static function copyInto(ZipArchive $zip, int $bid): array
    {
        $disk = Storage::disk(self::DISK);
        $out = [];

        foreach (self::KEPT_DIRS as $dir) {
            $folder = $dir.'/'.$bid;

            if (! $disk->directoryExists($folder)) {
                continue;
            }

            foreach ($disk->allFiles($folder) as $file) {
                $bytes = $disk->get($file);

                if ($bytes === null) {
                    continue;
                }

                $zip->addFromString('files/'.$file, $bytes);
                $out[] = ['path' => $file, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
        }

        return $out;
    }

    /**
     * ولا يُمحى شيءٌ قبل أن يُفتح الأرشيفُ ويُقرأ.
     *
     * «كُتب الملفُّ» ليست «الأرشيفُ سليم»: قرصٌ امتلأ في منتصف الكتابة يترك
     * ZIP مبتورًا يُفتح ولا يُقرأ. فيُعاد فتحُه، ويُتحقَّق أنّ البيانَ فيه
     * وأنّه يُقرأ — وإلّا فلا محو.
     */
    private static function verify(string $rel): void
    {
        $abs = Storage::disk(self::DISK)->path($rel);
        $zip = new ZipArchive;

        if ($zip->open($abs, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('الأرشيف لا يُفتح — أُلغي الحذف ولم يُمسّ شيء.'));
        }

        $manifest = $zip->getFromName('manifest.json');
        $bad = $zip->numFiles < 2 || $manifest === false || json_decode((string) $manifest, true) === null;
        $zip->close();

        if ($bad) {
            throw new RuntimeException(__('الأرشيف ناقص — أُلغي الحذف ولم يُمسّ شيء.'));
        }
    }

    /* ═══════════════════ المحو ═══════════════════ */

    /**
     * صفوفُ القاعدة — في معاملةٍ واحدة، الأبناءُ قبل الآباء.
     *
     * @return int عددُ الحسابات التي مُحيت
     */
    private static function wipe(int $bid): int
    {
        return DB::transaction(function () use ($bid) {
            /*
             * أبناءٌ لا يحملون المعرّف — يُحذفون عبر آبائهم أوّلًا.
             *
             * و`journal_lines` أوّلُ السطر لا صدفةً: قيدُها إلى `accounts`
             * هو الـRESTRICT الوحيد في النظام، وحذفُ الحسابات قبلها يُسقط
             * المحوَ كلَّه.
             */
            $viaParent = [
                'journal_lines' => ['journal_entries', 'journal_entry_id'],
                'order_items' => ['orders', 'order_id'],
                'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
                'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
                'goods_receipt_note_items' => ['goods_receipt_notes', 'goods_receipt_note_id'],
                'payroll_lines' => ['payroll_runs', 'payroll_run_id'],
                'customer_invoice_items' => ['customer_invoices', 'customer_invoice_id'],
                'customer_invoice_orders' => ['customer_invoices', 'customer_invoice_id'],
                'customer_payment_allocations' => ['customer_payments', 'customer_payment_id'],
                'customer_addresses' => ['customers', 'customer_id'],
                'custom_order_fields' => ['custom_order_templates', 'custom_order_template_id'],
                'support_messages' => ['support_conversations', 'conversation_id'],
                'support_reads' => ['support_conversations', 'conversation_id'],
            ];

            foreach ($viaParent as $child => [$parent, $key]) {
                if (! Schema::hasTable($child) || ! Schema::hasTable($parent) || ! Schema::hasColumn($child, $key)) {
                    continue;
                }

                DB::table($child)
                    ->whereIn($key, DB::table($parent)->where('business_id', $bid)->pluck('id'))
                    ->delete();
            }

            $users = self::users($bid);

            foreach (self::scoped() as $table) {
                DB::table($table)->where('business_id', $bid)->delete();
            }

            DB::table('businesses')->where('id', $bid)->delete();

            return $users;
        });
    }

    /**
     * حساباتُ هذه الشركة وحدَها — وجلساتُها ورموزُها معها.
     *
     * ═══ ومديرُ المنصّة محميٌّ بالبناء ═══
     *
     * `users.business_id` عمودٌ واحد: الحسابُ لشركةٍ واحدة، و`null` لمن هو
     * فوق الشركات. فالاختيارُ بـ`where('business_id', $bid)` لا يبلغ مديرَ
     * منصّةٍ أبدًا — والشرطُ الصريحُ `whereNotNull` مكتوبٌ فوقه على كلّ حال،
     * فحارسٌ يُقرأ خيرٌ من حارسٍ يُستنتج.
     *
     * ولا عضويّةَ متعدّدةَ الشركات في هذا المخطَّط: لا جدولَ وصلٍ بين
     * المستخدم والشركة. فمتى أُضيفت وجب أن يُعاد النظرُ هنا — وحارسٌ في
     * الاختبارات يقف لها.
     *
     * @return int عددُ الحسابات المحذوفة
     */
    private static function users(int $bid): int
    {
        $ids = DB::table('users')->whereNotNull('business_id')->where('business_id', $bid)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $emails = DB::table('users')->whereIn('id', $ids)->pluck('email');

        /* الجلسةُ قبل الحساب: جلسةٌ حيّةٌ لحسابٍ محذوف بابٌ يبقى مفتوحًا لحظة */
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }

        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
        }

        if (Schema::hasTable('dismissed_notifications')) {
            DB::table('dismissed_notifications')->whereIn('user_id', $ids)->delete();
        }

        if (Schema::hasTable('branch_user')) {
            DB::table('branch_user')->whereIn('user_id', $ids)->delete();
        }

        /* وحذفٌ صريحٌ لا ناعم: `users` تحمل `deleted_at`، والمحوُ محوٌ */
        DB::table('users')->whereIn('id', $ids)->delete();

        return $ids->count();
    }

    /* ═══════════════════ الملفّات ═══════════════════ */

    /**
     * ملفّاتُ الشركة على القرص — بعد أن استقرّ المحوُ في القاعدة.
     *
     * وما يسقط منها يُجمع ويُقال: ملفٌّ بقي ليس سببًا لإعادة صفوفٍ مُحيت،
     * وسكوتٌ عنه يترك على القرص ما لا يعرف أحدٌ أنّه هناك.
     *
     * @param  list<string>  $publicPaths  مقروءةٌ قبل المحو — انظر `publicPaths`
     * @return array{deleted:int, failures:list<string>}
     */
    private static function files(int $bid, array $publicPaths): array
    {
        $deleted = 0;
        $failures = [];
        $local = Storage::disk(self::DISK);

        foreach ([...self::KEPT_DIRS, ...self::DROP_DIRS] as $dir) {
            $folder = $dir.'/'.$bid;

            try {
                if ($local->directoryExists($folder)) {
                    $deleted += count($local->allFiles($folder));
                    $local->deleteDirectory($folder);
                }
            } catch (\Throwable $e) {
                $failures[] = $folder.' — '.$e->getMessage();
            }
        }

        $public = Storage::disk('public');

        foreach ($publicPaths as $path) {
            try {
                if ($public->exists($path)) {
                    $public->delete($path);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $failures[] = $path.' — '.$e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'failures' => $failures];
    }

    /**
     * ملفّاتُ `public` التي يشير إليها صفٌّ من صفوف هذه الشركة.
     *
     * تُقرأ **قبل** المحو وتُحذف بعده: بعد المحو لا صفَّ يُقرأ منه المسار.
     *
     * @return list<string>
     */
    public static function publicPaths(int $bid): array
    {
        $out = [];

        foreach (self::PUBLIC_FILES as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $q = $table === 'businesses'
                ? DB::table($table)->where('id', $bid)
                : DB::table($table)->where('business_id', $bid);

            foreach ($q->pluck($column) as $path) {
                if (is_string($path) && $path !== '' && ! str_starts_with($path, 'http')) {
                    $out[] = $path;
                }
            }
        }

        $out = array_values(array_unique($out));

        /*
         * ولا يُحذف مسارٌ يشير إليه صفُّ شركةٍ أخرى.
         *
         * مجلّداتُ `public` مشتركة، وصورةٌ نُسخ مسارُها إلى صفٍّ في متجرٍ
         * آخر — باستنساخِ صنفٍ أو ببذرةٍ قديمة — تُحذف من تحته. والسؤالُ
         * يقع قبل المحو، حيث ما زالت الصفوفُ كلُّها تُقرأ.
         */
        return array_values(array_filter($out, fn (string $path) => ! self::sharedPath($path, $bid)));
    }

    /** أيشير إلى هذا المسار صفٌّ لا يخصّ هذه الشركة؟ */
    private static function sharedPath(string $path, int $bid): bool
    {
        foreach (self::PUBLIC_FILES as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $q = DB::table($table)->where($column, $path);

            $q = $table === 'businesses'
                ? $q->where('id', '!=', $bid)
                : $q->where(fn ($w) => $w->where('business_id', '!=', $bid)->orWhereNull('business_id'));

            if ($q->exists()) {
                return true;
            }
        }

        return false;
    }

    /* ═══════════════════ السجلّ ═══════════════════ */

    /**
     * سطرُ السجلّ — أقلُّ ما يُثبت الواقعة.
     *
     * مَن، ومتى (بختم الصفّ)، وأيُّ شركة، وكم صفًّا وحسابًا وملفًّا، وأين
     * الأرشيفُ وبصمتُه. ولا كلمةَ مرورٍ ولا رمزَ ولا بياناتِ زبونٍ: السجلُّ
     * يُثبت الفعلَ ولا ينسخ ما مُحي.
     *
     * @param  array{path:string, sha256:string, bytes:int, entries:int}  $archive
     * @param  array<string,int>  $counts
     * @param  array{deleted:int, failures:list<string>}  $files
     */
    private static function record(string $name, int $bid, array $archive, array $counts, int $users, array $files): string
    {
        return __('حذف الشركة نهائيًا: :name (#:id) — الصفوف: :rows، الجداول: :tables، الحسابات: :users، الملفّات: :files. الأرشيف :path بصمة :hash:fail', [
            'name' => $name,
            'id' => $bid,
            'rows' => array_sum($counts),
            'tables' => count($counts),
            'users' => $users,
            'files' => $files['deleted'],
            'path' => $archive['path'],
            'hash' => substr($archive['sha256'], 0, 16),
            'fail' => $files['failures'] === [] ? '' : ' — تعذّر حذف: '.implode(' / ', $files['failures']),
        ]);
    }
}
