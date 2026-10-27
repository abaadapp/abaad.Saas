<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\AlertMetrics;
use App\Support\Books;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\PaymobRefunds;
use App\Support\Receipts;
use App\Support\Receivables;
use App\Support\StandaloneInvoiceSales;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لوحةُ التحكّم تجيب أربعةَ أسئلةٍ بأربعة أرقام — لا رقمٌ يتنكّر في غيره.
 *
 *   «مبيعات اليوم»        كم بعتُ؟        الطلباتُ وفواتيرُ العملاء يومَ صدورها، نقدًا وآجلًا.
 *   «مقبوضات اليوم»       كم وصلني؟       المالُ من الدفتر (`Receipts`)، كلُّ قبضٍ مرّة والردُّ يومَه.
 *   «مستحقات العملاء»     كم بقي لي؟      `Receivables::outstanding` — ما تقوله شاشةُ الذمم.
 *   «روابط بانتظار الدفع» كم ينتظر الدفع؟ `PaymentLinks::awaiting` — مؤشّرٌ لا دَينٌ يُضاف.
 *
 * ═══ وما كان قبل هذا ═══
 *
 * كانت فاتورةٌ يُرسل لها رابطٌ تخرج من «مبيعات اليوم» حتى يُدفع — بيعٌ اعتُرف
 * به في الدفتر يومَ صدوره يُعرض يومَ قبضه، فرابطٌ يُنشأ مساءً يُغيّر بيعَ
 * الصباح. وقرّر صاحبُ المنتج: الفاتورةُ الصادرةُ بيعٌ يومَ صدورها، والرابطُ
 * لا يصنع بيعًا ولا يغيّر يومَه، والقبضُ رقمٌ مستقلّ.
 */
