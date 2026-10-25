<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Lists\PaidLinksList;
use App\Support\OrderStatus;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\SalesChannel;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رابطُ الدفع المدفوع يُقرأ في «المبيعات» — تحصيلًا لا بيعًا.
 *
 * دفع العميلُ عبر رابطٍ فبحث صاحبُ المحلّ عن المال في «المبيعات» فلم يجده:
 * الرابطُ ليس طلبًا. فصار مُرشِّحُ المصدر «رابط دفع» يعرض التحصيلات الناجحة
 * من جدولها (`PaidLinksList`)، و«كل المصادر» يذكرها سطرًا خارج الإجمالي.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المدفوعُ بإشعارٍ موقَّعٍ وتحصيلٍ قائمٍ وحده — في بيئة الإنتاج وحدها.
 *   - البيعُ لا يُعدّ مرّتين: فاتورةُ الرابط (أو طلبُها) بيعٌ، والرابطُ سدادُه.
 *   - ولا يتحرّك إجماليُّ المبيعات ولا مُرشِّحاتُ الصندوق والموقع ولا الدفتر.
 *   - البحثُ والصفحاتُ والترتيبُ والملفّاتُ تقرأ ما في الشاشة.
 *   - الفروعُ والمتاجرُ والصلاحيات.
 */
