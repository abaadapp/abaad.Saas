<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * فهرس التقارير: كل بطاقةٍ فيه تقود إلى شيءٍ موجود.
 *
 * الفهرس قائمةٌ مكتوبة يدًا في Support\Reports، ومثلها يتعفّن بصمت: مسارٌ
 * يُعاد تسميته، أو تقريرٌ يُحذف من المتحكّم، فتبقى بطاقته مرسومةً تعد بشيءٍ
 * لا وجود له. والوعد الفارغ في فهرسٍ أسوأ من غيابه — التاجر يبني عليه.
 *
 * فيُفحص البندان معًا: من له صفحة تُفتح صفحته، ومن له مفتاح بيانات يردّ
 * الخادمُ عليه جدولًا لا ٤٠٤.
 */
class ReportsCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
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

    public function test_the_catalog_opens_and_carries_its_cards(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.reports.index'))->viewData('page')['props'];

        $this->assertSame(count(Reports::ALL), count($props['reports']));
        $this->assertSame(array_keys(Reports::CATEGORIES), array_keys($props['categories']));
    }

    public function test_the_catalog_opens_with_the_numbers_not_with_cards_alone(): void
    {
        /*
         * صاحبُ المحلّ يفتح «التقارير» ليعرف كم باع.
         *
         * وكانت الصفحة تفتح على حقل بحثٍ وخمسَ عشرةَ بطاقةً رماديةً بلا رقمٍ
         * واحد، فعليه أن يعرف اسمَ التقرير الذي فيه ذلك ويضغطه. فعادت
         * الأرقام إلى رأسها.
         */
        $props = $this->actingAs($this->owner)
            ->get(route('admin.reports.index'))->viewData('page')['props'];

        foreach (['sales', 'profit', 'expenses', 'tax'] as $number) {
            $this->assertArrayHasKey($number, $props['summary'], "رأس الصفحة بلا «{$number}»");
        }

        $this->assertArrayHasKey('labels', $props['salesSeries']);
        $this->assertSame('month', $props['range']);
    }

    public function test_the_headline_reads_the_period_it_is_asked_for(): void
    {
        // وإلّا قال الرأسُ أرقام الشهر وقالت الشرائح «اليوم» فوقها
        $props = $this->actingAs($this->owner)
            ->get(route('admin.reports.index', ['range' => 'today']))->viewData('page')['props'];

        $this->assertSame('today', $props['range']);
    }

    public function test_the_headline_says_what_the_sales_summary_says(): void
    {
        /*
         * مصدرٌ واحد لا نسخة: رقمان يقولان الشيء نفسه يفترقان يومًا، ويقف
         * التاجر أمام شاشتين من نظامٍ واحد تقولان له مبيعتين.
         */
        $index = $this->actingAs($this->owner)
            ->get(route('admin.reports.index', ['range' => 'week']))->viewData('page')['props'];

        $page = $this->actingAs($this->owner)
            ->get(route('admin.reports.sales', ['range' => 'week']))->viewData('page')['props'];

        $this->assertSame($page['summary']['sales'], $index['summary']['sales']);
        $this->assertSame($page['summary']['profit'], $index['summary']['profit']);
    }

    public function test_every_card_names_a_page_of_its_own(): void
    {
        /*
         * صار لكلّ تقريرٍ صفحته، فلم يبقَ في الفهرس بندٌ بمفتاح بيانات
         * يُعرض في نافذةٍ مشتركة. والقاعدة الآن أضيق: `route` وحده.
         */
        foreach (Reports::ALL as $report) {
            $this->assertArrayHasKey('route', $report, "التقرير «{$report['key']}» بلا صفحة");
            $this->assertArrayNotHasKey('data', $report, "التقرير «{$report['key']}» ما زال يُعرض في نافذة");
            $this->assertContains($report['category'], array_keys(Reports::CATEGORIES), "تصنيف «{$report['key']}» مجهول");
        }
    }

    public function test_every_card_with_a_page_really_opens_it(): void
    {
        foreach (Reports::ALL as $report) {
            if (! isset($report['route'])) {
                continue;
            }

            // يُحلّ الاسم أوّلًا: مسارٌ أُعيدت تسميته يسقط هنا لا في وجه التاجر
            $this->actingAs($this->owner)
                ->get(route($report['route']))
                ->assertOk();
        }
    }

    public function test_a_report_the_employee_cannot_open_is_not_shown_to_him(): void
    {
        /*
         * أهمّ ما في الملفّ. الفهرس لو عرض كل شيء لكل أحد، لصار المحاسب يرى
         * عشرين بطاقة يصطدم بأكثرها بـ٤٠٣ — والقائمة الجانبية تُخفي ما لا
         * يُملك منذ البداية، فيفترق البابان على الشيء نفسه.
         */
        $user = $this->staff(['reports', 'expenses']);

        $shown = collect(Reports::forUser($user))->pluck('key');

        $this->assertContains('sales', $shown);       // reports
        $this->assertContains('expenses', $shown);    // expenses
        $this->assertNotContains('staff', $shown);    // employees — لم يُمنح
        $this->assertNotContains('customers', $shown); // customers — لم يُمنح

        // كان هنا `assertNotContains('vat')` وهي لا تفحص شيئًا: لا بطاقة
        // بهذا المفتاح في الفهرس أصلًا، فالتوكيد يمرّ ولو انفتح كل شيء
    }

    /**
     * أهمّ من الذي قبله: الفهرس يُخفي، وهذا يفحص أنّ الباب يُغلق.
     *
     * حارس المسار يقيس `admin.reports.*` بصلاحية «التقارير» وحدها، وتقارير
     * النافذة قراءاتٌ على أقسامٍ أخرى — إنفاق العملاء ومبيعات كل موظف
     * ومقبوضات الصندوق. فمن مُنح «التقارير» وحدها كان يقرؤها كلّها بكتابة
     * عنوانها، والفهرس لا يعرض له منها بطاقةً واحدة.
     */
    public function test_a_hidden_report_is_also_closed_at_its_door(): void
    {
        /*
         * وصفحاتُها الجديدة تحت `admin.reports.*`، فحارس المسار يقيسها
         * بصلاحية «التقارير» وحدها. ولو اكتُفي به لَقرأ من مُنح التقارير
         * مبيعاتِ كل موظفٍ وإنفاقَ كل عميل بكتابة العنوان.
         */
        $user = $this->staff(['reports']);

        foreach (['admin.reports.payments', 'admin.reports.staff', 'admin.reports.customers'] as $route) {
            $this->actingAs($user)->get(route($route))
                ->assertForbidden("«{$route}» انفتح لمن لا يملك قسمه");
        }

        // وصاحبُ القسم يفتحه: الحارس يمنع الغريب لا الجميع
        $this->actingAs($this->staff(['reports', 'employees']))
            ->get(route('admin.reports.staff'))->assertOk();
    }

    public function test_the_shared_window_is_gone_not_merely_unused(): void
    {
        /*
         * بابٌ متروكٌ يبقى بابًا: مسارُ النافذة كان يردّ الجداول الثلاثة
         * بصلاحيةٍ أخرى وبلا فترة. وتركُه مفتوحًا بعد أن صار لكلٍّ صفحته
         * يعني بابين للشيء الواحد، يُدقّق أحدهما ويُنسى الآخر.
         */
        $this->assertFalse(Route::has('admin.reports.data'), 'مسار النافذة المشتركة ما زال قائمًا');
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/ReportDataController.php'));
        $this->assertFileDoesNotExist(resource_path('js/Pages/Admin/Reports/partials/ReportViewer.tsx'));
    }

    /**
     * الملفّ يحمل ما على الشاشة — وإلا فهو ورقةٌ تناقضها ولا تقول ذلك.
     *
     * كانت مؤشّراته من `Demo::adminStats()`: أرقام اليوم والشهر مهما كانت
     * الفترة المطلوبة، ومحصورةٌ بالفرع الحالي بينما ما تحتها ليس كذلك.
     */
    public function test_the_exported_file_carries_the_numbers_on_the_screen(): void
    {
        $screen = $this->actingAs($this->owner)
            ->get(route('admin.reports.sales', ['range' => 'today']))->viewData('page')['props'];

        $csv = $this->actingAs($this->owner)
            ->get(route('admin.export.reports', ['range' => 'today']));
        $csv->assertOk();

        $body = $csv->streamedContent();

        foreach (Reports::summaryRows($screen['summary']) as $row) {
            $value = $row['money'] ? number_format((float) $row['value'], 3, '.', '') : (string) $row['value'];
            $this->assertStringContainsString($row['label'], $body);
            $this->assertStringContainsString($value, $body, "قيمة «{$row['label']}» ليست في الملفّ");
        }
    }

    public function test_the_accountant_can_export_the_report_he_is_allowed_to_read(): void
    {
        /*
         * `admin.export.reports` كان يشتقّ قسمه فلا يجده، فيسقط إلى
         * «الإعدادات». والزرّ مرسومٌ لكل من يفتح الصفحة — فالمحاسب، وهو
         * أكثر من يُصدّر، يفتح تقريرًا مأذونًا له ويُردّ بـ٤٠٣ عن ملفّه.
         */
        $user = $this->staff(['reports']);

        $this->actingAs($user)->get(route('admin.reports.sales'))->assertOk();
        $this->actingAs($user)->get(route('admin.export.reports'))->assertOk();
        $this->actingAs($user)->get(route('admin.reports.xlsx'))->assertOk();
        $this->actingAs($user)->get(route('admin.reports.pdf'))->assertOk();
    }

    /**
     * القسم يُدرج في SECTIONS فيظهر في قائمة الصلاحيات — ثمّ يُنسى في ROUTES
     * وفي أسماء الأقسام.
     *
     * ووقعت على «التقارير» بعينها: `panelEntry` تختار أوّل قسمٍ يملكه
     * المستخدم ثمّ تقرأ مساره من ROUTES مباشرةً، فمن مُنحت له التقاريرُ
     * وحدها كان يُرفَع عليه خطأ مفتاحٍ ناقص — داخل مشاركة Inertia، أي
     * خمسمئةً على كلّ صفحةٍ يفتحها لا على صفحةٍ واحدة. واسمها كان يُعرض
     * «reports» بحروفٍ لاتينية في قائمة صلاحياتٍ عربية.
     */
    public function test_every_section_has_a_door_and_a_name(): void
    {
        $labels = Permissions::sectionLabels();

        foreach (Permissions::SECTIONS as $section) {
            $this->assertArrayHasKey($section, Permissions::ROUTES, "القسم «{$section}» بلا مسار");
            $this->assertTrue(Route::has(Permissions::ROUTES[$section]), "مسار «{$section}» لا وجود له");
            $this->assertNotSame($section, $labels[$section] ?? $section, "القسم «{$section}» يُعرض بمفتاحه لا باسمه");
        }
    }

    public function test_an_employee_given_only_reports_can_open_the_panel(): void
    {
        $user = $this->staff(['reports']);

        $this->actingAs($user)->get(route('admin.reports.index'))->assertOk();
        $this->assertSame(route('admin.reports.index'), Permissions::panelEntry($user));
    }

    public function test_the_sales_summary_kept_its_page_after_the_move(): void
    {
        // محتوى /reports القديم انتقل إلى مسارٍ خاص به، ولم يضع في النقل
        $props = $this->actingAs($this->owner)
            ->get(route('admin.reports.sales'))->viewData('page')['props'];

        $this->assertArrayHasKey('summary', $props);
        $this->assertArrayHasKey('salesSeries', $props);
        $this->assertArrayHasKey('topSellingProducts', $props);
    }
}
