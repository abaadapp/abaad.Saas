<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryNoteAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $a;
    private Branch $b;

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
    }

    private function product(string $name, int $qty): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => $name,
            'price' => 10, 'cost' => 5, 'quantity' => $qty, 'alert_qty' => 1, 'active' => true,
        ]);
        // نصفٌ في كل فرع
        BranchStock::adjust($this->business->id, $this->a->id, $p->id, intdiv($qty, 2));
        BranchStock::adjust($this->business->id, $this->b->id, $p->id, $qty - intdiv($qty, 2));

        return $p;
    }

    private function send(array $items, array $extra = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.inventory.deliveries.store'), array_merge([
            'delivered_at' => now()->toDateString(),
            'items' => $items,
        ], $extra));
    }

    private function note(array $items, array $extra = []): DeliveryNote
    {
        $this->send($items, $extra)->assertSessionHasNoErrors();

        return DeliveryNote::latest('id')->firstOrFail();
    }

    private function order(Product $product, int $qty): Order
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'زبون']);
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-'.random_int(100000, 999999),
            'customer_id' => $customer->id, 'customer_name' => 'زبون', 'employee_name' => 'المالك',
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 10,
            'ordered_at' => now(), 'is_held' => false,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name,
            'price' => 10, 'quantity' => $qty, 'total' => 10 * $qty,
        ]);

        return $order->refresh();
    }

    /**
     * ١ — الكسر لا يمسّ رصيدًا يُعدّ بالوحدة.
     *
     * حقل الإشعار `decimal(12,3)` ورصيد المنتج عمودٌ صحيح. فكان الخصم يقصّ
     * الكسر: ورقةٌ تقول «٢٫٥ كجم» ورصيدٌ ينقص ٢، وورقةٌ تقول «٠٫٥» ورصيدٌ
     * لا ينقص شيئًا — بضاعةٌ تخرج ولا أثر لها في رقمٍ ولا في حركة.
     */
    public function test_a_fraction_of_a_stocked_item_is_refused(): void
    {
        $p = $this->product('سكّر', 100);

        foreach ([2.5, 0.5] as $qty) {
            $this->send([['product_id' => $p->id, 'name' => 'سكّر', 'quantity' => $qty, 'unit' => 'كجم']])
                ->assertSessionHasErrors('items');
        }

        $this->assertSame(100, (int) $p->fresh()->quantity, 'رُفض الإشعار وتحرّك الرصيد');
        $this->assertSame(0, DeliveryNote::count());
    }

    /** والكسر مأذونٌ لبندٍ بلا منتج: ليس في الجرد أصلًا فلا شيء يُقصّ */
    public function test_a_fraction_without_a_product_is_allowed(): void
    {
        $note = $this->note([['name' => 'رمل', 'quantity' => 2.5, 'unit' => 'متر']]);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.deliveries.deliver', $note->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('مُسلَّم', $note->fresh()->status);
    }

    /**
     * ٢ — «كل الفروع» عرضٌ لا مخزن.
     *
     * `currentBranchId` تُرجع null حينها، فكان الإشعار يُنشأ بلا فرع:
     * التسليم يُنقص الإجماليّ و`BranchStock::adjust` تنصرف عند null. فيفترق
     * الإجماليّ عن مجموع الفروع ولا يعودان يلتقيان.
     */
    public function test_the_total_never_parts_from_the_sum_of_branches(): void
    {
        $p = $this->product('صنف', 100);   // ٥٠ + ٥٠
        $note = $this->note([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 10]]);

        $this->assertNotNull($note->branch_id, 'أُنشئ الإشعار بلا فرعٍ يخرج منه');

        $this->actingAs($this->owner)->post(route('admin.inventory.deliveries.deliver', $note->id));

        $this->assertSame(90, (int) $p->fresh()->quantity);
        $this->assertSame(90, (int) BranchStock::where('product_id', $p->id)->sum('quantity'));
    }

    /**
     * ٣ — المتاح يُقاس بالفرع لا بالنشاط كلّه.
     *
     * كان الفحص على `products.quantity` وهو مجموع الفروع: ففرعٌ فارغ يُسلَّم
     * منه أربعون ما دام في الفرع الآخر مئة — يمرّ الفحص، ويهوي رصيد الفرع
     * إلى سالبٍ تبيع عليه نقطة البيع ما ليس عندها.
     */
    public function test_an_empty_branch_cannot_deliver_from_a_full_company(): void
    {
        $p = $this->product('صنف', 100);
        BranchStock::where('product_id', $p->id)->where('branch_id', $this->b->id)->update(['quantity' => 0]);
        BranchStock::where('product_id', $p->id)->where('branch_id', $this->a->id)->update(['quantity' => 100]);

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
            ->post(route('admin.inventory.deliveries.store'), [
                'delivered_at' => now()->toDateString(),
                'items' => [['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 40]],
            ])->assertSessionHasNoErrors();

        $note = DeliveryNote::latest('id')->firstOrFail();

        $this->assertSame($this->b->id, (int) $note->branch_id);

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->b->id])
            ->post(route('admin.inventory.deliveries.deliver', $note->id))
            ->assertSessionHasErrors('deliver');

        $this->assertSame(0, (int) BranchStock::where('product_id', $p->id)
            ->where('branch_id', $this->b->id)->value('quantity'), 'رصيد الفرع صار سالبًا');
        $this->assertSame(100, (int) $p->fresh()->quantity);
    }

    /**
     * ٤ — أوسع ثغرةٍ في الشاشة.
     *
     * المربوط بطلبٍ لا يمسّ المخزون — البضاعة خرجت يوم البيع. وكان الربط بلا
     * فحص: تختار طلبًا ثمّ تكتب مئةً من صنفٍ ليس فيه، فتخرج البضاعة بورقةٍ
     * موقّعة ولا يُنقص منها شيء — لا من الإجماليّ ولا من الفرع ولا في سجلّ
     * الحركات. لا تُكتشف إلا في جردٍ بعد أشهر.
     */
    public function test_a_note_tied_to_an_order_cannot_carry_what_it_does_not_hold(): void
    {
        $p = $this->product('صنف', 100);
        $order = $this->order($p, 3);

        $this->send([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 100]], ['order_id' => $order->id])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, DeliveryNote::count());
        $this->assertSame(100, (int) $p->fresh()->quantity);
    }

    /** وما في الطلب يمرّ — ولا يُنقص المخزون ثانيةً */
    public function test_a_note_within_the_order_passes_and_moves_no_stock(): void
    {
        $p = $this->product('صنف', 100);
        $order = $this->order($p, 3);

        $note = $this->note([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 3]], ['order_id' => $order->id]);
        $this->actingAs($this->owner)->post(route('admin.inventory.deliveries.deliver', $note->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('مُسلَّم', $note->fresh()->status);
        $this->assertSame(100, (int) $p->fresh()->quantity, 'المربوط بطلبٍ أنقص المخزون مرّةً ثانية');
    }

    /** وطلبٌ من ثلاثة لا يُسلَّم بإشعارين كلٌّ منهما ثلاثة */
    public function test_the_same_order_is_not_delivered_twice_over(): void
    {
        $p = $this->product('صنف', 100);
        $order = $this->order($p, 3);

        $this->note([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 2]], ['order_id' => $order->id]);

        $this->send([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 2]], ['order_id' => $order->id])
            ->assertSessionHasErrors('items');
    }

    /** ولا يُربط بطلبٍ معلّق: بضاعتُه لم تُنقص، فالإعفاء من الخصم بلا خصمٍ سابق */
    public function test_a_held_order_cannot_exempt_a_note_from_moving_stock(): void
    {
        $p = $this->product('صنف', 100);
        $order = $this->order($p, 3);
        $order->update(['is_held' => true]);

        $this->send([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 3]], ['order_id' => $order->id])
            ->assertSessionHasErrors('items');
    }

    /** ٥ — نقرةٌ مزدوجة لا تُخرج البضاعة مرّتين من إشعارٍ واحد */
    public function test_delivering_twice_moves_stock_once(): void
    {
        $p = $this->product('صنف', 100);
        $note = $this->note([['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 10]]);

        $this->actingAs($this->owner)->post(route('admin.inventory.deliveries.deliver', $note->id));
        $this->actingAs($this->owner)->post(route('admin.inventory.deliveries.deliver', $note->id))
            ->assertSessionHasErrors('deliver');

        $this->assertSame(90, (int) $p->fresh()->quantity);
        $this->assertSame(1, \App\Models\InventoryMovement::where('product_id', $p->id)->count());
    }
}
