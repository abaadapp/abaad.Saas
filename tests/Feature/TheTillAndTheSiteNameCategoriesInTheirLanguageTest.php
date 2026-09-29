<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Support\CategoryName;
use App\Support\Storefront;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * اسمُ القسم بلغة الشاشة التي تعرضه — في الصندوق وفي الموقع.
 *
 * ═══ ما كان ═══
 *
 * الصندوقُ الإنجليزيّ يعرض «منتجات» و«عروض» لأنّ `name_en` فارغ في أغلب
 * الأقسام، و`Demo::ln` تردّ العربيّ حينها. والموقعُ الإنجليزيّ يعرضها عربيّةً
 * ولو كتب التاجر `name_en` — `Preview::categories` لم يكن يقرؤه أصلًا.
 *
 * ═══ القاعدة (`CategoryName`) ═══
 *
 *   العربيّة ← `name`.
 *   الإنجليزيّة ← `name_en`، ثمّ `Lexicon` إن عرف كلَّ لفظ، ثمّ `name`.
 *
 * ═══ وما لا يتغيّر ═══
 *
 * ما يُرشَّح به: قيمةُ القسم في الصندوق (الاسمُ كما كُتب) ومعرّفُه في الموقع.
 * ولا يُكتب في الجدول شيءٌ لأجل العرض. ولغةُ الموقع لغةُ النشاط
 * (`Preview::locale`) لا لغةُ لوحة من يفتح المعاينة.
 *
 * والرسمُ في المتصفّح — التبويبان وما يرشّحانه — حارسُه
 * `tests/js/the-till-names-its-categories-in-its-language.test.tsx`.
 */
