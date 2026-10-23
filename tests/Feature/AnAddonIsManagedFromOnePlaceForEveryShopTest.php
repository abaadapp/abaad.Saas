<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\ProductAddons;
use App\Support\SaleLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * نظامُ إضافاتٍ واحد لكلّ متجر — يُدار من صفحته ومن شاشة المنتج.
 *
 * ═══ ما يُحرس ═══
 *
 *   - متجرٌ بلا بوتيكات يرى إضافاتِه كلَّها — ولا يرى إضافةَ متجرٍ آخر.
 *   - الإيقافُ عمودٌ واحد: لا يمحو مدى الإضافة، ويردّها الخادمُ عند البيع.
 *   - منتجٌ يدخل مدى إضافةٍ مخصّصة ويخرج منه: صفٌّ واحدٌ مهما تكرّر الطلب،
 *     والإضافةُ باقيةٌ لمنتجاتها الأخرى. والعامّةُ والمملوكةُ لا تُربطان ولا
 *     تُفكّان من منتج.
 *   - شاشةُ المنتج تقرأ ما ينطبق عليه بقاعدة البيع نفسِها.
 *   - قطعةُ البوتيك لا تُربط إلّا حيث يُكتب مستحقُّها، وقطعًا كاملة. وتُباع
 *     بسعرٍ يحدّده المتجر، ومستحقُّها ثمنُ القطعة × عددها — مرّةً واحدة،
 *     والرفُّ ينقص مرّةً واحدة، ولقطةُ الطلب لا يمسّها تغييرٌ بعده.
 *
 * والنافذةُ والقائمةُ وقسمُ المنتج: `an-addon-is-managed-from-one-place.test.tsx`.
 */
class AnAddonIsManagedFromOnePlaceForEveryShopTest extends TestCase
{
    use RefreshDatabase;

    private Business $plain;

    private User $plainOwner;

    private Business $ribbon;

    private User $ribbonOwner;

    private Boutique $lama;

    private Product $silver;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        app()->setLocale('ar');

        // قوائمُ المتاجر بمعرّفاتها — لا يصير متجرُ الاختبار «سعود» بتسلسل PostgreSQL
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        [$this->plain, $this->plainOwner] = $this->shop('محلّ عادي', 'plain@abaad.om');
        [$this->ribbon, $this->ribbonOwner] = $this->shop('RIBBON', 'ribbon@abaad.om', [
            'boutiques_enabled' => true, 'boutique_components_enabled' => true,
        ]);

