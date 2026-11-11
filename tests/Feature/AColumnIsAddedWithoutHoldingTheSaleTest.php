<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\OrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * هجرتا نقاط الولاء على قاعدةٍ فيها بيانات — كما ستجري على الإنتاج.
 *
 * ═══ ما يُثبَت ═══
 *
 *   ١. الصفوفُ القائمة لا يتغيّر فيها حرف: الطلباتُ والفواتيرُ وبنودُها كما
 *      كانت، والأعمدةُ الجديدة تبدأ صفرًا أو فارغة — وهو ما وقع لها فعلًا.
 *   ٢. الرجوعُ (`down`) يرفض ما دامت في القاعدة نقاطٌ صُرفت على فاتورة أو
 *      بيعةٌ تنتظر المراجعة — فلا يمحو الرجوعُ ما يُعاد به للعميل. وبلاها
 *      يُسقط الأعمدةَ ويُعيدها الصعودُ كما كانت.
 *   ٣. والقفلُ لحظةٌ لا انتظار: تقريرٌ طويلٌ يُمسك الجدولَ فتتراجع الهجرةُ في
 *      مهلتها، ولا تقف خلفه البيعات — ثمّ تعيد المحاولة فتتمّ حين يُفلت.
 *
 * على PostgreSQL وحدها — هي قاعدةُ الإنتاج، و`lock_timeout` منها. ولا
 * `RefreshDatabase`: الهجرةُ تكتب في معاملاتها، والقفلُ يُمسكه اتّصالٌ آخر.
 * والأعمدةُ تُعاد في كلّ حال (`finally`) فلا يجد ما بعده جدولًا ناقصًا.
 */
class AColumnIsAddedWithoutHoldingTheSaleTest extends TestCase
{
    use DatabaseTruncation;

    private const INVOICES = '2026_11_11_090000_a_customer_invoice_remembers_the_points_it_spent.php';

    private const ORDERS = '2026_11_11_100000_an_offline_sale_keeps_its_points_review.php';

