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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ميزةُ الإهداء — الطلبُ هديّةٌ لغير مشتريه، لمن فتحها له مديرُ المنصّة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإهداءُ مغلقٌ افتراضًا، ويفتحه مديرُ المنصّة لنشاطٍ بعينه
 *     (`businesses.gift_orders_enabled`). لا يفتحه التاجرُ من إعداداته ولا
 *     بحمولةٍ يكتبها، ولا `store_gift_checkout` محفوظ.
 *   - الطلبُ العاديّ كما كان؛ والهديّةُ حالةٌ مكتوبة (`is_gift`) لا مستنتَجة.
 *   - المشتري صاحبُ الطلب والدفع، والمستلِمُ منفصلٌ ومطلوبٌ اسمًا ورقمًا.
 *   - المناسبةُ اختياريّة، من القائمة أو «أخرى» بنصّها؛ و«لا تذكر اسمي»
 *     يُخفيه عن المستلِم لا عن التاجر.
 *   - قسمُ المستلِم «بيانات المستلم» لا «كرت الهدية».
 *   - الهديّةُ تُوصَّل كأيّ طلب: المنطقةُ من القائمة والعنوانُ بقواعده، ولا
 *     طريقةَ موقعٍ تُختار، ولا يُكتب `contact_recipient` لطلبٍ جديد.
 *   - والطلبُ القديم بـ`contact_recipient` يُقرأ ويُتمَّم كما كان.
 *   - تنبيهُ نطاق التوصيل لكلّ لغةٍ نصُّه، ولا يمسّ القواعد.
 *   - ولا يتغيّر مالٌ: الإجماليُّ والمعاملةُ كطلبٍ ليس هديّة.
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

        // مديرُ المنصّة فتح الإهداءَ للأوّل وحده
        $this->a->update(['gift_orders_enabled' => true]);
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
            'store_delivery_areas' => "الخوير\nالعذيبة", 'store_delivery_slots' => '9 ص – 12 م',
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

    private function boss(): User
    {
        return User::firstOrCreate(['email' => 'boss@abaadapp.om'], [
            'business_id' => null, 'name' => 'مدير المنصة', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** ما ترسله شاشةُ النشاط في المنصّة — الحقولُ الإلزاميّة ومفتاحُ الإهداء */
    private function platformSave(Business $shop, bool $gifting)
    {
        return $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $shop->id), [
            'name' => $shop->name, 'type' => $shop->type, 'status' => 'نشط',
            'gift_orders_enabled' => $gifting ? '1' : '0',
        ]);
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
        ]);
    }

    /** ما يراه الزبون — لا شرحَ السكربت المضمَّن في الصفحة */
    private function visible(string $html): string
    {
        return (string) preg_replace('#<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->#s', '', $html);
    }

    /* ═══════════ مديرُ المنصّة يفتح الإهداء ═══════════ */

    public function test_gifting_is_off_by_default_and_the_platform_opens_it_per_business(): void
    {
        $fresh = Business::create(['name' => 'نشاط جديد', 'type' => 'عام', 'status' => 'نشط']);
        $this->assertFalse((bool) $fresh->fresh()->gift_orders_enabled, 'الإهداءُ مفتوحٌ لنشاطٍ لم يُقرَّر له');
        $this->assertFalse(GiftOrders::on($fresh->id));
        $this->assertFalse(GiftOrders::on($this->b->id));

        // مديرُ المنصّة يفتحه للثاني — ولا يمسّ الأوّل ولا غيرَه
        $this->platformSave($this->b, true)->assertSessionHasNoErrors();
        $this->assertTrue(GiftOrders::on($this->b->id));
        $this->assertTrue(GiftOrders::on($this->a->id));
        $this->assertFalse(GiftOrders::on($fresh->id));

        // ويغلقه
        $this->platformSave($this->b, false)->assertSessionHasNoErrors();
        $this->assertFalse(GiftOrders::on($this->b->id));
        $this->assertTrue(GiftOrders::on($this->a->id), 'إغلاقُه لنشاطٍ أغلقه لآخر');

        // ويُقيَّد في السجلّ — إذنٌ يُسأل عنه لاحقًا
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $this->b->id, 'description' => 'فتح الإهداء في المتجر الإلكتروني: '.$this->b->name]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $this->b->id, 'description' => 'أغلق الإهداء في المتجر الإلكتروني: '.$this->b->name]);

        // وشاشةُ التعديل تعرض المحفوظ
        $this->actingAs($this->boss())->get(route('super-admin.businesses.edit', $this->a->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('business.gift_orders_enabled', true)->etc());
        $this->actingAs($this->boss())->get(route('super-admin.businesses.edit', $this->b->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('business.gift_orders_enabled', false)->etc());
    }

    public function test_a_platform_save_without_the_flag_leaves_it_as_it_is(): void
    {
        $this->actingAs($this->boss())->put(route('super-admin.businesses.update', $this->a->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->a->type, 'status' => 'نشط',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(GiftOrders::on($this->a->id), 'حفظٌ لاسمٍ أطفأ الإهداء');
    }

    public function test_a_merchant_cannot_open_gifting_from_its_settings_nor_a_crafted_payload(): void
    {
        // من إعدادات الموقع — والمفتاحُ القديم لا يُحفظ ولا يُقرأ
        $this->actingAs($this->owner($this->b))
            ->post(route('admin.marketing.store.save'), ['store_gift_checkout' => '1', 'business_id' => $this->a->id, 'gift_orders_enabled' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse(GiftOrders::on($this->b->id));
        $this->assertDatabaseMissing('settings', ['business_id' => $this->b->id, 'key' => 'store_gift_checkout']);

        // ولا صفٌّ قديمٌ في الجدول يفتحه
        Setting::create(['business_id' => $this->b->id, 'key' => 'store_gift_checkout', 'value' => '1']);
        MarketingSettings::forget($this->b->id);
        $this->assertFalse(GiftOrders::on($this->b->id), '`store_gift_checkout` فتح الإهداء');

        // ولا حمولةُ هديّةٍ في إتمام متجرٍ مغلق
        $this->postJson('/s/other/checkout', $this->order($this->theirRose, [
            'is_gift' => true, 'recipient_name' => 'سارة', 'recipient_phone' => '96899110002',
            'occasion' => 'love', 'hide_sender' => true, 'store_gift_checkout' => '1',
        ]))->assertOk();
        $o = Order::sole();
        $this->assertSame([false, null, false], [(bool) $o->is_gift, $o->occasion_type, (bool) $o->hide_sender]);

        // ولا بابَ المنصّة — لنشاطه ولا لغيره
        $this->actingAs($this->owner($this->b))
            ->put(route('super-admin.businesses.update', $this->b->id), ['name' => 'x', 'type' => 'x', 'status' => 'نشط', 'gift_orders_enabled' => '1'])
            ->assertForbidden();
        $this->actingAs($this->owner($this->b))
            ->put(route('super-admin.businesses.update', $this->a->id), ['name' => 'x', 'type' => 'x', 'status' => 'نشط', 'gift_orders_enabled' => '0'])
            ->assertForbidden();
        $this->assertFalse(GiftOrders::on($this->b->id));
        $this->assertTrue(GiftOrders::on($this->a->id));
        $this->assertSame('متجر ribbon', $this->a->fresh()->name);
    }

    /**
     * والهجرةُ لا تنقل شيئًا — الإهداءُ يبدأ مغلقًا لكلّ نشاط، قرارُ المالك.
     *
     * يُعاد بناءُ ما قبل الهجرة: العمودُ غائب، ونشاطٌ رفع `store_gift_checkout`
     * بنفسه وآخرُ لم يرفعه. وبعدها كلاهما مغلق، ولا يفتحه للتاجر مفتاحُه القديم
     * ولا حمولتُه؛ ومديرُ المنصّة يفتحه لواحدٍ فلا ينفتح الآخر.
     */
    public function test_the_migration_starts_every_business_closed_whatever_it_had_set(): void
    {
        $migration = require database_path('migrations/2026_10_04_110000_the_platform_decides_who_may_take_gift_orders.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('businesses', 'gift_orders_enabled'));

        // ما قبل الهجرة: الأوّلُ رفع المفتاحَ بنفسه، والثاني تركه مغلقًا
        Setting::create(['business_id' => $this->a->id, 'key' => 'store_gift_checkout', 'value' => '1']);
        Setting::create(['business_id' => $this->b->id, 'key' => 'store_gift_checkout', 'value' => '0']);
        Setting::create(['business_id' => null, 'key' => 'store_gift_checkout', 'value' => '1']);

        $migration->up();
        MarketingSettings::forget($this->a->id);
        MarketingSettings::forget($this->b->id);

        // ١ و٢: كلاهما مغلق — الخامُ لا النموذج
        $this->assertSame(
            [false, false],
            [(bool) DB::table('businesses')->where('id', $this->a->id)->value('gift_orders_enabled'),
                (bool) DB::table('businesses')->where('id', $this->b->id)->value('gift_orders_enabled')],
            'الهجرةُ نقلت `store_gift_checkout` إلى الإذن',
        );
        $this->assertFalse(GiftOrders::on($this->a->id));
        $this->assertFalse(GiftOrders::on($this->b->id));
        $this->assertSame(3, Setting::where('key', 'store_gift_checkout')->count(), 'الهجرةُ محت صفوفًا');

        // ٣: نشاطٌ جديدٌ بعدها مغلق
        $fresh = Business::create(['name' => 'نشاط جديد', 'type' => 'عام', 'status' => 'نشط']);
        $this->assertFalse((bool) DB::table('businesses')->where('id', $fresh->id)->value('gift_orders_enabled'));
        $this->assertFalse(GiftOrders::on($fresh->id));

        // ٤: التاجرُ يرسل المفتاحَ القديم — فلا ينفتح، ولا يُعرض في إتمامه
        $this->actingAs($this->owner($this->a))
            ->post(route('admin.marketing.store.save'), ['store_gift_checkout' => '1', 'gift_orders_enabled' => '1'])
            ->assertSessionHasNoErrors();
        MarketingSettings::forget($this->a->id);
        $this->assertFalse(GiftOrders::on($this->a->id), 'التاجرُ فتح الإهداءَ بنفسه');
        $this->assertStringNotContainsString('rb-gift-toggle', $this->get('/s/ribbon/checkout')->assertOk()->getContent());

        // ٥: مديرُ المنصّة يفتحه للأوّل صراحةً
        $this->platformSave($this->a, true)->assertSessionHasNoErrors();
        $this->assertTrue(GiftOrders::on($this->a->id));

        // ٦: ولا ينفتح الثاني ولا الجديد
        $this->assertFalse(GiftOrders::on($this->b->id), 'فتحُه لنشاطٍ فتحه لآخر');
        $this->assertFalse(GiftOrders::on($fresh->id));
    }

    public function test_the_merchant_screen_carries_no_gifting_switch(): void
    {
        foreach (['Fields.tsx', 'form.ts'] as $file) {
            $src = file_get_contents(resource_path('js/Pages/Admin/Website/theme/sections/'.$file));
            $this->assertStringNotContainsString('store_gift_checkout', $src, "$file ما زال يكتب مفتاحَ الإهداء");
        }
        $this->assertStringNotContainsString('ميزة الإهداء', file_get_contents(resource_path('js/Pages/Admin/Website/theme/sections/Fields.tsx')));

        $form = file_get_contents(resource_path('js/Pages/Platform/Businesses/partials/BusinessForm.tsx'));
        $this->assertStringContainsString('gift_orders_enabled', $form);
        $this->assertStringContainsString('الإهداء في المتجر الإلكتروني', $form);
        $this->assertStringContainsString('السماح لهذا النشاط باستقبال طلبات هدايا لمستلمين آخرين عبر المتجر الإلكتروني.', $form);
    }

    /* ═══════════ صفحةُ الإتمام ═══════════ */

    public function test_the_checkout_shows_gifting_only_where_it_is_open_under_recipient_details(): void
    {
        $off = $this->get('/s/other/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-rb-gift ', $off);
        $this->assertStringNotContainsString('هذا الطلب هدية', $off);

        $ar = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        foreach (['بيانات المستلم', 'هذا الطلب هدية', 'المناسبة — اختياري', 'اكتب المناسبة', 'لا تذكر اسمي للمستلم', 'عيد ميلاد', 'اكتب عنوان المستلم في قسم التوصيل أعلاه.'] as $s) {
            $this->assertStringContainsString($s, $ar);
        }

        $en = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        foreach (['Recipient details', 'This order is a gift', 'Occasion — optional', 'Enter the occasion', 'Do not reveal my name to the recipient', 'Birthday'] as $s) {
            $this->assertStringContainsString(e($s), $en);
        }
        foreach (['هذا الطلب هدية', 'المناسبة', 'لا تذكر اسمي', 'عيد ميلاد', 'بيانات المستلم'] as $arabic) {
            $this->assertStringNotContainsString($arabic, $this->visible($en), 'نصٌّ عربيٌّ من الإهداء في الصفحة الإنجليزيّة');
        }

        // والمستلِمُ لا يُسمّى «كرت الهدية»، ولا طريقةَ موقعٍ تُختار
        $section = $this->visible($ar);
        $recipient = substr($section, (int) strpos($section, 'data-testid="rb-recipient-section"'));
        $end = strpos($recipient, 'data-testid="rb-card-section"') ?: strpos($recipient, 'data-rb-pay');
        $recipient = substr($recipient, 0, (int) $end);
        $this->assertStringContainsString('بيانات المستلم', $recipient);
        $this->assertStringNotContainsString('كرت الهدية', $recipient);
        foreach (['recipient_location', 'طريقة تحديد موقع المستلم', 'سأدخل الموقع الآن', 'تواصلوا مع المستلم للحصول على الموقع', 'تواصل مع المستلم'] as $gone) {
            $this->assertStringNotContainsString($gone, $ar, "ما زال في الإتمام «{$gone}»");
            $this->assertStringNotContainsString($gone, $en);
        }
        foreach (['Recipient location', "I'll provide the location now", 'Contact the recipient for the location'] as $gone) {
            $this->assertStringNotContainsString(e($gone), $en);
        }
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
        // ولا طريقةَ موقعٍ لطلبٍ جديد — والعنوانُ من قسم التوصيل
        $this->assertNull($gift->recipient_location_mode);
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

    /* ═══════════ الهديّةُ تُوصَّل كأيّ طلب ═══════════ */

    public function test_a_gift_uses_the_normal_delivery_area_and_address_and_cannot_skip_them(): void
    {
        // من القائمة نفسِها التي يختار منها كلُّ طلب
        $this->postJson('/s/ribbon/checkout', $this->gift(['area' => 'العذيبة', 'address' => 'بيت ١٢']))->assertOk();
        $this->assertSame('العذيبة — بيت 12', Order::sole()->delivery_address);

        // ومنطقةٌ خارج القائمة تُردّ كما تُردّ لكلّ طلب
        $this->postJson('/s/ribbon/checkout', $this->gift(['area' => 'صلالة']))->assertStatus(422)->assertJsonValidationErrors('area');

        // والعنوانُ المطلوب لا يُتخطّى — ولا بحمولة «تواصلوا مع المستلم» القديمة
        foreach ([[], ['recipient_location' => GiftOrders::CONTACT]] as $extra) {
            $this->postJson('/s/ribbon/checkout', $this->gift($extra + ['address' => '']))
                ->assertStatus(422)->assertJsonValidationErrors('address');
        }
        MarketingSettings::save($this->a->id, 'website', ['store_field_area' => 'required']);
        $this->postJson('/s/ribbon/checkout', $this->gift(['area' => '', 'recipient_location' => GiftOrders::CONTACT]))
            ->assertStatus(422)->assertJsonValidationErrors('area');
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['address' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertSame(1, Order::count());
    }

    public function test_no_new_order_ever_writes_contact_recipient(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_location' => GiftOrders::CONTACT]))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->gift(['recipient_location' => 'anywhere']))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['recipient_location' => GiftOrders::CONTACT]))->assertOk();

        $this->assertSame(0, Order::where('recipient_location_mode', GiftOrders::CONTACT)->count());
        $this->assertSame([null, null, null], Order::orderBy('id')->pluck('recipient_location_mode')->all());
        $this->assertSame(3, Order::whereNotNull('delivery_address')->count());
        foreach (Order::all() as $o) {
            $this->assertFalse(GiftOrders::awaitingLocation($o));
            $this->assertNull(GiftOrders::contactLink($o, 'x'));
        }
    }

    public function test_pickup_stays_as_it_was_for_a_gift_and_a_normal_order(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift(['fulfil' => 'pickup', 'address' => '', 'area' => '']))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['fulfil' => 'pickup', 'address' => '', 'area' => '']))->assertOk();

        $this->assertSame([[true, null, null], [false, null, null]], Order::orderBy('id')->get()
            ->map(fn ($o) => [(bool) $o->is_gift, $o->recipient_location_mode, $o->delivery_address])->all());
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

    /* ═══════════ تنبيهُ نطاق التوصيل ═══════════ */

    public function test_the_delivery_area_notice_speaks_each_language_alone_and_changes_no_rule(): void
    {
        $ar = 'التوصيل متاح داخل المناطق المحددة فقط. يرجى اختيار منطقة التوصيل الصحيحة قبل تأكيد الطلب.';
        $en = 'Delivery is available only within the listed service areas.';

        // فارغان: لا تنبيه
        $this->assertStringNotContainsString('rb-delivery-area-note', $this->get('/s/ribbon/checkout')->getContent());

        // العربيّ وحده: يُعرض في العربيّة ولا يقع على الإنجليزيّة
        MarketingSettings::save($this->a->id, 'website', ['store_delivery_area_note' => $ar]);
        $this->get('/s/ribbon/checkout')->assertOk()->assertSee($ar)->assertSee('data-testid="rb-delivery-area-note"', false);
        $page = $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        $this->assertStringNotContainsString($ar, $page);
        $this->assertStringNotContainsString('rb-delivery-area-note', $page);

        // والإنجليزيّ وحده: يُعرض في الإنجليزيّة ولا يقع على العربيّة
        MarketingSettings::save($this->a->id, 'website', ['store_delivery_area_note' => '', 'store_delivery_area_note_en' => $en]);
        $this->get('/s/ribbon/checkout?lang=en')->assertOk()->assertSee($en);
        $arPage = $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString($en, $arPage);
        $this->assertStringNotContainsString('rb-delivery-area-note', $arPage);

        // والقواعدُ كما هي: منطقةٌ خارج القائمة تُردّ، ومن القائمة تُقبل — هديّةً أو لا
        MarketingSettings::save($this->a->id, 'website', ['store_delivery_area_note' => $ar]);
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose, ['area' => 'صلالة']))->assertStatus(422)->assertJsonValidationErrors('area');
        $this->postJson('/s/ribbon/checkout', $this->gift(['area' => 'صلالة']))->assertStatus(422)->assertJsonValidationErrors('area');
        $this->postJson('/s/ribbon/checkout', $this->order($this->rose))->assertOk();
        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
    }

    public function test_the_merchant_saves_both_notices_for_its_own_shop(): void
    {
        $this->actingAs($this->owner($this->a))->post(route('admin.marketing.store.save'), [
            'store_delivery_area_note' => 'داخل مسقط فقط', 'store_delivery_area_note_en' => 'Muscat only', 'business_id' => $this->b->id,
        ])->assertSessionHasNoErrors();

        $a = MarketingSettings::group($this->a->id, 'website');
        $this->assertSame(['داخل مسقط فقط', 'Muscat only'], [$a['store_delivery_area_note'], $a['store_delivery_area_note_en']]);
        $b = MarketingSettings::group($this->b->id, 'website');
        $this->assertSame(['', ''], [$b['store_delivery_area_note'], $b['store_delivery_area_note_en']]);
    }

    /* ═══════════ الطلباتُ القديمة بـ«تواصلوا مع المستلم» ═══════════ */

    /**
     * طلبٌ سبق هذا التغيير — بلا عنوانٍ وبانتظار التواصل.
     *
     * لا يُنشئه الإتمامُ بعد اليوم، فيُكتب حالُه في القاعدة كما بقي فيها.
     */
    private function legacyContactOrder(): Order
    {
        $this->postJson('/s/ribbon/checkout', $this->gift(['hide_sender' => true, 'name' => 'مريم المُهدية']))->assertOk();
        $order = Order::latest('id')->first();
        DB::table('orders')->where('id', $order->id)
            ->update(['recipient_location_mode' => GiftOrders::CONTACT, 'delivery_address' => null]);

        return $order->fresh();
    }

    public function test_a_legacy_contact_order_still_loads_and_says_where_its_location_stands(): void
    {
        $o = $this->legacyContactOrder();
        $this->assertTrue(GiftOrders::awaitingLocation($o));

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

        $this->actingAs($this->owner($this->a))->get(route('admin.preparation.index'))->assertOk();
    }

    public function test_a_new_gift_shows_no_location_state_to_the_merchant(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
        $o = Order::sole();

        $this->actingAs($this->owner($this->a))->get(route('admin.orders.show', $o->number))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('order.is_gift', true)
                ->where('order.recipient_location_mode', null)
                ->where('order.location_label', null)
                ->where('order.awaiting_location', false));
    }

    public function test_the_legacy_whatsapp_action_names_neither_sender_nor_price_and_sends_nothing(): void
    {
        Http::fake();
        $o = $this->legacyContactOrder();

        $this->actingAs($this->owner($this->a))
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

    public function test_the_legacy_action_is_refused_for_a_new_gift_and_ends_once_an_address_is_written(): void
    {
        $this->postJson('/s/ribbon/checkout', $this->gift())->assertOk();
        $new = Order::sole();

        $this->assertNull(GiftOrders::contactLink($new, 'x'));
        $this->actingAs($this->owner($this->a))->post(route('admin.orders.contactRecipient', $new->number));
        $this->assertSame('danger', session('toast')['type']);
        $this->assertArrayNotHasKey('link', session('toast'));

        // وعنوانٌ كُتب بعد التواصل يُنهي انتظارَ الطلب القديم
        $waiting = $this->legacyContactOrder();
        $waiting->update(['delivery_address' => 'العذيبة، بيت ١٢']);
        $this->assertFalse(GiftOrders::awaitingLocation($waiting->fresh()));
        $this->assertNull(GiftOrders::contactLink($waiting->fresh(), 'x'));
    }

    public function test_another_shop_cannot_reach_a_gift_order_by_its_number(): void
    {
        $o = $this->legacyContactOrder();

        $this->actingAs($this->owner($this->b))->post(route('admin.orders.contactRecipient', $o->number))->assertNotFound();
        $this->actingAs($this->owner($this->b))->get(route('admin.orders.show', $o->number))->assertNotFound();
        $this->actingAs($this->owner($this->b))
            ->put(route('admin.orders.details.update', $o->number), ['recipient_phone' => '96800000000'])
            ->assertNotFound();
        $this->assertSame('96899110002', $o->fresh()->recipient_phone);
    }

    public function test_a_legacy_gift_awaiting_its_location_can_still_be_edited_and_others_keep_the_address_rule(): void
    {
        $o = $this->legacyContactOrder();

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
