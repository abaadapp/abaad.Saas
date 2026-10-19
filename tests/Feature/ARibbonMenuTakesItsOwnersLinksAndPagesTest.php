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
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreNav;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * القائمةُ الرئيسيّة بروابط صاحبها — «الرئيسية | المتجر | المناسبات | خدماتنا | من نحن | تواصل معنا».
 *
 * ═══ وما يُحرَس ═══
 *
 *  • متجرٌ لم يكتب قائمتَه قائمتُه القديمةُ بترتيبها وشكلها حرفًا بحرف.
 *  • وصاحبُ المتجر يضيف روابطَ إلى صفحاته المخصّصة ومجموعاته وفئاته وصفحاته،
 *    ويرتّب القائمةَ كلَّها — والصفحاتُ الأربعُ فيها لا تُحذف.
 *  • والرابطُ المخفيُّ، ووجهةٌ زالت، وصفحةٌ أطفأها — لا تُرسم. والمُضاءُ ما يقف
 *    عليه الزائر بعينه، والتذييلُ يتبع القائمة.
 *  • والحفظُ صارم: وجهاتُه من متجره وحده، وعشرةُ روابط لا أكثر، ويُنشر كأخواته.
 *
 * وقواعدُ الشاشة في `the-owner-writes-his-main-menu.test.tsx`، والصفحاتُ نفسُها في
 * `ARibbonCustomPageShowsWhatItsOwnerWroteTest`.
 */
