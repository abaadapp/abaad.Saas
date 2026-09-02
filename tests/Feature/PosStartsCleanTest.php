<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * بيعةٌ تمّت فبدايةٌ نظيفة — والزبون معها.
 *
 * كانت السلّة تُفرَغ ويبقى الزبون معلّقًا في الشاشة: يمشي ويقف غيره،
 * فتُقيَّد البيعةُ التالية عليه **وتُضاف نقاطُ ولائه إلى من لم يشترِ**.
 * والنقاط مالٌ يُستبدَل، ولا يُكتشف ذلك إلا حين يسأل صاحبها عن رصيدٍ ناقص.
 *
 * وشاشة العملاء تقول للكاشير رقمَ آخر فاتورة: يُسأل عنها والزبون واقفٌ
 * أمامه، وتاريخٌ بلا رقم لا يفتح شيئًا.
 */
class PosStartsCleanTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->actingAs($this->cashier);
    }

    private function sell(Customer $customer): Order
    {
        $this->postJson(route('pos.checkout'), [
            'client_uuid' => uniqid('s', true),
            'payment_method' => 'نقدي',
            'customer' => $customer->name,
            'customer_id' => $customer->id,
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'price' => 10, 'qty' => 1]],
        ])->assertOk()->assertJsonPath('ok', true);

        return Order::latest('id')->firstOrFail();
    }

    private function customer(string $name): Customer
    {
        return Customer::create(['business_id' => $this->business->id, 'name' => $name]);
    }

    /* --------------------- الشاشة تبدأ نظيفة بعد البيع --------------------- */

    /**
     * الشاشة تُنظَّف في المتصفّح، فالحارس على مصدرها.
     *
     * و`clear` تبقى على حالها عمدًا: هي زرّ «إفراغ السلّة»، ومن يُلغي أصنافًا
     * لا يُلغي الزبون الواقف أمامه.
     */
    public function test_a_finished_sale_resets_the_customer_not_only_the_cart(): void
    {
        $hook = file_get_contents(resource_path('js/hooks/usePosCart.ts'));

        $this->assertMatchesRegularExpression(
            '/const reset = useCallback\(\(\) => \{\s*clear\(\);\s*setCustomer\(CASH_CUSTOMER\);\s*setCustomerId\(null\);/u',
            $hook,
            'بدايةٌ جديدة لا تُعيد الزبون إلى «عميل نقدي»',
        );

        $screen = file_get_contents(resource_path('js/Pages/Pos/Index.tsx'));

        $this->assertStringContainsString('onNewOrder={() => { cart.reset();', $screen);
        $this->assertStringNotContainsString('onNewOrder={() => { cart.clear();', $screen);
    }

    /** وأيًّا كان سبيلُ إغلاق نافذة الدفع — لا زرّ «طلب جديد» وحده */
    public function test_closing_the_paid_dialog_any_way_starts_the_next_order(): void
    {
        $dialog = file_get_contents(resource_path('js/Pages/Pos/partials/PaymentDialog.tsx'));

        $this->assertMatchesRegularExpression(
            "/const closeTo = \(next: boolean\) => \{\s*if \(!next && step === 'success'\) \{\s*onNewOrder\(\);/u",
            $dialog,
        );
        $this->assertStringContainsString('<Dialog open={open} onOpenChange={closeTo}>', $dialog);
    }

    /* ------------------------- آخر فاتورة في العملاء ------------------------- */

    public function test_the_customers_screen_carries_the_last_invoice(): void
    {
        $zahra = $this->customer('زهرة');
        $first = $this->sell($zahra);
        $second = $this->sell($zahra);

        $rows = collect(Demo::customers())->keyBy('id');

        $this->assertSame($second->number, $rows[$zahra->id]['last_invoice']);
        $this->assertNotSame($first->number, $rows[$zahra->id]['last_invoice']);
        $this->assertSame((float) $second->total, $rows[$zahra->id]['last_invoice_total']);
    }

    public function test_a_customer_who_never_bought_shows_nothing(): void
    {
        $jadid = $this->customer('جديد');

        $rows = collect(Demo::customers())->keyBy('id');

        $this->assertNull($rows[$jadid->id]['last_invoice']);
        $this->assertNull($rows[$jadid->id]['last_invoice_total']);
    }

    /** والمعلَّقة ليست فاتورة: سلّةٌ لم تُبَع بعد */
    public function test_a_held_basket_is_not_the_last_invoice(): void
    {
        $zahra = $this->customer('زهرة');
        $sold = $this->sell($zahra);

        Order::create([
            'business_id' => $this->business->id, 'customer_id' => $zahra->id,
            'number' => 'HELD-1', 'customer_name' => 'زهرة', 'total' => 999,
            'is_held' => true, 'status' => 'معلق', 'ordered_at' => now()->addHour(),
        ]);

        $rows = collect(Demo::customers())->keyBy('id');

        $this->assertSame($sold->number, $rows[$zahra->id]['last_invoice']);
    }

    /** ولا فاتورةُ جارٍ تصل شاشة أحد */
    public function test_a_neighbours_invoice_never_appears(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $theirCustomer = Customer::create(['business_id' => $other->id, 'name' => 'زبونهم']);
        Order::create([
            'business_id' => $other->id, 'customer_id' => $theirCustomer->id,
            'number' => 'JAAR-1', 'customer_name' => 'زبونهم', 'total' => 50,
            'is_held' => false, 'status' => 'مكتمل', 'ordered_at' => now(),
        ]);

        $numbers = collect(Demo::customers())->pluck('last_invoice')->filter()->all();

        $this->assertNotContains('JAAR-1', $numbers);
    }

    /** والشاشة تفتحها بضغطة — لا تعرض رقمًا لا يُفتح */
    public function test_the_screen_links_the_number_to_the_invoice(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Pos/Customers.tsx'));

        $this->assertStringContainsString("route('pos.order-details', c.last_invoice)", $screen);
        $this->assertNotNull(Route::getRoutes()->getByName('pos.order-details'));
    }
}
