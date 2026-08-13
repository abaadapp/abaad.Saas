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

        /*
         * ساعةٌ ثابتة من اليوم.
         *
         * المحور يقف عند اللحظة الحاليّة، فبيعةٌ تُوضع في الساعة ١٤ تكون
         * ماضيًا إن جرى الاختبار مساءً ومستقبلًا إن جرى صباحًا — فيمرّ الملفّ
         * ويسقط بلا أن يتغيّر سطرٌ من الشيفرة.
         */
        $this->travelTo(now()->startOfDay()->addHours(20));
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

    public function test_the_axis_is_drawn_whole(): void
    {
        // الفترة تُعرض كاملة ليُقرأ اليومُ في موضعه منها: من يرى عمودًا في
        // منتصف محورٍ يعرف أنه في منتصف شهره
        $this->assertCount(24, Demo::salesTrend('today')['labels'], 'اليوم أربع وعشرون ساعة');
        $this->assertCount(7, Demo::salesTrend('week')['labels'], 'الأسبوع سبعة أيّام');
        $this->assertCount(now()->daysInMonth, Demo::salesTrend('month')['labels'], 'الشهر بأيّامه كلّها');
        $this->assertCount(12, Demo::salesTrend('year')['labels'], 'السنة اثنا عشر شهرًا');
        $this->assertCount(12, Demo::salesTrend('all')['labels'], 'الكلّ: آخر اثني عشر شهرًا');
    }

    public function test_what_has_not_happened_is_empty_not_zero(): void
    {
        /*
         * المحور كاملٌ والخطّ ليس كذلك: يومٌ لم يأتِ ليس يومًا بلا بيع.
         * بكتابته صفرًا يهوي الخطّ إلى القاع في منتصف كل شهر، فيقرأ صاحب
         * المتجر انهيارًا وهو تقويمٌ لم يُستهلك.
         */
        $trend = Demo::salesTrend('month');

        $this->assertNotNull($trend['data'][now()->day - 1], 'اليوم الجاري يُقرأ بما تحقّق منه');

        if (now()->day < now()->daysInMonth) {
            $this->assertNull($trend['data'][now()->day], 'كُتب صفرٌ عن يومٍ لم يأتِ');
            $this->assertNull($trend['counts'][now()->day]);
            $this->assertNull($trend['data'][now()->daysInMonth - 1], 'كُتب صفرٌ عن آخر الشهر');
        }

        $hours = Demo::salesTrend('today');
        $this->assertNotNull($hours['data'][now()->hour]);
        if (now()->hour < 23) {
            $this->assertNull($hours['data'][23], 'رُسمت ساعةٌ لم تأتِ صفرًا');
        }
    }

    public function test_a_day_with_no_sale_is_still_a_zero(): void
    {
        // الفراغ للمستقبل وحده: يومٌ مضى بلا بيع صفرٌ حقيقيّ يُرسم
        $trend = Demo::salesTrend('year');

        for ($i = 0; $i < now()->month; $i++) {
            $this->assertNotNull($trend['data'][$i], 'شهرٌ مضى صار فراغًا بدل صفر');
        }
    }

    public function test_a_window_across_two_years_says_which_year(): void
    {
        /*
         * «آخر ١٢ شهرًا» تبدأ من سبتمبر وتنتهي بأغسطس، فيُقرأ ديسمبر قبل
         * يناير فتبدو الأشهر غير مرتّبة وهي مرتّبة على سنتين.
         */
        $all = Demo::salesTrend('all')['labels'];

        $this->assertStringContainsString(now()->subYear()->format('y'), $all[0], 'أوّل شهرٍ بلا سنته');
        $this->assertStringContainsString(now()->format('y'), end($all));

        // وسنةٌ كاملة لا تحتاجها: أشهرها كلّها من سنةٍ واحدة
        $this->assertSame(
            [now()->startOfYear()->translatedFormat('F'), 12],
            [Demo::salesTrend('year')['labels'][0], count(Demo::salesTrend('year')['labels'])],
            'أُقحمت السنة على محورٍ لا يعبرها'
        );
    }

    public function test_each_column_carries_its_order_count(): void
    {
        // مئة ريالٍ من طلبٍ واحد غير مئةٍ من أربعين، والمبلغ وحده لا يفرّق
        $this->sale(60, now()->startOfDay()->addHours(9));
        $this->sale(40, now()->startOfDay()->addHours(9)->addMinutes(20));

        $trend = Demo::salesTrend('today');

        $this->assertSame(100.0, $trend['data'][9]);
        $this->assertSame(2, $trend['counts'][9]);
        $this->assertSame(0, $trend['counts'][8]);
    }

    public function test_every_column_has_a_label_that_reads_alone(): void
    {
        // «١٠» على المحور لا تقول أيّ شهرٍ ولا أيّ يومٍ من الأسبوع
        $trend = Demo::salesTrend('month');

        $this->assertCount(count($trend['labels']), $trend['full']);
        $this->assertStringContainsString(now()->translatedFormat('F'), $trend['full'][0]);
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
        $this->assertSame(0.0, $trend['data'][1] ?? 0.0);
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
        $props = $this->get(route('admin.reports.sales', ['range' => 'today']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('today', $props['range']);
        $this->assertCount(24, $props['salesSeries']['labels']);
    }

    public function test_a_made_up_period_falls_back_instead_of_breaking(): void
    {
        // الرابط مُدخَلٌ لا يُوثق به: ?range=<script> لا يكسر شاشة
        $props = $this->get(route('admin.reports.sales', ['range' => 'nonsense']))
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

    /* ------------------------- ما يغادر الشاشة ------------------------- */

    public function test_an_exported_file_carries_the_period_it_was_asked_for(): void
    {
        /*
         * الملفّ يغادر الشاشة: يُرسَل إلى المحاسب ويُطبع ويُفتح بعد شهرين،
         * ولا مبدّل فوقه يصحّح قراءته. فكان من يقرأ تقرير «اليوم» ويضغط
         * تصدير يخرج باثني عشر شهرًا ولا سطر فيه يقول ذلك.
         */
        $this->sale(70, now()->setTime(11, 0));
        $this->sale(900, now()->subMonths(3));

        $csv = $this->get(route('admin.export.reports', ['range' => 'today']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString(__('الفترة'), $csv);
        $this->assertStringContainsString(now()->startOfDay()->format('Y-m-d'), $csv);
        $this->assertStringContainsString('70.000', $csv);
        $this->assertStringNotContainsString('900.000', $csv, 'دخل الملفَّ ما هو خارج الفترة');
    }

    public function test_the_file_name_says_which_period_it_holds(): void
    {
        // ملفّان لفترتين في يومٍ واحد كانا يخرجان باسمٍ واحد
        $this->get(route('admin.reports.xlsx', ['range' => 'year']))
            ->assertOk()
            ->assertDownload('sales-report-year-'.now()->format('Y-m-d').'.xlsx');
    }

    public function test_the_printed_report_prints_its_period(): void
    {
        $pdf = $this->get(route('admin.reports.pdf', ['range' => 'today']))->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('sales-report-today', $pdf->headers->get('Content-Disposition'));
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
