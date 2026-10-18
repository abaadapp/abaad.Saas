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
use App\Support\DocumentTemplates;
use App\Support\Ledger;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * الاسمُ العربيّ الطويل يلتفّ بين كلماته — لا داخلها، ولا يُصغَّر الجدولُ له.
 *
 * ═══ العطب ═══
 *
 * على شريط ٥٨ مم خرج «باقة ورد أحمر فاخرة مع تغلي» ثمّ «ف ذهبي». وفي الجدول
 * كان اسمٌ لا يتّسع لعموده يُصغّر جدولَ الأصناف كلَّه: ٢٫٩ نقطة بدل ٥٫٥ على
 * ٥٨ مم — حروفٌ لا تُقرأ على ورقٍ حراريّ، وإيصالٌ يبدو قصيرًا لأنّه مصغَّر.
 *
 * ═══ والسبب في الخطّ ═══
 *
 * دمجُ مقاطع «IBM Plex Sans Arabic» ترك قاعدة `locl` تُبدّل كلَّ مسافةٍ
 * بنسخةٍ لا رقمَ يونيكود لها (`space` ← `space.3`)، فيكتبها mpdf رمزًا خاصًّا
 * ومقطِّعُ أسطره لا يكسر إلّا عند `' '`: لا موضعَ كسرٍ في الجملة كلِّها.
 * أُصلح في الخطّ (`keep_spaces` في `scripts/build-document-font.py`) — لا
 * وسمَ يُضاف إلى الورقة، فلا يكبر الـHTML ولا يقترب من حدّ PCRE في mpdf.
 *
 * ═══ ما يُحرس — من الـPDF المرسوم نفسِه ═══
 *
 *   - الخطّ بوزنيه يكتب المسافةَ مسافة بعد التشكيل.
 *   - إيصالُ الصندوق والنسخةُ الداخليّة وسندُ التسليم على ٥٨ و٨٠ مم بأسماءٍ
 *     عربيّةٍ وإنجليزيّةٍ ومختلطةٍ طويلة، وإضافاتٍ ومقاساتٍ وملاحظات، وعنوانٍ
 *     ورسالةِ بطاقةٍ طويلين: لا سطرَ قُطعت فيه كلمة (`PdfText::wordCuts`)، ولا
 *     حجمَ خطٍّ لا تكتبه الورقةُ نفسُها بأسماءٍ قصيرة (لا جدولَ مصغَّر)،
 *     وتلتفّ فعلًا، وصفحةٌ واحدة بعرض الشريط.
 *
 * والإيصالُ الطويل بمئتي صنفٍ وألف: `ALongReceiptPrintsWholeOnOneStripTest`.
 * والطابعةُ الحقيقيّة لا تُقاس هنا.
 */
