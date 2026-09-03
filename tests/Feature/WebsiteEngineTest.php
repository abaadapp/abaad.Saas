<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Content;
use App\Support\Website\Publisher;
use App\Support\Website\Sections;
use App\Support\Website\Templates;
use App\Support\Website\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * محرّك الموقع — من جوابين إلى موقعٍ يصلح للنشر.
 *
 * وما يُحرَس هنا ثلاثة: أنّ التاجر لا يبدأ من فراغ، وأنّ ما يكتبه لا يخرج
 * إلى موقعٍ عامّ بلا تنظيف، وأنّ تعديله لا يمسّ ما نُشر حتى يَنشر.
 */
class WebsiteEngineTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@abaad.om',
            'city' => 'مسقط', 'address' => 'الخوير', 'logo' => '/storage/logo.png',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->bid(), 'key' => $key], ['value' => $value]);
    }

    private function catalogue(): void
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->bid(), 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true,
        ]);
    }

    private function build(string $goal = Blueprints::STORE, string $template = 'modern'): Website
    {
        return Builder::create($this->business, $goal, $template, $this->owner->id);
    }

    /* ===================== لا أحد يبدأ من فراغ ===================== */

    public function test_two_answers_build_a_whole_website(): void
    {
        $this->catalogue();
        $site = $this->build();

        $this->assertSame(4, $site->pages()->count(), 'موقعٌ ناقص الصفحات');
        $this->assertGreaterThan(8, WebsiteSection::where('website_id', $site->id)->count());
        // والترويسة والتذييل معه: موقعٌ بلا قائمةٍ صفحاتٌ لا يصل بينها شيء
        $this->assertNotNull($site->header());
        $this->assertNotNull($site->footer());
    }

    public function test_the_home_page_exists_and_is_not_removable(): void
    {
        $home = $this->build()->homePage();

        $this->assertNotNull($home);
        $this->assertSame('/', $home->slug);
        $this->assertFalse($home->removable, 'موقعٌ تُحذف صفحتُه الأولى نطاقٌ يردّ بلا شيء');
    }

    public function test_the_goal_decides_what_exists(): void
    {
        /*
         * وهذا هو Progressive Disclosure في البنية لا في الشاشة: من اختار
         * «تعريفيّ» لا يملك «الأكثر مبيعًا» ولا سلّةً في ترويسته أصلًا —
         * فلا حاجة إلى إخفاءٍ ولا إلى إعدادٍ يُطفأ.
         */
        $profile = $this->build(Blueprints::PROFILE);
        $types = WebsiteSection::where('website_id', $profile->id)->pluck('type');

        $this->assertFalse($types->contains('best_sellers'));
        $this->assertFalse($types->contains('featured_products'));
        $this->assertArrayNotHasKey('show_cart', $profile->header()->data);
        $this->assertFalse($profile->sells());
    }

    public function test_a_store_carries_the_shop_and_the_cart(): void
    {
        $this->catalogue();
        $store = $this->build(Blueprints::STORE);

        $this->assertTrue($store->pages()->where('key', 'shop')->exists());
        $this->assertTrue($store->header()->data['show_cart']);
        $this->assertTrue($store->sells());
    }

    public function test_a_section_with_nothing_to_show_is_not_built(): void
    {
        // متجرٌ بلا تصنيفات ولا تقييمات: شريطان فارغان يُقبّحان الموقع
        $site = $this->build();
        $types = WebsiteSection::where('website_id', $site->id)->pluck('type');

        $this->assertFalse($types->contains('categories'));
        $this->assertFalse($types->contains('testimonials'));
        $this->assertTrue($types->contains('hero'), 'سقط ما لا مصدر له أصلًا');
    }

    public function test_reviews_bring_their_section_back(): void
    {
        Review::create([
            'business_id' => $this->bid(), 'author_name' => 'زبون',
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);

        $types = WebsiteSection::where('website_id', $this->build()->id)->pluck('type');

        $this->assertTrue($types->contains('testimonials'));
    }

    /* ===================== ما يعرفه النظام لا يُسأل عنه ===================== */

    public function test_the_merchants_own_data_fills_the_site(): void
    {
        $this->setting('site_tagline', 'أجمل الورود في مسقط');
        $this->setting('site_about', 'نصمّم الباقات منذ عشر سنوات.');
        $this->setting('site_instagram', '@wuroodmuscat');

        $site = $this->build();
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $contact = WebsiteSection::where('website_id', $site->id)->where('type', 'contact')->firstOrFail();
        $social = WebsiteSection::where('website_id', $site->id)->where('type', 'social')->firstOrFail();

        $this->assertSame('أجمل الورود في مسقط', $hero->data['title']);
        $this->assertSame('نصمّم الباقات منذ عشر سنوات.', $hero->data['subtitle']);
        $this->assertSame('96890000000', $contact->data['phone']);
        $this->assertSame('shop@abaad.om', $contact->data['email']);
        $this->assertStringContainsString('الخوير', $contact->data['address']);
        // والعلامة تُنزع: الرابط يُبنى بلا «@»
        $this->assertSame([['network' => 'instagram', 'value' => 'wuroodmuscat']], $social->data['accounts']);
    }

    public function test_the_dead_settings_finally_have_a_reader(): void
    {
        // خمسة مفاتيح كانت تُحفظ منذ نسخٍ ولا يقرؤها شيء في النظام كلّه
        $this->setting('site_whatsapp', '96899999999');
        $site = $this->build();

        $this->assertSame('96899999999', $site->header()->data['show_whatsapp'] ? '96899999999' : '');
        $contact = WebsiteSection::where('website_id', $site->id)->where('type', 'contact')->firstOrFail();
        $this->assertSame('96899999999', $contact->data['whatsapp']);
    }

    public function test_a_second_website_is_never_built(): void
    {
        $first = $this->build();
        $again = $this->build(Blueprints::PROFILE, 'luxury');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Website::where('business_id', $this->bid())->count());
    }

    /* ===================== ما يُكتب يُنظَّف قبل أن يُحفظ ===================== */

    public function test_a_script_never_reaches_the_public_site(): void
    {
        $clean = Content::clean('hero', [
            'title' => '<script>alert(1)</script>مرحبًا',
            'subtitle' => '<b>نصّ</b> عريض',
        ]);

        $this->assertSame('مرحبًا', $clean['title']);
        $this->assertSame('نصّ عريض', $clean['subtitle']);
    }

    public function test_a_javascript_link_is_refused(): void
    {
        $clean = Content::clean('hero', [
            'cta_href' => 'javascript:alert(document.cookie)',
        ]);

        $this->assertSame('', $clean['cta_href']);
    }

    public function test_the_links_that_are_allowed_survive(): void
    {
        foreach (['/shop', 'https://abaad.om', 'tel:96890000000', 'mailto:a@b.om'] as $href) {
            $this->assertSame($href, Content::clean('hero', ['cta_href' => $href])['cta_href'], $href);
        }

        // و«//host» ليس نسبيًّا رغم شكله: يقود إلى موقعٍ آخر بالبروتوكول نفسه
        $this->assertSame('', Content::clean('hero', ['cta_href' => '//evil.example'])['cta_href']);
    }

    public function test_an_unknown_key_is_never_stored(): void
    {
        $clean = Content::clean('hero', ['title' => 'عنوان', 'onclick' => 'steal()', 'admin' => true]);

        $this->assertArrayNotHasKey('onclick', $clean);
        $this->assertArrayNotHasKey('admin', $clean);
        $this->assertArrayHasKey('title', $clean);
    }

    public function test_a_number_stays_inside_its_bounds(): void
    {
        $this->assertSame(24, Content::clean('latest_products', ['limit' => 9999])['limit']);
        $this->assertSame(2, Content::clean('latest_products', ['limit' => -5])['limit']);
    }

    public function test_an_empty_list_row_is_dropped(): void
    {
        $clean = Content::clean('faq', ['items' => [
            ['q' => 'سؤال', 'a' => 'جواب'],
            ['q' => '', 'a' => ''],
        ]]);

        $this->assertCount(1, $clean['items']);
    }

    public function test_an_unknown_social_network_is_dropped(): void
    {
        $clean = Content::clean('social', ['accounts' => [
            ['network' => 'instagram', 'value' => '@shop'],
            ['network' => 'myspace', 'value' => 'shop'],
            ['network' => 'instagram', 'value' => 'shop2'],
        ]]);

        $this->assertSame([['network' => 'instagram', 'value' => 'shop']], $clean['accounts']);
    }

    /* ===================== التصميم يبقى مقروءًا ===================== */

    public function test_unreadable_text_is_corrected(): void
    {
        // رماديٌّ فاتح على أبيض: التاجر لا يريد موقعًا لا يُقرأ، ولا يعرف أنّه فعل
        $theme = Theme::normalize(['background' => '#ffffff', 'text' => '#eeeeee']);

        $this->assertSame('#111111', $theme['text']);
        $this->assertGreaterThanOrEqual(Theme::MIN_CONTRAST, Theme::contrast($theme['text'], $theme['background']));
    }

    public function test_readable_text_is_left_alone(): void
    {
        $theme = Theme::normalize(['background' => '#ffffff', 'text' => '#333333']);

        $this->assertSame('#333333', $theme['text']);
    }

    public function test_what_is_written_over_the_primary_colour_is_derived(): void
    {
        $this->assertSame('#ffffff', Theme::tokens(['primary' => '#111111'])['on_primary']);
        $this->assertSame('#111111', Theme::tokens(['primary' => '#fde047'])['on_primary']);
    }

    public function test_a_colour_that_is_not_a_colour_falls_back(): void
    {
        $theme = Theme::normalize(['primary' => 'red; background:url(x)'], ['primary' => '#2563eb']);

        $this->assertSame('#2563eb', $theme['primary']);
        // والمختصر يُمدّ: «#fff» لونٌ صحيح يكتبه الناس
        $this->assertSame('#ffffff', Theme::normalize(['primary' => '#fff'])['primary']);
    }

    public function test_an_unknown_template_falls_back_to_the_default(): void
    {
        $this->assertSame(Templates::DEFAULT, Templates::key('nope'));
        $this->assertSame('luxury', Templates::key('luxury'));
    }

    /* ===================== المسوّدة والمنشور ===================== */

    public function test_a_new_website_is_a_draft(): void
    {
        $site = $this->build();

        $this->assertSame(Website::DRAFT, $site->state());
        $this->assertNull($site->published_version_id);
    }

    public function test_publishing_freezes_a_version(): void
    {
        $site = $this->build();
        $version = Publisher::publish($site, $this->owner->id);

        $this->assertSame(1, $version->number);
        $this->assertSame($version->id, $site->fresh()->published_version_id);
        $this->assertSame(Website::PUBLISHED, $site->fresh()->state());
        $this->assertSame($this->owner->id, $version->created_by);
        $this->assertNotEmpty($version->payload['pages']);
    }

    public function test_editing_after_publishing_says_so(): void
    {
        /*
         * التاجر يعدّل ثمّ ينسى أنّه لم ينشر، فيسأل لماذا لا يرى الزائر ما
         * عدّله. والحالُ تقول له قبل أن يسأل.
         */
        $site = $this->build();
        Publisher::publish($site, $this->owner->id);
        $this->assertSame(Website::PUBLISHED, $site->fresh()->state());

        // في الثانية نفسها — والعدّاد لا يلتبس حيث يلتبس الوقت
        $site->fresh()->touchDraft();

        $this->assertSame(Website::CHANGED, $site->fresh()->state());
    }

    public function test_the_draft_never_touches_the_published_version(): void
    {
        $this->setting('site_tagline', 'العنوان الأول');
        $site = $this->build();
        $version = Publisher::publish($site, $this->owner->id);

        WebsiteSection::where('website_id', $site->id)->where('type', 'hero')
            ->update(['data' => json_encode(['title' => 'العنوان الثاني'])]);

        $published = collect($version->fresh()->payload['pages'])->firstWhere('key', 'home');
        $hero = collect($published['sections'])->firstWhere('type', 'hero');

        $this->assertSame('العنوان الأول', $hero['data']['title'], 'تسرّب تعديل المسوّدة إلى المنشور');
    }

    public function test_restoring_brings_the_old_draft_back_without_publishing(): void
    {
        $site = $this->build();
        $first = Publisher::publish($site, $this->owner->id);

        $site->update(['name' => 'اسمٌ آخر', 'template' => 'luxury']);
        WebsitePage::where('website_id', $site->id)->where('key', 'about')->delete();
        Publisher::publish($site->fresh(), $this->owner->id);

        Publisher::restore($site->fresh(), $first);

        $this->assertSame(4, $site->fresh()->pages()->count());
        $this->assertSame('modern', $site->fresh()->template);
        // والاستعادة لا تنشر: يعاين التاجر ثم ينشر إن رضي
        $this->assertSame(Website::CHANGED, $site->fresh()->state());
    }

    public function test_a_neighbours_version_is_never_restored(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Builder::create($other, Blueprints::PROFILE, 'bold');
        $theirVersion = Publisher::publish($theirs);

        $mine = $this->build();
        $before = $mine->template;

        Publisher::restore($mine, $theirVersion);

        $this->assertSame($before, $mine->fresh()->template, 'استُعيدت نسخة الجار في موقعنا');
    }

    /* ===================== القائمة تتبع الصفحات ===================== */

    public function test_a_new_page_appears_in_the_menu(): void
    {
        $site = $this->build();

        WebsitePage::create([
            'website_id' => $site->id, 'business_id' => $this->bid(),
            'key' => 'faq', 'title' => 'أسئلة شائعة', 'slug' => '/faq',
            'status' => WebsitePage::PUBLISHED, 'position' => 9,
        ]);
        Builder::syncMenu($site->fresh());

        $hrefs = collect($site->fresh()->header()->data['links'])->pluck('href');

        $this->assertTrue($hrefs->contains('/faq'));
    }

    public function test_a_custom_external_link_survives_the_sync(): void
    {
        $site = $this->build();
        $header = $site->header();
        $data = $header->data;
        $data['links'][] = ['label' => 'مدوّنتنا', 'href' => 'https://blog.example.om'];
        $header->update(['data' => $data]);

        Builder::syncMenu($site->fresh());

        $hrefs = collect($site->fresh()->header()->data['links'])->pluck('href');

        $this->assertTrue($hrefs->contains('https://blog.example.om'));
    }

    public function test_a_link_to_a_deleted_page_is_dropped(): void
    {
        $site = $this->build();
        WebsitePage::where('website_id', $site->id)->where('key', 'about')->delete();

        Builder::syncMenu($site->fresh());

        $hrefs = collect($site->fresh()->header()->data['links'])->pluck('href');

        $this->assertFalse($hrefs->contains('/about'), 'قائمةٌ تقود إلى «غير موجود»');
    }

    /* ===================== المكتبة تتبع ما يملكه المتجر ===================== */

    public function test_the_library_hides_what_the_shop_cannot_show(): void
    {
        $types = collect(Sections::library(Blueprints::STORE, [
            'products' => true, 'categories' => false, 'reviews' => false,
        ]))->pluck('type');

        $this->assertTrue($types->contains('latest_products'));
        $this->assertFalse($types->contains('categories'));
        $this->assertFalse($types->contains('testimonials'));
        // والأقسام العامّة ليست من المكتبة: تُبنى مرّةً ولا تُضاف
        $this->assertFalse($types->contains(Sections::HEADER));
    }

    public function test_the_library_follows_the_goal(): void
    {
        $profile = collect(Sections::library(Blueprints::PROFILE, ['products' => true]))->pluck('type');

        $this->assertFalse($profile->contains('best_sellers'));
        $this->assertTrue($profile->contains('gallery'));
    }
}
