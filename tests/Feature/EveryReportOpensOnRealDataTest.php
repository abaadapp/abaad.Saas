<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كلُّ تقريرٍ يُفتح على بياناتٍ حقيقيّة — لا على محلٍّ فارغ.
 *
 * ═══ ولمَ لا يكفي ما كان ═══
 *
 * `ReportsCatalogTest` يفتح كلَّ تقريرٍ في الفهرس — على متجرٍ **بلا صفٍّ
 * واحد**. وأكثرُ ما يكسر التقاريرَ لا يقع على الفراغ: قسمةٌ على صفرٍ حين
 * تُحسب نسبة، وحقلٌ فارغٌ في صفٍّ واحدٍ يُجمع مع غيره، وتجميعٌ على عمودٍ
 * نصّيّ. فشاشةٌ تُفتح فارغةً ثمّ تنكسر أوّلَ يومٍ يبيع فيه صاحبُها.
 *
 * وقوائمُ المسارات كانت تُكتب باليد في اختبارين — `PAGES` و`MOVED`. فتقريرٌ
 * يُضاف بعد شهرٍ لا يمرّ على أحد.
 *
 * ═══ فما يُفعل هنا ═══
 *
 * المساراتُ تُعدّ من جدول التوجيه نفسِه — كلُّ ما اسمه `admin.reports.*`.
 * ويُنادى كلٌّ منها على **كلّ فترة**، وعلى بياناتٍ فيها طلباتٌ وبنودٌ
 * ومصروفاتٌ وحركاتٌ وزبائنُ ومورّدون. وما ردَّ غيرَ ٢٠٠ يسقط هنا لا في وجه
 * التاجر.
 */
class EveryReportOpensOnRealDataTest extends TestCase
{
    use RefreshDatabase;

