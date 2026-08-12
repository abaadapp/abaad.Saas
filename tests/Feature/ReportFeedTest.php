<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التقارير تُحتسب لحظة الفتح ثم تتجمّد — وصفحة تُترك مفتوحة على مكتب التاجر
 * كانت تعرض أرقام الصباح بعد يوم بيع كامل، وعليها يُبنى قرار.
 *
 * وأهمّ ما يُختبر هنا: أن التغذية تقيس **ما عُرض** عند الفتح بالضبط. تغذيةٌ
 * تحسب شيئًا آخر تجعل الأرقام تقفز بلا سبب ظاهر، وهو أسوأ من التجمّد.
 */
class ReportFeedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} حمولة الصفحة ثم حمولة التغذية */
    private function pageAndFeed(string $page, string $feed): array
    {
        $props = $this->actingAs($this->owner)->get(route($page))->assertOk()
            ->viewData('page')['props'];
        $json = $this->actingAs($this->owner)->getJson(route($feed))->assertOk()->json();

        unset($json['updated_at']);

        return [$props, $json];
    }

    /* ------------------ التغذية تطابق ما عُرض عند الفتح ------------------ */

    public function test_the_reports_feed_matches_what_the_page_rendered(): void
    {
        // التغذية تخدم ملخّص المبيعات لا الفهرس: الفهرس قائمةُ بطاقاتٍ لا أرقامًا
        [$props, $feed] = $this->pageAndFeed('admin.reports.sales', 'admin.reports.feed');

        $this->assertNotEmpty($feed);
        foreach ($feed as $key => $value) {
            $this->assertEquals($value, $props[$key], "التغذية تقيس غير ما عُرض: {$key}");
        }
    }

    public function test_the_analytics_feed_matches_what_the_page_rendered(): void
    {
        [$props, $feed] = $this->pageAndFeed('admin.analytics.index', 'admin.analytics.feed');

        $this->assertNotEmpty($feed);
        foreach ($feed as $key => $value) {
            $this->assertEquals($value, $props[$key], "التغذية تقيس غير ما عُرض: {$key}");
        }
    }

    public function test_the_profitability_feed_matches_what_the_page_rendered(): void
    {
        [$props, $feed] = $this->pageAndFeed('admin.profitability.index', 'admin.profitability.feed');

        $this->assertNotEmpty($feed);
        foreach ($feed as $key => $value) {
            $this->assertEquals($value, $props[$key], "التغذية تقيس غير ما عُرض: {$key}");
        }
    }

    /* --------------------------- تتحرّك فعلًا --------------------------- */

    public function test_a_new_sale_moves_the_figures_without_reopening_the_page(): void
    {
        $before = $this->actingAs($this->owner)->getJson(route('admin.reports.feed'))
            ->assertOk()->json('summary.sales');

        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'subtotal' => 100, 'tax' => 5, 'total' => 105,
            'user_id' => $this->owner->id,
            // بتاريخٍ صريح: صارت التقارير تُقرأ على فترة، وبيعةٌ بلا تاريخ
            // لا تُنسب إلى يومٍ ولا شهر — ونقطةُ البيع تكتبه دائمًا
            'ordered_at' => now(),
        ]);

        $after = $this->actingAs($this->owner)->getJson(route('admin.reports.feed'))
            ->assertOk()->json('summary.sales');

        $this->assertNotSame($before, $after, 'التغذية جامدة — لا تلتقط بيعة جديدة');
    }

    public function test_every_feed_carries_the_time_it_was_measured(): void
    {
        foreach (['admin.reports.feed', 'admin.analytics.feed', 'admin.profitability.feed'] as $name) {
            $at = $this->actingAs($this->owner)->getJson(route($name))->assertOk()->json('updated_at');

            $this->assertMatchesRegularExpression(
                '/^\d{2}:\d{2}:\d{2}$/',
                (string) $at,
                "رقمٌ بلا عمرٍ معروف يُقرأ على أنه لحظيّ: {$name}",
            );
        }
    }

    /* ----------------------------- الحراسة ----------------------------- */

    public function test_the_feeds_are_closed_to_guests(): void
    {
        foreach (['admin.reports.feed', 'admin.analytics.feed', 'admin.profitability.feed'] as $name) {
            $this->getJson(route($name))->assertUnauthorized();
        }
    }

    public function test_a_feed_never_reports_another_businesss_sales(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'X-1', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'subtotal' => 9000, 'tax' => 0, 'total' => 9000,
        ]);

        $sales = $this->actingAs($this->owner)->getJson(route('admin.reports.feed'))
            ->assertOk()->json('summary.sales');

        $this->assertSame(0.0, (float) $sales, 'تسرّبت مبيعات نشاط آخر إلى التغذية');
    }
}
