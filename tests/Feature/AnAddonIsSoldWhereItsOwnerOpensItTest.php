<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\PriceMovedAfterPayment;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الإضافةُ تُباع حيث فتحها صاحبُها — الصندوق، أو الموقع، أو كلاهما، أو لا شيء.
 *
 * ═══ الحالُ التي أنشأت هذا الملفّ ═══
 *
 * كانت الإضافةُ تُباع في الصندوق وحدَه: الموقعُ يطرح إضافاتِ البند من السلّة
 * (`WebCheckout::items`) ويكتب بندَه بـ`addons_total = 0`. فمن أراد أن يبيع
 * «شوكولاتة» مع باقته على الشبكة لم يجد إلّا أن يجعلها صنفًا مستقلًّا.
 *
 * ═══ وما يُحرس ═══
 *
 *   - الإضافةُ القائمة تبقى في الصندوق ولا تظهر في الموقع (افتراضا الهجرة).
 *   - صاحبُ المحلّ يختار القناتين، والجديدةُ في الصندوق وحدَه ما لم يقل غيرَ ذلك.
 *   - الخادمُ يردّ ما لم يُفتح للباب: الصندوقُ لا يبيع إضافةَ الموقع، والموقعُ
 *     لا يبيع إضافةَ الصندوق ولا الموقوفةَ ولا إضافةَ متجرٍ آخر — ولو حمل الطلبُ
 *     معرّفَها بيده. وشاشةُ الكاشير لا تعرض ما يردّه.
 *   - الموقعُ يسعّر الإضافةَ من القاعدة، ويضيفها مرّةً للبند لا لكلّ قطعة،
 *     ويُدخلها الضريبةَ، ويُبقي بندين بإضافاتٍ مختلفة بندين.
 *   - طلبُ الموقع يكتب لقطاتِها كما يكتبها الصندوق، ويخصم رفَّها مرّةً،
 *     وتكلفتُها في القيد مرّةً.
 *   - إضافةٌ نفد رفُّها تُردّ قبل صفحة البنك. ودفعةٌ قُبضت تُكتب بإضافاتها ولو
 *     أُطفئت في أثناء الدفع، وتبدُّلُ سعرها يردّ المالَ لا يكتب مبلغًا آخر.
 *
 * والأرقامُ قبل الضريبة إلّا حيث تُفتح: باقةٌ بعشرين، وشوكولاتةٌ باثنين
 * (من رفّ «شوكولاتة الإضافات» بتكلفة ٠٫٨٠٠)، وبطاقةُ تهنئةٍ بواحد.
 */
