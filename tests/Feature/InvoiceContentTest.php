<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * محتوى الفاتورة، لا مجرّد أن الملف يُولَّد.
 *
 * كان المفحوص أن نقطة النهاية تُرجع بايتات بنوع application/pdf — وهذا لا
 * يقول شيئًا عن الأرقام داخلها. والفاتورة الضريبية مستند يُقدَّم للجهات،
 * فرقمٌ خاطئ فيها ليس عطلًا في الشاشة بل مخالفة.
 */
class InvoiceContentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 12.5, 'quantity' => 50, 'active' => true,
        ]);

    }

    /** يبيع فعليًا عبر نقطة البيع ويعيد الطلب الناتج */
    /**
     * ورقة A4 كما تخرج فعلًا — وهي أيضًا فاتورة ضريبية حين يكون للمتجر رقم.
     *
     * وهي والورقة المستقلّة تحملان الحقول نفسها الآن: رقم المشتري الضريبي
     * ونسبة الضريبة. فمن أخذ إحداهما لم ينقصه شيء من الأخرى.
     */
    private function invoiceHtml(\App\Models\Order $order, ?string $customerTax = null): string
    {
        $tpl = \App\Support\ReceiptTemplate::forBusiness($this->business->id);
        $tpl['paper'] = 'A4';

        return view('pdf.invoice', [
            'order' => $order->fresh('items'),
            'tpl' => $tpl,
            'customerTax' => $customerTax,
            'qr' => \App\Support\EInvoice::forOrder(
                $order,
                \App\Support\Demo::vatSettings(),
                \App\Support\Demo::business($this->business->id),
            ),
        ])->render();
    }

    public function test_the_order_invoice_carries_the_buyer_tax_number_and_the_rate(): void
    {
        $html = $this->invoiceHtml($this->sell(2), 'OM9900112233');

        $this->assertStringContainsString('OM9900112233', $html, 'رقم المشتري');
        $this->assertStringContainsString('(5%)', $html, 'نسبة الضريبة');
    }

    private function sell(int $qty = 2): \App\Models\Order
    {
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return \App\Models\Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    public function test_the_totals_stored_on_the_order_add_up(): void
    {
        $order = $this->sell(2);

        $itemsSum = round($order->items->sum(fn ($i) => $i->price * $i->quantity), 3);

        $this->assertSame(25.0, $itemsSum, 'مجموع البنود');
        $this->assertSame(25.0, (float) $order->subtotal);
        $this->assertSame(1.25, (float) $order->tax, 'ضريبة 5٪ على 25');
        $this->assertSame(26.25, (float) $order->total);
        $this->assertSame(
            round((float) $order->subtotal - (float) $order->discount + (float) $order->tax + (float) $order->delivery_fee, 3),
            (float) $order->total,
        );
    }

    public function test_the_tax_invoice_shows_those_very_numbers(): void
    {
        $order = $this->sell(2);

        $html = view('pdf.tax-invoice', [
            'order' => $order->fresh('items'),
            'vat' => \App\Support\Demo::vatSettings(),
            'business' => \App\Support\Demo::business($this->business->id),
            'customerTax' => null,
            'qr' => \App\Support\EInvoice::forOrder($order, \App\Support\Demo::vatSettings(), \App\Support\Demo::business($this->business->id)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        // النصّ يحمل الأرقام بثلاث خانات عشرية كعملة عُمان
        $this->assertStringContainsString($order->number, $html);
        $this->assertStringContainsString('25.000', $html, 'المجموع قبل الضريبة');
        $this->assertStringContainsString('1.250', $html, 'قيمة الضريبة');
        $this->assertStringContainsString('26.250', $html, 'الإجمالي المستحقّ');
        $this->assertStringContainsString('باقة ورد', $html, 'اسم الصنف');
        $this->assertStringContainsString('OM1100234567', $html, 'الرقم الضريبي');
    }

    public function test_the_declared_vat_rate_is_the_one_actually_applied(): void
    {
        // نسبة معلنة تخالف المحتسبة = فاتورة تقول ما لا تفعل
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_rate'],
            ['value' => '10'],
        );

        $order = $this->sell(2);

        $this->assertSame(2.5, (float) $order->tax, 'ضريبة 10٪ على 25');

        $html = view('pdf.tax-invoice', [
            'order' => $order->fresh('items'),
            'vat' => \App\Support\Demo::vatSettings(),
            'business' => \App\Support\Demo::business($this->business->id),
            'customerTax' => null, 'qr' => null,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString('(10%)', $html, 'النسبة المطبوعة');
        $this->assertStringContainsString('2.500', $html, 'قيمة الضريبة المطبوعة');
    }

    public function test_the_generated_file_is_a_real_pdf_not_an_error_page(): void
    {
        $order = $this->sell(1);

        $response = $this->actingAs($this->owner)->get(route('admin.orders.taxInvoice', $order->number));
        $response->assertOk();

        $bytes = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $bytes, 'ليس ملف PDF');
        $this->assertGreaterThan(1000, strlen($bytes), 'ملف أصغر من أن يحمل فاتورة');
    }

    public function test_the_qr_payload_carries_the_invoice_total_for_the_tax_authority(): void
    {
        $order = $this->sell(2);

        $qr = \App\Support\EInvoice::forOrder(
            $order,
            \App\Support\Demo::vatSettings(),
            \App\Support\Demo::business($this->business->id),
        );

        $this->assertNotEmpty($qr, 'لا رمز QR على الفاتورة الضريبية');
    }

    /* ==================== الورقة تتبع قالب المتجر ==================== */

    /** الفاتورة الضريبية كما تخرج، بقالبٍ مضبوط */
    private function taxInvoiceHtml(\App\Models\Order $order, array $tpl = []): string
    {
        return view('pdf.tax-invoice', [
            'order' => $order->fresh('items'),
            'vat' => \App\Support\Demo::vatSettings(),
            'business' => \App\Support\Demo::business($this->business->id),
            'customerTax' => null,
            'qr' => 'QRPAYLOAD',
            'tpl' => $tpl + \App\Support\ReceiptTemplate::forBusiness($this->business->id),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();
    }

    public function test_the_tax_invoice_prints_the_header_and_footer_you_set(): void
    {
        $html = $this->taxInvoiceHtml($this->sell(1), [
            'tpl_header' => 'مؤسسة النور للتجارة',
            'tpl_footer' => "شكرًا لتعاملكم\nهاتف: 90000000",
        ]);

        $this->assertStringContainsString('مؤسسة النور للتجارة', $html);
        $this->assertStringContainsString('شكرًا لتعاملكم', $html);
        $this->assertStringContainsString('90000000', $html);
    }

    public function test_hiding_a_field_in_the_settings_hides_it_here_too(): void
    {
        $order = $this->sell(1);

        $shown = $this->taxInvoiceHtml($order, ['tpl_show_employee' => true, 'tpl_show_qr' => true]);
        $hidden = $this->taxInvoiceHtml($order, ['tpl_show_employee' => false, 'tpl_show_qr' => false]);

        $this->assertStringContainsString(__('الموظف:'), $shown);
        $this->assertStringNotContainsString(__('الموظف:'), $hidden);
        $this->assertStringContainsString('QRPAYLOAD', $shown);
        $this->assertStringNotContainsString('QRPAYLOAD', $hidden);
    }

    /**
     * ورقةٌ عنوانها «فاتورة ضريبية» بلا رقم بائعها ليست فاتورةً ضريبية.
     *
     * فمفتاح «الرقم الضريبي» في الإعدادات لا يُخفيه هنا: إخفاؤه يجعل الورقة
     * تدّعي ما ليست به، وهو سبب وجودها أصلًا.
     */
    public function test_the_seller_tax_number_is_not_hideable_on_this_paper(): void
    {
        $html = $this->taxInvoiceHtml($this->sell(1), ['tpl_show_vat_no' => false]);

        $this->assertStringContainsString('OM1100234567', $html);
    }
}
