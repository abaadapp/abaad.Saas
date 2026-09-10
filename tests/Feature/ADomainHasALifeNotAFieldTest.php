<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteDomain;
use App\Support\MarketingSettings;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domain\CustomDomainProvider;
use App\Support\Website\Domain\DomainCheck;
use App\Support\Website\Domains;
use App\Support\Website\Publisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * النطاق كِيانٌ له حال — لا حقلٌ نصّيّ في جدول المفاتيح.
 *
 * وثلاثةٌ يحرسها هذا الملفّ:
 *
 * ١) **التفرّد في القاعدة لا في المتحكّم.** كان الفحصُ شرطًا مكتوبًا في
 *    شاشةٍ واحدة، فطلبان متزامنان يمرّان كلاهما ويصير للعنوان صاحبان —
 *    والقارئ ينتقي صفًّا من صفّين بترتيبٍ لا يضمنه محرّكٌ لأحد.
 *
 * ٢) **وحالُ الربط تُقال.** التاجر يكتب نطاقه ويرى «حُفظ» ثمّ يفتحه فلا
 *    يعمل، ولا شيء في اللوحة يقول أين وقف.
 *
 * ٣) **وعنوانُ أبعاد مستقلٌّ عمّا سواه.** من لم يربط نطاقًا، ومن ربطه ولم
 *    يُوجّهه بعد، ومن فكّه — كلُّهم يبقى موقعُهم يُفتح على عنوان أبعاد.
 */
class ADomainHasALifeNotAFieldTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_10_100000_a_domain_is_a_thing_with_a_life_not_a_text_field.php';

    private Business $mine;

    private Business $theirs;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط', 'site_slug' => 'wrood',
        ]);

        $this->theirs = Business::create([
            'name' => 'الجار', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'jar',
        ]);

        $this->owner = User::create([
            'business_id' => $this->mine->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** مزوّدٌ يقول ما نريد أن يقوله — فلا يخرج اختبارٌ إلى الشبكة */
    private function provider(DomainCheck $answer): void
    {
        $this->app->bind(CustomDomainProvider::class, fn () => new class($answer) implements CustomDomainProvider
        {
            public function __construct(private DomainCheck $answer) {}

            public function name(): string { return 'fake'; }

            public function instructions(WebsiteDomain $domain): array
            {
                return [['type' => 'CNAME', 'name' => 'www', 'value' => 'connect.abaadapp.om']];
            }

            public function register(WebsiteDomain $domain): ?string { return 'provider-123'; }

            public function check(WebsiteDomain $domain): DomainCheck { return $this->answer; }

            public function forget(WebsiteDomain $domain): void {}
        });
    }

    /* ═════════════════════ العنوانُ يُطبَّع ═════════════════════ */

    public function test_an_address_is_matched_the_way_it_arrives(): void
    {
        foreach ([
            'MyStore.OM' => 'mystore.om',
            'https://mystore.om/shop?x=1' => 'mystore.om',
            'mystore.om.' => 'mystore.om',
            'mystore.om:8443' => 'mystore.om',
            '  www.MyStore.om  ' => 'www.mystore.om',
        ] as $raw => $want) {
            $this->assertSame($want, Domains::normalize($raw), "«{$raw}» لم يُطبَّع");
        }
    }

    /** وما ليس نطاقًا لا يصير نطاقًا */
    public function test_what_is_not_a_domain_is_refused(): void
    {
        foreach (['', 'mystore', 'javascript:alert(1)', '-bad.om', 'a..b.om', '/path'] as $raw) {
            $this->assertNull(Domains::normalize($raw), "«{$raw}» قُبل نطاقًا");
        }
    }

    /* ═════════════════════ التفرّد ═════════════════════ */

    /** والقاعدةُ تحرسه لا المتحكّم: صفّان لعنوانٍ واحد لا يُكتبان */
    public function test_the_database_itself_refuses_a_second_row(): void
    {
        Domains::attach($this->mine, 'mystore.om');

        $this->expectException(QueryException::class);

        WebsiteDomain::create([
            'business_id' => $this->theirs->id,
            'hostname' => 'mystore.om',
            'normalized_hostname' => 'mystore.om',
            'type' => Domains::CUSTOM,
            'status' => Domains::PENDING,
        ]);
    }

    /** ومن سبق: الثاني يُردّ بكلمةٍ يفهمها لا بخطأ قاعدة */
    public function test_the_second_shop_is_told_not_crashed(): void
    {
        Domains::attach($this->mine, 'mystore.om');

        $result = Domains::attach($this->theirs, 'MYSTORE.OM');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame(1, WebsiteDomain::where('normalized_hostname', 'mystore.om')->count());
    }

    /**
     * ونطاقٌ قديمٌ في المرآة يُمسك أيضًا.
     *
     * الهجرةُ نقلت ما في `settings.site_domain` إلّا ما تكرّر منه. وسؤالُ
     * المرآة عند الادّعاء يمنع أن يُسجَّل ثالثٌ عنوانًا يُخدَم عليه غيرُه.
     */
    public function test_a_legacy_mirror_row_still_holds_the_name(): void
    {
        Setting::create([
            'business_id' => $this->theirs->id, 'key' => 'site_domain', 'value' => 'OLD.OM',
        ]);

        $this->assertFalse(Domains::attach($this->mine, 'old.om')['ok']);
    }

    /** ولا يُربط عنوانُ أبعاد نطاقًا «خاصًّا» — ذاك يُحجز لا يُدّعى */
    public function test_an_abaad_address_is_not_claimed_as_a_private_domain(): void
    {
        $result = Domains::attach($this->mine, 'jar.abaadapp.om');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, WebsiteDomain::where('normalized_hostname', 'jar.abaadapp.om')->count());
    }

    /* ═════════════════════ الحال ═════════════════════ */

    public function test_a_new_domain_waits_for_its_routing(): void
    {
        Domains::attach($this->mine, 'mystore.om');

        $this->assertSame(Domains::PENDING, Domains::custom((int) $this->mine->id)->status);
    }

    public function test_a_routed_domain_becomes_connected(): void
    {
        $this->provider(DomainCheck::active());
        Domains::attach($this->mine, 'mystore.om');

        $domain = Domains::check(Domains::custom((int) $this->mine->id));

        $this->assertSame(Domains::ACTIVE, $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertNull($domain->failure_reason);
    }

    /** وما لم يصحّ يُقال سببُه — لا «حدث خطأ» */
    public function test_a_misrouted_domain_says_why(): void
    {
        $this->provider(DomainCheck::failed('هذا النطاق يشير إلى مكانٍ آخر'));
        Domains::attach($this->mine, 'mystore.om');

        $domain = Domains::check(Domains::custom((int) $this->mine->id));

        $this->assertSame(Domains::FAILED, $domain->status);
        $this->assertSame('هذا النطاق يشير إلى مكانٍ آخر', $domain->failure_reason);
        $this->assertNotNull($domain->last_checked_at);
    }

    /** و«لم يصل بعد» ليست «فشل»: من أضاف سجلَّه قبل دقيقتين لا يُقال له أخطأت */
    public function test_a_record_that_has_not_spread_is_not_a_failure(): void
    {
        $this->provider(DomainCheck::waiting('لم يظهر السجلّ بعد'));
        Domains::attach($this->mine, 'mystore.om');

        $this->assertSame(
            Domains::VERIFYING,
            Domains::check(Domains::custom((int) $this->mine->id))->status,
        );
    }

    /** والفحصُ لا يقع عند فتح الشاشة: صفحةٌ تنتظر جوابَ DNS لا تفتح */
    public function test_opening_the_screen_asks_no_network(): void
    {
        $asked = 0;
        $this->app->bind(CustomDomainProvider::class, fn () => new class($asked) implements CustomDomainProvider
        {
            public function __construct(public int &$asked) {}

            public function name(): string { return 'counting'; }

            public function instructions(WebsiteDomain $domain): array { return []; }

            public function register(WebsiteDomain $domain): ?string { return null; }

            public function check(WebsiteDomain $domain): DomainCheck
            {
                $this->asked++;

                return DomainCheck::active();
            }

            public function forget(WebsiteDomain $domain): void {}
        });

        Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id);
        Domains::attach($this->mine, 'mystore.om');

        $this->actingAs($this->owner)->get(route('admin.website.domain'))->assertOk();

        $this->assertSame(0, $asked, 'الشاشة سألت الشبكة');
    }

    /* ═════════════════════ الملكيّة ═════════════════════ */

    /** والنطاقُ يخصّ نشاطَه وموقعَه — لا يُقرأ لجارٍ */
    public function test_a_domain_belongs_to_its_own_shop_and_site(): void
    {
        $site = Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id);
        Domains::attach($this->mine->refresh(), 'mystore.om');

        $domain = Domains::custom((int) $this->mine->id);

        $this->assertSame((int) $this->mine->id, (int) $domain->business_id);
        $this->assertSame((int) $site->id, (int) $domain->website_id);
        $this->assertNull(Domains::custom((int) $this->theirs->id));
    }

    /** ونطاقٌ رُبط قبل بناء الموقع يلحق به حين يُبنى */
    public function test_a_domain_bound_before_the_site_finds_it_later(): void
    {
        Domains::attach($this->mine, 'mystore.om');
        $this->assertNull(Domains::custom((int) $this->mine->id)->website_id);

        $site = Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id);

        $this->assertSame((int) $site->id, (int) Domains::custom((int) $this->mine->id)->website_id);
    }

    /* ═════════════════════ عنوانُ أبعاد مستقلّ ═════════════════════ */

    /** من لم يربط نطاقًا يُفتح موقعُه على عنوان أبعاد */
    public function test_the_platform_address_works_with_no_custom_domain(): void
    {
        Publisher::publish(
            Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );

        $this->get(route('site.published', 'wrood.'.\App\Support\Storefront::domain()))->assertSuccessful();
    }

    /** ومن ربطه ولم يُوجّهه بعد يبقى عنوانُ أبعاد يعمل */
    public function test_the_platform_address_survives_a_pending_domain(): void
    {
        Publisher::publish(
            Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );

        Domains::attach($this->mine->refresh(), 'mystore.om');

        $this->get(route('site.published', 'wrood.'.\App\Support\Storefront::domain()))->assertSuccessful();
    }

    /** ومن فكّ نطاقه لم يخسر عنوانه */
    public function test_detaching_a_domain_leaves_the_platform_address(): void
    {
        Publisher::publish(
            Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );

        Domains::attach($this->mine->refresh(), 'mystore.om');
        Domains::detach($this->mine->refresh());

        $this->assertNull(Domains::custom((int) $this->mine->id));
        $this->assertSame('', MarketingSettings::group((int) $this->mine->id, 'website')['site_domain']);
        $this->get(route('site.published', 'wrood.'.\App\Support\Storefront::domain()))->assertSuccessful();
    }

    /* ═════════════════════ المرآةُ لا تفترق ═════════════════════ */

    /**
     * ما يقرؤه زرُّ الشريط هو ما في الجدول.
     *
     * `settings.site_domain` يقرؤه `Demo::websiteUrl` والسيو وشاشةُ
     * الإعدادات. وكاتبُه واحد — طبقةُ النطاقات — فلا يفترق عن الأصل.
     */
    public function test_the_mirror_says_what_the_table_says(): void
    {
        Domains::attach($this->mine, 'https://MyStore.om/');

        $this->assertSame(
            'mystore.om',
            MarketingSettings::group((int) $this->mine->id, 'website')['site_domain'],
        );
        $this->assertSame('mystore.om', Domains::custom((int) $this->mine->id)->normalized_hostname);
    }

    /* ═════════════════════ الأصلُ ما يُخدَم ═════════════════════ */

    /**
     * و`canonical` لا يشير إلى عنوانٍ لا يُخدَم.
     *
     * الإشارةُ إلى نطاقٍ رُبط ولم يُوجَّه تدلّ محرّكَ البحث على بابٍ مغلق
     * وتترك الصفحةَ الحيّة بلا فهرسة — فتضرّ حيث يُراد بها النفع.
     */
    public function test_the_canonical_never_points_at_a_closed_door(): void
    {
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);

        Domains::attach($this->mine, 'mystore.om');

        $this->assertStringContainsString('/s/wrood', (string) Domains::canonical((int) $this->mine->id));
    }

    /** وحين يخدمها الخادم يصير نطاقُ التاجر النشطُ هو الأصل */
    public function test_a_served_and_active_domain_becomes_the_canonical(): void
    {
        config(['storefront.custom_domains' => true]);
        $this->provider(DomainCheck::active());

        Domains::attach($this->mine, 'mystore.om');
        Domains::check(Domains::custom((int) $this->mine->id));

        $this->assertSame('https://mystore.om', Domains::canonical((int) $this->mine->id));
        $this->assertTrue(Domains::custom((int) $this->mine->id)->fresh()->is_primary);
    }

    /* ═════════════════════ الهجرة ═════════════════════ */

    /**
     * وما كان مكتوبًا في المرآة يصير صفًّا نشطًا — لا «بانتظار التوجيه».
     *
     * هذه نطاقاتٌ تفتح مواقعَ في اللحظة التي تُنفَّذ فيها الهجرة. وإنزالُها
     * إلى «قيد الربط» يُطفئ متاجرَ تعمل، بلا أن يفعل أصحابُها شيئًا.
     */
    public function test_the_migration_carries_working_domains_over_as_they_are(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('website_domains');

        Setting::create(['business_id' => $this->mine->id, 'key' => 'site_domain', 'value' => 'MyStore.OM']);
        Setting::create(['business_id' => $this->theirs->id, 'key' => 'site_domain', 'value' => 'jar-shop.om']);

        (require base_path(self::MIGRATION))->up();

        $mine = WebsiteDomain::where('business_id', $this->mine->id)->first();

        $this->assertNotNull($mine);
        $this->assertSame('mystore.om', $mine->normalized_hostname, 'العنوان لم يُطبَّع فلا يُطابق ما يصل');
        $this->assertSame(Domains::ACTIVE, $mine->status);
        $this->assertSame(2, WebsiteDomain::count());
    }

    /**
     * وصفّان بعنوانٍ واحد لا يُسقطان الهجرة — يُؤخذ الأسبق.
     *
     * بقيا من قبل أن يُفحص التفرّد. وإسقاطُ الهجرة عليهما يوقف النشر كلَّه
     * على قاعدةٍ حقيقية، وذلك أسوأُ من ترك الثاني على حاله.
     */
    public function test_a_duplicated_legacy_domain_does_not_break_the_migration(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('website_domains');

        Setting::create(['business_id' => $this->mine->id, 'key' => 'site_domain', 'value' => 'shared.om']);
        Setting::create(['business_id' => $this->theirs->id, 'key' => 'site_domain', 'value' => 'SHARED.OM']);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame(1, WebsiteDomain::count());
        $this->assertSame(
            (int) $this->mine->id,
            (int) WebsiteDomain::first()->business_id,
            'العنوان أُعطي للثاني لا للأسبق',
        );
    }

    /* ═════════════════════ الخدمةُ على نطاق التاجر ═════════════════════ */

    /** ونطاقُ التاجر يفتح موقعه حين يخدمه الخادم */
    public function test_a_custom_domain_opens_the_built_site(): void
    {
        config(['storefront.custom_domains' => true]);

        Publisher::publish(
            Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );
        Domains::attach($this->mine->refresh(), 'mystore.om');

        $this->get('https://mystore.om/')->assertOk()->assertSee('ورود مسقط');
    }

    /** ومضيفٌ لا صفَّ له لا يُخدَم عليه موقعُ أحد */
    public function test_an_unclaimed_host_opens_nothing(): void
    {
        config(['storefront.custom_domains' => true]);

        Publisher::publish(
            Builder::create($this->mine, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );

        $this->get('https://someone-else.om/')->assertNotFound();
    }
}
