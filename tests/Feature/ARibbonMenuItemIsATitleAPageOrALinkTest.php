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
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreNav;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * عناصرُ القائمة الرئيسيّة بأنواعها — عنوانٌ لا يُضغط، وصفحةُ كتابة، وصفحةُ صور،
 * وكتابةٌ وصور، وصفحةُ منتجات، ومحتوى متنوّع، ورابط.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • «عنوان فقط» كلمةٌ في القائمة بلا `href` — لا `href="#"` ولا صفحة — وغيرُ
 *    «صفحة كتابة» التي هي رابطٌ إلى صفحةٍ فيها عنوانٌ وفقرات.
 *  • لا يُطلب رابطٌ لقسم، ولا صورةٌ لصفحة كتابة، ولا نصٌّ لصفحة صور، ولا مجموعةٌ
 *    لصفحة منتجات — والصفحةُ تُفتح من القائمة برابطها الداخليّ.
 *  • والمنتجاتُ بطاقاتُ الرفّ بأسعارها وروابطها، من المعروض وحده ومن متجره وحده.
 *  • والوحداتُ بترتيب صاحبها، وما لا يناسب نوعَ الصفحة يُردّ، والنصُّ يُطبع نصًّا.
 *  • والرابطُ المكتوبُ آمنٌ وحده: الخارجيُّ في نافذةٍ جديدة، والداخليُّ تحت المتجر.
 *
 * وقواعدُ الشاشة في `the-owner-builds-his-menu-and-pages.test.tsx`.
 */
