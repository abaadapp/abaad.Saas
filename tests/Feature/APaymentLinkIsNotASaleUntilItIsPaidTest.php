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
use App\Models\User;
use App\Support\AlertMetrics;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DashboardMetrics;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Lists\PaidLinksList;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\StandaloneInvoiceSales;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رابطُ الدفع طلبُ مالٍ لا بيع — لا يدخل «مبيعات اليوم» حتى يُدفع.
 *
 * ═══ ما كان ═══
 *
 * «أصدر الفاتورة وأنشئ رابط دفع» ضغطةٌ واحدة، و«مبيعات اليوم» تعدّ الفاتورةَ
 * المستقلّة بإجماليّها يومَ صدورها (`StandaloneInvoiceSales`). فرابطٌ بعشرين
 * يُرسَل ولم يُدفع يرفع البطاقةَ عشرين — والتاجرُ يقرأ مالًا لم يصل.
 *
 * ═══ ما صار ═══
 *
 * الفاتورةُ التي فُتح عليها رابطٌ حقيقيٌّ تُعدّ في البطاقة **بما سُدّد منها،
 * يومَ سداده**. والدفترُ كما هو: إيرادُها وضريبتُها يومَ صدورها، و«صافي
 * الأرباح» والتقاريرُ والإقرارُ تقرؤها هناك. وتحت البطاقة سطرٌ يقول ما حُصّل
 * عبر الروابط اليوم وما ينتظر.
 *
 * ═══ ما يُحرس — بأرقام بنود الموجز ═══
 *
 *   1. معلّقٌ لا يدخل المبيعاتِ ولا التحصيل.   2. مدفوعٌ يظهر مرّةً.
 *   3. أُنشئ أمس ودُفع اليوم (و18: يومُ مسقط).  4. فاتورةٌ غيرُ مدفوعة تبقى مستحقّة.
 *   5. جزئيّ.  6. مستقلٌّ بلا فاتورة.  7. فاشل.  8. منتهٍ.  9. ملغى.
 *   10. إشعارٌ مكرّر وعمليّةٌ ثانية.  11. المتزامن في `TwoPaymobNoticesAtOnceSellOnceTest`.
 *   12. استرجاع.  13. المبيعاتُ غيرُ المقبوضات.  14. الدفترُ والضريبةُ كما هما.
 *   15. الصندوقُ والموقع.  16. متجران وفرعان.  17. البطاقةُ والملخّصُ والتنبيهُ
 *   والمخطّطُ وشاشةُ المبيعات.  19. التجربةُ ليست مالًا.  20. الفاتورةُ بلا رابطٍ كما كانت.
 */
