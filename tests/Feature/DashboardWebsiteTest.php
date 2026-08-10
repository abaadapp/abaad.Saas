<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * زرّ «الموقع الإلكتروني».
 *
 * الزر كان موجودًا في النسخة القديمة بلا وجهة فحُذف. عاد بوجهة من إعدادات
 * النشاط — وهذا يعني أن قيمة يكتبها التاجر تصبح رابطًا قابلًا للنقر، فيلزم
 * تطبيعها والتحقق منها لا عرضها كما وصلت.
 *
 * وموضعه انتقل في 3.15 من اللوحة الرئيسية إلى الترويسة، فصار الرابط في
 * السياق المشترك (context.website) لا في بيانات صفحةٍ واحدة — وهو ما تقرؤه
 * هذه الاختبارات: الترويسة تُرسم على كل صفحة، والقيمة يجب أن تصلها هناك.
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

    private function setWebsite(?string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'website'],
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
        $this->setWebsite($raw);

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
        $this->setWebsite($raw);

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
        $this->setWebsite('abaad.om');

        $this->assertSame('https://abaad.om', $this->sharedWebsite());
    }

    public function test_it_reaches_the_header_on_every_page_not_the_dashboard_alone(): void
    {
        /*
         * الزرّ في الترويسة، والترويسة على كل صفحة. ولو بقي الرابط في بيانات
         * اللوحة وحدها لظهر الزرّ فارغًا في كل ما سواها — وهو أسوأ من غيابه:
         * زرٌّ يُرى ولا يفتح شيئًا.
         */
        $this->setWebsite('abaad.om');

        $this->assertSame('https://abaad.om', $this->sharedWebsite('admin.products.index'));
        $this->assertSame('https://abaad.om', $this->sharedWebsite('admin.settings.index'));
    }

    public function test_it_says_null_so_the_button_points_at_settings(): void
    {
        $this->assertNull($this->sharedWebsite());
    }

    public function test_a_broken_address_is_refused_at_save_time_not_discovered_as_a_dead_link(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['website' => 'javascript:alert(1)'])
            ->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('settings', [
            'business_id' => $this->business->id,
            'key' => 'website',
        ]);
    }

    public function test_a_valid_address_saves_and_reaches_the_dashboard(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['website' => 'abaad.om'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id,
            'key' => 'website',
            'value' => 'abaad.om',
        ]);

        $this->assertSame('https://abaad.om', $this->sharedWebsite());
    }

    public function test_clearing_the_field_removes_the_button_again(): void
    {
        $this->setWebsite('abaad.om');

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['website' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->sharedWebsite());
    }
}
