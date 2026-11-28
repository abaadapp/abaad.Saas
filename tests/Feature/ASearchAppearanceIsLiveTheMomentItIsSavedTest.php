<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreSite;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteVersion;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreSeo;
use App\Support\Store\ThemePublisher;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domains;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use App\Support\Website\SearchReadiness;
use App\Support\Website\Sitemap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «الظهور في البحث» يسري بالحفظ — لكلّ تاجرٍ يملك الموقع، بنوعَي موقعه.
 *
 * ═══ ما كان ═══
 *
 * الموقعُ المبنيّ يحفظ سيوَه في صفّه والزائرُ يقرأ آخرَ نشرة، والواجهةُ
 * الخاصّة تحفظ نصَّه في المسوّدة. فيقرأ صاحبُ المتجر «حُفظ» ويبقى غوغل على
 * القديم حتّى ينشر تصميمَه كلَّه — ونشرُ مسوّدةٍ قديمة أو استرجاعُ نسخةٍ
 * سابقة يُعيد عنوانًا كتب فوقه.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أن يسري الحفظُ على الصفحة المنشورة بلا نشر ولا يمسّ المسوّدة، وألّا
 * يعود القديمُ بنشرٍ أو استرجاع، وأن يبقى ما نُشر قبل الترقية كما هو حتّى
 * يحفظ صاحبُه، وأن تُطاع الباقةُ والصلاحيّة ويُعزل كلُّ متجرٍ عن جاره —
 * وأن يُكتب في HTML وخريطة الموقع ما حُفظ.
 */
