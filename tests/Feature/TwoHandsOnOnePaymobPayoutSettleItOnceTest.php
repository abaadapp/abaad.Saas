<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * يدان على تحويلٍ واحد في اللحظة نفسها — تُسجَّل المدفوعةُ مرّةً ويُرحَّل التحويلُ مرّة.
 *
 * كلُّ خطوةٍ في التحويل الكبير (إضافةُ دفعة، ترحيل) تقفل صفَّ المتجر أوّلًا، ثمّ
 * تسأل: أهذه المدفوعةُ محجوزة؟ أهذا التحويلُ ما زال مسوّدة؟ فيدٌ وصلت والأخرى
 * تُمسك المتجرَ تنتظرها، ثمّ ترى ما كتبته فتُردّ — لا تكرار ولا قيدَ ثانٍ.
 * على PostgreSQL وحدها: SQLite لا تُبقي معاملتين مفتوحتين معًا.
 */
class TwoHandsOnOnePaymobPayoutSettleItOnceTest extends TestCase
{
    use DatabaseTruncation;
    use PaymobPayouts;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        Carbon::setTestNow('2027-02-10 10:00:00');
        app()->setLocale('ar');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        [$this->shop, $this->owner, $this->rose] = $this->openShop('two-hands', 'two-hands@abaadapp.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /**
     * يدٌ أخرى باتّصالٍ آخر: تقفل المتجرَ كما تقفله كلُّ خطوةِ تسوية، وتكتب ما كتبته.
     *
     * @return resource
     */
    private function anotherHand(string $sql, float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $sql = sprintf('SELECT id FROM businesses WHERE id = %d FOR UPDATE; ', $this->shop->id).$sql;
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل اليد الأخرى');
        usleep(600_000);

        return $proc;
    }

    private function draft(float $net): PaymobSettlement
    {
        return PaymobSettlements::openDraft($this->shop->id, ['fee' => 0, 'net' => $net, 'settled_on' => now()->toDateString()], $this->owner->id);
    }

    public function test_a_batch_waits_for_another_hand_reserving_one_of_its_payments_and_is_refused_whole(): void
    {
        $keys = $this->manyPaidLinks(600);
        Carbon::setTestNow(now()->addHour());
        [$mine, $theirs] = [$this->draft(600), $this->draft(1)];
        $link = (int) explode(':', $keys[300])[1];

        $other = $this->anotherHand(sprintf(
            "INSERT INTO paymob_settlement_items (business_id, paymob_settlement_id, customer_payment_link_id, source, amount, booked, created_at, updated_at) VALUES (%d, %d, %d, 'link:%d', 1, 'clearing', now(), now());",
            $this->shop->id, $theirs->id, $link, $link,
        ));

        $started = microtime(true);
        try {
            PaymobSettlements::addToDraft($this->shop->id, $mine->id, array_slice($keys, 0, PaymobSettlements::CHUNK));
            $refused = null;
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertGreaterThan(0.5, $waited, 'لم تنتظر الدفعةُ يدًا تُمسك المتجر');
        $this->assertNotNull($refused, 'حُجزت مدفوعةٌ في تحويلين');
        $this->assertSame(0, $mine->items()->count(), 'بقي من الدفعة المردودة شيء');
        $this->assertSame(1, $theirs->items()->count());
    }

    public function test_a_post_arriving_while_another_hand_posts_the_same_payout_waits_and_writes_nothing(): void
    {
        $this->manyPaidLinks(600);
        Carbon::setTestNow(now()->addHour());
        $draft = $this->draft(600);
        do {
            $step = PaymobSettlements::addWaitingToDraft($this->shop->id, $draft->id, now()->toDateString());
        } while ($step['remaining'] > 0);
        $before = JournalEntry::count();

        $other = $this->anotherHand(sprintf("UPDATE paymob_settlements SET status = 'posted' WHERE id = %d;", $draft->id));

        $started = microtime(true);
        try {
            PaymobSettlements::postDraft($this->shop->id, $draft->id, $this->owner->id);
            $refused = null;
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($other), 'سقطت اليدُ الأخرى');

        $this->assertGreaterThan(0.5, $waited, 'لم ينتظر الترحيلُ يدًا تُمسك المتجر');
        $this->assertNotNull($refused, 'رُحّل التحويلُ مرّتين');
        $this->assertStringContainsString('ليس مسوّدة', $refused);
        $this->assertSame($before, JournalEntry::count(), 'كُتب قيدٌ ثانٍ لتحويلٍ واحد');
    }
}
