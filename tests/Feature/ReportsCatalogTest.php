<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_every_card_names_one_destination_not_none_and_not_both(): void
    {
        foreach (Reports::ALL as $report) {
            // القوسان ضروريّان: xor أضعف من = فيلتقط الإسنادُ الطرفَ الأوّل وحده
            $has = (isset($report['route']) xor isset($report['data']));

            $this->assertTrue($has, "التقرير «{$report['key']}» يجب أن يحمل route أو data — لا كليهما ولا واحدًا منهما");
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

    public function test_every_card_without_a_page_really_returns_a_table(): void
    {
        foreach (Reports::ALL as $report) {
            if (! isset($report['data'])) {
                continue;
            }

            $payload = $this->actingAs($this->owner)
                ->getJson(route('admin.reports.data', $report['data']))
                ->assertOk()
                ->json();

            $this->assertArrayHasKey('columns', $payload, "التقرير «{$report['key']}» بلا أعمدة");
            $this->assertArrayHasKey('rows', $payload, "التقرير «{$report['key']}» بلا صفوف");
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
        $user = $this->staff(['reports']);

        foreach (Reports::ALL as $report) {
            if (! isset($report['data'])) {
                continue;
            }

            $this->actingAs($user)
                ->getJson(route('admin.reports.data', $report['data']))
                ->assertForbidden();
        }
    }

    public function test_a_data_key_no_card_points_to_is_not_a_door(): void
    {
        // خمسةُ تقاريرَ كانت تُفتح بكتابة عنوانها ولا بطاقة تقصدها، وفيها
        // «الضريبة» التي تخترع التزامًا ضريبيًّا بضرب المبيعات في نسبةٍ ثابتة
        foreach (['sales', 'profit', 'expenses', 'tax', 'products', 'categories'] as $key) {
            $this->assertNull(Reports::sectionForData($key), "«{$key}» ليس مفتاح بياناتٍ في الفهرس");

            $this->actingAs($this->owner)
                ->getJson(route('admin.reports.data', $key))
                ->assertNotFound();
        }
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

        foreach (\App\Support\Reports::summaryRows($screen['summary']) as $row) {
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
        $labels = \App\Support\Permissions::sectionLabels();

        foreach (\App\Support\Permissions::SECTIONS as $section) {
            $this->assertArrayHasKey($section, \App\Support\Permissions::ROUTES, "القسم «{$section}» بلا مسار");
            $this->assertTrue(\Illuminate\Support\Facades\Route::has(\App\Support\Permissions::ROUTES[$section]), "مسار «{$section}» لا وجود له");
            $this->assertNotSame($section, $labels[$section] ?? $section, "القسم «{$section}» يُعرض بمفتاحه لا باسمه");
        }
    }

    public function test_an_employee_given_only_reports_can_open_the_panel(): void
    {
        $user = $this->staff(['reports']);

        $this->actingAs($user)->get(route('admin.reports.index'))->assertOk();
        $this->assertSame(route('admin.reports.index'), \App\Support\Permissions::panelEntry($user));
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
