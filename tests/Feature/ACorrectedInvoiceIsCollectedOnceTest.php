<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * فاتورةٌ صُحّحت بإشعارٍ مدين أو دائن تُحصَّل مرّةً واحدة، ولا يتجاوز تحصيلُها ما عليها (#195 مع #287).
 *
 * الباقي = الإجماليّ + المدين − الدائن − المقبوض. والتحصيلُ يقرأ هذا الباقي لا الإجماليَّ كما صدر:
 * إشعارٌ مدين يرفعه فيُحصَّل الفرقُ على دفعات، وإشعارٌ دائن بعد السداد يصير رصيدًا للعميل
 * لا يُردّ وحده ولا يفتح بابًا لتحصيلٍ آخر. ورابطُ Paymob المعلّق يمنع التصحيح حتى يُلغى،
 * ولا يُعاد فتحُ الرابط الملغى. ولا يتحرّك مخزونٌ في شيءٍ من ذلك.
 */
class ACorrectedInvoiceIsCollectedOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $company;

    private Branch $branch;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Muscat'));

        $this->shop = Business::create(['name' => 'متجر التصحيح والتحصيل', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        foreach (['vat_enabled' => '0', 'pay_credit' => '1'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@corrected-collect.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->company = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222223', 'allow_credit_sales' => true, 'language' => 'ar']);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══ أدوات ═══ */

    /** فاتورةٌ صادرة: عشر وحداتٍ بعشرة = مئة */
    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, ['issued_at' => '2027-02-10', 'branch_id' => $this->branch->id], [
            ['description' => 'توريد', 'quantity' => 10, 'unit_price' => 10],
        ], $this->owner->id), $this->owner->id);
    }

    /** تصحيحُ الكمّيّة إلى `$qty` — كما ترسله الشاشة */
    private function correct(CustomerInvoice $invoice, float $qty): TestResponse
    {
        $invoice = $invoice->fresh('items');
        $lines = array_map(fn ($l) => [
            'key' => $l['key'], 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
            'unit_price' => (float) $l['unit_price'], 'discount' => (float) $l['discount'], 'tax_rate' => (float) $l['tax_rate'],
        ], CustomerInvoiceEdits::state($invoice)['lines']);
        $lines[0]['quantity'] = $qty;

        return $this->put(route('admin.customerInvoices.update', $invoice->id), [
            'expected_revision' => (int) $invoice->revision, 'edit_token' => 'cc-'.(++$this->n), 'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(), 'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days, 'branch_id' => $invoice->branch_id, 'notes' => $invoice->notes,
            'internal_notes' => $invoice->internal_notes, 'reason' => 'تصحيح الكمية', 'basis' => CustomerInvoiceRevision::ERROR, 'items' => $lines,
        ]);
    }

    private function collect(CustomerInvoice $invoice, float $amount, ?string $key = null): TestResponse
    {
        return $this->post(route('admin.customerPayments.store'), [
            'request_key' => $key ?? (string) Str::uuid(), 'customer_id' => $invoice->customer_id,
            'customer_invoice_id' => $invoice->id, 'amount' => $amount, 'method' => 'نقدي',
        ]);
    }

    private function books(CustomerInvoice $invoice): array
    {
        $inv = $invoice->fresh();

        return [
            'left' => $inv->outstanding(),
            'paid' => $inv->paidTotal(),
            'credit' => round(Receivables::credit($this->shop->id), 3) + 0.0,
            'cash' => round(Ledger::balance($this->shop->id, 'cash'), 3) + 0.0,
            'receivable' => round(Ledger::balance($this->shop->id, 'receivable'), 3) + 0.0,
            'payments' => CustomerPayment::where('business_id', $this->shop->id)->whereNull('cancelled_at')->count(),
            'entries' => JournalEntry::where('business_id', $this->shop->id)->count(),
        ];
    }

    private function assertBooksAgree(): void
    {
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذمم لا تطابق الدفتر');
    }

    /* ═══ الحالات ═══ */

    public function test_a_debit_note_raises_what_is_left_and_it_is_collected_once_in_parts(): void
    {
        $invoice = $this->invoice();
        $this->correct($invoice, 12)->assertSessionHasNoErrors();
        $this->assertSame(20.0, round((float) CustomerDebitNote::sole()->amount, 3));
        $this->assertEquals(120, $this->books($invoice)['left']);

        $this->collect($invoice, 50)->assertSessionHasNoErrors();
        $this->assertEquals(70, $this->books($invoice)['left']);

        // فوق الباقي يُردّ ولا يُكتب شيء — والباقي يُقال
        $before = $this->books($invoice);
        $this->collect($invoice, 71)->assertSessionHasErrors('amount');
        $this->assertStringContainsString('70', (string) session('errors')->first('amount'));
        $this->assertSame($before, $this->books($invoice));

        // والباقي يُحصَّل مرّةً واحدة — وإرسالُه ثانيةً بمفتاحه لا يكتب ثانية
        $key = (string) Str::uuid();
        $this->collect($invoice, 70, $key)->assertSessionHasNoErrors();
        $paid = $this->books($invoice);
        $this->collect($invoice, 70, $key);
        $this->assertSame($paid, $this->books($invoice), 'كُتب التحصيلُ مرّتين');
        $this->assertEquals(0, $paid['left']);
        $this->assertSame(120.0, $paid['cash']);

        // ومدفوعةٌ كلُّها لا تأخذ شيئًا آخر
        $this->collect($invoice, 1)->assertSessionHasErrors('amount');
        $this->assertSame($paid, $this->books($invoice));
        $this->assertBooksAgree();
    }

    public function test_a_credit_note_after_full_payment_leaves_the_customer_credit_and_refunds_nothing(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 100)->assertSessionHasNoErrors();
        $cash = $this->books($invoice)['cash'];

        $this->correct($invoice, 8)->assertSessionHasNoErrors();
        $this->assertSame(20.0, round((float) CustomerCreditNote::sole()->amount, 3));

        $after = $this->books($invoice);
        $this->assertEquals(0, $after['left'], 'الباقي لا يصير سالبًا');
        $this->assertSame(20.0, $after['credit'], 'الفائضُ رصيدٌ للعميل');
        $this->assertSame($cash, $after['cash'], 'رُدّ مالٌ وحده');

        $this->collect($invoice, 1)->assertSessionHasErrors('amount');
        $this->assertSame($after, $this->books($invoice));
        $this->assertBooksAgree();
    }

    public function test_a_partly_paid_invoice_corrected_below_what_was_paid_owes_nothing_and_takes_nothing(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 90)->assertSessionHasNoErrors();

        $this->correct($invoice, 8)->assertSessionHasNoErrors(); // الإجماليُّ بعد الإشعار ثمانون، والمقبوضُ تسعون

        $after = $this->books($invoice);
        $this->assertEquals(0, $after['left']);
        $this->assertSame(10.0, $after['credit']);
        $this->collect($invoice, 5)->assertSessionHasErrors('amount');
        $this->assertSame($after, $this->books($invoice));
        $this->assertBooksAgree();
    }

    public function test_a_pending_paymob_link_holds_the_correction_and_the_cancelled_link_is_never_reopened(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_cc', 'secret_key' => 'sk_cc', 'hmac_secret' => 'hmac-cc',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api-cc', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580011, 'amount_cents' => 100000, 'shorten_url' => 'https://paymob.link/cc',
                'client_url' => 'https://oman.paymob.com/cc', 'created_at' => '2027-02-10T10:00:00',
                'expires_at' => '2027-02-17T23:59:00', 'state' => 'created', 'order' => 616057559,
            ]),
            '*' => Http::response([]),
        ]);

        $invoice = $this->invoice();
        $link = PaymentLinks::create($this->shop->id, $this->company, $invoice, null, null, $this->owner->id);
        $this->assertSame(CustomerPaymentLink::PENDING, $link->fresh()->status);

        // الرابطُ المعلّق يطلب مئة: لا يُصحَّح المبلغ تحته فيُطلب من العميل غيرُ ما عليه
        $this->correct($invoice, 12);
        $this->assertSame(0, CustomerDebitNote::count());

        $this->post(route('admin.finance.paymentLinks.cancel', $link->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerPaymentLink::CANCELLED, $link->fresh()->status);

        $this->correct($invoice, 12)->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerDebitNote::count());

        // والرابطُ الملغى لا يعود — ولا يُحصَّل به
        $this->assertSame(CustomerPaymentLink::CANCELLED, $link->fresh()->status);
        $this->assertEquals(120, $this->books($invoice)['left']);
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertBooksAgree();
    }
}
