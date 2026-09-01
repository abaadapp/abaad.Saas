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
