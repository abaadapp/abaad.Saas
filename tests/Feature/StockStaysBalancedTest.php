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

    private function assertBalanced(string $door): void
    {
        $total = (int) $this->p->fresh()->quantity;
        $sum = (int) BranchStock::where('product_id', $this->p->id)->sum('quantity');

        $this->assertSame($total, $sum,
            "«{$door}» كسر التوازن: الإجماليّ {$total} ومجموع الفروع {$sum}");
    }

    public function test_a_pos_sale_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
            ->postJson(route('pos.checkout'), [
                'items' => [['product_id' => $this->p->id, 'quantity' => 3, 'price' => 10]],
                'payment_method' => 'نقدي', 'paid' => 30,
            ]);

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
            $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
                ->post(route('admin.inventory.adjustments.store'), [
                    'product_id' => $this->p->id,
                    'quantity_delta' => $case['delta'],
                    'reason' => $case['reason'],
                    'adjusted_at' => now()->toDateString(),
                ])->assertSessionHasNoErrors();

            $this->assertBalanced('تسوية مخزون ('.$case['delta'].')');
        }
    }

    public function test_a_stocktake_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->post(route('admin.inventory.stocktake.apply'), [
                'counts' => [['product_id' => $this->p->id, 'counted' => 55]],
            ]);

        $this->assertBalanced('جرد');
    }

    public function test_a_manual_movement_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
            ->post(route('admin.inventory.store'), [
                'product_id' => $this->p->id, 'type' => 'إضافة كمية', 'quantity' => 9,
            ]);

        $this->assertBalanced('حركة مخزون يدوية');
    }

    public function test_a_quick_quantity_edit_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->patch(route('admin.products.quick', $this->p->id), ['quantity' => 130]);

        $this->assertBalanced('تعديل سريع للكمية');
    }

    public function test_editing_a_product_keeps_it_balanced(): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->a->id])
            ->put(route('admin.products.update', $this->p->id), [
                'name' => 'صنف', 'price' => 10, 'cost' => 4, 'quantity' => 70, 'alert_qty' => 5,
            ]);

        $this->assertBalanced('تعديل المنتج');
    }
}
