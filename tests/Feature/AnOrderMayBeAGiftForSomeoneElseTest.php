<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\GiftOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ميزةُ الإهداء — الطلبُ هديّةٌ لغير مشتريه، لأيّ متجرٍ يرفع مفتاحَها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المفتاحُ لكلّ متجر، مطفأٌ افتراضًا، ولا يمسّ رفعُه متجرًا آخر.
 *   - الطلبُ العاديّ كما كان؛ والهديّةُ حالةٌ مكتوبة (`is_gift`) لا مستنتَجة.
 *   - المشتري صاحبُ الطلب والدفع، والمستلِمُ منفصلٌ ومطلوبٌ اسمًا ورقمًا.
 *   - المناسبةُ اختياريّة، من القائمة أو «أخرى» بنصّها.
 *   - «لا تذكر اسمي» يُخفيه عن المستلِم لا عن التاجر.
 *   - «تواصلوا مع المستلم» يقبل الطلبَ بلا عنوان، ولا يُكتب عنوانٌ مخترَع.
 *   - زرُّ التاجر يفتح واتساب المستلِم بلا مُهدٍ ولا ثمن، ولا يُرسل شيئًا.
 *   - ولا يتغيّر مالٌ: الإجماليُّ والمعاملةُ كطلبٍ ليس هديّة، ولا كرتَ يُضاف.
 *
 * والمتجران عامّان — لا معرّفَ مكتوبٌ ولا واجهةٌ تُسأل في المنطق.
 */
