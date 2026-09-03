<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قسم الموقع من بابه — ما يراه التاجر وما يُردّ عنه.
 *
 * والسؤال الذي تحرسه هذه الاختبارات واحد: **هل يستطيع من لا يعرف شيئًا عن
 * تصميم المواقع أن يخرج بموقعٍ منشور؟** فتُقرأ كما تُقرأ رحلته: يفتح القسم،
 * يجيب سؤالين، يجد بياناته، يعدّل، يعاين، ينشر.
 *
 * وتحرس معها ما لا يراه: أنّ جاره لا يصل إلى صفحاته، وأنّ من لم يُمنح القسم
 * لا يفتحه، وأنّ ما يعدّله لا يمسّ ما نشره.
 */
class WebsiteBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@abaad.om', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function screen(string $url): string
    {
        return $this->get($url)->assertOk()->viewData('page')['component'];
    }

    private function build(string $goal = Blueprints::STORE): Website
    {
        return Builder::create($this->business, $goal, 'modern', $this->owner->id);
    }

    private function catalogue(): void
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->bid(), 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true,
        ]);
    }

    /* ================== أوّل مرّة: معالجٌ لا شاشةُ إعدادات ================== */

    public function test_a_merchant_with_no_site_meets_the_wizard(): void
    {
        $this->assertSame('Admin/Website/Wizard', $this->screen(route('admin.website.index')));
    }

    public function test_the_wizard_shows_the_merchant_his_own_data(): void
    {
        $this->catalogue();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_tagline', 'value' => 'أجمل الورود']);

        $props = $this->props(route('admin.website.index'));

        $this->assertSame('ورود مسقط', $props['identity']['name']);
        $this->assertSame('أجمل الورود', $props['identity']['tagline']);
        $this->assertSame('96890000000', $props['identity']['phone']);
        $this->assertSame(1, $props['counts']['products']);
        $this->assertCount(3, $props['goals']);
        $this->assertNotEmpty($props['templates']);
    }

    public function test_two_answers_end_in_the_editor(): void
    {
        $this->post(route('admin.website.create'), [
            'goal' => Blueprints::STORE, 'template' => 'modern', 'name' => 'ورود مسقط',
        ])->assertRedirect();

        $site = Website::where('business_id', $this->bid())->firstOrFail();

        $this->assertSame(4, $site->pages()->count());
        $this->assertSame(Website::DRAFT, $site->state());
        // ولا شيء يظهر للزائر: النشر فعلٌ مستقلّ
        $this->assertNull($site->published_version_id);
    }

    public function test_a_second_site_is_refused_and_the_first_is_opened(): void
    {
        $this->build();

        $this->post(route('admin.website.create'), ['goal' => Blueprints::PROFILE, 'template' => 'luxury'])
            ->assertRedirect(route('admin.website.index'));

        $this->assertSame(1, Website::where('business_id', $this->bid())->count());
        $this->assertSame('modern', Website::where('business_id', $this->bid())->value('template'));
    }

    public function test_a_bad_answer_is_refused(): void
    {
        $this->post(route('admin.website.create'), ['goal' => 'anything', 'template' => 'nope'])
            ->assertSessionHasErrors(['goal', 'template']);

        $this->assertSame(0, Website::count());
    }

    /* ================== بعد الإنشاء: لوحةٌ لا إعدادات ================== */

    public function test_a_merchant_with_a_site_meets_the_dashboard(): void
    {
        $this->build();

        $this->assertSame('Admin/Website/Dashboard', $this->screen(route('admin.website.index')));
    }

    public function test_the_dashboard_says_where_the_site_stands(): void
    {
        $site = $this->build();
        $props = $this->props(route('admin.website.index'));

        $this->assertSame(Website::DRAFT, $props['site']['state']);
        $this->assertTrue($props['site']['changes']);
        $this->assertNull($props['site']['url']);
        $this->assertSame(4, $props['summary']['pages']);

        Publisher::publish($site, $this->owner->id);

        $this->assertSame(Website::PUBLISHED, $this->props(route('admin.website.index'))['site']['state']);
    }

    public function test_the_site_url_appears_once_a_domain_is_set(): void
    {
        $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);

        $this->assertStringContainsString('wurood.om', (string) $this->props(route('admin.website.index'))['site']['url']);
    }

    public function test_a_sub_screen_without_a_site_leads_to_the_wizard(): void
    {
        /*
         * ورابطٌ محفوظ لا يقود إلى «غير موجود»: تلك تقول للتاجر إنّ الصفحة
         * معطوبة، والصحيح أن يُقال له إنّ موقعه لم يُنشأ بعد.
         */
        foreach (['pages', 'design', 'shop', 'seo', 'editor'] as $screen) {
            $this->get(route('admin.website.'.$screen))->assertRedirect(route('admin.website.index'));
        }
    }

    /* ========================== الصفحات والأقسام ========================== */

    public function test_a_new_page_starts_from_a_template_not_a_blank(): void
    {
        $site = $this->build();

        $this->post(route('admin.website.pages.store'), [
            'title' => 'أسئلة شائعة', 'template' => 'faq',
        ])->assertRedirect();

        $page = WebsitePage::where('website_id', $site->id)->where('title', 'أسئلة شائعة')->firstOrFail();

        $this->assertSame('/أسئلة-شائعة', $page->slug);
        $this->assertGreaterThan(0, $page->sections()->count(), 'صفحةٌ فارغة — والقالب لم يُبنَ');
        // مسوّدةً أوّلًا: لا تظهر للزوّار قبل أن تُملأ
        $this->assertSame(WebsitePage::DRAFT, $page->status);
    }

    public function test_two_pages_never_share_a_link(): void
    {
        $site = $this->build();

        $this->post(route('admin.website.pages.store'), ['title' => 'من نحن', 'template' => 'blank']);

        $slugs = WebsitePage::where('website_id', $site->id)->pluck('slug');

        $this->assertSame($slugs->count(), $slugs->unique()->count());
    }

    public function test_the_home_page_is_not_deleted(): void
    {
        $site = $this->build();
        $home = $site->homePage();

        $this->delete(route('admin.website.pages.destroy', $home->id));

        $this->assertDatabaseHas('website_pages', ['id' => $home->id]);
    }

    public function test_the_home_pages_link_never_moves(): void
    {
        $site = $this->build();
        $home = $site->homePage();

        $this->put(route('admin.website.pages.update', $home->id), [
            'title' => 'البداية', 'slug' => '/start', 'status' => WebsitePage::HIDDEN,
        ])->assertSessionHasNoErrors();

        $this->assertSame('/', $home->fresh()->slug);
        $this->assertSame(WebsitePage::PUBLISHED, $home->fresh()->status);
        $this->assertSame('البداية', $home->fresh()->title);
    }

    public function test_a_section_is_added_filled_not_empty(): void
    {
        $site = $this->build(Blueprints::PROFILE);
        $page = $site->homePage();

        $this->post(route('admin.website.sections.add', $page->id), ['type' => 'faq'])
            ->assertSessionHasNoErrors();

        $section = WebsiteSection::where('page_id', $page->id)->where('type', 'faq')->firstOrFail();

        $this->assertNotEmpty($section->data['items'], 'قسمٌ أُضيف فارغًا — والموقع صار أسوأ بضغطة زر');
    }

    public function test_a_section_that_does_not_suit_the_goal_is_refused(): void
    {
        $site = $this->build(Blueprints::PROFILE);

        $this->post(route('admin.website.sections.add', $site->homePage()->id), ['type' => 'best_sellers'])
            ->assertSessionHasErrors('type');
    }

    public function test_a_unique_section_is_not_added_twice(): void
    {
        $site = $this->build(Blueprints::PROFILE);
        $page = $site->homePage();

        $this->post(route('admin.website.sections.add', $page->id), ['type' => 'map']);
        $this->post(route('admin.website.sections.add', $page->id), ['type' => 'map'])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, WebsiteSection::where('page_id', $page->id)->where('type', 'map')->count());
    }

    public function test_a_section_is_hidden_without_losing_what_is_in_it(): void
    {
        $site = $this->build();
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $before = $hero->data;

        $this->post(route('admin.website.sections.toggle', $hero->id));

        $this->assertFalse($hero->fresh()->visible);
        $this->assertSame($before, $hero->fresh()->data);
    }

    public function test_a_duplicate_lands_beside_its_original(): void
    {
        $site = $this->build();
        $benefits = WebsiteSection::where('website_id', $site->id)->where('type', 'benefits')->firstOrFail();

        $this->post(route('admin.website.sections.duplicate', $benefits->id));

        $copy = WebsiteSection::where('page_id', $benefits->page_id)->where('type', 'benefits')
            ->where('id', '!=', $benefits->id)->firstOrFail();

        $this->assertSame($benefits->position + 1, $copy->position);
        $this->assertSame($benefits->data, $copy->data);
    }

    public function test_a_section_that_cannot_repeat_is_not_duplicated(): void
    {
        // «الواجهة الرئيسية» واحدةٌ في الصفحة — ونسخُها يعني صفحةً برأسين
        $site = $this->build();
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();

        $this->post(route('admin.website.sections.duplicate', $hero->id));

        $this->assertSame(1, WebsiteSection::where('page_id', $hero->page_id)->where('type', 'hero')->count());
    }

    public function test_the_header_is_neither_added_nor_deleted(): void
    {
        $site = $this->build();
        $header = $site->header();

        $this->post(route('admin.website.sections.add', $site->homePage()->id), ['type' => 'header'])
            ->assertSessionHasErrors('type');

        $this->delete(route('admin.website.sections.destroy', $header->id))
            ->assertSessionHasErrors('section');

        $this->assertDatabaseHas('website_sections', ['id' => $header->id]);
    }

    public function test_reordering_puts_the_sections_where_they_were_put(): void
    {
        $site = $this->build();
        $page = $site->homePage();
        $ids = $page->sections()->pluck('id')->all();

        $this->post(route('admin.website.sections.reorder', $page->id), ['order' => array_reverse($ids)]);

        $this->assertSame(array_reverse($ids), $page->sections()->pluck('id')->all());
    }

    public function test_a_neighbours_section_is_never_moved_by_our_reorder(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Builder::create($other, Blueprints::STORE, 'bold');
        $theirSection = WebsiteSection::where('website_id', $theirs->id)->whereNotNull('page_id')->firstOrFail();
        $before = $theirSection->position;

        $site = $this->build();
        $page = $site->homePage();

        $this->post(route('admin.website.sections.reorder', $page->id), [
            'order' => [$theirSection->id, ...$page->sections()->pluck('id')->all()],
        ]);

        $this->assertSame($before, $theirSection->fresh()->position);
    }

    /* ============================ ما يُكتب يُنظَّف ============================ */

    public function test_what_the_merchant_types_is_cleaned_before_it_is_stored(): void
    {
        $site = $this->build();
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();

        $this->put(route('admin.website.sections.update', $hero->id), [
            'data' => [
                'title' => '<script>alert(1)</script>عنوان',
                'cta_href' => 'javascript:alert(1)',
                'evil' => 'x',
            ],
        ]);

        $data = $hero->fresh()->data;

        $this->assertSame('عنوان', $data['title']);
        $this->assertSame('', $data['cta_href']);
        $this->assertArrayNotHasKey('evil', $data);
    }

    public function test_editing_marks_the_site_as_changed(): void
    {
        $site = $this->build();
        Publisher::publish($site, $this->owner->id);
        $this->assertSame(Website::PUBLISHED, $site->fresh()->state());

        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $this->put(route('admin.website.sections.update', $hero->id), ['data' => ['title' => 'جديد']]);

        $this->assertSame(Website::CHANGED, $site->fresh()->state());
    }

    /* ============================== التصميم ============================== */

    public function test_switching_template_keeps_the_content(): void
    {
        $site = $this->build();
        $pages = $site->pages()->count();
        $sections = WebsiteSection::where('website_id', $site->id)->count();

        $this->put(route('admin.website.design.update'), ['template' => 'luxury', 'adopt' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('luxury', $site->fresh()->template);
        $this->assertSame('#b8860b', $site->fresh()->theme['primary']);
        $this->assertSame($pages, $site->fresh()->pages()->count());
        $this->assertSame($sections, WebsiteSection::where('website_id', $site->id)->count());
    }

    public function test_an_unreadable_palette_is_corrected_on_save(): void
    {
        $site = $this->build();

        $this->put(route('admin.website.design.palette'), [
            'theme' => ['background' => '#ffffff', 'text' => '#f5f5f5'],
        ]);

        $this->assertSame('#111111', $site->fresh()->theme['text']);
    }

    /* ============================ المتجر والسيو ============================ */

    public function test_a_profile_site_is_told_it_has_no_shop(): void
    {
        $this->build(Blueprints::PROFILE);

        $this->assertFalse($this->props(route('admin.website.shop'))['hasCatalogue']);
    }

    public function test_hiding_prices_closes_ordering(): void
    {
        $this->build(Blueprints::STORE);

        $this->put(route('admin.website.shop.save'), ['show_prices' => false, 'allow_orders' => true]);

        $saved = \App\Support\MarketingSettings::group($this->bid(), 'website');

        $this->assertSame('0', $saved['site_show_prices']);
        $this->assertSame('0', $saved['site_allow_orders'], 'يُطلب ما لا يُعرف ثمنه');
    }

    public function test_a_catalogue_site_never_takes_orders(): void
    {
        $this->build(Blueprints::CATALOG);

        $this->put(route('admin.website.shop.save'), ['show_prices' => true, 'allow_orders' => true]);

        $this->assertSame('0', \App\Support\MarketingSettings::group($this->bid(), 'website')['site_allow_orders']);
    }

    public function test_the_seo_screen_shows_pages_without_a_description(): void
    {
        $this->build();

        $props = $this->props(route('admin.website.seo'));

        $this->assertSame('ورود مسقط', $props['seo']['title']);
        $this->assertTrue($props['seo']['index']);
        $this->assertCount(4, $props['pages']);
    }

    public function test_saving_seo_also_feeds_the_old_screen(): void
    {
        // شاشة السيو القديمة تقرأ `seo_title` و`seo_description` — فلا تفترقان
        $this->build();

        $this->put(route('admin.website.seo.save'), [
            'title' => 'ورود مسقط · باقات', 'description' => 'باقات وهدايا', 'index' => true,
        ])->assertSessionHasNoErrors();

        $seo = \App\Support\MarketingSettings::group($this->bid(), 'seo');

        $this->assertSame('ورود مسقط · باقات', $seo['seo_title']);
        $this->assertSame('باقات وهدايا', $seo['seo_description']);
    }

    /* ============================ النشر والصيانة ============================ */

    public function test_a_site_with_no_published_page_is_not_published(): void
    {
        $site = $this->build();
        WebsitePage::where('website_id', $site->id)->update(['status' => WebsitePage::DRAFT]);

        $this->post(route('admin.website.publish'));

        $this->assertNull($site->fresh()->published_version_id);
    }

    public function test_publishing_then_restoring_walks_the_whole_path(): void
    {
        $site = $this->build();

        $this->post(route('admin.website.publish'))->assertSessionHasNoErrors();
        $first = $site->fresh()->published_version_id;
        $this->assertNotNull($first);

        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $this->put(route('admin.website.sections.update', $hero->id), ['data' => ['title' => 'عنوانٌ ثانٍ']]);
        $this->post(route('admin.website.publish'));

        $this->post(route('admin.website.restore', $first));

        $restored = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();

        $this->assertNotSame('عنوانٌ ثانٍ', $restored->data['title']);
        $this->assertSame(Website::CHANGED, $site->fresh()->state(), 'الاستعادة نشرت بنفسها');
    }

    public function test_maintenance_is_a_state_of_the_site_not_a_stray_switch(): void
    {
        $site = $this->build();
        Publisher::publish($site, $this->owner->id);

        $this->post(route('admin.website.maintenance'), ['maintenance' => true]);

        $this->assertTrue($site->fresh()->maintenance);
        $this->assertSame(Website::MAINTENANCE, $site->fresh()->state());
        // واللوحة تعمل كما هي: الصيانة تمنع الزائر لا التاجر
        $this->get(route('admin.website.index'))->assertOk();
    }

    /* ============================ الصلاحيات والعزل ============================ */

    public function test_whoever_was_not_granted_the_section_never_opens_it(): void
    {
        $this->build();

        $clerk = User::create([
            'business_id' => $this->bid(), 'name' => 'موظف', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => ['dashboard', 'settings'],
        ]);

        $this->actingAs($clerk)->get(route('admin.website.index'))->assertForbidden();
        $this->actingAs($clerk)->post(route('admin.website.publish'))->assertForbidden();
    }

    public function test_a_manager_opens_it(): void
    {
        $this->build();

        $manager = User::create([
            'business_id' => $this->bid(), 'name' => 'مدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($manager)->get(route('admin.website.index'))->assertOk();
    }

    public function test_a_neighbours_page_is_never_edited_from_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Builder::create($other, Blueprints::STORE, 'bold');
        $theirPage = $theirs->homePage();
        $theirSection = WebsiteSection::where('website_id', $theirs->id)->whereNotNull('page_id')->firstOrFail();

        $this->build();

        $this->get(route('admin.website.editor', $theirPage->id))->assertNotFound();
        $this->put(route('admin.website.pages.update', $theirPage->id), [
            'title' => 'مسروقة', 'status' => 'published',
        ])->assertNotFound();
        $this->put(route('admin.website.sections.update', $theirSection->id), ['data' => []])->assertNotFound();
        $this->post(route('admin.website.restore', $theirs->versions()->count() + 1))->assertNotFound();

        $this->assertNotSame('مسروقة', $theirPage->fresh()->title);
    }

    /* ============================ البيانات الناقصة ============================ */

    public function test_a_shop_with_nothing_in_it_still_gets_a_site(): void
    {
        /*
         * تاجرٌ فتح حسابه اليوم: لا منتج ولا تصنيف ولا تقييم ولا شعار. وهو
         * أوّل من سيفتح هذه الشاشة — فلا تُبنى له شاشةٌ تنكسر عند الفراغ.
         */
        $site = $this->build();

        $this->get(route('admin.website.index'))->assertOk();
        $this->get(route('admin.website.editor'))->assertOk();
        $this->get(route('admin.website.design'))->assertOk();

        $document = $this->props(route('admin.website.editor'))['document'];

        $this->assertNotEmpty($document['pages']);
        $this->assertSame('#2563eb', $document['tokens']['primary']);
    }

    /* ======================= ما يخرج إلى العارض الخارجيّ ======================= */

    public function test_an_unknown_domain_gets_nothing(): void
    {
        $this->get('/site/nowhere.om')->assertNotFound();
    }

    public function test_a_domain_whose_site_is_not_published_gets_nothing(): void
    {
        $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);

        $this->get('/site/wurood.om')->assertNotFound();
    }

    public function test_the_published_site_goes_out_with_its_catalogue(): void
    {
        $this->catalogue();
        $site = $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);
        Publisher::publish($site, $this->owner->id);

        $body = $this->get('/site/WUROOD.OM')->assertOk()->json();

        $this->assertSame(1, $body['version']);
        $this->assertSame('ورود مسقط', $body['site']['name']);
        $this->assertNotEmpty($body['site']['pages']);

        $home = collect($body['site']['pages'])->firstWhere('key', 'home');
        $products = collect($home['sections'])->firstWhere('type', 'featured_products');

        $this->assertSame('باقة ورد', $products['items'][0]['name']);
    }

    public function test_the_draft_never_leaves_the_building(): void
    {
        $site = $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);
        Publisher::publish($site, $this->owner->id);

        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $this->put(route('admin.website.sections.update', $hero->id), ['data' => ['title' => 'لم يُنشر']]);

        $body = $this->get('/site/wurood.om')->assertOk()->json();
        $home = collect($body['site']['pages'])->firstWhere('key', 'home');
        $published = collect($home['sections'])->firstWhere('type', 'hero');

        $this->assertNotSame('لم يُنشر', $published['data']['title']);
    }

    public function test_maintenance_answers_without_opening_the_site(): void
    {
        $site = $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);
        Publisher::publish($site, $this->owner->id);
        $site->update(['maintenance' => true, 'maintenance_message' => 'نعود بعد ساعة']);

        $body = $this->get('/site/wurood.om')->assertStatus(503)->json();

        $this->assertTrue($body['maintenance']);
        $this->assertSame('نعود بعد ساعة', $body['message']);
        $this->assertArrayNotHasKey('site', $body, 'كُشفت صفحاتُ موقعٍ في الصيانة');
    }

    public function test_each_domain_gets_its_own_site(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Builder::create($other, Blueprints::PROFILE, 'bold');
        Setting::create(['business_id' => $other->id, 'key' => 'site_domain', 'value' => 'jar.om']);
        Publisher::publish($theirs);

        $mine = $this->build();
        Setting::create(['business_id' => $this->bid(), 'key' => 'site_domain', 'value' => 'wurood.om']);
        Publisher::publish($mine, $this->owner->id);

        $this->assertSame('الجار', $this->get('/site/jar.om')->json('site.name'));
        $this->assertSame('ورود مسقط', $this->get('/site/wurood.om')->json('site.name'));
    }

    public function test_the_preview_carries_the_products_it_will_show(): void
    {
        $this->catalogue();
        $site = $this->build();

        $document = $this->props(route('admin.website.editor'))['document'];
        $home = collect($document['pages'])->firstWhere('key', 'home');
        $products = collect($home['sections'])->firstWhere('type', 'featured_products');

        $this->assertNotNull($products, 'قسم المنتجات لم يُبنَ لمتجرٍ له منتجات');
        $this->assertCount(1, $products['items']);
        $this->assertSame('باقة ورد', $products['items'][0]['name']);
        $this->assertSame(12.5, $products['items'][0]['final']);
    }
}
