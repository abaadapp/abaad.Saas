<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\CouponLimits;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لكلّ زبونٍ نصيبُه من الكود — لا يأكله أوّلُ زبونين.
 *
 * ═══ ما كان ═══
 *
 * `max_uses` حدٌّ إجماليٌّ واحد. فالتاجر الذي أراد «مرّتان لكلّ زبون» كتب
 * «٢» فأغلق كودَه على الناس كلِّهم بعد أوّل بيعتين — وهو عكسُ ما أراد
 * بالضبط. وليس له سبيلٌ آخر: يكتب رقمًا كبيرًا فيصير بلا حدٍّ فعليّ، أو
 * يكتب اثنين فيموت الكود في ساعته.
 *
 * ═══ والحدّان لا حدٌّ ═══
 *
 * إجماليٌّ لكلّ الناس، وحدٌّ لكلّ واحد. كلٌّ منهما اختياريّ، وكلٌّ منهما
 * يعمل وحدَه أو معًا. والفراغُ في الثاني هو حالُ كلّ كوبونٍ في القاعدة قبل
 * اليوم — فلا يتبدّل عليه شيء.
 *
 * ═══ ومن هو «الزبون» ═══
 *
 * رقمُه مطبَّعًا (`WhatsAppPhone::normalize`). فلا يُتجاوز الحدُّ بإعادة
 * كتابة الرقم «+968 9123 4567» بدل «91234567»، ولا ببطاقةٍ ثانيةٍ بالرقم
 * نفسِه. ومن غيَّر رقمه على بطاقته يُعرف بمعرّفها. والعزلُ بالمتجر: رقمُ
 * الهاتف ليس فريدًا في الدنيا، هو فريدٌ في متجرٍ واحد.
 */