class TheTillAndTheSiteNameCategoriesInTheirLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /** @var array<string, Category> */
    private array $cats = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => 'ward']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@lang.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        /*
         * والأقسامُ تُكتب في الجدول مباشرةً لا من شاشة الإضافة: تلك تملأ
         * `name_en` من المعجم عند الحفظ (`Lexicon::fill`)، والقسمُ القديم
         * أو المستورَد يصل فارغَه — وهو ما يُختبر هنا.
         */
        foreach ([
            'منتجات' => 'Products',
            'خدمات' => null,
            'عروض' => null,
            'باقات المناسبات' => null,
            'الإضافات' => 'Add-ons',
        ] as $name => $en) {
            $id = DB::table('categories')->insertGetId([
                'business_id' => $this->shop->id, 'name' => $name, 'name_en' => $en,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->cats[$name] = Category::find($id);

            Product::create([
                'business_id' => $this->shop->id, 'category_id' => $id,
                'name' => 'صنف '.$name, 'price' => 5, 'quantity' => 10, 'active' => true, 'published' => true,
            ]);
        }
    }

    /* ═══════════════════════════ القاعدة ═══════════════════════════ */

    public function test_the_rule(): void
    {
        $this->assertSame('منتجات', CategoryName::display('منتجات', 'Products', 'ar'));
        $this->assertSame('Products', CategoryName::display('منتجات', 'Products', 'en'));
        $this->assertSame('Services', CategoryName::display('خدمات', null, 'en'));
        $this->assertSame('Offers', CategoryName::display('عروض', '', 'en'));
        $this->assertSame('Add-ons', CategoryName::display('الإضافات', null, 'en'));
        // ولا ترجمةَ مخترَعة: لفظٌ واحدٌ لا يعرفه المعجم يُبقي الاسمَ كما كُتب
        $this->assertSame('باقات المناسبات', CategoryName::display('باقات المناسبات', null, 'en'));
        // وما كتبه التاجر بيده يعلو على المعجم
        $this->assertSame('Signature', CategoryName::display('منتجات', 'Signature', 'en'));
    }

    /* ═══════════════════════════ الصندوق ═══════════════════════════ */

    /** @return array<string, string> القيمة ← التسمية، كما يرسلها الصندوق */
    private function till(string $locale): array
    {
        $this->owner->update(['locale' => $locale]);
        $this->actingAs($this->owner);
        $this->activatePosDevice($this->shop->id, Branch::where('business_id', $this->shop->id)->value('id'));

        $props = $this->get(route('pos.index'))->assertOk()->viewData('page')['props'];

        return collect($props['categories'])->pluck('label', 'value')->all();
    }

    public function test_the_arabic_till_reads_the_names_as_written(): void
    {
        $this->assertSame([
            'الكل' => 'الكل',
            'منتجات' => 'منتجات',
            'خدمات' => 'خدمات',
            'عروض' => 'عروض',
            'باقات المناسبات' => 'باقات المناسبات',
            'الإضافات' => 'الإضافات',
        ], $this->till('ar'));
    }

    public function test_the_english_till_reads_them_in_english_and_filters_by_the_same_values(): void
    {
        $this->assertSame([
            // والمفاتيحُ — قيمُ الترشيح — هي نفسُها بالعربيّة
            'الكل' => 'All',
            'منتجات' => 'Products',   // name_en
            'خدمات' => 'Services',    // المعجم
            'عروض' => 'Offers',       // المعجم
            'باقات المناسبات' => 'باقات المناسبات',   // لا ترجمةَ موثوقة
            'الإضافات' => 'Add-ons',  // name_en
        ], $this->till('en'));
    }

    public function test_the_products_still_carry_the_value_their_tab_filters_by(): void
    {
        $this->till('en');
        $props = $this->get(route('pos.index'))->viewData('page')['props'];

        $cats = collect($props['products'])->pluck('cat', 'name')->all();

        $this->assertSame('منتجات', $cats['صنف منتجات']);
        $this->assertSame('الإضافات', $cats['صنف الإضافات']);

        // وكلُّ قسمٍ يجد أصنافَه بقيمته — لا قسمَ فرغ بالترجمة
        foreach (collect($props['categories'])->pluck('value')->reject(fn ($v) => $v === 'الكل') as $value) {
            $this->assertContains($value, $cats, "قسم «{$value}» لا صنفَ يطابق قيمتَه");
        }
    }

    public function test_the_system_add_ons_tab_has_its_own_name_and_the_general_word_is_untouched(): void
    {
        $this->till('en');
        $translations = $this->get(route('pos.index'))->viewData('page')['props']['translations'];

        $this->assertSame('Product add-ons', $translations['إضافات المنتجات']);
        // و«الإضافات» العامّة كما هي — مستعملةٌ في مواضعَ أخرى
        $this->assertSame('Add-ons', $translations['الإضافات']);
        $this->assertNotSame($translations['الإضافات'], $translations['إضافات المنتجات']);
    }

    /* ═══════════════════════════ الموقع ═══════════════════════════ */

    private function siteLocale(string $locale): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'locale'], ['value' => $locale]);
    }

    private function site(): Website
    {
        $site = Builder::create($this->shop, Blueprints::STORE, 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        return $site->fresh();
    }

    /** @return array<int, string> المعرّف ← الاسم */
    private function names(array $doc): array
    {
        return collect($doc['data']['categories'])->pluck('name', 'id')->all();
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function siteLocales(): iterable
    {
        yield 'موقعٌ عربيّ' => ['ar', [
            'منتجات' => 'منتجات', 'خدمات' => 'خدمات', 'عروض' => 'عروض',
            'باقات المناسبات' => 'باقات المناسبات', 'الإضافات' => 'الإضافات',
        ]];
        yield 'موقعٌ إنجليزيّ' => ['en', [
            'منتجات' => 'Products', 'خدمات' => 'Services', 'عروض' => 'Offers',
            'باقات المناسبات' => 'باقات المناسبات', 'الإضافات' => 'Add-ons',
        ]];
    }

    #[DataProvider('siteLocales')]
    public function test_the_site_names_categories_in_its_own_language_in_the_preview_and_the_live_site_alike(string $locale, array $expected): void
    {
        $this->siteLocale($locale);
        $site = $this->site();

        $preview = Preview::document($site);
        $live = Published::forBusiness($this->shop->id)['site'];

        $want = [];
        foreach ($expected as $name => $shown) {
            $want[$this->cats[$name]->id] = $shown;
        }
        ksort($want);

        $got = $this->names($preview);
        ksort($got);

        $this->assertSame($locale, $preview['locale']);
        $this->assertSame($want, $got, 'المعاينة');
        // والمنشورُ يقول ما تقوله المعاينة — حرفًا بحرف، وبالمعرّفات نفسِها
        $this->assertSame($this->names($preview), $this->names($live), 'افترق المنشورُ عن المعاينة');
    }

    /**
     * ولغةُ الموقع لغةُ النشاط — لا لغةُ لوحة من يفتح المعاينة.
     *
     * صاحبُ المحلّ يدير لوحته بالعربيّة وموقعُه إنجليزيّ لزبائنه، أو
     * العكس. فلو قُرئت لغةُ الجلسة لرأى في معاينته غير ما يراه زبونُه.
     */
    public function test_the_admins_own_language_does_not_leak_into_the_site(): void
    {
        $this->siteLocale('en');
        $site = $this->site();

        app()->setLocale('ar');
        $this->assertSame('Products', $this->names(Preview::document($site))[$this->cats['منتجات']->id]);

        $this->siteLocale('ar');
        app()->setLocale('en');
        $this->assertSame('منتجات', $this->names(Preview::document($site->fresh()))[$this->cats['منتجات']->id]);
        $this->assertSame('منتجات', $this->names(Published::forBusiness($this->shop->id)['site'])[$this->cats['منتجات']->id]);
    }

    public function test_the_ids_are_untouched_and_each_product_still_points_at_its_category(): void
    {
        $this->siteLocale('en');
        $doc = Preview::document($this->site());

        $ids = array_keys($this->names($doc));
        sort($ids);
        $this->assertSame(collect($this->cats)->pluck('id')->sort()->values()->all(), $ids);

        // والكتالوجُ يرشّح بـ`category_id` — وكلُّ صنفٍ ما زال يشير إلى قسمه
        $products = collect($doc['pages'])->flatMap(fn ($p) => $p['sections'])
            ->where('type', 'product_catalog')->flatMap(fn ($s) => $s['items'] ?? []);
        $this->assertNotEmpty($products, 'لا كتالوجَ في الموقع المبنيّ');

        foreach ($products as $p) {
            $name = mb_substr($p['name'], mb_strlen('صنف '));
            $this->assertSame($this->cats[$name]->id, $p['category_id'], "صنف «{$name}» فقد قسمَه");
        }
    }

    public function test_a_shop_reads_only_its_own_categories(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = DB::table('categories')->insertGetId([
            'business_id' => $other->id, 'name' => 'منتجات', 'name_en' => 'Neighbour goods',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteLocale('en');
        $names = $this->names(Preview::document($this->site()));

        $this->assertArrayNotHasKey($theirs, $names);
        $this->assertNotContains('Neighbour goods', $names);
        // واسمُ الجار الإنجليزيّ لا يُقرأ لقسمٍ هنا يحمل الاسمَ العربيّ نفسَه
        $this->assertSame('Products', $names[$this->cats['منتجات']->id]);
    }

    public function test_showing_a_name_writes_nothing(): void
    {
        $before = DB::table('categories')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->till('en');
        $this->siteLocale('en');
        Preview::document($this->site());
        Published::forBusiness($this->shop->id);

        $after = DB::table('categories')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame($before, $after, 'العرضُ كتب في جدول الأقسام');
        $this->assertNull(DB::table('categories')->where('id', $this->cats['خدمات']->id)->value('name_en'));
    }

    /* ═══════════════════════ صفحةُ المتجر البسيطة ═══════════════════════ */

    /**
     * صفحةُ المتجر البسيطة عربيّةٌ كلُّها — ولا لغةَ ثانيةً لها.
     *
     * قالبُها `lang="ar" dir="rtl"` وكلماتُه مكتوبةٌ فيه («الكل»، «غير متوفّر
     * حاليًا») لا تمرّ على الترجمة. فاسمُ القسم فيها يتبع قاعدةَ العربيّة
     * (`name`) أيًّا كانت لغةُ النشاط — وإلّا صار «Products» زرًّا إنجليزيًّا
     * بين أزرارٍ عربيّة. وتعليمُها لغةً ثانية تصميمٌ لصفحتها لا إصلاحُ اسم.
     */
    public function test_the_simple_store_page_is_arabic_and_names_its_categories_as_written(): void
    {
        $this->siteLocale('en');

        $names = collect(Storefront::page($this->shop->fresh())['categories'])->pluck('name')->all();

        $this->assertContains('منتجات', $names);
        $this->assertNotContains('Products', $names);
    }
}
