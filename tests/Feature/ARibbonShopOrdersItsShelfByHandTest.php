<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\ShelfOrder;
use App\Support\Store\StoreCollections;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * صاحبُ المتجر يرتّب رفَّه بيده — «كل المنتجات» وكلُّ فئةٍ على حدة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لمن في `storefront.ribbon_shelf_order_businesses` وحده؛ ومن ليس فيها
 *     يبقى رفُّه بالاسم ولو كُتب المفتاحُ في جدوله، ويُردّ حفظُه بـ403.
 *   - القائمتان منفصلتان: ترتيبُ الفئة لا يُبدّل «كل المنتجات»، ولا العكس.
 *   - الجديدُ آخرًا بترتيب إضافته، والمخفيُّ يعود إلى موضعه، والمنقولُ بين
 *     الفئات يقع آخرَ فئته الجديدة.
 *   - «الأكثر مبيعًا» والمجموعاتُ بترتيبهما، والبحثُ يحفظ الترتيب.
 *   - والحفظُ لا يأتمن الشاشة: أصنافُ متجره وفئاتُه وحدها.
 *   - ومسوّدةٌ تُنشر — والمحرّرُ يفتح عليها.
 *   - ولا يمسّ المنتجَ نفسَه ولا نقطةَ البيع.
 *
 * والمتجران عامّان — يُكتب معرّفُ أحدهما في الإعداد، ولا يُفرض المعرّف ٥.
 * وسلوكُ اللوحة في `tests/js/the-owner-orders-the-shelf-by-hand.test.tsx`.
 */
class ARibbonShopOrdersItsShelfByHandTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $otherOwner;

    private Category $roses;

    private Category $gifts;

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'saud@abaad.om');
        [$this->other, $this->otherOwner] = $this->ribbon('other', 'other@abaad.om');

        $this->roses = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد']);
        $this->gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا']);

        /*
         * بالاسم: الأبيض، الأحمر، الورديّ، البطاقة — والأحمرُ ما يريده أوّلًا.
         *
         * وبأسماءٍ لاتينيّةٍ تختلف حروفُها الأولى: الأبجديّةُ ترتيبُ القاعدة
         * (`orderBy('name')`)، والعربيّةُ تُرتَّب في PostgreSQL بغير ما تُرتَّب
         * في SQLite («بطاقة» قبل «باقة») — والحارسُ يسأل عن الترتيب اليدويّ لا عن جدول الحروف.
         */
        $this->p['white'] = $this->product('Aster white bouquet', $this->roses)->id;
        $this->p['red'] = $this->product('Begonia red bouquet', $this->roses)->id;
        $this->p['pink'] = $this->product('Camellia pink bouquet', $this->roses)->id;
        $this->p['card'] = $this->product('Daisy gift card', $this->gifts)->id;

        // وكلُّ قوائم الإذن تبدأ فارغة — فلا يصير خامسُ متجرٍ يُنشأ «سعود» بالصدفة
        config([
            'storefront.ribbon_shelf_order_businesses' => [$this->shop->id],
            'storefront.ribbon_catalog_editor_businesses' => [],
            'storefront.ribbon_curated_new_arrivals_businesses' => [],
            'storefront.ribbon_picks_businesses' => [],
        ]);
    }

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        $owner = User::create(['business_id' => $shop->id, 'name' => 'مالك '.$slug, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function product(string $name, ?Category $category = null, array $over = [], ?Business $shop = null): Product
    {
        return Product::create($over + [
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'price' => 10, 'category_id' => $category?->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function save(array $order, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), [ShelfOrder::KEY => json_encode($order)]);
    }

    /** @return list<int> الرفُّ كما يراه الزائر */
    private function shelf(array $query = [], string $slug = 'ribbon'): array
    {
        auth()->logout();

        return array_column($this->get("/s/{$slug}/shop".($query ? '?'.http_build_query($query) : ''))->assertOk()->viewData('products'), 'id');
    }

    private function stored(?Business $shop = null): string
    {
        return (string) MarketingSettings::group(($shop ?? $this->shop)->id, 'website')[ShelfOrder::KEY];
    }

    private function ids(string ...$keys): array
    {
        return array_map(fn ($k) => $this->p[$k], $keys);
    }

    /* ═══════════ لمن ═══════════ */

    public function test_the_shipped_list_is_saud_alone_and_no_id_is_written_in_the_code(): void
    {
        $config = require config_path('storefront.php');
        $this->assertSame([5], $config['ribbon_shelf_order_businesses']);

        foreach ([
            app_path('Support/Store/ShelfOrder.php'),
            app_path('Http/Controllers/Store/RibbonController.php'),
            resource_path('js/Pages/Admin/Website/theme/ShelfOrderField.tsx'),
        ] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/(business_?[iI]d|bid|id)\s*(===?|!==?)\s*5\b|\[\s*5\s*\]/',
                file_get_contents($file),
                basename($file).' يسأل عن متجرٍ بمعرّفه',
            );
        }
    }

    public function test_nothing_moves_until_the_owner_orders_a_shelf(): void
    {
        $this->assertSame('', MarketingSettings::GROUPS['website'][ShelfOrder::KEY]);
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), $this->shelf(), 'الرفُّ ليس أبجديًّا كما كان');
        $this->assertSame($this->ids('white', 'red', 'pink'), $this->shelf(['cat' => $this->roses->id]));
    }

    public function test_a_shop_outside_the_list_keeps_its_alphabet_and_cannot_post(): void
    {
        $b = $this->product('ب', null, [], $this->other)->id;
        $a = $this->product('أ', null, [], $this->other)->id;

        // يُكتب في الجدول بلا بابِ الحفظ — ولا يُقرأ
        MarketingSettings::save($this->other->id, 'website', [ShelfOrder::KEY => json_encode(['all' => [$b, $a]])]);
        $this->assertSame([$a, $b], $this->shelf([], 'other'));

        $this->save(['all' => [$b, $a]], $this->otherOwner)->assertForbidden();
        $this->actingAs($this->otherOwner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), ['store_headline' => 'عنوان', ShelfOrder::KEY => ''])
            ->assertForbidden();

        $this->assertNull($this->actingAs($this->otherOwner)->get(route('admin.website.editor'))->viewData('page')['props']['shelfOrder']);
    }

    /* ═══════════ قائمتان منفصلتان ═══════════ */

    public function test_all_products_and_a_category_each_keep_their_own_order(): void
    {
        $this->save(['categories' => [(string) $this->roses->id => $this->ids('red', 'white', 'pink')]])->assertSessionHasNoErrors();

        $this->assertSame($this->ids('red', 'white', 'pink'), $this->shelf(['cat' => $this->roses->id]));
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), $this->shelf(), 'ترتيبُ الفئة بدّل «كل المنتجات»');

        $this->save([
            'all' => $this->ids('card', 'pink', 'red', 'white'),
            'categories' => [(string) $this->roses->id => $this->ids('red', 'white', 'pink')],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->ids('card', 'pink', 'red', 'white'), $this->shelf());
        $this->assertSame($this->ids('red', 'white', 'pink'), $this->shelf(['cat' => $this->roses->id]), 'ترتيبُ «كل المنتجات» بدّل الفئة');
        // والبحثُ داخلهما يحفظ الترتيب
        $this->assertSame($this->ids('pink', 'red', 'white'), $this->shelf(['q' => 'bouquet']));
    }

    public function test_a_new_product_lands_last_in_the_order_it_was_added(): void
    {
        $this->save(['all' => $this->ids('red', 'pink', 'white', 'card')]);

        $late = $this->product('أوركيد', $this->roses)->id;
        $later = $this->product('أزهار مجففة', $this->roses)->id;

        $this->assertSame([...$this->ids('red', 'pink', 'white', 'card'), $late, $later], $this->shelf());
    }

    public function test_a_hidden_product_keeps_its_place_and_returns_to_it(): void
    {
        $this->save(['all' => $this->ids('red', 'pink', 'white', 'card')]);

        Product::whereKey($this->p['pink'])->update(['published' => false]);
        $this->assertSame($this->ids('red', 'white', 'card'), $this->shelf());

        // اللوحةُ ترسل المخفيَّ في موضعه — والحفظُ لا يُسقطه
        $this->save(['all' => $this->ids('card', 'red', 'pink', 'white')])->assertSessionHasNoErrors();
        $this->assertSame($this->ids('card', 'red', 'pink', 'white'), ShelfOrder::fromJson($this->stored())['all']);

        Product::whereKey($this->p['pink'])->update(['published' => true]);
        $this->assertSame($this->ids('card', 'red', 'pink', 'white'), $this->shelf(), 'عاد المخفيُّ إلى غير موضعه');
    }

    public function test_a_product_moved_to_another_category_lands_last_there_and_leaves_the_old_list(): void
    {
        $this->save(['categories' => [
            (string) $this->roses->id => $this->ids('red', 'white', 'pink'),
            (string) $this->gifts->id => $this->ids('card'),
        ]]);

        Product::whereKey($this->p['red'])->update(['category_id' => $this->gifts->id]);

        $this->assertSame($this->ids('white', 'pink'), $this->shelf(['cat' => $this->roses->id]));
        $this->assertSame($this->ids('card', 'red'), $this->shelf(['cat' => $this->gifts->id]));

        // وقائمةٌ تحمل صنفًا ليس في فئتها لا تُكتب به
        $this->save(['categories' => [(string) $this->roses->id => $this->ids('red', 'pink', 'white')]])->assertSessionHasNoErrors();
        $this->assertSame([$this->roses->id => $this->ids('pink', 'white')], ShelfOrder::fromJson($this->stored())['categories']);
    }

    /* ═══════════ وما لا يُمسّ ═══════════ */

    public function test_best_sellers_and_collections_keep_their_own_order(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => json_encode([[
            'slug' => 'eid', 'title_ar' => 'العيد', 'title_en' => 'Eid', 'description_ar' => '', 'description_en' => '',
            'image' => '', 'visible' => true, 'products' => $this->ids('pink', 'white'),
        ]])]);
        $collection = $this->shelf(['collection' => 'eid']);
        $best = $this->shelf(['view' => 'best']);

        $this->save(['all' => $this->ids('white', 'pink', 'card', 'red')]);

        $this->assertSame($collection, $this->shelf(['collection' => 'eid']), 'تبدّل ترتيبُ المجموعة');
        $this->assertSame($this->ids('pink', 'white'), $collection);
        $this->assertSame($best, $this->shelf(['view' => 'best']), 'تبدّل «الأكثر مبيعًا»');
    }

    public function test_ordering_touches_no_product_row(): void
    {
        $before = DB::table('products')->where('business_id', $this->shop->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->save(['all' => $this->ids('card', 'pink', 'red', 'white')])->assertSessionHasNoErrors();

        $this->assertSame($before, DB::table('products')->where('business_id', $this->shop->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(0, Order::count());
    }

    /* ═══════════ والحفظُ لا يأتمن الشاشة ═══════════ */

    public function test_another_shops_products_and_categories_are_never_written(): void
    {
        $theirs = $this->product('صنفهم', null, [], $this->other)->id;
        $theirCat = Category::create(['business_id' => $this->other->id, 'name' => 'فئتهم']);

        $this->save([
            'all' => [$theirs, $this->p['red']],
            'categories' => [(string) $theirCat->id => [$theirs], (string) $this->roses->id => $this->ids('pink')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            json_encode(['all' => [$this->p['red']], 'categories' => [(string) $this->roses->id => [$this->p['pink']]]]),
            $this->stored(),
        );
    }

    public function test_what_is_not_a_list_of_positive_numbers_is_refused(): void
    {
        foreach (['{not json', '[1,2]', json_encode(['all' => 'red']), json_encode(['all' => [0]]),
            json_encode(['all' => ['7']]), json_encode(['categories' => ['x' => [1]]]),
            json_encode(['all' => range(1, ShelfOrder::MAX + 1)])] as $raw) {
            $this->actingAs($this->owner)->from(route('admin.website.editor'))
                ->post(route('admin.marketing.store.save'), [ShelfOrder::KEY => $raw])
                ->assertSessionHasErrors(ShelfOrder::KEY);
        }

        $this->assertSame('', $this->stored());
    }

    public function test_an_emptied_list_is_alphabetical_again(): void
    {
        $this->save(['all' => $this->ids('card', 'red')]);
        $this->save(['all' => []])->assertSessionHasNoErrors();

        $this->assertSame('', $this->stored());
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), $this->shelf());
    }

    /* ═══════════ مسوّدةٌ تُنشر ═══════════ */

    public function test_a_draft_order_waits_for_publish_and_the_editor_opens_on_it(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save(['all' => $this->ids('red', 'pink', 'white', 'card')])->assertSessionHasNoErrors();

        $seed = $this->actingAs($this->owner)->get(route('admin.website.editor'))->viewData('page')['props']['shelfOrder'];
        $this->assertSame(json_encode(['all' => $this->ids('red', 'pink', 'white', 'card')]), $seed['value'], 'فُتحت اللوحةُ على المنشور');
        $this->assertSame($this->ids('white', 'red', 'pink', 'card'), $this->shelf(), 'وصل الترتيبُ إلى الزائر قبل النشر');

        $this->actingAs($this->owner)->from(route('admin.website.editor'))->post(route('admin.website.store.publish'))->assertSessionHasNoErrors();

        $this->assertSame($this->ids('red', 'pink', 'white', 'card'), $this->shelf());
    }

    public function test_the_editor_lists_the_shelf_alphabetically_with_the_hidden_that_hold_a_place(): void
    {
        $placedHidden = $this->product('Bluebell hidden bouquet', $this->roses, ['published' => false])->id;
        $this->product('صنف مطفأ بلا موضع', $this->roses, ['active' => false]);
        $this->product('صنفهم', null, [], $this->other);
        MarketingSettings::save($this->shop->id, 'website', [ShelfOrder::KEY => json_encode(['all' => [$placedHidden]])]);

        $seed = $this->actingAs($this->owner)->get(route('admin.website.editor'))->viewData('page')['props']['shelfOrder'];

        // بترتيب الرفّ الأبجديّ نفسِه — والمخفيُّ في موضعه منه
        $this->assertSame(
            [...$this->ids('white', 'red'), $placedHidden, ...$this->ids('pink', 'card')],
            array_column($seed['products'], 'id'),
        );
        $this->assertFalse($seed['products'][2]['shown']);
        $this->assertSame($this->roses->id, $seed['products'][2]['category_id']);
        $this->assertTrue($seed['products'][0]['shown']);
    }
}
