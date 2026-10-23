<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إشعارا Paymob في اللحظة نفسها من عمليّتين — تحصيلٌ واحد ونقاطٌ مرّةً واحدة.
 *
 * Paymob تُعيد الإشعارَ حين لا يصلها ردٌّ في وقته، وقد تصل الإعادةُ والأوّلُ لم
 * يُودَع بعد. فيُشغَّل هنا `PaymentLinks::callback` في عمليّتين مستقلّتين
 * باتّصالين بالقاعدة، تبدآن معًا عند لحظةٍ واحدة. والتحصيلُ تحت قفل صفّ الرابط
 * (`settle`)، والنقاطُ تحت قفله ثانيةً وقفلِ صفّ العميل، و`source_key` فريدٌ
 * خلفهما (`InvoiceLoyalty`).
 *
 * على PostgreSQL وحدها: SQLite لا تُبقي معاملتين مفتوحتين معًا. ولا
 * `RefreshDatabase`: معاملتُها تُخفي الصفوفَ عن العمليّتين، فتُودَع وتُكنَس بعده.
 */
class TwoPaymobNoticesAtOnceEarnOnePointsRowTest extends TestCase
{
    use DatabaseTruncation;

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
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }
    }

    /** عمليّةٌ مستقلّة تُقلع التطبيق وتنتظر اللحظةَ ثمّ تُسلّم الإشعار — @return resource */
    private function notice(string $script, array $obj, string $hmac, float $at)
    {
        $c = DB::connection()->getConfig();
        $env = [
            'PATH' => getenv('PATH'), 'HOME' => sys_get_temp_dir(),
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
        ];
        $proc = proc_open([PHP_BINARY, $script, base_path(), json_encode($obj), $hmac, (string) $at],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc);

        return [$proc, $pipes];
    }

    public function test_two_notices_at_once_write_one_payment_and_one_points_row(): void
    {
        $shop = Business::create(['name' => 'ورود التزامن', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        Setting::create(['business_id' => $shop->id, 'key' => 'loyalty_enabled', 'value' => '1']);
        Setting::create(['business_id' => $shop->id, 'key' => 'loyalty_earn_rate', 'value' => '5']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'race@points.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'عميل', 'phone' => '91112222', 'points' => 7]);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-race',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::TEST,
        ]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => now()->toDateString()], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0],
        ]))->fresh();
        // رابطٌ مُنشأٌ كما يُنشئه `PaymentLinks::create` — بلا طلبٍ إلى Paymob هنا
        $link = CustomerPaymentLink::create([
            'business_id' => $shop->id, 'customer_id' => $customer->id, 'customer_invoice_id' => $invoice->id,
            'reference' => 'pay-race-1', 'amount' => 10, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING,
            'provider_order_id' => '777001', 'is_live' => false, 'created_by' => $owner->id,
        ]);

        $obj = [
            'amount_cents' => Amount::toMinor(10, 'OMR'), 'created_at' => '2026-10-25T10:05:00.000000', 'currency' => 'OMR',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 5550001, 'integration_id' => 111,
            'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'order' => ['id' => 777001, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true, 'is_live' => false,
        ];
        $hmac = Paymob::signature($obj, 'hmac-race');

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

        $at = microtime(true) + 2.0;
        $runs = [$this->notice($script, $obj, $hmac, $at), $this->notice($script, $obj, $hmac, $at)];
        $out = '';
        foreach ($runs as [$proc, $pipes]) {
            $out .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $out);
        }
        @unlink($script);

        $this->assertSame(2, substr_count($out, 'done'), $out);
        $this->assertSame(1, CustomerPayment::where('business_id', $shop->id)->count(), 'تحصيلان لدفعةٍ واحدة');
        $this->assertSame(1, PointTransaction::where('customer_id', $customer->id)->count(), 'حركتا نقاط لدفعةٍ واحدة');
        $this->assertSame(57, (int) $customer->fresh()->points, '٧ + ٥٠');
        $this->assertSame(57, (int) PointTransaction::where('customer_id', $customer->id)->value('balance_after'));
        $meta = (array) CustomerPaymentLink::find($link->id)->meta;
        $this->assertSame('awarded', $meta['loyalty']);
        $this->assertArrayNotHasKey('loyalty_error', $meta, 'ولا خطأَ كُتب على الرابط من الخاسر');
    }
}
