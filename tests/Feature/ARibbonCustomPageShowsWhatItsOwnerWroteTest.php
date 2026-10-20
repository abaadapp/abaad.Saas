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
use App\Support\Store\PageEditor;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreNav;
use App\Support\Store\ThemePublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صفحةٌ مخصّصةٌ كتبها صاحبُ المتجر — «خدماتنا»: عنوانٌ ونبذةٌ وصورٌ وأقسامٌ وزرّ.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • تُفتح من رابطها `‎/page/{slug}‎` بعنوانها ونبذتها وصورتها وأقسامها بترتيبها
 *    وزرِّها إلى وجهةٍ في متجره — بلغتها، والعنوانُ يقع على الآخر إن فرغ.
 *  • والصورُ بأبعادها الحقيقيّة (لا قفزَ في الصفحة) وبطريقة عرضها: كاملة أو تملأ إطارها.
 *  • والمخفيّةُ والمجهولةُ «غير موجود» للزبون، والمخفيّةُ تُعايَن لصاحبها وحده.
 *  • ولا وسمَ يُرسم، ولا صورةَ من غير مجلّده، ولا زرَّ إلى متجرٍ آخر.
 *  • والمسوّدةُ لا تبلغ الزبون حتّى تُنشر — والسلّةُ والإتمامُ يعملان كما كانا.
 *
 * وقواعدُ الشاشة في `the-owner-writes-his-own-pages.test.tsx`.
 */
