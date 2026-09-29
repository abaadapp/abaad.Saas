<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * واجهةُ RIBBON تُسمّي أقسامَها بالقاعدة التي يُسمّي بها الصندوقُ والموقعُ المبنيّ.
 *
 * ═══ ما كان ═══
 *
 * `RibbonController` كان يختار اسمَ القسم بيده: `name_en` في الإنجليزيّة وإلّا
 * العربيّ. فمتجرٌ بواجهةٍ إنجليزيّة يرى «منتجات» و«الإضافات» في المرشّح وعلى
 * البطاقات — والمعجمُ (`Lexicon`) يعرفهما يقينًا، والصندوقُ يقولهما Products
 * وAdd-ons منذ PR #27.
 *
 * ═══ القاعدة (`CategoryName`) ═══
 *
 *   العربيّة ← `name`.
 *   الإنجليزيّة ← `name_en`، ثمّ المعجم إن عرف كلَّ لفظ، ثمّ `name` كما كُتب.
 *
 * ═══ ولأيّ متجر ═══
 *
 * المتجران هنا عامّان — لا معرّفَ ولا رابطَ ولا اسمَ لمتجرٍ بعينه. والقاعدةُ
 * لا تسأل عن النشاط: تقرأ اسمَي القسم ولغةَ الصفحة. والمعرّفُ كما هو —
 * به يُرشَّح الرفّ (`?cat=`) — ولا يُكتب في الجدول شيء.
 */
