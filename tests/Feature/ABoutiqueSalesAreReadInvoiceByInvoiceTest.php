<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «مبيعات البوتيك وفواتيره» — كلُّ بيعٍ لبوتيكٍ بفاتورته الأصليّة، بلا عدٍّ مرّتين.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المنفرد: صفٌّ ببنده وإجماليّه وعمولة المتجر بنسبة ساعة البيع وصافي البوتيك،
 *     والملخّصُ يساوي `Boutiques::soldTotals` والكشفَ.
 *   - الطقمُ بعشرين فيه قطعةُ بوتيكٍ بسبعة: صفُّ القطعة باسم الطقم، وللبوتيك سبعة
 *     بلا عمولة، ويبقى للمتجر ثلاثة عشر من إيراد البند — ولا تُعدّ بيعًا منفردًا.
 *   - الإضافةُ الاختياريّة، وبوتيكان في طقمٍ واحد، والمنفردُ مع الطقم في فاتورة.
 *   - السعرُ والنسبةُ لقطةٌ لا تتغيّر بتغيّر البطاقة.
 *   - الخصمُ يتحمّله المتجر، والضريبةُ الشاملة وغيرُ الشاملة والإعفاءُ تقرأ ما يقرؤه الكشف.
 *   - الإلغاءُ والإرجاعُ الجزئيّ، والتسويةُ والسدادُ، والمرتجعُ بعد الورقة.
 *   - البيعةُ المعادة بالمعرّف نفسِه صفٌّ واحد، والمعلّقةُ لا تُعرض.
 *   - العزل: بوتيكُ متجرٍ آخر وفاتورتُه لا تُقرآن، والصلاحيّةُ على الخادم.
 *   - لا كتابة: الأبوابُ الثلاثة لا تكتب في القاعدة شيئًا.
 *   - الأداء: آلافُ الفواتير صفحةً صفحة بعددٍ ثابتٍ من الاستعلامات.
 *
 * الأرقامُ بالريال العُماني بثلاث خانات.
 */
