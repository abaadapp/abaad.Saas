<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\DeliveryPaper;
use App\Support\DocumentTemplates;
use App\Support\ItemAddons;
use App\Support\Ledger;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * الشريطُ الحراريّ يقول ما بيع — بإضافاته ومقاسه وخياراته، ومالُه مرّةً واحدة.
 *
 * ═══ العطب ═══
 *
 * إيصالُ الصندوق كان يطبع الصنفَ بثمنه وحده: باقةٌ بيعت بشوكولاتة وبطاقة تخرج
 * «باقة ورد» بلا إضافاتها ولا مقاسها، وعمودُ الإجماليّ يقول ثمنَ الباقة دون ما
 * جُبي معها — فلا تجمع أسطرُه إلى «المجموع الفرعي» تحتها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - إيصالُ الصندوق: المقاسُ تحت الاسم؛ الإضافةُ المجبيّةُ سطرٌ بثمنها وكميّتها
 *     ومجموعها؛ التي داخل الثمن تُسمّى بلا مال؛ واللقطاتُ التي لا تطابق ما جُبي
 *     سطرٌ واحدٌ بما جُبي. وأسطرُ الصنف تجمع إلى ما دُفع فيه، والأسطرُ كلُّها إلى
 *     «المجموع الفرعي». والإضافةُ المحذوفةُ من الكتالوج تُطبع من لقطتها.
 *   - فاتورةُ العميل الداخليّة: بندُ الإضافة يُعلَّم «+» ومالُه مرّةً كما فُوتر،
 *     والمجاميعُ والضريبةُ مجاميعُ الفاتورة الأصليّة — والمجمَّعةُ من طلباتٍ كذلك.
 *   - سندُ التسليم: المقاسُ والإضافاتُ أسماءً بلا ثمن، والمرسلُ المخفيُّ مخفيّ.
 *   - ٥٨ و٨٠ مم، وخمسون صنفًا بإضافاتها على شريطٍ واحد — يُقرأ من الـPDF نفسِه.
 *
 * والطابعةُ الحقيقيّة لا تُقاس هنا.
 */
