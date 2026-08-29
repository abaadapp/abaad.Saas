<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لوحة التجهيز: ما ينتظر الصنع، مرتّبًا بموعده.
 *
 * وأخطر ما فيها ليس ما تعرضه بل ما لا تعرضه: من يقف عند الطاولة يقرأ ما
 * يُصنَع به — لا سعرَ القطعة ولا تكلفتها ولا هامش المحلّ.
 */
class PreparationBoardTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);
        $this->actingAs($this->owner);
    }

    protected function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 2, 'total' => 50,
        ]);

        return $order;
    }

    private function board(array $query = []): array
    {
        return $this->props($query)['orders'];
    }

    /** خصائص الصفحة كاملةً — للعدّادات لا للبطاقات */
    private function props(array $query = []): array
    {
        return $this->get(route('admin.preparation.index', $query))
            ->viewData('page')['props'];
    }

    /* ------------------------------ ما يظهر ------------------------------ */

    public function test_a_live_scheduled_order_appears(): void
    {
        $order = $this->order();

        $this->assertSame([$order->number], array_column($this->board(), 'number'));
    }

    /** والمغلق لا يظهر: لا ملغى ولا مكتمل ولا مُسلَّم ولا مُستلَم */
    public function test_closed_orders_are_excluded(): void
    {
        foreach (OrderStatus::CLOSED as $status) {
            $this->order(['status' => $status]);
        }

        $this->assertSame([], $this->board(), 'طلبٌ مغلق ظهر على لوحة التجهيز');
    }

    /**
     * وبيعة المنضدة لا تظهر: دُفعت وأُخذت في اللحظة نفسها، ولا شيء فيها
     * يُجهَّز. ولولا استبعادُها لَامتلأت اللوحة بفواتير الصندوق كلّها.
     */
    public function test_an_order_with_no_schedule_is_not_on_the_board(): void
    {
        $this->order(['scheduled_for' => null, 'status' => OrderStatus::COMPLETED]);

        $this->assertSame([], $this->board());
    }

    /** والسلّة المعلّقة ليست طلبًا بعد */
    public function test_a_held_cart_is_not_on_the_board(): void
    {
        $this->order(['is_held' => true]);

        $this->assertSame([], $this->board());
    }

    /* ------------------------------ الترتيب ------------------------------ */

    /** الأقرب موعدًا أوّلًا — والمتأخّر أقدمُ الجميع فيتصدّر */
    public function test_the_board_is_sorted_by_schedule_with_overdue_first(): void
    {
        $late = $this->order(['scheduled_for' => now()->subHours(2)]);
        $soon = $this->order(['scheduled_for' => now()->addHour()]);
        $later = $this->order(['scheduled_for' => now()->addDays(2)]);

        $this->assertSame(
            [$late->number, $soon->number, $later->number],
            array_column($this->board(), 'number')
        );
    }

    public function test_an_overdue_order_is_flagged(): void
    {
        $this->order(['scheduled_for' => now()->subHour()]);

        $this->assertTrue($this->board()[0]['overdue']);
    }

    /** وطلبٌ فات موعدُه وسُلّم ليس متأخّرًا */
    public function test_a_delivered_order_is_never_overdue(): void
    {
        $this->order(['scheduled_for' => now()->subDays(3), 'status' => OrderStatus::DELIVERED]);

        $this->assertSame([], $this->board(['when' => 'overdue']));
    }

    /* ------------------------------ التصفية ------------------------------ */

    public function test_the_windows_hold_what_belongs_to_them(): void
    {
        $overdue = $this->order(['scheduled_for' => now()->subHours(3)]);
        $today = $this->order(['scheduled_for' => now()->addHour()]);
        $tomorrow = $this->order(['scheduled_for' => now()->addDay()->setTime(10, 0)]);
        $later = $this->order(['scheduled_for' => now()->addDays(5)]);

        $this->assertSame([$overdue->number], array_column($this->board(['when' => 'overdue']), 'number'));
        $this->assertSame([$tomorrow->number], array_column($this->board(['when' => 'tomorrow']), 'number'));
        // «قادم» ما بعد الغد — فالنوافذ الأربع تقسم اللوحة ولا تتداخل
        $this->assertSame([$later->number], array_column($this->board(['when' => 'upcoming']), 'number'));

        $todayList = array_column($this->board(['when' => 'today']), 'number');
        $this->assertContains($today->number, $todayList);
        $this->assertNotContains($tomorrow->number, $todayList);
        $this->assertNotContains($later->number, $todayList);
    }

    /* --------------------------- مرشّح التنفيذ --------------------------- */

    public function test_the_fulfillment_filter_splits_the_board(): void
    {
        $delivery = $this->order(['fulfillment_type' => FlowerOrder::DELIVERY]);
        $pickup = $this->order(['fulfillment_type' => FlowerOrder::PICKUP]);

        $this->assertSame([$delivery->number], array_column($this->board(['type' => 'delivery']), 'number'));
        $this->assertSame([$pickup->number], array_column($this->board(['type' => 'pickup']), 'number'));

        $all = array_column($this->board(), 'number');
        $this->assertContains($delivery->number, $all);
        $this->assertContains($pickup->number, $all);
    }

    /**
     * والمرشّحان يعملان معًا لا يُلغي أحدهما الآخر.
     *
     * «توصيل اليوم» هو السؤال الذي يُسأل صباحًا: أين تذهب سيّارة المحلّ.
     */
    public function test_the_time_window_and_the_fulfillment_filter_compose(): void
    {
        $wanted = $this->order(['fulfillment_type' => FlowerOrder::DELIVERY, 'scheduled_for' => now()->addHour()]);
        $this->order(['fulfillment_type' => FlowerOrder::PICKUP, 'scheduled_for' => now()->addHour()]);
        $this->order(['fulfillment_type' => FlowerOrder::DELIVERY, 'scheduled_for' => now()->addDays(4)]);

        $this->assertSame(
            [$wanted->number],
            array_column($this->board(['when' => 'today', 'type' => 'delivery']), 'number')
        );
    }

    /**
     * والرقم على التبويب هو عدد ما يظهر عند الضغط عليه.
     *
     * عدّادٌ يُحسب بمعزلٍ عن المرشّح الآخر يَعِد بستّة ثم يفتح على اثنين —
     * وهو أسوأ من ألّا يكون هناك عدّاد.
     */
    public function test_each_counter_is_measured_under_the_other_filter(): void
    {
        $this->order(['fulfillment_type' => FlowerOrder::DELIVERY, 'scheduled_for' => now()->addHour()]);
        $this->order(['fulfillment_type' => FlowerOrder::PICKUP, 'scheduled_for' => now()->addHour()]);
        $this->order(['fulfillment_type' => FlowerOrder::PICKUP, 'scheduled_for' => now()->addDay()->setTime(10, 0)]);

        // نوافذ الزمن تُعدّ تحت «توصيل»
        $counts = $this->props(['type' => 'delivery'])['counts'];
        $this->assertSame(1, $counts['all']);
        $this->assertSame(1, $counts['today']);
        $this->assertSame(0, $counts['tomorrow']);

        // ومبدّل التنفيذ يُعدّ تحت «اليوم»
        $types = $this->props(['when' => 'today'])['typeCounts'];
        $this->assertSame(2, $types['all']);
        $this->assertSame(1, $types['delivery']);
        $this->assertSame(1, $types['pickup']);
    }

    /**
     * وطلبٌ بلا نوع تنفيذ يبقى في «الكلّ» وحده.
     *
     * لا هو توصيلٌ ولا استلام، فإسقاطه من الاثنين صحيح — لكنّ إسقاطه من
     * «الكلّ» أيضًا يعني طلبًا له موعدٌ لا تراه اللوحة في أيّ وضع.
     */
    public function test_an_order_with_no_fulfillment_type_stays_under_all(): void
    {
        $vague = $this->order(['fulfillment_type' => null]);

        $this->assertSame([$vague->number], array_column($this->board(), 'number'));
        $this->assertSame([], $this->board(['type' => 'delivery']));
        $this->assertSame([], $this->board(['type' => 'pickup']));
        $this->assertSame(1, $this->props()['typeCounts']['all']);
    }

    /** ونوعٌ لا يُعرف يعني «الكلّ» لا لوحةً فارغة */
    public function test_an_unknown_fulfillment_filter_shows_everything(): void
    {
        $order = $this->order(['fulfillment_type' => FlowerOrder::PICKUP]);

        $this->assertSame([$order->number], array_column($this->board(['type' => 'لا-شيء']), 'number'));
    }

    /* ---------------------------- ما لا يظهر ---------------------------- */

    /**
     * لا سعر ولا تكلفة ولا إجمالي.
     *
     * والفحص على ما يُرسل لا على ما يُرسَم: عمودٌ يصل إلى الشاشة مقروءٌ لكل
     * من يفتح أدوات المتصفّح، رُسم أم لم يُرسم.
     */
    public function test_no_money_reaches_the_board(): void
    {
        $this->order();

        $payload = json_encode($this->board(), JSON_UNESCAPED_UNICODE);

        foreach (['price', 'cost', 'total', 'subtotal', 'profit'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $payload,
                "حقلٌ محاسبيّ «{$forbidden}» وصل إلى لوحة التجهيز");
        }
    }

    /** وما يحتاجه العامل يصل: الأصناف والكميّات والبطاقة والمناسبة */
    public function test_what_the_worker_needs_does_reach_the_board(): void
    {
        $this->order([
            'recipient_name' => 'سارة', 'card_message' => 'كل عام وأنتِ بخير',
            'occasion_type' => 'birthday', 'fulfillment_type' => FlowerOrder::DELIVERY,
            'delivery_address' => 'الخوير',
        ]);

        $card = $this->board()[0];
        $this->assertSame('سارة', $card['recipient']);
        $this->assertSame('كل عام وأنتِ بخير', $card['card_message']);
        $this->assertSame(__('عيد ميلاد'), $card['occasion']);
        $this->assertSame('الخوير', $card['address']);
        $this->assertSame('باقة', $card['items'][0]['name']);
        $this->assertSame(2, $card['items'][0]['qty']);
    }

    /* ------------------------------ الأداء ------------------------------ */

    /**
     * عددُ الاستعلامات لا ينمو مع عدد الطلبات.
     *
     * والقياس نسبيٌّ لا مطلق: كلّ صفحةٍ في اللوحة تدفع ثمنًا ثابتًا للشريط
     * الجانبي والإعدادات والتنبيهات، وحدٌّ مطلقٌ عليه يقيس ذلك الثمن لا يقيس
     * هذه الشاشة — وينكسر يوم يُضاف مقبضٌ في الترويسة.
     *
     * فيُقارَن ثلاثة طلبات بأربعةٍ وعشرين: لو كان في الشاشة استعلامٌ لكل
     * طلبٍ لَزاد الفرق واحدًا وعشرين على الأقلّ.
     */
    public function test_the_query_count_does_not_grow_with_the_orders(): void
    {
        foreach (range(1, 3) as $i) {
            $this->order(['scheduled_for' => now()->addHours($i)]);
        }
        $few = $this->countQueries();

        foreach (range(4, 24) as $i) {
            $this->order(['scheduled_for' => now()->addHours($i)]);
        }
        $many = $this->countQueries();

        $this->assertLessThanOrEqual($few + 2, $many,
            "استعلامات اللوحة نمت من {$few} إلى {$many} حين نمت الطلبات من ٣ إلى ٢٤");
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.preparation.index'))->assertSuccessful();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /* ------------------------------ الأفعال ------------------------------ */

    public function test_start_preparing_and_mark_ready(): void
    {
        $order = $this->order(['status' => OrderStatus::CONFIRMED]);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::READY, $order->fresh()->status);
    }

    /** والحارس هنا هو حارسُ شاشة المبيعات نفسه — لا حارسان يفترقان */
    public function test_an_illegal_move_is_refused_on_the_board_too(): void
    {
        $order = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }
}