class ABoutiqueSalesAreReadInvoiceByInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $perfume;

    private Product $set;

    private Product $duo;

    private Product $plain;

    private Addon $silverAddon;

    private const PERIOD = '2027-02';

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

        [$this->shop, $this->owner] = $this->shopWith('RIBBON', 'saud@abaad.om');

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);

        $this->silver = $this->product('قطعة فضّة', 6, boutique: $this->lama->id, qty: 50);
        $this->perfume = $this->product('عطر صغير', 12, boutique: $this->reem->id, qty: 50);

        // طقمُ سعود بعشرين، فيه قطعةُ فضّةٍ من لمى بستّة — مثالُ صاحب المتجر نفسُه
        $this->set = $this->product('طقم الفضّة', 20);
        $this->recipe($this->set, $this->silver, 1);

        $this->duo = $this->product('طقم البوتيكين', 30);
        $this->recipe($this->duo, $this->silver, 1);
        $this->recipe($this->duo, $this->perfume, 1);

        $this->plain = $this->product('باقة بسيطة', 20);

        $this->silverAddon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'فضّة اختياريّة', 'price' => 6,
            'active' => true, 'scope' => Addon::SCOPE_ALL,
            'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أدوات ═══════════ */

    private function shopWith(string $name, string $email): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'tier' => 'gold',
            'boutiques_enabled' => true, 'boutique_components_enabled' => true,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'مالك '.$name, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function product(string $name, float $price, ?int $boutique = null, int $qty = 100, ?Business $shop = null): Product
    {
        return Product::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'price' => $price, 'cost' => 1,
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    private function recipe(Product $of, Product $component, float $qty): RecipeItem
    {
        return RecipeItem::create([
            'business_id' => $of->business_id, 'product_id' => $of->id,
            'component_product_id' => $component->id, 'quantity' => $qty, 'wastage_percent' => 0,
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

    private function sell(array $lines, array $extra = [], ?User $as = null): Order
    {
        $as ??= $this->owner;
        $this->actingAs($as)->postJson('/pos/checkout', [
            'items' => $lines, 'payment_method' => 'نقدي', 'client_uuid' => uniqid('b', true), ...$extra,
        ])->assertOk();

        return Order::where('business_id', $as->business_id)->orderByDesc('id')->firstOrFail();
    }

    private function rows(array $params = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD, ...$params]))
            ->assertOk()->json();
    }

    private function summary(array $params = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->getJson(route('admin.boutiques.sales.summary', ['period' => self::PERIOD, ...$params]))
            ->assertOk()->json();
    }

    private function invoice(Order $order, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)
            ->getJson(route('admin.boutiques.sales.order', $order->id))->assertOk()->json();
    }

    /* ═══════════ ١ · المنفرد ═══════════ */

    public function test_a_boutique_piece_sold_alone_is_one_row_with_the_commission_of_its_day(): void
    {
        $order = $this->sell([$this->line($this->silver, 2)]);

        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame('standalone', $r['type']);
        $this->assertSame($order->number, $r['order_number']);
        $this->assertSame('2027-02-10 10:00', $r['ordered_at']);
        $this->assertSame('قطعة فضّة', $r['product']);
        $this->assertSame('بوتيك لمى', $r['boutique']);
        $this->assertNull($r['parent']);
        $this->assertEquals(2, $r['quantity']);
        $this->assertEquals(6, $r['unit_price']);
        $this->assertEquals(12, $r['amount']);
        $this->assertEquals(20, $r['rate']);
        $this->assertEquals(2.4, $r['commission'], 'عشرون بالمئة من اثني عشر');
        $this->assertEquals(9.6, $r['net']);
        $this->assertSame('active', $r['invoice_state']);
        $this->assertSame('pending', $r['settle_state']);

        $s = $this->summary(['boutique' => (string) $this->lama->id]);
        $totals = Boutiques::soldTotals($this->shop->id, $this->lama, Carbon::parse('2027-02-01'), null, null, Carbon::parse('2027-02-28 23:59:59'));
        $this->assertEquals($totals['gross'], $s['standalone']['gross']);
        $this->assertEquals($totals['commission'], $s['standalone']['commission']);
        $this->assertEquals($totals['net'], $s['standalone']['net']);

        // والكشفُ القائم يقول الأرقامَ نفسَها
        $st = Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD);
        $this->assertEquals([$st['gross'], $st['commission'], $st['net']], [$s['standalone']['gross'], $s['standalone']['commission'], $s['standalone']['net']]);
        $this->assertEquals(9.6, $s['owed']);
        $this->assertEquals(0, $s['components']['due']);

        // ولا «−٠» يُطبع في الشاشة: الصفرُ صفر
        $raw = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.summary', ['period' => self::PERIOD]))->getContent();
        $this->assertDoesNotMatchRegularExpression('/[:\[,]-0(\.0+)?[,}\]]/', $raw);
    }

    /* ═══════════ ٢ · القطعة داخل منتج المتجر ═══════════ */

    public function test_a_set_of_twenty_with_a_piece_of_six_owes_six_and_leaves_fourteen(): void
    {
        $order = $this->sell([$this->line($this->set)]);

        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows, 'القطعةُ صفٌّ واحد — لا بيعٌ منفردٌ ثانٍ');
        $r = $rows[0];
        $this->assertSame('bundle', $r['type']);
        $this->assertSame($order->number, $r['order_number']);
        $this->assertSame('قطعة فضّة', $r['product']);
        $this->assertSame('طقم الفضّة', $r['parent']);
        $this->assertEquals(20, $r['parent_total']);
        $this->assertEquals(1, $r['quantity']);
        $this->assertEquals(6, $r['unit_price']);
        $this->assertEquals(6, $r['amount']);
        $this->assertNull($r['rate']);
        $this->assertEquals(0, $r['commission'], 'لا عمولة على قطعةٍ دخلت منتجَ المتجر');
        $this->assertEquals(6, $r['net']);

        $s = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(0, $s['standalone']['gross'], 'الطقمُ ليس مبيعًا للبوتيك');
        $this->assertEquals(6, $s['components']['bundle']);
        $this->assertEquals(6, $s['components']['due']);
        $this->assertEquals(20, $s['bundles']['revenue']);
        $this->assertEquals(6, $s['bundles']['boutiques_due']);
        $this->assertEquals(14, $s['bundles']['shop_remaining']);
        $this->assertEquals(6, $s['owed'], 'المستحقُّ ستّة — لا عشرون ولا ستّةٌ وعشرون');

        $inv = $this->invoice($order);
        $this->assertCount(1, $inv['lines']);
        $this->assertSame('طقم الفضّة', $inv['lines'][0]['name']);
        $this->assertNull($inv['lines'][0]['boutique']);
        $this->assertEquals(6, $inv['lines'][0]['dues']);
        $this->assertEquals(14, $inv['lines'][0]['shop_remaining']);
        $this->assertSame('bundle', $inv['lines'][0]['pieces'][0]['type']);
        $this->assertEquals(6, $inv['totals']['owed']);

        // والأرقامُ هي ما في دفتر المستحقّات وفي الحساب 2160
        $this->assertEquals(BoutiqueDues::owedFor($order), $s['components']['due']);
        $this->assertSame(6.0, Ledger::balance($this->shop->id, 'boutique_payable'));
    }

    public function test_an_optional_piece_is_an_addon_on_the_shops_product(): void
    {
        $this->sell([$this->line($this->plain, 1, addonQty: 1)]);

        $r = $this->rows()['rows'][0];
        $this->assertSame('addon', $r['type']);
        $this->assertSame('باقة بسيطة', $r['parent']);
        $this->assertSame('فضّة اختياريّة', $r['addon']);
        $this->assertEquals(6, $r['amount']);

        $s = $this->summary();
        $this->assertEquals(6, $s['components']['addon']);
        $this->assertEquals(26, $s['bundles']['revenue'], 'البندُ بإضافته: عشرون وستّة');
        $this->assertEquals(20, $s['bundles']['shop_remaining']);
    }

    public function test_two_boutiques_in_one_set_are_each_given_their_own(): void
    {
        $this->sell([$this->line($this->duo)]);

        $all = collect($this->rows()['rows']);
        $this->assertCount(2, $all);
        $this->assertEquals(6, $all->firstWhere('boutique', 'بوتيك لمى')['amount']);
        $this->assertEquals(12, $all->firstWhere('boutique', 'بوتيك ريم')['amount']);

        $lama = $this->rows(['boutique' => (string) $this->lama->id])['rows'];
        $this->assertCount(1, $lama);
        $this->assertSame('بوتيك لمى', $lama[0]['boutique']);

        $s = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(6, $s['components']['due']);
        $this->assertEquals(30, $s['bundles']['revenue']);
        $this->assertEquals(18, $s['bundles']['boutiques_due'], 'ما للبوتيكين معًا على البند');
        $this->assertEquals(12, $s['bundles']['shop_remaining']);

        $this->assertEquals(12, $this->summary(['boutique' => (string) $this->reem->id])['components']['due']);
        $this->assertEquals(18, $this->summary()['owed']);
    }

    public function test_a_piece_sold_alone_and_a_set_in_one_invoice_are_two_rows_and_never_summed_twice(): void
    {
        $order = $this->sell([$this->line($this->silver), $this->line($this->set)]);

        $rows = collect($this->rows()['rows']);
        $this->assertSame(['bundle', 'standalone'], $rows->pluck('type')->sort()->values()->all());
        $this->assertSame([$order->number], $rows->pluck('order_number')->unique()->values()->all());

        $s = $this->summary();
        $this->assertEquals(6, $s['standalone']['gross']);
        $this->assertEquals(1.2, $s['standalone']['commission']);
        $this->assertEquals(4.8, $s['standalone']['net']);
        $this->assertEquals(6, $s['components']['due']);
        $this->assertEquals(10.8, $s['owed'], 'صافي المنفرد وثمنُ القطعة — لا ثمنُ الطقم');

        $inv = $this->invoice($order);
        $this->assertCount(2, $inv['lines']);
        $this->assertEquals(['standalone_gross' => 6, 'commission' => 1.2, 'standalone_net' => 4.8, 'components_due' => 6, 'owed' => 10.8], $inv['totals']);
    }

    public function test_a_shop_product_and_a_boutique_piece_in_one_invoice_each_get_their_share(): void
    {
        $order = $this->sell([$this->line($this->plain), $this->line($this->silver, 2)]);

        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows, 'منتجُ المتجر ليس صفًّا في مبيعات البوتيك');
        $this->assertEquals(12, $rows[0]['amount']);

        $inv = $this->invoice($order);
        $this->assertCount(2, $inv['lines'], 'الفاتورةُ ببنودها كلّها');
        $shopLine = collect($inv['lines'])->firstWhere('name', 'باقة بسيطة');
        $this->assertNull($shopLine['boutique']);
        $this->assertEquals(20, $shopLine['shop_share']);
        $this->assertEquals(2.4, collect($inv['lines'])->firstWhere('name', 'قطعة فضّة')['shop_share'], 'العمولةُ وحدها للمتجر من بند البوتيك');

        $this->assertEquals([[
            'id' => $this->lama->id, 'name' => 'بوتيك لمى', 'standalone_gross' => 12, 'commission' => 2.4,
            'standalone_net' => 9.6, 'components_due' => 0, 'owed' => 9.6,
        ]], $inv['shares']['boutiques']);
        $this->assertEquals(22.4, $inv['shares']['shop']);
        $this->assertEquals(32, $inv['shares']['lines_total']);
        $this->assertTrue($inv['shares']['reconciles']);
        $this->assertEquals($inv['order']['subtotal'], $inv['shares']['lines_total']);
        $this->assertEquals(32, $inv['order']['total'], 'إجماليُّ الفاتورة كما سُجّل — لا يُضاعف ولا يُعاد حسابه');
        $this->assertSame('none', $inv['order']['tax_basis']);
    }

    public function test_pieces_of_several_boutiques_in_one_invoice_are_each_their_own_and_the_invoice_is_never_counted_twice(): void
    {
        $order = $this->sell([$this->line($this->silver), $this->line($this->perfume), $this->line($this->duo)]);

        $rows = collect($this->rows()['rows']);
        $this->assertCount(4, $rows, 'منفردان وقطعتا الطقم');
        $this->assertSame([$order->number], $rows->pluck('order_number')->unique()->values()->all());

        $inv = $this->invoice($order);
        $shares = collect($inv['shares']['boutiques'])->keyBy('name');
        $this->assertEquals(['standalone_gross' => 6, 'commission' => 1.2, 'standalone_net' => 4.8, 'components_due' => 6, 'owed' => 10.8],
            collect($shares['بوتيك لمى'])->only(['standalone_gross', 'commission', 'standalone_net', 'components_due', 'owed'])->all());
        $this->assertEquals(['standalone_gross' => 12, 'commission' => 1.2, 'standalone_net' => 10.8, 'components_due' => 12, 'owed' => 22.8],
            collect($shares['بوتيك ريم'])->only(['standalone_gross', 'commission', 'standalone_net', 'components_due', 'owed'])->all());
        $this->assertEquals(14.4, $inv['shares']['shop'], 'عمولتان ٢٫٤ + باقي الطقم ١٢');
        $this->assertEquals(48, $inv['shares']['lines_total']);
        $this->assertEquals(48, round($inv['shares']['shop'] + $shares->sum('owed'), 3), 'مجموعُ الجهات = مجموعُ البنود');
        $this->assertTrue($inv['shares']['reconciles']);

        // والملخّصُ لكلّ بوتيكٍ وحده يجمع إلى ملخّص الكلّ — لا يدخل الطقمُ مرّتين
        $all = $this->summary();
        $lama = $this->summary(['boutique' => (string) $this->lama->id]);
        $reem = $this->summary(['boutique' => (string) $this->reem->id]);
        $this->assertEquals(10.8, $lama['owed']);
        $this->assertEquals(22.8, $reem['owed']);
        $this->assertEquals(33.6, $all['owed']);
        $this->assertEquals(18, $all['standalone']['gross']);
        $this->assertEquals(18, $all['components']['due']);
        $this->assertEquals(1, $all['bundles']['lines'], 'الطقمُ بندٌ واحد ولو فيه بوتيكان');
        $this->assertEquals(30, $all['bundles']['revenue']);
    }

    public function test_pieces_inside_a_set_stay_out_of_what_is_owed_while_the_switch_is_off(): void
    {
        Business::whereKey($this->shop->id)->update(['boutique_components_enabled' => false]);

        $alone = $this->sell([$this->line($this->set)]);
        $this->assertSame(0, BoutiqueComponentDue::count(), 'المفتاحُ مطفأ: لا مستحقَّ يُكتب');
        $this->assertSame(0, $this->rows()['total'], 'الطقمُ لسعود وحده');
        $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $alone->id))->assertNotFound();

        $mixed = $this->sell([$this->line($this->set), $this->line($this->silver)]);
        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows, 'المنفردُ وحده');
        $this->assertSame('standalone', $rows[0]['type']);

        $s = $this->summary();
        $this->assertSame('off', $s['pieces']);
        $this->assertEquals(0, $s['components']['due']);
        $this->assertEquals(4.8, $s['owed'], 'صافي المنفرد وحده — لا ثمنُ القطعة في الطقم');
        $this->assertEquals(0, $s['bundles']['boutiques_due']);

        $inv = $this->invoice($mixed);
        $set = collect($inv['lines'])->firstWhere('name', 'طقم الفضّة');
        $this->assertSame([], $set['pieces']);
        $this->assertEquals(20, $set['shop_share'], 'الطقمُ كلُّه للمتجر ما دام المفتاحُ مطفأ');
        $this->assertEquals([4.8], collect($inv['shares']['boutiques'])->pluck('owed')->all());
        $this->assertEquals(21.2, $inv['shares']['shop']);
        $this->assertEquals(26, $inv['shares']['lines_total']);
    }

    public function test_a_line_belongs_to_who_owned_it_when_it_was_sold(): void
    {
        $this->sell([$this->line($this->silver), $this->line($this->set)]);

        // القطعةُ انتقلت إلى ريم، ولمى غيّرت اسمها ونسبتها — بعد البيع
        $this->silver->update(['boutique_id' => $this->reem->id]);
        $this->lama->update(['name' => 'بوتيك لمى الجديد', 'commission_rate' => 50]);

        $rows = collect($this->rows()['rows']);
        $this->assertSame([$this->lama->id], $rows->pluck('boutique_id')->unique()->values()->all(), 'صاحبُ البند صاحبُه ساعةَ البيع');
        $this->assertEquals(1.2, $rows->firstWhere('type', 'standalone')['commission'], 'ونسبتُه نسبةُ يومها');
        $this->assertEquals(4.8 + 6, $this->summary(['boutique' => (string) $this->lama->id])['owed']);
        $this->assertEquals(0, $this->summary(['boutique' => (string) $this->reem->id])['owed']);
    }

    /* ═══════════ ٣ · اللقطة ═══════════ */

    public function test_a_price_or_rate_changed_after_the_sale_changes_nothing_that_was_sold(): void
    {
        $this->sell([$this->line($this->silver), $this->line($this->set)]);

        $this->silver->update(['price' => 9]);
        $this->lama->update(['commission_rate' => 35]);

        $rows = collect($this->rows()['rows']);
        $this->assertEquals(6, $rows->firstWhere('type', 'standalone')['amount']);
        $this->assertEquals(20, $rows->firstWhere('type', 'standalone')['rate']);
        $this->assertEquals(6, $rows->firstWhere('type', 'bundle')['unit_price']);
        $this->assertEquals(10.8, $this->summary()['owed']);
    }

    /* ═══════════ ٤ · الخصم والضريبة ═══════════ */

    public function test_the_shop_bears_its_discount_and_the_report_says_so(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'MINUS5', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);
        $order = $this->sell([$this->line($this->set)], ['coupon_code' => 'MINUS5']);

        $this->assertEquals(6, $this->rows()['rows'][0]['amount'], 'الخصمُ لا يُنقص حصّة البوتيك');
        $inv = $this->invoice($order);
        $this->assertEquals(5, $inv['order']['discount']);
        $this->assertEquals(15, $inv['order']['total']);
        $this->assertEquals(14, $inv['lines'][0]['shop_remaining'], 'الباقي من البند قبل خصم الفاتورة');
    }

    public static function taxModes(): array
    {
        return [
            'غير شاملة' => ['exclusive', null],
            'شاملة' => ['inclusive', null],
            'صنفٌ معفى' => ['exclusive', 0.0],
        ];
    }

    #[DataProvider('taxModes')]
    public function test_a_taxed_shop_reads_what_its_statement_reads(string $mode, ?float $productTax): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'tax_mode', 'value' => $mode]);
        $this->silver->update(['tax' => $productTax]);

        $order = $this->sell([$this->line($this->silver, 3)]);

        $r = $this->rows()['rows'][0];
        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(round((float) $item->total, 3), $r['amount'], 'الإجماليُّ بند الفاتورة كما كُتب');

        $s = $this->summary();
        $st = Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD);
        $this->assertEquals([$st['gross'], $st['commission'], $st['net']], [$s['standalone']['gross'], $s['standalone']['commission'], $s['standalone']['net']]);

        // وقطعةٌ في طقمٍ تُردّ في متجرٍ يحتسب الضريبة — لا يُفتح ما لم يُقرَّر
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [$this->line($this->set)], 'payment_method' => 'نقدي',
        ])->assertStatus(422);
        $this->assertCount(1, $this->rows()['rows']);
        $this->assertSame('taxed', $this->summary()['pieces'], 'والشاشةُ تقول إنّ القطع لا تُحسب في متجرٍ بضريبة');
    }

    public function test_the_screen_says_when_pieces_inside_the_shops_products_are_not_counted(): void
    {
        $this->assertSame('on', $this->summary()['pieces']);

        Business::whereKey($this->shop->id)->update(['boutique_components_enabled' => false]);
        $this->assertSame('off', $this->summary()['pieces'], 'المفتاحُ مطفأ — يُقال ولا يُفتح');
        $this->assertSame(1, Business::whereKey($this->shop->id)->where('boutique_components_enabled', false)->count(), 'القراءةُ لا تُشعله');
    }

    public static function addedOrInside(): array
    {
        return ['ضريبةٌ مضافة' => ['exclusive', 'added'], 'ضريبةٌ ضمن الأسعار' => ['inclusive', 'inside']];
    }

    #[DataProvider('addedOrInside')]
    public function test_an_invoice_with_a_discount_vat_and_a_partial_return_keeps_every_share_whole(string $mode, string $basis): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'tax_mode', 'value' => $mode]);
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'MINUS5', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);

        $order = $this->sell([$this->line($this->plain), $this->line($this->silver, 3)], ['coupon_code' => 'MINUS5']);
        $this->actingAs($this->owner);
        $piece = OrderItem::where('order_id', $order->id)->whereNotNull('boutique_id')->firstOrFail();
        OrderCorrection::setQuantity($order->fresh(), $piece, 2, 'رُدّت قطعة');
        $order->refresh();

        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('partial', $rows[0]['invoice_state']);
        $this->assertEquals(2, $rows[0]['quantity']);
        $this->assertEquals(12, $rows[0]['amount'], 'سعرُ البيع × ما بقي — لا الخصمُ ولا الضريبةُ تُقتطع منه');
        $this->assertEquals(2.4, $rows[0]['commission']);
        $this->assertEquals(9.6, $rows[0]['net']);

        $s = $this->summary();
        $st = Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD);
        $this->assertEquals([$st['gross'], $st['commission'], $st['net']], [$s['standalone']['gross'], $s['standalone']['commission'], $s['standalone']['net']], 'كما يقرؤه كشفُ الحساب');
        $this->assertEquals(12, $s['standalone']['gross']);
        $this->assertSame(1, $s['standalone']['taxed_orders'], 'الشاشةُ تقول إنّ في الشهر فاتورةً بضريبة');

        $inv = $this->invoice($order);
        $this->assertSame($basis, $inv['order']['tax_basis'], 'موضعُ الضريبة يُقرأ من الفاتورة نفسها');
        $this->assertEquals(5, $inv['order']['discount']);
        $this->assertGreaterThan(0, $inv['order']['tax']);
        $this->assertEquals(round((float) $order->total, 3), $inv['order']['total']);
        $this->assertEquals(9.6, $inv['shares']['boutiques'][0]['owed']);
        $this->assertTrue($inv['shares']['reconciles'], 'البنودُ بعد الإرجاع تطابق إجماليَّ الفاتورة');
        $this->assertEquals(32, $inv['shares']['lines_total']);
        // والمجموعُ الفرعيُّ المسجَّل صافٍ من الضريبة في الأسعار الشاملة — فلا يُقرأ منه موضعُها
        $this->assertEquals($mode === 'inclusive' ? round(32 - (float) $order->tax, 3) : 32.0, round((float) $order->subtotal, 3));
        $this->assertEquals($mode === 'inclusive' ? 27 : round(27 + (float) $order->tax, 3), $inv['order']['total']);
        $this->assertEquals(22.4, $inv['shares']['shop'], 'الخصمُ والضريبةُ على الفاتورة لا على الجهات');
        $this->assertNotEmpty($inv['edits']);
    }

    /* ═══════════ ٥ · الإلغاء والإرجاع ═══════════ */

    public function test_a_full_return_of_one_boutiques_line_leaves_the_rest_of_the_invoice(): void
    {
        $order = $this->sell([$this->line($this->plain), $this->line($this->silver, 2), $this->line($this->perfume)]);
        $this->actingAs($this->owner);
        $silver = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->lama->id)->firstOrFail();
        OrderCorrection::setQuantity($order->fresh(), $silver, 0, 'رُدّ الصنف كلُّه');
        $order->refresh();

        $rows = $this->rows()['rows'];
        $this->assertSame(['بوتيك ريم'], collect($rows)->pluck('boutique')->all());
        $this->assertSame('partial', $rows[0]['invoice_state']);
        $this->assertEquals(0, $this->summary(['boutique' => (string) $this->lama->id])['owed']);
        $this->assertEquals(10.8, $this->summary(['boutique' => (string) $this->reem->id])['owed']);

        $inv = $this->invoice($order);
        $this->assertSame(['بوتيك ريم'], collect($inv['shares']['boutiques'])->pluck('name')->all());
        $this->assertEquals(32, $inv['shares']['lines_total'], '٢٠ + ١٢ — ما رُدّ خرج مرّةً واحدة');
        $this->assertEquals(21.2, $inv['shares']['shop'], '٢٠ + عمولة ريم ١٫٢');
        $this->assertTrue($inv['shares']['reconciles']);
        $this->assertFalse($inv['shares']['void']);
    }

    public function test_a_delivery_fee_and_redeemed_points_are_on_the_invoice_not_on_the_shares(): void
    {
        foreach (['loyalty_enabled' => '1', 'loyalty_redeem_max_pct' => '50', 'loyalty_redeem_min' => '100'] as $key => $value) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
        }
        $customer = Customer::create(['business_id' => $this->shop->id, 'language' => 'ar', 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000]);

        $order = $this->sell([$this->line($this->plain), $this->line($this->silver)], [
            'customer_id' => $customer->id, 'redeem_points' => 999999, 'delivery_fee' => 2,
        ]);
        $this->assertGreaterThan(0, (float) $order->discount, 'الاستبدالُ لم يقع — الاختبارُ لا يقيس شيئًا');

        $inv = $this->invoice($order);
        $this->assertEquals(2, $inv['order']['delivery_fee']);
        $this->assertEquals(round((float) $order->discount, 3), $inv['order']['discount']);
        $this->assertEquals(26, $inv['shares']['lines_total']);
        $this->assertEquals(4.8, $inv['shares']['boutiques'][0]['owed'], 'النقاطُ والتوصيلُ لا تمسّان نصيبَ البوتيك');
        $this->assertEquals(21.2, $inv['shares']['shop']);
        $this->assertTrue($inv['shares']['reconciles'], 'البنودُ − الخصم + التوصيل = الإجمالي');
    }

    public function test_a_cancelled_invoice_of_several_boutiques_keeps_its_lines_and_owes_nobody(): void
    {
        $order = $this->sell([$this->line($this->plain), $this->line($this->silver), $this->line($this->perfume), $this->line($this->duo)]);
        $before = OrderItem::where('order_id', $order->id)->orderBy('id')->get(['name', 'quantity', 'price', 'total', 'boutique_id', 'boutique_rate'])->toArray();
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'الزبون ألغى', boutiqueReturned: true);

        $inv = $this->invoice($order);
        $this->assertSame('cancelled', $inv['order']['invoice_state']);
        $this->assertCount(4, $inv['lines'], 'البنودُ كما سُجّلت');
        $this->assertTrue($inv['shares']['void']);
        $this->assertEquals([0, 0], collect($inv['shares']['boutiques'])->pluck('owed')->all());
        $this->assertEquals(0, $inv['shares']['shop']);
        $this->assertEquals(0, $inv['totals']['owed']);

        $s = $this->summary();
        $this->assertEquals(0, $s['owed']);
        $this->assertEquals(0, $s['standalone']['gross']);
        $this->assertEquals(0, $s['components']['due']);
        $this->assertEquals(18, $s['returns']['cancelled_gross'], 'المنفردان ٦ + ١٢ — يُعرضان ملغيَين لا مبيعًا');
        $this->assertSame($before, OrderItem::where('order_id', $order->id)->orderBy('id')->get(['name', 'quantity', 'price', 'total', 'boutique_id', 'boutique_rate'])->toArray(), 'القراءةُ لا تمسّ سجلَّ البيع');
    }

    public function test_a_cancelled_invoice_is_shown_cancelled_and_counted_nowhere(): void
    {
        $order = $this->sell([$this->line($this->silver), $this->line($this->set)]);
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'الزبون ألغى', boutiqueReturned: true);

        $rows = collect($this->rows()['rows']);
        $this->assertCount(2, $rows, 'الملغاةُ تُعرض بحالها');
        $this->assertSame(['cancelled'], $rows->pluck('invoice_state')->unique()->values()->all());
        $this->assertEquals(0, $rows->firstWhere('type', 'bundle')['amount'], 'المستحقُّ عُكس');

        $s = $this->summary();
        $this->assertEquals(0, $s['standalone']['gross']);
        $this->assertEquals(0, $s['components']['due']);
        $this->assertEquals(0, $s['owed']);
        $this->assertEquals(6, $s['returns']['cancelled_gross']);
    }

    public function test_a_partial_return_is_read_from_the_corrections_and_lowers_the_due(): void
    {
        $order = $this->sell([$this->line($this->silver, 2), $this->line($this->set, 2)]);
        $this->actingAs($this->owner);
        $standalone = OrderItem::where('order_id', $order->id)->whereNotNull('boutique_id')->firstOrFail();
        $set = OrderItem::where('order_id', $order->id)->whereNull('boutique_id')->firstOrFail();
        OrderCorrection::setQuantity($order->fresh(), $standalone, 1, 'رُدّت قطعة');
        OrderCorrection::setQuantity($order->fresh(), $set->fresh(), 1, 'رُدّ طقم');

        $rows = collect($this->rows()['rows']);
        $this->assertSame(['partial'], $rows->pluck('invoice_state')->unique()->values()->all());
        $this->assertEquals(1, $rows->firstWhere('type', 'standalone')['quantity']);
        $this->assertEquals(1, $rows->firstWhere('type', 'bundle')['quantity'], 'الأصلُ وعكسُه والجديدُ صفٌّ واحد');
        $this->assertEquals(6, $rows->firstWhere('type', 'bundle')['amount']);
        $this->assertEquals(10.8, $this->summary()['owed']);

        $edits = collect($this->invoice($order)['edits']);
        $this->assertCount(2, $edits);
    }

    /* ═══════════ ٦ · التسوية والسداد والمرتجع بعدها ═══════════ */

    public function test_settlement_issued_then_paid_is_read_per_row_and_in_the_summary(): void
    {
        $this->sell([$this->line($this->silver), $this->line($this->set)]);

        $before = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(10.8, $before['settlement']['pending']['total']);
        $this->assertEquals(10.8, $before['settlement']['remaining']);

        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);

        $rows = collect($this->rows()['rows']);
        $this->assertSame(['issued'], $rows->pluck('settle_state')->unique()->values()->all());
        $this->assertSame([$sheet->number], $rows->pluck('settlement')->unique()->values()->all());
        $mid = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(10.8, $mid['settlement']['issued']);
        $this->assertEquals(10.8, $mid['settlement']['unpaid']);
        $this->assertEquals(0, $mid['settlement']['pending']['total']);
        $this->assertEquals(10.8, $mid['settlement']['remaining']);
        $this->assertEquals(10.8, $mid['owed'], 'المبيعاتُ لم تتغيّر بالتسوية');

        // المنفردُ يُسدَّد من المبالغ المستحقة، والقطعُ بسدادها
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame('paid', collect($this->rows()['rows'])->firstWhere('type', 'standalone')['settle_state']);
        $this->assertSame('issued', collect($this->rows()['rows'])->firstWhere('type', 'bundle')['settle_state']);

        BoutiqueDues::pay($sheet->fresh(), 'نقدي');
        $after = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(10.8, $after['settlement']['paid']);
        $this->assertEquals(0, $after['settlement']['unpaid']);
        $this->assertEquals(0, $after['settlement']['remaining']);
        $this->assertSame(['paid'], collect($this->rows()['rows'])->pluck('settle_state')->unique()->values()->all());

        $this->assertSame('paid', collect($this->rows(['settle' => 'paid'])['rows'])->pluck('settle_state')->unique()->first());
        $this->assertCount(0, $this->rows(['settle' => 'pending'])['rows']);
    }

    public function test_a_return_after_the_sheet_is_shown_apart_and_not_taken_from_sales_twice(): void
    {
        $order = $this->sell([$this->line($this->silver), $this->line($this->set)]);
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'نقدي']);
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');

        Carbon::setTestNow('2027-02-15 09:00:00');
        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُعيدت سليمة', boutiqueReturned: true, boutiqueLines: null);

        $rows = collect($this->rows()['rows']);
        $bundle = $rows->firstWhere('type', 'bundle');
        $this->assertEquals(6, $bundle['amount'], 'ما حملته الورقةُ يبقى كما صدر');
        $this->assertEquals(6, $bundle['credited'], 'والعكسُ رصيدٌ للمتجر');
        $this->assertSame('paid', $bundle['settle_state']);

        $s = $this->summary();
        $this->assertEquals(6, $s['returns']['component_credits']);
        $this->assertEquals(0, $s['standalone']['gross'], 'المبيعاتُ الحاليّة بعد الإلغاء');
        $this->assertSame(BoutiqueReturn::where('business_id', $this->shop->id)->count(), $s['returns']['notes']);
        $this->assertEquals(10.8, $s['settlement']['paid'], 'ما سُدِّد سُدِّد');
    }

    /* ═══════════ ٧ · الشبكة والمعلّقة والفواتير القديمة ═══════════ */

    public function test_a_retried_sale_with_the_same_key_is_one_row(): void
    {
        $payload = ['items' => [$this->line($this->set)], 'payment_method' => 'نقدي', 'client_uuid' => 'retry-key-1'];
        $this->actingAs($this->owner)->postJson('/pos/checkout', $payload)->assertOk();
        $this->actingAs($this->owner)->postJson('/pos/checkout', $payload);

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertCount(1, $this->rows()['rows']);
        $this->assertEquals(6, $this->summary()['components']['due']);
    }

    public function test_a_held_sale_is_not_a_sale_and_an_old_line_without_a_rate_reads_zero(): void
    {
        $held = Order::create(['business_id' => $this->shop->id, 'number' => 'HOLD-1', 'status' => 'جديد', 'is_held' => true,
            'subtotal' => 6, 'total' => 6, 'ordered_at' => now()]);
        OrderItem::create(['order_id' => $held->id, 'product_id' => $this->silver->id, 'name' => 'قطعة فضّة', 'price' => 6, 'quantity' => 1, 'total' => 6,
            'boutique_id' => $this->lama->id, 'boutique_name' => 'بوتيك لمى', 'boutique_rate' => 20]);

        $old = Order::create(['business_id' => $this->shop->id, 'number' => 'OLD-1', 'status' => 'مكتمل', 'is_held' => false,
            'subtotal' => 5, 'total' => 5, 'ordered_at' => '2027-02-02 09:00:00']);
        OrderItem::create(['order_id' => $old->id, 'product_id' => $this->silver->id, 'name' => 'قطعة قديمة', 'price' => 5, 'quantity' => 1, 'total' => 5,
            'boutique_id' => $this->lama->id, 'boutique_name' => 'بوتيك لمى', 'boutique_rate' => null]);

        $rows = $this->rows()['rows'];
        $this->assertCount(1, $rows, 'المعلّقةُ ليست بيعًا');
        $this->assertSame('OLD-1', $rows[0]['order_number']);
        $this->assertEquals(0, $rows[0]['commission']);
        $this->assertEquals(5, $rows[0]['net']);
        $this->assertEquals(5, $this->summary()['standalone']['net']);
    }

    /* ═══════════ ٨ · التقريب ═══════════ */

    public function test_rounding_is_one_rule_in_whole_baisa_and_the_systems_difference_is_shown_not_hidden(): void
    {
        $this->lama->update(['commission_rate' => 12.5]);
        $odd = $this->product('قطعة كسريّة', 0.333, boutique: $this->lama->id);
        foreach (range(1, 7) as $_) {
            $this->sell([$this->line($odd, 3)]);
        }

        $rows = collect($this->rows()['rows']);
        $this->assertCount(7, $rows);
        foreach ($rows as $r) {
            $this->assertEquals(0.999, $r['amount']);
            $this->assertEquals(0.125, $r['commission'], '٠٫١٢٤٨٧٥ ← أقربُ بيسة');
            $this->assertEquals(0.874, $r['net']);
        }

        $s = $this->summary();
        $this->assertEquals(6.993, $s['standalone']['gross']);
        $this->assertEquals(0.875, $s['standalone']['commission'], 'الملخّصُ جمعُ عمولات البنود نفسِها — لا قاعدةٌ ثانية');
        $this->assertEquals(6.118, $s['standalone']['net']);
        $this->assertEquals(round($rows->sum('commission'), 3), $s['standalone']['commission']);

        // ودالّتا النظام تُقرّبان على المجموع — فرقُ البيسة يُعرض بعينه
        $this->assertEquals(0.875, $s['rounding']['lines_commission']);
        $this->assertEquals(0.874, $s['rounding']['report_commission'], 'تقريرُ المبيعات: ٦٫٩٩٣ × ١٢٫٥٪ مرّةً واحدة');
        $this->assertEquals(-0.001, $s['rounding']['report_difference']);
        $this->assertEquals(6.118, $s['rounding']['lines_pending_net']);
        $this->assertEquals(6.119, $s['rounding']['sheet_pending_net'], 'ورقةُ التسوية تقرّب الصنفَ مجموعًا');
        $this->assertEquals(0.001, $s['rounding']['sheet_difference']);
    }

    public function test_half_a_baisa_rounds_up_without_floating_point_and_the_sheets_float_is_reported(): void
    {
        $this->lama->update(['commission_rate' => 12.5]);
        $half = $this->product('نصف بيسة', 0.004, boutique: $this->lama->id);
        $a = $this->product('قطعة ١٫٠٠٥', 1.005, boutique: $this->reem->id);
        $b = $this->product('قطعة ١٫٠١٥', 1.015, boutique: $this->reem->id);
        $big = $this->product('قطعة كبيرة', 99999.999, boutique: $this->reem->id);
        $this->sell([$this->line($half), $this->line($a), $this->line($b), $this->line($big)]);

        $rows = collect($this->rows()['rows'])->keyBy('product');
        $this->assertEquals(0.001, $rows['نصف بيسة']['commission'], '٠٫٠٠٠٥ تمامًا ← نصفٌ إلى أعلى');
        $this->assertEquals(0.003, $rows['نصف بيسة']['net']);
        $this->assertEquals(0.101, $rows['قطعة ١٫٠٠٥']['commission'], '٠٫١٠٠٥ تمامًا — لا ٠٫١٠٠٤٩٩… العائمة');
        $this->assertEquals(0.904, $rows['قطعة ١٫٠٠٥']['net']);
        $this->assertEquals(0.102, $rows['قطعة ١٫٠١٥']['commission']);
        $this->assertEquals(10000.0, $rows['قطعة كبيرة']['commission'], '٩٩٩٩٫٩٩٩٩ ← ١٠٠٠٠');
        $this->assertEquals(89999.999, $rows['قطعة كبيرة']['net']);

        $reem = $this->summary(['boutique' => (string) $this->reem->id]);
        $this->assertEquals(10000.203, $reem['standalone']['commission'], '٠٫١٠١ + ٠٫١٠٢ + ١٠٠٠٠');
        $this->assertEquals(round(1.005 + 1.015 + 99999.999 - 10000.203, 3), $reem['standalone']['net']);

        // وورقةُ التسوية اليوم تضرب بالكسر العائم: ٠٫١٠٠ و٠٫١٠١ — بيستان للبوتيك فوق الحساب الصحيح.
        // لا يُصلَح هنا (تغييرٌ في مبلغ الورقة) — يُعرض فرقًا ويُرفع قرارًا
        $sheet = Boutiques::statement($this->shop->id, $this->reem->id, self::PERIOD);
        $this->assertEquals(10000.201, $sheet['commission'], 'إن تغيّر هذا فقد أُصلحت الورقة — حدِّث الاختبار والتقرير');
        $this->assertEquals(0.002, $reem['rounding']['sheet_difference']);
        $this->assertEquals(round($sheet['net'], 3), $reem['rounding']['sheet_pending_net']);

        // والفاتورةُ: الأنصبةُ بالبيسة تجمع إلى البنود بلا كسر
        $inv = $this->invoice(Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail());
        $owed = collect($inv['shares']['boutiques'])->sum(fn ($p) => (int) round($p['owed'] * 1000));
        $this->assertSame((int) round($inv['shares']['lines_total'] * 1000), $owed + (int) round($inv['shares']['shop'] * 1000));
        $this->assertEquals(0.001 + 0.101 + 0.102 + 10000.0, $inv['totals']['commission']);
        $this->assertTrue($inv['shares']['reconciles']);
    }

    public function test_an_old_line_without_a_rate_has_no_commission_and_the_rate_is_the_lines_own(): void
    {
        $order = $this->sell([$this->line($this->silver)]);
        OrderItem::where('order_id', $order->id)->update(['boutique_rate' => null]);
        $this->lama->update(['commission_rate' => 50]);

        $r = $this->rows()['rows'][0];
        $this->assertEquals(0, $r['commission']);
        $this->assertEquals(6, $r['net']);
        $this->assertEquals(0, $this->summary()['standalone']['commission'], 'نسبةُ اليوم لا تُسقَط على بيعٍ مضى');
    }

    /* ═══════════ ٩ · المرشّحات ═══════════ */

    public function test_filters_type_search_and_month_narrow_on_the_server(): void
    {
        $a = $this->sell([$this->line($this->silver)]);
        $this->sell([$this->line($this->set)]);
        $this->sell([$this->line($this->plain, 1, addonQty: 1)]);

        $this->assertSame(['standalone'], collect($this->rows(['type' => 'standalone'])['rows'])->pluck('type')->all());
        $this->assertSame(['bundle'], collect($this->rows(['type' => 'bundle'])['rows'])->pluck('type')->all());
        $this->assertSame(['addon'], collect($this->rows(['type' => 'addon'])['rows'])->pluck('type')->all());
        $this->assertSame([$a->number], collect($this->rows(['q' => $a->number])['rows'])->pluck('order_number')->all());
        $this->assertSame(['bundle'], collect($this->rows(['q' => 'طقم الفضّة'])['rows'])->pluck('type')->all());
        $this->assertCount(0, $this->rows(['period' => '2027-01'])['rows']);
        $this->assertCount(3, $this->rows(['type' => 'nonsense', 'settle' => 'nonsense'])['rows'], 'ما لا يصحّ يُقرأ «الكلّ»');
    }

    /* ═══════════ ١٠ · العزل والصلاحيّة ═══════════ */

    public function test_another_shops_boutique_and_invoice_are_never_read(): void
    {
        [$other, $otherOwner] = $this->shopWith('متجر آخر', 'other@abaad.om');
        $otherBoutique = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيك الجار', 'commission_rate' => 15, 'active' => true]);
        $otherPiece = $this->product('قطعة الجار', 10, boutique: $otherBoutique->id, shop: $other);
        $theirs = $this->sell([['id' => $otherPiece->id, 'name' => 'قطعة الجار', 'qty' => 1, 'price' => 10]], as: $otherOwner);

        $this->sell([$this->line($this->silver)]);

        // بوتيكُ الجار في الرابط يُقرأ «الكلّ» — لا يُعرف منه أنّه موجود
        $rows = collect($this->rows(['boutique' => (string) $otherBoutique->id])['rows']);
        $this->assertSame(['بوتيك لمى'], $rows->pluck('boutique')->unique()->values()->all());
        $this->assertNotContains('قطعة الجار', $rows->pluck('product')->all());
        $this->assertEquals(6, $this->summary(['boutique' => (string) $otherBoutique->id])['standalone']['gross']);

        $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $theirs->id))->assertNotFound();

        // وفاتورةُ المتجر نفسِه بلا نصيبٍ لبوتيك لا تُقرأ من هذا الباب
        $plain = $this->sell([['id' => $this->plain->id, 'name' => 'باقة بسيطة', 'qty' => 1, 'price' => 20]]);
        $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $plain->id))->assertNotFound();

        // ورقمُ بوتيك الجار في رابط الشاشة نفسها لا يفتحها — ولا رقمٌ لا وجود له، ولا نصٌّ مكانه
        $this->actingAs($this->owner)->get(route('admin.boutiques.show', $otherBoutique->id))->assertNotFound();
        $this->actingAs($this->owner)->getJson('/admin/products/boutiques/sales/orders/'.($theirs->id + 100000))->assertNotFound();
        $this->actingAs($this->owner)->getJson('/admin/products/boutiques/sales/orders/abc')->assertNotFound();
        $this->assertSame(1, $this->rows(['boutique' => 'abc'])['total'], 'معرّفٌ غير رقميّ يُقرأ «الكلّ» من متجره');

        // والجارُ لا يرى من متجر سعود شيئًا بالعكس
        $mine = Order::where('business_id', $this->shop->id)->firstOrFail();
        $this->actingAs($otherOwner)->getJson(route('admin.boutiques.sales.order', $mine->id))->assertNotFound();
        $this->actingAs($otherOwner)->get(route('admin.boutiques.show', $this->lama->id))->assertNotFound();
        $seen = $this->actingAs($otherOwner)->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD, 'boutique' => (string) $this->lama->id]))->assertOk()->json();
        $this->assertSame(['قطعة الجار'], collect($seen['rows'])->pluck('product')->all());
    }

    public function test_the_doors_answer_only_to_who_may_open_the_boutiques_screen(): void
    {
        $this->sell([$this->line($this->silver)]);
        $cashier = User::create(['business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@abaad.om', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['pos']]);
        $order = Order::where('business_id', $this->shop->id)->firstOrFail();

        foreach ($this->doors($order) as $url) {
            $res = $this->actingAs($cashier)->getJson($url);
            $this->assertContains($res->status(), [302, 403], 'بابٌ ماليٌّ مفتوحٌ لمن لا يملك قسم المنتجات: '.$url);
        }

        // ومتجرٌ لا يؤوي بوتيكات لا يرى الشاشةَ أصلًا
        [$bare, $bareOwner] = $this->shopWith('متجر بلا بوتيكات', 'bare@abaad.om');
        $bare->forceFill(['boutiques_enabled' => false])->save();
        $this->actingAs($bareOwner)->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD]))->assertNotFound();

        $this->actingAs($this->owner)->get(route('admin.boutiques.show', $this->lama->id))->assertOk();
    }

    public function test_only_the_owner_and_who_he_trusted_with_money_read_the_section(): void
    {
        $order = $this->sell([$this->line($this->silver)]);
        $staff = fn (string $email, string $role, ?array $permissions) => User::create([
            'business_id' => $this->shop->id, 'name' => $email, 'email' => $email, 'password' => bcrypt('x'),
            'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);

        $refused = [
            // يفتح شاشةَ البوتيك بالمنتجات ليُدخل أصنافه — ولا يقرأ عمولةً ولا مستحقًّا
            'موظفُ مبيعاتٍ بدوره' => $staff('sales@abaad.om', 'sales', null),
            'صاحبُ بوتيكٍ أُعطي حسابًا لأصنافه' => $staff('lama@abaad.om', 'sales', ['dashboard', 'products']),
            'محاسبٌ لا يفتح المنتجات' => $staff('accountant@abaad.om', 'accountant', null),
        ];
        foreach ($refused as $who => $user) {
            foreach ($this->doors($order) as $url) {
                $this->assertContains($this->actingAs($user)->getJson($url)->status(), [302, 403], $who.' قرأ '.$url);
            }
        }
        foreach (['موظفُ مبيعاتٍ بدوره', 'صاحبُ بوتيكٍ أُعطي حسابًا لأصنافه'] as $who) {
            $this->actingAs($refused[$who])->get(route('admin.boutiques.show', $this->lama->id))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('canSeeSales', false)->where('boutiqueOptions', []));
        }

        $allowed = [
            'المالك' => $this->owner,
            'المدير' => $staff('manager@abaad.om', 'manager', null),
            'موظفٌ أذن له المالكُ بالمصروفات' => $staff('finance@abaad.om', 'sales', ['dashboard', 'products', 'expenses']),
        ];
        foreach ($allowed as $who => $user) {
            foreach ($this->doors($order) as $url) {
                $this->assertSame(200, $this->actingAs($user)->getJson($url)->status(), $who.' رُدّ عن '.$url);
            }
            $this->actingAs($user)->get(route('admin.boutiques.show', $this->lama->id))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('canSeeSales', true)->has('boutiqueOptions', 2));
        }

        // ومالكُ متجرٍ آخر — ولو كان صاحبَ بوتيكٍ هنا — لا يقرأ فاتورةً من هذا المتجر
        [, $stranger] = $this->shopWith('متجر لمى', 'lama.shop@abaad.om');
        $this->actingAs($stranger)->getJson(route('admin.boutiques.sales.order', $order->id))->assertNotFound();
        $this->assertSame(0, $this->actingAs($stranger)->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD]))->assertOk()->json('total'));
    }

    /** @return list<string> */
    private function doors(Order $order): array
    {
        return [
            route('admin.boutiques.sales', ['period' => self::PERIOD]),
            route('admin.boutiques.sales.summary', ['period' => self::PERIOD]),
            route('admin.boutiques.sales.order', $order->id),
        ];
    }

    /* ═══════════ ١١ · قراءةٌ لا كتابة ═══════════ */

    public function test_the_doors_write_nothing(): void
    {
        $order = $this->sell([$this->line($this->silver), $this->line($this->set)]);
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) && ! str_contains($q->sql, 'sessions') && ! str_contains($q->sql, '"cache')) {
                $writes[] = $q->sql;
            }
        });

        $this->rows();
        $this->summary();
        $this->invoice($order);

        $this->assertSame([], $writes, 'بابُ قراءةٍ كتب في القاعدة');
        $this->assertSame(0, BoutiqueSettlement::count());
        $this->assertSame(1, BoutiqueComponentDue::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_filters_search_and_pages_never_change_the_total(): void
    {
        foreach (range(1, 10) as $_) {
            $this->sell([$this->line($this->silver)]);
            $this->sell([$this->line($this->set)]);
            $this->sell([$this->line($this->plain, 1, 1)]);
        }

        $base = $this->summary();
        foreach ([['type' => 'standalone'], ['type' => 'bundle'], ['type' => 'addon'], ['settle' => 'pending'], ['settle' => 'paid'],
            ['q' => 'فضّة'], ['q' => 'لا شيء بهذا الاسم'], ['page' => '2']] as $params) {
            $this->assertEquals($base, $this->summary($params), 'الملخّصُ تغيّر بـ'.json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        // ومشيُ الصفحات كلّها: كلُّ صفٍّ مرّةً واحدة، ومجموعُها مجموعُ الملخّص
        $first = $this->rows();
        $this->assertSame(30, $first['total']);
        $this->assertSame(2, $first['last_page']);
        $keys = [];
        $amount = 0.0;
        $byType = ['standalone' => 0.0, 'bundle' => 0.0, 'addon' => 0.0];
        foreach (range(1, $first['last_page']) as $n) {
            foreach ($this->rows(['page' => (string) $n])['rows'] as $r) {
                $keys[] = $r['key'];
                $amount += $r['amount'];
                $byType[$r['type']] += $r['amount'];
            }
        }
        $this->assertCount(30, $keys);
        $this->assertCount(30, array_unique($keys), 'صفٌّ في صفحتين');
        $this->assertEquals(round($base['standalone']['gross'] + $base['components']['due'], 3), round($amount, 3));
        $this->assertEquals($base['standalone']['gross'], round($byType['standalone'], 3));
        $this->assertEquals($base['components']['bundle'], round($byType['bundle'], 3));
        $this->assertEquals($base['components']['addon'], round($byType['addon'], 3));

        // والمُرشَّحُ يقصّ الصفوف ولا يقصّ الملخّص
        $this->assertSame(10, $this->rows(['type' => 'standalone'])['total']);
        $this->assertSame(30, $this->rows(['q' => 'فضّة'])['total'], 'القطعةُ باسمها: منفردةً وفي الطقم وإضافةً');
        $this->assertSame(10, $this->rows(['q' => 'طقم'])['total'], 'وباسم المنتج الذي دخلته');
    }

    /* ═══════════ ١٢ · الأداء ═══════════ */

    public function test_thousands_of_invoices_are_read_a_page_at_a_time_with_a_fixed_number_of_queries(): void
    {
        $branch = Branch::where('business_id', $this->shop->id)->value('id');
        $now = now();
        $orders = [];
        foreach (range(1, 3000) as $i) {
            $orders[] = ['business_id' => $this->shop->id, 'branch_id' => $branch, 'number' => 'BULK-'.$i, 'status' => 'مكتمل', 'is_held' => false,
                'subtotal' => 6, 'total' => 6, 'ordered_at' => Carbon::parse('2027-02-01 08:00:00')->addMinutes($i), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        $ids = DB::table('orders')->where('business_id', $this->shop->id)->orderBy('id')->pluck('id');
        $items = [];
        $dues = [];
        foreach ($ids as $n => $id) {
            $piece = $n % 2 === 0;
            $items[] = ['order_id' => $id, 'product_id' => $piece ? $this->silver->id : $this->set->id, 'name' => $piece ? 'قطعة فضّة' : 'طقم الفضّة',
                'price' => $piece ? 6 : 20, 'quantity' => 1, 'total' => $piece ? 6 : 20,
                'boutique_id' => $piece ? $this->lama->id : null, 'boutique_name' => $piece ? 'بوتيك لمى' : null, 'boutique_rate' => $piece ? 20 : null,
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($items, 500) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        $sets = DB::table('order_items')->whereIn('order_id', $ids)->whereNull('boutique_id')->get(['id', 'order_id']);
        foreach ($sets as $s) {
            $dues[] = ['business_id' => $this->shop->id, 'order_id' => $s->order_id, 'order_item_id' => $s->id, 'boutique_id' => $this->lama->id,
                'boutique_name' => 'بوتيك لمى', 'source' => BoutiqueComponentDue::RECIPE, 'kind' => BoutiqueComponentDue::DUE,
                'product_id' => $this->silver->id, 'product_name' => 'قطعة فضّة', 'unit_price' => 6, 'unit_quantity' => 1, 'quantity' => 1, 'amount' => 6,
                'live_key' => 'bulk'.$s->id, 'occurred_at' => '2027-02-05 10:00:00', 'period_at' => '2027-02-05 10:00:00', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($dues, 500) as $chunk) {
            DB::table('boutique_component_dues')->insert($chunk);
        }

        $this->actingAs($this->owner);
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $t = microtime(true);
        $page = $this->rows();
        $rowsTime = microtime(true) - $t;
        $rowsQueries = $count;

        $this->assertSame(3000, $page['total']);
        $this->assertCount(25, $page['rows']);
        $this->assertSame(120, $page['last_page']);
        $this->assertSame('BULK-3000', $page['rows'][0]['order_number'], 'الأحدثُ أوّلًا');

        $count = 0;
        $last = $this->rows(['page' => '120']);
        $this->assertCount(25, $last['rows']);
        $this->assertLessThanOrEqual($rowsQueries, $count, 'الصفحةُ الأخيرة بعدد استعلامات الأولى');
        $this->assertLessThan(20, $rowsQueries, 'استعلامٌ لكلّ صفّ؟');

        $count = 0;
        $t = microtime(true);
        $s = $this->summary();
        $summaryTime = microtime(true) - $t;
        $this->assertEquals(9000, $s['standalone']['gross']);
        $this->assertEquals(9000, $s['components']['due']);
        $this->assertEquals(30000, $s['bundles']['revenue']);
        $this->assertEquals(21000, $s['bundles']['shop_remaining']);
        $this->assertLessThan(60, $count);
        $this->assertEquals($s, $this->summary(['type' => 'bundle', 'q' => 'BULK-1', 'page' => '7']), 'المُرشِّحُ والصفحةُ لا يغيّران الإجمالي');

        // سقفٌ واسع يكشف الانهيار لا التذبذب — والأرقامُ الفعليّة تُطبع في التقرير
        $this->assertLessThan(5.0, $rowsTime, 'صفحةُ الفواتير بطيئة');
        $this->assertLessThan(10.0, $summaryTime, 'الملخّصُ بطيء');
        fwrite(STDERR, sprintf("\n[perf] 3000 invoices: page %.0f ms (%d queries), summary %.0f ms\n", $rowsTime * 1000, $rowsQueries, $summaryTime * 1000));
    }
}
