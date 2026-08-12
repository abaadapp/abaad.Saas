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
        $this->assertNotContains('vat', $shown);      // vat — لم يُمنح
        $this->assertNotContains('staff', $shown);    // employees — لم يُمنح
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
