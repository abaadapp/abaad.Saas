<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ما يُستحقّ للبوتيك عن صنفه حين يدخل باقةً أو يُضاف إليها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - باقةٌ بعشرين فيها فضّةُ بوتيكٍ بسبعة: للبوتيك سبعة، ويبقى للمتجر
 *     ثلاثة عشر قبل تكاليفه. وإضافةٌ اختياريّة: ٢٠ + ٧ = ٢٧، وللبوتيك سبعة.
 *   - بوتيكان في باقةٍ واحدة، والكمّيّات (الباقةُ تضرب، والإضافةُ لا).
 *   - المتجرُ يتحمّل خصمَه، وخصمٌ يأكل حصّة البوتيك يُردّ قبل البيع.
 *   - السعرُ يُلتقط ساعةَ البيع ولا يُعاد حسابه، ولا بتصحيح الكمّيّة.
 *   - قيدُ التكلفة يُقسم: المخزونُ لما يملكه المتجر، و«مستحقّات البوتيكات»
 *     لما للبوتيك — ولا تكلفةَ مرّتين.
 *   - الإلغاءُ قبل التسوية يعكس المستحقَّ صفًّا وقيدًا، ولا يُعكس مرّتين.
 *   - تقريرُ الربح ودفترُ الأستاذ ودفترُ المستحقّات تقول رقمًا واحدًا.
 *   - المفتاحُ المطفأ يُبقي كلَّ شيءٍ كما كان، والمتجرُ الخاضع للضريبة
 *     يُردّ، وما لا يُحسب بدقّة (مقاسات، فاقد، كسور) يُردّ برسالة.
 *   - الصنفُ المنفرد وعمولتُه لا يمسّهما شيء.
 *
 * الأرقامُ بلا ضريبة ولا توصيل.
 */
