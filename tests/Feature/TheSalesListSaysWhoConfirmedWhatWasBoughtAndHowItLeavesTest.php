<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\Lists\SaleCells;
use App\Support\OrderStatus;
use App\Support\SalesChannel;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * «المبيعات» تقول من أكّد الطلب، وما اشتُري، وكيف يخرج — لكلّ متجر.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) طلبُ الموقع بالبطاقة لا يُكتب إلّا حين تؤكّد Paymob القبض، ومرّةً واحدة
 *    ولو تكرّر الإشعار؛ وعند الاستلام يُكتب «غير مدفوع» ينتظر تأكيده.
 * ٢) أوّلُ من نقله من «جديد» إلى العمل يُحفظ باسمه ووقته — لا يُكتب فوقه
 *    من حرّكه بعده، ولا يُمسّ `employee_name` («الموقع الإلكتروني»). والقديمُ
 *    بلا مؤكِّدٍ محفوظ: «مؤكّد — الموظف غير معروف»، لا اسمٌ يُخترع.
 * ٣) «منتجان — 5 قطع» لا «5 منتجات»، والإضافةُ المبيعةُ سطرًا في سطرها.
 * ٤) «التسليم / الاستلام» نوعُ التنفيذ دائمًا، وموعدُه بطرفي فترته أو «لم
 *    يُحدد موعد»، و«متأخّر» بجانبه لا بدلَه.
 * ٥) الملفّاتُ تقول ما تقوله الشاشة، والمتجرُ الآخرُ لا يُقرأ ولا يُؤكَّد طلبُه،
 *    والصفحةُ تُقرأ بعددٍ ثابتٍ من الأسئلة مهما كثرت صفوفُها.
 */
