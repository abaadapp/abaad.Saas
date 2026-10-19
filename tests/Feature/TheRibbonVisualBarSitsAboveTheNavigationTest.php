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
use App\Support\Store\StoreVisualBar;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * شريطُ المجموعات المصوّر في RIBBON — بطاقاتٌ بصورها فوق قائمة الصفحات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - مطفأٌ لكلّ متجرٍ حتّى يكتبه ويشغّله وينشره: لا عنصرَ ولا قاعدةَ CSS في
 *     صفحة متجرٍ بلا شريط.
 *   - يُرسم فوق الترويسة اللاصقة لا داخلها، وقائمةُ الصفحات تحته كما هي —
 *     ولا عنصرَ لاصقٌ ثانٍ.
 *   - لكلّ لغةٍ نصُّها، وبطاقةٌ بلا عنوانٍ بلغة الصفحة لا تُرسم فيها.
 *   - والبطاقةُ تفتح وجهتَها في هذا المتجر: مجموعةً ظاهرة، فئةً فيها صنفٌ
 *     معروض، صنفًا معروضًا، أو صفحةً تُفتح. وما زالت وجهتُه تسقط بطاقتُه.
 *   - وفي صفحات التصفّح وحدها — لا في السلّة والإتمام.
 *   - والحفظُ صارم: لا رابطَ حرّ، ولا وجهةَ ولا صورةَ من متجرٍ آخر.
 */
