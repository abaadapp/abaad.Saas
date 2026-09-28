<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use App\Support\Store\CheckoutFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * طلبُ الموقع يصل الطاولةَ وإن لم يُسأل عن ساعة.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * حقلُ الموعد يملكه صاحبُ المحلّ: يُعرض، أو يُترك اختياريًّا، أو يُطفأ
 * (`CheckoutFields`). ومتجرٌ يبيع ما هو جاهزٌ الآن يُطفئه، فـ`scheduledFor`
 * تردّ فراغًا — ولا يُخترع له موعد، وهذا صواب.
 *
 * وكانت `Order::awaitingPreparation` تشترط الموعدَ لدخول اللوحة. فيقع
 * الطلبُ في القاعدة صحيحًا كاملًا — «جديد»، له مستلِمٌ وهاتفٌ وعنوانٌ ونوعُ
 * تنفيذ — ولا يراه أحد: لا الموظّفُ ولا المدير. يدفع الزبونُ وينتظر، ولا
 * أحد يصنع طلبَه.
 *
 * والشرطُ كان يقصد غيرَه: بيعةَ المنضدة، وهي لا موعدَ لها **ولا نوعَ
 * تنفيذٍ لها** — تُدفع وتُؤخذ في اللحظة نفسِها. فصار المقياسُ ما يُقصد:
 * طلبٌ يُجهَّز هو طلبٌ له نوعُ تنفيذ. والموعدُ إن وُجد يُصنّف ويرتّب، ولا
 * يقرّر وجودَ الطلب.
 */