class AThermalPaperShowsWhatWasSoldWithItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Customer $customer;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'Ribbon Flowers', 'type' => 'عام', 'status' => 'نشط',
            'order_notes_and_edits_enabled' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'Muscat']);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'Salim', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs(User::create([
            'business_id' => $this->shop->id, 'name' => 'Owner', 'email' => 'owner@thermal.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function strip(int $mm): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => DocumentTemplates::key('sale', 'strip')],
            ['value' => "{$mm}mm"],
        );
    }

    /**
     * طلبٌ بأصنافه وإضافاتها كما يكتبها الصندوق.
     *
     * @param  list<array{name: string, price: float, qty?: int, variant?: string, note?: string, custom?: array, standalone?: Addon, addons?: list<array{0: string, 1: float, 2: int, 3?: ?Addon}>, addons_total?: float}>  $items
     */
    private function sale(array $items): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'POS-'.(++$this->n), 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'customer_id' => $this->customer->id, 'customer_name' => $this->customer->name,
            'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'ordered_at' => now(),
        ]);

        $subtotal = 0.0;
        foreach ($items as $i) {
            $qty = $i['qty'] ?? 1;
            $addons = $i['addons'] ?? [];
            $charged = $i['addons_total'] ?? round(array_sum(array_map(fn ($a) => $a[1] * $a[2], $addons)), 3);

            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => isset($i['custom']) || isset($i['standalone']) ? null : Product::create(['business_id' => $this->shop->id, 'name' => $i['name'], 'price' => $i['price']])->id,
                'standalone_addon_id' => ($i['standalone'] ?? null)?->id,
                'name' => $i['name'],
                'variant_name' => $i['variant'] ?? null,
                'price' => $i['price'], 'quantity' => $qty, 'cost' => 0,
                'total' => round($i['price'] * $qty, 3),
                'note' => $i['note'] ?? null,
                'custom_details' => $i['custom'] ?? null,
                'addons_total' => $charged,
            ]);

            foreach ($addons as $a) {
                OrderItemAddon::create([
                    'order_item_id' => $item->id, 'addon_id' => ($a[3] ?? null)?->id, 'name' => $a[0],
                    'unit_price' => $a[1], 'quantity' => $a[2], 'total' => round($a[1] * $a[2], 3), 'cost' => 0,
                ]);
            }

            $subtotal += $i['price'] * $qty + $charged;
        }

        $order->update(['subtotal' => round($subtotal, 3), 'total' => round($subtotal, 3)]);

        return $order->fresh('items.addons');
    }

    private function receiptHtml(Order $order): string
    {
        return PdfController::saleHtml($this->shop->id, $order, thermal: true)['html'];
    }

    /**
     * نصُّ الـPDF نفسِه — بواجهةٍ إنجليزيّة كاختبار الإيصال الطويل.
     *
     * العربيّةُ المُشكَّلة تُكتب في الملفّ رموزَ حروفٍ لا نصًّا، ومعها أرقامُ
     * سطرها (`PdfText`). فما يُقرأ من الملفّ هنا لاتينيٌّ وأرقام، والمبالغُ
     * تُقرأ أيضًا من الورقة نفسِها قبل رسمها (`rowTotals`).
     */
    private function receiptText(Order $order): string
    {
        app()->setLocale('en');

        try {
            return implode("\n", array_column(PdfText::pages(PdfController::thermal($this->shop->id, $order)->getContent()), 'text'));
        } finally {
            app()->setLocale('ar');
        }
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * عمودُ «الإجمالي» في جدول الأصناف — مبلغُ كلّ سطرٍ كما رُسم.
     *
     * @return list<float>
     */
    private static function rowTotals(string $html): array
    {
        preg_match('#<table class="items">(.*?)</table>#s', $html, $table);
        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $table[1] ?? '', $rows);

        $out = [];
        foreach ($rows[1] as $row) {
            if (preg_match_all('#<td class="l[^"]*"><span dir="ltr">([^<]+)</span>#u', $row, $cells) && $cells[1] !== []) {
                preg_match('/[\d,]+\.\d+|\d+/', str_replace(',', '', end($cells[1])), $num);
                $out[] = (float) ($num[0] ?? 0);
            }
        }

        return $out;
    }

    private const CUSTOM = [
        'v' => 2, 'mode' => 'budget',
        'template' => ['id' => 1, 'name' => 'Custom Arrangement', 'name_en' => 'Custom Arrangement'],
        'fields' => [
            ['label' => 'Colors', 'label_en' => 'Colors', 'internal' => false, 'values' => [['label' => 'Red and white', 'label_en' => 'Red and white']]],
            ['label' => 'Florist notes', 'label_en' => 'Florist notes', 'internal' => true, 'values' => [['label' => 'Staff only secret', 'label_en' => 'Staff only secret']]],
        ],
    ];

    /* ============================== إيصالُ الصندوق ============================== */

    public function test_a_product_without_addons_prints_as_before(): void
    {
        $order = $this->sale([['name' => 'Plain Bouquet', 'price' => 12]]);
        $html = $this->receiptHtml($order);

        $this->assertSame('none', ItemAddons::of($order->items->first())['mode']);
        $this->assertStringNotContainsString('data-addon', $html);
        $this->assertSame([12.0], self::rowTotals($html));
        $this->assertStringContainsString('Plain Bouquet', $this->receiptText($order));
    }

    public function test_paid_addons_print_under_their_product_with_their_own_price_once(): void
    {
        $choc = Addon::create(['business_id' => $this->shop->id, 'name' => 'Chocolate', 'price' => 3, 'active' => true]);
        $order = $this->sale([[
            'name' => 'Red Rose Bouquet', 'price' => 20, 'variant' => 'Large', 'note' => 'Gold wrapping',
            'addons' => [['Chocolate', 3, 2, $choc], ['Greeting Card', 1, 1]],
        ]]);

        $html = $this->receiptHtml($order);
        $this->assertSame(2, substr_count($html, 'data-addon="priced"'));

        // كلُّ سطرٍ بمالِه مرّةً — والأسطرُ تجمع إلى ما دُفع وإلى المجموع الفرعي
        $this->assertSame([20.0, 6.0, 1.0], self::rowTotals($html));
        $this->assertSame((float) $order->subtotal, array_sum(self::rowTotals($html)));
        $this->assertSame(27.0, $order->items->first()->lineTotal());

        $pdf = $this->receiptText($order);
        $this->assertSame(1, substr_count($pdf, 'Chocolate'));
        $this->assertSame(1, substr_count($pdf, 'Greeting Card'));
        $this->assertStringContainsString('Size: Large', $pdf);
        $this->assertStringContainsString('Gold wrapping', $pdf);
        $this->assertStringContainsString('6.000', $pdf);
        $this->assertSame(2, substr_count($pdf, '27.000'), 'المجموعُ الفرعيّ والإجماليّ وحدهما — لا سطرٌ يكتب الصنفَ بإضافاته فوق سطورها');
    }

    public function test_addons_included_in_the_price_are_named_without_money(): void
    {
        $order = $this->sale([[
            'name' => 'Custom Arrangement', 'price' => 30, 'custom' => self::CUSTOM,
            'addons' => [['Chocolate', 3, 2], ['Ribbon', 0.5, 1]], 'addons_total' => 0,
        ]]);

        $html = $this->receiptHtml($order);
        $this->assertSame('included', ItemAddons::of($order->items->first())['mode']);
        $this->assertSame(2, substr_count($html, 'data-addon="included"'));
        $this->assertSame([30.0], self::rowTotals($html), 'داخل الثمن: لا مالَ يُضاف');

        $text = self::text($html);
        $this->assertStringContainsString('Chocolate × 2', $text);
        $this->assertStringContainsString('Colors: Red and white', $text);
        $this->assertStringNotContainsString('Staff only secret', $text, 'الداخليُّ لا يبلغ ورقةَ الزبون');
        $this->assertStringNotContainsString('6.000', $this->receiptText($order));
    }

    public function test_snapshots_that_do_not_match_what_was_charged_print_one_line_with_the_charge(): void
    {
        $order = $this->sale([[
            'name' => 'Old Bouquet', 'price' => 20,
            'addons' => [['Chocolate', 3, 2], ['Card', 1, 1]], 'addons_total' => 5,
        ]]);

        $html = $this->receiptHtml($order);
        $this->assertSame(1, substr_count($html, 'data-addon="bundled"'));
        $this->assertSame([20.0, 5.0], self::rowTotals($html), 'ما جُبي فعلًا — لا يُوزَّع بالظنّ');
        $this->assertStringContainsString('Chocolate × 2، Card', self::text($html));
        $this->assertStringNotContainsString('6.000', $this->receiptText($order));
    }

    public function test_a_standalone_addon_line_prints_once_and_a_deleted_catalog_addon_keeps_its_snapshot(): void
    {
        $gift = Addon::create(['business_id' => $this->shop->id, 'name' => 'Gift Box', 'price' => 2, 'active' => true]);
        $vase = Addon::create(['business_id' => $this->shop->id, 'name' => 'Glass Vase', 'price' => 4, 'active' => true]);

        $order = $this->sale([
            ['name' => 'Gift Box', 'price' => 2, 'standalone' => $gift],
            ['name' => 'Tulip Bouquet', 'price' => 15, 'addons' => [['Glass Vase', 4, 1, $vase]]],
        ]);

        // حُذفت الإضافةُ من الكتالوج بعد البيع — والإيصالُ من لقطته
        $vase->delete();
        $order = $order->fresh('items.addons');
        $this->assertNull(OrderItemAddon::sole()->addon_id);

        $html = $this->receiptHtml($order);
        $this->assertSame([2.0, 15.0, 4.0], self::rowTotals($html));
        $pdf = $this->receiptText($order);
        $this->assertSame(1, substr_count($pdf, 'Gift Box'));
        $this->assertSame(1, substr_count($pdf, 'Glass Vase'));
    }

    public function test_an_item_quantity_above_one_keeps_its_addons_as_sold(): void
    {
        $order = $this->sale([[
            'name' => 'Daisy Bouquet', 'price' => 10, 'qty' => 3,
            'addons' => [['Chocolate', 3, 2]],
        ]]);

        // كميّةُ الإضافة كما بيعت للسطر — لا تُضرب في كميّة الصنف
        $html = $this->receiptHtml($order);
        $this->assertSame([30.0, 6.0], self::rowTotals($html));
        $this->assertSame(36.0, (float) $order->subtotal);

        // وسطرُها كسطر الصنف: الاسم، ثمنُ الواحدة، الكميّة، المجموع
        preg_match('#<tr data-addon="priced">(.*?)</tr>#s', $html, $row);
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $row[1], $cells);
        $cells = array_map(fn (string $c) => self::text($c), $cells[1]);
        $this->assertSame('+ Chocolate', $cells[0]);
        $this->assertStringContainsString('3.000', $cells[1]);
        $this->assertSame('2', $cells[2]);
        $this->assertStringContainsString('6.000', $cells[3]);
    }

    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    /** خمسون صنفًا بإضافتين لكلٍّ — شريطٌ واحد، وكلُّ إضافةٍ مرّة */
    #[DataProvider('widths')]
    public function test_fifty_items_with_their_addons_stay_one_strip(int $mm): void
    {
        $this->strip($mm);
        $items = [];
        for ($i = 1; $i <= 50; $i++) {
            $items[] = [
                'name' => "Item {$i} Premium long-stem roses with a long silk ribbon", 'price' => 10, 'qty' => $i % 3 + 1,
                'variant' => $i % 2 ? 'Large' : null,
                'addons' => [["Extra {$i}A chocolate box", 2, 1], ["Extra {$i}B card", 0.5, 2]],
            ];
        }
        $order = $this->sale($items);

        app()->setLocale('en');
        $pages = PdfText::pages(PdfController::thermal($this->shop->id, $order)->getContent());
        // والشريطُ بطول محتواه — يُقاس بالورقة نفسِها التي رُسمت
        $height = Pdf::stripHeight($this->receiptHtml($order), $mm);
        app()->setLocale('ar');
        $this->assertCount(1, $pages, 'شريطٌ واحد');
        $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);
        $this->assertEqualsWithDelta($height, $pages[0]['height'], 0.2);

        $text = $pages[0]['text'];
        for ($i = 1; $i <= 50; $i++) {
            $this->assertSame(1, substr_count($text, "Item {$i} Premium"), "{$mm}mm: الصنف {$i}");
            $this->assertSame(1, substr_count($text, "Extra {$i}A"), "{$mm}mm: إضافة {$i}A");
            $this->assertSame(1, substr_count($text, "Extra {$i}B"), "{$mm}mm: إضافة {$i}B");
        }
        $this->assertSame(1, substr_count($text, 'Subtotal'));
        $this->assertLessThan(strpos($text, 'Subtotal'), strpos($text, 'Extra 50B'));
        $this->assertSame((float) $order->subtotal, array_sum(self::rowTotals($this->receiptHtml($order))));
    }

    /* ============================== فاتورةُ العميل الداخليّة ============================== */

    private function prep(CustomerInvoice $invoice, array $over = []): CustomerInvoicePreparation
    {
        return (new CustomerInvoicePreparation)->forceFill($over + [
            'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'branch_id' => $this->branch->id,
            'fulfillment_type' => 'delivery', 'scheduled_for' => '2026-10-19 16:00',
            'recipient_name' => 'Maryam', 'recipient_phone' => '99887766', 'delivery_address' => 'Al Khuwair, Way 45',
            'is_gift' => true, 'card_message' => 'Happy birthday', 'sender_name' => 'Secret Sender', 'hide_sender' => true,
        ]);
    }

    #[DataProvider('widths')]
    public function test_the_internal_invoice_marks_its_addon_lines_and_matches_the_original_total(int $mm): void
    {
        $this->strip($mm);
        $choc = Addon::create(['business_id' => $this->shop->id, 'name' => 'Chocolate Box', 'price' => 3, 'active' => true]);
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'Rose Bouquet', 'price' => 20]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['product_id' => $rose->id, 'description' => 'Rose Bouquet', 'quantity' => 1, 'unit_price' => 20, 'discount' => 2],
            ['addon_id' => $choc->id, 'description' => 'Chocolate Box', 'quantity' => 2, 'unit_price' => 3],
        ]))->load('items');
        $prep = $this->prep($invoice);

        $paper = CustomerInvoicePreparations::internalPaper($prep, $invoice);
        $this->assertSame([false, true], array_column($paper['items'], 'addon'));

        $html = CustomerInvoicePreparations::internalHtml($prep, $invoice, $mm);
        $this->assertSame(1, substr_count($html, 'data-addon'));

        app()->setLocale('en');
        $pdf = PdfText::pages(CustomerInvoicePreparations::internalPdf($prep, $invoice)->getContent());
        app()->setLocale('ar');
        $this->assertCount(1, $pdf);
        $this->assertEqualsWithDelta($mm, $pdf[0]['width'], 0.2);
        $text = $pdf[0]['text'];
        $this->assertSame(1, substr_count($text, 'Chocolate Box'), 'بندُ الإضافة مرّةً — لا يُدمج ولا يتكرّر');
        $this->assertStringContainsString('6.000', $text);
        $this->assertStringContainsString(number_format((float) $invoice->total, 3), $text, 'إجماليُّ الفاتورة الأصليّة');
        $this->assertStringContainsString(number_format((float) $invoice->tax_total, 3), $text, 'وضريبتُها');
        $this->assertStringContainsString('2.000', $text, 'وخصمُ البند');
        $this->assertStringContainsString('Secret Sender', $text, 'النسخةُ الداخليّة تحمل المرسل — والمستلِمُ لا يراها');
    }

    /** وفاتورةٌ مجمَّعةٌ من طلباتٍ بإضافاتها: كلُّ سطرٍ مرّة، والمجاميعُ مجاميعُها */
    public function test_a_consolidated_historical_invoice_prints_each_addon_line_once_with_its_own_totals(): void
    {
        $a = $this->sale([['name' => 'Bouquet A', 'price' => 20, 'variant' => 'Small', 'addons' => [['Chocolate', 3, 2]]]]);
        $b = $this->sale([['name' => 'Bouquet B', 'price' => 10, 'addons' => [['Card', 1, 1]]]]);
        foreach ([$a, $b] as $o) {
            $o->update(['payment_method' => 'آجل', 'payment_status' => 'غير مدفوع']);
        }

        $invoice = CustomerInvoices::consolidate($this->customer, [$a->id, $b->id])->load('items');
        $lines = $invoice->items->pluck('description')->all();
        $this->assertSame(1, count(array_filter($lines, fn ($d) => str_starts_with($d, 'Chocolate'))));
        $this->assertTrue(collect($lines)->contains(fn ($d) => str_starts_with($d, 'Bouquet A — Small')), 'والمقاسُ مع اسمه في الفاتورة الجديدة');

        $paper = CustomerInvoicePreparations::internalPaper($this->prep($invoice), $invoice);
        $this->assertCount($invoice->items->count(), $paper['items']);
        $text = self::text(CustomerInvoicePreparations::internalHtml($this->prep($invoice), $invoice, 80));
        $this->assertSame(1, substr_count($text, 'Chocolate'));
        $this->assertStringContainsString(number_format((float) $invoice->total, 3), $text);
        $this->assertEqualsWithDelta((float) $invoice->subtotal, $invoice->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price - (float) $i->discount), 0.001);
    }

    /* ============================== سندُ التسليم ============================== */

    public function test_the_delivery_note_names_sizes_and_addons_without_any_money(): void
    {
        $gift = Addon::create(['business_id' => $this->shop->id, 'name' => 'Gift Box', 'price' => 2, 'active' => true]);
        $order = $this->sale([
            [
                'name' => 'Red Rose Bouquet', 'price' => 20, 'variant' => 'Large',
                'addons' => [['Chocolate', 3, 2], ['Greeting Card', 1, 1]],
            ],
            ['name' => 'Gift Box', 'price' => 2, 'standalone' => $gift],
            ['name' => 'Custom Arrangement', 'price' => 30, 'custom' => self::CUSTOM],
        ]);

        // والطلبُ كما يُقرأ بلا إضافاتٍ محمَّلة، أو بلا أصنافٍ محمَّلة أصلًا — الورقةُ نفسُها
        foreach ([$order, Order::with('items')->find($order->id), Order::find($order->id)] as $read) {
            [$item, $standalone, $custom] = DeliveryPaper::stripItems($read);
            $this->assertSame(['name', 'qty', 'note', 'options'], array_keys($item));
            $this->assertSame([
                ['label' => 'المقاس', 'value' => 'Large'],
                ['label' => 'الإضافات', 'value' => 'Chocolate × 2، Greeting Card'],
            ], $item['options']);

            // والإضافةُ المبيعةُ وحدَها تُعلَّم «+» — لا الصنفُ المخصَّص الذي لا منتجَ له
            $this->assertSame(['Red Rose Bouquet', '+ Gift Box', 'Custom Arrangement'], [$item['name'], $standalone['name'], $custom['name']]);

            // أسماءٌ وكميّاتٌ بلا ثمن — لا ثمنُ الإضافة ولا أنّها جُبيت
            foreach (['3.000', '6.000', '1.000', '20.000', '27.000'] as $money) {
                $this->assertStringNotContainsString($money, json_encode([$item, $standalone, $custom], JSON_UNESCAPED_UNICODE));
            }
        }
    }

    #[DataProvider('widths')]
    public function test_the_invoice_delivery_note_marks_addons_and_carries_no_price_and_no_hidden_sender(int $mm): void
    {
        $this->strip($mm);
        $choc = Addon::create(['business_id' => $this->shop->id, 'name' => 'Chocolate Box', 'price' => 3, 'active' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'Rose Bouquet', 'quantity' => 1, 'unit_price' => 20],
            ['addon_id' => $choc->id, 'description' => 'Chocolate Box', 'quantity' => 2, 'unit_price' => 3],
        ]))->load('items');
        $prep = $this->prep($invoice);

        $text = self::text(CustomerInvoicePreparations::deliveryHtml($prep, $invoice, $mm));
        $this->assertStringContainsString('+ Chocolate Box', $text);
        $this->assertStringContainsString('Rose Bouquet', $text);
        $this->assertStringNotContainsString('+ Rose Bouquet', $text, 'بندُ الفاتورة العاديّ لا يُعلَّم إضافةً');
        $this->assertStringContainsString('Happy birthday', $text);
        $this->assertStringNotContainsString('Secret Sender', $text, 'المرسلُ المخفيّ لا يبلغ المستلِم');
        $this->assertStringNotContainsString('Salim', $text, 'ولا يُكتب المشتري مكانَه');
        foreach (['20.000', '6.000', '3.000', number_format((float) $invoice->total, 3), number_format((float) $invoice->tax_total, 3)] as $money) {
            $this->assertStringNotContainsString($money, $text, "لا مال: {$money}");
        }

        app()->setLocale('en');
        $pdf = PdfText::pages(CustomerInvoicePreparations::deliveryPdf($prep, $invoice)->getContent());
        app()->setLocale('ar');
        $this->assertCount(1, $pdf);
        $this->assertEqualsWithDelta($mm, $pdf[0]['width'], 0.2);
        $this->assertStringContainsString('Chocolate Box', $pdf[0]['text']);
        $this->assertStringNotContainsString('20.000', $pdf[0]['text']);
        $this->assertStringNotContainsString('Secret Sender', $pdf[0]['text']);
    }
}