class TheRibbonVisualBarSitsAboveTheNavigationTest extends TestCase
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

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@vbar.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@vbar.test');

        $this->cat['bouquets'] = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $this->cat['empty'] = Category::create(['business_id' => $this->shop->id, 'name' => 'فارغة']);
        $this->cat['foreign'] = Category::create(['business_id' => $this->neighbour->id, 'name' => 'فئة الجار']);

        $this->p['rose'] = $this->product($this->shop, 'باقة ورد', $this->cat['bouquets']);
        $this->p['hidden'] = $this->product($this->shop, 'غير منشور', $this->cat['bouquets'], ['published' => false]);
        $this->p['foreign'] = $this->product($this->neighbour, 'صنف الجار', $this->cat['foreign']);

        MarketingSettings::save($this->shop->id, 'website', [
            StoreCollections::KEY => json_encode([
                ['slug' => 'graduation', 'title_ar' => 'هدايا التخرّج', 'title_en' => 'Graduation gifts', 'image' => $this->mine('graduation.jpg'), 'visible' => true, 'in_row' => true, 'products' => [$this->p['rose']->id]],
                ['slug' => 'secret', 'title_ar' => 'مخفيّة', 'title_en' => 'Hidden', 'visible' => false, 'products' => [$this->p['rose']->id]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    private function product(Business $shop, string $name, ?Category $cat, array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $shop->id, 'name' => $name, 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $cat?->id, 'active' => true, 'published' => true,
        ]);
    }

    /** صورةٌ في مجلّد هذا المتجر — كما يردّها بابُ الرفع */
    private function mine(string $file, ?Business $shop = null): string
    {
        return Storage::disk('public')->url('website/'.($shop ?? $this->shop)->id.'/'.$file);
    }

    private function card(array $target, array $over = []): array
    {
        return $over + [
            'image' => '', 'title_ar' => 'بطاقة', 'title_en' => 'Card', 'sub_ar' => '', 'sub_en' => '',
            'cta_ar' => '', 'cta_en' => '', 'target' => $target, 'visible' => true,
        ];
    }

    private function bar(array $cards, bool $on = true, string $pages = StoreVisualBar::ON_HOME): string
    {
        return json_encode(['on' => $on, 'pages' => $pages, 'cards' => $cards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function save(string $bar, array $more = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [StoreVisualBar::KEY => $bar] + $more);
    }

    /** يحفظ الشريطَ كما يحفظه المحرّر — ويتأكّد أنّه قُبل */
    private function wear(array $cards, bool $on = true, string $pages = StoreVisualBar::ON_HOME): void
    {
        $this->save($this->bar($cards, $on, $pages))->assertSessionHasNoErrors();
    }

    private function page(string $path = '/', string $lang = 'ar', string $slug = 'ribbon'): string
    {
        return $this->get('/s/'.$slug.$path.(str_contains($path, '?') ? '&' : '?').'lang='.$lang)->assertOk()->getContent();
    }

    /** @return list<string> */
    private function hrefs(string $html): array
    {
        preg_match_all('#<a class="rb-vcard" href="([^"]+)"#', $html, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function titles(string $html): array
    {
        preg_match_all('#<span class="rb-vcard-title">([^<]*)</span>#u', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    /* ═══════════════════════ مطفأٌ حتّى يُشغَّل ═══════════════════════ */

    public function test_a_shop_that_wrote_no_bar_has_neither_its_markup_nor_its_style(): void
    {
        foreach (['/', '/shop', '/p/'.$this->p['rose']->id] as $path) {
            $html = $this->page($path);
            $this->assertStringNotContainsString('rb-visual-bar', $html, $path);
            $this->assertStringNotContainsString('rb-vbar', $html, $path);
            $this->assertStringNotContainsString('rb-vcard', $html, $path);
        }
        $this->assertSame([], StoreVisualBar::cards($this->shop->id, 'ar', '/s/ribbon', 'home'));
    }

    public function test_a_written_bar_stays_hidden_until_it_is_switched_on(): void
    {
        $this->wear([$this->card(['type' => 'shop', 'value' => ''])], on: false);

        $this->assertStringNotContainsString('rb-visual-bar', $this->page());
    }

    /* ═══════════════════════ فوق القائمة لا مكانها ═══════════════════════ */

    public function test_the_bar_sits_above_the_sticky_header_and_the_page_list_stays_below_it(): void
    {
        $this->wear([$this->card(['type' => 'collection', 'value' => 'graduation'], ['title_ar' => 'هدايا التخرّج'])]);
        $html = $this->page();

        $bar = strpos($html, 'data-testid="rb-visual-bar"');
        $head = strpos($html, '<header class="rb-head">');
        $nav = strpos($html, 'data-testid="rb-nav"');

        $this->assertNotFalse($bar);
        $this->assertLessThan($head, $bar, 'الشريطُ فوق الترويسة');
        $this->assertGreaterThan($head, $nav, 'وقائمةُ الصفحات في الترويسة كما كانت');
        foreach (['rb-nav-home', 'rb-nav-shop'] as $link) {
            $this->assertStringContainsString('data-testid="'.$link.'"', $html, 'القائمةُ لا تُستبدل');
        }

        // والترويسةُ وحدها لاصقة — والشريطُ لا يلصق ولا يثبت
        $this->assertStringContainsString('header.rb-head { background: var(--rb-olive); position: sticky; top: 0;', $html);
        preg_match('#\.rb-vbar \{[^}]*\}#', $html, $rule);
        $this->assertStringNotContainsString('sticky', $rule[0]);
        $this->assertStringNotContainsString('fixed', $rule[0]);
        // ومقاسُ الصورة محجوزٌ قبل أن تصل، وتُقصّ ولا تُمطّ
        $this->assertStringContainsString('.rb-vcard-img { display: block; aspect-ratio: 4 / 5;', $html);
        $this->assertStringContainsString('object-fit: cover', $html);
        $this->assertStringContainsString('env(safe-area-inset-left', $html);
    }

    public function test_cards_keep_their_order_and_open_their_targets_in_this_shop(): void
    {
        $this->wear([
            $this->card(['type' => 'collection', 'value' => 'graduation'], ['title_ar' => 'التخرّج']),
            $this->card(['type' => 'category', 'value' => $this->cat['bouquets']->id], ['title_ar' => 'الباقات']),
            $this->card(['type' => 'product', 'value' => $this->p['rose']->id], ['title_ar' => 'الوردة']),
            $this->card(['type' => 'best', 'value' => ''], ['title_ar' => 'الأكثر']),
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'الكل']),
        ]);
        $html = $this->page();

        $this->assertSame(['التخرّج', 'الباقات', 'الوردة', 'الأكثر', 'الكل'], $this->titles($html));
        $this->assertSame([
            '/s/ribbon/shop?collection=graduation',
            '/s/ribbon/shop?cat='.$this->cat['bouquets']->id,
            '/s/ribbon/p/'.$this->p['rose']->id,
            '/s/ribbon/shop?view=best',
            '/s/ribbon/shop',
        ], $this->hrefs($html));

        // وكلُّ وجهةٍ تُفتح فعلًا
        foreach ($this->hrefs($html) as $href) {
            $this->get($href)->assertOk();
        }
    }

    /* ═══════════════════════ اللغة ═══════════════════════ */

    public function test_each_language_shows_only_what_was_written_for_it(): void
    {
        $this->wear([
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'الكل', 'title_en' => 'Everything', 'sub_ar' => 'سطر', 'sub_en' => 'Line', 'cta_ar' => 'تسوّق', 'cta_en' => 'Shop now']),
            $this->card(['type' => 'best', 'value' => ''], ['title_ar' => 'عربيّةٌ وحدها', 'title_en' => '']),
            $this->card(['type' => 'collection', 'value' => 'graduation'], ['title_ar' => '', 'title_en' => 'English only']),
        ]);

        $ar = $this->page('/', 'ar');
        $this->assertSame(['الكل', 'عربيّةٌ وحدها'], $this->titles($ar));
        $this->assertStringContainsString('<span class="rb-vcard-sub">سطر</span>', $ar);
        $this->assertStringContainsString('<span class="rb-vcard-cta">تسوّق</span>', $ar);
        $this->assertStringContainsString('aria-label="مجموعات المتجر"', $ar);

        $en = $this->page('/', 'en');
        $this->assertSame(['Everything', 'English only'], $this->titles($en));
        $this->assertStringContainsString('<span class="rb-vcard-cta">Shop now</span>', $en);
        $this->assertStringContainsString('aria-label="Shop collections"', $en);
        $this->assertStringNotContainsString('عربيّةٌ وحدها', $en);
    }

    /* ═══════════════════════ ما يسقط ═══════════════════════ */

    public function test_a_hidden_card_or_an_unreachable_target_drops_alone(): void
    {
        $this->wear([
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'باقية']),
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'مخفيّة البطاقة', 'visible' => false]),
            $this->card(['type' => 'collection', 'value' => 'secret'], ['title_ar' => 'إلى مجموعةٍ مخفيّة']),
            $this->card(['type' => 'category', 'value' => $this->cat['empty']->id], ['title_ar' => 'إلى فئةٍ فارغة']),
            $this->card(['type' => 'about', 'value' => ''], ['title_ar' => 'إلى «من نحن» بلا نبذة']),
        ]);

        $this->assertSame(['باقية'], $this->titles($this->page()));
    }

    public function test_a_target_that_goes_away_after_saving_drops_its_card(): void
    {
        $this->wear([
            $this->card(['type' => 'product', 'value' => $this->p['rose']->id], ['title_ar' => 'الوردة']),
            $this->card(['type' => 'collection', 'value' => 'graduation'], ['title_ar' => 'التخرّج']),
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'الكل']),
        ]);

        $this->p['rose']->update(['published' => false]);
        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => '']);

        $this->assertSame(['الكل'], $this->titles($this->page()));
    }

    /* ═══════════════════════ الصورة ═══════════════════════ */

    public function test_the_card_image_or_its_collections_image_or_a_tint(): void
    {
        $this->wear([
            $this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'بصورتها', 'image' => $this->mine('card.jpg')]),
            $this->card(['type' => 'collection', 'value' => 'graduation'], ['title_ar' => 'بصورة مجموعتها']),
            $this->card(['type' => 'best', 'value' => ''], ['title_ar' => 'بلا صورة']),
        ]);
        $html = $this->page();

        $this->assertStringContainsString('<img src="'.$this->mine('card.jpg').'" alt="" width="164" height="205"', $html);
        $this->assertStringContainsString('<img src="'.$this->mine('graduation.jpg').'" alt=""', $html);
        $this->assertSame(1, substr_count($html, '<span class="rb-stripes" aria-hidden="true"></span>'));
    }

    /* ═══════════════════════ أين يظهر ═══════════════════════ */

    public function test_home_only_shows_it_on_the_home_page_alone(): void
    {
        $this->wear([$this->card(['type' => 'shop', 'value' => ''])], pages: StoreVisualBar::ON_HOME);

        $this->assertStringContainsString('rb-visual-bar', $this->page('/'));
        $this->assertStringNotContainsString('rb-visual-bar', $this->page('/shop'));
        $this->assertStringNotContainsString('rb-visual-bar', $this->page('/p/'.$this->p['rose']->id));
    }

    public function test_every_browsing_page_shows_it_and_the_purchase_never_does(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_about' => 'نبذةٌ عن المحلّ']);
        $this->wear([$this->card(['type' => 'shop', 'value' => ''])], pages: StoreVisualBar::ON_BROWSE);

        foreach (['/', '/shop', '/shop?collection=graduation', '/p/'.$this->p['rose']->id, '/about'] as $path) {
            $this->assertStringContainsString('rb-visual-bar', $this->page($path), $path);
        }
        foreach (['/cart', '/checkout'] as $path) {
            $this->assertStringNotContainsString('rb-visual-bar', $this->page($path), 'لا شريطَ حيث يدفع الزبون: '.$path);
        }
    }

    /* ═══════════════════════ الحفظُ صارم ═══════════════════════ */

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forgedCards(): iterable
    {
        yield 'رابطٌ خارجيّ' => [['target' => ['type' => 'url', 'value' => 'https://evil.example']]];
        yield 'رابطُ javascript' => [['target' => ['type' => 'javascript', 'value' => 'alert(1)']]];
        yield 'مجموعةٌ لا توجد' => [['target' => ['type' => 'collection', 'value' => 'nothing']]];
        yield 'بلا وجهة' => [['target' => null]];
        yield 'معرّفُ صنفٍ نصٌّ' => [['target' => ['type' => 'product', 'value' => '1 or 1=1']]];
        yield 'صورةٌ من موقعٍ آخر' => [['image' => 'https://tracker.example/pixel.gif']];
        yield 'بلا عنوانٍ في اللغتين' => [['title_ar' => '', 'title_en' => '']];
        yield 'عنوانٌ أطولُ من حدّه' => [['title_en' => str_repeat('a', StoreVisualBar::TITLE_MAX + 1)]];
        yield 'زرٌّ أطولُ من حدّه' => [['cta_ar' => str_repeat('ا', StoreVisualBar::CTA_MAX + 1)]];
    }

    #[DataProvider('forgedCards')]
    public function test_a_forged_card_is_refused_and_nothing_is_saved(array $over): void
    {
        $this->save($this->bar([$over + $this->card(['type' => 'shop', 'value' => ''])]))->assertSessionHasErrors(StoreVisualBar::KEY);
        $this->assertSame('', (string) MarketingSettings::group($this->shop->id, 'website')[StoreVisualBar::KEY]);
    }

    public function test_targets_and_images_of_another_shop_are_refused(): void
    {
        foreach ([
            ['target' => ['type' => 'category', 'value' => $this->cat['foreign']->id]],
            ['target' => ['type' => 'product', 'value' => $this->p['foreign']->id]],
            ['target' => ['type' => 'product', 'value' => $this->p['hidden']->id]],
            ['image' => $this->mine('theirs.jpg', $this->neighbour)],
        ] as $over) {
            $this->save($this->bar([$over + $this->card(['type' => 'shop', 'value' => ''])]))->assertSessionHasErrors(StoreVisualBar::KEY);
        }

        $this->assertSame([], StoreVisualBar::settings($this->shop->id)['cards']);
    }

    public function test_beyond_the_card_limit_and_an_unknown_placement_are_refused(): void
    {
        $many = array_fill(0, StoreVisualBar::MAX_CARDS + 1, $this->card(['type' => 'shop', 'value' => '']));
        $this->save($this->bar($many))->assertSessionHasErrors(StoreVisualBar::KEY);
        $this->save($this->bar(array_slice($many, 0, StoreVisualBar::MAX_CARDS)))->assertSessionHasNoErrors();

        $this->save(json_encode(['on' => true, 'pages' => 'checkout', 'cards' => []]))->assertSessionHasErrors(StoreVisualBar::KEY);
        $this->save('[1,2]')->assertSessionHasErrors(StoreVisualBar::KEY);
    }

    public function test_a_card_may_open_a_collection_saved_in_the_same_request(): void
    {
        $collections = json_encode([['slug' => 'eid', 'title_ar' => 'العيد', 'products' => [$this->p['rose']->id]]], JSON_UNESCAPED_UNICODE);

        $this->save($this->bar([$this->card(['type' => 'collection', 'value' => 'eid'], ['title_ar' => 'العيد'])]), [StoreCollections::KEY => $collections])
            ->assertSessionHasNoErrors();

        $this->assertSame(['/s/ribbon/shop?collection=eid'], $this->hrefs($this->page()));
    }

    public function test_html_in_a_card_is_shown_as_text(): void
    {
        $this->wear([$this->card(['type' => 'shop', 'value' => ''], ['title_ar' => '<img src=x onerror=alert(1)>'])]);
        $html = $this->page();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_a_shop_without_a_theme_cannot_save_a_bar(): void
    {
        $this->shop->update(['storefront_theme' => null]);

        $this->save($this->bar([$this->card(['type' => 'shop', 'value' => ''])]))->assertForbidden();
    }

    public function test_another_shop_never_shows_this_shops_bar(): void
    {
        $this->wear([$this->card(['type' => 'shop', 'value' => ''])]);

        $this->assertStringNotContainsString('rb-visual-bar', $this->page('/', 'ar', 'jaar'));
    }

    /* ═══════════════════════ المسوّدةُ والنشر ═══════════════════════ */

    public function test_a_saved_bar_waits_for_publishing(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->assertContains(StoreVisualBar::KEY, StoreContent::VERSIONED);

        $this->wear([$this->card(['type' => 'shop', 'value' => ''], ['title_ar' => 'قريبًا'])]);
        $this->assertStringNotContainsString('rb-visual-bar', $this->page(), 'المسوّدةُ لا تبلغ الزبون');
        $this->assertContains(StoreVisualBar::KEY, StoreContent::changed($this->shop->id));

        // والمعاينةُ ترسم المسوّدةَ لصاحبها وحده
        $preview = $this->actingAs($this->owner)->get(route('admin.store.preview'))->assertOk()->getContent();
        $this->assertSame(['قريبًا'], $this->titles($preview));

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'الشريط المصوّر');
        $this->assertSame(['قريبًا'], $this->titles($this->page()));
    }

    /* ═══════════════════════ المحرّر ═══════════════════════ */

    public function test_the_editor_offers_the_bar_first_to_every_ribbon_shop(): void
    {
        foreach ([$this->shop, $this->neighbour] as $shop) {
            $rows = PageEditor::rows((int) $shop->id);
            $this->assertSame(PageEditor::VISUAL_BAR, $rows[0]['key'], 'الشريطُ أوّلُ ما يُرى — أوّلُ الصفوف');
            $this->assertSame('visualbar', $rows[0]['fields'][0]['kind']);
            $this->assertSame(StoreVisualBar::MAX_CARDS, $rows[0]['fields'][0]['max']);
        }

        $this->assertContains(StoreVisualBar::KEY, PageEditor::omitted());
    }
}