class APaidPaymentLinkShowsInSalesAsACollectionTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private User $stranger;

    private Customer $ministry;

    private Customer $company;

    private int $tx = 7000;

    private int $paymobOrder = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('ورود الروابط', 'owner@paid-links.om', 'hmac-shop');
        [$this->other, $this->stranger] = $this->business('متجر الجار', 'other@paid-links.om', 'hmac-other');
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);

        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $this->company = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الورد', 'phone' => '92222222', 'allow_credit_sales' => true]);

        $this->fakePaymob();
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
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api_'.$shop->id, 'mode' => PaymentGateway::LIVE,
        ]);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'صاحب '.$name, 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner];
    }

    private function staff(string $role, string $email): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف '.$role, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** Paymob مزيَّفة — وكلُّ رابطٍ بطلبٍ عندها غيرِ طلب الآخر */
    private function fakePaymob(): void
    {
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok-secret']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => function () {
                $n = ++$this->paymobOrder;

                return Http::response([
                    'id' => 580000 + $n, 'shorten_url' => 'https://paymob.link/l'.$n,
                    'created_at' => '2026-10-20T10:00:00', 'expires_at' => '2026-10-27T23:59:00',
                    'state' => 'created', 'order' => 616000000 + $n,
                ]);
            },
        ]);
    }

    private function invoice(float $price, ?Customer $customer = null, ?Branch $branch = null): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $customer ?? $this->ministry, ['issued_at' => '2026-10-19'], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $price],
        ]);
        $invoice = CustomerInvoices::issue($invoice);

        if ($branch) {
            // الفاتورةُ من الشاشة بلا فرع — ويُعطى هنا ليُقرأ مُرشِّحُ الفرع
            DB::table('customer_invoices')->where('id', $invoice->id)->update(['branch_id' => $branch->id]);
        }

        return $invoice->fresh();
    }

    private function link(?CustomerInvoice $invoice = null, ?float $amount = null, ?Customer $customer = null, ?Business $shop = null): CustomerPaymentLink
    {
        $shop ??= $this->shop;

        return PaymentLinks::create($shop->id, $customer ?? $invoice?->customer ?? $this->ministry, $invoice, $amount, null, null);
    }

    /** إشعارُ Paymob الموقَّع — دفعةٌ ناجحةٌ في بيئة الرابط */
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

    /** «المبيعات» كما تراها الشاشة */
    private function sales(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->get(route('admin.orders.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function collections(array $query = [], ?User $as = null): array
    {
        return $this->sales(['channel' => PaidLinksList::SOURCE] + $query, $as);
    }

    private function order(string $number, ?string $channel, float $total, string $payment = 'مدفوع', ?Customer $customer = null): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => $number, 'customer_name' => $customer?->name ?? 'زبون',
            'customer_id' => $customer?->id, 'employee_name' => 'أحمد', 'branch_id' => $this->muscat->id, 'branch' => 'مسقط',
            'channel' => $channel, 'payment_method' => $payment === 'مدفوع' ? 'نقدي' : 'آجل', 'payment_status' => $payment,
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'is_held' => false,
            'ordered_at' => now(), 'status' => OrderStatus::COMPLETED,
        ]);
        Books::recordSale($order);

        return $order;
    }

    /** ما يقوله «كل المصادر» عن المبيعات — وما لا يجوز أن يتحرّك بالتحصيل */
    private function salesFigures(): array
    {
        $p = $this->sales();

        return [
            'rows' => array_column($p['orders'], 'id'),
            'count' => $p['totalCount'],
            'amount' => $p['totalAmount'],
            'cancelled' => $p['cancelledCount'],
            'website' => [$p['websiteCount'], $p['websiteAmount']],
            'revenue' => round(Ledger::netRevenue($this->shop->id), 3),
            'tax' => round(Ledger::outputTax($this->shop->id), 3),
        ];
    }

    /* ═══════════ ١ — رابطٌ مدفوعٌ يظهر مرّةً واحدة ═══════════ */

    public function test_a_paid_link_shows_once_as_a_collection_with_its_details(): void
    {
        $invoice = $this->invoice(100);
        $link = $this->pay($this->link($invoice));
        $payment = CustomerPayment::findOrFail($link->customer_payment_id);

        $props = $this->collections();

        $this->assertCount(1, $props['collections']);
        $row = $props['collections'][0];
        $this->assertSame($payment->number, $row['id']);
        $this->assertSame((string) $link->provider_transaction_id, (string) $row['reference']);
        $this->assertSame('وزارة الثقافة', $row['customer']);
        $this->assertSame(PaidLinksList::INVOICE, $row['type']);
        $this->assertSame(['id' => $invoice->id, 'number' => $invoice->number], $row['invoice']);
        $this->assertSame(round((float) $invoice->total, 3), $row['amount']);
        $this->assertSame('2026-10-20 10:00', $row['date']);
        $this->assertSame('مدفوع', $row['status']);
        $this->assertFalse($row['review']);
        $this->assertSame(['id' => $payment->id, 'number' => $payment->number], $row['payment']);

        $this->assertSame([], $props['orders'], 'دخل التحصيلُ قائمةَ الطلبات');
        $this->assertSame(0.0, (float) $props['totalAmount'], 'قُرئ التحصيلُ إجماليَّ مبيعات');
        $this->assertSame(['count' => 1, 'amount' => round((float) $invoice->total, 3)], array_intersect_key($props['collectionTotals'], ['count' => 0, 'amount' => 0]));

        // والخيارُ في مُرشِّح المصدر مع قنوات الطلبات — لا بدلَها
        $this->assertSame(
            [SalesChannel::WEBSITE, SalesChannel::POS, SalesChannel::UNKNOWN, PaidLinksList::SOURCE],
            array_column($props['channelOptions'], 'value'),
        );
        // ولا تُكتب قناةً على طلب
        $this->assertSame(0, Order::where('channel', PaidLinksList::SOURCE)->count());
    }

    /* ═══════════ ٢ — غيرُ المدفوع لا يُقرأ مدفوعًا ═══════════ */

    public function test_only_a_settled_live_link_reads_as_paid(): void
    {
        $paid = $this->pay($this->link(null, 10));

        // منتهٍ انقضت مهلتُه، ثمّ معلَّقٌ لم يُدفع، ومردودٌ رفضته البطاقة، وملغًى
        $expired = $this->link(null, 14);
        Carbon::setTestNow('2026-10-28 10:00:00'); // بعد مهلة Paymob المزيَّفة (27 أكتوبر)
        PaymentLinks::expireDue($this->shop->id);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $pending = $this->link(null, 11);
        $declined = $this->link(null, 12);
        $this->hook($this->obj($declined, ['success' => false, 'data' => ['message' => 'Declined']]));
        PaymentLinks::cancel($this->link(null, 13), $this->owner->id);

        // ودفعةُ بيئة التجربة — مقبولةٌ في بيئتها، وليست مالًا
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::TEST]);
        $test = $this->pay($this->link(null, 15));
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::LIVE]);

        // ومدفوعٌ أُلغي تحصيلُه بعدُ — لم يعد مالًا مقبوضًا
        $reversed = $this->pay($this->link(null, 16));
        CustomerPayments::cancel(CustomerPayment::findOrFail($reversed->customer_payment_id), 'استُرجع', $this->owner->id);

        // وصفٌّ يقول «ملغى» ومعه تحصيل — لا يكتبه بابٌ اليوم، والحالُ هي الحكم لا التحصيل وحده
        $odd = $this->pay($this->link(null, 17));
        DB::table('customer_payment_links')->where('id', $odd->id)->update(['status' => CustomerPaymentLink::CANCELLED]);

        $this->assertSame(CustomerPaymentLink::PENDING, $pending->fresh()->status);
        $this->assertSame(CustomerPaymentLink::PENDING, $declined->fresh()->status);
        $this->assertSame(CustomerPaymentLink::EXPIRED, $expired->fresh()->status);
        $this->assertSame(CustomerPaymentLink::PAID, $test->status);
        $this->assertFalse((bool) $test->is_live);

        $rows = $this->collections()['collections'];

        $this->assertSame([CustomerPayment::findOrFail($paid->customer_payment_id)->number], array_column($rows, 'id'));
        $this->assertSame(['count' => 1, 'amount' => 10.0], $this->sales()['linkCollections']);
    }

    /* ═══════════ ٣ — رابطٌ على فاتورةٍ صدرت عن بيعة: لا بيعَ ثانٍ ═══════════ */

    public function test_a_link_on_an_invoice_made_from_a_sale_never_sells_twice(): void
    {
        $credit = $this->order('CR-1', SalesChannel::POS, 40, 'غير مدفوع', $this->company);
        $invoice = CustomerInvoices::consolidate($this->company, [$credit->id], [], $this->owner->id);
        if ($invoice->status !== CustomerInvoice::ISSUED) {
            $invoice = CustomerInvoices::issue($invoice, $this->owner->id);
        }
        $before = $this->salesFigures();
        $receivable = round(Ledger::balance($this->shop->id, 'receivable'), 3);

        $this->pay($this->link($invoice->fresh(), 40.0, $this->company));

        $after = $this->salesFigures();
        $this->assertSame($before, $after, 'تحرّكت أرقامُ المبيعات أو الدفترُ بتحصيل');
        $this->assertSame(['CR-1'], $after['rows'], 'البيعةُ صفٌّ واحدٌ كما كانت');
        // والتحصيلُ يُنقص الذمّة — وهو ما يفعله الرابطُ منذ كان، لا هذه الشاشة
        $this->assertSame(round($receivable - 40, 3), round(Ledger::balance($this->shop->id, 'receivable'), 3));

        $row = $this->collections()['collections'][0];
        $this->assertSame(PaidLinksList::INVOICE, $row['type']);
        $this->assertSame($invoice->number, $row['invoice']['number']);
        $this->assertSame(['CR-1'], $row['orders'], 'لم يُقل عن أيّ بيعٍ دُفع');
    }

    /* ═══════════ ٤ — رابطٌ بلا فاتورة: تحصيلٌ لا يزيد المبيعات ═══════════ */

    public function test_a_standalone_link_is_a_collection_outside_the_sales_total(): void
    {
        $this->order('POS-1', SalesChannel::POS, 30);
        $invoice = $this->invoice(50);
        $before = $this->salesFigures();

        $this->pay($this->link(null, 25, $this->company));
        $this->pay($this->link($invoice));

        $this->assertSame($before, $this->salesFigures());

        $props = $this->collections();
        $standalone = collect($props['collections'])->firstWhere('invoice', null);
        $this->assertSame(PaidLinksList::STANDALONE, $standalone['type']);
        $this->assertSame([], $standalone['orders']);
        $this->assertSame(['count' => 1, 'amount' => 25.0], $props['collectionTotals']['standalone']);
        $this->assertSame(['count' => 1, 'amount' => round((float) $invoice->total, 3)], $props['collectionTotals']['on_invoices']);

        // و«كل المصادر» يذكرها خارج إجماليّه
        $all = $this->sales();
        $this->assertSame(30.0, (float) $all['totalAmount']);
        $this->assertSame(['count' => 2, 'amount' => round(25 + (float) $invoice->total, 3)], $all['linkCollections']);
    }

    /* ═══════════ ٥ — إعادةُ الإشعار لا تكرّر ═══════════ */

    public function test_a_replayed_webhook_shows_one_collection(): void
    {
        $link = $this->link($this->invoice(80));
        $obj = $this->obj($link);

        foreach (range(1, 4) as $i) {
            $this->hook($obj);
        }

        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $props = $this->collections();
        $this->assertCount(1, $props['collections']);
        $this->assertSame(1, $props['collectionTotals']['count']);
    }

    /* ═══════════ ٦ — القراءةُ لا تكتب ═══════════ */

    public function test_reading_the_screen_and_its_files_writes_nothing(): void
    {
        $this->pay($this->link($this->invoice(60)));
        $this->link(null, 9); // معلَّقٌ انقضت مهلتُه — لا تُغلقه القراءة
        Carbon::setTestNow('2026-11-30 10:00:00');

        $snapshot = fn () => [
            'orders' => DB::table('orders')->count(),
            'invoices' => DB::table('customer_invoices')->get()->toJson(),
            'payments' => DB::table('customer_payments')->get()->toJson(),
            'links' => DB::table('customer_payment_links')->get()->toJson(),
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->count(),
            'stock' => DB::table('inventory_movements')->count(),
        ];
        $before = $snapshot();

        $this->collections();
        $this->sales();
        $q = ['channel' => PaidLinksList::SOURCE];
        $this->get($this->exportRoute('admin.export.orders', $q))->assertOk()->streamedContent();
        $this->get($this->exportRoute('admin.orders.xlsx', $q))->assertOk();
        $this->get($this->exportRoute('admin.orders.exportPdf', $q))->assertOk();

        $this->assertSame($before, $snapshot());
    }

    /* ═══════════ ٧ — مُرشِّحا الصندوق والموقع كما كانا ═══════════ */

    public function test_pos_website_and_unknown_filters_stay_as_they_were(): void
    {
        $this->order('POS-1', SalesChannel::POS, 10);
        $this->order('WEB-1', SalesChannel::WEBSITE, 20);
        $this->order('OLD-1', null, 30);
        $this->pay($this->link(null, 5));

        $all = $this->sales();
        $this->assertEqualsCanonicalizing(['POS-1', 'WEB-1', 'OLD-1'], array_column($all['orders'], 'id'));
        $this->assertSame([3, 60.0, 1, 20.0], [$all['totalCount'], (float) $all['totalAmount'], $all['websiteCount'], (float) $all['websiteAmount']]);
        $this->assertArrayNotHasKey('collections', $all);

        foreach ([SalesChannel::POS => 'POS-1', SalesChannel::WEBSITE => 'WEB-1', SalesChannel::UNKNOWN => 'OLD-1'] as $channel => $number) {
            $props = $this->sales(['channel' => $channel]);
            $this->assertSame([$number], array_column($props['orders'], 'id'), $channel);
            $this->assertNull($props['linkCollections'], 'ذُكرت التحصيلاتُ تحت مصدرٍ آخر');
            $this->assertArrayNotHasKey('collections', $props);
        }
    }

    /* ═══════════ ٨ — البحثُ والصفحاتُ والترتيبُ والملفّات ═══════════ */

    public function test_search_pages_sort_and_files_match_the_screen(): void
    {
        $customers = [];
        foreach (range(1, 12) as $i) {
            $customers[$i] = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل '.$i, 'phone' => '9100000'.($i % 10)]);
            $this->pay($this->link(null, $i * 1.5, $customers[$i]));
        }

        $first = $this->collections();
        $this->assertCount(10, $first['collections']);
        $this->assertSame(12, $first['pagination']['total']);
        $this->assertCount(2, $this->collections(['page' => 2])['collections']);
        $this->assertSame(['id', 'customer', 'total', 'date'], $first['sorts']);

        $ascending = array_column($this->collections(['sort' => 'total', 'dir' => 'asc'])['collections'], 'amount');
        $this->assertSame([1.5, 3.0, 4.5], array_slice($ascending, 0, 3));

        $hit = $this->collections(['q' => 'عميل 7']);
        $this->assertSame(['عميل 7'], array_column($hit['collections'], 'customer'));
        $this->assertSame(10.5, $hit['collectionTotals']['amount']);
        $reference = (string) CustomerPaymentLink::where('customer_id', $customers[3]->id)->value('provider_transaction_id');
        $this->assertSame(['عميل 3'], array_column($this->collections(['q' => $reference])['collections'], 'customer'));

        // والملفُّ ما في الشاشة: صفوفُ البحث نفسُها، لا الصفحةُ وحدها ولا القائمةُ كلُّها
        $csv = $this->get($this->exportRoute('admin.export.orders', ['channel' => PaidLinksList::SOURCE, 'q' => 'عميل 1']))->assertOk()->streamedContent();
        foreach (['عميل 1', 'عميل 10', 'عميل 11', 'عميل 12'] as $name) {
            $this->assertStringContainsString($name, $csv);
        }
        $this->assertStringNotContainsString('عميل 7', $csv);
        $all = $this->get($this->exportRoute('admin.export.orders', ['channel' => PaidLinksList::SOURCE]))->assertOk()->streamedContent();
        foreach ($customers as $c) {
            $this->assertStringContainsString($c->name, $all);
        }
        $this->get($this->exportRoute('admin.orders.xlsx', ['channel' => PaidLinksList::SOURCE]))->assertOk();
        $this->get($this->exportRoute('admin.orders.exportPdf', ['channel' => PaidLinksList::SOURCE]))->assertOk();

        // وملفُّ الطلبات بلا المُرشِّح كما كان: لا تحصيلَ فيه
        $orders = $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('عميل 7', $orders);
    }

    /** والرابطُ التاريخيُّ يُقرأ في فترته — ويوم الدفع هو الفترة */
    public function test_an_old_paid_link_reads_in_its_own_period(): void
    {
        // فُتح الأوّلُ في الخامس ودُفع في الثاني عشر، وفُتح الثاني في الثاني عشر ودُفع في العشرين
        Carbon::setTestNow('2026-10-05 09:00:00');
        $old = $this->link(null, 7, $this->company);
        Carbon::setTestNow('2026-10-12 09:00:00');
        $old = $this->pay($old);
        $new = $this->link(null, 8);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $new = $this->pay($new);

        $number = fn (CustomerPaymentLink $l) => CustomerPayment::findOrFail($l->customer_payment_id)->number;

        $this->assertEqualsCanonicalizing([$number($old), $number($new)], array_column($this->collections()['collections'], 'id'));
        $this->assertSame([$number($new)], array_column($this->collections(['from' => '2026-10-15'])['collections'], 'id'));
        $this->assertSame([$number($old)], array_column($this->collections(['from' => '2026-10-01', 'to' => '2026-10-14'])['collections'], 'id'));
        $this->assertSame('2026-10-12 09:00', collect($this->collections()['collections'])->firstWhere('id', $number($old))['date']);
    }

    /* ═══════════ ٩ — «فواتير العملاء» كما هي ═══════════ */

    public function test_customer_invoices_keep_every_door(): void
    {
        $invoice = $this->invoice(100);
        $this->pay($this->link($invoice));

        $index = $this->get(route('admin.customerInvoices.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame([$invoice->id], array_column($index['invoices'], 'id'));
        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk();
        $this->get(route('admin.customerInvoices.create'))->assertOk();
    }

    /* ═══════════ ١١ — الروابطُ القديمة للتبويبات المرفوعة تُفتح ═══════════ */

    public function test_the_removed_tabs_old_links_still_open(): void
    {
        foreach (['admin.customerInvoices.sales', 'admin.customerInvoices.posSales', 'admin.customerInvoices.websiteOrders'] as $name) {
            $this->get(route($name))->assertOk();
        }
        foreach (['admin.customerInvoices.sales.csv', 'admin.customerInvoices.sales.xlsx', 'admin.customerInvoices.sales.pdf'] as $name) {
            $this->get($this->exportRoute($name))->assertOk();
        }
    }

    /* ═══════════ ١٢ — المتاجرُ والفروعُ والصلاحيات ═══════════ */

    public function test_two_shops_never_read_each_others_collections(): void
    {
        $mine = $this->pay($this->link(null, 10));
        $theirs = $this->pay(
            PaymentLinks::create($this->other->id, Customer::create(['business_id' => $this->other->id, 'name' => 'عميل الجار', 'phone' => '93333333']), null, 99, null, null),
            'hmac-other',
        );
        $this->assertSame(CustomerPaymentLink::PAID, $theirs->status);

        $this->assertSame(['وزارة الثقافة'], array_column($this->collections()['collections'], 'customer'));
        $this->assertSame([], $this->collections(['q' => (string) $theirs->provider_transaction_id])['collections']);
        $csv = $this->get($this->exportRoute('admin.export.orders', ['channel' => PaidLinksList::SOURCE]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('عميل الجار', $csv);

        $neighbour = $this->collections([], $this->stranger);
        $this->assertSame(['عميل الجار'], array_column($neighbour['collections'], 'customer'));
        $this->assertSame([], $this->collections(['q' => (string) $mine->provider_transaction_id], $this->stranger)['collections']);
    }

    public function test_a_branch_reads_the_collections_of_its_own_invoices(): void
    {
        $this->pay($this->link($this->invoice(10, null, $this->muscat)));
        $this->pay($this->link($this->invoice(20, null, $this->sohar)));
        $this->pay($this->link(null, 30));

        $amounts = fn () => array_map('floatval', array_column($this->collections()['collections'], 'amount'));
        $tax = fn (float $v) => round((float) CustomerInvoice::where('business_id', $this->shop->id)->where('subtotal', $v)->value('total'), 3);

        $this->assertEqualsCanonicalizing([$tax(10), $tax(20), 30.0], $amounts());

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $this->assertSame([$tax(10)], $amounts());
        $this->get(route('admin.branch.switch', $this->sohar->id));
        $this->assertSame([$tax(20)], $amounts(), 'رابطٌ بلا فاتورةٍ نُسب إلى فرع');
    }

    public function test_who_may_see_the_collections_and_their_receipts(): void
    {
        $this->pay($this->link(null, 10));

        // المبيعاتُ بلا «المالية»: يرى التحصيلَ ولا يُعطى بابَ سنده
        $sales = $this->collections([], $this->staff('sales', 'sales@paid-links.om'));
        $this->assertCount(1, $sales['collections']);
        $this->assertFalse($sales['mayOpenReceipts']);

        $this->assertTrue($this->collections([], $this->staff('accountant', 'acc@paid-links.om'))['mayOpenReceipts']);

        // ومن لا «مبيعات» له لا يفتح الشاشة بمُرشِّحها ولا ملفَّها
        $cashier = $this->staff('cashier', 'cashier@paid-links.om');
        $this->actingAs($cashier)->get(route('admin.orders.index', ['channel' => PaidLinksList::SOURCE]))->assertForbidden();
        $this->actingAs($cashier)->get($this->exportRoute('admin.export.orders', ['channel' => PaidLinksList::SOURCE]))->assertForbidden();
    }
}
