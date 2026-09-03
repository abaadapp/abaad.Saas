<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ما كان يعمل قبل واتساب يعمل بعده.
 *
 * وهذا أهمّ ملفّ في المجموعة: ميزةُ إشعاراتٍ تُسقط بيعةً واحدة عند الصندوق
 * تُطفَأ في اليوم الأوّل — والثمن حينها ليس الميزة وحدها بل الثقة بالنظام.
 */
class WhatsAppRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // واتساب مفعَّلٌ بالكامل — الاختبار أن يعمل البيع رغم ذلك، لا بغيابه
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567',
        ]);

        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => '1']);
        }

        $this->actingAs($this->cashier);
        session(['current_branch' => $branch->id]);
    }

    private function sell(array $extra = [])
    {
        return $this->postJson(route('pos.checkout'), array_merge([
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 2]],
            'payment_method' => 'نقدي',
            'customer_id' => $this->customer->id,
            'client_uuid' => uniqid('r', true),
        ], $extra));
    }

    /* ------------------------------ البيع سليم ------------------------------ */

    public function test_a_sale_still_completes_with_whatsapp_on(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $order = Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertEqualsWithDelta(50.0, (float) $order->subtotal, 0.0005);
        $this->assertSame(48, (int) $this->product->fresh()->quantity, 'المخزون خُصم كما كان');
    }

    /**
     * وميتا ساقطة لا تُسقط البيعة.
     *
     * وهذا الفحص لا يُغني عنه غيره: الطابور والمعاملة والاستثناءات كلّها
     * تُقاس هنا في سطرٍ واحد — أيبيع الكاشير حين ينقطع مزوّد الرسائل؟
     */
    public function test_a_dead_provider_does_not_break_the_sale(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('meta is down'));

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(48, (int) $this->product->fresh()->quantity);
    }

    /** ووصلةٌ مفقودة كذلك */
    public function test_a_missing_connection_does_not_break_the_sale(): void
    {
        WhatsAppConnection::query()->platform()->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    /** ونفادُ الحصّة كذلك — والفاتورة تُصدر بترقيمها */
    public function test_an_exhausted_quota_does_not_break_the_sale(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $this->business->update(['whatsapp_monthly_limit' => 0]);

        $response = $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertNotEmpty($response->json('invoice'));
    }

    /* ---------------------------- الأسعار والمخزون ---------------------------- */

    /** السعر ما زال من القاعدة لا من الطلب */
    public function test_pricing_is_unaffected(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->sell(['items' => [[
            'id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 1,
        ]]])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(25.0, (float) $order->subtotal, 0.0005);
    }

    /* ------------------------------ سلّة معلّقة ------------------------------ */

    /**
     * السلّة المعلّقة ليست طلبًا — فلا رسالة عنها.
     *
     * ولو أُرسلت لَقرأ الزبون «تم تأكيد طلبك» عن سلّةٍ ما زالت على الشاشة،
     * وقد تُلغى بعد دقيقة.
     */
    public function test_a_held_cart_sends_nothing(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->postJson(route('pos.hold'), [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1]],
            'customer_id' => $this->customer->id,
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    /* ---------------------------- لوحة التجهيز ---------------------------- */

    /** ونقلُ الحالة من لوحة التجهيز يُرسل كما يُرسل من شاشة الطلب — بابٌ واحد */
    public function test_moving_the_status_from_the_preparation_board_also_sends(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.BOARD']]], 200)]);

        $order = Order::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-BOARD', 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ]);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY])
            ->assertRedirect();

        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)
            ->where('event_type', WhatsAppEvent::ORDER_READY)->count());
    }

    /** ومن شاشة الطلب أيضًا — ولا تُرسل مرّتين بينهما */
    public function test_the_order_screen_and_the_board_do_not_double_send(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $order = Order::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-BOTH', 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ]);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY]);
        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::READY]);

        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)->count());
        Http::assertSentCount(1);
    }
}
