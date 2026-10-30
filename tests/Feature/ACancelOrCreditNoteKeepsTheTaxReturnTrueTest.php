<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\BooksPeriod;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\ReportData;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إلغاءُ فاتورة العميل وإشعارُها الدائنُ اليدويّ — لا يكتبان في الإقرار ما لا تقوله الأوراق.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإلغاءُ يعكس قيدَ الفاتورة كلَّه اليوم، بلا مستندٍ يشير إليها: فلا يكون
 *     لفاتورةٍ في فترةٍ ضريبيّةٍ قُدّم إقرارُها (متجرٌ مسجَّلٌ أو فاتورةٌ حملت
 *     ضريبة). وما أُلغي أو أُرجع بعد الإقرار يُكتب إشعارًا دائنًا يشير إليها.
 *   - ولا إلغاءَ ولا إشعارَ يُنزل الباقي تحت رابطِ دفعٍ ينتظر: لا يدفع العميلُ
 *     فاتورةً ملغاةً ولا أكثرَ ممّا عليه.
 *   - ضريبةُ الإشعار لا تفوق ما يحمله مبلغُه بأعلى نسبةٍ في الفاتورة، ولا
 *     تتجاوز الإشعاراتُ معًا ضريبةَ الفاتورة.
 *   - والمشروعُ باقٍ: إلغاءُ ما لم يُقرّ، والإشعارُ لإرجاعٍ أو خصمٍ بعد الإقرار،
 *     والإشعارُ ببندٍ معفًى، وغيرُ المسجَّل في الضريبة.
 */
