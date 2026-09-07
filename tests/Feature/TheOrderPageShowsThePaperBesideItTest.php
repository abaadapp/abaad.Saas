<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحةُ الطلب — البياناتُ والورقةُ جنبًا إلى جنب، وأزرارُ الورقة تعمل.
 *
 * وكان تبويبان: من يراجع فاتورةً أمام زبونٍ يبدّل ذهابًا وإيابًا بين ما
 * يقوله النظام وما يقوله الورق.
 *
 * والمعروضُ هو الملفُّ نفسُه لا نسخةٌ منه مرسومةٌ في الشاشة — ونسخةٌ ثانيةٌ
 * تفترق عن أصلها يومًا، فيرى التاجرُ في اللوحة غيرَ ما يقرؤه الزبون في يده.
 * فهذه الحالاتُ تحرس أنّ الملفَّ يُفتح، وأنّه يُعرض داخل الصفحة لا يُنزَّل.
 */
class TheOrderPageShowsThePaperBesideItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '91234567',
        ]);
    }

    private function order(array $extra = []): Order
    {
        return Order::create($extra + [
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-000900',
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25,
            'ordered_at' => now(),
        ]);
    }

    /* ----------------------------- الورقة ----------------------------- */

    /**
     * الملفُّ يُعرض داخل الصفحة لا يُنزَّل.
     *
     * والفرقُ رأسٌ واحد: `attachment` يجعل المتصفّح يحفظه، والإطارُ المدمج
     * يبقى أبيضَ فارغًا. فالمعاينةُ كلُّها معلّقةٌ على `inline`.
     */
    public function test_the_invoice_file_is_served_inline_so_the_frame_can_show_it(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    /** وصفحةُ الطلب تُفتح ومعها ما يحتاجه الزرّان في رأس الورقة */
    public function test_the_order_page_opens(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Orders/Show')
                ->where('order.id', $order->number)
                ->has('taxInvoice'));
    }

    /* ---------------------------- «إرسال» ---------------------------- */

    /**
     * «إرسال» يُعدّ النصَّ في الخادم ويردُّ رابطًا يُفتح.
     *
     * والنصُّ من هناك لا من الشاشة: مبلغٌ تصنعه واجهةٌ يفتحها من يشاء يصل
     * الزبونَ رقمًا غير الذي في الدفتر.
     */
    public function test_send_hands_back_a_whatsapp_link_carrying_the_number_and_the_amount(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)
            ->post(route('admin.orders.send', $order->number))
            ->assertSessionHasNoErrors();

        $url = urldecode(session('toast')['link']['url']);

        $this->assertStringContainsString('wa.me/', $url);
        $this->assertStringContainsString('96891234567', $url);
        $this->assertStringContainsString('INV-000900', $url);
        $this->assertStringContainsString('25.000', $url);
    }

    /**
     * وطلبٌ بلا رقمٍ يُقال له ذلك — ولا يُصمَت عنه.
     *
     * والردُّ رسالةٌ تُرسم لا خطأُ نموذج: الشاشةُ لا ترسم حقلًا اسمه `send`،
     * فخطأٌ يُكتب هناك لا يقرؤه أحد — تُضغط الضغطةُ فلا يقع شيءٌ ولا يُقال
     * لماذا.
     */
    public function test_an_order_with_no_number_says_so_instead_of_falling_silent(): void
    {
        $order = $this->order(['customer_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('admin.orders.send', $order->number))
            ->assertSessionHasNoErrors();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertArrayNotHasKey('link', session('toast'));
    }

    /** وطلبُ متجرٍ آخر لا يُرسَل من هنا */
    public function test_another_shops_order_cannot_be_sent(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $theirs = Order::create([
            'business_id' => $other->id,
            'branch_id' => Branch::where('business_id', $other->id)->value('id'),
            'number' => 'INV-000777', 'status' => OrderStatus::PENDING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.orders.send', $theirs->number))
            ->assertNotFound();
    }

    /** والكاشير لا يفتح هذا الباب */
    public function test_a_cashier_cannot_send_from_the_admin_page(): void
    {
        $order = $this->order();
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->post(route('admin.orders.send', $order->number))->assertForbidden();
    }

    /** والإرسالُ يُقيَّد في سجلّ النشاط — من أرسل وأيَّ ورقة */
    public function test_sending_is_written_down(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)->post(route('admin.orders.send', $order->number));

        $this->assertDatabaseHas('activity_logs', [
            'business_id' => $this->business->id,
            'subject_type' => 'order',
            'subject_id' => $order->id,
        ]);
    }
}
