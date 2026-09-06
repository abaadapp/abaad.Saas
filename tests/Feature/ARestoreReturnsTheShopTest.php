<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\BackupService;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * نسخةٌ لا تُعيد المتجر ليست نسخة.
 *
 * كانت تحفظ سبعةَ عشرَ جدولًا من أكثر من ستّين، والاستعادةُ تحذف الآباء ثمّ
 * تُعيد ما في الملفّ — فما لم يُنسخ يسقط بالتتالي ولا يعود: مخزونُ الفروع،
 * والصناديقُ، ودفترُ الأستاذ، وسنداتُ الموردين، ونقاطُ العملاء. والرسالةُ
 * تقول «تمّت الاستعادة بنجاح».
 *
 * فما يُحرَس هنا ثلاثة: أن يُنسخ كلُّ جدولٍ للمتجر، وأن يعود بعد الاستعادة
 * كما كان، وأن **لا يمرّ جدولٌ جديد صامتًا** كما مرّ ثمانيةٌ وثلاثون.
 */
class ARestoreReturnsTheShopTest extends TestCase
{
    use RefreshDatabase;

    /** جداولُ النظام التي لا تخصّ متجرًا أصلًا */
    private const PLUMBING = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens',
    ];

    /* ======================= الحارس: لا جدولَ منسيّ ======================= */

    public function test_every_table_of_a_shop_is_either_backed_up_or_excluded_with_a_reason(): void
    {
        /*
         * قائمةٌ تُكتب باليد تنسى التاليَ دائمًا. وهذا ما وقع: ثمانيةٌ
         * وثلاثون جدولًا أُضيفت بعد كتابة النسخة ولم يلحظ أحدٌ غيابها، لأنّ
         * لا شيء كان يسأل.
         */
        $forgotten = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'] ?? $table;

            if (in_array($name, self::PLUMBING, true)) {
                continue;
            }

            if (in_array($name, TenantTables::ORDER, true) || isset(TenantTables::NOT_MINE[$name])) {
                continue;
            }

            $forgotten[] = $name;
        }

        sort($forgotten);

        $this->assertSame([], $forgotten,
            'جداولٌ لا يعرفها النسخُ ولا الاستثناء — صنّفها في TenantTables');
    }

    public function test_every_exclusion_says_why(): void
    {
        foreach (TenantTables::NOT_MINE as $table => $reason) {
            $this->assertNotSame('', trim((string) $reason), "استُثني {$table} بلا سبب");
        }
    }

    public function test_the_order_puts_every_parent_before_its_child(): void
    {
        /*
         * الترتيبُ ليس ذوقًا: الإدراجُ يمشي به والحذفُ بعكسه. وابنٌ يسبق أباه
         * يُردّ بمفتاحٍ خارجيّ فتسقط الاستعادة كلُّها — بعد أن حذفت.
         *
         * والفحصُ بالمفاتيح نفسها لا بالعين.
         */
        $position = array_flip(TenantTables::ORDER);
        $wrong = [];

        foreach (TenantTables::ORDER as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];

                if ($parent === $table || ! isset($position[$parent])) {
                    continue;
                }

                // الحلقةُ المعلومة تُحلّ بالتأجيل لا بالترتيب
                if (in_array($fk['columns'][0] ?? '', TenantTables::DEFERRED[$table] ?? [], true)) {
                    continue;
                }

                if ($position[$parent] > $position[$table]) {
                    $wrong[] = "{$table} قبل أبيه {$parent}";
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_no_stripped_secret_is_a_required_column(): void
    {
        /*
         * عمودٌ إلزاميٌّ يُنزع من الملفّ يُسقط الاستعادة كلَّها بـNOT NULL —
         * والحمايةُ تصير عطبًا. فإمّا أن يُستثنى جدولُه كلُّه بسببه، وإمّا
         * أن يُعاد توليدُ قيمته. ووقع هذا فعلًا في `document_links.token`.
         */
        foreach (TenantTables::SECRETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $required = collect(Schema::getColumns($table))
                ->reject(fn ($c) => $c['nullable'] || $c['default'] !== null)
                ->pluck('name')->all();

            $regenerated = TenantTables::REGENERATED[$table] ?? [];

            foreach (array_diff($columns, $regenerated) as $column) {
                $this->assertNotContains($column, $required,
                    "{$table}.{$column} إلزاميٌّ ويُنزع ولا يُولَّد — الاستعادةُ تسقط عليه");
            }
        }
    }

    public function test_a_child_table_is_read_through_its_parent(): void
    {
        // وإلّا نُسخت سطورُ متجرٍ آخر، أو لم تُنسخ سطورُ هذا المتجر أصلًا
        foreach (TenantTables::ORDER as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $hasColumn = Schema::hasColumn($table, 'business_id');
            $through = isset(TenantTables::THROUGH[$table]);

            $this->assertTrue($hasColumn || $through,
                "{$table} لا `business_id` فيه ولا أبَ في THROUGH — لا يُعرف لمن سطورُه");
        }
    }

    /* ========================= الرحلةُ ذهابًا وإيابًا ========================= */

    public function test_the_shop_comes_back_whole(): void
    {
        [$business, $owner] = $this->shop();
        $before = $this->census($business->id);

        // وما نُسخ ليس فارغًا: اختبارٌ يستعيد لا شيء يمرّ دائمًا
        $this->assertGreaterThan(8, count(array_filter($before)), 'المتجر التجريبيّ أفقر من أن يُختبر به');

        $file = $this->backup($business->id);

        $this->restore($owner, $file);

        $after = $this->census($business->id);

        /*
         * والاستعادةُ تترك أثرَها في السجلّ: صفٌّ يقول من استعاد ومتى.
         * فهو الفرقُ الوحيد المسموح — وفعلٌ بهذا الحجم لا يقع بلا أثر.
         */
        $this->assertSame($before['activity_logs'] + 1, $after['activity_logs'],
            'استعادةٌ لا تترك أثرًا في السجلّ');

        unset($before['activity_logs'], $after['activity_logs']);

        $this->assertSame($before, $after, 'عاد المتجر ناقصًا');
    }

    public function test_the_registers_survive_a_restore(): void
    {
        /*
         * `pos_devices` كان يسقط بالتتالي من `branches` ولا يُعاد — فكلُّ
         * صندوقٍ يتعطّل بعد الاستعادة ولا يعرف التاجر لماذا.
         */
        [$business, $owner] = $this->shop();
        $hash = DB::table('pos_devices')->where('business_id', $business->id)->value('token_hash');

        $this->restore($owner, $this->backup($business->id));

        $this->assertSame(1, DB::table('pos_devices')->where('business_id', $business->id)->count());
        $this->assertSame($hash, DB::table('pos_devices')->where('business_id', $business->id)->value('token_hash'),
            'الصندوق عاد بهويّةٍ أخرى — يلزم إقرانُه من جديد');
    }

    public function test_branch_stock_does_not_drift_from_the_product(): void
    {
        /*
         * `branch_stocks` لم يكن يُنسخ ولا يُحذف: فيبقى بأرقامٍ قديمة
         * و`products.quantity` يأتي من الملفّ. تباعدٌ لا يظهر إلا في الجرد.
         */
        [$business, $owner] = $this->shop();

        $file = $this->backup($business->id);

        // ثمّ يبيع المتجر بعد أخذ النسخة — الرفّ والبطاقة ينقصان معًا
        DB::table('branch_stocks')->where('business_id', $business->id)->update(['quantity' => 3]);
        DB::table('products')->where('business_id', $business->id)->update(['quantity' => 3]);

        $this->restore($owner, $file);

        $this->assertSame(20, (int) DB::table('branch_stocks')->where('business_id', $business->id)->value('quantity'));
        $this->assertSame(20, (int) DB::table('products')->where('business_id', $business->id)->value('quantity'));
    }

    public function test_a_supplier_bill_does_not_abort_the_restore(): void
    {
        /*
         * `supplier_invoices.supplier_id` مرتبطٌ بـ`restrict`: حذفُ المورّدين
         * قبله كان يُسقط المعاملة كلَّها — بعد أن حُذفت المنتجات.
         */
        [$business, $owner] = $this->shop();

        $this->assertSame(1, DB::table('supplier_invoices')->where('business_id', $business->id)->count());

        $this->restore($owner, $this->backup($business->id));

        $this->assertSame(1, DB::table('supplier_invoices')->where('business_id', $business->id)->count());
        $this->assertSame(1, DB::table('products')->where('business_id', $business->id)->count(),
            'سقطت الاستعادة بعد الحذف — والمتجر بلا منتجات');
    }

    public function test_the_ledger_comes_back_with_its_lines(): void
    {
        [$business, $owner] = $this->shop();

        $this->restore($owner, $this->backup($business->id));

        $this->assertSame(1, DB::table('journal_entries')->where('business_id', $business->id)->count());
        $this->assertSame(2, DB::table('journal_lines')
            ->whereIn('journal_entry_id', DB::table('journal_entries')->where('business_id', $business->id)->select('id'))
            ->count());
    }

    /* ============================== الحدود ============================== */

    public function test_a_neighbours_data_is_neither_read_nor_written(): void
    {
        [$business, $owner] = $this->shop();
        [$other] = $this->shop('الجار', 'j@abaadapp.om');

        $payload = BackupService::payload($business->id);

        foreach ($payload['products'] as $row) {
            $this->assertSame($business->id, (int) $row['business_id']);
        }

        $this->restore($owner, $this->backup($business->id));

        $this->assertSame(1, DB::table('products')->where('business_id', $other->id)->count(),
            'مسّت الاستعادةُ متجر الجار');
    }

    public function test_an_old_format_file_is_refused_before_anything_is_deleted(): void
    {
        [$business, $owner] = $this->shop();

        $old = json_encode(['meta' => ['app' => 'AbadPOS', 'version' => 2], 'products' => []]);
        $file = UploadedFile::fake()->createWithContent('old.json', $old);

        $this->restore($owner, $file);

        $this->assertSame(1, DB::table('products')->where('business_id', $business->id)->count(),
            'حُذف المتجر لأجل ملفٍّ لا يُعيده');
    }

    public function test_no_secret_leaves_in_the_file(): void
    {
        /*
         * والأعمدةُ مكتوبةٌ هنا بأسمائها لا مقروءةً من `SECRETS`.
         *
         * حارسٌ يقرأ القائمةَ التي يحرسها لا يحرس شيئًا: من حذف سطرًا منها
         * حذف سؤالَه معه، فيمرّ التسريبُ ويمرّ الاختبار.
         */
        [$business] = $this->shop();

        $payload = BackupService::payload($business->id);
        $file = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $never = [
            'users' => ['password', 'remember_token'],
            'whatsapp_connections' => ['access_token'],
        ];

        foreach ($never as $table => $columns) {
            $this->assertNotEmpty($payload[$table] ?? [], "لا سطرَ في {$table} — الحارس لا يحرس شيئًا");

            foreach ($payload[$table] as $row) {
                foreach ($columns as $column) {
                    $this->assertArrayNotHasKey($column, $row, "{$table}.{$column} خرج في ملفٍّ يُنزَّل");
                }
            }
        }

        // والقيمةُ نفسها لا تُلتقط من مكانٍ آخر في الملفّ
        $this->assertStringNotContainsString('EAAG-سرّ-', $file, 'رمزُ الوصول خرج في الملفّ');
    }

    /* ============================== أدوات ============================== */

    /** عددُ السطور في كل جدولٍ للمتجر — بصمةُ حالته */
    private function census(int $bid): array
    {
        $out = [];

        foreach (TenantTables::all() as $table) {
            if (Schema::hasTable($table)) {
                $out[$table] = TenantTables::scope($table, $bid)->count();
            }
        }

        return $out;
    }

    private function backup(int $bid): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('backup.json', BackupService::json($bid));
    }

    /**
     * استعادةٌ يُنتظر منها أن تنجح — والخمسمئة ليست نجاحًا.
     *
     * و`assertSessionHasNoErrors` وحدها لا تكفي: ردُّ الخمسمئة لا أخطاءَ
     * جلسةٍ فيه أصلًا، فكلُّ تأكيدٍ بعده يمرّ على متجرٍ لم يُمَسّ. وهكذا كان
     * حارسٌ كامل يمرّ على استعادةٍ لم تعمل قطّ.
     */
    private function restore(User $actor, UploadedFile $file): void
    {
        $this->actingAs($actor)->post(route('admin.backup.restore'), ['backup' => $file])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /** متجرٌ فيه سطرٌ في كلّ ما يُخشى عليه */
    private function shop(string $name = 'ورود مسقط', string $email = 'o@abaadapp.om'): array
    {
        $business = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $bid = $business->id;

        $owner = User::create([
            'business_id' => $bid, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $branch = DB::table('branches')->insertGetId(['business_id' => $bid, 'name' => 'الرئيسي', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['business_id' => $bid, 'name' => 'عام', 'created_at' => now(), 'updated_at' => now()]);

        $product = DB::table('products')->insertGetId([
            'business_id' => $bid, 'category_id' => $category, 'name' => 'قميص', 'sku' => 'SH-'.$bid,
            'price' => 10, 'cost' => 6, 'quantity' => 20, 'alert_qty' => 2, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('branch_stocks')->insert([
            'business_id' => $bid, 'branch_id' => $branch, 'product_id' => $product,
            'quantity' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pos_devices')->insert([
            'business_id' => $bid, 'branch_id' => $branch, 'name' => 'الصندوق الأول',
            'token_hash' => hash('sha256', 'device-'.$bid), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = DB::table('customers')->insertGetId([
            'business_id' => $bid, 'name' => 'زبون', 'phone' => '9000000'.$bid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = DB::table('orders')->insertGetId([
            'business_id' => $bid, 'branch_id' => $branch, 'customer_id' => $customer,
            'number' => 'INV-'.$bid.'-1', 'subtotal' => 10, 'tax' => 0.5, 'total' => 10.5,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false,
            'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order, 'product_id' => $product, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => 1, 'total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplier = DB::table('suppliers')->insertGetId(['business_id' => $bid, 'name' => 'مورّد', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_invoices')->insert([
            'business_id' => $bid, 'supplier_id' => $supplier, 'supplier_ref' => 'SI-1',
            'total' => 100, 'paid' => 0, 'issued_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = DB::table('accounts')->insertGetId([
            'business_id' => $bid, 'code' => '1100', 'name' => 'الصندوق',
            'type' => 'أصل', 'normal_side' => 'debit', 'system_key' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = DB::table('journal_entries')->insertGetId([
            'business_id' => $bid, 'number' => 'JE-1', 'description' => 'قيد',
            'entry_date' => now()->toDateString(), 'source' => 'يدوي',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => 10, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => 0, 'credit' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('settings')->insert(['business_id' => $bid, 'key' => 'vat_rate', 'value' => '5', 'created_at' => now(), 'updated_at' => now()]);

        // ورمزان سرّيّان: بلا سطرٍ يحملهما لا يحرس حارسُ التسريب شيئًا
        DB::table('whatsapp_connections')->insert([
            'business_id' => $bid, 'owner_type' => 'business', 'provider' => 'meta',
            'access_token' => 'EAAG-سرّ-'.$bid, 'status' => 'connected',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$business, $owner];
    }
}
