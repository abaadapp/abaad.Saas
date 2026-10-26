<?php

namespace Tests\Feature;

use App\Models\AddonOrder;
use App\Models\Invoice;
use App\Support\Addons\AddonOrders;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriptionAddons;
use Tests\TestCase;

/**
 * طلبان لإضافةٍ واحدةٍ في اللحظة نفسها — طلبٌ واحد، ولا فاتورتان.
 *
 * نافذةٌ أولى باتّصالها تكتب طلبَها وتُمسك معاملتها، والثانيةُ تصل وهي مفتوحة:
 *
 *   - تأخذ الأولى قفلَ المتجر (كما يأخذه `AddonOrders::request`): تنتظرها الثانية
 *     ثمّ تجد طلبَها فتعيده.
 *   - تتخطّى الأولى القفل: يوقفها المفتاحُ الفريد (`open_key`)، فتعيد الثانيةُ طلبَ
 *     الأولى ولا تكتب ثانيًا.
 */
class TwoRequestsForOneAddonAtOnceMakeOneOrderTest extends TestCase
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

    /** @return resource */
    private function aFirstWindow(int $businessId, int $addonId, bool $locksTheShop)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = ($locksTheShop ? "SELECT id FROM businesses WHERE id = {$businessId} FOR UPDATE;" : '')
            .'INSERT INTO addon_orders (business_id, subscription_addon_id, kind, cycle, quantity, unit_price, amount, status, open_key, created_at, updated_at)'
            ." VALUES ({$businessId}, {$addonId}, 'new', 'monthly', 1, 3.9, 3.9, 'requested', 'new:{$businessId}:{$addonId}', now(), now());";
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(1500000); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل النافذة الأخرى');
        usleep(600_000);

        return $proc;
    }

    private function race(bool $locksTheShop): void
    {
        [$business] = $this->shop('basic', 'race'.(int) $locksTheShop.'@shop.om');
        $addon = $this->addon('loyalty');
        $proc = $this->aFirstWindow($business->id, $addon->id, $locksTheShop);
        $started = microtime(true);

        $result = AddonOrders::request($business, $addon, 'monthly');

        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($proc), 'سقطت النافذةُ الأولى');
        $this->assertGreaterThan(0.4, $waited, 'لم تنتظر الثانيةُ الأولى — لم يُثبَت السباق');

        $this->assertFalse($result['created'], 'كتبت الثانيةُ طلبًا');
        $this->assertSame(1, AddonOrder::where('business_id', $business->id)->count(), 'طلبان لإضافةٍ واحدة');
        $this->assertSame($result['order']->id, AddonOrder::where('business_id', $business->id)->value('id'));

        // واعتمادُه مرّتين فاتورةٌ واحدة
        AddonOrders::approve($result['order']);
        try {
            AddonOrders::approve($result['order']);
        } catch (\DomainException) {
        }
        $this->assertSame(1, Invoice::where('business_id', $business->id)->count());
    }

    public function test_the_second_request_waits_for_the_shop_lock_and_returns_the_first(): void
    {
        $this->race(true);
    }

    public function test_a_first_window_that_skipped_the_lock_is_stopped_by_the_unique_key(): void
    {
        $this->race(false);
    }
}
