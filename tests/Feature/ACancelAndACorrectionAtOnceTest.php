<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * إلغاءٌ وتصحيحُ بندٍ على الفاتورة نفسها في اللحظة نفسها — أحدُهما بعد الآخر.
 *
 * ═══ والخطرُ الذي يُحرس ═══
 *
 * الإلغاءُ يقفل صفَّ الفاتورة (`cancel`)، وتصحيحُ الكمّيّة كان يقفل البندَ
 * وحده. فلا ينتظر أحدُهما الآخر: يردّ الإلغاءُ ثلاثَ وردٍ، ويردّ التصحيحُ
 * «من ثلاثٍ إلى واحدة» اثنتين — على فاتورةٍ ما عادت تملك شيئًا تردّه.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`AClosingDateAndAnEntryWrittenAtOnceTest`: عمليّةٌ
 * أخرى باتّصالها تُمسك صفَّ الفاتورة وتكتبها «ملغي» ومعاملتُها مفتوحة، وفي
 * أثناء ذلك يصل التصحيح. ولا `RefreshDatabase`: معاملتُه تُخفي الصفوف عن
 * الاتّصال الآخر، فتُودَع وتُكنَس بعد كلّ اختبار.
 */
class ACancelAndACorrectionAtOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private Order $order;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر السباق', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'race-cancel@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('وردة');
        $vase = $this->product('مزهرية');

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10],
                ['id' => $vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 10],
            ],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('race', true),
        ])->assertOk();
        $this->order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function product(string $name): Product
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 4,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->shop->id, $this->branch->id, $p->id, 10);

        return $p;
    }

    /**
     * إلغاءٌ جارٍ باتّصالٍ آخر: يقفل الفاتورة كما يقفلها `cancel` ويكتبها ملغاة.
     *
     * @return resource
     */
    private function aCancelInProgress(float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = sprintf("SELECT id FROM orders WHERE id = %d FOR UPDATE; UPDATE orders SET status = '%s' WHERE id = %d;", $this->order->id, Order::CANCELLED, $this->order->id);
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الطلب الآخر');

        // يُمهَل حتى يقفل فعلًا قبل أن يصل التصحيح
        usleep(600_000);

        return $proc;
    }

    public function test_a_correction_arriving_while_the_sale_is_being_cancelled_waits_and_is_refused(): void
    {
        $this->assertSame(7, (int) $this->rose->fresh()->quantity);
        $line = OrderItem::where('order_id', $this->order->id)->where('product_id', $this->rose->id)->firstOrFail();

        $other = $this->aCancelInProgress();

        $started = microtime(true);
        try {
            OrderCorrection::setQuantity($this->order, $line, 1, 'تصحيحٌ وصل والإلغاءُ جارٍ');
            $refused = null;
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($other), 'سقط الإلغاء الآخر');

        $this->assertGreaterThan(0.5, $waited, 'لم ينتظر التصحيحُ إلغاءً يُمسك الفاتورة');
        $this->assertNotNull($refused, 'مضى التصحيحُ على فاتورةٍ أُلغيت قبله بلحظة');
        $this->assertStringContainsString('ملغاة', $refused);
        $this->assertSame(7, (int) $this->rose->fresh()->quantity, 'ردّ التصحيحُ إلى الرفّ وردًا من فاتورةٍ ملغاة');
        $this->assertSame(3, (int) $line->fresh()->quantity);
    }
}