class TheSalesListSaysWhoConfirmedWhatWasBoughtAndHowItLeavesTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private Product $tulip;

    private User $ahmed;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
        app()->setLocale('ar');

        $this->openRibbon();
        $this->bankOpens();

        $this->tulip = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة توليب', 'price' => 5, 'cost' => 2,
            'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        $this->ahmed = User::create([
            'business_id' => $this->shop->id, 'name' => 'أحمد', 'email' => 'ahmed@confirm.local',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** صفُّ الطلب في «المبيعات» كما يصل الشاشة */
    private function row(string $number, ?User $as = null): array
    {
        $rows = $this->actingAs($as ?? $this->owner)->get(route('admin.orders.index'))->assertOk()->viewData('page')['props']['orders'];

        return collect($rows)->firstWhere('id', $number) ?? $this->fail("لا صفَّ للطلب {$number}");
    }

    private function move(Order $order, string $to, User $by): void
    {
        $this->actingAs($by)->post(route('admin.orders.status', $order->number), ['status' => $to])->assertRedirect();
    }

    /** طلبٌ مكتوبٌ بيده — لما لا يدخل من الموقع (الصندوق، والطلبُ القديم) */
    private function sale(string $number, array $over = []): Order
    {
        return Order::create($over + [
            'business_id' => $this->shop->id, 'number' => $number, 'customer_name' => 'زبون '.$number,
            'employee_name' => 'خالد', 'branch_id' => $this->shop->branches()->value('id'), 'branch' => 'الخوير',
            'channel' => SalesChannel::POS, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20, 'is_held' => false,
            'ordered_at' => now(), 'status' => OrderStatus::COMPLETED,
        ]);
    }

    private function websiteOrder(array $over = []): Order
    {
        $this->checkout($over)->assertOk()->assertJsonPath('ok', true);

        return Order::latest('id')->firstOrFail();
    }

    /* ═══════════ ١ — طلبُ الموقع: البطاقةُ حين تؤكّدها Paymob، والاستلامُ ينتظر ═══════════ */

    public function test_a_card_order_is_written_once_and_only_when_paymob_confirms_the_payment(): void
    {
        $this->checkout(['pay' => 'card', 'items' => [['id' => $this->rose->id, 'qty' => 2]]])->assertOk()->assertJsonPath('ok', true);
        $intent = StorePaymentIntent::sole();
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ قبل أن يُقبض شيء');

        // إشعارٌ بدفعةٍ فشلت لا يكتب طلبًا ولا «مدفوع»
        $failed = $this->notice($intent, 4401);
        $failed['success'] = false;
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($failed, 'hmac_secret_value'), ['obj' => $failed])->assertOk();
        $this->assertSame(0, Order::count(), 'دفعةٌ فاشلة كتبت طلبًا');

        // والقبضُ الموثوق يكتب طلبًا واحدًا — ولو تكرّر إشعارُه
        $this->paid($intent);
        $this->paid($intent);
        $order = Order::sole();

        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame(SalesChannel::WEBSITE, $order->channel);
        $this->assertSame('الموقع الإلكتروني', $order->employee_name);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(48, (int) $this->rose->fresh()->quantity, 'خُصم الرفُّ مرّتين أو لم يُخصم');

        $row = $this->row($order->number);
        $this->assertSame('الموقع الإلكتروني', $row['channel_label']);
        $this->assertSame('pending', $row['confirmation']['state']);
        $this->assertSame('بانتظار التأكيد', $row['confirmation']['label']);
        $this->assertSame('منتج واحد — قطعتان', $row['products']['label']);
        $this->assertSame((float) $intent->amount, $row['total']);
    }

    public function test_a_cash_on_delivery_order_is_unpaid_and_waits_for_its_confirmation(): void
    {
        $order = $this->websiteOrder(['pay' => 'cod']);

        $this->assertSame('غير مدفوع', $order->payment_status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertNull($order->confirmed_at);
        $this->assertSame('بانتظار التأكيد', $this->row($order->number)['confirmation']['label']);
    }

    /* ═══════════ ٢ — أوّلُ مؤكِّدٍ باسمه ووقته، ولا يُكتب فوقه ═══════════ */

    public function test_the_first_confirmation_names_who_and_when_and_never_moves(): void
    {
        $order = $this->websiteOrder();

        Carbon::setTestNow('2026-10-28 10:30:00');
        $this->move($order, OrderStatus::CONFIRMED, $this->ahmed);

        $order->refresh();
        $this->assertSame($this->ahmed->id, (int) $order->confirmed_by);
        $this->assertSame('أحمد', $order->confirmed_by_name);
        $this->assertSame('2026-10-28 10:30:00', $order->confirmed_at->toDateTimeString());
        // ومن أنشأه لا يتبدّل: البابُ الذي دخل منه
        $this->assertSame('الموقع الإلكتروني', $order->employee_name);

        $row = $this->row($order->number);
        $this->assertSame('confirmed', $row['confirmation']['state']);
        $this->assertSame('أكّده: أحمد — 10:30 ص', $row['confirmation']['label']);
        $this->assertSame('الموقع الإلكتروني', $row['employee']);
        $this->assertSame(OrderStatus::CONFIRMED, $row['status']);

        // صاحبُ المحلّ يحرّكه بعده — إلى الطاولة ثمّ يعيده — والمؤكِّدُ هو هو
        Carbon::setTestNow('2026-10-28 11:00:00');
        $this->move($order, OrderStatus::PREPARING, $this->owner);
        $this->move($order, OrderStatus::CONFIRMED, $this->owner);
        $order->refresh();
        $this->assertSame([$this->ahmed->id, 'أحمد', '2026-10-28 10:30:00'], [(int) $order->confirmed_by, $order->confirmed_by_name, $order->confirmed_at->toDateTimeString()]);

        // وصفحةُ الطلب تقوله بيومه وساعته
        $detail = $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))->assertOk()->viewData('page')['props']['order'];
        $this->assertSame('أحمد', $detail['confirmation']['by']);
        $this->assertSame('28/10/2026، 10:30 ص', $detail['confirmation']['moment']);

        // وسجلُّ النشاط كما كان: سطرٌ لكلّ نقلٍ لا أكثر
        $this->assertSame(3, DB::table('activity_logs')->where('subject_type', 'order')->where('subject_id', $order->id)->where('action', 'status')->count());
    }

    public function test_straight_to_the_bench_is_a_confirmation_and_a_cancel_is_not(): void
    {
        $bench = $this->websiteOrder();
        $this->move($bench, OrderStatus::PREPARING, $this->owner);
        $this->assertSame([$this->owner->id, 'سعود'], [(int) $bench->fresh()->confirmed_by, $bench->fresh()->confirmed_by_name]);

        $dropped = $this->websiteOrder(['phone' => '96899110002']);
        $this->move($dropped, OrderStatus::CANCELLED, $this->owner);
        $dropped->refresh();
        $this->assertSame(OrderStatus::CANCELLED, $dropped->status);
        $this->assertNull($dropped->confirmed_at, 'الإلغاءُ من «جديد» كُتب تأكيدًا');
        $this->assertSame('none', $this->row($dropped->number)['confirmation']['state']);
    }

    /* ═══════════ ٣ — القديمُ بلا مؤكِّد، والصندوقُ موظّفُه، ولا يُخترع اسم ═══════════ */

    public function test_old_orders_say_what_is_known_and_invent_no_name(): void
    {
        $old = $this->sale('OLD-1', ['channel' => SalesChannel::WEBSITE, 'employee_name' => 'الموقع الإلكتروني', 'status' => OrderStatus::CONFIRMED, 'payment_status' => 'غير مدفوع']);
        $till = $this->sale('POS-1');

        $row = $this->row('OLD-1');
        $this->assertSame('unknown', $row['confirmation']['state']);
        $this->assertSame('مؤكّد — الموظف غير معروف', $row['confirmation']['label']);
        $this->assertNull($row['confirmation']['by']);
        // ولم يُكتب في الصفّ القديم شيء
        $this->assertNull(DB::table('orders')->where('id', $old->id)->value('confirmed_by_name'));

        // بيعةُ الصندوق: موظّفُها، ولا سطرَ تأكيد
        $pos = $this->row('POS-1');
        $this->assertSame('خالد', $pos['employee']);
        $this->assertNull($pos['confirmation']);
        $this->assertNull(DB::table('orders')->where('id', $till->id)->value('confirmed_at'));
    }

    /* ═══════════ ٤ — منتجان وخمسُ قطع، والإضافةُ في سطرها ═══════════ */

    public function test_two_products_of_five_pieces_read_as_such_and_an_extra_is_not_a_product(): void
    {
        $order = $this->websiteOrder(['items' => [['id' => $this->rose->id, 'qty' => 3], ['id' => $this->tulip->id, 'qty' => 2]]]);
        $this->assertSame('منتجان — 5 قطع', $this->row($order->number)['products']['label']);

        // وإضافةٌ تُباع سطرًا وحدها لا تُعدّ منتجًا ثالثًا
        $chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 2, 'active' => true]);
        OrderItem::create(['order_id' => $order->id, 'standalone_addon_id' => $chocolate->id, 'name' => 'شوكولاتة', 'price' => 2, 'quantity' => 1, 'total' => 2]);

        $products = $this->row($order->number)['products'];
        $this->assertSame(['lines' => 2, 'pieces' => 5, 'addons' => 1], array_intersect_key($products, ['lines' => 0, 'pieces' => 0, 'addons' => 0]));
        $this->assertSame('منتجان — 5 قطع', $products['label']);
        $this->assertSame('+ إضافة واحدة', $products['addons_label']);

        // وأعدادُ العربيّة كما تُقرأ
        $this->sale('POS-11', ['total' => 1]);
        foreach (range(1, 11) as $i) {
            OrderItem::create(['order_id' => Order::where('number', 'POS-11')->value('id'), 'name' => 'صنف '.$i, 'price' => 1, 'quantity' => 1, 'total' => 1]);
        }
        $this->assertSame('11 منتجًا — 11 قطعة', $this->row('POS-11')['products']['label']);
    }

    /* ═══════════ ٥ — التسليم / الاستلام: النوعُ دائمًا، والموعدُ أو أنّه لم يُحدَّد ═══════════ */

    public function test_delivery_and_pickup_always_say_their_kind_and_their_time_or_that_none_was_set(): void
    {
        $at = fn (string $t) => Carbon::parse($t);
        $this->sale('D-WIN', ['fulfillment_type' => FlowerOrder::DELIVERY, 'scheduled_for' => $at('2026-10-28 17:00'), 'scheduled_until' => $at('2026-10-28 19:00'), 'status' => OrderStatus::CONFIRMED]);
        $this->sale('D-NONE', ['fulfillment_type' => FlowerOrder::DELIVERY, 'status' => OrderStatus::CONFIRMED]);
        $this->sale('P-AT', ['fulfillment_type' => FlowerOrder::PICKUP, 'scheduled_for' => $at('2026-10-29 17:00'), 'status' => OrderStatus::CONFIRMED]);
        $this->sale('P-NONE', ['fulfillment_type' => FlowerOrder::PICKUP, 'status' => OrderStatus::CONFIRMED]);
        $this->sale('LATE', ['fulfillment_type' => FlowerOrder::DELIVERY, 'scheduled_for' => $at('2026-10-28 08:00'), 'status' => OrderStatus::PREPARING]);
        $this->sale('COUNTER');

        $this->assertSame('توصيل — 28/10/2026، 5:00 م – 7:00 م', $this->row('D-WIN')['handover']['label']);
        $this->assertSame('توصيل — لم يُحدد موعد', $this->row('D-NONE')['handover']['label']);
        $this->assertSame('استلام من المحل — 29/10/2026، 5:00 م', $this->row('P-AT')['handover']['label']);
        $pickup = $this->row('P-NONE')['handover'];
        $this->assertSame(['pickup', 'استلام من المحل', null], [$pickup['kind'], $pickup['type'], $pickup['when']]);
        $this->assertSame('استلام من المحل — لم يُحدد موعد', $pickup['label']);

        // «متأخّر» بجانب النوع لا بدلَه — ولا يُقاس بيوم الطلب
        $late = $this->row('LATE');
        $this->assertTrue($late['late']);
        $this->assertSame(['delivery', 'توصيل'], [$late['handover']['kind'], $late['handover']['type']]);
        $this->assertFalse($this->row('D-WIN')['late']);

        // وبيعةُ المنضدة لا نوعَ لها ولا موعد
        $this->assertNull($this->row('COUNTER')['handover']);

        // وبالإنجليزيّة لغتُها
        app()->setLocale('en');
        $this->actingAs($this->owner)->withSession(['locale' => 'en']);
        $this->assertStringContainsString('28/10/2026, 5:00 PM – 7:00 PM', (string) SaleCells::window($at('2026-10-28 17:00'), $at('2026-10-28 19:00')));
    }

    /* ═══════════ ٦ — الملفّاتُ تقول ما تقوله الشاشة ═══════════ */

    public function test_the_files_say_what_the_screen_says_and_change_no_number(): void
    {
        $order = $this->websiteOrder(['items' => [['id' => $this->rose->id, 'qty' => 3], ['id' => $this->tulip->id, 'qty' => 2]]]);
        Carbon::setTestNow('2026-10-28 10:30:00');
        $this->move($order, OrderStatus::CONFIRMED, $this->ahmed);
        $screen = $this->row($order->number);
        $before = DB::table('orders')->get(['id', 'total', 'tax', 'subtotal', 'status', 'payment_status'])->toJson();

        $csv = $this->actingAs($this->owner)->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $lines = preg_split('/\R/u', trim($csv));
        $header = array_map(fn ($c) => trim(str_replace("\u{FEFF}", '', $c), '"'), explode(',', $lines[0]));
        $cells = array_map(fn ($c) => trim($c, '"'), explode(',', (string) collect($lines)->first(fn ($l) => str_starts_with(trim($l, '"'), $order->number))));
        $col = fn (string $name) => $cells[array_search($name, $header, true)] ?? $this->fail("لا عمودَ «{$name}» في الملفّ");

        $this->assertSame('2', $col('عدد المنتجات'));
        $this->assertSame('5', $col('عدد القطع'));
        $this->assertSame('0', $col('الإضافات'));
        $this->assertSame($screen['handover']['label'], $col('التسليم / الاستلام'));
        $this->assertSame('أحمد', $col('المؤكِّد'));
        $this->assertSame('2026-10-28 10:30', $col('وقت التأكيد'));
        $this->assertSame('الموقع الإلكتروني', $col('الموظف'));
        $this->assertSame('الخوير', $col('الفرع'));

        // وExcel يحمل الأعمدةَ نفسَها
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $this->get($this->exportRoute('admin.orders.xlsx'))->assertOk()->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($file);
        foreach (['عدد القطع', 'التسليم / الاستلام', 'المؤكِّد', 'وقت التأكيد', 'أحمد', $order->number] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        // وPDF: صفوفُ الشاشة بما تقوله
        $sent = null;
        View::composer('pdf.orders-report', function ($view) use (&$sent) {
            $sent = $view->getData();
        });
        $this->get($this->exportRoute('admin.orders.exportPdf'))->assertOk();
        $pdfRow = collect($sent['rows'])->firstWhere('id', $order->number);
        $this->assertSame($screen['products']['label'], $pdfRow['products']['label']);
        $this->assertSame($screen['confirmation']['label'], $pdfRow['confirmation']['label']);
        $this->assertSame($screen['handover']['label'], $pdfRow['handover']['label']);

        // ولا رقمٌ تبدّل بقراءة الشاشة وملفّاتها
        $this->assertSame($before, DB::table('orders')->get(['id', 'total', 'tax', 'subtotal', 'status', 'payment_status'])->toJson());
    }

    /* ═══════════ ٧ — كلُّ متجرٍ وطلباتُه، والصلاحيات ═══════════ */

    public function test_a_shop_reads_and_confirms_only_its_own_orders_and_staff_follow_their_sections(): void
    {
        // رقمُ الطلب يتكرّر بين متجرين (لكلٍّ عدّادُه) — فيُميَّز هنا ليُسأل عنه بعنوانه
        $mine = $this->sale('MINE-1', ['channel' => SalesChannel::WEBSITE, 'employee_name' => 'الموقع الإلكتروني', 'status' => OrderStatus::PENDING, 'payment_status' => 'غير مدفوع']);
        $ownShop = $this->shop;
        $ownOwner = $this->owner;

        // متجرٌ ثانٍ بالقالب نفسِه — يُنشأ كما يُنشأ أيُّ متجرٍ جديد، بلا إعدادٍ خاصّ
        $this->openRibbon('second-shop', 'second@confirm.local');
        $theirs = $this->websiteOrder(['phone' => '96899110009']);
        $this->assertSame('pending', $this->row($theirs->number)['confirmation']['state']);

        // صاحبُ الثاني لا يرى طلبي ولا يؤكّده بعنوانه
        $this->assertNotContains($mine->number, array_column($this->actingAs($this->owner)->get(route('admin.orders.index'))->viewData('page')['props']['orders'], 'id'));
        $this->actingAs($this->owner)->post(route('admin.orders.status', $mine->number), ['status' => OrderStatus::CONFIRMED])->assertNotFound();
        $this->assertNull($mine->fresh()->confirmed_at);

        // والكاشيرُ بلا «المبيعات» لا يفتح الجدولَ ولا يؤكّد
        $cashier = User::create(['business_id' => $ownShop->id, 'name' => 'كاشير', 'email' => 'cashier@confirm.local', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط']);
        $this->actingAs($cashier)->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.orders.status', $mine->number), ['status' => OrderStatus::CONFIRMED])->assertForbidden();
        $this->assertNull($mine->fresh()->confirmed_at);

        // وموظّفُ المبيعات يؤكّد في متجره
        $this->move($mine, OrderStatus::CONFIRMED, $this->ahmed);
        $this->assertSame('أحمد', $mine->fresh()->confirmed_by_name);
        $this->assertNull($theirs->fresh()->confirmed_at);
        $this->assertSame($ownOwner->business_id, $this->ahmed->business_id);
    }

    /* ═══════════ ٨ — أسئلةٌ ثابتةٌ مهما كثرت الصفوف ═══════════ */

    public function test_the_page_asks_a_fixed_number_of_questions_however_many_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('admin.orders.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->sale('Q-1', ['fulfillment_type' => FlowerOrder::DELIVERY, 'channel' => SalesChannel::WEBSITE, 'status' => OrderStatus::PENDING]);
        // زيارةٌ أولى تكتب ما يُكتب مرّةً (الجلسةُ وآخرُ ظهور) — فلا يُعدّ في المقارنة
        $count();
        $few = $count();

        foreach (range(2, 12) as $i) {
            $o = $this->sale('Q-'.$i, ['fulfillment_type' => FlowerOrder::PICKUP, 'channel' => SalesChannel::WEBSITE, 'status' => OrderStatus::CONFIRMED, 'confirmed_at' => now(), 'confirmed_by_name' => 'أحمد']);
            OrderItem::create(['order_id' => $o->id, 'name' => 'باقة', 'price' => 10, 'quantity' => 2, 'total' => 20]);
        }

        $this->assertSame($few, $count(), 'كلُّ صفٍّ يسأل القاعدةَ وحده');
    }
}
