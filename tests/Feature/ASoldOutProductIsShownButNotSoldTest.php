<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\WebCheckout;
use App\Support\WhatsAppOrderCatalog;
use App\Support\WhatsAppOrderCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «نفدت الكمية» بيد التاجر — يُرى الصنفُ في الموقع بشارته ولا يُطلب.
 *
 * ولا يمسّ المخزونَ ولا الصندوقَ ولا الطلباتِ السابقة ولا بطاقاتِ الفئات، ولا
 * يردّ دفعةً قُبضت، ولا يمنع طلبَ واتساب. والخانةُ لمن في
 * `storefront.website_sold_out_businesses` وحده — لا لمن لبس واجهةً خاصّة.
 */
class ASoldOutProductIsShownButNotSoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    private Branch $branch;

    private Category $bags;

    private Category $hiddenCard;

    private Product $bouquet;

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
        config(['storefront.website_sold_out_businesses' => [$this->shop->id]]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'بوشر']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'soldout-owner@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'الكاشير', 'email' => 'soldout-cashier@abaad.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_fulfil' => 'pickup']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->bags = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات الكيس', 'name_en' => 'Flower bags']);
        // وفئةٌ أخفى صاحبُها بطاقتَها — لا تمسّها «نفدت الكمية» ولا تُظهرها
        $this->hiddenCard = Category::create(['business_id' => $this->shop->id, 'name' => 'الاضافات', 'card_hidden' => true]);

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'category_id' => $this->bags->id, 'name' => 'باقة الصيف', 'name_en' => 'Summer bouquet',
            'price' => 20, 'cost' => 6, 'quantity' => 10, 'tracks_stock' => true, 'active' => true, 'published' => true,
        ]);
        Product::create([
            'business_id' => $this->shop->id, 'category_id' => $this->hiddenCard->id, 'name' => 'شوكولاتة',
            'price' => 2, 'cost' => 1, 'quantity' => 10, 'tracks_stock' => true, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function mark(Product $p, string $status, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->patchJson('/admin/products/'.$p->id.'/website-status', ['status' => $status]);
    }

    private function line(?Product $p = null, ?int $variant = null): array
    {
        return ['id' => ($p ?? $this->bouquet)->id, 'variant_id' => $variant, 'qty' => 1];
    }

    private function form(array $items, string $pay = 'cod'): array
    {
        return [
            'items' => $items, 'fulfil' => 'pickup', 'pay' => $pay,
            'name' => 'مريم', 'phone' => '96899110001', 'date' => '2027-05-05',
        ];
    }

    private function quote(array $items)
    {
        return $this->postJson('/s/ribbon/quote', ['items' => $items, 'fulfil' => 'pickup']);
    }

    private function place(array $items, string $pay = 'cod')
    {
        return $this->postJson('/s/ribbon/checkout', $this->form($items, $pay));
    }

    /** معرّفاتُ بطاقات «تسوّق حسب الفئة» في الرئيسيّة */
    private function cards(string $html): array
    {
        preg_match('/data-testid="rb-sec-cats"(.*?)(data-testid="rb-sec-|<footer)/s', $html, $m);
        preg_match_all('/\/shop\?cat=(\d+)/', $m[1] ?? '', $ids);

        return array_map('intval', $ids[1]);
    }

    /* ------------------------------ ١) الانتقال بين الحالات ------------------------------ */

    public function test_the_owner_moves_a_product_through_the_three_states_and_back(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk()
            ->assertJson(['ok' => true, 'status' => 'sold_out', 'published' => true, 'website_sold_out' => true]);
        $this->assertTrue((bool) $this->bouquet->fresh()->website_sold_out);

        // والإخفاءُ لا يمحو علَمَ النفاد — من أظهره من القائمة يجده كما تركه
        $this->mark($this->bouquet, 'hidden')->assertOk()->assertJson(['status' => 'hidden']);
        $this->assertFalse((bool) $this->bouquet->fresh()->published);
        $this->assertTrue((bool) $this->bouquet->fresh()->website_sold_out);

        $this->mark($this->bouquet, 'available')->assertOk()
            ->assertJson(['status' => 'available', 'published' => true, 'website_sold_out' => false]);

        $this->assertSame(3, ActivityLog::where('business_id', $this->shop->id)->where('subject_id', $this->bouquet->id)
            ->where('description', 'like', '%الموقع%')->count(), 'كلُّ تبديلٍ في سجلّ النشاط');
        $this->mark($this->bouquet, 'gone')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_edit_screen_opens_on_the_saved_state(): void
    {
        $this->bouquet->update(['website_sold_out' => true]);

        $this->actingAs($this->owner)->get('/admin/products/'.$this->bouquet->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', 'sold_out'));
        $this->actingAs($this->owner)->get('/admin/products/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', 'available'));
        $this->actingAs($this->owner)->get('/admin/products')->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.0.website_sold_out', fn ($v) => is_bool($v)));
    }

    /* ------------------------------ ٢) يُرى بشارته ولا يُضاف ------------------------------ */

    public function test_a_sold_out_product_stays_in_every_list_with_its_badge(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $home = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-soldout-tag"', $home, 'في «وصل حديثًا» بشارته');
        $this->assertStringContainsString('/p/'.$this->bouquet->id, $home);

        $this->get('/s/ribbon/shop')->assertOk()->assertSee('باقة الصيف')->assertSee('data-testid="rb-soldout-tag"', false);
        $this->get('/s/ribbon/shop?cat='.$this->bags->id)->assertOk()->assertSee('باقة الصيف');
        $this->get('/s/ribbon/shop?q='.urlencode('الصيف'))->assertOk()->assertSee('باقة الصيف');
        $this->get('/s/ribbon/shop?lang=en')->assertOk()->assertSee('Sold out');
    }

    public function test_its_page_keeps_photo_price_and_details_but_no_add_button(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()
            ->assertSee('باقة الصيف')->assertSee('20.000')
            ->assertSee('data-testid="rb-soldout-manual"', false)->assertSee('نفدت الكمية')->assertSee('غير متوفر للطلب حاليًا')
            ->assertDontSee('data-testid="rb-add"', false);
        $this->get('/s/ribbon/p/'.$this->bouquet->id.'?lang=en')->assertOk()->assertSee('Sold out')->assertDontSee('data-testid="rb-add"', false);

        // ويعود زرُّه بإعادته «متوفر»
        $this->mark($this->bouquet, 'available')->assertOk();
        $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->assertSee('data-testid="rb-add"', false)
            ->assertDontSee('data-testid="rb-soldout"', false);
    }

    /* ------------------------------ ٣) سلّةٌ قديمة وطلبٌ مباشر ------------------------------ */

    public function test_an_old_cart_or_a_direct_request_cannot_order_it(): void
    {
        $this->quote([$this->line()])->assertOk();
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->quote([$this->line()])->assertStatus(422)->assertJsonValidationErrors('items')
            ->assertJsonPath('sold_out', [$this->bouquet->id]);
        $this->assertStringContainsString('نفدت كميته', $this->quote([$this->line()])->json('errors.items.0'));
        $this->place([$this->line()])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
        // وما سواه في السلّة لا يُسمّى
        $this->assertSame([], $this->postJson('/s/ribbon/quote', ['items' => [['id' => 999999, 'qty' => 1]]])->json('sold_out'));
    }

    public function test_no_new_card_payment_is_opened_for_it(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_pay_card' => '1']);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876', 'active' => true,
        ]);
        Http::fake();
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $res = $this->place([$this->line()], 'card')->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertStringContainsString('نفدت كميته', (string) $res->json('errors.items.0'));
        $this->assertSame(0, StorePaymentIntent::where('business_id', $this->shop->id)->count(), 'لا نيّةَ دفع');
        Http::assertNothingSent();
    }

    /* ------------------------------ ٤) المخزونُ يحكم كما كان ------------------------------ */

    public function test_available_does_not_open_an_empty_shelf(): void
    {
        $this->bouquet->update(['quantity' => 0]);
        $this->mark($this->bouquet, 'available')->assertOk();

        $this->quote([$this->line()])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertStringContainsString('نفد من المتجر', $this->quote([$this->line()])->json('errors.items.0'));
        $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()
            ->assertSee('data-testid="rb-soldout"', false)->assertDontSee('data-testid="rb-soldout-manual"', false);
    }

    /* ------------------------------ ٥) الصندوق ------------------------------ */

    public function test_the_till_still_sells_it(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [$this->line() + ['name' => 'باقة الصيف']],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('soldout', true),
        ])->assertOk();

        $this->assertSame(9, (int) $this->bouquet->fresh()->quantity, 'وخُصم من رفّه كأيّ بيعة');
    }

    /* ------------------------------ ٦) لا مخزونَ ولا حركةَ ولا طلبَ قديم ------------------------------ */

    public function test_switching_states_moves_no_stock_and_rewrites_no_past_order(): void
    {
        $order = WebCheckout::place($this->shop, $this->form([$this->line()]), 'ar');
        $qty = (int) $this->bouquet->fresh()->quantity;
        $moves = InventoryMovement::where('business_id', $this->shop->id)->count();
        $stock = BranchStock::where('product_id', $this->bouquet->id)->orderBy('id')->get(['branch_id', 'quantity'])->toArray();
        $before = $order->fresh()->only(['status', 'total', 'subtotal']);

        foreach (['sold_out', 'hidden', 'available', 'sold_out'] as $s) {
            $this->mark($this->bouquet, $s)->assertOk();
        }

        $this->assertSame($qty, (int) $this->bouquet->fresh()->quantity);
        $this->assertSame((bool) $this->bouquet->tracks_stock, (bool) $this->bouquet->fresh()->tracks_stock);
        $this->assertSame($moves, InventoryMovement::where('business_id', $this->shop->id)->count(), 'لا حركةَ مخزون');
        $this->assertSame($stock, BranchStock::where('product_id', $this->bouquet->id)->orderBy('id')->get(['branch_id', 'quantity'])->toArray());
        $this->assertSame($before, $order->fresh()->only(['status', 'total', 'subtotal']));
        $this->assertSame(1, $order->items()->count());
    }

    /* ------------------------------ ٧) الفئاتُ وبطاقاتُها ------------------------------ */

    public function test_categories_and_their_hidden_cards_are_left_as_they_are(): void
    {
        $before = $this->cards($this->get('/s/ribbon')->getContent());
        $this->assertSame([$this->bags->id], $before, 'المخفيّةُ بطاقتُها لا تُرسم');

        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->assertSame($before, $this->cards($this->get('/s/ribbon')->getContent()), 'فئةُ الصنف باقيةٌ ببطاقتها');
        $this->assertTrue((bool) $this->hiddenCard->fresh()->card_hidden);
        $this->assertFalse((bool) $this->bags->fresh()->card_hidden);
    }

    /* ------------------------------ ٨) متجرٌ آخر ------------------------------ */

    public function test_another_shop_neither_reaches_nor_is_reached(): void
    {
        $other = Business::create(['name' => 'متجرٌ بسيط', 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => 'simple-shop', 'tier' => 'gold']);
        Currency::create(['business_id' => $other->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $theirOwner = User::create([
            'business_id' => $other->id, 'name' => 'مالكٌ آخر', 'email' => 'soldout-other@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'ورد', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'active' => true, 'published' => true,
        ]);

        // لا يبلغ صنفَ RIBBON، ولا تُفتح الخانةُ لمن لا يرسمها موقعُه
        $this->mark($this->bouquet, 'sold_out', $theirOwner)->assertNotFound();
        $this->mark($theirs, 'sold_out', $theirOwner)->assertNotFound();
        $this->mark($theirs, 'sold_out')->assertNotFound();
        $this->actingAs($theirOwner)->put('/admin/products/'.$theirs->id, ['name' => 'ورد', 'price' => 5, 'website_sold_out' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) $theirs->fresh()->website_sold_out, 'ولا يُكتب العلَمُ من النموذج');
        $this->actingAs($theirOwner)->get('/admin/products/'.$theirs->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', null));

        $this->mark($this->bouquet, 'sold_out')->assertOk();
        $this->assertFalse((bool) $theirs->fresh()->website_sold_out);
    }

    public function test_a_new_product_can_be_born_sold_out_only_in_a_listed_shop(): void
    {
        $this->actingAs($this->owner)->post('/admin/products', ['name' => 'باقة العيد', 'price' => 30, 'website_sold_out' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) Product::where('business_id', $this->shop->id)->where('name', 'باقة العيد')->sole()->website_sold_out);

        $this->actingAs($this->owner)->post('/admin/products', ['name' => 'باقة عادية', 'price' => 30])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Product::where('business_id', $this->shop->id)->where('name', 'باقة عادية')->sole()->website_sold_out);

        // وواجهةُ RIBBON باقيةٌ عليه — والقائمةُ وحدها تفتحها
        config(['storefront.website_sold_out_businesses' => []]);
        $this->assertSame('ribbon', $this->shop->fresh()->storefrontTheme());
        $this->actingAs($this->owner)->post('/admin/products', ['name' => 'باقة خارج القائمة', 'price' => 30, 'website_sold_out' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) Product::where('business_id', $this->shop->id)->where('name', 'باقة خارج القائمة')->sole()->website_sold_out);
    }

    public function test_the_full_form_keeps_the_state_unless_it_sends_it(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->actingAs($this->owner)->put('/admin/products/'.$this->bouquet->id, ['name' => 'باقة الصيف', 'price' => 21])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->bouquet->fresh()->website_sold_out, 'حفظةٌ لم تُرسله لا تُعيده متوفّرًا');

        $this->actingAs($this->owner)->put('/admin/products/'.$this->bouquet->id, ['name' => 'باقة الصيف', 'price' => 21, 'website_sold_out' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->bouquet->fresh()->website_sold_out);
    }

    /* ------------------------------ ٩) الدفعُ المقبوض ------------------------------ */

    public function test_a_payment_already_captured_still_settles_after_it_is_marked_sold_out(): void
    {
        $intent = StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'WEB-'.uniqid(), 'status' => StorePaymentIntent::PAID,
            'provider_transaction_id' => 'tx-'.uniqid(), 'amount' => 20, 'currency' => 'OMR',
            'payload' => $this->form([$this->line()], 'card'), 'lang' => 'ar',
        ]);

        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $order = WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent);

        $this->assertEqualsWithDelta(20.0, (float) $order->total, 0.0001, 'المالُ قُبض فيُكتب طلبُه');
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
    }

    public function test_an_intent_whose_money_was_not_captured_is_refused_like_a_new_order(): void
    {
        // والبطاقةُ مفتوحة — فلا يُردّ إلّا بنفاد الصنف
        MarketingSettings::save($this->shop->id, 'website', ['store_pay_card' => '1']);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB,
            'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x',
            'hmac_secret' => 'hmac_secret_value', 'card_integration_id' => '4569876', 'active' => true,
        ]);
        $intent = StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'reference' => 'WEB-'.uniqid(), 'status' => StorePaymentIntent::PENDING,
            'amount' => 20, 'currency' => 'OMR', 'payload' => $this->form([$this->line()], 'card'), 'lang' => 'ar',
        ]);
        $this->mark($this->bouquet, 'sold_out')->assertOk();

        try {
            WebCheckout::place($this->shop, (array) $intent->payload, 'ar', paid: true, intent: $intent);
            $this->fail('نيّةٌ لم يُقبض مالُها كتبت طلبًا');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('نفدت كميته', (string) ($e->errors()['items'][0] ?? ''));
        }
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    /* ------------------------------ ١٠) المقاسات ------------------------------ */

    public function test_every_size_of_a_sold_out_product_is_refused(): void
    {
        $small = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'صغير', 'price' => 15, 'active' => true]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'كبير', 'price' => 30, 'active' => true]);
        $this->quote([$this->line(variant: $small->id)])->assertOk();

        $this->mark($this->bouquet, 'sold_out')->assertOk();

        $this->quote([$this->line(variant: $small->id)])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->assertSee('data-testid="rb-soldout-manual"', false)
            ->assertDontSee('data-testid="rb-add"', false);
    }

    /* ------------------------------ ١١) واتساب يبيعه كما كان ------------------------------ */

    public function test_whatsapp_still_lists_and_orders_it_by_its_old_rules(): void
    {
        Http::fake();
        $this->mark($this->bouquet, 'sold_out')->assertOk();
        $cart = [['product_id' => $this->bouquet->id, 'variant_id' => null, 'qty' => 2]];

        // يُعرض في قائمة المحادثة ويُختار بمعرّفه
        $this->assertContains($this->bouquet->id, array_column(WhatsAppOrderCatalog::products($this->shop->id, $this->bags->id, 'ar'), 'id'));
        $this->assertSame(1, collect(WhatsAppOrderCatalog::categories($this->shop->id, 'ar'))->firstWhere('id', $this->bags->id)['count'] ?? 0);
        $this->assertNotNull(WhatsAppOrderCatalog::product($this->shop->id, $this->bouquet->id));

        // ويُسعَّر بقاعدة الموقع بلا علَمه — والموقعُ في اللحظة نفسها يردّه
        $quote = WhatsAppOrderCheckout::quote($this->shop, $cart, ['fulfil' => 'pickup']);
        $this->assertSame([$this->bouquet->id], array_map(fn ($l) => (int) $l['product']->id, $quote['_lines']));
        $this->assertEqualsWithDelta(40.0, (float) $quote['subtotal'], 0.0001);
        $this->quote([$this->line()])->assertStatus(422)->assertJsonPath('sold_out', [$this->bouquet->id]);

        // وقواعدُه السابقة باقيةٌ عليه: الرفُّ الفارغ والمخفيُّ يُردّان
        $this->bouquet->update(['quantity' => 0]);
        $this->assertRefused(fn () => WhatsAppOrderCheckout::quote($this->shop, $cart, ['fulfil' => 'pickup']), 'نفد من المتجر');
        $this->bouquet->update(['quantity' => 10]);
        $this->mark($this->bouquet, 'hidden')->assertOk();
        $this->assertRefused(fn () => WhatsAppOrderCheckout::quote($this->shop, $cart, ['fulfil' => 'pickup']), 'لم يعد متاحًا');
        $this->assertNull(WhatsAppOrderCatalog::product($this->shop->id, $this->bouquet->id));

        // ولا رسالةَ تُرسل، ولا مخزونَ يتحرّك
        Http::assertNothingSent();
        $this->assertSame(10, (int) $this->bouquet->fresh()->quantity);
    }

    public function test_the_site_cannot_borrow_the_whatsapp_door(): void
    {
        $this->mark($this->bouquet, 'sold_out')->assertOk();
        $smuggled = ['fromWhatsApp' => true, 'channel' => 'whatsapp', 'source' => 'whatsapp', '_fromWhatsApp' => true, 'whatsapp' => 1];

        $this->postJson('/s/ribbon/quote', ['items' => [$this->line()], 'fulfil' => 'pickup'] + $smuggled)
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/s/ribbon/checkout', $this->form([$this->line()]) + $smuggled)
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertRefused(fn () => WebCheckout::quote($this->shop, ['items' => [$this->line()], 'fulfil' => 'pickup'] + $smuggled), 'نفدت كميته');

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    private function assertRefused(callable $run, string $says): void
    {
        try {
            $run();
            $this->fail('كان يجب أن يُردّ');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($says, (string) collect($e->errors())->flatten()->first());
        }
    }

    /* ------------------------------ ١٢) القائمةُ تفتحها لا الواجهة ------------------------------ */

    public function test_a_private_theme_alone_does_not_open_the_three_states(): void
    {
        $twin = Business::create([
            'name' => 'متجرٌ بواجهة RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon-twin', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $twin->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $twinOwner = User::create([
            'business_id' => $twin->id, 'name' => 'مالك التوأم', 'email' => 'soldout-twin@abaad.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $theirs = Product::create([
            'business_id' => $twin->id, 'name' => 'ورد', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'active' => true, 'published' => true,
        ]);
        $this->assertSame('ribbon', $twin->storefrontTheme());

        $this->mark($theirs, 'sold_out', $twinOwner)->assertNotFound();
        $this->actingAs($twinOwner)->get('/admin/products/'.$theirs->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', null));
        $this->actingAs($twinOwner)->get('/admin/products/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', null));
        $this->actingAs($twinOwner)->put('/admin/products/'.$theirs->id, ['name' => 'ورد', 'price' => 5, 'website_sold_out' => '1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($twinOwner)->post('/admin/products', ['name' => 'ورد جديد', 'price' => 5, 'website_sold_out' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Product::where('business_id', $twin->id)->where('website_sold_out', true)->count());

        // ويُفتح له بكتابته في القائمة — لا بشيءٍ سواها
        config(['storefront.website_sold_out_businesses' => [$this->shop->id, $twin->id]]);
        $this->mark($theirs, 'sold_out', $twinOwner)->assertOk();
        $this->assertTrue((bool) $theirs->fresh()->website_sold_out);
    }

    public function test_leaving_the_list_brings_the_site_back_as_it_was(): void
    {
        $tagged = fn (bool $on) => fn ($page) => $page->where('products', fn ($rows) => collect($rows)->firstWhere('id', $this->bouquet->id)['website_sold_out'] === $on);
        $this->mark($this->bouquet, 'sold_out')->assertOk();
        $this->actingAs($this->owner)->get('/admin/products')->assertOk()->assertInertia($tagged(true));
        config(['storefront.website_sold_out_businesses' => []]);

        // العلَمُ باقٍ في عموده لا يُمحى — وأثرُه كلُّه يُرفع
        $this->assertTrue((bool) $this->bouquet->fresh()->website_sold_out);
        $this->get('/s/ribbon/shop')->assertOk()->assertSee('باقة الصيف')->assertDontSee('data-testid="rb-soldout-tag"', false);
        $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->assertSee('data-testid="rb-add"', false)
            ->assertDontSee('data-testid="rb-soldout-manual"', false);
        $this->quote([$this->line()])->assertOk();
        $this->actingAs($this->owner)->get('/admin/products')->assertOk()->assertInertia($tagged(false));
        $this->actingAs($this->owner)->get('/admin/products/'.$this->bouquet->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('websiteStatus', null));
        $this->mark($this->bouquet, 'available')->assertNotFound();

        // وبعودته إليها يعود كما تركه صاحبُه
        config(['storefront.website_sold_out_businesses' => [$this->shop->id]]);
        $this->get('/s/ribbon/shop')->assertOk()->assertSee('data-testid="rb-soldout-tag"', false);
        $this->quote([$this->line()])->assertStatus(422);
    }
}
