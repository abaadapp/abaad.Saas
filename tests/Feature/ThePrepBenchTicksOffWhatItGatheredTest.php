<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PreparationController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\OrderPrepCheck;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Activity;
use App\Support\OrderStatus;
use App\Support\PrepChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لوحةُ التجهيز بعد التطوير: أعمدةٌ، وعلاماتٌ، وخطُّ حالٍ لا يُخترع.
 *
 * وأخطرُ ما تحرسه هذه الاختبارات ليس ما يُضاف بل ما لا يقع: مربّعٌ يُؤشَّر لا
 * يخصم من رفّ، ولا يكتب قيدًا، ولا ينقل الطلب. وعمودٌ لا يبتلع بطاقة.
 */
class ThePrepBenchTicksOffWhatItGatheredTest extends TestCase
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

        // الساعة تُثبَّت — اللوحة كلُّها نوافذُ زمن (انظر `PreparationBoardTest`)
        $this->travelTo(today()->setTime(9, 0));
        $this->actingAs($this->owner);
    }

    private function order(array $extra = []): Order
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

    private function props(array $query = []): array
    {
        return $this->get(route('admin.preparation.index', $query))->viewData('page')['props'];
    }

    private function cardOf(Order $o): array
    {
        foreach ($this->props()['orders'] as $card) {
            if ($card['number'] === $o->number) {
                return $card;
            }
        }

        $this->fail('الطلب '.$o->number.' ليس على اللوحة');
    }

    /* ════════════════════════ الأعمدة ════════════════════════ */

    /**
     * كلُّ حالةٍ حيّة لها عمودٌ واحد — لا صفرٌ ولا اثنان.
     *
     * هذا هو الحارسُ الذي لولاه لاختفت بطاقات: قائمةُ أعمدةٍ تُكتب بالحدس
     * تنسى «تعذّر التوصيل» — وهي طلبٌ رجع من الطريق، أحوجُ ما على اللوحة إلى
     * أن يُرى. فيختفي من العرض العموديّ بلا كلمة، ويبقى عدّادُ التبويب يعدّه.
     */
    public function test_every_live_status_has_exactly_one_column(): void
    {
        $live = array_values(array_diff(OrderStatus::ALL, OrderStatus::CLOSED));

        foreach ($live as $status) {
            $columns = array_keys(array_filter(
                PreparationController::COLUMNS,
                fn ($statuses) => in_array($status, $statuses, true),
            ));

            $this->assertCount(1, $columns, "الحال «{$status}» لها ".count($columns).' عمود');
        }
    }

    /** ولا عمودَ يذكر حالًا مغلقة: اللوحة لا تعرضها أصلًا */
    public function test_no_column_claims_a_closed_status(): void
    {
        foreach (PreparationController::COLUMNS as $key => $statuses) {
            foreach ($statuses as $status) {
                $this->assertNotContains($status, OrderStatus::CLOSED, "العمود «{$key}» يذكر حالًا مغلقة");
            }
        }
    }

    /** والخريطة تصل الشاشة — لا تُكتب فيها مرّةً ثانية فتفترق */
    public function test_the_column_map_reaches_the_screen(): void
    {
        $this->order();

        $this->assertSame(PreparationController::COLUMNS, $this->props()['columns']);
    }

    public function test_the_columns_count_what_stands_in_them(): void
    {
        $this->order(['status' => OrderStatus::PENDING]);
        $this->order(['status' => OrderStatus::CONFIRMED]);
        $this->order(['status' => OrderStatus::PREPARING]);
        $this->order(['status' => OrderStatus::READY]);
        $this->order(['status' => OrderStatus::DELIVERY_FAILED]);

        $counts = $this->props()['columnCounts'];

        // «جديد» و«مؤكّد» عمودٌ واحد: كلاهما لم يُبدأ بعد
        $this->assertSame(2, $counts['waiting']);
        $this->assertSame(1, $counts['preparing']);
        $this->assertSame(1, $counts['ready']);
        $this->assertSame(0, $counts['out']);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['other']);
    }

    /** وحالٌ قديمةٌ لا يعرفها الملفّ تُعدّ في «أخرى» ولا تُبتلع */
    public function test_an_unknown_status_is_counted_under_other(): void
    {
        $o = $this->order();
        DB::table('orders')->where('id', $o->id)->update(['status' => 'بانتظار الزبون']);

        $this->assertSame(1, $this->props()['columnCounts']['other']);
    }

    /** وتُعدّ تحت المرشّحين القائمين لا فوقهما */
    public function test_the_column_counts_obey_the_filters(): void
    {
        $this->order(['status' => OrderStatus::PREPARING, 'scheduled_for' => now()->addHours(2)]);
        $this->order(['status' => OrderStatus::PREPARING, 'scheduled_for' => now()->addDay()->setTime(10, 0)]);

        $this->assertSame(2, $this->props()['columnCounts']['preparing']);
        $this->assertSame(1, $this->props(['when' => 'today'])['columnCounts']['preparing']);
        $this->assertSame(1, $this->props(['when' => 'tomorrow'])['columnCounts']['preparing']);
    }

    /* ════════════════════════ الموعد ════════════════════════ */

    /**
     * الموعدُ يصل مفكوكًا محسوبًا — لا نصًّا يُفسَّر في المتصفّح.
     *
     * `new Date('2026-09-23 14:00')` تُقرأ بالتوقيت المحليّ للجهاز، وجهازُ
     * الطاولة قد يكون على توقيتٍ لم يُضبط. فالحسابُ في الخادم على توقيت
     * التاجر، والشاشةُ تعرض ما يصلها.
     */
    public function test_the_schedule_arrives_already_worked_out(): void
    {
        $o = $this->order(['scheduled_for' => now()->addMinutes(150)]);

        $s = $this->cardOf($o)['scheduled'];

        $this->assertSame(now()->addMinutes(150)->format('Y-m-d'), $s['date']);
        $this->assertSame(now()->addMinutes(150)->format('H:i'), $s['time']);
        $this->assertSame('today', $s['day']);
        $this->assertSame(150, $s['minutes_left']);
    }

    /** والتأخيرُ سالبٌ لا مطلق: «متأخّر ساعة» لا «ساعة» */
    public function test_a_late_order_reports_negative_minutes(): void
    {
        $o = $this->order(['scheduled_for' => now()->subMinutes(90)]);

        $this->assertSame(-90, $this->cardOf($o)['scheduled']['minutes_left']);
        $this->assertTrue($this->cardOf($o)['overdue']);
    }

    /** واليومُ مفتاحٌ لا كلمة — الترجمةُ في الشاشة */
    public function test_the_day_arrives_as_a_key(): void
    {
        $today = $this->order(['scheduled_for' => now()->addHour()]);
        $tomorrow = $this->order(['scheduled_for' => now()->addDay()->setTime(10, 0)]);
        $later = $this->order(['scheduled_for' => now()->addDays(4)]);

        $this->assertSame('today', $this->cardOf($today)['scheduled']['day']);
        $this->assertSame('tomorrow', $this->cardOf($tomorrow)['scheduled']['day']);
        $this->assertNull($this->cardOf($later)['scheduled']['day']);
    }

    /* ════════════════════ قائمة التحقّق ════════════════════ */

    public function test_a_check_is_saved_and_comes_back_with_the_board(): void
    {
        $o = $this->order();
        $item = $o->items()->first();

        $this->post(route('admin.preparation.check', $o->number), [
            'key' => 'item:'.$item->id, 'checked' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_prep_checks', [
            'business_id' => $this->business->id, 'order_id' => $o->id, 'key' => 'item:'.$item->id,
        ]);

        $checks = $this->cardOf($o)['checks'];
        $this->assertArrayHasKey('item:'.$item->id, $checks);
        $this->assertSame('المالك', $checks['item:'.$item->id]['by']);
    }

    /** ورفعُها حذفٌ — لا صفٌّ ثالثٌ بقيمة «لا» */
    public function test_unchecking_removes_the_row(): void
    {
        $o = $this->order();
        $key = 'item:'.$o->items()->first()->id;

        $this->post(route('admin.preparation.check', $o->number), ['key' => $key, 'checked' => true]);
        $this->post(route('admin.preparation.check', $o->number), ['key' => $key, 'checked' => false]);

        $this->assertDatabaseMissing('order_prep_checks', ['order_id' => $o->id, 'key' => $key]);
        $this->assertSame([], $this->cardOf($o)['checks']);
    }

    /** والإضافةُ تُؤشَّر كالبند: من يجهّز يجمع الدبّ كما يجمع الورد */
    public function test_an_addon_can_be_ticked(): void
    {
        $o = $this->order();
        $addon = OrderItemAddon::create([
            'order_item_id' => $o->items()->first()->id, 'name' => 'دبّ', 'quantity' => 1,
            'unit_price' => 3, 'total' => 3,
        ]);

        $this->post(route('admin.preparation.check', $o->number), [
            'key' => 'addon:'.$addon->id, 'checked' => true,
        ])->assertSessionHasNoErrors();

        $this->assertArrayHasKey('addon:'.$addon->id, $this->cardOf($o)['checks']);
    }

    /** والمهمّتان الثابتتان تُقبلان لكلّ طلبٍ مهما كانت بنودُه */
    public function test_the_fixed_tasks_are_accepted(): void
    {
        $o = $this->order();

        foreach (PrepChecklist::TASKS as $task) {
            $this->post(route('admin.preparation.check', $o->number), ['key' => $task, 'checked' => true])
                ->assertSessionHasNoErrors();
        }

        $this->assertCount(2, $this->cardOf($o)['checks']);
    }

    /**
     * ومفتاحُ طلبٍ آخر يُردّ — ولو كان من المتجر نفسِه.
     *
     * وإلّا لَأشّر موظّفٌ بندَ طلبٍ غيرِ الذي أمامه برقمٍ يُبدَّل في الطلب،
     * فيقرأ زميلُه على الطاولة الثانية أنّ ما لم يُجمع قد جُمع.
     */
    public function test_a_key_from_another_order_is_refused(): void
    {
        $mine = $this->order();
        $other = $this->order();
        $stranger = 'item:'.$other->items()->first()->id;

        $this->post(route('admin.preparation.check', $mine->number), [
            'key' => $stranger, 'checked' => true,
        ])->assertSessionHasErrors('key');

        $this->assertDatabaseMissing('order_prep_checks', ['order_id' => $mine->id, 'key' => $stranger]);
    }

    /** ومفتاحٌ مخترَع يُردّ — فلا يتراكم في الجدول ما لا يُعرض */
    public function test_an_invented_key_is_refused(): void
    {
        $o = $this->order();

        $this->post(route('admin.preparation.check', $o->number), [
            'key' => 'task:whatever', 'checked' => true,
        ])->assertSessionHasErrors('key');

        $this->assertSame(0, OrderPrepCheck::count());
    }

    /** وطلبُ متجرٍ آخر لا يُؤشَّر — يُردّ بـ٤٠٤ قبل أن يُقرأ مفتاح */
    public function test_a_neighbours_order_cannot_be_ticked(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'محل ورود', 'status' => 'نشط']);
        $theirs = Order::create([
            'business_id' => $other->id, 'number' => 'INV-JAR', 'status' => OrderStatus::CONFIRMED,
            'is_held' => false, 'total' => 10, 'ordered_at' => now(), 'scheduled_for' => now()->addHour(),
        ]);
        OrderItem::create(['order_id' => $theirs->id, 'name' => 'باقة', 'price' => 10, 'quantity' => 1, 'total' => 10]);

        $this->post(route('admin.preparation.check', $theirs->number), [
            'key' => 'task:packaging', 'checked' => true,
        ])->assertNotFound();

        $this->assertSame(0, OrderPrepCheck::count());
    }

    /** وطلبُ فرعٍ آخر كذلك حين تكون اللوحة مقيّدةً بفرع */
    public function test_another_branchs_order_cannot_be_ticked(): void
    {
        $second = Branch::create(['business_id' => $this->business->id, 'name' => 'الثاني']);
        $theirs = $this->order(['branch_id' => $second->id]);

        $main = Branch::where('business_id', $this->business->id)->where('name', 'الرئيسي')->value('id');
        session(['current_branch' => $main]);

        $this->post(route('admin.preparation.check', $theirs->number), [
            'key' => 'task:packaging', 'checked' => true,
        ])->assertNotFound();

        $this->assertSame(0, OrderPrepCheck::count());
    }

    /**
     * والعلامةُ لا تخصم ولا تُقيّد ولا تنقل.
     *
     * هذا هو الخطُّ الذي لا يُعبر: المربّع يقول «جمعتُه» ولا يقول «جاهز».
     * وخلطُهما يعني طلبًا يقفز إلى «جاهز» لأنّ موظّفًا أشّر آخرَ بندٍ وهو لم
     * يغلّفه — أو مخزونًا يُخصم مرّتين: مرّةً بالبيع ومرّةً بالمربّع.
     */
    public function test_ticking_moves_no_stock_no_ledger_and_no_status(): void
    {
        $o = $this->order();
        $qty = $this->product->fresh()->quantity;
        $movements = DB::table('inventory_movements')->count();
        $transactions = Transaction::count();

        foreach (PrepChecklist::keys($o->load('items.addons')) as $key) {
            $this->post(route('admin.preparation.check', $o->number), ['key' => $key, 'checked' => true]);
        }

        $this->assertSame($qty, $this->product->fresh()->quantity, 'المربّع خصم من الرفّ');
        $this->assertSame($movements, DB::table('inventory_movements')->count(), 'المربّع كتب حركة مخزون');
        $this->assertSame($transactions, Transaction::count(), 'المربّع كتب قيدًا ماليًّا');
        $this->assertSame(OrderStatus::CONFIRMED, $o->fresh()->status, 'المربّع نقل حال الطلب');
    }

    /** ولا تمنع «جاهز»: المنعُ يوقف طلباتٍ قديمةً لا قائمةَ لها */
    public function test_an_incomplete_checklist_does_not_block_ready(): void
    {
        $o = $this->order(['status' => OrderStatus::PREPARING]);

        $this->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::READY])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::READY, $o->fresh()->status);
    }

    /** وضغطتان على المربّع نفسِه لا تكتبان صفّين */
    public function test_ticking_twice_writes_one_row(): void
    {
        $o = $this->order();

        $this->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true]);
        $this->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true]);

        $this->assertSame(1, OrderPrepCheck::where('order_id', $o->id)->count());
    }

    /** وعلاماتُ اللوحة تُقرأ في استعلامٍ واحد مهما كثرت البطاقات */
    public function test_reading_the_checks_does_not_grow_with_the_orders(): void
    {
        foreach (range(1, 3) as $i) {
            $o = $this->order(['scheduled_for' => now()->addHours($i)]);
            PrepChecklist::set($o, 'task:packaging', true);
        }
        $few = $this->countQueries();

        foreach (range(4, 24) as $i) {
            $o = $this->order(['scheduled_for' => now()->addHours($i)]);
            PrepChecklist::set($o, 'task:packaging', true);
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

    /* ════════════════════ خطُّ الحال ════════════════════ */

    /** الخطُّ من سجلّ النشاط القائم — ما كتبته اللوحةُ وشاشةُ الطلب معًا */
    public function test_the_timeline_reads_what_was_recorded(): void
    {
        $o = $this->order(['status' => OrderStatus::PREPARING]);
        $this->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::READY]);

        $events = $this->getJson(route('admin.preparation.timeline', $o->number))
            ->assertSuccessful()->json('events');

        $this->assertCount(1, $events);
        $this->assertStringContainsString(OrderStatus::READY, $events[0]['text']);
        $this->assertSame('المالك', $events[0]['by']);
        $this->assertNotEmpty($events[0]['at']);
    }

    /** وما لم يُسجَّل لا يُخترع: طلبٌ لم يُنقل خطُّه فارغ */
    public function test_an_order_with_no_recorded_moves_has_an_empty_timeline(): void
    {
        $o = $this->order();

        $this->assertSame([], $this->getJson(route('admin.preparation.timeline', $o->number))->json('events'));
    }

    /** ولا يقرأ سطرًا من متجرٍ آخر ولو حمل معرّفَ الطلب نفسَه */
    public function test_the_timeline_does_not_read_a_neighbours_log(): void
    {
        $o = $this->order();
        $other = Business::create(['name' => 'جار', 'type' => 'محل ورود', 'status' => 'نشط']);

        Activity::log('status', 'سطرُ الجار', [
            'business_id' => $other->id, 'subject_id' => $o->id, 'subject_type' => 'order',
        ]);

        $this->assertSame([], $this->getJson(route('admin.preparation.timeline', $o->number))->json('events'));
    }

    /** وطلبُ الجار نفسُه يُردّ بـ٤٠٤ */
    public function test_a_neighbours_timeline_is_not_served(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'محل ورود', 'status' => 'نشط']);
        $theirs = Order::create([
            'business_id' => $other->id, 'number' => 'INV-JAR2', 'status' => OrderStatus::CONFIRMED,
            'is_held' => false, 'total' => 10, 'ordered_at' => now(), 'scheduled_for' => now()->addHour(),
        ]);

        $this->get(route('admin.preparation.timeline', $theirs->number))->assertNotFound();
    }

    /* ════════════════════ المال والصلاحية ════════════════════ */

    /** ولا مالَ في الحقول الجديدة — العلاماتُ ولا الموعدُ ولا الخطّ */
    public function test_the_new_fields_carry_no_money(): void
    {
        $o = $this->order();
        PrepChecklist::set($o, 'task:packaging', true);
        $this->post(route('admin.preparation.move', $o->number), ['status' => OrderStatus::PREPARING]);

        $payload = json_encode($this->props(), JSON_UNESCAPED_UNICODE)
            .json_encode($this->getJson(route('admin.preparation.timeline', $o->number))->json(), JSON_UNESCAPED_UNICODE);

        foreach (['price', 'cost', 'total', 'subtotal', 'profit'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $payload,
                "حقلٌ محاسبيّ «{$forbidden}» وصل إلى لوحة التجهيز");
        }
    }

    /** والبابان الجديدان يتبعان «التجهيز» لا «المبيعات» */
    public function test_the_new_doors_belong_to_whoever_prepares(): void
    {
        $o = $this->order();
        $worker = User::create([
            'business_id' => $this->business->id, 'name' => 'المجهِّز', 'email' => 'w@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['preparation'],
        ]);

        $this->actingAs($worker)
            ->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($worker)->get(route('admin.preparation.timeline', $o->number))->assertSuccessful();
    }

    /** و«المبيعات» وحدها لا تفتحهما — القسمان منفصلان ويبقيان */
    public function test_sales_alone_opens_neither_door(): void
    {
        $o = $this->order();
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'البائع', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => ['orders'],
        ]);

        $this->actingAs($seller)
            ->post(route('admin.preparation.check', $o->number), ['key' => 'task:packaging', 'checked' => true])
            ->assertForbidden();

        $this->actingAs($seller)->get(route('admin.preparation.timeline', $o->number))->assertForbidden();
    }
}
