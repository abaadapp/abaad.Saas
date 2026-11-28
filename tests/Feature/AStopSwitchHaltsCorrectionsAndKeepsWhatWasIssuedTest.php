<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoices;
use App\Support\InvoiceCorrectionsSwitch;
use App\Support\Ledger;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مفتاحُ الإيقاف هو خطّةُ الرجوع: يوقف الإصدار ويُبقي القراءة.
 *
 * ═══ لمَ لا Revert ═══
 *
 * بعد أوّل إشعارٍ مدين، كودُ ما قبل التعديل لا يعرف الإشعارات المدينة: يقرأ ما على
 * العميل أقلَّ ممّا عليه، ويُسقطها من الإقرار — يتجاهل مستندًا ماليًّا صدر. فالرجوعُ
 * بعد ذلك إيقافٌ من هنا، وما صدر يبقى مقروءًا كما صدر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا صفّ = مشغَّل: يعمل كما نُشر.
 *   - موقوفًا: لا تعديلَ مسودّة، ولا تصحيحَ صادرة، ولا استبدال — والمعاينةُ تقول لماذا،
 *     والحفظُ لا يكتب شيئًا: لا مراجعة ولا إشعار ولا قيد.
 *   - موقوفًا: ما صدر يُقرأ — الصفحة والإشعار المدين والباقي والذمم والدفتر.
 *   - يبدّله مدير المنصّة وحده، وكلُّ تبديلٍ في السجلّ، والضغطُ مرّتين سطرٌ واحد.
 *   - يعود فيعمل التصحيح من جديد.
 */
