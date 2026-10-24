<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Support\BranchReports;
use App\Support\BranchTransfers;
use App\Support\Demo;
use App\Support\PlanFeatures;
use App\Support\Profitability;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EnterpriseShop;
use Tests\TestCase;

/**
 * لوحةُ أداء الفروع والتقريرُ الأسبوعيّ والتنبيهاتُ المركزيّة.
 *
 * والأرقامُ لا تُحسب هنا بمعادلةٍ ثانية: كلُّ فرعٍ بتقرير صافي الربح نفسِه
 * (`Profitability::summary`)، ومجموعُ الصفوف يساوي رقمَ النشاط. ولا يدخل متجرٌ
 * آخر في شيءٍ منها.
 */
class TheOwnerSeesEveryBranchSideBySideTest extends TestCase
{
    use EnterpriseShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->makeEnterpriseShop();
        Customer::create(['business_id' => $this->shop->id, 'name' => 'خالد', 'phone' => '91234567', 'language' => 'ar']);
    }

    private function sellAt(Branch $branch, int $qty): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $branch->id])
            ->postJson('/pos/checkout', [
                'items' => [['id' => $this->rose->id, 'name' => 'ورد أحمر', 'qty' => $qty]],
                'payment_method' => 'نقدي', 'customer' => 'خالد', 'client_uuid' => uniqid('b', true),
                'fulfillment_type' => 'pickup', 'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
            ])->assertOk();
    }

    public function test_each_branch_is_read_by_the_approved_profit_report_and_the_rows_add_up(): void
    {
        $this->sellAt($this->muscat, 4);
        $this->sellAt($this->salalah, 2);
        // مبيعاتُ الجار لا تدخل
        $theirs = $this->staff('مالك الجار', 'admin', [], $this->neighbour);
        Branch::create(['business_id' => $this->neighbour->id, 'name' => 'فرع الجار']);
        $this->assertSame(2, Order::where('business_id', $this->shop->id)->count());

        $period = ReportingPeriod::preset('month');
        $report = BranchReports::branches($this->shop->id, $period);

        $this->assertCount(3, $report['rows']);
        foreach ($report['rows'] as $row) {
            $money = Profitability::summary($this->shop->id, $period->start, $period->end, $row['branch_id']);
            foreach (['sales', 'net_revenue', 'cogs', 'gross_profit', 'expenses', 'net_profit', 'margin'] as $key) {
                $this->assertSame($money[$key], $row[$key], "{$row['branch']} › {$key} يخالف تقرير صافي الربح");
            }
        }

        $byName = collect($report['rows'])->keyBy('branch');
        $this->assertSame(1, $byName['مسقط']['orders']);
        $this->assertSame(1, $byName['صلالة']['orders']);
        $this->assertSame(0, $byName['صحار']['orders']);

        $whole = Profitability::summary($this->shop->id, $period->start, $period->end);
        $this->assertSame($whole['net_profit'], $report['summary']['net_profit'], 'صافي ربح النشاط يخالف تقريره');
        $this->assertEqualsWithDelta(
            $whole['sales'],
            array_sum(array_column($report['rows'], 'sales')) + ($report['unassigned']['sales'] ?? 0),
            0.001,
            'مجموعُ الفروع لا يساوي النشاط',
        );
        $this->assertEqualsWithDelta(
            $whole['expenses'] - array_sum(array_column($report['rows'], 'expenses')),
            $report['summary']['unallocated'],
            0.001,
        );

        // المخزونُ لكلّ فرع — والوردُ في مسقط وصلالة
        // مسقط: ٥٠ وردة بِيع منها ٤، و٢٠ ورقة
        $this->assertSame(46 + 20, $byName['مسقط']['stock_quantity']);
        $this->assertEqualsWithDelta(46 * 1.5 + 20 * 0.25, $byName['مسقط']['stock_value'], 0.001);
        // صلالة: الوردُ ٢ (بِيع منه ٢) والورقُ ٠ — كلاهما تحت حدّه ٥
        $this->assertSame(2, $byName['صلالة']['low_stock']);
        $this->assertSame(0, $byName['مسقط']['low_stock']);

        $this->assertSame('مسقط', $report['summary']['best']);
        $this->assertSame('صلالة', $report['summary']['worst']);
    }

    /**
     * وفرعٌ واحدٌ له نشاطٌ لا يُسمّى «أعلى فرع ربحًا».
     *
     * وُجد في المتصفّح: فرعٌ خسر ٣ ر.ع بفقدٍ أثناء النقل، والآخرُ بلا نشاط —
     * فظهر الخاسرُ «أعلى فرع ربحًا». ومتجرا المؤسسات على الإنتاج بفرعٍ واحد،
     * فكان فرعُهما الوحيد سيُسمّى «الأعلى» دائمًا.
     */
    public function test_one_active_branch_is_not_named_the_best(): void
    {
        $this->sellAt($this->muscat, 4);

        $summary = BranchReports::branches($this->shop->id, ReportingPeriod::preset('month'))['summary'];

        $this->assertNull($summary['best']);
        $this->assertNull($summary['worst']);
    }

    public function test_the_screen_ranks_and_isolates_and_counts_what_is_open(): void
    {
        $this->sellAt($this->muscat, 4);
        $this->sellAt($this->salalah, 1);
        $this->actingAs($this->salalahManager)->post(route('admin.inventory.supply.store'), [
            'branch_id' => $this->salalah->id, 'items' => [['product_id' => $this->rose->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->sohar->id,
            'items' => [['product_id' => $this->wrap->id, 'quantity' => 4]], 'execute' => 1, 'self_approve' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.reports.branches'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Reports/Branches')
                ->has('rows', 3)
                ->where('rows.1.open_supply', 1)
                ->where('rows.2.incoming', 4)
                ->where('rows.0.transfers_open', 1)
                ->where('summary.in_transit_quantity', 4)
                ->where('summary.best', 'مسقط'));

        // والجارُ لا يرى فروعَ هذا المتجر
        $theirs = $this->staff('مالك الجار', 'admin', [], $this->neighbour);
        $this->actingAs($theirs)->get(route('admin.reports.branches'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows', 0)->where('summary.sales', 0));
    }

    public function test_the_weekly_report_reads_one_sunday_to_saturday_week(): void
    {
        [$start, $end] = BranchReports::week('2026-10-21');
        $this->assertSame('2026-10-18', $start->toDateString(), 'الأسبوعُ يبدأ الأحد');
        $this->assertSame('2026-10-25', $end->toDateString());

        $this->sellAt($this->muscat, 3);

        // سندٌ أُرسل قبل ثلاثة أيام ولم يُستلم — متأخّر
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 6]], 'execute' => 1, 'self_approve' => 1,
        ])->assertSessionHasNoErrors();
        $late = BranchTransfer::latest('id')->first();
        $late->update(['dispatched_at' => now()->subDays(3)]);

        // وسندٌ استُلم بفرق
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->sohar->id,
            'items' => [['product_id' => $this->wrap->id, 'quantity' => 5]], 'execute' => 1, 'self_approve' => 1,
        ])->assertSessionHasNoErrors();
        $gap = BranchTransfer::latest('id')->first();
        BranchTransfers::receive($gap, $this->owner->fresh(), [$gap->lines()->first()->id => 3]);

        $weekly = BranchReports::weekly($this->shop->id, now()->toDateString());

        $this->assertCount(3, $weekly['sections']['الفروع']);
        $this->assertSame('ورد أحمر', $weekly['sections']['الأكثر مبيعًا'][0]['product']);
        $this->assertSame([$late->number], array_column($weekly['sections']['التحويلات المتأخرة'], 'number'));
        $this->assertSame([$gap->number], array_column($weekly['sections']['فروقات الاستلام'], 'number'));
        $this->assertSame(2, $weekly['sections']['فروقات الاستلام'][0]['quantity']);
        $this->assertSame(1, $weekly['summary']['late']);
        $this->assertSame('مسقط', $weekly['summary']['top_branch']);

        $this->actingAs($this->owner)->get(route('admin.reports.branchesWeekly', ['week' => now()->toDateString()]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Reports/BranchesWeekly')->where('summary.late', 1));
    }

    public function test_alerts_reach_the_person_who_can_act_and_leave_when_the_cause_does(): void
    {
        $alerts = fn ($user) => collect((function () use ($user) {
            $this->actingAs($user->fresh());

            return Demo::allNotifications();
        })())->pluck('key')->filter(fn ($k) => str_starts_with($k, 'branch-') && ! str_starts_with($k, 'branch-low-'))->values()->all();

        $this->actingAs($this->muscatManager)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 6]],
        ])->assertSessionHasNoErrors();
        $t = BranchTransfer::latest('id')->first();

        $this->assertSame(['branch-approve-'.$t->id], $alerts($this->owner));
        $this->assertSame([], $alerts($this->muscatManager), 'مديرُ الفرع لا يعتمد — فلا يُنبَّه');

        BranchTransfers::approve($t, $this->owner->fresh());
        $this->assertSame(['branch-dispatch-'.$t->id], $alerts($this->muscatManager));
        $this->assertSame([], $alerts($this->salalahManager), 'مديرُ صلالة لا يُرسل من مسقط');

        BranchTransfers::prepare($t->fresh(), $this->muscatManager->fresh());
        BranchTransfers::dispatch($t->fresh(), $this->muscatManager->fresh());
        $this->assertSame([], $alerts($this->muscatManager));

        BranchTransfers::receive($t->fresh(), $this->salalahKeeper->fresh(), [$t->lines()->first()->id => 5]);
        $this->assertSame(['branch-gap-'.$t->id], $alerts($this->owner));

        // ولا تنبيهاتِ فروعٍ لباقةٍ لا تملكها
        $pro = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => PlanFeatures::baseKeys()]);
        $this->shop->update(['plan_id' => $pro->id]);
        $this->assertSame([], $alerts($this->owner));
    }

    public function test_a_branch_low_on_a_product_is_flagged_for_its_staff_only(): void
    {
        $keys = function ($user) {
            $this->actingAs($user->fresh());

            return collect(Demo::allNotifications())->pluck('key')->filter(fn ($k) => str_starts_with($k, 'branch-low-'))->values()->all();
        };

        $this->assertContains('branch-low-'.$this->salalah->id.'-'.$this->rose->id, $keys($this->owner));
        $this->assertContains('branch-low-'.$this->salalah->id.'-'.$this->rose->id, $keys($this->salalahManager));
        $this->assertNotContains('branch-low-'.$this->salalah->id.'-'.$this->rose->id, $keys($this->muscatManager));
    }

    /**
     * ومتجرٌ بفرعٍ واحد لا يُنبَّه مرّتين على الصنف نفسه.
     *
     * نقصُ الفرع الوحيد هو نقصُ الإجماليّ، وله تنبيهُه القائم (`low-`). ومتجرا
     * المؤسسات على الإنتاج يوم النشر بفرعٍ واحد كلٌّ منهما — فكان كلُّ صنفٍ
     * منخفضٍ سيظهر في الجرس سطرين.
     */
    public function test_a_shop_with_one_branch_hears_of_a_low_product_once(): void
    {
        $only = Branch::create(['business_id' => $this->neighbour->id, 'name' => 'الوحيد']);
        $low = $this->stocked('شمع', 1, [$only->id => 2], $this->neighbour);
        $this->actingAs($this->staff('مالك الجار', 'admin', [], $this->neighbour));

        $keys = collect(Demo::allNotifications())->pluck('key');
        $this->assertContains('low-'.$low->id, $keys, 'التنبيهُ القائم باقٍ');
        $this->assertSame([], $keys->filter(fn ($k) => str_starts_with($k, 'branch-low-'))->values()->all());
    }
}
