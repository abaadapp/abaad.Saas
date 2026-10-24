<?php

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\BranchTransfer;
use App\Models\InventoryMovement;
use App\Support\BranchTransfers;
use App\Support\TransferRefused;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnterpriseShop;
use Tests\TestCase;

/**
 * سباقٌ حقيقيّ على سند النقل — جهازٌ آخر يمسك الصفَّ والسندُ يُرسَل.
 *
 * ═══ والإثبات سباقٌ لا محاكاة ═══
 *
 * SQLite لا تقفل صفًّا، فلا سباقَ فيها ولا يُثبَت. فيُجرى على PostgreSQL —
 * قاعدةِ الإنتاج — باتّصالٍ ثانٍ من عمليّةٍ أخرى (`proc_open`) يفتح معاملةً
 * ويكتب ويمسك القفل، ثمّ يطلب الاختبارُ الإرسالَ وهو ممسوك:
 *
 *   ١ — إرسالٌ ثانٍ للسند نفسِه يقع والأوّلُ لم يُغلق معاملته: ينتظر القفل، ثمّ
 *       يقرأ «قيد النقل» فيُردّ — ولا تخرج البضاعةُ مرّتين.
 *   ٢ — بيعٌ يأخذ من الرفّ في اللحظة نفسها: الإرسالُ ينتظر، ثمّ يجد الرفَّ أقلّ
 *       ممّا يُرسل فيُردّ كاملًا — ولا ينزل رصيدٌ تحت الصفر.
 */
class TwoHandsOnOneTransferMoveTheStockOnceTest extends TestCase
{
    use DatabaseTruncation;
    use EnterpriseShop;

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تقفل صفًّا فلا سباقَ فيها');
        }

        app()->setLocale('ar');
        $this->makeEnterpriseShop();
    }

    /** يُطلب ويُعتمد ويُجهَّز — والإرسالُ هو المتسابَق عليه */
    private function prepared(int $qty): BranchTransfer
    {
        $t = BranchTransfers::create($this->muscatManager, [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'lines' => [['product_id' => $this->rose->id, 'quantity' => $qty]],
        ]);
        BranchTransfers::approve($t, $this->owner);
        BranchTransfers::prepare($t->fresh(), $this->muscatManager);

        return $t->fresh();
    }

    /** اتّصالٌ آخر يكتب هذه الجمل في معاملةٍ ويمسكها ثمّ يُغلقها */
    private function otherHand(array $statements, float $holdSeconds)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = implode('', array_map(fn ($s) => '$p->exec('.var_export($s, true).');', $statements));
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->beginTransaction(); %s usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            $sql, (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الاتّصال الآخر');

        // يُمهَل حتى يمسك الصفوفَ فعلًا قبل أن يطلب الاختبار
        usleep(600_000);

        return $proc;
    }

    public function test_a_second_dispatch_waits_then_finds_the_goods_already_gone(): void
    {
        $t = $this->prepared(10);

        // الجهازُ الآخر يُرسل السندَ نفسَه: يقفل صفَّه، ويخصم الرفّ، ويكتب «قيد النقل»
        $proc = $this->otherHand([
            "SELECT id FROM branch_transfers WHERE id = {$t->id} FOR UPDATE",
            "UPDATE branch_stocks SET quantity = quantity - 10 WHERE branch_id = {$this->muscat->id} AND product_id = {$this->rose->id}",
            "UPDATE products SET quantity = quantity - 10 WHERE id = {$this->rose->id}",
            "UPDATE branch_transfers SET status = 'in_transit' WHERE id = {$t->id}",
        ], holdSeconds: 1.5);

        try {
            $refused = null;
            try {
                BranchTransfers::dispatch($t, $this->muscatManager);
            } catch (TransferRefused $e) {
                $refused = $e->getMessage();
            }
        } finally {
            proc_close($proc);
        }

        $this->assertNotNull($refused, 'أُرسل السندُ مرّتين');
        $this->assertSame(40, (int) BranchStock::where('branch_id', $this->muscat->id)->where('product_id', $this->rose->id)->value('quantity'),
            'خرجت البضاعةُ مرّتين من رفّ المصدر');
        $this->assertSame(44, (int) $this->rose->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::where('reference', $t->number)->count(), 'كتب الإرسالُ المردودُ حركة');
    }

    public function test_a_dispatch_racing_a_sale_on_the_last_units_is_refused_whole(): void
    {
        $t = $this->prepared(50);

        // بيعٌ يأخذ ستًّا من مسقط في اللحظة نفسها
        $proc = $this->otherHand([
            "UPDATE products SET quantity = quantity - 6 WHERE id = {$this->rose->id}",
            "UPDATE branch_stocks SET quantity = quantity - 6 WHERE branch_id = {$this->muscat->id} AND product_id = {$this->rose->id}",
        ], holdSeconds: 1.5);

        try {
            $refused = null;
            try {
                BranchTransfers::dispatch($t, $this->muscatManager);
            } catch (TransferRefused $e) {
                $refused = $e->getMessage();
            }
        } finally {
            proc_close($proc);
        }

        $this->assertNotNull($refused, 'أُرسلت خمسون والرفُّ أربعٌ وأربعون');
        $this->assertSame(44, (int) BranchStock::where('branch_id', $this->muscat->id)->where('product_id', $this->rose->id)->value('quantity'),
            'نزل الرفُّ تحت ما بقي فيه');
        $this->assertSame(48, (int) $this->rose->fresh()->quantity);
        $this->assertSame(BranchTransfer::PREPARING, $t->fresh()->status);
        $this->assertSame(0, InventoryMovement::where('reference', $t->number)->count());
    }
}
