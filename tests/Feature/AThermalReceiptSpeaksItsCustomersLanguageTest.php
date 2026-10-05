<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الإيصالُ الحراريّ في «قوالب الأوراق ‹ فاتورة البيع» — قالبٌ واحدٌ، بلغة من يُطبع له.
 *
 * ═══ ما يُحرس ═══
 *
 *   · الترويسةُ والتذييلُ بالعربيّة والإنجليزيّة، لكلّ متجرٍ قالبُه.
 *   · والفارغُ بالإنجليزيّة لا يُغيّر الورقة: يُطبع ما كان يُطبع قبلها حرفًا
 *     حرفًا — فمتجرٌ لم يضبط شيئًا لا يتغيّر إيصالُه.
 *   · ومقابضُ التاريخ والرقم الضريبيّ والرمز على ما كانت عليه — افتراضًا
 *     وإشعالًا وإطفاءً — ولكلّ متجرٍ ضبطُه.
 *   · الشعارُ والهاتفُ والموقعُ مقابضُ عرضٍ — والأصنافُ والمبالغُ والضريبةُ
 *     والدفعُ ورقمُ الفاتورة لا مقبضَ لها، تُطبع أبدًا.
 *   · والمعاينةُ بطلبٍ مثاليّ — لا زبونَ حقيقيٌّ في المحرّر.
 *
 * وأنّ إيصالَ الموقع هو إيصالُ الصندوق حرفًا حرفًا: `ACustomerWhoPaidByCardGetsTheShopsOwnReceiptTest`.
 */
class AThermalReceiptSpeaksItsCustomersLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'Flowers', 'status' => 'نشط',
            'phone' => '+968 9525 9066', 'city' => 'Muscat', 'site_slug' => 'ribbon',
        ]);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'Saud', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->order = $this->sale($this->shop, 'INV-000162');
    }

    private function sale(Business $shop, string $number, array $over = []): Order
    {
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'Khuwair']);

        $order = Order::create($over + [
            'business_id' => $shop->id, 'branch_id' => $branch->id, 'branch' => 'Khuwair',
            'number' => $number, 'status' => 'مكتمل', 'customer_name' => 'Real Customer Name',
            'employee_name' => 'Cashier', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 24, 'discount' => 2, 'tax' => 0, 'delivery_fee' => 1.5, 'total' => 23.5,
            'ordered_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'Rose Bouquet', 'price' => 12, 'quantity' => 2, 'total' => 24]);

        return $order->load('items');
    }

    /** الإيصالُ كما يُطبع — من الوصفة الواحدة (`PdfController::saleHtml`) — بلغةٍ، نصًّا بلا وسوم */
    private function strip(?Business $shop = null, ?Order $order = null, string $lang = 'ar'): string
    {
        $was = app()->getLocale();
        app()->setLocale($lang);

        try {
            $html = PdfController::saleHtml(($shop ?? $this->shop)->id, $order ?? $this->order, thermal: true)['html'];
        } finally {
            app()->setLocale($was);
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));
    }

    private function raw(array $values = [], string $lang = 'ar'): string
    {
        if ($values !== []) {
            DocumentTemplates::save($this->shop->id, 'sale', $values);
        }

        app()->setLocale($lang);

        return PdfController::saleHtml($this->shop->id, $this->order, thermal: true)['html'];
    }

    /* ═════════════ ١١ · ١٢ · لكلّ متجرٍ قالبُه ═════════════ */

    public function test_each_shop_keeps_its_own_receipt_words(): void
    {
        $other = Business::create(['name' => 'BLOOM', 'type' => 'Flowers', 'status' => 'نشط']);
        $theirs = $this->sale($other, 'INV-000900');

        DocumentTemplates::save($this->shop->id, 'sale', ['header_en' => 'Ribbon words', 'footer_en' => 'Ribbon footer']);

        $this->assertSame('Ribbon words', DocumentTemplates::settings($this->shop->id, 'sale')['header_en']);
        $this->assertSame('', DocumentTemplates::settings($other->id, 'sale')['header_en']);

        $this->assertStringNotContainsString('Ribbon', $this->strip($other, $theirs, 'en'));
        $this->assertStringContainsString('Ribbon words', $this->strip(lang: 'en'));
    }

    /* ═════════════ ١٣ · ١٤ · بلغة الورقة ═════════════ */

    public function test_the_arabic_receipt_reads_arabic_and_the_english_reads_english(): void
    {
        DocumentTemplates::save($this->shop->id, 'sale', [
            'header' => 'ورد بعناية', 'footer' => 'شكرًا لاختياركم',
            'header_en' => 'Flowers with care', 'footer_en' => 'Thank you for choosing us',
        ]);

        $ar = $this->strip();
        $this->assertStringContainsString('ورد بعناية', $ar);
        $this->assertStringContainsString('شكرًا لاختياركم', $ar);
        $this->assertStringNotContainsString('Flowers with care', $ar);

        $en = $this->strip(lang: 'en');
        $this->assertStringContainsString('Flowers with care', $en);
        $this->assertStringContainsString('Thank you for choosing us', $en);
        $this->assertStringNotContainsString('ورد بعناية', $en);
        $this->assertStringNotContainsString('شكرًا لاختياركم', $en);
    }

    /* ═════════════ ٢١ · ومن لم يضبط شيئًا لا يفقد شيئًا ═════════════ */

    public function test_an_empty_english_text_keeps_what_was_printed_before(): void
    {
        // ترويسةٌ عربيّةٌ وحدها — كانت تُطبع على الإيصال الإنجليزيّ، وتبقى
        DocumentTemplates::save($this->shop->id, 'sale', ['header' => 'ورد بعناية']);
        $this->assertStringContainsString('ورد بعناية', $this->strip(lang: 'en'));

        // والتذييلُ الافتراضيُّ كما كان يُطبع على الإيصال الإنجليزيّ — لا يُترجَم لمن لم يطلب
        $this->assertStringContainsString('شكرًا لزيارتكم', $this->strip(lang: 'en'));
        $this->assertStringNotContainsString('Thank you for visiting', $this->strip(lang: 'en'));
        $this->assertStringContainsString('شكرًا لزيارتكم', $this->strip());

        // وتذييلٌ كتبه التاجرُ بالعربيّة وحدها يبقى كما كتبه
        DocumentTemplates::save($this->shop->id, 'sale', ['footer' => 'نراكم قريبًا']);
        $this->assertStringContainsString('نراكم قريبًا', $this->strip(lang: 'en'));
    }

    /* ═════════════ ١٥ · الشعارُ بمقبضه ═════════════ */

    public function test_the_logo_follows_its_knob(): void
    {
        // والشعارُ مسارُ ملفٍّ في العمود — والنموذجُ يقرؤه رابطًا (`Business::getLogoAttribute`)
        Storage::fake('public');
        Storage::disk('public')->put('logos/ribbon.png', 'PNG');
        DB::table('businesses')->where('id', $this->shop->id)->update(['logo' => 'logos/ribbon.png']);
        $this->order->unsetRelation('business');

        $this->assertStringContainsString('logos/ribbon.png', $this->raw(['show_logo' => true]));
        $this->assertStringNotContainsString('logos/ribbon.png', $this->raw(['show_logo' => false]));
    }

    /** والهاتفُ والموقعُ بمقبضيهما — من ملفّ المتجر لا من نصٍّ يُكتب */
    public function test_phone_and_website_follow_their_knobs(): void
    {
        $this->assertStringContainsString('+968 9525 9066', $this->raw(), 'الهاتفُ ظاهرٌ كما كان');
        $this->assertStringNotContainsString('+968 9525 9066', $this->raw(['show_phone' => false]));

        $this->assertStringNotContainsString('ribbon.', $this->raw(), 'والموقعُ مُطفأٌ افتراضًا');
        $this->assertMatchesRegularExpression('#ribbon\.[a-z.]+|/s/ribbon#', $this->raw(['show_website' => true]));
    }

    /* ═════════════ ١٦ · ما لا يُخفى ═════════════ */

    public function test_the_transaction_is_printed_whatever_is_switched_off(): void
    {
        $off = array_fill_keys(array_keys(DocumentTemplates::TYPES['sale']['fields']), false);
        DocumentTemplates::save($this->shop->id, 'sale', $off);

        $text = $this->strip();

        foreach (['INV-000162', 'Rose Bouquet', '12.000', '24.000', '2.000', '1.500', '23.500',
            'المجموع الفرعي', 'الخصم', 'رسوم التوصيل', 'الإجمالي', 'وسيلة الدفع', 'نقدي'] as $must) {
            $this->assertStringContainsString($must, $text, "«{$must}» اختفى بإطفاء المقابض");
        }

        // ولا مقبضَ في القالب يُخفي الأصنافَ أو المبالغ أو الدفع أو رقمَ الفاتورة
        foreach (['show_prices', 'show_items', 'show_total', 'show_payment', 'show_number', 'show_tax'] as $never) {
            $this->assertArrayNotHasKey($never, DocumentTemplates::TYPES['sale']['fields']);
        }

        $this->assertSame(
            [],
            array_values(array_diff(
                array_keys(DocumentTemplates::rules('sale')),
                [...array_keys(DocumentTemplates::defaults('sale'))],
            )),
            'لا يُقبل في الحفظ حقلٌ ليس في السجلّ',
        );
    }

    /** وحالُ الدفع سطرٌ لمن أشعله — ولم يكن على إيصالٍ قبلها، فلا يُضاف لغيره */
    public function test_the_payment_status_line_is_there_only_for_who_turned_it_on(): void
    {
        $this->assertStringNotContainsString('حالة الدفع', $this->strip());

        DocumentTemplates::save($this->shop->id, 'sale', ['show_payment_status' => true]);
        $this->assertStringContainsString('حالة الدفعمدفوع', $this->strip());
        $this->assertStringContainsString('Payment statusPaid', $this->strip(lang: 'en'));
    }

    /* ═════════════ ٤ · ٥ · ٦ · مقابضُ التاريخ والرقم والرمز كما كانت ═════════════ */

    /**
     * ومتجرٌ لم يفتح «قوالب الأوراق» بعدها يطبع ما كان يطبعه بعينه.
     *
     * التاريخُ ظاهر، والرقمُ الضريبيُّ مخفيٌّ ما لم يُشعَل، والرمزُ ظاهرٌ لمتجرٍ
     * مسجَّل، والهاتفُ ظاهر، ولا موقعَ ولا حالَ دفع. وكلُّ مقبضٍ يُشعَل ويُطفأ
     * كما كان — لا يُفرض شيءٌ منها على متجر.
     */
    public function test_the_date_vat_number_and_code_knobs_behave_as_before(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_number', 'value' => 'OM1100223344']);

        $defaults = DocumentTemplates::defaults('sale');
        $this->assertTrue($defaults['show_datetime']);
        $this->assertFalse($defaults['show_vat_no']);
        $this->assertTrue($defaults['show_qr']);
        $this->assertTrue($defaults['show_phone']);
        $this->assertFalse($defaults['show_website']);
        $this->assertFalse($defaults['show_payment_status']);

        $html = PdfController::saleHtml($this->shop->id, $this->order, thermal: true)['html'];
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));
        $this->assertStringContainsString('التاريخ', $text);
        $codesOn = substr_count($html, '<barcode');
        $this->assertStringNotContainsString('OM1100223344', $text);
        $this->assertStringContainsString('<barcode', $html);
        $this->assertStringContainsString('+968 9525 9066', $text);
        $this->assertStringNotContainsString('حالة الدفع', $text);

        DocumentTemplates::save($this->shop->id, 'sale', ['show_datetime' => false, 'show_vat_no' => true, 'show_qr' => false]);
        $html = PdfController::saleHtml($this->shop->id, $this->order, thermal: true)['html'];
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));
        $this->assertStringNotContainsString('التاريخ', $text);
        $this->assertStringContainsString('OM1100223344', $text);
        // والرمزُ الضريبيُّ يُطفأ بمقبضه — ويبقى رمزُ الورقة أونلاين وحده
        $this->assertSame($codesOn - 1, substr_count($html, '<barcode'));
    }

    /** وضبطُ متجرٍ لمقابضه لا يبلغ متجرًا آخر */
    public function test_one_shops_knobs_never_reach_another_shop(): void
    {
        $other = Business::create(['name' => 'BLOOM', 'type' => 'Flowers', 'status' => 'نشط']);

        DocumentTemplates::save($this->shop->id, 'sale', [
            'show_datetime' => false, 'show_vat_no' => true, 'show_qr' => false,
            'show_payment_status' => true, 'show_website' => true, 'show_logo' => true,
        ]);

        $this->assertSame(
            DocumentTemplates::defaults('sale'),
            DocumentTemplates::settings($other->id, 'sale'),
            'متجرٌ لم يضبط شيئًا على الافتراضيّ كلِّه',
        );
    }

    /* ═════════════ ٨ · ولا متجرَ مسمًّى في الشفرة ═════════════ */

    /**
     * القدرةُ لكلّ متجر — ولا يُسمّى فيها متجرٌ بعينه.
     *
     * لا اسمَ ولا معرّفَ ولا نطاقَ لمتجرٍ في ما يرسم الإيصالَ وصفحةَ الشكر
     * ويحفظهما. فما يُضبط لمتجرٍ يُضبط من شاشته، لا من سطرٍ في الشفرة.
     */
    public function test_no_shop_is_named_in_the_code_that_draws_or_saves_these(): void
    {
        $files = [
            'app/Support/Store/ThankYouPage.php',
            'app/Support/DocumentRenderer.php',
            'app/Support/DocumentTemplates.php',
            'app/Http/Controllers/Admin/TemplateController.php',
            'app/Http/Controllers/PdfController.php',
            'resources/views/documents/v1/thermal.blade.php',
            'resources/views/store/ribbon/done.blade.php',
            'resources/js/Pages/Admin/Settings/TemplateEditor.tsx',
            'resources/js/Pages/Admin/Website/theme/sections/Checkout.tsx',
        ];

        foreach ($files as $file) {
            $code = (string) file_get_contents(base_path($file));

            // و`store.ribbon` اسمُ قالب الواجهة لا اسمُ متجر — فالاسمُ بحروفه الكبيرة
            $this->assertDoesNotMatchRegularExpression('/\bRIBBON\b/u', $code, "متجرٌ مسمًّى في {$file}");
            $this->assertDoesNotMatchRegularExpression('/saud|سعود|ريبون/iu', $code, "متجرٌ مسمًّى في {$file}");
            $this->assertDoesNotMatchRegularExpression('/business_?id\W{1,6}(===?|!==?)\s*\d+|\$bid\s*(===?|!==?)\s*\d+/i', $code, "معرّفُ متجرٍ مكتوبٌ في {$file}");
        }

        // ولا ترحيلَ يكتب لمتجرٍ نصوصَه أو مقابضَه
        foreach (glob(database_path('migrations/*.php')) as $migration) {
            $this->assertStringNotContainsString('store_thanks_', (string) file_get_contents($migration), basename($migration));
            $this->assertStringNotContainsString('tpl_header_en', (string) file_get_contents($migration), basename($migration));
        }
    }

    /* ═════════════ ١٧ · ١٨ · الضريبةُ ورمزُها ═════════════ */

    public function test_tax_and_its_code_are_printed_for_a_registered_shop(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_number', 'value' => 'OM1100223344']);
        $taxed = $this->sale($this->shop, 'INV-000163', ['tax' => 1.2, 'total' => 24.7]);

        $html = PdfController::saleHtml($this->shop->id, $taxed, thermal: true)['html'];
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));

        $this->assertStringContainsString('فاتورة ضريبية', $text);
        $this->assertStringContainsString('الضريبة', $text);
        $this->assertStringContainsString('1.200', $text);
        $this->assertStringContainsString('24.700', $text);
        $this->assertStringContainsString('<barcode', $html, 'رمزُ الفوترة يُطبع لمتجرٍ مسجَّل');

        // والإنجليزيّةُ تقول المبالغَ نفسَها
        app()->setLocale('en');
        $en = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', PdfController::saleHtml($this->shop->id, $taxed, thermal: true)['html']))));
        $this->assertStringContainsString('1.200', $en);
        $this->assertStringContainsString('24.700', $en);
    }

    /* ═════════════ ١٩ · وإيصالُ الصندوق يعمل كما كان ═════════════ */

    public function test_the_till_still_prints_its_thermal_receipt(): void
    {
        DocumentTemplates::save($this->shop->id, 'sale', ['header' => 'ورد بعناية']);

        $res = $this->actingAs($this->owner)->get(route('admin.orders.receipt', $this->order->number))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    /* ═════════════ ٢٢ · والمعاينةُ بلا زبونٍ حقيقيّ ═════════════ */

    public function test_the_receipt_preview_shows_a_sample_never_a_real_customer(): void
    {
        $html = $this->actingAs($this->owner)
            ->postJson(route('admin.settings.templates.preview', 'sale'), ['thermal' => true, 'show_customer' => true])
            ->assertOk()->json('html');

        $this->assertStringContainsString('INV-000123', $html);
        $this->assertStringNotContainsString('Real Customer Name', $html);
        $this->assertStringNotContainsString('INV-000162', $html);
    }

    /** والمعاينةُ تُرى بالإنجليزيّة — بالقيم التي على الشاشة قبل الحفظ */
    public function test_the_preview_can_be_read_in_english_before_saving(): void
    {
        $html = $this->actingAs($this->owner)
            ->postJson(route('admin.settings.templates.preview', 'sale'), [
                'thermal' => true, 'lang' => 'en',
                'header' => 'ورد بعناية', 'header_en' => 'Flowers with care',
            ])
            ->assertOk()->json('html');

        $this->assertStringContainsString('Flowers with care', $html);
        $this->assertStringNotContainsString('ورد بعناية', $html);
        $this->assertSame('ar', app()->getLocale(), 'والطلبُ يعود إلى لغة صاحبه بعد الرسم');

        // ولا يُحفظ شيءٌ من المعاينة
        $this->assertSame('', DocumentTemplates::settings($this->shop->id, 'sale')['header_en']);
    }

    /** والنصُّ الإنجليزيّ بحدّ أخيه */
    public function test_the_english_header_has_the_arabic_headers_limit(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.templates.update', 'sale'), ['header_en' => str_repeat('a', 121)])
            ->assertSessionHasErrors('header_en');
    }
}
