<?php

namespace Tests\Feature;

use App\Models\BranchTransfer;
use App\Models\Plan;
use App\Support\PlanFeatures;
use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnterpriseShop;
use Tests\TestCase;

/**
 * إدارةُ الفروع تُباع مع باقة المؤسسات وحدها — ولا تُفتح بالافتراض.
 *
 * الأساسيّةُ والاحترافيّةُ لا تملكانها، ولا المتجرُ بلا باقة، ولا باقةٌ قديمةٌ
 * عمودُ قدراتها فارغ، ولا باقةٌ سُمّيت «المؤسسات» باسمها. ولا يفتحها طلبٌ يُرسَل
 * إلى الخادم مباشرة. وما أُرسل قبل تخفيض الباقة يُستلم ويُسوّى بعده.
 */
class BranchOperationsAreSoldWithTheEnterprisePlanOnlyTest extends TestCase
{
    use EnterpriseShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->makeEnterpriseShop();
    }

    private function onPlan(?array $capabilities, string $name = 'الباقة الاحترافية'): Plan
    {
        $plan = Plan::create(['name' => $name, 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => $capabilities]);
        $this->shop->update(['plan_id' => $plan->id]);

        return $plan;
    }

    public function test_only_the_enterprise_seed_holds_the_branch_capabilities(): void
    {
        $plans = collect(SeedData::plans())->keyBy('name');

        foreach (PlanFeatures::ENTERPRISE as $key) {
            $this->assertNotContains($key, $plans['الباقة الأساسية']['capabilities'], $key);
            $this->assertNotContains($key, $plans['الباقة الاحترافية']['capabilities'], "الاحترافيّةُ تفتح «{$key}»");
            $this->assertContains($key, $plans['باقة المؤسسات']['capabilities'], $key);
        }

        // والاحترافيّةُ تبقى على كلّ ما قبل المؤسسات
        $this->assertEqualsCanonicalizing(PlanFeatures::baseKeys(), $plans['الباقة الاحترافية']['capabilities']);
        $this->assertSame(Plan::CODE_ENTERPRISE, $plans['باقة المؤسسات']['code']);
    }

    public function test_the_branch_capabilities_are_closed_without_an_explicit_grant(): void
    {
        foreach (PlanFeatures::ENTERPRISE as $key) {
            $this->assertTrue(PlanFeatures::allows($this->shop->fresh(), $key), "المؤسساتُ لا تفتح «{$key}»");
        }

        $cases = [
            'الاحترافيّة' => PlanFeatures::baseKeys(),
            'باقةٌ عمودُها فارغ' => null,
            'الأساسيّة' => [],
        ];

        foreach ($cases as $label => $capabilities) {
            $this->onPlan($capabilities);
            foreach (PlanFeatures::ENTERPRISE as $key) {
                $this->assertFalse(PlanFeatures::allows($this->shop->fresh(), $key), "{$label} تفتح «{$key}»");
            }
            if ($capabilities === null) {
                // والفارغةُ تبقى تفتح ما قبلها كما كانت
                $this->assertTrue(PlanFeatures::allows($this->shop->fresh(), 'website'));
            }
        }

        $this->shop->update(['plan_id' => null]);
        foreach (PlanFeatures::ENTERPRISE as $key) {
            $this->assertFalse(PlanFeatures::allows($this->shop->fresh(), $key), "متجرٌ بلا باقة يفتح «{$key}»");
        }
    }

    public function test_a_plan_named_enterprise_is_not_enterprise_by_its_name(): void
    {
        $this->onPlan(PlanFeatures::baseKeys(), 'باقة المؤسسات');

        $this->assertFalse(PlanFeatures::allows($this->shop->fresh(), 'enterprise_transfers'));
        $this->actingAs($this->owner)->get(route('admin.reports.branches'))->assertForbidden();
    }

    public function test_a_platform_grant_on_a_custom_plan_opens_exactly_what_it_names(): void
    {
        $this->onPlan([...PlanFeatures::baseKeys(), 'enterprise_branch_analytics'], 'باقة مخصصة');

        $this->assertTrue(PlanFeatures::allows($this->shop->fresh(), 'enterprise_branch_analytics'));
        $this->assertFalse(PlanFeatures::allows($this->shop->fresh(), 'enterprise_transfers'));
        $this->actingAs($this->owner)->get(route('admin.reports.branches'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.reports.branchesWeekly'))->assertForbidden();
    }

    public function test_a_professional_shop_is_refused_at_every_door_even_by_a_direct_request(): void
    {
        $this->onPlan(PlanFeatures::baseKeys());

        $items = [['product_id' => $this->rose->id, 'quantity' => 1]];
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id, 'items' => $items,
        ])->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.inventory.supply.store'), [
            'branch_id' => $this->salalah->id, 'items' => $items,
        ])->assertForbidden();

        foreach (['admin.inventory.transfers', 'admin.inventory.supply', 'admin.reports.branches', 'admin.reports.branchesWeekly', 'admin.reports.transfers'] as $name) {
            $this->actingAs($this->owner)->get(route($name))->assertForbidden();
        }

        // ولا يُنزَّل ملفُّ التقرير من باب التصدير العامّ — والاحترافيّةُ تملك التصدير
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $this->actingAs($this->owner);
            $this->get($this->exportRoute('admin.reports.export.'.$format, 'branches'))->assertForbidden();
            $this->get($this->exportRoute('admin.reports.export.'.$format, 'branchesWeekly'))->assertForbidden();
        }

        $this->assertSame(0, BranchTransfer::count());

        // والرسالةُ تقول ما يُفعل
        $this->actingAs($this->owner)->get(route('admin.reports.branches'))
            ->assertSee(PlanFeatures::refusal($this->shop->fresh(), 'enterprise_branch_analytics'), false);
    }

    public function test_after_a_downgrade_what_was_sent_is_received_and_nothing_new_starts(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 5]], 'execute' => 1, 'self_approve' => 1,
        ])->assertSessionHasNoErrors();
        $sent = BranchTransfer::latest('id')->firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'items' => [['product_id' => $this->rose->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $pending = BranchTransfer::latest('id')->firstOrFail();

        $this->onPlan(PlanFeatures::baseKeys());
        // كلُّ طلبٍ يقرأ المستخدمَ من جديد — والكائنُ في الاختبار يحمل باقتَه القديمة
        $owner = $this->owner->fresh();

        // السجلُّ يُقرأ، وما أُرسل يُستلم
        $this->actingAs($owner)->get(route('admin.inventory.transfers'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('licensed', false)->where('can.create', false));
        $line = $sent->lines()->first();
        $this->actingAs($this->salalahKeeper)->post(route('admin.inventory.transfers.receive', $sent->id), ['quantities' => [$line->id => 5]])
            ->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::RECEIVED, $sent->fresh()->status);
        $this->assertSame([54, 45, 9, 0], $this->shelves($this->rose));

        // ولا يُعتمد جديد ولا يُنشأ — ويُلغى ما لم يُرسل
        $this->actingAs($owner)->post(route('admin.inventory.transfers.approve', $pending->id), ['self_approve' => 1])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.inventory.transfers.cancel', $pending->id), ['reason' => 'تغيّرت الباقة'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BranchTransfer::CANCELLED, $pending->fresh()->status);
    }

    /* ====================== ترحيلُ الباقات القائمة ====================== */

    private function migration(): object
    {
        return require database_path('migrations/2026_10_24_090000_the_enterprise_plan_runs_its_branches.php');
    }

    /** باقةٌ كما على الإنتاج قبل الترحيل: بلا رمز، وعلى سلّم الأسقف ونصّه */
    private function livePlan(string $name, ?array $capabilities): int
    {
        $migration = $this->migration();

        return DB::table('plans')->insertGetId([
            'name' => $name, 'monthly_price' => 69.9, 'yearly_price' => 699,
            'max_branches' => 10, 'max_employees' => 100, 'max_products' => null,
            'features' => json_encode($migration::BEFORE), 'capabilities' => $capabilities === null ? null : json_encode($capabilities),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_live_enterprise_plan_gets_its_code_capabilities_and_text_and_nothing_else_changes(): void
    {
        DB::table('plans')->where('id', $this->enterprise->id)->update(['code' => null, 'name' => 'باقة قديمة']);
        $pro = DB::table('plans')->insertGetId([
            'name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'max_branches' => 3,
            'features' => json_encode(['إدارة + نمو']), 'capabilities' => json_encode(PlanFeatures::baseKeys()),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $live = $this->livePlan('باقة المؤسسات', ['reports_advanced', 'website']);
        $before = DB::table('plans')->where('id', $pro)->first();

        $migration = $this->migration();
        $migration->up();

        $row = DB::table('plans')->where('id', $live)->first();
        $this->assertSame(Plan::CODE_ENTERPRISE, $row->code);
        $granted = json_decode($row->capabilities, true);
        $this->assertSame(['reports_advanced', 'website'], array_slice($granted, 0, 2), 'تغيّر ما كانت تملكه');
        foreach (PlanFeatures::ENTERPRISE as $key) {
            $this->assertContains($key, $granted);
        }
        $this->assertSame($migration::AFTER, json_decode($row->features, true));
        $this->assertSame(['69.900', '699.000'], [(string) Plan::find($live)->monthly_price, (string) Plan::find($live)->yearly_price], 'تغيّر السعر');
        $this->assertSame(10, (int) $row->max_branches);
        $this->assertEquals($before, DB::table('plans')->where('id', $pro)->first(), 'مُسّت الاحترافيّة');

        // والنصُّ نفسُه في البذرة والترحيل
        $this->assertSame($migration::AFTER, collect(SeedData::plans())->firstWhere('name', 'باقة المؤسسات')['features']);
        $this->assertSame(PlanFeatures::ENTERPRISE, $migration::CAPABILITIES);

        $migration->down();
        $row = DB::table('plans')->where('id', $live)->first();
        $this->assertNull($row->code);
        $this->assertSame(['reports_advanced', 'website'], json_decode($row->capabilities, true));
        $this->assertSame($migration::BEFORE, json_decode($row->features, true));
    }

    public function test_an_empty_capability_column_is_written_out_in_full_so_nothing_closes(): void
    {
        DB::table('plans')->where('id', $this->enterprise->id)->update(['code' => null, 'name' => 'باقة قديمة']);
        $live = $this->livePlan('باقة المؤسسات', null);

        $this->migration()->up();

        $this->assertEqualsCanonicalizing(PlanFeatures::keys(), json_decode(DB::table('plans')->where('id', $live)->value('capabilities'), true));
    }

    public function test_an_ambiguous_or_edited_plan_gets_no_code_and_no_grant(): void
    {
        DB::table('plans')->where('id', $this->enterprise->id)->update(['code' => null, 'name' => 'باقة قديمة']);
        $a = $this->livePlan('باقة المؤسسات', PlanFeatures::baseKeys());
        $b = $this->livePlan('باقة المؤسسات', PlanFeatures::baseKeys());

        $this->migration()->up();

        foreach ([$a, $b] as $id) {
            $row = DB::table('plans')->where('id', $id)->first();
            $this->assertNull($row->code, 'أُسند الرمز إلى باقةٍ ملتبسة');
            $this->assertNotContains('enterprise_transfers', json_decode($row->capabilities, true));
        }

        DB::table('plans')->where('id', $b)->delete();
        DB::table('plans')->where('id', $a)->update(['max_branches' => 25]);
        $this->migration()->up();
        $this->assertNull(DB::table('plans')->where('id', $a)->value('code'), 'أُسند الرمز إلى باقةٍ عُدّلت أسقفها');
    }
}
