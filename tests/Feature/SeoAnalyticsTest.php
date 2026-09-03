<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الظهور في البحث وربط Google Analytics.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **«مربوط» تُقاس بالفحص لا باللصق**: لو قيست
 * بأنّ التاجر كتب معرّفًا في حقلٍ لَقالت الشاشة «مربوط» لمن نسي أن يلصق
 * الوسم في موقعه — فينتظر أرقامًا لا تأتي أبدًا ولا يعرف لماذا، والشاشةُ هي
 * التي كذبت عليه.
 *
 * ويحرس معه أنّ الشاشة **لا تعِد بما لا تفعل**: الموقع خارج النظام، فلا حقلَ
 * فيها لعنوان الصفحة ولا وصفها — وهي الحقول التي حُذفت من الإعدادات لأنّها
 * تُملأ ولا تصل صفحةً يقرؤها محرّك.
 */
class SeoAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'G-ABC1234567';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);

        $this->fakeSite();

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'robots.txt')) {
                return Http::response('User-agent: *', 200);
            }

            if (str_contains($url, 'sitemap.xml')) {
                return Http::response('<urlset/>', 200);
            }

            return Http::response($this->body, $this->status);
        });
    }

    private function domain(string $domain = 'mystore.om'): void
    {
        MarketingSettings::save($this->business->id, 'website', ['site_domain' => $domain]);
    }

    private function tag(?string $id = self::ID): void
    {
        MarketingSettings::save($this->business->id, 'seo', ['ga_measurement_id' => (string) $id]);
    }

    /** صفحةٌ سليمة — ويُبدَّل منها ما يُختبر */
    private function page(array $with = []): string
    {
        $d = array_merge([
            'title' => 'محل ورود أبعاد — مسقط',
            'description' => 'باقات ورد وهدايا وتوصيل داخل مسقط في اليوم نفسه لكل المناسبات.',
            'viewport' => true,
            'robots' => '',
            'tag' => '',
        ], $with);

        return '<html lang="ar"><head>'
            .'<title>'.$d['title'].'</title>'
            .'<meta name="description" content="'.$d['description'].'">'
            .($d['viewport'] ? '<meta name="viewport" content="width=device-width, initial-scale=1">' : '')
            .($d['robots'] !== '' ? '<meta name="robots" content="'.$d['robots'].'">' : '')
            .($d['tag'] !== '' ? '<script src="https://www.googletagmanager.com/gtag/js?id='.$d['tag'].'"></script>' : '')
            .'</head><body>ورود</body></html>';
    }

    /*
     * والردُّ يُبدَّل بمتغيّرٍ لا بتسجيلٍ ثانٍ.
     *
     * `Http::fake` تُراكم التسجيلات ويغلب أوّلُ ما يطابق، فتسجيلٌ ثانٍ في
     * منتصف الاختبار لا يُبدّل شيئًا — والاختبار يمرّ أو يسقط لغير سببه.
     */
    private string $body = '';

    private int $status = 200;

    private function fakeSite(array $with = [], int $status = 200): void
    {
        $this->body = $this->page($with);
        $this->status = $status;
    }

    private function check(bool $refresh = false): array
    {
        return Seo::check($this->business->id, $refresh);
    }

    /** بندٌ بمفتاحه من نتيجة الفحص */
    private function item(array $audit, string $key): array
    {
        return collect($audit['checks'])->firstWhere('key', $key) ?? [];
    }

    /* ========================= معرّف القياس ========================= */

    public function test_it_reads_a_measurement_id(): void
    {
        $this->assertSame(self::ID, Seo::measurementId(' g-abc1234567 '));
    }

    public function test_it_refuses_the_ids_that_never_measure(): void
    {
        /*
         * `UA-` توقّفت عن جمع البيانات، و`GTM-` معرّفُ مدير الوسوم لا القياس.
         * ومن لصق أحدهما ورأى «مربوط» انتظر أرقامًا لا تأتي أبدًا.
         */
        foreach (['UA-12345-1', 'GTM-ABCD12', 'ABC1234567', ''] as $bad) {
            $this->assertNull(Seo::measurementId($bad), "قُبل «{$bad}» وهو لا يقيس");
        }
    }

    public function test_an_unreadable_id_is_refused_before_it_is_stored(): void
    {
        $this->post(route('admin.marketing.seo.save'), ['ga_measurement_id' => 'UA-12345-1'])
            ->assertSessionHasErrors('ga_measurement_id');

        $this->assertSame('', MarketingSettings::group($this->business->id, 'seo')['ga_measurement_id']);
    }

    public function test_the_snippet_carries_the_id_that_was_saved(): void
    {
        // وسمٌ بمعرّفٍ آخر يُلصق في الموقع فيُرسل القياس إلى حسابٍ ليس حسابه
        $this->tag();

        $snippet = Seo::forBusiness($this->business->id)['snippet'];

        $this->assertStringContainsString(self::ID, $snippet);
        $this->assertStringContainsString('googletagmanager.com/gtag/js', $snippet);
    }

    /* ==================== «مربوط» تُقاس بالفحص ==================== */

    public function test_a_saved_id_alone_is_not_a_connection(): void
    {
        /*
         * هذا هو الحارس الأوّل: الحقلُ مملوءٌ والموقعُ يفتح — ولا وسمَ فيه.
         * ولو قيست الحالة باللصق لَقالت «مربوط» ولانتظر أرقامًا لا تأتي.
         */
        $this->domain();
        $this->tag();
        $this->fakeSite();

        $audit = $this->check();

        $this->assertFalse($audit['site']['tagged']);
        $this->assertSame('fail', $this->item($audit, 'analytics')['state']);
        $this->assertNotNull($this->item($audit, 'analytics')['fix']);
    }

    public function test_the_tag_seen_on_the_page_is_the_connection(): void
    {
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => self::ID]);

        $audit = $this->check();

        $this->assertTrue($audit['site']['tagged']);
        $this->assertSame('pass', $this->item($audit, 'analytics')['state']);
    }

    public function test_another_shops_tag_on_the_page_is_not_a_connection(): void
    {
        // وسمُ من بنى الموقع أو وسمُ متجرٍ آخر ليس وسمَك
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => 'G-ZZZ9999999']);

        $this->assertFalse($this->check()['site']['tagged']);
    }

    /* ========================= بنود الظهور ========================= */

    public function test_noindex_is_named_because_nothing_else_shows_it(): void
    {
        /*
         * سطرٌ واحد يبقى من يوم التجربة فلا تظهر الصفحة في البحث أبدًا —
         * والتاجر يرى موقعه يفتح فيظنّه سليمًا.
         */
        $this->domain();
        $this->fakeSite(['robots' => 'noindex, nofollow']);

        $this->assertSame('fail', $this->item($this->check(), 'noindex')['state']);
    }

    public function test_a_page_that_allows_indexing_passes(): void
    {
        $this->domain();
        $this->fakeSite();

        $this->assertSame('pass', $this->item($this->check(), 'noindex')['state']);
    }

    public function test_a_missing_title_is_a_failure_and_a_long_one_a_warning(): void
    {
        $this->domain();
        $this->fakeSite(['title' => '']);
        $this->assertSame('fail', $this->item($this->check(), 'title')['state']);

        Seo::forget($this->business->id);
        $this->fakeSite(['title' => str_repeat('ورود ومناسبات ', 12)]);
        $this->assertSame('warn', $this->item($this->check(true), 'title')['state']);
    }

    public function test_a_missing_viewport_is_named_because_most_customers_use_a_phone(): void
    {
        $this->domain();
        $this->fakeSite(['viewport' => false]);

        $this->assertSame('fail', $this->item($this->check(), 'viewport')['state']);
    }

    public function test_a_passing_check_carries_no_advice(): void
    {
        // نصيحةٌ تحت بندٍ سليم ضجيجٌ يُخفي البند الذي يحتاج عملًا
        $this->domain();
        $this->fakeSite();

        foreach ($this->check()['checks'] as $row) {
            if ($row['state'] === 'pass') {
                $this->assertNull($row['fix'], "بندٌ سليم «{$row['key']}» يحمل نصيحة");
            }
        }
    }

    /* ========================== المواقف ========================== */

    public function test_a_shop_without_a_domain_is_told_so_not_shown_a_clean_report(): void
    {
        $this->tag();

        $this->assertSame('nodomain', $this->check()['state']);
    }

    public function test_a_site_that_does_not_open_is_told_so(): void
    {
        $this->domain();
        $this->fakeSite(status: 500);

        $audit = $this->check();

        $this->assertSame('unreachable', $audit['state']);
        $this->assertNotNull($audit['error']);
    }

    public function test_a_failure_is_not_kept_in_memory(): void
    {
        /*
         * موقعٌ أُصلح يعمل في اللحظة، فحفظُ العطل نصفَ ساعةٍ يجعل التاجر
         * يُصلح ثمّ يرى العطلَ نفسه فيظنّ أنّه لم يُصلح.
         */
        $this->domain();
        $this->fakeSite(status: 500);
        $this->assertSame('unreachable', $this->check()['state']);

        $this->fakeSite();
        $this->assertSame('ok', $this->check()['state']);
    }

    public function test_a_second_read_does_not_open_the_site_again(): void
    {
        // الفحصُ يفتح موقع التاجر من خادمنا — وفتحُ الشاشة ليس سببًا لطلبٍ جديد
        $this->domain();
        $this->fakeSite();

        $this->check();
        $sent = count(Http::recorded());
        $this->check();

        $this->assertCount($sent, Http::recorded());
    }

    public function test_refresh_opens_the_site_again(): void
    {
        $this->domain();
        $this->fakeSite();
        $this->check();
        $before = count(Http::recorded());

        $this->post(route('admin.marketing.seo.refresh'))->assertRedirect();

        $this->assertGreaterThan($before, count(Http::recorded()));
    }

    public function test_changing_the_id_drops_the_old_check(): void
    {
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => self::ID]);
        $this->assertTrue($this->check()['site']['tagged']);

        // حالةُ ربطٍ محفوظةٌ لمعرّفٍ بُدّل خبرٌ عن غيره
        $this->post(route('admin.marketing.seo.save'), ['ga_measurement_id' => 'G-NEW7654321'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->check()['site']['tagged']);
    }

    public function test_one_shops_check_never_reaches_another(): void
    {
        $this->domain();
        $this->fakeSite();
        $this->check();

        $other = Business::create(['name' => 'جارتي', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertSame('nodomain', Seo::check($other->id)['state']);
    }

    /* =========================== الأبواب =========================== */

    public function test_the_page_opens_and_carries_its_checks(): void
    {
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => self::ID]);

        $this->get(route('admin.marketing.seo'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Marketing/Seo')
                ->where('link.measurement_id', self::ID)
                ->where('audit.state', 'ok')
                ->where('audit.site.tagged', true));
    }

    public function test_it_is_measured_by_the_marketing_section(): void
    {
        // كلُّ شاشةٍ تُقاس بقسمها — والتسويق ليس مفتوحًا لكل موظّف
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => ['pos'],
        ]);

        $this->actingAs($staff)->get(route('admin.marketing.seo'))->assertForbidden();
    }

    public function test_the_screen_promises_no_field_it_cannot_deliver(): void
    {
        /*
         * لا حقلَ لعنوان الصفحة ولا وصفها ولا كلماتها المفتاحية.
         *
         * الموقع خارج النظام، فما يُكتب عندنا لا يصل صفحةً يقرؤها محرّك.
         * وهي الحقول التي حُذفت من الإعدادات لهذا السبب، وإعادتُها تعيد
         * شاشةً تُملأ ولا تفعل شيئًا.
         */
        $keys = array_keys(MarketingSettings::GROUPS['seo']);

        $this->assertSame(['ga_measurement_id'], $keys);
    }
}
