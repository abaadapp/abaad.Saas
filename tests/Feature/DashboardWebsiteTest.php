<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * زرّ «الموقع الإلكتروني» في الترويسة.
 *
 * قيمةٌ يكتبها التاجر تصبح رابطًا قابلًا للنقر، فتُطبَّع ويُتحقّق منها لا
 * تُعرض كما وصلت. وموضع الزرّ الترويسة، والترويسة على كل صفحة — فالرابط في
 * السياق المشترك (context.website) لا في بيانات صفحةٍ واحدة.
 *
 * ومصدره صار واحدًا: شاشة «الموقع الإلكتروني» في أدوات التسويق. كان مفتاحان
 * لشيءٍ واحد — حقلٌ في بيانات النشاط ونطاقٌ في شاشة التسويق — فيضبط التاجر
 * أحدهما ويقرأ الزرّ الآخر.
 */
class DashboardWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function setDomain(?string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'site_domain'],
            ['value' => $value],
        );
    }

    private function setSetting(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key],
            ['value' => $value],
        );
    }

    public static function normalizes(): array
    {
        return [
            'بلا بروتوكول يُقرأ مسارًا نسبيًا فيُكمَّل' => ['abaad.om', 'https://abaad.om'],
            'مسافات زائدة من اللصق' => ['  abaad.om  ', 'https://abaad.om'],
            'https كما هو' => ['https://abaad.om', 'https://abaad.om'],
            'http لا يُرقّى قسرًا' => ['http://abaad.om', 'http://abaad.om'],
            'مسار داخلي محفوظ' => ['abaad.om/shop', 'https://abaad.om/shop'],
        ];
    }

    #[DataProvider('normalizes')]
    public function test_it_normalizes_what_the_merchant_typed(string $raw, string $expected): void
    {
        $this->actingAs($this->owner);
        $this->setDomain($raw);

        $this->assertSame($expected, Demo::websiteUrl());
    }

    public static function rejected(): array
    {
        return [
            'فارغ' => [''],
            'مسافات فقط' => ['   '],
            // زرٌّ يفتح javascript: منفذ تنفيذ لا رابط
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_refuses_anything_that_is_not_a_web_address(string $raw): void
    {
        $this->actingAs($this->owner);
        $this->setDomain($raw);

        $this->assertNull(Demo::websiteUrl());
    }

    public function test_it_is_null_when_the_merchant_never_set_one(): void
    {
        $this->actingAs($this->owner);

        $this->assertNull(Demo::websiteUrl());
    }

    /** الرابط كما يصل الترويسة على صفحةٍ ما */
    private function sharedWebsite(string $route = 'admin.dashboard'): ?string
    {
        return $this->actingAs($this->owner)
            ->get(route($route))
            ->assertOk()
            ->viewData('page')['props']['context']['website'] ?? null;
    }

    public function test_the_shared_context_hands_the_link_to_the_button(): void
    {
        $this->setDomain('abaad.om');

        $this->assertSame('https://abaad.om', $this->sharedWebsite());
    }

    public function test_it_reaches_the_header_on_every_page_not_the_dashboard_alone(): void
    {
        /*
         * الزرّ في الترويسة، والترويسة على كل صفحة. ولو بقي الرابط في بيانات
         * اللوحة وحدها لظهر الزرّ فارغًا في كل ما سواها — وهو أسوأ من غيابه:
         * زرٌّ يُرى ولا يفتح شيئًا.
         */
        $this->setDomain('abaad.om');

        $this->assertSame('https://abaad.om', $this->sharedWebsite('admin.products.index'));
        $this->assertSame('https://abaad.om', $this->sharedWebsite('admin.settings.index'));
    }

    public function test_it_says_null_so_the_button_points_at_the_marketing_screen(): void
    {
        $this->assertNull($this->sharedWebsite());
    }

    /* ------------------- وجهة الزرّ حين لا عنوان يُفتح ------------------- */

    /** @return array<string, mixed> */
    private function statusPage(): array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.marketing.website.status'))
            ->assertOk()
            ->viewData('page');
    }

    /**
     * من ضبط نطاقه يُنقل إليه — لا تُعرض عليه صفحة.
     *
     * ولا مفتاح نشرٍ في الشرط: كان `site_enabled` يُحفظ ولا يقرؤه شيء، فحُذف
     * من النظام. واشتراطُ مفتاحٍ لا سبيل إلى رفعه يعني زرًّا لا يعمل عند أحد.
     */
    public function test_a_merchant_with_a_domain_goes_straight_to_it(): void
    {
        $this->setDomain('abaad.om');

        $this->actingAs($this->owner)
            ->get(route('admin.marketing.website.status'))
            ->assertRedirect('https://abaad.om');
    }

    /** ومن لم يختر طريقه بعد يُعرض عليه أن يختار */
    public function test_a_merchant_who_has_not_chosen_yet_is_asked_to_choose(): void
    {
        $page = $this->statusPage();

        $this->assertSame('Admin/Marketing/WebsiteInactive', $page['component']);
        $this->assertSame('', $page['props']['mode']);
        $this->assertNull($page['props']['subdomain']);
        $this->assertNull($page['props']['request']);
    }

    /** ومن حجز اسمًا فرعيًّا يُقال له إنّ الاستضافة قيد التجهيز — بعنوانه */
    public function test_a_reserved_subdomain_is_shown_as_being_prepared(): void
    {
        $this->setSetting('site_domain_mode', 'subdomain');
        $this->setSetting('site_subdomain', 'my-store');

        $page = $this->statusPage();

        $this->assertSame('subdomain', $page['props']['mode']);
        $this->assertStringStartsWith('my-store.', (string) $page['props']['subdomain']);
        // ولا يُفتح: لا شيء يخدم هذا العنوان بعد
        $this->assertNull(Demo::websiteUrl());
    }

    /** ومن طلب من أبعاد وينتظر يُقال له إنّ طلبه قيد المعالجة — لا «اضبط نطاقك» */
    public function test_a_pending_request_is_reported_instead_of_asking_again(): void
    {
        $this->setSetting('site_domain_mode', 'new');
        \App\Models\DomainRequest::create([
            'business_id' => $this->business->id,
            'domain' => 'mystore.om',
            'status' => \App\Models\DomainRequest::PENDING,
        ]);

        $page = $this->statusPage();

        $this->assertSame('mystore.om', $page['props']['request']['domain']);
        $this->assertSame(\App\Models\DomainRequest::PENDING, $page['props']['request']['status']);
    }

    /* ---------------- ردُّ المشغّل يصل صاحبه في الجرس ---------------- */

    /** @return list<string> */
    private function bellTexts(): array
    {
        return array_column($this->actingAs($this->owner)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props']['notifications']['items'] ?? [], 'text');
    }

    public function test_a_completed_request_reaches_the_merchant_bell(): void
    {
        \App\Models\DomainRequest::create([
            'business_id' => $this->business->id, 'domain' => 'mystore.om',
            'status' => \App\Models\DomainRequest::DONE, 'handled_at' => now(),
        ]);

        $this->assertNotEmpty(array_filter($this->bellTexts(),
            fn ($t) => str_contains($t, 'mystore.om')),
            'أُغلق الطلب ولم يصل صاحبه خبرٌ به');
    }

    public function test_a_rejected_request_reaches_him_too(): void
    {
        \App\Models\DomainRequest::create([
            'business_id' => $this->business->id, 'domain' => 'taken.om',
            'note' => 'النطاق محجوز', 'status' => \App\Models\DomainRequest::REJECTED,
            'handled_at' => now(),
        ]);

        $this->assertNotEmpty(array_filter($this->bellTexts(),
            fn ($t) => str_contains($t, 'taken.om')));
    }

    /** والمعلّق لا يُنبَّه عليه: انتظارٌ لم ينتهِ ليس خبرًا يُقرع له الجرس */
    public function test_a_pending_request_does_not_ring_the_bell(): void
    {
        \App\Models\DomainRequest::create([
            'business_id' => $this->business->id, 'domain' => 'waiting.om',
            'status' => \App\Models\DomainRequest::PENDING,
        ]);

        $this->assertEmpty(array_filter($this->bellTexts(),
            fn ($t) => str_contains($t, 'waiting.om')));
    }

    /* ------------------------- المصدر واحد لا اثنان ------------------------- */

    public function test_the_domain_is_read_from_the_marketing_screen(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.save'), [
                'site_domain' => 'abaad.om', 'site_enabled' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://abaad.om', $this->sharedWebsite());
    }

    public function test_a_broken_address_is_refused_at_save_time_not_discovered_as_a_dead_link(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.save'), ['site_domain' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('site_domain');

        $this->assertNull($this->sharedWebsite());
    }

    public function test_clearing_the_field_removes_the_button_again(): void
    {
        $this->setDomain('abaad.om');

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.save'), ['site_domain' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->sharedWebsite());
    }

    /**
     * الحقل القديم في بيانات النشاط لم يعد يُقرأ — ولا يُحفظ.
     *
     * لو بقي يُحفظ لصار مقبضًا يُملأ ولا يفعل شيئًا: يكتب التاجر نطاقه فيه
     * ويبقى الزرّ فارغًا، وهو أسوأ من غياب الحقل.
     */
    public function test_the_old_field_in_the_business_settings_no_longer_feeds_the_button(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'website', 'value' => 'old.om',
        ]);

        $this->assertNull($this->sharedWebsite());
    }

    /** وما ضُبط قبل هذه النسخة يُنقل ولا يضيع */
    public function test_a_domain_set_before_this_version_was_carried_over(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'website', 'value' => 'https://old.om/shop',
        ]);
        DB::table('settings')->where('key', 'site_domain')->delete();

        // الهجرة بعينها — لا تُعاد كل الهجرات لأجل واحدة
        (require base_path('database/migrations/2026_08_18_090000_the_website_has_one_field_not_two.php'))->up();

        $this->assertSame('old.om', Setting::where('business_id', $this->business->id)
            ->where('key', 'site_domain')->value('value'));
        $this->assertSame('https://old.om', $this->sharedWebsite());
    }
}