class ARibbonMenuItemIsATitleAPageOrALinkTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@items.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@items.test');

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $make = fn (string $name, string $en, float $price, array $over = []) => Product::create($over + [
            'business_id' => $this->shop->id, 'name' => $name, 'name_en' => $en, 'price' => $price, 'cost' => 1, 'quantity' => 20,
            'category_id' => $cat->id, 'active' => true, 'published' => true,
        ]);
        $this->p['rose'] = $make('باقة ورد', 'Rose bouquet', 12);
        $this->p['tulip'] = $make('باقة توليب', 'Tulip bouquet', 18);
        $this->p['hidden'] = $make('باقة مخفيّة', 'Hidden', 9, ['published' => false]);
        $this->p['foreign'] = Product::create([
            'business_id' => $this->neighbour->id, 'name' => 'منتج الجار', 'price' => 5, 'cost' => 1, 'quantity' => 5, 'active' => true, 'published' => true,
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            StoreCollections::KEY => json_encode([
                ['slug' => 'occasions', 'title_ar' => 'المناسبات', 'title_en' => 'Occasions', 'visible' => true, 'products' => [$this->p['tulip']->id, $this->p['rose']->id]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => $slug,
            'tier' => 'gold', 'storefront_theme' => 'ribbon', 'phone' => '+96890000000',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    /** صورةٌ حقيقيّةٌ في مجلّد المتجر — ورابطُها كما يكتبه المحرّر */
    private function image(string $file, ?Business $shop = null): string
    {
        $im = imagecreatetruecolor(400, 300);
        ob_start();
        imagepng($im);
        $dir = 'website/'.($shop ?? $this->shop)->id.'/'.$file;
        Storage::disk('public')->put($dir, (string) ob_get_clean());

        return Storage::disk('public')->url($dir);
    }

    private function page(string $slug, string $kind, array $blocks, array $over = []): array
    {
        return $over + [
            'slug' => $slug, 'kind' => $kind, 'title_ar' => 'صفحة '.$slug, 'title_en' => '',
            'intro_ar' => '', 'intro_en' => '', 'blocks' => $blocks, 'visible' => true,
        ];
    }

    private function item(string $id, string $ar, string $en, ?array $target, bool $visible = true): array
    {
        return $target === null
            ? ['type' => 'label', 'id' => $id, 'title_ar' => $ar, 'title_en' => $en, 'visible' => $visible]
            : ['type' => 'link', 'id' => $id, 'title_ar' => $ar, 'title_en' => $en, 'target' => $target, 'visible' => $visible];
    }

    /** يحفظ القائمةَ وصفحاتِها معًا — كما يحفظها صفُّها في المحرّر */
    private function save(array $pages, array $menu = [])
    {
        $json = fn (array $v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            StoreCustomPages::KEY => $pages === [] ? '' : $json($pages),
            StoreNav::MENU => $menu === [] ? '' : $json($menu),
        ]);
    }

    private function menu(array ...$items): array
    {
        return [['type' => 'page', 'key' => 'home'], ['type' => 'page', 'key' => 'shop'], ...$items, ['type' => 'page', 'key' => 'about'], ['type' => 'page', 'key' => 'contact']];
    }

    private function html(string $path = '/', string $lang = 'ar', string $slug = 'ribbon'): string
    {
        return $this->get('/s/'.$slug.$path.(str_contains($path, '?') ? '&' : '?').'lang='.$lang)->assertOk()->getContent();
    }

    /** ما في المقالة وحدها — لا الترويسة ولا التذييل */
    private function article(string $slug, string $lang = 'ar'): string
    {
        preg_match('#<article class="rb-screen" data-testid="rb-custom-page".*?</article>#su', $this->html('/page/'.$slug, $lang), $m);

        return $m[0] ?? '';
    }

    /** @return list<string> وحداتُ الصفحة بترتيب رسمها */
    private function blocks(string $slug, string $lang = 'ar'): array
    {
        preg_match_all('#data-testid="rb-page-(heading|text|image|gallery|section|products|collection|cta|promo)"#', $this->article($slug, $lang), $m);

        return $m[1];
    }

    private function nav(string $lang = 'ar'): string
    {
        preg_match('#<nav class="rb-nav[^"]*" data-testid="rb-nav".*?</nav>#su', $this->html('/', $lang), $m);

        return $m[0] ?? '';
    }

    /* ═══════════════════════ عنوانٌ فقط ═══════════════════════ */

    public function test_a_title_shows_in_the_menu_as_words_that_open_nothing(): void
    {
        $this->save([], $this->menu(
            $this->item('occ', 'المناسبات', 'Occasions', null),
            $this->item('off', 'مخفيّ', '', null, false),
        ))->assertSessionHasNoErrors();

        $nav = $this->nav();
        $this->assertStringContainsString('<span class="rb-nav-a rb-nav-label" data-testid="rb-nav-label-occ">المناسبات</span>', $nav);
        $this->assertStringNotContainsString('href="#"', $nav, 'لا رابطَ وهميّ');
        $this->assertDoesNotMatchRegularExpression('#<a[^>]+data-testid="rb-nav-label-occ"#', $nav, 'العنوانُ لا يُضغط');
        $this->assertStringNotContainsString('مخفيّ', $nav);

        $this->assertStringContainsString('>Occasions</span>', $this->nav('en'));
        // والتذييلُ روابطُ تُضغط — لا ينسخ العنوانَ إليه
        $this->assertStringNotContainsString('rb-foot-label-occ', $this->html());

        $saved = StoreNav::menu($this->shop->id)[2];
        $this->assertSame(['type' => 'label', 'id' => 'occ', 'title_ar' => 'المناسبات', 'title_en' => 'Occasions', 'visible' => true], $saved, 'ولا وجهةَ تُحفظ له');
    }

    /* ═══════════════════════ صفحاتٌ بلا رابطٍ يُكتب ═══════════════════════ */

    public function test_a_text_page_needs_no_link_and_no_image_and_opens_from_the_menu(): void
    {
        $this->save(
            [$this->page('story', 'text', [
                ['type' => 'heading', 'text_ar' => 'من نحن', 'text_en' => ''],
                ['type' => 'text', 'body_ar' => "سطرٌ أوّل\nوسطرٌ ثانٍ", 'body_en' => ''],
            ], ['title_ar' => 'قصّتنا', 'intro_ar' => 'منذ سنوات'])],
            $this->menu($this->item('p1', 'قصّتنا', '', ['type' => 'page', 'value' => 'story'])),
        )->assertSessionHasNoErrors();

        // والعنوانُ غيرُ صفحة الكتابة: هذه رابطٌ إلى صفحتها
        $this->assertStringContainsString('<a href="/s/ribbon/page/story"', $this->nav());
        $this->assertSame(['heading', 'text'], $this->blocks('story'));

        $article = $this->article('story');
        $this->assertStringContainsString('منذ سنوات', $article);
        $this->assertStringContainsString("سطرٌ أوّل\nوسطرٌ ثانٍ", $article, 'وأسطرُه أسطر');
        $this->assertStringNotContainsString('<img', $article, 'صفحةُ كتابةٍ بلا صورة');
    }

    public function test_an_image_page_needs_no_words(): void
    {
        $a = $this->image('a.png');
        $b = $this->image('b.png');

        $this->save([$this->page('gallery', 'images', [
            ['type' => 'image', 'image' => $a, 'fit' => 'contain'],
            ['type' => 'gallery', 'images' => [$a, $b], 'fit' => 'contain'],
        ])])->assertSessionHasNoErrors();

        $this->assertSame(['image', 'gallery'], $this->blocks('gallery'));
        $article = $this->article('gallery');
        $this->assertSame(3, substr_count($article, 'data-fit="contain"'), 'الصورُ كاملةٌ كما اختار');
        $this->assertStringContainsString('width="400" height="300"', $article);
        $this->assertStringNotContainsString('rb-page-text', $article);
    }

    public function test_a_text_and_image_page_puts_each_image_beside_or_above_its_words(): void
    {
        $img = $this->image('s.png');
        $section = fn (string $layout, string $title) => ['type' => 'textimage', 'image' => $img, 'fit' => 'contain', 'layout' => $layout, 'title_ar' => $title, 'title_en' => '', 'body_ar' => 'نص', 'body_en' => ''];

        $this->save([$this->page('services', 'textimage', [$section('side', 'أوّل'), $section('side', 'ثانٍ'), $section('top', 'ثالث')])])
            ->assertSessionHasNoErrors();

        preg_match_all('#data-testid="rb-page-section" data-layout="(side|top)">\s*<div class="rb-cp-sec([^"]*)"#', $this->article('services'), $m);
        $this->assertSame(['side', 'side', 'top'], $m[1]);
        $this->assertStringNotContainsString('is-flip', $m[2][0]);
        $this->assertStringContainsString('is-flip', $m[2][1], 'والجانبيّان يتناوبان');
        $this->assertStringContainsString('is-top', $m[2][2], 'والصورةُ فوق نصّها');
    }

    public function test_a_products_page_shows_its_chosen_products_with_prices_and_links_without_a_collection(): void
    {
        $before = StoreCollections::all($this->shop->id);

        $this->save([$this->page('gifts', 'products', [
            ['type' => 'products', 'title_ar' => 'هدايا التخرّج', 'title_en' => '', 'products' => [$this->p['tulip']->id, $this->p['hidden']->id, $this->p['rose']->id]],
            ['type' => 'products', 'title_ar' => 'كلُّها مخفيّة', 'title_en' => '', 'products' => [$this->p['hidden']->id]],
        ])])->assertSessionHasNoErrors();

        $article = $this->article('gifts');
        preg_match_all('#href="/s/ribbon/p/(\d+)" class="rb-card"#', $article, $m);
        $this->assertSame([$this->p['tulip']->id, $this->p['rose']->id], array_map('intval', $m[1]), 'بترتيبه — والمخفيُّ لا يُرسم');
        $this->assertStringContainsString('هدايا التخرّج', $article);
        $this->assertStringContainsString('class="rb-price"', $article);
        $this->assertStringNotContainsString('كلُّها مخفيّة', $article, 'ووحدةٌ لم يبقَ فيها معروضٌ لا تُرسم — لا عنوانَ فوق رفٍّ فارغ');
        $this->assertSame(1, substr_count($article, 'data-testid="rb-page-products"'));
        $this->assertSame($before, StoreCollections::all($this->shop->id), 'ولا مجموعةَ تُنشأ لها');

        // والمخفيُّ يبقى في صفحته محفوظًا — يعود حين يُعرض
        $this->assertContains($this->p['hidden']->id, StoreCustomPages::all($this->shop->id)[0]['blocks'][0]['products']);
    }

    public function test_a_collection_block_shows_its_shelf_and_a_link_to_all_of_it(): void
    {
        $this->save([$this->page('occ', 'products', [['type' => 'collection', 'slug' => 'occasions', 'title_ar' => '', 'title_en' => '']])])
            ->assertSessionHasNoErrors();

        $article = $this->article('occ');
        $this->assertStringContainsString('المناسبات', $article, 'وعنوانُها اسمُ المجموعة');
        $this->assertStringContainsString('href="/s/ribbon/shop?collection=occasions" data-testid="rb-page-collection-all"', $article);
        $this->assertSame(2, substr_count($article, 'class="rb-card"'));
    }

    public function test_a_mixed_page_keeps_its_owners_order_and_its_links_are_safe(): void
    {
        $img = $this->image('m.png');

        $this->save([$this->page('events', 'mixed', [
            ['type' => 'heading', 'text_ar' => 'هدايا لكلّ مناسبة', 'text_en' => ''],
            ['type' => 'image', 'image' => $img, 'fit' => 'contain'],
            ['type' => 'text', 'body_ar' => 'فقرةٌ تعريفيّة', 'body_en' => ''],
            ['type' => 'products', 'title_ar' => 'التخرّج', 'title_en' => '', 'products' => [$this->p['rose']->id]],
            ['type' => 'button', 'label_ar' => 'إنستغرام', 'label_en' => '', 'target' => ['type' => 'url', 'value' => 'https://instagram.com/ribbon']],
            ['type' => 'promo', 'image' => '', 'fit' => '', 'title_ar' => 'خصم العيد', 'title_en' => '', 'body_ar' => 'على كلّ الباقات', 'body_en' => '', 'label_ar' => 'تسوّق', 'label_en' => '', 'target' => ['type' => 'url', 'value' => '/shop?cat=1']],
        ])])->assertSessionHasNoErrors();

        $this->assertSame(['heading', 'image', 'text', 'products', 'cta', 'promo'], $this->blocks('events'));

        $article = $this->article('events');
        $this->assertStringContainsString('href="https://instagram.com/ribbon" data-testid="rb-page-cta" target="_blank" rel="noopener noreferrer"', $article, 'والخارجيُّ في نافذةٍ جديدة');
        $this->assertStringContainsString('href="/s/ribbon/shop?cat=1" data-testid="rb-page-promo-cta">', $article, 'والداخليُّ تحت عنوان المتجر');
    }

    public function test_an_external_menu_link_opens_in_a_new_window(): void
    {
        $this->save([], $this->menu($this->item('ig', 'إنستغرام', '', ['type' => 'url', 'value' => 'https://instagram.com/ribbon'])))
            ->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('#<a href="https://instagram.com/ribbon"\s+class="rb-nav-a\s*"\s+data-testid="rb-nav-link-ig"\s+target="_blank" rel="noopener noreferrer">إنستغرام</a>#u', $this->nav());
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    /** @return array<string, array{0: callable(self): array}> */
    public static function forged(): array
    {
        return [
            'منتجاتٌ في صفحة كتابة' => [fn (self $t) => $t->page('x', 'text', [['type' => 'products', 'title_ar' => '', 'title_en' => '', 'products' => [$t->p['rose']->id]]])],
            'نوعٌ لا يُعرف' => [fn (self $t) => $t->page('x', 'shop', [])],
            'وحدةٌ لا تُعرف' => [fn (self $t) => $t->page('x', 'mixed', [['type' => 'script', 'body_ar' => 'x']])],
            'عنوانٌ فارغ' => [fn (self $t) => $t->page('x', 'text', [['type' => 'heading', 'text_ar' => '', 'text_en' => '']])],
            'منتجُ الجار' => [fn (self $t) => $t->page('x', 'products', [['type' => 'products', 'title_ar' => '', 'title_en' => '', 'products' => [$t->p['foreign']->id]]])],
            'صورةٌ من مجلّد الجار' => [fn (self $t) => $t->page('x', 'images', [['type' => 'image', 'image' => $t->image('n.png', $t->neighbour), 'fit' => 'contain']])],
            'مجموعةٌ لا توجد' => [fn (self $t) => $t->page('x', 'products', [['type' => 'collection', 'slug' => 'nothing', 'title_ar' => '', 'title_en' => '']])],
            'زرٌّ إلى javascript' => [fn (self $t) => $t->page('x', 'text', [['type' => 'button', 'label_ar' => 'اضغط', 'label_en' => '', 'target' => ['type' => 'url', 'value' => 'javascript:alert(1)']]])],
            'معرضٌ فوق حدّه' => [fn (self $t) => $t->page('x', 'images', [['type' => 'gallery', 'images' => array_fill(0, StoreCustomPages::MAX_GALLERY + 1, $t->image('g.png')), 'fit' => 'contain']])],
            'مكانُ صورةٍ لا يُعرف' => [fn (self $t) => $t->page('x', 'textimage', [['type' => 'textimage', 'image' => '', 'fit' => '', 'layout' => 'under', 'title_ar' => 'x', 'title_en' => '', 'body_ar' => '', 'body_en' => '']])],
        ];
    }

    #[DataProvider('forged')]
    public function test_a_forged_page_is_refused_and_nothing_is_saved(callable $page): void
    {
        $this->save([$page($this)])->assertSessionHasErrors(StoreCustomPages::KEY);

        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[StoreCustomPages::KEY] ?? ''));
    }

    public function test_the_refusal_names_the_page_and_the_block(): void
    {
        $this->save([$this->page('x', 'text', [
            ['type' => 'text', 'body_ar' => 'نص', 'body_en' => ''],
            ['type' => 'heading', 'text_ar' => '', 'text_en' => ''],
        ], ['title_ar' => 'المناسبات'])])->assertSessionHasErrors([StoreCustomPages::KEY => 'في صفحة «المناسبات»، الوحدة 2: اكتب العنوان أو احذف الوحدة.']);
    }

    public function test_words_are_printed_as_words(): void
    {
        $this->save([$this->page('x', 'text', [['type' => 'heading', 'text_ar' => '<script>alert(1)</script>', 'text_en' => '']])])
            ->assertSessionHasNoErrors();

        $article = $this->article('x');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $article);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $article);
    }

    public function test_the_english_page_draws_only_what_was_written_in_english(): void
    {
        $this->save([$this->page('story', 'text', [
            ['type' => 'heading', 'text_ar' => 'من نحن', 'text_en' => 'About us'],
            ['type' => 'text', 'body_ar' => 'بالعربيّة وحدها', 'body_en' => ''],
        ], ['title_ar' => 'قصّتنا'])])->assertSessionHasNoErrors();

        $this->assertSame(['heading'], $this->blocks('story', 'en'), 'والفقرةُ بلا إنجليزيّة لا تُرسم في صفحتها');
        $article = $this->article('story', 'en');
        $this->assertStringContainsString('About us', $article);
        $this->assertStringContainsString('قصّتنا', $article, 'والعنوانُ اسمٌ يقع على الأخرى');
        $this->assertStringNotContainsString('بالعربيّة وحدها', $article);
    }

    /* ═══════════════════════ الرابطُ والنشرُ والعزل ═══════════════════════ */

    public function test_renaming_a_page_keeps_its_old_link(): void
    {
        $this->save([$this->page('occasions', 'text', [], ['title_ar' => 'المناسبات'])])->assertSessionHasNoErrors();
        $this->save([$this->page('occasions', 'text', [], ['title_ar' => 'مناسباتنا'])])->assertSessionHasNoErrors();

        $this->assertStringContainsString('مناسباتنا', $this->article('occasions'));
    }

    public function test_a_page_and_its_menu_item_wait_for_publishing(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save(
            [$this->page('story', 'text', [], ['title_ar' => 'قصّتنا'])],
            $this->menu($this->item('p1', 'قصّتنا', '', ['type' => 'page', 'value' => 'story']), $this->item('t1', 'عناوين', '', null)),
        )->assertSessionHasNoErrors();

        $this->get('/s/ribbon/page/story')->assertNotFound();
        $this->assertStringNotContainsString('rb-nav-label-t1', $this->nav(), 'لا يراه زبونٌ قبل النشر');

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'القائمة');

        $this->assertStringContainsString('قصّتنا', $this->article('story'));
        $this->assertStringContainsString('rb-nav-label-t1', $this->nav());
    }

    public function test_a_page_is_its_shop_alone(): void
    {
        $this->save([$this->page('story', 'text', [], ['title_ar' => 'قصّتنا'])])->assertSessionHasNoErrors();

        $this->get('/s/jaar/page/story')->assertNotFound();
        $this->assertSame([], StoreCustomPages::all($this->neighbour->id));
    }
}