class ARibbonShopNamesItsCategoriesLikeEveryOtherScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{shop: Business, cats: array<string, int>, products: array<string, int>}> */
    private array $shops = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * متجران بالواجهة نفسِها وبالأسماء العربيّة نفسِها — والإنجليزيّةُ
         * تختلف: ما كتبه كلٌّ منهما لأقسامه لا يعبر إلى الآخر.
         */
        $this->shops['a'] = $this->shop('shop-a', [
            'منتجات' => null,
            'الإضافات' => null,
            'باقات المناسبات' => null,
            'هدايا' => 'Gift ideas',
        ]);
        $this->shops['b'] = $this->shop('shop-b', [
            'منتجات' => 'Our range',
            'الإضافات' => null,
            'باقات المناسبات' => 'Occasion bouquets',
            'هدايا' => null,
        ]);
    }

    /** @param  array<string, ?string>  $categories  الاسمُ العربيّ ← `name_en` */
    private function shop(string $slug, array $categories): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        $cats = [];
        $products = [];

        /*
         * والأقسامُ تُكتب في الجدول مباشرةً: شاشةُ الإضافة تملأ `name_en` من
         * المعجم عند الحفظ، والقسمُ القديم أو المستورَد يصل فارغَه — وهو ما يُختبر.
         */
        foreach ($categories as $name => $en) {
            $cats[$name] = DB::table('categories')->insertGetId([
                'business_id' => $shop->id, 'name' => $name, 'name_en' => $en,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $products[$name] = Product::create([
                'business_id' => $shop->id, 'category_id' => $cats[$name],
                'name' => 'صنف '.$name, 'price' => 5, 'cost' => 1, 'quantity' => 10,
                'active' => true, 'published' => true,
            ])->id;
        }

        return ['shop' => $shop, 'cats' => $cats, 'products' => $products];
    }

    /** @return array<int, string> المعرّف ← الاسم، كما في مرشّح الرفّ */
    private function filter(string $key, string $lang): array
    {
        $slug = $this->shops[$key]['shop']->site_slug;
        $cats = $this->get("/s/{$slug}/shop?lang={$lang}")->assertOk()->viewData('categories');

        return collect($cats)->pluck('name', 'id')->all();
    }

    /** @return array<int, string> المعرّف ← الاسم، كما على بطاقات الرفّ */
    private function cards(string $key, string $lang): array
    {
        $slug = $this->shops[$key]['shop']->site_slug;
        $products = $this->get("/s/{$slug}/shop?lang={$lang}")->assertOk()->viewData('products');

        return collect($products)->pluck('category', 'category_id')->all();
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function expectations(): iterable
    {
        yield 'المتجر أ بالإنجليزيّة' => ['a', 'en', [
            'منتجات' => 'Products',                      // المعجم
            'الإضافات' => 'Add-ons',                     // المعجم
            'باقات المناسبات' => 'باقات المناسبات',       // لا ترجمةَ موثوقة
            'هدايا' => 'Gift ideas',                     // name_en يعلو على المعجم
        ]];
        yield 'المتجر ب بالإنجليزيّة' => ['b', 'en', [
            'منتجات' => 'Our range',
            'الإضافات' => 'Add-ons',
            'باقات المناسبات' => 'Occasion bouquets',
            'هدايا' => 'Gifts',                          // المعجم
        ]];
        foreach (['a', 'b'] as $key) {
            yield "المتجر {$key} بالعربيّة" => [$key, 'ar', [
                'منتجات' => 'منتجات', 'الإضافات' => 'الإضافات',
                'باقات المناسبات' => 'باقات المناسبات', 'هدايا' => 'هدايا',
            ]];
        }
    }

    #[DataProvider('expectations')]
    public function test_the_filter_names_each_category_by_the_shared_rule(string $key, string $lang, array $expected): void
    {
        $want = [];
        foreach ($expected as $name => $shown) {
            $want[$this->shops[$key]['cats'][$name]] = $shown;
        }
        ksort($want);

        $got = $this->filter($key, $lang);
        ksort($got);

        $this->assertSame($want, $got);
    }

    #[DataProvider('expectations')]
    public function test_a_card_names_its_category_as_the_filter_does(string $key, string $lang, array $expected): void
    {
        // والبطاقةُ والمرشّحُ اسمٌ واحدٌ للمعرّف نفسِه — لا يفترقان
        $cards = $this->cards($key, $lang);
        $filter = $this->filter($key, $lang);

        foreach ($cards as $id => $name) {
            $this->assertSame($filter[$id], $name, "البطاقةُ والمرشّحُ يفترقان في القسم {$id}");
        }

        // وصفحةُ الصنف تقوله كما تقوله البطاقة
        $slug = $this->shops[$key]['shop']->site_slug;
        $pid = $this->shops[$key]['products']['منتجات'];
        $page = $this->get("/s/{$slug}/p/{$pid}?lang={$lang}")->assertOk()->viewData('product');

        $this->assertSame($expected['منتجات'], $page['category']);
    }

    public function test_the_home_page_and_the_menu_use_the_same_names(): void
    {
        $slug = $this->shops['a']['shop']->site_slug;
        $home = $this->get("/s/{$slug}?lang=en")->assertOk();

        $names = collect($home->viewData('categories'))->pluck('name')->all();
        $this->assertContains('Products', $names);
        $this->assertContains('Add-ons', $names);
        $this->assertNotContains('منتجات', $names);

        $menu = collect($home->viewData('catsNav'))->pluck('name')->all();
        $this->assertContains('Products', $menu);
    }

    public function test_the_filter_still_filters_by_the_same_id(): void
    {
        $slug = $this->shops['a']['shop']->site_slug;
        $cat = $this->shops['a']['cats']['منتجات'];

        $products = $this->get("/s/{$slug}/shop?lang=en&cat={$cat}")->assertOk()->viewData('products');

        $this->assertSame([$this->shops['a']['products']['منتجات']], collect($products)->pluck('id')->all());
        $this->assertSame('Products', $products[0]['category']);
    }

    public function test_showing_a_name_writes_nothing(): void
    {
        $before = DB::table('categories')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        foreach (['a', 'b'] as $key) {
            $this->filter($key, 'en');
            $this->cards($key, 'en');
        }

        $this->assertSame($before, DB::table('categories')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertNull(Category::find($this->shops['a']['cats']['منتجات'])->name_en);
    }

    /**
     * ولا يعود الاختيارُ اليدويّ للقسم.
     *
     * نسخةٌ ثانيةٌ من القاعدة تفترق عن الأولى يوم تتبدّل إحداهما — وهو بالضبط
     * ما أصاب هذه الواجهة. فلا يُكتب هنا اختيارٌ بين `name_en` و`name` لقسم:
     * يُسأل `CategoryName`. وأسماءُ الأصناف والمقاسات خارجُ هذا — لها قاعدتُها.
     */
    public function test_the_controller_does_not_pick_a_category_name_by_hand(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Store/RibbonController.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/filled\(\s*\$(c|cat|category|p->category)->name_en\s*\)/',
            $src,
            'RibbonController يختار اسمَ القسم بيده — استعمل CategoryName::display',
        );
        $this->assertDoesNotMatchRegularExpression('/->category->name_en\s*\?:/', $src);
        $this->assertGreaterThanOrEqual(2, substr_count($src, 'CategoryName::display('));
    }
}
