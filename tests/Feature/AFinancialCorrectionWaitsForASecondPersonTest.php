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
use App\Models\CustomerPayment;
use App\Models\FinancialCorrection;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\FinancialCorrections;
use App\Support\Ledger;
use App\Support\NeedsApproval;
use App\Support\Permissions;
use App\Support\Receivables;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الاعتمادُ الثنائيّ: من يصحّح مالَ فاتورةٍ صادرة لا يعتمد تصحيحه (#196 فوق #195).
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - مطفأٌ لكلّ نشاطٍ قائمٍ وجديد: التصحيحُ يُحفظ كما كان (#195).
 *   - مُشعلًا: تصحيحٌ يصدر به إشعارٌ دائنٌ أو مدين طلبٌ لا يكتب شيئًا — لا
 *     إشعارَ ولا قيدَ ولا سجلَّ تصحيح. وكذلك «إلغاء وإصدار بديلة».
 *   - والبابُ المباشرُ لا يتجاوزه: `apply` و`replace` تردّان بلا طلبٍ مقفل.
 *   - مقدّمُه لا يعتمده ولا يرفضه، ومن لا يملك الاعتمادَ لا يعتمد.
 *   - المعتمدُ ينفّذه مرّةً واحدة — والثاني يُردّ.
 *   - فاتورةٌ تغيّرت أثناء الانتظار (تحصيلٌ مثلًا) لا يُنفَّذ عليها الطلب.
 *   - وعند الاعتماد يُعاد السؤالُ عن صلاحية مقدّمه وفترتها والدفاتر.
 *   - والرفضُ بسبب، والسحبُ لمقدّمه وحده، والمفتاحُ لصاحب النشاط وحده،
 *     ولا يُشعَل بلا معتمدٍ آخر. وكلُّ ذلك في سجلّ النشاط.
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
            'internal_notes' => $invoice->internal_notes,
            'reason' => 'تصحيح الكمية حسب أمر الشراء',
            'basis' => CustomerInvoiceRevision::ERROR,
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

    /** الإجماليُّ بعد مستندات التصحيح — والفاتورةُ نفسُها كما صدرت */
    private function effective(CustomerInvoice $invoice): float
    {
        return round($invoice->fresh()->effectiveTotal(), 3);
    }

    private function logged(string $like): int
    {
        return ActivityLog::where('business_id', $this->shop->id)->where('description', 'like', '%'.$like.'%')->count();
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
        $this->assertSame(1, CustomerDebitNote::count());
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
        $this->assertSame($total, $this->effective($invoice), 'صدر مستندُ تصحيحٍ قبل الاعتماد');
        $this->assertSame(0, (int) $invoice->fresh()->revision);
        $this->assertSame($entries, $this->entries(), 'كُتب قيدٌ قبل الاعتماد');
        $this->assertSame([0, 0, 0], [CustomerInvoiceRevision::count(), CustomerDebitNote::count(), CustomerCreditNote::count()]);
        $this->assertSame(126.0, (float) $correction->summary['money']['total_after']);
        $this->assertEquals(['amount' => 21.0, 'tax' => 1.0], $correction->summary['documents']['debit']);
        $this->assertSame(1, $this->logged('بانتظار الاعتماد'), 'الطلبُ في سجلّ النشاط');
    }

    public function test_the_door_itself_refuses_without_a_locked_request(): void
    {
        $this->dualOn();
        $invoice = $this->issued();

        // من يبلغ الخدمةَ مباشرةً — لا من المتحكّم — يُردّ قبل أوّل كتابة
        try {
            CustomerInvoiceEdits::apply($invoice, $this->form($invoice, [], 12), $this->owner);
            $this->fail('نُفّذ تصحيحٌ ماليٌّ بلا اعتماد');
        } catch (NeedsApproval) {
        }

        try {
            CustomerInvoiceEdits::replace($invoice->fresh(), Customer::create([
                'business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92345678', 'allow_credit_sales' => true,
            ]), 'العميل الصحيح', $this->owner);
            $this->fail('استُبدلت فاتورةٌ بلا اعتماد');
        } catch (NeedsApproval) {
        }

        $this->assertSame([CustomerInvoice::ISSUED, 0, 0], [$invoice->fresh()->status, CustomerDebitNote::count(), FinancialCorrection::count()]);
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count(), 'كُتبت بديلةٌ بلا اعتماد');
    }

    public function test_the_preview_says_it_will_wait(): void
    {
        $this->dualOn();
        $invoice = $this->issued();

        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, [], 12))
            ->assertOk()->assertJsonPath('approval.required', true);

        // وتعديلُ الملاحظة لا يمسّ المال — لا ينتظر أحدًا
        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, ['internal_notes' => 'سلّم صباحًا']))
            ->assertOk()->assertJsonPath('approval.required', false);
    }

    public function test_a_draft_and_a_notes_only_edit_never_wait(): void
    {
        $this->dualOn();

        $draft = $this->draft();
        $this->put(route('admin.customerInvoices.update', $draft->id), $this->form($draft, [], 12))->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $draft->fresh()->revision);

        $invoice = $this->issued();
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, ['internal_notes' => 'سلّم صباحًا']))
            ->assertSessionHasNoErrors();
        $this->assertSame('سلّم صباحًا', $invoice->fresh()->internal_notes);
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

        // ونموذجٌ آخر والطلبُ الأوّل معلّق: يُردّ بسببه — بجملةٍ تُقرأ، والمعاينةُ تقولها قبله
        $why = __('على هذه الفاتورة طلب تصحيح مالي بانتظار الاعتماد — يُعتمد أو يُرفض أو يُسحب أولًا.');
        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, [], 15))
            ->assertOk()->assertJsonPath('blockers', [$why]);
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], 15))
            ->assertSessionHasErrors(['edit' => $why]);
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

        // والخدمةُ نفسُها تسأل — لا الباب وحده
        foreach ([fn () => FinancialCorrections::approve($correction, $reader), fn () => FinancialCorrections::reject($correction, $reader, 'لا أملكه')] as $act) {
            try {
                $act();
                $this->fail('بتّ في الطلب من لا يملك الاعتماد');
            } catch (AuthorizationException) {
            }
        }
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
        $this->assertSame(105.0, (float) $invoice->total, 'الفاتورةُ كما صدرت');
        $this->assertSame(126.0, $this->effective($invoice));
        $this->assertSame(1, (int) $invoice->revision);
        $revision = CustomerInvoiceRevision::sole();
        $this->assertSame($this->owner->id, (int) $revision->created_by, 'المؤلِّفُ مقدّمُ الطلب');
        $this->assertSame($revision->id, (int) $correction->customer_invoice_revision_id);
        $this->assertSame(21.0, (float) CustomerDebitNote::sole()->amount);
        $this->assertSame($entries + 1, $this->entries(), 'قيدُ الإشعار المدين وحده');
        $this->assertSame(1, $this->logged('اعتمد تصحيح فاتورة عميل'), 'الاعتمادُ في سجلّ النشاط');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذممُ تطابق الدفتر');

        // والثاني يُردّ — ولا يُكتب سجلٌّ ثانٍ ولا قيد
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');
        $this->assertSame([1, 1], [CustomerInvoiceRevision::count(), CustomerDebitNote::count()]);
        $this->assertSame($entries + 1, $this->entries());

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

        $this->assertSame(84.0, $this->effective($invoice));
        $this->assertSame(21.0, (float) CustomerCreditNote::sole()->amount);
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
        $this->assertSame(105.0, $this->effective($invoice));
        $this->assertSame([0, 0], [CustomerInvoiceRevision::count(), CustomerDebitNote::count()]);
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
        $this->assertSame(105.0, $this->effective($invoice));
    }

    public function test_approval_asks_again_about_the_period(): void
    {
        $this->dualOn();
        $clerk = $this->user('accountant', 'period@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $invoice = $this->issued();
        $correction = $this->request($invoice, $clerk);

        // اعتُمد بعد شهرين: صارت فاتورةُ سبتمبر من فترةٍ سابقة، ومقدّمُه لا يملك تصحيحَها
        Carbon::setTestNow('2025-11-05 10:00:00');

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertForbidden();
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
        $this->assertSame([0, 0], [(int) $invoice->fresh()->revision, CustomerDebitNote::count()]);
    }

    public function test_approval_asks_again_about_closed_books(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        // أُقفلت الدفاترُ حتى اليوم أثناء الانتظار — لا يُقيَّد مستندُ التصحيح
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'books_closed_through'], ['value' => '2025-09-20']);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))
            ->assertSessionHasErrors('correction');
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);
        $this->assertSame(0, CustomerDebitNote::count());
    }

    /* ============================== الرفض والسحب ============================== */

    public function test_a_rejection_needs_a_reason_and_changes_nothing(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $correction = $this->request($invoice);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.reject', $correction->id), [])
            ->assertSessionHasErrors('reason');
        // والخدمةُ نفسُها لا ترفض بلا سبب
        try {
            FinancialCorrections::reject($correction, $this->approver, '  ');
            $this->fail('رُفض الطلبُ بلا سبب');
        } catch (ValidationException) {
        }
        $this->assertSame(FinancialCorrection::PENDING, $correction->fresh()->status);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.reject', $correction->id), ['reason' => 'الكمية في أمر الشراء 10'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FinancialCorrection::REJECTED, $correction->fresh()->status);
        $this->assertSame('الكمية في أمر الشراء 10', $correction->fresh()->decision_note);
        $this->assertSame(105.0, $this->effective($invoice));
        $this->assertSame(1, $this->logged('رفض طلب تصحيح فاتورة عميل'), 'الرفضُ في سجلّ النشاط');

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
        $this->assertSame(1, $this->logged('سحب طلب تصحيح فاتورة عميل'), 'السحبُ في سجلّ النشاط');

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
        $this->assertSame(1, $this->logged('شغّل الاعتماد الثنائي'), 'من شغّله في سجلّ النشاط');

        $this->actingAs($manager)->post(route('admin.settings.update'), ['dual_approval_corrections' => false])
            ->assertSessionHasErrors('dual_approval_corrections');
        $this->assertTrue(FinancialCorrections::required($this->shop->id));
    }

    /* ============================== صاحبُ النشاط وحده ============================== */

    public function test_a_lone_owner_is_told_why_and_the_switch_stays_off(): void
    {
        // المعتمدُ الوحيدُ غيرُه عُطّل — فلا أحدَ يعتمد تصحيحَ صاحب النشاط
        $this->approver->forceFill(['status' => 'معطل'])->save();
        $this->assertSame(0, FinancialCorrections::approvers($this->shop->id, $this->owner->id));

        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['dual_approval_corrections' => true])
            ->assertSessionHasErrors(['dual_approval_corrections' => __('لا يملك «اعتماد تصحيح مالي» موظفٌ نشطٌ غيرك — امنحه لموظف آخر من ملفه أولًا، وإلا بقيت تصحيحاتك المالية بلا من يعتمدها.')]);
        $this->assertFalse(FinancialCorrections::required($this->shop->id));

        // والإطفاءُ لا يُسأل عنه، ومعتمدٌ نشطٌ يفتح الإشعال
        $this->post(route('admin.settings.update'), ['dual_approval_corrections' => false])->assertSessionHasNoErrors();
        $this->approver->forceFill(['status' => 'نشط'])->save();
        $this->post(route('admin.settings.update'), ['dual_approval_corrections' => true])->assertSessionHasNoErrors();
        $this->assertTrue(FinancialCorrections::required($this->shop->id));
    }

    public function test_an_approver_gone_after_it_was_turned_on_leaves_no_request_hanging(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $total = (float) $invoice->fresh()->total;
        $entries = $this->entries();
        $this->approver->forceFill(['status' => 'معطل'])->save();
        $why = __('الاعتماد الثنائي مفعّل، ولا يملك «اعتماد تصحيح مالي» موظفٌ نشطٌ غيرك — فلا يُرسل طلبٌ لا يعتمده أحد. امنح الصلاحية لموظف آخر من ملفه، أو يطفئ صاحب النشاط الاعتماد الثنائي من الإعدادات.');

        // المعاينةُ تقوله قبل الحفظ، والحفظُ يردّه بسببه — لا طلبَ معلّقٌ بلا من يبتّ فيه
        $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $this->form($invoice, [], 12))
            ->assertOk()->assertJsonPath('approval.required', true)->assertJsonPath('blockers', [$why]);

        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], 12))
            ->assertSessionHasErrors(['edit' => $why]);
        $this->assertSame(0, FinancialCorrection::count());
        $this->assertSame($total, (float) $invoice->fresh()->total);
        $this->assertSame($entries, $this->entries());

        // وما لا مالَ فيه يُحفظ كما كان
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, ['internal_notes' => 'سلّم صباحًا']))
            ->assertSessionHasNoErrors();
        $this->assertSame('سلّم صباحًا', $invoice->fresh()->internal_notes);

        // والموظّفُ يطلب فيعتمده صاحبُ النشاط — لا يُردّ إلّا من لا معتمدَ له
        $clerk = $this->user('accountant', 'clerk-lone@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_INVOICE_EDIT_ISSUED]);
        $correction = $this->request($invoice->fresh(), $clerk, 12);
        $this->assertSame(FinancialCorrection::PENDING, $correction->status);
        $this->assertTrue(FinancialCorrections::mayDecide($this->owner, $correction));
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

    /* ============================== الاستبدال لعميلٍ آخر ============================== */

    public function test_replacing_for_another_customer_waits_then_is_carried_out_once(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $health = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92345678', 'allow_credit_sales' => true]);
        $entries = $this->entries();

        $replace = fn () => $this->actingAs($this->owner)->post(route('admin.customerInvoices.replace', $invoice->id), [
            'customer_id' => $health->id, 'reason' => 'صدرت باسم الوزارة الخطأ',
        ]);
        $replace()->assertRedirect(route('admin.customerInvoices.show', $invoice->id))->assertSessionHasNoErrors();
        // والنموذجُ مرّتين طلبٌ واحد
        $replace()->assertSessionHasNoErrors();

        $correction = FinancialCorrection::sole();
        $this->assertSame([FinancialCorrection::INVOICE_REPLACE, FinancialCorrection::PENDING], [$correction->kind, $correction->status]);
        $this->assertSame([CustomerInvoice::ISSUED, 1, $entries], [$invoice->fresh()->status, CustomerInvoice::where('business_id', $this->shop->id)->count(), $this->entries()],
            'أُلغيت الفاتورة أو كُتبت بديلتها قبل الاعتماد');

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertSessionHasNoErrors();

        $correction->refresh();
        $draft = CustomerInvoice::where('business_id', $this->shop->id)->where('replaces_invoice_id', $invoice->id)->sole();
        $this->assertSame([FinancialCorrection::APPROVED, $draft->id], [$correction->status, (int) $correction->replacement_invoice_id]);
        $this->assertSame([CustomerInvoice::CANCELLED, CustomerInvoice::DRAFT, $health->id], [$invoice->fresh()->status, $draft->status, (int) $draft->customer_id]);
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);

        // والثاني يُردّ — ولا بديلةَ ثانية
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $correction->id))->assertSessionHasErrors('correction');
        $this->assertSame(1, CustomerInvoice::where('replaces_invoice_id', $invoice->id)->count());
    }

    public function test_a_replacement_that_could_not_happen_is_refused_before_it_is_asked(): void
    {
        $this->dualOn();
        $invoice = $this->issued();
        $this->pay($invoice, 30);
        $health = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الصحة', 'phone' => '92345678', 'allow_credit_sales' => true]);

        // قُبض منها مال باسم عميلها — لا تُستبدل، فلا يُعلَّق طلبٌ لن يُنفَّذ
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.replace', $invoice->id), [
            'customer_id' => $health->id, 'reason' => 'صدرت باسم الوزارة الخطأ',
        ])->assertSessionHasErrors('replace');

        $this->assertSame([0, CustomerInvoice::ISSUED], [FinancialCorrection::count(), $invoice->fresh()->status]);
    }

    /* ═══════ الإشعارُ الدائنُ اليدويّ وإلغاءُ الصادرة — قرارُ المالك 2026-11-28 ═══════ */

    private function creditNote(CustomerInvoice $invoice, float $amount, string $token, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->owner)->post(route('admin.customerInvoices.creditNote', $invoice->id), [
            'amount' => $amount, 'tax_amount' => 0, 'reason' => 'خصمٌ متّفق عليه', 'basis' => CustomerCreditNote::ERROR, 'token' => $token,
        ]);
    }

    private function cancelInvoice(CustomerInvoice $invoice, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->owner)->post(route('admin.customerInvoices.cancel', $invoice->id), ['reason' => 'أُلغي التوريد']);
    }

    public function test_a_manual_credit_note_waits_then_is_issued_once_in_the_requesters_name(): void
    {
        $invoice = $this->issued();
        $this->dualOn();
        $entries = $this->entries();

        // مرّتين بالرمز نفسه: طلبٌ واحد، ولا إشعارَ ولا قيد
        $this->creditNote($invoice, 20, 'cn-1')->assertRedirect(route('admin.customerInvoices.show', $invoice->id))->assertSessionHasNoErrors();
        $this->creditNote($invoice, 20, 'cn-1')->assertSessionHasNoErrors();
        $this->assertSame(1, FinancialCorrection::where('kind', FinancialCorrection::CREDIT_NOTE)->count());
        $this->assertSame([0, $entries], [CustomerCreditNote::count(), $this->entries()]);

        $row = FinancialCorrection::sole();
        $this->assertSame(20.0, (float) $row->summary['documents']['credit']['amount']);

        // ومقدّمُه لا يعتمده
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertForbidden();

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertSessionHasNoErrors();
        $note = CustomerCreditNote::sole();
        $this->assertSame([20.0, (int) $this->owner->id], [(float) $note->amount, (int) $note->created_by]);
        $this->assertSame([FinancialCorrection::APPROVED, (int) $note->id], [$row->fresh()->status, (int) $row->fresh()->customer_credit_note_id]);
        $this->assertSame($entries + 1, $this->entries());

        // ومرّةً ثانية: بُتّ فيه
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertSessionHasErrors();
        $this->assertSame(1, CustomerCreditNote::count());
    }

    public function test_cancelling_an_issued_invoice_waits_then_reverses_its_entry_once(): void
    {
        $invoice = $this->issued();
        $this->dualOn();

        $this->cancelInvoice($invoice)->assertSessionHasNoErrors();
        $this->cancelInvoice($invoice)->assertSessionHasNoErrors(); // ضغطتان: طلبٌ واحد
        $this->assertSame([1, CustomerInvoice::ISSUED], [FinancialCorrection::where('kind', FinancialCorrection::INVOICE_CANCEL)->count(), $invoice->fresh()->status]);

        $entries = $this->entries();
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', FinancialCorrection::sole()->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
        $this->assertSame($entries + 1, $this->entries(), 'قيدُ عكسٍ واحد');
    }

    public function test_no_direct_route_or_inner_service_issues_or_cancels_without_its_approval(): void
    {
        $invoice = $this->issued();
        $this->dualOn();

        foreach ([
            fn () => CustomerInvoices::creditNote($invoice, 10, 0, 'مباشر', $this->owner->id, basis: CustomerCreditNote::ERROR),
            fn () => CustomerInvoices::cancel($invoice, 'مباشر', $this->owner->id),
        ] as $direct) {
            try {
                $direct();
                $this->fail('مرّ بلا اعتماد');
            } catch (NeedsApproval) {
            }
        }
        $this->assertSame([0, 0, CustomerInvoice::ISSUED], [FinancialCorrection::count(), CustomerCreditNote::count(), $invoice->fresh()->status]);
    }

    public function test_turning_it_off_carries_out_nothing_pending_and_lets_nothing_go_around_it(): void
    {
        $invoice = $this->issued();
        $this->dualOn();
        $this->creditNote($invoice, 20, 'cn-off')->assertSessionHasNoErrors();
        $row = FinancialCorrection::sole();

        // يُطفئه صاحبُ النشاط: لا يُنفَّذ المعلّق، ولا يقع على الورقة شيءٌ مباشرة فوقه
        Setting::where('business_id', $this->shop->id)->where('key', FinancialCorrections::SETTING)->update(['value' => '0']);
        $this->assertSame([FinancialCorrection::PENDING, 0], [$row->fresh()->status, CustomerCreditNote::count()]);

        $this->creditNote($invoice, 5, 'cn-direct')->assertSessionHasErrors('amount');
        $this->cancelInvoice($invoice)->assertSessionHasErrors('invoice');
        $this->actingAs($this->owner)->put(route('admin.customerInvoices.update', $invoice->id), $this->form($invoice, [], 12))->assertSessionHasErrors();
        $this->assertSame([0, CustomerInvoice::ISSUED], [CustomerCreditNote::count(), $invoice->fresh()->status]);

        // ويبقى يُعتمد أو يُرفض أو يُسحب كما هو
        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerCreditNote::count());
    }

    public function test_a_disabled_approver_or_a_requester_who_lost_the_action_decides_and_issues_nothing(): void
    {
        $clerk = $this->user('manager', 'clerk@dual.om', ['dashboard', 'orders', Permissions::CUSTOMER_CREDIT_NOTE]);
        $invoice = $this->issued();
        $this->dualOn();
        $this->creditNote($invoice, 20, 'cn-clerk', $clerk)->assertSessionHasNoErrors();
        $row = FinancialCorrection::sole();

        // المعتمدُ عُطّل حسابُه وجلستُه مفتوحة: لا يبتّ
        $this->approver->forceFill(['status' => 'معطل'])->save();
        try {
            FinancialCorrections::approve($row, $this->approver->fresh());
            $this->fail('اعتمد معتمدٌ معطّل');
        } catch (AuthorizationException) {
        }
        $this->approver->forceFill(['status' => 'نشط'])->save();

        // ومقدّمُه سُحب منه إصدارُ الإشعارات: لا يصدر باسمه
        $clerk->forceFill(['permissions' => ['dashboard', 'orders']])->save();
        try {
            FinancialCorrections::approve($row, $this->approver->fresh());
            $this->fail('صدر الإشعارُ باسم من لم يعد يملكه');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('لم يعد يملك', $e->getMessage());
        }
        $this->assertSame([FinancialCorrection::PENDING, 0], [$row->fresh()->status, CustomerCreditNote::count()]);
    }

    public function test_a_collection_while_a_credit_note_waits_closes_it_unissued(): void
    {
        $invoice = $this->issued();
        $this->dualOn();
        $this->creditNote($invoice, 20, 'cn-stale')->assertSessionHasNoErrors();
        $row = FinancialCorrection::sole();

        $this->pay($invoice, 30);

        $this->actingAs($this->approver)->post(route('admin.customerInvoices.corrections.approve', $row->id))->assertSessionHasErrors();
        $this->assertSame([FinancialCorrection::STALE, 0], [$row->fresh()->status, CustomerCreditNote::count()]);
    }

    public function test_the_pending_count_reaches_whoever_may_approve_and_no_one_else(): void
    {
        $invoice = $this->issued();
        $this->dualOn();
        $this->creditNote($invoice, 20, 'cn-count')->assertSessionHasNoErrors();

        $props = fn (User $u) => $this->actingAs($u)->get(route('admin.customerInvoices.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $props($this->approver)['pendingCorrections']);
        // ومقدّمُه لا يُعدّ له طلبُه — لا يبتّ فيه
        $this->assertSame(0, $props($this->owner)['pendingCorrections']);
        $seller = $this->user('sales', 'seller@dual.om', ['dashboard', 'orders']);
        $this->assertNull($props($seller)['pendingCorrections']);
    }
}