class ABoutiqueIsOwedForWhatWentIntoABouquetTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $perfume;

    private Product $rose;

    private Product $bouquet;

    private Product $duo;

    private Product $plain;

    private Addon $silverAddon;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');

        // قوائمُ المتاجر في الإعدادات بمعرّفاتها — لا يصير متجرُ الاختبار «سعود» بتسلسل PostgreSQL
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'boutiques_enabled' => true, 'boutique_components_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'pay_credit', 'value' => '1']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);

        // تكلفةُ البطاقة مكتوبةٌ عمدًا — والمستحقُّ ثمنُ الصنف لا تكلفتُه
        $this->silver = $this->product('قطعة فضّة', 7, cost: 3, boutique: $this->lama->id, qty: 10);
        $this->perfume = $this->product('عطر صغير', 12, cost: 5, boutique: $this->reem->id, qty: 10);
        $this->rose = $this->product('وردة', 1, cost: 0.5, qty: 100);

        $this->bouquet = $this->product('باقة الفضّة', 20, cost: 0);
        $this->recipe($this->bouquet, $this->silver, 1);

        $this->duo = $this->product('باقة البوتيكين', 30, cost: 0);
        $this->recipe($this->duo, $this->silver, 1);
        $this->recipe($this->duo, $this->perfume, 1);

        $this->plain = $this->product('باقة بسيطة', 20, cost: 0);

        $this->silverAddon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'فضّة اختياريّة', 'price' => 7,
            'active' => true, 'scope' => Addon::SCOPE_ALL,
            'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, float $cost, ?int $boutique = null, int $qty = 100): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => $cost,
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true,
            'boutique_id' => $boutique,
        ]);
    }

    private function recipe(Product $of, Product $component, float $qty, float $wastage = 0): RecipeItem
    {
        return RecipeItem::create([
            'business_id' => $this->shop->id, 'product_id' => $of->id,
            'component_product_id' => $component->id, 'quantity' => $qty, 'wastage_percent' => $wastage,
        ]);
    }

    private function line(Product $p, int $qty = 1, int $addonQty = 0): array
    {
        $line = ['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) $p->price];
        if ($addonQty > 0) {
            $line['addons'] = [['addon_id' => $this->silverAddon->id, 'qty' => $addonQty]];
        }

        return $line;
    }

    private function sell(array $lines, array $extra = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => $lines, 'payment_method' => 'نقدي', ...$extra,
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function balance(string $key): float
    {
        return Ledger::balance($this->shop->id, $key);
    }

    private function dues(?Order $order = null)
    {
        return BoutiqueComponentDue::where('business_id', $this->shop->id)
            ->when($order, fn ($q) => $q->where('order_id', $order->id))
            ->orderBy('id')->get();
    }

    private function owedTo(Boutique $b): float
    {
        return round((float) BoutiqueComponentDue::where('boutique_id', $b->id)->sum('amount'), 3);
    }

    /* ═══════════ الأرقام ═══════════ */

    public function test_a_bouquet_of_twenty_owes_seven_and_leaves_thirteen(): void
    {
        $order = $this->sell([$this->line($this->bouquet)]);

        $this->assertSame(20.0, (float) $order->total);

        $rows = $this->dues($order);
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($this->lama->id, (int) $row->boutique_id);
        $this->assertSame('بوتيك لمى', $row->boutique_name);
        $this->assertSame(BoutiqueComponentDue::RECIPE, $row->source);
        $this->assertSame($this->silver->id, (int) $row->product_id);
        $this->assertSame(7.0, (float) $row->unit_price, 'المستحقُّ ثمنُ الصنف لا تكلفةُ بطاقته (٣)');
        $this->assertSame(1.0, (float) $row->quantity);
        $this->assertSame(7.0, (float) $row->amount);

        // ولقطةُ تكلفة البند هي المستحقّ — لا ثلاثةُ البطاقة ولا الاثنان معًا
        $this->assertSame(7.0, (float) OrderItem::where('order_id', $order->id)->value('cost'));

        $this->assertSame(20.0, $this->balance('sales'));
        $this->assertSame(7.0, $this->balance('cogs'));
        $this->assertSame(7.0, $this->balance('boutique_payable'));
        $this->assertSame(0.0, $this->balance('inventory'), 'أمانةُ البوتيك لا تخرج من مخزون المتجر');
        $this->assertSame(13.0, round($this->balance('sales') - $this->balance('cogs'), 3));

        $this->assertSame(9, (int) $this->silver->fresh()->quantity, 'والرفُّ يُخصم مرّةً واحدة');
    }

    public function test_an_optional_silver_makes_twenty_seven_and_owes_seven(): void
    {
        $order = $this->sell([$this->line($this->plain, 1, addonQty: 1)]);

        $this->assertSame(27.0, (float) $order->total);

        $rows = $this->dues($order);
        $this->assertCount(1, $rows);
        $this->assertSame(BoutiqueComponentDue::ADDON, $rows->first()->source);
        $this->assertNotNull($rows->first()->order_item_addon_id);
        $this->assertSame(7.0, (float) $rows->first()->amount);

        $addon = OrderItem::where('order_id', $order->id)->firstOrFail()->addons()->firstOrFail();
        $this->assertSame(7.0, (float) $addon->cost);

        $this->assertSame(7.0, $this->balance('boutique_payable'));
        $this->assertSame(20.0, round($this->balance('sales') - $this->balance('cogs'), 3));
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
    }

    public function test_two_boutiques_in_one_bouquet_are_owed_each_their_own(): void
    {
        $order = $this->sell([$this->line($this->duo)]);

        $this->assertSame(30.0, (float) $order->total);
        $this->assertSame(7.0, $this->owedTo($this->lama));
        $this->assertSame(12.0, $this->owedTo($this->reem));
        $this->assertSame(19.0, $this->balance('boutique_payable'));
        $this->assertSame(11.0, round($this->balance('sales') - $this->balance('cogs'), 3));
    }

    public function test_the_bouquet_quantity_multiplies_its_recipe_but_not_its_addon(): void
    {
        // باقتان، وعلى البند فضّةٌ اختياريّةٌ واحدة: ٢ × ٢٠ + ٧
        $order = $this->sell([$this->line($this->bouquet, 2, addonQty: 1)]);

        $this->assertSame(47.0, (float) $order->total);

        $rows = $this->dues($order);
        $this->assertCount(2, $rows, 'استعمالان: الوصفةُ والإضافة — لكلٍّ صفّ');
        $this->assertSame([2.0, 1.0], $rows->map(fn ($r) => (float) $r->quantity)->all());
        $this->assertSame([14.0, 7.0], $rows->map(fn ($r) => (float) $r->amount)->all());

        $this->assertSame(21.0, $this->balance('boutique_payable'));
        $this->assertSame(21.0, $this->balance('cogs'));
        $this->assertSame(7, (int) $this->silver->fresh()->quantity, 'ثلاثُ قطعٍ من الرفّ — لا أكثر');
    }

    public function test_the_shop_bears_its_own_discount(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'MINUS5', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);

        $order = $this->sell([$this->line($this->bouquet)], ['coupon_code' => 'MINUS5']);

        $this->assertSame(15.0, (float) $order->total);
        $this->assertSame(7.0, (float) $this->dues($order)->sum('amount'), 'الخصمُ لا يُنقص حصّة البوتيك');
        $this->assertSame(15.0, $this->balance('sales'));
        $this->assertSame(8.0, round($this->balance('sales') - $this->balance('cogs'), 3));
    }

    public function test_a_discount_that_eats_the_boutique_share_is_refused_before_the_sale(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'MINUS15', 'type' => 'مبلغ', 'value' => 15, 'active' => true]);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [$this->line($this->bouquet)], 'payment_method' => 'نقدي', 'coupon_code' => 'MINUS15',
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
        $this->assertCount(0, $this->dues());
        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
        $this->assertSame(0, JournalEntry::where('business_id', $this->shop->id)->count());
    }

    public function test_the_price_of_the_sale_is_kept_when_the_card_changes(): void
    {
        $first = $this->sell([$this->line($this->bouquet)]);
        $this->silver->update(['price' => 9]);
        $second = $this->sell([$this->line($this->bouquet)]);

        $this->assertSame(7.0, BoutiqueDues::owedFor($first), 'سعرُ الأمس لا يُعاد بسعر اليوم');
        $this->assertSame(9.0, BoutiqueDues::owedFor($second));

        // وتصحيحُ الكمّيّة يعكس ويكتب بسعر البيعة نفسِها
        $this->actingAs($this->owner);
        $item = OrderItem::where('order_id', $first->id)->firstOrFail();
        OrderCorrection::setQuantity($first->fresh(), $item, 2, 'الزبون أخذ باقةً ثانية');

        $rows = $this->dues($first);
        $this->assertCount(3, $rows, 'الأصلُ وعكسُه وصفُّ الكمّيّة الجديدة');
        $this->assertNull($rows[0]->live_key);
        $this->assertSame($rows[0]->id, (int) $rows[1]->reverses_id);
        $this->assertSame(-7.0, (float) $rows[1]->amount);
        $this->assertSame(7.0, (float) $rows[2]->unit_price);
        $this->assertSame(14.0, (float) $rows[2]->amount);

        $this->assertSame(14.0, BoutiqueDues::owedFor($first));
        $this->assertSame(23.0, $this->balance('boutique_payable'));
        $this->assertSame(23.0, $this->balance('cogs'));
    }

    public function test_cancelling_before_settlement_reverses_the_due_and_its_entry(): void
    {
        $order = $this->sell([$this->line($this->bouquet, 1, addonQty: 1)]);
        $this->assertSame(14.0, $this->balance('boutique_payable'));

        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'الزبون ألغى');

        $rows = $this->dues($order);
        $this->assertCount(4, $rows, 'صفّان وعكسُهما — لا محو');
        $this->assertSame(0, $rows->whereNotNull('live_key')->count());
        $this->assertSame(0.0, BoutiqueDues::owedFor($order));
        $this->assertSame(0.0, $this->balance('boutique_payable'));
        $this->assertSame(0.0, $this->balance('cogs'));
        $this->assertSame(10, (int) $this->silver->fresh()->quantity, 'القطعتان عادتا إلى الرفّ');

        // ولا يُعكس ما عُكس
        BoutiqueDues::cancel($order->fresh(), 'مرّة ثانية');
        OrderCorrection::cancel($order->fresh(), 'مرّة ثانية');
        $this->assertCount(4, $this->dues($order));
    }

    public function test_a_due_is_written_once_however_often_it_is_asked(): void
    {
        $order = $this->sell([$this->line($this->bouquet)]);
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $row = $this->dues($order)->first();

        BoutiqueDues::forItem($order, $item, ['boutique_dues' => [[
            'source' => BoutiqueComponentDue::RECIPE, 'recipe_item_id' => $row->recipe_item_id, 'addon_id' => null,
            'product_id' => $this->silver->id, 'product_name' => 'قطعة فضّة',
            'boutique_id' => $this->lama->id, 'boutique_name' => 'بوتيك لمى',
            'unit_price' => 7.0, 'unit_quantity' => 1.0, 'unit_amount' => 7.0,
        ]]]);
        Books::recordSale($order->fresh());

        $this->assertCount(1, $this->dues($order));
        $this->assertSame(7.0, $this->balance('boutique_payable'));
        $this->assertSame(9, (int) $this->silver->fresh()->quantity);
    }

    /* ═══════════ تقريرٌ ودفترٌ ودفترُ مستحقّات ═══════════ */

    public function test_the_profit_report_the_ledger_and_the_dues_say_one_number(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'MINUS5', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);
        $mixed = $this->product('باقة الورد والفضّة', 25, cost: 0);
        $this->recipe($mixed, $this->silver, 1);
        $this->recipe($mixed, $this->rose, 4);

        $this->sell([$this->line($this->bouquet)]);                          // ٢٠، لمى ٧
        $this->sell([$this->line($this->plain, 1, addonQty: 1)]);            // ٢٧، لمى ٧
        $this->sell([$this->line($this->duo)]);                              // ٣٠، لمى ٧ وريم ١٢
        $this->sell([$this->line($mixed)]);                                  // ٢٥، لمى ٧ وورد المتجر ٢
        $this->sell([$this->line($this->bouquet)], ['coupon_code' => 'MINUS5']); // ١٥، لمى ٧
        $standalone = $this->sell([$this->line($this->perfume)]);            // ١٢ منفردًا — عمولة ريم كما كانت

        $total = (float) Order::where('business_id', $this->shop->id)->sum('total');
        $this->assertSame(129.0, $total);
        $this->assertSame(129.0, Ledger::netRevenue($this->shop->id));

        $this->assertSame(35.0, $this->owedTo($this->lama));
        $this->assertSame(12.0, $this->owedTo($this->reem));
        $this->assertSame(47.0, $this->balance('boutique_payable'));

        // التكلفةُ: ٤٧ للبوتيكات و٢ وردٌ من مخزون المتجر
        $this->assertSame(49.0, $this->balance('cogs'));
        $this->assertSame(-2.0, $this->balance('inventory'));
        $this->assertSame(49.0, round(Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01')), 3), 'التقريرُ يقرأ ما يقرؤه الدفتر');
        $this->assertSame(80.0, round(Ledger::netRevenue($this->shop->id) - $this->balance('cogs'), 3));

        // والصنفُ المنفرد كما كان: بلا تكلفة، وبلا صفٍّ في دفتر المكوّنات، وعمولتُه في كشفه
        $item = OrderItem::where('order_id', $standalone->id)->firstOrFail();
        $this->assertSame($this->reem->id, (int) $item->boutique_id);
        $this->assertSame(0.0, (float) $item->cost);
        $this->assertCount(0, $this->dues($standalone));
        $statement = Boutiques::statement($this->shop->id, $this->reem->id, '2027-02');
        $this->assertSame(12.0, $statement['gross']);
        $this->assertSame(1.2, $statement['commission']);
        $this->assertSame(10.8, $statement['net']);
    }

    public function test_a_website_order_owes_exactly_as_the_till_does(): void
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->duo->id, 'qty' => 2]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->where('channel', 'website')->firstOrFail();
        $this->assertSame(14.0, $this->owedTo($this->lama));
        $this->assertSame(24.0, $this->owedTo($this->reem));
        $this->assertSame(38.0, BoutiqueDues::owedFor($order));
        $this->assertSame(38.0, $this->balance('boutique_payable'));
        $this->assertSame(38.0, $this->balance('cogs'));
    }

    /* ═══════════ ما لا يُحسب ═══════════ */

    public function test_switched_off_the_books_stay_as_they_were(): void
    {
        $this->shop->update(['boutique_components_enabled' => false]);

        $order = $this->sell([$this->line($this->bouquet)]);

        $this->assertCount(0, $this->dues());
        $this->assertSame(3.0, (float) OrderItem::where('order_id', $order->id)->value('cost'), 'تكلفةُ البطاقة كما كانت');
        $this->assertSame(0.0, $this->balance('boutique_payable'));
        $this->assertSame(-3.0, $this->balance('inventory'));
    }

    public function test_a_taxed_shop_refuses_a_boutique_component_until_its_policy_is_set(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [$this->line($this->bouquet)], 'payment_method' => 'نقدي',
        ])->assertStatus(422)->assertJsonFragment(['items.0' => [__('«:name» صنفُ بوتيك، ومستحقُّ البوتيك لا يُحسب بعد في متجرٍ يحتسب الضريبة.', ['name' => 'قطعة فضّة'])]]);

        // وما لا بوتيكَ فيه يُباع كالمعتاد
        $this->sell([$this->line($this->plain)]);
        $this->assertCount(0, $this->dues());
    }

    public function test_what_cannot_be_counted_exactly_is_refused_with_a_reason(): void
    {
        $refused = function (string $needle) {
            $res = $this->actingAs($this->owner)->postJson('/pos/checkout', [
                'items' => [$this->line($this->bouquet)], 'payment_method' => 'نقدي',
            ])->assertStatus(422);
            $this->assertStringContainsString($needle, (string) json_encode($res->json('errors'), JSON_UNESCAPED_UNICODE));
        };
        $row = RecipeItem::where('product_id', $this->bouquet->id)->firstOrFail();

        // الفاقدُ لا يولّد مستحقًّا تلقائيًّا — يُمنع حتى تُعتمد قاعدتُه
        $row->update(['wastage_percent' => 10]);
        $refused('فاقد');

        $row->update(['wastage_percent' => 0, 'quantity' => 1.5]);
        $refused('كسريّة');

        $row->update(['quantity' => 1]);
        ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->silver->id, 'name' => 'كبيرة', 'price' => 9, 'active' => true]);
        $refused('مقاسات');

        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
        $this->assertSame(10, (int) $this->silver->fresh()->quantity);
    }
}
