<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * واجهةُ صاحب المتجر لمستحقّات مكوّنات البوتيك — من المنتج إلى الكشف إلى الإلغاء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - شاشةُ المنتج لا ترسم القسم إلّا لمن فُتح له السجلّ، وتقول «الضريبة»
 *     لمن يحتسبها. والإضافةُ من المنتج نفسِه: مشمولٌ يُكتب في وصفته،
 *     واختياريٌّ يُكتب إضافةً لهذا المنتج بقنواتها — ويبيعهما الصندوقُ
 *     والموقعُ بأرقام 20/7/13 و27/7.
 *   - ما لا يُحسب بدقّة يُردّ برسالة، ومنتجُ متجرٍ آخر لا يُمسّ.
 *   - كشفُ البوتيك يرسم المكوّنات والرصيدين، ويسدّد جزءَ المكوّنات
 *     ويسجّل الاسترداد — ورصيدُ كلّ بوتيكٍ مجموعُه 2160 و1370 في الدفتر.
 *   - الإلغاءُ من شاشة الطلب يحمل حالَ كلّ قطعة، وما بقي يُتمّ بعده.
 *   - مديرُ المنصّة وحده يفتح المفتاح، ولا يفتحه لمن يحتسب الضريبة.
 */
class ASaudLinksABoutiquePieceFromHisOwnProductTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $silver;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = $this->shop('RIBBON', 'ribbon', true);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->silver = $this->product($this->shop, 'قطعة فضّة', 7, $this->lama->id, 10);
        $this->bouquet = $this->product($this->shop, 'باقة الفضّة', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(string $name, string $slug, bool $components): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '9689525'.random_int(1000, 9999),
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'boutiques_enabled' => true, 'boutique_components_enabled' => $components,
        ]);
        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($b->id);
        Branch::create(['business_id' => $b->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $b;
    }

    private function product(Business $b, string $name, float $price, ?int $boutique = null, int $qty = 100): Product
    {
        return Product::create([
            'business_id' => $b->id, 'name' => $name, 'price' => $price, 'cost' => 0,
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    private function link(array $data, ?Product $on = null)
    {
        return $this->actingAs($this->owner)->postJson(route('admin.products.boutique-parts.store', ($on ?? $this->bouquet)->id), $data);
    }

    private function sell(Product $p, array $addons = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => (float) $p->price, 'addons' => $addons]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function balance(string $key): float
    {
        return Ledger::balance($this->shop->id, $key);
    }

    /* ═══════════ شاشةُ المنتج ═══════════ */

    public function test_the_product_screen_shows_the_section_only_where_it_is_open(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->bouquet->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('boutiqueParts.taxed', false)
                ->where('boutiqueParts.options.0.label', 'قطعة فضّة')
                ->where('boutiqueParts.options.0.boutique', 'بوتيك لمى')
                ->where('boutiqueParts.options.0.price', 7)
                ->where('boutiqueParts.parts', [])
                ->etc());

        $this->shop->update(['boutique_components_enabled' => false]);
        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->bouquet->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('boutiqueParts', null)->etc());
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])->assertNotFound();
    }

    public function test_an_included_piece_is_written_into_the_recipe_and_sells_twenty_seven_thirteen(): void
    {
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])
            ->assertOk()->assertJsonPath('parts.0.kind', 'included')->assertJsonPath('parts.0.unit_price', 7);

        $row = RecipeItem::where('product_id', $this->bouquet->id)->firstOrFail();
        $this->assertSame([1.0, 0.0], [(float) $row->quantity, (float) $row->wastage_percent]);

        // تكرارُه يحدّث الكمّيّة ولا يكتب صفًّا ثانيًا
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])->assertOk();
        $this->assertSame(1, RecipeItem::where('product_id', $this->bouquet->id)->count());

        $order = $this->sell($this->bouquet);
        $this->assertSame(20.0, (float) $order->total);
        $this->assertSame(7.0, BoutiqueDues::owedFor($order));
        $this->assertSame(13.0, round(Ledger::netRevenue($this->shop->id) - $this->balance('cogs'), 3));
    }

    public function test_an_optional_piece_becomes_an_addon_for_this_product_at_the_till_and_on_the_site(): void
    {
        $plain = $this->product($this->shop, 'باقة بسيطة', 20);
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'optional', 'quantity' => 1,
            'addon_price' => 7, 'pos_enabled' => true, 'web_enabled' => true], $plain)
            ->assertOk()->assertJsonPath('parts.0.kind', 'optional')->assertJsonPath('parts.0.addon_price', 7);

        $addon = Addon::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame([Addon::SCOPE_SELECTED, $this->silver->id, true, true], [$addon->scope, (int) $addon->inventory_product_id, (bool) $addon->pos_enabled, (bool) $addon->web_enabled]);
        $this->assertTrue(DB::table('product_addons')->where('product_id', $plain->id)->where('addon_id', $addon->id)->exists());

        // الصندوق: ٢٠ + ٧ = ٢٧، وللمى ٧
        $order = $this->sell($plain, [['addon_id' => $addon->id, 'qty' => 1]]);
        $this->assertSame(27.0, (float) $order->total);
        $this->assertSame(7.0, BoutiqueDues::owedFor($order));

        // والموقعُ بالإضافة نفسِها وقناتِها
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $plain->id, 'variant_id' => null, 'qty' => 1, 'addons' => [['id' => $addon->id, 'qty' => 1]]]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ])->assertOk();
        $web = Order::where('business_id', $this->shop->id)->where('channel', 'website')->firstOrFail();
        $this->assertSame([27.0, 7.0], [(float) $web->total, BoutiqueDues::owedFor($web)]);
        $this->assertSame(14.0, $this->balance('boutique_payable'));
    }

    public function test_two_pieces_suggest_fourteen_and_a_customer_price_never_moves_the_boutique_due(): void
    {
        // بلا سعرٍ مكتوب: قطعتان بسبعة = ١٤ للعميل، و١٤ للمى
        $plain = $this->product($this->shop, 'باقة بسيطة', 20);
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'optional', 'quantity' => 2], $plain)
            ->assertOk()->assertJsonPath('parts.0.addon_price', 14)->assertJsonPath('parts.0.quantity', 2);
        $suggested = Addon::where('business_id', $this->shop->id)->firstOrFail();

        $order = $this->sell($plain, [['addon_id' => $suggested->id, 'qty' => 1]]);
        $this->assertSame([34.0, 14.0], [(float) $order->total, BoutiqueDues::owedFor($order)]);

        // وسعرٌ يكتبه سعود (١٨) يغيّر ما يدفعه العميل وحده: للمى ١٤ كما هي، والفرقُ ٤ للمتجر
        $other = $this->product($this->shop, 'باقة ثانية', 20);
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'optional', 'quantity' => 2, 'addon_price' => 18], $other)
            ->assertOk()->assertJsonPath('parts.0.addon_price', 18);
        $custom = Addon::where('business_id', $this->shop->id)->whereKeyNot($suggested->id)->firstOrFail();
        $this->assertSame([18.0, 2], [(float) $custom->price, (int) $custom->inventory_quantity]);

        $order = $this->sell($other, [['addon_id' => $custom->id, 'qty' => 1]]);
        $this->assertSame([38.0, 14.0], [(float) $order->total, BoutiqueDues::owedFor($order)]);

        // الدفتر: إيرادُ ٣٤ + ٣٨، و2160 = ١٤ + ١٤، والقطعُ الأربع خرجت من الرفّ مرّةً واحدة
        $this->assertSame(72.0, round(Ledger::netRevenue($this->shop->id), 3));
        $this->assertSame(28.0, $this->balance('boutique_payable'));
        $this->assertSame(28.0, $this->balance('cogs'));
        $this->assertSame(6, (int) $this->silver->fresh()->quantity);
    }

    public function test_changing_the_quantity_of_an_included_piece_saves_that_quantity_not_the_sum(): void
    {
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])->assertOk();
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 3])
            ->assertOk()->assertJsonCount(1, 'parts')->assertJsonPath('parts.0.quantity', 3);

        $this->assertSame([3.0], RecipeItem::where('product_id', $this->bouquet->id)->pluck('quantity')->map(fn ($q) => (float) $q)->all());

        $this->bouquet->update(['price' => 30]);
        $order = $this->sell($this->bouquet->fresh());
        $this->assertSame([30.0, 21.0], [(float) $order->total, BoutiqueDues::owedFor($order)]);
        $this->assertSame(7, (int) $this->silver->fresh()->quantity);
    }

    public function test_what_cannot_be_counted_exactly_is_refused_and_another_shop_is_untouched(): void
    {
        // كمّيّةٌ كسريّة
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1.5])->assertStatus(422);

        // صنفٌ له مقاسات
        $sized = $this->product($this->shop, 'عقد', 9, $this->lama->id);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $sized->id, 'name' => 'كبير', 'price' => 12, 'active' => true]);
        $this->link(['component_product_id' => $sized->id, 'mode' => 'included', 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('component_product_id');

        // صنفُ المتجر نفسِه ليس صنفَ بوتيك
        $rose = $this->product($this->shop, 'وردة', 1);
        $this->link(['component_product_id' => $rose->id, 'mode' => 'included', 'quantity' => 1])->assertStatus(422);

        // ومتجرٌ آخر: صنفُه لا يُختار، ومنتجُه لا يُفتح
        $other = $this->shop('متجر آخر', 'other', true);
        $theirBoutique = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيكهم', 'commission_rate' => 0, 'active' => true]);
        $theirs = $this->product($other, 'فضّتهم', 5, $theirBoutique->id);
        $this->link(['component_product_id' => $theirs->id, 'mode' => 'included', 'quantity' => 1])->assertStatus(422);
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1], $this->product($other, 'باقتهم', 20))->assertNotFound();

        // ومتجرٌ يحتسب الضريبة
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])->assertStatus(422);
        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->bouquet->id))
            ->assertInertia(fn (Assert $p) => $p->where('boutiqueParts.taxed', true)->etc());

        $this->assertSame(0, RecipeItem::count());
    }

    /* ═══════════ كشفُ البوتيك ═══════════ */

    public function test_the_statement_screen_pays_components_records_a_refund_and_matches_the_ledger(): void
    {
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 1])->assertOk();
        $order = $this->sell($this->bouquet);

        $this->actingAs($this->owner)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => '2027-02']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('statement.components.due', 7)
                ->where('statement.payable', 7)
                ->where('ledger.owed', 7)
                ->where('ledger.credit', 0)
                ->etc());

        $this->actingAs($this->owner)->post(route('admin.boutiques.settle', $this->lama->id), ['period' => '2027-02'])->assertSessionHasNoErrors();
        $s = BoutiqueSettlement::firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.boutiques.components.pay', [$this->lama->id, $s->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance('boutique_payable'));

        // أُعيدت الباقةُ في مارس وعادت الفضّة: رصيدٌ لسعود يُستردّ من الكشف
        Carbon::setTestNow('2027-03-02 10:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُعيدت', boutiqueReturned: true);

        $this->actingAs($this->owner)->get(route('admin.boutiques.show', [$this->lama->id, 'period' => '2027-03']))
            ->assertInertia(fn (Assert $p) => $p->where('ledger.credit', 7)->where('ledger.owed', 0)
                ->where('ledger.movements.0.type', 'credit')->etc());

        $this->actingAs($this->owner)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي', 'amount' => 3])->assertSessionHasNoErrors();
        $this->assertSame(4.0, $this->balance('boutique_receivable'));
        $this->assertSame(4.0, BoutiqueDues::ledgerFor($this->shop->id, $this->lama->id)['credit'], 'الكشفُ والدفترُ رقمٌ واحد');

        $this->actingAs($this->owner)->post(route('admin.boutiques.refund', $this->lama->id), ['method' => 'نقدي', 'amount' => 9])
            ->assertSessionHasErrors('refund');
    }

    /* ═══════════ الإلغاءُ من شاشة الطلب ═══════════ */

    public function test_cancelling_from_the_order_screen_carries_the_state_of_each_piece(): void
    {
        $this->link(['component_product_id' => $this->silver->id, 'mode' => 'included', 'quantity' => 2])->assertOk();

        // طلبُ موقعٍ «جديد» يُلغى من شاشته. وبيعةُ الصندوق «المكتملة» تُحذف من قائمة
        // الطلبات فتصير قطعُها «لم يُتحقَّق منها»، وتُتمّ من هذه الشاشة كما أدناه
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->bouquet->id, 'variant_id' => null, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->where('channel', 'website')->firstOrFail();

        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn (Assert $p) => $p->where('boutiquePieces.0.quantity', 2)->where('boutiquePieces.0.state', null)->etc());
        $piece = BoutiqueDues::piecesOf($order)[0]['id'];

        $this->actingAs($this->owner)->post(route('admin.orders.status', $order->number), [
            'status' => 'ملغي', 'pieces' => [$piece => ['returned' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('ملغي', $order->fresh()->status);
        $open = BoutiqueComponentDue::whereNotNull('live_key')->firstOrFail();
        $this->assertSame([1.0, BoutiqueDues::UNVERIFIED], [(float) $open->quantity, $open->kept_state]);
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);

        // ثمّ عادت الثانية — من الشاشة نفسِها
        $this->actingAs($this->owner)->post(route('admin.orders.boutique-pieces', $order->number), [
            'action' => 'returned', 'pieces' => [$open->id => 1],
        ])->assertSessionHasNoErrors();
        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
        $this->assertSame(0.0, $this->balance('boutique_payable'));

        // ومن لا يُلغي لا يُتمّ
        $clerk = User::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'clerk@abaad.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);
        $this->actingAs($clerk)->post(route('admin.orders.boutique-pieces', $order->number), ['action' => 'lost', 'pieces' => [$open->id => 1]])->assertForbidden();
    }

    /* ═══════════ مفتاحُ المنصّة ═══════════ */

    public function test_only_the_platform_opens_it_and_never_for_a_taxed_shop(): void
    {
        $fresh = $this->shop('متجر جديد', 'fresh', false);
        User::create(['business_id' => $fresh->id, 'name' => 'مالكه', 'email' => 'fresh@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $boss = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        $put = fn (array $over) => $this->actingAs($boss)->put(route('super-admin.businesses.update', $fresh->id),
            ['name' => $fresh->name, 'type' => $fresh->type, 'status' => 'نشط'] + $over);

        Setting::where('business_id', $fresh->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        $put(['boutique_components_enabled' => '1'])->assertSessionHasErrors('boutique_components_enabled');
        $this->assertFalse(BoutiqueDues::active($fresh->id));

        Setting::where('business_id', $fresh->id)->where('key', 'vat_enabled')->update(['value' => '0']);
        $put(['boutique_components_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(BoutiqueDues::active($fresh->id));

        // وحفظٌ بلا المفتاح لا يُطفئه، و«0» يُطفئه
        $put([])->assertSessionHasNoErrors();
        $this->assertTrue(BoutiqueDues::active($fresh->id));
        $put(['boutique_components_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(BoutiqueDues::active($fresh->id));

        // والتاجرُ لا يفتحه لنفسه
        $this->actingAs($this->owner)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط', 'boutique_components_enabled' => '1',
        ])->assertStatus(403);
    }
}
