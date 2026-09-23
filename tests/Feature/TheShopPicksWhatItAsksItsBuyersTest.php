<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\CheckoutFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * المحلُّ ينتقي ما يسأل عنه زبونَه — والخادمُ يشترط ما تعرضه الشاشة.
 *
 * ═══ وأثقلُ ما يُحرَس هنا ═══
 *
 * أن يفترق الاثنان: حقلٌ مُخفًى في الشاشة ما زال مطلوبًا في الخادم يردّ
 * الطلبَ بخطأٍ عن حقلٍ لا يراه الزبون، فلا يعرف ماذا يُصلح ويُغلق الصفحة.
 * والعكسُ أسوأ: حقلٌ يُعرض «مطلوبًا» ولا يشترطه الخادم يمرّ فارغًا، فيصل
 * الطلبُ بلا عنوانٍ ولا موعد.
 */
class TheShopPicksWhatItAsksItsBuyersTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->picks();

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function picks(array $over = []): void
    {
        MarketingSettings::save($this->shop->id, 'website', $over + [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '3',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);
    }

    private function order(array $over = []): array
    {
        return $over + [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]],
            'fulfil' => 'delivery', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'area' => 'الخوير', 'address' => 'شارع ١٨', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ];
    }

    private function place(array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $this->order($over));
    }

    private function page(): string
    {
        return $this->get('/s/ribbon/checkout')->assertOk()->getContent();
    }

    /* ═══════════ والفراغُ يعني «ما كان» ═══════════ */

    /**
     * متجرٌ لم يمسّ الشاشةَ لا يتبدّل عليه شيء.
     *
     * وهي أهمُّ حالةٍ هنا: ترقيةٌ تُضيف مفاتيحَ فارغةً إلى كلّ متجرٍ في
     * أبعاد، فإن قُرئ الفراغُ «مُطفأً» اختفت الحقولُ من مئة موقع، وإن قُرئ
     * «مطلوبًا» صار حقلٌ حرٌّ إلزامًا لم يطلبه أحد.
     */
    public function test_a_shop_that_touched_nothing_keeps_what_it_had(): void
    {
        $this->assertSame([
            'area' => 'required', 'address' => 'required', 'date' => 'required',
            'slot' => 'optional', 'recipient' => 'optional', 'promo' => 'optional',
        ], CheckoutFields::all($this->shop->id));

        $this->assertSame(['pickup', 'delivery'], CheckoutFields::fulfilments($this->shop->id));
        $this->assertSame(60, CheckoutFields::maxDays($this->shop->id));
    }

    /** والمنطقةُ وحدَها تتبع قائمتَها — حرّةٌ حين لا تُضبط، مشترَطةٌ حين تُضبط */
    public function test_the_area_follows_its_list_as_it_always_did(): void
    {
        $this->picks(['store_delivery_areas' => '']);
        $this->assertSame('optional', CheckoutFields::state($this->shop->id, 'area'));

        $this->picks(['store_delivery_areas' => 'الخوير, القرم']);
        $this->assertSame('required', CheckoutFields::state($this->shop->id, 'area'));
    }

    /* ═══════════ الإخفاء ═══════════ */

    /** حقلٌ أُخفي لا يُرسم ولا يُشترط — والطلبُ يمضي بلا ذكره */
    public function test_a_hidden_field_is_neither_drawn_nor_demanded(): void
    {
        $this->picks(['store_field_date' => 'off', 'store_field_promo' => 'off']);

        $page = $this->page();
        $this->assertStringNotContainsString('name="date"', $page);
        $this->assertStringNotContainsString('name="promo"', $page);

        $this->place(['date' => '', 'slot' => ''])->assertOk();

        // ولا يُخترع له موعد: `Carbon::parse('')` تقرأ «الآن» فيقف الطلبُ متأخّرًا
        $this->assertNull(Order::where('business_id', $this->shop->id)->firstOrFail()->scheduled_for);
    }

    /** وما أُرسل عن حقلٍ مُطفأٍ يُسقَط — لا يُبتلع ولا يردّ الطلب */
    public function test_what_is_sent_about_a_dead_field_is_dropped(): void
    {
        $this->picks(['store_field_address' => 'off', 'store_field_recipient' => 'off']);

        $this->place(['address' => 'عنوانٌ دُسّ', 'recipient_name' => 'مدسوس'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        $this->assertStringNotContainsString('عنوانٌ دُسّ', (string) $order->delivery_address);
        $this->assertSame('مريم', $order->recipient_name, 'وصل مستلِمٌ من حقلٍ لا وجود له');
    }

    /**
     * وكوبونٌ يُرسَل إلى متجرٍ أطفأ حقلَه لا يُطبَّق.
     *
     * الحقلُ المخفيُّ لا يمنع إرسالَه، فيُخصم من فاتورته بكودٍ قديمٍ يعرفه
     * زبونٌ واحد — وهو لا يرى في شاشته حقلًا يشكّ فيه.
     */
    public function test_a_coupon_sent_to_a_shop_that_hid_the_box_is_ignored(): void
    {
        Coupon::create([
            'business_id' => $this->shop->id, 'code' => 'HALF', 'type' => 'نسبة',
            'value' => 50, 'active' => true, 'used_count' => 0,
        ]);

        $withBox = $this->postJson('/s/ribbon/quote', [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]], 'fulfil' => 'pickup', 'promo' => 'HALF',
        ])->assertOk()->json();
        $this->assertSame(10.0, (float) $withBox['discount'], 'الكوبونُ لا يعمل أصلًا');

        $this->picks(['store_field_promo' => 'off']);

        $hidden = $this->postJson('/s/ribbon/quote', [
            'items' => [['id' => $this->bouquet->id, 'qty' => 1]], 'fulfil' => 'pickup', 'promo' => 'HALF',
        ])->assertOk()->json();

        $this->assertSame(0.0, (float) $hidden['discount'], 'خُصم بكودٍ في متجرٍ أطفأ حقلَه');
    }

    /* ═══════════ الاشتراط ═══════════ */

    /** حقلٌ صار مطلوبًا يردّ الطلبَ بلا مِلئه */
    public function test_a_field_made_required_stops_an_empty_order(): void
    {
        $this->picks(['store_field_recipient' => 'required']);

        $this->place(['recipient_name' => '', 'recipient_phone' => ''])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['recipient_name']]);

        $this->place(['recipient_name' => 'أمّي', 'recipient_phone' => '96899220002'])->assertOk();
    }

    /** وحقلٌ صار اختياريًّا يمضي فارغًا */
    public function test_a_field_made_optional_lets_an_empty_order_through(): void
    {
        $this->picks(['store_field_address' => 'optional']);

        $this->place(['address' => ''])->assertOk();

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
    }

    /**
     * والشرطُ في العنوان والمنطقة مربوطٌ بالتوصيل لا بالحقل وحده.
     *
     * من اختار الاستلامَ من المحلّ لا يُسأل عن عنوانه — وهو حالُ النظام
     * قبل الشاشة ويبقى.
     */
    public function test_the_address_is_only_demanded_of_whoever_is_delivered_to(): void
    {
        $this->place(['fulfil' => 'pickup', 'address' => '', 'area' => ''])->assertOk();

        $this->place(['fulfil' => 'delivery', 'address' => ''])
            ->assertStatus(422)->assertJsonPath('errors.address.0', 'اكتب العنوان بالتفصيل.');
    }

    /** والقائمةُ تُحرَس ولو كان الحقلُ اختياريًّا — منطقةٌ من خارجها سائقٌ بلا وجهة */
    public function test_a_value_outside_the_list_is_refused_even_when_optional(): void
    {
        $this->picks(['store_field_area' => 'optional']);

        $this->place(['area' => ''])->assertOk();

        $this->place(['area' => 'صلالة'])
            ->assertStatus(422)->assertJsonPath('errors.area.0', 'اختر المنطقة.');
    }

    /* ═══════════ طرقُ الاستلام ═══════════ */

    /** متجرٌ لا يوصّل لا يعرض توصيلًا ولا يُسعّر رسمَه */
    public function test_a_shop_that_does_not_deliver_prices_no_delivery(): void
    {
        $this->picks(['store_fulfil' => 'pickup']);

        $this->assertStringNotContainsString('data-v="delivery"', $this->page(), 'عُرض اختيارٌ لا ثانيَ له');

        // وتسعيرةٌ بلا طريقةٍ لا تحمل رسمًا لا يُدفع
        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->bouquet->id, 'qty' => 1]]])
            ->assertOk()->json();
        $this->assertSame(0.0, (float) $q['delivery']);

        $this->place(['fulfil' => 'delivery'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['fulfil']]);
    }

    /**
     * والافتراضيُّ «توصيل» متى كان مفتوحًا.
     *
     * `FlowerOrder::FULFILLMENT` يبدأ بالاستلام، فقراءةُ أوّلِه تقلب
     * افتراضيَّ كلّ متجرٍ قائم: تسعيرةٌ بلا `fulfil` كانت تحمل الرسمَ فتصير
     * بلا رسم — ويُعرض للزبون مجموعٌ أقلُّ ممّا سيُدفع.
     */
    public function test_a_quote_without_a_method_still_prices_delivery(): void
    {
        $q = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->bouquet->id, 'qty' => 1]]])
            ->assertOk()->json();

        $this->assertSame(3.0, (float) $q['delivery']);
    }

    /** ولا تخلو من واحدة — إعدادٌ فارغٌ يُقرأ «الاثنتين» لا «لا شيء» */
    public function test_a_shop_never_ends_up_delivering_nothing(): void
    {
        $this->picks(['store_fulfil' => 'عبث']);

        $this->assertSame(['pickup', 'delivery'], CheckoutFields::fulfilments($this->shop->id));
        $this->place()->assertOk();
    }

    /* ═══════════ الحجزُ مقدَّمًا ═══════════ */

    /** وأبعدُ موعدٍ كما ضبطه — وما بعده يُردّ */
    public function test_the_booking_window_is_the_one_the_shop_set(): void
    {
        $this->picks(['store_max_days' => '3']);

        $this->place(['date' => '2027-02-03'])->assertOk();
        $this->place(['date' => '2027-02-20'])->assertStatus(422)->assertJsonStructure(['errors' => ['date']]);

        // والفراغُ ستّون كما كان
        $this->picks(['store_max_days' => '']);
        $this->place(['date' => '2027-02-20'])->assertOk();
    }

    /* ═══════════ الشاشةُ والخادمُ من موضعٍ واحد ═══════════ */

    /**
     * كلُّ حقلٍ يملك التاجرُ أمرَه له مفتاحٌ يُحفظ.
     *
     * حقلٌ يُضاف إلى `FIELDS` ولا يُضاف إلى الإعدادات يُقرأ أبدًا بحاله
     * القديمة مهما حرّك التاجرُ مفتاحَه — ولا يُخطئ شيء.
     */
    public function test_every_controllable_field_has_a_key_that_is_saved(): void
    {
        $keys = array_keys(MarketingSettings::group($this->shop->id, 'website'));

        foreach (CheckoutFields::FIELDS as $field) {
            $this->assertContains('store_field_'.$field, $keys, 'حقلٌ بلا مفتاح: '.$field);
        }
    }

    /**
     * ومتجرٌ بلا طريقةِ تسليمٍ لا يُحفظ.
     *
     * إطفاؤهما معًا يترك زبونًا يملأ النموذجَ ثمّ يُردّ بخطأٍ عن حقلٍ لا
     * يراه. والشاشةُ تمنعه، ومن أرسل الحمولةَ بيده لا.
     */
    public function test_a_shop_cannot_save_itself_out_of_delivering(): void
    {
        $owner = User::where('business_id', $this->shop->id)->firstOrFail();

        /*
         * والبابُ `marketing.store.save` لا `marketing.website.save`.
         *
         * الأوّلُ ما تكتب فيه شاشةُ الإعدادات متجرَها، والثاني بابٌ أقدمُ
         * لنطاق الموقع. وهما متجاوران في المتحكّم نفسِه — واختبارٌ يطرق
         * الثاني يقرأ «حُفظ» ويظنّ حارسَه يعمل.
         */
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_fulfil' => ''])
            ->assertSessionHasErrors('store_fulfil');

        // ولا يُخفى العنوانُ والمنطقةُ معًا في متجرٍ يوصّل — سائقٌ بلا وجهة
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), [
            'store_fulfil' => 'delivery,pickup',
            'store_field_area' => 'off', 'store_field_address' => 'off',
        ])->assertSessionHasErrors('store_field_address');

        // وأحدُهما يكفي: من يوصّل داخل مناطقَ معدودةٍ ويتّصل ليسأل عن البيت
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), [
            'store_fulfil' => 'delivery',
            'store_field_area' => 'required', 'store_field_address' => 'off',
        ])->assertSessionHasNoErrors();

        // وقيمةٌ ليست من الثلاث تُردّ — لا تُحفظ فتُقرأ «ما كان» بلا أن يدري
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), [
            'store_fulfil' => 'delivery,pickup', 'store_field_date' => 'عبث',
        ])->assertSessionHasErrors('store_field_date');
    }

    /** والشاشةُ تعرض ما يشترطه الخادم — النجمةُ حيث «مطلوب» */
    public function test_the_page_stars_exactly_what_the_server_demands(): void
    {
        $this->picks(['store_field_recipient' => 'required', 'store_field_address' => 'optional']);

        $page = $this->page();

        $this->assertStringContainsString('اسم المستلِم *', $page);
        $this->assertStringNotContainsString('رقم المنزل) *', $page, 'نُجّم حقلٌ لا يشترطه الخادم');

        // والعكسُ يُحرَس كذلك: مشترَطٌ بلا نجمةٍ يُترك فارغًا ثمّ يُردّ
        $this->picks(['store_field_address' => 'required']);
        $this->assertStringContainsString('رقم المنزل) *', $this->page(), 'شُرط حقلٌ بلا نجمةٍ تقوله');
        // ومطلوبًا يُفتح بلا خانةٍ تُضغط: شرطٌ خلف طيّةٍ لا يُرى
        $this->assertStringNotContainsString('type="checkbox" data-rb-forother', $page);
    }
}
