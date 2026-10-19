<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\InvoiceBranding;
use App\Support\InvoiceLineDetails;
use App\Support\Ledger;
use App\Support\PrepSheet;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * بندُ فاتورة العميل يحفظ ما اختير معه — ويُقرأ في كلّ ورقة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - **الحفظ:** المقاسُ من مقاسات الصنف، والإضافةُ ممّا يُباع معه، وثمنُها
 *     واسمُها من الكتالوج لا من المتصفّح؛ والمدفوعةُ سطرٌ تحت صنفها، والمشمولةُ
 *     في الثمن تُسمّى بلا مال؛ والخياراتُ والملاحظةُ لقطة.
 *   - **«ضمن السعر»:** لما ثمنُه في الكتالوج صفرٌ وحده. مدفوعةٌ معلَّمةٌ به
 *     تُردّ ولا يُكتب شيء — لموظّفٍ ولا لصاحب النشاط — فلا ينقص إيرادٌ ولا ضريبة.
 *   - **المال:** الإجماليُّ يجمع الصنفَ وإضافاتِه المدفوعةَ مرّةً واحدة، شاملًا
 *     للضريبة وغيرَ شامل، والمشمولةُ لا تُحسب، ولا قيدَ ولا مخزونَ للعرض.
 *   - **الردّ:** مقاسُ صنفٍ آخر، وإضافةٌ لا تُباع معه، وإضافةٌ مع بندٍ يدويّ،
 *     وإضافةُ متجرٍ آخر — تُردّ ولا يُكتب شيء. والمعاينةُ تُسقطها وترسم الباقي.
 *   - **الأوراق:** A4 (PDF حقيقيّ، والإنجليزيّةُ بأسمائها الإنجليزيّة)، والمعاينة،
 *     وصفحةُ الفاتورة، والنسخةُ الداخليّة بمالها، وسندُ التسليم بلا مال ولا
 *     خيارٍ داخليّ، وورقةُ التجهيز بلا مال.
 *   - **التاريخ:** الفاتورةُ القديمةُ تُرسم كما كانت، ومن الطلب تُربط إضافاتُه
 *     ببنده — والقديمةُ منه تُقرأ بـ`order_item_id` لا بتشابه اسم — ونصُّ
 *     الوصف يُكتب بالعربيّة ولو كانت شاشةُ الكاشير إنجليزيّة.
 */
