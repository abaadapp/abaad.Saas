<?php

namespace Tests\Feature;

use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Support\Addons\AddonOrders;
use App\Support\Billing;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * سدادان لفاتورة إضافةٍ واحدةٍ في اللحظة نفسها — استحقاقٌ واحد، ودورةٌ واحدة.
 *
 * نافذةٌ أولى باتّصالها تقفل الفاتورةَ كما يقفلها `Billing::markPaid`، فتقيّدها
 * مدفوعةً وتفعّل استحقاقَها وتُمسك معاملتها. والثانيةُ تسجّل السدادَ نفسَه وهي
 * مفتوحة: تنتظر القفل، ثمّ تجد الفاتورةَ مدفوعةً فلا تفعّل ولا تمدّ.
 */
class TwoPaymentsOfOneAddonInvoiceAtOnceOpenItOnceTest extends TestCase
{
    use DatabaseTruncation;
    use SubscriptionAddons;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        $this->seedPlans();
        $this->launch();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_the_second_payment_waits_for_the_first_and_opens_nothing_more(): void
    {
        [$business] = $this->shop('basic', 'race@shop.om');
        $order = AddonOrders::approve(AddonOrders::request($business, $this->addon('pos'), 'monthly')['order']);
        $ends = now()->addMonthNoOverflow()->toDateString();

        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = "SELECT id FROM invoices WHERE id = {$order->invoice_id} FOR UPDATE;"
            ."UPDATE invoices SET status = '".Billing::PAID."' WHERE id = {$order->invoice_id};"
            .'INSERT INTO business_addons (business_id, subscription_addon_id, quantity, source, cycle, unit_price, starts_at, ends_at, renews, created_at, updated_at)'
            ." VALUES ({$business->id}, {$order->subscription_addon_id}, 1, 'purchase', 'monthly', 9.9, '".now()->toDateString()."', '{$ends}', true, now(), now());"
            ."UPDATE addon_orders SET status = 'paid', open_key = NULL, business_addon_id = currval(pg_get_serial_sequence('business_addons', 'id')) WHERE id = {$order->id};";
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(1500000); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل النافذة الأخرى');
        usleep(600_000);
        $started = microtime(true);

        Billing::markPaid(Invoice::findOrFail($order->invoice_id));

        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($proc), 'سقطت النافذةُ الأولى');
        $this->assertGreaterThan(0.4, $waited, 'لم ينتظر السدادُ الثاني الأوّلَ — لم يُثبَت السباق');

        $this->assertSame(1, BusinessAddon::where('business_id', $business->id)->count(), 'سدادان فتحا استحقاقين');
        $this->assertSame($ends, BusinessAddon::where('business_id', $business->id)->sole()->ends_at->toDateString(), 'سدادان مدّا الدورة');
        $this->assertSame(AddonOrders::PAID, $order->fresh()->status);
        $this->assertSame(1, Invoice::where('business_id', $business->id)->count());
    }
}
