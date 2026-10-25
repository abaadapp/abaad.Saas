<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\PaymobSettlement;
use App\Models\PaymobSettlementItem;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * نافذتان تسجّلان تحويلَ Paymob نفسه في اللحظة نفسها — تُسوّى المدفوعةُ مرّةً واحدة.
 *
 * ═══ والإثبات سباقٌ حقيقيّ ═══
 *
 * على PostgreSQL وحدها كـ`TwoCorrectionsOfOneSaleAtOnceRefundOnceTest`: عمليّةٌ
 * أخرى باتّصالها تكتب تسويةً وسطرَ المدفوعة ومعاملتُها مفتوحة، وفي أثناء ذلك
 * تصل النافذةُ الثانية بالمدفوعة نفسها.
 *
 * - الأولى تقفل المتجرَ كما يقفله `PaymobSettlements::record`: الثانية تنتظرها،
 *   ثمّ ترى السطرَ فتُردّ — ولا تكتب شيئًا.
 * - والأولى بابٌ نسي القفل: يبقى الطابورُ نفسُه — سطرُ التسوية يشير إلى المتجر
 *   (`business_id`)، فكتابتُه تأخذ على صفّ المتجر قفلًا مشتركًا (FOR KEY SHARE)
 *   يعارض `lockForUpdate`. فالثانيةُ تنتظر، ثمّ ترى السطرَ فتُردّ. والفهرسُ
 *   الفريدُ خلفهما (`APaymobPayoutNeverCountsTheBankTwiceTest`).
 */
class TwoPayoutsOfOnePaymentAtOnceSettleItOnceTest extends TestCase
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
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        [$this->shop, $this->owner, $this->rose] = $this->openShop('raceshop', 'race-payout@abaadapp.om');
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
     * النافذةُ الأولى باتّصالها: تكتب تسويةً وسطرَ المدفوعة وتُمسك معاملتَها.
     *
     * @return resource
     */
    private function aFirstWindow(int $linkId, bool $locksTheShop, float $holdSeconds = 1.5)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);
        $bid = $this->shop->id;
        $sql = ($locksTheShop ? "SELECT id FROM businesses WHERE id = {$bid} FOR UPDATE;" : '')
            ."INSERT INTO paymob_settlements (business_id, gross, fee, net, status, settled_on, created_at, updated_at) VALUES ({$bid}, 30, 0.9, 29.1, 'posted', CURRENT_DATE, now(), now());"
            .'INSERT INTO paymob_settlement_items (business_id, paymob_settlement_id, customer_payment_link_id, source, amount, booked, created_at, updated_at)'
            ." VALUES ({$bid}, currval(pg_get_serial_sequence('paymob_settlements', 'id')), {$linkId}, 'link:{$linkId}', 30, 'clearing', now(), now());";
        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
            .' $p->beginTransaction(); $p->exec(%s); usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            var_export($sql, true), (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل النافذة الأخرى');

        // تُمهَل حتى تكتب فعلًا قبل أن تصل الثانية
        usleep(600_000);

        return $proc;
    }

    private function race(bool $locksTheShop): string
    {
        $link = $this->paidLink(30);
        $entries = JournalEntry::count();
        $proc = $this->aFirstWindow($link->id, $locksTheShop);
        $started = microtime(true);

        try {
            $this->settle([$link], 0.9, 29.1);
            $message = 'ACCEPTED';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $waited = microtime(true) - $started;
        $this->assertSame(0, proc_close($proc), 'سقطت النافذةُ الأولى');

        $this->assertGreaterThan(0.4, $waited, 'لم تنتظر الثانيةُ الأولى — لم يُثبَت السباق');
        $this->assertSame(1, PaymobSettlement::count(), 'كُتبت تسويتان للمدفوعة نفسها');
        $this->assertSame(1, PaymobSettlementItem::count());
        $this->assertSame($entries, JournalEntry::count(), 'كتبت الثانيةُ قيدًا');
        $this->assertSame(30.0, $this->clearing(), 'تحرّك مالُ التسوية');

        return $message;
    }

    public function test_the_second_window_waits_for_the_shop_lock_then_is_refused(): void
    {
        $this->assertStringContainsString('سبقت تسويتُها', $this->race(true));
    }

    public function test_a_window_that_skipped_the_lock_still_queues_the_second_behind_it(): void
    {
        $this->assertStringContainsString('سبقت تسويتُها', $this->race(false));
    }
}
