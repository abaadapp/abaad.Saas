<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * تصحيح فاتورةٍ بيعت.
 *
 * ما يُحرَس هنا ليس تغيّر رقمٍ في صفّ، بل أن السبعة التي كتبتها البيعة تعود
 * جميعًا إلى ما كانت ستكون عليه لو أُدخلت صحيحةً: الفاتورة وبنودها، ومخزون
 * الفرع، وحركة المخزون، والمعاملة المالية، ونقاط العميل. وأن الأثر يبقى.
 */
class OrderCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'نورة', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $cat = Category::create(['business_id' => $this->business->id, 'name' => 'عام']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'category_id' => $cat->id,
            'name' => 'قميص', 'sku' => 'SH-1', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 2, 'active' => true, 'tax' => 5,
        ]);
        BranchStock::ensureAllocated($this->business->id, $this->product->id, 20);
    }

    /** فاتورةٌ بثلاث قطع كما تكتبها نقطة البيع */
    private function sale(int $qty = 3, ?Customer $customer = null): Order
    {
        $gross = 10 * $qty;
        $tax = round($gross * 5 / 100, 3);

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-000001', 'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? 'عميل نقدي',
            'employee_name' => 'نورة', 'user_id' => $this->cashier->id,
            'subtotal' => $gross, 'discount' => 0, 'tax' => $tax, 'delivery_fee' => 0,
            'total' => round($gross + $tax, 3), 'payment_method' => 'نقدي',
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'ordered_at' => now(), 'points_earned' => $customer ? (int) floor(($gross + $tax) * 5) : 0,
        ]);
        $order->items()->create([
            'product_id' => $this->product->id, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => $qty, 'total' => $gross,
        ]);

        $this->product->decrement('quantity', $qty);
        BranchStock::adjust($this->business->id, $this->branch->id, $this->product->id, -$qty);

        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'reference' => $order->number, 'description' => 'مبيعات', 'method' => 'نقدي',
            'type' => 'دخل', 'amount' => $order->total, 'tax_amount' => $order->tax,
            'occurred_at' => now(),
        ]);

        return $order->fresh('items');
    }

    /* --------------------------- الحساب --------------------------- */

    public function test_lowering_a_quantity_recomputes_the_whole_invoice(): void
    {
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'أدخلتُ الكمية خطأً');

        $order->refresh();
        $this->assertSame('10.000', (string) $order->subtotal);
        $this->assertSame('0.500', (string) $order->tax);
        $this->assertSame('10.500', (string) $order->total);
    }

    public function test_the_stock_that_never_left_the_shelf_goes_back(): void
    {
        $order = $this->sale(3);
        $this->assertSame(17, (int) $this->product->fresh()->quantity);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ كمية');

        $this->assertSame(19, (int) $this->product->fresh()->quantity);
        $this->assertSame(19, (int) BranchStock::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_the_correction_is_written_as_a_stock_movement_not_silently(): void
    {
        // جردٌ بعد شهر يسأل: من أين جاءت هذه القطعتان؟
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ كمية');

        $this->assertDatabaseHas('inventory_movements', [
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'type' => 'تعديل فاتورة',
            'quantity' => '+2',
        ]);
    }

    public function test_the_finance_transaction_follows_the_invoice(): void
    {
        // رقمٌ في المالية لا يقابله بيعٌ يُفسد كل تقريرٍ يقرؤه
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ كمية');

        $row = Transaction::where('order_id', $order->id)->first();
        $this->assertSame(10.5, (float) $row->amount);
        $this->assertSame(0.5, (float) $row->tax_amount);
    }

    public function test_loyalty_points_are_pulled_back_to_what_was_really_bought(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001', 'points' => 0,
        ]);
        $order = $this->sale(3, $customer);
        $customer->update(['points' => (int) $order->points_earned]);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ كمية');

        // 10.500 × 5 = 52 نقطة على ما اشتُري فعلًا
        $this->assertSame(52, (int) $customer->fresh()->points);
        $this->assertSame(52, (int) $order->fresh()->points_earned);
    }

    public function test_points_are_never_pushed_below_zero_by_a_later_correction(): void
    {
        // العميل قد ينفق نقاطه بين البيعة والتصحيح
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000002', 'points' => 0,
        ]);
        $order = $this->sale(3, $customer);
        $customer->update(['points' => 5]);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'خطأ كمية');

        $this->assertSame(0, (int) $customer->fresh()->points);
    }

    /* --------------------------- الحراسة --------------------------- */

    public function test_an_invoice_is_never_emptied_of_its_last_line(): void
    {
        // حذفُ آخر بندٍ إلغاءٌ باسمٍ آخر
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        $this->expectException(RuntimeException::class);
        OrderCorrection::setQuantity($order, $order->items->first(), 0, 'حذف');
    }

    public function test_a_line_can_be_removed_when_another_remains(): void
    {
        $order = $this->sale(3);
        $order->items()->create([
            'product_id' => null, 'name' => 'كيس', 'price' => 1, 'quantity' => 1, 'total' => 1,
        ]);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->fresh('items')->items->first(), 0, 'صنف مكرّر');

        $this->assertSame(1, $order->fresh('items')->items->count());
        $this->assertSame(20, (int) $this->product->fresh()->quantity);
    }

    public function test_raising_a_quantity_still_obeys_the_stock_ceiling(): void
    {
        // وإلّا صار التصحيح بابًا خلفيًّا يتجاوز ما يُغلق عند البيع
        $order = $this->sale(3);
        $this->product->update(['quantity' => 0]);
        BranchStock::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)->update(['quantity' => 0]);
        $this->actingAs($this->cashier);

        $this->expectException(RuntimeException::class);
        OrderCorrection::setQuantity($order, $order->items->first(), 5, 'زيادة');
    }

    public function test_an_unchanged_quantity_is_refused_rather_than_logged_as_a_correction(): void
    {
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        $this->expectException(RuntimeException::class);
        OrderCorrection::setQuantity($order, $order->items->first(), 3, 'لا شيء');
    }

    /* --------------------------- الأثر --------------------------- */

    public function test_the_correction_leaves_a_trace_with_who_and_why(): void
    {
        $order = $this->sale(3);
        $this->actingAs($this->cashier);

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'أدخلتُ الكمية خطأً');

        $this->assertDatabaseHas('order_edits', [
            'order_id' => $order->id,
            'item_name' => 'قميص',
            'qty_before' => 3,
            'qty_after' => 1,
            'reason' => 'أدخلتُ الكمية خطأً',
        ]);

        $edit = \App\Models\OrderEdit::where('order_id', $order->id)->first();
        $this->assertSame('31.500', (string) $edit->order_total_before);
        $this->assertSame('10.500', (string) $edit->order_total_after);
    }

    /* --------------------------- المسار --------------------------- */

    public function test_the_screen_refuses_a_correction_with_no_reason(): void
    {
        $order = $this->sale(3);

        $this->actingAs($this->cashier)
            ->put(route('pos.orders.items.update', [$order->number, $order->items->first()->id]), [
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(3, (int) $order->fresh('items')->items->first()->quantity);
    }

    public function test_a_cashier_cannot_touch_an_invoice_of_another_business(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $order = $this->sale(3);
        $order->update(['business_id' => $other->id]);

        $this->actingAs($this->cashier)
            ->put(route('pos.orders.items.update', [$order->number, $order->items->first()->id]), [
                'quantity' => 1, 'reason' => 'محاولة',
            ])
            ->assertNotFound();
    }

    public function test_the_owner_sees_the_correction_on_the_order_page(): void
    {
        $order = $this->sale(3);
        $this->actingAs($this->cashier);
        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'أدخلتُ الكمية خطأً');

        $edits = \App\Support\Demo::orderEdits($order->id);

        $this->assertCount(1, $edits);
        $this->assertSame('أدخلتُ الكمية خطأً', $edits[0]['reason']);
        $this->assertSame('نورة', $edits[0]['by']);
    }
}
