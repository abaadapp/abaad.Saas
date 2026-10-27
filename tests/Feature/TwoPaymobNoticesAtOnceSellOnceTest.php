<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Lists\PaidLinksList;
use App\Support\Paymob\Amount;
use App\Support\StandaloneInvoiceSales;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إشعارا دفعٍ في اللحظة نفسِها — بيعٌ واحدٌ في «مبيعات اليوم» وتحصيلٌ واحد.
 *
 * `APaymentLinkIsNotASaleUntilItIsPaidTest` يكرّر الإشعارَ متتاليًا، وهذا
 * يُطلقه في عمليّتين مستقلّتين معًا: كلٌّ بمعاملته واتّصاله، فلا يحمي أحدَهما
 * إلّا قفلُ الرابط في القاعدة والفهرسُ الفريد على العمليّة.
 *
 * ═══ ويُثبَت على PostgreSQL وحدها ═══
 *
 * SQLite لا تُبقي معاملتين مفتوحتين معًا، فيُتخطّى هناك — كأخيه
 * `TwoPaymobNoticesAtOnceEarnOnePointsRowTest`.
 */
class TwoPaymobNoticesAtOnceSellOnceTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_notices_at_once_make_one_collection_and_one_sale_on_the_card(): void
    {
        $shop = Business::create(['name' => 'ورود التزامن', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'race@sells-once.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'عميل', 'phone' => '91112233']);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-race',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => now()->toDateString()], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 0],
        ]))->fresh();
        // رابطٌ مُنشأٌ كما يُنشئه `PaymentLinks::create` — بلا طلبٍ إلى Paymob هنا
        $link = CustomerPaymentLink::create([
            'business_id' => $shop->id, 'customer_id' => $customer->id, 'customer_invoice_id' => $invoice->id,
            'reference' => 'pay-race-sale', 'amount' => 20, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING,
            'provider_order_id' => '778001', 'is_live' => true, 'created_by' => $owner->id,
        ]);

        $today = fn () => StandaloneInvoiceSales::total($shop->id, today(), today()->addDay());
        $this->assertSame(0.0, $today(), 'رابطٌ معلّقٌ عُدّ قبل الدفع');

        $obj = [
            'amount_cents' => Amount::toMinor(20, 'OMR'), 'currency' => 'OMR', 'id' => 5560001,
            'is_refunded' => false, 'is_voided' => false, 'pending' => false, 'success' => true, 'is_live' => true,
            'order' => ['id' => 778001, 'merchant_order_id' => null],
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
        $this->race($obj, Paymob::signature($obj, 'hmac-race'));

        $this->assertSame(1, CustomerPayment::where('business_id', $shop->id)->count(), 'تحصيلان لدفعةٍ واحدة');
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame(20.0, $today(), 'بيعان لدفعةٍ واحدة');
        $this->assertSame(20.0, PaidLinksList::collected($shop->id, today(), today()->addDay()));
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
    }

    /** إشعاران في عمليّتين — يبدآن معًا عند لحظةٍ واحدة */
    private function race(array $obj, string $hmac): void
    {
        $script = tempnam(sys_get_temp_dir(), 'notice');
        file_put_contents($script, <<<'PHP'
<?php
[$self, $root, $obj, $hmac, $at] = $argv;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
while (microtime(true) < (float) $at) { usleep(200); }
App\Support\PaymentLinks::callback(json_decode($obj, true), $hmac);
echo 'done';
PHP);

        $c = DB::connection()->getConfig();
        $env = [
            'PATH' => getenv('PATH'), 'HOME' => sys_get_temp_dir(),
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
        ];
        $at = microtime(true) + 2.0;
        $runs = [];
        for ($i = 0; $i < 2; $i++) {
            $proc = proc_open([PHP_BINARY, $script, base_path(), json_encode($obj), $hmac, (string) $at],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $runs[] = [$proc, $pipes];
        }

        $out = '';
        foreach ($runs as [$proc, $pipes]) {
            $out .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $out);
        }
        @unlink($script);

        $this->assertSame(2, substr_count($out, 'done'), $out);
    }
}
