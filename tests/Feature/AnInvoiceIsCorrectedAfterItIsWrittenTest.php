<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\BooksPeriod;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\CustomerPurchases;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Receivables;
use App\Support\ReportData;
use App\Support\ReportingPeriod;
use App\Support\StandaloneInvoiceSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تعديلُ فاتورة العميل وتصحيحُها — المسودّةُ تُعدَّل، والصادرةُ تُصحَّح بمستند.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الفاتورةُ الصادرة كما صدرت: رقمُها وتاريخُها وبنودُها ومجاميعُها وقيدُها
 *     وورقتُها — ولو صُحّحت مرّاتٍ ودُفعت كلُّها (م١٥٧(١) من اللائحة).
 *   - ما نقص إشعارٌ دائن، وما زاد إشعارٌ مدين: لكلٍّ رقمُه وتسلسلُه وتاريخُه
 *     وبنودُه وقيدُه في يومه، ويشير إلى الفاتورة (م٤٨ و٥٠ و١٥٥).
 *   - المقبوضُ لا يُمسّ: الزيادةُ باقٍ على العميل، والنقصُ عمّا قُبض رصيدٌ له،
 *     ولا يُردّ مالٌ ولا يُفعَّل رابطٌ قديم ولا تتكرّر مهمّةُ تجهيز.
 *   - لا إيرادَ ولا ضريبةَ مرّتين، والإقرارُ والسجلُّ وكشفُ الحساب يقرؤون المستندات.
 *   - ما لم يُحسم حكمُه الضريبيّ يُمنع: الفترةُ المُقرّة، والإرجاعُ بعد ثلاثة أشهر،
 *     وتغييرُ الوصف أو النسبة أو بيانات الفاتورة المطبوعة.
 *   - الصلاحياتُ بحال الورقة، وعزلُ المتاجر، والنموذجُ المكرّر، والنسخةُ القديمة.
 */
