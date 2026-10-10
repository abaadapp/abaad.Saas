<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «عرض التفاصيل» في «ما جرى في المدة» — ما يقوله الخادمُ عن الزرّ ووجهتِه.
 *
 * الزرُّ نفسُه ورابطُه حارسُهما `the-finance-summary-opens-its-period-in-the-profit-report.test.tsx`.
 * وهنا ما تبني عليه الواجهة:
 *
 *   - الملخّصُ يردّ الفترةَ التي طُلبت (`range`)، والتقريرُ يقبلها كما هي —
 *     بالقيم الخمس نفسِها (`Demo::RANGES`).
 *   - والتقريرُ بلا `branch_id` للنشاط كلِّه — كالملخّص، فلا فرعَ يُمرَّر.
 *   - ومن يملك «المالية» دون «التقارير» يفتح الملخّصَ ولا تصله «reports»
 *     في `auth.abilities` — فلا يُرسم له الزرّ — والتقريرُ يردّه بـ403.
 */
class TheFinanceSummaryOpensItsPeriodInTheProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'صحار']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
    }

    /** @param  list<string>|null  $permissions */
    private function user(string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'مستخدم '.$role, 'email' => $role.uniqid().'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(string $range): array
    {
        return $this->get(route('admin.finance.summary', ['range' => $range]))->assertOk()->viewData('page')['props'];
    }

    public function test_the_owner_carries_every_period_to_the_whole_business_profit_report(): void
    {
        $this->actingAs($this->user('admin'));

        foreach (['today', 'week', 'month', 'year', 'all'] as $range) {
            $summary = $this->summary($range);

            $this->assertSame($range, $summary['range'], 'الملخّص لم يردّ الفترة التي طُلبت');
            $this->assertContains('reports', $summary['auth']['abilities'], 'المالك لا يرى الزرّ');

            // والرابطُ كما تبنيه الواجهة: `route('admin.reports.profit', { range })`
            $report = $this->get(route('admin.reports.profit', ['range' => $summary['range']]))
                ->assertOk()->viewData('page')['props'];

            $this->assertSame($range, $report['range'], "التقرير فتح فترةً غير {$range}");
            $this->assertSame(['range' => $range, 'branch_id' => null], $report['filters']);
            // للنشاط كلِّه كالملخّص — لا فرع
            $this->assertSame('business', $report['scope']['kind']);
            $this->assertNull($report['scope']['branch_id']);
        }
    }

    /** و«day» ليست فترةً هنا: الملخّصُ يردّها إلى الشهر، فيحمل الرابطُ الشهر */
    public function test_an_unknown_period_falls_back_to_the_month_before_it_reaches_the_link(): void
    {
        $this->actingAs($this->user('admin'));

        $this->assertSame('month', $this->summary('day')['range']);
    }

    public function test_finance_without_reports_opens_the_summary_but_is_never_shown_a_door_to_403(): void
    {
        $this->actingAs($this->user('staff', ['finance']));

        $summary = $this->summary('year');

        $this->assertContains('finance', $summary['auth']['abilities']);
        $this->assertNotContains('reports', $summary['auth']['abilities']);

        // وهذا ما كان الزرُّ سيقود إليه
        $this->get(route('admin.reports.profit', ['range' => 'year']))->assertForbidden();
    }

    public function test_finance_with_reports_sees_the_door_and_it_opens(): void
    {
        $this->actingAs($this->user('staff', ['finance', 'reports']));

        $summary = $this->summary('year');

        $this->assertContains('reports', $summary['auth']['abilities']);
        $this->get(route('admin.reports.profit', ['range' => 'year']))->assertOk();
    }

    public function test_the_period_cards_keep_their_keys(): void
    {
        $this->actingAs($this->user('admin'));

        $this->assertSame(
            ['sales', 'cogs', 'gross_profit', 'expenses', 'profit', 'tax', 'in', 'out', 'transfers'],
            array_keys($this->summary('month')['period']),
        );
    }
}
