<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الورقةُ تحمل هويّةَ المحلّ — اسمَه وشعارَه ولغتَه، ولا تحمل عنوانَ مبناه.
 *
 * ═══ لماذا هذا الملفّ ═══
 *
 * صاحبُ المحلّ يرسل هذه الورقة باسمه إلى وزارةٍ أو شركة. فثلاثةٌ تُحرَس هنا:
 *
 *  • **الهويّة**: الاسمُ المعروض غيرُ المسجَّل، والشعارُ يصل الورقةَ في
 *    الموضعين — الشاشةِ والطابعةِ — لا في أحدهما.
 *
 *  • **العنوان**: لا يُطبع. ولا بمقبضٍ يُطفأ، بل بألّا يُرسَل أصلًا: مقبضٌ
 *    يُخفي حقلًا موجودًا يُنسى فيُشعَل، وحقلٌ لا يبلغ القالبَ لا يُطبع أبدًا.
 *
 *  • **المال**: أنّ «مدفوعة» تعني إيصالًا حقيقيًّا بقيدٍ في الدفتر، وأنّ
 *    «آجل» لا تصنع إيصالًا وهميًّا، وأنّ الورقةَ وإيصالَها يقعان معًا أو لا
 *    يقع أحدُهما.
 */
class TheInvoiceCarriesTheShopsIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private User $owner;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'أبعاد للورود ش.م.م', 'type' => 'محل ورود', 'status' => 'نشط',
            // عنوانُ المبنى محفوظٌ في الصفّ — والحجّةُ أنّه لا يبلغ الورقةَ رغم ذلك
            'address' => 'شارع السلطان قابوس، مبنى ١٢، الخوير',
            'city' => 'مسقط', 'phone' => '96871141624', 'email' => 'info@abaad.om',
        ]);

        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'البائع', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
            'customer_type' => 'جهة حكومية', 'allow_credit_sales' => true,
            'billing_address' => 'الخوير، مبنى الوزارة، الطابق الثالث',
        ]);

        // ولا ضريبةَ في هذه الأوراق ما لم يُقل: الأرقامُ تُقرأ بلا حساب نسبة
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '0'],
        );
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'issued_at' => '2026-09-10',
            'items' => [['description' => 'تنسيق قاعة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $override);
    }

    private function previewHtml(array $override = []): string
    {
        $res = $this->actingAs($this->owner)
            ->postJson(route('admin.customerInvoices.preview'), $this->payload($override));

        $res->assertOk();

        return (string) $res->json('html');
    }

    private function issued(): CustomerInvoice
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.store'), $this->payload(['issue' => true]))
            ->assertSessionHasNoErrors();

        return CustomerInvoice::latest('id')->firstOrFail();
    }

    /** الورقةُ المطبوعة نصًّا — ببانيها هو، لا بنسخةٍ يكتبها الاختبار */
    private function printedHtml(CustomerInvoice $invoice): string
    {
        return CustomerInvoiceController::paper(
            $this->business->id,
            $invoice,
            $invoice->paidTotal(),
            $invoice->outstanding(),
            BankAccount::where('business_id', $this->business->id)->orderBy('id')->first(),
        )->render();
    }

    /* ═══════════════════ العميلُ الافتراضيّ ═══════════════════ */

    /** يُختار وحدَه عند فتح الشاشة */
    public function test_the_default_customer_is_preselected_on_the_create_screen(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.defaultCustomer'), ['customer_id' => $this->customer->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('default_customer_id', $this->customer->id));
    }

    /** ويبقى قابلًا للتبديل: هو اختيارٌ مبدئيٌّ لا قفل */
    public function test_the_writer_may_still_invoice_another_customer(): void
    {
        InvoiceBranding::defaultCustomerId($this->business->id);
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => InvoiceBranding::DEFAULT_CUSTOMER],
            ['value' => (string) $this->customer->id],
        );

        $other = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الخليج']);

        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.store'), $this->payload(['customer_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $other->id, (int) CustomerInvoice::firstOrFail()->customer_id);
    }

    /**
     * وعميلٌ حُذف يترك رقمًا لا صفَّ له — فيُهمَل الرقم ولا تُفتح شاشةٌ عليه.
     *
     * وبلا ذلك تُفتح الشاشةُ على حقلٍ مختارٍ بلا اسم، ويُردّ الحفظُ بـ«ليس من
     * عملاء متجرك» عن اختيارٍ لم يختره أحد.
     */
    public function test_a_default_customer_that_no_longer_exists_is_ignored(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => InvoiceBranding::DEFAULT_CUSTOMER],
            ['value' => '999999'],
        );

        $this->assertNull(InvoiceBranding::defaultCustomerId($this->business->id));

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('default_customer_id', null));
    }

    /** ولا يُضبط عميلُ متجرٍ آخر افتراضيًّا — يُردّ على حقله ولا يُكتب */
    public function test_a_customer_from_another_shop_cannot_be_made_the_default(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.defaultCustomer'), ['customer_id' => $theirs->id])
            ->assertSessionHasErrors('customer_id');

        $this->assertNull(InvoiceBranding::defaultCustomerId($this->business->id));
    }

    /* ═══════════════════ من يملك تبديلَ الهويّة ═══════════════════ */

    /**
     * هويّةُ الورقة إعدادُ متجرٍ لا فعلُ فاتورة.
     *
     * ومن مُنح كتابةَ الفواتير لم يُمنح تبديلَ ما يُطبع على كلّ ورقةٍ قادمة.
     * والشاشةُ تقرأ `may.brand` فلا تعرض له بابًا يُردّ عنه.
     */
    public function test_a_seller_may_not_change_the_papers_identity(): void
    {
        $this->actingAs($this->seller)
            ->post(route('admin.customerInvoices.branding'), ['display_name' => 'اسمٌ آخر'])
            ->assertForbidden();

        $this->actingAs($this->seller)
            ->post(route('admin.customerInvoices.defaultCustomer'), ['customer_id' => $this->customer->id])
            ->assertForbidden();

        $this->actingAs($this->seller)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('may.brand', false));

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('may.brand', true));
    }

    /* ═══════════════════ الاسمُ المعروض ═══════════════════ */

    /** والاسمُ المعروض يحلّ محلّ المسجَّل على الورقة — ولا يمسّ المسجَّل */
    public function test_the_display_name_replaces_the_registered_name_on_the_paper(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.branding'), ['display_name' => 'أبعاد للورود'])
            ->assertSessionHasNoErrors();

        $html = $this->previewHtml();

        $this->assertStringContainsString('أبعاد للورود', $html);
        $this->assertStringNotContainsString('ش.م.م', $html, 'الاسمُ المسجَّل يُطبع رغم اختيار اسمٍ معروض');

        // والاسمُ القانونيُّ في صفّه كما هو: تجميلُ الطبع لا يعدّل السجلّ
        $this->assertSame(
            'أبعاد للورود ش.م.م',
            DB::table('businesses')->where('id', $this->business->id)->value('name'),
        );

        $this->assertStringContainsString('أبعاد للورود', $this->printedHtml($this->issued()));
    }

    /** والفراغُ يعني «استعمل المسجَّل» لا اسمًا خاويًا في الترويسة */
    public function test_an_empty_display_name_falls_back_to_the_registered_name(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.branding'), ['display_name' => ''])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('أبعاد للورود ش.م.م', $this->previewHtml());
    }

    /* ═══════════════════ الشعار ═══════════════════ */

    /**
     * الشعارُ يصل المعاينةَ والمطبوعَ معًا — وبصورةٍ مضمَّنة.
     *
     * والتضمينُ لأنّ الورقةَ تُقرأ في موضعين لا يشتركان في أصل: إطارٌ معزول
     * في الشاشة، ومحرّكُ mpdf الذي لا جلسةَ له. ورابطٌ نسبيٌّ يعمل في أحدهما
     * ويسقط في الآخر بلا صوت — فيرى التاجرُ شعارَه في المعاينة ويخرج الورقُ
     * بلا شعار.
     */
    public function test_the_uploaded_logo_reaches_both_the_preview_and_the_print(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $stored = DB::table('businesses')->where('id', $this->business->id)->value('logo');
        $this->assertNotNull($stored, 'الشعارُ لم يُحفظ');
        Storage::disk('public')->assertExists($stored);

        $this->assertStringContainsString('data:image/', (string) InvoiceBranding::logo($this->business->id));
        $this->assertStringContainsString('data:image/', $this->previewHtml(), 'الشعارُ لا يبلغ المعاينة');
        $this->assertStringContainsString('data:image/', $this->printedHtml($this->issued()), 'الشعارُ لا يبلغ المطبوع');
    }

    /**
     * وشعارُ الشاشة رابطٌ لا صورةٌ مضمَّنة.
     *
     * نافذةُ التخصيص عنصرُ DOM يقرأ الروابط، وحملُ الصورة مرمَّزةً في حمولة
     * كلّ فتحةٍ للشاشة ثمنٌ يُدفع في كلّ مرّة مقابل صورةٍ بحجم إبهام. ولا
     * يُقلب الأمرُ فيُرسَل رابطٌ إلى الورقة: الورقةُ تُقرأ في إطارٍ معزولٍ
     * وفي mpdf، وكلاهما لا يتبع الرابط.
     */
    public function test_the_screen_carries_a_link_while_the_paper_carries_the_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p
                ->where('branding.logo', fn ($v) => is_string($v)
                    && ! str_starts_with($v, 'data:')
                    && str_contains($v, 'logos/'))
                ->etc());

        $this->assertStringStartsWith('data:image/', (string) InvoiceBranding::logo($this->business->id));
    }

    /** ويُرفع فيختفي من الورقتين */
    public function test_removing_the_logo_clears_it_from_the_paper(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'remove_logo' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(InvoiceBranding::logo($this->business->id));
        $this->assertStringNotContainsString('data:image/', $this->previewHtml());
    }

    /** وهويّةُ متجرٍ لا تُقرأ في ورقة متجرٍ آخر */
    public function test_branding_never_crosses_between_shops(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        Setting::updateOrCreate(
            ['business_id' => $other->id, 'key' => InvoiceBranding::NAME],
            ['value' => 'اسمُ الجار'],
        );

        $this->assertSame('أبعاد للورود ش.م.م', InvoiceBranding::name($this->business->id));
        $this->assertStringNotContainsString('اسمُ الجار', $this->previewHtml());
    }

    /* ═══════════════════ ولا عنوانَ مبنًى ═══════════════════ */

    /**
     * عنوانُ المتجر وعنوانُ العميل — لا يُطبع منهما حرف.
     *
     * والحجّةُ أنّ الاثنين **محفوظان** في صفّيهما، ولا يظهران رغم ذلك: حارسٌ
     * يفحص ورقةً لعميلٍ بلا عنوانٍ أصلًا يقول «سليم» دائمًا.
     */
    public function test_no_building_address_reaches_the_customer_paper(): void
    {
        $invoice = $this->issued();

        foreach (['preview' => $this->previewHtml(), 'print' => $this->printedHtml($invoice)] as $where => $html) {
            $this->assertStringNotContainsString('مبنى ١٢', $html, "عنوانُ المتجر في الـ{$where}");
            $this->assertStringNotContainsString('الطابق الثالث', $html, "عنوانُ العميل في الـ{$where}");
        }
    }

    /**
     * ولا مفتاحَ عنوانٍ يبلغ القالبَ أصلًا — لا فارغًا ولا مطفأً.
     *
     * مقبضٌ يُخفي حقلًا موجودًا يُنسى فيُشعَل، ومفتاحٌ فارغٌ يُملأ يومًا بسطرٍ
     * في مكانٍ آخر. وما لا يُرسَل لا يُطبع أبدًا.
     */
    public function test_the_papers_brand_carries_no_address_key_at_all(): void
    {
        $paper = InvoiceBranding::paper($this->business->id);

        $this->assertArrayNotHasKey('address', $paper);
        $this->assertNotContains('شارع السلطان قابوس، مبنى ١٢، الخوير', $paper);
    }

    /* ═══════════════════ لغةُ الورقة ═══════════════════ */

    /** العربيةُ افتراضًا — بمسمّياتها كما تُقرأ */
    public function test_the_arabic_paper_carries_arabic_labels(): void
    {
        $html = $this->previewHtml(['lang' => 'ar']);

        /*
         * والنقطتانِ لم تعودا في النصّ.
         *
         * كان المسمّى يُكتب «رقم الفاتورة:» ثمّ قيمتُه بعده في السطر نفسه.
         * وصار المسمّى في عمودٍ والقيمةُ في عمود، فالنقطتان زينةٌ تُكرّر ما
         * يقوله التخطيط. والمحروسُ هو **المسمّى بلغته** لا ترقيمُه.
         */
        foreach (['رقم الفاتورة', 'تاريخ الإصدار', 'فاتورة إلى', 'البيان', 'الكمية', 'الإجمالي', 'المجموع الفرعي'] as $label) {
            $this->assertStringContainsString($label, $html, "«{$label}» ليست في الورقة العربية");
        }
    }

    /** والإنجليزيةُ إنجليزيّةٌ كلُّها — لا نصفُ ورقةٍ بلغةٍ ونصفُها بأخرى */
    public function test_the_english_paper_carries_english_labels_and_no_arabic_ones(): void
    {
        $html = $this->previewHtml(['lang' => 'en']);

        foreach (['Invoice no.', 'Issued on', 'Bill to', 'Description', 'Quantity', 'Total', 'Subtotal'] as $label) {
            $this->assertStringContainsString($label, $html, "«{$label}» ليست في الورقة الإنجليزية");
        }

        foreach (['رقم الفاتورة', 'المجموع الفرعي', 'البيان', 'فاتورة إلى'] as $label) {
            $this->assertStringNotContainsString($label, $html, "«{$label}» عربيّةٌ في ورقةٍ إنجليزية");
        }

        /*
         * والاتّجاهُ يتبع اللغة: نصٌّ لاتينيٌّ يبدأ من اليمين يُقرأ عربيّةً
         * بحروفٍ لاتينية.
         *
         * ═══ وعلى جسد الورقة لا على الورقة كلِّها ═══
         *
         * كان الفحصُ `direction: ltr` في النصّ كلِّه — وهي مكتوبةٌ في
         * `.amt` على كلّ ورقةٍ مهما كانت لغتُها (المبالغُ لاتينيّةٌ دائمًا).
         * فالحارسُ كان يقول «سليم» ولو رُسمت الإنجليزيةُ من اليمين، وقد
         * نجت منه طفرةٌ تُثبّت `Paper::rtl()` على `true`. فيُقرأ الآن كتلةُ
         * `body` بعينها.
         */
        $this->assertMatchesRegularExpression(
            '/body\s*\{[^}]*direction:\s*ltr/',
            $html,
            'جسدُ الورقة الإنجليزية يُرسم من اليمين',
        );

        $this->assertMatchesRegularExpression(
            '/body\s*\{[^}]*direction:\s*rtl/',
            $this->previewHtml(['lang' => 'ar']),
            'جسدُ الورقة العربية لا يُرسم من اليمين',
        );
    }

    /**
     * وتقليبُ لغة المعاينة ليس حفظًا.
     *
     * من نظر إلى الشكل الإنجليزيّ ليرى كيف يقرؤها عميلُه ثمّ عاد لا يجب أن
     * يجد فواتيرَه القادمة إنجليزيّة. والحفظُ من «تخصيص التصميم» وحده.
     */
    public function test_previewing_in_another_language_does_not_save_it(): void
    {
        $this->previewHtml(['lang' => 'en']);

        $this->assertNull(
            DB::table('settings')->where('business_id', $this->business->id)
                ->where('key', InvoiceBranding::LANGUAGE)->value('value'),
        );
    }

    /** واللغةُ المحفوظة هي التي تُطبع — لا لغةُ من ضغط الزرّ */
    public function test_the_saved_language_is_the_one_that_prints(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.branding'), ['language' => 'en'])
            ->assertSessionHasNoErrors();

        $invoice = $this->issued();

        app()->setLocale('ar');

        // وبابُ الطباعة يُسأل فعلًا: ورقةٌ تُبنى ولا تخرج من بابها ليست حجّة
        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk();

        $html = InvoiceBranding::render(
            $this->business->id,
            null,
            fn () => $this->printedHtml($invoice),
        );

        $this->assertStringContainsString('Invoice no.', $html, 'الورقةُ طُبعت بغير لغتها المحفوظة');

        // واللغةُ تعود إلى ما كانت بعد الرسم — ولا تبقى الجلسةُ بلغةٍ لم تُختَر
        $this->assertSame('ar', app()->getLocale());
    }

    /* ═══════════════════ سطرُ الذيل ═══════════════════ */

    /**
     * يُطبع إن كُتب — ولا صندوقَ ذيلٍ فارغًا حين لا يُكتب.
     *
     * ═══ ولا يُفحص نصُّه وحدَه ═══
     *
     * كان الفحصُ «لا يحوي نصنع الجمال» — ويمرّ على ورقةٍ تطبع صندوقَ الذيل
     * خاويًا بخطٍّ فوقه، فيُقرأ موضعًا سقط منه نصُّه. ونجت منه طفرةٌ تُثبّت
     * شرطَ الطبع على `true`. فيُفحص **الصندوق** لا النصّ.
     */
    public function test_the_footer_line_prints_only_when_it_is_written(): void
    {
        $bare = $this->previewHtml();

        $this->assertStringNotContainsString('نصنع الجمال', $bare);
        // ولا التذييلُ الافتراضيُّ لأخواتها: «شكرًا لزيارتكم» عبارةُ إيصالٍ
        // يُسلَّم في المحلّ، لا ورقةٍ تُطالب بها وزارةٌ بمبلغ
        $this->assertStringNotContainsString('شكرًا لزيارتكم', $bare);
        /*
         * و`class="foot"` لا `foot`: الاسمُ مكتوبٌ في ورقة الأنماط على
         * كلّ ورقة، فالبحثُ عنه مجرَّدًا يجده دائمًا — حارسٌ يقول «سليم» أبدًا.
         */
        $this->assertStringNotContainsString('class="foot"', $bare, 'صندوقُ الذيل يُطبع بلا نصّ');

        $this->actingAs($this->owner)
            ->post(route('admin.customerInvoices.branding'), ['footer' => 'نصنع الجمال لكل مناسبة'])
            ->assertSessionHasNoErrors();

        $written = $this->previewHtml();

        $this->assertStringContainsString('class="foot"', $written);
        $this->assertStringContainsString('نصنع الجمال لكل مناسبة', $written);
    }

    /* ═══════════════════ شروطُ السداد على الورقة ═══════════════════ */

    /**
     * شروطُ السداد تُطبع — ولا تبقى في الشاشة وحدها.
     *
     * «صافي ٣٠» ما يحتجّ به من يطالب، وما يبني عليه المحاسبُ في الجهة جدولَ
     * صرفه. وورقةٌ تحمل تاريخَ استحقاقٍ بلا شرطٍ يفسّره تُقرأ تاريخًا اختاره
     * كاتبُها.
     */
    public function test_the_payment_terms_are_printed_on_the_paper(): void
    {
        $html = $this->previewHtml(['payment_terms_days' => 30]);

        $this->assertStringContainsString('شروط الدفع', $html);
        $this->assertStringContainsString('صافي 30 يومًا', $html);
    }

    /** و«فورًا» تُقال صراحةً — لا «0 يومًا» تُقرأ حقلًا لم يُملأ */
    public function test_immediate_terms_are_spelled_out_not_printed_as_zero(): void
    {
        $html = $this->previewHtml(['payment_terms_days' => 0]);

        $this->assertStringContainsString('مستحق فورًا', $html);
        $this->assertStringNotContainsString('صافي 0', $html);
    }

    /** وورقةٌ بلا شروطٍ لا تطبع سطرًا فارغًا */
    public function test_a_paper_without_terms_prints_no_terms_line(): void
    {
        $this->assertStringNotContainsString('شروط الدفع:', $this->previewHtml());
    }

    /* ═══════════════════ بابان ومفتاحٌ واحد ═══════════════════ */

    /**
     * فاتورةُ العميل ورقةٌ في «قوالب الأوراق» كأخواتها.
     *
     * وكانت خارجه: تُطبع بترويسةٍ لا يملك التاجر منها شيئًا بينما تُضبط
     * أخواتُها الأربع من شاشةٍ واحدة. فمن ضبط تذييلَ فاتورة البيع توقّع أن
     * تتبعه — ولا موضعَ يقول له لماذا لم تتبعه.
     */
    public function test_the_customer_invoice_is_a_paper_in_the_templates_registry(): void
    {
        $this->assertArrayHasKey(CustomerInvoiceController::PAPER_TYPE, DocumentTemplates::TYPES);
        $this->assertTrue(DocumentTemplates::exists(CustomerInvoiceController::PAPER_TYPE));

        // وبابُ محرّرها مفتوحٌ لصاحبها كبقيّة الأوراق
        $this->actingAs($this->owner)
            ->get(route('admin.settings.templates.edit', CustomerInvoiceController::PAPER_TYPE))
            ->assertOk();
    }

    /**
     * ═══ ومفتاحٌ واحد يُقرأ من البابين ═══
     *
     * ما يُكتب من «تخصيص التصميم» في شاشة الفاتورة **هو عينُه** ما يقرؤه
     * محرّرُ القوالب، ويُطبع على الورقة. ولو كانا مفتاحين لكتب صاحبُه في
     * أحدهما وبحث عن أثره في الآخر — وهو الشقُّ الذي دفع إلى الضمّ.
     */
    public function test_one_key_is_read_by_both_doors(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'header' => 'أجمل الورود في مسقط',
            'footer' => 'نصنع الجمال لكل مناسبة',
            'font' => 'كبير',
        ])->assertSessionHasNoErrors();

        $key = DocumentTemplates::key(CustomerInvoiceController::PAPER_TYPE, 'footer');

        // ١) المفتاحُ في السجلّ باسم النوع — لا مفتاحٌ من عند الفاتورة
        $this->assertSame('tpl_customer_invoice_footer', $key);
        $this->assertSame(
            'نصنع الجمال لكل مناسبة',
            DB::table('settings')->where('business_id', $this->business->id)->where('key', $key)->value('value'),
        );

        // ٢) ولا يبقى للفاتورة مفتاحٌ خاصٌّ بالتذييل
        $this->assertSame(
            0,
            DB::table('settings')->where('business_id', $this->business->id)
                ->where('key', 'invoice_footer_note')->count(),
            'بقي مفتاحُ تذييلٍ ثانٍ للفاتورة',
        );

        // ٣) ومحرّرُ القوالب يقرأ ما كُتب من الشاشة الأخرى
        $this->actingAs($this->owner)
            ->get(route('admin.settings.templates.edit', CustomerInvoiceController::PAPER_TYPE))
            ->assertInertia(fn ($p) => $p
                ->where('template.values.footer', 'نصنع الجمال لكل مناسبة')
                ->where('template.values.header', 'أجمل الورود في مسقط')
                ->where('template.values.font', 'كبير')
                ->etc());

        // ٤) والورقةُ تُطبع بالثلاثة
        $html = $this->previewHtml();

        $this->assertStringContainsString('أجمل الورود في مسقط', $html);
        $this->assertStringContainsString('نصنع الجمال لكل مناسبة', $html);
        $this->assertStringContainsString('font-size: 11.4pt', $html, 'حجمُ الخطّ لا يُدير شيئًا');
    }

    /**
     * وما كُتب من المحرّر يُقرأ في الشاشة الأخرى — الاتّجاه المعاكس.
     *
     * ومقبضٌ يعمل في اتّجاهٍ واحد أسوأ من مقبضين: يبدو موصولًا حتى يُجرَّب
     * من الطرف الآخر.
     */
    public function test_what_the_editor_writes_reaches_the_invoice_screen(): void
    {
        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.update', CustomerInvoiceController::PAPER_TYPE),
            ['header' => 'من المحرّر', 'footer' => 'ذيلٌ من المحرّر', 'font' => 'صغير'],
        )->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p
                ->where('branding.header', 'من المحرّر')
                ->where('branding.footer', 'ذيلٌ من المحرّر')
                ->where('branding.font', 'صغير')
                ->etc());

        $this->assertStringContainsString('من المحرّر', $this->previewHtml());
    }

    /** والمقابضُ الثلاثة في المحرّر تُدير شيئًا فعلًا — لا مقبضَ يُرسم ولا يُغيّر */
    public function test_every_flag_in_the_editor_changes_the_paper(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.branding'), [
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertSessionHasNoErrors();

        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '1'],
        );
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_number'],
            ['value' => 'OM1100234455'],
        );

        $on = $this->previewHtml(['notes' => 'ملاحظةٌ للعميل']);

        $this->assertStringContainsString('data:image/', $on);
        $this->assertStringContainsString('OM1100234455', $on);
        $this->assertStringContainsString('ملاحظةٌ للعميل', $on);

        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.update', CustomerInvoiceController::PAPER_TYPE),
            ['show_logo' => false, 'show_vat_no' => false, 'show_notes' => false],
        )->assertSessionHasNoErrors();

        $off = $this->previewHtml(['notes' => 'ملاحظةٌ للعميل']);

        $this->assertStringNotContainsString('data:image/', $off, 'مفتاحُ الشعار لا يُطفئه');
        $this->assertStringNotContainsString('OM1100234455', $off, 'مفتاحُ الرقم الضريبي لا يُطفئه');
        $this->assertStringNotContainsString('ملاحظةٌ للعميل', $off, 'مفتاحُ الملاحظة لا يُطفئها');

        // والشعارُ محفوظٌ رغم إطفائه: أُطفئ الرسمُ لا مُحي الملفّ
        $this->assertNotNull(InvoiceBranding::logo($this->business->id));
    }

    /** ومعاينةُ المحرّر لا تكتب مسودّةً في دفتر التاجر */
    public function test_the_editor_preview_writes_no_invoice(): void
    {
        $this->actingAs($this->owner)->post(
            route('admin.settings.templates.preview', CustomerInvoiceController::PAPER_TYPE),
            ['footer' => 'تجربة'],
        )->assertOk();

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(0, DB::table('customer_invoice_items')->count());
    }

    /* ═══════════════════ المال ═══════════════════ */

    /** ولا ورقةَ بلا وسيلةٍ تُختار — و«آجل» اختيارٌ يُقال لا صمتٌ يُفسَّر */
    public function test_the_payment_method_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['payment_method']);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $payload)
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, CustomerInvoice::count());
    }

    /** «نقدي» تصنع إيصالًا حقيقيًّا: مدين الصندوق / دائن الذمم */
    public function test_cash_creates_a_real_receipt_and_moves_the_drawer(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'نقدي', 'issue' => true,
        ]))->assertSessionHasNoErrors();

        $payment = CustomerPayment::firstOrFail();

        $this->assertSame('نقدي', $payment->method);
        $this->assertSame(100.0, round((float) $payment->amount, 3));
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0.0, CustomerInvoice::firstOrFail()->outstanding());
    }

    /** و«تحويل» تدخل البنكَ المسمّى بعينه — لا «البنك» عامّةً */
    public function test_a_transfer_names_the_bank_account_that_received_it(): void
    {
        $account = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'بنك ظفار',
            'bank_name' => 'بنك ظفار', 'account_name' => 'أبعاد للورود',
            'iban' => 'OM810350000000000123', 'active' => true, 'is_primary' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'تحويل', 'bank_account_id' => $account->id,
            'payment_reference' => 'TRF-99120', 'issue' => true,
        ]))->assertSessionHasNoErrors();

        $payment = CustomerPayment::firstOrFail();

        $this->assertSame((int) $account->id, (int) $payment->bank_account_id);
        $this->assertSame('TRF-99120', $payment->external_reference);
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'), 'مالُ التحويل دخل الصندوق');
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /** و«آجل» لا تصنع إيصالًا — والذمّةُ تبقى قائمة */
    public function test_credit_creates_no_receipt_and_leaves_the_debt_standing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'آجل', 'issue' => true,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, CustomerPayment::count(), 'ورقةٌ آجلةٌ صنعت إيصالًا');
        $this->assertSame(100.0, Ledger::balance($this->business->id, 'receivable'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(100.0, CustomerInvoice::firstOrFail()->outstanding());
    }

    /** وبعضُ المبلغ يُسجَّل بعضًا — والباقي ذمّة */
    public function test_a_partial_payment_leaves_the_rest_owed(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'نقدي', 'paid_amount' => 40, 'issue' => true,
        ]))->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame(40.0, round((float) CustomerPayment::firstOrFail()->amount, 3));
        $this->assertSame(40.0, $invoice->paidTotal());
        $this->assertSame(60.0, $invoice->outstanding());
        $this->assertSame(40.0, Ledger::balance($this->business->id, 'cash'));
        $this->assertSame(60.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /**
     * وما زاد عن الورقة يُردّ برسالةٍ على حقله — ولا يُقصّ في صمت.
     *
     * ولا تبقى ورقةٌ صادرةٌ خلفه: المعاملةُ تسقط كاملة.
     */
    public function test_paying_more_than_the_total_is_refused_and_writes_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'نقدي', 'paid_amount' => 1000, 'issue' => true,
        ]))->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, CustomerInvoice::count(), 'بقيت ورقةٌ من معاملةٍ سقطت');
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /**
     * ═══ والورقةُ وإيصالُها يقعان معًا أو لا يقع أحدُهما ═══
     *
     * حسابٌ بنكيٌّ من متجرٍ آخر يُردّ في `CustomerPayments::accountFor` بعد
     * أن تكون الورقةُ قد كُتبت وصدرت. وبلا معاملةٍ واحدة تبقى **فاتورةٌ
     * صادرةٌ بذمّةٍ في الدفتر ومالٌ في يد التاجر لا إيصالَ له** — ويقرأ
     * التاجرُ رسالةَ خطأ فيعيد الضغط، فتُكتب ورقةٌ ثانية.
     */
    public function test_a_failing_receipt_rolls_the_issued_invoice_back(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = BankAccount::create([
            'business_id' => $other->id, 'label' => 'حسابهم', 'bank_name' => 'بنكهم',
            'active' => true, 'is_primary' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'تحويل', 'bank_account_id' => $theirs->id, 'issue' => true,
        ]))->assertSessionHasErrors();

        $this->assertSame(0, CustomerInvoice::count(), 'بقيت ورقةٌ صادرةٌ بلا إيصال');
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, DB::table('journal_entries')->count(), 'بقي قيدٌ من معاملةٍ سقطت');
    }

    /** والمسودّةُ لا تكتب قيدًا ولا إيصالًا — ولا تُقبل عليها وسيلةٌ مقبوضة */
    public function test_a_draft_moves_no_money(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::DRAFT, CustomerInvoice::firstOrFail()->status);
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, DB::table('journal_entries')->count());

        // و«نقدي» على مسودّةٍ تُردّ: التحصيلُ يُخصَّص على ورقةٍ صادرة
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'payment_method' => 'نقدي',
        ]))->assertSessionHasErrors('payment_method');
    }

    /**
     * والفاتورةُ اليدويّةُ مستندٌ ماليٌّ لا مستندُ مخزون.
     *
     * لا تُنقص رفًّا ولا تكتب حركةً ولا تُقيَّد لها تكلفة: خروجُ البضاعة
     * يتبع الطلب، وورقةٌ تُكتب لخدمةٍ لا تُخرج شيئًا أصلًا.
     */
    public function test_a_manual_invoice_moves_no_stock(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 100, 'cost' => 40, 'quantity' => 7,
        ]);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), $this->payload([
            'issue' => true,
            'items' => [[
                'product_id' => $product->id, 'description' => 'باقة ورد',
                'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0,
            ]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            7.0,
            round((float) DB::table('products')->where('id', $product->id)->value('quantity'), 3),
            'الفاتورةُ اليدويّة أنقصت المخزون',
        );
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cogs'), 'الفاتورةُ اليدويّة قيّدت تكلفة');
    }
}
