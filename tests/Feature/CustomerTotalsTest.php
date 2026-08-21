<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إنفاق العميل: المُباع وحده، في كل شاشة.
 *
 * `withCount('orders')` تعدّ العلاقة كما هي فتتجاوز النطاق: كانت البطاقة فوق
 * الجدول تستثني الملغى وصفوفُه تحتها تجمعه — شاشةٌ واحدة تقول رقمين عن
 * العميل نفسه. ومن أُلغيت فاتورته بألف يبقى في القائمة «أنفق ١٠٠٠» بينما
 * صفحته وكشف حسابه يقولان مئة.
 */
class CustomerTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001',
        ]);

        $this->actingAs($this->owner);
    }

    private function order(string $status, float $total, bool $held = false): Order
    {
        return Order::create([
            'business_id' => $this->business->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 6, '0', STR_PAD_LEFT),
            'customer_id' => $this->customer->id, 'customer_name' => 'سالم',
            'branch' => 'الرئيسي', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'status' => $status, 'subtotal' => $total, 'discount' => 0, 'tax' => 0,
            'total' => $total, 'is_held' => $held, 'ordered_at' => now(),
        ]);
    }

    /** الصفّ كما يصل شاشة العملاء */
    private function row(): array
    {
        return $this->get(route('admin.customers.index'))
            ->assertOk()->viewData('page')['props']['customers'][0];
    }

    public function test_a_cancelled_invoice_is_not_spending(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $row = $this->row();

        $this->assertSame(100.0, $row['total_spent'], 'الملغى دخل إنفاق العميل');
        $this->assertSame(1, $row['orders']);
    }

    public function test_a_held_cart_is_not_spending_either(): void
    {
        $this->order('مكتمل', 100);
        $this->order('معلق', 900, held: true);

        $this->assertSame(100.0, $this->row()['total_spent']);
    }

    /** والبطاقة فوق الجدول والصفّ تحتها يقولان الرقم نفسه */
    public function test_the_card_and_the_row_agree(): void
    {
        $this->order('مكتمل', 250);
        $this->order('ملغي', 900);

        $props = $this->get(route('admin.customers.index'))->assertOk()->viewData('page')['props'];
        $card = collect($props['stats'])->firstWhere('label', __('إجمالي المشتريات'))['value'];

        $this->assertStringContainsString('250', (string) $card);
        $this->assertSame(250.0, $props['customers'][0]['total_spent']);
    }

    /** وآخر طلبٍ لا يكون طلبًا ملغى */
    public function test_the_last_order_date_ignores_a_cancelled_one(): void
    {
        $this->order('مكتمل', 100)->update(['ordered_at' => now()->subDays(5)]);
        $this->order('ملغي', 900)->update(['ordered_at' => now()]);

        $this->assertSame(now()->subDays(5)->format('Y-m-d'), $this->row()['last_order']);
    }

    /** والقائمة المشتركة (نقطة البيع والتصدير) تقول ما تقوله الشاشة */
    public function test_the_shared_list_says_the_same(): void
    {
        $this->order('مكتمل', 100);
        $this->order('ملغي', 900);

        $row = collect(Demo::customers())->firstWhere('id', $this->customer->id);

        $this->assertSame(100.0, $row['total_spent']);
        $this->assertSame(1, $row['orders']);
    }
}
