<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * لكلّ تقريرٍ صفحته — بفلاترها ومؤشّراتها وبياناتها.
 *
 * كانت ثلاثةُ تقارير تُعرض في نافذةٍ واحدة بقالبٍ واحد: أعمدةٌ وصفوفٌ لا
 * غير. فلا مبدّلَ فترةٍ فوقها — كانت محسوبةً على الشهر الجاري وحده ولا شيء
 * على الشاشة يقول ذلك، و«أكثرُ العملاء إنفاقًا» في شهرٍ غيرُ «أكثرهم إنفاقًا»
 * منذ فُتح المحل — ولا مؤشّراتٍ تُقرأ بنظرة، ولا رابطٌ يُحفَظ أو يُرسَل.
 *
 * وهذه الاختبارات تحرس الثلاثة: الفترة تُغيّر الأرقام فعلًا، والمؤشّرات
 * تُحتسب من الصفوف نفسها، والتنقّل بينها لا يُظهر بابًا لا يُفتح.
 */
class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'admin.reports.payments' => 'payments',
        'admin.reports.staff' => 'staff',
        'admin.reports.customers' => 'customers',
    ];

    /** التقارير التي انتقلت من شاشات أقسامها إلى صفحاتٍ خاصّة بها */
    private const MOVED = [
        'admin.reports.finance' => 'finance',
        'admin.reports.expenses' => 'expenses',
        'admin.reports.bank' => 'finance',
        'admin.reports.orders' => 'orders',
        'admin.reports.products' => 'products',
        'admin.reports.inventory' => 'inventory',
        'admin.reports.purchases' => 'purchases',
        'admin.reports.suppliers' => 'suppliers',
        'admin.reports.activity' => 'settings',
        'admin.reports.marketing' => 'marketing',
    ];

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** @param  string[]  $permissions */
    private function staff(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'موظف',
            'email' => 'staff'.uniqid().'@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function props(string $route, array $query = []): array
    {
        return $this->get(route($route, $query))->viewData('page')['props'];
    }

    /** بيعةٌ في وقتٍ محدّد — تقع داخل فترةٍ وخارج أخرى */
    private function sale(float $total, string $when, ?User $by = null): Order
    {
        return Order::create([
            'business_id' => $this->business->id,
            'number' => 'INV-'.uniqid(),
            'customer_name' => 'زبون',
            'total' => $total,
            'status' => 'مكتمل',
            // صراحةً: `sold()` تشترط `is_held = false`، والافتراض null لا false
            'is_held' => false,
            'ordered_at' => $when,
            'user_id' => $by?->id,
        ]);
    }

    /* ===================== كلٌّ يفتح صفحته ===================== */

    public function test_each_report_opens_its_own_page(): void
    {
        foreach (self::PAGES as $route => $key) {
            $props = $this->props($route);

            $this->assertArrayHasKey('summary', $props, "«{$key}» بلا مؤشّرات");
            $this->assertArrayHasKey('range', $props, "«{$key}» بلا فترة");
        }
    }

    public function test_the_index_no_longer_hides_a_report_behind_a_window(): void
    {
        // كل بطاقةٍ في الفهرس صارت رابطًا: لا بطاقة بلا وجهة
        foreach ($this->props('admin.reports.index')['reports'] as $card) {
            $this->assertNotNull($card['href'] ?? null, "بطاقة «{$card['key']}» بلا رابط");
            $this->assertArrayNotHasKey('data', $card, "بطاقة «{$card['key']}» ما زالت تُفتح في نافذة");
        }
    }

    /* ======================= الفترة تعمل ======================= */

    public function test_the_period_actually_changes_the_numbers(): void
    {
        /*
         * أصلُ العطب: كانت الثلاثة محسوبةً على `month` ثابتةً في الشيفرة.
         * فلا يكفي أن يظهر المبدّل — يجب أن تتبعه الأرقام.
         */
        // داخل الشهر يقينًا وخارجه يقينًا — لا `subDays` التي تعبر الشهر في أوّله
        $this->sale(100, now()->toDateTimeString());
        $this->sale(500, now()->subMonths(6)->toDateTimeString());

        $month = $this->props('admin.reports.customers', ['range' => 'month'])['summary'];
        $all = $this->props('admin.reports.customers', ['range' => 'all'])['summary'];

        $this->assertSame(100.0, $month['total'], 'الشهر ابتلع بيعةً خارجه');
        $this->assertSame(600.0, $all['total'], 'الكل لم يشمل ما قبل الشهر');
    }

    public function test_the_period_travels_in_the_link_so_it_can_be_shared(): void
    {
        // رابطٌ يُرسَل أو يُحفَظ يفتح على ما فُتح عليه
        foreach (array_keys(self::PAGES) as $route) {
            $this->assertSame('year', $this->props($route, ['range' => 'year'])['range']);
        }
    }

    public function test_an_unknown_period_falls_back_to_the_month_not_to_all_time(): void
    {
        // فترةٌ مجهولة كانت تسقط إلى null فتُقرأ «كل الفترات» بلا أن يقول شيءٌ ذلك
        foreach (array_keys(self::PAGES) as $route) {
            $this->assertSame('month', $this->props($route, ['range' => 'قرن'])['range']);
        }
    }

    /* ===================== المؤشّرات صادقة ===================== */

    public function test_the_staff_indicators_are_counted_from_the_rows(): void
    {
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->sale(300, now()->toDateTimeString(), $seller);

        $props = $this->props('admin.reports.staff', ['range' => 'month']);
        $summary = $props['summary'];

        $this->assertSame(300.0, $summary['total']);
        $this->assertSame(2, $summary['staff'], 'عدد الموظفين يشمل من لم يبع');
        $this->assertSame(1, $summary['sellers'], 'عدد البائعين يشمل من لم يبع');

        /*
         * والمتوسّط على من باع لا على الكشف كلّه: قسمةُ المبيعات على الجميع
         * تُظهر البائعين أضعف ممّا هم كلّما كبر عدد غير البائعين.
         */
        $this->assertSame(300.0, $summary['average']);
        $this->assertSame('بائع', $summary['topName']);
    }

    public function test_the_staff_rows_are_ordered_by_sales_not_by_id(): void
    {
        // تقريرُ أداءٍ أوّلُ سطرٍ فيه أقدمُ موظفٍ لا أعلاهم بيعًا لا يُقرأ بنظرة
        $second = User::create([
            'business_id' => $this->business->id, 'name' => 'الثاني', 'email' => 'b@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->sale(900, now()->toDateTimeString(), $second);

        $rows = $this->props('admin.reports.staff')['rows'];

        $this->assertSame('الثاني', $rows[0]['name'], 'الصفوف مرتّبةٌ بالمعرّف لا بالمبيعات');
    }

    public function test_the_payment_indicators_count_only_what_actually_moved(): void
    {
        Transaction::create([
            'business_id' => $this->business->id, 'type' => 'دخل', 'method' => 'نقدي',
            'reference' => Transaction::nextReference($this->business->id),
            'amount' => 250, 'occurred_at' => now(), 'description' => 'بيع',
        ]);

        $summary = $this->props('admin.reports.payments', ['range' => 'month'])['summary'];

        $this->assertSame(250.0, $summary['total']);
        $this->assertSame(1, $summary['count']);
        // «النشطة» ما تحرّك منها: عددُ الصفوف ثابتٌ مهما كان في الدرج
        $this->assertSame(1, $summary['active'], 'عُدّت وسيلةٌ لم تتحرّك نشطةً');
        $this->assertSame('نقدي', $summary['topName']);
    }

    public function test_an_empty_period_says_so_instead_of_dividing_by_zero(): void
    {
        foreach (array_keys(self::PAGES) as $route) {
            $summary = $this->props($route, ['range' => 'today'])['summary'];

            $this->assertSame(0.0, (float) $summary['total']);
            $this->assertSame(0.0, (float) ($summary['average'] ?? 0));
        }
    }

    /* ====================== ما لا يُفقد في النقل ====================== */

    public function test_every_report_offers_the_three_approved_exports(): void
    {
        /*
         * وحلّت محلّ زرّ الطباعة بطلب صاحب المشروع.
         *
         * والورقةُ من المتصفّح تخرج بهوامشه وترويسته ورابط الصفحة في قاعها،
         * ولا تحمل اسم المتجر ولا فترة التقرير. والملفّ يخرج بترويسة المتجر
         * وفترته، ويُرسَل، ويُجمع عمودُه في إكسل. ومن أراد ورقةً طبع الـPDF.
         */
        $dir = resource_path('js/Pages/Admin/Reports');

        foreach (glob($dir.'/*.tsx') as $file) {
            $screen = basename($file, '.tsx');
            if ($screen === 'Index') {
                continue;
            }

            $source = file_get_contents($file);
            $viaShell = str_contains($source, 'ReportScreen');

            $this->assertTrue(
                $viaShell || str_contains($source, 'ExportMenu'),
                "«{$screen}» بلا تصدير"
            );
            $this->assertStringNotContainsString('PrintReport', $source, "«{$screen}» ما زال يعرض الطباعة");
        }

        // والهيكل المشترك يحملها، فما بُني عليه يرثها بصيغها الثلاث
        $shell = file_get_contents(resource_path('js/Components/ReportScreen.tsx'));
        foreach (['export.xlsx', 'export.pdf', 'export.csv'] as $format) {
            $this->assertStringContainsString($format, $shell, "الهيكل بلا {$format}");
        }

        $this->assertFileDoesNotExist(resource_path('js/Components/PrintReport.tsx'));
    }

    public function test_the_filters_use_the_systems_picker_not_the_native_one(): void
    {
        /*
         * القائمة الأصلية يرسمها نظام التشغيل: نافذةٌ داكنةٌ ضيّقة تطفو فوق
         * الحقل فتحجبه، ولا تحترم عرضه ولا خطّه ولا اتجاه الواجهة — والمشروع
         * استبدلها بمنتقٍ مرسومٍ داخل الصفحة (انظر Components/ui/select).
         */
        $shell = file_get_contents(resource_path('js/Components/ReportScreen.tsx'));

        $this->assertStringNotContainsString('<select', $shell, 'عاد المنتقي الذي يرسمه نظام التشغيل');
        $this->assertStringContainsString('<Select', $shell, 'المرشّحات لا تستعمل منتقي النظام');
    }

    /* ================== التصديرات الثلاث ================== */

    public function test_the_three_formats_download_for_every_report(): void
    {
        foreach (array_keys(ReportColumns::MAP) as $report) {
            foreach (['xlsx', 'csv', 'pdf'] as $format) {
                $this->get(route("admin.reports.export.{$format}", $report))
                    ->assertOk("«{$report}» لا يُصدَّر بصيغة {$format}");
            }
        }
    }

    public function test_the_file_carries_what_the_screen_shows_not_another_period(): void
    {
        /*
         * الملفّ يغادر الشاشة ولا مبدّلَ فوقه يصحّحه: خرج بفترته الخاصّة
         * قُرئ على أنه الفترة التي كان ينظر إليها صاحبه.
         */
        $this->sale(100, now()->toDateTimeString());
        $this->sale(500, now()->subMonths(6)->toDateTimeString());

        $month = $this->get(route('admin.reports.export.csv', 'orders').'?range=month')->streamedContent();
        $all = $this->get(route('admin.reports.export.csv', 'orders').'?range=all')->streamedContent();

        $this->assertStringContainsString('100', $month);
        $this->assertStringNotContainsString('500', $month, 'ملفُّ الشهر حمل بيعةً خارجه');
        $this->assertStringContainsString('500', $all);
    }

    public function test_the_file_obeys_the_filters_the_screen_obeys(): void
    {
        $this->sale(100, now()->toDateTimeString());
        $cancelled = $this->sale(400, now()->toDateTimeString());
        $cancelled->update(['status' => Order::CANCELLED]);

        $csv = $this->get(route('admin.reports.export.csv', 'orders')
            .'?range=month&status='.urlencode(Order::CANCELLED))->streamedContent();

        $this->assertStringContainsString('400', $csv);
        $this->assertStringNotContainsString('100', $csv, 'الملفّ تجاهل المرشّح الذي رشّحت به الشاشة');
    }

    public function test_the_columns_a_file_writes_really_exist_in_the_rows(): void
    {
        /*
         * الأعمدة معلنةٌ في `ReportColumns` والصفوف تُبنى في `ReportData`،
         * وهما ملفّان. فمفتاحٌ يُعاد تسميته في أحدهما يُخرج عمودًا كاملًا
         * من الشرطات — ولا شيء يشتكي.
         */
        $this->sale(100, now()->toDateTimeString());

        foreach (array_keys(ReportColumns::MAP) as $report) {
            $data = ReportData::$report($this->business->id, ['range' => 'all']);

            foreach ($data['rows'] as $row) {
                foreach (ReportColumns::for($report) as $column) {
                    $this->assertArrayHasKey(
                        $column['key'],
                        $row,
                        "عمود «{$column['label']}» في «{$report}» لا يقابله مفتاحٌ في الصفوف"
                    );
                }
            }
        }
    }

    public function test_an_export_is_measured_by_its_own_section_too(): void
    {
        /*
         * والتنزيل يُخرج البيانات من النظام إلى ملفٍّ يُرسَل — فحراسته أولى
         * لا أهون. ومساره تحت `admin.reports.*` فيقيسه حارس المسار بصلاحية
         * الفهرس وحدها.
         */
        $user = $this->staff(['reports']);

        foreach (['staff', 'customers', 'finance'] as $report) {
            $this->actingAs($user)->get(route('admin.reports.export.csv', $report))
                ->assertForbidden("«{$report}» نُزّل لمن لا يملك قسمه");
        }
    }

    public function test_an_unknown_report_is_not_a_download(): void
    {
        $this->get(route('admin.reports.export.csv', 'sales'))->assertNotFound();
        $this->get(route('admin.reports.export.xlsx', 'لا-شيء'))->assertNotFound();
    }

    /* ============ الهالك: ستُّ قراءاتٍ لا جدولٌ واحد ============ */

    public function test_the_waste_report_exports_all_six_readings(): void
    {
        /*
         * وتصديرُ قراءةٍ واحدةٍ وترْكُ خمسٍ يُخرج ملفًّا يقول أقلَّ ممّا على
         * الشاشة، ومن يقارنه بها يظنّ أنّ شيئًا سقط في الطريق.
         */
        $sections = ReportColumns::sectionsOf('waste');
        $this->assertCount(6, $sections, 'قراءةٌ من الستّ غائبةٌ عن التصدير');

        $csv = $this->get(route('admin.reports.export.csv', 'waste'))->streamedContent();

        foreach ($sections as $section) {
            $this->assertStringContainsString($section, $csv, "«{$section}» ليست في الملفّ");
        }
    }

    public function test_each_waste_reading_becomes_its_own_sheet(): void
    {
        // إكسل يحتمل الألسنة، فستُّ قراءاتٍ ستُّ أوراقٍ لا ستّةُ جداولَ ملصوقة
        $path = tempnam(sys_get_temp_dir(), 'waste').'.xlsx';
        file_put_contents($path, $this->get(route('admin.reports.export.xlsx', 'waste'))->streamedContent());

        $book = IOFactory::load($path);
        @unlink($path);

        $this->assertSame(6, $book->getSheetCount(), 'الأوراق ليست بعدد القراءات');
        // والملفّ يُفتح على أوّل قراءةٍ لا على آخر ما كُتب
        $this->assertSame(0, $book->getActiveSheetIndex());
    }

    public function test_the_waste_file_obeys_the_dates_on_the_screen(): void
    {
        /*
         * والهالك يُرشَّح بمدّةٍ بحدّين لا بفترةٍ مسمّاة — شاشتُه تقارن المدّة
         * بسابقتها. وإقحامُ `range` عليه يُخرج ملفًّا بمدّةٍ غير المعروضة.
         */
        $from = now()->subMonth()->toDateString();
        $to = now()->toDateString();

        $response = $this->get(route('admin.reports.export.csv', 'waste')."?from={$from}&to={$to}");
        $csv = $response->streamedContent();

        $this->assertStringContainsString($from, $csv, 'الملفّ لا يقول مدّته');
        $this->assertStringContainsString($to, $csv);

        /*
         * واسمُ الملفّ يحمل مدّته لا فترةً مسمّاة: ملفّاتٌ عدّة في مجلّد
         * التنزيلات كلُّها «waste-month» لا يُعرف أيُّها لأيّ مدّة.
         */
        $this->assertStringContainsString(
            $from,
            $response->headers->get('content-disposition') ?? '',
            'اسمُ الملفّ يقول فترةً مسمّاة لا مدّته'
        );
    }

    public function test_the_waste_export_is_measured_by_the_reports_section(): void
    {
        $this->actingAs($this->staff(['orders']))
            ->get(route('admin.reports.export.csv', 'waste'))->assertForbidden();

        $this->actingAs($this->staff(['reports']))
            ->get(route('admin.reports.export.csv', 'waste'))->assertOk();
    }

    public function test_the_waste_screen_offers_the_same_three_formats(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Reports/Waste.tsx'));

        $this->assertStringContainsString('ExportMenu', $source, 'شاشة الهالك بلا تصدير');
        foreach (['export.xlsx', 'export.pdf', 'export.csv'] as $format) {
            $this->assertStringContainsString($format, $source, "شاشة الهالك بلا {$format}");
        }
    }

    /* ====================== الرجوع إلى الفهرس ====================== */

    public function test_every_report_has_one_way_back_to_the_index(): void
    {
        /*
         * وحلّ الرجوعُ محلّ شريطٍ كان يعرض التقارير الستّة عشر كلَّها فوق كل
         * صفحة: صفٌّ يفيض عن الشاشة ويُمرَّر أفقيًّا، ويأخذ من ارتفاع الطيّة
         * أكثر ممّا يعطي — والقارئ جاء ليقرأ تقريرًا لا ليختار غيره.
         *
         * والشكل واحد في الجميع: صفحةٌ ترجع بزرٍّ وأخرى برابطٍ تجعل القارئ
         * يبحث عن المخرج في كل شاشة.
         */
        $dir = resource_path('js/Pages/Admin/Reports');

        foreach (glob($dir.'/*.tsx') as $file) {
            $screen = basename($file, '.tsx');
            if ($screen === 'Index') {
                continue;
            }

            $source = file_get_contents($file);
            $viaShell = str_contains($source, 'ReportScreen');

            $this->assertTrue(
                $viaShell || str_contains($source, 'BackToReports'),
                "«{$screen}» بلا طريقٍ للرجوع إلى الفهرس"
            );
        }

        // والهيكل المشترك يحمله، فما بُني عليه يرثه
        $this->assertStringContainsString(
            'BackToReports',
            file_get_contents(resource_path('js/Components/ReportScreen.tsx')),
        );
    }

    public function test_the_crowded_tab_strip_is_gone_not_merely_hidden(): void
    {
        // مكوّنٌ متروكٌ يعود يومًا: يُحذف هو ومصدرُ بياناته في الخادم معًا
        $this->assertFileDoesNotExist(resource_path('js/Components/ReportTabs.tsx'));
        $this->assertFalse(method_exists(Reports::class, 'tabsFor'), 'مصدرُ الشريط ما زال في الخادم');

        foreach (glob(resource_path('js/Pages/Admin/Reports').'/*.tsx') as $file) {
            $this->assertStringNotContainsString('ReportTabs', file_get_contents($file), basename($file));
        }
    }

    public function test_the_index_hides_what_its_owner_cannot_open(): void
    {
        /*
         * بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض: من ضغطه اصطدم بـ٤٠٣
         * وظنّ العطب في النظام.
         */
        $accountant = $this->staff(['reports', 'finance']);

        $shown = collect(Reports::forUser($accountant))->pluck('key')->all();

        $this->assertContains('payments', $shown);        // finance — مُنح
        $this->assertNotContains('staff', $shown);        // employees — لم يُمنح
        $this->assertNotContains('stocktake', $shown);    // inventory — لم يُمنح

        // وما غاب عن الفهرس مغلقٌ عند بابه أيضًا
        $this->actingAs($accountant)->get(route('admin.reports.stocktake'))->assertForbidden();
    }

    /* ==================== عمليات جرد المخزون ==================== */

    /** يطبّق جردًا على صنفٍ ويردّ فرقه */
    private function stocktake(int $counted, int $book = 10): Product
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة'.uniqid(),
            'price' => 5, 'cost' => 2, 'quantity' => $book, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->business->branches()->first()->id,
            'counts' => [$product->id => $counted],
        ])->assertSessionHasNoErrors();

        return $product;
    }

    public function test_the_stocktake_report_reads_what_the_stocktake_wrote(): void
    {
        // نقصٌ كشفه العدّ: عشرةٌ في الدفتر وسبعةٌ في الرفّ
        $this->stocktake(counted: 7, book: 10);

        $props = $this->props('admin.reports.stocktake', ['range' => 'month']);

        $this->assertCount(1, $props['rows'], 'لم يُقرأ ما كتبه الجرد');
        $this->assertSame(-3, $props['rows'][0]['delta']);
        $this->assertSame(StockAdjustment::STOCKTAKE_LOSS, $props['rows'][0]['reason']);

        // ثلاثُ ورداتٍ بتكلفة اثنين = ستّة
        $this->assertSame(6.0, $props['summary']['shortage']);
        $this->assertSame(0.0, $props['summary']['surplus']);
        $this->assertSame(-6.0, $props['summary']['net']);
        $this->assertSame(1, $props['summary']['operations']);
        $this->assertSame(1, $props['summary']['items']);
    }

    public function test_the_stocktake_report_separates_a_shortage_from_a_surplus(): void
    {
        /*
         * جردٌ نقصُه يوازي زيادتَه دفترٌ مضطرب لا خسارة — والتاجر يحتاج أن
         * يفرّق بين الاثنين قبل أن يتّهم أحدًا. فلو جُمعا في رقمٍ واحد ضاع الفرق.
         */
        $this->stocktake(counted: 7, book: 10);   // نقص ٣ × ٢ = ٦
        $this->stocktake(counted: 14, book: 10);  // زيادة ٤ × ٢ = ٨

        $summary = $this->props('admin.reports.stocktake', ['range' => 'month'])['summary'];

        $this->assertSame(6.0, $summary['shortage']);
        $this->assertSame(8.0, $summary['surplus']);
        $this->assertSame(2.0, $summary['net']);
        $this->assertSame(2, $summary['items']);
    }

    public function test_the_stocktake_report_ignores_damage_recorded_by_hand(): void
    {
        /*
         * التلفُ والفقدُ والإهداء تعديلاتٌ يكتبها التاجر بيده، ولها تقريرها
         * (تحليلات الهالك). وخلطُها بالجرد يجعل «فرق الجرد» يشمل ما لم يكشفه
         * عدٌّ أصلًا — فيُتَّهم الدفتر بما ليس فيه.
         */
        $this->stocktake(counted: 7, book: 10);

        StockAdjustment::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->business->branches()->first()->id,
            'product_id' => Product::first()->id,
            'number' => 'SA-999999', 'quantity_delta' => -5, 'cost_at_time' => 2,
            'reason' => 'تلف', 'adjusted_at' => now(),
        ]);

        $props = $this->props('admin.reports.stocktake', ['range' => 'month']);

        $this->assertCount(1, $props['rows'], 'التلف اليدويّ دخل تقرير الجرد');
        $this->assertSame(6.0, $props['summary']['shortage']);
    }

    public function test_the_stocktake_report_is_owned_by_inventory_not_by_reports(): void
    {
        $this->actingAs($this->staff(['reports']))
            ->get(route('admin.reports.stocktake'))->assertForbidden();

        $this->actingAs($this->staff(['reports', 'inventory']))
            ->get(route('admin.reports.stocktake'))->assertOk();
    }
}
