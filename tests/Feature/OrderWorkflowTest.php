<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسار الطلب: يُسجَّل، يُجهَّز، يجهز، يخرج، يُسلَّم.
 *
 * والحارس في الخادم لا في الشاشة: الشاشة تُخفي الزرّ، والطلب يصل من عنوانٍ
 * يُكتب. و«تم التسليم ← قيد التجهيز» ليست خطأً في الترتيب — هي باقةٌ خرجت
 * من المحلّ تُعاد إلى طاولة العمل، فتُجهَّز مرّتين وتُحسب مرّتين.
 */
class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    private function order(array $extra = []): Order
    {
        return Order::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25,
            'ordered_at' => now(),
            'scheduled_for' => now()->addDay(),
        ], $extra));
    }

    /* ---------------------------- الانتقالات ---------------------------- */

    public function test_the_happy_path_runs_end_to_end(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::DELIVERY]);

        foreach ([
            OrderStatus::CONFIRMED,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
            OrderStatus::COMPLETED,
        ] as $next) {
            $this->post(route('admin.orders.status', $order->number), ['status' => $next])
                ->assertSessionHasNoErrors();
            $this->assertSame($next, $order->fresh()->status);
        }
    }

    public function test_a_pickup_order_reaches_picked_up(): void
    {
        $order = $this->order(['status' => OrderStatus::READY, 'fulfillment_type' => FlowerOrder::PICKUP]);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::PICKED_UP])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::PICKED_UP, $order->fresh()->status);
    }

    /** ولا يعود ما خرج: باقةٌ سُلّمت لا تُعاد إلى الطاولة */
    public function test_a_delivered_order_cannot_go_back_to_preparing(): void
    {
        $order = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_a_cancelled_order_is_a_dead_end(): void
    {
        $order = $this->order(['status' => OrderStatus::CANCELLED]);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $order = $this->order();

        $this->post(route('admin.orders.status', $order->number), ['status' => 'مطبوخ'])
            ->assertSessionHasErrors('status');
    }

    /** والإلغاء مفتوحٌ من كلّ حالةٍ حيّة: الزبون يعتذر في أيّ لحظة */
    public function test_a_live_order_can_always_be_cancelled(): void
    {
        foreach ([OrderStatus::PENDING, OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY] as $from) {
            $order = $this->order(['status' => $from]);
            $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CANCELLED])
                ->assertSessionHasNoErrors();
            $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status, "تعذّر إلغاء طلبٍ حالتُه {$from}");
        }
    }

    /**
     * وحالةٌ لا نعرفها لا تُحبس.
     *
     * في القاعدة طلباتٌ كُتبت قبل هذا المسار، وردُّ كلّ انتقالٍ منها يعني
     * طلبًا متجمّدًا بلا زرٍّ يحرّكه — وإصلاحُ بياناتٍ قديمة لا يكون بحبسها.
     */
    public function test_an_order_in_an_unknown_state_is_not_frozen(): void
    {
        $order = $this->order(['status' => 'حالةٌ قديمة']);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    /* ------------------------------ التعديل ------------------------------ */

    public function test_the_details_can_be_edited(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::PICKUP]);

        $this->put(route('admin.orders.details.update', $order->number), [
            'recipient_name' => 'سارة الجديدة',
            'recipient_phone' => '99887766',
            'scheduled_for' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'occasion_type' => 'wedding',
        ])->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('سارة الجديدة', $fresh->recipient_name);
        $this->assertSame('99887766', $fresh->recipient_phone);
        $this->assertSame('wedding', $fresh->occasion_type);
    }

    /** والتحوّل إلى توصيلٍ يجرّ معه شروطه */
    public function test_switching_to_delivery_demands_an_address(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::PICKUP]);

        $this->put(route('admin.orders.details.update', $order->number), [
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
        ])->assertSessionHasErrors('delivery_address');

        $this->assertSame(FlowerOrder::PICKUP, $order->fresh()->fulfillment_type);
    }

    /**
     * وتعديلٌ جزئيّ لا يمسح ما لم تفتحه الشاشة.
     *
     * لولا ذلك لَكان تصحيح رقم هاتفٍ يمحو نصّ البطاقة والعنوان والمناسبة —
     * لأنّ الحقول لم تُرسل، لا لأنّ أحدًا أفرغها.
     */
    public function test_a_partial_edit_does_not_erase_what_it_did_not_touch(): void
    {
        $order = $this->order([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير', 'card_message' => 'كل عام وأنتِ بخير',
            'occasion_type' => 'birthday',
        ]);

        $this->put(route('admin.orders.details.update', $order->number), [
            'recipient_phone' => '99887766',
        ])->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('99887766', $fresh->recipient_phone);
        $this->assertSame('كل عام وأنتِ بخير', $fresh->card_message);
        $this->assertSame('الخوير', $fresh->delivery_address);
        $this->assertSame('birthday', $fresh->occasion_type);
    }

    /* ---------------------------- سجلّ النشاط ---------------------------- */

    /** ما يُغيّر التنفيذ يُقيَّد بقيمته القديمة والجديدة — لا «عُدّل الطلب» */
    public function test_moving_the_schedule_is_recorded_with_both_values(): void
    {
        $order = $this->order(['scheduled_for' => '2026-09-01 10:00:00']);

        $this->put(route('admin.orders.details.update', $order->number), [
            'scheduled_for' => '2026-09-03 18:30:00',
        ]);

        $log = ActivityLog::where('subject_id', $order->id)->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($log, 'نقلُ الموعد لم يُقيَّد');
        $this->assertStringContainsString('2026-09-01', $log->description);
        $this->assertStringContainsString('2026-09-03', $log->description);
    }

    public function test_a_status_move_is_recorded(): void
    {
        $order = $this->order();

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::PREPARING]);

        $this->assertDatabaseHas('activity_logs', [
            'subject_id' => $order->id,
            'action' => 'status',
        ]);
    }

    /** والمناسبةُ لا تُقيَّد: تُصحَّح مرّاتٍ قبل الطباعة فتُغرق السجلّ */
    public function test_cosmetic_edits_are_not_logged(): void
    {
        $order = $this->order();
        $before = ActivityLog::count();

        $this->put(route('admin.orders.details.update', $order->number), [
            'occasion_type' => 'love', 'card_message' => 'أحبّك',
        ]);

        $this->assertSame($before, ActivityLog::where('action', 'updated')->count());
    }
}
