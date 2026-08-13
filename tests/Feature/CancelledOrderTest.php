<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلب الملغى ليس بيعًا — في كل شاشة.
 *
 * كان شرط الاستثناء يُكتب بيدٍ في كل استعلام على حدة، فكُتب في ثلاثة مواضع
 * ونُسي في أحدٍ وثلاثين: بطاقات التقارير تجمع الملغى والمخطّط تحتها يستثنيه،
 * فتقرأ الشاشةُ الواحدة رقمين متناقضين عن الفترة نفسها — ١٠٠٠ فوق و١٠٠
 * تحت. وأخطرها الإقرار الضريبي: ضريبةٌ تُقرّ على بيعةٍ أُلغيت.
 *
 * فصار موضعًا واحدًا (Order::scopeSold) يقرأ منه الجميع.
 */
class CancelledOrderTest extends TestCase
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
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 100, 'active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    private function order(string $status, float $total, float $tax = 0): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'زبون', 'employee_name' => 'سالم',
            'branch_id' => $this->branch->id, 'branch' => 'الرئيسي',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'status' => $status,
            'subtotal' => $total - $tax, 'discount' => 0, 'tax' => $tax, 'total' => $total,
            'is_held' => false, 'ordered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'صنف',
            'price' => $total, 'cost' => 60, 'quantity' => 1, 'total' => $total,
        ]);

        return $order;
    }

    /* ------------------------ الرقم الواحد في الشاشة ------------------------ */

    public function test_the_cards_and_the_chart_now_say_the_same_number(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $cards = Demo::reportSummary('all')['sales'];
        $chart = array_sum(array_filter(Demo::salesTrend('today')['data'], fn ($v) => $v !== null));

        $this->assertSame(100.0, $cards, 'البطاقات تجمع الملغى');
        $this->assertSame(100.0, $chart);
    }

    public function test_the_vat_return_does_not_declare_a_cancelled_sale(): void
    {
        // أخطرها: ضريبةٌ تُقرّ على بيعةٍ أُلغيت
        $this->order('مكتمل', 105, 5);
        $this->order('ملغي', 945, 45);

        $this->assertSame(5.0, round((float) Demo::vatReport('year')['output_vat'], 3));
    }

    public function test_the_dashboard_cards_leave_it_out_too(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        // البطاقة الأولى «مبيعات اليوم» — تُقرأ بموضعها لا باسمها لأن الاسم
        // يمرّ بالترجمة فيختلف بلغة الجلسة
        $today = Demo::adminStats()[0];

        $this->assertStringContainsString('100', (string) $today['value']);
        $this->assertStringNotContainsString('1,000', (string) $today['value']);
    }

    public function test_top_products_and_customers_leave_it_out(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $this->assertSame(100.0, (float) Demo::topSellingProducts(5, 'all')[0]['revenue']);
        $this->assertSame(100.0, (float) Demo::topProducts('all')[0]['total']);
    }

    public function test_payment_distribution_leaves_it_out(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $this->assertSame([100.0], Demo::paymentDistribution('all')['series']);
    }

    public function test_profit_does_not_count_a_cancelled_sale(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $this->assertSame(100.0, Demo::reportSummary('all')['profit']);
    }

    /* ------------------------------ الشاشة ------------------------------ */

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $rows = $this->get(route('admin.orders.index', ['status' => 'ملغي']))
            ->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
        $this->assertSame('ملغي', $rows[0]['status']);
    }

    public function test_the_list_can_be_filtered_by_a_date_range(): void
    {
        $this->order('مكتمل', 100)->update(['ordered_at' => now()->subDays(10)]);
        $this->order('مكتمل', 200);

        $rows = $this->get(route('admin.orders.index', [
            'from' => now()->subDays(3)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
    }

    public function test_the_screen_totals_what_was_filtered_not_what_was_shown(): void
    {
        $this->order('مكتمل', 100);
        $this->order('مكتمل', 250);
        $this->order('ملغي', 900);

        $props = $this->get(route('admin.orders.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(350.0, $props['totalAmount'], 'دخل الملغى مجموع المبيعات');
        $this->assertSame(3, $props['totalCount']);
        $this->assertSame(1, $props['cancelledCount']);
    }

    public function test_the_search_now_finds_the_cashier(): void
    {
        $this->order('مكتمل', 100);

        $rows = $this->get(route('admin.orders.index', ['q' => 'سالم']))
            ->assertOk()->viewData('page')['props']['orders'];

        $this->assertCount(1, $rows);
    }
}
