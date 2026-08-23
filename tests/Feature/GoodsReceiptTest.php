<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * استلام أمر الشراء: ما وصل يدخل، وما لم يصل يبقى مفتوحًا، وللدخول ورقة.
 *
 * كان الاستلام كلًّا أو لا شيء: الزرّ يرسل طلبًا فارغًا والكود يكتب
 * `received_quantity = quantity` لكل بند. فمورّدٌ يشحن ثمانين من مئة يُسجَّل
 * مئةً — يزيد المخزون عشرين لم تصل، ويُحسب متوسّط التكلفة على مئةٍ دُفع ثمن
 * ثمانين منها. رقمان يفسدان معًا، ويظهر الفرق بعد أشهرٍ في جردٍ لا يُعرف من
 * أين جاء.
 */
class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدي']);
    }

    private function product(string $name, int $qty, float $cost): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => $name,
            'price' => $cost * 2, 'cost' => $cost, 'quantity' => $qty, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    /** @param  array<int, array{product: Product, qty: int, cost: float}>  $lines */
    private function order(array $lines): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'number' => 'PO-'.random_int(10000, 99999),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل',
            'total' => collect($lines)->sum(fn ($l) => $l['qty'] * $l['cost']),
            'ordered_at' => now(),
        ]);

        foreach ($lines as $l) {
            $po->items()->create([
                'product_id' => $l['product']->id, 'name' => $l['product']->name,
                'cost' => $l['cost'], 'quantity' => $l['qty'],
            ]);
        }

        return $po->refresh();
    }

    public function test_a_short_shipment_is_recorded_short(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 100, 'cost' => 4]]);
        $line = $po->items->first();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receive', $po->id), ['items' => [['id' => $line->id, 'quantity' => 80]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(80, (int) $product->fresh()->quantity, 'دخل المخزون غيرُ ما وصل');
        $this->assertSame(80, (int) $line->fresh()->received_quantity);
        $this->assertSame(20, $line->fresh()->remaining);
        $this->assertSame('مستلم جزئيًا', $po->fresh()->status);
        $this->assertNull($po->fresh()->received_at, 'أمرٌ لم يكتمل لا تاريخ اكتمالٍ له');
    }

    public function test_the_rest_arrives_later_and_closes_the_order(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 100, 'cost' => 4]]);
        $line = $po->items->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 80]]]);
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 20]]])->assertSessionHasNoErrors();

        $this->assertSame(100, (int) $product->fresh()->quantity);
        $this->assertSame('مستلم', $po->fresh()->status);
        $this->assertNotNull($po->fresh()->received_at);

        // ورقةٌ لكل دفعة لا ورقةٌ للأمر: الدفعتان وصلتا في يومين مختلفين
        $this->assertSame(2, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());
    }

    /**
     * أخطر ما في الملفّ.
     *
     * متوسّط التكلفة يُرجَّح بما وصل لا بما طُلب: دفعةٌ من ثمانين لا تُثقَّل
     * بوزن مئة، وإلا انحرفت تكلفة كلّ بيعةٍ قادمة انحرافًا لا يُرى.
     */
    public function test_the_average_cost_is_weighted_by_what_arrived(): void
    {
        // مئة قطعةٍ في المخزن بتكلفة ٤، وتصل عشرون من أمرٍ من مئةٍ بتكلفة ٩
        $product = $this->product('صنف', 100, 4);
        $po = $this->order([['product' => $product, 'qty' => 100, 'cost' => 9]]);
        $line = $po->items->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 20]]])->assertSessionHasNoErrors();

        // (100×4 + 20×9) ÷ 120 = 4.833 — لا (100×4 + 100×9) ÷ 200 = 6.5
        $this->assertSame(4.833, round((float) $product->fresh()->cost, 3));
    }

    public function test_more_than_remaining_is_refused_not_silently_trimmed(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 10, 'cost' => 1]]);
        $line = $po->items->first();

        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receive', $po->id), ['items' => [['id' => $line->id, 'quantity' => 25]]])
            ->assertSessionHasErrors('receive');

        $this->assertSame(0, (int) $product->fresh()->quantity, 'رُفض الطلب ودخلت البضاعة');
        $this->assertSame('مُرسل', $po->fresh()->status);
    }

    public function test_an_empty_request_still_receives_everything(): void
    {
        // زرّ «استلام الكل» القديم يرسل طلبًا فارغًا — ولا يجوز أن يُكسر
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 7, 'cost' => 3]]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $product->fresh()->quantity);
        $this->assertSame('مستلم', $po->fresh()->status);
    }

    public function test_the_note_says_what_came_in_and_from_which_order(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 10, 'cost' => 2.5]]);
        $line = $po->items->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id),
            ['items' => [['id' => $line->id, 'quantity' => 4]]]);

        $note = GoodsReceiptNote::where('purchase_order_id', $po->id)->with('items')->firstOrFail();

        $this->assertSame('GRN-000001', $note->number);
        $this->assertSame($this->supplier->id, $note->supplier_id);
        $this->assertSame($this->branch->id, $note->branch_id);
        $this->assertSame('المالك', $note->receiver);
        $this->assertSame(4.0, (float) $note->items->first()->quantity);
        $this->assertSame(2.5, (float) $note->items->first()->cost);
    }

    /** الورقة تُقرأ في قسم المخزون، ولا تُكتب بيدٍ هناك */
    public function test_the_receipts_screen_lists_the_note(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 3, 'cost' => 2]]);
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts'))->viewData('page')['props'];

        $this->assertCount(1, $props['notes']);
        $this->assertSame('GRN-000001', $props['notes'][0]['number']);
        $this->assertSame(6.0, $props['notes'][0]['value']);
        $this->assertSame($po->number, $props['notes'][0]['order']);
    }

    /**
     * إشعار الاستلام يقود إلى أمره — إلى الأمر نفسه لا إلى قائمةٍ فيه.
     *
     * لا شاشةَ أمرٍ مفردة في النظام، وقائمةُ الأوامر تُصفَّح: رابطٌ يُنزل
     * التاجر في رأسها ويتركه يبحث بين عشرات الأوامر يَعِد ولا يفي. فيصل رقم
     * الأمر مع الرابط ويُملأ به حقل البحث، فتفتح الشاشة على أمرٍ واحد.
     */
    public function test_the_note_points_at_its_own_order_not_at_a_list(): void
    {
        $product = $this->product('صنف', 0, 0);
        $po = $this->order([['product' => $product, 'qty' => 3, 'cost' => 1]]);
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        // الورقة تحمل رقم أمرها — وعليه يقوم الرابط
        $notes = $this->actingAs($this->owner)
            ->get(route('admin.inventory.receipts'))->viewData('page')['props']['notes'];
        $this->assertSame($po->number, $notes[0]['order']);

        // والرقم يصل إلى الشاشة الأخرى فيُملأ به البحث
        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.orders', ['q' => $po->number]))->viewData('page')['props'];

        $this->assertSame($po->number, $props['q'], 'الرابط يصل والبحث لا يُملأ');
        $this->assertContains($po->number, collect($props['orders'])->pluck('number')->all());
    }

    /** وبلا رابطٍ لا بحثَ مفروضًا: الشاشة تُفتح على أوامرها كلّها */
    public function test_opening_the_orders_screen_plainly_filters_nothing(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.purchases.orders'))->viewData('page')['props'];

        $this->assertNull($props['q']);
    }

    /** والإشعار لا يُدخل البضاعة ثانيةً: الاستلام أدخلها، وهذا ورقتُه */
    public function test_the_note_does_not_move_stock_a_second_time(): void
    {
        $product = $this->product('صنف', 5, 1);
        $po = $this->order([['product' => $product, 'qty' => 10, 'cost' => 1]]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(15, (int) $product->fresh()->quantity);
        $this->assertSame(1, \App\Models\InventoryMovement::where('product_id', $product->id)->count());
    }
}
