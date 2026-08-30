<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مستندا المخزون: تعديلٌ يُقيَّد، وإشعارٌ لا يُخرج البضاعة مرّتين.
 *
 * التعديل ليس تصحيحًا للرقم وحده — قطعةٌ تلفت مالٌ ضاع. والاكتفاء بتنقيص
 * العدد يُبقي قيمة المخزون في الميزانية كما كانت، فيظهر المتجر أغنى ممّا هو
 * بقيمة كلّ ما تلف عنده.
 *
 * وإشعار التسليم خطرُه معكوس: بضاعةٌ بيعت خرجت من الرصيد يوم البيع، فإشعارٌ
 * يُنقصها ثانيةً يُخرج الصنف مرّتين من رصيدٍ واحد.
 */
class InventoryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $product;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'شاي', 'sku' => 'TEA-1',
            'price' => 5, 'cost' => 2, 'quantity' => 100,
        ]);

        $this->actingAs($owner);
        $this->get(route('admin.inventory.adjustments'));
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /* --------------------------- تعديلات المخزون --------------------------- */

    private function adjust(array $overrides = [])
    {
        return $this->post(route('admin.inventory.adjustments.store'), array_merge([
            // الفرع صريحٌ في العقد منذ FIX-BATCH-001: التعديل يقع على رصيد فرع
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity_delta' => -10,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ], $overrides));
    }

    public function test_a_loss_lowers_the_stock_and_books_the_expense(): void
    {
        $this->adjust()->assertSessionHasNoErrors();

        $this->assertSame(90, (int) $this->product->fresh()->quantity);
        // عشر قطعٍ بتكلفة اثنين = عشرون خسارة
        $this->assertSame(20.0, Ledger::account($this->bid(), 'other_expenses')->balance());
        $this->assertSame(-20.0, Ledger::account($this->bid(), 'inventory')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_a_found_item_raises_the_stock_and_reverses_the_expense(): void
    {
        $this->adjust(['quantity_delta' => 5, 'reason' => 'جرد'])->assertSessionHasNoErrors();

        $this->assertSame(105, (int) $this->product->fresh()->quantity);
        $this->assertSame(10.0, Ledger::account($this->bid(), 'inventory')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_the_cost_is_copied_at_the_moment_not_read_later(): void
    {
        /*
         * تكلفة المنتج متوسّطٌ يتحرّك مع كل شراء، فقراءتها اليوم عن تلفٍ وقع
         * قبل سنة تُعطي رقمًا لم يقع.
         */
        $this->adjust();
        $this->product->update(['cost' => 9]);

        $this->assertSame(2.0, (float) StockAdjustment::first()->cost_at_time);
        $this->assertSame(-20.0, StockAdjustment::first()->valueImpact());
    }

    public function test_stock_cannot_be_driven_below_zero(): void
    {
        /*
         * رصيدٌ سالب يُفسد كلّ ما يُبنى عليه: قيمة المخزون تصير سالبة،
         * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطة البيع تبيع ما ليس عندها.
         */
        $this->adjust(['quantity_delta' => -500])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_an_adjustment_of_zero_changes_nothing_and_is_refused(): void
    {
        $this->adjust(['quantity_delta' => 0])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_another_stores_product_cannot_be_adjusted(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'سكر', 'price' => 3, 'cost' => 1, 'quantity' => 50,
        ]);

        $this->adjust(['product_id' => $theirs->id])->assertSessionHasErrors('product_id');

        $this->assertSame(50, (int) $theirs->fresh()->quantity);
    }

    /* --------------------------- إشعار التسليم --------------------------- */

    private function note(array $overrides = [])
    {
        return $this->post(route('admin.inventory.deliveries.store'), array_merge([
            'delivered_at' => now()->toDateString(),
            'recipient' => 'أبو سالم',
            'items' => [
                ['product_id' => $this->product->id, 'name' => 'شاي', 'quantity' => 4],
            ],
        ], $overrides));
    }

    public function test_a_standalone_note_lowers_the_stock_when_delivered(): void
    {
        // شحنةٌ تخرج بلا بيعٍ مسجَّل — وبلا إنقاصٍ تخرج البضاعة ولا أثر لها
        $this->note()->assertSessionHasNoErrors();
        $note = DeliveryNote::first();

        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'أُنقص المخزون قبل التسليم');

        $this->post(route('admin.inventory.deliveries.deliver', $note->id))->assertSessionHasNoErrors();

        $this->assertSame(96, (int) $this->product->fresh()->quantity);
        $this->assertSame('مُسلَّم', $note->fresh()->status);
    }

    public function test_a_note_tied_to_an_order_does_not_lower_the_stock_again(): void
    {
        /*
         * أهمّ ما في هذا الملفّ: البضاعة خرجت من المخزون يوم البيع (نقطة البيع
         * تُنقص الكمية عند الدفع)، فالإشعار ورقةٌ تُطبع وتُوقَّع لا غير.
         */
        $order = Order::create([
            'business_id' => $this->bid(), 'number' => 'ORD-1', 'status' => 'مكتمل',
            'total' => 20, 'created_at' => now(),
        ]);

        /*
         * والطلب يحمل ما يحمله الإشعار.
         *
         * كان يُنشأ هنا بلا بنودٍ أصلًا، والإشعار يدّعي أربعةً من شاي —
         * فيمرّ. وهو الثغرة بعينها لا اختبارُها: طلبٌ لم يبع شيئًا يُعفي
         * الإشعار من الخصم، فتخرج البضاعة بورقةٍ موقّعة ولا يُنقص منها شيء.
         */
        $order->items()->create([
            'product_id' => $this->product->id, 'name' => 'شاي',
            'price' => 5, 'quantity' => 4, 'total' => 20,
        ]);

        $this->note(['order_id' => $order->id])->assertSessionHasNoErrors();
        $note = DeliveryNote::first();

        $this->post(route('admin.inventory.deliveries.deliver', $note->id))->assertSessionHasNoErrors();

        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'خرج الصنف مرّتين من رصيدٍ واحد');
        $this->assertSame('مُسلَّم', $note->fresh()->status);
    }

    public function test_delivering_twice_does_not_take_the_stock_twice(): void
    {
        $this->note();
        $note = DeliveryNote::first();

        $this->post(route('admin.inventory.deliveries.deliver', $note->id));
        $this->post(route('admin.inventory.deliveries.deliver', $note->id));

        $this->assertSame(96, (int) $this->product->fresh()->quantity);
    }

    public function test_delivering_more_than_is_in_stock_is_refused(): void
    {
        // وإشعارٌ يُسلَّم بكميةٍ لا وجود لها يترك رصيدًا سالبًا تبيع عليه نقطة البيع
        $this->note([
            'items' => [['product_id' => $this->product->id, 'name' => 'شاي', 'quantity' => 400]],
        ]);
        $note = DeliveryNote::first();

        $this->post(route('admin.inventory.deliveries.deliver', $note->id))->assertSessionHasErrors('deliver');

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame('مسودة', $note->fresh()->status);
    }

    public function test_a_delivered_note_is_neither_cancelled_nor_deleted(): void
    {
        // البضاعة عند العميل، ولا تعود بإلغاء ورقة
        $this->note();
        $note = DeliveryNote::first();
        $this->post(route('admin.inventory.deliveries.deliver', $note->id));

        $this->post(route('admin.inventory.deliveries.cancel', $note->id));
        $this->assertSame('مُسلَّم', $note->fresh()->status);

        $this->delete(route('admin.inventory.deliveries.destroy', $note->id));
        $this->assertNotNull(DeliveryNote::find($note->id));
    }

    public function test_the_note_creates_no_ledger_entry(): void
    {
        /*
         * مستند حركةٍ لا مستند مال: الذمّة نشأت بالفاتورة، وخلطُهما يُحمّل
         * العميل مرّتين.
         */
        $customer = Customer::create(['business_id' => $this->bid(), 'name' => 'زبون']);

        $this->note(['customer_id' => $customer->id]);
        $note = DeliveryNote::first();
        $this->post(route('admin.inventory.deliveries.deliver', $note->id));

        $this->assertSame(0, JournalEntry::where('business_id', $this->bid())->count());
        $this->assertSame(0.0, Ledger::account($this->bid(), 'receivable')?->balance() ?? 0.0);
    }
}