class AWebsiteOrderReachesTheBenchWithoutAnHourTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدواتٌ ═══════════════════════ */

    /** يُطفئ حقلَ الموعد كما يُطفئه صاحبُ المحلّ من شاشته */
    private function hideTheDateField(string $state = CheckoutFields::OFF): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_field_date' => $state, 'store_field_slot' => $state,
        ]);
    }

    /** طلبٌ من الموقع بالدفع عند الاستلام */
    private function buy(array $over = [])
    {
        return $this->postJson('/s/ribbon/checkout', $over + [
            'items' => [['id' => $this->rose->id, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod',
            'name' => 'مريم', 'phone' => '96899110001',
        ]);
    }

    /** أرقامُ البطاقات على لوحة التجهيز كما يراها المستخدمُ الحاليّ */
    private function board(array $query = []): array
    {
        return array_column(
            $this->get(route('admin.preparation.index', $query))->viewData('page')['props']['orders'],
            'number',
        );
    }

    private function counts(array $query = []): array
    {
        return $this->get(route('admin.preparation.index', $query))
            ->viewData('page')['props']['counts'];
    }

    /** طلبٌ يُكتب رأسًا — لبيعةِ المنضدة وللطلبات القديمة */
    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id,
            'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'total' => 20, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3), 'fulfillment_type' => FlowerOrder::PICKUP,
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->rose->id, 'name' => 'باقة ورد',
            'price' => 20, 'cost' => 8, 'quantity' => 1, 'total' => 20,
        ]);

        return $order;
    }

    /* ═════════ (أ) طلبُ موقعٍ بلا موعد — يجب أن يُرى ═════════ */

    public function test_a_website_order_without_an_hour_still_reaches_the_bench(): void
    {
        $this->hideTheDateField();
        $this->buy()->assertOk();

        $order = Order::where('business_id', $this->shop->id)->sole();

        // وهذه هي الحالُ التي كانت تختفي: حيّةٌ، بلا موعد، ولها نوعُ تنفيذ
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertNull($order->scheduled_for, 'اختُرع موعدٌ لم يسأل عنه المتجر');
        $this->assertSame(FlowerOrder::PICKUP, $order->fulfillment_type);
        $this->assertSame(SalesChannel::WEBSITE, $order->channel);

        $this->actingAs($this->owner);
        $this->assertSame([$order->number], $this->board(), 'طلبُ الموقع لم يصل لوحةَ التجهيز');
    }

    /** ويراه الموظّفُ كما يراه المدير — لا بابَ يخصّ أحدَهما */
    public function test_the_staff_at_the_bench_sees_it_too(): void
    {
        $this->hideTheDateField();
        $this->buy()->assertOk();

        $staff = User::create([
            'business_id' => $this->shop->id, 'name' => 'عامل', 'email' => 'bench@abaad.om',
            'password' => bcrypt('x'), 'role' => 'staff', 'status' => 'نشط',
            // قسمُ اللوحة يُشتقّ من اسم المسار `preparation.*`
            'permissions' => ['preparation'],
        ]);

        $this->actingAs($staff);
        $this->assertCount(1, $this->board());
    }

    /* ═════════ (ح) الحقلُ اختياريٌّ فتركه الزبون ═════════ */

    public function test_an_optional_date_the_customer_left_empty_does_not_hide_the_order(): void
    {
        $this->hideTheDateField(CheckoutFields::OPTIONAL);
        $this->buy(['date' => '', 'slot' => ''])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertNull($order->scheduled_for);

        $this->actingAs($this->owner);
        $this->assertSame([$order->number], $this->board());
    }

    /* ═════════ (ب) وبموعدٍ مستقبليّ — كما كان ═════════ */

    public function test_a_website_order_with_an_hour_keeps_its_place_and_its_windows(): void
    {
        $this->buy(['date' => '2027-02-11', 'slot' => '9 ص – 12 م'])->assertOk();

        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertNotNull($order->scheduled_for);

        $this->actingAs($this->owner);
        $this->assertSame([$order->number], $this->board());
        // غدًا — فيُعدّ في تبويبه وحدَه، لا في «اليوم» ولا في «متأخّر»
        $this->assertSame([$order->number], $this->board(['when' => 'tomorrow']));
        $this->assertSame([], $this->board(['when' => 'today']));
        $this->assertSame([], $this->board(['when' => 'overdue']));
    }

    /* ═════════ (ج) الطلبُ المجدول القديم — لا يتبدّل ═════════ */

    public function test_an_old_scheduled_order_keeps_every_window_it_had(): void
    {
        $late = $this->order(['scheduled_for' => now()->subDay()]);
        $today = $this->order(['scheduled_for' => now()->addHours(2)]);
        $soon = $this->order(['scheduled_for' => now()->addDay()]);

        $this->actingAs($this->owner);
        $this->assertSame([$late->number], $this->board(['when' => 'overdue']));
        $this->assertSame([$today->number], $this->board(['when' => 'today']));
        $this->assertSame([$soon->number], $this->board(['when' => 'tomorrow']));
        $this->assertSame(
            ['all' => 3, 'overdue' => 1, 'today' => 1, 'tomorrow' => 1],
            $this->counts(),
        );
    }

    /* ═════════ (د) المغلقُ لا يدخل — ولو كان من الموقع ═════════ */

    public function test_a_closed_website_order_never_returns_to_the_bench(): void
    {
        $this->actingAs($this->owner);

        foreach (OrderStatus::CLOSED as $status) {
            $this->order([
                'status' => $status, 'scheduled_for' => null,
                'channel' => SalesChannel::WEBSITE,
            ]);
        }

        $this->assertSame([], $this->board(), 'طلبٌ مغلق عاد إلى لوحة التجهيز');
    }

    /** والمعلَّقُ سلّةٌ لم تُبَع — يبقى خارجًا */
    public function test_a_held_basket_without_an_hour_is_still_not_an_order(): void
    {
        $this->order(['is_held' => true, 'scheduled_for' => null, 'status' => OrderStatus::PENDING]);

        $this->actingAs($this->owner);
        $this->assertSame([], $this->board());
    }

    /* ═════════ (ز) بيعةُ المنضدة — لا تدخل اللوحة ═════════ */

    public function test_a_counter_sale_from_the_till_still_stays_off_the_board(): void
    {
        $this->order([
            'scheduled_for' => null, 'fulfillment_type' => null,
            'channel' => SalesChannel::POS, 'status' => OrderStatus::PENDING,
        ]);

        $this->actingAs($this->owner);
        $this->assertSame([], $this->board(), 'بيعةُ المنضدة امتلأت بها لوحةُ التجهيز');
    }

    /** وطلبُ الصندوق ذو الموعد يبقى على اللوحة كما كان */
    public function test_a_till_order_with_an_hour_is_untouched(): void
    {
        $o = $this->order(['channel' => SalesChannel::POS]);

        $this->actingAs($this->owner);
        $this->assertSame([$o->number], $this->board());
    }

    /* ═════════ (هـ) عزلُ الشركات ═════════ */

    public function test_a_neighbours_website_order_never_shows_on_my_bench(): void
    {
        $other = Business::create(['name' => 'جارٌ', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعُه']);
        Order::create([
            'business_id' => $other->id,
            'branch_id' => Branch::where('business_id', $other->id)->value('id'),
            'number' => 'INV-JAR', 'status' => OrderStatus::PENDING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'غير مدفوع',
            'subtotal' => 20, 'total' => 20, 'ordered_at' => now(),
            'scheduled_for' => null, 'fulfillment_type' => FlowerOrder::DELIVERY,
            'channel' => SalesChannel::WEBSITE,
        ]);

        $this->actingAs($this->owner);
        $this->assertSame([], $this->board());
    }

    /* ═════════ (و) عزلُ الفروع — بلا توسيعِ صلاحية ═════════ */

    public function test_a_chosen_branch_shows_only_its_own_and_all_branches_shows_both(): void
    {
        $first = Branch::where('business_id', $this->shop->id)->value('id');
        $second = Branch::create(['business_id' => $this->shop->id, 'name' => 'القرم']);

        $here = $this->order(['branch_id' => $first, 'scheduled_for' => null, 'status' => OrderStatus::PENDING]);
        $there = $this->order(['branch_id' => $second->id, 'scheduled_for' => null, 'status' => OrderStatus::PENDING]);

        $this->actingAs($this->owner);

        // «كل الفروع» — لا فرعَ في الجلسة
        $this->assertEqualsCanonicalizing([$here->number, $there->number], $this->board());

        $this->withSession(['current_branch' => $second->id]);
        $this->assertSame([$there->number], $this->board());

        $this->withSession(['current_branch' => $first]);
        $this->assertSame([$here->number], $this->board());
    }

    /* ═════════ (ط) الترتيب — ولا يسقط ما لا موعدَ له ═════════ */

    public function test_the_dateless_order_is_ranked_by_when_it_arrived_not_dropped(): void
    {
        /*
         * وترتيبُ الصفوف يُكتب عكسَ ما يُنتظر عمدًا.
         *
         * `orderBy('scheduled_for')` وحدَها تردّ الفراغَ بترتيبِ المحرّك: آخرًا
         * على PostgreSQL، وأوّلًا بترتيب الإدخال على SQLite. فلو كُتب المتأخّرُ
         * وصولًا آخرًا لَمرّت على SQLite بالصدفة وسقطت على الإنتاج وحدَه.
         */
        $late = $this->order(['scheduled_for' => null, 'ordered_at' => now()->subHour(), 'status' => OrderStatus::PENDING]);
        $noon = $this->order(['scheduled_for' => now()->addHours(2)]);
        $early = $this->order(['scheduled_for' => null, 'ordered_at' => now()->subHours(5), 'status' => OrderStatus::PENDING]);
        $evening = $this->order(['scheduled_for' => now()->addHours(9)]);

        $this->actingAs($this->owner);

        // من وصل أوّلًا يتقدّم — ولا يقف أحدُهم في ذيلٍ يُقصّ ولا يختفي
        $this->assertSame(
            [$early->number, $late->number, $noon->number, $evening->number],
            $this->board(),
        );
    }

    /** والعدّادُ يقول ما تقوله البطاقات — «الكلّ» يشملُ من لا موعدَ له */
    public function test_the_all_tab_counts_what_the_board_shows(): void
    {
        $this->order();
        $this->order(['scheduled_for' => null, 'status' => OrderStatus::PENDING]);

        $this->actingAs($this->owner);
        $this->assertSame(2, $this->counts()['all']);
        $this->assertCount(2, $this->board());
    }
}