class AnOrderMayBeAGiftForSomeoneElseTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private Product $rose;

    private Product $theirRose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');
        // أسماءٌ عربيّة في الحمولة — والقائمةُ الإنجليزيّة شأنٌ آخر
        config(['storefront.ribbon_english_checkout_businesses' => [], 'storefront.ribbon_gift_card_product_businesses' => []]);

        [$this->a, $this->rose] = $this->shop('ribbon');
        [$this->b, $this->theirRose] = $this->shop('other');

        MarketingSettings::save($this->a->id, 'website', [GiftOrders::KEY => '1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: Product} */
    private function shop(string $slug): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $slug.'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_pay_transfer' => '1', 'store_bank' => 'Bank Muscat 0123',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
            'store_delivery_fee' => '2', 'store_free_delivery_over' => '',
        ]);

        $rose = Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return [$shop, $rose];
    }

    private function owner(Business $shop): User
    {
        return User::where('business_id', $shop->id)->firstOrFail();
    }

    private function order(Product $rose, array $over = []): array
    {
        return $over + [
            'items' => [['id' => $rose->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    private function gift(array $over = []): array
    {
        return $this->order($this->rose, $over + [
            'is_gift' => true, 'recipient_name' => 'سارة', 'recipient_phone' => '96899110002',
            'recipient_location' => GiftOrders::PROVIDED,
        ]);
    }

    /* ═══════════ المفتاحُ لكلّ متجر ═══════════ */

    public function test_gifting_is_off_until_a_shop_raises_it_and_its_storefront_follows(): void
    {
        $this->assertFalse(GiftOrders::on($this->b->id), 'متجرٌ لم يلمس المفتاح صار يُهدي');
        $this->assertSame('0', MarketingSettings::group($this->b->id, 'website')[GiftOrders::KEY]);

        $this->assertStringNotContainsString('data-rb-gift ', $this->get('/s/other/checkout')->assertOk()->getContent());
        $this->assertStringNotContainsString('هذا الطلب هدية', $this->get('/s/other/checkout')->getContent());

        $ar = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        foreach (['هذا الطلب هدية', 'المناسبة — اختياري', 'اكتب المناسبة', 'لا تذكر اسمي للمستلم', 'طريقة تحديد موقع المستلم', 'سأدخل الموقع الآن', 'تواصلوا مع المستلم للحصول على الموقع', 'عيد ميلاد'] as $s) {
            $this->assertStringContainsString($s, $ar);
        }

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        foreach (['This order is a gift', 'Occasion — optional', 'Enter the occasion', 'Do not reveal my name to the recipient', 'Recipient location', "I'll provide the location now", 'Contact the recipient for the location', 'Birthday'] as $s) {
            $this->assertStringContainsString(e($s), $en);
        }
        // ما يراه الزبون — لا شرحَ السكربت المضمَّن في الصفحة
        $visible = (string) preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->#s', '', $en);
        foreach (['هذا الطلب هدية', 'المناسبة', 'لا تذكر اسمي', 'طريقة تحديد موقع', 'عيد ميلاد', 'تواصلوا مع المستلم'] as $arabic) {
            $this->assertStringNotContainsString($arabic, $visible, 'نصٌّ عربيٌّ من الإهداء في الصفحة الإنجليزيّة');
        }
    }

    public function test_one_shop_raises_gifting_from_its_settings_without_touching_another(): void
    {
        MarketingSettings::save($this->a->id, 'website', [GiftOrders::KEY => '0']);
        $before = Setting::where('business_id', $this->b->id)->pluck('value', 'key')->all();

        // ومعرّفُ المتجر الآخر في الحمولة لا يُقرأ
        $this->actingAs($this->owner($this->a))
            ->post(route('admin.marketing.store.save'), [GiftOrders::KEY => '1', 'business_id' => $this->b->id, 'bid' => $this->b->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue(GiftOrders::on($this->a->id));
        $this->assertFalse(GiftOrders::on($this->b->id));
        $this->assertSame($before, Setting::where('business_id', $this->b->id)->pluck('value', 'key')->all(), 'تبدّلت إعداداتُ متجرٍ آخر');

        $this->actingAs($this->owner($this->a))->post(route('admin.marketing.store.save'), [GiftOrders::KEY => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(GiftOrders::on($this->a->id));
    }

    /* ═══════════ الطلبُ العاديّ والهديّة ═══════════ */

    public function test_a_normal_order_is_placed_as_before_on_a_gifting_shop(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose))->assertOk();

        $o = Order::sole();
        $this->assertFalse($o->is_gift);
        $this->assertNull($o->recipient_location_mode);
        $this->assertSame('مريم', $o->recipient_name, 'المستلِمُ في الطلب العاديّ هو المشتري كما كان');
        $this->assertSame('الخوير — شارع 18', $o->delivery_address);
    }

    public function test_a_gift_keeps_the_buyer_as_customer_and_the_recipient_apart_and_costs_the_same(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose))->assertOk();
        $plain = Order::sole();

        $this->postJson('/s/ribbon/checkout', $this->gift([
            'occasion' => 'birthday', 'hide_sender' => true, 'phone' => '96899110001',
        ]))->assertOk();
        $gift = Order::latest('id')->first();

        $this->assertTrue($gift->is_gift);
        $this->assertSame(['مريم', 'سارة', '96899110002', 'مريم'], [$gift->customer_name, $gift->recipient_name, $gift->recipient_phone, $gift->sender_name]);
        $this->assertSame('96899110001', $gift->customer->phone, 'المشتري ليس صاحبَ الطلب');
        $this->assertSame('birthday', $gift->occasion_type);
        $this->assertNull($gift->occasion_text);
        $this->assertTrue($gift->hide_sender);
        $this->assertSame(GiftOrders::PROVIDED, $gift->recipient_location_mode);
        $this->assertSame('الخوير — شارع 18', $gift->delivery_address);

        // ولا كرتَ يُضاف، ولا مالَ يتبدّل
        $this->assertSame(1, $gift->items()->count());
        $this->assertNull($gift->card_message);
        foreach (['subtotal', 'discount', 'tax', 'delivery_fee', 'total', 'payment_method', 'payment_status'] as $col) {
            $this->assertEquals($plain->{$col}, $gift->{$col}, "تبدّل $col لأنّ الطلب هديّة");
        }
        $this->assertEquals(
            (float) Transaction::where('order_id', $plain->id)->value('amount'),
            (float) Transaction::where('order_id', $gift->id)->value('amount'),
        );
        $this->assertSame(8, (int) $this->rose->fresh()->quantity, 'المخزونُ لم يُخصم كما يُخصم لكلّ بيعة');

        // والمُهدي يُخفى عن المستلِم وحده — والتاجرُ يراه
        $this->assertNull(FlowerOrder::cardForRecipient($gift)['sender']);
        $this->assertSame('مريم', $gift->sender_name);
    }

    public function test_a_gift_needs_its_recipient_even_where_the_recipient_field_is_off(): void
    {
        MarketingSettings::save($this->a->id, 'website', ['store_field_recipient' => 'off']);

        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_name' => '', 'recipient_phone' => '']))
            ->assertStatus(422)
            ->assertJsonPath('errors.recipient_name.0', 'اكتب اسم المستلم.')
            ->assertJsonPath('errors.recipient_phone.0', 'اكتب رقم المستلم.');

        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_phone' => '12ab']))
            ->assertStatus(422)->assertJsonValidationErrors('recipient_phone');

        $this->assertSame(0, Order::count());

        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
        $this->assertSame('سارة', Order::sole()->recipient_name);
    }

    public function test_the_occasion_is_optional_listed_or_written_by_hand(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->gift(['occasion' => 'graduation']))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->gift(['occasion' => 'other', 'occasion_text' => 'افتتاح محلّ']))->assertOk();
        // ونصٌّ مع مناسبةٍ من القائمة لا يُكتب
        $this->postJson('/s/ribbon/checkout', $this->gift(['occasion' => 'love', 'occasion_text' => 'لا يُكتب']))->assertOk();

        $this->assertSame(
            [[null, null], ['graduation', null], ['other', 'افتتاح محلّ'], ['love', null]],
            Order::orderBy('id')->get()->map(fn ($o) => [$o->occasion_type, $o->occasion_text])->all(),
        );
        $this->assertSame('أخرى: افتتاح محلّ', GiftOrders::occasionLabel(Order::where('occasion_type', 'other')->sole()));

        $this->postJson('/s/ribbon/checkout', $this->gift(['occasion' => 'عقيقة']))->assertStatus(422)->assertJsonValidationErrors('occasion');
    }

    /* ═══════════ موقعُ المستلِم ═══════════ */

    public function test_contact_recipient_takes_the_order_without_an_address_and_writes_none(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift([
            'recipient_location' => GiftOrders::CONTACT, 'address' => '', 'area' => '',
        ]))->assertOk();

        $o = Order::sole();
        $this->assertSame(GiftOrders::CONTACT, $o->recipient_location_mode);
        $this->assertNull($o->delivery_address, 'كُتب عنوانٌ مخترَع');
        $this->assertTrue(GiftOrders::awaitingLocation($o));

        // والمستلِمُ والموعدُ ما زالا شرطين
        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_location' => GiftOrders::CONTACT, 'address' => '', 'recipient_phone' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('recipient_phone');
        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_location' => GiftOrders::CONTACT, 'address' => '', 'date' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_provided_mode_and_ordinary_delivery_still_need_the_address(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift(['address' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['address' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('address');
        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_location' => 'anywhere']))
            ->assertStatus(422)->assertJsonValidationErrors('recipient_location');
        $this->assertSame(0, Order::count());

        // واستلامٌ من المحلّ: هديّةٌ بلا طريقة موقع
        $this->postJson('/s/ribbon/checkout', $this->gift(['fulfil' => 'pickup', 'address' => '', 'area' => '']))->assertOk();
        $this->assertNull(Order::sole()->recipient_location_mode);
    }

    public function test_a_shop_without_gifting_ignores_gift_fields_and_keeps_its_rules(): void
    {
        $payload = $this->order($this->theirRose, [
            'is_gift' => true, 'recipient_location' => GiftOrders::CONTACT, 'address' => '', 'hide_sender' => true, 'occasion' => 'love',
        ]);

        $this->postJson('/s/other/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors('address');

        $this->postJson('/s/other/checkout', ['address' => 'شارع ٩'] + $payload)->assertOk();
        $o = Order::sole();
        $this->assertSame([false, null, false, null], [$o->is_gift, $o->recipient_location_mode, $o->hide_sender, $o->occasion_type]);
    }

    /* ═══════════ شاشةُ التاجر وواتساب ═══════════ */

    private function contactOrder(): Order
    {
        $this->postJson('/s/ribbon/checkout', $this->gift([
            'recipient_location' => GiftOrders::CONTACT, 'address' => '', 'hide_sender' => true, 'name' => 'مريم المُهدية',
        ]))->assertOk();

        return Order::latest('id')->first();
    }

    public function test_the_merchant_sees_a_gift_its_buyer_and_where_the_location_stands(): void
    {
        $o = $this->contactOrder();

        $this->actingAs($this->owner($this->a))->get(route('admin.orders.show', $o->number))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('order.is_gift', true)
                ->where('order.customer', 'مريم المُهدية')
                ->where('order.sender_name', 'مريم المُهدية')
                ->where('order.hide_sender', true)
                ->where('order.recipient_name', 'سارة')
                ->where('order.recipient_location_mode', GiftOrders::CONTACT)
                ->where('order.location_label', 'الموقع: بانتظار التواصل مع المستلم')
                ->where('order.awaiting_location', true));
    }

    public function test_whatsapp_to_the_recipient_names_neither_sender_nor_price_and_sends_nothing(): void
    {
        Http::fake();
        $o = $this->contactOrder();

        $res = $this->actingAs($this->owner($this->a))
            ->post(route('admin.orders.contactRecipient', $o->number))
            ->assertSessionHas('toast');

        $toast = session('toast');
        $this->assertSame('info', $toast['type']);
        $this->assertStringStartsWith('https://wa.me/96899110002?text=', $toast['link']['url']);

        $text = rawurldecode(substr($toast['link']['url'], strlen('https://wa.me/96899110002?text=')));
        $this->assertStringContainsString('متجر ribbon', $text);
        foreach (['مريم', '96899110001', '22', 'ر.ع', 'دفع'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "الرسالةُ تكشف «{$secret}»");
        }
        Http::assertNothingSent();
    }

    public function test_the_whatsapp_action_is_offered_only_where_a_location_is_awaited(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
        $provided = Order::sole();

        $this->assertNull(GiftOrders::contactLink($provided, 'x'));
        $this->actingAs($this->owner($this->a))->post(route('admin.orders.contactRecipient', $provided->number));
        $this->assertSame('danger', session('toast')['type']);
        $this->assertArrayNotHasKey('link', session('toast'));

        // وعنوانٌ كُتب بعد التواصل يُنهي الانتظار
        $waiting = $this->contactOrder();
        $waiting->update(['delivery_address' => 'العذيبة، بيت ١٢']);
        $this->assertFalse(GiftOrders::awaitingLocation($waiting->fresh()));
        $this->assertNull(GiftOrders::contactLink($waiting->fresh(), 'x'));
    }

    public function test_another_shop_cannot_reach_a_gift_order_by_its_number(): void
    {
        $o = $this->contactOrder();

        $this->actingAs($this->owner($this->b))->post(route('admin.orders.contactRecipient', $o->number))->assertNotFound();
        $this->actingAs($this->owner($this->b))->get(route('admin.orders.show', $o->number))->assertNotFound();
        $this->actingAs($this->owner($this->b))
            ->put(route('admin.orders.details.update', $o->number), ['recipient_phone' => '96800000000'])
            ->assertNotFound();
        $this->assertSame('96899110002', $o->fresh()->recipient_phone);
    }

    public function test_a_gift_awaiting_its_location_can_still_be_edited_and_others_keep_the_address_rule(): void
    {
        $o = $this->contactOrder();

        $this->actingAs($this->owner($this->a))
            ->put(route('admin.orders.details.update', $o->number), ['recipient_phone' => '96899110009'])
            ->assertSessionHasNoErrors();
        $this->assertSame('96899110009', $o->fresh()->recipient_phone);

        $this->postJson('/s/ribbon/checkout', $this->order($this->rose))->assertOk();
        $plain = Order::latest('id')->first();
        $this->actingAs($this->owner($this->a))
            ->put(route('admin.orders.details.update', $plain->number), ['delivery_address' => ''])
            ->assertSessionHasErrors('delivery_address');
    }
}
