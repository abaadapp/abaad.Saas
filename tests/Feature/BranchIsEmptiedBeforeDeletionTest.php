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

    /* ─────────── الاسمُ لا يُحجَز بعد الحذف ─────────── */

    /**
     * فرعٌ حُذف لا يحجز اسمه.
     *
     * ═══ العطب ═══
     *
     * `Rule::unique` تقرأ الجدولَ كما هو — والصفُّ المحذوف ليّنًا باقٍ فيه.
     * فيحذف التاجر «فرع القرم» ثمّ يفتحه من جديد، فيُقال له «لديك فرعٌ بهذا
     * الاسم» — وهو ينظر إلى قائمةٍ ليس فيها فرعُ القرم.
     *
     * فلا يفهم، ولا شيءَ في الرسالة يدلّه على سلّة المحذوفات. ويظنّ العطبَ
     * في بصره أو في النظام، ويسمّيه «فرع القرم 2».
     *
     * وهو القيدُ نفسُه الذي يتّبعه المنتج: صنفٌ حُذف لا يحجز رمزه.
     */
    public function test_a_deleted_branch_does_not_hold_its_name(): void
    {
        $this->delete(route('admin.branches.destroy', $this->second->id))->assertRedirect();
        $this->assertSoftDeleted('branches', ['id' => $this->second->id]);

        $this->post(route('admin.branches.store'), ['name' => 'فرع القرم'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Branch::where('business_id', $this->business->id)
            ->where('name', 'فرع القرم')->count(), 'لم يُفتح الفرع باسمه بعد حذفه');
    }

    /** والاسمُ الحيُّ يبقى محجوزًا — القيدُ لم يُرفع، إنّما ضُبط */
    public function test_a_live_branch_still_holds_its_name(): void
    {
        $this->post(route('admin.branches.store'), ['name' => 'فرع القرم'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Branch::where('business_id', $this->business->id)
            ->where('name', 'فرع القرم')->count());
    }

    /**
     * ولا يعود فرعان بالاسم نفسِه من سلّة المحذوفات.
     *
     * ثمنُ تحرير الاسم أنّ الاسمَ قد يُشغَل قبل «تراجع». والفرعُ يُعرَف باسمه
     * في ترويسة الملفّ وفي عمود «الفرع» وفي رسائل الجرد — ففرعان بالاسم
     * نفسِه يعني تقريرًا لا يُعرف لأيّهما.
     *
     * فيُردّ الإحياء ويُقال سببُه، كما يُردّ إحياءُ منتجٍ صار رمزُه لغيره.
     */
    public function test_a_restore_that_would_duplicate_a_live_name_is_refused(): void
    {
        $this->delete(route('admin.branches.destroy', $this->second->id));
        $this->post(route('admin.branches.store'), ['name' => 'فرع القرم']);

        $this->post(route('admin.branches.restore', $this->second->id), ['type' => 'branch'])
            ->assertRedirect();

        $this->assertSoftDeleted('branches', ['id' => $this->second->id]);
        $this->assertSame(1, Branch::where('business_id', $this->business->id)
            ->where('name', 'فرع القرم')->count(), 'عاد فرعان بالاسم نفسِه');
    }

    /** وما لم يُشغل اسمُه يعود كما كان */
    public function test_a_restore_whose_name_is_free_still_works(): void
    {
        $this->delete(route('admin.branches.destroy', $this->second->id));

        $this->post(route('admin.branches.restore', $this->second->id), ['type' => 'branch'])
            ->assertRedirect();

        $this->assertNull($this->second->fresh()->deleted_at, 'لم يعد الفرع وقد كان اسمُه حرًّا');
    }

    /* ─────────── والاسمُ يُحجَز في هذا المتجر وحده ─────────── */

    /** جارٌ في المنصّة له «فرع القرم» — ولا يمنعني من فتح فرعي بالاسم نفسِه */
    public function test_a_neighbours_branch_name_does_not_block_mine(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'فرع صحار']);

        $this->post(route('admin.branches.store'), ['name' => 'فرع صحار'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Branch::where('business_id', $this->business->id)
            ->where('name', 'فرع صحار')->count(), 'اسمُ جارٍ منع فتحَ فرعي');
    }

    /** ولا يمنعني من إحياء فرعي المحذوف */
    public function test_a_neighbours_branch_name_does_not_block_my_restore(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'فرع القرم']);

        $this->delete(route('admin.branches.destroy', $this->second->id));

        $this->post(route('admin.branches.restore', $this->second->id), ['type' => 'branch'])
            ->assertRedirect();

        $this->assertNull($this->second->fresh()->deleted_at, 'اسمُ جارٍ منع إحياءَ فرعي');
    }
}
