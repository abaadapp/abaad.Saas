<?php

namespace Tests\Feature;

use App\Mail\LowStockMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AlertMetrics;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * التنبيهُ المرسَل يطيع مفتاحَه، ويقيس بما يقيس به الجرس.
 *
 * ═══ العطبان ═══
 *
 * ١ — مفتاحُ «التنبيهات الذكية» كان يُطفئ `alerts:smart` وحدَها. و`alerts:low-stock`
 *     مهمّةٌ ثانيةٌ لا تسأل عنه: يطفئه التاجر فيصله بريدُ المخزون كلَّ صباحٍ
 *     من بابٍ آخر، ولا مقبضَ في الشاشة يوقفه. ومقبضٌ يُدير بعضَ ما تقوله
 *     لافتتُه أسوأ من مقبضٍ لا يُدير شيئًا — يُجرَّب فيبدو أنّه عمل.
 *
 * ٢ — والقاعدتان تفترقان: الجرسُ يقرأ `quantity < alert_qty`، والبريدُ كان
 *     يقرأ `statusFor` فيعدّ صنفًا كميّتُه صفرٌ وحدُّه صفر. فمن كتب
 *     `alert_qty = 0` على صنفٍ يقول «لا تنبّهني بهذا» يسكت عنه الجرسُ ويصله
 *     بريدٌ كلَّ صباح، ولا شيءَ يفسّر الاختلاف.
 */
class ASentAlertObeysItsSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->biz = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'email' => 'shop@abaad.om',
        ]);
        Branch::create(['business_id' => $this->biz->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Product::create([
            'business_id' => $this->biz->id, 'name' => 'صنف نادر',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 10,
        ]);
    }

    private function switchOff(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->biz->id, 'key' => 'notify_smart_alerts'],
            ['value' => '0'],
        );
    }

    private function lowStockMails(): int
    {
        return count(Mail::sent(LowStockMail::class)) + count(Mail::queued(LowStockMail::class));
    }

    /* ─────────── المفتاح ─────────── */

    /** والبريدُ يخرج حين يكون المفتاح مُشعَلًا */
    public function test_the_low_stock_mail_goes_out_when_the_switch_is_on(): void
    {
        Mail::fake();

        $this->artisan('alerts:low-stock')->assertSuccessful();

        $this->assertSame(1, $this->lowStockMails(), 'لم يخرج بريدُ المخزون والمفتاح مُشعَل');
    }

    /** ويسكت حين يُطفأ — وهو ما تَعِد به لافتتُه */
    public function test_switching_off_smart_alerts_silences_the_low_stock_mail(): void
    {
        $this->switchOff();
        Mail::fake();

        $this->artisan('alerts:low-stock')->assertSuccessful();

        $this->assertSame(0, $this->lowStockMails(), 'أُرسل بريدُ المخزون والمفتاح مُطفأ');
    }

    /** والمفتاحُ لمتجره وحده: إطفاءُ جارٍ لا يُسكت بريدي */
    public function test_a_neighbours_switch_does_not_silence_mine(): void
    {
        $neighbour = Business::create([
            'name' => 'جار', 'type' => 'عام', 'status' => 'نشط', 'email' => 'n@abaad.om',
        ]);
        Setting::create(['business_id' => $neighbour->id, 'key' => 'notify_smart_alerts', 'value' => '0']);
        Product::create([
            'business_id' => $neighbour->id, 'name' => 'صنف الجار',
            'price' => 5, 'quantity' => 1, 'alert_qty' => 10,
        ]);

        Mail::fake();
        $this->artisan('alerts:low-stock');

        $this->assertSame(1, $this->lowStockMails(), 'إطفاءُ متجرٍ أسكت بريدَ غيره أو أرسل لمن أطفأ');
    }

    /* ─────────── والقاعدةُ واحدة ─────────── */

    /**
     * «لا تنبّهني بهذا» تُطاع في البريد كما تُطاع في الجرس.
     *
     * والصفرُ اختيارٌ لا سهو: الافتراضُ عشرة، ومن كتب صفرًا قصده.
     */
    public function test_the_mail_and_the_bell_pick_the_same_products(): void
    {
        $muted = Product::create([
            'business_id' => $this->biz->id, 'name' => 'لا تنبّهني',
            'price' => 5, 'quantity' => 0, 'alert_qty' => 0,
        ]);

        $this->actingAs($this->owner);
        $bell = collect(Demo::allNotifications())->pluck('key');
        $mail = Product::where('business_id', $this->biz->id)->needsStockAlert()->pluck('id');

        $this->assertFalse($bell->contains('low-'.$muted->id), 'الجرس ينبّه بصنفٍ أُسكت');
        $this->assertFalse($mail->contains($muted->id), 'البريد ينبّه بصنفٍ أُسكت');

        // وما يستحقّ التنبيه يصل الاثنين
        $this->assertTrue($bell->contains('low-1'));
        $this->assertTrue($mail->contains(1));
    }

    /**
     * والبريدُ الخارجُ فعلًا يحمل ما يحمله الجرسُ — لا ما تقوله دالّة.
     *
     * فحصُ القاعدةِ وحدَها لا يمسك مهمّةً تسأل غيرَها: الحارسُ يقرأ ما وُضع
     * في الرسالة نفسِها.
     */
    public function test_the_mail_that_goes_out_carries_the_same_products(): void
    {
        $muted = Product::create([
            'business_id' => $this->biz->id, 'name' => 'لا تنبّهني',
            'price' => 5, 'quantity' => 0, 'alert_qty' => 0,
        ]);

        Mail::fake();
        $this->artisan('alerts:low-stock')->assertSuccessful();

        $sent = collect(Mail::sent(LowStockMail::class))->first()
            ?? collect(Mail::queued(LowStockMail::class))->first();

        $this->assertNotNull($sent, 'لم يخرج بريدٌ فلا يُقاس شيء');

        $names = collect($sent->products)->pluck('name');
        $this->assertTrue($names->contains('صنف نادر'), 'سقط صنفٌ يستحقّ التنبيه من البريد');
        $this->assertFalse($names->contains($muted->name), 'البريدُ يحمل صنفًا أُسكت تنبيهُه');
    }

    /** ومقياسُ التنبيه المخصَّص يقرأ القاعدةَ نفسَها */
    public function test_the_custom_alert_metric_reads_the_same_rule(): void
    {
        Product::create([
            'business_id' => $this->biz->id, 'name' => 'لا تنبّهني',
            'price' => 5, 'quantity' => 0, 'alert_qty' => 0,
        ]);

        $this->assertSame(
            (float) Product::where('business_id', $this->biz->id)->needsStockAlert()->count(),
            AlertMetrics::value('low_stock_products', $this->biz->id),
            'المقياس يعدّ غيرَ ما يعدّه الجرس',
        );
    }

    /* ─────────── وربحُ اليوم يُقاس بتكلفة ما خرج ─────────── */

    /**
     * ═══ العطب ═══
     *
     * `today_profit` كانت تقرأ التكلفة من `products.cost` — سعرِ البطاقة
     * **الآن** — لا من `order_items.cost`، وهي لقطةُ ما خرج من الرفّ التي
     * يقيّدها الدفتر.
     *
     * فتُصحَّح تكلفةُ صنفٍ بعد الظهر فيتبدّل «ربحُ اليوم» لبيعاتٍ وقعت في
     * الصباح — رقمٌ مضى يتغيّر خلف ظهر صاحبه.
     */
    public function test_today_profit_reads_the_cost_recorded_at_the_sale(): void
    {
        $p = Product::create([
            'business_id' => $this->biz->id, 'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 50,
        ]);
        $order = Order::create([
            'business_id' => $this->biz->id, 'number' => 'S-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => 'صنف',
            'quantity' => 1, 'price' => 10, 'total' => 10, 'cost' => 4,
        ]);

        $this->assertEqualsWithDelta(6.0, AlertMetrics::value('today_profit', $this->biz->id), 0.0005);

        // تُصحَّح البطاقةُ اليوم — ولا تُعيد كتابة ربحِ بيعةٍ مضت
        $p->update(['cost' => 9]);

        $this->assertEqualsWithDelta(6.0, AlertMetrics::value('today_profit', $this->biz->id), 0.0005,
            'تصحيحُ بطاقةِ صنفٍ أعاد حسابَ ربحِ بيعةٍ وقعت قبله');
    }

    /**
     * والطلبُ المخصَّص له تكلفة — ولا صنفَ له.
     *
     * `product_id` فارغ، فالانضمامُ الأيسر كان يردّ لا شيء و`COALESCE` تكتب
     * صفرًا. فمحلُّ وردٍ يبيع باقاتٍ يقرأ ربحَه كاملًا بلا تكلفة، وتنبيهُ
     * «صافي ربح اليوم أقلّ من كذا» لا يُطلق أبدًا.
     */
    public function test_a_custom_order_carries_its_cost_into_todays_profit(): void
    {
        $order = Order::create([
            'business_id' => $this->biz->id, 'number' => 'C-1', 'status' => 'مكتمل',
            'subtotal' => 20, 'total' => 20, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'name' => 'باقة',
            'quantity' => 1, 'price' => 20, 'total' => 20, 'cost' => 8,
            'custom_details' => ['v' => 2, 'mode' => 'value'],
        ]);

        $this->assertEqualsWithDelta(12.0, AlertMetrics::value('today_profit', $this->biz->id), 0.0005,
            'الطلبُ المخصَّص دخل الربحَ بلا تكلفة');
    }

    /** وصفٌّ قديمٌ بلا لقطةٍ يسقط على بطاقة صنفه — ولا يُحسب صفرًا */
    public function test_an_old_row_without_a_snapshot_falls_back_to_the_card(): void
    {
        $p = Product::create([
            'business_id' => $this->biz->id, 'name' => 'قديم', 'price' => 10, 'cost' => 3, 'quantity' => 50,
        ]);
        $order = Order::create([
            'business_id' => $this->biz->id, 'number' => 'O-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => 'قديم',
            'quantity' => 1, 'price' => 10, 'total' => 10, 'cost' => 0,
        ]);

        $this->assertEqualsWithDelta(7.0, AlertMetrics::value('today_profit', $this->biz->id), 0.0005,
            'صفٌّ بلا لقطةٍ حُسب بلا تكلفة');
    }

    /* ─────────── ومفتاحُ العملاء الراكدين يُضبط ─────────── */

    /**
     * ═══ مفتاحٌ كان يُقرأ ولا يُكتب ═══
     *
     * `Demo::buildNotifications` تسأل عن `notify_dormant_customers` منذ زمن —
     * ولا مدخلَ له في مخطّط الإعدادات ولا مقبضَ في الشاشة. فالقراءةُ تَعِد
     * بخيارٍ لا يملكه أحد: متجرٌ له ثلاثمئة زبونٍ راكد يمتلئ جرسُه بهم كلَّ
     * يوم ولا سبيل إلى إسكاتهم.
     */
    public function test_the_dormant_switch_can_actually_be_written(): void
    {
        $c = Customer::create([
            'business_id' => $this->biz->id, 'name' => 'زبونٌ راكد', 'phone' => '90000000',
        ]);
        Order::create([
            'business_id' => $this->biz->id, 'customer_id' => $c->id, 'number' => 'OLD-1',
            'status' => 'مكتمل', 'subtotal' => 10, 'total' => 10, 'ordered_at' => now()->subDays(200),
        ]);

        $this->actingAs($this->owner);
        $this->assertTrue(
            collect(Demo::allNotifications())->contains(fn ($n) => str_starts_with($n['key'], 'dormant-')),
            'المقدّمة خاطئة: لا صفَّ راكدٍ أصلًا',
        );

        $this->post(route('admin.settings.update'), [
            'section' => 'notifications',
            'notify_dormant_customers' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('0', (string) Setting::where('business_id', $this->biz->id)
            ->where('key', 'notify_dormant_customers')->value('value'), 'لم يُحفظ المفتاح');

        $this->assertFalse(
            collect(Demo::allNotifications())->contains(fn ($n) => str_starts_with($n['key'], 'dormant-')),
            'أُطفئ المفتاحُ وبقي الصفّ',
        );
    }
}