    private Business $shop;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — قاعدةُ الإنتاج، و`lock_timeout` منها');
        }

        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الهجرة', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '92222222', 'points' => 300, 'language' => 'ar',
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->restoreColumns();
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    /** ما قد يُسقطه اختبارٌ سقط في منتصفه — يعود قبل أن يراه غيره */
    private function restoreColumns(): void
    {
        if (! Schema::hasColumn('orders', 'loyalty_review')) {
            $this->migration(self::ORDERS)->up();
        }
        if (! Schema::hasColumn('customer_invoices', 'loyalty_points')) {
            $this->migration(self::INVOICES)->up();
        }
    }

    /** طلبٌ وفاتورةٌ ببندها — كما في متجرٍ قائم قبل الميزة */
    private function legacyRows(): array
    {
        Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'number' => 'INV-000041', 'status' => OrderStatus::COMPLETED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'discount' => 2.5, 'total' => 22.5, 'ordered_at' => now(),
        ]);
        CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => now()->toDateString()], [
            ['description' => 'باقة ورد', 'quantity' => 2, 'unit_price' => 30, 'discount' => 5],
        ]);

        return $this->snapshot();
    }

    /** الصفوفُ كما في القاعدة — بلا الأعمدة الجديدة، فيُقارن ما كان بما صار */
    private function snapshot(): array
    {
        $read = fn (string $table, array $without) => DB::table($table)->orderBy('id')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip($without)))->all();

        return [
            'orders' => $read('orders', ['loyalty_review']),
            'customer_invoices' => $read('customer_invoices', ['loyalty_points', 'loyalty_discount']),
            'customer_invoice_items' => $read('customer_invoice_items', ['loyalty_discount']),
        ];
    }

    /** اتّصالٌ آخر يقرأ `orders` في معاملةٍ مفتوحة `$seconds` ثانية — تقريرٌ طويل */
    private function holdOrders(float $seconds): array
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        // والانتظارُ حلقةٌ على الساعة لا نومةٌ واحدة: نومةٌ تقطعها إشارةٌ تعود قبل وقتها، فيُفلت القفلُ مبكّرًا
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->beginTransaction(); $p->exec("LOCK TABLE orders IN ACCESS SHARE MODE"); echo "locked\n"; fflush(STDOUT); $until = microtime(true) + %F; while (microtime(true) < $until) { usleep(20000); } $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            $seconds,
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        // وخطؤه يُقرأ إن لم يُمسك وحده: قراءةُ stderr تنتظر خروجَه — أي إفلاتَ القفل
        if (trim((string) fgets($pipes[1])) !== 'locked') {
            $this->fail('لم يُمسك الاتّصالُ الآخرُ الجدول: '.stream_get_contents($pipes[2]));
        }

        return [$proc, $pipes];
    }

    private function release(array $held): void
    {
        [$proc, $pipes] = $held;
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
    }

    public function test_existing_rows_are_untouched_and_the_new_columns_start_empty(): void
    {
        $before = $this->legacyRows();
        $invoices = $this->migration(self::INVOICES);
        $orders = $this->migration(self::ORDERS);

        // كأنّ الميزةَ لم تكن: تُسقط الأعمدة، ثمّ تُضاف على البيانات القائمة
        $orders->down();
        $invoices->down();
        $this->assertFalse(Schema::hasColumn('orders', 'loyalty_review'));
        $this->assertFalse(Schema::hasColumn('customer_invoices', 'loyalty_points'));

        $invoices->up();
        $orders->up();

        $this->assertSame($before, $this->snapshot(), 'تغيّر صفٌّ قائمٌ بالهجرة');
        $this->assertSame([null], DB::table('orders')->pluck('loyalty_review')->all());
        $this->assertSame([0], DB::table('customer_invoices')->pluck('loyalty_points')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([0.0], DB::table('customer_invoices')->pluck('loyalty_discount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([0.0], DB::table('customer_invoice_items')->pluck('loyalty_discount')->map(fn ($v) => (float) $v)->all());

        // ومرّةً ثانيةً لا تتغيّر — الصعودُ والهبوطُ والصعودُ يعيدان الشكلَ نفسَه
        $orders->down();
        $orders->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rolling_back_refuses_while_points_or_reviews_are_recorded(): void
    {
        $this->legacyRows();

        DB::table('orders')->update(['loyalty_review' => json_encode(['requested_points' => 500, 'spent_points' => 200])]);
        try {
            $this->migration(self::ORDERS)->down();
            $this->fail('سقط عمودٌ فيه بيعةٌ تنتظر المراجعة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يُسقط العمود', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_review'));

        DB::table('customer_invoices')->update(['loyalty_points' => 200, 'loyalty_discount' => 2]);
        try {
            $this->migration(self::INVOICES)->down();
            $this->fail('سقط عمودٌ فيه نقاطٌ صُرفت على فاتورة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا تُسقط العمودين', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('customer_invoices', 'loyalty_points'));
        $this->assertSame(200, (int) DB::table('customer_invoices')->value('loyalty_points'));
    }

    /**
     * تقريرٌ يُمسك الجدولَ أطولَ من مهلتها: تتراجع ولا تبقى في الطابور — والبيعُ يمضي.
     *
     * بلا مهلة كانت `ALTER TABLE` تنتظر التقريرَ بلا حدّ، وكلُّ بيعةٍ تصل في
     * الأثناء تقف خلفها. والمهلةُ هنا قصيرةٌ ليقصر الاختبار؛ على الإنتاج ثلاثُ ثوانٍ.
     */
    public function test_a_long_read_makes_it_give_way_instead_of_holding_every_sale(): void
    {
        $orders = $this->migration(self::ORDERS);
        $orders->down();

        $held = $this->holdOrders(2.0);
        $orders->lockTimeout = '200ms';
        $orders->attempts = 2;
        $orders->pause = 0.1;

        $started = microtime(true);
        try {
            $orders->up();
            $this->fail('أُضيف العمودُ والجدولُ ممسوك');
        } catch (QueryException $e) {
            $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? $e->getCode()));
        }
        $this->assertLessThan(1.5, microtime(true) - $started, 'انتظرت الهجرةُ أطولَ من مهلتها');
        $this->assertFalse(Schema::hasColumn('orders', 'loyalty_review'), 'وقع نصفُ التغيير');

        // والبيعُ لا ينتظرها — بيعةٌ تُكتب والتقريرُ ما زال مفتوحًا
        $sale = microtime(true);
        DB::table('orders')->insert([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'number' => 'INV-000042',
            'status' => OrderStatus::COMPLETED, 'is_held' => false, 'payment_method' => 'نقدي', 'subtotal' => 10, 'total' => 10,
            'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertLessThan(0.5, microtime(true) - $sale);

        $this->release($held);
        $orders->up();
        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_review'));
    }

    /** وتقريرٌ يُفلت الجدولَ بعد قليل: تعيد المحاولةَ فتتمّ — ولا يسقط النشر */
    public function test_a_short_read_is_waited_out_by_retrying(): void
    {
        $orders = $this->migration(self::ORDERS);
        $orders->down();

        $held = $this->holdOrders(1.0);
        $orders->lockTimeout = '300ms';
        $orders->attempts = 10;
        $orders->pause = 0.1;

        $started = microtime(true);
        $orders->up();
        $took = microtime(true) - $started;

        $this->assertTrue(Schema::hasColumn('orders', 'loyalty_review'));
        $this->assertGreaterThan(0.5, $took, 'لم تنتظر — فالجدولُ لم يكن ممسوكًا');
        $this->assertLessThan(5.0, $took);
        $this->release($held);
    }
}
