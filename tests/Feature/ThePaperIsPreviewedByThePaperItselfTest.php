<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Paper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المعاينةُ تُرسم بالورقة نفسها — ولا تكتب شيئًا.
 *
 * ═══ لماذا هذا الملفّ ═══
 *
 * شاشةُ إنشاء الفاتورة صارت عمودين: تفاصيلُ إلى جانب الورقة كما ستخرج.
 * وكان أمامها طريقان: أن تُرسم صورةٌ تشبه الفاتورة في JSX، أو أن يُرسم
 * القالبُ الذي يُطبع فعلًا. والأوّلُ نسخةٌ ثانية تفترق عن أصلها عند أوّل
 * تعديل — يُرفع سطرٌ من الورقة ويبقى في الصورة، فيعتمد التاجر شكلًا لا
 * يخرج من الطابعة ويرسل إلى عميله ورقةً غيرَ التي رآها. وهي القاعدةُ
 * المكتوبة في `DocumentRenderer` منذ محرّر القوالب.
 *
 * وهنا تُحرَس ثلاثةٌ: أنّ الرسم لا يكتب، وأنّه يقرأ ما تقرأه الورقة،
 * وأنّ العنوانَ رُفع من الاثنتين معًا لا من إحداهما.
 */
class ThePaperIsPreviewedByThePaperItselfTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'استوديو أبعاد', 'type' => 'عام', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس، مبنى ١٢، مسقط',
            'phone' => '96890000000',
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

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة',
            'customer_type' => 'جهة حكومية', 'allow_credit_sales' => true,
            'payment_terms_days' => 45,
            'billing_address' => 'الخوير، مبنى الوزارة، الطابق الثالث',
            'tax_number' => 'OM1100234455',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'issued_at' => '2026-09-09',
            'due_at' => '2026-10-24',
            'notes' => 'شكرًا لثقتكم.',
            'items' => [
                ['description' => 'تنسيق قاعة', 'quantity' => 10, 'unit_price' => 750, 'discount' => 0, 'tax_rate' => 5],
            ],
        ], $override);
    }

    /* ─────────────────── أنّها لا تكتب شيئًا ─────────────────── */

    /**
     * المعاينةُ لا تترك صفًّا ولا رقمًا ولا سطرًا في سجلّ النشاط.
     *
     * ومن فتح الشاشة وكتب بندًا ثمّ تركها لا يجب أن يجد مسودّةً في دفتره
     * ولا فجوةً في تسلسل أرقامه — والتسلسلُ يُقرأ عند الضريبة.
     */
    public function test_previewing_writes_nothing(): void
    {
        $before = [
            'invoices' => DB::table('customer_invoices')->count(),
            'items' => DB::table('customer_invoice_items')->count(),
            'activity' => DB::table('activity_logs')->count(),
            'journal' => DB::table('journal_entries')->count(),
            'payments' => DB::table('customer_payments')->count(),
        ];

        $this->actingAs($this->owner)
            ->postJson(route('admin.customerInvoices.preview'), $this->payload())
            ->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, match ($table) {
                'invoices' => DB::table('customer_invoices')->count(),
                'items' => DB::table('customer_invoice_items')->count(),
                'activity' => DB::table('activity_logs')->count(),
                'journal' => DB::table('journal_entries')->count(),
                'payments' => DB::table('customer_payments')->count(),
            }, 'المعاينةُ كتبت في '.$table);
        }
    }

    /** و`draft` نفسُها لا تلمس القاعدة — البابُ يُحرَس، والدالّةُ تُحرَس */
    public function test_the_draft_is_never_persisted(): void
    {
        $draft = CustomerInvoices::draft($this->business->id, $this->customer, [
            'issued_at' => '2026-09-09',
        ], [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]]);

        $this->assertFalse($draft->exists, 'المسودّةُ محفوظة');
        $this->assertNull($draft->getKey(), 'المسودّةُ أخذت معرّفًا');
        $this->assertNull($draft->number, 'المسودّةُ قطعت رقمًا من التسلسل');
        $this->assertSame(0, DB::table('customer_invoices')->count());
        $this->assertSame(0, DB::table('customer_invoice_items')->count());
    }

    /* ─────────────────── أنّها الورقةُ نفسها ─────────────────── */

    /**
     * ما يُرسَم في الشاشة هو ما يُطبع — بندًا بندًا ورقمًا رقمًا.
     *
     * ولا تُقارَن بالنصّ العربيّ وحده: العددُ هو الحجّة. بندٌ اسمُه
     * «تنسيق قاعة» بعشرة في ٧٥٠ وضريبةِ ٥٪ إجمالُه ٧٨٧٥ — فإن اختلف
     * حسابُ المعاينة عن حساب `compute` ظهر هنا.
     */
    public function test_the_preview_is_the_printed_template_with_the_computed_numbers(): void
    {
        $html = $this->previewHtml();

        $this->assertStringContainsString('تنسيق قاعة', $html);
        $this->assertStringContainsString('7,875.000', $html, 'إجماليُّ الورقة ليس ما يحسبه الخادم');
        $this->assertStringContainsString('استوديو أبعاد', $html, 'اسمُ المتجر لا يُطبع في المعاينة');
        $this->assertStringContainsString('وزارة الثقافة', $html);
        $this->assertStringContainsString('شكرًا لثقتكم.', $html);
    }

    /**
     * والمعاينةُ ترسم القالبَ الذي يُطبع — لا شكلًا يشبهه.
     *
     * والحجّةُ أنّ الاثنتين تخرجان من ملفٍّ واحد: يُغيَّر سطرٌ في
     * `pdf.customer-invoice` فيتغيّر في الشاشة وفي الـPDF معًا. ولو رُسمت
     * المعاينةُ في JSX لبقيت على حالها ولم يقل شيءٌ إنّها افترقت.
     */
    public function test_the_preview_and_the_pdf_come_out_of_the_same_file(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/CustomerInvoiceController.php'));
        $pdf = file_get_contents(app_path('Http/Controllers/PdfController.php'));

        /*
         * وموضعُ البناء واحدٌ لا اثنان.
         *
         * كان كلٌّ منهما يكتب `view('pdf.customer-invoice', [...])` بقائمة
         * متغيّراتٍ خاصّةٍ به. والقالبُ واحدٌ فعلًا، لكنّ **ما يُمرَّر إليه**
         * كان اثنين — فمتغيّرٌ يُضاف لأحدهما لا يبلغ الآخر: يُضبط الشعارُ
         * فيُرى في المعاينة ويغيب عن الطابعة، وهو خلافٌ لا يُكتشف إلّا بعد
         * أن تصل الورقةُ إلى العميل. فصار البناءُ في `CustomerInvoiceController::paper`
         * وحدها، ومتحكّمُ الطباعة يناديها.
         */
        /*
         * واسمُ القالب صار يُشتقّ من نسخته (`Version::views`) لا يُكتب
         * حرفيًّا: فاتورةُ العام الماضي تُرسم بقالبها لا بقالب اليوم. فما
         * يُعَدُّ هنا هو اسمُ الملفّ في آخر التعبير — والحارسُ نفسُه: موضعُ
         * بناءٍ واحد، لا اثنان يفترقان عند أوّل متغيّرٍ يُضاف.
         */
        $this->assertSame(
            1,
            substr_count($controller, ".'.customer-invoice'"),
            'الورقةُ تُبنى في أكثر من موضع داخل متحكّم الفواتير',
        );
        $this->assertStringNotContainsString(
            "customer-invoice'",
            $pdf,
            'متحكّمُ الطباعة يبني الورقةَ بنفسه بدل أن ينادي بانيَها',
        );
        $this->assertStringContainsString('CustomerInvoiceController::paper(', $pdf);

        // ولا صورةَ ثانيةً في الشاشة: الإطارُ يعرض ما يردّه الخادم
        $screen = file_get_contents(resource_path('js/Pages/Admin/CustomerInvoices/Create.tsx'));
        $this->assertStringContainsString('srcDoc={html}', $screen, 'المعاينةُ ليست في إطارٍ يقرأ ردَّ الخادم');
        $this->assertStringContainsString(
            "route('admin.customerInvoices.preview')",
            $screen,
            'الشاشةُ لا تسأل بابَ المعاينة',
        );
    }

    /* ─────────────────── ولا عنوانَ مبنًى ─────────────────── */

    /**
     * لا عنوانُ العميل في المعاينة ولا في المطبوع.
     *
     * ═══ ولا يُسأل الـPDF نفسُه ═══
     *
     * الملفُّ ثنائيٌّ ونصوصُه مرمَّزةٌ مضغوطة، فـ`assertStringNotContainsString`
     * عليه يمرّ ولو طُبع العنوانُ في وسطه — حارسٌ يقول «سليم» دائمًا. فيُسأل
     * ما يُرسَل إلى المحرّك: القالبُ نفسُه بالقيم التي يمرّرها `PdfController`.
     */
    public function test_no_building_address_is_printed_on_either(): void
    {
        $this->assertStringNotContainsString(
            $this->customer->billing_address,
            $this->previewHtml(),
            'عنوانُ العميل في المعاينة',
        );

        $this->assertStringNotContainsString(
            $this->customer->billing_address,
            $this->printedHtml($this->issued()),
            'عنوانُ العميل في الورقة المطبوعة',
        );

        // والحقلُ باقٍ في الصفّ لقطةً — رُفع من الطباعة لا من الدفتر
        $this->assertSame(
            'الخوير، مبنى الوزارة، الطابق الثالث',
            DB::table('customer_invoices')->latest('id')->value('customer_address'),
        );
    }

    /**
     * ولا عنوانُ المتجر في ترويستها — ولم يكن فيها قطّ.
     *
     * ═══ وهذا ما قِيس قبل أن يُكتب علمٌ لإخفائه ═══
     *
     * `pdf.layout` يقرأ `Paper::brand($business)`، و`$business` في كلّ أوراق
     * `PdfController` مصفوفةُ `Demo::business()` — **ولا مفتاحَ `address`
     * فيها**. فبابُ العنوان في `Paper::brand` لا يُفتح لهذه الأوراق أصلًا.
     * وعلمٌ يُضاف لإخفاء ما هو غائبٌ مقبضٌ لا يُدير شيئًا: كُتب ثمّ رُفع بعد
     * القياس، ولم يبقَ منه أثر.
     *
     * والاختبارُ يبقى لأنّ الغيابَ اليوم ليس ضمانًا للغد: من يضيف
     * `'address' => $b->address` إلى `Demo::business()` — وله وجهٌ، فالتاجر
     * يملؤه في تهيئة المتجر ولا يُطبع في ورقةٍ واحدة — يُدخل عنوانَ المبنى
     * إلى فاتورةٍ رُفع منها بطلبٍ صريح، ولا يقول شيءٌ إنّه فعل.
     */
    public function test_the_shops_building_address_does_not_reach_this_paper(): void
    {
        $this->business->update(['address' => 'شارع السلطان قابوس، مبنى ١٢، مسقط']);

        $this->assertStringNotContainsString(
            'شارع السلطان قابوس',
            $this->previewHtml(),
            'عنوانُ المتجر في ترويسة المعاينة',
        );

        $this->assertStringNotContainsString(
            'شارع السلطان قابوس',
            $this->printedHtml($this->issued()),
            'عنوانُ المتجر في ترويسة الورقة المطبوعة',
        );

        // والسببُ مقيسٌ لا مفترَض: ما يصل الترويسةَ لا عنوانَ فيه
        $this->assertArrayNotHasKey('address', Demo::business($this->business->id));
    }

    /**
     * وبندٌ بلا نسبةٍ يُعاين بنسبة المتجر — كما يُحفظ بها تمامًا.
     *
     * ═══ وهذا مطبٌّ يُخفيه أنّ الصفر رقمٌ صحيح ═══
     *
     * `compute` تقرأ **وجودَ** المفتاح لا قيمتَه، لأنّ `tax_rate: 0` إعفاءٌ
     * مقصودٌ يُحفظ لقطةً في السطر. وبندٌ يصل بـ`tax_rate: null` — وهو ما
     * ترسله الشاشةُ لصفٍّ لم يُلمس — يُقرأ صفرًا لا غيابًا، فيُعفى في صمت.
     *
     * فـ`store` تحذف المفتاحَ الفارغ قبل الحساب، والمعاينةُ يجب أن تحذفه
     * مثلَها. ولو لم تفعل لرأى التاجر ورقةً بلا ضريبة ثمّ استلم عميلُه
     * ورقةً بها — ومعاينةٌ تكذب أسوأ من غياب المعاينة.
     *
     * وقد نجت هذه الطفرةُ في أوّل قياس: كان الحارسُ يرسل نسبةً صريحة في
     * كلّ بند، فلا يمرّ بالمسار الذي يحرسه.
     */
    public function test_a_line_without_a_rate_previews_at_the_shop_rate_exactly_as_it_saves(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '1'],
        );
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_rate'],
            ['value' => '5'],
        );

        $line = ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => null];

        // ١) ما تعرضه المعاينة
        $html = $this->previewHtml(['items' => [$line]]);

        $this->assertStringContainsString('210.000', $html, 'المعاينةُ أعفت بندًا لم يُعفَ');
        $this->assertStringNotContainsString('200.000</strong>', $html);

        // ٢) وما يُحفظ فعلًا — والرقمان واحد
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'items' => [$line],
        ])->assertSessionHasNoErrors();

        $saved = DB::table('customer_invoices')->latest('id')->first();

        $this->assertSame(10.0, round((float) $saved->tax_total, 3), 'المحفوظُ لا يحمل ضريبة المتجر');
        $this->assertSame(210.0, round((float) $saved->total, 3));
        $this->assertStringContainsString(
            number_format((float) $saved->total, 3),
            $html,
            'إجماليُّ المعاينة يخالف إجماليَّ المحفوظ',
        );
    }

    /* ─────────────── وسائلُ السداد: قائمةٌ واحدة ─────────────── */

    /**
     * ما تعرضه شاشةُ الإنشاء هو ما يقبله التحصيل — و«شيك» منها.
     *
     * كانت ثلاثَ قوائم مكتوبةٍ بأيديها: شاشةُ الإنشاء، وقاعدةُ المصادقة،
     * وشاشةُ الفاتورة المفتوحة. و«شيك» في قائمة التحصيل الحقيقيّة ولم تكن
     * في شاشة الإنشاء — فمن قبض شيكًا لم يجد وسيلته.
     */
    public function test_the_screen_offers_exactly_what_collection_accepts(): void
    {
        $this->assertSame(
            array_merge(['آجل'], CustomerPayments::METHODS),
            CustomerInvoices::methods(),
        );

        $this->assertContains('شيك', CustomerInvoices::methods());

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($page) => $page
                ->where('methods', CustomerInvoices::methods())
                ->where('bank_methods', CustomerInvoices::bankMethods()));
    }

    /** والشاشتان تقرآن من مالكٍ واحد — لا قائمةً مكتوبةً في ملفّ الواجهة */
    public function test_neither_screen_writes_the_list_by_hand(): void
    {
        foreach (['Create', 'Show'] as $screen) {
            $source = file_get_contents(
                resource_path("js/Pages/Admin/CustomerInvoices/{$screen}.tsx")
            );

            $this->assertStringNotContainsString(
                "'نقدي', 'بطاقة', 'تحويل'",
                $source,
                "شاشةُ {$screen} تكتب وسائلَ السداد بيدها",
            );
        }
    }

    /**
     * وفاتورةٌ تُقبض بشيك تُسجَّل شيكًا — وتدخل البنك.
     *
     * وهذا هو الربطُ بالمالية: الإيصالُ يُكتب، والقيدُ يُدين حسابَ البنك
     * الذي سُمّي بعينه ويُدين ذمّةَ العميل دائنًا — لا «بنك» عامّة.
     */
    public function test_a_cheque_invoice_records_a_receipt_into_the_named_bank(): void
    {
        $account = BankAccount::create([
            'business_id' => $this->business->id, 'bank_name' => 'بنك ظفار',
            'account_name' => 'استوديو أبعاد', 'iban' => 'OM12BANK0000001234',
            'active' => true, 'is_primary' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => 'شيك',
            'bank_account_id' => $account->id,
            'items' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        $payment = DB::table('customer_payments')->latest('id')->first();

        $this->assertNotNull($payment, 'لم يُسجَّل إيصال');
        $this->assertSame('شيك', $payment->method);
        $this->assertSame((int) $account->id, (int) $payment->bank_account_id, 'الشيكُ بلا حسابٍ منسوب');
        $this->assertSame(100.0, round((float) $payment->amount, 3));

        $invoice = CustomerInvoice::latest('id')->first();
        $this->assertSame(0.0, round($invoice->outstanding(), 3), 'الورقةُ بقيت مستحقّة');
    }

    /**
     * ووسيلةٌ لا يعرفها النظام تُردّ — ولا تُقرأ نقدًا في صمت.
     *
     * `CustomerPayments::record` تسقط إلى «نقدي» عند ما لا تعرفه، وقاعدةُ
     * `pay` كانت `['required','string']` بلا قائمة. فطلبٌ بوسيلةٍ مكتوبةٍ
     * بحرفٍ زائد **يدخل المالَ الصندوقَ** ويكتب قيدَه على `cash` والمالُ
     * في البنك — ويقول التنبيه «سُجّل التحصيل».
     */
    public function test_an_unknown_method_is_refused_at_the_collection_door(): void
    {
        $invoice = $this->issued();

        $this->actingAs($this->owner)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 50,
            'method' => 'تحويلٌ بنكي',
        ])->assertSessionHasErrors('method');

        $this->assertSame(0, DB::table('customer_payments')->count(), 'سُجّل تحصيلٌ بوسيلةٍ مرفوضة');
    }

    /** وحسابُ البنك يُسأل عن كلّ وسيلةٍ تدخله — والشيكُ منها */
    public function test_the_bank_methods_are_exactly_those_whose_money_enters_a_bank(): void
    {
        $this->assertSame(['بطاقة', 'تحويل', 'شيك'], CustomerInvoices::bankMethods());

        foreach (CustomerInvoices::bankMethods() as $method) {
            $this->assertSame('bank', CustomerPayments::sideFor($method));
        }

        $this->assertNotContains('نقدي', CustomerInvoices::bankMethods());
    }

    /* ─────────────── الشعارُ والاسمُ يُضبطان من موضعٍ واحد ─────────────── */

    /**
     * اسمُ المتجر وشعارُه يبلغان الورقة — ويُغيَّران من الإعدادات.
     *
     * ولا حقلَ ثانٍ لهما في شاشة الفاتورة: حقلان يقولان اسمَ المتجر
     * يفترقان يومًا، فتحمل الفاتورةُ اسمًا ويحمل الإيصالُ غيرَه.
     */
    public function test_the_shop_name_and_logo_reach_the_paper_from_the_settings(): void
    {
        $this->business->update(['logo' => 'logos/abaad.png']);

        $html = $this->previewHtml();

        $this->assertStringContainsString('استوديو أبعاد', $html);
        $this->assertStringContainsString('logos/abaad.png', $html, 'الشعارُ لا يبلغ الورقة');

        // ويُغيَّران من بابٍ قائم — لا من حقلٍ يُكتب في كلّ فاتورة
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shop_name' => 'استوديو أبعاد الرقمي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'استوديو أبعاد الرقمي',
            DB::table('businesses')->where('id', $this->business->id)->value('name'),
        );

        $this->assertStringContainsString('استوديو أبعاد الرقمي', $this->previewHtml());
    }

    /* ─────────────── الشاشةُ عمودان: تفاصيلُ وورقة ─────────────── */

    /** والمعاينةُ ملتصقةٌ لا تنزل تحت طيّة الشاشة كلّما طالت البنود */
    public function test_the_screen_is_two_columns_with_a_sticky_paper(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Admin/CustomerInvoices/Create.tsx'));

        /*
         * والنسبةُ نحو ٥٧ إلى ٤٣ — لا عمودٌ ثابتُ العرض.
         *
         * كان الأيمنُ مقيَّدًا بـ`460px`، فعلى شاشةٍ عريضة تنكمش الورقةُ إلى
         * ثُلثٍ ويبقى النموذجُ ممدودًا بلا حاجة — والورقةُ هي ما يُراجَع.
         * وهي نسبةُ التصميم المعتمد.
         */
        $this->assertStringContainsString('xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]', $screen);
        $this->assertStringContainsString('xl:sticky xl:top-4 xl:self-start', $screen);

        // والإطارُ معزولٌ بلا تنفيذ: الورقةُ نصٌّ يُطبع لا صفحةٌ تعمل
        $this->assertStringContainsString('sandbox=""', $screen);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function previewHtml(array $override = []): string
    {
        $res = $this->actingAs($this->owner)
            ->postJson(route('admin.customerInvoices.preview'), $this->payload($override));

        $res->assertOk();

        return (string) $res->json('html');
    }

    /**
     * الورقةُ المطبوعة نصًّا — قبل أن يبتلعها محرّكُ الـPDF.
     *
     * ═══ وتُبنى ببانيها لا بنسخةٍ هنا ═══
     *
     * كانت قائمةُ المتغيّرات تُكتب في هذا الملفّ بيدها. وهي نسخةٌ ثالثة إلى
     * جانب نسختَي المتحكّمَين — فحارسٌ يقول «لا عنوان في المطبوع» وهو إنّما
     * يفحص ورقةً بناها الاختبارُ لنفسه، لا التي تخرج من الطابعة. وحارسٌ
     * يفحص نسختَه هو أسوأ من غياب الحارس: يقول «سليم» عن بابٍ لم يمرّ به.
     */
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

    private function issued(): CustomerInvoice
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => '0'],
        );

        $invoice = CustomerInvoices::create($this->business->id, $this->customer, [
            'issued_at' => '2026-09-09',
        ], [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }
}