class APaymentLinkIsNotASaleUntilItIsPaidTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $muscat;

    private Branch $sohar;

    private int $paymobOrder = 0;

    private int $tx = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Asia/Muscat'));
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('ورود الروابط', 'owner@link-sales.om', 'hmac-shop');
        [$this->other] = $this->business('متجر الجار', 'other@link-sales.om', 'hmac-other');
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => function () {
                $n = ++$this->paymobOrder;

                return Http::response([
                    'id' => 590000 + $n, 'shorten_url' => 'https://paymob.link/s'.$n,
                    'created_at' => '2026-10-27T10:00:00', 'expires_at' => '2026-11-03T23:59:00',
                    'state' => 'created', 'order' => 626000000 + $n,
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

    private function business(string $name, string $email, string $hmac, string $mode = PaymentGateway::LIVE): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => $hmac,
            'card_integration_id' => '111', 'api_key' => 'api_'.$shop->id, 'mode' => $mode,
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

    private function stat(string $label): array
    {
        return collect(Demo::adminStats())->firstWhere('label', $label);
    }

    private function assertToday(float $amount, string $why = ''): void
    {
        $this->assertSame(Demo::money($amount), $this->stat('مبيعات اليوم')['value'], $why ?: 'مبيعات اليوم');
    }

    private function day(string $date): float
    {
        return StandaloneInvoiceSales::total($this->shop->id, Carbon::parse($date), Carbon::parse($date)->addDay());
    }

    private function hint(): ?string
    {
        return $this->stat('مبيعات اليوم')['hint'] ?? null;
    }

    private function hintSays(float $collected, float $awaiting): string
    {
        return __('روابط الدفع: حُصّل اليوم :a · بانتظار الدفع :b', ['a' => Demo::money($collected), 'b' => Demo::money($awaiting)]);
    }

    /** الدفترُ كلُّه — ما لا يجوز أن يمسّه رابطٌ يُرسَل */
    private function books(): array
    {
        return [
            JournalEntry::where('business_id', $this->shop->id)->count(),
            (string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.business_id', $this->shop->id)->selectRaw('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) + COUNT(*) as v')->value('v'),
        ];
    }

    /* ═══════════ ١ و٤ و١٣ و١٤ — معلّقٌ لا يُعدّ، والفاتورةُ مستحقّة، والدفترُ كما هو ═══════════ */

    public function test_an_unpaid_link_adds_nothing_to_todays_sales_or_collections_and_writes_nothing(): void
    {
        $invoice = $this->invoice(20);
        // فاتورةٌ بلا رابطٍ كما كانت: بيعُ يومِ صدورها
        $this->assertToday(20, 'فاتورةٌ بلا رابطٍ خرجت من البطاقة');

        $books = $this->books();
        $tax = Ledger::outputTax($this->shop->id, today(), today()->addDay());
        $revenue = Ledger::netRevenue($this->shop->id, today(), today()->addDay());

        $link = $this->link($invoice);
        $this->assertSame(CustomerPaymentLink::PENDING, $link->status);

        $this->assertToday(0, 'رابطٌ معلّقٌ دخل «مبيعات اليوم»');
        $this->assertSame($this->hintSays(0, 20), $this->hint());
        $this->assertSame(0.0, PaidLinksList::collected($this->shop->id, today(), today()->addDay()));
        $this->assertSame(20.0, PaidLinksList::awaiting($this->shop->id));

        // لا تحصيلَ ولا سندَ ولا قيدَ — والدفترُ والضريبةُ كما كانا
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertSame($books, $this->books(), 'إرسالُ رابطٍ كتب في الدفتر');
        $this->assertSame($tax, Ledger::outputTax($this->shop->id, today(), today()->addDay()));
        $this->assertSame($revenue, Ledger::netRevenue($this->shop->id, today(), today()->addDay()));

        // والفاتورةُ ومستحقُّها محفوظان في حساب العميل
        $this->assertSame(20.0, $invoice->fresh()->outstanding());
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);

        // وشاشةُ المبيعات لا ترى المعلّق
        $this->get(route('admin.orders.index', ['channel' => 'payment_link']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('pagination.total', 0));
    }

    /* ═══════════ ٢ و١٠ و١٣ — مدفوعٌ مرّةً واحدة ═══════════ */

    public function test_a_paid_link_shows_once_however_often_paymob_repeats_and_a_second_transaction_is_not_a_second_sale(): void
    {
        $invoice = $this->invoice(20);
        $link = $this->link($invoice);
        $obj = $this->obj($link);

        $this->hook($obj);
        $this->hook($obj);
        $this->hook($obj);

        $link->refresh();
        $this->assertSame(CustomerPaymentLink::PAID, $link->status);
        $this->assertSame((string) $obj['id'], $link->provider_transaction_id);
        $this->assertSame('2026-10-27', $link->paid_at->toDateString());

        $this->assertToday(20);
        $this->assertSame($this->hintSays(20, 0), $this->hint());
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count(), 'تحصيلان لدفعةٍ واحدة');
        $this->assertSame(0.0, $invoice->fresh()->outstanding());

        // وعمليّةٌ أخرى على رابطٍ مدفوع تُحفظ للمراجعة — لا بيعٌ ثانٍ
        $this->hook($this->obj($link));
        $this->assertToday(20, 'عمليّةٌ ثانيةٌ كرّرت البيع');
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());

        // والمبيعاتُ غيرُ المقبوضات: الدفترُ عدّها يومَ صدرت، والبطاقةُ يومَ دُفعت — وكلاهما عشرون
        $this->assertSame(20.0, round(Ledger::netRevenue($this->shop->id, today(), today()->addDay()), 3));
    }

    /* ═══════════ ٣ و١٨ — أمس معلّق، واليوم مدفوع، بيوم مسقط ═══════════ */

    public function test_created_yesterday_and_paid_today_counts_today_by_muscat_day(): void
    {
        $this->assertSame('Asia/Muscat', config('app.timezone'));

        Carbon::setTestNow(Carbon::parse('2026-10-27 23:30:00', 'Asia/Muscat'));
        $link = $this->link($this->invoice(15));
        $this->assertToday(0);

        // ٠٠:٣٠ بتوقيت مسقط يومَ ٢٨ — وهو ٢٠:٣٠ يومَ ٢٧ بالتوقيت العالميّ
        Carbon::setTestNow(Carbon::parse('2026-10-28 00:30:00', 'Asia/Muscat'));
        $this->assertSame('2026-10-27', now()->utc()->toDateString());
        $link = $this->pay($link);

        $this->assertSame('2026-10-28', $link->paid_at->toDateString());
        $this->assertSame('2026-10-28', CustomerPayment::find($link->customer_payment_id)->occurred_at->toDateString());
        $this->assertSame(0.0, $this->day('2026-10-27'), 'عُدّ يومَ أُنشئ الرابط');
        $this->assertSame(15.0, $this->day('2026-10-28'));
        $this->assertToday(15);
        $this->assertSame($this->hintSays(15, 0), $this->hint());

        // والفاتورةُ في الدفتر يومَ صدورها كما كانت
        $this->assertSame(15.0, round(Ledger::netRevenue($this->shop->id, Carbon::parse('2026-10-27'), Carbon::parse('2026-10-28')), 3));
    }

    /* ═══════════ ٥ — جزئيّ ثمّ الباقي ═══════════ */

    public function test_a_partial_payment_counts_what_was_paid_and_the_rest_on_its_own_day(): void
    {
        $invoice = $this->invoice(30);
        $first = $this->pay($this->link($invoice, 10));

        $this->assertSame(CustomerPaymentLink::PAID, $first->status);
        $this->assertToday(10, 'الجزئيُّ عُدّ كاملًا');
        $this->assertSame(20.0, $invoice->fresh()->outstanding());

        Carbon::setTestNow(Carbon::parse('2026-10-29 11:00:00', 'Asia/Muscat'));
        $second = $this->link($invoice->fresh());
        $this->assertSame(20.0, (float) $second->amount);
        $this->assertToday(0);
        $this->pay($second);

        $this->assertToday(20);
        $this->assertSame(10.0, $this->day('2026-10-27'));
        $this->assertSame(0.0, $this->day('2026-10-28'));
        $this->assertSame(30.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')), 'الشهرُ لا يزيد على الفاتورة');
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
    }

    /* ═══════════ ٦ — رابطٌ مستقلٌّ بلا فاتورة ═══════════ */

    public function test_a_standalone_link_collects_without_becoming_a_sale_or_an_invoice(): void
    {
        $link = $this->link(null, 12.5);
        $this->assertToday(0);
        $this->assertSame($this->hintSays(0, 12.5), $this->hint());

        $this->pay($link);

        $this->assertToday(0, 'رابطٌ مستقلٌّ صار بيعًا');
        $this->assertSame($this->hintSays(12.5, 0), $this->hint());
        $this->assertSame(0, CustomerInvoice::where('business_id', $this->shop->id)->count(), 'أُنشئت فاتورةٌ لرابطٍ مستقلّ');
        $this->assertSame(0.0, round(Ledger::netRevenue($this->shop->id, today(), today()->addDay()), 3), 'إيرادٌ وهميّ');
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'طلبٌ لرابط');
    }

    /* ═══════════ ٧ و٨ و٩ — فاشلٌ ومنتهٍ وملغى ═══════════ */

    public function test_a_failed_an_expired_and_a_cancelled_link_collect_nothing(): void
    {
        $failed = $this->link($this->invoice(7));
        $this->hook($this->obj($failed, ['success' => false]));
        $this->assertSame(CustomerPaymentLink::PENDING, $failed->fresh()->status);

        $expired = $this->link($this->invoice(8));
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        PaymentLinks::expireDue($this->shop->id);
        $this->assertSame(CustomerPaymentLink::EXPIRED, $expired->fresh()->status);

        $cancelledInvoice = $this->invoice(9);
        $cancelled = PaymentLinks::cancel($this->link($cancelledInvoice), $this->owner->id);
        $this->assertSame(CustomerPaymentLink::CANCELLED, $cancelled->status);

        // ولا مبلغَ منها في المبيعات ولا في المقبوض — والمعلّقُ وحده ينتظر
        $this->assertToday(0);
        $this->assertSame($this->hintSays(0, 7), $this->hint());
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count());

        // والفاتورةُ التي أُلغي رابطُها تُعدّ يومَ تُدفع بأيّ وسيلة — لا يومَ أُلغي
        CustomerPayments::record($this->shop->id, $this->customer, 9, ['method' => 'نقدي', 'occurred_at' => today()->toDateString()], [$cancelledInvoice->id => 9], $this->owner->id);
        $this->assertToday(9);
    }

    /* ═══════════ ١٠ — رابطان على الفاتورة نفسِها ═══════════ */

    public function test_two_links_on_one_invoice_count_the_one_that_was_paid_once(): void
    {
        $invoice = $this->invoice(20);
        $first = $this->link($invoice);
        // ضغطةٌ ثانية أو إعادةُ تحميل: الرابطُ القائمُ نفسُه، لا رابطٌ ثانٍ
        [$again, $existing] = PaymentLinks::forInvoice($invoice, $this->owner->id);
        $this->assertTrue($existing);
        $this->assertSame($first->id, $again->id);

        PaymentLinks::cancel($first, $this->owner->id);
        $second = $this->link($invoice->fresh());
        $this->pay($second);

        $this->assertToday(20);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
    }

    /* ═══════════ ١٢ — استرجاع ═══════════ */

    public function test_a_refund_keeps_the_collection_until_it_is_reversed_and_then_takes_it_out(): void
    {
        $invoice = $this->invoice(20);
        $link = $this->pay($this->link($invoice));
        $this->assertToday(20);

        // Paymob تُبلغ بالاسترجاع: يُقال على الرابط، والتحصيلُ باقٍ حتّى يُعكس بيده
        $this->hook($this->obj($link, ['id' => (int) $link->provider_transaction_id, 'success' => true, 'is_refunded' => true]));
        $this->assertSame('refunded', ((array) $link->fresh()->meta)['provider_refund'] ?? null);
        $this->assertToday(20);

        CustomerPayments::cancel(CustomerPayment::findOrFail($link->customer_payment_id), 'استرجاع عبر Paymob', $this->owner->id);

        $this->assertToday(0, 'تحصيلٌ مُلغى بقي في المبيعات');
        $this->assertSame($this->hintSays(0, 0), $this->hint());
        $this->assertSame(20.0, $invoice->fresh()->outstanding());
    }

    /* ═══════════ ١٥ — الصندوقُ والموقع ═══════════ */

    public function test_pos_and_website_orders_count_as_before_beside_links(): void
    {
        $pos = $this->order(50, 'pos', 'مدفوع');
        $web = $this->order(30, 'website', 'غير مدفوع');
        $this->assertToday(80);

        $this->link($this->invoice(20));
        $this->assertToday(80, 'رابطٌ معلّقٌ غيّر الطلبات');

        $this->assertSame([50.0, 30.0], [(float) $pos->fresh()->total, (float) $web->fresh()->total]);
    }

    /* ═══════════ ١٦ — متجران وفرعان ═══════════ */

    public function test_shops_and_branches_never_read_each_others_links(): void
    {
        $theirCustomer = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'phone' => '93333333', 'allow_credit_sales' => true]);
        $theirs = $this->link($this->invoice(40, shop: $this->other, customer: $theirCustomer), shop: $this->other, customer: $theirCustomer);
        $this->pay($theirs, 'hmac-other');
        $this->link($this->invoice(44, shop: $this->other, customer: $theirCustomer), shop: $this->other, customer: $theirCustomer);

        $this->assertToday(0, 'تحصيلُ متجرٍ آخر في بطاقتي');
        $this->assertNull($this->hint(), 'متجري لم يفتح رابطًا — ولا سطرَ يُقرأ من غيره');
        $this->assertSame(40.0, StandaloneInvoiceSales::total($this->other->id, today(), today()->addDay()));

        // وفرعُ الفاتورة: مسقط تراها، وصحار لا
        $mine = $this->invoice(25, branch: $this->muscat);
        $this->pay($this->link($mine));
        $this->assertSame(25.0, StandaloneInvoiceSales::total($this->shop->id, today(), today()->addDay(), $this->muscat->id));
        $this->assertSame(0.0, StandaloneInvoiceSales::total($this->shop->id, today(), today()->addDay(), $this->sohar->id));
        $this->assertSame(0.0, PaidLinksList::collected($this->shop->id, today(), today()->addDay(), $this->sohar->id));
        $this->assertSame(25.0, PaidLinksList::collected($this->shop->id, today(), today()->addDay(), $this->muscat->id));
    }

    /* ═══════════ ١٧ — رقمٌ واحدٌ في كلّ مكان ═══════════ */

    public function test_the_card_the_month_the_chart_the_daily_summary_the_alert_and_the_sales_screen_agree(): void
    {
        $this->order(50, 'pos', 'مدفوع');
        $paid = $this->pay($this->link($this->invoice(20)));
        $this->link($this->invoice(35));

        $this->assertToday(70);
        $this->assertSame(Demo::money(70), $this->stat('مبيعات الشهر')['value']);
        $this->assertSame(70.0, (float) Demo::dailySummaryFor($this->shop->id, now())['sales']);
        $this->assertEquals(70, DashboardMetrics::salesYear()['data'][9]);
        $this->assertSame(70.0, round(AlertMetrics::value('daily_sales', $this->shop->id), 3));

        // وشاشةُ المبيعات: المدفوعُ وحده تحصيلًا، بمبلغه
        $this->get(route('admin.orders.index', ['channel' => 'payment_link']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('pagination.total', 1)->where('collections.0.link_id', $paid->id));
        $this->assertSame(20.0, PaidLinksList::collected($this->shop->id, today(), today()->addDay()));
    }

    /* ═══════════ ١٩ — التجربةُ ليست مالًا ═══════════ */

    public function test_a_test_mode_link_never_reaches_collections_or_moves_the_card(): void
    {
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::TEST]);
        $invoice = $this->invoice(20);
        $link = $this->link($invoice);
        $this->assertFalse((bool) $link->is_live);

        // فاتورةٌ برابطِ تجربةٍ وحده تبقى كما كانت: بيعُ يومِ صدورها
        $this->assertToday(20);
        $this->pay($link);

        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame(0, CustomerPayment::where('business_id', $this->shop->id)->count(), 'دفعةُ تجربةٍ صارت تحصيلًا');
        $this->assertSame(0.0, PaidLinksList::collected($this->shop->id, today(), today()->addDay()));
        $this->assertToday(20);
        $this->assertNull($this->hint(), 'رابطُ التجربة أظهر سطرَ تحصيل');
    }

    /* ═══════════ ٢٠ — ما قبل الروابط كما هو ═══════════ */

    public function test_old_invoices_without_links_keep_their_day_and_their_amount(): void
    {
        $old = $this->invoice(60, '2026-10-20');
        CustomerPayments::record($this->shop->id, $this->customer, 60, ['method' => 'نقدي', 'occurred_at' => '2026-10-22'], [$old->id => 60], $this->owner->id);

        $this->assertSame(60.0, $this->day('2026-10-20'));
        $this->assertSame(0.0, $this->day('2026-10-22'), 'سدادُ فاتورةٍ بلا رابطٍ صار بيعًا ثانيًا');

        // ورابطٌ على فاتورةٍ أخرى لا يمسّها
        $this->pay($this->link($this->invoice(20)));
        $this->assertSame(60.0, $this->day('2026-10-20'));
    }

    private function order(float $total, string $channel, string $payment): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'channel' => $channel,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => $payment, 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => now(),
        ]);
        Books::recordSale($order);

        return $order;
    }
}
