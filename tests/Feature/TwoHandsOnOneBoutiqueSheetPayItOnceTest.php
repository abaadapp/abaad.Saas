<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثلاثُ أيدٍ في اللحظة نفسها على ورقةٍ واحدة — تُصدر أو تُسدَّد أو تُستردّ مرّةً واحدة.
 *
 * والحكمُ للقاعدة لا لشاشةٍ قرأت قبل أن تكتب: قفلُ الصفّ في السداد والاسترداد،
 * وحجزُ البنود في الإصدار. وهنا عمليّاتٌ حقيقيّة باتّصالاتٍ مستقلّة على PostgreSQL —
 * فـSQLite لا تُبقي معاملتين معًا.
 */
class TwoHandsOnOneBoutiqueSheetPayItOnceTest extends TestCase
{
    use DatabaseTruncation;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    /** البيع على الورق نفسه بلا صندوق — الطلبُ وبنودُه ومستحقُّه كما يكتبها البيع */
    private function sell(): Order
    {
        $silver = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة', 'price' => 7, 'cost' => 1, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id]);
        $set = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 20, 'cost' => 1, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $set->id, 'component_product_id' => $silver->id, 'quantity' => 1, 'wastage_percent' => 0]);
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $set->id, 'name' => 'باقة', 'qty' => 1, 'price' => 20], ['id' => $silver->id, 'name' => 'قطعة', 'qty' => 1, 'price' => 7]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function hand(string $body, float $startAt): array
    {
        $c = DB::connection()->getConfig();
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_TIMEZONE' => (string) config('app.timezone'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];
        $code = sprintf(
            'require %s; $app = require %s; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' Illuminate\Support\Carbon::setTestNow("2027-03-01 10:00:00");'
            .' while (microtime(true) < %F) { usleep(300); }'
            .' $out = "ok"; try { %s } catch (App\Exceptions\SettlementRefused $e) { $out = "refused"; } catch (Throwable $e) { $out = get_class($e).": ".$e->getMessage(); }'
            .' echo json_encode($out);',
            var_export(base_path('vendor/autoload.php'), true), var_export(base_path('bootstrap/app.php'), true), $startAt, $body,
        );
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc, 'تعذّر تشغيل اليد الأخرى');

        return [$proc, $pipes];
    }

    /** @return list<string> */
    private function hands(string $body, int $n = 3): array
    {
        $start = microtime(true) + 1.2;
        $running = array_map(fn () => $this->hand($body, $start), range(1, $n));
        $out = [];
        foreach ($running as [$proc, $pipes]) {
            $out[] = (string) json_decode(stream_get_contents($pipes[1]));
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($err), 'يدٌ انهارت: '.$err);
        }

        return $out;
    }

    private function journals(): int
    {
        return DB::table('journal_entries')->where('business_id', $this->shop->id)->count();
    }

    public function test_three_hands_issuing_one_month_make_one_sheet(): void
    {
        $this->sell();
        $out = $this->hands(sprintf(
            'App\Support\Boutiques::settle(App\Models\Business::find(%d), App\Models\Boutique::find(%d), "%s");',
            $this->shop->id, $this->lama->id, self::PERIOD,
        ));
        sort($out);
        $this->assertSame(['ok', 'refused', 'refused'], $out, 'أكثرُ من يدٍ صدرت ورقتُها');
        $this->assertSame(1, BoutiqueSettlement::count());
        $this->assertSame(1, Expense::where('business_id', $this->shop->id)->count(), 'مصروفٌ ثانٍ لورقةٍ واحدة');
        $this->assertSame(1, OrderItem::whereNotNull('boutique_settlement_id')->count());
        $this->assertSame(1, BoutiqueComponentDue::whereNotNull('boutique_settlement_id')->count());
    }

    public function test_three_hands_paying_one_sheet_pay_it_once(): void
    {
        $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        // والمفتاحُ أُطفئ بعدها: السدادُ ما زال واحدًا
        Business::whereKey($this->shop->id)->update(['boutiques_enabled' => false, 'boutique_components_enabled' => false]);
        $before = Ledger::balance($this->shop->id, 'cash');
        $entries = $this->journals();
        $out = $this->hands(sprintf('App\Support\BoutiqueDues::pay(App\Models\BoutiqueSettlement::find(%d), "نقدي");', $sheet->id));
        $this->assertSame(['ok', 'ok', 'ok'], $out, 'القفلُ يُصفّ اللاحقين بلا خطأ');
        $this->assertSame($entries + 1, $this->journals(), 'قيدُ سدادٍ ثانٍ');
        $this->assertEquals($before - 7, Ledger::balance($this->shop->id, 'cash'), 'خرج نقدٌ أكثر من المستحق');
        $this->assertSame(5.6, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3), 'ما بقي صافي المنفرد وحده (٧ − ٢٠٪) — مصروفُه يُسدَّد من المستحقّات');
    }

    public function test_three_hands_refunding_one_credit_refund_it_once(): void
    {
        $order = $this->sell();
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');
        $this->actingAs($this->owner);
        $items = OrderItem::where('order_id', $order->id)->whereNotNull('boutique_id')->get();
        OrderCorrection::cancel($order->fresh(), 'رُدّ', boutiqueReturned: true, boutiqueLines: $items->mapWithKeys(fn ($i) => [$i->id => ['sound' => (int) $i->quantity, 'damaged' => 0]])->all());
        $credit = round(array_sum(BoutiqueDues::credits($this->shop->id, $this->lama->id)), 3);
        $this->assertEquals(7, $credit);

        $out = $this->hands(sprintf(
            'App\Support\BoutiqueDues::refund(App\Models\Business::find(%d), App\Models\Boutique::find(%d), "نقدي");',
            $this->shop->id, $this->lama->id,
        ));
        sort($out);
        $this->assertSame(['ok', 'refused', 'refused'], $out, 'رصيدٌ واحدٌ استُردّ أكثر من مرّة');
        $this->assertSame(1, BoutiqueSettlement::where('kind', BoutiqueSettlement::REFUND)->count());
        $this->assertEquals(0, round(array_sum(BoutiqueDues::credits($this->shop->id, $this->lama->id)), 3));
        $this->assertEquals(7, round((float) BoutiqueSettlement::where('kind', BoutiqueSettlement::REFUND)->sum('components_credit'), 3));
    }
}