class ACustomerInvoiceKeepsWhatWasChosenWithEachLineTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Product $bouquet;

    private Product $vase;

    private ProductVariant $large;

    private Addon $chocolate;

    private Addon $card;

    private Addon $ribbon;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الندى', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@lines.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        $this->bouquet = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة ورد', 'name_en' => 'Rose Bouquet', 'price' => 20, 'cost' => 8, 'quantity' => 30, 'alert_qty' => 1]);
        $this->vase = Product::create(['business_id' => $this->shop->id, 'name' => 'مزهرية', 'price' => 12, 'cost' => 5, 'quantity' => 30, 'alert_qty' => 1]);
        $this->large = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bouquet->id, 'name' => 'كبيرة', 'name_en' => 'Large', 'price' => 25, 'active' => true]);

        $this->chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3.5, 'active' => true]);
        // مجّانيٌّ في الكتالوج — وهو وحدَه ما يُشمل في ثمن الصنف
        $this->card = Addon::create(['business_id' => $this->shop->id, 'name' => 'كرت إهداء', 'name_en' => 'Greeting card', 'price' => 0, 'active' => true]);
        // تُباع مع ما اختير لها وحده — ولم يُختر لها شيء
        $this->ribbon = Addon::create(['business_id' => $this->shop->id, 'name' => 'شريطة', 'price' => 2, 'active' => true, 'scope' => Addon::SCOPE_SELECTED]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    /** باقةٌ كبيرةٌ بشوكولاتتين مدفوعتين وكرتٍ ضمن ثمنها، بخيارٍ وملاحظة — وبندٌ يدويّ */
    private function lines(array $over = []): array
    {
        return [
            array_merge([
                'product_id' => $this->bouquet->id, 'variant_id' => $this->large->id, 'description' => 'باقة ورد',
                'quantity' => 1, 'unit_price' => 25, 'discount' => 0,
                'note' => 'Gold wrapping', 'options' => [['label' => 'Colour', 'value' => 'Red and white']],
                'addons' => [
                    ['addon_id' => $this->chocolate->id, 'quantity' => 2],
                    ['addon_id' => $this->card->id, 'quantity' => 1, 'included' => true],
                ],
            ], $over),
            ['description' => 'تنسيق طاولة الاستقبال', 'quantity' => 1, 'unit_price' => 10, 'note' => 'قبل العاشرة'],
        ];
    }

    private function form(array $lines, array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id, 'issued_at' => '2026-10-20', 'payment_method' => 'آجل', 'issue' => true,
            'form_token' => 'f-'.uniqid(), 'items' => $lines,
        ], $over);
    }

    private function issued(array $lines): CustomerInvoice
    {
        $this->post(route('admin.customerInvoices.store'), $this->form($lines))->assertSessionHasNoErrors();

        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->with('items')->firstOrFail();
    }

    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /* ============================== الحفظ والمال ============================== */

    public static function vat(): array
    {
        return ['غير شاملة' => [false], 'شاملة' => [true]];
    }

    #[DataProvider('vat')]
    public function test_the_lines_keep_what_was_chosen_and_the_money_counts_each_paid_addon_once(bool $inclusive): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_rate'], ['value' => '5']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => $inclusive ? 'inclusive' : 'exclusive']);
        $this->assertSame($inclusive, Vat::inclusive($this->shop->id));

        // ثمنُ الإضافة من المتصفّح لا يُقرأ: شوكولاتةٌ «بصفر» تُكتب بثمنها
        $invoice = $this->issued($this->lines(['addons' => [
            ['addon_id' => $this->chocolate->id, 'quantity' => 2, 'unit_price' => 0],
            ['addon_id' => $this->card->id, 'quantity' => 1, 'included' => true],
        ]]));

        $items = $invoice->items->sortBy('sort_order')->values();
        $this->assertCount(3, $items, 'الصنف، وإضافتُه المدفوعة، والبندُ اليدويّ — والمشمولةُ لا سطرَ لها');

        [$main, $addon, $manual] = [$items[0], $items[1], $items[2]];
        $this->assertSame('باقة ورد — كبيرة', $main->description);
        $this->assertSame(['id' => $this->large->id, 'name' => 'كبيرة', 'name_en' => 'Large'], $main->details['variant']);
        $this->assertSame('Rose Bouquet — Large', $main->details['label_en']);
        $this->assertSame('Gold wrapping', $main->details['note']);
        $this->assertSame([['label' => 'Colour', 'value' => 'Red and white', 'internal' => false]], $main->details['options']);
        $this->assertSame('كرت إهداء', $main->details['included'][0]['name']);

        $this->assertSame((int) $main->id, (int) $addon->parent_item_id);
        $this->assertSame((int) $this->chocolate->id, (int) $addon->addon_id);
        $this->assertNull($addon->product_id);
        $this->assertEqualsWithDelta(3.5, (float) $addon->unit_price, 0.0001, 'ثمنُها من الكتالوج');
        $this->assertEqualsWithDelta(2, (float) $addon->quantity, 0.0001);
        $this->assertEqualsWithDelta((float) $main->tax_rate, (float) $addon->tax_rate, 0.0001, 'بضريبة صنفها');
        $this->assertSame('Chocolate', $addon->details['label_en']);

        $this->assertNull($manual->parent_item_id);
        $this->assertSame('قبل العاشرة', $manual->details['note']);

        // والمالُ مرّةً واحدة: ٢٥ + (٢ × ٣٫٥) + ١٠ — والكرتُ داخل الثمن لا يُحسب
        $expect = CustomerInvoices::compute($this->shop->id, [
            ['quantity' => 1, 'unit_price' => 25], ['quantity' => 2, 'unit_price' => 3.5], ['quantity' => 1, 'unit_price' => 10],
        ]);
        $this->assertEqualsWithDelta($expect['total'], (float) $invoice->total, 0.0005);
        $this->assertEqualsWithDelta($expect['tax'], (float) $invoice->tax_total, 0.0005);
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $invoice->items->sum('line_total'), 0.0005, 'البنودُ تجمع إلى إجماليّ الورقة');
    }

    public static function refusals(): array
    {
        return [
            'مقاسُ صنفٍ آخر' => ['variant'],
            'إضافةٌ لا تُباع مع الصنف' => ['scoped'],
            'إضافةٌ مع بندٍ يدويّ' => ['manual'],
            'إضافةُ متجرٍ آخر' => ['foreign'],
            'مدفوعةٌ «ضمن السعر»' => ['paidIncluded'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_what_the_catalog_does_not_allow_is_refused_and_nothing_is_written(string $case): void
    {
        $foreign = Addon::create(['business_id' => $this->other->id, 'name' => 'إضافة الجار', 'price' => 1, 'active' => true]);
        $vaseSize = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->vase->id, 'name' => 'وسط', 'price' => 13, 'active' => true]);

        $lines = match ($case) {
            'variant' => $this->lines(['variant_id' => $vaseSize->id]),
            'scoped' => $this->lines(['addons' => [['addon_id' => $this->ribbon->id, 'quantity' => 1]]]),
            'manual' => [['description' => 'بندٌ يدويّ', 'quantity' => 1, 'unit_price' => 5, 'addons' => [['addon_id' => $this->chocolate->id, 'quantity' => 1]]]],
            'foreign' => $this->lines(['addons' => [['addon_id' => $foreign->id, 'quantity' => 1]]]),
            'paidIncluded' => $this->lines(['addons' => [['addon_id' => $this->chocolate->id, 'quantity' => 2, 'included' => true]]]),
        };

        $before = [CustomerInvoice::count(), CustomerInvoiceItem::count(), DB::table('journal_entries')->count()];

        $this->post(route('admin.customerInvoices.store'), $this->form($lines))->assertSessionHasErrors('items');

        $this->assertSame($before, [CustomerInvoice::count(), CustomerInvoiceItem::count(), DB::table('journal_entries')->count()]);
    }

    /**
     * «ضمن السعر» لا يُعطي مدفوعةً مجّانًا — لا لموظّفٍ ولا لصاحب النشاط.
     *
     * لا قاعدةَ في الكتالوج تشمل إضافةً بثمنٍ في صنف، ولا فعلَ يأذن به: فالعلَمُ
     * على شوكولاتةٍ بثلاثة ونصف يُردّ ولا يُكتب شيء. وبثمنها تُحسب مرّةً بضريبة
     * صنفها؛ والكرتُ المجّانيُّ في الكتالوج وحده يُشمل — فلا ينقص إيرادٌ ولا ضريبة.
     */
    #[DataProvider('vat')]
    public function test_a_paid_addon_is_never_included_free_so_the_revenue_and_its_tax_stay_whole(bool $inclusive): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_rate'], ['value' => '5']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => $inclusive ? 'inclusive' : 'exclusive']);

        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'البائع', 'email' => 'clerk@lines.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط',
        ]);
        $books = fn () => [CustomerInvoice::count(), CustomerInvoiceItem::count(), DB::table('journal_entries')->count()];
        $before = $books();
        $paidAsFree = $this->lines(['addons' => [['addon_id' => $this->chocolate->id, 'quantity' => 2, 'included' => true]]]);

        // البائعُ مسودّةً، وصاحبُ النشاط مُصدِرًا — كلاهما يُردّ
        $this->actingAs($clerk)->post(route('admin.customerInvoices.store'), $this->form($paidAsFree, ['issue' => false]))->assertSessionHasErrors('items');
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->form($paidAsFree))->assertSessionHasErrors('items');
        $this->assertSame($before, $books(), 'لا فاتورةَ ولا بندَ ولا قيد');

        // وبثمنها: الشوكولاتتان سطرٌ بسبعةٍ وضريبةِ صنفها
        $paid = $this->issued($this->lines(['addons' => [['addon_id' => $this->chocolate->id, 'quantity' => 2]]]));
        $expect = CustomerInvoices::compute($this->shop->id, [
            ['quantity' => 1, 'unit_price' => 25], ['quantity' => 2, 'unit_price' => 3.5], ['quantity' => 1, 'unit_price' => 10],
        ]);
        foreach (['subtotal' => 'subtotal', 'tax' => 'tax_total', 'total' => 'total'] as $key => $column) {
            $this->assertEqualsWithDelta($expect[$key], (float) $paid->{$column}, 0.0005, $column);
        }

        // والكرتُ المجّانيُّ ضمن السعر لا يغيّر شيئًا: لا إيرادَ له ولا ضريبة
        $free = $this->issued($this->lines(['addons' => [['addon_id' => $this->card->id, 'quantity' => 1, 'included' => true]]]));
        $bare = CustomerInvoices::compute($this->shop->id, [['quantity' => 1, 'unit_price' => 25], ['quantity' => 1, 'unit_price' => 10]]);
        foreach (['subtotal' => 'subtotal', 'tax' => 'tax_total', 'total' => 'total'] as $key => $column) {
            $this->assertEqualsWithDelta($bare[$key], (float) $free->{$column}, 0.0005, $column);
        }
        $this->assertCount(2, $free->items, 'الصنفُ والبندُ اليدويّ — والمشمولُ لا سطرَ له');
        $this->assertSame('كرت إهداء', $free->items->firstWhere('parent_item_id', null)->details['included'][0]['name']);
    }

    public function test_the_preview_draws_the_same_details_and_drops_what_is_not_allowed(): void
    {
        $wrap = Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف فاخر', 'price' => 2, 'active' => true]);

        $html = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id,
            'items' => $this->lines(['addons' => [
                ['addon_id' => $this->chocolate->id, 'quantity' => 2],
                ['addon_id' => $this->card->id, 'quantity' => 1, 'included' => true],
                ['addon_id' => $this->ribbon->id, 'quantity' => 1],
                ['addon_id' => $wrap->id, 'quantity' => 1, 'included' => true],
            ]]),
        ])->assertOk()->json('html');

        $text = self::text($html);
        foreach (['باقة ورد — كبيرة', 'ملاحظة المنتج: Gold wrapping', 'Colour: Red and white', '+ شوكولاتة', '+ كرت إهداء × 1 — ضمن السعر', 'تنسيق طاولة الاستقبال', 'قبل العاشرة'] as $mark) {
            $this->assertStringContainsString($mark, $text, $mark);
        }
        $this->assertStringNotContainsString('شريطة', $text, 'إضافةٌ لا تُباع مع الصنف تسقط من المعاينة');
        $this->assertStringNotContainsString('تغليف فاخر', $text, 'ومدفوعةٌ «ضمن السعر» تسقط — لا تُرسم مجّانًا');
        $this->assertSame(0, CustomerInvoice::count(), 'المعاينةُ لا تكتب ورقة');
    }

    /* ============================== الأوراق ============================== */

    public function test_the_a4_pdf_prints_each_addon_under_its_product_in_the_papers_language(): void
    {
        $invoice = $this->issued($this->lines());
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => InvoiceBranding::LANGUAGE], ['value' => 'en']);

        $pdf = $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk()->getContent();
        $text = implode(' ', array_column(PdfText::pages($pdf), 'text'));

        foreach (['Rose Bouquet — Large', 'Chocolate', 'Greeting card', 'Gold wrapping', 'Red and white'] as $mark) {
            $this->assertStringContainsString($mark, $text, $mark);
        }

        $lines = PdfText::lines($pdf);
        $y = fn (string $needle) => collect($lines)->first(fn ($l) => str_contains($l['text'], $needle))['y'] ?? null;
        $this->assertNotNull($y('Rose Bouquet'));
        $this->assertGreaterThan($y('Chocolate'), $y('Rose Bouquet'), 'الإضافةُ تحت صنفها في الورقة');
    }

    public function test_the_invoice_page_lists_the_same_rows(): void
    {
        $invoice = $this->issued($this->lines());

        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.items.0.description', 'باقة ورد — كبيرة')
            ->where('invoice.items.0.child', false)
            ->where('invoice.items.0.details.0.text', 'Gold wrapping')
            ->where('invoice.items.0.included.0.name', 'كرت إهداء')
            ->where('invoice.items.1.description', 'شوكولاتة')
            ->where('invoice.items.1.child', true)
            ->where('invoice.items.2.description', 'تنسيق طاولة الاستقبال'));
    }

    private function prepared(): CustomerInvoicePreparation
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $invoice = $this->issued($this->lines());

        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'delivery', 'branch_id' => $branch->id, 'scheduled_for' => '2026-10-20 16:00',
            'recipient_name' => 'مريم', 'recipient_phone' => '99887766', 'delivery_address' => 'الخوير',
        ], $this->owner->id);
        CustomerPayments::record($this->shop->id, $this->customer, (float) $invoice->total, ['method' => 'نقدي', 'occurred_at' => '2026-10-20'], [$invoice->id => (float) $invoice->total]);
        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_HAND);

        return $task->fresh(['invoice.items', 'branch']);
    }

    public function test_the_internal_copy_shows_the_details_with_their_money_and_the_delivery_note_without(): void
    {
        $task = $this->prepared();
        $invoice = $task->invoice;

        $internal = self::text(CustomerInvoicePreparations::internalHtml($task, $invoice, 80));
        foreach (['باقة ورد — كبيرة', 'ملاحظة المنتج: Gold wrapping', 'Colour: Red and white', '+ شوكولاتة', '3.500', 'كرت إهداء × 1 — ضمن السعر'] as $mark) {
            $this->assertStringContainsString($mark, $internal, 'الداخليّة: '.$mark);
        }

        $delivery = self::text(CustomerInvoicePreparations::deliveryHtml($task, $invoice, 80));
        foreach (['باقة ورد — كبيرة', 'Gold wrapping', 'Colour: Red and white', 'شوكولاتة', 'كرت إهداء'] as $mark) {
            $this->assertStringContainsString($mark, $delivery, 'السند: '.$mark);
        }
        foreach (['3.500', '7.350', '25.000', 'ضمن السعر', (string) number_format((float) $invoice->total, 3)] as $money) {
            $this->assertStringNotContainsString($money, $delivery, "لا مال على السند: {$money}");
        }

        $sheet = self::text(PrepSheet::html($this->shop->id, PrepSheet::forInvoiceTask($task, $invoice), 80));
        foreach (['باقة ورد — كبيرة', '+ شوكولاتة', '× 2', '+ كرت إهداء', 'Gold wrapping', 'Colour: Red and white'] as $mark) {
            $this->assertStringContainsString($mark, $sheet, 'ورقة التجهيز: '.$mark);
        }
        foreach (['3.500', '7.000', '25.000'] as $money) {
            $this->assertStringNotContainsString($money, $sheet, "لا مال على ورقة التجهيز: {$money}");
        }
    }

    public function test_an_internal_option_reaches_the_bench_but_never_the_recipient(): void
    {
        $task = $this->prepared();
        $main = $task->invoice->items->firstWhere('product_id', $this->bouquet->id);
        $main->forceFill(['details' => array_merge($main->details, ['options' => [['label' => 'Florist', 'value' => 'Bench secret', 'internal' => true]]])])->save();
        $invoice = $task->invoice->fresh('items');

        $this->assertStringContainsString('Bench secret', self::text(CustomerInvoicePreparations::internalHtml($task, $invoice, 80)));
        $this->assertStringContainsString('Bench secret', self::text(PrepSheet::html($this->shop->id, PrepSheet::forInvoiceTask($task, $invoice), 80)));
        $this->assertStringNotContainsString('Bench secret', self::text(CustomerInvoicePreparations::deliveryHtml($task, $invoice, 80)));

        // ولا ورقةَ العميل A4 — من الـPDF نفسِه
        $pdf = $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Bench secret', implode(' ', array_column(PdfText::pages($pdf), 'text')));
        $this->assertStringContainsString('Gold wrapping', implode(' ', array_column(PdfText::pages($pdf), 'text')));
    }

    /* ============================== التاريخ ============================== */

    public function test_an_old_invoice_without_details_draws_as_it_always_did(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-01'], [
            ['description' => 'باقة قديمة', 'quantity' => 1, 'unit_price' => 20],
            ['addon_id' => $this->chocolate->id, 'description' => 'شوكولاتة', 'quantity' => 1, 'unit_price' => 3.5],
        ]))->load('items');
        $invoice->items->each(fn ($i) => $i->forceFill(['details' => null, 'parent_item_id' => null])->save());

        $rows = InvoiceLineDetails::rows($invoice->fresh('items')->items);

        $this->assertSame([false, false], array_column($rows, 'child'), 'بندٌ مستقلٌّ لا يُخترع له صاحب');
        $this->assertSame(['باقة قديمة', 'شوكولاتة'], array_column($rows, 'label'));
        $this->assertSame([[], []], array_column($rows, 'subs'));
    }

    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'number' => 'INV-'.uniqid(),
            'status' => 'مكتمل', 'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع',
            'subtotal' => 32, 'total' => 32, 'ordered_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->bouquet->id, 'name' => 'باقة ورد', 'variant_name' => 'كبيرة',
            'price' => 25, 'quantity' => 1, 'total' => 32, 'addons_total' => 7, 'note' => 'بشريط ذهبي',
        ]);
        OrderItemAddon::create(['order_item_id' => $item->id, 'addon_id' => $this->chocolate->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'unit_price' => 3.5, 'quantity' => 2, 'total' => 7]);

        return $order->fresh('items.addons');
    }

    public function test_an_invoice_from_an_order_links_its_addons_to_their_item_and_keeps_its_note(): void
    {
        $invoice = CustomerInvoices::fromOrder($this->order())->load('items');
        $items = $invoice->items->sortBy('sort_order')->values();

        $this->assertSame('باقة ورد — كبيرة', $items[0]->description);
        $this->assertSame('بشريط ذهبي', $items[0]->details['note']);
        $this->assertSame((int) $items[0]->id, (int) $items[1]->parent_item_id);
        $this->assertSame('Chocolate', $items[1]->details['label_en']);
        $this->assertSame([false, true], array_column(InvoiceLineDetails::rows($items), 'child'));
    }

    public function test_an_older_invoice_from_an_order_groups_its_addons_by_their_order_item_not_by_name(): void
    {
        $invoice = CustomerInvoices::fromOrder($this->order())->load('items');
        $invoice->items->each(fn ($i) => $i->forceFill(['details' => null, 'parent_item_id' => null])->save());

        $rows = InvoiceLineDetails::rows($invoice->fresh('items')->items->sortBy('sort_order')->values());

        $this->assertSame([false, true], array_column($rows, 'child'), 'من بند الطلب نفسِه — علاقةٌ محفوظة');
    }

    public function test_an_english_cashier_writes_the_invoice_words_in_the_ledgers_arabic(): void
    {
        app()->setLocale('en');
        $order = $this->order();
        $order->items->first()->forceFill(['addons_total' => 9])->save(); // جُبي غيرُ ما تقوله اللقطات: سطرٌ واحد «إضافات: …»

        $invoice = CustomerInvoices::fromOrder($order->fresh('items.addons'))->load('items');
        $combined = $invoice->items->first(fn ($i) => str_contains($i->description, 'شوكولاتة'));

        $this->assertStringStartsWith('إضافات: ', $combined->description, 'اللقطةُ بلغة الدفتر لا بلغة الكاشير');
        $this->assertStringStartsWith('Add-ons: Chocolate', $combined->details['label_en']);
    }

    public function test_reading_and_printing_the_lines_moves_no_money_and_no_stock(): void
    {
        $invoice = $this->issued($this->lines());
        $before = [DB::table('journal_entries')->count(), DB::table('inventory_movements')->count(), $this->bouquet->fresh()->quantity, (float) $invoice->fresh()->total];

        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk();
        $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk();

        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('inventory_movements')->count(), $this->bouquet->fresh()->quantity, (float) $invoice->fresh()->total]);
    }

    public function test_an_addons_price_is_read_from_the_catalog_even_when_a_price_is_sent(): void
    {
        $lines = InvoiceLineDetails::expand($this->shop->id, $this->lines(['addons' => [['addon_id' => $this->chocolate->id, 'quantity' => 2, 'unit_price' => 0]]]));

        $child = collect($lines)->firstWhere('addon_id', $this->chocolate->id);
        $this->assertEqualsWithDelta(3.5, $child['unit_price'], 0.0001);
        $this->assertSame('شوكولاتة', $child['description']);
    }

    public function test_the_expand_refusal_names_what_is_wrong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('هذه الإضافة لا تُباع مع هذا الصنف.');

        InvoiceLineDetails::expand($this->shop->id, $this->lines(['addons' => [['addon_id' => $this->ribbon->id]]]));
    }
}