        $this->lama = Boutique::create(['business_id' => $this->ribbon->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->silver = $this->product($this->ribbon, 'قطعة فضّة', 7, boutique: $this->lama->id, qty: 10);
        $this->bouquet = $this->product($this->ribbon, 'باقة بسيطة', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $email, array $over = []): array
    {
        $shop = Business::create($over + ['name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'city' => 'مسقط']);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الفرع']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function product(Business $shop, string $name, float $price, ?int $boutique = null, int $qty = 100): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'price' => $price, 'cost' => 0,
            'quantity' => $qty, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    private function addon(Business $shop, string $name, array $over = []): Addon
    {
        return Addon::create($over + ['business_id' => $shop->id, 'name' => $name, 'price' => 1, 'active' => true]);
    }

    private function select(Addon $addon, Product ...$products): void
    {
        $addon->update(['scope' => Addon::SCOPE_SELECTED]);
        foreach ($products as $i => $p) {
            DB::table('product_addons')->insert([
                'business_id' => $addon->business_id, 'product_id' => $p->id, 'addon_id' => $addon->id,
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function sellAtTheTill(User $owner, Product $p, Addon $addon, int $addonQty = 1)
    {
        return $this->actingAs($owner)->postJson('/pos/checkout', [
            'items' => [[
                'id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => (float) $p->price,
                'addons' => [['addon_id' => $addon->id, 'qty' => $addonQty]],
            ]],
            'payment_method' => 'نقدي',
        ]);
    }

    private function rows(Addon $addon): int
    {
        return DB::table('product_addons')->where('addon_id', $addon->id)->count();
    }

    /* ═══════════ متجرٌ بلا بوتيكات ═══════════ */

    public function test_a_shop_without_boutiques_lists_every_addon_of_its_own(): void
    {
        $roses = $this->product($this->plain, 'ورد', 10);
        $this->actingAs($this->plainOwner)->postJson(route('admin.products.addons.store'), [
            'name' => 'تغليف', 'price' => 1.5, 'pos_enabled' => true, 'web_enabled' => false,
        ])->assertOk();
        $this->actingAs($this->plainOwner)->postJson(route('admin.products.addons.store'), [
            'name' => 'بطاقة', 'price' => 0.5, 'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$roses->id],
            'pos_enabled' => true, 'web_enabled' => true, 'active' => false,
        ])->assertOk();
        $this->addon($this->ribbon, 'إضافة الجار');

        $this->actingAs($this->plainOwner)->get(route('admin.addons.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Addons/Index')
                ->has('addons', 2)
                ->where('addons.0.label', 'تغليف')
                ->where('addons.0.scope', 'all')
                ->where('addons.0.source', 'shop')
                ->where('addons.0.active', true)
                ->where('addons.1.label', 'بطاقة')
                ->where('addons.1.scope', 'selected')
                ->where('addons.1.product_ids', [$roses->id])
                ->where('addons.1.web_enabled', true)
                ->where('addons.1.active', false, 'أُنشئت موقوفةً كما طُلب')
                ->where('boutiqueLinks.hosts', false)
                ->where('boutiques', []));
    }

    /* ═══════════ الإيقاف ═══════════ */

    public function test_pausing_keeps_the_scope_and_the_server_refuses_the_sale(): void
    {
        $other = $this->product($this->ribbon, 'باقة ثانية', 15);
        $card = $this->addon($this->ribbon, 'بطاقة إهداء', ['price' => 0.5]);
        $this->select($card, $this->bouquet, $other);

        $this->actingAs($this->ribbonOwner)->putJson(route('admin.addons.active', $card->id), ['active' => false])
            ->assertOk()->assertJsonPath('addon.active', false)->assertJsonPath('addon.scope', 'selected');
        $this->assertSame(Addon::SCOPE_SELECTED, $card->fresh()->scope);
        $this->assertSame(2, $this->rows($card), 'الإيقافُ محا منتجات الإضافة');

        // لا يكفي إخفاؤها: الخادمُ يردّها ولو حملها الطلب
        $this->sellAtTheTill($this->ribbonOwner, $this->bouquet, $card)->assertStatus(422);
        $this->assertSame(0, Order::where('business_id', $this->ribbon->id)->count());

        $this->actingAs($this->ribbonOwner)->putJson(route('admin.addons.active', $card->id), ['active' => true])->assertOk();
        $this->sellAtTheTill($this->ribbonOwner, $this->bouquet, $card)->assertOk();

        // وإضافةُ متجرٍ آخر لا تُمسّ
        $theirs = $this->addon($this->plain, 'إضافة الجار');
        $this->actingAs($this->ribbonOwner)->putJson(route('admin.addons.active', $theirs->id), ['active' => false])->assertNotFound();
        $this->assertTrue($theirs->fresh()->active);
    }

    /* ═══════════ الربط من شاشة المنتج ═══════════ */

    public function test_a_product_joins_and_leaves_a_specific_addon_without_deleting_it(): void
    {
        $other = $this->product($this->ribbon, 'باقة ثانية', 15);
        $wrap = $this->addon($this->ribbon, 'تغليف فاخر', ['price' => 2]);
        $this->select($wrap, $other);

        $link = fn () => $this->actingAs($this->ribbonOwner)
            ->putJson(route('admin.products.addons.link', [$this->bouquet->id, $wrap->id]));

        $link()->assertOk()->assertJsonPath('addon.product_ids', [$other->id, $this->bouquet->id]);
        $link()->assertOk();
        $this->assertSame(2, $this->rows($wrap), 'ضغطتان كتبتا صفّين');
        $this->assertTrue(ProductAddons::for($this->bouquet->fresh(), channel: SaleLines::POS)->pluck('id')->contains($wrap->id));

        $this->actingAs($this->ribbonOwner)->deleteJson(route('admin.products.addons.unlink', [$this->bouquet->id, $wrap->id]))
            ->assertOk()->assertJsonPath('addon.product_ids', [$other->id]);
        $this->assertNotNull($wrap->fresh(), 'فكُّ الربط حذف الإضافة');
        $this->assertSame(1, $this->rows($wrap));
        $this->assertFalse(ProductAddons::for($this->bouquet->fresh(), channel: SaleLines::POS)->pluck('id')->contains($wrap->id));
        $this->sellAtTheTill($this->ribbonOwner, $this->bouquet, $wrap)->assertStatus(422);
    }

    public function test_general_and_owned_addons_are_not_linked_or_unlinked_from_a_product(): void
    {
        $general = $this->addon($this->ribbon, 'تغليف');
        $owned = $this->addon($this->ribbon, 'شريط', ['product_id' => $this->bouquet->id]);

        $this->actingAs($this->ribbonOwner)->putJson(route('admin.products.addons.link', [$this->bouquet->id, $general->id]))->assertStatus(422);
        $this->actingAs($this->ribbonOwner)->deleteJson(route('admin.products.addons.unlink', [$this->bouquet->id, $general->id]))->assertStatus(422);
        $this->actingAs($this->ribbonOwner)->deleteJson(route('admin.products.addons.unlink', [$this->bouquet->id, $owned->id]))->assertStatus(422);
        $this->assertSame(0, $this->rows($general));
        $this->assertNull($general->fresh()->scope, 'العامّةُ صارت مخصّصة');

        // ولا منتجَ ولا إضافةَ من متجرٍ آخر
        $theirProduct = $this->product($this->plain, 'منتج الجار', 5);
        $theirAddon = $this->addon($this->plain, 'إضافة الجار', ['scope' => Addon::SCOPE_SELECTED]);
        $mine = $this->addon($this->ribbon, 'مخصّصة', ['scope' => Addon::SCOPE_SELECTED]);
        $this->actingAs($this->ribbonOwner)->putJson(route('admin.products.addons.link', [$theirProduct->id, $mine->id]))->assertNotFound();
        $this->actingAs($this->ribbonOwner)->putJson(route('admin.products.addons.link', [$this->bouquet->id, $theirAddon->id]))->assertNotFound();
        $this->assertSame(0, DB::table('product_addons')->count());
    }

    public function test_the_product_screen_reads_what_applies_by_the_sale_rule(): void
    {
        $general = $this->addon($this->ribbon, 'تغليف');
        $older = $this->addon($this->ribbon, 'بطاقة');
        $specific = $this->addon($this->ribbon, 'شوكولاتة');
        $this->select($specific, $this->bouquet);

        // بلا قائمةٍ قديمة: العامّتان معه
        $this->actingAs($this->ribbonOwner)->get(route('admin.products.edit', $this->bouquet->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('composition.legacy_addon_ids', null)
                ->where('addonBoutiqueLinks.allowed', true));

        // وقائمةٌ قديمةٌ فيها «تغليف» وحدها تستثني «بطاقة» — في الشاشة كما في البيع
        DB::table('product_addons')->insert(['business_id' => $this->ribbon->id, 'product_id' => $this->bouquet->id, 'addon_id' => $general->id, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->ribbonOwner)->get(route('admin.products.edit', $this->bouquet->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('composition.legacy_addon_ids', [$general->id]));

        $sold = ProductAddons::for($this->bouquet->fresh())->pluck('id')->all();
        $this->assertContains($general->id, $sold);
        $this->assertContains($specific->id, $sold);
        $this->assertNotContains($older->id, $sold);
        // وكلُّ إضافةٍ مرّةً واحدة — ولو كان للعامّة صفٌّ مع المنتج
        $this->assertSame(count($sold), count(array_unique($sold)));
    }

    /* ═══════════ قطعةُ البوتيك ═══════════ */

    public function test_a_boutique_piece_becomes_an_addon_only_where_its_dues_are_kept(): void
    {
        $piece = ['name' => 'فضّة اختياريّة', 'price' => 9, 'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 2];

        // السجلُّ مطفأ: يُردّ — ويقول الخادمُ للشاشة لماذا
        $this->ribbon->update(['boutique_components_enabled' => false]);
        $this->actingAs($this->ribbonOwner)->postJson(route('admin.products.addons.store'), $piece)
            ->assertStatus(422)->assertJsonValidationErrors('inventory_product_id');
        $this->actingAs($this->ribbonOwner)->get(route('admin.addons.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('boutiqueLinks.hosts', true)
                ->where('boutiqueLinks.allowed', false)
                ->where('boutiqueLinks.reason', fn ($r) => str_contains((string) $r, 'تفعيل'))
                ->where('boutiques.0.label', 'بوتيك لمى'));

        // ومتجرٌ يحتسب الضريبة كذلك
        $this->ribbon->update(['boutique_components_enabled' => true]);
        Setting::where('business_id', $this->ribbon->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        $this->actingAs($this->ribbonOwner)->postJson(route('admin.products.addons.store'), $piece)->assertStatus(422);
        Setting::where('business_id', $this->ribbon->id)->where('key', 'vat_enabled')->update(['value' => '0']);

        // ونصفُ قطعة لا مستحقَّ له — يُردّ عند الحفظ لا عند كلّ بيعة
        $this->actingAs($this->ribbonOwner)->postJson(route('admin.products.addons.store'), ['inventory_quantity' => 1.5] + $piece)
            ->assertStatus(422)->assertJsonValidationErrors('inventory_quantity');

        // مفتوحٌ بلا ضريبة: يُربط، والمستحقُّ ثمنُ القطعة × عددها لا سعرُ الزبون
        $this->actingAs($this->ribbonOwner)->postJson(route('admin.products.addons.store'), $piece)
            ->assertOk()
            ->assertJsonPath('addon.source', 'boutique')
            ->assertJsonPath('addon.boutique_name', 'بوتيك لمى')
            ->assertJsonPath('addon.stock_name', 'قطعة فضّة')
            ->assertJsonPath('addon.boutique_due', 14)
            ->assertJsonPath('addon.price', 9);

        // وصنفُ بوتيكٍ من متجرٍ آخر لا يُربط
        $theirs = $this->product($this->plain, 'قطعة الجار', 5);
        $this->actingAs($this->ribbonOwner)->postJson(route('admin.products.addons.store'), ['name' => 'أخرى', 'inventory_product_id' => $theirs->id] + $piece)
            ->assertStatus(422)->assertJsonValidationErrors('inventory_product_id');
    }

    public function test_a_boutique_addon_owes_its_piece_price_once_and_keeps_its_snapshot(): void
    {
        $silver = Addon::create([
            'business_id' => $this->ribbon->id, 'name' => 'فضّة اختياريّة', 'price' => 9, 'active' => true,
            'inventory_product_id' => $this->silver->id, 'inventory_quantity' => 2,
        ]);

        $this->sellAtTheTill($this->ribbonOwner, $this->bouquet, $silver)->assertOk();
        $order = Order::where('business_id', $this->ribbon->id)->latest('id')->firstOrFail();

        // الزبونُ دفع ٢٠ + ٩، وللبوتيك ١٤ = قطعتان × ٧ — صفٌّ واحد
        $this->assertSame(29.0, (float) $order->total);
        $dues = BoutiqueComponentDue::where('order_id', $order->id)->get();
        $this->assertCount(1, $dues);
        $this->assertSame(14.0, (float) $dues->first()->amount);
        $this->assertSame((int) $this->lama->id, (int) $dues->first()->boutique_id);
        $this->assertSame(14.0, Ledger::balance($this->ribbon->id, 'boutique_payable'));
        // والرفُّ نقص قطعتين — مرّةً واحدة
        $this->assertSame(8, (int) $this->silver->fresh()->quantity);

        // وتغييرُ السعرين والمدى بعد البيع لا يمسّ ما بِيع
        $silver->update(['price' => 12, 'scope' => Addon::SCOPE_SELECTED]);
        $this->silver->update(['price' => 8]);
        $row = OrderItem::where('order_id', $order->id)->firstOrFail()->addons()->firstOrFail();
        $this->assertSame(9.0, (float) $row->unit_price);
        $this->assertSame(14.0, (float) $row->cost, 'تكلفةُ الإضافة مستحقُّ البوتيك — مرّةً واحدة');
        $this->assertSame(14.0, (float) BoutiqueComponentDue::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(29.0, (float) $order->fresh()->total);
    }
}
