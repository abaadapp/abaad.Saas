<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مجموع الفروع = كمية المنتج — بعد كلّ عمليةٍ تُحرّك مخزونًا.
 *
 * هذا الثابت هو المخزون كلّه: الرقم الإجماليّ يُقرأ في التقارير والجرد،
 * ورصيد الفرع تبيع عليه نقطة البيع. فانكسارُه لا يُوقف شيئًا ولا يرفع خطأً —
 * يبقى صامتًا حتى يُعدّ الرفّ بعد أشهر ولا يُعرف من أين جاء الفرق.
 *
 * وقد انكسر فعلًا في التسليم قبل ساعة. فيُفحص كلّ باب.
 */
class StockStaysBalancedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $a;
    private Branch $b;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->a = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->b = Branch::create(['business_id' => $this->business->id, 'name' => 'الثاني']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->a->id, $this->p->id, 60);
        BranchStock::adjust($this->business->id, $this->b->id, $this->p->id, 40);
    }

    /**
     * الباب فُتح فعلًا.
     *
     * ثلاثةٌ من أبواب هذا الملفّ كانت تُردّ قبل أن تبلغ متحكّمها — بعقدٍ
     * خاطئ أو بحقلٍ مطلوبٍ لم يُرسَل — فيقارن الاختبار مئةً بمئة وينجح على
     * عمليةٍ لم تقع. فلا يكفي أن يُقاس التوازن بعد الطلب: يجب أن يُثبَت أوّلًا
     * أنّ الطلب غيّر شيئًا.
     */
    private function assertDidSomething(int $before, string $door): void
    {
        $this->assertNotSame($before, (int) $this->p->fresh()->quantity,
            "«{$door}» لم يغيّر الكمية — الطلب على الأرجح رُفض قبل أن يبلغ المتحكّم");
    }

    private function assertBalanced(string $door): void
    {
        $total = (int) $this->p->fresh()->quantity;
        $sum = (int) BranchStock::where('product_id', $this->p->id)->sum('quantity');

        $this->assertSame($total, $sum,
            "«{$door}» كسر التوازن: الإجماليّ {$total} ومجموع الفروع {$sum}");
    }

    public function test_a_pos_sale_keeps_it_balanced(): void
    {
        $before = (int) $this->p->fresh()->quantity;

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
            ->postJson(route('pos.checkout'), [
                // عقد الدفع الحقيقي: id/name/qty. كان المُرسَل product_id/quantity
                // بلا name، فيُردّ الطلب ٤٢٢ ولا يبلغ المتحكّم — ثمّ يقارن
                // الاختبار مئةً بمئة وينجح على بيعةٍ لم تقع
                'items' => [['id' => $this->p->id, 'name' => 'صنف', 'qty' => 3, 'price' => 10]],
                'payment_method' => 'نقدي', 'paid' => 30,
            ])->assertOk();

        $this->assertDidSomething($before, 'بيعة نقطة البيع');
        $this->assertBalanced('بيعة نقطة البيع');
    }

    public function test_receiving_a_purchase_order_keeps_it_balanced(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->b->id,
            'number' => 'PO-1', 'supplier_id' => $supplier->id, 'supplier_name' => 'مورّد',
            'status' => 'مُرسل', 'total' => 40, 'ordered_at' => now(),
        ]);
        $po->items()->create(['product_id' => $this->p->id, 'name' => 'صنف', 'cost' => 4, 'quantity' => 10]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertBalanced('استلام أمر شراء');
    }

    public function test_a_delivery_note_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->post(route('admin.inventory.deliveries.store'), [
                'delivered_at' => now()->toDateString(),
                'items' => [['product_id' => $this->p->id, 'name' => 'صنف', 'quantity' => 5]],
            ])->assertSessionHasNoErrors();

        $note = \App\Models\DeliveryNote::latest('id')->firstOrFail();
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->post(route('admin.inventory.deliveries.deliver', $note->id))->assertSessionHasNoErrors();

        $this->assertBalanced('إشعار تسليم');
    }

    public function test_a_stock_adjustment_keeps_it_balanced(): void
    {
        foreach ([['delta' => -7, 'reason' => 'تلف'], ['delta' => 12, 'reason' => 'تصحيح']] as $case) {
            $before = (int) $this->p->fresh()->quantity;

            $this->actingAs($this->owner)
                ->post(route('admin.inventory.adjustments.store'), [
                    'branch_id' => $this->b->id,
                    'product_id' => $this->p->id,
                    'quantity_delta' => $case['delta'],
                    'reason' => $case['reason'],
                    'adjusted_at' => now()->toDateString(),
                ])->assertSessionHasNoErrors();

            $this->assertDidSomething($before, 'تسوية مخزون');

            $this->assertBalanced('تسوية مخزون ('.$case['delta'].')');
        }
    }

    public function test_a_stocktake_keeps_it_balanced(): void
    {
        $before = (int) $this->p->fresh()->quantity;

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.stocktake.apply'), [
                // العقد الحقيقي: branch_id مطلوب، وcounts خريطة معرّف => المعدود.
                // كان المُرسَل قائمةَ قواميس بلا فرع، فيُردّ ٣٠٢ بأخطاء تحقّق
                'branch_id' => $this->a->id,
                'counts' => [$this->p->id => 55],
            ])->assertSessionHasNoErrors();

        $this->assertDidSomething($before, 'جرد');
        $this->assertBalanced('جرد');
    }

    public function test_a_manual_movement_keeps_it_balanced(): void
    {
        $before = (int) $this->p->fresh()->quantity;

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.store'), [
                // `branch_id` مطلوبٌ في المتحكّم منذ البداية ولم يكن يُرسَل:
                // بابٌ ثالثٌ لا يُفتح، ظهر عند إصلاح البابين الأوّلين
                'branch_id' => $this->b->id,
                'product_id' => $this->p->id, 'type' => 'إضافة كمية', 'quantity' => 9,
            ])->assertSessionHasNoErrors();

        $this->assertDidSomething($before, 'حركة مخزون يدوية');
        $this->assertBalanced('حركة مخزون يدوية');
    }

    public function test_a_quick_quantity_edit_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->patch(route('admin.products.quick', $this->p->id), ['quantity' => 130])
            ->assertSessionHasNoErrors();

        $this->assertSame(130, (int) $this->p->fresh()->quantity, 'التعديل السريع لم يبلغ المتحكّم');
        $this->assertBalanced('تعديل سريع للكمية');
    }

    public function test_editing_a_product_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->put(route('admin.products.update', $this->p->id), [
                'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 70, 'alert_qty' => 5,
            ])->assertSessionHasNoErrors();

        $this->assertSame(70, (int) $this->p->fresh()->quantity, 'تعديل المنتج لم يبلغ المتحكّم');
        $this->assertBalanced('تعديل المنتج');
    }
}