class ASearchAppearanceIsLiveTheMomentItIsSavedTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /* ═══════════ المتاجر ═══════════ */

    private function business(string $slug, array $over = []): Business
    {
        $business = Business::create($over + [
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '9689000'.str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT),
            'city' => 'مسقط', 'site_slug' => $slug,
        ]);
        Currency::create(['business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($business->id);
        Branch::create(['business_id' => $business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $business->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $business;
    }

    private function owner(Business $business, array $over = []): User
    {
        return User::create($over + [
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner'.$business->id.'-'.(++$this->seq).'@site.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** متجرٌ ببانِي المواقع، بقالبه، منشور — كما يصير أيُّ تاجرٍ جديد @return array{0: Business, 1: User, 2: Website} */
    private function built(string $slug, string $template = 'modern', array $over = []): array
    {
        $business = $this->business($slug, $over);
        $owner = $this->owner($business);
        $site = Builder::create($business, Blueprints::STORE, $template, $owner->id);
        Publisher::publish($site, $owner->id);

        return [$business, $owner, $site->fresh()];
    }

    /** متجرٌ بواجهةٍ خاصّة (RIBBON)، منشور، وفيه منتجٌ معروض @return array{0: Business, 1: User} */
    private function themed(string $slug, array $over = []): array
    {
        $business = $this->business($slug, $over + ['tier' => 'gold', 'storefront_theme' => 'ribbon']);
        MarketingSettings::save($business->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        Product::create([
            'business_id' => $business->id, 'name' => 'باقة ورد أحمر', 'price' => 20, 'cost' => 8,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return [$business->fresh(), $this->owner($business)];
    }

    /** متجرٌ بصفحة المتجر البسيطة وحدها — نشره ولم يبنِ موقعًا ولم يلبس واجهة @return array{0: Business, 1: User} */
    private function simple(string $slug, array $settings = []): array
    {
        $business = $this->business($slug);
        MarketingSettings::save($business->id, 'website', $settings + ['store_on' => '1', 'store_headline' => 'ورود '.$slug, 'store_about' => 'باقاتُ وردٍ وهدايا في مسقط.']);

        return [$business->fresh(), $this->owner($business)];
    }

    private function saveSimple(User $as, array $seo)
    {
        return $this->actingAs($as)->from(route('admin.website.seo'))->put(route('admin.website.seo.save'), $seo + [
            'store_seo_title' => '', 'store_seo_desc' => '', 'store_seo_index' => '1',
        ]);
    }

    /** العنوانُ الأصليُّ كما يكتبه رأسُ الصفحة */
    private function canonicalOf(string $html): ?string
    {
        return preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);

        return array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES | ENT_XML1), $m[1]);
    }

    /** نطاقُ التاجر الخاصّ نشطًا — كما يصير بعد فحص توجيهه */
    private function activeDomain(Business $business, string $host): void
    {
        config(['storefront.custom_domains' => true]);
        Domains::attach($business->fresh(), $host);
        Domains::custom((int) $business->id)->update(['status' => Domains::ACTIVE]);
    }

    private function saveBuilt(User $as, array $seo)
    {
        return $this->actingAs($as)->from(route('admin.website.seo'))
            ->put(route('admin.website.seo.save'), $seo + ['title' => '', 'description' => '', 'image' => '', 'index' => true]);
    }

    private function saveThemed(User $as, array $seo)
    {
        return $this->actingAs($as)->from(route('admin.website.seo'))->put(route('admin.website.seo.save'), $seo + [
            'store_seo_title' => '', 'store_seo_desc' => '', 'store_seo_title_en' => '', 'store_seo_desc_en' => '', 'store_seo_index' => '1',
        ]);
    }

    private function screen(User $as): array
    {
        return $this->actingAs($as)->get(route('admin.website.seo'))->assertOk()->viewData('page')['props'];
    }

    /** نصُّ `<title>` الصفحةِ كما يقرؤه غوغل */
    private function titleOf(string $html): string
    {
        preg_match('#<title>(.*?)</title>#su', $html, $m);

        return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /* ═══════════ الحفظُ يسري بلا نشر — الموقعُ المبنيّ ═══════════ */

    /**
     * تاجرٌ جديد يحفظ — فيقرأ غوغل الجديدَ الآن، ولا يخرج شيءٌ من مسوّدته.
     */
    public function test_a_new_builder_merchant_saves_and_the_published_page_reads_it_without_the_draft(): void
    {
        [$business, $owner, $site] = $this->built('wrood');

        // تعديلٌ في التصميم لم يُنشر بعد — كما يترك التاجرُ عملَه نصفَ يوم
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $hero->update(['data' => array_merge((array) $hero->data, ['title' => 'جملةٌ في المسوّدة لم تُنشر'])]);
        $site->touchDraft();
        $before = $site->fresh();

        $this->saveBuilt($owner, [
            'title' => 'ورود مسقط · باقات وهدايا تُوصَّل اليوم',
            'description' => 'باقاتُ وردٍ طبيعيّ وهدايا تُوصَّل في مسقط خلال اليوم نفسه، ونصنعها لكلّ مناسبة.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.website.seo'));

        $html = $this->get('/s/wrood')->assertOk()->getContent();

        $this->assertSame('ورود مسقط · باقات وهدايا تُوصَّل اليوم', $this->titleOf($html), 'العنوانُ ينتظر نشرة');
        $this->assertStringContainsString('باقاتُ وردٍ طبيعيّ وهدايا تُوصَّل في مسقط', $html);
        $this->assertStringNotContainsString('جملةٌ في المسوّدة لم تُنشر', $html, 'حفظُ البحث نشر المسوّدة');

        $after = $site->fresh();
        $this->assertSame($before->published_version_id, $after->published_version_id, 'حفظُ البحث كتب نشرة');
        $this->assertSame($before->draft_revision, $after->draft_revision, 'حفظُ البحث مسّ المسوّدة');
        $this->assertSame(1, WebsiteVersion::where('website_id', $site->id)->count());

        // والمسوّدةُ ما زالت تنتظر صاحبَها — ونشرُها يُخرجها (الاختبارُ يرى ما يقيس)
        Publisher::publish($site->fresh(), $owner->id);
        $this->assertStringContainsString('جملةٌ في المسوّدة لم تُنشر', $this->get('/s/wrood')->getContent());
        $this->assertSame('ورود مسقط · باقات وهدايا تُوصَّل اليوم', $this->titleOf($this->get('/s/wrood')->getContent()));
    }

    /** وإطفاءُ الفهرسة يسري بالحفظ — والخريطةُ تُطوى معه */
    public function test_switching_indexing_off_and_on_applies_at_once(): void
    {
        [, $owner] = $this->built('wrood');

        $this->saveBuilt($owner, ['title' => 'ورود مسقط · باقات', 'index' => false])->assertSessionHasNoErrors();

        $this->get('/s/wrood')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get('/s/wrood/sitemap.xml')->assertNotFound();

        $this->saveBuilt($owner, ['title' => 'ورود مسقط · باقات', 'index' => true])->assertSessionHasNoErrors();

        $this->get('/s/wrood')->assertOk()->assertSee('<meta name="robots" content="index, follow">', false);
        $this->get('/s/wrood/sitemap.xml')->assertOk();
    }

    /* ═══════════ وما نُشر قبل الترقية يبقى كما هو ═══════════ */

    /**
     * تاجرٌ قائمٌ حفظ قبل الترقية ولم ينشر — لا يتبدّل ظهورُه في غوغل بالترقية.
     *
     * كان حفظُه ينتظر نشرة، فإطفاءُ الفهرسة الذي حفظه لم يسرِ قطّ. ولو صار
     * الصفُّ مصدرًا بالترقية لَاختفى متجرُه من غوغل يومَ النشر لا يومَ قرّر.
     * فتبقى صفحتُه على نشرتها، ويُعرض عليه ما حفظه ليستعمله.
     */
    public function test_an_existing_published_site_keeps_its_search_appearance_through_the_upgrade(): void
    {
        [, $owner, $site] = $this->built('wrood');
        $published = $this->titleOf($this->get('/s/wrood')->getContent());

        // ما يتركه الحفظُ القديم: صفٌّ بلا `saved_at` يخالف النشرة
        $site->update(['seo' => ['title' => 'حفظٌ قديمٌ لم يُنشر', 'description' => '', 'image' => '', 'index' => false]]);

        $html = $this->get('/s/wrood')->assertOk()->getContent();
        $this->assertSame($published, $this->titleOf($html), 'تبدّل عنوانُ متجرٍ قائمٍ بالترقية');
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html, 'تبدّلت فهرسةُ متجرٍ قائمٍ بالترقية');

        $props = $this->screen($owner);
        $this->assertSame($published, $props['seo']['title'], 'الشاشة لا تعرض ما على الموقع');
        $this->assertTrue($props['seo']['index']);
        $this->assertSame('حفظٌ قديمٌ لم يُنشر', $props['pending']['title']);
        $this->assertSame('0', $props['pending']['index']);
        $this->assertNull($props['savedAt']);

        // ثمّ يقرّر — فيسري
        $this->saveBuilt($owner, ['title' => 'حفظٌ قديمٌ لم يُنشر', 'index' => false])->assertSessionHasNoErrors();

        $html = $this->get('/s/wrood')->getContent();
        $this->assertSame('حفظٌ قديمٌ لم يُنشر', $this->titleOf($html));
        $this->assertStringContainsString('noindex', $html);
        $this->assertSame([], $this->screen($owner)['pending']);
    }

    /* ═══════════ ولا يعود القديمُ بنشرٍ أو استرجاع ═══════════ */

    public function test_restoring_and_publishing_an_old_builder_version_does_not_bring_back_old_seo(): void
    {
        [, $owner, $site] = $this->built('wrood');

        $this->saveBuilt($owner, ['title' => 'عنوانٌ قديمٌ في نشرةٍ قديمة', 'index' => false])->assertSessionHasNoErrors();
        $old = Publisher::publish($site->fresh(), $owner->id);

        $this->saveBuilt($owner, ['title' => 'العنوانُ الذي اختاره أخيرًا', 'index' => true])->assertSessionHasNoErrors();

        // يسترجع نسخةً قديمةً لتصميمها، ثمّ ينشر
        Publisher::restore($site->fresh(), $old);
        Publisher::publish($site->fresh(), $owner->id);

        $html = $this->get('/s/wrood')->getContent();
        $this->assertSame('العنوانُ الذي اختاره أخيرًا', $this->titleOf($html), 'الاسترجاعُ أعاد عنوانًا كُتب فوقه');
        $this->assertStringContainsString('index, follow', $html, 'الاسترجاعُ أعاد إخفاءً أُلغي');
        $this->assertSame('العنوانُ الذي اختاره أخيرًا', $site->fresh()->seo['title']);
    }

    public function test_publishing_an_old_theme_draft_or_version_does_not_bring_back_old_seo(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        ThemePublisher::enable($shop, null);

        // نشرةٌ قديمةٌ تحمل عنوانَ بحث — كما كُتبت قبل أن يسري بالحفظ
        $old = WebsiteVersion::create([
            'website_id' => null, 'business_id' => $shop->id, 'kind' => WebsiteVersion::THEME,
            'number' => WebsiteVersion::nextThemeNumber($shop->id),
            'payload' => ['schema_version' => 1, 'engine' => 'theme', 'content' => [
                'store_seo_title' => 'من نشرةٍ قديمة', 'store_hero_title' => 'واجهةٌ قديمة',
            ]],
            'published_at' => now()->subMonth(),
        ]);

        $this->saveThemed($owner, ['store_seo_title' => 'ريبون — ورد وهدايا بمسقط'])->assertSessionHasNoErrors();

        ThemePublisher::restore($shop, $old, $owner->id);
        $this->assertNotNull(ThemePublisher::publish($shop, $owner->id), 'لم يُنشر شيء — فالاختبار لا يقيس');

        $this->assertSame('ريبون — ورد وهدايا بمسقط', MarketingSettings::group($shop->id, 'website')['store_seo_title']);
        $this->assertSame('ريبون — ورد وهدايا بمسقط', $this->titleOf($this->get('/s/ribbon')->getContent()));
    }

    /* ═══════════ الواجهةُ الخاصّة — بلغتيها ═══════════ */

    public function test_a_themed_store_saves_both_languages_live_without_publishing_its_draft(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        ThemePublisher::enable($shop, null);

        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_hero_title' => 'واجهةٌ في المسوّدة لم تُنشر'])
            ->assertSessionHasNoErrors();

        $this->saveThemed($owner, [
            'store_seo_title' => 'ريبون — ورد وهدايا بمسقط',
            'store_seo_desc' => 'باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط خلال اليوم نفسه، وهدايا لكلّ مناسبة.',
            'store_seo_title_en' => 'Ribbon — flowers & gifts in Muscat',
            'store_seo_desc_en' => 'Fresh bouquets delivered across Muscat the same day, and gifts for every occasion.',
        ])->assertSessionHasNoErrors()->assertSessionHas('toast');

        $ar = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertSame('ريبون — ورد وهدايا بمسقط', $this->titleOf($ar));
        $this->assertStringContainsString('باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط', $ar);
        $this->assertStringNotContainsString('واجهةٌ في المسوّدة لم تُنشر', $ar, 'حفظُ البحث نشر المسوّدة');

        $en = $this->get('/s/ribbon?lang=en')->assertOk()->getContent();
        $this->assertSame('Ribbon — flowers & gifts in Muscat', $this->titleOf($en));
        $this->assertStringContainsString('Fresh bouquets delivered across Muscat', $en);

        $this->assertSame(['store_hero_title'], StoreContent::changed($shop->id), 'البحثُ صار «تغييرًا غير منشور» أو نُشرت المسوّدة');
    }

    /**
     * عنوانُ بحثٍ حُفظ في المسوّدة قبل الترقية — يُعرض ولا يُطبَّق، ولا يُرمى.
     */
    public function test_a_title_left_in_an_old_theme_draft_is_offered_kept_and_cleared_by_a_save(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        MarketingSettings::save($shop->id, 'website', ['store_seo_title' => 'ما على الموقع']);
        ThemePublisher::enable($shop, null);

        $site = StoreSite::where('business_id', $shop->id)->firstOrFail();
        $site->update(['draft' => (array) $site->draft + ['store_seo_title' => 'حفظٌ قديمٌ في المسوّدة']]);

        $props = $this->screen($owner);
        $this->assertSame('ما على الموقع', $props['seo']['title'], 'الشاشة لا تعرض ما على الموقع');
        $this->assertSame('حفظٌ قديمٌ في المسوّدة', $props['pending']['store_seo_title']);
        $this->assertSame('ما على الموقع', $this->titleOf($this->get('/s/ribbon')->getContent()), 'طُبّق حفظٌ قديمٌ بالترقية');

        // حفظُ المحرّر والتراجعُ لا يرميانه
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_hero_title' => 'واجهة'])->assertSessionHasNoErrors();
        $this->assertSame('حفظٌ قديمٌ في المسوّدة', StoreSeo::pending($shop->id)['store_seo_title'] ?? null, 'حفظُ المحرّر رمى عنوانًا لم يُقرَّر فيه');
        ThemePublisher::discard($shop, $owner->id);
        $this->assertSame('حفظٌ قديمٌ في المسوّدة', StoreSeo::pending($shop->id)['store_seo_title'] ?? null, 'التراجعُ رمى عنوانًا لم يُقرَّر فيه');

        // ولا ينشره النشر
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_hero_title' => 'واجهةٌ جديدة'])->assertSessionHasNoErrors();
        ThemePublisher::publish($shop, $owner->id);
        $this->assertSame('ما على الموقع', MarketingSettings::group($shop->id, 'website')['store_seo_title'], 'النشرُ طبّق عنوانَ بحثٍ من المسوّدة');

        // وحفظُ الشاشة يقرّر فيه — فيُطوى
        $this->saveThemed($owner, ['store_seo_title' => 'حفظٌ قديمٌ في المسوّدة'])->assertSessionHasNoErrors();
        $this->assertSame([], StoreSeo::pending($shop->id));
        $this->assertArrayNotHasKey('store_seo_title', (array) $site->fresh()->draft);
        $this->assertSame('حفظٌ قديمٌ في المسوّدة', $this->titleOf($this->get('/s/ribbon')->getContent()));
    }

    /* ═══════════ ويبقى بعد التحديث والخروج والدخول ═══════════ */

    public function test_saved_values_survive_a_reload_and_a_new_sign_in(): void
    {
        [, $builtOwner] = $this->built('wrood');
        [, $themedOwner] = $this->themed('ribbon');

        $this->saveBuilt($builtOwner, ['title' => 'ورود مسقط · باقات', 'description' => 'وصفٌ يبقى', 'index' => false])->assertSessionHasNoErrors();
        $this->saveThemed($themedOwner, ['store_seo_title' => 'ريبون', 'store_seo_title_en' => 'Ribbon', 'store_seo_index' => '0'])->assertSessionHasNoErrors();

        Auth::logout();
        $this->flushSession();

        $built = $this->screen(User::find($builtOwner->id));
        $this->assertSame('ورود مسقط · باقات', $built['seo']['title']);
        $this->assertSame('وصفٌ يبقى', $built['seo']['description']);
        $this->assertFalse($built['seo']['index']);
        $this->assertNotNull($built['savedAt']);

        $themed = $this->screen(User::find($themedOwner->id));
        $this->assertSame('ريبون', $themed['seo']['title']);
        $this->assertSame('Ribbon', $themed['seo']['title_en']);
        $this->assertFalse($themed['seo']['index']);
        $this->assertNotNull($themed['savedAt']);
    }

    /* ═══════════ لكلّ قالبٍ — والواجهةُ الخاصّة منها ═══════════ */

    /** @return array<string, array{0: string}> */
    public static function templates(): array
    {
        return ['atelier' => ['atelier'], 'souq' => ['souq'], 'bloom' => ['bloom'], 'mono' => ['mono'], 'modern' => ['modern'], 'luxury' => ['luxury'], 'fashion' => ['fashion']];
    }

    #[DataProvider('templates')]
    public function test_every_builder_template_takes_a_saved_title_live(string $template): void
    {
        [, $owner] = $this->built('t-'.$template, $template);

        $this->saveBuilt($owner, ['title' => 'عنوانٌ للبحث في قالب '.$template])->assertSessionHasNoErrors();

        $this->assertSame('عنوانٌ للبحث في قالب '.$template, $this->titleOf($this->get('/s/t-'.$template)->assertOk()->getContent()));
    }

    /* ═══════════ الباقةُ والصلاحيّة ═══════════ */

    /**
     * باقةٌ لا تبيع الموقع لا تفتح الشاشة ولا تحفظ — ثمّ تُرقّى فتفتحها بلا ضبطٍ يدويّ.
     */
    public function test_a_plan_without_the_website_is_refused_and_an_upgrade_opens_it_with_nothing_to_set_up(): void
    {
        $basic = Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => ['whatsapp']]);
        $pro = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => PlanFeatures::keys()]);

        $business = $this->business('later', ['plan_id' => $basic->id]);
        $owner = $this->owner($business);

        $this->actingAs($owner)->get(route('admin.website.seo'))->assertForbidden();
        $this->actingAs($owner)->put(route('admin.website.seo.save'), ['title' => 'x', 'index' => true])->assertForbidden();
        $this->get('/s/later/sitemap.xml')->assertNotFound();

        // تُفعَّل له الميزةُ بعد إنشاء حسابه — ولا شيءَ يُنشأ له يدويًّا
        $business->forceFill(['plan_id' => $pro->id])->save();
        // والمستخدمُ يُقرأ من جديد كما في طلبٍ جديد — لا بباقته المحفوظة في الذاكرة
        $owner = $owner->fresh();
        $site = Builder::create($business->fresh(), Blueprints::STORE, 'modern', $owner->id);
        Publisher::publish($site, $owner->id);

        $props = $this->screen($owner);
        $this->assertSame($site->fresh()->seo['title'], $props['seo']['title'], 'الافتراضيُّ لا يُقرأ');
        $this->assertNotSame('', $props['seo']['title']);
        $this->assertTrue($props['seo']['index']);

        $this->saveBuilt($owner, ['title' => 'متجرٌ فُعّل موقعه لاحقًا'])->assertSessionHasNoErrors();
        $this->assertSame('متجرٌ فُعّل موقعه لاحقًا', $this->titleOf($this->get('/s/later')->getContent()));
    }

    /**
     * ومن لا يضبط الموقع لا يحفظ بحثَه — لا من شاشته ولا من باب المتجر.
     */
    public function test_staff_without_website_configure_cannot_change_search_by_either_door(): void
    {
        [$shop] = $this->themed('ribbon');
        MarketingSettings::save($shop->id, 'website', ['store_seo_title' => 'ما ضبطه المالك']);

        $staff = $this->owner($shop, ['role' => 'staff', 'permissions' => ['website', 'marketing']]);

        $this->saveThemed($staff, ['store_seo_title' => 'موظّف'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.marketing.store.save'), ['store_seo_index' => '0'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.marketing.store.save'), ['store_seo_title' => 'موظّف'])->assertForbidden();

        $now = MarketingSettings::group($shop->id, 'website');
        $this->assertSame('ما ضبطه المالك', $now['store_seo_title']);
        $this->assertSame('1', $now['store_seo_index']);

        // وما ليس بحثًا يحفظه كما كان — الحارسُ على البحث وحده
        $this->actingAs($staff)->post(route('admin.marketing.store.save'), ['store_delivery_note' => 'نوصّل حتّى التاسعة'])
            ->assertSessionHasNoErrors();
        $this->assertSame('نوصّل حتّى التاسعة', MarketingSettings::group($shop->id, 'website')['store_delivery_note']);

        // ومن مُنح الضبطَ يحفظ
        $configurer = $this->owner($shop, ['role' => 'staff', 'permissions' => ['website', 'marketing', Permissions::WEBSITE_CONFIGURE]]);
        $this->saveThemed($configurer, ['store_seo_title' => 'مدير الموقع'])->assertSessionHasNoErrors();
        $this->assertSame('مدير الموقع', MarketingSettings::group($shop->id, 'website')['store_seo_title']);
    }

    /* ═══════════ العزل ═══════════ */

    public function test_two_shops_never_touch_each_others_search_settings(): void
    {
        [$a, $aOwner] = $this->themed('ribbon');
        [$b, $bOwner] = $this->themed('lily');
        [, $cOwner, $cSite] = $this->built('wrood');

        MarketingSettings::save($b->id, 'website', ['store_seo_title' => 'ليلي', 'store_seo_index' => '1']);
        $cBefore = $cSite->fresh()->seo;

        $this->saveThemed($aOwner, ['store_seo_title' => 'ريبون', 'store_seo_index' => '0'])->assertSessionHasNoErrors();

        $this->assertSame('ليلي', MarketingSettings::group($b->id, 'website')['store_seo_title']);
        $this->assertSame('1', MarketingSettings::group($b->id, 'website')['store_seo_index']);
        $this->assertSame('', MarketingSettings::group($b->id, 'website')[StoreSeo::SAVED_AT]);
        $this->assertSame($cBefore, $cSite->fresh()->seo);

        // وصاحبُ الموقع المبنيّ يحفظ في موقعه وحده — لا معرّفَ في الطلب يُبدَّل
        $this->saveBuilt($cOwner, ['title' => 'ورود', 'index' => false])->assertSessionHasNoErrors();
        $this->assertSame('ريبون', MarketingSettings::group($a->id, 'website')['store_seo_title']);
        $this->assertSame('ليلي', MarketingSettings::group($b->id, 'website')['store_seo_title']);

        $this->assertStringNotContainsString('noindex', $this->get('/s/lily')->getContent(), 'إخفاءُ متجرٍ أخفى جارَه');
        $this->assertSame('ليلي', $this->titleOf($this->get('/s/lily')->getContent()));
        $this->assertSame('ليلي', $this->screen($bOwner)['seo']['title']);
    }

    /* ═══════════ وفشلُ الحفظ لا يُبقي نصفًا ═══════════ */

    public function test_a_refused_save_changes_nothing(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        $this->saveThemed($owner, ['store_seo_title' => 'ريبون', 'store_seo_desc' => 'وصف'])->assertSessionHasNoErrors();
        $before = MarketingSettings::group($shop->id, 'website');

        $this->saveThemed($owner, ['store_seo_title' => str_repeat('ا', StoreSeo::TITLE_MAX + 1), 'store_seo_desc' => 'وصفٌ جديد', 'store_seo_index' => '0'])
            ->assertSessionHasErrors('store_seo_title');

        MarketingSettings::forget();
        $after = MarketingSettings::group($shop->id, 'website');
        foreach ([...StoreSeo::KEYS, StoreSeo::SAVED_AT] as $key) {
            $this->assertSame($before[$key], $after[$key], 'حفظٌ مردودٌ غيّر «'.$key.'»');
        }

        [, $builtOwner, $site] = $this->built('wrood');
        $seo = $site->fresh()->seo;
        $this->saveBuilt($builtOwner, ['title' => str_repeat('ا', 71), 'index' => false])->assertSessionHasErrors('title');
        $this->assertSame($seo, $site->fresh()->seo);
    }

    /**
     * وحفظٌ تعثّر في منتصفه لا يُبقي نصفَه — معاملةٌ واحدة.
     */
    public function test_a_save_that_fails_half_way_leaves_nothing_half_saved(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        MarketingSettings::save($shop->id, 'website', ['store_seo_title' => 'ما على الموقع', 'store_seo_index' => '1']);
        ThemePublisher::enable($shop, null);
        $site = StoreSite::where('business_id', $shop->id)->firstOrFail();
        $site->update(['draft' => (array) $site->draft + ['store_seo_title' => 'حفظٌ قديم']]);

        // يتعثّر الحفظُ عند آخر خطوةٍ فيه — بعد أن كُتبت الإعدادات
        StoreSite::updating(function () {
            throw new \RuntimeException('تعثّر الحفظ');
        });

        $this->saveThemed($owner, ['store_seo_title' => 'جديد', 'store_seo_index' => '0'])->assertStatus(500);

        Event::forget('eloquent.updating: '.StoreSite::class);
        MarketingSettings::forget();
        $now = MarketingSettings::group($shop->id, 'website');
        $this->assertSame('ما على الموقع', $now['store_seo_title'], 'بقي نصفُ حفظٍ متعثّر');
        $this->assertSame('1', $now['store_seo_index'], 'بقي نصفُ حفظٍ متعثّر');
        $this->assertSame('', $now[StoreSeo::SAVED_AT]);
        $this->assertSame('حفظٌ قديم', StoreSeo::pending($shop->id)['store_seo_title'] ?? null);
    }

    /* ═══════════ HTML النهائيّ وrobots وخريطة الموقع ═══════════ */

    public function test_the_built_page_head_and_sitemap_say_what_was_saved(): void
    {
        [$business, $owner, $site] = $this->built('wrood');

        $this->saveBuilt($owner, [
            'title' => 'ورود مسقط · باقات وهدايا',
            'description' => 'باقاتُ وردٍ تُوصَّل في مسقط خلال اليوم نفسه، وهدايا مختارة لكلّ مناسبة سعيدة.',
        ])->assertSessionHasNoErrors();

        $html = $this->get('/s/wrood')->getContent();
        $this->assertStringContainsString('<title>ورود مسقط · باقات وهدايا</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="باقاتُ وردٍ تُوصَّل في مسقط', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s/wrood').'">', $html);

        $xml = $this->get('/s/wrood/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<loc>'.url('/s/wrood').'</loc>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'الخريطةُ ليست XML صالحًا');

        // وكلُّ صفحةٍ منشورةٍ فيها بعنوانها — والمسوّدةُ لا
        $pages = collect($site->pages()->get())->where('status', 'published');
        $this->assertSame($pages->count(), substr_count($xml, '<loc>'));

        // ومتجرٌ لا وجود له لا خريطةَ له
        $this->get('/s/no-such-shop/sitemap.xml')->assertNotFound();
    }

    public function test_the_themed_page_head_and_sitemap_cover_both_languages_and_skip_private_pages(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        $product = Product::where('business_id', $shop->id)->firstOrFail();

        $this->saveThemed($owner, ['store_seo_title' => 'ريبون — ورد مسقط', 'store_seo_title_en' => 'Ribbon — Muscat flowers'])->assertSessionHasNoErrors();

        $ar = $this->get('/s/ribbon')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s/ribbon').'">', $ar);
        $this->assertStringContainsString('hreflang="en" href="'.url('/s/ribbon').'?lang=en"', $ar);
        $this->assertStringNotContainsString('rb-noindex', $ar);

        // والإنجليزيّةُ أصلُها نفسُها — لا تُطوى في العربيّة نسخةً مكرّرة
        $en = $this->get('/s/ribbon?lang=en')->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s/ribbon').'?lang=en">', $en);
        $this->assertStringContainsString('hreflang="ar" href="'.url('/s/ribbon').'"', $en);

        $xml = $this->get('/s/ribbon/sitemap.xml')->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($xml));
        foreach (['', '/shop', '/p/'.$product->id] as $path) {
            $this->assertStringContainsString('<loc>'.url('/s/ribbon'.$path).'</loc>', $xml, $path.' ليس في الخريطة');
            $this->assertStringContainsString('<loc>'.url('/s/ribbon'.$path).'?lang=en</loc>', $xml);
        }
        foreach (StoreSeo::PRIVATE_PAGES as $private) {
            $this->assertStringNotContainsString('/s/ribbon/'.$private, $xml, $private.' في الخريطة');
        }

        // وإخفاؤه يُخفي الخريطة ويكتب noindex
        $this->saveThemed($owner, ['store_seo_index' => '0'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('rb-noindex', $this->get('/s/ribbon')->getContent());
        $this->get('/s/ribbon/sitemap.xml')->assertNotFound();
    }

    /* ═══════════ جاهزيّةُ الفهرسة ═══════════ */

    public function test_the_readiness_check_reads_title_description_robots_canonical_and_sitemap(): void
    {
        [$shop, $owner] = $this->themed('ribbon');

        $this->saveThemed($owner, [
            'store_seo_title' => 'ريبون — ورد وهدايا بمسقط',
            'store_seo_desc' => 'باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط خلال اليوم نفسه، وهدايا مختارة لكلّ مناسبة.',
        ])->assertSessionHasNoErrors();

        $readiness = $this->screen($owner)['readiness'];
        $states = collect($readiness['items'])->pluck('state', 'key')->all();

        $this->assertSame(['published', 'title', 'description', 'title_en', 'description_en', 'robots', 'canonical', 'sitemap'], array_keys($states));
        $this->assertSame('pass', $states['published']);
        $this->assertSame('pass', $states['title']);
        $this->assertSame('pass', $states['description']);
        $this->assertSame('warn', $states['title_en'], 'عنوانٌ إنجليزيٌّ فارغٌ قيل عنه «جيّد»');
        $this->assertSame('pass', $states['robots']);
        $this->assertSame(url('/s/ribbon'), $readiness['canonical']);
        $this->assertSame(url('/s/ribbon/sitemap.xml'), $readiness['sitemap']);

        // وإخفاؤه يُقال قرارًا لا عطبًا، والخريطةُ تُطوى
        $this->saveThemed($owner, ['store_seo_index' => '0'])->assertSessionHasNoErrors();
        $states = collect($this->screen($owner)['readiness']['items'])->pluck('state', 'key')->all();
        $this->assertSame('off', $states['robots']);
        $this->assertSame('off', $states['sitemap']);

        // وموقعٌ لم يُنشر يُقال عنه
        MarketingSettings::save($shop->id, 'website', ['store_on' => '0']);
        $readiness = SearchReadiness::forBusiness($shop->fresh());
        $this->assertFalse($readiness['published']);
        $this->assertNull($readiness['canonical']);
        $this->assertNull($readiness['sitemap']);
    }

    public function test_a_server_robots_file_that_blocks_the_stores_is_caught(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'robots');

        file_put_contents($file, "User-agent: *\nDisallow:\n");
        $this->assertTrue(SearchReadiness::robotsTxtAllows('/s/ribbon', $file));

        file_put_contents($file, "User-agent: Bingbot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n");
        $this->assertTrue(SearchReadiness::robotsTxtAllows('/s/ribbon', $file));

        file_put_contents($file, "User-agent: *\nDisallow: /s/\n");
        $this->assertFalse(SearchReadiness::robotsTxtAllows('/s/ribbon', $file));

        unlink($file);

        // وملفُّ الخادم اليومَ يأذن بالمتاجر كلِّها
        $this->assertTrue(SearchReadiness::robotsTxtAllows('/s/ribbon'));
    }

    /* ═══════════ مراجعة #204 — كلُّ تاجرٍ مؤهَّل، بلا استثناء ═══════════ */

    /**
     * كلُّ صورةٍ يكون عليها متجرٌ يملك الموقع — وأيُّ شاشةٍ تفتح له، وأيُّ حفظٍ يسري.
     *
     * ═══ والاستثناءُ الذي وُجد ═══
     *
     * صاحبُ صفحة المتجر البسيطة (نشر متجره ولم يبنِ موقعًا) كانت الشاشةُ
     * تردّه إلى معالج الإنشاء، وصفحتُه منشورةٌ يقرؤها غوغل. فصار له شاشتُه.
     */
    public function test_every_kind_of_eligible_merchant_gets_a_search_screen(): void
    {
        [, $theme] = $this->themed('ribbon');
        [, $built] = $this->built('wrood');
        [, $simple] = $this->simple('lily');

        // موقعٌ مبنيٌّ لم يُنشر، وصفحتُه البسيطة منشورة — يرى شاشةَ موقعه الجديد
        [$moving, $movingOwner] = $this->simple('jasmine');
        Builder::create($moving, Blueprints::STORE, 'modern', $movingOwner->id);

        // ومن لا موقعَ له أصلًا — يُقاد إلى إنشائه
        $none = $this->owner($this->business('nothing'));

        foreach ([
            'الواجهة الخاصّة' => [$theme, 'Admin/Website/ThemeSeo'],
            'الموقع المبنيّ' => [$built, 'Admin/Website/Seo'],
            'الصفحة البسيطة' => [$simple, 'Admin/Website/SimpleSeo'],
            'موقعٌ مبنيٌّ لم يُنشر' => [$movingOwner, 'Admin/Website/Seo'],
        ] as $kind => [$owner, $component]) {
            $this->assertSame($component, $this->actingAs($owner)->get(route('admin.website.seo'))->assertOk()->viewData('page')['component'], $kind);
        }

        $this->actingAs($none)->get(route('admin.website.seo'))->assertRedirect(route('admin.website.index'));

        // ومن بدأ موقعًا ولم ينشره يُقال له إنّ زبائنه يرون البسيطةَ الآن
        $published = collect($this->screen($movingOwner)['readiness']['items'])->firstWhere('key', 'published');
        $this->assertSame('fail', $published['state']);
        $this->assertStringContainsString('صفحة متجرك البسيطة', $published['detail']);
    }

    /**
     * صفحةُ المتجر البسيطة: رأسُها كما كان حتّى يكتب صاحبُها — ثمّ يسري ما كتب.
     */
    public function test_a_simple_store_page_keeps_its_head_until_saved_then_applies_it_live(): void
    {
        [$shop, $owner] = $this->simple('lily');

        $html = $this->get('/s/lily')->assertOk()->getContent();
        $this->assertSame('ورود lily', $this->titleOf($html), 'تبدّل عنوانُ صفحةٍ قائمةٍ بالترقية');
        $this->assertStringContainsString('<meta name="description" content="باقاتُ وردٍ وهدايا في مسقط.">', $html);
        $this->assertStringNotContainsString('noindex', $html, 'أُخفيت صفحةٌ قائمةٌ بالترقية');
        // والأصلُ ما يخدمها فعلًا — لا نطاقٌ فرعيٌّ مطفأ
        $this->assertSame(url('/s/lily'), $this->canonicalOf($html));

        $props = $this->screen($owner);
        $this->assertSame('ورود lily', $props['fallback']['title']);
        $this->assertSame('', $props['seo']['title']);
        $this->assertNull($props['savedAt']);

        $this->saveSimple($owner, [
            'store_seo_title' => 'ورود ليلي · باقات مسقط',
            'store_seo_desc' => 'باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط خلال اليوم نفسه، وهدايا مختارة لكلّ مناسبة.',
            // وما لا صفحةَ له لا يُقبل: البسيطةُ عربيّةٌ وحدها
            'store_seo_title_en' => 'Lily',
        ])->assertSessionHasNoErrors()->assertSessionHas('toast');

        $html = $this->get('/s/lily')->getContent();
        $this->assertSame('ورود ليلي · باقات مسقط', $this->titleOf($html));
        $this->assertStringContainsString('<meta property="og:title" content="ورود ليلي · باقات مسقط">', $html);
        $this->assertStringContainsString('باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط', $html);
        $this->assertSame('', MarketingSettings::group($shop->id, 'website')['store_seo_title_en']);
        $this->assertNotNull($this->screen($owner)['savedAt']);

        $this->assertSame([url('/s/lily')], $this->locs($this->get('/s/lily/sitemap.xml')->assertOk()->getContent()));

        $this->saveSimple($owner, ['store_seo_title' => 'ورود ليلي · باقات مسقط', 'store_seo_index' => '0'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $this->get('/s/lily')->getContent());
        $this->get('/s/lily/sitemap.xml')->assertNotFound();
        $this->assertSame('off', collect($this->screen($owner)['readiness']['items'])->firstWhere('key', 'robots')['state']);

        // ومن لا يضبط الموقع لا يحفظها
        $staff = $this->owner($shop, ['role' => 'staff', 'permissions' => ['website', 'marketing']]);
        $this->saveSimple($staff, ['store_seo_title' => 'موظّف'])->assertForbidden();
        $this->assertSame('ورود ليلي · باقات مسقط', MarketingSettings::group($shop->id, 'website')['store_seo_title']);
    }

    /**
     * صفحةٌ بسيطةٌ في مفاتيحها قيمُ بحثٍ كُتبت قبل أن تقرأها — من واجهةٍ خُلعت أو
     * من باب «التسويق». لم تصل زبونًا قطّ، فلا يُخفيها النشرُ ولا يبدّل عنوانَها.
     */
    public function test_a_simple_store_page_ignores_search_values_written_before_it_read_them(): void
    {
        [$shop, $owner] = $this->simple('lily', [
            'store_seo_title' => 'عنوانٌ قديم',
            'store_seo_desc' => 'وصفٌ قديمٌ لم يُنشر.',
            'store_seo_index' => '0',
        ]);
        $app = rtrim(url('/'), '/');

        $html = $this->get($app.'/s/lily')->assertOk()->getContent();
        $this->assertSame('ورود lily', $this->titleOf($html), 'تبدّل عنوانُ صفحةٍ قائمةٍ بالترقية');
        $this->assertStringContainsString('<meta name="description" content="باقاتُ وردٍ وهدايا في مسقط.">', $html);
        $this->assertStringNotContainsString('noindex', $html, 'أُخفيت صفحةٌ قائمةٌ بالترقية');
        $this->assertSame([url('/s/lily')], $this->locs($this->get($app.'/s/lily/sitemap.xml')->assertOk()->getContent()));
        $this->assertSame('pass', collect($this->screen($owner)['readiness']['items'])->firstWhere('key', 'robots')['state']);

        // والشاشةُ تعرض ما على الصفحة، والقديمُ معلّقًا يختاره صاحبُه
        $props = $this->screen($owner);
        $this->assertSame(['title' => '', 'desc' => '', 'index' => true], $props['seo']);
        $this->assertSame(['store_seo_title' => 'عنوانٌ قديم', 'store_seo_desc' => 'وصفٌ قديمٌ لم يُنشر.', 'store_seo_index' => '0'], $props['pending']);

        // فإن حفظ — سرى ما حفظه، وانطفأ المعلّق
        $this->saveSimple($owner, ['store_seo_title' => 'عنوانٌ قديم', 'store_seo_index' => '0'])->assertSessionHasNoErrors();
        $html = $this->get($app.'/s/lily')->getContent();
        $this->assertSame('عنوانٌ قديم', $this->titleOf($html));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertSame([], $this->screen($owner)['pending']);
        $this->assertSame('0', MarketingSettings::group($shop->id, 'website')['store_seo_index']);
    }

    /**
     * صفحةٌ بسيطةٌ منشورة وموقعٌ مبنيٌّ قيد الإنشاء: يعدّل صاحبُها ظهورَ ما يُقرأ اليوم
     * من بابٍ مستقلّ — فلا يُنشر موقعُه الجديد، ولا تُمسّ مسوّدتُه ولا سيوه.
     */
    public function test_a_live_simple_page_beside_an_unpublished_builder_site_is_edited_on_its_own(): void
    {
        [$shop, $owner] = $this->simple('lily');
        $site = Builder::create($shop, Blueprints::STORE, 'modern', $owner->id);
        $this->saveBuilt($owner, ['title' => 'عنوانُ الموقع الجديد'])->assertSessionHasNoErrors();
        $hero = WebsiteSection::where('website_id', $site->id)->where('type', 'hero')->firstOrFail();
        $hero->update(['data' => array_merge((array) $hero->data, ['title' => 'جملةٌ في مسوّدة الموقع الجديد'])]);
        $site->touchDraft();
        $before = $site->fresh();
        $app = rtrim(url('/'), '/');

        // شاشتُه الرئيسيّة شاشةُ موقعه الجديد — وتدلّه على صفحته المنشورة
        $main = $this->actingAs($owner)->get(route('admin.website.seo'))->assertOk()->viewData('page');
        $this->assertSame('Admin/Website/Seo', $main['component']);
        $this->assertTrue($main['props']['liveSimple']);

        // وصفحتُه المنشورة لها شاشتُها: فحصُها فحصُ الصفحة البسيطة لا الموقعِ المسوّدة
        $page = $this->actingAs($owner)->get(route('admin.website.seo.store'))->assertOk()->viewData('page');
        $this->assertSame('Admin/Website/SimpleSeo', $page['component']);
        $this->assertTrue($page['props']['builderDraft']);
        $this->assertSame('ورود lily', $page['props']['fallback']['title']);
        $this->assertSame(url('/s/lily'), $page['props']['readiness']['canonical']);
        $this->assertTrue($page['props']['readiness']['published']);

        $this->actingAs($owner)->from(route('admin.website.seo.store'))->put(route('admin.website.seo.store.save'), [
            'store_seo_title' => 'ورود ليلي · باقات مسقط',
            'store_seo_desc' => 'باقاتُ وردٍ طبيعيّ تُوصَّل في مسقط خلال اليوم نفسه، وهدايا مختارة لكلّ مناسبة.',
            'store_seo_index' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.website.seo.store'));

        // يسري على المنشور الآن
        $html = $this->get($app.'/s/lily')->assertOk()->getContent();
        $this->assertSame('ورود ليلي · باقات مسقط', $this->titleOf($html));
        $this->assertStringNotContainsString('جملةٌ في مسوّدة الموقع الجديد', $html, 'حفظُ الصفحة نشر مسوّدةَ الموقع');
        $this->assertSame([url('/s/lily')], $this->locs($this->get($app.'/s/lily/sitemap.xml')->assertOk()->getContent()));

        // والموقعُ الجديد كما تُرك: بلا نشرة، ومسوّدتُه وسيوه لم يُمسّا
        $after = $site->fresh();
        $this->assertNull($after->published_version_id, 'حفظُ الصفحة نشر الموقعَ الجديد');
        $this->assertSame(0, WebsiteVersion::where('website_id', $site->id)->count());
        $this->assertSame($before->draft_revision, $after->draft_revision, 'حفظُ الصفحة مسّ مسوّدةَ الموقع');
        $this->assertSame($before->seo, $after->seo, 'حفظُ الصفحة كتب في سيو الموقع الجديد');
        $this->assertSame('جملةٌ في مسوّدة الموقع الجديد', WebsiteSection::find($hero->id)->data['title']);

        // وشاشتُها تحفظ من بابها هذا لا من باب الموقع الجديد
        $this->assertStringContainsString("route('admin.website.seo.store.save')", (string) file_get_contents(resource_path('js/Pages/Admin/Website/SimpleSeo.tsx')));

        // ومن لا يضبط الموقع لا يفتح البابين
        $staff = $this->owner($shop, ['role' => 'staff', 'permissions' => ['website', 'marketing']]);
        $this->actingAs($staff)->put(route('admin.website.seo.store.save'), ['store_seo_title' => 'موظّف', 'store_seo_index' => '1'])->assertForbidden();
        $this->assertSame('ورود ليلي · باقات مسقط', MarketingSettings::group($shop->id, 'website')['store_seo_title']);

        // فإذا نشر موقعَه صار هو المنشور — والبابُ يردّه إلى شاشته، والحفظُ منه مردود
        Publisher::publish($site->fresh(), $owner->id);
        $this->actingAs($owner)->get(route('admin.website.seo.store'))->assertRedirect(route('admin.website.seo'));
        $this->actingAs($owner)->put(route('admin.website.seo.store.save'), ['store_seo_title' => 'بعد النشر', 'store_seo_index' => '1'])->assertNotFound();
        $this->assertFalse($this->screen($owner)['liveSimple']);
    }

    /** والواجهةُ الخاصّة لا تُكتب من باب الصفحة البسيطة — مفاتيحُهما واحدة */
    public function test_the_simple_page_door_is_closed_to_a_themed_store(): void
    {
        [$shop, $owner] = $this->themed('ribbon');

        $this->actingAs($owner)->get(route('admin.website.seo.store'))->assertRedirect(route('admin.website.seo'));
        $this->actingAs($owner)->put(route('admin.website.seo.store.save'), ['store_seo_title' => 'من الباب الخطأ', 'store_seo_index' => '0'])->assertNotFound();
        $this->assertSame('', (string) (MarketingSettings::group($shop->id, 'website')['store_seo_title'] ?? ''));
        $this->assertSame('1', MarketingSettings::group($shop->id, 'website')['store_seo_index'] ?? '1');
    }

    /* ═══════════ خرائطُ المواقع على الطرق الثلاث ═══════════ */

    /**
     * الطريقُ البديل والنطاقُ الفرعيّ ونطاقُ التاجر — لكلّ نوع: ٢٠٠، و٤٠٤، وnoindex،
     * وعنوانٌ في الخريطة يساوي ما تكتبه الصفحةُ نفسُها في `canonical`.
     */
    public function test_sitemaps_on_the_path_the_subdomain_and_the_custom_domain(): void
    {
        [$builtShop, $builtOwner] = $this->built('wrood');
        [$themedShop, $themedOwner] = $this->themed('ribbon');
        [, $simpleOwner] = $this->simple('lily');

        // والطريقُ البديلُ بعنوانه كاملًا: المسارُ النسبيُّ بعد طلبٍ إلى نطاقٍ آخر يُرسَل إليه
        $app = rtrim(url('/'), '/');

        // الطريقُ البديل — والنطاقاتُ مطفأة
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);
        foreach (['wrood', 'ribbon', 'lily'] as $slug) {
            $locs = $this->locs($this->get('/s/'.$slug.'/sitemap.xml')->assertOk()->getContent());
            $this->assertSame($this->canonicalOf($this->get('/s/'.$slug)->getContent()), $locs[0], $slug.': الخريطةُ والصفحةُ تختلفان في الأصل');
            $this->assertStringStartsWith(url('/s/'.$slug), $locs[0]);
        }

        // النطاقُ الفرعيّ
        config(['storefront.subdomains' => true]);
        foreach (['wrood', 'ribbon', 'lily'] as $slug) {
            $host = 'http://'.$slug.'.abaadapp.om';
            $locs = $this->locs($this->get($host.'/sitemap.xml')->assertOk()->getContent());
            $this->assertSame('https://'.$slug.'.abaadapp.om', $locs[0], $slug.' على النطاق الفرعيّ');
            $this->assertSame($locs[0], $this->canonicalOf($this->get($host.'/')->assertOk()->getContent()));
        }
        $this->get('http://nobody.abaadapp.om/sitemap.xml')->assertNotFound();
        config(['storefront.subdomains' => false]);

        // ونطاقُ التاجر — مطفأً ثمّ نشطًا
        Domains::attach($builtShop->fresh(), 'wrood.om');
        $this->get('https://wrood.om/sitemap.xml')->assertNotFound();

        $this->activeDomain($builtShop, 'wrood.om');
        $this->activeDomain($themedShop, 'ribbon.om');
        $built = $this->locs($this->get('https://wrood.om/sitemap.xml')->assertOk()->getContent());
        $this->assertSame('https://wrood.om', $built[0]);
        $this->assertSame($built[0], $this->canonicalOf($this->get('https://wrood.om/')->assertOk()->getContent()));
        $themed = $this->locs($this->get('https://ribbon.om/sitemap.xml')->assertOk()->getContent());
        $this->assertContains('https://ribbon.om/shop', $themed);
        $this->assertSame('https://ribbon.om', $this->canonicalOf($this->get('https://ribbon.om/')->assertOk()->getContent()));
        $this->get('https://someone-else.om/sitemap.xml')->assertNotFound();

        // والصفحةُ البسيطة لا تُخدَم على نطاقٍ خاصّ — فلا خريطةَ لها هناك، وأصلُها طريقُها
        $this->activeDomain(Business::where('site_slug', 'lily')->firstOrFail(), 'lily.om');
        $this->get('https://lily.om/sitemap.xml')->assertNotFound();
        $this->assertSame($app.'/s/lily', $this->canonicalOf($this->get($app.'/s/lily')->assertOk()->getContent()));

        // وnoindex يطوي الخريطةَ على كلّ طريق
        $this->saveBuilt($builtOwner, ['title' => 'ورود', 'index' => false])->assertSessionHasNoErrors();
        $this->saveThemed($themedOwner, ['store_seo_index' => '0'])->assertSessionHasNoErrors();
        $this->saveSimple($simpleOwner, ['store_seo_index' => '0'])->assertSessionHasNoErrors();
        $this->get('https://wrood.om/sitemap.xml')->assertNotFound();
        $this->get('https://ribbon.om/sitemap.xml')->assertNotFound();
        foreach (['wrood', 'ribbon', 'lily'] as $slug) {
            $this->assertStringContainsString('noindex', $this->get($app.'/s/'.$slug)->assertOk()->getContent());
            $this->get($app.'/s/'.$slug.'/sitemap.xml')->assertNotFound();
        }
        config(['storefront.subdomains' => true]);
        $this->get('http://wrood.abaadapp.om/sitemap.xml')->assertNotFound();
        $this->get('http://ribbon.abaadapp.om/sitemap.xml')->assertNotFound();
        $this->get('http://lily.abaadapp.om/sitemap.xml')->assertNotFound();
    }

    /* ═══════════ و«حفظ» لا ينشر شيئًا معلّقًا ═══════════ */

    /**
     * الموقعُ المبنيّ: صفحةٌ أُضيفت، وتصميمٌ بُدّل، ومنتجٌ غيرُ منشور — لا يخرج منها
     * شيءٌ بحفظ البحث. والمستندُ المخدوم قبلَه وبعدَه واحدٌ إلّا سيوَه.
     */
    public function test_saving_search_publishes_no_pending_page_design_or_product_on_a_built_site(): void
    {
        [$business, $owner, $site] = $this->built('wrood');

        WebsitePage::create([
            'website_id' => $site->id, 'business_id' => $business->id, 'key' => 'custom',
            'title' => 'عروض الموسم', 'slug' => 'offers', 'status' => 'published', 'is_home' => false, 'removable' => true, 'position' => 9,
        ]);
        $site->update(['theme' => array_merge((array) $site->theme, ['accent' => '#123456'])]);
        $site->touchDraft();
        Product::create([
            'business_id' => $business->id, 'name' => 'باقةٌ لم تُنشر', 'price' => 30, 'cost' => 10,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'published' => false,
        ]);

        $before = Published::forBusiness($business->id)['site'];

        $this->saveBuilt($owner, ['title' => 'ورود مسقط · باقات', 'description' => 'وصفٌ جديد للبحث'])->assertSessionHasNoErrors();

        $after = Published::forBusiness($business->id)['site'];
        $this->assertSame('ورود مسقط · باقات', $after['seo']['title']);
        unset($before['seo'], $after['seo']);
        $this->assertEquals($before, $after, 'حفظُ البحث بدّل في الموقع المنشور غيرَ سيوه');

        $this->get('/s/wrood/offers')->assertNotFound();
        $this->assertStringNotContainsString('#123456', $this->get('/s/wrood')->getContent(), 'نُشر تصميمٌ معلّق');
        $this->assertStringNotContainsString('باقةٌ لم تُنشر', $this->get('/s/wrood')->getContent());
        $this->assertNotContains(url('/s/wrood/offers'), $this->locs($this->get('/s/wrood/sitemap.xml')->getContent()));

        // ونشرُ صاحبه يُخرجها — فالاختبارُ يرى ما يقيس
        Publisher::publish($site->fresh(), $owner->id);
        $this->get('/s/wrood/offers')->assertOk();
        $this->assertContains(url('/s/wrood/offers'), $this->locs($this->get('/s/wrood/sitemap.xml')->getContent()));
    }

    /** والواجهةُ الخاصّة: صفحةٌ فُتحت في المسوّدة ومنتجٌ غيرُ منشور — لا يخرجان */
    public function test_saving_search_publishes_no_pending_page_or_product_on_a_themed_store(): void
    {
        [$shop, $owner] = $this->themed('ribbon');
        MarketingSettings::save($shop->id, 'website', ['store_about' => 'محلُّ وردٍ في الخوير منذ عشر سنين.', 'store_pages' => 'contact']);
        ThemePublisher::enable($shop, null);
        $hidden = Product::create([
            'business_id' => $shop->id, 'name' => 'باقةٌ لم تُنشر', 'price' => 30, 'cost' => 10,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'published' => false,
        ]);

        // يفتح «من نحن» في المسوّدة ولا ينشر
        $this->actingAs($owner)->post(route('admin.marketing.store.save'), ['store_pages' => 'about,contact'])->assertSessionHasNoErrors();
        $live = StoreContent::live($shop->id);
        $site = StoreSite::where('business_id', $shop->id)->firstOrFail();

        $this->saveThemed($owner, ['store_seo_title' => 'ريبون — ورد مسقط'])->assertSessionHasNoErrors();

        $this->assertSame($live, StoreContent::live($shop->id), 'حفظُ البحث نشر شيئًا من المسوّدة');
        $this->assertSame(['store_pages'], StoreContent::changed($shop->id));
        $this->assertSame((int) $site->published_version_id, (int) $site->fresh()->published_version_id);
        $this->get('/s/ribbon/about')->assertNotFound();
        $this->get('/s/ribbon/p/'.$hidden->id)->assertNotFound();
        $xml = $this->get('/s/ribbon/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('/about', $xml);
        $this->assertStringNotContainsString('/p/'.$hidden->id.'<', $xml);

        ThemePublisher::publish($shop, $owner->id);
        $this->get('/s/ribbon/about')->assertOk();
    }

    /* ═══════════ وحارسُ البحث لا يمسّ سائرَ التسويق ═══════════ */

    /**
     * موظّفُ التسويق بلا ضبط الموقع: يحفظ شاشةَ المتجر كلَّها كما كان، ومعرّفَ
     * Google Analytics، ولا يُردّ لأنّ حمولته حملت مفاتيحَ البحث بقيمها نفسِها.
     */
    public function test_the_search_guard_leaves_the_rest_of_marketing_as_it_was(): void
    {
        [$shop] = $this->themed('ribbon');
        MarketingSettings::save($shop->id, 'website', ['store_seo_title' => 'ريبون', 'store_seo_index' => '1']);
        $staff = $this->owner($shop, ['role' => 'staff', 'permissions' => ['website', 'marketing']]);

        // حمولةُ «المتجر والطلبات» — مفاتيحُها كما ترسلها شاشتُها
        $this->actingAs($staff)->post(route('admin.marketing.store.save'), [
            'store_delivery_fee' => '2.500', 'store_free_delivery_over' => '30', 'store_delivery_note' => 'نوصّل حتّى التاسعة',
            'store_pay_cod' => '1', 'store_pay_transfer' => '1', 'store_bank' => 'OM12 3456',
            'store_thanks_title' => 'شكرًا لك', 'store_image_note' => 'الصورةُ للتوضيح',
        ])->assertSessionHasNoErrors();

        $now = MarketingSettings::group($shop->id, 'website');
        $this->assertSame('2.500', $now['store_delivery_fee']);
        $this->assertSame('OM12 3456', $now['store_bank']);
        $this->assertSame('شكرًا لك', $now['store_thanks_title']);

        // وحمولةٌ تحمل البحثَ بلا تغيير لا تُردّ — الحارسُ على ما تغيّر
        $this->actingAs($staff)->post(route('admin.marketing.store.save'), [
            'store_seo_title' => 'ريبون', 'store_seo_index' => '1', 'store_delivery_note' => 'نوصّل حتّى العاشرة',
        ])->assertSessionHasNoErrors();
        $this->assertSame('نوصّل حتّى العاشرة', MarketingSettings::group($shop->id, 'website')['store_delivery_note']);

        // ومعرّفُ Google Analytics بابُه كما كان
        $this->actingAs($staff)->from(route('admin.marketing.seo'))->post(route('admin.marketing.seo.save'), ['ga_measurement_id' => 'G-ABC1234'])
            ->assertSessionHasNoErrors();
        $this->assertSame('G-ABC1234', MarketingSettings::group($shop->id, 'seo')['ga_measurement_id']);
        $this->actingAs($staff)->get(route('admin.marketing.seo'))->assertOk();
    }

    /* ═══════════ حدُّ الخريطة واكتشافُها ═══════════ */

    /** منتجاتٌ معروضةٌ بالجملة — كما يكون متجرٌ كبير */
    private function products(Business $business, int $n, array $over = []): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < $n; $i++) {
            $rows[] = $over + [
                'business_id' => $business->id, 'name' => 'منتج '.$i, 'price' => 5, 'cost' => 2,
                'quantity' => 1, 'alert_qty' => 0, 'active' => true, 'published' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('products')->insert($chunk);
        }
    }

    /**
     * متجرٌ يزيد على ملفٍّ: `sitemap.xml` فهرسٌ لأجزائه، ولا يزيد جزءٌ على الحدّ،
     * ولا منتجَ يُقصّ، وعنوانا المنتج بلغتيه في جزءٍ واحد.
     */
    public function test_a_large_catalogue_splits_its_sitemap_under_the_protocol_limit(): void
    {
        [$shop] = $this->themed('ribbon');
        $per = intdiv(Sitemap::PER_FILE, 2);
        $this->products($shop, $per);
        $this->products($shop, 3, ['published' => false]);
        $app = rtrim(url('/'), '/');

        $this->assertLessThanOrEqual(50000, Sitemap::PER_FILE, 'حدُّ الملفّ فوق حدّ البروتوكول');

        $index = $this->get($app.'/s/ribbon/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertSame([url('/s/ribbon/sitemap-1.xml'), url('/s/ribbon/sitemap-2.xml')], $this->locs($index));

        $one = $this->locs($this->get($app.'/s/ribbon/sitemap-1.xml')->assertOk()->getContent());
        $two = $this->locs($this->get($app.'/s/ribbon/sitemap-2.xml')->assertOk()->getContent());
        $this->get($app.'/s/ribbon/sitemap-3.xml')->assertNotFound();

        $this->assertLessThanOrEqual(Sitemap::PER_FILE, count($one));
        $this->assertLessThanOrEqual(Sitemap::PER_FILE, count($two));

        // كلُّ منتجٍ معروضٍ بلغتيه، مرّةً واحدة — والمخفيُّ لا
        $all = array_merge($one, $two);
        $shown = DB::table('products')->where('business_id', $shop->id)->where('published', true)->where('active', true)->pluck('id');
        $this->assertSame(count($all), count(array_unique($all)), 'عنوانٌ في جزأين');
        foreach ($shown as $id) {
            $ar = url('/s/ribbon/p/'.$id);
            $this->assertContains($ar, $all);
            // وترجمتُه معه في جزئه — لا يفصل الحدُّ بينهما
            $this->assertSame(in_array($ar, $one, true), in_array($ar.'?lang=en', $one, true), 'فُصل المنتج عن ترجمته');
        }
        $hidden = DB::table('products')->where('business_id', $shop->id)->where('published', false)->pluck('id');
        foreach ($hidden as $id) {
            $this->assertNotContains(url('/s/ribbon/p/'.$id), $all, 'منتجٌ غير معروض في الخريطة');
        }

        // والعنوانُ ما تكتبه صفحتُه
        $this->assertSame(url('/s/ribbon/p/'.$shown->last()), $this->canonicalOf($this->get($app.'/s/ribbon/p/'.$shown->last())->getContent()));

        // وخريطةٌ في ملفٍّ واحد: جزؤها الأوّلُ هي نفسُها، ولا ثاني
        $this->built('wrood');
        $this->assertSame($this->get($app.'/s/wrood/sitemap.xml')->getContent(), $this->get($app.'/s/wrood/sitemap-1.xml')->assertOk()->getContent());
        $this->get($app.'/s/wrood/sitemap-2.xml')->assertNotFound();
    }

    /**
     * خريطةُ المنصّة: يدلّ عليها robots.txt، وتُعدّد ملفّاتِ كلِّ متجرٍ مؤهَّلٍ على مضيفها
     * — ومن يصير مؤهَّلًا غدًا يدخلها بلا يد، والمخفيُّ وغيرُ المنشور خارجَها.
     */
    public function test_the_platform_sitemap_lists_every_eligible_store_and_nothing_hidden(): void
    {
        $basic = Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => ['whatsapp']]);
        $pro = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => PlanFeatures::keys()]);

        $this->built('wrood');
        [$big] = $this->themed('ribbon');
        $this->products($big, intdiv(Sitemap::PER_FILE, 2));
        $this->simple('lily');

        // مخفيٌّ عن البحث، وغيرُ منشور، وباقتُه لا تبيع الموقع، وأصلُه نطاقُه الخاصّ
        [, $hiddenOwner] = $this->simple('hidden');
        $this->saveSimple($hiddenOwner, ['store_seo_index' => '0'])->assertSessionHasNoErrors();
        $this->business('draft');
        $later = $this->business('later', ['plan_id' => $basic->id]);
        MarketingSettings::save($later->id, 'website', ['store_on' => '1', 'store_headline' => 'لاحقًا']);
        [$own] = $this->built('own');
        $this->activeDomain($own, 'own-flowers.om');

        $app = rtrim(url('/'), '/');
        $platform = fn () => $this->locs($this->get($app.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent());

        $this->assertSame([
            url('/s/wrood/sitemap.xml'),
            url('/s/ribbon/sitemap-1.xml'),
            url('/s/ribbon/sitemap-2.xml'),
            url('/s/lily/sitemap.xml'),
        ], $platform(), 'خريطةُ المنصّة لا تطابق المؤهَّلين');

        // وكلُّ ملفٍّ فيها يُفتح — لا تدلّ على بابٍ مغلق
        foreach ($platform() as $file) {
            $this->get($file)->assertOk();
        }

        // والتاجرُ الذي تُفتح له الميزةُ لاحقًا يدخلها بلا يد
        $later->forceFill(['plan_id' => $pro->id])->save();
        $this->assertContains(url('/s/later/sitemap.xml'), $platform());

        // ومن أطفأ متجرَه يخرج منها
        MarketingSettings::save($later->id, 'website', ['store_on' => '0']);
        MarketingSettings::forget($later->id);
        $this->assertNotContains(url('/s/later/sitemap.xml'), $platform());

        // وrobots.txt يدلّ عليها، ولا يمنع المتاجر
        $robots = (string) file_get_contents(public_path('robots.txt'));
        $this->assertMatchesRegularExpression('#^Sitemap:\s*https://[^/\s]+/sitemap\.xml\s*$#m', $robots);
        preg_match('#^Sitemap:\s*(\S+)#m', $robots, $m);
        $this->assertSame('/sitemap.xml', parse_url($m[1], PHP_URL_PATH));
        $this->assertSame('/sitemap.xml', route('sitemap.platform', [], false));
        $this->assertTrue(SearchReadiness::robotsTxtAllows('/s/lily'));
    }
}
