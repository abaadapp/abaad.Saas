<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\PageEditor;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreHeader;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صفُّ خيارات المتجر بأقسام صاحبه وترتيبه — بجانب «كل المنتجات» و«الأكثر مبيعًا».
 *
 * ═══ وما يُحرَس ═══
 *
 *  • مجموعاتٌ كثيرةٌ تظهر في الصفّ بعد الخيارين الأساسيّين — ومتجرٌ لم يكتب
 *    شيئًا صفُّه كما كان حرفًا بحرف.
 *  • وصاحبُ المتجر يرتّب الصفَّ كلَّه: الأساسيّين والمجموعاتِ والفئات، مثل
 *    «العروض | كل المنتجات | هدايا التخرّج | الأكثر مبيعًا | الشوكولاتة».
 *  • و«كل المنتجات» لا يغيب أبدًا، وما أُنشئ بعد الترتيب يُلحق آخرَه.
 *  • والحفظُ صارم: رموزٌ معروفةٌ من متجره وحده، ويُنشر كأخواته.
 *
 * وقواعدُ الشاشة نفسُها في `the-owner-orders-his-shop-row.test.tsx`.
 */
class ARibbonShopRowFollowsItsOwnersOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    /** @var array<string, Category> */
    private array $cat = [];

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@row.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@row.test');

        $this->cat['bouquets'] = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $this->cat['sweets'] = Category::create(['business_id' => $this->shop->id, 'name' => 'حلويات', 'name_en' => 'Sweets']);
        $this->cat['foreign'] = Category::create(['business_id' => $this->neighbour->id, 'name' => 'فئة الجار']);

        $this->p['rose'] = $this->product('باقة ورد', $this->cat['bouquets']);
        $this->p['choc'] = $this->product('علبة شوكولاتة', $this->cat['sweets']);

        MarketingSettings::save($this->shop->id, 'website', [
            StoreCollections::KEY => $this->collections([
                ['slug' => 'offers', 'title_ar' => 'العروض', 'title_en' => 'Offers'],
                ['slug' => 'graduation', 'title_ar' => 'هدايا التخرّج', 'title_en' => 'Graduation gifts'],
                ['slug' => 'chocolate', 'title_ar' => 'الشوكولاتة', 'title_en' => 'Chocolate'],
            ]),
        ]);
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => $slug,
            'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    private function product(string $name, Category $cat): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $cat->id, 'active' => true, 'published' => true,
        ]);
    }

    private function collections(array $rows): string
    {
        return json_encode(array_map(fn (array $c) => $c + ['visible' => true, 'in_row' => true, 'products' => [$this->p['rose']->id, $this->p['choc']->id]], $rows), JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private function pills(string $lang = 'ar', string $query = ''): array
    {
        $html = $this->get('/s/ribbon/shop?lang='.$lang.$query)->assertOk()->getContent();
        preg_match_all('#data-testid="rb-opt-([^"]+)"[^>]*>([^<]*)</a>#u', $html, $m);

        return array_map(fn ($k, $label) => $k.'|'.html_entity_decode($label), $m[1], $m[2]);
    }

    private function save(string $row, array $more = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [StoreHeader::ROW => $row] + $more);
    }

    /* ═══════════════════════ القراءة ═══════════════════════ */

    public function test_many_collections_sit_beside_all_products_and_best_sellers(): void
    {
        $this->assertSame([
            'all|كل المنتجات', 'best|الأكثر مبيعًا',
            'col-offers|العروض', 'col-graduation|هدايا التخرّج', 'col-chocolate|الشوكولاتة',
        ], $this->pills());

        $this->assertSame([
            'all|All Products', 'best|Best Sellers',
            'col-offers|Offers', 'col-graduation|Graduation gifts', 'col-chocolate|Chocolate',
        ], $this->pills('en'));
    }

    public function test_a_shop_without_collections_keeps_its_old_row_letter_for_letter(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => '', StoreHeader::SHORTCUTS => (string) $this->cat['bouquets']->id]);

        $this->assertSame(['all|كل المنتجات', 'best|الأكثر مبيعًا', 'cat-'.$this->cat['bouquets']->id.'|باقات'], $this->pills());
    }

    public function test_the_owner_orders_the_whole_row_basics_and_collections_alike(): void
    {
        $this->save('col:offers,all,col:graduation,best,col:chocolate')->assertSessionHasNoErrors();

        // «العروض | كل المنتجات | هدايا التخرّج | الأكثر مبيعًا | الشوكولاتة» — كما طلب صاحبُ المتجر
        $this->assertSame([
            'col-offers|العروض', 'all|كل المنتجات', 'col-graduation|هدايا التخرّج', 'best|الأكثر مبيعًا', 'col-chocolate|الشوكولاتة',
        ], $this->pills());
        $this->assertSame('col:offers,all,col:graduation,best,col:chocolate', MarketingSettings::group($this->shop->id, 'website')[StoreHeader::ROW]);
    }

    public function test_categories_take_their_place_in_the_same_order(): void
    {
        $sweets = $this->cat['sweets']->id;
        MarketingSettings::save($this->shop->id, 'website', [StoreHeader::SHORTCUTS => (string) $sweets]);
        $this->save('cat:'.$sweets.',all,best,col:offers')->assertSessionHasNoErrors();

        $this->assertSame(
            ['cat-'.$sweets, 'all', 'best', 'col-offers', 'col-graduation', 'col-chocolate'],
            array_map(fn ($p) => explode('|', $p)[0], $this->pills()),
            'ما لم يُذكر في الترتيب يُلحق آخرَه بترتيبه الأصليّ',
        );
    }

    public function test_all_products_never_leaves_the_row_and_a_new_collection_joins_its_end(): void
    {
        // ترتيبٌ كُتب بيدٍ في القاعدة بلا «كل المنتجات»
        MarketingSettings::save($this->shop->id, 'website', [StoreHeader::ROW => 'col:chocolate,best']);
        $this->assertSame(['all', 'col-chocolate', 'best', 'col-offers', 'col-graduation'], array_map(fn ($p) => explode('|', $p)[0], $this->pills()));

        // ومجموعةٌ أُنشئت بعد الترتيب تظهر في آخره — لا تضيع
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => $this->collections([
            ['slug' => 'offers', 'title_ar' => 'العروض'], ['slug' => 'graduation', 'title_ar' => 'هدايا التخرّج'],
            ['slug' => 'chocolate', 'title_ar' => 'الشوكولاتة'], ['slug' => 'birthday', 'title_ar' => 'هدايا الميلاد'],
        ])]);
        $this->assertSame('col-birthday', explode('|', last($this->pills()))[0]);
    }

    public function test_the_open_collection_is_lit_wherever_it_stands(): void
    {
        $this->save('col:graduation,all,best')->assertSessionHasNoErrors();
        $html = $this->get('/s/ribbon/shop?collection=graduation')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#class="rb-opt\s+is-on\s*"\s+data-testid="rb-opt-col-graduation"#', $html);
        $this->assertDoesNotMatchRegularExpression('#is-on\s*"\s+data-testid="rb-opt-all"#', $html);
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    /** @return array<string, array{0: string}> */
    public static function forged(): array
    {
        return [
            'رمزٌ لا يُعرف' => ['all,best,url:https://evil.example'],
            'مجموعةٌ لا توجد' => ['all,col:nothing'],
            'رمزٌ مكرّر' => ['all,best,all'],
            'بلا «كل المنتجات»' => ['best,col:offers'],
            'معرّفُ فئةٍ نصٌّ' => ['all,cat:abc'],
        ];
    }

    #[DataProvider('forged')]
    public function test_a_forged_order_is_refused_and_nothing_is_saved(string $row): void
    {
        $this->save($row)->assertSessionHasErrors(StoreHeader::ROW);

        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[StoreHeader::ROW] ?? ''));
    }

    public function test_a_category_of_another_shop_is_refused(): void
    {
        $this->save('all,cat:'.$this->cat['foreign']->id)->assertSessionHasErrors(StoreHeader::ROW);
    }

    public function test_a_collection_saved_in_the_same_request_may_be_ordered(): void
    {
        $this->save('col:eid,all,best', [StoreCollections::KEY => $this->collections([['slug' => 'eid', 'title_ar' => 'العيد']])])
            ->assertSessionHasNoErrors();

        $this->assertSame(['col-eid', 'all', 'best'], array_map(fn ($p) => explode('|', $p)[0], $this->pills()));
    }

    public function test_an_empty_order_is_the_old_row_again(): void
    {
        $this->save('best,all')->assertSessionHasNoErrors();
        $this->save('')->assertSessionHasNoErrors();

        $this->assertSame('all', explode('|', $this->pills()[0])[0]);
    }

    public function test_a_shop_without_a_theme_cannot_write_it(): void
    {
        $this->shop->forceFill(['storefront_theme' => null])->save();

        $this->save('best,all')->assertForbidden();
    }

    public function test_the_order_waits_for_publishing_and_lives_in_the_collections_row(): void
    {
        $this->assertContains(StoreHeader::ROW, StoreContent::VERSIONED);

        $row = collect(PageEditor::rows($this->shop->id))->firstWhere('key', PageEditor::COLLECTIONS);
        $this->assertContains(StoreHeader::ROW, array_column($row['fields'], 'key'), 'الترتيبُ يُكتب في صفّ المجموعات');

        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->save('best,all')->assertSessionHasNoErrors();
        $this->assertSame('all', explode('|', $this->pills()[0])[0], 'المسوّدةُ لا تبلغ الزبون');

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'ترتيب الشريط');
        $this->assertSame('best', explode('|', $this->pills()[0])[0]);
    }
}
