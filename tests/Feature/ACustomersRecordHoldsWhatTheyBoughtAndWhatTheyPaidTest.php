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
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Exports\Workbook;
use App\Support\Ledger;
use App\Support\Lists\CustomersList;
use App\Support\MarketingSettings;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\ReportingPeriod;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سجلُّ العميل — كلُّ ما اشتراه وكلُّ ما دفعه، ورقمٌ واحد في كلّ شاشة.
 *
 *   - طلبُ الموقع يعرف زبونَه برقمه مطبَّعًا في متجره، ولا يكرّره؛
 *   - الفاتورةُ برابط Paymob شراءٌ واحد، وسدادُها يُغيّر حالَها لا عددَها؛
 *   - الرابطُ المستقلّ تحصيلٌ ورصيدٌ — لا شراء؛
 *   - البيعُ الآجل شراءٌ يومَ وقع، وسدادُ القديم لا يُضيف شراءً؛
 *   - الفاتورةُ التي تغطّي طلبًا لا تُعدّ شراءً ثانيًا؛
 *   - والقائمةُ والصفحةُ والملفُّ والبطاقةُ والتقرير تقول الرقمَ نفسه.
 */
class ACustomersRecordHoldsWhatTheyBoughtAndWhatTheyPaidTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $branch;

    private Product $bouquet;

    private int $paymobOrder = 0;

    private int $tx = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Muscat'));
        app()->setLocale('ar');

        // لا متجرَ هنا يُقرأ «RIBBON» بقوائم الإعداد — انظر `storefront.ribbon_*`
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        [$this->shop, $this->owner] = $this->business('زهرة السجلّ', 'owner@customer-record.om', 'hmac-shop', 'zahra-record');
        [$this->other] = $this->business('متجر الجار', 'other@customer-record.om', 'hmac-other', 'jar-record');
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Branch::create(['business_id' => $this->other->id, 'name' => 'صحار']);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م',
        ]);
        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 0,
            'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true,
        ]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => function () {
                $n = ++$this->paymobOrder;

                return Http::response([
                    'id' => 690000 + $n, 'shorten_url' => 'https://paymob.link/r'.$n,
                    'created_at' => '2027-02-10T10:00:00', 'expires_at' => '2027-03-10T23:59:00',
                    'state' => 'created', 'order' => 737000000 + $n,
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

    private function business(string $name, string $email, string $hmac, string $slug): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895550000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
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

    private function invoice(float $total, ?string $issuedAt = null, ?Customer $customer = null): CustomerInvoice
    {
        $draft = CustomerInvoices::create($this->shop->id, $customer ?? $this->customer, ['issued_at' => $issuedAt ?? now()->toDateString()], [
            ['description' => 'توريد زهور', 'quantity' => 1, 'unit_price' => $total, 'tax_rate' => 0],
        ], $this->owner->id);

        return CustomerInvoices::issue($draft, $this->owner->id)->fresh();
    }

    private function order(float $total, string $channel = 'pos', string $payment = 'مدفوع', string $method = 'نقدي', ?Customer $customer = null, ?string $at = null): Order
    {
        $customer ??= $this->customer;
        $order = Order::create([
            'business_id' => $customer->business_id, 'branch_id' => $this->branch->id, 'channel' => $channel,
            'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => $payment, 'is_held' => false,
            'payment_method' => $method, 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => $at ? Carbon::parse($at) : now(),
        ]);
        Books::recordSale($order);

        return $order;
    }

    private function link(?CustomerInvoice $invoice, ?float $amount = null): CustomerPaymentLink
    {
        return PaymentLinks::create($this->shop->id, $this->customer, $invoice, $amount, null, $this->owner->id);
    }

    private function obj(CustomerPaymentLink $link): array
    {
        return [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'currency' => $link->currency, 'id' => ++$this->tx,
            'is_refunded' => false, 'is_voided' => false, 'pending' => false, 'success' => true,
            'is_live' => (bool) $link->is_live,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
        ];
    }

    private function hook(array $obj): void
    {
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function checkout(string $name, string $phone, int $qty = 1): Order
    {
        $before = Order::where('business_id', $this->shop->id)->max('id') ?? 0;

        $this->postJson('/s/zahra-record/checkout', [
            'items' => [['id' => $this->bouquet->id, 'variant_id' => null, 'qty' => $qty, 'addons' => []]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => $name, 'phone' => $phone,
            'date' => '2027-02-12', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->where('id', '>', $before)->where('channel', 'website')->firstOrFail();
    }

    /** صفُّ القائمة كما تُرسله الشاشة */
    private function listRow(Customer $customer): array
    {
        $rows = $this->get(route('admin.customers.index', ['q' => $customer->phone]))->assertOk()->viewData('page')['props']['customers'];

        return collect($rows)->firstWhere('id', $customer->id) ?? $this->fail('العميلُ غائبٌ عن قائمته');
    }

    private function page(Customer $customer): array
    {
        return $this->get(route('admin.customers.show', $customer->id))->assertOk()->viewData('page')['props'];
    }

    /** صفُّ الملفّ — التصديرُ الذي يُرسَل */
    private function fileRow(Customer $customer): array
    {
        $dataset = CustomersList::export(Request::create('/', 'GET', ['q' => $customer->phone]));

        foreach (($dataset->rows)() as $row) {
            if ($row[1] === $customer->phone) {
                return array_combine(array_keys($dataset->columns), $row);
            }
        }

        $this->fail('العميلُ غائبٌ عن ملفّه');
    }

    private function reportTotal(Customer $customer): ?float
    {
        $row = collect(Demo::customerSpend(ReportingPeriod::all(), $this->shop->id))->firstWhere('name', $customer->name);

        return $row['total'] ?? null;
    }

    /* ═════════════ الموقع: زبونٌ واحد برقمه في متجره ═════════════ */

    public function test_a_website_order_finds_its_customer_by_the_normalized_phone_and_never_crosses_shops(): void
    {
        // زبونةٌ بالرقم نفسه في متجر الجار — لا يُربط بها طلبٌ من هنا
        $neighbour = Customer::create(['business_id' => $this->other->id, 'name' => 'مريم', 'phone' => '99110001']);

        $first = $this->checkout('مريم', '96899110001');
        $mariam = Customer::where('business_id', $this->shop->id)->findOrFail($first->customer_id);

        // والرقمُ نفسُه بكتابةٍ أخرى زبونٌ واحد
        $second = $this->checkout('Mariam', '+968 9911 0001', 2);
        $this->assertSame($mariam->id, (int) $second->customer_id, 'رقمٌ واحدٌ بكتابتين صار زبونين');

        // واسمٌ مثلُه برقمٍ آخر زبونٌ آخر — لا دمجَ بالاسم
        $namesake = $this->checkout('مريم', '96899110002');
        $this->assertNotSame($mariam->id, (int) $namesake->customer_id, 'دُمج زبونان لتشابه الاسم');

        $this->assertSame(3, Customer::where('business_id', $this->shop->id)->count());
        $this->assertSame(0, Order::where('customer_id', $neighbour->id)->count(), 'طلبٌ من متجرٍ رُبط بزبونِ غيره');

        $row = $this->listRow($mariam);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(60.0, $row['total_spent']);
        $this->assertSame(['kind' => 'order', 'id' => $second->id, 'number' => $second->number, 'amount' => 40.0, 'date' => '2027-02-10'], $row['last_purchase']);

        $history = $this->page($mariam)['history'];
        $this->assertSame([$second->number, $first->number], array_column($history, 'number'));
        $this->assertSame(['website', 'website'], array_column($history, 'source'));
    }

    public function test_the_website_locks_its_shop_before_it_asks_who_owns_the_phone(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('القفلُ يُكتب `FOR UPDATE` على PostgreSQL وحدها — SQLite تُسلسل الكتابة بنفسها.');
        }

        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = strtolower($q->sql);
        });

        $this->checkout('سالم', '96899110003');

        $lock = collect($seen)->search(fn ($s) => str_contains($s, 'from "businesses"') && str_contains($s, 'for update'));
        $ask = collect($seen)->search(fn ($s) => str_contains($s, 'from "customers"') && str_contains($s, '"phone" is not null'));

        $this->assertNotFalse($ask, 'لم يُسأل عن الرقم');
        $this->assertNotFalse($lock, 'لا قفلَ على صفّ المتجر');
        $this->assertLessThan($ask, $lock, 'سُئل عن الرقم قبل القفل — طلبان معًا يكتبان زبونين');
    }

    /* ═════════════ الفاتورةُ برابط Paymob ═════════════ */

    public function test_an_invoice_paid_through_paymob_links_is_one_purchase_that_turns_partial_then_paid(): void
    {
        $invoice = $this->invoice(100);

        $row = $this->listRow($this->customer);
        $this->assertSame(1, $row['orders']);
        $this->assertSame(100.0, $row['total_spent']);
        $this->assertSame(['kind' => 'invoice', 'id' => $invoice->id, 'number' => $invoice->number, 'amount' => 100.0, 'date' => '2027-02-10'], $row['last_purchase']);
        $this->assertSame('غير مدفوع', $this->page($this->customer)['history'][0]['pay_state']);

        // دفعةٌ جزئيّة برابط — ثمّ الإشعارُ نفسُه مرّةً ثانية
        $first = $this->link($invoice, 40);
        $obj = $this->obj($first);
        $this->hook($obj);
        $this->hook($obj);

        $page = $this->page($this->customer);
        $this->assertSame('مدفوع جزئيًا', $page['history'][0]['pay_state']);
        $this->assertSame(['count' => 1, 'sales' => 100.0, 'collected' => 40.0, 'outstanding' => 60.0, 'cancelled' => 0], $page['historyTotals']);
        $this->assertCount(1, $page['collections'], 'إشعارٌ مكرّرٌ كتب تحصيلًا ثانيًا');
        $this->assertSame(1, CustomerPayment::where('customer_id', $this->customer->id)->count());
        $this->assertSame('Paymob', $page['collections'][0]['method']);
        $this->assertSame(40.0, $page['collections'][0]['amount']);
        $this->assertSame($first->fresh()->reference, $page['collections'][0]['link']);
        $this->assertSame([['id' => $invoice->id, 'number' => $invoice->number, 'amount' => 40.0, 'orders' => []]], $page['collections'][0]['invoices']);

        // والباقي برابطٍ ثانٍ — السدادُ يُغيّر الحالَ لا العدد
        $this->hook($this->obj($this->link($invoice, 60)));

        $page = $this->page($this->customer);
        $this->assertSame('مدفوع', $page['history'][0]['pay_state']);
        $this->assertSame(['count' => 1, 'sales' => 100.0, 'collected' => 100.0, 'outstanding' => 0.0, 'cancelled' => 0], $page['historyTotals']);
        $this->assertSame(1, $page['customer']['orders']);
        $this->assertSame(100.0, $page['customer']['total_spent']);
        $this->assertCount(2, $page['collections']);
    }

    /* ═════════════ الرابطُ المستقلّ ═════════════ */

    public function test_a_standalone_link_is_a_collection_and_a_credit_never_a_purchase(): void
    {
        $this->hook($this->obj($this->link(null, 25)));
        $this->link(null, 30); // معلّقٌ لم يُدفع

        $row = $this->listRow($this->customer);
        $this->assertSame(0, $row['orders']);
        $this->assertSame(0.0, $row['total_spent']);
        $this->assertSame('—', $row['last_order']);
        $this->assertNull($row['last_purchase']);

        $page = $this->page($this->customer);
        $this->assertSame([], $page['history']);
        $this->assertCount(1, $page['collections'], 'رابطٌ لم يُدفع ظهر تحصيلًا');
        $this->assertSame(25.0, $page['collections'][0]['amount']);
        $this->assertSame(25.0, $page['collections'][0]['credit'], 'الرابطُ المستقلّ رصيدٌ للعميل');
        $this->assertSame([], $page['collections'][0]['invoices']);
        $this->assertNull($this->reportTotal($this->customer), 'التقريرُ عدّ الرابطَ المستقلّ شراءً');
    }

    /* ═════════════ الآجلُ وسدادُ القديم ═════════════ */

    public function test_a_credit_sale_counts_when_sold_and_paying_an_old_invoice_adds_no_purchase(): void
    {
        $old = $this->invoice(60, '2027-01-15');
        Carbon::setTestNow(Carbon::parse('2027-02-01 09:00:00', 'Asia/Muscat'));
        $credit = $this->order(50, 'pos', 'غير مدفوع', 'آجل');
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Muscat'));

        // سدادُ الفاتورة القديمة اليوم — دفعةٌ لا شراء
        CustomerPayments::record($this->shop->id, $this->customer, 60,
            ['method' => 'تحويل', 'occurred_at' => today()->toDateString(), 'auto_allocate' => false], [$old->id => 60], $this->owner->id);

        $row = $this->listRow($this->customer);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(110.0, $row['total_spent']);
        $this->assertSame('2027-02-01', $row['last_order'], 'آخرُ شراءٍ صار يومَ الدفعة');
        $this->assertSame($credit->number, $row['last_purchase']['number']);

        $page = $this->page($this->customer);
        $states = array_column($page['history'], 'pay_state', 'number');
        $this->assertSame('غير مدفوع', $states[$credit->number], 'البيعُ الآجل شراءٌ يومَ وقع ولو لم يُسدَّد');
        $this->assertSame('مدفوع', $states[$old->number]);
        $this->assertSame('تحويل', $page['collections'][0]['method']);
        $this->assertSame($old->number, $page['collections'][0]['invoices'][0]['number']);
    }

    /* ═════════════ فاتورةٌ تغطّي طلبًا ═════════════ */

    public function test_an_invoice_covering_an_order_is_one_purchase_and_its_payment_reaches_the_order(): void
    {
        $order = $this->order(80, 'pos', 'غير مدفوع', 'آجل');
        $invoice = CustomerInvoices::issue(CustomerInvoices::fromOrder($order, [], $this->owner->id), $this->owner->id);

        $row = $this->listRow($this->customer);
        $this->assertSame(1, $row['orders'], 'الطلبُ وفاتورتُه عُدّا شراءين');
        $this->assertSame(80.0, $row['total_spent']);

        $this->hook($this->obj($this->link($invoice->fresh(), 80)));

        $page = $this->page($this->customer);
        $this->assertCount(1, $page['history']);
        $this->assertSame('order', $page['history'][0]['kind']);
        $this->assertSame(['id' => $invoice->id, 'number' => $invoice->number], $page['history'][0]['cover']);
        $this->assertSame('مدفوع', $page['history'][0]['pay_state']);
        $this->assertSame([['id' => $order->id, 'number' => $order->number]], $page['collections'][0]['invoices'][0]['orders']);
        $this->assertSame(80.0, $this->reportTotal($this->customer));
    }

    /* ═════════════ الإلغاءُ والإشعارُ الدائن ═════════════ */

    public function test_cancellations_stay_in_the_record_and_leave_the_total_and_a_credit_note_comes_off(): void
    {
        $kept = $this->invoice(50);
        CustomerInvoices::creditNote($kept, 10, 0, 'مردود', $this->owner->id);
        CustomerInvoices::cancel($this->invoice(20), 'خطأ', $this->owner->id);
        $this->order(30)->update(['status' => Order::CANCELLED]);

        $row = $this->listRow($this->customer);
        $this->assertSame(1, $row['orders']);
        $this->assertSame(40.0, $row['total_spent']);

        $page = $this->page($this->customer);
        $this->assertCount(3, $page['history'], 'الملغى مُحي من سجلّ العميل');
        $this->assertSame(2, collect($page['history'])->where('cancelled', true)->count());
        $this->assertSame(10.0, collect($page['history'])->firstWhere('key', 'invoice:'.$kept->id)['credited']);
        $this->assertSame(40.0, $page['historyTotals']['sales']);
        $this->assertSame(40.0, $this->reportTotal($this->customer));
    }

    /* ═════════════ رقمٌ واحد في كلّ مكان ═════════════ */

    public function test_the_list_the_page_the_file_the_cards_and_the_report_say_one_number(): void
    {
        $this->order(50, 'pos');
        $this->order(30, 'website', 'مدفوع', 'Paymob');
        $manual = $this->invoice(100, '2027-02-05');
        CustomerInvoices::creditNote($manual, 10, 0, 'مردود', $this->owner->id);
        $covered = $this->order(40, 'pos', 'غير مدفوع', 'آجل');
        CustomerInvoices::issue(CustomerInvoices::fromOrder($covered, [], $this->owner->id), $this->owner->id);
        $this->hook($this->obj($this->link(null, 25)));

        // وما في متجر الجار لا يصل — ولو حمل معرّفَ هذا العميل
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'غريب', 'phone' => '93334444']);
        $this->order(500, 'pos', 'مدفوع', 'نقدي', $stranger)->forceFill(['customer_id' => $this->customer->id])->save();

        $expected = 210.0; // 50 + 30 + (100 − 10) + 40 — والرابطُ المستقلّ ليس شراءً
        $row = $this->listRow($this->customer);
        $page = $this->page($this->customer);
        $file = $this->fileRow($this->customer);

        $this->assertSame($expected, $row['total_spent'], 'القائمة');
        $this->assertSame(4, $row['orders']);
        $this->assertSame($expected, $page['customer']['total_spent'], 'بطاقةُ الصفحة');
        $this->assertSame(4, $page['customer']['orders']);
        $this->assertSame($expected, $page['historyTotals']['sales'], 'مجموعُ سجلّ المشتريات');
        $this->assertSame($expected, round(collect($page['history'])->where('cancelled', false)->sum(fn ($r) => $r['amount'] - ($r['credited'] ?? 0)), 3), 'صفوفُ السجلّ');
        $this->assertSame($expected, (float) $file[Workbook::money('إجمالي المشتريات')], 'الملفّ');
        $this->assertSame(4, $file[__('عدد المشتريات')]);
        $this->assertSame($expected, $this->reportTotal($this->customer), 'تقريرُ الأكثر إنفاقًا');
        $this->assertSame($expected, round((float) Demo::customerStats()['total_purchases'], 3), 'بطاقةُ القائمة');

        // ولا يُفتح عميلُ متجرٍ آخر من هنا
        $this->get(route('admin.customers.show', $stranger->id))->assertNotFound();
    }

    public function test_the_list_sorts_by_purchases_not_by_orders(): void
    {
        $byInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الفواتير', 'phone' => '95550001']);
        $byOrder = Customer::create(['business_id' => $this->shop->id, 'name' => 'زبون الصندوق', 'phone' => '95550002']);
        $this->invoice(300, null, $byInvoice);
        $this->order(100, 'pos', 'مدفوع', 'نقدي', $byOrder);

        $rows = $this->get(route('admin.customers.index', ['sort' => 'total_spent', 'dir' => 'desc']))
            ->assertOk()->viewData('page')['props']['customers'];

        $this->assertSame([$byInvoice->id, $byOrder->id], array_slice(array_column($rows, 'id'), 0, 2));
    }
}
