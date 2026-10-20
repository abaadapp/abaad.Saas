<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * آخرُ فرصةٍ في الكوبون تُستعمل مرّةً واحدة — بين الصندوق والموقع والبطاقة، ولو تزامنت.
 *
 * ═══ ما يُقاس ═══
 *
 * كلُّ بابٍ يستهلك الكوبون يأخذ قفلَ صفِّه قبل أن يسأل «بقيت فرصة؟»: الصندوق
 * (`findCoupon(lock: true)`)، والدفعُ عند الاستلام (`quote(lock: true)`)، والآن
 * صفحةُ البطاقة (`CouponLimits::hold`). فإن أمسكه بابٌ انتظر الآخرُ حتّى يُودِع،
 * ثمّ قرأ ما كتبه فرُدّ.
 *
 * وكانت صفحةُ البطاقة وحدَها تسأل بلا قفل، فتقرأ «لا شيء» وبابٌ آخر في منتصف
 * كتابته — فتحجز فرصةً أُخذت.
 *
 * ═══ والإثباتُ سباقٌ حقيقيّ لا قراءةُ مصدر ═══
 *
 * SQLite لا تقفل صفًّا فلا سباقَ فيها — يُتخطّى عليها صريحًا، ويُجرى على
 * PostgreSQL (وظائفُ CI الثلاث تشغّله). واتّصالٌ ثانٍ في عمليّةٍ أخرى يفعل ما
 * يفعله البابُ الآخر بالترتيب نفسِه — يُمسك صفَّ الكوبون، ويكتب حجزَه أو بيعتَه،
 * ويُبقي معاملتَه مفتوحةً لحظةً — وفي أثنائها يطلب البابُ المقيس.
 *
 * ولا `RefreshDatabase`: معاملتُها تُخفي الصفوفَ عن الاتّصال الثاني.
 */
