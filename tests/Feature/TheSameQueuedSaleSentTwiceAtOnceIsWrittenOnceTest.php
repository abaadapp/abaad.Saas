<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ‏بيعةُ الطابور نفسُها تُرسل مرّتين في اللحظة نفسها — فاتورةٌ واحدة.
 *
 * تبويبان للصندوق على جهازٍ واحد يقرآن الطابورَ نفسَه، أو جولةٌ آليّة وضغطةُ
 * «أعد الإرسال» معًا. فيصل الطلبان والبيعةُ لم تُكتب بعد: كلاهما يجتاز سؤالَ
 * المعرّف. والذي يمنع حقًّا قيدُ القاعدة (`orders_business_client_uuid_unique`):
 * الثاني ينتظر قفلَ الصنف، ثمّ ينكسر على القيد فتتراجع معاملتُه كلُّها — مخزونُها
 * ونقاطُها — ويُردّ إليه رقمُ الفاتورة الأولى.
 *
 * عمليّتان مستقلّتان باتّصالين مستقلّين، وكلٌّ يُبطئ كتابةَ الطلب ثانيةً فيتشابكان
 * فعلًا. على PostgreSQL وحدها: SQLite لا تُبقي معاملتين مفتوحتين معًا.
 */
class TheSameQueuedSaleSentTwiceAtOnceIsWrittenOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        $this->shop = Business::create(['name' => 'ورود الطابور', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        foreach (['loyalty_enabled' => '1', 'loyalty_earn_rate' => '1', 'vat_enabled' => '0'] as $key => $value) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $key, 'value' => $value]);
        }
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'twice@queue.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'مريم', 'phone' => '92222222', 'points' => 0, 'language' => 'ar',
        ]);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 50, 'cost' => 20,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /** ‏صندوقٌ يرسل البيعةَ من طابوره — بالمعرّف نفسِه في الاثنين، ويُبطئ كتابةَ الطلب */
    private const TAB = <<<'PHP'
App\Models\Order::creating(function () { usleep(1000000); });
$user = App\Models\User::findOrFail($ids['owner']);
auth()->setUser($user);
app('session')->put('current_branch', $ids['branch']);
$request = Illuminate\Http\Request::create('/pos/checkout', 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
], json_encode($ids['payload']));
$response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode().' '.$response->getContent();
PHP;

    private function race(int $count, array $ids): array
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
        $at = microtime(true) + 2.0;
        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            $script = tempnam(sys_get_temp_dir(), 'twice');
            file_put_contents($script, "<?php\n[\$self, \$root, \$at, \$ids] = \$argv;\n\$ids = json_decode(\$ids, true);\n"
                ."require \$root.'/vendor/autoload.php';\n\$app = require \$root.'/bootstrap/app.php';\n"
                ."\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\napp()->setLocale('ar');\n"
                ."while (microtime(true) < (float) \$at) { usleep(200); }\n".self::TAB."\n");
            $proc = proc_open([PHP_BINARY, $script, base_path(), (string) $at, json_encode($ids)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $runs[] = [$proc, $pipes, $script];
        }

        $outs = [];
        foreach ($runs as [$proc, $pipes, $script]) {
            $out = trim(stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            @unlink($script);
            $this->assertSame(0, $code, 'سقطت عمليّة: '.$out);
            $outs[] = $out;
        }

        return $outs;
    }

    public function test_two_tabs_sending_one_queued_sale_at_once_write_one_invoice_take_the_stock_once_and_earn_once(): void
    {
        $outs = $this->race(2, [
            'owner' => $this->owner->id,
            'branch' => $this->branch->id,
            'payload' => [
                'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 2]],
                'payment_method' => 'نقدي',
                'customer_id' => $this->customer->id,
                'client_uuid' => 'queued-once',
                'replay' => true,
            ],
        ]);

        $invoices = [];
        foreach ($outs as $out) {
            [$status, $body] = explode(' ', $out, 2);
            $this->assertSame('200', $status, 'كلا الطلبين يُجاب بنجاح: '.$out);
            $invoices[] = json_decode($body, true)['invoice'] ?? null;
        }

        $order = Order::where('business_id', $this->shop->id)->where('client_uuid', 'queued-once')->sole();
        $this->assertSame([$order->number, $order->number], $invoices, 'والفاتورةُ نفسُها للاثنين');
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertSame(8, (int) $this->product->fresh()->quantity, 'المخزونُ خُصم مرّةً واحدة');
        $this->assertSame(1, PointTransaction::where('customer_id', $this->customer->id)->where('type', 'earn')->count(), 'والنقاطُ كُسبت مرّةً');
        $this->assertSame((int) $order->points_earned, (int) $this->customer->fresh()->points);
    }
}