class AStopSwitchHaltsCorrectionsAndKeepsWhatWasIssuedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private User $platform;

    private Customer $ministry;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الإيقاف', 'type' => 'عام', 'status' => 'نشط']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@stop.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'platform@stop.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->ministry = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true,
        ]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══ أدوات ═══ */

    private function draft(): CustomerInvoice
    {
        return CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-20', 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => 10, 'unit_price' => 10],
        ], $this->owner->id);
    }

    private function issued(): CustomerInvoice
    {
        return CustomerInvoices::issue($this->draft(), $this->owner->id);
    }

    /** نموذجُ التعديل كما ترسله الشاشة، وكمّيّةُ البند الأوّل `$qty` */
    private function form(CustomerInvoice $invoice, float $qty): array
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
        $lines[0]['quantity'] = $qty;

        return [
            'expected_revision' => (int) $invoice->revision, 'edit_token' => 'stop-'.(++$this->n),
            'customer_id' => $invoice->customer_id, 'issued_at' => $invoice->issued_at->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(), 'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id, 'notes' => $invoice->notes, 'internal_notes' => $invoice->internal_notes,
            'reason' => 'تصحيح الكمية حسب أمر الشراء', 'basis' => CustomerInvoiceRevision::ERROR, 'items' => $lines,
        ];
    }

    private function edit(CustomerInvoice $invoice, float $qty)
    {
        return $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, $qty));
    }

    /** ما لا يتحرّك ما دام المفتاحُ موقوفًا */
    private function written(): array
    {
        return [
            CustomerInvoiceRevision::count(), CustomerCreditNote::count(), CustomerDebitNote::count(),
            JournalEntry::where('business_id', $this->shop->id)->count(), CustomerInvoice::count(),
        ];
    }

    private function flip(bool $on)
    {
        return $this->actingAs($this->platform)->from(route('super-admin.settings.index'))
            ->post(route('super-admin.invoice-corrections.update'), ['enabled' => $on]);
    }

    /* ═══ الحالات ═══ */

    public function test_without_a_row_corrections_run_as_released(): void
    {
        $this->assertTrue(InvoiceCorrectionsSwitch::on());
        $invoice = $this->issued();

        $this->edit($invoice, 12)->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerDebitNote::count());
        $this->assertSame(0, Setting::whereNull('business_id')->where('key', InvoiceCorrectionsSwitch::KEY)->count(), 'كُتب صفٌّ لم يُطلب');
    }

    public function test_stopped_nothing_is_edited_corrected_or_replaced_and_the_reason_is_said_first(): void
    {
        $draft = $this->draft();
        $issued = $this->issued();
        $other = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة أخرى', 'phone' => '92345678', 'allow_credit_sales' => true]);
        $this->flip(false)->assertRedirect(route('super-admin.settings.index'));
        $this->actingAs($this->owner);
        $before = $this->written();

        // المعاينةُ تقول لماذا — قبل أن يكتب أحدٌ تصحيحه
        $preview = $this->postJson(route('admin.customerInvoices.editPreview', $issued->id), $this->form($issued, 8))->assertOk()->json();
        $this->assertSame(InvoiceCorrectionsSwitch::pausedReason(), $preview['blockers'][0]);
        $page = $this->get(route('admin.customerInvoices.edit', $issued->id))->assertOk()->viewData('page')['props'];
        $this->assertSame(InvoiceCorrectionsSwitch::pausedReason(), ((array) $page['standing'])['paused'] ?? null);

        // والحفظُ لا يكتب شيئًا — مسودّةً ولا صادرةً نقصًا ولا زيادة، ولا استبدالًا
        $this->edit($draft, 3);
        $this->edit($issued, 8);
        $this->edit($issued, 12);
        $this->post(route('admin.customerInvoices.replace', $issued->id), ['customer_id' => $other->id, 'reason' => 'عميل آخر']);

        $this->assertSame($before, $this->written(), 'كُتب شيءٌ والمفتاحُ موقوف');
        $this->assertEquals(10, (float) $draft->fresh()->items()->sole()->quantity);
        $this->assertSame(CustomerInvoice::ISSUED, $issued->fresh()->status);
    }

    public function test_stopped_what_was_issued_is_read_as_it_was_issued(): void
    {
        $invoice = $this->issued();
        $this->edit($invoice, 12)->assertSessionHasNoErrors();   // إشعارٌ مدين بعشرين وضريبتها
        $this->edit($invoice->fresh(), 11)->assertSessionHasNoErrors(); // ثمّ دائنٌ بعشرةٍ وضريبتها
        $owed = $invoice->fresh()->outstanding();

        $this->flip(false);
        $this->actingAs($this->owner);

        // الصفحةُ والإشعاران والباقي والذمم والدفتر — كما صدرت
        $page = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $page['invoice']['debit_notes']);
        $this->assertCount(1, $page['invoice']['credit_notes']);
        $this->assertEquals(115.5, $owed, '100 + 21 − 10.5');
        $this->assertEquals($owed, $invoice->fresh()->outstanding());
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذمم تطابق الدفتر');
        $this->get(route('admin.customerInvoices.debitNotes.show', CustomerDebitNote::sole()->id))->assertOk();
    }

    public function test_only_the_platform_manager_flips_it_and_every_flip_is_logged_once(): void
    {
        // التاجرُ صاحبُ النشاط لا يبلغه
        $this->actingAs($this->owner)->post(route('super-admin.invoice-corrections.update'), ['enabled' => false])->assertForbidden();
        $this->assertTrue(InvoiceCorrectionsSwitch::on());

        $this->flip(false)->assertSessionHas('toast');
        $this->flip(false); // مرّةً ثانية: لا سطرَ ثانٍ
        $this->assertFalse(InvoiceCorrectionsSwitch::on());
        $this->flip(true);

        $logs = ActivityLog::where('subject_type', Setting::class)->orderBy('id')->pluck('description')->all();
        $this->assertCount(2, $logs);
        $this->assertStringStartsWith('أوقف تعديل فواتير العملاء وتصحيحها', $logs[0]);
        $this->assertStringStartsWith('شغّل تعديل فواتير العملاء وتصحيحها', $logs[1]);

        $state = $this->actingAs($this->platform)->get(route('super-admin.settings.index'))->assertOk()->viewData('page')['props']['invoiceCorrections'];
        $this->assertTrue($state['on']);
        $this->assertCount(2, $state['history']);

        $this->post(route('super-admin.invoice-corrections.update'), ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
    }

    public function test_turned_back_on_corrections_run_again(): void
    {
        $invoice = $this->issued();
        $this->flip(false);
        $this->actingAs($this->owner);
        $this->edit($invoice, 12);
        $this->assertSame(0, CustomerDebitNote::count());

        $this->flip(true);
        $this->actingAs($this->owner);
        $this->edit($invoice->fresh(), 12)->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerDebitNote::count());
    }
}
