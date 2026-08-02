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
 * زرّ «الموقع الإلكتروني» في اللوحة الرئيسية.
 *
 * الزر كان موجودًا في النسخة القديمة بلا وجهة فحُذف. عاد الآن بوجهة من
 * إعدادات النشاط — وهذا يعني أن قيمة يكتبها التاجر تصبح رابطًا قابلًا
 * للنقر، فيلزم تطبيعها والتحقق منها لا عرضها كما وصلت.
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

    public function test_the_dashboard_hands_the_link_to_the_button(): void
    {
        $this->setWebsite('abaad.om');

        $props = $this->actingAs($this->owner)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('https://abaad.om', $props['website']);
    }

    public function test_the_dashboard_says_null_so_the_button_points_at_settings(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['website']);
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

        $props = $this->actingAs($this->owner)
            ->get(route('admin.dashboard'))->viewData('page')['props'];

        $this->assertSame('https://abaad.om', $props['website']);
    }

    public function test_clearing_the_field_removes_the_button_again(): void
    {
        $this->setWebsite('abaad.om');

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['website' => ''])
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($this->owner)
            ->get(route('admin.dashboard'))->viewData('page')['props'];

        $this->assertNull($props['website']);
    }
}
