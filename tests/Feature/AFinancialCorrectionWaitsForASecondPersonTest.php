<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPayment;
use App\Models\FinancialCorrection;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\FinancialCorrections;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الاعتمادُ الثنائيّ: من يصحّح مالَ فاتورةٍ صادرة لا يعتمد تصحيحه.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - مطفأٌ لكلّ نشاطٍ قائمٍ وجديد: التعديلُ يُحفظ كما كان (#195).
 *   - مُشعلًا: تعديلُ المال طلبٌ لا يكتب شيئًا — لا فاتورةَ ولا قيدَ ولا سجلّ.
 *   - مقدّمُه لا يعتمده ولا يرفضه، ومن لا يملك الاعتمادَ لا يعتمد.
 *   - المعتمدُ ينفّذه مرّةً واحدة — والثاني يُردّ.
 *   - فاتورةٌ تغيّرت أثناء الانتظار (تحصيلٌ مثلًا) لا يُنفَّذ عليها الطلب.
 *   - وعند الاعتماد يُعاد السؤالُ عن صلاحية مقدّمه والفترة.
 *   - والرفضُ بسبب، والسحبُ لمقدّمه وحده، والمفتاحُ لصاحب النشاط وحده.
 *   - ومتجرٌ لا يبلغ طلبَ غيره.
 */
class AFinancialCorrectionWaitsForASecondPersonTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private User $approver;

    private Customer $ministry;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الاعتماد', 'type' => 'عام', 'status' => 'نشط']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);

        $this->owner = $this->user('admin', 'owner@dual.om');
        $this->approver = $this->user('accountant', 'approver@dual.om', [
            'dashboard', 'orders', Permissions::CUSTOMER_INVOICE_HISTORY, Permissions::FINANCIAL_CORRECTION_APPROVE,
        ]);
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

    private function dualOn(?Business $shop = null): void
    {
        Setting::updateOrCreate(['business_id' => ($shop ?? $this->shop)->id, 'key' => FinancialCorrections::SETTING], ['value' => '1']);
    }

    private function issued(float $qty = 10, float $price = 10): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10', 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => $qty, 'unit_price' => $price],
        ], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function draft(): CustomerInvoice
    {
        return CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2025-09-10', 'branch_id' => $this->branch->id], [
            ['description' => 'توريد زهور', 'quantity' => 10, 'unit_price' => 10],
        ], $this->owner->id);
    }

    private function pay(CustomerInvoice $invoice, float $amount): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->ministry, $amount, [
            'method' => 'نقدي', 'occurred_at' => '2025-09-12',
        ], [$invoice->id => $amount], $this->owner->id);
    }

    private function form(CustomerInvoice $invoice, array $over = [], ?float $qty = null): array
    {
        $invoice = $invoice->fresh('items');

        return array_merge([
            'expected_revision' => (int) $invoice->revision,
            'edit_token' => 'dual-'.(++$this->n),
            'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id,
            'notes' => $invoice->notes,
            'reason' => 'تصحيح الكمية حسب أمر الشراء',
            'items' => $invoice->items->map(fn ($i) => [
                'item_id' => $i->id, 'description' => $i->description, 'quantity' => $qty ?? (float) $i->quantity,
                'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'tax_rate' => (float) $i->tax_rate,
            ])->all(),
        ], $over);
    }

    private function request(CustomerInvoice $invoice, ?User $by = null, float $qty = 12): FinancialCorrection
    {
        $this->actingAs($by ?? $this->owner)
            ->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], $qty))
            ->assertRedirect(route('admin.customerInvoices.show', $invoice->id))
            ->assertSessionHasNoErrors();

        return FinancialCorrection::where('customer_invoice_id', $invoice->id)->latest('id')->firstOrFail();
    }

    private function entries(): int
    {
        return JournalEntry::where('business_id', $this->shop->id)->count();
    }

    /* ============================== مطفأ ============================== */

    public function test_off_for_every_shop_old_and_new_an_edit_is_applied_at_once(): void
    {
        $fresh = Business::create(['name' => 'نشاطٌ جديد', 'type' => 'عام', 'status' => 'نشط']);
        $this->assertFalse(FinancialCorrections::required($this->shop->id));
        $this->assertFalse(FinancialCorrections::required($fresh->id));

        $invoice = $this->issued();
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], 12))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $invoice->fresh()->revision);
        $this->assertSame(0, FinancialCorrection::count());
    }

    /* ============================== الطلب ============================== */

    public function test_on_a_money_edit_waits_and_writes_nothing(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $total = (float) $invoice->fresh()->total;
        $entries = $this->entries();

        $correction = $this->request($invoice);

        $this->assertSame(FinancialCorrection::PENDING, $correction->status);
        $this->assertSame($this->owner->id, (int) $correction->requested_by);
        $this->assertSame($total, (float) $invoice->fresh()->total, 'تغيّرت الفاتورة قبل الاعتماد');
        $this->assertSame(0, (int) $invoice->fresh()->revision);
        $this->assertSame($entries, $this->entries(), 'كُتب قيدٌ قبل الاعتماد');
        $this->assertSame(0, CustomerInvoiceRevision::count());
        $this->assertSame(126.0, (float) $correction->summary['money']['total_after']);
    }

    public function test_the_preview_says_it_will_wait(): void
    {
        $this->dualOn();
        $invoice = $this->issued();

        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, [], 12))
            ->assertOk()->assertJsonPath('approval.required', true);

        // وتعديلُ الملاحظة لا يمسّ المال — لا ينتظر أحدًا
        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, ['notes' => 'سلّم صباحًا']))
            ->assertOk()->assertJsonPath('approval.required', false);
    }

    public function test_a_draft_and_a_notes_only_edit_never_wait(): void
    {
        $this->dualOn();

        $draft = $this->draft();
        $this->put(route('admin.customerInvoices.update', $draft->id), $this->form($draft, [], 12))->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $draft->fresh()->revision);

        $invoice = $this->issued();
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, ['notes' => 'سلّم صباحًا']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $invoice->fresh()->revision);
        $this->assertSame(0, FinancialCorrection::count());
    }

    public function test_the_same_form_sent_twice_makes_one_request_and_a_second_request_waits_its_turn(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $form = $this->form($invoice, [], 12);

        $this->put(route('admin.customerInvoices.update', $invoice->id), $form)->assertSessionHasNoErrors();
        $this->put(route('admin.customerInvoices.update', $invoice->id), $form)->assertSessionHasNoErrors();
        $this->assertSame(1, FinancialCorrection::count());

        // ونموذجٌ آخر والطلبُ الأوّل معلّق: يُردّ بسببه
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], 15))
            ->assertSessionHasErrors('edit');
        $this->assertSame(1, FinancialCorrection::count());
    }

    /* ============================== الاعتماد ============================== */

    public function test_the_requester_can_neither_approve_nor_reject_his_own(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.reject', $correction->id), ['reason' => 'أرفض طلبي'])
            ->assertForbidden();

        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
        $this->assertSame(0, (int) $invoice->fresh()->revision);
    }

    public function test_who_does_not_hold_approval_cannot_approve(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);
        $reader = $this->user('accountant', 'reader@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_HISTORY]);

        $this->actingAs($reader)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertForbidden();
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
    }

    public function test_a_second_person_approves_and_it_is_applied_once(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $entries = $this->entries();
        $correction = $this->request($invoice);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id), ['note' => 'مطابق لأمر الشراء'])
            ->assertRedirect(route('admin.customerInvoices.show', $invoice->id))->assertSessionHasNoErrors();

        $correction->refresh();
        $this->assertSame(FinancialCorrection::APPROVED, $correction->status);
        $this->assertSame($this->approver->id, (int) $correction->decided_by);
        $this->assertNotNull($correction->decided_at);

        $invoice->refresh();
        $this->assertSame(126.0, (float) $invoice->total);
        $this->assertSame(1, (int) $invoice->revision);
        $revision = CustomerInvoiceRevision::sole();
        $this->assertSame($this->owner->id, (int) $revision->created_by, 'المؤلِّفُ مقدّمُ الطلب');
        $this->assertSame($revision->id, (int) $correction->customer_invoice_revision_id);
        $this->assertSame($entries + 2, $this->entries(), 'عكسٌ وترحيلٌ واحد');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذممُ تطابق الدفتر');

        // والثاني يُردّ — ولا يُكتب سجلٌّ ثانٍ ولا قيد
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');
        $this->assertSame(1, CustomerInvoiceRevision::count());
        $this->assertSame($entries + 2, $this->entries());

        // والسجلُّ يقول من اعتمده
        $this->actingAs($this->owner)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p->where('revisions.0.approved_by', $this->approver->name)
                ->where('corrections.0.status', FinancialCorrection::APPROVED));
    }

    public function test_a_paid_invoice_waits_too_and_its_payment_is_untouched(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $payment = $this->pay($invoice, 105);
        $correction = $this->request($invoice, null, 8);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(84.0, (float) $invoice->fresh()->total);
        $this->assertSame(105.0, (float) $payment->fresh()->amount, 'المقبوضُ لا يُمسّ');
        $this->assertSame(21.0, Receivables::credit($this->shop->id, $this->ministry->id), 'الفرقُ رصيدٌ دائنٌ للعميل');
    }

    public function test_a_payment_recorded_while_waiting_closes_the_request_unapplied(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        $this->pay($invoice, 30);
        $entries = $this->entries();

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');

        $this->assertSame(FinancialCorrection::STALE, $correction->fresh()->status);
        $this->assertSame(105.0, (float) $invoice->fresh()->total);
        $this->assertSame(0, CustomerInvoiceRevision::count());
        $this->assertSame($entries, $this->entries());
    }

    public function test_approval_asks_again_about_the_requesters_permission(): void
    {
        $this->dualOn();
        $clerk = $this->user('accountant', 'clerk@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $invoice = $this->issued();
        $correction = $this->request($invoice, $clerk);

        // سُحبت منه الصلاحيةُ أثناء الانتظار
        $clerk->forceFill(['permissions' => ['dashboard', 'orders']])->save();

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertForbidden();
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
        $this->assertSame(105.0, (float) $invoice->fresh()->total);
    }

    public function test_approval_asks_again_about_the_period(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        // اعتُمد بعد شهرين: قيدُ سبتمبر صار في شهرٍ مضى، والطلبُ لم يختر موضعه
        Carbon::setTestNow('2025-11-05 10:00:00');

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
        $this->assertSame(0, (int) $invoice->fresh()->revision);
    }

    /* ============================== الرفض والسحب ============================== */

    public function test_a_rejection_needs_a_reason_and_changes_nothing(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.reject', $correction->id), [])
            ->assertSessionHasErrors('reason');
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.reject', $correction->id), ['reason' => 'الكمية في أمر الشراء 10'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FinancialCorrection::REJECTED, $correction->fresh()->status);
        $this->assertSame('الكمية في أمر الشراء 10', $correction->fresh()->decision_note);
        $this->assertSame(105.0, (float) $invoice->fresh()->total);

        // وطلبٌ بُتّ فيه لا يُعتمد بعده
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');
        $this->assertSame(0, CustomerInvoiceRevision::count());
    }

    public function test_only_the_requester_withdraws(): void
    {
        $this->dualOn();
        $clerk = $this->user('accountant', 'clerk2@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $invoice = $this->issued();
        $correction = $this->request($invoice, $clerk);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.withdraw', $correction->id))->assertForbidden();
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);

        $this->actingAs($clerk)->post(route('admin.customerInvoices.corrections.withdraw', $correction->id))->assertSessionHasNoErrors();
        $this->assertSame(FinancialCorrection::WITHDRAWN, $correction->fresh()->status);

        // وبعد السحب يُطلب تصحيحٌ جديد
        $this->request($invoice, $clerk, 11);
        $this->assertSame(2, FinancialCorrection::count());
    }

    /* ============================== المفتاح والمتاجر ============================== */

    public function test_only_the_owner_turns_it_on_or_off(): void
    {
        $manager = $this->user('manager', 'mgr@dual.om');

        $this->actingAs($manager)->post(route('admin.settings.update'), ['dual_approval_corrections' => true])
            ->assertSessionHasErrors('dual_approval_corrections');
        $this->assertFalse(FinancialCorrections::required($this->shop->id));

        // ومن يحفظ بقيّة القسم يرسل المفتاحَ كما قرأه — فلا يُردّ
        $this->actingAs($manager)->post(route('admin.settings.update'), ['dual_approval_corrections' => false, 'staff_sees_performance' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['dual_approval_corrections' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(FinancialCorrections::required($this->shop->id));

        $this->actingAs($manager)->post(route('admin.settings.update'), ['dual_approval_corrections' => false])
            ->assertSessionHasErrors('dual_approval_corrections');
        $this->assertTrue(FinancialCorrections::required($this->shop->id));
    }

    public function test_another_shop_neither_sees_nor_decides_a_request(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = $this->user('admin', 'stranger@dual.om', null, $other);

        $this->actingAs($stranger)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.customerInvoices.corrections.reject', $correction->id), ['reason' => 'ليس لي'])
            ->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.customerInvoices.corrections.withdraw', $correction->id))->assertNotFound();
        $this->actingAs($stranger)->get(route('admin.customerInvoices.corrections.index'))
            ->assertInertia(fn ($p) => $p->has('corrections', 0));

        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);

        // والمفتاحُ لنشاطه وحده
        $this->assertFalse(FinancialCorrections::required($other->id));
    }

    public function test_the_list_shows_who_may_decide(): void
    {
        $this->dualOn();
        $correction = $this->request($this->issued());

        $this->actingAs($this->approver)->get(route('admin.customerInvoices.corrections.index'))
            ->assertInertia(fn ($p) => $p->component('Admin/CustomerInvoices/Corrections')
                ->where('corrections.0.id', $correction->id)
                ->where('corrections.0.may_decide', true)
                ->where('corrections.0.may_withdraw', false));

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.corrections.index'))
            ->assertInertia(fn ($p) => $p->where('corrections.0.may_decide', false)->where('corrections.0.may_withdraw', true));
    }
}
