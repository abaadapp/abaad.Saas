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
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Lists\AllSalesList;
use App\Support\Lists\PaidLinksList;
use App\Support\OrderStatus;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\SalesChannel;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * «المبيعات ← كل المصادر» — الطلباتُ وتحصيلاتُ روابط الدفع في جدولٍ واحد،
 * و«عرض» التحصيل يفتح فاتورتَه الأصليّة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لكلّ متجرٍ فُتحت فيه روابطُ الدفع (`PaymentLinks::ready`) — لا لغيره.
 *   - الصندوقُ والموقعُ وغيرُ المحدَّد والتحصيلُ الناجح صفوفٌ بيوم كلٍّ منها،
 *     بالبحث والترتيب والصفحات على الخادم.
 *   - التحصيلُ ليس بيعًا: إجماليُّ المبيعات والدفترُ والضريبةُ كما كانت،
 *     ومجموعُه وحده. والقراءةُ لا تكتب.
 *   - التجربةُ والمعلَّقُ والمردودُ والملغى لا تُعرض، والإشعارُ المكرَّر صفٌّ واحد.
 *   - «عرض»: الفاتورةُ الأصليّة (وطلباتُها من الصندوق أو الموقع) بتحصيلها،
 *     والرابطُ المستقلّ صفحةُ تحصيلٍ بلا فاتورةٍ تُصنع.
 *   - الملفّاتُ الثلاثة صفوفُ الشاشة، والمبلغان في عمودين.
 *   - الصلاحياتُ والمتاجرُ والفروع.
 */
class EveryPaidLinkSitsAmongTheSalesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private Branch $muscat;

    private User $owner;

    private User $stranger;

    private Customer $ministry;

    private Customer $company;

    private int $tx = 9000;

    private int $paymobOrder = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('ورود الجدول', 'owner@all-sales.om', 'hmac-shop');
        [$this->other, $this->stranger] = $this->business('متجر الجار', 'other@all-sales.om', 'hmac-other');
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);

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

    private function fakePaymob(): void
    {
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok-secret']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => function () {
                $n = ++$this->paymobOrder;

                return Http::response([
                    'id' => 590000 + $n, 'shorten_url' => 'https://paymob.link/s'.$n,
                    'created_at' => '2026-10-20T10:00:00', 'expires_at' => '2026-10-27T23:59:00',
                    'state' => 'created', 'order' => 626000000 + $n,
                ]);
            },
        ]);
    }

    private function invoice(float $price, ?Customer $customer = null): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $customer ?? $this->ministry, ['issued_at' => '2026-10-19'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => $price / 2],
        ]);

        return CustomerInvoices::issue($invoice)->fresh();
    }

    /** فاتورةٌ صدرت عن بيعةٍ آجلة — من الصندوق أو الموقع */
    private function invoiceOf(Order $order, Customer $customer): CustomerInvoice
    {
        $invoice = CustomerInvoices::consolidate($customer, [$order->id], [], $this->owner->id);

        return ($invoice->status === CustomerInvoice::ISSUED ? $invoice : CustomerInvoices::issue($invoice, $this->owner->id))->fresh();
    }

    private function link(?CustomerInvoice $invoice = null, ?float $amount = null, ?Customer $customer = null): CustomerPaymentLink
    {
        return PaymentLinks::create($this->shop->id, $customer ?? $invoice?->customer ?? $this->ministry, $invoice, $amount, null, null);
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

    private function pay(CustomerPaymentLink $link): CustomerPaymentLink
    {
        $this->hook($this->obj($link));

        return $link->fresh();
    }

    private function receipt(CustomerPaymentLink $link): string
    {
        return CustomerPayment::findOrFail($link->customer_payment_id)->number;
    }

    private function sales(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->get(route('admin.orders.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function order(string $number, ?string $channel, float $total, string $at = '2026-10-20 10:00:00', string $payment = 'مدفوع', ?Customer $customer = null): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => $number, 'customer_name' => $customer?->name ?? 'زبون '.$number,
            'customer_id' => $customer?->id, 'employee_name' => 'أحمد', 'branch_id' => $this->muscat->id, 'branch' => 'مسقط',
            'channel' => $channel, 'payment_method' => $payment === 'مدفوع' ? 'نقدي' : 'آجل', 'payment_status' => $payment,
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'is_held' => false,
            'ordered_at' => $at, 'status' => OrderStatus::COMPLETED,
        ]);
        // صنفٌ يُقرأ في فاتورتها حين تُضمّ — بندٌ بكمّيّته وسعره
        OrderItem::create(['order_id' => $order->id, 'name' => 'باقة ورد '.$number, 'price' => $total / 2, 'quantity' => 2, 'total' => $total]);
        Books::recordSale($order);

        return $order;
    }

    /** ما يقوله الدفترُ والمبيعات — وما لا يجوز أن يتحرّك بالتحصيل ولا بالقراءة */
    private function books(): array
    {
        return [
            'revenue' => round(Ledger::netRevenue($this->shop->id), 3),
            'tax' => round(Ledger::outputTax($this->shop->id), 3),
            'entries' => DB::table('journal_entries')->count(),
            'lines' => DB::table('journal_lines')->get()->toJson(),
            'orders' => DB::table('orders')->get()->toJson(),
            'invoices' => DB::table('customer_invoices')->get()->toJson(),
            'payments' => DB::table('customer_payments')->get()->toJson(),
            'links' => DB::table('customer_payment_links')->get()->toJson(),
            'stock' => DB::table('inventory_movements')->count(),
            'customers' => DB::table('customers')->get()->toJson(),
            'settlements' => DB::table('paymob_settlements')->count(),
        ];
    }

    /* ═══════════ ١ — جدولٌ واحد بيوم كلٍّ منها ═══════════ */

    public function test_all_sources_list_orders_and_paid_links_in_one_timeline(): void
    {
        $this->order('POS-1', SalesChannel::POS, 10, '2026-10-20 08:00:00');
        $this->order('WEB-1', SalesChannel::WEBSITE, 20, '2026-10-20 09:30:00');
        $this->order('OLD-1', null, 30, '2026-10-19 12:00:00');
        Carbon::setTestNow('2026-10-20 09:00:00');
        $standalone = $this->pay($this->link(null, 25, $this->company));
        Carbon::setTestNow('2026-10-20 10:00:00');
        $invoice = $this->invoice(100);
        $onInvoice = $this->pay($this->link($invoice));

        $props = $this->sales();

        $this->assertTrue($props['withLinks']);
        $this->assertSame(
            [$this->receipt($onInvoice), 'WEB-1', $this->receipt($standalone), 'POS-1', 'OLD-1'],
            array_column($props['orders'], 'id'),
            'لم تُرتَّب الصفوفُ بيوم كلٍّ منها',
        );
        $this->assertSame(5, $props['pagination']['total']);

        $row = collect($props['orders'])->firstWhere('id', $this->receipt($onInvoice));
        $this->assertSame(AllSalesList::LINK, $row['kind']);
        $this->assertSame($onInvoice->id, $row['link_id']);
        $this->assertSame((string) $onInvoice->provider_transaction_id, (string) $row['reference']);
        $this->assertSame('وزارة الثقافة', $row['customer']);
        $this->assertSame(['id' => $invoice->id, 'number' => $invoice->number], $row['invoice']);
        $this->assertSame(round((float) $invoice->total, 3), $row['amount']);
        $this->assertSame('2026-10-20 10:00', $row['date']);
        $this->assertNull(collect($props['orders'])->firstWhere('id', $this->receipt($standalone))['invoice']);

        // المجاميعُ للطلبات وحدها — والتحصيلُ مجموعُه وحده
        $this->assertSame([3, 60.0], [$props['totalCount'], (float) $props['totalAmount']]);
        $this->assertSame(['count' => 2, 'amount' => round(25 + (float) $invoice->total, 3)], $props['linkCollections']);
        $this->assertSame(0, Order::where('number', 'like', 'RCPT-%')->count(), 'كُتب التحصيلُ طلبًا');
    }

    /* ═══════════ ٢ — متجرٌ لم تُفتح فيه الروابط: كما كان ═══════════ */

    public function test_a_shop_without_payment_links_reads_as_before(): void
    {
        $this->order('POS-1', SalesChannel::POS, 10);
        $paid = $this->pay($this->link(null, 5));
        PaymentGateway::where('business_id', $this->shop->id)->update(['active' => false]);
        $this->assertFalse(PaymentLinks::ready($this->shop->id));

        $props = $this->sales();

        $this->assertFalse($props['withLinks']);
        $this->assertSame(['POS-1'], array_column($props['orders'], 'id'));
        $this->assertArrayNotHasKey('kind', $props['orders'][0], 'تغيّر صفُّ الطلب لمتجرٍ بلا روابط');
        // و«رابط دفع» مُرشِّحٌ مستقلٌّ كما هو
        $this->assertSame([$this->receipt($paid)], array_column($this->sales(['channel' => PaidLinksList::SOURCE])['collections'], 'id'));

        $csv = $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($this->receipt($paid), $csv);
        $this->assertStringNotContainsString('المحصّل عبر رابط دفع', $csv);
    }

    /* ═══════════ ٣ — ما لم يُدفع حقًّا لا يُعرض، والمكرَّرُ صفٌّ واحد ═══════════ */

    public function test_only_real_settled_payments_show_and_a_replay_shows_once(): void
    {
        $paid = $this->link($this->invoice(80));
        $obj = $this->obj($paid);
        foreach (range(1, 3) as $i) {
            $this->hook($obj);
        }
        $paid = $paid->fresh();

        $this->link(null, 11); // معلَّق
        $declined = $this->link(null, 12);
        $this->hook($this->obj($declined, ['success' => false, 'data' => ['message' => 'Declined']]));
        PaymentLinks::cancel($this->link(null, 13), $this->owner->id);
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::TEST]);
        $test = $this->pay($this->link(null, 15));
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::LIVE]);
        $reversed = $this->pay($this->link(null, 16));
        CustomerPayments::cancel(CustomerPayment::findOrFail($reversed->customer_payment_id), 'استُرجع', $this->owner->id);

        $links = array_values(array_filter($this->sales()['orders'], fn ($r) => ($r['kind'] ?? '') === AllSalesList::LINK));
        $this->assertSame([$this->receipt($paid)], array_column($links, 'id'));
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->whereNull('cancelled_at')->count());

        // ولا يُفتح بعنوانه ما لا تعرضه القائمة
        $this->get(route('admin.orders.collection', $test->id))->assertNotFound();
        $this->get(route('admin.orders.collection', $reversed->id))->assertNotFound();
        $this->get(route('admin.orders.collection', $declined->id))->assertNotFound();
    }

    /* ═══════════ ٤ — «عرض» رابطٍ على فاتورةٍ صدرت عن بيعة الصندوق ═══════════ */

    public function test_view_on_a_pos_invoice_link_opens_the_original_invoice(): void
    {
        $credit = $this->order('CR-1', SalesChannel::POS, 40, '2026-10-19 09:00:00', 'غير مدفوع', $this->company);
        $invoice = $this->invoiceOf($credit, $this->company);
        $link = $this->pay($this->link($invoice, null, $this->company));

        $row = collect($this->sales()['orders'])->firstWhere('kind', AllSalesList::LINK);
        $this->assertSame(['CR-1'], $row['orders']);

        // البابُ الواحد يحوّل إلى الفاتورة نفسِها ومعها التحصيل
        $to = route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $link->id]);
        $this->get(route('admin.orders.collection', $link->id))->assertRedirect($to);

        $page = $this->get($to)->assertOk()->viewData('page');
        $this->assertSame('Admin/CustomerInvoices/Show', $page['component']);
        $props = $page['props'];
        $this->assertSame($invoice->number, $props['invoice']['number']);
        $this->assertSame(['CR-1'], $props['invoice']['orders']);
        $this->assertSame([2.0, 20.0], [$props['invoice']['items'][0]['quantity'], $props['invoice']['items'][0]['unit_price']]);
        $this->assertStringContainsString('باقة ورد CR-1', $props['invoice']['items'][0]['description']);
        $this->assertSame(round((float) $invoice->total, 3), round((float) $props['invoice']['total'], 3));
        $this->assertSame(round((float) $invoice->tax_total, 3), round((float) $props['invoice']['tax_total'], 3));
        $this->assertSame(round((float) $invoice->discount_total, 3), round((float) $props['invoice']['discount_total'], 3));
        $this->assertStringContainsString($invoice->number, $props['paper']['html'], 'لم تُبنَ ورقةُ الفاتورة للطباعة');
        $this->assertSame(0.0, round((float) $props['invoice']['outstanding'], 3), 'لم يُقرأ السدادُ في الفاتورة');

        $c = $props['collection'];
        $this->assertSame($link->id, $c['link_id']);
        $this->assertSame(round((float) $invoice->total, 3), $c['amount']);
        $this->assertSame((string) $link->provider_transaction_id, (string) $c['reference']);
        $this->assertSame($this->receipt($link), $c['payment']['number']);
        $this->assertSame('2026-10-20 10:00', $c['date']);
        $this->assertSame('مدفوع', $c['status']);

        // وطباعةُ الفاتورة وبيعتُها الأصليّة من وظائفها القائمة
        $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk();
        $this->get(route('admin.orders.show', 'CR-1'))->assertOk();
    }

    /* ═══════════ ٥ — «عرض» رابطٍ على فاتورةٍ صدرت عن طلب الموقع ═══════════ */

    public function test_view_on_a_website_invoice_link_opens_the_original_invoice(): void
    {
        $web = $this->order('WEB-7', SalesChannel::WEBSITE, 55, '2026-10-19 09:00:00', 'غير مدفوع', $this->ministry);
        $invoice = $this->invoiceOf($web, $this->ministry);
        $link = $this->pay($this->link($invoice, null, $this->ministry));

        $this->get(route('admin.orders.collection', $link->id))
            ->assertRedirect(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $link->id]));
        $props = $this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $link->id]))->assertOk()->viewData('page')['props'];
        $this->assertSame(['WEB-7'], $props['invoice']['orders']);
        $this->assertSame([2.0, 27.5], [$props['invoice']['items'][0]['quantity'], $props['invoice']['items'][0]['unit_price']]);
        $this->assertStringContainsString('باقة ورد WEB-7', $props['invoice']['items'][0]['description']);
        $this->assertSame(round((float) $invoice->tax_total, 3), round((float) $props['invoice']['tax_total'], 3));
        $this->assertSame(round((float) $invoice->total, 3), round((float) $props['invoice']['total'], 3));
        $this->assertSame(0.0, round((float) $props['invoice']['outstanding'], 3));
        $this->assertSame($link->id, $props['collection']['link_id']);
        $this->assertSame((string) $link->provider_transaction_id, (string) $props['collection']['reference']);
        $this->assertStringContainsString($invoice->number, $props['paper']['html']);
        $this->get(route('admin.customerInvoices.pdf', $invoice->id))->assertOk();
        $this->get(route('admin.orders.show', 'WEB-7'))->assertOk();
    }

    /* ═══════════ ٦ — الرابطُ المستقلّ: صفحةُ تحصيلٍ بلا فاتورةٍ تُصنع ═══════════ */

    public function test_view_on_a_standalone_link_opens_its_collection_without_making_an_invoice(): void
    {
        $link = $this->pay($this->link(null, 25, $this->company));
        $invoices = CustomerInvoice::count();

        $page = $this->get(route('admin.orders.collection', $link->id))->assertOk()->viewData('page');

        $this->assertSame('Admin/Orders/Collection', $page['component']);
        $c = $page['props']['collection'];
        $this->assertNull($c['invoice']);
        $this->assertSame([], $c['orders']);
        $this->assertSame(PaidLinksList::STANDALONE, $c['type']);
        $this->assertSame(25.0, $c['amount']);
        $this->assertSame('شركة الورد', $c['customer']);
        $this->assertSame((string) $link->provider_transaction_id, (string) $c['reference']);
        $this->assertSame($link->reference, $c['link_reference']);
        $this->assertSame($invoices, CustomerInvoice::count(), 'أُنشئت فاتورةٌ لتحصيلٍ مستقلّ');
    }

    /* ═══════════ ٧ — البحثُ والترتيبُ والصفحاتُ على الخادم ═══════════ */

    public function test_search_sort_and_pages_run_on_the_server_across_both(): void
    {
        foreach (range(1, 8) as $i) {
            $this->order('POS-'.$i, SalesChannel::POS, $i * 10, '2026-10-1'.$i.' 09:00:00');
        }
        $links = [];
        foreach ([3 => 5.5, 5 => 7.5, 9 => 95.0, 7 => 1.0] as $day => $amount) {
            Carbon::setTestNow('2026-10-1'.$day.' 12:00:00');
            $links[$day] = $this->pay($this->link(null, $amount, Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل اليوم '.$day, 'phone' => '9300000'.$day])));
        }
        Carbon::setTestNow('2026-10-20 10:00:00');

        $first = $this->sales();
        $this->assertSame(12, $first['pagination']['total']);
        $this->assertCount(10, $first['orders']);
        $this->assertCount(2, $this->sales(['page' => 2])['orders']);
        $this->assertSame($this->receipt($links[9]), $first['orders'][0]['id'], 'الأحدثُ أوّلًا عبر الجدولين');
        $this->assertSame(['id', 'customer', 'employee', 'items_count', 'total', 'payment', 'date', 'scheduled'], $first['sorts']);

        // بالمبلغ تصاعديًّا: التحصيلُ بمبلغه بين الطلبات
        $asc = array_column($this->sales(['sort' => 'total', 'dir' => 'asc'])['orders'], 'id');
        $this->assertSame([$this->receipt($links[7]), $this->receipt($links[3]), $this->receipt($links[5]), 'POS-1'], array_slice($asc, 0, 4));
        // وبالموظّف: لا موظّفَ للتحصيل فيأتي آخرًا في الاتّجاهين
        foreach (['asc', 'desc'] as $dir) {
            $byEmployee = $this->sales(['sort' => 'employee', 'dir' => $dir, 'page' => 2])['orders'];
            $this->assertSame([AllSalesList::LINK, AllSalesList::LINK], array_column($byEmployee, 'kind'), $dir);
        }

        // والبحثُ بمرجع Paymob يجد التحصيلَ وحده، وبرقم الطلب الطلبَ وحده
        $this->assertSame([$this->receipt($links[5])], array_column($this->sales(['q' => (string) $links[5]->provider_transaction_id])['orders'], 'id'));
        $this->assertSame(['POS-4'], array_column($this->sales(['q' => 'POS-4'])['orders'], 'id'));
        // والفترةُ على يوم كلٍّ منها
        $this->assertEqualsCanonicalizing(
            ['POS-3', 'POS-4', $this->receipt($links[3])],
            array_column($this->sales(['from' => '2026-10-13', 'to' => '2026-10-14'])['orders'], 'id'),
        );
        // ومُرشِّحُ حالة الطلب يسأل عن طلباتٍ — فلا تحصيلَ فيه
        $done = $this->sales(['status' => OrderStatus::COMPLETED]);
        $this->assertFalse($done['withLinks']);
        $this->assertSame(8, $done['pagination']['total']);
    }

    /* ═══════════ ٨ — الملفّاتُ الثلاثة صفوفُ الشاشة بلا بيعٍ مضاعف ═══════════ */

    public function test_files_carry_the_same_rows_with_the_collection_in_its_own_column(): void
    {
        $this->order('POS-1', SalesChannel::POS, 10);
        $link = $this->pay($this->link(null, 25, $this->company));

        $csv = $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $this->assertStringContainsString('POS-1', $csv);
        $this->assertStringContainsString($this->receipt($link), $csv);
        $this->assertStringContainsString((string) $link->provider_transaction_id, $csv);
        $this->assertStringContainsString('المحصّل عبر رابط دفع', $csv);

        // ومجاميعُ الملفّ: الطلباتُ كما كانت، والتحصيلُ مجموعُه وحده باسمه
        $totals = collect(AllSalesList::export(Request::create('/'))->totals)->mapWithKeys(fn ($t) => [$t[0] => $t[1]]);
        $this->assertSame(10.0, (float) $totals->first(fn ($v, $k) => str_starts_with($k, 'إجمالي القيمة')));
        $this->assertSame(1, $totals->first(fn ($v, $k) => $k === 'عدد التحصيلات عبر روابط الدفع'));
        $this->assertSame(25.0, (float) $totals->first(fn ($v, $k) => str_contains($k, 'ليس من المبيعات')));

        // صفُّ التحصيل: «الإجمالي» فارغ والمبلغُ في عموده — فلا يُجمع عمودٌ واحد مرّتين
        // (لا فاصلةَ في قيم هذا الملفّ، فيُقسم السطرُ بها)
        $lines = preg_split('/\R/u', trim($csv)); // بلا u يقسم \R على البايت 0x85 داخل الحرف العربيّ
        $header = array_map(fn ($c) => trim(str_replace("\u{FEFF}", '', $c), '"'), explode(',', $lines[0]));
        $row = explode(',', (string) collect($lines)->first(fn ($l) => str_starts_with($l, $this->receipt($link).',')));
        $total = array_search('الإجمالي (OMR)', $header, true);
        $collected = array_search('المحصّل عبر رابط دفع (OMR)', $header, true);
        $this->assertNotFalse($total);
        $this->assertNotFalse($collected);
        $this->assertSame('', $row[$total]);
        $this->assertSame(25.0, (float) $row[$collected]);
        $orderRow = explode(',', (string) collect($lines)->first(fn ($l) => str_starts_with($l, 'POS-1,')));
        $this->assertSame([10.0, ''], [(float) $orderRow[$total], $orderRow[$collected]]);

        $this->get($this->exportRoute('admin.orders.xlsx'))->assertOk();
        $this->get($this->exportRoute('admin.orders.exportPdf'))->assertOk();
        // وكلُّ مصدرٍ منفردًا كما كان
        $pos = $this->get($this->exportRoute('admin.export.orders', ['channel' => SalesChannel::POS]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($this->receipt($link), $pos);
        $this->assertStringNotContainsString('المحصّل عبر رابط دفع', $pos);
    }

    /* ═══════════ ٩ — الإيرادُ والقيودُ والضريبة قبل التعديل وبعده ═══════════ */

    public function test_revenue_entries_and_tax_do_not_move_and_reading_writes_nothing(): void
    {
        $credit = $this->order('CR-1', SalesChannel::POS, 40, '2026-10-19 09:00:00', 'غير مدفوع', $this->company);
        $invoice = $this->invoiceOf($credit, $this->company);
        $this->order('POS-1', SalesChannel::POS, 10);
        $web = $this->invoiceOf($this->order('WEB-1', SalesChannel::WEBSITE, 30, '2026-10-19 10:00:00', 'غير مدفوع', $this->ministry), $this->ministry);
        $before = $this->sales();
        // الإيرادُ والضريبةُ وتكلفةُ المبيعات — والربحُ منها
        $figures = fn () => [
            round(Ledger::netRevenue($this->shop->id), 3),
            round(Ledger::outputTax($this->shop->id), 3),
            round(Ledger::balance($this->shop->id, 'cogs'), 3),
        ];
        $revenue = $figures();

        $link = $this->pay($this->link($invoice, null, $this->company));
        $this->pay($this->link($web, null, $this->ministry));
        $standalone = $this->pay($this->link(null, 25, $this->company));

        $after = $this->sales();
        $this->assertCount(3, array_filter($after['orders'], fn ($r) => ($r['kind'] ?? '') === AllSalesList::LINK));
        $this->assertSame([$before['totalCount'], $before['totalAmount'], $before['cancelledCount']], [$after['totalCount'], $after['totalAmount'], $after['cancelledCount']]);
        $this->assertSame($revenue, $figures(), 'تحرّك الإيرادُ أو الضريبةُ أو تكلفةُ المبيعات بتحصيل');

        $snapshot = $this->books();
        $this->sales();
        $this->sales(['sort' => 'total', 'dir' => 'asc', 'page' => 1]);
        $this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $link->id]))->assertOk();
        $this->get(route('admin.orders.collection', $standalone->id))->assertOk();
        $this->get(route('admin.orders.collection', $link->id))->assertRedirect();
        $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $this->get($this->exportRoute('admin.orders.xlsx'))->assertOk();
        $this->get($this->exportRoute('admin.orders.exportPdf'))->assertOk();
        $this->assertSame($snapshot, $this->books(), 'كتبت القراءةُ شيئًا');
    }

    /* ═══════════ ١٠ — الصلاحياتُ والمتاجرُ والفروع ═══════════ */

    public function test_staff_permissions_and_shop_isolation(): void
    {
        $invoice = $this->invoice(60);
        $mine = $this->pay($this->link($invoice));
        $alone = $this->pay($this->link(null, 10, $this->company));
        $theirs = PaymentLinks::create($this->other->id, Customer::create(['business_id' => $this->other->id, 'name' => 'عميل الجار', 'phone' => '93333333']), null, 99, null, null);
        $this->hook($this->obj($theirs), 'hmac-other');

        // المبيعاتُ بلا «المالية»: يرى ويفتح «عرض» ولا يُعطى سندَ القبض
        $sales = $this->staff('sales', 'sales@all-sales.om');
        $props = $this->sales([], $sales);
        $this->assertCount(2, array_filter($props['orders'], fn ($r) => ($r['kind'] ?? '') === AllSalesList::LINK));
        $this->assertFalse($props['mayOpenReceipts']);
        $this->actingAs($sales)->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $mine->id]))->assertOk();
        $this->actingAs($sales)->get(route('admin.orders.collection', $alone->id))->assertOk();
        $this->assertTrue($this->sales([], $this->staff('accountant', 'acc@all-sales.om'))['mayOpenReceipts']);

        // ومن لا «مبيعات» له لا يفتح الجدولَ ولا التحصيل
        $cashier = $this->staff('cashier', 'cashier@all-sales.om');
        $this->actingAs($cashier)->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('admin.orders.collection', $alone->id))->assertForbidden();
        $this->actingAs($cashier)->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $mine->id]))->assertForbidden();

        // والجارُ لا يُقرأ هنا، ولا يُفتح تحصيلُه بعنوانه، ولا يُلصق بفاتورتي
        $this->actingAs($this->owner);
        $this->assertNotContains('عميل الجار', array_column($this->sales()['orders'], 'customer'));
        $this->get(route('admin.orders.collection', $theirs->id))->assertNotFound();
        $this->assertNull($this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $theirs->id]))->viewData('page')['props']['collection']);
        // ولا تحصيلُ فاتورةٍ أخرى ولا مستقلٌّ يُلصق بهذه
        $this->assertNull($this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $alone->id]))->viewData('page')['props']['collection']);
        $this->assertNull($this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => 'x']))->viewData('page')['props']['collection']);

        // والجارُ يرى ما له وحده
        $this->assertSame(['عميل الجار'], array_column(array_filter($this->sales([], $this->stranger)['orders'], fn ($r) => ($r['kind'] ?? '') === AllSalesList::LINK), 'customer'));
        $this->actingAs($this->stranger)->get(route('admin.orders.collection', $alone->id))->assertNotFound();
    }

    /** كلُّ متجرٍ فُتحت فيه الروابطُ يرى تحصيلاتِه — بلا معرّفِ متجرٍ ثابت */
    public function test_every_shop_with_payment_links_reads_its_own_collections(): void
    {
        $mine = $this->pay($this->link(null, 10));
        $theirs = PaymentLinks::create($this->other->id, Customer::create(['business_id' => $this->other->id, 'name' => 'عميل الجار', 'phone' => '93333333']), null, 20, null, null);
        $this->hook($this->obj($theirs), 'hmac-other');
        $theirs = $theirs->fresh();

        // ومتجرٌ ثالثٌ لم تُفتح فيه الروابط: «كل المصادر» كما كانت
        [$third, $thirdOwner] = $this->business('متجر بلا روابط', 'third@all-sales.om', 'hmac-third');
        PaymentGateway::where('business_id', $third->id)->delete();

        foreach ([[$this->owner, $mine], [$this->stranger, $theirs]] as [$who, $link]) {
            $props = $this->sales([], $who);
            $this->assertTrue($props['withLinks'], $who->email);
            $this->assertSame(
                [CustomerPayment::findOrFail($link->customer_payment_id)->number],
                array_column(array_filter($props['orders'], fn ($r) => ($r['kind'] ?? '') === AllSalesList::LINK), 'id'),
                $who->email,
            );
        }
        $this->assertFalse($this->sales([], $thirdOwner)['withLinks']);
    }

    public function test_a_chosen_branch_reads_only_the_collections_of_its_invoices(): void
    {
        $invoice = $this->invoice(10);
        DB::table('customer_invoices')->where('id', $invoice->id)->update(['branch_id' => $this->muscat->id]);
        $inBranch = $this->pay($this->link($invoice->fresh()));
        $alone = $this->pay($this->link(null, 30));

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $ids = array_column($this->sales()['orders'], 'id');

        $this->assertContains($this->receipt($inBranch), $ids);
        $this->assertNotContains($this->receipt($alone), $ids, 'رابطٌ بلا فاتورةٍ نُسب إلى فرع');
        $this->assertSame('مسقط', collect($this->sales()['orders'])->firstWhere('id', $this->receipt($inBranch))['branch']);
    }

    /* ═══════════ ١١ — المراجعةُ النهائيّة ═══════════ */

    /** الصفوفُ تُقرأ بالطلب المعطى — لا بطلب HTTP الجاري (خللٌ وُجد في المراجعة) */
    public function test_rows_are_read_with_the_request_given_not_the_current_one(): void
    {
        $this->order('POS-1', SalesChannel::POS, 10);
        $link = $this->pay($this->link(null, 25, $this->company));

        // طلبُ HTTP الجاري بمُرشِّحٍ لا يطابق شيئًا — والملفُّ يُبنى بطلبٍ بلا مُرشِّح
        app()->instance('request', Request::create('/admin/orders', 'GET', ['q' => 'لا-يطابق-شيئًا']));

        $this->assertEqualsCanonicalizing(['POS-1', $this->receipt($link)], array_column(AllSalesList::rows(Request::create('/admin/orders')), 'id'));
    }

    /** دفعةٌ ثانيةٌ على الرابط نفسه: «مدفوع — يحتاج مراجعة»، لا تسويةٌ بنكيّة */
    public function test_a_payment_needing_review_reads_so_and_settles_nothing(): void
    {
        $link = $this->pay($this->link(null, 25, $this->company));
        $this->hook($this->obj($link)); // عمليّةٌ ثانيةٌ برقمٍ آخر على الرابط نفسه
        $this->assertNotSame([], (array) ($link->fresh()->meta['extra_transactions'] ?? []), 'لم تُسجَّل الدفعةُ الثانية');

        $row = collect($this->sales()['orders'])->firstWhere('kind', AllSalesList::LINK);
        $this->assertTrue($row['review']);
        $this->assertTrue($this->get(route('admin.orders.collection', $link->id))->viewData('page')['props']['collection']['review']);
        $this->assertStringContainsString('مدفوع — يحتاج مراجعة', $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent());
        $this->assertSame(1, DB::table('customer_payments')->where('business_id', $this->shop->id)->count(), 'كُتب تحصيلٌ ثانٍ');
        $this->assertSame(0, DB::table('paymob_settlements')->count());
    }

    /** التحصيلُ الملغى لا يُفتح، وفاتورتُه تُفتح بباقيها */
    public function test_a_cancelled_collection_opens_nothing_and_its_invoice_stays_open(): void
    {
        $invoice = $this->invoice(40);
        $link = $this->pay($this->link($invoice));
        CustomerPayments::cancel(CustomerPayment::findOrFail($link->customer_payment_id), 'استُرجع', $this->owner->id);

        $this->assertNotContains($this->receipt($link), array_column($this->sales()['orders'], 'id'));
        $this->get(route('admin.orders.collection', $link->id))->assertNotFound();
        $props = $this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $link->id]))->assertOk()->viewData('page')['props'];
        $this->assertNull($props['collection']);
        $this->assertSame(round((float) $invoice->total, 3), round((float) $props['invoice']['outstanding'], 3));
    }

    /**
     * الفرعُ المختار مُرشِّحُ قوائم — والتفاصيلُ بمتجرها كما في النظام كلِّه.
     *
     * الطلبُ والفاتورةُ والسند تُفتح بمتجرها وإن اختير فرعٌ آخر، وإسنادُ الموظّف
     * إلى فرعٍ (`branch_user`) قيدُ البيع في الصندوق لا قراءة المبيعات. فالتحصيلُ
     * يُفتح كما تُفتح فاتورتُه — ويُحرس هنا أنّه لا يفترق عنها.
     */
    public function test_view_follows_the_shop_not_the_chosen_branch_like_every_detail_screen(): void
    {
        $sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $invoice = $this->invoice(10);
        DB::table('customer_invoices')->where('id', $invoice->id)->update(['branch_id' => $sohar->id]);
        $onSohar = $this->pay($this->link($invoice->fresh()));
        $alone = $this->pay($this->link(null, 30));
        $order = $this->order('SOH-1', SalesChannel::POS, 15);
        DB::table('orders')->where('id', $order->id)->update(['branch_id' => $sohar->id, 'branch' => 'صحار']);

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $ids = array_column($this->sales()['orders'], 'id');
        foreach (['SOH-1', $this->receipt($onSohar), $this->receipt($alone)] as $id) {
            $this->assertNotContains($id, $ids, "قائمةُ مسقط تعرض {$id}");
        }

        // والتفاصيلُ بمتجرها — الطلبُ والفاتورةُ كما كانا، والتحصيلُ مثلُهما
        $this->get(route('admin.orders.show', 'SOH-1'))->assertOk();
        $props = $this->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $onSohar->id]))->assertOk()->viewData('page')['props'];
        $this->assertSame($onSohar->id, $props['collection']['link_id']);
        $this->get(route('admin.orders.collection', $alone->id))->assertOk();

        // وموظّفُ مبيعاتٍ مُسنَدٌ إلى مسقط وحدها، بلا «مالية»: يقرأ ما يقرأ الطلبَ والفاتورة، ولا سندَ له
        $sales = $this->staff('sales', 'branch-sales@all-sales.om');
        $sales->branches()->attach($this->muscat->id);
        $this->actingAs($sales)->get(route('admin.orders.show', 'SOH-1'))->assertOk();
        $this->actingAs($sales)->get(route('admin.orders.collection', $alone->id))->assertOk();
        $own = $this->actingAs($sales)->get(route('admin.customerInvoices.show', ['id' => $invoice->id, 'link' => $onSohar->id]))->assertOk()->viewData('page')['props'];
        $this->assertSame($onSohar->id, $own['collection']['link_id']);
        $this->assertFalse($own['mayOpenReceipts']);
        $this->actingAs($sales)->get(route('admin.customerPayments.show', $onSohar->customer_payment_id))->assertForbidden();
    }

    /** الملفّاتُ الثلاثة بالفرع والفترة صفوفُ الشاشة نفسُها — والمجموعان منفصلان */
    public function test_files_follow_the_chosen_branch_and_period_and_never_double_count(): void
    {
        $sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        $this->order('IN-1', SalesChannel::POS, 10, '2026-10-20 08:00:00');
        $this->order('OLD-1', SalesChannel::POS, 20, '2026-09-01 08:00:00');
        $mine = $this->invoice(30);
        DB::table('customer_invoices')->where('id', $mine->id)->update(['branch_id' => $this->muscat->id]);
        $inBranch = $this->pay($this->link($mine->fresh()));
        $theirs = $this->invoice(40);
        DB::table('customer_invoices')->where('id', $theirs->id)->update(['branch_id' => $sohar->id]);
        $this->pay($this->link($theirs->fresh()));
        $this->pay($this->link(null, 25, $this->company));

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $q = ['from' => '2026-10-15', 'to' => '2026-10-31'];
        $screen = array_column($this->sales($q)['orders'], 'id');
        $this->assertSame([$this->receipt($inBranch), 'IN-1'], $screen);

        // CSV: العمودُ الأوّل صفوفُ الشاشة بترتيبها
        $csv = $this->get($this->exportRoute('admin.export.orders', $q))->assertOk()->streamedContent();
        $lines = array_slice(preg_split('/\R/u', trim($csv)), 1);
        $this->assertSame($screen, array_map(fn ($l) => trim(explode(',', $l)[0], '"'), $lines));

        // Excel: الصفوفُ نفسُها وعمودُ المحصّل ومجموعُه باسمه
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $this->get($this->exportRoute('admin.orders.xlsx', $q))->assertOk()->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($file);
        foreach ([...$screen, 'المحصّل عبر رابط دفع', 'ليس من المبيعات', (string) $inBranch->provider_transaction_id] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('OLD-1', $text);
        $this->assertStringNotContainsString($this->receipt(CustomerPaymentLink::where('customer_invoice_id', $theirs->id)->firstOrFail()), $text);

        // PDF: ما يُرسل إلى ورقته — الصفوفُ، والمبيعاتُ وحدها في إجماليّها، والتحصيلُ وحده
        $sent = null;
        View::composer('pdf.orders-report', function ($view) use (&$sent) {
            $sent = $view->getData();
        });
        $this->get($this->exportRoute('admin.orders.exportPdf', $q))->assertOk();
        $this->assertSame($screen, array_column($sent['rows'], 'id'));
        $this->assertSame([1, 10.0], [$sent['ordersCount'], (float) $sent['total']]);
        $this->assertSame(['count' => 1, 'amount' => round((float) $mine->total, 3)], array_intersect_key($sent['links'], ['count' => 0, 'amount' => 0]));
    }

    /* ═══════════ ما حول التحصيل: منشئُه وفرعُه ومنتجاتُه وموعدُه — من مصدره ═══════════ */

    private function linkRow(CustomerPaymentLink $link): array
    {
        return collect($this->sales()['orders'])->firstWhere('link_id', $link->id) ?? $this->fail('لا صفَّ للتحصيل');
    }

    public function test_a_link_row_names_its_maker_and_reads_its_invoice_lines_and_preparation(): void
    {
        $sales = $this->staff('sales', 'maker@all-sales.om');
        $invoice = $this->invoice(60);
        $link = $this->pay(PaymentLinks::create($this->shop->id, $this->ministry, $invoice, null, null, $sales->id));

        $row = $this->linkRow($link);
        $this->assertSame('موظّف sales', $row['creator']);
        $this->assertSame('منتج واحد — قطعتان', $row['products']['label'], 'بنودُ الفاتورة لم تُقرأ');
        $this->assertNull($row['handover'], 'اختُرع موعدٌ لفاتورةٍ بلا تجهيزٍ ولا طلب');

        // ومهمّةُ تجهيز الفاتورة تقول فرعَها وموعدَها
        DB::table('customer_invoice_preparations')->insert([
            'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'branch_id' => $this->muscat->id,
            'fulfillment_type' => 'delivery', 'scheduled_for' => '2026-10-22 17:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->linkRow($link);
        $this->assertSame('مسقط', $row['branch']);
        $this->assertSame('توصيل — 22/10/2026، 5:00 م', $row['handover']['label']);

        // ومنشئٌ من متجرٍ آخر لا يُقرأ اسمُه هنا
        DB::table('customer_payment_links')->where('id', $link->id)->update(['created_by' => $this->stranger->id]);
        $this->assertNull($this->linkRow($link)['creator']);
    }

    public function test_a_link_on_an_invoice_of_orders_reads_its_lines_once_and_the_orders_time(): void
    {
        $order = $this->order('CR-1', SalesChannel::POS, 30, payment: 'غير مدفوع', customer: $this->ministry);
        $order->update(['fulfillment_type' => 'pickup', 'scheduled_for' => '2026-10-23 16:00:00']);
        $invoice = $this->invoiceOf($order, $this->ministry);
        $link = $this->pay(PaymentLinks::create($this->shop->id, $this->ministry, $invoice, null, null, $this->owner->id));

        $row = $this->linkRow($link);
        $this->assertSame('صاحب ورود الجدول', $row['creator']);
        $this->assertSame(['CR-1'], $row['orders']);
        // بندُ الفاتورة وحده — لا بندُ الطلب فوقه
        $this->assertSame(['lines' => 1, 'pieces' => 2], array_intersect_key($row['products'], ['lines' => 0, 'pieces' => 0]));
        $this->assertSame('استلام من المحل — 23/10/2026، 4:00 م', $row['handover']['label']);
        $this->assertSame('مسقط', $row['branch']);
    }

    public function test_an_invoice_of_orders_from_two_branches_and_two_times_says_so_with_details(): void
    {
        $khuwair = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        $a = $this->order('CR-1', SalesChannel::POS, 30, payment: 'غير مدفوع', customer: $this->ministry);
        $b = $this->order('CR-2', SalesChannel::POS, 20, payment: 'غير مدفوع', customer: $this->ministry);
        $a->update(['fulfillment_type' => 'delivery', 'scheduled_for' => '2026-10-23 10:00:00']);
        $b->update(['branch_id' => $khuwair->id, 'branch' => 'الخوير', 'fulfillment_type' => 'pickup', 'scheduled_for' => '2026-10-24 18:00:00']);
        $invoice = CustomerInvoices::consolidate($this->ministry, [$a->id, $b->id], [], $this->owner->id);
        $invoice = ($invoice->status === CustomerInvoice::ISSUED ? $invoice : CustomerInvoices::issue($invoice, $this->owner->id))->fresh();
        $link = $this->pay($this->link($invoice));

        $row = $this->linkRow($link);
        $this->assertSame('فروع متعددة', $row['branch']);
        $this->assertSame(['مسقط', 'الخوير'], $row['branches']);
        $this->assertSame('مواعيد متعددة', $row['handover']['label']);
        $this->assertSame(['CR-1: توصيل — 23/10/2026، 10:00 ص', 'CR-2: استلام من المحل — 24/10/2026، 6:00 م'], $row['handover']['details']);
        $this->assertSame(2, $row['products']['lines']);

        // والملفُّ يقولها بأسمائها
        $csv = $this->get($this->exportRoute('admin.export.orders'))->assertOk()->streamedContent();
        $this->assertStringContainsString('فروع متعددة: مسقط، الخوير', $csv);
        $this->assertStringContainsString('مواعيد متعددة: CR-1: توصيل', $csv);
    }

    public function test_a_standalone_link_invents_no_branch_products_or_time(): void
    {
        $alone = $this->pay(PaymentLinks::create($this->shop->id, $this->company, null, 10, 'دفعة مقدّمة', $this->owner->id));

        $row = $this->linkRow($alone);
        $this->assertSame('صاحب ورود الجدول', $row['creator']);
        $this->assertSame([null, [], null, null], [$row['branch'], $row['branches'], $row['products'], $row['handover']]);
        $this->assertSame(0, CustomerInvoice::count(), 'أُنشئت فاتورةٌ لرابطٍ مستقلّ');

        // والملفُّ المستقلّ يقول «لا توجد منتجات مرتبطة»، وصفحتُه تقول منشئَه
        $csv = $this->get($this->exportRoute('admin.export.orders', ['channel' => PaidLinksList::SOURCE]))->assertOk()->streamedContent();
        $this->assertStringContainsString('لا توجد منتجات مرتبطة', $csv);
        $this->assertStringContainsString('صاحب ورود الجدول', $csv);
        $this->assertSame('صاحب ورود الجدول', $this->get(route('admin.orders.collection', $alone->id))->assertOk()->viewData('page')['props']['collection']['creator']);
    }

    public function test_link_rows_ask_a_fixed_number_of_questions_however_many(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('admin.orders.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->pay($this->link($this->invoice(20)));
        $count();
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->pay($this->link($this->invoice(10 + $i)));
            $this->pay($this->link(null, 5 + $i, $this->company));
        }

        $this->assertSame($few, $count(), 'كلُّ تحصيلٍ يسأل القاعدةَ وحده');
    }
}
