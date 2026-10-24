<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تصحيحان لبندين من فاتورةٍ مدفوعة في اللحظة نفسها — يُردّ لكلٍّ فرقُه وحده.
 *
 * ═══ والخطرُ الذي يُحرس ═══
 *
 * الثاني قرأ الفاتورة بخمسين، والأوّلُ يُمسكها مقفلةً ويُنزلها إلى أربعين.
 * فإن حسب الثاني فرقَه من نسخته — لا من الفاتورة التي أقفلها بعد انتظاره —
 * ردّ عشرين عن مزهريّةٍ بعشرة: العشرةُ الأولى تُردّ مرّتين.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`ACancelAndACorrectionAtOnceTest`: عمليّةٌ أخرى
 * باتّصالها تُمسك صفَّ الفاتورة وتكتب أثرَ التصحيح الأوّل ومعاملتُها مفتوحة،
 * وفي أثناء ذلك يصل الثاني بنسخته القديمة.
 */
class TwoCorrectionsOfOneSaleAtOnceRefundOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private Order $order;

    private OrderItem $rose;

    private OrderItem $vase;

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
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'race-refund@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $products = [];
        foreach (['وردة', 'مزهرية'] as $name) {
            $products[$name] = Product::create([
                'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 4,
                'quantity' => 10, 'alert_qty' => 1, 'active' => true,
            ]);
            BranchStock::adjust($this->shop->id, $branch->id, $products[$name]->id, 10);
        }

        $this->actingAs($this->owner)->withSession(['current_branch' => $branch->id])->postJson('/pos/checkout', [
            'items' => [
                ['id' => $products['وردة']->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10],
                ['id' => $products['مزهرية']->id, 'name' => 'مزهرية', 'qty' => 2, 'price' => 10],
            ],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('race', true),
        ])->assertOk();

        $this->order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->rose = OrderItem::where('order_id', $this->order->id)->where('product_id', $products['وردة']->id)->firstOrFail();
        $this->vase = OrderItem::where('order_id', $this->order->id)->where('product_id', $products['مزهرية']->id)->firstOrFail();
    }

    /**
     * التصحيحُ الأوّل جارٍ باتّصالٍ آخر: يقفل الفاتورة ويكتب وردتين بدل ثلاث.
     *
     * @return resource
     */
    private function aFirstCorrectionInProgress(float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = sprintf(
            'SELECT id FROM orders WHERE id = %1$d FOR UPDATE;'
            .' UPDATE order_items SET quantity = 2, total = 20 WHERE id = %2$d;'
            .' UPDATE orders SET subtotal = 40, total = 40, paid_after_sale = paid_after_sale - 10 WHERE id = %1$d;',
            $this->order->id, $this->rose->id,
        );
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الطلب الآخر');

        // يُمهَل حتى يقفل فعلًا قبل أن يصل التصحيحُ الثاني
        usleep(600_000);

        return $proc;
    }

    public function test_the_second_correction_refunds_its_own_line_once(): void
    {
        // الجهازُ الثاني فتح الفاتورة وهي بخمسين
        $older = Order::findOrFail($this->order->id);
        $this->assertSame(50.0, (float) $older->total);

        $other = $this->aFirstCorrectionInProgress();

        $started = microtime(true);
        OrderCorrection::setQuantity($older, $this->vase, 1, 'أعاد مزهرية', 'refunded', 'نقدي');
        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($other), 'سقط التصحيح الآخر');

        $this->assertGreaterThan(0.5, $waited, 'لم ينتظر التصحيحُ الثاني الأوّل');
        $this->assertSame(30.0, (float) $this->order->fresh()->total);
        $this->assertSame(
            10.0,
            round((float) Transaction::where('order_id', $this->order->id)->where('kind', Transaction::ORDER_REFUND)->sum('amount'), 3),
            'ردّ الثاني فرقَ الأوّل معه — من نسخةٍ قُرئت قبل القفل',
        );
        $this->assertSame(40.0, (float) OrderEdit::where('order_id', $this->order->id)->where('kind', OrderEdit::LINE)->value('order_total_before'));
    }
}