class TheDashboardTellsSoldReceivedOwedAndAwaitedApartTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $muscat;

    private Branch $sohar;

    private int $paymobOrder = 0;

    private int $tx = 7100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Muscat'));
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('ورود اللوحة', 'owner@four-cards.om', 'hmac-shop');
        [$this->other] = $this->business('متجر الجار', 'other@four-cards.om', 'hmac-other');
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => function () {
                $n = ++$this->paymobOrder;

                return Http::response([
                    'id' => 680000 + $n, 'shorten_url' => 'https://paymob.link/c'.$n,
                    'created_at' => '2026-10-27T10:00:00', 'expires_at' => '2026-11-20T23:59:00',
                    'state' => 'created', 'order' => 727000000 + $n,
                ]);
            },
        ]);
        Http::preventStrayRequests();

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function business(string $name, string $email, string $hmac): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => $hmac,
            'card_integration_id' => '111', 'api_key' => 'api_'.$shop->id, 'mode' => PaymentGateway::LIVE,
        ]);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'صاحب '.$name, 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner];
    }

    private function invoice(float $total, ?string $issuedAt = null, ?Branch $branch = null, ?Business $shop = null, ?Customer $customer = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $draft = CustomerInvoices::create($shop->id, $customer ?? $this->customer, ['issued_at' => $issuedAt ?? now()->toDateString(), 'branch_id' => $branch?->id], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $total, 'tax_rate' => 0],
        ], $this->owner->id);

        return CustomerInvoices::issue($draft, $this->owner->id)->fresh();
    }

    private function link(?CustomerInvoice $invoice, ?float $amount = null, ?Business $shop = null, ?Customer $customer = null): CustomerPaymentLink
    {
        $shop ??= $this->shop;

        return PaymentLinks::create($shop->id, $customer ?? $invoice?->customer ?? $this->customer, $invoice, $amount, null, $this->owner->id);
    }

    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        return array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'currency' => $link->currency, 'id' => ++$this->tx,
            'is_refunded' => false, 'is_voided' => false, 'pending' => false, 'success' => true,
            'is_live' => (bool) $link->is_live,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
        ], $over);
    }

    private function hook(array $obj, string $secret = 'hmac-shop'): void
    {
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, $secret), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function pay(CustomerPaymentLink $link, string $secret = 'hmac-shop'): CustomerPaymentLink
    {
        $this->hook($this->obj($link), $secret);

        return $link->fresh();
    }

    /** تحصيلٌ بيدٍ على فواتيرَ مسمّاة — بلا توزيعٍ على الأقدم */
    private function collect(float $amount, array $allocations, string $method = 'نقدي', ?string $on = null): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->customer, $amount,
            ['method' => $method, 'occurred_at' => $on ?? today()->toDateString(), 'auto_allocate' => false],
            $allocations, $this->owner->id);
    }

    private function order(float $total, string $channel = 'pos', string $payment = 'مدفوع', string $method = 'نقدي', ?Branch $branch = null, ?Customer $customer = null): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => ($branch ?? $this->muscat)->id, 'channel' => $channel,
            'customer_id' => $customer?->id, 'customer_name' => $customer?->name,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => $payment, 'is_held' => false,
            'payment_method' => $method, 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => now(),
        ]);
        Books::recordSale($order);

        return $order;
    }

    private function stats(?Branch $branch = null): array
    {
        session(['current_branch' => $branch?->id]);

        return Demo::adminStats();
    }

    private function card(string $label, ?Branch $branch = null): ?array
    {
        return collect($this->stats($branch))->firstWhere('label', $label);
    }

    private function says(string $label, float $amount, string $why = '', ?Branch $branch = null): void
    {
        $this->assertSame(Demo::money($amount), $this->card($label, $branch)['value'] ?? null, $why ?: $label);
    }

    private function hint(string $label, ?Branch $branch = null): string
    {
        return (string) ($this->card($label, $branch)['hint'] ?? '');
    }

    private function received(string $day, ?Branch $branch = null): array
    {
        return Receipts::between($this->shop->id, Carbon::parse($day), Carbon::parse($day)->addDay(), $branch?->id);
    }

    private function sold(string $day): float
    {
        return round((float) Order::where('business_id', $this->shop->id)->sold()->whereDate('ordered_at', $day)->sum('total')
            + StandaloneInvoiceSales::total($this->shop->id, Carbon::parse($day), Carbon::parse($day)->addDay()), 3);
    }

    private function books(): array
    {
        return [
            JournalEntry::where('business_id', $this->shop->id)->count(),
            (string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.business_id', $this->shop->id)->selectRaw('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) + COUNT(*) as v')->value('v'),
        ];
    }

    /* ═══════════ مثالُ القبول ═══════════ */

    public function test_the_owners_example_sells_150_and_receives_100_and_owes_from_the_invoices(): void
    {
        $old = $this->invoice(60, '2026-10-20');

        $this->order(50);                                   // بيعُ صندوقٍ مدفوع
        $this->invoice(100);                                // فاتورةٌ آجلةٌ صدرت اليوم
        $this->collect(30, [$old->id => 30]);               // تحصيلُ فاتورةٍ قديمة
        $this->pay($this->link(null, 20));                  // رابطٌ مستقلٌّ دُفع مقدّمًا

        $this->says('مبيعات اليوم', 150);
        $this->says('مقبوضات اليوم', 100);
        // من أرصدة الفواتير القائمة — لا «المبيعات ناقص المقبوضات» (50)
        $this->says('مستحقات العملاء', 130);
        $this->says('روابط بانتظار الدفع', 0);

        // والعشرون المقدّمة رصيدٌ للعميل يُقال بجوار المستحقات ولا يُطرح منها
        $this->assertStringContainsString(Demo::isolatedMoney(20), $this->hint('مستحقات العملاء'));
        $this->assertSame(20.0, Receivables::credit($this->shop->id));

        // والرقمُ هو رقمُ شاشة الذمم نفسُه
        $totals = Receivables::totals($this->shop->id);
        $this->assertSame($totals['total'], Receivables::outstanding($this->shop->id));
        $this->assertSame($totals['credit'], Receivables::credit($this->shop->id));

        // والبطاقاتُ الأربع أوّلًا، ثمّ ما كان
        $this->assertSame(
            ['مبيعات اليوم', 'مقبوضات اليوم', 'مستحقات العملاء', 'روابط بانتظار الدفع', 'مبيعات الشهر', 'عدد الطلبات', 'متوسط قيمة الطلب', 'عدد العملاء', 'منتجات منخفضة المخزون', 'إجمالي المصروفات', 'صافي الأرباح'],
            array_column($this->stats(), 'label'),
        );

        // والملخّصُ اليوميّ والتنبيهُ يقولان المبيعات نفسَها
        $this->assertSame(150.0, (float) Demo::dailySummaryFor($this->shop->id, now())['sales']);
        $this->assertSame(150.0, round(AlertMetrics::value('daily_sales', $this->shop->id), 3));
    }

    /* ═══════════ الرابطُ ليس بيعًا ولا قبضًا — والفاتورةُ بيعُ يومِ صدورها ═══════════ */

    public function test_an_invoice_is_sold_when_issued_and_its_link_neither_sells_again_nor_receives_until_paid(): void
    {
        // تصدر صباحًا — بيعٌ اليوم
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', 'Asia/Muscat'));
        $invoice = $this->invoice(20);
        $this->says('مبيعات اليوم', 20);
        $books = $this->books();

        // ويُرسل رابطُها مساءً — لا يتغيّر تصنيفُ البيع ولا يُكتب في الدفتر شيء
        Carbon::setTestNow(Carbon::parse('2026-10-27 19:00:00', 'Asia/Muscat'));
        $link = $this->link($invoice);
        $this->says('مبيعات اليوم', 20, 'رابطُ المساء غيّر بيعَ الصباح');
        $this->says('مقبوضات اليوم', 0, 'رابطٌ معلّقٌ صار مالًا');
        $this->says('مستحقات العملاء', 20);
        $this->says('روابط بانتظار الدفع', 20);
        $this->assertSame($books, $this->books(), 'إرسالُ رابطٍ كتب في الدفتر');

        // يُدفع — وإشعارُه يتكرّر، وعمليّةٌ ثانيةٌ تصل على الرابط المدفوع
        $obj = $this->obj($link);
        $this->hook($obj);
        $this->hook($obj);
        $this->hook($this->obj($link));

        $this->says('مبيعات اليوم', 20, 'الدفعُ صار بيعًا ثانيًا');
        $this->says('مقبوضات اليوم', 20);
        $this->assertStringContainsString(__('منه عبر Paymob :a', ['a' => Demo::isolatedMoney(20)]), $this->hint('مقبوضات اليوم'));
        $this->says('مستحقات العملاء', 0);
        $this->says('روابط بانتظار الدفع', 0);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count(), 'تحصيلان لدفعةٍ واحدة');

        // والدفترُ عدّ الإيرادَ مرّةً — يومَ الصدور
        $this->assertSame(20.0, round(Ledger::netRevenue($this->shop->id, today(), today()->addDay()), 3));
    }

    public function test_monday_invoice_tuesday_link_wednesday_payment_is_a_monday_sale_and_a_wednesday_receipt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'Asia/Muscat'));
        $invoice = $this->invoice(30);
        $this->says('مبيعات اليوم', 30);

        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Muscat'));
        $link = $this->link($invoice);
        $this->assertSame(30.0, $this->sold('2026-10-26'), 'رابطُ الثلاثاء غيّر بيعَ الاثنين');

        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'Asia/Muscat'));
        $this->pay($link);

        $this->says('مبيعات اليوم', 0, 'سدادُ الأربعاء صار بيعًا ثانيًا');
        $this->says('مقبوضات اليوم', 30);
        $this->says('مبيعات الشهر', 30, 'الشهرُ عدّ الفاتورةَ مرّتين');
        $this->assertSame(30.0, $this->sold('2026-10-26'));
        $this->assertSame(30.0, (float) Demo::dailySummaryFor($this->shop->id, Carbon::parse('2026-10-26 12:00:00'))['sales']);
        $this->assertSame(0.0, $this->received('2026-10-26')['total']);
        $this->assertSame(30.0, round(Ledger::netRevenue($this->shop->id, Carbon::parse('2026-10-26'), Carbon::parse('2026-10-27')), 3));
    }

    public function test_a_backdated_invoice_sells_on_its_document_date_and_receives_on_the_payment_day(): void
    {
        $link = $this->link($this->invoice(25, '2026-10-20'));

        $this->says('مبيعات اليوم', 0, 'فاتورةٌ بتاريخٍ مضى عُدّت اليوم');
        $this->assertSame(25.0, $this->sold('2026-10-20'));
        $this->says('مبيعات الشهر', 25);

        $this->pay($link);
        $this->says('مبيعات اليوم', 0);
        $this->says('مقبوضات اليوم', 25);
        $this->assertSame(0.0, $this->received('2026-10-20')['total']);
    }

    public function test_a_partial_payment_and_the_rest_another_day_receive_on_their_own_days_and_sell_once(): void
    {
        $invoice = $this->invoice(30);
        $this->pay($this->link($invoice, 10));
        $this->says('مقبوضات اليوم', 10);
        $this->says('مستحقات العملاء', 20);

        Carbon::setTestNow(Carbon::parse('2026-10-28 11:00:00', 'Asia/Muscat'));
        $rest = $this->link($invoice->fresh());
        $this->says('روابط بانتظار الدفع', 20);
        $this->pay($rest);

        $this->says('مقبوضات اليوم', 20);
        $this->says('مبيعات اليوم', 0);
        $this->says('مستحقات العملاء', 0);
        $this->assertSame(30.0, $this->sold('2026-10-27'));
        $this->assertSame(10.0, $this->received('2026-10-27')['total']);
    }

    /* ═══════════ إشعارٌ دائن، واسترجاع، وشيك ═══════════ */

    public function test_a_credit_note_after_payment_lowers_its_own_days_sales_and_never_makes_a_debt_negative(): void
    {
        $invoice = $this->invoice(40);
        $this->collect(40, [$invoice->id => 40]);

        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'Asia/Muscat'));
        CustomerInvoices::creditNote($invoice->fresh(), 10, 0, 'مردود', $this->owner->id);

        $this->says('مبيعات اليوم', -10, 'الإشعارُ لم يُنقص يومَه');
        $this->assertSame(40.0, $this->sold('2026-10-27'), 'الإشعارُ غيّر يومَ البيع');
        $this->says('مقبوضات اليوم', 0, 'إشعارٌ دائنٌ صار مالًا خارجًا');
        $this->says('مستحقات العملاء', 0, 'فاتورةٌ مسدّدةٌ صارت دينًا سالبًا');

        // وحقُّ العميل بالعشرة رصيدٌ دائنٌ له — كما قيّده الإشعارُ في الدفتر
        $this->assertSame(10.0, Receivables::credit($this->shop->id));
        $this->assertSame(10.0, Receivables::customerCredit($this->shop->id, $this->customer->id));
        $this->assertSame(10.0, Receivables::totals($this->shop->id)['credit']);
        $this->assertStringContainsString(__('رصيدٌ دائن للعملاء :a لا يُطرح منها', ['a' => Demo::isolatedMoney(10)]), $this->hint('مستحقات العملاء'));
        $reconcile = Receivables::reconcile($this->shop->id);
        $this->assertTrue($reconcile['balanced'], 'الذممُ تفترق عن الدفتر بفارق '.$reconcile['difference']);
        $this->assertSame(-10.0, $reconcile['ledger']);

        // وتحصيلٌ أُلغي بعدها يُعيد الحسابَ كما يقوله الدفتر: عليه ثلاثون، ولا رصيدَ له
        CustomerPayments::cancel(CustomerPayment::where('business_id', $this->shop->id)->firstOrFail(), 'خطأ', $this->owner->id);
        $this->says('مستحقات العملاء', 30);
        $this->assertSame(0.0, Receivables::credit($this->shop->id));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
    }

    public function test_a_bounced_cheque_left_unallocated_is_no_credit_for_the_customer(): void
    {
        $cheque = $this->collect(30, [], Cheques::METHOD);
        // تحت التحصيل: الذمّةُ انتقلت إلى الورقة — رصيدٌ للعميل كما يقوله الدفتر
        $this->assertSame(30.0, Receivables::credit($this->shop->id));

        Cheques::bounce($cheque, 'رصيد غير كافٍ', $this->owner->id);

        $this->assertSame(0.0, Receivables::credit($this->shop->id), 'شيكٌ مرتجعٌ بقي رصيدًا للعميل');
        $this->assertSame(0.0, Receivables::customerCredit($this->shop->id, $this->customer->id));
        $this->assertTrue(Receivables::reconcile($this->shop->id)['balanced']);
        $this->says('مقبوضات اليوم', 0);
    }

    public function test_a_refund_paymob_only_flags_is_said_without_an_amount_and_its_reversal_reads_on_its_own_day(): void
    {
        $invoice = $this->invoice(20);
        $link = $this->pay($this->link($invoice));
        $books = $this->books();

        // الدفعةُ الأصل صارت «مستردّة» — ولا مبلغَ موقَّعًا يقول كم
        $this->hook($this->obj($link, ['id' => (int) $link->provider_transaction_id, 'is_refunded' => true]));

        $this->says('مقبوضات اليوم', 20, 'عُكس المالُ تلقائيًّا');
        $this->assertSame($books, $this->books(), 'كُتب في الدفتر بلا يد');
        $this->assertStringContainsString(__('استرجاعٌ من Paymob ينتظر التسوية: :n بمبلغٍ يُراجَع في Paymob', ['n' => 1]), $this->hint('مقبوضات اليوم'));
        $this->assertSame(['amount' => 0.0, 'cases' => 1, 'unknown' => 1], PaymobRefunds::awaiting($this->shop->id));
        $this->assertContains(__('أبلغت Paymob باسترجاعٍ من دفعة :name عبر رابط الدفع — المبلغ يُراجَع في Paymob', ['name' => $this->customer->name]),
            array_column(Demo::notifications(), 'text'));

        // ويُلغى التحصيلُ غدًا — ردٌّ في يومه، ويومُ القبض كما قُرئ، والتنبيهُ يزول
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'Asia/Muscat'));
        CustomerPayments::cancel(CustomerPayment::findOrFail($link->customer_payment_id), 'استرجاع عبر Paymob', $this->owner->id);

        $this->says('مقبوضات اليوم', -20);
        $this->assertStringContainsString(__('بعد ردّ :a', ['a' => Demo::isolatedMoney(20)]), $this->hint('مقبوضات اليوم'));
        $this->assertSame(['amount' => 0.0, 'cases' => 0, 'unknown' => 0], PaymobRefunds::awaiting($this->shop->id));
        $this->assertSame(20.0, $this->received('2026-10-27')['total'], 'الإلغاءُ أعاد كتابةَ يومٍ مضى');
        $this->says('مستحقات العملاء', 20);
    }

    public function test_full_and_partial_paymob_refunds_are_read_from_their_signed_amount_once_and_never_reverse_anything(): void
    {
        $full = $this->pay($this->link($this->invoice(20)));
        $part = $this->pay($this->link(null, 30));
        $books = $this->books();
        $refund = fn (CustomerPaymentLink $l, float $amount, int $id) => $this->obj($l, [
            'id' => $id, 'has_parent_transaction' => true, 'amount_cents' => Amount::toMinor($amount, 'OMR'),
        ]);

        // ردٌّ كاملٌ وإشعارُه مكرّر، وردٌّ جزئيٌّ بخمسةٍ من ثلاثين
        $this->hook($refund($full, 20, 990001));
        $this->hook($refund($full, 20, 990001));
        $this->hook($refund($part, 5, 990002));

        // لا «دفعةٌ ثانية» ولا «المبلغُ لا يطابق» — ولا قيدٌ ولا تحصيلٌ جديد
        foreach ([$full, $part] as $l) {
            $meta = (array) $l->fresh()->meta;
            $this->assertArrayNotHasKey('extra_transactions', $meta, 'ردٌّ قُرئ دفعةً ثانية');
            $this->assertNull($l->fresh()->error, 'ردٌّ قُرئ مبلغًا لا يطابق');
        }
        $this->assertSame($books, $this->books(), 'الردُّ عكس المالَ بلا يد');
        $this->assertSame(2, CustomerPayment::where('business_id', $this->shop->id)->count());

        // والمبلغُ ما وُقّع: خمسةٌ وعشرون، لا خمسون
        $this->assertSame(['amount' => 25.0, 'cases' => 2, 'unknown' => 0], PaymobRefunds::awaiting($this->shop->id));
        $this->says('مقبوضات اليوم', 50);
        $this->assertStringContainsString(__('استرجاعٌ من Paymob ينتظر التسوية :a', ['a' => Demo::isolatedMoney(25)]), $this->hint('مقبوضات اليوم'));

        $texts = array_column(Demo::notifications(), 'text');
        $this->assertContains(__('أبلغت Paymob بردّ :amount كاملًا من دفعة :name عبر رابط الدفع — التحصيل :payment قائمٌ حتى يُسوّى', [
            'amount' => Demo::money(20), 'name' => $this->customer->name, 'payment' => $full->fresh()->payment->number,
        ]), $texts);
        $this->assertContains(__('أبلغت Paymob بردّ :amount من :total دفعها :name عبر رابط الدفع — التحصيل :payment قائمٌ حتى يُسوّى', [
            'amount' => Demo::money(5), 'total' => Demo::money(30), 'name' => $this->customer->name, 'payment' => $part->fresh()->payment->number,
        ]), $texts);

        // وردٌّ ثانٍ على الجزئيّ يُجمع ولا يتجاوز الدفعة
        $this->hook($refund($part, 40, 990003));
        $this->assertSame(50.0, PaymobRefunds::awaiting($this->shop->id)['amount']);

        // والإلغاءُ قبل التسوية يردّ الدفعةَ كلَّها
        $void = $this->pay($this->link(null, 7));
        $this->hook($this->obj($void, ['id' => (int) $void->provider_transaction_id, 'is_voided' => true]));
        $this->assertSame(7.0, PaymobRefunds::refunded(7, 'OMR', (array) $void->fresh()->meta));
    }

    public function test_a_child_notice_on_an_unpaid_link_is_not_a_refund_and_the_first_payment_still_settles(): void
    {
        $link = $this->link($this->invoice(20));
        $this->hook($this->obj($link, ['id' => 990010, 'has_parent_transaction' => true]));

        // رابطٌ لم يُدفع لا يُسأل عن ردّ — والإشعارُ يمضي كما كان
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertArrayNotHasKey('provider_refunds', (array) $link->fresh()->meta);
        $this->says('مقبوضات اليوم', 20);
        $this->assertSame(0, PaymobRefunds::awaiting($this->shop->id)['cases']);
    }

    public function test_a_website_order_whose_payment_was_refunded_waits_for_settlement_until_it_is_cancelled(): void
    {
        $order = $this->order(25, 'website', 'مدفوع', 'بطاقة');
        StorePaymentIntent::create([
            'business_id' => $this->shop->id, 'order_id' => $order->id, 'reference' => 'web-refunded-1',
            'amount' => 25, 'currency' => 'OMR', 'status' => StorePaymentIntent::PAID, 'payload' => [],
            'refund_status' => StorePaymentIntent::REFUND_SENT, 'refunded_at' => now(),
        ]);

        $this->says('مقبوضات اليوم', 25);
        $this->assertSame(['amount' => 0.0, 'cases' => 1, 'unknown' => 1], PaymobRefunds::awaiting($this->shop->id));
        $this->assertSame(1, PaymobRefunds::awaiting($this->shop->id, $this->muscat->id)['cases']);
        $this->assertSame(0, PaymobRefunds::awaiting($this->shop->id, $this->sohar->id)['cases']);

        $order->update(['status' => Order::CANCELLED]);
        $this->assertSame(0, PaymobRefunds::awaiting($this->shop->id)['cases']);
    }

    public function test_a_cheque_is_received_the_day_it_clears_and_never_when_it_bounces(): void
    {
        $first = $this->invoice(50);
        $second = $this->invoice(30);
        $cleared = $this->collect(50, [$first->id => 50], Cheques::METHOD);
        $bounced = $this->collect(30, [$second->id => 30], Cheques::METHOD);

        $this->says('مقبوضات اليوم', 0, 'شيكٌ تحت التحصيل صار مالًا');

        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00', 'Asia/Muscat'));
        Cheques::clear($cleared, '2026-10-29', null, $this->owner->id);
        Cheques::bounce($bounced, 'رصيد غير كافٍ', $this->owner->id);

        $this->says('مقبوضات اليوم', 50);
        $this->assertSame(0.0, $this->received('2026-10-27')['total']);
        // والمرتجعُ عاد دَينًا على صاحبه
        $this->says('مستحقات العملاء', 30);
    }

    /* ═══════════ زيادةٌ وإلغاءات ═══════════ */

    public function test_paying_more_than_is_owed_receives_it_all_and_keeps_the_rest_as_credit(): void
    {
        $invoice = $this->invoice(30);
        $this->collect(50, [$invoice->id => 50]);

        $this->says('مقبوضات اليوم', 50);
        $this->says('مستحقات العملاء', 0);
        $this->assertSame(20.0, Receivables::credit($this->shop->id));
        $this->assertSame(Receivables::totals($this->shop->id)['credit'], Receivables::credit($this->shop->id));
        $this->assertStringContainsString(__('رصيدٌ دائن للعملاء :a لا يُطرح منها', ['a' => Demo::isolatedMoney(20)]), $this->hint('مستحقات العملاء'));
    }

    public function test_a_cancelled_invoice_owes_nothing_and_a_cancelled_payment_is_returned_on_its_day(): void
    {
        CustomerInvoices::cancel($this->invoice(15), 'خطأ', $this->owner->id);
        $paid = $this->invoice(12);
        $payment = $this->collect(12, [$paid->id => 12]);
        CustomerPayments::cancel($payment, 'سُجّل خطأ', $this->owner->id);

        $this->says('مبيعات اليوم', 12, 'الملغاةُ بقيت بيعًا');
        $this->says('مقبوضات اليوم', 0);
        $this->assertSame(12.0, $this->received('2026-10-27')['out']);
        $this->says('مستحقات العملاء', 12);
    }

    /* ═══════════ أبوابُ المال كلُّها — وما ليس مالًا من عميل ═══════════ */

    public function test_every_door_money_comes_through_counts_once_and_internal_moves_count_never(): void
    {
        $card = $this->order(40, 'pos', 'مدفوع', 'بطاقة');                       // بطاقةُ الصندوق ← البنك
        $this->order(25, 'website', 'مدفوع', 'بطاقة');                           // الموقعُ عبر Paymob
        $this->order(15, 'website', 'غير مدفوع', 'نقدي', null, $this->customer); // عند الاستلام

        $this->says('مبيعات اليوم', 80);
        $this->says('مقبوضات اليوم', 65);
        $this->assertSame(25.0, $this->received('2026-10-27')['paymob']);
        $this->says('مستحقات العملاء', 15);

        // تسويةُ Paymob إلى البنك، ومصروفٌ من الدرج، ورأسُ مالٍ يدخل — لا شيءَ منها قبضٌ من عميل
        Ledger::post($this->shop->id, 'تسوية Paymob', [
            ['account' => 'bank', 'debit' => 24], ['account' => 'payment_gateway_fees', 'debit' => 1],
            ['account' => 'paymob_clearing', 'credit' => 25],
        ], now(), 'تسوية Paymob');
        Ledger::post($this->shop->id, 'كهرباء', [['account' => 'utilities', 'debit' => 5], ['account' => 'cash', 'credit' => 5]], now());
        Ledger::post($this->shop->id, 'إيداع المالك', [['account' => 'cash', 'debit' => 100], ['account' => 'capital', 'credit' => 100]], now());
        $this->says('مقبوضات اليوم', 65, 'حركةٌ داخليّةٌ صارت قبضًا');

        // وبيعةٌ رُدّت غدًا تُقرأ ردًّا غدًا
        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'Asia/Muscat'));
        Books::unpostSale($card, $this->owner->id, 'إلغاء');
        $card->update(['status' => Order::CANCELLED]);
        $this->says('مقبوضات اليوم', -40);
        $this->assertSame(65.0, $this->received('2026-10-27')['total']);
    }

    public function test_a_test_mode_link_is_never_money_and_never_awaited(): void
    {
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::TEST]);
        $invoice = $this->invoice(20);
        $link = $this->link($invoice);
        $this->assertFalse((bool) $link->is_live);

        $this->says('روابط بانتظار الدفع', 0);
        $this->pay($link);

        $this->says('مبيعات اليوم', 20);
        $this->says('مقبوضات اليوم', 0, 'دفعةُ تجربةٍ صارت مالًا');
        $this->says('مستحقات العملاء', 20);
    }

    /* ═══════════ الروابطُ المنتظرة ═══════════ */

    public function test_awaiting_links_count_each_invoice_once_never_above_its_balance_and_only_live_valid_links(): void
    {
        $invoice = $this->invoice(30);
        $first = $this->link($invoice);
        [$again, $existing] = PaymentLinks::forInvoice($invoice, $this->owner->id);
        $this->assertTrue($existing);
        $this->assertSame($first->id, $again->id);

        // سُدّد بعضُها نقدًا بعد الرابط: الرابطُ يطلب ثلاثين والباقي عشرون
        $this->collect(10, [$invoice->id => 10]);

        $this->link(null, 12.5);                                                 // دفعةٌ مقدّمةٌ تُطلب
        $expired = $this->link($this->invoice(8));
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();      // انقضت ولم يمرّ عليها `expireDue`
        PaymentLinks::cancel($this->link($this->invoice(9)), $this->owner->id);
        CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'customer_invoice_id' => $this->invoice(6)->id,
            'reference' => 'pay-failed-card', 'amount' => 6, 'currency' => 'OMR', 'status' => CustomerPaymentLink::FAILED, 'is_live' => true,
        ]);
        CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'customer_invoice_id' => $this->invoice(7)->id,
            'reference' => 'pay-test-card', 'amount' => 7, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PENDING, 'is_live' => false,
        ]);

        $wait = PaymentLinks::awaiting($this->shop->id);
        $this->assertSame(['amount' => 32.5, 'count' => 2, 'capped' => 10.0, 'unattributed' => 32.5], $wait);
        $this->says('روابط بانتظار الدفع', 32.5);
        $this->assertStringContainsString(__('لم يُعدّ :a فوق باقي فواتيرها', ['a' => Demo::isolatedMoney(10)]), $this->hint('روابط بانتظار الدفع'));

        // ومؤشّرٌ لا دَين: المستحقاتُ باقي الفواتير وحده، لا يُضاف إليه ما يُطلب
        $this->says('مستحقات العملاء', 20 + 8 + 9 + 6 + 7);
        $this->says('مقبوضات اليوم', 10);
    }

    /* ═══════════ الفروعُ ومتجران ═══════════ */

    public function test_branches_split_receipts_by_where_they_belong_and_add_up_to_the_business_with_the_unattributed_said(): void
    {
        $this->order(50, branch: $this->muscat);                             // مسقط: بيعُ صندوق
        $soharInvoice = $this->invoice(40, branch: $this->sohar);
        $this->pay($this->link($soharInvoice));                              // صحار: رابطُ فاتورتها
        $m = $this->invoice(10, branch: $this->muscat);
        $s = $this->invoice(15, branch: $this->sohar);
        $this->collect(25, [$m->id => 10, $s->id => 15]);                    // دفعةٌ واحدةٌ على فرعين
        $this->pay($this->link(null, 20));                                   // مقدّمةٌ بلا فاتورة
        $free = $this->invoice(30);
        $this->collect(30, [$free->id => 30]);                               // فاتورةٌ بلا فرع

        $all = $this->received('2026-10-27');
        $muscat = $this->received('2026-10-27', $this->muscat)['total'];
        $sohar = $this->received('2026-10-27', $this->sohar)['total'];

        $this->assertSame(60.0, $muscat);
        $this->assertSame(55.0, $sohar);
        $this->assertSame(165.0, $all['total']);
        $this->assertSame(50.0, $all['unattributed']);
        $this->assertSame($all['total'], round($muscat + $sohar + $all['unattributed'], 3), 'الفروعُ وغيرُ المنسوب لا تساوي النشاط');

        $this->says('مقبوضات اليوم', 60, branch: $this->muscat);
        $this->assertStringContainsString(__('غير منسوب لفرع :a', ['a' => Demo::isolatedMoney(50)]), $this->hint('مقبوضات اليوم'));
        $this->assertStringNotContainsString(__('غير منسوب لفرع :a', ['a' => Demo::isolatedMoney(50)]), $this->hint('مقبوضات اليوم', $this->muscat));

        // والمستحقاتُ والروابطُ بفرع الفاتورة
        $this->invoice(70, branch: $this->muscat);
        $this->link($this->invoice(11, branch: $this->sohar));
        $this->says('مستحقات العملاء', 70, branch: $this->muscat);
        $this->says('مستحقات العملاء', 11, branch: $this->sohar);
        $this->says('روابط بانتظار الدفع', 0, branch: $this->muscat);
        $this->says('روابط بانتظار الدفع', 11, branch: $this->sohar);
        $this->says('مستحقات العملاء', 81);
    }

    public function test_a_shop_never_reads_another_shops_money_and_one_without_paymob_sees_no_links_card(): void
    {
        $theirCustomer = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '93333333', 'allow_credit_sales' => true]);
        $theirs = $this->invoice(40, shop: $this->other, customer: $theirCustomer);
        $this->pay($this->link($theirs, shop: $this->other, customer: $theirCustomer), 'hmac-other');
        $this->link($this->invoice(44, shop: $this->other, customer: $theirCustomer), shop: $this->other, customer: $theirCustomer);

        $this->says('مبيعات اليوم', 0);
        $this->says('مقبوضات اليوم', 0, 'مالُ متجرٍ آخر في بطاقتي');
        $this->says('مستحقات العملاء', 0);
        $this->says('روابط بانتظار الدفع', 0);
        $this->assertSame(40.0, Receipts::between($this->other->id, today(), today()->addDay())['total']);

        PaymentGateway::where('business_id', $this->shop->id)->delete();
        $this->assertNull($this->card('روابط بانتظار الدفع'), 'متجرٌ بلا Paymob يُشغَل بصفرٍ دائم');
        $this->assertCount(10, $this->stats());
    }
}