class TheLastCouponUseIsSpentOnceAcrossTillsAndPagesTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $cashier;

    private Product $rose;

    private Coupon $coupon;

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

        /*
         * ومتجرُ سعود في الإنتاج رقمُه ٥، وله قواعدُ خاصّة (اسمٌ بالإنجليزيّة،
         * وكرتُ هديّة). ورقمُ الصفّ هنا يتبع تسلسلَ القاعدة — فقد يصير ٥.
         */
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@race.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        // مفاتيحُ تجريبيّةٌ — لا مفتاحَ حقيقيٍّ في اختبار، ولا دفعةَ حقيقيّة
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876',
            'active' => true,
        ]);

        // فرصةٌ واحدةٌ إجمالًا
        $this->coupon = Coupon::create([
            'business_id' => $this->shop->id, 'code' => 'SAVE5', 'type' => 'مبلغ', 'value' => 5,
            'min_order' => 0, 'active' => true, 'used_count' => 0, 'max_uses' => 1,
        ]);
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

    private function web(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'card',
            'name' => 'مريم', 'phone' => '96899110001',
            'date' => now()->addDays(2)->format('Y-m-d'), 'slot' => '9 ص – 12 م',
            'promo' => 'SAVE5',
        ];
    }

    private function pdoCode(string $body): string
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']);

        return sprintf(
            '$p = new PDO(%s, %s, %s); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); %s',
            var_export($dsn, true),
            var_export((string) $c['username'], true),
            var_export((string) ($c['password'] ?? ''), true),
            $body,
        );
    }

    /**
     * البابُ الآخر: يُمسك صفَّ الكوبون، ويكتب، ويُبقي معاملتَه مفتوحةً لحظة.
     *
     * @return resource
     */
    private function otherDoor(string $writes, float $holdSeconds = 1.5)
    {
        $code = $this->pdoCode(sprintf(
            '$p->beginTransaction();'
            // القفلُ على صفّ الكوبون نفسِه — وهو ما ينتظره البابُ المقيس
            .' $p->exec("SELECT id FROM coupons WHERE id = %d FOR UPDATE");'
            .' %s usleep(%d); $p->commit();',
            $this->coupon->id,
            $writes,
            (int) ($holdSeconds * 1_000_000),
        ));

        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'تعذّر تشغيل البابِ الآخر');

        // يُمهَل حتّى يمسك الصفَّ فعلًا قبل أن يطلب البابُ المقيس
        usleep(600_000);

        return $proc;
    }

    /** زبونٌ آخر في صفحة البنك على آخر فرصة — حجزُه يُكتب الآن ولم يُودَع بعد */
    private function anotherCardIsBeingHeld()
    {
        $intent = StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'web-race-held', 'payload' => [],
            'amount' => 15, 'currency' => 'OMR', 'status' => StorePaymentIntent::PENDING,
            'expires_at' => now()->addHour(),
        ]);

        return $this->otherDoor(sprintf(
            '$p->exec("INSERT INTO coupon_redemptions'
            .' (business_id, coupon_id, order_id, store_payment_intent_id, customer_id, customer_key, reserved_until, redeemed_at, created_at, updated_at)'
            .' VALUES (%d, %d, NULL, %d, NULL, \'phone:96899330003\', \'%s\', now(), now(), now())");',
            $this->shop->id, $this->coupon->id, $intent->id,
            // بتوقيت التطبيق لا القاعدة — فلا ينقضي الحجزُ أو يطول بفرق المنطقة
            now()->addHour()->toDateTimeString(),
        ));
    }

    /** بيعةٌ على آخر فرصة تُكتب الآن في صندوقٍ آخر ولم تُودَع بعد */
    private function anotherSaleIsBeingWritten()
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'RACE-1', 'customer_name' => 'سالم',
            'branch' => 'الخوير', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 5, 'tax' => 0, 'total' => 15,
            'coupon_code' => 'SAVE5', 'coupon_discount' => 5,
            'ordered_at' => now(), 'status' => 'مكتمل',
        ]);

        return $this->otherDoor(sprintf(
            '$p->exec("UPDATE coupons SET used_count = used_count + 1 WHERE id = %d");'
            .' $p->exec("INSERT INTO coupon_redemptions'
            .' (business_id, coupon_id, order_id, customer_id, customer_key, redeemed_at, created_at, updated_at)'
            .' VALUES (%d, %d, %d, NULL, \'phone:96899330003\', now(), now(), now())");',
            $this->coupon->id, $this->shop->id, $this->coupon->id, $order->id,
        ));
    }

    /** كم فرصةً أُخذت: ما استُعمل، وما يُحجز الآن */
    private function slotsTaken(): int
    {
        $held = DB::table('coupon_redemptions')
            ->where('coupon_id', $this->coupon->id)
            ->whereNull('order_id')
            ->where('reserved_until', '>', now())
            ->count();

        return (int) $this->coupon->fresh()->used_count + $held;
    }

    private function bankOpens(): void
    {
        Http::fake(['oman.paymob.com/v1/intention/' => Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201)]);
    }

    /* ═════════════ البطاقةُ في أثناء بابٍ آخر ═════════════ */

    public function test_a_payment_page_waits_for_another_hold_and_is_refused(): void
    {
        $this->bankOpens();
        $proc = $this->anotherCardIsBeingHeld();

        try {
            $res = $this->postJson('/s/ribbon/checkout', $this->web());
        } finally {
            proc_close($proc);
        }

        $res->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $res->json('errors'), 'فُتحت صفحةُ دفعٍ على فرصةٍ يحجزها غيرُه');
        $this->assertSame(1, $this->slotsTaken(), 'أُخذت الفرصةُ الواحدة مرّتين');
        Http::assertNothingSent();
    }

    public function test_a_payment_page_waits_for_a_sale_at_the_till_and_is_refused(): void
    {
        $this->bankOpens();
        $proc = $this->anotherSaleIsBeingWritten();

        try {
            $res = $this->postJson('/s/ribbon/checkout', $this->web());
        } finally {
            proc_close($proc);
        }

        $res->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $res->json('errors'), 'حُجزت فرصةٌ بِيعت في الصندوق للتوّ');
        $this->assertSame(1, $this->slotsTaken(), 'أُخذت الفرصةُ الواحدة مرّتين');
        Http::assertNothingSent();
    }

    /* ═════════════ والدفعُ عند الاستلام والصندوقُ في أثناء صفحة بطاقة ═════════════ */

    public function test_cash_on_delivery_waits_for_a_card_hold_and_is_refused(): void
    {
        $proc = $this->anotherCardIsBeingHeld();

        try {
            $res = $this->postJson('/s/ribbon/checkout', $this->web(['pay' => 'cod']));
        } finally {
            proc_close($proc);
        }

        $res->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $res->json('errors'));
        $this->assertSame(0, Order::where('coupon_code', 'SAVE5')->count(), 'كُتب طلبٌ بخصمٍ محجوزٍ لغيره');
        $this->assertSame(1, $this->slotsTaken());
    }

    public function test_the_till_waits_for_a_card_hold_and_is_refused(): void
    {
        $proc = $this->anotherCardIsBeingHeld();

        try {
            $res = $this->actingAs($this->cashier)->postJson('/pos/checkout', [
                'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('r', true),
                'coupon_code' => 'SAVE5',
            ]);
        } finally {
            proc_close($proc);
        }

        $res->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->assertSame(0, Order::where('coupon_code', 'SAVE5')->count(), 'باع الصندوقُ بخصمٍ محجوزٍ لزبون البطاقة');
        $this->assertSame(1, $this->slotsTaken());
    }

    /* ═════════════ والبنكُ يُطرق وصفُّ الكوبون حرّ ═════════════ */

    public function test_the_coupon_row_is_free_while_the_bank_is_asked(): void
    {
        /*
         * الطَّرقُ يأخذ حتّى عشرين ثانية. فلو بقي صفُّ الكوبون مقفلًا في أثنائه
         * لَانتظر كلُّ صندوقٍ يبيع بالكود نفسِه — والبنكُ بطيءٌ أحيانًا.
         *
         * فيُسأل من اتّصالٍ آخر في لحظة الطَّرق: «أيمكن إمساكُ الصفّ الآن؟» بلا
         * انتظار (`NOWAIT`). ويُكتب الجوابُ رقمَ خروج: صفرٌ حرّ، وثلاثةٌ مقفل.
         */
        $probe = $this->pdoCode(sprintf(
            '$p->beginTransaction();'
            .' try { $p->exec("SELECT id FROM coupons WHERE id = %d FOR UPDATE NOWAIT"); $p->rollBack(); exit(0); }'
            .' catch (PDOException $e) { exit(3); }',
            $this->coupon->id,
        ));

        $exit = null;
        Http::fake(function (Request $request) use ($probe, &$exit) {
            $proc = proc_open([PHP_BINARY, '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $exit = proc_close($proc);

            return Http::response(['client_secret' => 'csk_x', 'intention_order_id' => '777'], 201);
        });

        $this->postJson('/s/ribbon/checkout', $this->web())->assertOk();

        $this->assertSame(0, $exit, 'بقي صفُّ الكوبون مقفلًا والبنكُ يُطرق — فينتظره كلُّ صندوق');
        $this->assertSame(1, $this->slotsTaken());
    }
}
