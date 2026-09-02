<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دورةُ البيع تقع مرّةً — ولو وصل الطلب مرّتين.
 *
 * وكلّ ما هنا فحصٌ يسبق كتابةً، وبينهما فرجة: يُسأل «هل وقع؟» فيُقال لا،
 * ثمّ يُكتب. وطلبان يصلان معًا يقرآن «لا» كلاهما. والنتيجة ليست ورقةً
 * زائدة: بضاعةٌ تُخصم مرّتين، ودخلٌ يُقيَّد مرّتين، ورفٌّ يزيد بلا سبب.
 */
class SaleHappensOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    private function checkout(array $extra = [])
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 2]],
            'payment_method' => 'نقدي',
        ], $extra));
    }

    /* ------------------- مفتاحُ الصمود ------------------- */

    public function test_the_same_key_twice_returns_the_first_invoice(): void
    {
        $first = $this->checkout(['client_uuid' => 'u-1'])->assertOk();
        $second = $this->checkout(['client_uuid' => 'u-1'])->assertOk();

        $this->assertSame($first->json('invoice'), $second->json('invoice'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(98, (int) $this->product->fresh()->quantity);
    }

    /**
     * والقيد في القاعدة هو ما يمنع فعلًا — لا الفحص وحده.
     *
     * الفهرس كان عاديًّا للبحث لا للمنع، فلا شيء تحت الفحص يسنده.
     */
    public function test_the_database_itself_refuses_a_second_row_with_the_same_key(): void
    {
        $this->checkout(['client_uuid' => 'u-2'])->assertOk();
        $first = Order::firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        Order::create($first->only([
            'business_id', 'customer_name', 'branch_id', 'status', 'payment_method',
            'subtotal', 'total',
        ]) + ['number' => 'INV-OTHER', 'client_uuid' => 'u-2']);
    }

    /**
     * ومن خسر السباق يُردّ إليه رقمُ الفاتورة الأولى — لا خطأُ خادم.
     *
     * ولا يُحاكى السباق هنا: الرابح يجب أن يكون قد أودع صفَّه فعلًا، ومعاملةُ
     * الخاسر حين تتراجع تمحو معها أيّ صفٍّ كُتب داخلها — فأيّ محاكاةٍ في
     * الذاكرة تمحو رابحها بيدها. فيبقى الحارس مقروءًا من مصدره: التقاطُ
     * انكسار القيد، والردّ بفاتورة التوأم بدل خمسمئة.
     */
    public function test_the_racer_who_lost_is_handed_the_winners_invoice(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Pos/PosController.php'));

        // يُلتقط الموضع باسم الصنف لا بمساره الكامل: المنسّق يختصره إلى
        // `use` فيصير البحث عن النصّ الطويل لا يجد شيئًا، وتمرّ الأسطر
        // الأربعة أدناه على أوّل سبعمئة حرفٍ من الملفّ بلا معنى
        $at = strpos($source, 'catch (QueryException');
        $this->assertNotFalse($at, 'لم يعد انكسار القيد يُلتقط عند إتمام البيع');

        $catch = substr($source, $at, 700);

        $this->assertStringContainsString('client_uuid', $catch);
        $this->assertStringContainsString('$twin->number', $catch);
        $this->assertStringContainsString("'duplicate' => true", $catch);
        $this->assertStringContainsString('throw $e;', $catch);
    }

    /* ------------------- السلّة المعلّقة ------------------- */

    private function hold(): int
    {
        $this->postJson(route('pos.hold'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 2]],
        ])->assertOk();

        return (int) Order::where('is_held', true)->value('id');
    }

    public function test_a_held_cart_cannot_become_two_invoices(): void
    {
        $held = $this->hold();

        $this->checkout(['resume_id' => $held])->assertOk();
        // جهازان يستأنفان السلّة نفسها — وهي معروضةٌ على كلّ أجهزة الفرع
        $this->checkout(['resume_id' => $held])->assertStatus(422);

        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(98, (int) $this->product->fresh()->quantity);
    }

    public function test_a_sale_without_a_held_cart_is_untouched(): void
    {
        $this->checkout()->assertOk();

        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    /* ------------------- الإلغاء يقع مرّة ------------------- */

    private function soldOrder(): Order
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001',
        ]);
        Coupon::create([
            'business_id' => $this->business->id, 'code' => 'WELCOME', 'type' => 'نسبة',
            'value' => 10, 'active' => true, 'used_count' => 0, 'min_order' => 0,
        ]);

        $this->checkout([
            'customer' => 'سالم', 'customer_id' => $customer->id, 'coupon_code' => 'WELCOME',
        ])->assertOk();

        return Order::where('is_held', false)->firstOrFail();
    }

    /**
     * الضغطتان على «إلغاء» — أو موظّفان يفتحان الطلب نفسه.
     *
     * كلٌّ منهما يحمل نسخته من الصفّ قُرئت قبل المعاملة، فتقرأ «مكتمل»
     * وتدخل. والقفل وحده يُصفّهما.
     */
    public function test_a_stale_second_cancel_does_not_return_the_stock_twice(): void
    {
        $order = $this->soldOrder();
        $stockAfterSale = (int) $this->product->fresh()->quantity;

        $stale = Order::findOrFail($order->id);   // قراءةٌ ثانية قبل الإلغاء
        OrderCorrection::cancel($order);
        OrderCorrection::cancel($stale);

        $this->assertSame($stockAfterSale + 2, (int) $this->product->fresh()->quantity);
    }

    public function test_a_stale_second_cancel_does_not_return_the_coupon_twice(): void
    {
        $order = $this->soldOrder();
        $stale = Order::findOrFail($order->id);

        OrderCorrection::cancel($order);
        OrderCorrection::cancel($stale);

        $this->assertSame(0, (int) Coupon::where('code', 'WELCOME')->value('used_count'));
    }

    public function test_a_stale_second_cancel_writes_one_audit_line_only(): void
    {
        $order = $this->soldOrder();
        $stale = Order::findOrFail($order->id);

        OrderCorrection::cancel($order);
        OrderCorrection::cancel($stale);

        $this->assertSame(1, \App\Models\OrderEdit::where('order_id', $order->id)
            ->where('kind', \App\Models\OrderEdit::CANCEL)->count());
    }

    public function test_cancelling_once_still_does_everything_it_should(): void
    {
        $order = $this->soldOrder();
        $stockAfterSale = (int) $this->product->fresh()->quantity;

        OrderCorrection::cancel($order);

        $this->assertSame($stockAfterSale + 2, (int) $this->product->fresh()->quantity);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
        $this->assertSame(0, (int) Coupon::where('code', 'WELCOME')->value('used_count'));
    }

    /* ------------------- حدّ المتجر ------------------- */

    public function test_the_key_belongs_to_its_own_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-THEIRS', 'client_uuid' => 'u-9',
            'customer_name' => 'غريب', 'status' => OrderStatus::COMPLETED,
            'payment_method' => 'نقدي', 'subtotal' => 5, 'total' => 5, 'is_held' => false,
        ]);

        // مفتاحُ متجرٍ آخر لا يُبطل بيعةً هنا ولا يُعيد فاتورتهم
        $response = $this->checkout(['client_uuid' => 'u-9'])->assertOk();

        $this->assertNotSame('INV-THEIRS', $response->json('invoice'));
    }
}