class AnInvoiceIsCorrectedAfterItIsWrittenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $branch;

    private User $owner;

    private Customer $ministry;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الفواتير', 'type' => 'عام', 'status' => 'نشط']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = $this->user('admin', 'owner@edit.om');
        $this->actingAs($this->owner);

        $this->ministry = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function user(string $role, string $email, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $role.' '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /** فاتورةٌ يدويّةٌ صادرة: بندٌ واحد، بنسبة المتجر (٥٪) */
    private function issued(string $on, float $qty, float $price, array $line = []): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => $on, 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => $qty, 'unit_price' => $price] + $line,
        ], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function pay(CustomerInvoice $invoice, float $amount, string $on = '2025-09-12', string $method = 'نقدي'): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->ministry, $amount, [
            'method' => $method, 'occurred_at' => $on,
        ], [$invoice->id => $amount], $this->owner->id);
    }

    /**
     * النموذجُ كما تُرسله الشاشة — والبنودُ كما تعرضها: من حال الورقة بعد آخر
     * تصحيح (`CustomerInvoiceEdits::state`) لصادرة، ومن صفوفها لمسودّة.
     */
    private function form(CustomerInvoice $invoice, array $over = [], ?array $items = null): array
    {
        $invoice = $invoice->fresh('items');
        $lines = $invoice->status === CustomerInvoice::DRAFT
            ? $invoice->items->map(fn ($i) => [
                'item_id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'tax_rate' => (float) $i->tax_rate,
            ])->all()
            : array_map(fn ($l) => [
                'key' => $l['key'], 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
                'unit_price' => (float) $l['unit_price'], 'discount' => (float) $l['discount'], 'tax_rate' => (float) $l['tax_rate'],
            ], CustomerInvoiceEdits::state($invoice)['lines']);

        return array_merge([
            'expected_revision' => (int) $invoice->revision,
            'edit_token' => 'tok-'.(++$this->n),
            'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id,
            'notes' => $invoice->notes,
            'internal_notes' => $invoice->internal_notes,
            'reason' => 'تصحيح الكمية حسب أمر الشراء',
            'basis' => CustomerInvoiceRevision::ERROR,
            'items' => $items ?? $lines,
        ], $over);
    }

    /** البندُ الأوّل بكمّيّةٍ أو سعرٍ آخر */
    private function withLine(CustomerInvoice $invoice, array $line, array $over = []): array
    {
        $form = $this->form($invoice, $over);
        $form['items'][0] = array_merge($form['items'][0], $line);

        return $form;
    }

    private function edit(CustomerInvoice $invoice, array $form)
    {
        return $this->put(route('admin.customerInvoices.update', $invoice->id), $form);
    }

    private function vat(string $month = '2025-09'): array
    {
        return ReportData::vat($this->shop->id, ['_period' => ReportingPeriod::month($month)])['summary'];
    }

    private function ledger(string $month = '2025-09'): array
    {
        $p = ReportingPeriod::month($month);

        return [
            'net' => Ledger::netRevenue($this->shop->id, $p->start, $p->end),
            'tax' => Ledger::outputTax($this->shop->id, $p->start, $p->end),
        ];
    }

    /** الفاتورةُ كما صدرت — رأسًا وبنودًا ومالًا وقيدًا */
    private function asIssued(CustomerInvoice $invoice): array
    {
        $invoice = $invoice->fresh('items');

        return [
            'head' => [$invoice->number, $invoice->status, $invoice->issued_at->toDateString(), (int) $invoice->customer_id, $invoice->customer_name],
            'money' => [(float) $invoice->subtotal, (float) $invoice->discount_total, (float) $invoice->tax_total, (float) $invoice->total],
            'items' => $invoice->items->map(fn ($i) => [$i->id, $i->description, (float) $i->quantity, (float) $i->unit_price, (float) $i->discount, (float) $i->tax_rate, (float) $i->tax_amount, (float) $i->line_total])->all(),
            'entries' => JournalEntry::where('business_id', $this->shop->id)->where('sourceable_type', CustomerInvoice::class)
                ->where('sourceable_id', $invoice->id)->get(['id', 'entry_date', 'reversed_at', 'reverses_id'])
                ->map(fn ($e) => [$e->id, Carbon::parse($e->entry_date)->toDateString(), $e->reversed_at, $e->reverses_id])->all(),
        ];
    }

    /* ═══════════ ١ · المسودّة ═══════════ */

    public function test_a_draft_is_rewritten_with_no_entry_no_document_and_its_history_kept(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], [
            ['description' => 'عرض', 'quantity' => 1, 'unit_price' => 10],
        ], $this->owner->id);
        $other = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92222222', 'allow_credit_sales' => true]);

        $form = $this->withLine($draft, ['quantity' => 3, 'unit_price' => 20, 'discount' => 5], ['reason' => null, 'basis' => null, 'customer_id' => $other->id, 'issued_at' => '2025-09-15']);
        $this->edit($draft, $form)->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertNull($draft->number, 'المسودّةُ لا رقمَ ضريبيّ لها بعد');
        $this->assertSame('وزارة الصحة', $draft->customer_name);
        $this->assertSame('2025-09-15', $draft->issued_at->toDateString());
        $this->assertEquals(57.75, (float) $draft->total); // (60 − 5) × 1.05
        $this->assertSame(0, JournalEntry::where('business_id', $this->shop->id)->count());
        $this->assertSame(0, CustomerCreditNote::count() + CustomerDebitNote::count());
        $this->assertSame(CustomerInvoiceRevision::DRAFT, $draft->revisions()->first()->kind);
    }

    /* ═══════════ ٢ · الصادرة: الفاتورةُ كما صدرت، والتصحيحُ مستند ═══════════ */

    public function test_lowering_an_issued_invoice_issues_a_credit_note_and_leaves_the_invoice_as_issued(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 100 + 5 = 105
        $before = $this->asIssued($invoice);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1]))->assertSessionHasNoErrors()
            ->assertSessionHas('toast.msg', fn ($m) => str_contains($m, 'CN-000001'));

        // الفاتورةُ نفسُها: رقمُها وتاريخُها وبنودُها ومالُها وقيدُها كما صدرت — لا عكسَ ولا ترحيل
        $this->assertSame($before, $this->asIssued($invoice));

        $note = CustomerCreditNote::sole();
        $this->assertSame('CN-000001', $note->number);
        $this->assertSame('2025-09-20', $note->issued_at->toDateString());
        $this->assertSame((int) $invoice->id, (int) $note->customer_invoice_id);
        $this->assertEquals(52.5, (float) $note->amount);
        $this->assertEquals(2.5, (float) $note->tax_amount);
        $this->assertSame(CustomerInvoiceRevision::ERROR, $note->basis);
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $note->reason);
        $this->assertNotNull($note->revision_id);
        $this->assertEquals([[
            'key' => 'i'.$invoice->items()->first()->id, 'change' => 'changed', 'description' => 'توريد زهور',
            'quantity_before' => 2.0, 'quantity_after' => 1.0, 'unit_price_before' => 50.0, 'unit_price_after' => 50.0,
            'discount_before' => 0.0, 'discount_after' => 0.0, 'tax_rate' => 5.0, 'taxable' => 50.0, 'tax' => 2.5, 'total' => 52.5,
        ]], $note->items);

        // قيدُ الإشعار في يومه، والذمّةُ والمطلوبُ بعده
        $invoice->refresh();
        $this->assertEquals(52.5, $invoice->effectiveTotal());
        $this->assertEquals(52.5, $invoice->outstanding());
        $this->assertSame(['net' => 50.0, 'tax' => 2.5], $this->ledger());
        $this->assertEquals(2.5, $this->vat()['output']);
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
    }

    public function test_raising_an_issued_invoice_issues_a_debit_note_with_its_own_sequence(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $before = $this->asIssued($invoice);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasNoErrors();
        $second = $this->issued('2025-09-11', 1, 10);
        $this->edit($second, $this->withLine($second, ['unit_price' => 30]))->assertSessionHasNoErrors();

        $this->assertSame($before, $this->asIssued($invoice));
        $this->assertSame(['DN-000001', 'DN-000002'], CustomerDebitNote::orderBy('id')->pluck('number')->all(), 'تسلسلٌ للإشعارات المدينة مستقلٌّ عن الفواتير والدائنة');

        $note = CustomerDebitNote::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertEquals(52.5, (float) $note->amount);
        $this->assertEquals(2.5, (float) $note->tax_amount);
        $entry = JournalEntry::where('sourceable_type', CustomerDebitNote::class)->where('sourceable_id', $note->id)->sole();
        $this->assertSame(CustomerInvoices::SOURCE_DEBIT, $entry->source);
        $this->assertSame('2025-09-20', Carbon::parse($entry->entry_date)->toDateString());

        $this->assertEquals(157.5, $invoice->fresh()->outstanding());
        $this->assertSame(['net' => 180.0, 'tax' => 9.0], $this->ledger()); // 100 + 50 + 10 + 20
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);

        // وإشعارٌ مدينٌ لا يُعدَّل ولا يُحذف
        try {
            $note->update(['amount' => 1]);
            $this->fail('الإشعار المدين عُدّل');
        } catch (\LogicException) {
        }
        $this->assertEquals(52.5, (float) $note->fresh()->amount);
    }

    public function test_one_line_down_and_a_new_line_up_issue_both_documents_never_netted(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $form = $this->form($invoice);
        $form['items'][0]['quantity'] = 1;
        $form['items'][] = ['description' => 'تغليف فاخر', 'quantity' => 1, 'unit_price' => 30];

        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $credit = CustomerCreditNote::sole();
        $debit = CustomerDebitNote::sole();
        $this->assertEquals(52.5, (float) $credit->amount);
        $this->assertEquals(31.5, (float) $debit->amount);
        $this->assertSame((int) $credit->revision_id, (int) $debit->revision_id, 'مستندا تصحيحٍ واحد');
        $this->assertSame('added', $debit->items[0]['change']);
        $this->assertEquals(84.0, $invoice->fresh()->effectiveTotal()); // 105 − 52.5 + 31.5
        $this->assertSame(['net' => 80.0, 'tax' => 4.0], $this->ledger());
    }

    public function test_corrections_chain_from_the_last_state_not_from_the_invoice(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 105

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1]))->assertSessionHasNoErrors(); // −52.5
        $this->edit($invoice, $this->withLine($invoice->fresh(), ['unit_price' => 70]))->assertSessionHasNoErrors(); // 1 × 70 → +21

        $invoice->refresh();
        $this->assertSame(2, (int) $invoice->revision);
        $this->assertEquals(105.0, (float) $invoice->total, 'الفاتورةُ بمبلغها كما صدرت');
        $this->assertEquals(73.5, $invoice->effectiveTotal());
        $this->assertSame([52.5], CustomerCreditNote::pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame([21.0], CustomerDebitNote::pluck('amount')->map(fn ($a) => (float) $a)->all());

        $state = CustomerInvoiceEdits::state($invoice);
        $this->assertEquals(73.5, $state['total']);
        $this->assertEquals(1.0, $state['lines'][0]['quantity']);
        $this->assertEquals(70.0, $state['lines'][0]['unit_price']);
        // وبنودُ الحال تساوي الفاتورةَ والمستندات — لا إيرادَ مرّتين
        $this->assertEquals($invoice->effectiveTotal(), $state['total']);
        $this->assertSame(['net' => 70.0, 'tax' => 3.5], $this->ledger());
    }

    /* ═══════════ ٣ · بعد الدفع — مثالُ الخمسين ═══════════ */

    public function test_a_paymob_paid_fifty_raised_to_fifty_five_owes_five_and_its_receipt_stays(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => 'inclusive']);
        $invoice = $this->issued('2025-09-10', 1, 50); // شاملة: 50
        $this->assertEquals(50.0, (float) $invoice->total);
        $payment = $this->pay($invoice, 50, method: 'Paymob');

        $this->edit($invoice, $this->withLine($invoice, ['unit_price' => 55]))->assertSessionHasNoErrors();

        $note = CustomerDebitNote::sole();
        $this->assertEquals(5.0, (float) $note->amount);
        $this->assertEquals(0.238, (float) $note->tax_amount); // 5 × 5 ÷ 105
        $invoice->refresh();
        $this->assertEquals(5.0, $invoice->outstanding(), 'الخمسةُ باقيةٌ على العميل');
        $this->assertSame('مدفوعة جزئيًا', $invoice->paymentState());
        $this->assertEquals(50.0, (float) $payment->fresh()->amount, 'سندُ القبض كما هو');
        $this->assertEquals(50.0, $invoice->paidTotal());
        $this->assertSame(1, CustomerPayment::count());
        $this->assertEquals(50.0, (float) $invoice->total);
    }

    public function test_a_paymob_paid_fifty_lowered_to_forty_five_is_a_credit_balance_and_nothing_is_refunded(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => 'inclusive']);
        $invoice = $this->issued('2025-09-10', 1, 50);
        $payment = $this->pay($invoice, 50, method: 'Paymob');

        $this->edit($invoice, $this->withLine($invoice, ['unit_price' => 45]))->assertSessionHasNoErrors();

        $note = CustomerCreditNote::sole();
        $this->assertEquals(5.0, (float) $note->amount);
        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->outstanding());
        $this->assertSame('مدفوعة', $invoice->paymentState());
        $this->assertEquals(5.0, Receivables::credit($this->shop->id, $this->ministry->id), 'رصيدٌ دائنٌ للعميل — لا ردّ تلقائيّ');
        $this->assertEquals(50.0, (float) $payment->fresh()->amount);
        $this->assertNull($payment->fresh()->cancelled_at);
        $this->assertSame(1, CustomerPayment::count(), 'لا سندَ صرفٍ ولا ردّ');
    }

    public function test_a_partly_paid_invoice_keeps_what_was_collected(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 105
        $this->pay($invoice, 30);

        $this->edit($invoice, $this->withLine($invoice, ['unit_price' => 40]))->assertSessionHasNoErrors(); // 84 → دائن 21

        $invoice->refresh();
        $this->assertEquals(30.0, $invoice->paidTotal());
        $this->assertEquals(54.0, $invoice->outstanding());
        $this->assertSame(CustomerInvoiceRevision::PAID, $invoice->revisions()->first()->kind);
        $this->assertEquals(30.0, (float) $invoice->revisions()->first()->paid_at_edit);
    }

    public function test_a_paid_task_on_the_board_is_corrected_in_price_and_never_duplicated(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $invoice = $this->issued('2025-09-10', 2, 50);
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id, 'scheduled_for' => '2025-09-21 16:00',
            'recipient_name' => 'سالم', 'recipient_phone' => '91234567',
        ], $this->owner->id);
        $this->pay($invoice, 105, method: 'Paymob');
        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        // الكمّيّةُ لا تتغيّر تحت من يجهّزها
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasErrors('edit');
        $this->assertSame(0, CustomerDebitNote::count());

        // والسعرُ يُصحَّح — والمهمّةُ واحدةٌ كما كانت
        $this->edit($invoice, $this->withLine($invoice, ['unit_price' => 45]))->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $task->fresh()->activation_source);
        $this->assertSame($task->activated_at->toDateTimeString(), $task->fresh()->activated_at->toDateTimeString());
    }

    /* ═══════════ ٤ · ما يُمنع ═══════════ */

    public function test_printed_invoice_details_never_change_after_issue(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $other = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92222222', 'allow_credit_sales' => true]);

        foreach ([
            ['customer_id' => $other->id],
            ['issued_at' => '2025-09-12'],
            ['notes' => 'ملاحظة للعميل بعد الإصدار'],
            ['po_number' => 'PO-1'],
        ] as $over) {
            $this->edit($invoice, $this->form($invoice, $over))->assertSessionHasErrors('edit');
        }

        $form = $this->form($invoice);
        $form['items'][0]['description'] = 'وصف آخر';
        $this->edit($invoice, $form)->assertSessionHasErrors('edit');

        $form = $this->form($invoice);
        $form['items'][0]['tax_rate'] = 0;
        $this->edit($invoice, $form)->assertSessionHasErrors('edit');

        $form = $this->form($invoice);
        $form['items'][] = ['description' => 'بند معفى', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0];
        $this->edit($invoice, $form)->assertSessionHasErrors('edit');

        $this->assertSame(0, CustomerInvoiceRevision::count());

        // وما لا يُطبع يُعدَّل في مكانه
        $this->edit($invoice, $this->form($invoice, ['internal_notes' => 'تابع مع المشتريات']))->assertSessionHasNoErrors();
        $this->assertSame('تابع مع المشتريات', $invoice->fresh()->internal_notes);
        $this->assertSame(0, CustomerCreditNote::count() + CustomerDebitNote::count());
    }

    public function test_a_correction_needs_a_reason_and_a_basis(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1], ['reason' => '']))->assertSessionHasErrors('reason');
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1], ['basis' => null]))->assertSessionHasErrors('basis');

        $this->assertSame(0, CustomerCreditNote::count());
    }

    public function test_an_invoice_born_of_an_order_keeps_its_lines_its_stock_and_its_books(): void
    {
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 100, 'quantity' => 10]);
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'number' => 'S-1', 'status' => 'مكتمل',
            'is_held' => false, 'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع',
            'customer_id' => $this->ministry->id, 'customer_name' => $this->ministry->name,
            'subtotal' => 100, 'discount' => 0, 'tax' => 5, 'total' => 105, 'ordered_at' => Carbon::parse('2025-09-06'),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $rose->id, 'name' => 'وردة', 'price' => 100, 'quantity' => 1, 'cost' => 0, 'total' => 100]);
        Books::recordSale($order);
        $invoice = CustomerInvoices::issue(CustomerInvoices::fromOrder($order, [], $this->owner->id), $this->owner->id);
        $entries = JournalEntry::where('business_id', $this->shop->id)->count();

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 5]))->assertSessionHasErrors('edit');

        $this->assertEquals(105.0, (float) $invoice->fresh()->total);
        $this->assertSame(10, (int) $rose->fresh()->quantity, 'لا حركة مخزون');
        $this->assertSame($entries, JournalEntry::where('business_id', $this->shop->id)->count(), 'لا قيد ثانٍ للبيعة');
        $this->assertSame(0, CustomerDebitNote::count());
        $this->expectException(\RuntimeException::class);
        CustomerInvoices::debitNote($invoice, 10, 0, 'زيادة', []);
    }

    public function test_a_pending_payment_link_holds_the_amount(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id,
            'reference' => 'ref-edit-1', 'amount' => 105, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING,
            'expires_at' => now()->addDays(3), 'is_live' => true,
        ]);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertSessionHasErrors('edit');
        $this->assertSame(0, CustomerDebitNote::count());
        $this->assertSame(CustomerPaymentLink::PENDING, CustomerPaymentLink::sole()->status, 'الرابطُ كما هو — لا يُعاد ولا يُلغى من هنا');
    }

    public function test_manual_credit_notes_set_a_floor_under_the_lines(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        CustomerInvoices::creditNote($invoice, 63, 3, 'مرتجع جزئي', $this->owner->id);

        $this->edit($invoice, $this->withLine($invoice, ['unit_price' => 50]))->assertSessionHasErrors('edit'); // 52.5 < 63
        $this->assertSame(1, CustomerCreditNote::count());
    }

    /* ═══════════ ٥ · الفترات والإقرار ═══════════ */

    public function test_an_invoice_in_a_closed_month_is_corrected_today_by_its_own_permission(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-09-30']);
        Carbon::setTestNow('2025-10-05 10:00:00');

        // يملك تصحيحَ الصادرة ولا يملك تصحيحَ فترةٍ سابقة
        $this->actingAs($this->user('accountant', 'prior@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]));
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertForbidden();

        $this->actingAs($this->owner);
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasNoErrors();

        // سبتمبرُ المقفلُ كما أُعلن — والفرقُ في أكتوبر يومَ المستند
        $this->assertSame(['net' => 100.0, 'tax' => 5.0], $this->ledger('2025-09'));
        $this->assertEquals(5.0, $this->vat('2025-09')['output']);
        $this->assertSame(['net' => 50.0, 'tax' => 2.5], $this->ledger('2025-10'));
        $this->assertEquals(2.5, $this->vat('2025-10')['output']);
        $this->assertSame('2025-10-05', CustomerDebitNote::sole()->issued_at->toDateString());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
    }

    public function test_books_closed_through_today_take_no_document(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-09-20']);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1]))->assertSessionHasErrors('edit');
        $this->assertSame(0, CustomerCreditNote::count());
    }

    public function test_an_error_in_a_filed_vat_period_waits_for_the_accountant_and_a_return_does_not(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_filed_through'], ['value' => '2025-09-15']);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1]))->assertSessionHasErrors('edit');
        $this->assertSame(0, CustomerCreditNote::count());

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 1], ['basis' => CustomerInvoiceRevision::RETURN, 'reason' => 'رُدّت باقة']))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoiceRevision::RETURN, CustomerCreditNote::sole()->basis);
    }

    public function test_a_return_never_raises_and_waits_for_the_accountant_after_three_months(): void
    {
        $old = $this->issued('2025-05-10', 2, 50);
        $this->edit($old, $this->withLine($old, ['quantity' => 1], ['basis' => CustomerInvoiceRevision::RETURN]))->assertSessionHasErrors('edit');

        $recent = $this->issued('2025-09-10', 2, 50);
        $this->edit($recent, $this->withLine($recent, ['quantity' => 3], ['basis' => CustomerInvoiceRevision::RETURN]))->assertSessionHasErrors('edit');

        $this->assertSame(0, CustomerCreditNote::count() + CustomerDebitNote::count());
    }

    public function test_a_merchant_not_registered_for_vat_corrects_by_documents_without_tax(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $invoice = $this->issued('2025-09-10', 2, 50);
        $this->assertEquals(100.0, (float) $invoice->total);
        $this->assertEquals(0.0, (float) $invoice->tax_total);

        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasNoErrors();

        $note = CustomerDebitNote::sole();
        $this->assertEquals(50.0, (float) $note->amount);
        $this->assertEquals(0.0, (float) $note->tax_amount);
        $this->assertEquals(100.0, (float) $invoice->fresh()->total, 'ولا تُعاد كتابتُها هي أيضًا');
        $this->assertSame(['net' => 150.0, 'tax' => 0.0], $this->ledger());
    }

    /* ═══════════ ٦ · الصلاحيات والعزل ═══════════ */

    public function test_who_corrects_what_is_decided_by_the_papers_state(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], [
            ['description' => 'عرض', 'quantity' => 1, 'unit_price' => 10],
        ], $this->owner->id);
        $invoice = $this->issued('2025-09-10', 1, 100);
        $paid = $this->issued('2025-09-11', 1, 100);
        $this->pay($paid, 50);

        // البائعُ يعدّل المسودّة بدوره، ولا يصحّح الصادرة ولا المدفوعة — ولا ببابٍ مباشر
        $this->actingAs($this->user('sales', 'seller@edit.om'));
        $this->edit($draft, $this->withLine($draft, ['quantity' => 2], ['reason' => null, 'basis' => null]))->assertSessionHasNoErrors();
        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertForbidden();
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertForbidden();
        $this->edit($paid, $this->withLine($paid, ['quantity' => 2]))->assertForbidden();

        // ومن يُصدر الفاتورة لا يصحّحها بذلك — ولا مديرُ الفرع والمحاسب بدورهما
        $this->actingAs($this->user('accountant', 'issuer@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_ISSUE, Permissions::CUSTOMER_CREDIT_NOTE]));
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertForbidden();
        foreach (['manager', 'accountant'] as $role) {
            $this->actingAs($this->user($role, $role.'@edit.om'));
            $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertForbidden();
        }

        // ومن مُنح تصحيحَ الصادرة يصحّحها — ولا يصحّح المدفوعة بها
        $this->actingAs($this->user('accountant', 'granted@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]));
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertSessionHasNoErrors();
        $this->edit($paid, $this->withLine($paid, ['quantity' => 2]))->assertForbidden();

        $this->assertSame(1, CustomerDebitNote::count());
        $this->assertSame((int) $invoice->id, (int) CustomerDebitNote::sole()->customer_invoice_id);

        // والأفعالُ بأسمائها العربيّة في شاشة الموظّفين، والمدفوعةُ لصاحب النشاط وحده بدوره
        $labels = Permissions::actionLabels();
        $this->assertSame('تصحيح فاتورة عميل مدفوعة جزئيًا أو كليًا', $labels[Permissions::CUSTOMER_INVOICE_EDIT_PAID]);
        $this->assertSame(['admin'], Permissions::ACTION_ROLES[Permissions::CUSTOMER_INVOICE_EDIT_PAID]);
        $this->assertNotContains('sales', Permissions::ACTION_ROLES[Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
    }

    public function test_a_shop_never_reaches_another_shops_invoice_or_documents(): void
    {
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '97000000']);
        $theirs = CustomerInvoices::issue(CustomerInvoices::create($this->other->id, $stranger, ['issued_at' => '2025-09-10'], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 700],
        ]));
        $theirOwner = $this->user('admin', 'neighbour@edit.om', null, $this->other);
        $this->actingAs($theirOwner);
        $this->edit($theirs, $this->withLine($theirs, ['quantity' => 2]))->assertSessionHasNoErrors();
        $theirNote = CustomerDebitNote::sole();

        $this->actingAs($this->owner);
        $this->get(route('admin.customerInvoices.edit', $theirs->id))->assertNotFound();
        $this->post(route('admin.customerInvoices.editPreview', $theirs->id), $this->form($theirs))->assertNotFound();
        $this->edit($theirs, $this->withLine($theirs, ['quantity' => 9]))->assertNotFound();
        $this->post(route('admin.customerInvoices.replace', $theirs->id), ['customer_id' => $this->ministry->id, 'reason' => 'x'])->assertNotFound();
        $this->get(route('admin.customerInvoices.debitNotes.show', $theirNote->id))->assertNotFound();
        $this->get(route('admin.customerInvoices.debitNotes.pdf', $theirNote->id))->assertNotFound();
        $this->assertSame(1, CustomerDebitNote::count());

        // ولا يُكتب في ورقته بندٌ من متجرٍ آخر
        $mine = $this->issued('2025-09-10', 1, 100);
        $this->edit($mine, $this->form($mine, [], [['key' => 'i'.$theirs->items()->first()->id, 'quantity' => 1, 'unit_price' => 1]]))->assertSessionHasErrors('items');
    }

    /* ═══════════ ٧ · مرّةً واحدة، وبلا محوٍ لما سبق ═══════════ */

    public function test_the_same_form_sent_twice_issues_one_document(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $form = $this->withLine($invoice, ['quantity' => 3]);

        $this->edit($invoice, $form)->assertSessionHasNoErrors();
        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(1, CustomerDebitNote::count());
        $this->assertSame(['net' => 150.0, 'tax' => 7.5], $this->ledger());
    }

    public function test_a_second_corrector_with_an_old_copy_issues_nothing(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $first = $this->withLine($invoice, ['quantity' => 3]);
        $second = $this->withLine($invoice, ['quantity' => 9]); // فُتحت النسخةُ نفسُها قبل حفظ الأوّل

        $this->edit($invoice, $first)->assertSessionHasNoErrors();
        $this->edit($invoice, $second)->assertSessionHasErrors('edit');

        $this->assertSame(1, CustomerDebitNote::count());
        $this->assertEquals(157.5, $invoice->fresh()->effectiveTotal());
    }

    /* ═══════════ ٨ · العميلُ الآخر: إلغاءٌ وبديلة ═══════════ */

    public function test_another_customer_cancels_an_unpaid_invoice_and_writes_its_replacement_as_a_draft(): void
    {
        $other = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92222222', 'allow_credit_sales' => true]);
        $invoice = $this->issued('2025-09-10', 2, 50);

        // من لا يملك الإلغاء لا يستبدل
        $this->actingAs($this->user('accountant', 'nocancel@edit.om', ['orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]));
        $this->post(route('admin.customerInvoices.replace', $invoice->id), ['customer_id' => $other->id, 'reason' => 'العميل الصحيح'])->assertForbidden();

        $this->actingAs($this->owner);
        $this->post(route('admin.customerInvoices.replace', $invoice->id), ['customer_id' => $other->id, 'reason' => 'العميل الصحيح وزارة الصحة'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $invoice->refresh();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->status);
        $this->assertSame('CINV-000001', $invoice->number, 'تبقى برقمها مقروءةً');
        $draft = CustomerInvoice::where('replaces_invoice_id', $invoice->id)->sole();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertSame((int) $other->id, (int) $draft->customer_id);
        $this->assertEquals(105.0, (float) $draft->total);
        $this->assertSame(['net' => 0.0, 'tax' => 0.0], $this->ledger(), 'قيدُها عُكس — والبديلةُ مسودّةٌ بلا قيد');

        // ومدفوعةٌ لا تُستبدل: مالُ عميلٍ لا ينتقل إلى غيره
        $paid = $this->issued('2025-09-11', 1, 100);
        $this->pay($paid, 10);
        $this->post(route('admin.customerInvoices.replace', $paid->id), ['customer_id' => $other->id, 'reason' => 'x'])->assertSessionHasErrors('replace');
        $this->assertSame(CustomerInvoice::ISSUED, $paid->fresh()->status);
    }

    /* ═══════════ ٩ · التقارير والأوراق ═══════════ */

    public function test_reports_read_the_documents_once_and_in_their_own_day(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50); // 105
        $this->pay($invoice, 105);
        Carbon::setTestNow('2025-10-05 10:00:00');
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasNoErrors(); // +52.5 في أكتوبر

        // الإقرارُ ومبيعاتُ اللوحة: سبتمبرُ كما كان، والزيادةُ في أكتوبر
        $this->assertEquals(5.0, $this->vat('2025-09')['output']);
        $this->assertEquals(2.5, $this->vat('2025-10')['output']);
        $this->assertEquals(52.5, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2025-10-01'), Carbon::parse('2025-11-01')));
        $this->assertEquals(105.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2025-09-01'), Carbon::parse('2025-10-01')));

        // كشفُ الحساب: الفاتورةُ والتحصيلُ والإشعارُ المدين — والباقي 52.5
        $statement = Receivables::statement($this->shop->id, $this->ministry->id, Carbon::parse('2025-09-01'), Carbon::parse('2025-10-31'));
        $this->assertSame(['فاتورة', 'تحصيل', 'إشعار مدين'], array_column($statement['rows'], 'kind'));
        $this->assertEquals(52.5, $statement['closing']);

        // ومشترياتُه في سجلّه: 157.5 لا 105 ولا 262.5
        $row = CustomerPurchases::apply(Customer::whereKey($this->ministry->id))->first();
        $this->assertEquals(157.5, CustomerPurchases::total($row));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
    }

    public function test_the_original_and_each_document_open_and_print_on_their_own(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $form = $this->form($invoice);
        $form['items'][0]['quantity'] = 1;
        $form['items'][] = ['description' => 'تغليف فاخر', 'quantity' => 1, 'unit_price' => 30];
        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $credit = CustomerCreditNote::sole();
        $debit = CustomerDebitNote::sole();

        $show = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertEquals(105.0, $show['invoice']['total'], 'الفاتورةُ بمبلغها كما صدرت');
        $this->assertEquals(84.0, $show['invoice']['effective_total']);
        $this->assertSame($credit->number, $show['invoice']['credit_notes'][0]['number']);
        $this->assertSame($debit->number, $show['invoice']['debit_notes'][0]['number']);
        $this->assertCount(2, $show['revisions'][0]['documents']);

        $page = $this->get(route('admin.customerInvoices.debitNotes.show', $debit->id))->assertOk()->viewData('page')['props'];
        $this->assertSame('debit', $page['kind']);
        $this->assertStringContainsString('إشعار مدين', $page['paper']['html']);
        $this->assertStringContainsString('تغليف فاخر', $page['paper']['html']);
        $this->assertStringContainsString($invoice->number, $page['paper']['html']);

        $page = $this->get(route('admin.customerInvoices.creditNotes.show', $credit->id))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('إشعار دائن', $page['paper']['html']);
        $this->assertStringContainsString('توريد زهور', $page['paper']['html']);

        $this->get(route('admin.customerInvoices.debitNotes.pdf', $debit->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.customerInvoices.creditNotes.pdf', $credit->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk();
    }

    /* ═══════════ ١٠ · السجلّ والمعاينة ═══════════ */

    public function test_the_history_is_read_by_who_may_and_never_rewritten(): void
    {
        $invoice = $this->issued('2025-09-10', 2, 50);
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 3]))->assertSessionHasNoErrors();

        $page = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['mayEdit']);
        $this->assertCount(1, $page['revisions']);
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $page['revisions'][0]['reason']);
        $this->assertSame('تصحيح خطأ في الفاتورة', $page['revisions'][0]['basis']);
        $this->assertEquals(157.5, $page['revisions'][0]['total_after']);

        $this->actingAs($this->user('sales', 'reader@edit.om'));
        $page = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertFalse($page['mayEdit']);
        $this->assertNull($page['revisions'], 'السجلُّ لمن يُمنح قراءته');

        $rev = CustomerInvoiceRevision::first();
        try {
            $rev->update(['reason' => 'غيرُه']);
            $this->fail('سجلُّ التعديل عُدّل');
        } catch (\LogicException) {
        }
        $this->assertSame('تصحيح الكمية حسب أمر الشراء', $rev->fresh()->reason);
    }

    public function test_the_preview_names_the_documents_and_writes_nothing(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $this->pay($invoice, 105);
        $form = $this->withLine($invoice, ['unit_price' => 80]);

        $plan = $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $form)->assertOk()->json();

        $this->assertTrue($plan['changed']);
        $this->assertSame('paid', $plan['kind']);
        $this->assertEquals(-21.0, $plan['money']['difference']);
        $this->assertEquals(21.0, $plan['money']['customer_credit']);
        $this->assertEquals(21.0, $plan['documents']['credit']['amount']);
        $this->assertEquals(1.0, $plan['documents']['credit']['tax']);
        $this->assertNull($plan['documents']['debit']);
        $this->assertSame('2025-09-20', $plan['period']['note_date']);
        $this->assertFalse($plan['stock']['moves']);
        $this->assertSame([], $plan['blockers']);

        $this->assertSame(0, CustomerInvoiceRevision::count());
        $this->assertSame(0, CustomerCreditNote::count());
    }

    public function test_a_catalog_addon_line_keeps_the_price_it_was_sold_at(): void
    {
        $chocolate = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 3, 'active' => true, 'scope' => Addon::SCOPE_ALL,
        ]);
        $lines = CustomerInvoices::addonsFromCatalog($this->shop->id, [['addon_id' => $chocolate->id, 'quantity' => 2]]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10'], $lines, $this->owner->id), $this->owner->id);
        $chocolate->update(['price' => 9, 'active' => false]);

        $form = $this->withLine($invoice, ['quantity' => 4, 'unit_price' => 999]); // ثمنٌ مكتوبٌ بيد لإضافة كتالوج — لا يُقرأ
        $this->edit($invoice, $form)->assertSessionHasNoErrors();

        $this->assertEquals(6.3, (float) CustomerDebitNote::sole()->amount); // 2 × 3 × 1.05
    }

    public function test_an_item_leaves_with_its_paid_addons_or_not_at_all(): void
    {
        $invoice = $this->issued('2025-09-10', 1, 100);
        $parent = $invoice->items()->first();
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'parent_item_id' => $parent->id, 'description' => 'تغليف',
            'quantity' => 1, 'unit_price' => 0, 'discount' => 0, 'tax_rate' => 5, 'tax_amount' => 0, 'line_total' => 0, 'sort_order' => 1,
        ]);
        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertOk();

        $form = $this->form($invoice);
        $child = $form['items'][1];
        $this->edit($invoice, $this->form($invoice, [], [$child]))->assertSessionHasErrors('items');
        $this->assertSame(0, CustomerCreditNote::count());
    }

    public function test_a_new_merchant_finds_the_corrections_and_their_permissions_with_no_setup(): void
    {
        $fresh = Business::create(['name' => 'متجرٌ جديد', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($fresh->id);
        $owner = $this->user('admin', 'fresh@edit.om', null, $fresh);
        $customer = Customer::create(['business_id' => $fresh->id, 'name' => 'عميل', 'phone' => '93333333', 'allow_credit_sales' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($fresh->id, $customer, ['issued_at' => '2025-09-10'], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 100],
        ], $owner->id), $owner->id);

        $this->actingAs($owner);
        $this->assertTrue($this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props']['mayEdit']);
        $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertOk();
        $this->edit($invoice, $this->withLine($invoice, ['quantity' => 2]))->assertSessionHasNoErrors();
        $this->assertSame('DN-000001', CustomerDebitNote::where('business_id', $fresh->id)->sole()->number);
    }
}
