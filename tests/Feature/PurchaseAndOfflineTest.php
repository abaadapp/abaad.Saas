<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أمر الشراء (يرفع المخزون) وصمود الانقطاع (يرفع الطلبات المؤجَّلة).
 *
 * كلاهما يكتب في المخزون والمالية دون أن يقف أمامهما شاشة، فالخطأ فيهما
 * صامت: أمرُ شراءٍ يُستلم مرّتين يضاعف المخزون، وطلبٌ يُرفع مرّتين بعد عودة
 * الاتصال يفوتر الزبون مرّتين.
 */
class PurchaseAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 5, 'active' => true,
        ]);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->business->id);
    }

    private function purchaseOrder(int $qty = 20, float $cost = 6): PurchaseOrder
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id, 'number' => 'PO-1',
            'status' => 'مطلوب', 'total' => $qty * $cost, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'quantity' => $qty, 'cost' => $cost,
        ]);

        return $po->fresh('items');
    }

    /* --------------------------- أوامر الشراء --------------------------- */

    public function test_receiving_a_purchase_order_raises_stock(): void
    {
        $po = $this->purchaseOrder(qty: 20);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(25, (int) $this->product->fresh()->quantity, '5 + 20');
    }

    public function test_receiving_twice_does_not_double_the_stock(): void
    {
        // نقرتان متتاليتان على «استلام» ليستا حدثًا نادرًا
        $po = $this->purchaseOrder(qty: 20);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(25, (int) $this->product->fresh()->quantity, 'تضاعف المخزون');
    }

    public function test_receiving_updates_the_cost_used_for_profit(): void
    {
        $po = $this->purchaseOrder(qty: 20, cost: 6);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(6.0, (float) $this->product->fresh()->cost, 'التكلفة لم تُحدَّث');
    }

    public function test_receiving_writes_an_inventory_movement(): void
    {
        $po = $this->purchaseOrder(qty: 20);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertDatabaseHas('inventory_movements', [
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'type' => 'إضافة كمية',
            'quantity' => '+20',
        ]);
    }

    public function test_one_business_cannot_receive_another_businesses_purchase_order(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $po = PurchaseOrder::create([
            'business_id' => $theirs->id, 'number' => 'PO-JAR',
            'status' => 'مطلوب', 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receive', $po->id))
            ->assertNotFound();

        $this->assertSame('مطلوب', $po->fresh()->status);
    }

    /* ------------------------- صمود الانقطاع ------------------------- */

    private function sell(string $uuid, int $qty = 1)
    {
        return $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => 'نقدي',
            'client_uuid' => $uuid,
        ]);
    }

    public function test_a_queued_sale_replayed_after_reconnect_is_not_billed_twice(): void
    {
        $first = $this->sell('offline-1')->assertOk()->json();

        // الشبكة عادت والواجهة أعادت رفع نفس السلّة
        $second = $this->sell('offline-1')->assertOk()->json();

        $this->assertSame($first['invoice'], $second['invoice'], 'رقم فاتورة مختلف');
        $this->assertTrue($second['duplicate'] ?? false, 'لم يُعلَم أنه تكرار');
        $this->assertSame(1, Order::where('business_id', $this->business->id)->count());
    }

    public function test_a_replayed_sale_does_not_deduct_stock_again(): void
    {
        $this->sell('offline-2', qty: 2)->assertOk();
        $this->assertSame(3, (int) $this->product->fresh()->quantity, '5 - 2');

        $this->sell('offline-2', qty: 2)->assertOk();

        $this->assertSame(3, (int) $this->product->fresh()->quantity, 'خُصم المخزون مرّتين');
    }

    public function test_two_genuinely_different_sales_are_both_recorded(): void
    {
        $a = $this->sell('offline-3')->assertOk()->json();
        $b = $this->sell('offline-4')->assertOk()->json();

        $this->assertNotSame($a['invoice'], $b['invoice']);
        $this->assertSame(2, Order::where('business_id', $this->business->id)->count());
    }

    public function test_one_businesses_replay_key_cannot_collide_with_anothers(): void
    {
        // المفتاح يأتي من متصفّح العميل: تصادمه بين مستأجرين لا يجوز أن
        // يُرجع فاتورة متجرٍ لمتجرٍ آخر
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $theirs->id, 'name' => 'فرعهم']);
        Currency::create([
            'business_id' => $theirs->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $theirProduct = Product::create([
            'business_id' => $theirs->id, 'name' => 'منتجهم',
            'price' => 10, 'quantity' => 5, 'active' => true,
        ]);
        $theirOwner = User::create([
            'business_id' => $theirs->id, 'name' => 'جارهم', 'email' => 'n@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->openShiftFor($theirs->id);

        $mine = $this->sell('same-uuid')->assertOk()->json();

        $theirs_ = $this->actingAs($theirOwner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $theirProduct->id, 'name' => $theirProduct->name, 'qty' => 1]],
            'payment_method' => 'نقدي', 'client_uuid' => 'same-uuid',
        ])->assertOk()->json();

        $this->assertFalse($theirs_['duplicate'] ?? false, 'حُسب بيع الجار تكرارًا لبيعي');
        $this->assertSame(1, Order::where('business_id', $theirs->id)->count());
        $this->assertSame(1, Order::where('business_id', $this->business->id)->count());
    }

    /* --------------------- متانة ترقيم الفواتير --------------------- */

    public function test_a_stray_non_numeric_invoice_number_does_not_break_the_till(): void
    {
        // رقم شاذّ قد يأتي من نسخة مستعادة أو إدخال يدوي. على SQLite يُقرأ
        // صفرًا بصمت، وعلى PostgreSQL كان CAST يرفع خطأً فيتعطّل كل بيع بعده.
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-ABC',
            'total' => 1, 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);

        $this->sell('after-stray')->assertOk();

        $this->assertSame(2, Order::where('business_id', $this->business->id)->count());
    }
}