    /** الفترات التي تعرضها الشاشة — انظر `Demo::rangeStart` */
    private const RANGES = ['today', 'week', 'month', 'year', 'all'];

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->seedRealActivity();
    }

    /**
     * كلُّ تقريرٍ على كلّ فترة.
     *
     * ولا يُكتفى بـ«لم يسقط»: الحالةُ تُقرأ ويُقال أيُّ تقريرٍ وأيُّ فترة.
     */
    public function test_every_report_page_opens_on_every_period(): void
    {
        $broken = [];
        $checked = 0;

        foreach ($this->reportRoutes() as $name => $uri) {
            foreach (self::RANGES as $range) {
                $checked++;

                $status = $this->actingAs($this->owner)
                    ->get('/'.ltrim($uri, '/').'?range='.$range)
                    ->getStatusCode();

                if ($status !== 200) {
                    $broken[] = $name.' ['.$range.'] = '.$status;
                }
            }
        }

        $this->assertGreaterThan(80, $checked, 'لم يُجرَّب ما يكفي — الفحصُ نفسُه معطوب');
        $this->assertSame([], $broken, "تقاريرُ لا تُفتح:\n".implode("\n", $broken));
    }

    /**
     * والتصديرُ يخرج كما تخرج الشاشة.
     *
     * ملفٌّ يُعرض زرُّه ولا ينزل أسوأ من غياب الزرّ: صاحبُه ينتظره ثمّ يعيد
     * المحاولة ظانًّا العطبَ في جهازه.
     */
    public function test_every_report_exports_in_every_format(): void
    {
        $broken = [];
        $checked = 0;

        foreach (Reports::ALL as $report) {
            /*
             * و«المبيعات» بابُ تصديرٍ خاصٌّ بها — `admin.reports.xlsx` وأخواتها.
             * ولا صفوفَ لها في `ReportColumns`، فالبابُ العامّ يردّ ٤٠٤ بحقّ.
             * ويُجرَّب بابُها في `test_the_sales_report_exports_through_its_own_door`.
             */
            if (! ReportColumns::has($report['key']) && ! ReportColumns::sectioned($report['key'])) {
                continue;
            }

            foreach (['xlsx', 'csv', 'pdf'] as $format) {
                $checked++;

                $response = $this->actingAs($this->owner)
                    ->get(route('admin.reports.export.'.$format, [
                        'report' => $report['key'], 'range' => 'all',
                    ]));

                if (! in_array($response->getStatusCode(), [200, 302], true)) {
                    $broken[] = $report['key'].'.'.$format.' = '.$response->getStatusCode();
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'لم يُجرَّب ما يكفي');
        $this->assertSame([], $broken, "تصديرٌ لا يخرج:\n".implode("\n", $broken));
    }

    /**
     * وتقريرُ المبيعات يُصدَّر من بابه هو.
     *
     * ثلاثةُ مساراتٍ قديمةٍ خاصّةٍ به (`admin.reports.xlsx` و`pdf`
     * و`admin.export.reports`) — وهي التي تُرسمها شاشتُه. فلو سقطت لَبقيت
     * الأزرارُ معروضةً لا تُنزِّل شيئًا.
     */
    public function test_the_sales_report_exports_through_its_own_door(): void
    {
        foreach (['admin.reports.xlsx', 'admin.reports.pdf', 'admin.export.reports'] as $name) {
            $this->actingAs($this->owner)
                ->get(route($name, ['range' => 'month']))
                ->assertOk();
        }
    }

    /**
     * وكلُّ رابطِ تنزيلٍ **مرسومٍ في شاشة** يُفتح فعلًا.
     *
     * «بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض». والشاشاتُ تُقرأ من
     * المجلّد لا من قائمةٍ تُكتب: شاشةٌ تُضاف بعد شهرٍ ومعها زرُّ تنزيلٍ
     * إلى مسارٍ لا وجود له تسقط هنا لا في يد التاجر.
     */
    public function test_every_download_link_drawn_on_a_screen_really_opens(): void
    {
        $dir = resource_path('js/Pages/Admin/Reports');
        $broken = [];
        $checked = 0;

        foreach (glob($dir.'/*.tsx') ?: [] as $file) {
            $source = file_get_contents($file);

            /* route('admin.…', 'key')  أو  route('admin.…', { … }) */
            preg_match_all("/route\\('(admin\\.[a-z.]*(?:xlsx|pdf|csv|reports))'(?:\\s*,\\s*'([a-z]+)')?/", $source, $m, PREG_SET_ORDER);

            foreach ($m as $hit) {
                $name = $hit[0 + 1];
                $key = $hit[2] ?? null;

                if (! Route::has($name)) {
                    $broken[] = basename($file).' → '.$name.' (مسارٌ لا وجود له)';

                    continue;
                }

                $checked++;

                $url = $key !== null
                    ? route($name, [$key, 'range' => 'month'])
                    : route($name, ['range' => 'month']);

                $status = $this->actingAs($this->owner)->get($url)->getStatusCode();

                if ($status !== 200) {
                    $broken[] = basename($file).' → '.$name.($key ? " [{$key}]" : '').' = '.$status;
                }
            }
        }

        $this->assertGreaterThan(8, $checked, 'لم تُقرأ روابطُ التنزيل — الفحصُ نفسُه معطوب');
        $this->assertSame([], $broken, "روابطُ تنزيلٍ معروضةٌ لا تُفتح:\n".implode("\n", $broken));
    }

    /**
     * وكلُّ تقريرٍ يقرأ **المتجرَ المطلوب** لا متجرَ من هو داخلٌ الآن.
     *
     * ═══ العطبُ الذي كان ═══
     *
     * `ReportData::customers` و`staff` تأخذان `$bid` ولا تستعملانه: كانتا
     * تُنادِيان `Demo::topCustomers` و`staffPerformance` وهما تقرآن
     * `Demo::bid()` — أي متجرَ من هو داخلٌ الآن.
     *
     * ولا يُسرّب اليوم لأنّ الشاشة تُنادَى وصاحبُها داخل. لكنّه معطًى يكذب:
     * يومَ يُنادى من طابورٍ أو تقريرٍ للمنصّة يردّ صفوفَ متجرٍ آخر — أو لا
     * شيء. والعطبُ من هذا النوع لا يُكتشف حتّى يقع.
     *
     * ═══ وكيف يُحرَس ═══
     *
     * يُنادى كلُّ تقريرٍ **بمعرّف متجرٍ ثانٍ** بينما الداخلُ صاحبُ الأوّل،
     * ويُقارَن بما يردّه نداءٌ بمعرّف الأوّل. فما لا يفترق بينهما لا يقرأ
     * `$bid` أصلًا.
     */
    public function test_every_report_reads_the_business_it_was_asked_for(): void
    {
        /* متجرٌ ثانٍ بنشاطٍ مختلف — ولا مستخدمَ لنا فيه */
        $other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);

        User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        Customer::create(['business_id' => $other->id, 'name' => 'زبونُ الآخر', 'phone' => '96890000099']);

        $deaf = [];

        foreach (['customers', 'staff'] as $report) {
            $mine = ReportData::$report($this->shop->id, ['range' => 'all']);
            $theirs = ReportData::$report($other->id, ['range' => 'all']);

            if ($mine === $theirs) {
                $deaf[] = $report;
            }
        }

        $this->assertSame([], $deaf, 'تقاريرُ لا تقرأ المتجرَ المطلوب: '.implode('، ', $deaf));
    }

    /** ولا تقريرَ في جدول التوجيه خارج هذا الفحص */
    public function test_no_report_route_escapes_this_check(): void
    {
        $this->assertGreaterThan(
            15,
            count($this->reportRoutes()),
            'عددُ التقارير المفحوصة انخفض — أحُذفت أم فاتت الفحص؟'
        );
    }

    /* ═══════════════════ أدوات ═══════════════════ */

    /**
     * مساراتُ التقارير — من جدول التوجيه لا من قائمةٍ تُكتب باليد.
     *
     * @return array<string, string>
     */
    private function reportRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! str_starts_with($name, 'admin.reports.')) {
                continue;
            }

            // ما يأخذ معاملًا يُجرَّب في اختبار التصدير بمفاتيحه الحقيقيّة
            if (str_contains($route->uri(), '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $out[$name] = $route->uri();
        }

        return $out;
    }

    /** نشاطٌ حقيقيّ: بيعٌ وبنودٌ ومصروفٌ وحركةُ مالٍ ومورّدٌ وزبون */
    private function seedRealActivity(): void
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96890000001',
        ]);

        $product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 30, 'alert_qty' => 3, 'active' => true,
        ]);

        Supplier::create(['business_id' => $this->shop->id, 'name' => 'مشتل']);

        /* طلباتٌ موزّعةٌ على الفترات: اليوم، وهذا الشهر، والعام الماضي */
        foreach ([now(), now()->copy()->subDays(10), now()->copy()->subMonths(8)] as $i => $when) {
            $order = Order::create([
                'business_id' => $this->shop->id, 'branch_id' => 1,
                'customer_id' => $customer->id, 'customer_name' => 'زبونة',
                'employee_name' => 'المالك', 'user_id' => $this->owner->id,
                'number' => 'INV-'.($i + 1),
                'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
                'payment_method' => 'نقدي',
                'subtotal' => 25, 'discount' => 0, 'tax' => 1.25, 'total' => 26.25,
                'ordered_at' => $when, 'created_at' => $when, 'updated_at' => $when,
            ]);

            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة ورد',
                'price' => 12.5, 'quantity' => 2, 'cost' => 5, 'total' => 25,
            ]);

            Transaction::create([
                'business_id' => $this->shop->id,
                'reference' => Transaction::nextReference($this->shop->id),
                'description' => 'بيع', 'method' => 'نقدي', 'type' => 'دخل',
                'amount' => 26.25, 'employee_name' => 'المالك', 'occurred_at' => $when,
            ]);
        }

        Expense::create([
            'business_id' => $this->shop->id, 'description' => 'كهرباء', 'type' => 'تشغيلية',
            'amount' => 15, 'method' => 'نقدي', 'employee_name' => 'المالك',
            'spent_at' => now()->copy()->subDays(3),
        ]);
    }
}