class ACancelOrCreditNoteKeepsTheTaxReturnTrueTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $customer;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-09-20 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الإلغاء', 'type' => 'عام', 'status' => 'نشط']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@cancel.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** فاتورةٌ صادرة: مئةٌ بخمسةٍ في المئة = ١٠٥ — وبنودٌ أخرى إن طُلبت */
    private function issued(string $on = '2025-09-10', array $lines = [['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50]]): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => $on, 'branch_id' => $this->branch->id], $lines, $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function cancel(CustomerInvoice $invoice)
    {
        return $this->post(route('admin.customerInvoices.cancel', $invoice->id), ['reason' => 'طلب العميل']);
    }

    private int $n = 0;

    private function credit(CustomerInvoice $invoice, float $amount, float $tax, ?string $basis = CustomerCreditNote::RETURN, ?string $token = null)
    {
        return $this->post(route('admin.customerInvoices.creditNote', $invoice->id), array_filter([
            'amount' => $amount, 'tax_amount' => $tax, 'reason' => 'رُدّت باقة', 'basis' => $basis,
            'token' => $token ?? 'form-'.(++$this->n),
        ], fn ($v) => $v !== null));
    }

    private function link(CustomerInvoice $invoice, float $amount, bool $live = true): CustomerPaymentLink
    {
        return CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'customer_invoice_id' => $invoice->id,
            'reference' => 'pay-'.$invoice->id.'-'.$amount.'-'.(int) $live, 'amount' => $amount, 'currency' => 'OMR',
            'status' => CustomerPaymentLink::PENDING, 'is_live' => $live, 'expires_at' => now()->addDay(), 'created_by' => $this->owner->id,
        ]);
    }

    private function outputTax(string $month = '2025-09'): float
    {
        return (float) ReportData::vat($this->shop->id, ['_period' => ReportingPeriod::month($month)])['summary']['output'];
    }

    /** الفاتورةُ وقيودُ المتجر كما كانت — لا إلغاءَ ولا عكسَ ولا إشعار */
    private function snapshot(CustomerInvoice $invoice): array
    {
        return [
            $invoice->fresh()->status,
            JournalEntry::where('business_id', $this->shop->id)->count(),
            CustomerCreditNote::where('customer_invoice_id', $invoice->id)->count(),
            $this->outputTax(),
        ];
    }

    /* ═══════════ ١ · الفترةُ الضريبيّةُ المُقرّة ═══════════ */

    public function test_an_invoice_in_a_filed_vat_period_is_not_cancelled_but_a_later_return_is_still_credited(): void
    {
        $invoice = $this->issued();
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_filed_through'], ['value' => '2025-09-15']);
        $before = $this->snapshot($invoice);

        // الإلغاءُ يعكس ضريبتَها اليومَ بلا مستند — يُردّ، ولو للمالك
        $this->cancel($invoice)->assertSessionHasErrors('invoice');
        $this->assertStringContainsString('قُدّم إقرارها حتى 2025-09-15', session('errors')->first('invoice'));
        $this->assertSame($before, $this->snapshot($invoice));

        // وفاتورةٌ حملت ضريبةً ثمّ أُطفئت الضريبة: إقرارُها قائم
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $this->cancel($invoice)->assertSessionHasErrors('invoice');
        $this->assertSame($before, $this->snapshot($invoice));
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);

        // وإرجاعٌ بعد الإقرار مستندٌ يشير إليها — باقٍ، وأثرُه يومَه
        $this->credit($invoice, 52.5, 2.5)->assertSessionHasNoErrors();
        $note = CustomerCreditNote::sole();
        $this->assertSame('2025-09-20', $note->issued_at->toDateString());
        $this->assertEquals(2.5, $this->outputTax());
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
    }

    public function test_a_merchant_not_registered_for_vat_cancels_past_a_filed_date(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $invoice = $this->issued();
        $this->assertEquals(0.0, (float) $invoice->tax_total);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_filed_through'], ['value' => '2025-09-15']);

        $this->cancel($invoice)->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
    }

    public function test_an_open_period_invoice_is_still_cancelled(): void
    {
        $invoice = $this->issued('2025-09-18');
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_filed_through'], ['value' => '2025-09-15']);

        $this->cancel($invoice)->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
        $this->assertEquals(0.0, $this->outputTax(), 'صدرت وأُلغيت في فترةٍ لم تُقرّ');
    }

    /* ═══════════ ٢ · رابطُ دفعٍ ينتظر ═══════════ */

    public function test_a_payment_link_awaiting_payment_holds_the_amount_it_asks_for(): void
    {
        $invoice = $this->issued();
        $link = $this->link($invoice, 105);
        $before = $this->snapshot($invoice);

        $this->cancel($invoice)->assertSessionHasErrors('invoice');
        $this->assertStringContainsString('ألغِ الرابط', session('errors')->first('invoice'));
        $this->credit($invoice, 10, 0.476)->assertSessionHasErrors('amount');
        $this->assertSame($before, $this->snapshot($invoice));

        // رابطٌ بجزءٍ من المبلغ يبقى صحيحًا ما دام الباقي يغطّيه
        $link->forceFill(['status' => CustomerPaymentLink::CANCELLED, 'cancelled_at' => now()])->save();
        $this->link($invoice, 50);
        $this->credit($invoice, 10, 0.476)->assertSessionHasNoErrors();
        $this->credit($invoice, 50, 2.381)->assertSessionHasErrors('amount');

        // ورابطُ التجربة لا يُحصِّل مالًا (#178) — لا يمنع
        CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->update(['status' => CustomerPaymentLink::CANCELLED]);
        $this->link($invoice, 95, live: false);
        $this->cancel($invoice)->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
    }

    /* ═══════════ ٣ · ضريبةُ الإشعار ═══════════ */

    public function test_a_credit_notes_tax_never_exceeds_what_its_invoice_charged(): void
    {
        $invoice = $this->issued(); // ١٠٠ + ٥

        // عشرةٌ لا تحمل عشرةً ضريبة — أقصاها عشرةٌ × ٥ ÷ ١٠٥
        $this->credit($invoice, 10, 10)->assertSessionHasErrors('tax_amount');
        $this->credit($invoice, 10, 0.5)->assertSessionHasErrors('tax_amount');
        $this->assertSame(0, CustomerCreditNote::count());
        $this->assertEquals(5.0, $this->outputTax());

        $this->credit($invoice, 10, 0.476)->assertSessionHasNoErrors();
        // وبندٌ معفًى يُردّ بلا ضريبة — يكتبه التاجر بيده
        $this->credit($invoice, 10, 0)->assertSessionHasNoErrors();
        $this->assertEquals(4.524, round($this->outputTax(), 3));

        // فاتورةٌ بنسبتين: ضريبتُها خمسة — والإشعاراتُ معًا لا تتجاوزها
        $mixed = $this->issued('2025-09-12', [
            ['description' => 'زهور', 'quantity' => 1, 'unit_price' => 100],
            ['description' => 'كتاب', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
        ]);
        $this->assertEquals(5.0, (float) $mixed->tax_total);
        $this->credit($mixed, 105, 5)->assertSessionHasNoErrors();
        $this->credit($mixed, 50, 2)->assertSessionHasErrors('tax_amount');
        $this->credit($mixed, 50, 0)->assertSessionHasNoErrors();

        // وفاتورةٌ بلا ضريبة: إشعارُها بلا ضريبة
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $plain = $this->issued('2025-09-14');
        $this->credit($plain, 10, 0.1)->assertSessionHasErrors('tax_amount');
        $this->credit($plain, 10, 0)->assertSessionHasNoErrors();
    }

    /* ═══════════ ٤ · سببُ الإشعار ═══════════ */

    public function test_a_manual_credit_note_says_why_and_an_error_waits_in_a_filed_period(): void
    {
        $invoice = $this->issued();

        // بلا سبب، أو بسببٍ لا يُعرف: يُردّ
        $this->credit($invoice, 10, 0.476, basis: null)->assertSessionHasErrors('basis');
        $this->credit($invoice, 10, 0.476, basis: 'gift')->assertSessionHasErrors('basis');
        $this->assertSame(0, CustomerCreditNote::count());

        // فترةٌ قُدّم إقرارها: تصحيحُ الخطأ ينتظر المحاسب — ولو للمالك
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_filed_through'], ['value' => '2025-09-15']);
        $this->credit($invoice, 10, 0.476, basis: CustomerCreditNote::ERROR)->assertSessionHasErrors('basis');
        $this->assertStringContainsString('قُدّم إقرارها حتى 2025-09-15', session('errors')->first('basis'));
        $this->assertSame(0, CustomerCreditNote::count());
        $this->assertEquals(5.0, $this->outputTax());

        // والإرجاعُ والخصمُ بعد الإقرار يُصدران — بسببهما، يومَهما
        $this->credit($invoice, 21, 1, basis: CustomerCreditNote::RETURN)->assertSessionHasNoErrors();
        $this->credit($invoice, 10.5, 0.5, basis: CustomerCreditNote::DISCOUNT)->assertSessionHasNoErrors();
        $this->assertSame([CustomerCreditNote::RETURN, CustomerCreditNote::DISCOUNT], CustomerCreditNote::orderBy('id')->pluck('basis')->all());
        $this->assertEquals(3.5, $this->outputTax());
        $this->assertStringContainsString('خصم بعد البيع', (string) ActivityLog::latest('id')->value('description'));

        // ولا يتجاوزه استدعاءٌ آخر للخدمة: بلا سبب، أو بتصحيح خطأٍ في الفترة المُقرّة
        foreach ([null, CustomerCreditNote::ERROR] as $basis) {
            try {
                CustomerInvoices::creditNote($invoice->fresh(), 5, 0.238, 'استدعاء مباشر', $this->owner->id, basis: $basis);
                $this->fail('أُصدر إشعارٌ يدويّ بلا سببٍ مقبول');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('basis', $e->errors());
            }
        }
        $this->assertSame(2, CustomerCreditNote::count());

        // وإشعارُ إلغاء الطلب الآليّ لا سببَ له ولا يُسأل عنه
        $auto = CustomerInvoices::creditNote($invoice->fresh(), 5, 0.238, 'إلغاء طلب', null, CustomerInvoices::NOTE_ORDER_CANCELLED);
        $this->assertNull($auto->basis);
    }

    public function test_a_credit_note_form_sent_twice_issues_one_note(): void
    {
        $invoice = $this->issued();
        $journal = JournalEntry::where('business_id', $this->shop->id)->count();

        $this->credit($invoice, 21, 1, token: 'same-form')->assertSessionHasNoErrors();
        $this->credit($invoice, 21, 1, token: 'same-form')->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerCreditNote::count());
        $this->assertSame($journal + 1, JournalEntry::where('business_id', $this->shop->id)->count(), 'قيدٌ واحد');

        // ونموذجٌ رُدّ لخطأٍ فيه يُصحَّح ويُرسل بالرمز نفسه
        $this->credit($invoice, 10, 9, token: 'fixed-form')->assertSessionHasErrors('tax_amount');
        $this->credit($invoice, 10, 0.476, token: 'fixed-form')->assertSessionHasNoErrors();
        $this->assertSame(2, CustomerCreditNote::count());
    }

    /* ═══════════ ٥ · الدفاترُ المقفلة، والصلاحيات، والعزل ═══════════ */

    public function test_closed_books_take_no_cancel_and_no_credit_note(): void
    {
        $invoice = $this->issued();
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => BooksPeriod::SETTING], ['value' => '2025-09-20']);
        $before = $this->snapshot($invoice);

        $this->cancel($invoice)->assertSessionHasErrors('invoice');
        $this->credit($invoice, 10, 0.476)->assertSessionHasErrors('amount');
        $this->assertSame($before, $this->snapshot($invoice));
    }

    public function test_who_cancels_or_credits_and_whose_invoice(): void
    {
        $invoice = $this->issued();
        $before = $this->snapshot($invoice);

        $this->actingAs(User::create([
            'business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'seller@cancel.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط',
        ]));
        $this->cancel($invoice)->assertForbidden();
        $this->credit($invoice, 10, 0.476)->assertForbidden();

        $neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->actingAs(User::create([
            'business_id' => $neighbour->id, 'name' => 'مالك الجار', 'email' => 'neighbour@cancel.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]));
        $this->cancel($invoice)->assertNotFound();
        $this->credit($invoice, 10, 0.476)->assertNotFound();

        $this->assertSame($before, $this->snapshot($invoice));
    }
}