class ARibbonCustomPageShowsWhatItsOwnerWroteTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-03-10 10:00:00');
        Storage::fake('public');

        [$this->shop, $this->owner] = $this->ribbon('ribbon', 'owner@pages.test');
        [$this->neighbour] = $this->ribbon('jaar', 'jaar@pages.test');

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8, 'quantity' => 50,
            'alert_qty' => 1, 'category_id' => $cat->id, 'active' => true, 'published' => true,
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            StoreCollections::KEY => json_encode([['slug' => 'weddings', 'title_ar' => 'الأعراس', 'visible' => true, 'products' => [$this->rose->id]]], JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_fulfil' => 'delivery,pickup']);

        return [$shop, $owner];
    }

    /** صورةٌ حقيقيّةٌ في مجلّد المتجر — بأبعادها — ورابطُها كما يردّه الرفع */
    private function image(string $file, int $w, int $h, ?Business $shop = null): string
    {
        $shop ??= $this->shop;
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        Storage::disk('public')->put('website/'.$shop->id.'/'.$file, (string) ob_get_clean());

        return Storage::disk('public')->url('website/'.$shop->id.'/'.$file);
    }

    private function services(array $over = []): array
    {
        return $over + [
            'slug' => 'services', 'title_ar' => 'خدماتنا', 'title_en' => 'Our services',
            'intro_ar' => "نُنسّق الورد لكلّ مناسبة.\nونوصّله إلى بابك.", 'intro_en' => 'We arrange flowers for every occasion.',
            'image' => $this->image('hero.png', 1200, 600), 'fit' => 'contain',
            'sections' => [
                ['image' => $this->image('tall.png', 300, 900), 'fit' => 'contain', 'title_ar' => 'الأعراس', 'title_en' => 'Weddings', 'body_ar' => 'كوشةٌ وطاولات.', 'body_en' => 'Stages and tables.'],
                ['image' => '', 'title_ar' => 'الشركات', 'title_en' => '', 'body_ar' => 'باقاتُ المكاتب كلَّ أسبوع.', 'body_en' => ''],
                ['image' => $this->image('square.png', 500, 500), 'fit' => 'cover', 'title_ar' => '', 'title_en' => '', 'body_ar' => '', 'body_en' => ''],
            ],
            'cta' => ['label_ar' => 'تسوّق الأعراس', 'label_en' => 'Shop weddings', 'target' => ['type' => 'collection', 'value' => 'weddings']],
            'visible' => true,
        ];
    }

    private function save(array|string $pages, array $more = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            StoreCustomPages::KEY => is_string($pages) ? $pages : json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ] + $more);
    }

    private function html(string $path, string $lang = 'ar'): string
    {
        return $this->get('/s/ribbon'.$path.'?lang='.$lang)->assertOk()->getContent();
    }

    /* ═══════════════════════ الصفحة ═══════════════════════ */

    public function test_the_page_opens_from_its_link_with_all_its_owner_wrote(): void
    {
        $this->save([$this->services()])->assertSessionHasNoErrors();
        $html = $this->html('/page/services');

        $this->assertStringContainsString('<h1 class="rb-h1" style="font-size:clamp(28px,3.6vw,44px);line-height:1.2" data-testid="rb-page-title">خدماتنا</h1>', $html);
        $this->assertStringContainsString("نُنسّق الورد لكلّ مناسبة.\nونوصّله إلى بابك.", $html, 'الأسطرُ أسطرٌ كما كُتبت');
        $this->assertSame(3, substr_count($html, 'data-testid="rb-page-section"'));
        $this->assertMatchesRegularExpression('#الأعراس.*الشركات#su', $html, 'الأقسامُ بترتيب صاحبها');
        $this->assertStringContainsString('href="/s/ribbon/shop?collection=weddings" data-testid="rb-page-cta">تسوّق الأعراس</a>', $html);
        $this->assertStringContainsString('<title>خدماتنا — متجر ribbon</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="نُنسّق الورد لكلّ مناسبة. ونوصّله إلى بابك.">', $html);
    }

    public function test_images_keep_their_real_size_and_their_chosen_fit(): void
    {
        $this->save([$this->services()])->assertSessionHasNoErrors();
        $html = $this->html('/page/services');

        // الأبعادُ من الملفّ نفسِه — فيُحجز مكانُها قبل أن تصل، ولا تقفز الصفحة
        $this->assertMatchesRegularExpression('#data-testid="rb-page-image" data-fit="contain">\s*<img[^>]+width="1200" height="600"#', $html);
        $this->assertMatchesRegularExpression('#class="rb-cp-figure is-contain" data-testid="rb-page-section-image" data-fit="contain">\s*<img[^>]+width="300" height="900"#', $html, 'الطوليّةُ كاملةً بنسبتها');
        $this->assertMatchesRegularExpression('#class="rb-cp-figure is-cover" data-testid="rb-page-section-image" data-fit="cover"#', $html);
        // و«كاملة» طولُها محدودٌ بالشاشة — صورةٌ طويلةٌ لا تبتلع الصفحة
        $this->assertStringContainsString('max-height: min(78vh, 720px); object-fit: contain;', $html);
    }

    public function test_the_english_page_reads_its_own_words_and_borrows_only_the_title(): void
    {
        $this->save([$this->services(['title_en' => ''])])->assertSessionHasNoErrors();
        $html = $this->html('/page/services', 'en');

        $this->assertStringContainsString('data-testid="rb-page-title">خدماتنا</h1>', $html, 'العنوانُ اسمٌ — يقع على العربيّ');
        $this->assertStringContainsString('We arrange flowers for every occasion.', $html);
        $this->assertStringNotContainsString('نُنسّق الورد', $html, 'النبذةُ العربيّة لا تُعرض في الصفحة الإنجليزيّة');
        // وقسمُ «الشركات» بلا نصٍّ إنجليزيٍّ ولا صورة لا يُرسم فيها — والصورةُ وحدها للّغتين
        $this->assertSame(2, substr_count($html, 'data-testid="rb-page-section"'));
        $this->assertStringContainsString('data-testid="rb-page-cta">Shop weddings</a>', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function missing(): array
    {
        return ['اسمٌ لا يُعرف' => ['/page/nothing'], 'اسمٌ غيرُ صالح' => ['/page/Bad_Slug'], 'بلا اسم' => ['/page'], 'مقطعٌ ثالث' => ['/page/services/x']];
    }

    #[DataProvider('missing')]
    public function test_what_does_not_exist_is_not_found(string $path): void
    {
        $this->save([$this->services()])->assertSessionHasNoErrors();

        $this->get('/s/ribbon'.$path)->assertNotFound();
    }

    public function test_a_hidden_page_is_not_found_by_customers_and_previewed_by_its_owner(): void
    {
        $this->save([$this->services(['visible' => false])])->assertSessionHasNoErrors();

        $this->get('/s/ribbon/page/services')->assertNotFound();

        $preview = $this->actingAs($this->owner)->get(route('admin.store.preview', ['page' => 'page', 'slug' => 'services']))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="rb-page-hidden"', $preview);
        $this->assertStringContainsString('data-testid="rb-page-title">خدماتنا</h1>', $preview);
    }

    public function test_an_unsaved_page_is_previewed_from_the_screen(): void
    {
        $draft = json_encode([StoreCustomPages::KEY => json_encode([$this->services(['title_ar' => 'قبل الحفظ'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        $preview = $this->actingAs($this->owner)
            ->post(route('admin.store.preview', ['page' => 'page', 'slug' => 'services']), ['draft' => $draft])
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="rb-page-title">قبل الحفظ</h1>', $preview);
        $this->get('/s/ribbon/page/services')->assertNotFound();
    }

    public function test_html_written_in_a_page_is_shown_as_text(): void
    {
        $this->save([$this->services(['title_ar' => '<script>alert(1)</script>', 'intro_ar' => '<img src=x onerror=alert(1)>'])])->assertSessionHasNoErrors();
        $html = $this->html('/page/services');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_page_in_the_menu_opens_from_it(): void
    {
        $this->save([$this->services()], [StoreNav::MENU => json_encode([
            ['type' => 'page', 'key' => 'home'],
            ['type' => 'link', 'id' => 'svc', 'title_ar' => 'خدماتنا', 'title_en' => 'Services', 'target' => ['type' => 'page', 'value' => 'services'], 'visible' => true],
            ['type' => 'page', 'key' => 'shop'],
        ], JSON_UNESCAPED_UNICODE)])->assertSessionHasNoErrors();

        $home = $this->html('/');
        preg_match('#<a href="([^"]+)"\s+class="rb-nav-a[^"]*"\s+data-testid="rb-nav-link-svc"#', $home, $m);
        $this->assertSame('/s/ribbon/page/services', $m[1] ?? null);
        $this->html($m[1] === '' ? '/' : substr($m[1], strlen('/s/ribbon')));
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    /** @return array<string, array{0: \Closure}> */
    public static function forged(): array
    {
        return [
            'صورةٌ من موقعٍ آخر' => [fn (self $t) => $t->services(['image' => 'https://tracker.example/pixel.png'])],
            'صورةٌ من مجلّد الجار' => [fn (self $t) => $t->services(['image' => $t->image('n.png', 10, 10, $t->neighbour)])],
            'صورةُ قسمٍ javascript' => [fn (self $t) => $t->services(['sections' => [['image' => 'javascript:alert(1)', 'title_ar' => 'x']]])],
            // والرابطُ المكتوبُ يُقبل آمنًا وحده (`StoreTarget::safeUrl`) — لا شيفرةٌ ولا نطاقٌ بلا بروتوكول
            'زرٌّ إلى javascript' => [fn (self $t) => $t->services(['cta' => ['label_ar' => 'اذهب', 'target' => ['type' => 'url', 'value' => 'javascript:alert(1)']]])],
            'زرٌّ إلى //نطاقٍ آخر' => [fn (self $t) => $t->services(['cta' => ['label_ar' => 'اذهب', 'target' => ['type' => 'url', 'value' => '//evil.example']]])],
            'زرٌّ إلى data:' => [fn (self $t) => $t->services(['cta' => ['label_ar' => 'اذهب', 'target' => ['type' => 'url', 'value' => 'data:text/html,<script>alert(1)</script>']]])],
            'زرٌّ إلى مجموعةٍ لا توجد' => [fn (self $t) => $t->services(['cta' => ['label_ar' => 'اذهب', 'target' => ['type' => 'collection', 'value' => 'nothing']]])],
            'قسمٌ فارغ' => [fn (self $t) => $t->services(['sections' => [['image' => '', 'title_ar' => '', 'body_ar' => '']]])],
            'بلا عنوان' => [fn (self $t) => $t->services(['title_ar' => '', 'title_en' => ''])],
            'رابطٌ غير صالح' => [fn (self $t) => $t->services(['slug' => 'خدماتنا'])],
            'طريقةُ عرضٍ غريبة' => [fn (self $t) => $t->services(['fit' => 'stretch'])],
            'نصٌّ أطولُ من حدّه' => [fn (self $t) => $t->services(['intro_ar' => str_repeat('ن', StoreCustomPages::INTRO_MAX + 1)])],
        ];
    }

    #[DataProvider('forged')]
    public function test_a_forged_page_is_refused_and_nothing_is_saved(\Closure $page): void
    {
        $this->save([$page($this)])->assertSessionHasErrors(StoreCustomPages::KEY);

        $this->assertSame('', (string) (MarketingSettings::group($this->shop->id, 'website')[StoreCustomPages::KEY] ?? ''));
    }

    public function test_two_pages_with_one_link_and_too_many_pages_are_refused(): void
    {
        $this->save([$this->services(), $this->services()])->assertSessionHasErrors(StoreCustomPages::KEY);

        $many = array_map(fn ($i) => $this->services(['slug' => 'p'.$i, 'sections' => [], 'image' => '']), range(1, StoreCustomPages::MAX + 1));
        $this->save($many)->assertSessionHasErrors(StoreCustomPages::KEY);
    }

    public function test_a_button_may_open_another_page_saved_with_it(): void
    {
        $this->save([
            $this->services(['cta' => ['label_ar' => 'عروضنا', 'label_en' => '', 'target' => ['type' => 'page', 'value' => 'offers']]]),
            $this->services(['slug' => 'offers', 'title_ar' => 'عروضنا', 'sections' => [], 'cta' => null]),
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('href="/s/ribbon/page/offers" data-testid="rb-page-cta">عروضنا</a>', $this->html('/page/services'));
    }

    public function test_a_shop_without_a_theme_cannot_write_pages(): void
    {
        $this->shop->forceFill(['storefront_theme' => null])->save();

        $this->save([$this->services()])->assertForbidden();
    }

    public function test_a_saved_page_waits_for_publishing(): void
    {
        $this->assertContains(StoreCustomPages::KEY, StoreContent::VERSIONED);
        // والصفحاتُ تُكتب في صفّ القائمة نفسِه — مع عناصرها، لا في صفٍّ ثانٍ
        $menu = collect(PageEditor::rows($this->shop->id))->firstWhere('key', PageEditor::MENU);
        $this->assertContains(StoreCustomPages::KEY, array_column($menu['fields'], 'key'));

        ThemePublisher::enable($this->shop, (int) $this->owner->id);
        $this->save([$this->services()])->assertSessionHasNoErrors();
        $this->get('/s/ribbon/page/services')->assertNotFound();

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'خدماتنا');
        $this->get('/s/ribbon/page/services')->assertOk();
    }

    /* ═══════════════════════ والبيعُ كما كان ═══════════════════════ */

    public function test_pages_and_a_written_menu_do_not_break_the_cart_or_the_checkout(): void
    {
        $this->save([$this->services()], [StoreNav::MENU => json_encode([
            ['type' => 'link', 'id' => 'svc', 'title_ar' => 'خدماتنا', 'title_en' => '', 'target' => ['type' => 'page', 'value' => 'services'], 'visible' => true],
            ['type' => 'page', 'key' => 'shop'], ['type' => 'page', 'key' => 'home'],
        ], JSON_UNESCAPED_UNICODE)])->assertSessionHasNoErrors();

        foreach (['/', '/shop', '/p/'.$this->rose->id, '/cart', '/checkout'] as $path) {
            $this->get('/s/ribbon'.$path)->assertOk();
        }

        $quote = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $this->rose->id, 'qty' => 2]], 'fulfil' => 'pickup'])->assertOk()->json();
        $this->assertTrue($quote['ok']);

        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->rose->id, 'qty' => 2]], 'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001', 'date' => '2027-03-12', 'agreed_total' => $quote['total'],
        ])->assertOk();

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count(), 'طلبٌ واحدٌ كُتب كما كان يُكتب');
    }
}