class AnAddonIsSoldWhereItsOwnerOpensItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    private Branch $branch;

    private Product $bouquet;

    private Product $chocStock;

    /** للصندوق والموقع معًا، ومن رفٍّ */
    private Addon $choc;

    /** للموقع وحدَه — خدمةٌ بلا رفّ */
    private Addon $card;

    /** إضافةٌ قائمة: للصندوق وحدَه كما كانت */
    private Addon $wrap;

    /** مفتوحةٌ للموقع لكنّها موقوفة */
    private Addon $balloon;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-05-04 10:00:00');

        // قوائمُ المتاجر في الإعدادات بمعرّفاتها — لا يصير متجرُ الاختبار «سعود» بتسلسل PostgreSQL
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'addon-owner@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'الكاشير', 'email' => 'addon-cashier@abaad.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_fulfil' => 'pickup']);
        // متجرٌ غيرُ مسجّلٍ في الضريبة — الأرقامُ ٢٠ و٢ و١ كما هي. وتُفتح حيث تُختبر
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'name_en' => 'Rose bouquet',
            'price' => 20, 'cost' => 6, 'quantity' => 10, 'tracks_stock' => true, 'active' => true, 'published' => true,
        ]);
        // رفُّ الشوكولاتة لا يُباع وحدَه في الموقع — يُؤكل بالإضافة
        $this->chocStock = Product::create([
            'business_id' => $this->shop->id, 'name' => 'شوكولاتة الإضافات',
            'price' => 2, 'cost' => 0.8, 'quantity' => 5, 'tracks_stock' => true, 'active' => true, 'published' => false,
        ]);

        $this->choc = $this->addon('شوكولاتة', 2, ['pos_enabled' => true, 'web_enabled' => true,
            'inventory_product_id' => $this->chocStock->id, 'inventory_quantity' => 1]);
        $this->card = $this->addon('بطاقة تهنئة', 1, ['pos_enabled' => false, 'web_enabled' => true]);
        $this->wrap = $this->addon('تغليف عند الكاشير', 0.5, []);
        $this->balloon = $this->addon('بالون', 1.5, ['web_enabled' => true, 'active' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function addon(string $name, float $price, array $attrs): Addon
    {
        return Addon::create($attrs + [
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'active' => true,
        ])->fresh();
    }

    /** @param  list<array{0: Addon, 1: int}>  $addons */
    private function line(array $addons = [], int $qty = 1): array
    {
        $line = ['id' => $this->bouquet->id, 'variant_id' => null, 'qty' => $qty];
        if ($addons) {
            $line['addons'] = array_map(fn ($a) => ['id' => $a[0]->id, 'qty' => $a[1]], $addons);
        }

        return $line;
    }

    private function quote(array $items)
    {
        return $this->postJson('/s/ribbon/quote', ['items' => $items, 'fulfil' => 'pickup']);
    }

    private function form(array $items, string $pay = 'cod'): array
    {
        return [
            'items' => $items, 'fulfil' => 'pickup', 'pay' => $pay,
            'name' => 'مريم', 'phone' => '96899110001', 'date' => '2027-05-05',
        ];
    }

    private function place(array $items)
    {
        return $this->postJson('/s/ribbon/checkout', $this->form($items));
    }

    private function sellAtTill(array $line, int $expect)
    {
        // والصندوقُ يرسل الإضافةَ بـ`addon_id` كما ترسلها شاشتُه
        $line['addons'] = array_map(fn ($a) => ['addon_id' => $a['id'], 'qty' => $a['qty']], $line['addons'] ?? []);

        return $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [$line + ['name' => 'باقة ورد']],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('addon', true),
        ])->assertStatus($expect);
    }

    private function paidIntent(array $items, float $amount): StorePaymentIntent
    {
        return StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'WEB-'.uniqid(), 'status' => StorePaymentIntent::PAID,
            'provider_transaction_id' => 'tx-'.uniqid(), 'amount' => $amount, 'currency' => 'OMR',
            'payload' => $this->form($items, 'card'), 'lang' => 'ar',
        ]);
    }

    /* ------------------------------ القنوات ------------------------------ */

    /** إضافةٌ كُتبت قبل العمودين تبقى في الصندوق ولا تظهر في الموقع */
    public function test_an_addon_written_before_the_channels_stays_at_the_till_and_off_the_website(): void
    {
        $id = DB::table('addons')->insertGetId([
            'business_id' => $this->shop->id, 'name' => 'إضافةٌ قديمة', 'price' => 1, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $old = Addon::findOrFail($id);

        $this->assertTrue((bool) DB::table('addons')->where('id', $id)->value('pos_enabled'));
        $this->assertFalse((bool) DB::table('addons')->where('id', $id)->value('web_enabled'));
        $this->assertTrue($old->sellsAtPos());
        $this->assertFalse($old->sellsOnline());
        // والموقوفةُ لا تُباع في أيّ قناة
        $this->assertFalse($this->balloon->sellsOnline());
        $this->assertFalse($this->balloon->sellsAtPos());
    }

    /** الجديدةُ في الصندوق وحدَه، والقناتان تُختاران، وحفظُ سعرٍ لا يغيّرهما */
    public function test_the_owner_chooses_the_channels_and_a_new_addon_starts_at_the_till_only(): void
    {
        $this->actingAs($this->owner);

        $plain = $this->postJson(route('admin.products.addons.store'), ['name' => 'شريط', 'price' => 0.5])
            ->assertOk()->json('addon');
        $this->assertTrue($plain['pos_enabled']);
        $this->assertFalse($plain['web_enabled']);

        $online = $this->postJson(route('admin.products.addons.store'), [
            'name' => 'بطاقة رقمية', 'price' => 1, 'pos_enabled' => false, 'web_enabled' => true,
        ])->assertOk()->json('addon');
        $this->assertFalse($online['pos_enabled']);
        $this->assertTrue($online['web_enabled']);

        // تعديلُ السعر وحدَه لا يفتحها للموقع ولا يغلقها عن الصندوق
        $this->putJson(route('admin.products.addons.update', $online['value']), ['name' => 'بطاقة رقمية', 'price' => 1.5])
            ->assertOk()->assertJsonPath('addon.pos_enabled', false)->assertJsonPath('addon.web_enabled', true);

        // والقناتان مطفأتان معًا: محفوظةٌ لا تُباع
        $this->putJson(route('admin.products.addons.update', $plain['value']), [
            'name' => 'شريط', 'price' => 0.5, 'pos_enabled' => false, 'web_enabled' => false,
        ])->assertOk();
        $none = Addon::findOrFail($plain['value']);
        $this->assertTrue((bool) $none->active);
        $this->assertFalse($none->sellsAtPos());
        $this->assertFalse($none->sellsOnline());

        $this->putJson(route('admin.products.addons.update', $plain['value']), [
            'name' => 'شريط', 'price' => 0.5, 'web_enabled' => 'نعم',
        ])->assertStatus(422)->assertJsonValidationErrors('web_enabled');
    }

    /** الصندوقُ يردّ إضافةَ الموقع من الخادم، ولا تعرضها شاشتُه */
    public function test_the_till_refuses_an_addon_open_to_the_website_only(): void
    {
        $this->sellAtTill($this->line([[$this->card, 1]]), 422)->assertJsonValidationErrors('items.0.addons');
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());

        // والقائمةُ كما كانت للصندوق: الإضافةُ القديمة والمشتركة تُباعان
        $this->sellAtTill($this->line([[$this->wrap, 1], [$this->choc, 1]]), 200);
        $item = OrderItem::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(2.5, (float) $item->addons_total, 0.0001);

        $this->actingAs($this->cashier);
        $screen = collect(Demo::products($this->branch->id))->firstWhere('id', $this->bouquet->id);
        $this->assertEqualsCanonicalizing([$this->choc->id, $this->wrap->id], $screen['addon_ids']);
        $flags = collect(Demo::addons())->pluck('active', 'id');
        $this->assertFalse($flags[$this->card->id], 'إضافةُ الموقع لا تُعرض على الكاشير');
        $this->assertTrue($flags[$this->wrap->id]);
    }

    /* ------------------------------ الموقع ------------------------------ */

    /** صفحةُ الصنف تعرض ما فُتح للموقع وحدَه، بأسعاره للعرض */
    public function test_the_product_page_offers_only_the_addons_open_to_the_website(): void
    {
        $html = $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-addons"', $html);
        $this->assertStringContainsString('إضافات اختيارية', $html);
        $this->assertMatchesRegularExpression('/data-rb-addon="'.$this->choc->id.'" data-qty="0" data-amount="2"/', $html);
        $this->assertMatchesRegularExpression('/data-rb-addon="'.$this->card->id.'" data-qty="0" data-amount="1"/', $html);
        $this->assertStringNotContainsString('data-rb-addon="'.$this->wrap->id.'"', $html);
        $this->assertStringNotContainsString('data-rb-addon="'.$this->balloon->id.'"', $html);
        $this->assertStringContainsString("resource_path('js/store/ribbon-addons.js')", file_get_contents(resource_path('views/store/ribbon/_addons.blade.php')));
        $this->assertStringContainsString('window.RBAddons', $html);

        // ونفادُ رفّها يُعرض بلا عدّاد
        $this->chocStock->update(['quantity' => 0]);
        $html = $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-rb-addon="'.$this->choc->id.'"[^>]*data-soldout/', $html);
    }

    /** باقةٌ ٢٠ + شوكولاتة ٢ + بطاقة ١ = ٢٣ — من القاعدة لا من المتصفّح */
    public function test_the_website_prices_the_addons_from_the_database(): void
    {
        $line = $this->line([[$this->choc, 1], [$this->card, 1]]);
        // سعرٌ كتبه المتصفّح لا يُقرأ
        $line['price'] = 0.001;
        $line['addons'][0]['price'] = 0.001;

        $q = $this->quote([$line])->assertOk()->json();

        $this->assertEqualsWithDelta(23.0, $q['subtotal'], 0.0001);
        $this->assertEqualsWithDelta(23.0, $q['total'], 0.0001);
        $this->assertSame(['شوكولاتة', 'بطاقة تهنئة'], array_column($q['lines'][0]['addons'], 'name'));
        $this->assertStringContainsString('23.000', $q['lines'][0]['line_text']);
    }

    /** الإضافةُ مرّةً للبند لا لكلّ قطعة — قاعدةُ الصندوق: باقتان ٤٠ + شوكولاتة ٢ = ٤٢ */
    public function test_an_addon_is_charged_once_for_its_line_not_for_each_piece(): void
    {
        $q = $this->quote([$this->line([[$this->choc, 1]], qty: 2)])->assertOk()->json();

        $this->assertEqualsWithDelta(42.0, $q['subtotal'], 0.0001);
    }

    /** باقةٌ بشوكولاتة وباقةٌ بلا شيء بندان — لا تُدمج اختياراتُهما */
    public function test_two_bouquets_with_different_addons_stay_two_lines(): void
    {
        $items = [$this->line([[$this->choc, 1]]), $this->line()];

        $q = $this->quote($items)->assertOk()->json();
        $this->assertCount(2, $q['lines']);
        $this->assertEqualsWithDelta(42.0, $q['subtotal'], 0.0001);

        $this->place($items)->assertOk();
        $rows = OrderItem::where('product_id', $this->bouquet->id)->orderBy('id')->get();
        $this->assertSame([2.0, 0.0], $rows->map(fn ($i) => round((float) $i->addons_total, 3))->all());
        $this->assertSame(1, OrderItemAddon::count());
    }

    /** ما لم يُفتح للموقع يُردّ — ولو حمل الطلبُ معرّفَه */
    public function test_the_website_refuses_what_is_not_open_to_it(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'شوكولاتتهم', 'price' => 0.1, 'active' => true, 'web_enabled' => true]);
        $scoped = $this->addon('مخصّصة لغيرها', 1, ['web_enabled' => true, 'scope' => Addon::SCOPE_SELECTED]);

        foreach (['للصندوق وحدَه' => $this->wrap, 'موقوفة' => $this->balloon, 'لمتجرٍ آخر' => $theirs, 'لصنفٍ آخر' => $scoped] as $why => $addon) {
            $this->quote([$this->line([[$addon, 1]])])->assertStatus(422);
            $this->place([$this->line([[$addon, 1]])])->assertStatus(422);
        }

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'لا طلبَ بإضافةٍ مردودة');
    }

    /** الضريبةُ على الباقة وإضافاتها معًا — ٥٪ من ٢٣ = ١٫١٥٠ */
    public function test_vat_is_charged_on_the_addons_too(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);

        $q = $this->quote([$this->line([[$this->choc, 1], [$this->card, 1]])])->assertOk()->json();

        $this->assertEqualsWithDelta(23.0, $q['subtotal'], 0.0001);
        $this->assertEqualsWithDelta(1.15, $q['tax'], 0.0001);
        $this->assertEqualsWithDelta(24.15, $q['total'], 0.0001);
    }

    /** طلبُ الموقع يكتب الإضافاتِ كما يكتبها الصندوق — والرفُّ والتكلفةُ مرّةً */
    public function test_a_website_order_writes_its_addons_like_the_till(): void
    {
        $this->place([$this->line([[$this->choc, 1], [$this->card, 1]])])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(23.0, (float) $order->total, 0.0001);

        $item = $order->items()->firstOrFail();
        $this->assertEqualsWithDelta(20.0, (float) $item->total, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $item->addons_total, 0.0001);

        $rows = OrderItemAddon::where('order_item_id', $item->id)->orderBy('id')->get();
        $this->assertSame(['شوكولاتة', 'بطاقة تهنئة'], $rows->pluck('name')->all());
        $this->assertEqualsWithDelta(2.0, (float) $rows[0]->unit_price, 0.0001);
        $this->assertSame(1, (int) $rows[0]->quantity);
        $this->assertEqualsWithDelta(0.8, (float) $rows[0]->cost, 0.0001);
        $this->assertSame($this->chocStock->id, (int) $rows[0]->inventory_product_id);
        $this->assertNull($rows[1]->cost, 'خدمةٌ بلا رفّ لا تكلفةَ لها');

        // الرفُّ مرّةً: باقةٌ واحدة وقطعةُ شوكولاتة واحدة
        $this->assertSame(9, (int) $this->bouquet->fresh()->quantity);
        $this->assertSame(4, (int) $this->chocStock->fresh()->quantity);

        // والتكلفةُ في القيد مرّةً: ٦ للباقة + ٠٫٨ للشوكولاتة
        $this->assertEqualsWithDelta(6.8, Books::costOf($order->fresh()), 0.0001);

        // واللقطةُ تبقى: سعرٌ تبدّل بعد البيع لا يمسّ ما بيع
        $this->choc->update(['price' => 9]);
        $this->assertEqualsWithDelta(2.0, (float) $rows[0]->fresh()->unit_price, 0.0001);
    }

    /** إضافةٌ نفد رفُّها تُقال قبل صفحة البنك — لا بعد أن يُقبض المال */
    public function test_an_addon_whose_shelf_is_empty_is_refused_before_payment(): void
    {
        $this->chocStock->update(['quantity' => 0]);

        $this->quote([$this->line([[$this->choc, 1]])])->assertStatus(422)->assertJsonValidationErrors('items');
        // والباقةُ وحدَها تمضي
        $this->quote([$this->line()])->assertOk();
    }

    /** سلّةٌ قديمةٌ بلا إضافات تُسعَّر كما كانت */
    public function test_an_old_cart_without_addons_prices_as_before(): void
    {
        $q = $this->quote([['id' => $this->bouquet->id, 'variant_id' => null, 'qty' => 1]])->assertOk()->json();

        $this->assertEqualsWithDelta(20.0, $q['subtotal'], 0.0001);
        $this->assertSame([], $q['lines'][0]['addons']);
    }

    /* ------------------------------ الدفع بالبطاقة ------------------------------ */

    /** إضافةٌ أُطفئت والزبونُ عند البنك تُكتب في طلبه — دُفع ثمنُها */
    public function test_a_paid_addon_is_kept_when_it_is_switched_off_while_the_customer_pays(): void
    {
        $items = [$this->line([[$this->choc, 1], [$this->card, 1]])];
        $intent = $this->paidIntent($items, 23);

        $this->card->update(['web_enabled' => false]);
        $this->choc->update(['active' => false]);

        $order = WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent);

        $this->assertEqualsWithDelta(23.0, (float) $order->total, 0.0001);
        $this->assertSame(2, OrderItemAddon::count());
        $this->assertSame(4, (int) $this->chocStock->fresh()->quantity, 'وتُخصم مرّةً واحدة');
    }

    /** وتبدُّلُ سعرها في أثناء الدفع يردّ المالَ — لا يُكتب طلبٌ بمبلغٍ غيرِ ما دُفع */
    public function test_a_price_change_while_paying_refunds_instead_of_writing_another_amount(): void
    {
        $items = [$this->line([[$this->choc, 1]])];
        $intent = $this->paidIntent($items, 22);

        $this->choc->update(['price' => 3]);

        $this->expectException(PriceMovedAfterPayment::class);
        try {
            WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent);
        } finally {
            $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
            $this->assertSame(5, (int) $this->chocStock->fresh()->quantity);
        }
    }
}
