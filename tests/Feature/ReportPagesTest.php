<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $this->assertArrayHasKey('tabs', $props, "«{$key}» بلا تنقّل");
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

    public function test_printing_survived_the_move_out_of_the_window(): void
    {
        /*
         * النافذة المحذوفة كانت تحمل زرّ «طباعة»، والصفحات وُلدت بلا واحد:
         * قدرةٌ كانت بيد التاجر تذهب في إعادة تنظيمٍ لم تُعلن أنها تأخذها.
         *
         * والزرّ وحده لا يكفي — قاعدةُ الطباعة العامة تُخفي الصفحة كلّها إلا
         * الإيصال الحراري، فبلا `printable-report` تخرج ورقةٌ بيضاء: زرٌّ
         * يقول شيئًا ولا يفعله. ويُطوى عن الورق ما ليس منها.
         */
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.printable-report', $css, 'الكشف للطابعة ذهب مع النافذة');

        foreach (['Payments', 'Staff', 'Customers'] as $screen) {
            $source = file_get_contents(resource_path("js/Pages/Admin/Reports/{$screen}.tsx"));

            $this->assertStringContainsString('PrintReport', $source, "«{$screen}» بلا زرّ طباعة");
            $this->assertStringContainsString('printable-report', $source, "«{$screen}» يُطبع ورقةً بيضاء");
            $this->assertStringContainsString('no-print', $source, "«{$screen}» يطبع أدواته مع بياناته");
        }
    }

    /* ====================== التنقّل بينها ====================== */

    public function test_the_navigation_carries_every_report_the_owner_can_open(): void
    {
        $tabs = collect($this->props('admin.reports.payments')['tabs'])->pluck('key')->all();

        foreach (['sales', 'payments', 'staff', 'customers', 'waste'] as $key) {
            $this->assertContains($key, $tabs, "«{$key}» غائبٌ عن شريط التنقّل");
        }
    }

    public function test_the_navigation_never_shows_a_door_that_will_not_open(): void
    {
        /*
         * بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض: من ضغطه اصطدم بـ٤٠٣
         * وظنّ العطب في النظام.
         */
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'a@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
            'permissions' => ['reports', 'finance'],
        ]);

        $tabs = collect(Reports::tabsFor($accountant))->pluck('key')->all();

        $this->assertContains('payments', $tabs);       // finance — مُنح
        $this->assertNotContains('staff', $tabs);       // employees — لم يُمنح
        $this->assertNotContains('customers', $tabs);   // customers — لم يُمنح

        // وما غاب عن الشريط مغلقٌ عند بابه أيضًا
        $this->actingAs($accountant)->get(route('admin.reports.staff'))->assertForbidden();
    }

    /* ============ ما انتقل من شاشة قسمه إلى صفحته ============ */

    public function test_every_catalog_entry_now_has_a_report_page_of_its_own(): void
    {
        /*
         * كانت عشرُ بطاقاتٍ تقود إلى **شاشات الأقسام**: «تقرير الطلبات» يفتح
         * شاشة إدارة الطلبات وفيها التعديل والحذف — فمن دخل ليقرأ وجد نفسه
         * في موضع الكتابة، ولا فترةَ تُختار ولا مؤشّراتٍ فوق الجدول.
         */
        foreach (Reports::ALL as $report) {
            $this->assertStringStartsWith(
                'admin.reports.',
                $report['route'],
                "«{$report['key']}» ما زال يقود إلى شاشة قسمٍ لا إلى تقرير"
            );
        }
    }

    public function test_each_moved_report_carries_its_own_indicators_and_rows(): void
    {
        foreach (array_keys(self::MOVED) as $route) {
            $props = $this->props($route);

            $this->assertArrayHasKey('summary', $props, "«{$route}» بلا مؤشّرات");
            $this->assertArrayHasKey('rows', $props, "«{$route}» بلا صفوف");
            $this->assertArrayHasKey('filters', $props, "«{$route}» لا يعيد مرشّحاته");
            $this->assertArrayHasKey('tabs', $props, "«{$route}» بلا تنقّل");
            $this->assertNotEmpty($props['summary'], "«{$route}» مؤشّراتُه فارغة");
        }
    }

    public function test_a_moved_report_is_measured_by_its_own_section_not_by_reports(): void
    {
        /*
         * أخطرُ ما في النقل: صفحاتُها صارت تحت `admin.reports.*`، فحارس
         * المسار يقيسها بصلاحية «التقارير» وحدها. ولولا حارسٌ ثانٍ لَقرأ من
         * مُنح التقارير سجلَّ النشاط وأوامر الشراء وحركة المال كلَّها.
         */
        $user = $this->staff(['reports']);

        foreach (self::MOVED as $route => $section) {
            $this->actingAs($user)->get(route($route))
                ->assertForbidden("«{$route}» انفتح لمن لا يملك «{$section}»");
        }
    }

    public function test_the_section_owner_still_opens_his_report(): void
    {
        // الحارس يمنع الغريب لا الجميع
        foreach (self::MOVED as $route => $section) {
            $this->actingAs($this->staff(['reports', $section]))
                ->get(route($route))->assertOk("«{$route}» أُغلق في وجه صاحب «{$section}»");
        }
    }

    public function test_a_filter_actually_filters(): void
    {
        $this->sale(100, now()->toDateTimeString());
        $cancelled = $this->sale(400, now()->toDateTimeString());
        $cancelled->update(['status' => Order::CANCELLED]);

        $all = $this->props('admin.reports.orders', ['range' => 'month']);
        $this->assertCount(2, $all['rows']);
        $this->assertSame(1, $all['summary']['cancelled']);
        // المتوسّط على المُباع لا على الكلّ: الملغى يُنقصه بلا أن يُنقص الإيراد
        $this->assertSame(100.0, $all['summary']['average']);

        $only = $this->props('admin.reports.orders', ['range' => 'month', 'status' => Order::CANCELLED]);
        $this->assertCount(1, $only['rows'], 'المرشّح لم يرشّح شيئًا');
        $this->assertSame(Order::CANCELLED, $only['filters']['status'], 'المرشّح لا يعود إلى الشاشة');
    }

    public function test_changing_the_period_does_not_drop_the_other_filters(): void
    {
        /*
         * مبدّلُ الفترة يكتب سلسلة الاستعلام كاملةً، فبلا حمل المرشّحات معه
         * يعود الجدول إلى الكلّ بلا أن يلمس التاجر المنتقي — ويقرأ أرقامًا
         * ليست التي رشّح عليها.
         */
        $source = file_get_contents(resource_path('js/Components/ReportScreen.tsx'));
        $this->assertStringContainsString('params={filters}', $source, 'الفترة لا تحمل المرشّحات معها');

        $range = file_get_contents(resource_path('js/Components/RangeTabs.tsx'));
        $this->assertStringContainsString('...(params ?? {})', $range, 'مبدّل الفترة يمحو ما سواه');
    }

    public function test_a_truncated_table_says_so(): void
    {
        // جدولٌ مبتورٌ بلا ما يقول ذلك يُقرأ على أنه كلّ ما في المتجر
        $this->assertNull($this->props('admin.reports.orders')['truncated'], 'أُعلن البتر بلا بتر');

        $source = file_get_contents(resource_path('js/Components/ReportScreen.tsx'));
        $this->assertStringContainsString('truncated', $source);
    }

    public function test_the_stock_report_has_no_period_switch_because_it_is_a_snapshot(): void
    {
        /*
         * الرصيد رقمُ اليوم لا مجموعُ مدّة. ومبدّلٌ لا يغيّر شيئًا أسوأ من
         * غيابه: يظنّه التاجر عاملًا فيبني على فرقٍ لا وجود له.
         */
        $source = file_get_contents(resource_path('js/Pages/Admin/Reports/Inventory.tsx'));

        $this->assertStringContainsString('range={null}', $source, 'شاشة الرصيد تعرض مبدّل فترةٍ لا أثر له');
    }

    public function test_the_section_screens_they_came_from_still_work(): void
    {
        // تغيّرت وجهةُ البطاقة لا الشاشة: القائمة الجانبية تقصدها كما كانت
        foreach (['admin.orders.index', 'admin.products.index', 'admin.inventory.index',
            'admin.expenses.index', 'admin.suppliers.index', 'admin.activity.index'] as $route) {
            $this->get(route($route))->assertOk("شاشة «{$route}» تأثّرت بنقل بطاقتها");
        }
    }

    public function test_the_navigation_holds_only_reports_that_are_reports(): void
    {
        /*
         * بقيّةُ بنود الفهرس شاشاتُ أقسامٍ أخرى — الطلبات والمنتجات
         * والمخزون — ولكلٍّ تبويباتُ قسمها. ووضعُ شريط التقارير فوق شاشة
         * المنتجات يقول إنها تقرير، وهي قسمٌ قائم.
         */
        foreach (Reports::tabsFor($this->owner) as $tab) {
            $this->assertStringContainsString('/reports/', $tab['href'], "«{$tab['key']}» ليس تقريرًا في قسم التقارير");
        }
    }
}
