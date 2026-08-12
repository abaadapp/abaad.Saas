<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فترةٌ واحدة تقرأ منها الشاشة كلّها.
 *
 * كانت كل قطعةٍ في التقارير تقرأ فترتها المخفيّة: البطاقات تجمع عمر المتجر
 * كلّه، والمخطّط تحتها يرسم السنة الجارية، والمقارنة تقيس شهرًا بشهر. ثلاث
 * فترات في شاشةٍ واحدة، ولا شيء يقول ذلك — فيُقرأ رقمٌ على أنه يخصّ ما فوقه
 * وهو عن مدًى آخر.
 *
 * ودقّة المحور تتبع المدى: يومٌ يُقرأ بالساعات، وشهرٌ بالأيّام، وسنةٌ
 * بالأشهر. ومن يختار «اليوم» فيرى اثني عشر عمودًا شهريًّا يظنّ مبيعات يومه
 * موزّعةً على السنة.
 */
class ReportRangeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 500, 'active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    private function sale(float $total, Carbon $at, string $method = 'نقدي'): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'payment_method' => $method, 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'is_held' => false, 'ordered_at' => $at,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => $this->product->name,
            'price' => $total, 'cost' => 6, 'quantity' => 1, 'total' => $total,
        ]);

        return $order;
    }

    /* ---------------------------- دقّة المحور ---------------------------- */

    public function test_the_axis_follows_the_chosen_period(): void
    {
        $this->assertCount(24, Demo::salesTrend('today')['labels'], 'اليوم يُقرأ بالساعات');
        $this->assertCount(7, Demo::salesTrend('week')['labels'], 'الأسبوع يُقرأ بالأيّام');
        $this->assertCount(now()->daysInMonth, Demo::salesTrend('month')['labels'], 'الشهر يُقرأ بأيّامه');
        $this->assertCount(12, Demo::salesTrend('year')['labels'], 'السنة تُقرأ بالأشهر');
        $this->assertCount(12, Demo::salesTrend('all')['labels'], 'الكلّ: آخر اثني عشر شهرًا');
    }

    public function test_a_sale_lands_in_its_own_bucket(): void
    {
        $this->sale(40, now()->startOfDay()->addHours(14));

        $trend = Demo::salesTrend('today');
        $hour = (int) now()->startOfDay()->addHours(14)->format('H');

        $this->assertSame(40.0, $trend['data'][$hour]);
        $this->assertSame(40.0, array_sum($trend['data']), 'تسرّبت البيعة إلى أكثر من عمود');
    }

    public function test_empty_buckets_are_zeros_not_gaps(): void
    {
        /*
         * يومٌ بلا بيع فراغٌ في المحور لا سقوطٌ منه: بحذفه تتقارب نقطتان
         * بينهما أسبوع فيبدو الخطّ متّصلًا وهو منقطع.
         */
        $this->sale(10, now()->startOfMonth());

        $trend = Demo::salesTrend('month');

        $this->assertCount(now()->daysInMonth, $trend['data']);
        $this->assertSame(10.0, $trend['data'][0]);
        $this->assertSame(0.0, $trend['data'][1]);
    }

    public function test_yesterday_stays_out_of_today(): void
    {
        $this->sale(90, now()->subDay()->setTime(12, 0));
        $this->sale(10, now()->setTime(12, 0));

        $this->assertSame(10.0, array_sum(Demo::salesTrend('today')['data']));
    }

    public function test_a_held_basket_is_not_a_sale(): void
    {
        $order = $this->sale(50, now()->setTime(10, 0));
        $order->update(['is_held' => true]);

        $this->assertSame(0.0, array_sum(Demo::salesTrend('today')['data']));
    }

    public function test_a_cancelled_order_is_not_counted(): void
    {
        $order = $this->sale(50, now()->setTime(10, 0));
        $order->update(['status' => 'ملغي']);

        $this->assertSame(0.0, array_sum(Demo::salesTrend('today')['data']));
    }

    public function test_another_stores_sales_never_enter_the_chart(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-900001',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => 'مكتمل',
            'subtotal' => 999, 'discount' => 0, 'tax' => 0, 'total' => 999,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->assertSame(0.0, array_sum(Demo::salesTrend('today')['data']));
    }

    /* --------------------------- بقيّة الشاشة --------------------------- */

    public function test_the_cards_follow_the_period_too(): void
    {
        // كانت تجمع عمر المتجر كلّه بينما المخطّط تحتها يرسم السنة
        $this->sale(100, now()->setTime(9, 0));
        $this->sale(500, now()->subMonths(3));

        $this->assertSame(100.0, Demo::reportSummary('today')['sales']);
        $this->assertSame(600.0, Demo::reportSummary('all')['sales']);
    }

    public function test_expenses_in_the_cards_follow_the_period(): void
    {
        Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار',
            'amount' => 300, 'method' => 'نقدي', 'spent_at' => now()->subMonths(2)->toDateString(),
        ]);

        $this->assertSame(0.0, Demo::reportSummary('today')['expenses']);
        $this->assertSame(300.0, Demo::reportSummary('all')['expenses']);
    }

    public function test_what_has_no_time_is_left_alone(): void
    {
        // عدد المنتجات حالةٌ الآن لا حصيلةُ فترة — فلا يتغيّر بتغيّرها
        $this->assertSame(1, Demo::reportSummary('today')['products']);
        $this->assertSame(1, Demo::reportSummary('year')['products']);
    }

    public function test_payment_distribution_follows_the_period(): void
    {
        $this->sale(60, now()->setTime(11, 0), 'بطاقة');
        $this->sale(400, now()->subMonths(4), 'نقدي');

        $today = Demo::paymentDistribution('today');

        $this->assertSame([60.0], $today['series']);
        $this->assertCount(2, Demo::paymentDistribution('all')['series']);
    }

    public function test_top_products_follow_the_period(): void
    {
        $this->sale(25, now()->setTime(13, 0));
        $this->sale(700, now()->subYear());

        $this->assertSame(25.0, Demo::topSellingProducts(5, 'today')[0]['revenue']);
        $this->assertSame(725.0, Demo::topSellingProducts(5, 'all')[0]['revenue']);
    }

    public function test_the_comparison_measures_the_chosen_period(): void
    {
        /*
         * كانت الشهر بالشهر دائمًا: من يقرأ «اليوم» في بقيّة الشاشة يجد هنا
         * شهرًا فيظنّ الأرقام متناقضة وهي عن فترتين.
         */
        $this->sale(30, now()->setTime(10, 0));
        $this->sale(20, now()->subDay()->setTime(10, 0));

        $today = Demo::periodComparison('today');

        $this->assertSame(__('مبيعات اليوم'), $today[0]['label']);
        $this->assertSame(50.0, $today[0]['delta'], '٣٠ مقابل ٢٠ = زيادة ٥٠٪');
    }

    /* ----------------------------- المسارات ----------------------------- */

    public function test_the_page_echoes_the_period_it_drew(): void
    {
        $props = $this->get(route('admin.reports.index', ['range' => 'today']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('today', $props['range']);
        $this->assertCount(24, $props['salesSeries']['labels']);
    }

    public function test_a_made_up_period_falls_back_instead_of_breaking(): void
    {
        // الرابط مُدخَلٌ لا يُوثق به: ?range=<script> لا يكسر شاشة
        $props = $this->get(route('admin.reports.index', ['range' => 'nonsense']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('month', $props['range']);
    }

    public function test_the_live_feed_keeps_the_chosen_period(): void
    {
        /*
         * بلا تمرير الفترة كانت الشاشة تُحدَّث بعد دقائق على الافتراضيّة:
         * يفتح التاجر «اليوم» فتنقلب أرقامه إلى أرقام الشهر بلا أن يلمس شيئًا.
         */
        $this->sale(15, now()->setTime(12, 0));
        $this->sale(500, now()->subDays(10));

        $feed = $this->getJson(route('admin.reports.feed', ['range' => 'today']))->assertOk()->json();

        $this->assertSame(15.0, (float) $feed['summary']['sales']);
        $this->assertCount(24, $feed['salesSeries']['labels']);
    }

    public function test_analytics_and_profitability_take_the_period_as_well(): void
    {
        $this->sale(80, now()->setTime(15, 0));
        $this->sale(900, now()->subMonths(5));

        $analytics = $this->get(route('admin.analytics.index', ['range' => 'today']))
            ->assertOk()->viewData('page')['props'];
        $profit = $this->get(route('admin.profitability.index', ['range' => 'today']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('today', $analytics['range']);
        $this->assertSame(80.0, $analytics['topProducts'][0]['total']);

        $this->assertSame('today', $profit['range']);
        $this->assertSame(80.0, $profit['products'][0]['revenue']);
    }
}
