<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Plan;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الفرع يُفرَغ قبل أن يُحذف.
 *
 * كان الحذف يقع بلا سؤال فيبقى مخزون الفرع في مكانه ويختفي من كلّ شاشة:
 * الصنف يقول «الكمية ١٠» ومجموع الفروع الظاهرة ٤. ستّ قطعٍ لا تُرى ولا
 * تُصرَف ولا تُباع، ولا يُكتشف الفرق إلا في جردٍ آخر السنة.
 *
 * وأجهزته كانت تبقى «نشطة» على فرعٍ لا وجود له، فيردّ الصندوقُ كاشيرَه
 * برسالة «رمز غير صحيح» — فيظنّ أنه أخطأ رمزه، والسبب حذفٌ وقع في اللوحة.
 */
class BranchIsEmptiedBeforeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->second = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع القرم']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function stockIn(Branch $branch, float $qty): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف', 'price' => 10, 'cost' => 5, 'quantity' => $qty,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id,
            'product_id' => $p->id, 'quantity' => $qty,
        ]);

        return $p;
    }

    private function device(Branch $branch): PosDevice
    {
        return PosDevice::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id, 'name' => 'صندوق',
            'token_hash' => hash('sha256', 'raw'.$branch->id.uniqid()), 'status' => PosDevice::ACTIVE,
            'activated_by' => $this->owner->id, 'activated_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_a_branch_holding_stock_is_not_deleted(): void
    {
        $this->stockIn($this->second, 6);

        $this->delete(route('admin.branches.destroy', $this->second->id))
            ->assertSessionHasErrors('branch');

        $this->assertNotNull(Branch::find($this->second->id), 'حُذف فرعٌ فيه بضاعة');
    }

    /** والرسالة تقول كم بقي — لا «لا يمكن الحذف» وحدها */
    public function test_the_refusal_says_how_much_is_left(): void
    {
        $this->stockIn($this->second, 6);

        $this->delete(route('admin.branches.destroy', $this->second->id));

        $this->assertStringContainsString('6', json_encode(session()->get('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('فرع القرم', json_encode(session()->get('errors'), JSON_UNESCAPED_UNICODE));
    }

    /** ولا يبقى إجماليُّ الصنف مخالفًا لمجموع فروعه */
    public function test_the_total_never_drifts_from_the_visible_branches(): void
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف', 'price' => 10, 'cost' => 5, 'quantity' => 10,
        ]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->main->id, 'product_id' => $p->id, 'quantity' => 4]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->second->id, 'product_id' => $p->id, 'quantity' => 6]);

        $this->delete(route('admin.branches.destroy', $this->second->id));

        $visible = BranchStock::whereIn('branch_id', Branch::where('business_id', $this->business->id)->pluck('id'))
            ->where('product_id', $p->id)->sum('quantity');

        $this->assertEquals($p->fresh()->quantity, $visible,
            'كميةٌ عالقة في فرعٍ محذوف: الإجمالي لا يساوي مجموع الفروع');
    }

    /** وفرعٌ فارغ يُحذف كما كان — المنع على البضاعة لا على الحذف */
    public function test_an_empty_branch_is_still_deleted(): void
    {
        $this->delete(route('admin.branches.destroy', $this->second->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(Branch::find($this->second->id));
    }

    /** وصفرٌ في السجلّ ليس بضاعة */
    public function test_a_zero_row_does_not_block_deletion(): void
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف', 'price' => 10, 'cost' => 5, 'quantity' => 0,
        ]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->second->id, 'product_id' => $p->id, 'quantity' => 0]);

        $this->delete(route('admin.branches.destroy', $this->second->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(Branch::find($this->second->id));
    }

    public function test_deleting_a_branch_revokes_its_tills(): void
    {
        $device = $this->device($this->second);

        $this->delete(route('admin.branches.destroy', $this->second->id));

        $this->assertSame(PosDevice::REVOKED, $device->fresh()->status,
            'جهازٌ بقي نشطًا على فرعٍ لا وجود له');
    }

    /** وأجهزة الفروع الأخرى لا تُمَسّ */
    public function test_the_other_branches_tills_are_untouched(): void
    {
        $mine = $this->device($this->main);
        $his = $this->device($this->second);

        $this->delete(route('admin.branches.destroy', $this->second->id));

        $this->assertSame(PosDevice::ACTIVE, $mine->fresh()->status, 'أُبطل جهازٌ في فرعٍ لم يُحذف');
        $this->assertSame(PosDevice::REVOKED, $his->fresh()->status);
    }
}
