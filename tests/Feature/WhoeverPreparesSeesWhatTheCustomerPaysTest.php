<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من يملك قسمَ «لوحة التجهيز» وحده يرى ما يدفعه الزبون — ولا يرى ما كلّف.
 *
 * ═══ ما يُحرس ═══
 *
 * الصلاحيةُ صلاحيةُ القسم نفسِه: موظّفٌ مُنح `preparation` وحده — بلا
 * «المبيعات» ولا «المالية» — يرى إجماليَّ الطلب وحالَ دفعه ووسيلتَه وسعرَ
 * كلّ بندٍ وإجماليَّه (قرارُ المالك 2026-10-02). ومن لم يُمنحه لا يفتح
 * اللوحة. ولا يصل أحدًا عليها تكلفةٌ ولا ربحٌ ولا هامش. والإلغاءُ إذنٌ
 * منفصل (`PREPARATION_CANCEL`) لا يأتي مع القسم.
 */
class WhoeverPreparesSeesWhatTheCustomerPaysTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);
        $this->travelTo(today()->setTime(9, 0));
    }

    private function staff(array $permissions, string $email = 'p@abaad.om'): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'بطاقة', 'payment_status' => 'مدفوع',
            'subtotal' => 50, 'total' => 52.5, 'tax' => 2.5, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 2, 'total' => 50,
        ]);

        return $order;
    }

    private function card(User $who, Order $order): array
    {
        $cards = $this->actingAs($who)->get(route('admin.preparation.index'))
            ->assertOk()->viewData('page')['props']['orders'];

        $card = collect($cards)->firstWhere('number', $order->number);
        $this->assertNotNull($card, 'الطلبُ ليس على اللوحة');

        return $card;
    }

    public function test_the_preparation_section_alone_shows_what_the_customer_pays(): void
    {
        $prep = $this->staff(['preparation']);
        $this->assertFalse($prep->allows('orders'));
        $this->assertFalse($prep->allows('finance'));

        $card = $this->card($prep, $this->order());

        $this->assertSame(52.5, $card['total']);
        $this->assertSame('مدفوع', $card['payment_status']);
        $this->assertSame('بطاقة', $card['payment_method']);
        $this->assertSame(25.0, $card['items'][0]['price']);
        $this->assertSame(50.0, $card['items'][0]['total']);
        $this->assertSame(2, $card['items'][0]['qty']);
    }

    public function test_without_the_preparation_section_the_board_does_not_open(): void
    {
        $other = $this->staff(['dashboard', 'orders', 'finance']);

        $this->actingAs($other)->get(route('admin.preparation.index'))->assertForbidden();
    }

    public function test_no_cost_profit_or_margin_reaches_the_board(): void
    {
        $this->order();

        $page = $this->actingAs($this->staff(['preparation']))->get(route('admin.preparation.index'))->assertOk();
        $payload = json_encode($page->viewData('page')['props']['orders'], JSON_UNESCAPED_UNICODE);

        foreach (['cost', 'purchase_cost', 'unit_cost', 'profit', 'margin', 'gross', 'cogs'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $payload, "«{$forbidden}» وصل لوحةَ التجهيز");
        }
        // ولا قيمةُ التكلفة نفسُها تحت اسمٍ آخر: كلّف البندُ ١٠ وبيع بـ٢٥
        $this->assertStringNotContainsString(':10,', $payload);
        $this->assertStringNotContainsString(':10.0', $payload);
        $this->assertStringNotContainsString(':20,', $payload);
    }

    public function test_the_section_does_not_bring_the_right_to_cancel(): void
    {
        $prep = $this->staff(['preparation']);
        $order = $this->order();

        $this->assertFalse($prep->may(Permissions::PREPARATION_CANCEL));
        $this->assertNotContains(OrderStatus::CANCELLED, $this->card($prep, $order)['next']);

        $this->actingAs($prep)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::CANCELLED]);
        $this->assertNotSame(OrderStatus::CANCELLED, $order->fresh()->status, 'أُلغي الطلبُ بلا إذن الإلغاء');
    }
}
