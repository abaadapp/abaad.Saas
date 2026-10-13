<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * حدُّ الباقة الفارغ يعني «بلا حدّ» — يُحفظ ولا يُسقط النافذة.
 *
 * ═══ ما كان ═══
 *
 * نافذةُ الباقة تقول «الفراغ يعني بلا حدّ»، والمتحكّم يكتبه `null`، والأعمدةُ
 * `NOT NULL` — فحقلٌ فارغٌ واحد يردّ 500 عند الإنشاء والتعديل معًا.
 *
 * ═══ وما يُحرس هنا ═══
 *
 *   - باقةٌ جديدة بسقوفٍ فارغة تُحفظ، وكلُّ فارغٍ `null`.
 *   - تفريغُ سقفٍ في باقةٍ قائمة يرفعه وحده، والباقي كما كُتب.
 *   - متجرٌ على باقةٍ بلا سقف لا يُقفل عليه، ولا يُردّ تنزيلٌ إليها.
 *   - الأعمدةُ تقبل الفراغ، والافتراضيُّ القديم باقٍ لمن لا يذكر السقوف.
 */
class AnEmptyPlanLimitMeansNoLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@empty-limit.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** الصفُّ كما في القاعدة — لا كما يقرؤه النموذج */
    private function limitsOf(int $planId): array
    {
        $row = DB::table('plans')->where('id', $planId)->first(['max_branches', 'max_employees', 'max_products']);

        return array_map(fn ($v) => $v === null ? null : (int) $v, (array) $row);
    }

    public function test_a_new_plan_with_empty_limits_is_saved_without_them(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.plans.store'), [
            'name' => 'باقة بلا حدود', 'monthly_price' => 50, 'yearly_price' => 500,
            'max_branches' => '', 'max_employees' => '', 'max_products' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plan = Plan::where('name', 'باقة بلا حدود')->firstOrFail();

        $this->assertSame(
            ['max_branches' => null, 'max_employees' => null, 'max_products' => null],
            $this->limitsOf($plan->id),
        );
    }

    public function test_emptying_one_limit_lifts_only_that_limit(): void
    {
        $plan = Plan::create([
            'name' => 'باقة', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 2, 'max_employees' => 5, 'max_products' => 200,
        ]);

        $this->actingAs($this->super)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => '', 'max_employees' => '5', 'max_products' => '200',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            ['max_branches' => null, 'max_employees' => 5, 'max_products' => 200],
            $this->limitsOf($plan->id),
        );

        // وإعادةُ الرقم تُعيد السقف
        $this->actingAs($this->super)->put(route('super-admin.plans.update', $plan->id), [
            'name' => 'باقة', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => '3', 'max_employees' => '5', 'max_products' => '200',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $this->limitsOf($plan->id)['max_branches']);
    }

    public function test_a_shop_on_a_plan_without_a_limit_is_not_capped(): void
    {
        $plan = Plan::create([
            'name' => 'باقة', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => null, 'max_employees' => 3, 'max_products' => 100,
        ]);
        $shop = Business::create(['name' => 'متجر الفروع', 'status' => 'نشط', 'plan_id' => $plan->id]);
        foreach (range(1, 4) as $i) {
            Branch::create(['business_id' => $shop->id, 'name' => "فرع {$i}"]);
        }
        $shop = $shop->fresh();

        $this->assertNull(PlanLimits::cap($shop, 'branches'));
        $this->assertFalse(PlanLimits::reached($shop, 'branches'));
        $this->assertSame([], PlanLimits::exceededBy($shop, $plan));
        // والسقفُ المكتوب ما زال يُفرض
        $this->assertSame(3, PlanLimits::cap($shop, 'employees'));
    }

    public function test_the_columns_take_an_empty_limit_and_keep_their_old_defaults(): void
    {
        $columns = collect(Schema::getColumns('plans'))->keyBy('name');

        foreach (['max_branches', 'max_employees', 'max_products'] as $name) {
            $this->assertTrue($columns[$name]['nullable'], "«{$name}» لا يقبل الفراغ");
        }

        // باقةٌ لا تذكر سقوفها أصلًا تأخذ ما كانت تأخذه
        $plan = Plan::create(['name' => 'باقة', 'monthly_price' => 1, 'yearly_price' => 10]);

        $this->assertSame(
            ['max_branches' => 1, 'max_employees' => 3, 'max_products' => 100],
            $this->limitsOf($plan->id),
        );
    }
}
