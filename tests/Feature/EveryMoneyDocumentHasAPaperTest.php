<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثلاثةُ مستنداتٍ ماليّةٍ كانت تُنشأ ولا تخرج على ورق.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * إشعارُ الدائن يُنقص ذمّةَ عميل، وسندُ القبض إقرارٌ بأنّ المتجر استلم
 * مالًا، وفاتورةُ المورّد سندُ ما عليه لمورّده. وكلُّها **صفوفٌ في القاعدة
 * لها أثرٌ في الدفتر** — وكلُّها كانت تُقرأ سطرًا في جدولٍ لا يُفتح.
 *
 * فمن سُئل في تدقيقٍ عن إشعارٍ دائن كان جوابُه لقطةَ شاشة، ومن دفع بمئةٍ
 * وُزّعت على ثلاث فواتير لم يجد ورقةً واحدةً تقول ما دفع وأين ذهب.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. أنّ لكلٍّ صفحةً تُفتح وورقةً مرسومةً إلى جانبها.
 *  ٢. أنّ ما تقوله الشاشةُ هو ما يخرج من باب الطباعة.
 *  ٣. أنّ الورقةَ تقول ما في الصفّ لا ما يُخترع له.
 *  ٤. أنّ رقمًا مُخمَّنًا في العنوان لا يفتح ورقةَ متجرٍ آخر.
 */
class EveryMoneyDocumentHasAPaperTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الصحة', 'phone' => '95000000',
        ]);
    }

    /* ═══════════════════ إشعارُ الدائن ═══════════════════ */

    /** وله صفحةٌ تُفتح، وورقتُه إلى جانبها */
    public function test_a_credit_note_opens_to_a_page_of_its_own(): void
    {
        $note = $this->creditNote();

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.creditNotes.show', $note->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/CustomerInvoices/CreditNoteShow')
                ->where('note.number', 'CN-000001')
                ->where('invoice.number', 'CI-000001')
                ->where('paper.url', route('admin.customerInvoices.creditNotes.pdf', $note->id))
                ->where('paper.html', fn (string $html) => str_contains($html, 'إشعار دائن')
                    && str_contains($html, 'CN-000001')
                    && str_contains($html, 'CI-000001')));
    }

    /**
     * والمبلغُ يُفصَّل: صافٍ وضريبةٌ وإجمالي.
     *
     * `amount` شاملٌ للضريبة و`tax_amount` نصيبُها منه — انظر
     * `CustomerInvoices::creditNote`. فورقةٌ تعرض الشاملَ وحدَه تجعل من
     * يراجعها يطرح بيده ليعرف كم رُدّ من الضريبة.
     */
    public function test_the_credit_note_splits_its_tax_from_its_net(): void
    {
        $note = $this->creditNote();

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.creditNotes.show', $note->id))
            ->assertInertia(fn ($p) => $p
                ->where('note.amount', 21)
                ->where('note.tax_amount', 1)
                ->where('note.net', 20));
    }

    /** وبابُ الطباعة يُخرج ملفًّا */
    public function test_the_credit_note_prints(): void
    {
        $printed = $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.creditNotes.pdf', $this->creditNote()->id));

        $printed->assertOk();
        $this->assertSame('application/pdf', $printed->headers->get('content-type'));
    }

    /* ═══════════════════ سندُ القبض ═══════════════════ */

    /** وله صفحةٌ تقول ما استُلم وأين ذهب */
    public function test_a_receipt_opens_to_a_page_of_its_own(): void
    {
        [$payment, $invoice] = $this->payment();

        $this->actingAs($this->owner)
            ->get(route('admin.customerPayments.show', $payment->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/CustomerInvoices/ReceiptShow')
                ->where('receipt.number', 'RC-000001')
                ->where('receipt.amount', 30)
                ->where('receipt.allocations.0.invoice', $invoice->number)
                ->where('paper.url', route('admin.customerPayments.pdf', $payment->id))
                ->where('paper.html', fn (string $html) => str_contains($html, 'سند قبض')
                    && str_contains($html, 'RC-000001')
                    && str_contains($html, $invoice->number)));
    }

    /**
     * وما لم يُوزَّع يُقال، لا يُطوى.
     *
     * من دفع بثلاثين وسُدِّدت بها فاتورةٌ بعشرين له عشرةٌ عند المتجر. وورقةٌ
     * تقول «استُلم ٣٠» وتسكت عن العشرة تجعل صاحبَها يظنّها ضاعت.
     */
    public function test_the_receipt_says_what_is_still_unallocated(): void
    {
        [$payment] = $this->payment(amount: 30.0, allocated: 20.0);

        $this->actingAs($this->owner)
            ->get(route('admin.customerPayments.show', $payment->id))
            ->assertInertia(fn ($p) => $p
                ->where('receipt.allocated', 20)
                ->where('receipt.unallocated', 10)
                ->where('paper.html', fn (string $html) => str_contains($html, 'رصيد لم يُخصَّص بعد')));
    }

    /** وبابُ الطباعة يُخرج ملفًّا */
    public function test_the_receipt_prints(): void
    {
        [$payment] = $this->payment();

        $printed = $this->actingAs($this->owner)->get(route('admin.customerPayments.pdf', $payment->id));

        $printed->assertOk();
        $this->assertSame('application/pdf', $printed->headers->get('content-type'));
    }

    /* ═══════════════════ والسطرُ يفتح سنده ═══════════════════ */

    /**
     * وصفوفُ الفاتورة تحمل مفاتيحَ ما تسمّيه.
     *
     * كان رقمُ التحصيل ورقمُ الإشعار نصَّين لا يُفتحان: الشاشةُ تسمّي مستندًا
     * ولا تقود إليه.
     */
    public function test_the_invoice_rows_carry_the_keys_that_open_them(): void
    {
        [$payment, $invoice] = $this->payment();
        $note = $this->creditNote($invoice);

        $this->actingAs($this->owner)
            ->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p
                ->where('invoice.payments.0.id', $payment->id)
                ->where('invoice.credit_notes.0.id', $note->id));
    }

    /* ═══════════════════ ولا يُفتح مستندُ جار ═══════════════════ */

    /** ورقمٌ مُخمَّنٌ في العنوان لا يعبر جدارَ المتجر */
    public function test_a_guessed_id_does_not_open_a_neighbours_paper(): void
    {
        [$payment, $invoice] = $this->payment();
        $note = $this->creditNote($invoice);

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'بقالة', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)
            ->get(route('admin.customerPayments.show', $payment->id))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('admin.customerPayments.pdf', $payment->id))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('admin.customerInvoices.creditNotes.show', $note->id))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('admin.customerInvoices.creditNotes.pdf', $note->id))->assertNotFound();
    }

    /* ————————————————— أدوات ————————————————— */

    private function invoice(): CustomerInvoice
    {
        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'number' => 'CI-000001', 'status' => 'صادرة',
            'issued_at' => now(), 'due_at' => now()->addDays(30),
            'subtotal' => 100.0, 'discount_total' => 0, 'tax_total' => 5.0, 'total' => 105.0,
            'customer_name' => 'وزارة الصحة', 'customer_tax_number' => 'OM1100123456',
        ]);

        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => 'تنسيق قاعة',
            'quantity' => 1, 'unit_price' => 100.0, 'discount' => 0,
            'tax_rate' => 5, 'tax_amount' => 5.0, 'line_total' => 105.0,
        ]);

        return $invoice;
    }

    private function creditNote(?CustomerInvoice $invoice = null): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'business_id' => $this->business->id,
            'customer_invoice_id' => ($invoice ?? $this->invoice())->id,
            'number' => 'CN-000001', 'amount' => 21.0, 'tax_amount' => 1.0,
            'issued_at' => now()->toDateString(), 'reason' => 'ردّ باقتين',
        ]);
    }

    /** @return array{0: CustomerPayment, 1: CustomerInvoice} */
    private function payment(float $amount = 30.0, float $allocated = 30.0): array
    {
        $invoice = $this->invoice();

        $payment = CustomerPayment::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'number' => 'RC-000001', 'amount' => $amount, 'method' => 'نقدي',
            'occurred_at' => now()->toDateString(),
        ]);

        CustomerPaymentAllocation::create([
            'customer_payment_id' => $payment->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => $allocated,
        ]);

        return [$payment->fresh()->load('allocations.invoice'), $invoice];
    }
}
