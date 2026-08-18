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

    /* ------------------------- المصدر واحد لا اثنان ------------------------- */

    public function test_the_domain_is_read_from_the_marketing_screen(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.save'), ['site_domain' => 'abaad.om'])
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