class ARibbonMenuTakesItsOwnersLinksAndPagesTest extends TestCase
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

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@menu.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@menu.test');

        $this->cat['bouquets'] = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $this->cat['foreign'] = Category::create(['business_id' => $this->neighbour->id, 'name' => 'فئة الجار']);
        $this->p['rose'] = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $this->cat['bouquets']->id, 'active' => true, 'published' => true,
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_about' => 'نبذةٌ عن المحلّ',
            StoreCollections::KEY => json_encode([
                ['slug' => 'occasions', 'title_ar' => 'المناسبات', 'title_en' => 'Occasions', 'visible' => true, 'products' => [$this->p['rose']->id]],
                ['slug' => 'secret', 'title_ar' => 'مخفيّة', 'visible' => false, 'products' => [$this->p['rose']->id]],
            ], JSON_UNESCAPED_UNICODE),
            StoreCustomPages::KEY => json_encode([
                $this->customPage('services', 'خدماتنا', 'Our services'),
                $this->customPage('offers', 'عروضنا', ''),
                $this->customPage('draft', 'مسوّدة', 'Draft', ['visible' => false]),
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

    private function customPage(string $slug, string $ar, string $en, array $over = []): array
    {
        return $over + [
            'slug' => $slug, 'title_ar' => $ar, 'title_en' => $en, 'intro_ar' => 'نبذة', 'intro_en' => '',
            'image' => '', 'sections' => [], 'cta' => null, 'visible' => true,
        ];
    }

    private function link(string $id, string $ar, string $en, array $target, bool $visible = true): array
    {
        return ['type' => 'link', 'id' => $id, 'title_ar' => $ar, 'title_en' => $en, 'target' => $target, 'visible' => $visible];
    }

    private function page(string $key): array
    {
        return ['type' => 'page', 'key' => $key];
    }

    private function save(array|string $menu, array $more = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            StoreNav::MENU => is_string($menu) ? $menu : json_encode($menu, JSON_UNESCAPED_UNICODE),
        ] + $more);
    }

    private function html(string $path = '/', string $lang = 'ar'): string
    {
        return $this->get('/s/ribbon'.$path.(str_contains($path, '?') ? '&' : '?').'lang='.$lang)->assertOk()->getContent();
    }

    /** @return list<string> «المفتاح|الاسم|الرابط» بترتيب القائمة في الترويسة */
    private function menu(string $path = '/', string $lang = 'ar'): array
    {
        $html = $this->html($path, $lang);
        preg_match('#<nav class="rb-nav[^"]*" data-testid="rb-nav".*?</nav>#su', $html, $nav);
        preg_match_all('#<a href="([^"]+)"\s+class="rb-nav-a[^"]*"\s+data-testid="rb-nav-([^"]+)"[^>]*>([^<]*)</a>#u', $nav[0] ?? '', $m);

        return array_map(fn ($href, $key, $label) => $key.'|'.html_entity_decode($label).'|'.$href, $m[1], $m[2], $m[3]);
    }

    /** @return list<string> */
    private function keys(string $path = '/', string $lang = 'ar'): array
    {
        return array_map(fn ($l) => explode('|', $l)[0], $this->menu($path, $lang));
    }

    /* ═══════════════════════ القائمةُ القديمة ═══════════════════════ */

    public function test_a_shop_that_wrote_no_menu_keeps_the_old_one_in_its_old_shape(): void
    {
        $html = $this->html();

        $this->assertSame(['home', 'shop', 'about', 'contact'], $this->keys());
        $this->assertStringContainsString('<nav class="rb-nav" data-testid="rb-nav"', $html, 'القائمةُ القديمة لا تصير سطرًا يُمرَّر');
        $this->assertStringContainsString('<div class="rb-nav-in">', $html);
        $this->assertStringNotContainsString('<div class="rb-nav-in" data-rb-nav-scroll', $html);
        $this->assertFalse(StoreNav::customized($this->shop->id));
    }

    /* ═══════════════════════ الروابط ═══════════════════════ */

    public function test_links_to_his_pages_collections_and_categories_sit_where_he_put_them(): void
    {
        $this->save([
            $this->page('home'), $this->page('shop'),
            $this->link('occ', 'المناسبات', 'Occasions', ['type' => 'collection', 'value' => 'occasions']),
            $this->link('svc', 'خدماتنا', 'Our services', ['type' => 'page', 'value' => 'services']),
            $this->link('cat', 'الباقات', '', ['type' => 'category', 'value' => $this->cat['bouquets']->id]),
            $this->page('about'), $this->page('contact'),
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            'home|الرئيسية|/s/ribbon/',
            'shop|المتجر|/s/ribbon/shop',
            'link-occ|المناسبات|/s/ribbon/shop?collection=occasions',
            'link-svc|خدماتنا|/s/ribbon/page/services',
            'link-cat|الباقات|/s/ribbon/shop?cat='.$this->cat['bouquets']->id,
            'about|من نحن|/s/ribbon/about',
            'contact|تواصل معنا|/s/ribbon/contact',
        ], $this->menu());

        // والاسمُ بلغة الصفحة، وإلّا الآخرُ — اسمٌ يُضغط لا جملة. و«من نحن» بلا نبذةٍ إنجليزيّةٍ
        // لا تُفتح بالإنجليزيّة — القاعدةُ القائمة (`StoreNav::has`) لا تتبدّل بالقائمة المكتوبة
        $this->assertSame(['Home', 'Shop', 'Occasions', 'Our services', 'الباقات', 'Contact'], array_map(fn ($l) => explode('|', $l)[1], $this->menu('/', 'en')));
        $this->assertStringContainsString('<nav class="rb-nav rb-nav-scroll" data-testid="rb-nav"', $this->html(), 'القائمةُ المكتوبة سطرٌ يُمرَّر');
        $this->assertStringContainsString('<div class="rb-nav-in" data-rb-nav-scroll>', $this->html());
    }

    public function test_the_four_pages_take_any_order_he_gives(): void
    {
        // «المتجر | عروضنا | الرئيسية | خدماتنا | من نحن | تواصل معنا»
        $this->save([
            $this->page('shop'),
            $this->link('off', 'عروضنا', 'Our offers', ['type' => 'page', 'value' => 'offers']),
            $this->page('home'),
            $this->link('svc', 'خدماتنا', 'Our services', ['type' => 'page', 'value' => 'services']),
            $this->page('about'), $this->page('contact'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['shop', 'link-off', 'home', 'link-svc', 'about', 'contact'], $this->keys());
    }

    public function test_a_hidden_link_or_a_destination_that_does_not_open_is_not_drawn(): void
    {
        $this->save([
            $this->page('home'), $this->page('shop'),
            $this->link('hid', 'مخفيّ', '', ['type' => 'page', 'value' => 'services'], visible: false),
            $this->link('dra', 'مسوّدة', '', ['type' => 'page', 'value' => 'draft']),
            $this->link('sec', 'سرّ', '', ['type' => 'collection', 'value' => 'secret']),
            $this->link('ok', 'باقٍ', '', ['type' => 'best', 'value' => '']),
            $this->page('about'), $this->page('contact'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['home', 'shop', 'link-ok', 'about', 'contact'], $this->keys());

        // و«من نحن» التي أطفأها لا تعود بالقائمة المكتوبة — بابُها شاشةُ «الصفحات»
        MarketingSettings::save($this->shop->id, 'website', ['store_pages' => StoreNav::CONTACT]);
        $this->assertNotContains('about', $this->keys());
    }

    public function test_a_destination_that_goes_away_after_saving_drops_its_link(): void
    {
        $this->save([$this->page('home'), $this->link('svc', 'خدماتنا', '', ['type' => 'page', 'value' => 'services']), $this->page('shop'), $this->page('about'), $this->page('contact')])
            ->assertSessionHasNoErrors();
        $this->assertContains('link-svc', $this->keys());

        MarketingSettings::save($this->shop->id, 'website', [StoreCustomPages::KEY => '']);
        $this->assertNotContains('link-svc', $this->keys(), 'رابطٌ إلى صفحةٍ حُذفت لا يقود إلى «غير موجود»');

        // ولا يُحذف بحذفه شيء: المنتجُ والمجموعةُ كما هما
        $this->assertTrue(Product::whereKey($this->p['rose']->id)->exists());
        $this->assertNotNull(StoreCollections::find($this->shop->id, 'occasions'));
    }

    public function test_the_lit_link_is_where_the_visitor_stands_and_the_footer_follows(): void
    {
        $this->save([
            $this->page('home'), $this->page('shop'),
            $this->link('occ', 'المناسبات', '', ['type' => 'collection', 'value' => 'occasions']),
            $this->link('svc', 'خدماتنا', '', ['type' => 'page', 'value' => 'services']),
            $this->page('about'), $this->page('contact'),
        ])->assertSessionHasNoErrors();

        $lit = function (string $path): array {
            preg_match_all('#class="rb-nav-a\s+is-on\s*"\s+data-testid="rb-nav-([^"]+)"#', $this->html($path), $m);

            return $m[1];
        };

        $this->assertSame(['link-svc'], $lit('/page/services'));
        $this->assertSame(['link-occ'], $lit('/shop?collection=occasions'), '«المتجر» لا يُضاء مع المجموعة');
        $this->assertSame(['shop'], $lit('/shop'));
        $this->assertSame(['home'], $lit('/'));

        // والتذييلُ من مصدر القائمة نفسِه
        preg_match_all('#data-testid="rb-foot-([a-z-]+)"#', $this->html(), $foot);
        $menu = ['home', 'shop', 'link-occ', 'link-svc', 'about', 'contact'];
        $this->assertSame($menu, array_values(array_intersect($foot[1], $menu)));
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    public function test_a_menu_that_is_the_old_one_is_saved_empty(): void
    {
        $this->save([$this->page('home'), $this->page('shop'), $this->page('about'), $this->page('contact')])->assertSessionHasNoErrors();

        $this->assertSame('', (string) MarketingSettings::group($this->shop->id, 'website')[StoreNav::MENU]);
    }

    public function test_a_missing_basic_page_is_put_back_at_the_end(): void
    {
        $this->save([$this->link('svc', 'خدماتنا', '', ['type' => 'page', 'value' => 'services']), $this->page('shop')])->assertSessionHasNoErrors();

        $this->assertSame(['link-svc', 'shop', 'home', 'about', 'contact'], $this->keys(), 'الصفحاتُ الأربعُ لا تُحذف من القائمة');
    }

    /** @return array<string, array{0: string}> */
    public static function forged(): array
    {
        $link = fn (array $target, array $over = []) => $over + ['type' => 'link', 'id' => 'x1', 'title_ar' => 'رابط', 'title_en' => '', 'target' => $target, 'visible' => true];

        return [
            'رابطٌ خارجيّ' => [json_encode([$link(['type' => 'url', 'value' => 'https://evil.example'])])],
            'javascript' => [json_encode([$link(['type' => 'javascript', 'value' => 'alert(1)'])])],
            'صفحةٌ لا توجد' => [json_encode([$link(['type' => 'page', 'value' => 'nothing'])])],
            'مجموعةٌ لا توجد' => [json_encode([$link(['type' => 'collection', 'value' => 'nothing'])])],
            'بلا اسم' => [json_encode([$link(['type' => 'shop', 'value' => ''], ['title_ar' => ''])])],
            'اسمٌ أطولُ من حدّه' => [json_encode([$link(['type' => 'shop', 'value' => ''], ['title_ar' => str_repeat('ر', 31)])])],
            'معرّفٌ غريب' => [json_encode([$link(['type' => 'shop', 'value' => ''], ['id' => '<b>'])])],
            'صفحةٌ أساسيّةٌ مكرّرة' => [json_encode([['type' => 'page', 'key' => 'home'], ['type' => 'page', 'key' => 'home']])],
            'صفحةٌ أساسيّةٌ لا تُعرف' => [json_encode([['type' => 'page', 'key' => 'admin']])],
            'نصٌّ ليس JSON' => ['{oops'],
        ];
    }

    #[DataProvider('forged')]
    public function test_a_forged_menu_is_refused_and_nothing_is_saved(string $menu): void
    {
        $this->save($menu)->assertSessionHasErrors(StoreNav::MENU);

        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[StoreNav::MENU] ?? ''));
    }

    public function test_destinations_of_another_shop_are_refused(): void
    {
        $foreign = Product::create([
            'business_id' => $this->neighbour->id, 'name' => 'صنف الجار', 'price' => 5, 'cost' => 1, 'quantity' => 3,
            'category_id' => $this->cat['foreign']->id, 'active' => true, 'published' => true,
        ]);

        foreach ([['type' => 'category', 'value' => $this->cat['foreign']->id], ['type' => 'product', 'value' => $foreign->id]] as $target) {
            $this->save([$this->link('x1', 'رابط', '', $target)])->assertSessionHasErrors(StoreNav::MENU);
        }
    }

    public function test_ten_links_and_no_more(): void
    {
        $links = fn (int $n) => array_map(fn ($i) => $this->link('l'.$i, 'رابط '.$i, '', ['type' => 'shop', 'value' => '']), range(1, $n));

        $this->save($links(StoreNav::MAX_LINKS))->assertSessionHasNoErrors();
        $this->save($links(StoreNav::MAX_LINKS + 1))->assertSessionHasErrors(StoreNav::MENU);
    }

    public function test_a_page_created_in_the_same_save_may_be_linked(): void
    {
        $pages = json_encode([$this->customPage('new-one', 'صفحة جديدة', 'New page')], JSON_UNESCAPED_UNICODE);

        $this->save([$this->page('home'), $this->link('n1', 'جديدة', '', ['type' => 'page', 'value' => 'new-one'])], [StoreCustomPages::KEY => $pages])
            ->assertSessionHasNoErrors();

        $this->assertContains('link-n1', $this->keys());
    }

    public function test_a_shop_without_a_theme_cannot_write_it(): void
    {
        $this->shop->forceFill(['storefront_theme' => null])->save();

        $this->save([$this->page('shop'), $this->page('home')])->assertForbidden();
    }

    public function test_the_menu_waits_for_publishing_and_its_row_sits_under_the_header(): void
    {
        $this->assertContains(StoreNav::MENU, StoreContent::VERSIONED);
        $keys = array_column(PageEditor::rows($this->shop->id), 'key');
        $this->assertSame(PageEditor::MENU, $keys[array_search(PageEditor::HEAD, $keys, true) + 1]);

        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->save([$this->page('shop'), $this->page('home')])->assertSessionHasNoErrors();
        $this->assertSame('home', $this->keys()[0], 'المسوّدةُ لا تبلغ الزبون');

        // والمعاينةُ ترسم المسوّدةَ لصاحبها
        $preview = $this->actingAs($this->owner)->get(route('admin.store.preview'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-testid="rb-nav-shop".*data-testid="rb-nav-home"#s', $preview);

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'القائمة');
        $this->assertSame('shop', $this->keys()[0]);
    }
}
