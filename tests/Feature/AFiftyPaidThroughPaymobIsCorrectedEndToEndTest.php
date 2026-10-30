<?php

namespace Tests\Feature;

use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\Paymob\Amount;
use App\Support\Receivables;
use App\Support\Store\Paymob;
use App\Http\Controllers\PdfController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TillsAndPaidInvoices;
use Tests\TestCase;

/**
 * فاتورةُ الخمسين تُدفع عبر Paymob ثمّ تُصحَّح — بالأبواب التي يمرّ بها الناس.
 *
 * ═══ ما يجري ═══
 *
 * المالكُ يُصدر فاتورةً بخمسين ريالًا (شاملةَ الضريبة) ورابطَ Paymob من
 * نموذجها، وPaymob تُشعر بالسداد موقَّعًا — مرّتين كما تفعل. ثمّ يفتح شاشة
 * «تصحيح الفاتورة» ويرسل ما تعرضه هي: يعاين، ثمّ يعتمد. مرّةً إلى 55 ومرّةً
 * إلى 45، كلٌّ في متجرٍ نظيف. والتقاريرُ تُقرأ من صفحاتها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - سندُ القبض كما هو، ولا سندَ ثانٍ ولو أعادت Paymob إشعارها بعد التصحيح.
 *   - الرابطُ المدفوع لا يُعاد تفعيلُه ولا يُنشأ غيرُه، ولا يُطلب من Paymob ردّ.
 *   - الزيادةُ إشعارٌ مدين يبقى به 5 على العميل، والنقصُ إشعارٌ دائن يصير به
 *     للعميل رصيدٌ دائنٌ 5 — والفاتورةُ وقيدُها كما صدرا.
 *   - الإقرارُ وكشفُ الحساب وصفحةُ الفاتورة يقرؤون المستندَ مرّةً، والدفاترُ متوازنة.
 *   - والأوراقُ المطبوعة: ورقةُ الفاتورة تجمع على وجهها (الإجماليّ + المدين −
 *     الدائن − المسدَّد = الباقي) بأرقام المستندات، والإشعارُ المدين لا يُسمّى
 *     «Credit note»، وسطرُ التغيير بلا سهمٍ لا يرسمه الخطّ.
 *   - وبيئةُ التجربة (Sandbox): الرابطُ يُدفع ولا تحصيلَ ولا قيد — فلا تُختبر
 *     بها سنداتُ القبض ولا الذمم، والتصحيحُ يعمل فوقها كفاتورةٍ غير مدفوعة.
 *
 * وPaymob نفسُها لا تُنادى: الطلباتُ إليها مُحاكاة (`Http::fake`)، والإشعارُ
 * موقَّعٌ بمفتاح HMAC محلّيّ. لا مالَ ولا مفاتيحَ حقيقيّة.
 */
