<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\OrderItemComponent;
use App\Models\OrderPrepCheck;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DocumentTemplates;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\PrepChecklist;
use App\Support\PrepSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * «طباعة تفاصيل التجهيز» — ورقةُ الطاولة على شريط طابعة الصندوق.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لكلّ متجرٍ فيه لوحةُ تجهيز، بلا مفتاحٍ من لوحة المنصّة، ولمن يجهّز وحده.
 *   - الورقةُ الحقيقيّةُ (PDF) على ٥٨ و٨٠ مم، بعرض إعداد المتجر، وبالعربيّة
 *     والإنجليزيّة: الأصنافُ بمقاساتها، والإضافاتُ تحت صنفها باسمها في لغة
 *     الورقة، والموادُّ والخياراتُ (والداخليُّ معلَّمًا) والملاحظاتُ والهديّة.
 *   - قائمةُ التحقّق بما أُشّر ومن أشّره — وتُقرأ ولا تُكتب.
 *   - ولا مالَ بحال: لا سعرَ ولا إجماليَّ ولا ضريبةَ ولا دفع.
 *   - ولا أثر: لا حالَ يُنقل، ولا علامةَ تُوضع، ولا ورقةَ في طابور الصندوق.
 *   - ستّون صنفًا وأكثر شريطٌ كامل، ومتجرٌ آخر وفرعٌ آخر لا يبلغانها.
 *   - ومهمّةُ فاتورة العميل بالباب نفسِه، بمعرّف مهمّتها ومربّعاتٍ فارغة.
 */