class EachCustomerHasHisOwnShareOfTheCouponTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'متجري', 'type' => 'محل ورد', 'status' => 'نشط',
            // وبابُ السلّة لا يُخدم إلّا لمتجرٍ واجهتُه خاصّة — انظر `Storefront::serves`
            'site_slug' => 'mine', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@share.local',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف', 'price' => 100, 'cost' => 40,
            'quantity' => 1000, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

    private function coupon(array $attrs = [], ?int $bid = null): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $bid ?? $this->business->id,
            'code' => 'SAVE10', 'type' => 'مبلغ', 'value' => 10,
            'min_order' => 0, 'active' => true, 'used_count' => 0,
        ], $attrs));
    }

    private function buyer(string $phone, string $name = 'زبون', ?int $bid = null): Customer
    {
        return Customer::create([
            'business_id' => $bid ?? $this->business->id,
            'name' => $name, 'phone' => $phone, 'language' => 'ar',
        ]);
    }

    /** بيعةُ صندوق — الزبونُ بمعرّف بطاقته كما ترسله الشاشة */
    private function sell(?Customer $customer, array $extra = [])
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('t', true),
                'customer' => $customer?->name,
                'customer_id' => $customer?->id,
                'coupon_code' => 'SAVE10',
            ], $extra));
    }

    /* ═════════ نصيبُ الزبون: مرّتان له، ويُردّ في الثالثة ═════════ */

    public function test_one_customer_spends_his_two_and_is_refused_the_third(): void
    {
        $this->coupon(['per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();
        $this->sell($ahmed)->assertOk();

        $third = $this->sell($ahmed);
        $third->assertStatus(422);
        $third->assertJsonValidationErrors('coupon_code');

        $this->assertSame(2, Order::where('coupon_code', 'SAVE10')->count(), 'الثالثةُ لا تُكتب فاتورةً بخصمٍ رُدّ');
        $this->assertSame(2, CouponLimits::countFor($this->coupon ?? Coupon::first(), 'phone:96891234567', $ahmed->id));
    }

    public function test_another_customer_has_his_own_two_and_the_code_does_not_end(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');
        $mohammed = $this->buyer('92222222', 'محمد');
        $salem = $this->buyer('93333333', 'سالم');

        $this->sell($ahmed)->assertOk();
        $this->sell($ahmed)->assertOk();
        $this->sell($ahmed)->assertStatus(422);

        // ومحمّدٌ لم يمسّه شيءٌ من ذلك
        $this->sell($mohammed)->assertOk();
        $this->sell($mohammed)->assertOk();
        $this->sell($mohammed)->assertStatus(422);

        $this->sell($salem)->assertOk();

        $this->assertSame(5, (int) $coupon->fresh()->used_count, 'العدّادُ الإجماليّ يعدّ ما وقع — ولا يُغلق الكود');
        $this->assertTrue($coupon->fresh()->isValid(), 'كودٌ بلا حدٍّ إجماليٍّ لا ينتهي بزبونين');
    }

    /* ═════════ والحدّان معًا حين يُكتبان معًا ═════════ */

    public function test_the_total_limit_and_the_per_customer_limit_bite_together(): void
    {
        $coupon = $this->coupon(['max_uses' => 3, 'per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');
        $mohammed = $this->buyer('92222222', 'محمد');

        $this->sell($ahmed)->assertOk();
        $this->sell($ahmed)->assertOk();
        // حدُّه هو بلغه — ولا حاجةَ إلى الإجماليّ
        $this->sell($ahmed)->assertStatus(422);

        $this->sell($mohammed)->assertOk();

        // والإجماليُّ بلغ ثلاثًا، فمحمّدٌ يُردّ وله مرّةٌ في حدّه هو
        $refused = $this->sell($mohammed);
        $refused->assertStatus(422);
        $refused->assertJsonValidationErrors('coupon_code');

        $this->assertSame(3, (int) $coupon->fresh()->used_count);
        $this->assertTrue($coupon->fresh()->isExhausted(), 'الإجماليُّ يُغلق الكود على الجميع كما كان');
    }

    /* ═════════ والكوبونات القائمة كما كانت ═════════ */

    public function test_a_coupon_without_the_new_limit_behaves_exactly_as_before(): void
    {
        $coupon = $this->coupon();
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->assertNull($coupon->per_customer_limit, 'الأصلُ فراغٌ — ولا يُفعَّل على كوبونٍ قائم');
        $this->assertFalse($coupon->isPerCustomerLimited());

        // أربعُ بيعاتٍ لزبونٍ واحد — ولا شيء يردّها، كما كان قبل الميزة
        foreach (range(1, 4) as $ignored) {
            $this->sell($ahmed)->assertOk();
        }

        $this->assertSame(4, (int) $coupon->fresh()->used_count);

        /*
         * والسجلُّ يُكتب على كلّ حال — ولا يُقرأ إلّا حين يُضبط حدٌّ.
         *
         * كتابتُه دائمًا تجعل الحدَّ ذا معنًى في اللحظة التي يُضبط فيها،
         * وتُبقي مسارًا واحدًا لا مساران يفترقان. وهو لا يمنع شيئًا هنا:
         * أربعُ بيعاتٍ مضت ولم تُردّ واحدة.
         */
        $this->assertSame(4, DB::table('coupon_redemptions')->count());
    }

    public function test_a_cash_walk_in_without_a_name_is_unaffected_when_there_is_no_per_customer_limit(): void
    {
        $this->coupon();

        $this->sell(null, ['customer' => 'عميل نقدي'])->assertOk();
    }

    /* ═════════ ومجهولٌ لا يُحسب له حدّ ═════════ */

    public function test_a_limited_coupon_asks_for_a_phone_when_the_buyer_is_unknown(): void
    {
        $this->coupon(['per_customer_limit' => 1]);

        $res = $this->sell(null, ['customer' => 'عميل نقدي']);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('coupon_code');
        $this->assertStringContainsString(
            'رقم هاتف',
            (string) ($res->json('errors.coupon_code.0') ?? ''),
            'يُقال للكاشير ما يُصلح به، لا «غير صحيح»',
        );
        $this->assertSame(0, Order::count(), 'ولا يُستعمل الكودُ ألفَ مرّةٍ على «عميل نقدي» واحد');
    }

    /* ═════════ ولا يُتجاوز الحدُّ بصيغةٍ أخرى للرقم ═════════ */

    public function test_the_same_number_written_differently_is_the_same_customer(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();

        // البطاقةُ الثانية بالرقم نفسِه بصيغةٍ دوليّة — زبونٌ واحد لا اثنان
        $again = $this->buyer('+968 9123 4567', 'أحمد ثانية');
        $this->sell($again)->assertStatus(422);

        // و«00968…» كذلك، و«٩١٢٣٤٥٦٧» بالأرقام العربية
        $this->sell($this->buyer('00968-91234567', 'ثالثة'))->assertStatus(422);
        $this->sell($this->buyer('٩١٢٣٤٥٦٧', 'رابعة'))->assertStatus(422);

        $this->assertSame(1, Order::where('coupon_code', 'SAVE10')->count());
    }

    public function test_changing_his_number_afterwards_does_not_open_the_limit_again(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();

        // غيَّر رقمه على بطاقته نفسِها — فهويّتُه بالرقم انقطعت
        $ahmed->update(['phone' => '95555555']);

        $this->sell($ahmed->fresh())->assertStatus(422);
        $this->assertSame(1, Order::where('coupon_code', 'SAVE10')->count());
    }

    public function test_the_old_order_keeps_the_identity_it_was_bought_with(): void
    {
        $this->coupon(['per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();
        $ahmed->update(['phone' => '95555555']);

        $row = DB::table('coupon_redemptions')->first();

        $this->assertSame('phone:96891234567', $row->customer_key, 'هويّةُ طلبٍ ماضٍ لا تُعاد كتابتُها');
    }

    /* ═════════ ولا تختلط كوبوناتُ التجّار ═════════ */

    public function test_a_neighbours_customer_never_spends_my_coupons_share(): void
    {
        $mine = $this->coupon(['per_customer_limit' => 1]);

        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $neighbour->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($neighbour->id);
        $his = $this->coupon(['per_customer_limit' => 1], bid: $neighbour->id);

        // الرقمُ نفسُه عند التاجرين — وهو أمرٌ واقعٌ لا افتراض
        $atMine = $this->buyer('91234567', 'أحمد');
        $atHis = $this->buyer('91234567', 'أحمد', bid: $neighbour->id);

        $this->sell($atMine)->assertOk();

        // استعمالُه عندي لا يُحسب على كود الجار
        $this->assertSame(0, CouponLimits::countFor($his, 'phone:96891234567', $atHis->id));
        $this->assertSame(1, CouponLimits::countFor($mine, 'phone:96891234567', $atMine->id));
        $this->assertNull(CouponLimits::refusal($his, 'phone:96891234567', $atHis->id));
        $this->assertNotNull(CouponLimits::refusal($mine, 'phone:96891234567', $atMine->id));

        $this->assertSame(
            0,
            DB::table('coupon_redemptions')->where('business_id', $neighbour->id)->count(),
            'سجلٌّ كُتب في متجر الجار من بيعةٍ في متجري',
        );
    }

    /* ═════════ والطلبُ لا يُحتسب مرّتين ═════════ */

    public function test_the_same_order_is_never_counted_twice(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();
        $order = Order::first();

        // نداءٌ ثانٍ بالطلب نفسِه — إشعارُ بوّابةٍ أُعيد إرسالُه، أو استكمالٌ مكرَّر
        CouponLimits::record($coupon, $order, 'phone:96891234567', $ahmed->id);
        CouponLimits::record($coupon, $order, 'phone:96891234567', $ahmed->id);

        $this->assertSame(1, DB::table('coupon_redemptions')->where('order_id', $order->id)->count());
        $this->assertSame(1, CouponLimits::countFor($coupon, 'phone:96891234567', $ahmed->id));
    }

    public function test_the_unique_key_on_the_order_is_a_constraint_in_the_database(): void
    {
        /*
         * فحصٌ في الكود يقرأ ثمّ يكتب يمرّ منه إشعاران متقاربان معًا. والقيدُ
         * في القاعدة لا يمرّ منه أحد — فيُقاس أنّه هناك فعلًا، لا أنّ الكودَ
         * يتذكّره.
         */
        $coupon = $this->coupon(['per_customer_limit' => 2]);
        $ahmed = $this->buyer('91234567', 'أحمد');
        $this->sell($ahmed)->assertOk();
        $order = Order::first();

        $row = [
            'business_id' => $this->business->id, 'coupon_id' => $coupon->id, 'order_id' => $order->id,
            'customer_id' => $ahmed->id, 'customer_key' => 'phone:96899999999',
            'redeemed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];

        /*
         * بهويّةٍ أخرى حتّى لا يُردّ لتشابه الصفّ — ويُردّ بالطلب وحده.
         *
         * والإدراجُ في نقطةِ حفظ لا في المعاملة عاريًا: PostgreSQL تُجهض
         * المعاملةَ كلَّها عند أوّل خطأ («current transaction is aborted»)
         * فيُردّ كلُّ استعلامٍ بعده، ومنه سؤالُنا عن عدد الصفوف. وSQLite تمضي
         * فلا يظهر الفرقُ إلّا على المحرّك الآخر — وقد ظهر. فتُلفّ المحاولةُ
         * في معاملةٍ داخليّة يجعلها لارافل `SAVEPOINT`، فالارتدادُ إليها
         * يُبقي ما حولها صالحًا للقراءة.
         */
        try {
            DB::transaction(fn () => DB::table('coupon_redemptions')->insert($row));
            $this->fail('القيدُ الفريد على order_id ليس في القاعدة — فطلبٌ واحد يُحتسب مرّتين');
        } catch (QueryException) {
            // ارتدّت إلى نقطة الحفظ، والاتّصالُ صالحٌ لما بعدها
        }

        $this->assertSame(1, DB::table('coupon_redemptions')->where('order_id', $order->id)->count());
    }

    public function test_a_refused_sale_leaves_no_trace_of_a_use(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();
        $this->sell($ahmed)->assertStatus(422);

        $this->assertSame(1, (int) $coupon->fresh()->used_count, 'بيعةٌ رُدّت لا تزيد العدّاد');
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    /* ═════════ وطلبٌ ألغي يردّ نصيبَه ═════════ */

    public function test_a_cancelled_order_gives_the_customer_his_use_back(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $this->sell($ahmed)->assertOk();
        $order = Order::first();

        $this->assertSame(1, DB::table('coupon_redemptions')->count());

        OrderCorrection::cancel($order, $this->owner->name);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'زبونٌ يبقى ممنوعًا بكوبونٍ لم ينتفع به');
        $this->assertSame(0, (int) $coupon->fresh()->used_count);

        // فيستعمله من جديد
        $this->sell($ahmed)->assertOk();
    }

    /* ═════════ والطلبُ المعلَّق لا يحتسب حتّى يُدفع ═════════ */

    public function test_holding_an_order_is_not_a_use_and_resuming_it_counts_once(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $held = $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.hold'), [
                'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
                'customer' => $ahmed->name,
                'coupon_code' => 'SAVE10',
            ]);

        $held->assertOk();
        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'التعليقُ لا يبيع شيئًا فلا يأكل نصيبًا');
        $this->assertSame(0, (int) $coupon->fresh()->used_count);

        // ثمّ يُستكمل ويُدفع — فيُحتسب مرّةً واحدة
        $this->sell($ahmed, ['resume_id' => $held->json('id') ?? Order::first()?->id])->assertOk();

        $this->assertSame(1, DB::table('coupon_redemptions')->count());
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    /* ═════════ ومعاينةُ الكود لا تحتسب استعمالًا ═════════ */

    public function test_previewing_the_code_is_not_a_use(): void
    {
        $coupon = $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');

        $ok = $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson('/pos/coupon', ['code' => 'SAVE10', 'subtotal' => 100, 'customer_id' => $ahmed->id]);

        $ok->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertSame(0, DB::table('coupon_redemptions')->count());
    }

    public function test_the_preview_says_the_limit_before_the_price_is_announced(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $ahmed = $this->buyer('91234567', 'أحمد');
        $this->sell($ahmed)->assertOk();

        $res = $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson('/pos/coupon', ['code' => 'SAVE10', 'subtotal' => 100, 'customer_id' => $ahmed->id]);

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('لكل زبون', (string) $res->json('error'));
    }

    /* ═════════ والموقعُ الإلكترونيّ بالقاعدة نفسِها ═════════ */

    private function web(array $over = [])
    {
        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
        ]);

        return $this->postJson('/s/mine/checkout', $over + [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
            // ومحلُّ الورد يشترط موعدًا — حقلٌ من حقول الإتمام لا علاقةَ له بالكوبون
            'date' => now()->addDays(2)->format('Y-m-d'), 'slot' => '9 ص – 12 م',
            'promo' => 'SAVE10',
        ]);
    }

    public function test_the_website_counts_and_refuses_by_the_same_rule(): void
    {
        $this->coupon(['per_customer_limit' => 1]);

        $this->web()->assertOk();

        $again = $this->web();
        $again->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $again->json('errors'), 'يُقال تحت حقل الكود لا في فراغ');

        $this->assertSame(1, Order::where('coupon_code', 'SAVE10')->count());
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    public function test_the_website_and_the_till_share_one_count(): void
    {
        $this->coupon(['per_customer_limit' => 1]);

        // يشتري من الموقع برقمه، ثمّ يأتي إلى المحلّ ببطاقته
        $this->web()->assertOk();

        $card = Customer::where('business_id', $this->business->id)->first();
        $this->assertNotNull($card, 'الموقعُ يُنشئ للزبون بطاقةً بهاتفه');

        $this->sell($card)->assertStatus(422);
    }

    public function test_a_second_account_with_the_same_number_on_the_website_is_refused(): void
    {
        $this->coupon(['per_customer_limit' => 1]);

        $this->web()->assertOk();

        // الرقمُ نفسُه بصيغةٍ محلّيّة واسمٍ آخر
        $again = $this->web(['name' => 'اسم آخر', 'phone' => '99110001']);
        $again->assertStatus(422);

        $this->assertSame(1, Order::where('coupon_code', 'SAVE10')->count());
    }

    /* ═════════ ومسارُ البطاقة يُفحص قبل صفحة الدفع ═════════ */

    public function test_the_card_path_refuses_before_the_payment_page_is_opened(): void
    {
        $this->coupon(['per_customer_limit' => 1]);
        $this->web()->assertOk();

        /*
         * وبوّابةٌ بمفاتيحَ تجريبيّة — لا مفتاحَ حقيقيٍّ في اختبار.
         *
         * وهي شرطُ ظهور البطاقة وسيلةَ دفع (`WebCheckout::payments`).
         */
        PaymentGateway::create([
            'business_id' => $this->business->id,
            'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_x', 'card_integration_id' => '1234567',
            'active' => true,
        ]);

        $res = $this->postJson('/s/mine/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'card',
            'name' => 'مريم', 'phone' => '96899110001',
            'date' => now()->addDays(2)->format('Y-m-d'), 'slot' => '9 ص – 12 م',
            'promo' => 'SAVE10',
        ]);

        $res->assertStatus(422);
        $this->assertArrayHasKey('promo', (array) $res->json('errors'), 'يُدفع ثمّ يُردّ طلبُه — مالٌ مقبوضٌ بلا طلب');
        $this->assertSame(0, StorePaymentIntent::count(), 'ولا نيّةُ دفعٍ تُفتح لطلبٍ سيُردّ');
    }

    /* ═════════ والقفلُ قبل الفحص، والفحصُ قبل الكتابة ═════════ */

    public function test_the_limit_is_read_under_the_lock_the_coupon_is_held_with(): void
    {
        /*
         * التزامنُ الحقيقيّ لا يُصنع في عمليةٍ واحدة: طلبان على عاملَي PHP
         * لا يُشغَّلان من اختبار — **وهذا ما لم يُقس هنا**. فيُقاس الحارسُ
         * من مصدره: الكوبونُ يُقرأ بقفل، وسؤالُ الحدّ بعد القفل، والكتابةُ
         * بعد السؤال. وما بينها في معاملةٍ واحدة.
         */
        $whole = (string) file_get_contents(app_path('Http/Controllers/Pos/PosController.php'));

        /*
         * ومن `checkout` وحدَها: المعاينةُ فوقها تحمل النداء نفسَه، فقياسُ
         * الترتيب على الملفّ كلِّه يقرأ موضعَ المعاينة ويقول شيئًا لا معنى له.
         */
        $at = strpos($whole, 'public function checkout(');
        $this->assertNotFalse($at, 'بابُ الدفع لم يعد باسمه');
        $src = substr($whole, $at);

        $lock = strpos($src, 'findCoupon($couponCode, lock: true)');
        $ask = strpos($src, 'CouponLimits::refusal(');
        $write = strpos($src, 'CouponLimits::record(');

        $this->assertNotFalse($lock, 'الكوبونُ لم يعد يُقرأ بقفلٍ عند الدفع');
        $this->assertNotFalse($ask, 'حدُّ الزبون لا يُفحص عند الدفع');
        $this->assertNotFalse($write, 'الاستعمالُ لا يُسجَّل عند الدفع');
        $this->assertLessThan($ask, $lock, 'يُسأل الحدُّ قبل أن يُقفل الصفّ — فطلبان يمرّان معًا');
        $this->assertLessThan($write, $ask, 'يُكتب الاستعمالُ قبل أن يُسأل الحدّ');
        $this->assertStringContainsString('DB::transaction', $src);
    }
}
