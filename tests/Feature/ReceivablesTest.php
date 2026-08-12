<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البيع الآجل والذمم.
 *
 * كان `payment_status = 'مدفوع'` مثبّتًا في الكود لكلّ بيعة: لا بيعَ على
 * الحساب، ولا كشفَ «من عليه لي». فبائع الجملة يبيع في النظام ويمسك دفترًا
 * على الورق ليعرف من عليه — وهو نصف نظام.
 */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'pay_credit', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'مطعم البحر', 'phone' => '99112233',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 100, 'active' => true,
        ]);

        $this->openShiftFor($this->business->id);
    }

    private function sellOnCredit(int $qty = 1, string $uuid = 'c-1')
    {
        return $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => 'آجل',
            'customer_id' => $this->customer->id,
            'client_uuid' => $uuid,
        ]);
    }

    public function test_a_credit_sale_is_recorded_as_owed_not_paid(): void
    {
        $this->sellOnCredit(3)->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame('آجل', $order->payment_status);
        $this->assertSame(0.0, (float) $order->paid_amount);
        $this->assertSame(30.0, $order->outstanding());
    }

    public function test_credit_is_refused_when_the_merchant_did_not_enable_it(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'pay_credit')->update(['value' => '0']);

        $this->sellOnCredit()->assertOk();

        $order = Order::latest('id')->first();
        $this->assertSame('نقدي', $order->payment_method, 'بيعٌ على الحساب دون إذن صاحب النشاط');
        $this->assertSame('مدفوع', $order->payment_status);
    }

    public function test_credit_falls_back_to_cash_for_a_walk_in(): void
    {
        /*
         * دَينٌ بلا اسمٍ لا يُحصَّل. والرفض عند الدفع يوقف طابورًا، فيمرّ
         * البيع نقديًّا — والنقد هو الأصل.
         */
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'آجل',
        ])->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame('نقدي', $order->payment_method);
        $this->assertSame(10.0, (float) $order->paid_amount);
    }

    public function test_a_cash_sale_is_paid_in_full_at_once(): void
    {
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 2]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame(20.0, (float) $order->paid_amount);
        $this->assertSame(0.0, $order->outstanding());
    }

    public function test_the_receivables_screen_shows_who_owes_what(): void
    {
        $this->sellOnCredit(3, 'c-1');
        $this->sellOnCredit(2, 'c-2');

        $props = $this->actingAs($this->owner)->get(route('admin.receivables.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(50.0, $props['summary']['total']);
        $this->assertSame(1, $props['summary']['customers']);
        $this->assertSame(2, $props['customers'][0]['invoices']);
    }

    public function test_a_payment_settles_the_oldest_invoice_first(): void
    {
        $this->sellOnCredit(3, 'c-1');   // 30
        $this->sellOnCredit(2, 'c-2');   // 20
        $oldest = Order::orderBy('id')->first();

        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 30, 'method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $oldest->fresh()->outstanding(), 'لم تُسدَّد الأقدم أوّلًا');
        $this->assertSame('مدفوع', $oldest->fresh()->payment_status);
        $this->assertSame(20.0, Order::orderByDesc('id')->first()->outstanding());
    }

    public function test_a_partial_payment_leaves_the_rest_owed(): void
    {
        $this->sellOnCredit(3);

        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 12, 'method' => 'نقدي',
        ]);

        $order = Order::latest('id')->first();

        $this->assertSame(12.0, (float) $order->paid_amount);
        $this->assertSame(18.0, $order->outstanding());
        $this->assertSame('آجل', $order->payment_status, 'اعتُبرت مسدَّدة وقد بقي منها');
    }

    public function test_paying_more_than_the_debt_is_refused(): void
    {
        // رصيدٌ دائنٌ لا شاشة له يضيع — فالرفض أصدق من قبولٍ لا أثر له
        $this->sellOnCredit(1);

        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 50, 'method' => 'نقدي',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0.0, (float) Order::latest('id')->first()->paid_amount);
    }

    public function test_a_collection_is_not_counted_as_a_new_sale(): void
    {
        /*
         * البيعة الآجلة قُيّدت دخلًا يوم وقعت. فلو قُيّد التحصيل دخلًا آخر
         * لظهر البيع مرّتين — ولبدا شهرُ التحصيل أعظم شهورِ المتجر.
         */
        $this->sellOnCredit(3);

        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 30, 'method' => 'نقدي',
        ]);

        $this->assertSame(1, Transaction::where('type', 'دخل')->count());
        $this->assertSame(1, Transaction::where('type', 'تحصيل')->count());
    }

    public function test_a_payment_is_kept_as_its_own_record(): void
    {
        $this->sellOnCredit(3);

        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 10, 'method' => 'تحويل بنكي', 'note' => 'حوالة',
        ]);
        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $this->customer->id), [
            'amount' => 5, 'method' => 'نقدي',
        ]);

        // دفعتان لا رقمٌ واحد مكتوبٌ فوق الآخر: متى دفع وكم في كل مرّة
        $this->assertSame(2, CustomerPayment::count());
        $this->assertSame(15.0, (float) Order::latest('id')->first()->paid_amount);
    }

    public function test_another_stores_customer_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->actingAs($this->owner)->get(route('admin.receivables.show', $theirs->id))->assertNotFound();
        $this->actingAs($this->owner)->post(route('admin.receivables.pay', $theirs->id), [
            'amount' => 5, 'method' => 'نقدي',
        ])->assertNotFound();
    }

    public function test_an_overdue_debt_is_flagged(): void
    {
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'آجل',
            'customer_id' => $this->customer->id,
            'due_at' => now()->subWeek()->toDateString(),
        ])->assertOk();

        $props = $this->actingAs($this->owner)->get(route('admin.receivables.index'))
            ->viewData('page')['props'];

        $this->assertTrue($props['customers'][0]['overdue']);
        $this->assertSame(10.0, $props['summary']['overdue']);
    }
}
