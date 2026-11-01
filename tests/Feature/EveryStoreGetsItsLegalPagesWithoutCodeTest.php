<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\StoreLegalVersion;
use App\Models\StoreTermsVersion;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\StoreLegal;
use App\Support\Store\StoreTerms;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domains;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لكلّ متجرٍ صفحاتُه القانونيّة — بلا أن يُنشئها له أحد، وبلا سطرٍ باسمه في الكود.
 *
 * متجرٌ بواجهة RIBBON، ومتجرٌ بسيطٌ جديد، ومتجرٌ بموقعٍ مبنيٍّ على نطاقه: كلٌّ
 * يجد قالبه من بياناته، ولا يُنشر حتّى يكمل بياناته ويراجع نصّه، ولا تقع
 * صفحةُ متجرٍ في عنوان غيره، ولا يُكتب فوق شروطٍ كتبها صاحبُها من قبل.
 */
class EveryStoreGetsItsLegalPagesWithoutCodeTest extends TestCase
{
    use RefreshDatabase;

    private Business $ribbon;

    private User $saud;

    private Business $fresh;

    private User $freshOwner;

    protected function setUp(): void
    {
        parent::setUp();

        // وقوائمُ متجرٍ بعينه فارغة — لا يرث متجرُ الاختبار ما يخصّ متجرًا على الإنتاج
        foreach (array_keys(config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        [$this->ribbon, $this->saud] = $this->shop('RIBBON', 'ribbon', 'ribbon', 'saud@abaad.test');
        [$this->fresh, $this->freshOwner] = $this->shop('ورد الخوير', 'wrood', null, 'owner@abaad.test');
    }

    /** @return array{Business, User} */
    private function shop(string $name, string $slug, ?string $theme, string $email): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '+96890000000',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => $theme,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);
        $owner = User::create(['business_id' => $shop->id, 'name' => $name, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        return [$shop, $owner];
    }

    private function facts(User $owner, string $legal, string $email = 'hello@shop.test')
    {
        return $this->actingAs($owner)->from(route('admin.website.legal'))
            ->put(route('admin.website.legal.facts'), ['store_legal_name' => $legal, 'store_legal_email' => $email]);
    }

    private function publish(User $owner, string $kind, ?string $ar = null, ?string $en = null)
    {
        $bid = (int) $owner->business_id;

        return $this->actingAs($owner)->from(route('admin.website.legal'))
            ->post(route('admin.website.legal.publish', $kind), [
                'body_ar' => $ar ?? StoreLegal::template($bid, $kind, 'ar'),
                'body_en' => $en ?? StoreLegal::template($bid, $kind, 'en'),
            ]);
    }

    private function fresh(): void
    {
        MarketingSettings::forget();
        StoreLegal::forget();
    }

    public function test_a_new_store_finds_its_three_templates_and_nothing_is_public_yet(): void
    {
        foreach (StoreLegal::PATHS as $path) {
            $this->get('/s/wrood/'.$path)->assertNotFound();
        }

        $this->actingAs($this->freshOwner)->get(route('admin.website.legal'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Website/Legal')
                ->where('pages.0.kind', StoreLegal::PRIVACY)
                ->where('pages.0.from', 'template')
                ->where('pages.0.published', null)
                ->where('pages.0.url', url('/s/wrood/privacy-policy'))
                ->where('pages.1.kind', StoreLegal::DELETION)
                ->where('pages.2.kind', StoreLegal::TERMS)
                ->where('missing.0.key', 'legal_name'));

        // ولا يُكتب صفٌّ بفتح الشاشة — القالبُ يُبنى ولا يُخزَّن
        $this->assertSame(0, StoreLegalVersion::count());
    }

    public function test_publishing_waits_for_the_legal_name_and_for_what_only_the_owner_can_write(): void
    {
        $this->publish($this->freshOwner, StoreLegal::PRIVACY)->assertSessionHasErrors('facts');
        $this->assertSame(0, StoreLegalVersion::count());

        $this->facts($this->freshOwner, 'Wrood Trading LLC')->assertSessionHasNoErrors();
        $this->fresh();

        // وسياسةُ الاسترجاع في الشروط لا يكتبها النظام — `[[ ]]` يردّ النشر
        $this->publish($this->freshOwner, StoreLegal::TERMS)->assertSessionHasErrors('body');
        $this->get('/s/wrood/terms')->assertNotFound();

        // وإحدى اللغتين فارغةً لا تُنشر
        $this->publish($this->freshOwner, StoreLegal::PRIVACY, null, '')->assertSessionHasErrors('body');

        $this->publish($this->freshOwner, StoreLegal::PRIVACY)->assertSessionHasNoErrors();
        $this->fresh();

        $page = $this->get('/s/wrood/privacy-policy')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $page->assertSee('Wrood Trading LLC')->assertSee('سياسة الخصوصية')->assertSee('hello@shop.test')
            ->assertDontSee('{{', false)->assertDontSee('[ناقص', false);

        $this->get('/s/wrood/privacy-policy?lang=en')->assertOk()
            ->assertSee('Privacy Policy')->assertSee('Wrood Trading LLC')->assertSee('/s/wrood/data-deletion', false);

        // والمنشورُ نصٌّ مملوءٌ ثابت — لا رموزٌ تُحلّ عند كلّ عرض
        $this->assertStringNotContainsString('{{', StoreLegalVersion::where('version', 1)->value('body_ar'));
    }

    public function test_ribbon_shows_its_legal_pages_in_its_own_layout_and_footer(): void
    {
        $home = (string) $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="rb-foot-legal"', $home, 'تذييلُ RIBBON كما كان حتّى تُنشر صفحة');

        $this->facts($this->saud, 'SALAM SITY TRADING LLC', 'care@ribbon.test');
        $this->fresh();
        $this->publish($this->saud, StoreLegal::PRIVACY)->assertSessionHasNoErrors();
        $this->publish($this->saud, StoreLegal::DELETION)->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('/s/ribbon/privacy-policy')->assertOk()
            ->assertSee('data-testid="rb-legal"', false)
            ->assertSee('data-testid="rb-foot-legal"', false)
            ->assertSee('SALAM SITY TRADING LLC')->assertSee('RIBBON')
            ->assertSee('care@ribbon.test');

        $this->get('/s/ribbon/data-deletion?lang=en')->assertOk()
            ->assertSee('Data Deletion Instructions')
            ->assertSee('Deletion is carried out manually by us, not automatically');

        $home = (string) $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('href="/s/ribbon/privacy-policy"', $home);
        $this->assertStringContainsString('href="/s/ribbon/data-deletion"', $home);
        $this->assertStringNotContainsString('data-testid="rb-foot-legal-terms"', $home, 'ولا رابطَ لصفحةٍ لم تُنشر');

        // وعلى النطاق الفرعيّ — جذرُه جذرُ المتجر
        $this->get('http://ribbon.'.config('storefront.domain').'/privacy-policy')->assertOk()
            ->assertSee('SALAM SITY TRADING LLC');
    }

    public function test_one_store_never_shows_another_stores_pages_or_data(): void
    {
        $this->facts($this->saud, 'SALAM SITY TRADING LLC');
        $this->fresh();
        $this->publish($this->saud, StoreLegal::PRIVACY)->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('/s/wrood/privacy-policy')->assertNotFound();

        // وما يحفظه صاحبُ متجرٍ يقع في متجره وحده — لا رقمَ متجرٍ في الرابط
        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();
        $this->publish($this->freshOwner, StoreLegal::PRIVACY)->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('/s/wrood/privacy-policy')->assertOk()
            ->assertSee('Wrood Trading LLC')->assertDontSee('SALAM SITY TRADING LLC')->assertDontSee('RIBBON');
        $this->get('/s/ribbon/privacy-policy')->assertOk()->assertDontSee('Wrood Trading LLC');

        $this->assertSame(1, StoreLegalVersion::where('business_id', $this->ribbon->id)->count());
        $this->assertSame(1, StoreLegalVersion::where('business_id', $this->fresh->id)->count());
    }

    public function test_terms_already_written_are_reused_and_never_overwritten(): void
    {
        StoreTerms::revise($this->ribbon->id, 'شروط سعود كما كتبها', 'Saud terms as written', $this->saud->id);
        MarketingSettings::save($this->ribbon->id, 'website', [StoreTerms::ON => '1']);
        $this->fresh();

        $this->get('/s/ribbon/terms')->assertOk()->assertSee('شروط سعود كما كتبها');
        $this->get('/s/ribbon/terms?lang=en')->assertOk()->assertSee('Saud terms as written');

        $this->actingAs($this->saud)->get(route('admin.website.legal'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pages.2.kind', StoreLegal::TERMS)
                ->where('pages.2.from', 'published')
                ->where('pages.2.body_ar', 'شروط سعود كما كتبها'));

        $this->assertSame(1, StoreTermsVersion::count());
        $this->assertSame(0, StoreLegalVersion::count());
    }

    public function test_terms_written_but_never_shown_stay_private_until_published(): void
    {
        StoreTerms::revise($this->ribbon->id, 'مسوّدة شروط', 'Draft terms', $this->saud->id);
        $this->fresh();

        $this->get('/s/ribbon/terms')->assertNotFound();

        $this->facts($this->saud, 'SALAM SITY TRADING LLC');
        $this->fresh();
        $this->publish($this->saud, StoreLegal::TERMS, 'شروطٌ منقّحة لـ {{store_name}}', 'Revised terms for {{store_name}}')
            ->assertSessionHasNoErrors();
        $this->fresh();

        // والنشرُ نسخةُ شروطٍ جديدة — الأولى باقيةٌ كما كُتبت
        $this->assertSame(2, StoreTermsVersion::count());
        $this->assertSame('مسوّدة شروط', StoreTermsVersion::where('version', 1)->value('body_ar'));
        $this->get('/s/ribbon/terms')->assertOk()->assertSee('شروطٌ منقّحة لـ RIBBON');
    }

    public function test_a_draft_changes_nothing_public_and_the_previous_text_can_come_back(): void
    {
        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();
        $this->publish($this->freshOwner, StoreLegal::DELETION, 'النص الأول', 'First text');
        $this->fresh();

        $this->actingAs($this->freshOwner)->put(route('admin.website.legal.draft', StoreLegal::DELETION), [
            'body_ar' => 'مسودة لم تنشر', 'body_en' => 'Unpublished draft',
        ])->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('/s/wrood/data-deletion')->assertOk()->assertSee('النص الأول')->assertDontSee('مسودة لم تنشر');
        $this->assertSame('draft', StoreLegal::source($this->fresh->id, StoreLegal::DELETION)['from']);

        $this->publish($this->freshOwner, StoreLegal::DELETION, 'النص الثاني', 'Second text');
        $this->fresh();
        $this->get('/s/wrood/data-deletion')->assertOk()->assertSee('النص الثاني');

        $this->actingAs($this->freshOwner)->post(route('admin.website.legal.restore', StoreLegal::DELETION))
            ->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('/s/wrood/data-deletion')->assertOk()->assertSee('النص الأول');
        // والرجوعُ نشرٌ جديد — لا يُمحى ما بعده
        $this->assertSame([1, 2, 3], StoreLegalVersion::where('business_id', $this->fresh->id)
            ->orderBy('version')->pluck('version')->all());
    }

    public function test_a_published_version_cannot_be_edited_or_deleted(): void
    {
        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();
        $this->publish($this->freshOwner, StoreLegal::PRIVACY, 'نص', 'Text');

        $v = StoreLegalVersion::where('version', 1)->firstOrFail();

        $this->expectException(\LogicException::class);
        $v->update(['body_ar' => 'نصٌّ آخر']);
    }

    public function test_the_store_template_mentions_only_what_the_store_uses(): void
    {
        $ar = StoreLegal::template($this->fresh->id, StoreLegal::PRIVACY, 'ar');

        // متجرٌ بلا بوّابة دفع لا يُقال عنه إنّ بطاقاتِ زبائنه تمرّ عبر Paymob
        $this->assertStringNotContainsString('Paymob', $ar);
        // ورقمُه مكتوبٌ فيُذكر واتساب — بصيغة «إن راسلتنا»
        $this->assertStringContainsString('واتساب', $ar);
        // والحذفُ يُوصف كما يقع: يدويًّا
        $this->assertStringContainsString('يدويًّا', StoreLegal::template($this->fresh->id, StoreLegal::DELETION, 'ar'));
    }

    public function test_preview_shows_the_unsaved_text_filled_with_the_store_data(): void
    {
        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();

        $this->actingAs($this->freshOwner)->post(route('admin.website.legal.preview', StoreLegal::PRIVACY), [
            'lang' => 'ar', 'body' => "## عنوان\nيديره {{legal_name}} — {{store_name}}",
        ])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('يديره Wrood Trading LLC — ورد الخوير');

        $this->assertSame(0, StoreLegalVersion::count());
    }

    public function test_a_merchant_site_on_its_own_domain_serves_its_legal_pages_there(): void
    {
        config(['storefront.custom_domains' => true]);

        Publisher::publish(Builder::create($this->fresh, Blueprints::STORE, 'modern', $this->freshOwner->id), $this->freshOwner->id);
        Domains::attach($this->fresh->refresh(), 'wrood-shop.om');

        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();
        $this->publish($this->freshOwner, StoreLegal::PRIVACY)->assertSessionHasNoErrors();
        $this->fresh();

        $this->get('https://wrood-shop.om/privacy-policy')->assertOk()->assertSee('Wrood Trading LLC');
        $this->get('https://wrood-shop.om/data-deletion')->assertNotFound();

        // والموقعُ المبنيّ يحمل روابطَ ما نُشر تحته
        $this->get('https://wrood-shop.om/')->assertOk()->assertSee('data-testid="site-legal-privacy"', false);
    }

    public function test_a_stranger_cannot_open_the_screen_and_legal_pages_need_no_login(): void
    {
        $this->get(route('admin.website.legal'))->assertRedirect();

        $this->facts($this->freshOwner, 'Wrood Trading LLC');
        $this->fresh();
        $this->publish($this->freshOwner, StoreLegal::PRIVACY);
        auth()->logout();
        $this->fresh();

        $this->get('/s/wrood/privacy-policy')->assertOk();
    }
}
