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
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * مجموعاتُ متجر RIBBON — رفوفٌ يختار صاحبُها أصنافَها بيده، غيرُ الفئات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإنشاءُ والتسميةُ والوصفُ والصورةُ والإخفاءُ والحذفُ والترتيبُ — من
 *     بابِ حفظ المحرّر القائم، وفي المسوّدة: لا يراها الزبونُ حتّى ينشر.
 *   - رفُّ المجموعة (`‎/shop?collection=…‎`) يعرض أصنافَها وحدها بترتيبه، من
 *     فئاتٍ شتّى، والصنفُ في أكثر من مجموعة — وفئتُه كما هي في القاعدة.
 *   - وما لا يُعرض (موقوفٌ أو غيرُ منشور) يسقط وحده، والمجموعةُ الفارغةُ
 *     تقول إنّها فارغة. والبحثُ داخلها، و«الأكثر مبيعًا» يغلبها.
 *   - والعزل: لا صنفَ ولا صورةَ من متجرٍ آخر تُحفظ، ولا تُفتح مجموعةُ متجرٍ
 *     آخر برابط، ومجموعةٌ مخفيّةٌ أو مجهولةٌ «غير موجود».
 *   - ومتجرٌ لم يكتب مجموعةً يبقى صفُّه ورفُّه كما كانا حرفًا بحرف.
 */
class ARibbonShopCuratesItsOwnCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    /** @var array<string, Product> */
    private array $p = [];

    /** @var array<string, Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@collections.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@collections.test');

        $this->cat['bouquets'] = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $this->cat['extras'] = Category::create(['business_id' => $this->shop->id, 'name' => 'إضافات', 'name_en' => 'Extras']);

        $this->p['rose'] = $this->product($this->shop, 'باقة ورد', 'Rose bouquet', $this->cat['bouquets']);
        $this->p['tulip'] = $this->product($this->shop, 'باقة توليب', 'Tulip bouquet', $this->cat['bouquets']);
        $this->p['balloon'] = $this->product($this->shop, 'بالون تخرّج', 'Graduation balloon', $this->cat['extras']);
        $this->p['choc'] = $this->product($this->shop, 'شوكولاتة', 'Chocolate', $this->cat['extras']);
        $this->p['hidden'] = $this->product($this->shop, 'غير منشور', null, $this->cat['extras'], ['published' => false]);
        $this->p['off'] = $this->product($this->shop, 'موقوف', null, $this->cat['extras'], ['active' => false]);
        $this->p['foreign'] = $this->product($this->neighbour, 'صنف الجار', null, null);
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

    private function product(Business $shop, string $name, ?string $en, ?Category $cat, array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $shop->id, 'name' => $name, 'name_en' => $en, 'price' => 10, 'cost' => 2,
            'quantity' => 20, 'category_id' => $cat?->id, 'active' => true, 'published' => true,
        ]);
    }

    /** مجموعةٌ كما ترسلها الشاشة */
    private function collection(string $slug, array $products, array $over = []): array
    {
        return $over + [
            'slug' => $slug, 'title_ar' => 'هدايا التخرّج', 'title_en' => 'Graduation gifts',
            'desc_ar' => 'لكلّ خرّيجٍ باقته', 'desc_en' => 'A bouquet for every graduate',
            'image' => '', 'visible' => true, 'in_row' => true,
            'products' => array_map(fn ($k) => $this->p[$k]->id, $products),
        ];
    }

    private function save(array $collections, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('admin.marketing.store.save'), [
            StoreCollections::KEY => json_encode($collections, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** والمُدخَلاتُ تُرمَّز كما يرمّزها المتصفّح — «باقة» في الرابط حروفٌ مرمَّزة */
    private function shopPage(string $query = '', string $lang = 'ar', string $slug = 'ribbon')
    {
        parse_str($query, $params);

        return $this->get('/s/'.$slug.'/shop?'.http_build_query($params + ['lang' => $lang]));
    }

    /** @return list<string> */
    private function names(string $query, string $lang = 'ar'): array
    {
        return array_column($this->shopPage($query, $lang)->assertOk()->viewData('products'), 'name');
    }

    /* ═══════════════════════ الإنشاءُ والتعديل ═══════════════════════ */

    public function test_a_collection_is_created_from_the_editor_and_opens_at_its_own_link(): void
    {
        $this->save([$this->collection('graduation', ['balloon', 'rose', 'choc'])])->assertSessionHasNoErrors();

        // بترتيبه هو لا بالاسم — ومن فئتين
        $this->assertSame(['بالون تخرّج', 'باقة ورد', 'شوكولاتة'], $this->names('collection=graduation'));

        $res = $this->shopPage('collection=graduation')->assertOk();
        $this->assertSame('هدايا التخرّج', $res->viewData('collection')['title']);
        $this->assertStringContainsString('لكلّ خرّيجٍ باقته', $res->getContent());
    }

    public function test_renaming_and_reordering_keep_the_link_and_the_category(): void
    {
        $this->save([$this->collection('graduation', ['balloon', 'rose'])]);
        $this->save([$this->collection('graduation', ['rose', 'balloon'], ['title_ar' => 'تخرّج الدفعة'])])->assertSessionHasNoErrors();

        $this->assertSame(['باقة ورد', 'بالون تخرّج'], $this->names('collection=graduation'));
        $this->assertSame('تخرّج الدفعة', $this->shopPage('collection=graduation')->viewData('collection')['title']);
        // والصنفُ في فئته كما كان — المجموعةُ عرضٌ لا تصنيف
        $this->assertSame($this->cat['extras']->id, (int) $this->p['balloon']->fresh()->category_id);
        $this->assertSame($this->cat['bouquets']->id, (int) $this->p['rose']->fresh()->category_id);
    }

    public function test_one_product_lives_in_several_collections(): void
    {
        $this->save([
            $this->collection('graduation', ['rose', 'balloon']),
            $this->collection('birthday', ['rose', 'choc'], ['title_ar' => 'أعياد الميلاد', 'title_en' => 'Birthdays']),
        ])->assertSessionHasNoErrors();

        $this->assertContains('باقة ورد', $this->names('collection=graduation'));
        $this->assertContains('باقة ورد', $this->names('collection=birthday'));
        $this->assertNotContains('شوكولاتة', $this->names('collection=graduation'));
    }

    public function test_a_hidden_collection_is_not_found_and_leaves_the_row(): void
    {
        $this->save([$this->collection('graduation', ['rose'], ['visible' => false])]);

        $this->shopPage('collection=graduation')->assertNotFound();
        $this->assertStringNotContainsString('data-testid="rb-opt-col-graduation"', $this->shopPage()->getContent());
    }

    public function test_a_deleted_collection_is_gone_and_its_products_stay(): void
    {
        $this->save([$this->collection('graduation', ['rose']), $this->collection('offers', ['choc'], ['title_ar' => 'عروض'])]);
        $this->save([$this->collection('offers', ['choc'], ['title_ar' => 'عروض'])])->assertSessionHasNoErrors();

        $this->shopPage('collection=graduation')->assertNotFound();
        $this->shopPage('collection=offers')->assertOk();
        $this->assertNotNull($this->p['rose']->fresh(), 'حذفُ المجموعة لا يحذف صنفًا');
    }

    public function test_emptying_the_list_saves_no_collections(): void
    {
        $this->save([$this->collection('graduation', ['rose'])]);
        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [StoreCollections::KEY => ''])->assertSessionHasNoErrors();

        $this->assertSame([], StoreCollections::all($this->shop->id));
        $this->shopPage('collection=graduation')->assertNotFound();
    }

    /* ═══════════════════════ ما يُعرض ═══════════════════════ */

    public function test_a_product_hidden_after_it_was_chosen_falls_out_alone(): void
    {
        $this->save([$this->collection('graduation', ['rose', 'balloon'])]);
        $this->p['balloon']->update(['published' => false]);

        $this->assertSame(['باقة ورد'], $this->names('collection=graduation'));
    }

    public function test_an_empty_collection_says_so(): void
    {
        $this->save([$this->collection('soon', [])]);

        $res = $this->shopPage('collection=soon')->assertOk();
        $this->assertSame([], $res->viewData('products'));
        $this->assertStringContainsString('لا توجد منتجات في هذه المجموعة بعد.', $res->getContent());
    }

    public function test_search_runs_inside_the_collection_and_best_sellers_wins_over_it(): void
    {
        $this->save([$this->collection('graduation', ['rose', 'balloon', 'tulip'])]);

        $this->assertSame(['باقة ورد', 'باقة توليب'], $this->names('collection=graduation&q=باقة'));
        // و«الأكثر مبيعًا» له الغلبة — المجموعةُ تُهمَل معه
        $this->assertNull($this->shopPage('view=best&collection=graduation')->assertOk()->viewData('collection'));
        // والفئةُ تُهمَل مع المجموعة
        $this->assertSame(['بالون تخرّج'], $this->names('collection=graduation&cat='.$this->cat['extras']->id.'&q=بالون'));
        $this->assertCount(3, $this->names('collection=graduation&cat='.$this->cat['extras']->id));
    }

    public function test_the_row_carries_the_collection_after_best_sellers_and_before_categories(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_shop_nav_categories' => (string) $this->cat['bouquets']->id]);
        $this->save([
            $this->collection('graduation', ['rose']),
            $this->collection('quiet', ['choc'], ['title_ar' => 'من الشريط وحده', 'in_row' => false]),
        ]);

        $keys = array_column($this->shopPage('collection=graduation')->viewData('shopOptions'), 'key');
        $this->assertSame(['all', 'best', 'col-graduation', 'cat-'.$this->cat['bouquets']->id], $keys);

        $current = array_column($this->shopPage('collection=graduation')->viewData('shopOptions'), 'current', 'key');
        $this->assertTrue($current['col-graduation']);
        $this->assertFalse($current['all'], 'ولا تُضاء «كل المنتجات» مع مجموعة');

        // والمخفيُّ عن الصفّ يُفتح برابطه
        $this->shopPage('collection=quiet')->assertOk();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function languages(): iterable
    {
        yield 'العربيّة' => ['ar', 'هدايا التخرّج', 'لكلّ خرّيجٍ باقته'];
        yield 'English' => ['en', 'Graduation gifts', 'A bouquet for every graduate'];
    }

    #[DataProvider('languages')]
    public function test_each_language_reads_the_name_and_description_written_for_it(string $lang, string $title, string $desc): void
    {
        $this->save([$this->collection('graduation', ['rose'])]);

        $res = $this->shopPage('collection=graduation', $lang)->assertOk();
        $this->assertSame($title, $res->viewData('collection')['title']);
        $this->assertSame($desc, $res->viewData('collection')['description']);
        $this->assertSame($title, collect($res->viewData('shopOptions'))->firstWhere('key', 'col-graduation')['label']);
    }

    public function test_an_arabic_only_collection_keeps_its_name_in_english_and_drops_its_description(): void
    {
        $this->save([$this->collection('eid', ['rose'], ['title_en' => '', 'desc_en' => ''])]);

        $res = $this->shopPage('collection=eid', 'en')->assertOk();
        $this->assertSame('هدايا التخرّج', $res->viewData('collection')['title'], 'الاسمُ يقع على ما كُتب — كاسم الصنف');
        $this->assertSame('', $res->viewData('collection')['description'], 'الوصفُ لا يقع على العربيّ');
        $this->assertStringNotContainsString('لكلّ خرّيجٍ باقته', $res->getContent());
    }

    /* ═══════════════════════ العزلُ والحراسة ═══════════════════════ */

    public function test_a_product_of_another_shop_is_refused_and_nothing_is_saved(): void
    {
        $bad = $this->collection('graduation', ['rose']);
        $bad['products'][] = $this->p['foreign']->id;

        $this->save([$bad])->assertSessionHasErrors(StoreCollections::KEY);
        $this->assertSame([], StoreCollections::all($this->shop->id));
    }

    /** @return iterable<string, array{string}> */
    public static function unshown(): iterable
    {
        yield 'غيرُ منشور' => ['hidden'];
        yield 'موقوف' => ['off'];
    }

    /*
     * ═══ والصنفُ الذي أُخفي بعد اختياره يبقى في مجموعته ولا يُرسم ═══
     *
     * كان يُردّ حفظُ المجموعات كلِّها من أجله، والشاشةُ تُسقطه حين تُفتح فيضيع
     * مع أوّل حفظ — فاختار صاحبُ المتجر أصنافه ثمّ لم يجدها. وهو الآن يُحفظ
     * ما دام من متجره، ولا يظهر لزبونه حتّى يعود معروضًا.
     */
    #[DataProvider('unshown')]
    public function test_a_product_hidden_after_it_was_chosen_stays_in_its_collection_but_is_not_shown(string $key): void
    {
        $this->save([$this->collection('graduation', ['rose', $key])])->assertSessionHasNoErrors();

        $this->assertSame([$this->p['rose']->id, $this->p[$key]->id], StoreCollections::all($this->shop->id)[0]['products']);

        $html = $this->shopPage('collection=graduation')->assertOk()->getContent();
        $this->assertStringContainsString('/p/'.$this->p['rose']->id.'"', $html);
        $this->assertStringNotContainsString('/p/'.$this->p[$key]->id.'"', $html, 'والمخفيُّ لا يُرسم لزبونه');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forged(): iterable
    {
        yield 'رابطٌ بحروفٍ كبيرة' => [['slug' => 'Graduation']];
        yield 'رابطٌ بمسار' => [['slug' => '../admin']];
        yield 'رابطٌ عربيّ' => [['slug' => 'تخرج']];
        yield 'بلا اسمٍ في اللغتين' => [['title_ar' => '', 'title_en' => '']];
        yield 'اسمٌ أطولُ من حدّه' => [['title_ar' => str_repeat('ا', StoreCollections::TITLE_MAX + 1)]];
        yield 'وصفٌ أطولُ من حدّه' => [['desc_en' => str_repeat('a', StoreCollections::DESC_MAX + 1)]];
        yield 'صورةٌ من موقعٍ آخر' => [['image' => 'https://tracker.example/pixel.gif']];
        yield 'صورةٌ بـ javascript' => [['image' => 'javascript:alert(1)']];
        yield 'أصنافٌ ليست قائمة' => [['products' => 'all']];
        yield 'معرّفٌ سالب' => [['products' => [-1]]];
    }

    #[DataProvider('forged')]
    public function test_a_forged_collection_is_refused(array $over): void
    {
        $this->save([$over + $this->collection('graduation', ['rose'])])->assertSessionHasErrors(StoreCollections::KEY);
        $this->assertSame([], StoreCollections::all($this->shop->id));
    }

    public function test_two_collections_cannot_share_one_link(): void
    {
        $this->save([$this->collection('graduation', ['rose']), $this->collection('graduation', ['choc'])])
            ->assertSessionHasErrors(StoreCollections::KEY);
    }

    public function test_beyond_the_limits_is_refused(): void
    {
        $many = array_map(fn ($n) => $this->collection('c'.$n, ['rose']), range(1, StoreCollections::MAX + 1));
        $this->save($many)->assertSessionHasErrors(StoreCollections::KEY);

        $this->save(array_slice($many, 0, StoreCollections::MAX))->assertSessionHasNoErrors();
    }

    public function test_an_image_from_the_neighbours_folder_is_refused_and_its_own_is_kept(): void
    {
        $mine = Storage::disk('public')->url('website/'.$this->shop->id.'/graduation.jpg');
        $theirs = Storage::disk('public')->url('website/'.$this->neighbour->id.'/graduation.jpg');

        $this->save([$this->collection('graduation', ['rose'], ['image' => $theirs])])->assertSessionHasErrors(StoreCollections::KEY);
        $this->save([$this->collection('graduation', ['rose'], ['image' => $mine])])->assertSessionHasNoErrors();

        $this->assertSame($mine, StoreCollections::all($this->shop->id)[0]['image']);
    }

    /**
     * والصورةُ التي يرفعها المحرّرُ نفسُه تُقبل — أيًّا كان القرصُ الافتراضيّ.
     *
     * بابُ الرفع يردّ `Storage::url` بالقرص الافتراضيّ: رابطًا نسبيًّا
     * (`‎/storage/…‎`) على `local`، ومطلقًا بعنوان التطبيق على `public`.
     * والحارسُ (`StoreMedia::owned`) يقبل الاثنين من مجلّد متجره وحده.
     *
     * @return iterable<string, array{string}>
     */
    public static function disks(): iterable
    {
        yield 'القرصُ الافتراضيّ local' => ['local'];
        yield 'القرصُ الافتراضيّ public' => ['public'];
    }

    #[DataProvider('disks')]
    public function test_an_image_uploaded_from_the_editor_is_accepted(string $default): void
    {
        config(['filesystems.default' => $default]);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.image'), ['image' => UploadedFile::fake()->image('graduation.jpg', 320, 400)])
            ->assertSessionHas('uploaded');
        $url = (string) session('uploaded');
        $this->assertStringContainsString('/storage/website/'.$this->shop->id.'/', $url);

        $this->save([$this->collection('graduation', ['rose'], ['image' => $url])])->assertSessionHasNoErrors();
        $this->assertSame($url, StoreCollections::all($this->shop->id)[0]['image']);
    }

    public function test_the_neighbour_cannot_open_this_shops_collection(): void
    {
        $this->save([$this->collection('graduation', ['rose'])]);

        // الرابطُ نفسُه على متجر الجار — لا مجموعةَ له بهذا الاسم
        $this->get('/s/jaar/shop?collection=graduation')->assertNotFound();
        $this->get('/s/ribbon/shop?collection=graduation')->assertOk();
    }

    public function test_a_link_that_is_not_text_is_not_found_not_a_crash(): void
    {
        $this->get('/s/ribbon/shop?collection[]=graduation')->assertNotFound();
        $this->get('/s/ribbon/shop?collection=nothing-here')->assertNotFound();
    }

    public function test_html_in_a_name_is_shown_as_text(): void
    {
        $this->save([$this->collection('graduation', ['rose'], ['title_ar' => '<script>alert(1)</script>'])])->assertSessionHasNoErrors();

        $html = $this->shopPage('collection=graduation')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_shop_without_a_theme_cannot_save_collections(): void
    {
        $this->shop->update(['storefront_theme' => null]);

        $this->save([$this->collection('graduation', ['rose'])])->assertForbidden();
    }

    /* ═══════════════════════ المسوّدةُ والنشر ═══════════════════════ */

    public function test_a_saved_collection_waits_for_publishing(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->assertContains(StoreCollections::KEY, StoreContent::VERSIONED);

        $this->save([$this->collection('graduation', ['rose'])])->assertSessionHasNoErrors();
        $this->shopPage('collection=graduation')->assertNotFound();
        $this->assertSame([], StoreCollections::all($this->shop->id), 'المسوّدةُ لا تبلغ الزبون');
        $this->assertContains(StoreCollections::KEY, StoreContent::changed($this->shop->id));

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'مجموعة التخرّج');
        $this->shopPage('collection=graduation')->assertOk();
    }

    /* ═══════════════════════ المتجرُ القائم ═══════════════════════ */

    public function test_a_shop_that_wrote_no_collection_keeps_its_shop_exactly(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_shop_nav_categories' => (string) $this->cat['bouquets']->id]);

        $res = $this->shopPage()->assertOk();
        $this->assertSame(['all', 'best', 'cat-'.$this->cat['bouquets']->id], array_column($res->viewData('shopOptions'), 'key'));
        $this->assertNull($res->viewData('collection'));
        $this->assertSame([], StoreCollections::all($this->shop->id));
        $this->assertSame('', (string) MarketingSettings::group($this->shop->id, 'website')[StoreCollections::KEY]);
    }

    public function test_the_editor_offers_the_collections_row_to_every_ribbon_shop(): void
    {
        foreach ([$this->shop, $this->neighbour] as $shop) {
            $row = collect(PageEditor::rows((int) $shop->id))->firstWhere('key', PageEditor::COLLECTIONS);
            $this->assertNotNull($row, 'صفُّ المجموعات لمتجرٍ يلبس RIBBON');
            $this->assertSame('collections', $row['fields'][0]['kind']);
            $this->assertSame(StoreCollections::MAX, $row['fields'][0]['max']);
        }

        $this->assertContains(StoreCollections::KEY, PageEditor::omitted(), 'بطاقةُ الإعدادات لا تكتب فوق ما حرّره في المحرّر');
    }
}
