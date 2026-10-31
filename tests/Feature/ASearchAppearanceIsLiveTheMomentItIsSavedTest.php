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
use App\Support\Website\Publisher;
use App\Support\Website\SearchReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
}
