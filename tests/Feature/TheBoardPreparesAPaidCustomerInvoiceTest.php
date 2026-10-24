<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DocumentTemplates;
use App\Support\Ledger;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * مهمّةُ فاتورة العميل على لوحة التجهيز — وورقتاها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - البطاقةُ في اللوحة نفسِها بمفتاحٍ مصنَّف، وفي فرعها وحده، ولمتجرها
 *     وحده، ولمن فُتح له التجهيزُ بعد السداد.
 *   - الانتقالاتُ المسموحة وحدها، والتوصيلُ غيرُ الاستلام، ولا إلغاءَ من اللوحة.
 *   - من يجهّز فقط يعمل على اللوحة ولا يفتح الفواتيرَ ولا الماليّة.
 *   - «فاتورة عميل — نسخة داخلية» تحمل المالَ والتجهيز؛ و«سند تسليم» لا
 *     يحمل سعرًا ولا دفعًا ولا ملاحظةً داخليّة ولا هويّةَ من أخفى اسمه.
 *   - الورقتان ٥٨ و٨٠ مم، والطويلةُ ورقةٌ واحدة.
 */
class TheBoardPreparesAPaidCustomerInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $north;

    private Branch $south;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 09:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط', 'phone' => '24000000']);
        // متجرٌ مسجَّل يجبي الضريبة — بالتفعيل الصريح، فالغيابُ إطفاء (`Vat::enabled`)
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->other->forceFill(['customer_invoice_preparation_enabled' => true])->save();

        $this->north = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوير']);
        $this->south = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع صحار']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@board.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم الحارثي', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** فاتورةٌ صادرةٌ مسدَّدةٌ ومهمّتُها ظاهرة — والسدادُ نقديّ ثمّ إرسالٌ بيد */
    private function task(array $prep = [], ?Branch $branch = null, int $lines = 1, ?Business $shop = null, ?Customer $customer = null): CustomerInvoicePreparation
    {
        $shop ??= $this->shop;
        $customer ??= $this->customer;
        $items = [];
        for ($i = 1; $i <= $lines; $i++) {
            $items[] = ['description' => $lines === 1 ? 'باقة ورد أبيض فاخرة' : 'صنف رقم '.$i.' باقة ورد بشريط حريري طويل', 'quantity' => 2, 'unit_price' => 50, 'discount' => $i === 1 ? 5 : 0];
        }
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => '2026-10-19', 'notes' => 'ملاحظة على الفاتورة', 'internal_notes' => 'ملاحظة داخلية للمالية'], $items));

        CustomerInvoicePreparations::save($invoice, array_merge([
            'fulfillment_type' => 'delivery',
            'branch_id' => ($branch ?? $this->north)->id,
            'scheduled_for' => '2026-10-19 16:00',
            'recipient_name' => 'مريم البلوشية',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير، شارع 45، فيلا 12',
            'delivery_notes' => 'الاتصال قبل الوصول',
            'internal_notes' => 'تغليف أبيض وذهبي — سرّي للطاولة',
        ], $prep), $this->owner->id);

        CustomerPayments::record($shop->id, $customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2026-10-19'], [$invoice->id => (float) $invoice->total]);
        [$activated] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        return $activated;
    }

    private function board(?User $user = null, array $query = [], ?Branch $branch = null): array
    {
        $req = $this->actingAs($user ?? $this->owner);
        if ($branch) {
            $req = $req->withSession(['current_branch' => $branch->id]);
        }

        return $req->get(route('admin.preparation.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function card(array $props, CustomerInvoicePreparation $task): ?array
    {
        return collect($props['orders'])->firstWhere('key', 'customer-invoice:'.$task->id);
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function preparer(?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'المجهّز', 'email' => 'bench'.uniqid().'@board.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['preparation'],
        ]);
    }

    /* ============================== اللوحة ============================== */

    public function test_the_paid_invoice_appears_on_the_same_board_with_its_own_key(): void
    {
        $task = $this->task(['is_gift' => true, 'occasion_type' => 'birthday', 'card_message' => 'كل عام وأنتِ بخير', 'sender_name' => 'سالم']);
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-000001', 'status' => OrderStatus::CONFIRMED,
            'fulfillment_type' => 'pickup', 'scheduled_for' => now()->addHours(2), 'branch_id' => $this->north->id,
            'total' => 20, 'subtotal' => 20, 'ordered_at' => now(),
        ]);

        $props = $this->board();
        $card = $this->card($props, $task);

        $this->assertNotNull($card);
        $this->assertSame('invoice', $card['kind']);
        $this->assertSame('فاتورة عميل', $card['source_label']);
        $this->assertSame($task->invoice->number, $card['number']);
        $this->assertSame('سالم الحارثي', $card['customer']);
        $this->assertSame('delivery', $card['fulfillment']);
        $this->assertSame('2026-10-19 16:00', $card['scheduled_for']);
        $this->assertSame('فرع الخوير', $card['branch']);
        $this->assertSame('مريم البلوشية', $card['recipient']);
        $this->assertSame('99887766', $card['recipient_phone']);
        $this->assertSame('الخوير، شارع 45، فيلا 12', $card['address']);
        $this->assertSame('باقة ورد أبيض فاخرة', $card['items'][0]['name']);
        $this->assertEquals(2, $card['items'][0]['qty']);
        $this->assertSame('تغليف أبيض وذهبي — سرّي للطاولة', $card['internal_notes']);
        $this->assertTrue($card['payment_verified']);
        $this->assertSame('عبر رابط الدفع', $card['payment_source']);
        $this->assertSame(OrderStatus::CONFIRMED, $card['status']);
        $this->assertSame([OrderStatus::PREPARING, OrderStatus::READY], $card['next']);
        $this->assertSame('عيد ميلاد', $card['occasion']);
        $this->assertSame(PrintJob::PENDING, $card['print']['status']);

        // والطلبُ بجانبها بمفتاحه — ولا يتصادم مفتاحان
        $keys = collect($props['orders'])->pluck('key');
        $this->assertContains('order:'.$order->number, $keys->all());
        $this->assertSame($keys->count(), $keys->unique()->count());

        // والعدّادُ يعدّها مع الطلبات
        $this->assertSame(2, $props['counts']['all']);
        $this->assertSame(['all' => 2, 'delivery' => 1, 'pickup' => 1], $props['typeCounts']);
        $this->assertSame(2, $props['columnCounts']['waiting']);

        // والمرشّحاتُ تعمل عليها كالطلبات
        $this->assertNull($this->card($this->board(null, ['type' => 'pickup']), $task));
        $this->assertNotNull($this->card($this->board(null, ['when' => 'today']), $task));
        $this->assertNull($this->card($this->board(null, ['when' => 'tomorrow']), $task));

        // ولا تكلفةَ ولا ربحَ ولا «مجموع فرعي» في البطاقة
        $json = json_encode($card);
        foreach (['"cost"', '"profit"', '"subtotal"'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_an_unpaid_invoice_does_not_appear(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10],
        ]));
        $prep = CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->north->id, 'scheduled_for' => '2026-10-19 12:00',
            'recipient_name' => 'سالم', 'recipient_phone' => '91234567',
        ], $this->owner->id);

        $this->assertNull($this->card($this->board(), $prep));
        $this->assertSame(0, $this->board()['counts']['all']);
    }

    public function test_each_branch_sees_its_own_tasks(): void
    {
        $north = $this->task();
        $south = $this->task([], $this->south);

        $atNorth = $this->board(null, [], $this->north);
        $this->assertNotNull($this->card($atNorth, $north));
        $this->assertNull($this->card($atNorth, $south));

        $atSouth = $this->board(null, [], $this->south);
        $this->assertNull($this->card($atSouth, $north));
        $this->assertNotNull($this->card($atSouth, $south));

        // ونقلُ مهمّة فرعٍ آخر يُردّ
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->south->id])
            ->post(route('admin.preparation.invoiceMove', $north->id), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');
        $this->assertSame(OrderStatus::CONFIRMED, $north->fresh()->status);
    }

    public function test_another_shop_neither_sees_nor_touches_the_task(): void
    {
        $task = $this->task();
        $neighbour = User::create(['business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'n@board.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->assertNull($this->card($this->board($neighbour), $task));

        $this->actingAs($neighbour)->post(route('admin.preparation.invoiceMove', $task->id), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasErrors('status');
        $this->actingAs($neighbour)->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertNotFound();
        $this->actingAs($neighbour)->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertNotFound();
        $this->actingAs($neighbour)->get(route('admin.preparation.invoiceTimeline', $task->id))->assertNotFound();
        $this->assertSame(OrderStatus::CONFIRMED, $task->fresh()->status);
    }

    public function test_a_shop_without_the_flag_sees_no_task_and_no_door(): void
    {
        $task = $this->task();
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();

        $props = $this->board();
        $this->assertNull($this->card($props, $task));
        $this->assertSame(0, $props['counts']['all']);

        $this->post(route('admin.preparation.invoiceMove', $task->id), ['status' => OrderStatus::PREPARING])->assertNotFound();
        $this->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertNotFound();
    }

    /** ومن يجهّز فقط يعمل على اللوحة — ولا يفتح الفواتيرَ ولا الماليّة */
    public function test_a_preparer_works_the_board_without_reaching_invoices_or_finance(): void
    {
        $task = $this->task();
        $bench = $this->preparer();

        $this->assertNotNull($this->card($this->board($bench), $task));

        $this->actingAs($bench)->post(route('admin.preparation.invoiceMove', $task->id), ['status' => OrderStatus::PREPARING])
            ->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PREPARING, $task->fresh()->status);

        $this->actingAs($bench)->get(route('admin.preparation.invoiceDeliveryNote', $task->id))->assertOk();
        $this->actingAs($bench)->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk();

        $this->actingAs($bench)->get(route('admin.customerInvoices.show', $task->customer_invoice_id))->assertForbidden();
        $this->actingAs($bench)->get(route('admin.customerInvoices.index'))->assertForbidden();
        $this->actingAs($bench)->post(route('admin.customerInvoices.preparation.activate', $task->customer_invoice_id))->assertForbidden();
        $this->actingAs($bench)->get(route('admin.finance.paymentLinks'))->assertForbidden();
    }

    /* ============================== الانتقالات ============================== */

    public function test_a_delivery_moves_along_its_road_and_leaves_the_board_when_delivered(): void
    {
        $task = $this->task();
        $move = fn (string $to) => $this->post(route('admin.preparation.invoiceMove', $task->id), ['status' => $to]);

        // ما لا يناسب التوصيلَ ولا اللوحةَ يُردّ
        foreach ([OrderStatus::PICKED_UP, OrderStatus::CANCELLED, OrderStatus::COMPLETED, OrderStatus::PENDING, OrderStatus::DELIVERED] as $refused) {
            $move($refused)->assertSessionHasErrors('status');
            $this->assertSame(OrderStatus::CONFIRMED, $task->fresh()->status, $refused);
        }

        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY] as $to) {
            $move($to)->assertSessionHasNoErrors();
            $this->assertSame($to, $task->fresh()->status);
        }
        $this->assertSame([OrderStatus::DELIVERED, OrderStatus::DELIVERY_FAILED], $this->card($this->board(), $task)['next']);

        $move(OrderStatus::DELIVERED)->assertSessionHasNoErrors();
        $this->assertNull($this->card($this->board(), $task), 'سُلّمت فخرجت من اللوحة');
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'customer_invoice_preparation', 'subject_id' => $task->id, 'action' => 'status']);

        // وسجلُّها يُقرأ ظهورًا وانتقالاتٍ
        $events = $this->get(route('admin.preparation.invoiceTimeline', $this->task()->id))->assertOk()->json('events');
        $this->assertNotEmpty($events);
    }

    public function test_a_pickup_is_collected_not_delivered(): void
    {
        $task = $this->task(['fulfillment_type' => 'pickup', 'delivery_address' => null]);

        foreach ([OrderStatus::PREPARING, OrderStatus::READY] as $to) {
            $this->assertNull(CustomerInvoicePreparations::move($this->shop->id, $task->id, null, $to));
        }
        $this->assertSame([OrderStatus::PICKED_UP, OrderStatus::PREPARING], CustomerInvoicePreparations::next($task->fresh()));
        $this->assertNotNull(CustomerInvoicePreparations::move($this->shop->id, $task->id, null, OrderStatus::OUT_FOR_DELIVERY));
        $this->assertNull(CustomerInvoicePreparations::move($this->shop->id, $task->id, null, OrderStatus::PICKED_UP));
        $this->assertSame(OrderStatus::PICKED_UP, $task->fresh()->status);
    }

    /** واستعلاماتُ اللوحة لا تكبر بعدد المهامّ */
    public function test_the_board_asks_the_same_number_of_queries_for_three_or_twelve_tasks(): void
    {
        foreach (range(1, 3) as $_) {
            $this->task();
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->board();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count();
        foreach (range(1, 9) as $_) {
            $this->task();
        }
        $this->assertLessThanOrEqual($few + 2, $count());
    }

    /* ============================== الورقتان ============================== */

    public function test_the_internal_thermal_invoice_carries_the_money_and_the_bench_notes(): void
    {
        $task = $this->task(['is_gift' => true, 'occasion_type' => 'birthday', 'card_message' => 'كل عام وأنتِ بخير', 'sender_name' => 'سالم', 'hide_sender' => true]);
        $invoice = $task->invoice()->with('items')->first();

        foreach ([58, 80] as $mm) {
            $text = self::text(CustomerInvoicePreparations::internalHtml($task, $invoice, $mm));

            foreach ([
                'فاتورة عميل — نسخة داخلية', $invoice->number, 'سالم الحارثي', '91234567', 'فرع الخوير',
                'توصيل', '2026-10-19 16:00', 'مريم البلوشية', '99887766', 'الخوير، شارع 45، فيلا 12', 'الاتصال قبل الوصول',
                'باقة ورد أبيض فاخرة', 'خصم', 'الضريبة', 'الإجمالي', 'مدفوعة', 'Paymob',
                'تغليف أبيض وذهبي — سرّي للطاولة', 'ملاحظة داخلية للمالية', 'ملاحظة على الفاتورة',
                'عيد ميلاد', 'كل عام وأنتِ بخير', 'سالم', 'الاسم مخفيّ عن المستلم',
                'ليست فاتورة ضريبية',
            ] as $mark) {
                $this->assertStringContainsString($mark, $text, "{$mm}mm: {$mark}");
            }
        }

        // ولا قيدَ ولا رقمَ ولا فاتورةَ تُكتب بطباعتها
        $before = [CustomerInvoice::count(), DB::table('journal_entries')->count()];
        $this->get(route('admin.preparation.invoiceInternalPdf', $task->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame($before, [CustomerInvoice::count(), DB::table('journal_entries')->count()]);
    }

    /**
     * وسندُ التسليم يُبنى للمستلِم في الخادم — لا يُخفى المالُ في الشاشة.
     */
    public function test_the_delivery_note_carries_nothing_the_recipient_must_not_read(): void
    {
        $task = $this->task([
            'is_gift' => true, 'occasion_type' => 'birthday', 'card_message' => 'كل عام وأنتِ بخير',
            'sender_name' => 'سالم الحارثي', 'hide_sender' => true,
            'delivery_notes' => 'اتصلوا بالرقم 91234567 قبل الوصول',
        ]);
        $invoice = $task->invoice()->with('items')->first();
        $text = self::text(CustomerInvoicePreparations::deliveryHtml($task, $invoice, 80));

        foreach (['سند تسليم', 'رقم الفاتورة', $invoice->number, 'تاريخ الفاتورة', '2026-10-19 16:00', 'توصيل', 'مريم البلوشية', '99887766', 'الخوير، شارع 45، فيلا 12', 'باقة ورد أبيض فاخرة', '× 2', 'عيد ميلاد', 'كل عام وأنتِ بخير', 'التوقيع'] as $mark) {
            $this->assertStringContainsString($mark, $text, $mark);
        }

        foreach ([
            'الإجمالي', 'الضريبة', 'خصم', 'المجموع', 'مدفوعة', 'Paymob', 'وسيلة الدفع', '105', '50.000', '100',
            'تغليف أبيض وذهبي', 'ملاحظة داخلية للمالية', 'ملاحظة على الفاتورة',
            'سالم الحارثي', '91234567', 'رقم الطلب',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "لا يُطبع للمستلِم: {$secret}");
        }

        // والمُهدي الظاهرُ يُطبع
        $open = $this->task(['is_gift' => true, 'card_message' => 'مبروك', 'sender_name' => 'أخوكِ خالد', 'hide_sender' => false]);
        $this->assertStringContainsString('أخوكِ خالد', self::text(CustomerInvoicePreparations::deliveryHtml($open, $open->invoice()->with('items')->first(), 58)));
    }

    public function test_the_delivery_note_is_never_queued_after_payment(): void
    {
        $task = $this->task();

        $this->assertSame(1, PrintJob::where('subject_id', $task->id)->count());
        $this->assertSame(PrintJob::KIND_INVOICE_INTERNAL, PrintJob::where('subject_id', $task->id)->value('kind'));
    }

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    #[DataProvider('widths')]
    public function test_both_papers_print_at_the_till_width_and_a_long_one_stays_one_strip(int $mm): void
    {
        DocumentTemplates::save($this->shop->id, 'sale', ['strip' => "{$mm}mm"]);
        $task = $this->task([], null, 60);

        foreach ([
            'internal' => route('admin.preparation.invoiceInternalPdf', $task->id),
            'delivery' => route('admin.preparation.invoiceDeliveryNote', $task->id),
        ] as $paper => $url) {
            $pdf = (string) $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
            $pages = PdfText::pages($pdf);

            $this->assertCount(1, $pages, "{$paper}: ستّون صنفًا ورقةٌ واحدة لا صفحات");
            $this->assertEqualsWithDelta($mm, $pages[0]['width'], 1.0, "{$paper}: بعرض {$mm}");
            $this->assertGreaterThan(300, $pages[0]['height'], "{$paper}: بطول محتواه");
        }
    }
}
