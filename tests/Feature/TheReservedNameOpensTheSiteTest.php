<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\DomainOptions;
use App\Support\Storefront;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الاسمُ الذي حجزه التاجر هو الذي يفتح موقعه.
 *
 * ═══ حجزٌ لا يؤدّي إلى شيء ═══
 *
 * العارضُ الخارجيّ كان يبحث عن الاسم المحجوز في مفتاح `site_subdomain` —
 * **مفتاحٌ لا يكتبه شيءٌ في النظام كلِّه**: لا شاشةَ تحفظه ولا متحكّمَ يمرّره،
 * ولا صفَّ له في قاعدة الإنتاج. فالفرعُ كلُّه لا يقع أبدًا.
 *
 * والاسمُ المحجوز فعلًا في مكانٍ آخر: `businesses.site_slug` — هو الذي
 * يُفحص تفرّدُه، وهو الذي يُعرض للتاجر عنوانًا (`متجري.abaadapp.om`)، وهو
 * الذي يخدمه مسارُ متجر أبعاد. فمن حجز اسمه وبنى موقعه ونشره ثمّ فتح عنوانه
 * وجد «غير موجود» — والحجزُ في لوحته يقول إنّه له.
 *
 * ═══ ولاحقةٌ من مصدرين ═══
 *
 * `DomainOptions::suffix` كانت تقرأ إعدادَ منصّةٍ **لا يكتبه أحد**، و`Route`
 * والعنوانُ المعروض يُبنيان من `config('storefront.domain')`. فلاحقتان لشيءٍ
 * واحد: لو ضُبطت الأولى يومًا لَبحث العارض في لاحقةٍ لا يخدمها الخادم.
 *
 * ولا يُغني نجاحُهما اليوم بالمصادفة — كلتاهما تردّ `abaadapp.om` — عن مصدرٍ
 * واحد: المصادفةُ تنقلب يوم يُضبط أحدُهما.
 */
class TheReservedNameOpensTheSiteTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_01_100000_a_domain_already_set_is_a_choice_made.php';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function publish(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
    }

    private function reserve(string $slug): void
    {
        $this->business->forceFill(['site_slug' => $slug])->save();
    }

    private function host(string $label): string
    {
        return $label.'.'.Storefront::domain();
    }

    /* --------------------------- الاسم المحجوز --------------------------- */

    public function test_the_reserved_name_opens_the_published_site(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $body = $this->get(route('site.published', $this->host('wrood')))
            ->assertSuccessful()->json();

        $this->assertSame('ورود مسقط', $body['site']['name'] ?? null);
    }

    /** ومن لم ينشر بعدُ يُقال له ذلك — لا «لا وجود لهذا العنوان» */
    public function test_a_reserved_name_without_a_published_site_says_so(): void
    {
        $this->reserve('wrood');

        $this->get(route('site.published', $this->host('wrood')))
            ->assertNotFound()->assertJson(['error' => 'not_published']);
    }

    /** واسمٌ لم يحجزه أحد لا يفتح شيئًا */
    public function test_an_unreserved_name_finds_nobody(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $this->get(route('site.published', $this->host('someone-else')))
            ->assertNotFound()->assertJson(['error' => 'not_found']);
    }

    /** والمضيفُ لا يفرّق بين حالتَي الحرف */
    public function test_the_host_is_matched_without_case(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $this->get(route('site.published', strtoupper($this->host('wrood'))))->assertSuccessful();
    }

    /** واسمٌ من جزأين ليس نطاقًا فرعيًّا محجوزًا */
    public function test_a_two_label_subdomain_is_not_a_reservation(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $this->get(route('site.published', 'a.'.$this->host('wrood')))->assertNotFound();
    }

    /**
     * ولا يُقرأ صفٌّ فيه نقطة على أنّه حجزُ اسمٍ واحد.
     *
     * `Storefront::slug` تُسقط النقاط، فلا يكتب هذا الصفَّ بابٌ في النظام —
     * ويكتبه عبثٌ مباشرٌ في القاعدة أو استيرادٌ من نسخةٍ قديمة. وحينها
     * `متجر.wrood.abaadapp.om` يفتح موقعًا: التاجرُ يظنّ عنوانه اسمًا واحدًا،
     * وكلُّ ما تحته يقود إليه.
     *
     * فالفحصُ على شكل المضيف لا على ما في العمود — والحارسُ أرخص من الثقة.
     */
    public function test_a_slug_carrying_a_dot_never_answers_a_deeper_host(): void
    {
        $this->business->forceFill(['site_slug' => 'a.wrood'])->save();
        $this->publish();

        $this->get(route('site.published', 'a.'.$this->host('wrood')))->assertNotFound();
    }

    /** والنطاقُ الذي يملكه التاجر يبقى كما كان */
    public function test_an_owned_domain_still_opens_the_site(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'wrood.om']);
        $this->publish();

        $this->get(route('site.published', 'wrood.om'))->assertSuccessful();
    }

    /** ولا يُقرأ موقعٌ بمعرّفه: عدّادٌ بسيط يمرّ على مواقع المتاجر كلّها */
    public function test_a_site_is_never_read_by_its_id(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $this->get(route('site.published', '1.'.Storefront::domain()))->assertNotFound();
    }

    /* --------------------------- اللاحقة الواحدة --------------------------- */

    /**
     * ولاحقةٌ واحدة: التي يخدمها الخادم.
     *
     * والفحصُ على المصدر لا على القيمة: كلتاهما تردّ `abaadapp.om` اليوم،
     * فمقارنةُ النصّ تمرّ ولو بقي مصدران. فيُبدَّل الإعداد الحيّ ويُنظر
     * أتتبعه اللاحقة أم تتبع إعدادًا لا يكتبه أحد.
     */
    public function test_the_suffix_follows_the_domain_the_server_serves(): void
    {
        config(['storefront.domain' => 'example.om']);

        $this->assertSame('example.om', DomainOptions::suffix());
        $this->assertSame('my-store.example.om', DomainOptions::host('my-store'));
    }

    /** ويتبعه العارضُ معها — فلا يبحث في لاحقةٍ لا تُخدَم */
    public function test_the_viewer_follows_the_same_suffix(): void
    {
        config(['storefront.domain' => 'example.om']);
        $this->reserve('wrood');
        $this->publish();

        $this->get(route('site.published', 'wrood.example.om'))->assertSuccessful();
        $this->get(route('site.published', 'wrood.abaadapp.om'))->assertNotFound();
    }

    /* ------------------------- ما تعرضه اللوحة ------------------------- */

    /**
     * واللوحةُ تعرض العنوان الذي يردّ عليه العارض — لا فراغًا.
     *
     * `domainState` كانت تقرأ المفتاح الميّت نفسه، فتردّ `null` دائمًا:
     * شاشةُ المعالج وشاشةُ السيو تعرضان «example.om» مكان عنوان التاجر.
     */
    public function test_the_panel_shows_the_host_the_viewer_answers(): void
    {
        $this->reserve('wrood');
        $this->publish();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.seo'))->viewData('page')['props'];

        $this->assertSame($this->host('wrood'), $props['domain']['subdomain']);
    }

    /** ومن لم يحجز اسمًا لا يُعرض له عنوانٌ مخترع */
    public function test_no_reservation_means_no_host(): void
    {
        $this->publish();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.seo'))->viewData('page')['props'];

        $this->assertNull($props['domain']['subdomain']);
    }

    /**
     * ولا يبقى في النظام قارئٌ للمفتاح الميّت.
     *
     * والفحصُ سلوكيّ أوّلًا: يُكتب الصفُّ بيدٍ ثمّ يُطلب العنوان. فلو بقي
     * قارئٌ لَفتح الموقعَ باسمٍ لم يحجزه صاحبُه من أيّ شاشة — وهو أسوأ من
     * ألّا يفتح: عنوانٌ يعمل ولا أحد يعرف من أين جاء.
     */
    public function test_a_row_in_the_dead_key_opens_nothing(): void
    {
        $this->publish();
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_subdomain', 'value' => 'ghost',
        ]);

        $this->get(route('site.published', $this->host('ghost')))->assertNotFound();
    }

    /** ولا في اللوحة: عنوانٌ يُعرض ولا يُجاب أسوأ من لا عنوان */
    public function test_the_dead_key_shows_no_host_in_the_panel(): void
    {
        $this->publish();
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_subdomain', 'value' => 'ghost',
        ]);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.website.seo'))->viewData('page')['props'];

        $this->assertNull($props['domain']['subdomain']);
    }

    /**
     * ولا يُقرأ المفتاح في شفرةٍ حيّة.
     *
     * والفحص على النصّ المقتبس (`'site_subdomain'`) لا على الكلمة: ذِكرُها
     * في شرحٍ يقول «رُفعت ولماذا» توثيقٌ لا قراءة — وأوّلُ صياغةٍ لهذا
     * الحارس سقطت على تعليقي أنا.
     */
    public function test_no_live_code_reads_the_dead_key(): void
    {
        $files = [
            'app/Http/Controllers/PublishedSiteController.php',
            'app/Http/Controllers/Admin/Website/Concerns.php',
            'app/Support/MarketingSettings.php',
        ];

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                "'site_subdomain'",
                file_get_contents(base_path($file)),
                "{$file} ما زال يقرأ مفتاحًا لا يكتبه أحد",
            );
        }
    }

    /* ------------------------------ الهجرة ------------------------------ */

    /**
     * والهجرةُ القديمة لا تنفجر على قاعدةٍ فيها نطاق.
     *
     * كانت تكتب `DomainOptions::OWN` — ثابتٌ **رُفع** من الصنف بعدها (وحارسٌ
     * في `OneListForTheAddressPathsTest` يمنع عودته). فسطرُها لا يُنفَّذ إلا
     * على قاعدةٍ فيها صفُّ `site_domain`، وهناك ينفجر: «ثابتٌ غير معرَّف».
     *
     * ولا تقع على الإنتاج — جرت يوم كان الثابت قائمًا — بل على استعادةِ نسخةٍ
     * أو خادمٍ جديد يُبنى من الصفر ببياناتٍ حقيقية. وهو أسوأُ وقتٍ تنفجر فيه.
     */
    public function test_the_old_migration_survives_a_database_with_a_domain(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'wrood.om']);

        (require base_path(self::MIGRATION))->up();

        $this->assertTrue(true, 'الهجرة نُفّذت بلا انفجار');
    }
}
