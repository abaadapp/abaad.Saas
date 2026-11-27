<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\BoutiqueReturns;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Ledger;
use App\Support\Lists\SalesRegister;
use App\Support\Lists\TransactionsList;
use App\Support\MarketingSettings;
use App\Support\OrderCorrection;
use App\Support\OrderPaymentState;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الطلبُ يقول ما تقوله تحصيلاتُه — في كلّ شاشةٍ يُقرأ فيها حالُ سداده.
 *
 * طلبُ الدفع عند الاستلام يُكتب «غير مدفوع» يومَ يُطلب، ويُقبض مالُه بعدها على
 * فاتورة العميل. فكانت صفحةُ الطلب والصندوقُ ولوحةُ التجهيز والإيصال تقول
 * «غير مدفوع» بعد أن قُبض كلُّه — واللوحةُ تطلب من المندوب أن يحصّله ثانيةً.
 *
 * والحالُ تُشتقّ من التحصيلات (`OrderPaymentState`) بقاعدة سجلّ كل المبيعات
 * نفسها، ولا يُكتب شيء: حقلُ الطلب يبقى كما كُتب، ولا صفَّ ولا قيد.
 */
class AnOrderShowsWhatItsCollectionsSayTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Product $vase;

    private Product $ring;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2027-02-10 10:00:00', 'Asia/Muscat'));
        $this->shop = Business::create(['name' => 'متجر السداد', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'paystate', 'tier' => 'gold',
            'storefront_theme' => Business::THEME_RIBBON, 'boutiques_enabled' => true, 'order_notes_and_edits_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        foreach (['vat_enabled' => '0', 'pay_credit' => '1'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'paystate@abaad.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'ع 1']);
        // صنفُ المتجر 15.000 وصنفُ بوتيك لمى 10.000 — الطلب 25.000
        $this->vase = Product::create(['business_id' => $this->shop->id, 'name' => 'مزهرية', 'price' => 15, 'cost' => 5, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $this->ring = Product::create(['business_id' => $this->shop->id, 'name' => 'خاتم', 'price' => 10, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $lama->id]);
        $this->actingAs($this->owner);
        session(['current_branch' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cod(string $phone = '96899110001'): Order
    {
        return WebCheckout::place($this->shop->fresh(), [
            'items' => [['id' => $this->vase->id, 'variant_id' => null, 'qty' => 1], ['id' => $this->ring->id, 'variant_id' => null, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => $phone, 'date' => '2027-02-11',
        ], 'ar');
    }

    private function invoice(Order ...$orders): CustomerInvoice
    {
        $customer = Customer::findOrFail($orders[0]->fresh()->customer_id);

        return CustomerInvoices::consolidate($customer, array_map(fn (Order $o) => $o->id, $orders), [], $this->owner->id);
    }

    private function collect(CustomerInvoice $invoice, float $amount, array $extra = []): CustomerPayment
    {
        $customer = Customer::findOrFail($invoice->customer_id);

        return CustomerPayments::record($this->shop->id, $customer, $amount, $extra + ['method' => 'نقدي'], [$invoice->id => $amount], $this->owner->id);
    }

    /** ما يكتبه النظامُ من مالٍ وحركة — يُعدّ قبل القراءة وبعدها */
    private function written(): array
    {
        return [
            'payments' => DB::table('customer_payments')->count(),
            'allocations' => DB::table('customer_payment_allocations')->count(),
            'transactions' => DB::table('transactions')->count(),
            'entries' => DB::table('journal_entries')->count(),
            'notes' => DB::table('customer_credit_notes')->count(),
            'payment_status' => DB::table('orders')->orderBy('id')->pluck('payment_status')->all(),
        ];
    }

    /** حالُ الطلب كما تقوله كلُّ شاشة */
    private function screens(Order $order): array
    {
        $order = $order->fresh();
        $admin = $this->get(route('admin.orders.show', $order->number))->assertOk()->viewData('page')['props']['order']['pay_state'];
        $pos = $this->withSession(['current_branch' => $this->branch->id])
            ->get(route('pos.order-details', $order->number))->assertOk()->viewData('page')['props']['order']['pay_state'];
        $board = collect($this->get(route('admin.preparation.index'))->assertOk()->viewData('page')['props']['orders'])
            ->firstWhere('number', $order->number)['pay_state'] ?? null;
        $strip = DocumentRenderer::saleStrip($this->shop->id, $order->load('items'),
            array_merge(DocumentTemplates::settings($this->shop->id, 'sale'), ['show_payment_status' => true]), 80);
        $register = collect(SalesRegister::rows(Request::create('/admin/sales', 'GET')))
            ->firstWhere('number', $order->number)['pay_state'] ?? null;

        return compact('admin', 'pos', 'board', 'strip', 'register');
    }

    /** كلُّ شاشةٍ تقول الشيءَ نفسَه — والسجلُّ معها */
    private function assertSays(Order $order, string $state, float $paid, float $due, ?string $label = null, bool $onBoard = true): array
    {
        $before = $this->written();
        $s = $this->screens($order);
        $this->assertSame($before, $this->written(), 'قراءةُ الشاشات كتبت شيئًا');

        $label ??= $state;
        foreach (['admin' => $s['admin'], 'pos' => $s['pos']] + ($onBoard ? ['board' => $s['board']] : []) as $where => $pay) {
            $this->assertNotNull($pay, "لا حالَ سدادٍ في «{$where}»");
            $this->assertSame($state, $pay['state'], "«{$where}» تقول حالًا أخرى");
            $this->assertSame($label, $pay['label'], "«{$where}» تكتب على الشارة غيرَ ما يُقال");
            $this->assertSame(round($paid, 3), $pay['paid'], "«{$where}»: المقبوض");
            $this->assertSame(round($due, 3), $pay['due'], "«{$where}»: المتبقّي");
        }
        $this->assertSame($state, $s['register'], 'سجلُّ كل المبيعات يقول غيرَ ما تقوله الشاشة');
        $this->assertStringContainsString('<td class="l">'.$label.'</td>', $s['strip'], 'الإيصال يطبع حالًا أخرى');

        return $s['admin'];
    }

    /* ═══════════ ١) طلبُ الدفع عند الاستلام: من «غير مدفوع» إلى «مدفوع» بفاتورته ═══════════ */

    public function test_a_cod_order_reads_its_invoice_from_unpaid_to_paid_on_every_screen(): void
    {
        $order = $this->cod();
        $this->assertSame(25.0, (float) $order->total);
        $this->assertSays($order, OrderPaymentState::UNPAID, 0, 25);

        $invoice = $this->invoice($order);
        $pay = $this->assertSays($order, OrderPaymentState::UNPAID, 0, 25);
        $this->assertSame($invoice->number, $pay['invoice']['number']);

        Carbon::setTestNow(Carbon::parse('2027-02-13 10:00:00', 'Asia/Muscat'));
        $this->collect($invoice, 10);
        $this->assertSays($order, OrderPaymentState::PARTIAL, 10, 15);

        Carbon::setTestNow(Carbon::parse('2027-03-02 10:00:00', 'Asia/Muscat'));
        $this->collect($invoice, 15);
        $pay = $this->assertSays($order, OrderPaymentState::PAID, 25, 0);

        // ولوحةُ التجهيز لا تطلب مالَ طلبٍ دُفع: ما يُحصَّل هو الباقي، وهو صفر
        $this->assertSame(0.0, $pay['due']);
        // والحقلُ كما كُتب يومَ البيع — معناه للدفتر «بيعةٌ آجلة»، ولا يُقلب
        $this->assertSame('غير مدفوع', $order->fresh()->payment_status);
        $this->assertSame(2, DB::table('customer_payments')->count(), 'تحصيلان لا ثالث');
    }

    /* ═══════════ ٢) محاولةُ تحصيلٍ ثانية بعد السداد ═══════════ */

    public function test_a_second_collection_attempt_leaves_the_order_paid_once(): void
    {
        $order = $this->cod();
        $invoice = $this->invoice($order);
        $this->collect($invoice, 25);

        try {
            // قد يردّها إصلاحُ التحصيل المكرّر، وقد تمرّ رصيدًا للعميل — والعرضُ واحدٌ في الحالين
            $this->collect($invoice->fresh(), 25);
        } catch (\Throwable) {
        }

        $this->assertSays($order, OrderPaymentState::PAID, 25, 0);
    }

    /* ═══════════ ٣) الملغى ملغى — قُبض أو لم يُقبض ═══════════ */

    public function test_a_cancelled_order_reads_cancelled_whether_or_not_it_was_paid(): void
    {
        $never = $this->cod('96899110003');
        OrderCorrection::cancel($never->fresh(), 'لم يحضر', true);
        // لا «غير مدفوع» عن طلبٍ أُلغي ولم يُقبض
        $this->assertSays($never, OrderPaymentState::VOID, 0, 0, onBoard: false);

        $paid = $this->cod('96899110004');
        $invoice = $this->invoice($paid);
        $this->collect($invoice, 25);
        OrderCorrection::cancel($paid->fresh(), 'أُعيد', true);
        $pay = $this->assertSays($paid, OrderPaymentState::VOID, 0, 0, onBoard: false);
        // ومالُه قُبض: يبقى رصيدًا للعميل يُقال — لا يُرى كأنّه لم يكن
        $this->assertSame(25.0, $pay['customer_credit']);
    }

    /* ═══════════ ٤) ما رُدّ بإشعار إرجاعٍ على فاتورته ═══════════ */

    public function test_a_return_on_its_invoice_is_marked_and_a_whole_return_is_named(): void
    {
        $order = $this->cod();
        $invoice = $this->invoice($order);
        $this->collect($invoice, 25);

        // تصحيحُ خطأٍ ليس إرجاعًا: يُنقص الباقي ولا يُسمّى «مرتجع»
        CustomerInvoices::creditNote($invoice->fresh(), 2, 0, 'خطأ في السعر', $this->owner->id, basis: CustomerCreditNote::ERROR);
        $pay = $this->assertSays($order, OrderPaymentState::PAID, 23, 0);
        $this->assertNull($pay['marker']);

        CustomerInvoices::creditNote($invoice->fresh(), 10, 0, 'أعاد الخاتم', $this->owner->id, basis: CustomerCreditNote::RETURN);
        $pay = $this->assertSays($order, OrderPaymentState::PAID, 13, 0);
        $this->assertSame(OrderPaymentState::PARTLY_RETURNED, $pay['marker']);
        $this->assertSame(10.0, $pay['returned']);

        CustomerInvoices::creditNote($invoice->fresh(), 13, 0, 'أعاد المزهرية', $this->owner->id, basis: CustomerCreditNote::RETURN);
        $pay = $this->assertSays($order, OrderPaymentState::PAID, 0, 0, OrderPaymentState::RETURNED);
        $this->assertNull($pay['marker']);
        $this->assertSame(23.0, $pay['returned']);
    }

    /* ═══════════ ٥) فرقٌ رُدّ للعميل بعد بيعةٍ مدفوعة ═══════════ */

    public function test_a_refund_after_a_paid_sale_is_marked(): void
    {
        $this->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 3, 'price' => 15]],
            'payment_method' => 'نقدي', 'client_uuid' => 'paystate-refund',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $item = $order->items()->firstOrFail();

        OrderCorrection::setQuantity($order->fresh(), $item, 1, 'أعاد قطعتين', 'refunded', 'نقدي');

        $pay = $this->assertSays($order, OrderPaymentState::PAID, 15, 0, onBoard: false);
        $this->assertSame(OrderPaymentState::PARTLY_REFUNDED, $pay['marker']);
        $this->assertSame(30.0, $pay['refunded']);
    }

    /* ═══════════ ٦) طلبُ الموقع المدفوع بالبطاقة ═══════════ */

    public function test_a_card_paid_web_order_is_paid_without_an_invoice(): void
    {
        $payload = [
            'items' => [['id' => $this->vase->id, 'variant_id' => null, 'qty' => 1], ['id' => $this->ring->id, 'variant_id' => null, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'card', 'name' => 'سارة', 'phone' => '96899110002', 'date' => '2027-02-11',
        ];
        $intent = StorePaymentIntent::create(['business_id' => $this->shop->id, 'reference' => 'paystate-card-1', 'amount' => 25, 'currency' => 'OMR',
            'status' => StorePaymentIntent::PAID, 'provider_transaction_id' => '990001', 'payload' => $payload, 'lang' => 'ar']);
        $order = WebCheckout::place($this->shop->fresh(), $payload, 'ar', paid: true, intent: $intent);

        $pay = $this->assertSays($order, OrderPaymentState::PAID, 25, 0);
        $this->assertNull($pay['invoice']);
    }

    /* ═══════════ ٧) بيعُ الصندوق الآجل — فاتورتُه لحظةَ البيع ═══════════ */

    public function test_a_till_credit_sale_follows_its_invoice(): void
    {
        $company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true, 'credit_limit' => null]);
        $this->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 15], ['id' => $this->ring->id, 'name' => 'خاتم', 'qty' => 1, 'price' => 10]],
            'payment_method' => 'نقدي', 'client_uuid' => 'paystate-credit', 'customer_id' => $company->id, 'credit' => true,
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $invoice = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))->firstOrFail();

        $this->assertSays($order, OrderPaymentState::UNPAID, 0, 25, onBoard: false);
        $this->collect($invoice, 25);
        $this->assertSays($order, OrderPaymentState::PAID, 25, 0, onBoard: false);
    }

    /* ═══════════ ٨) الشيك: تحت التحصيل مسدَّدٌ ويُقال، والمرتجعُ ليس سدادًا ═══════════ */

    public function test_a_cheque_under_collection_is_paid_and_said_and_a_bounced_one_is_not(): void
    {
        $bank = BankAccount::create(['business_id' => $this->shop->id, 'bank_name' => 'بنك مسقط', 'label' => 'الرئيسي', 'active' => true, 'is_primary' => true]);
        $order = $this->cod();
        $invoice = $this->invoice($order);
        $cheque = $this->collect($invoice, 25, ['method' => 'شيك', 'bank_account_id' => $bank->id, 'occurred_at' => now()->toDateString()]);
        $this->assertSame(Cheques::PENDING, $cheque->cheque_status);

        $pay = $this->assertSays($order, OrderPaymentState::PAID, 25, 0);
        $this->assertSame(25.0, $pay['cheque_pending']);

        Cheques::bounce($cheque->fresh(), 'رصيد غير كافٍ', $this->owner->id);
        $pay = $this->assertSays($order, OrderPaymentState::UNPAID, 0, 25);
        $this->assertSame(0.0, $pay['cheque_pending']);
    }

    /* ═══════════ ٩) فاتورةٌ لطلبين — مالُها للأقدم أوّلًا ═══════════ */

    public function test_an_invoice_for_two_orders_shares_its_money_oldest_first(): void
    {
        $first = $this->cod();
        Carbon::setTestNow(Carbon::parse('2027-02-10 11:00:00', 'Asia/Muscat'));
        $second = $this->cod();
        $invoice = $this->invoice($first, $second);
        $this->collect($invoice, 30);

        // حالُ الفاتورة قاعدةُ السجلّ — ومبلغا كلِّ طلبٍ نصيبُه منها
        $this->assertSays($first, OrderPaymentState::PARTIAL, 25, 0);
        $this->assertSays($second, OrderPaymentState::PARTIAL, 5, 20);
    }

    /* ═══════════ ١٠) فاتورةٌ أُلغيت: يعود الطلبُ إلى حاله ═══════════ */

    public function test_a_cancelled_invoice_returns_the_order_to_unpaid(): void
    {
        $order = $this->cod();
        $invoice = $this->invoice($order);
        CustomerInvoices::cancel($invoice->fresh(), 'خطأ', $this->owner->id);

        $pay = $this->assertSays($order, OrderPaymentState::UNPAID, 0, 25);
        $this->assertNull($pay['invoice']);
    }

    /* ═══════════ ١١) «الدخل المسجّل» و«المقبوض فعلًا» رقمان ═══════════ */

    public function test_the_finance_screens_say_recorded_and_received_apart_and_change_no_number(): void
    {
        $order = $this->cod();
        $this->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 15]],
            'payment_method' => 'نقدي', 'client_uuid' => 'paystate-cash',
        ])->assertOk();

        $summary = fn () => $this->get(route('admin.finance.summary', ['range' => 'month']))->assertOk()->viewData('page')['props'];
        $list = fn () => TransactionsList::summary(TransactionsList::filtered(Request::create('/admin/finance/transactions')));

        $before = $summary();
        $this->assertSame(40.0, $before['period']['in'], 'الدخل المسجّل: البيعتان يومَ بيعهما');
        $this->assertSame(25.0, $before['money']['uncollected_sales'], 'منه طلبُ الدفع عند الاستلام');
        $this->assertSame(15.0, $before['money']['received'], 'والمقبوضُ فعلًا: نقدُ الصندوق وحده');
        $this->assertSame(25.0, $list()['uncollected_sales']);
        $this->assertSame(40.0, $list()['in']);

        Carbon::setTestNow(Carbon::parse('2027-02-13 10:00:00', 'Asia/Muscat'));
        $this->collect($this->invoice($order), 10);

        $after = $summary();
        $this->assertSame($before['period'], $after['period'], 'تغيّر رقمٌ قائم في الملخّص — والإصلاحُ للعرض وحده');
        $this->assertSame(25.0, $after['money']['uncollected_sales'], 'البيعةُ نفسُها: سُجّلت يومَ بيعها ولم تُقبض عنده');
        $this->assertSame(25.0, $after['money']['received'], 'والمقبوضُ زاد بالتحصيل');
        $this->assertSame(40.0, $list()['in']);
    }
}