class APreparationSheetPrintsWhatTheBenchNeedsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $branch;

    private User $owner;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        app()->setLocale('ar');

        // متجرٌ عاديّ: لا مفتاحَ تجهيزِ فواتير ولا سندَ شريط — الزرُّ لكلّ متجر
        $this->shop = Business::create(['name' => 'زهور الندى', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع القرم']);
        $this->owner = $this->user('admin');
        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'name_en' => 'Rose Bouquet',
            'price' => 25.75, 'cost' => 10, 'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function user(string $role, ?array $permissions = null, ?Business $shop = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'موظّف '.$n, 'email' => 'p'.$n.'@sheet.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** طلبٌ على اللوحة: باقةٌ كبيرةٌ بإضافتين، وتنسيقٌ مخصَّص، وهديّةٌ أخفى مرسلُها اسمَه */
    private function order(int $extraItems = 0, array $over = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'فرع القرم',
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 96.125, 'tax' => 4.806, 'total' => 100.931, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3), 'fulfillment_type' => 'delivery',
            'customer_name' => 'سالم الحارثي',
            'recipient_name' => 'مريم البلوشية', 'recipient_phone' => '99887766',
            'delivery_address' => "الخوير الجنوبيّة، شارع ١٨ نوفمبر\nمبنى ٤٥، الطابق الثالث",
            'delivery_notes' => 'الاتصال قبل الوصول بعشر دقائق',
            'internal_notes' => 'لفّ الساق بشريط ساتان — سرّي للطاولة',
            'notes' => 'جهّزها قبل الخامسة',
            'is_gift' => true, 'occasion_type' => 'birthday',
            'card_message' => "كلّ عام وأنتِ بخير\nHappy birthday Maryam",
            'sender_name' => 'سالم', 'hide_sender' => true,
        ], $over));

        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->bouquet->id, 'name' => 'باقة ورد', 'variant_name' => 'كبيرة',
            'price' => 25.75, 'cost' => 10, 'quantity' => 2, 'total' => 64.5, 'addons_total' => 13.0, 'note' => 'تغليف ذهبي',
        ]);
        OrderItemAddon::create(['order_item_id' => $item->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'unit_price' => 3.25, 'quantity' => 2, 'total' => 6.5]);
        OrderItemAddon::create(['order_item_id' => $item->id, 'name' => 'كرت إهداء', 'name_en' => 'Greeting card', 'unit_price' => 6.5, 'quantity' => 1, 'total' => 6.5]);

        $custom = OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'name' => 'تنسيق مخصص', 'price' => 31.625, 'quantity' => 1, 'total' => 31.625,
            'custom_details' => ['v' => 2, 'fields' => [
                ['label' => 'اللون', 'label_en' => 'Colour', 'values' => [['label' => 'أحمر', 'label_en' => 'Red'], ['label' => 'أبيض', 'label_en' => 'White']], 'internal' => false],
                ['label' => 'ملاحظة المنسّق', 'values' => [['label' => 'شريط ذهبي عريض']], 'internal' => true],
            ]],
        ]);
        OrderItemComponent::create(['order_item_id' => $custom->id, 'name' => 'ورد أحمر', 'quantity' => 6]);
        OrderItemComponent::create(['order_item_id' => $custom->id, 'name' => 'ورق تغليف', 'quantity' => 1.5]);

        for ($i = 1; $i <= $extraItems; $i++) {
            OrderItem::create(['order_id' => $order->id, 'name' => 'صنف إضافي رقم '.$i.' باقة صغيرة بشريط حريري', 'price' => 1.111, 'quantity' => 1, 'total' => 1.111]);
        }

        return $order->fresh();
    }

    private function strip(int $mm): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => DocumentTemplates::key('sale', 'strip')],
            ['value' => "{$mm}mm"],
        );
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** ما لا يُطبع على ورقة الطاولة بحال — أثمانُ الطلب وإجماليُّه ودفعُه */
    private const MONEY = ['25.750', '64.500', '6.500', '3.250', '13.000', '31.625', '96.125', '4.806', '100.931', 'مدفوع', 'نقدي', 'الإجمالي', 'الضريبة', 'Paymob'];

    /* ============================== الورقة ============================== */

    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    #[DataProvider('widths')]
    public function test_the_sheet_is_a_real_strip_with_what_the_bench_needs_and_no_money(int $mm): void
    {
        $this->strip($mm);
        $order = $this->order();
        PrepChecklist::set($order, 'item:'.$order->items->first()->id, true);
        PrepChecklist::set($order, PrepChecklist::TASK_PACKAGING, true);

        $response = $this->get(route('admin.preparation.sheet', $order->number))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $pages = PdfText::pages($response->getContent());
        $this->assertCount(1, $pages);
        $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2, 'بعرض طابعة المتجر');
        $this->assertSame([], PdfText::wordCuts($response->getContent()), 'لا كلمةَ مقطوعة');

        $text = self::text(PrepSheet::html($this->shop->id, PrepSheet::forOrder($order->fresh(), PrepChecklist::of($order)), $mm));

        foreach ([
            'ورقة تجهيز داخلية', $order->number, 'فرع القرم', 'توصيل', 'سالم الحارثي', 'مريم البلوشية', '99887766',
            'الخوير الجنوبيّة', 'باقة ورد', 'المقاس', 'كبيرة', '× 2', '+ شوكولاتة', '+ كرت إهداء', 'تغليف ذهبي',
            'تنسيق مخصص', 'ورد أحمر', '× 6', '× 1.5', 'اللون: أحمر + أبيض', '(داخلي) ملاحظة المنسّق: شريط ذهبي عريض',
            'جهّزها قبل الخامسة', 'الاتصال قبل الوصول', 'لفّ الساق بشريط ساتان', 'عيد ميلاد', 'كلّ عام وأنتِ بخير', 'Happy birthday Maryam',
            'سالم', 'الاسم مخفيّ عن المستلم', 'قائمة التحقق', 'التغليف', 'مراجعة التعليمات والبطاقة', 'أُشِّر: '.$this->owner->name,
            'للاستخدام الداخلي فقط — ليست فاتورة ولا سند تسليم.',
        ] as $mark) {
            $this->assertStringContainsString($mark, $text, $mark);
        }

        foreach (self::MONEY as $money) {
            $this->assertStringNotContainsString($money, $text, "لا مال: {$money}");
        }
    }

    public function test_the_payload_itself_carries_no_money_field(): void
    {
        $sheet = PrepSheet::forOrder($this->order(), []);
        $keys = [];
        array_walk_recursive($sheet, function ($v, $k) use (&$keys) {
            $keys[] = (string) $k;
        });
        $walk = function (array $a) use (&$walk, &$keys) {
            foreach ($a as $k => $v) {
                $keys[] = (string) $k;
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($sheet);

        foreach (['price', 'total', 'tax', 'subtotal', 'discount', 'payment_status', 'payment_method', 'cost', 'unit_price'] as $money) {
            $this->assertNotContains($money, $keys, "الحمولةُ لا تحمل «{$money}»");
        }
    }

    public function test_an_english_sheet_names_the_addons_as_they_were_sold_in_english(): void
    {
        $order = $this->order();

        // ولغةُ الورقة لغةُ جلسة من يطبعها — كما تقرّرها `SetLocale` في كلّ طلب
        $pdf = $this->withSession(['locale' => 'en'])->get(route('admin.preparation.sheet', $order->number))->assertOk()->getContent();
        app()->setLocale('en');
        $text = implode(' ', array_column(PdfText::pages($pdf), 'text'));

        // من لقطة البيع — `order_item_addons.name_en` — لا من كتالوج اليوم
        $this->assertStringContainsString('Chocolate', $text);
        $this->assertStringContainsString('Greeting card', $text);
        $this->assertStringContainsString('Happy birthday Maryam', $text);
        $this->assertStringContainsString('Internal use only', $text);
        foreach (['25.750', '64.500', '100.931', '96.125'] as $money) {
            $this->assertStringNotContainsString($money, $text, "لا مال: {$money}");
        }

        $html = PrepSheet::html($this->shop->id, PrepSheet::forOrder($order, []), 80);
        $this->assertStringContainsString('dir="ltr"', $html, 'الورقةُ الإنجليزيّةُ من اليسار');
    }

    public function test_sixty_items_and_more_print_whole_on_one_strip(): void
    {
        $this->strip(58);
        $order = $this->order(70);

        $pdf = $this->get(route('admin.preparation.sheet', $order->number))->assertOk()->getContent();
        $pages = PdfText::pages($pdf);

        foreach ($pages as $page) {
            $this->assertEqualsWithDelta(58, $page['width'], 0.2);
        }
        $this->assertSame([], PdfText::wordCuts($pdf));

        $html = self::text(PrepSheet::html($this->shop->id, PrepSheet::forOrder($order, []), 58));
        $this->assertStringContainsString('صنف إضافي رقم 70', $html, 'آخرُ صنفٍ حاضر');
        $this->assertStringContainsString('للاستخدام الداخلي فقط', $html, 'والذيلُ بعده');
    }

    /* ============================== لا أثر ============================== */

    public function test_printing_moves_nothing_marks_nothing_and_queues_nothing(): void
    {
        $order = $this->order();
        PrepChecklist::set($order, PrepChecklist::TASK_PACKAGING, true);
        $before = [
            $order->fresh()->status,
            OrderPrepCheck::where('order_id', $order->id)->pluck('key')->sort()->values()->all(),
            PrintJob::count(),
            DB::table('journal_entries')->count(),
            DB::table('inventory_movements')->count(),
            $this->bouquet->fresh()->quantity,
        ];

        $this->get(route('admin.preparation.sheet', $order->number))->assertOk();

        $this->assertSame($before, [
            $order->fresh()->status,
            OrderPrepCheck::where('order_id', $order->id)->pluck('key')->sort()->values()->all(),
            PrintJob::count(),
            DB::table('journal_entries')->count(),
            DB::table('inventory_movements')->count(),
            $this->bouquet->fresh()->quantity,
        ]);
    }

    /* ============================== من يطبعها ============================== */

    public function test_whoever_prepares_prints_it_and_whoever_does_not_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->user('delivery', ['preparation']))
            ->get(route('admin.preparation.sheet', $order->number))->assertOk();

        $this->actingAs($this->user('sales', ['dashboard', 'orders']))
            ->get(route('admin.preparation.sheet', $order->number))->assertForbidden();
    }

    public function test_another_shop_and_another_branch_do_not_reach_it(): void
    {
        $order = $this->order();

        $this->actingAs($this->user('admin', null, $this->other))
            ->get(route('admin.preparation.sheet', $order->number))->assertNotFound();

        $elsewhere = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع صحار']);
        $this->actingAs($this->owner)->withSession(['current_branch' => $elsewhere->id])
            ->get(route('admin.preparation.sheet', $order->number))->assertNotFound();
    }

    public function test_a_closed_order_is_off_the_board_and_off_the_sheet(): void
    {
        $order = $this->order(0, ['status' => OrderStatus::DELIVERED]);

        $this->get(route('admin.preparation.sheet', $order->number))->assertNotFound();
    }

    /* ============================== مهمّةُ فاتورة العميل ============================== */

    private function invoiceTask(): CustomerInvoicePreparation
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $customer, ['issued_at' => '2026-10-20', 'notes' => 'تُسلَّم للاستقبال'], [
            ['description' => 'باقة الاستقبال الكبرى', 'quantity' => 3, 'unit_price' => 41.75],
        ]));
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id, 'scheduled_for' => '2026-10-20 16:00',
            'recipient_name' => 'مكتب الاستقبال', 'internal_notes' => 'شريط بلون الوزارة',
        ], $this->owner->id);
        CustomerPayments::record($this->shop->id, $customer, (float) $invoice->total, ['method' => 'نقدي', 'occurred_at' => '2026-10-20'], [$invoice->id => (float) $invoice->total]);
        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_HAND);

        return $task;
    }

    public function test_a_customer_invoice_task_prints_its_sheet_by_its_task_id_with_blank_boxes(): void
    {
        $task = $this->invoiceTask();
        $jobs = PrintJob::count();

        $pdf = $this->get(route('admin.preparation.invoiceSheet', $task->id))->assertOk()->getContent();
        $this->assertCount(1, PdfText::pages($pdf));
        $this->assertSame($jobs, PrintJob::count(), 'لا ورقةَ في طابور الصندوق');

        $text = self::text(PrepSheet::html($this->shop->id, PrepSheet::forInvoiceTask($task, $task->invoice), 80));
        foreach (['ورقة تجهيز داخلية', 'رقم الفاتورة', (string) $task->invoice->number, 'فاتورة عميل', 'باقة الاستقبال الكبرى', '× 3', 'استلام من المحل', 'مكتب الاستقبال', 'تُسلَّم للاستقبال', 'شريط بلون الوزارة', 'أشّر المربّعات بالقلم'] as $mark) {
            $this->assertStringContainsString($mark, $text, $mark);
        }
        foreach (['41.750', (string) number_format((float) $task->invoice->total, 3), 'مدفوعة', 'نقدي'] as $money) {
            $this->assertStringNotContainsString($money, $text, "لا مال: {$money}");
        }
        $this->assertStringNotContainsString('أُشِّر:', $text, 'لا علامةَ محفوظة لمهمّة الفاتورة');
    }

    public function test_a_task_sheet_is_closed_while_the_feature_is_off_for_the_shop(): void
    {
        $task = $this->invoiceTask();
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();

        $this->get(route('admin.preparation.invoiceSheet', $task->id))->assertNotFound();
    }
}
