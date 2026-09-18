<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الفرع يُصحَّح، ولا يُحذف ليُفتح من جديد.
 *
 * ═══ العطب ═══
 *
 * كان الفرع يُنشأ ويُحذف ولا يُعدَّل: قائمةُ الصفّ فيها «حذف» وحدها. فمن
 * كتب «فرغ صحار» بدل «فرع صحار» لا سبيل له إلى تصحيحه.
 *
 * والطريقُ الوحيد — احذفه وافتحه باسمه الصحيح — مسدودٌ في أكثر الأحوال:
 * لا يمرّ إن كان في الفرع بضاعة، ولا إن كان آخرَ فرعٍ له، ولا إن كانت
 * باقتُه تسمح بفرعٍ واحد (فلا يُنشأ البديل قبل حذف الأصل، ولا يُحذف الأصل
 * وهو الأخير). فيبقى الاسمُ الخطأ على كلّ شاشةٍ وكلّ فاتورةٍ تالية.
 *
 * والهاتفُ والعنوانُ يتبدّلان في الواقع: المحلّ ينتقل، والخطّ يتغيّر.
 */
class ABranchIsCorrectedNotReopenedTest extends TestCase
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
        $this->second = Branch::create([
            'business_id' => $this->business->id, 'name' => 'فرغ صحار',
            'phone' => '+968 26000000', 'address' => 'صحار - الحي',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function save(array $data, ?Branch $on = null)
    {
        return $this->put(route('admin.branches.update', ($on ?? $this->second)->id), $data);
    }

    /* ─────────── التصحيح نفسه ─────────── */

    public function test_a_branch_name_is_corrected_in_place(): void
    {
        $this->save(['name' => 'فرع صحار'])->assertSessionHasNoErrors();

        $this->assertSame('فرع صحار', $this->second->fresh()->name);
        $this->assertSame(0, Branch::where('business_id', $this->business->id)
            ->where('name', 'فرغ صحار')->count(), 'بقي الاسمُ الخطأ في الجدول');
    }

    /** ولا يُنشأ صفٌّ ثانٍ ولا يُحذف الأوّل */
    public function test_correcting_does_not_create_or_delete_a_row(): void
    {
        $before = $this->second->id;

        $this->save(['name' => 'فرع صحار']);

        $this->assertSame(2, Branch::where('business_id', $this->business->id)->count());
        $this->assertSame($before, Branch::where('name', 'فرع صحار')->value('id'),
            'التصحيح أنشأ صفًّا جديدًا — فانقطع عنه كلُّ ما يشير إليه');
    }

    /** والهاتفُ والعنوانُ يُصحَّحان معه */
    public function test_phone_and_address_are_editable(): void
    {
        $this->save(['name' => 'فرغ صحار', 'phone' => '+968 26111111', 'address' => 'صحار - الصناعية']);

        $b = $this->second->fresh();
        $this->assertSame('+968 26111111', $b->phone);
        $this->assertSame('صحار - الصناعية', $b->address);
    }

    /**
     * ═══ وحفظُ الاسم كما هو يمرّ ═══
     *
     * بلا `ignore` يصطدم الفرعُ بنفسه: يفتح التاجرُ النافذة ليصحّح الهاتفَ
     * وحدَه فيُقال له «لديك فرعٌ بهذا الاسم» — وهو هو.
     */
    public function test_saving_the_same_name_is_not_a_clash_with_itself(): void
    {
        $this->save(['name' => 'فرغ صحار', 'phone' => '+968 26222222'])
            ->assertSessionHasNoErrors();

        $this->assertSame('+968 26222222', $this->second->fresh()->phone);
    }

    /* ─────────── وما يحرسه التصحيح ─────────── */

    /** ولا يُتّخذ اسمُ فرعٍ حيّ: التفرّدُ لم يُرفع بفتح باب التعديل */
    public function test_an_edit_cannot_take_a_live_siblings_name(): void
    {
        $this->save(['name' => 'الرئيسي'])->assertSessionHasErrors('name');

        $this->assertSame('فرغ صحار', $this->second->fresh()->name, 'صار في المتجر فرعان باسمٍ واحد');
    }

    /** واسمُ فرعٍ محذوف حرٌّ — كما هو حرٌّ عند الإنشاء */
    public function test_an_edit_may_take_a_deleted_branchs_name(): void
    {
        $gone = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع نزوى']);
        $this->delete(route('admin.branches.destroy', $gone->id));
        $this->assertSoftDeleted('branches', ['id' => $gone->id]);

        $this->save(['name' => 'فرع نزوى'])->assertSessionHasNoErrors();

        $this->assertSame('فرع نزوى', $this->second->fresh()->name);
    }

    /** واسمُ جارٍ في المنصّة لا يمنعني */
    public function test_a_neighbours_name_does_not_block_my_edit(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'فرع الجار']);

        $this->save(['name' => 'فرع الجار'])->assertSessionHasNoErrors();

        $this->assertSame('فرع الجار', $this->second->fresh()->name);
    }

    /** واسمٌ فارغ أو أطولُ من الحقل يُردّ */
    public function test_an_empty_or_oversized_name_is_refused(): void
    {
        $this->save(['name' => ''])->assertSessionHasErrors('name');
        $this->save(['name' => str_repeat('م', 300)])->assertSessionHasErrors('name');

        $this->assertSame('فرغ صحار', $this->second->fresh()->name);
    }

    /* ─────────── وعزلُ المستأجرين ─────────── */

    /** ولا يُعدَّل فرعُ متجرٍ آخر بمعرّفه */
    public function test_another_tenants_branch_is_not_editable(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $his = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرع الجار']);

        $this->save(['name' => 'صار لي'], $his)->assertNotFound();

        $this->assertSame('فرع الجار', $his->fresh()->name, 'عُدِّل فرعُ متجرٍ آخر');
    }

    /** والكاشير لا يعدّل فرعًا — القسم تحت «الإعدادات» */
    public function test_a_cashier_cannot_edit_a_branch(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->save(['name' => 'فرع صحار'])->assertForbidden();

        $this->assertSame('فرغ صحار', $this->second->fresh()->name);
    }

    /* ─────────── والفواتيرُ الصادرة لا تتبدّل ─────────── */

    /**
     * اسمُ الفرع يُصوَّر في `orders.branch` يوم البيع.
     *
     * فإعادةُ التسمية لا تُعيد كتابة ورقةٍ خرجت — والفاتورةُ تقول ما كان
     * صحيحًا يوم صدرت. ولو كانت تُقرأ من الجدول وقتَ الطباعة لتبدّلت كلُّ
     * فاتورةٍ في الدرج بتصحيحِ حرف.
     */
    public function test_an_issued_invoice_keeps_the_name_it_was_printed_with(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->second->id,
            'branch' => 'فرغ صحار', 'number' => 'INV-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->save(['name' => 'فرع صحار']);

        $this->assertSame('فرغ صحار', $order->fresh()->branch,
            'تصحيحُ اسمِ فرعٍ أعاد كتابةَ فاتورةٍ صدرت');
        $this->assertSame($this->second->id, $order->fresh()->branch_id, 'انقطعت الفاتورةُ عن فرعها');
    }

    /* ─────────── والتصحيحُ هو مخرجُ آخرِ فرع ─────────── */

    /**
     * الحارسان يعتمد أحدُهما على الآخر.
     *
     * مُنع حذفُ آخر فرع — ولولا التعديل لَحُبس من أخطأ اسمَ فرعه الوحيد بلا
     * سبيل: لا يحذفه، ولا ينشئ بديلًا إن كانت باقتُه بفرعٍ واحد.
     */
    public function test_the_only_branch_cannot_be_deleted_but_can_be_corrected(): void
    {
        $this->delete(route('admin.branches.destroy', $this->second->id));
        $this->assertNull(Branch::find($this->second->id));

        $this->delete(route('admin.branches.destroy', $this->main->id));
        $this->assertNotNull(Branch::find($this->main->id), 'حُذف آخرُ فرع');

        $this->save(['name' => 'الفرع الرئيسي'], $this->main)->assertSessionHasNoErrors();
        $this->assertSame('الفرع الرئيسي', $this->main->fresh()->name,
            'لا حذفٌ ولا تعديل — الاسمُ الخطأ محبوسٌ على آخر فرع');
    }

    /** وفرعٌ فيه بضاعة يُصحَّح اسمُه — المنعُ على الحذف لا على التسمية */
    public function test_a_branch_holding_stock_is_still_renameable(): void
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف', 'price' => 10, 'cost' => 5, 'quantity' => 6,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->second->id,
            'product_id' => $p->id, 'quantity' => 6,
        ]);

        $this->delete(route('admin.branches.destroy', $this->second->id));
        $this->assertNotNull(Branch::find($this->second->id), 'حُذف فرعٌ فيه بضاعة');

        $this->save(['name' => 'فرع صحار'])->assertSessionHasNoErrors();
        $this->assertSame('فرع صحار', $this->second->fresh()->name);
    }
}