class ALongArabicNameWrapsBetweenItsWordsTest extends TestCase
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
            'name' => 'ريبون للزهور', 'type' => 'عام', 'status' => 'نشط',
            'order_notes_and_edits_enabled' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع الرئيسي']);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم بن سعيد الحارثي', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->actingAs(User::create([
            'business_id' => $this->shop->id, 'name' => 'Owner', 'email' => 'owner@wrap.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    /* ============================== الخطّ ============================== */

    /** وزنان، ولغتان، وعباراتٌ تتّسع لسطرها — فكلُّ مسافةٍ كُتبت تُرسم مسافة */
    public function test_the_document_font_keeps_every_space_through_shaping(): void
    {
        $phrases = ['باقة ورد أحمر', 'Red Roses Box', 'الإجمالي المستحق', 'Grand Total'];
        $html = '<div>'.$phrases[0].'</div><div>'.$phrases[1].'</div>'
            .'<div><b>'.$phrases[2].'</b></div><div><b>'.$phrases[3].'</b></div>';

        $lines = PdfText::lines(Pdf::strip($html, 'spaces.pdf', 80)->getContent());

        $this->assertCount(4, $lines, 'كلُّ عبارةٍ في سطرها');
        $this->assertSame(
            [2, 2, 1, 1],
            array_map(fn (array $l) => substr_count($l['text'], ' '), $lines),
            'المسافةُ بعد التشكيل U+0020 — لا رمزٌ خاصّ لا يراه مقطِّعُ الأسطر',
        );
    }

    /* ============================== إيصالُ الصندوق ============================== */

    private function strip(int $mm): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => DocumentTemplates::key('sale', 'strip')],
            ['value' => "{$mm}mm"],
        );
    }

    /**
     * بيعٌ بأصنافه وإضافاتها كما يكتبها الصندوق.
     *
     * @param  list<array{name: string, price: float, qty?: int, variant?: string, note?: string, addons?: list<array{0: string, 1: float, 2: int}>}>  $items
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
            $charged = round(array_sum(array_map(fn ($a) => $a[1] * $a[2], $addons)), 3);

            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => Product::create(['business_id' => $this->shop->id, 'name' => $i['name'], 'price' => $i['price']])->id,
                'name' => $i['name'], 'variant_name' => $i['variant'] ?? null,
                'price' => $i['price'], 'quantity' => $qty, 'cost' => 0,
                'total' => round($i['price'] * $qty, 3), 'note' => $i['note'] ?? null,
                'addons_total' => $charged,
            ]);
            foreach ($addons as $a) {
                OrderItemAddon::create([
                    'order_item_id' => $item->id, 'name' => $a[0],
                    'unit_price' => $a[1], 'quantity' => $a[2], 'total' => round($a[1] * $a[2], 3), 'cost' => 0,
                ]);
            }
            $subtotal += $i['price'] * $qty + $charged;
        }
        $order->update(['subtotal' => round($subtotal, 3), 'total' => round($subtotal, 3)]);

        return $order->fresh('items.addons');
    }

    /** أسماءٌ طويلةٌ عربيّةٌ وإنجليزيّةٌ ومختلطة — بإضافاتٍ ومقاسٍ وملاحظة */
    private const LONG_SALE = [
        [
            'name' => 'باقة ورد أحمر فاخرة مع تغليف ذهبي وشريط حريري عريض', 'price' => 20,
            'variant' => 'كبيرة جدًا — خمسون وردة', 'note' => 'تغليف ذهبي من فضلكم مع بطاقة مكتوبة بخطّ اليد',
            'addons' => [['علبة شوكولاتة بلجيكية فاخرة بالبندق والكراميل', 3, 2], ['بطاقة تهنئة مطبوعة', 1, 1]],
        ],
        [
            'name' => 'Premium long-stem white roses with eucalyptus and silk ribbon', 'price' => 30,
            'variant' => 'Extra Large', 'addons' => [['Handwritten greeting card with envelope', 0.5, 1]],
        ],
        [
            'name' => 'باقة Premium Red Roses مع بطاقة تهنئة مكتوبة باليد', 'price' => 15, 'qty' => 2,
            'addons' => [['شوكولاتة Godiva صغيرة', 2, 1]],
        ],
    ];

    /** البيعُ نفسُه بأسماءٍ تتّسع لعمودها — حجومُ خطّه هي حجومُ القالب */
    private const SHORT_SALE = [
        ['name' => 'باقة ورد', 'price' => 20, 'variant' => 'كبيرة', 'note' => 'تغليف', 'addons' => [['شوكولاتة', 3, 2], ['بطاقة', 1, 1]]],
        ['name' => 'White Roses', 'price' => 30, 'variant' => 'Large', 'addons' => [['Card', 0.5, 1]]],
        ['name' => 'باقة Roses', 'price' => 15, 'qty' => 2, 'addons' => [['Godiva', 2, 1]]],
    ];

    /**
     * لا كلمةَ مقطوعة، ولا حجمَ خطٍّ لا تكتبه الورقةُ نفسُها بأسماءٍ قصيرة.
     *
     * والجدولُ الذي يُصغّره mpdf ليتّسع يكتب حجومًا مضروبةً في نسبة التصغير
     * (٢٫٨٨ و٣٫٠٦ …) لا يكتبها القالب — فتُعرف هنا بلا رقمٍ مثبَّت.
     */
    private function assertWrapsReadably(string $pdf, string $reference, string $case): void
    {
        $this->assertSame([], PdfText::wordCuts($pdf), "{$case}: سطرٌ قُطعت فيه كلمة");

        $sizes = fn (string $p) => array_values(array_unique(array_map(fn (array $l) => round($l['size'], 2), PdfText::lines($p))));
        $this->assertSame([], array_values(array_diff($sizes($pdf), $sizes($reference))), "{$case}: حجمُ خطٍّ لا يكتبه القالب — جدولٌ مصغَّر");

        $this->assertGreaterThan(count(PdfText::lines($reference)), count(PdfText::lines($pdf)), "{$case}: الاسمُ الطويل يلتفّ أسطرًا");
    }

    #[DataProvider('widths')]
    public function test_a_till_receipt_wraps_long_names_between_words_at_its_own_size(int $mm): void
    {
        $this->strip($mm);
        $pdf = PdfController::thermal($this->shop->id, $this->sale(self::LONG_SALE))->getContent();
        $reference = PdfController::thermal($this->shop->id, $this->sale(self::SHORT_SALE))->getContent();

        $this->assertWrapsReadably($pdf, $reference, "{$mm}mm");

        $pages = PdfText::pages($pdf);
        $this->assertCount(1, $pages);
        $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);

        // واللاتينيُّ كلُّه حاضرٌ بترتيبه — داخل سطرٍ عربيّ أيضًا
        foreach (['Premium', 'eucalyptus', 'ribbon', 'Extra Large', 'Handwritten', 'envelope', 'Premium Red', 'Godiva'] as $word) {
            $this->assertStringContainsString($word, $pages[0]['text'], "{$mm}mm: {$word}");
        }
    }

    /* ============================== ورقتا فاتورة العميل ============================== */

    /** @param  list<array{0: string, 1: int, 2: float, 3?: bool}>  $lines  الوصفُ والكميّةُ والثمنُ وأهو إضافة */
    private function prepared(array $lines, array $prep): array
    {
        $addon = Addon::create(['business_id' => $this->shop->id, 'name' => 'إضافة', 'price' => 3, 'active' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19', 'notes' => $prep['notes']], array_map(fn (array $l) => [
            'addon_id' => ($l[3] ?? false) ? $addon->id : null, 'description' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2],
        ], $lines)))->load('items');

        $preparation = (new CustomerInvoicePreparation)->forceFill([
            'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'branch_id' => $this->branch->id,
            'fulfillment_type' => 'delivery', 'scheduled_for' => '2026-10-19 16:00',
            'recipient_name' => $prep['recipient'], 'recipient_phone' => '99887766',
            'delivery_address' => $prep['address'], 'delivery_notes' => $prep['delivery'],
            'is_gift' => true, 'card_message' => $prep['card'], 'sender_name' => 'المرسل', 'hide_sender' => true,
        ]);

        return [$preparation, $invoice];
    }

    #[DataProvider('widths')]
    public function test_the_internal_invoice_and_its_delivery_note_wrap_between_words(int $mm): void
    {
        $this->strip($mm);

        [$prep, $invoice] = $this->prepared([
            ['باقة ورد أحمر فاخرة مع تغليف ذهبي وشريط حريري عريض — كبيرة جدًا', 1, 20],
            ['Premium long-stem white roses with eucalyptus and silk ribbon', 2, 30],
            ['علبة شوكولاتة بلجيكية فاخرة بالبندق والكراميل', 2, 3, true],
        ], [
            'notes' => 'تُسلَّم في قاعة الاستقبال الرئيسيّة بالطابق الثالث بعد التنسيق مع الأمن',
            'recipient' => 'مريم بنت عبدالله بن سالم البلوشيّة',
            'address' => 'الخوير الجنوبيّة، شارع ١٨ نوفمبر، مبنى رقم ٤٥، الطابق الثالث، شقّة ١٢ — بجانب مسجد السلطان قابوس',
            'delivery' => 'الاتّصال قبل الوصول بعشر دقائق، والبوّابة الخلفيّة مفتوحة بعد الرابعة عصرًا',
            'card' => 'كلّ عام وأنتِ بخير يا أغلى الناس، أتمنّى لكِ سنةً مليئةً بالفرح والصحّة والنجاح وتحقيق الأحلام كلّها. Happy birthday!',
        ]);
        [$shortPrep, $shortInvoice] = $this->prepared([
            ['باقة ورد', 1, 20], ['White Roses', 2, 30], ['شوكولاتة', 2, 3, true],
        ], ['notes' => 'الاستقبال', 'recipient' => 'مريم', 'address' => 'الخوير', 'delivery' => 'اتّصل', 'card' => 'مبروك! Happy birthday!']);

        foreach ([
            'internal' => fn (CustomerInvoicePreparation $p, CustomerInvoice $i) => CustomerInvoicePreparations::internalPdf($p, $i)->getContent(),
            'delivery' => fn (CustomerInvoicePreparation $p, CustomerInvoice $i) => CustomerInvoicePreparations::deliveryPdf($p, $i)->getContent(),
        ] as $paper => $render) {
            $pdf = $render($prep, $invoice);
            $this->assertWrapsReadably($pdf, $render($shortPrep, $shortInvoice), "{$paper} {$mm}mm");

            $pages = PdfText::pages($pdf);
            $this->assertCount(1, $pages, "{$paper} {$mm}mm");
            $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);
            // و«!» في آخر فقرةٍ عربيّة تُرسم يسارَ ما قبلها — اتّجاهُ الفقرة لا اتّجاهُ الكلمة
            $this->assertStringContainsString('Happy birthday', $pages[0]['text'], "{$paper} {$mm}mm: رسالةُ البطاقة كاملة");
            $this->assertStringContainsString('eucalyptus', $pages[0]['text']);
        }
    }
}
