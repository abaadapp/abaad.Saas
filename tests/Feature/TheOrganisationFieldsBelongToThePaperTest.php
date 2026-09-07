<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Paper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بياناتُ الجهة — لقطةٌ على الورقة، لا إحالةٌ إلى صفّ العميل.
 *
 * وأمرُ الشراء يختلف من فاتورةٍ إلى فاتورةٍ للشركة نفسها. فلو قُرئ من صفّ
 * العميل لحمَلت فواتيرُ العام كلُّها آخرَ أمرِ شراءٍ كُتب.
 *
 * وما لم يُملأ منها لا يُخزَّن نصًّا فارغًا ولا يُطبع شرطةً: ورقةٌ نصفُها
 * شُرَطٌ تُقرأ نموذجًا لم يُكمَل، وتقول للجهة إنّ لها مركزَ تكلفةٍ لم يُذكر.
 */
class TheOrganisationFieldsBelongToThePaperTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    private User $owner;

    /** الستّةُ كما تُرسَل من الشاشة */
    private const ORG = [
        'po_number' => 'PO-2026-154',
        'contract_number' => 'CNT-2026-08',
        'external_reference' => 'REF-8842',
        'department' => 'العلاقات العامة',
        'cost_center' => 'CC-104',
        'attention_to' => 'أحمد البلوشي',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
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
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'customer_id' => $this->customer->id,
            'items' => [['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 5]],
        ];
    }

    /* ------------------------- ما يُملأ يُحفظ ------------------------- */

    /** الستّةُ تصل الورقةَ كما كُتبت — ولا واحدٌ منها يسقط في الطريق */
    public function test_all_six_organisation_fields_persist(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload(self::ORG))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_invoices', self::ORG + [
            'business_id' => $this->business->id,
        ]);
    }

    /* ------------------------ وما لا يُملأ لا ------------------------ */

    /**
     * فاتورةٌ بلا حقلٍ منها تُحفظ — ولا يُطلَب شيءٌ من صاحب متجرٍ يبيع لفرد.
     *
     * وتُخزَّن فراغًا لا نصًّا فارغًا: `''` ليست فارغةً عند من يفحص الوجود،
     * فتُطبع في الورقة ويُتخطّى بها كلُّ سقوطٍ إلى قيمةٍ افتراضية.
     */
    public function test_an_invoice_saves_with_no_organisation_fields_at_all(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload())
            ->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        foreach (array_keys(self::ORG) as $field) {
            $this->assertNull($invoice->{$field}, $field.' ليست فارغة');
        }
    }

    /** وفراغٌ يُرسَل صراحةً — أو مسافاتٌ — يُخزَّن فراغًا لا نصًّا */
    public function test_blank_and_whitespace_are_stored_as_nothing(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'po_number' => '', 'cost_center' => '   ', 'contract_number' => '  CNT-9  ',
        ]))->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertNull($invoice->po_number);
        $this->assertNull($invoice->cost_center);
        // والمكتوبُ يُقلَّم طرفاه ولا يُمسّ وسطُه
        $this->assertSame('CNT-9', $invoice->contract_number);
    }

    /**
     * والقسمُ يسقط إلى قسم العميل حين يُترك فارغًا — وهذا يعتمد على الفراغ.
     *
     * ولو وصل `''` لما وقع السقوطُ أبدًا: `?? ` لا تُشغّلها نصٌّ فارغ. وهو
     * ما يجعل هذه الحالةَ حارسًا على التفريغ لا على السقوط وحده.
     */
    public function test_a_blank_department_falls_back_to_the_customers_own(): void
    {
        $this->customer->update(['department' => 'المشتريات']);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload(['department' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('المشتريات', CustomerInvoice::firstOrFail()->department);
    }

    /* --------------------------- لقطةٌ لا إحالة --------------------------- */

    /**
     * ولكلّ فاتورةٍ أمرُ شرائها.
     *
     * شركةٌ تشتري ثلاث مرّاتٍ في الشهر لها ثلاثةُ أوامر شراء. وقراءةُ الرقم
     * من صفّ العميل تجعل الورقتين الأوليين تحملان رقمَ الثالثة.
     */
    public function test_each_invoice_keeps_its_own_purchase_order(): void
    {
        foreach (['PO-1', 'PO-2'] as $po) {
            $this->actingAs($this->owner)
                ->post('/admin/customer-invoices', $this->payload(['po_number' => $po]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(['PO-1', 'PO-2'], CustomerInvoice::orderBy('id')->pluck('po_number')->all());
    }

    /* ------------------------ تاريخُ الاستحقاق ------------------------ */

    /** المدّةُ تُحسب منها في الخادم أيضًا — لا في الشاشة وحدها */
    public function test_the_backend_derives_the_due_date_from_the_terms(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issued_at' => '2026-09-07', 'payment_terms_days' => 30,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-07', CustomerInvoice::firstOrFail()->due_at->toDateString());
    }

    /** و«تاريخ مخصص» يصل بلا مدّة — فيُحفظ التاريخُ كما كُتب ولا تُخترع مدّة */
    public function test_a_custom_due_date_survives_without_terms(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issued_at' => '2026-09-07', 'payment_terms_days' => '', 'due_at' => '2026-11-20',
        ]))->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame('2026-11-20', $invoice->due_at->toDateString());
        $this->assertNull($invoice->payment_terms_days);
    }

    /** والمكتوبُ بيدٍ يعلو على المدّة: من كتب تاريخًا لا يُكتب فوقه */
    public function test_a_written_due_date_beats_the_terms(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issued_at' => '2026-09-07', 'payment_terms_days' => 30, 'due_at' => '2026-09-20',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-09-20', CustomerInvoice::firstOrFail()->due_at->toDateString());
    }

    /**
     * واستحقاقٌ قبل تاريخ الورقة يُردّ.
     *
     * فاتورةٌ كهذه تُولد متأخّرةً: تدخل «المتأخّر» في الملخّص، ويصلها تذكيرُ
     * السداد المجدول في صباح يومها.
     */
    public function test_a_due_date_before_the_invoice_date_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issued_at' => '2026-09-07', 'due_at' => '2026-09-01',
        ]))->assertSessionHasErrors('due_at');

        $this->assertSame(0, CustomerInvoice::count());
    }

    /** ومدّةُ العميل الافتراضية تُستعمل حين لا تُرسَل مدّةٌ ولا تاريخ */
    public function test_the_customers_default_terms_are_used_when_none_are_sent(): void
    {
        $this->customer->update(['payment_terms_days' => 45]);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload([
            'issued_at' => '2026-09-07',
        ]))->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();

        $this->assertSame(45, (int) $invoice->payment_terms_days);
        $this->assertSame('2026-10-22', $invoice->due_at->toDateString());
    }

    /**
     * نصُّ الورقة قبل أن تصير PDF.
     *
     * والملفُّ المُخرَج ثنائيٌّ مضغوط، فالبحثُ عن «رقم العقد» فيه لا يجد شيئًا
     * — ولا حين تكون مطبوعةً فعلًا. فيُصيَّر القالبُ نفسُه بما يُمرّره
     * `PdfController` حرفيًّا.
     */
    private function render(CustomerInvoice $invoice): string
    {
        return view('pdf.customer-invoice', [
            'invoice' => $invoice,
            'business' => Demo::business($this->business->id),
            'vatNumber' => Paper::vatNumber($this->business->id),
            'paid' => $invoice->paidTotal(),
            'outstanding' => $invoice->outstanding(),
            'bank' => null,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();
    }

    /* ------------------------------ الورقة ------------------------------ */

    /** ما مُلئ يُطبع */
    public function test_the_pdf_prints_the_fields_that_were_filled(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id, $this->customer,
            ['po_number' => 'PO-4481', 'department' => 'العلاقات العامة'],
            [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
            $this->owner->id,
        ), $this->owner->id);

        $html = $this->render($invoice);

        $this->assertStringContainsString('PO-4481', $html);
        $this->assertStringContainsString('العلاقات العامة', $html);
        $this->assertStringContainsString(__('رقم أمر الشراء (PO)'), $html);
        $this->assertStringContainsString(__('القسم / الإدارة'), $html);
        // والبابُ نفسُه يُفتح — القالبُ وحده لا يكفي
        $this->actingAs($this->owner)->get('/admin/customer-invoices/'.$invoice->id.'/pdf')->assertOk();
    }

    /**
     * وما لم يُملأ لا يُطبع — لا اسمُه ولا شرطةٌ مكانه.
     *
     * والفحصُ على الاسم لا على القيمة: قيمةٌ فارغةٌ لا تُرى، أمّا «رقم العقد:»
     * متبوعًا بفراغٍ فيُرى — وهو ما كان يقع في «عناية» و«القسم».
     *
     * والاسمُ يُطلب من `__()` لا يُكتب عربيًّا: القالبُ يترجمه، والحزمةُ
     * الكاملة تعمل بالإنجليزية — فنصٌّ عربيٌّ مكتوبٌ هنا غائبٌ عن الورقة
     * دائمًا، ويمرّ الفحصُ وهو لا يفحص شيئًا. وهو ما وقع في أوّل صياغة.
     */
    public function test_the_pdf_hides_every_field_that_was_left_empty(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id, $this->customer, ['po_number' => 'PO-4481'],
            [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
            $this->owner->id,
        ), $this->owner->id);

        $html = $this->render($invoice);

        foreach (['رقم العقد', 'رقم المرجع', 'مركز التكلفة', 'موجه إلى / عناية'] as $absent) {
            $this->assertStringNotContainsString(__($absent), $html, $absent.' طُبعت وهي فارغة');
        }

        // والمملوءُ حاضرٌ في الورقة نفسها — كي لا يمرّ الفحصُ على ورقةٍ خاوية
        $this->assertStringContainsString(__('رقم أمر الشراء (PO)'), $html);
    }

    /** وورقةٌ قديمةٌ بلا شيءٍ من الستّة تُطبع كما كانت */
    public function test_an_invoice_with_no_organisation_fields_still_renders(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id, $this->customer, [],
            [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100]],
            $this->owner->id,
        ), $this->owner->id);

        $this->actingAs($this->owner)
            ->get('/admin/customer-invoices/'.$invoice->id.'/pdf')->assertOk();
    }

    /* ------------------------ ولا انكسر ما كان ------------------------ */

    /** المسودّةُ تُحفظ، والإصدارُ يقع، والإجماليُّ لم يتغيّر */
    public function test_draft_and_issue_and_totals_are_untouched(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload(self::ORG))
            ->assertSessionHasNoErrors();
        $draft = CustomerInvoice::firstOrFail();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertSame(105.0, (float) $draft->total);

        $this->actingAs($this->owner)->post('/admin/customer-invoices/'.$draft->id.'/issue')
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::ISSUED, $draft->fresh()->status);
        $this->assertSame(105.0, $draft->fresh()->outstanding());
        $this->assertSame(105.0, Ledger::balance($this->business->id, 'receivable'));
    }

    /** والبيعُ الآجلُ من الشاشة نفسها ما زال يُسجَّل إيصالًا حين يُقبض */
    public function test_the_payment_flow_still_works_alongside_the_new_fields(): void
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload(self::ORG + [
            'issue' => true, 'payment_method' => 'نقدي',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0.0, CustomerInvoice::firstOrFail()->outstanding());
        $this->assertSame('PO-2026-154', CustomerInvoice::firstOrFail()->po_number);
    }

    /** وعميلُ متجرٍ آخر يُردّ عند الباب مهما حملت الحمولةُ من بياناتِ جهة */
    public function test_a_cross_tenant_customer_is_still_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->actingAs($this->owner)->post('/admin/customer-invoices', $this->payload(self::ORG + [
            'customer_id' => $theirs->id,
        ]))->assertSessionHasErrors('customer_id');

        $this->assertSame(0, CustomerInvoice::count());
    }
}
