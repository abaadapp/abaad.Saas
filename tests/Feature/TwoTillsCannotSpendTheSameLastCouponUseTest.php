<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فرصةُ الزبون الأخيرةُ لا تُستهلك مرّتين — ولو تزامن صندوقان عليها.
 *
 * ═══ العطب الذي يُقاس ═══
 *
 * حدُّ «مرّةٌ واحدةٌ لكلّ زبون» يُفحص بعدَّ صفوفٍ في `coupon_redemptions`.
 * وعدٌّ يُقرأ ثمّ يُكتب ليس حارسًا: صندوقان يبيعان للزبون نفسِه في اللحظة
 * نفسها يقرأ كلاهما «صفرًا»، ويمرّ كلاهما، فيُخصم الكوبونُ مرّتين على حدٍّ
 * واحد.
 *
 * والحارسُ أنّ الفحصَ يقع **تحت قفل صفّ الكوبون** الذي يأخذه
 * `findCoupon(lock: true)`، في المعاملة التي تكتب الطلب. فالثاني ينتظر
 * الأوّلَ ثمّ يقرأ «واحدًا» فيُردّ.
 *
 * ═══ والإثبات سباقٌ حقيقيّ لا قراءةُ مصدر ═══
 *
 * SQLite لا تقفل صفًّا فلا سباقَ فيها — يُتخطّى عليها صريحًا، ويُجرى على
 * PostgreSQL (وظيفةُ CI الثانية تشغّله). واتّصالٌ ثانٍ في عمليّةٍ أخرى يمسك
 * صفَّ الكوبون ويكتب استعمالًا للزبون نفسِه ويُبقي معاملتَه مفتوحةً لحظةً،
 * وفي أثنائها يطلب الصندوق.
 *
 * ولا `RefreshDatabase`: معاملتُها تُخفي الصفوفَ عن الاتّصال الثاني.
 */
class TwoTillsCannotSpendTheSameLastCouponUseTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $cashier;

    private Product $rose;

    private Coupon $coupon;

    private Customer $ahmed;

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
        app()->setLocale('ar');

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تقفل صفًّا فلا سباقَ فيها');
        }

        $this->shop = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@coupon-race.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 20, 'cost' => 5,
            'quantity' => 500, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->ahmed = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'أحمد', 'phone' => '91234567', 'language' => 'ar',
        ]);

        $this->coupon = Coupon::create([
            'business_id' => $this->shop->id, 'code' => 'SAVE5', 'type' => 'مبلغ', 'value' => 5,
            'min_order' => 0, 'active' => true, 'used_count' => 0, 'per_customer_limit' => 1,
        ]);
    }

    /**
     * الصندوقُ الآخر: يمسك صفَّ الكوبون ويكتب استعمالًا ثمّ يُودع.
     *
     * @return resource
     */
    private function otherTillSpendsHisOnlyUse(float $holdSeconds)
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);

        // طلبٌ مكتملٌ بأقلّ ما يصحّ — لأنّ سجلَّ الاستعمال يشير إليه
        $order = Order::create([
            'business_id' => $this->shop->id,
            'number' => 'RACE-1',
            'customer_name' => 'أحمد',
            'customer_id' => $this->ahmed->id,
            'branch' => 'الرئيسي',
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 5, 'tax' => 0, 'total' => 15,
            'coupon_code' => 'SAVE5', 'coupon_discount' => 5,
            'ordered_at' => now(), 'status' => 'مكتمل',
        ]);

        $code = sprintf(
            '$p = new PDO(%s, %s, %s); $p->beginTransaction();'
            // القفلُ على صفّ الكوبون نفسِه — وهو ما ينتظره الصندوق
            .' $p->exec("SELECT id FROM coupons WHERE id = %d FOR UPDATE");'
            .' $p->exec("UPDATE coupons SET used_count = used_count + 1 WHERE id = %d");'
            .' $p->exec("INSERT INTO coupon_redemptions'
            .' (business_id, coupon_id, order_id, customer_id, customer_key, redeemed_at, created_at, updated_at)'
            .' VALUES (%d, %d, %d, %d, \'phone:96891234567\', now(), now(), now())");'
            .' usleep(%d); $p->commit();',
            var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) ($c['password'] ?? ''), true),
            $this->coupon->id, $this->coupon->id,
            $this->shop->id, $this->coupon->id, $order->id, $this->ahmed->id,
            (int) ($holdSeconds * 1_000_000),
        );

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل الصندوق الآخر');

        // يُمهَل حتّى يمسك الصفَّ فعلًا قبل أن يطلب الصندوق
        usleep(600_000);

        return $proc;
    }

    public function test_two_tills_cannot_both_spend_his_only_use(): void
    {
        $proc = $this->otherTillSpendsHisOnlyUse(holdSeconds: 1.5);

        try {
            $response = $this->actingAs($this->cashier)->postJson('/pos/checkout', [
                'items' => [['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('r', true),
                'customer' => 'أحمد',
                'customer_id' => $this->ahmed->id,
                'coupon_code' => 'SAVE5',
            ]);
        } finally {
            proc_close($proc);
        }

        $response->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->assertSame(
            1,
            DB::table('coupon_redemptions')->where('coupon_id', $this->coupon->id)->count(),
            'استُهلك حدُّ الزبون مرّتين — الفحصُ يُقرأ بلا قفل',
        );
        $this->assertSame(1, (int) $this->coupon->fresh()->used_count);
        $this->assertSame(1, Order::where('coupon_code', 'SAVE5')->count(), 'كُتبت فاتورةٌ بخصمٍ لا يملكه');
    }
}