class AFiftyPaidThroughPaymobIsCorrectedEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use TillsAndPaidInvoices;

    private int $tx = 7700;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTills();
        Carbon::setTestNow('2026-10-19 10:00:00');
        app()->setLocale('ar');

        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => 'inclusive']);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580020, 'shorten_url' => 'https://paymob.link/fifty',
                'expires_at' => '2026-10-26T23:59:00', 'state' => 'created', 'order' => 616057600,
            ]),
            '*' => Http::response(['detail' => 'not expected'], 500),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function gateway(string $mode): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-fifty',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => $mode,
        ]);
    }

    /** ① المالكُ يُصدر فاتورةَ الخمسين ورابطَها من نموذجها */
    private function issueFiftyWithLink(): CustomerInvoice
    {
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'issued_at' => '2026-10-19',
            'payment_method' => 'آجل',
            'issue' => true,
            'payment_link' => true,
            'form_token' => 'form-'.uniqid(),
            'items' => [['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 50]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->sole();
        $this->assertEquals(50.0, (float) $invoice->total, 'خمسون شاملةَ الضريبة');

        return $invoice;
    }

    /** ② Paymob تُشعر بالسداد — موقَّعًا، بالعمليّة نفسِها إن أُعيد */
    private function paymobPays(CustomerPaymentLink $link, bool $live, ?int $tx = null): int
    {
        $tx ??= ++$this->tx;
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-19T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => $tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => $live,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-fifty'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();

        return $tx;
    }

    /** ③ شاشةُ «تصحيح الفاتورة» كما تُفتح — والنموذجُ ممّا تعرضه هي */
    private function screenForm(CustomerInvoice $invoice, float $price): array
    {
        $props = $this->get(route('admin.customerInvoices.edit', $invoice->id))->assertOk()->viewData('page')['props'];
        $head = $props['invoice'];
        $lines = array_map(fn (array $l) => [
            'key' => $l['key'], 'description' => $l['description'], 'quantity' => $l['quantity'],
            'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'tax_rate' => $l['tax_rate'],
        ], $head['items']);
        $lines[0]['unit_price'] = $price;

        return [
            'expected_revision' => $head['revision'],
            'edit_token' => $props['edit_token'],
            'customer_id' => $head['customer_id'],
            'issued_at' => $head['issued_at'],
            'due_at' => $head['due_at'],
            'payment_terms_days' => $head['payment_terms_days'],
            'branch_id' => $head['branch_id'],
            'notes' => $head['notes'],
            'internal_notes' => $head['internal_notes'],
            'basis' => CustomerInvoiceRevision::ERROR,
            'reason' => 'السعر المتفق عليه في عرض السعر',
            'items' => $lines,
        ];
    }

    /** ما لا يجوز أن يمسّه تصحيح: الفاتورةُ كما صدرت، والتحصيلُ، والرابط */
    private function untouchable(CustomerInvoice $invoice): array
    {
        $invoice = $invoice->fresh('items');

        return [
            'invoice' => [$invoice->number, $invoice->status, $invoice->issued_at->toDateString(), (float) $invoice->subtotal, (float) $invoice->tax_total, (float) $invoice->total],
            'items' => $invoice->items->map(fn ($i) => [$i->id, $i->description, (float) $i->quantity, (float) $i->unit_price, (float) $i->tax_amount, (float) $i->line_total])->all(),
            'entry' => JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)
                ->get(['id', 'entry_date', 'reversed_at'])->map(fn ($e) => [$e->id, (string) $e->entry_date, $e->reversed_at])->all(),
            'payments' => CustomerPayment::where('business_id', $this->shop->id)->get(['id', 'amount', 'cancelled_at'])
                ->map(fn ($p) => [$p->id, (float) $p->amount, $p->cancelled_at])->all(),
            'allocations' => DB::table('customer_payment_allocations')->orderBy('id')->get(['customer_payment_id', 'customer_invoice_id', 'amount'])
                ->map(fn ($a) => [(int) $a->customer_payment_id, (int) $a->customer_invoice_id, (float) $a->amount])->all(),
            'links' => CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->get(['id', 'status', 'provider_transaction_id'])
                ->map(fn ($l) => [$l->id, $l->status, (string) $l->provider_transaction_id])->all(),
        ];
    }

    /** نصُّ الورقة كما تُرسم للطباعة — قبل أن تصير PDF */
    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function balanced(): void
    {
        $sums = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $this->shop->id)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) d, COALESCE(SUM(journal_lines.credit), 0) c')->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.0005, 'الدفاترُ متوازنة');
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced'], 'الذممُ تطابق الدفتر');
    }

    private function vatOutput(): float
    {
        $props = $this->get(route('admin.reports.vat', ['period' => 'month', 'month' => '2026-10']))->assertOk()->viewData('page')['props'];

        return (float) $props['summary']['output'];
    }

    private function statement(): array
    {
        // وورقتُه المطبوعة تُفتح أيضًا
        $this->get(route('admin.customers.statement', ['id' => $this->customer->id]))->assertOk()->assertHeader('content-type', 'application/pdf');

        return $this->get(route('admin.finance.customerStatement', ['customer' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()->viewData('page')['props'];
    }

    private function paymobWasAskedToRefund(): bool
    {
        return Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'refund') || str_contains($r->url(), 'void'))->isNotEmpty();
    }

    private function linksOpenedAtPaymob(): int
    {
        return Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'payment-links'))->count();
    }

    /* ============================== 50 ← 55 ============================== */

    public function test_fifty_paid_by_paymob_raised_to_fifty_five_by_a_debit_note(): void
    {
        $this->gateway(PaymentGateway::LIVE);
        $invoice = $this->issueFiftyWithLink();
        $link = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertTrue((bool) $link->is_live);

        $tx = $this->paymobPays($link, live: true);
        $this->paymobPays($link->fresh(), live: true, tx: $tx); // Paymob تُعيد إشعارها
        $invoice->refresh();
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count(), 'سندُ قبضٍ واحد ولو أُعيد الإشعار');
        $this->assertEquals(0.0, $invoice->outstanding());
        $before = $this->untouchable($invoice);
        $vatBefore = $this->vatOutput();
        $this->assertEqualsWithDelta(2.381, $vatBefore, 0.0005, '50 × 5 ÷ 105');

        // المعاينة: تسمّي الإشعارَ المدين ولا تكتب شيئًا
        $form = $this->screenForm($invoice, 55);
        $plan = $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $form)->assertOk()->json();
        $this->assertSame(['debit'], array_keys(array_filter($plan['documents'])));
        $this->assertEquals(5.0, $plan['documents']['debit']['amount']);
        $this->assertSame(0, CustomerDebitNote::count(), 'المعاينةُ لا تكتب');

        // الاعتماد — مرّتين بالنموذج نفسه (نقرةٌ مزدوجة)
        $this->put(route('admin.customerInvoices.update', $invoice->id), $form)->assertSessionHasNoErrors();
        $this->put(route('admin.customerInvoices.update', $invoice->id), $form);

        $note = CustomerDebitNote::sole();
        $this->assertStringStartsWith('DN-', $note->number);
        $this->assertSame($invoice->id, (int) $note->customer_invoice_id);
        $this->assertEquals(5.0, (float) $note->amount);
        $this->assertEquals(0.238, (float) $note->tax_amount, '5 × 5 ÷ 105');
        $this->assertSame(0, CustomerCreditNote::count());

        // الفاتورةُ وتحصيلُها ورابطُها كما كانت — وPaymob لم تُسأل شيئًا بعد الدفع
        $this->paymobPays($link->fresh(), live: true, tx: $tx); // وإشعارٌ متأخّرٌ بعد التصحيح
        $this->assertEquals($before, $this->untouchable($invoice));
        $this->assertSame(1, $this->linksOpenedAtPaymob(), 'لا رابطَ جديد ولا إعادةَ تفعيل');
        $this->assertFalse($this->paymobWasAskedToRefund());

        // الذمّة: يبقى 5 على العميل
        $invoice->refresh();
        $this->assertEquals(5.0, $invoice->outstanding());
        $this->assertSame('مدفوعة جزئيًا', $invoice->paymentState());
        $this->assertEquals(0.0, Receivables::credit($this->shop->id, $this->customer->id));

        // صفحةُ الفاتورة: كما صدرت، والمطلوبُ بعد المستند 55
        $show = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertEquals(50.0, (float) $show['invoice']['total']);
        $this->assertEquals(55.0, (float) $show['invoice']['effective_total']);
        $this->assertSame([$note->number], array_column($show['debit_notes'] ?? $show['invoice']['debit_notes'], 'number'));

        // الإقرار: ضريبةُ الإشعار في يومه مرّةً واحدة
        $this->assertEqualsWithDelta(2.619, $this->vatOutput(), 0.0005);

        // كشفُ الحساب: فاتورة، تحصيل، إشعار مدين — والختام 5
        $statement = json_encode($this->statement(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($note->number, $statement);
        $this->assertStringContainsString('إشعار مدين', $statement);

        // ورقةُ الفاتورة تجمع على وجهها: 50 + 5 − 50 = 5، برقم الإشعار
        $paper = self::text(PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh()));
        $this->assertStringContainsString('إشعارات مدينة '.$note->number, $paper);
        $this->assertStringContainsString('+ 5.000', $paper);

        // والإشعارُ المدين ورقتُه: لا «Credit note» بالإنجليزيّة، ولا سهمَ بلا حرف
        $debitPaper = fn () => self::text(DocumentRenderer::generic($this->shop->id, 'credit_note', DocumentPaper::forDebitNote($note->fresh('invoice'))));
        $ar = $debitPaper();
        $this->assertStringContainsString('السعر من 50.000', $ar);
        $this->assertStringNotContainsString('←', $ar);
        app()->setLocale('en');
        $en = $debitPaper();
        app()->setLocale('ar');
        $this->assertStringContainsString('Debit note no.', $en);
        $this->assertStringNotContainsString('Credit note', $en);

        // المستندُ يُفتح ويُطبع وحده
        $this->get(route('admin.customerInvoices.debitNotes.show', $note->id))->assertOk();
        $this->get(route('admin.customerInvoices.debitNotes.pdf', $note->id))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->balanced();
    }

    /* ============================== 50 ← 45 ============================== */

    public function test_fifty_paid_by_paymob_lowered_to_forty_five_is_a_credit_balance_never_a_refund(): void
    {
        $this->gateway(PaymentGateway::LIVE);
        $invoice = $this->issueFiftyWithLink();
        $link = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
        $tx = $this->paymobPays($link, live: true);
        $before = $this->untouchable($invoice->fresh());

        $form = $this->screenForm($invoice, 45);
        $plan = $this->postJson(route('admin.customerInvoices.editPreview', $invoice->id), $form)->assertOk()->json();
        $this->assertSame(['credit'], array_keys(array_filter($plan['documents'])));
        $this->assertEquals(5.0, $plan['documents']['credit']['amount']);
        $this->put(route('admin.customerInvoices.update', $invoice->id), $form)->assertSessionHasNoErrors();

        $note = CustomerCreditNote::sole();
        $this->assertStringStartsWith('CN-', $note->number);
        $this->assertEquals(5.0, (float) $note->amount);
        $this->assertEquals(0.238, (float) $note->tax_amount);
        $this->assertSame(0, CustomerDebitNote::count());

        $this->paymobPays($link->fresh(), live: true, tx: $tx);
        $this->assertEquals($before, $this->untouchable($invoice), 'سندُ القبض والتخصيصُ والرابطُ والفاتورةُ كما كانت');
        $this->assertFalse($this->paymobWasAskedToRefund(), 'لا ردَّ تلقائيّ');
        $this->assertSame(1, $this->linksOpenedAtPaymob());

        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->outstanding());
        $this->assertSame('مدفوعة', $invoice->paymentState());
        $this->assertEquals(5.0, Receivables::credit($this->shop->id, $this->customer->id), 'رصيدٌ دائنٌ للعميل');

        $show = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $this->assertEquals(50.0, (float) $show['invoice']['total']);
        $this->assertEquals(45.0, (float) $show['invoice']['effective_total']);

        $this->assertEqualsWithDelta(2.143, $this->vatOutput(), 0.0005, '2.381 − 0.238');
        $statement = json_encode($this->statement(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($note->number, $statement);

        $paper = self::text(PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh()));
        $this->assertStringContainsString('إشعارات دائنة '.$note->number, $paper);
        $this->assertStringContainsString('− 5.000', $paper);

        $this->get(route('admin.customerInvoices.creditNotes.pdf', $note->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->balanced();
    }

    /* ============================== بيئة التجربة ============================== */

    public function test_in_the_sandbox_the_link_is_paid_off_the_books_so_receipts_are_not_tested_there(): void
    {
        $this->gateway(PaymentGateway::TEST);
        $invoice = $this->issueFiftyWithLink();
        $link = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertFalse((bool) $link->is_live);

        $this->paymobPays($link, live: false);
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count(), 'دفعةُ التجربة لا تحصيل');
        $invoice->refresh();
        $this->assertEquals(50.0, $invoice->outstanding(), 'فالفاتورةُ في الدفاتر غيرُ مدفوعة');

        // والتصحيحُ فوقها تصحيحُ فاتورةٍ غير مدفوعة: الخمسةُ تُضاف إلى الخمسين
        $this->put(route('admin.customerInvoices.update', $invoice->id), $this->screenForm($invoice, 55))->assertSessionHasNoErrors();
        $this->assertEquals(5.0, (float) CustomerDebitNote::sole()->amount);
        $this->assertEquals(55.0, $invoice->fresh()->outstanding());
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->balanced();
    }
}
