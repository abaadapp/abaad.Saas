<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Lists\SalesRegister;
use App\Support\OrderStatus;
use App\Support\PosTerminal;
use App\Support\Receivables;
use App\Support\SalesChannel;
use App\Support\SeedData;
use App\Support\StandaloneInvoiceSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلُّ بيعةٍ في المتجر من مكانٍ واحد — «جميع عمليات البيع» في «فواتير العملاء».
 *
 * ═══ العطب ═══
 *
 * البيعةُ طلبٌ (الصندوق والموقع، في `orders`) أو فاتورةٌ مستقلّة (في
 * `customer_invoices`)، ولوحةُ التجهيز تعرض النوعين. و«فواتير العملاء» لا
 * تقرأ إلّا الفواتير: بيعةُ الموظّف على الصندوق، وطلبُ الموقع على اللوحة، لا
 * يظهران فيها أبدًا. و«المبيعات» لا تقرأ إلّا الطلبات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بيعةُ المالك والموظّف والكاشير المختار على صندوقٍ مشترك تظهر، كلٌّ باسم
 *     من نُسبت إليه، والحسابُ المسجَّل يُقال حين يختلف ولا يُخلط به.
 *   - فاتورةُ الموظّف المخوَّل باسم منشئها، وطلبُ الموقع بلا بائعٍ مختلَق.
 *   - البيعةُ باقيةٌ في كلّ مراحل التجهيز وبعد التسليم والإلغاء.
 *   - كلُّ بيعةٍ صفٌّ واحد: الطلبُ المفوتَر لا يصير بيعين، والسدادُ والتجهيزُ
 *     حالان لا صفّان، والمالُ يُعدّ مرّة.
 *   - الفرعُ والمتجرُ والصلاحيّةُ والباقة، والتصديرُ بالمرشِّحات نفسِها.
 *   - والقراءةُ لا تكتب: الدفترُ والمخزونُ والمستنداتُ قبلها وبعدها سواء.
 */
class EverySaleIsReadInOnePlaceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private User $ahmed;

    private User $sara;

    private User $accountant;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner, $this->muscat, $this->sohar] = $this->business('ورود', 'owner@one-place.om');

        $this->ahmed = $this->staff('أحمد', 'cashier', 'ahmed@one-place.om');
        $this->sara = $this->staff('سارة', 'cashier', 'sara@one-place.om');
        $this->accountant = $this->staff('منى', 'accountant', 'mona@one-place.om');

        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة النخيل', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $this->product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4, 'quantity' => 500, 'alert_qty' => 2, 'active' => true]);
        foreach ([$this->muscat, $this->sohar] as $branch) {
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $branch->id, 'product_id' => $this->product->id, 'quantity' => 250]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function business(string $name, string $email, ?int $planId = null): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'plan_id' => $planId]);
        $a = Branch::create(['business_id' => $shop->id, 'name' => 'مسقط']);
        $b = Branch::create(['business_id' => $shop->id, 'name' => 'صحار']);
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'صاحب '.$name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $owner, $a, $b];
    }

    private function staff(string $name, string $role, string $email, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** بيعةٌ من الصندوق الحقيقيّ — `pos.checkout` بكوكي جهاز الفرع */
    private function sellAtTill(User $as, Branch $branch, ?User $cashier = null, int $qty = 1, array $extra = []): Order
    {
        $this->activatePosDevice($this->shop->id, $branch->id);
        $this->actingAs($as);
        if ($cashier) {
            $this->post(route('pos.cashier.select'), ['employee_id' => $cashier->id])->assertRedirect(route('pos.index'));
        }

        // و`withCredentials`: الصندوقُ يرسل كوكي جهازه مع كلّ نداء — وبدونها لا يُعرف فرعُه
        $number = $this->withCredentials()->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => 'نقدي',
        ] + $extra)->assertOk()->json('invoice');

        if ($cashier) {
            $this->post(route('pos.cashier.leave'));
        }

        return Order::where('business_id', $this->shop->id)->where('number', $number)->firstOrFail();
    }

    /** طلبٌ من الموقع كما يكتبه `WebCheckout` — بلا بائع، وللتوصيل بموعد */
    private function websiteOrder(float $total, string $payment = 'مدفوع', ?Branch $branch = null, ?Business $shop = null): Order
    {
        $shop ??= $this->shop;
        $branch ??= $this->muscat;
        $order = Order::create([
            'business_id' => $shop->id, 'number' => 'WEB-'.uniqid(), 'customer_name' => 'ليلى', 'customer_id' => null,
            'employee_name' => __('الموقع الإلكتروني'), 'user_id' => null, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'channel' => SalesChannel::WEBSITE, 'payment_method' => 'بطاقة', 'payment_status' => $payment,
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => $total, 'is_held' => false,
            'ordered_at' => now(), 'status' => OrderStatus::PENDING, 'fulfillment_type' => 'delivery',
            'scheduled_for' => now()->addHours(6), 'delivery_address' => 'الخوير',
        ]);
        Books::recordSale($order);

        return $order;
    }

    /** فاتورةٌ مستقلّة — ٥٪ فوق السعر: مئةٌ تصير ١٠٥ */
    private function invoice(float $price, ?User $by = null, ?Branch $branch = null, string $at = '2026-10-20'): CustomerInvoice
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => $at, 'branch_id' => $branch?->id], [
            ['description' => 'تنسيق قاعة', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 5],
        ], ($by ?? $this->owner)->id);

        return CustomerInvoices::issue($draft, ($by ?? $this->owner)->id);
    }

    private function pay(CustomerInvoice $invoice, float $amount): void
    {
        CustomerPayments::record($this->shop->id, $this->customer, $amount, ['method' => 'نقدي', 'occurred_at' => '2026-10-20'], [$invoice->id => $amount], $this->owner->id);
    }

    /** السجلُّ كما يراه المالك — «كل الفروع» ما لم يُختر فرع */
    private function register(array $query = [], ?User $as = null, string $route = 'admin.customerInvoices.sales'): array
    {
        // صفحةُ الإدارة لا تحمل كوكي الصندوق — كتبويبٍ آخر في المتصفّح نفسه
        unset($this->defaultCookies[PosTerminal::COOKIE]);

        return $this->actingAs($as ?? $this->owner)->get(route($route, $query))->assertOk()->viewData('page')['props'];
    }

    private function allBranches(): void
    {
        $this->actingAs($this->owner)->get(route('admin.branch.switch', 'all'));
    }

    private function row(array $props, string $kind, int $id): ?array
    {
        return collect($props['rows'])->firstWhere('key', $kind.':'.$id);
    }

    /* ═══════════ A·B·C — الصندوق: المالك والموظّف والكاشير المختار ═══════════ */

    public function test_a_the_owners_till_sale_shows_with_its_details(): void
    {
        $order = $this->sellAtTill($this->owner, $this->muscat);
        $this->allBranches();

        $row = $this->row($this->register(), 'order', $order->id);

        $this->assertNotNull($row, 'بيعةُ المالك من الصندوق غابت عن السجلّ');
        $this->assertSame($order->number, $row['number']);
        $this->assertSame('بيع نقطة البيع', $row['type']);
        $this->assertSame('pos', $row['source']);
        $this->assertSame('person', $row['seller_kind']);
        $this->assertSame($this->owner->name, $row['seller']);
        $this->assertNull($row['account'], 'الحسابُ نفسُه البائع — لا يُكرَّر');
        $this->assertSame('مسقط', $row['branch']);
        $this->assertSame((float) $order->total, $row['amount']);
        $this->assertSame(SalesRegister::PAID, $row['pay_state']);
        $this->assertNull($row['prep'], 'بيعةُ المنضدة بلا تجهيز');
        $this->assertSame('2026-10-20 10:00', $row['date']);
    }

    public function test_b_an_employees_own_till_sale_shows_to_the_owner_under_his_name(): void
    {
        $order = $this->sellAtTill($this->ahmed, $this->sohar);
        $this->allBranches();

        $row = $this->row($this->register(), 'order', $order->id);

        $this->assertNotNull($row, 'بيعةُ الموظّف غابت عن المالك');
        $this->assertSame($this->ahmed->name, $row['seller']);
        $this->assertSame($this->ahmed->id, $row['seller_id']);
        $this->assertSame('صحار', $row['branch']);
    }

    public function test_c_d_a_chosen_cashier_on_a_shared_till_is_the_seller_and_the_account_is_said_apart(): void
    {
        // المالك فتح الصندوق بحسابه، واختار سارة ثمّ أحمد — بيعتان على جهازٍ واحد
        $bySara = $this->sellAtTill($this->owner, $this->muscat, $this->sara);
        $byAhmed = $this->sellAtTill($this->owner, $this->muscat, $this->ahmed);
        $this->allBranches();
        $props = $this->register();

        $sara = $this->row($props, 'order', $bySara->id);
        $ahmed = $this->row($props, 'order', $byAhmed->id);

        $this->assertSame('سارة', $sara['seller']);
        $this->assertSame($this->sara->id, $sara['seller_id']);
        $this->assertSame('أحمد', $ahmed['seller']);
        // والحسابُ المسجَّل يُقال ولا يحلّ محلّ البائع
        $this->assertSame($this->owner->name, $sara['account']);
        $this->assertSame($this->owner->name, $ahmed['account']);

        // واختيارُ موظّفٍ يعرض بيعاته وحده
        $only = $this->register(['seller' => (string) $this->sara->id]);
        $this->assertSame(['order:'.$bySara->id], array_column($only['rows'], 'key'));
        $this->assertSame((float) $bySara->total, $only['totals']['sales']);
    }

    /* ═══════════ D·E — فاتورةُ الموظّف وطلبُ الموقع ═══════════ */

    public function test_d_an_authorised_employees_invoice_shows_with_its_creator_and_payment(): void
    {
        $invoice = $this->invoice(100, $this->accountant);
        $this->pay($invoice, 40);

        $row = $this->row($this->register(), 'invoice', $invoice->id);

        $this->assertSame('فاتورة عميل', $row['type']);
        $this->assertSame('invoice', $row['source']);
        $this->assertSame('منى', $row['seller']);
        $this->assertSame($this->accountant->id, $row['seller_id']);
        $this->assertSame(SalesRegister::PARTIAL, $row['pay_state']);
        $this->assertSame('2026-10-20', $row['date']);

        // ومن يملك الفواتير من الموظّفين يفتح السجلّ
        $this->register([], $this->accountant);
    }

    public function test_e_a_website_order_shows_from_the_website_with_no_invented_seller(): void
    {
        $order = $this->websiteOrder(30);

        $row = $this->row($this->register(), 'order', $order->id);

        $this->assertSame('طلب موقع', $row['type']);
        $this->assertSame('website', $row['source']);
        $this->assertSame('website', $row['seller_kind']);
        $this->assertNull($row['seller']);
        $this->assertNull($row['seller_id']);
        $this->assertSame(['order:'.$order->id], array_column($this->register(['seller' => 'website'])['rows'], 'key'));
        $this->assertSame([], $this->register(['seller' => (string) $this->owner->id])['rows'], 'طلبُ الموقع نُسب إلى المالك');
        $this->assertSame(['order:'.$order->id], array_column($this->register([], null, 'admin.customerInvoices.websiteOrders')['rows'], 'key'));
    }

    /**
     * وبيعةٌ قبل أن يُحفظ مصدرُ البيع (`orders.channel` فارغ) — تُقرأ ولا تُخمَّن.
     *
     * لا يُعرف أكانت من الصندوق أم من الموقع: فهي في «جميع عمليات البيع»
     * بمصدر «غير محدّدة» وباسم بائعها المحفوظ، ويُقال عددُها في التبويبين.
     */
    public function test_a_sale_from_before_the_source_was_saved_shows_without_a_guessed_source(): void
    {
        $old = Order::create([
            'business_id' => $this->shop->id, 'number' => 'OLD-1', 'customer_name' => 'عميل نقدي', 'employee_name' => 'موظف سابق',
            'user_id' => null, 'branch_id' => $this->muscat->id, 'branch' => 'مسقط', 'channel' => null,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 12, 'discount' => 0, 'tax' => 0, 'total' => 12,
            'is_held' => false, 'ordered_at' => Carbon::parse('2026-08-01 12:00'), 'status' => OrderStatus::COMPLETED,
        ]);

        $row = $this->row($this->register(), 'order', $old->id);
        $this->assertSame('unknown', $row['source']);
        $this->assertSame('طلب بيع', $row['type']);
        $this->assertSame('موظف سابق', $row['seller'], 'الاسمُ المحفوظ ضاع');
        $this->assertNull($row['seller_id']);
        $this->assertSame(['order:'.$old->id], array_column($this->register(['source' => 'unknown'])['rows'], 'key'));
        // ولا حسابَ موظّفٍ يُنسب إليه — فيقع في «غير مرتبط بحساب موظف» باسمه المحفوظ
        $this->assertSame(['order:'.$old->id], array_column($this->register(['seller' => 'none'])['rows'], 'key'));

        $pos = $this->register([], null, 'admin.customerInvoices.posSales');
        $this->assertSame([], $pos['rows']);
        $this->assertSame(1, $pos['unknownCount']);
        $this->assertNull($this->register()['unknownCount']);
    }

    /* ═══════════ F·G·H — التجهيز لا يُخفي ولا يُضاعف ═══════════ */

    public function test_f_g_a_website_order_stays_through_every_board_stage_and_after_delivery(): void
    {
        $order = $this->websiteOrder(30);

        foreach ([OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED] as $status) {
            $this->actingAs($this->owner)->post(route('admin.preparation.move', $order->number), ['status' => $status])->assertRedirect();
            $props = $this->register();
            $row = $this->row($props, 'order', $order->id);

            $this->assertNotNull($row, 'الطلبُ اختفى من السجلّ عند «'.$status.'»');
            $this->assertSame($status, $row['prep']);
            $this->assertSame(1, $props['totals']['count']);
            $this->assertSame(30.0, $props['totals']['sales'], 'مرحلةُ «'.$status.'» غيّرت المبيعات');
        }

        $this->assertSame('done', $row['prep_stage']);
        $this->assertSame(['order:'.$order->id], array_column($this->register(['prep' => 'done'])['rows'], 'key'));
    }

    public function test_h_a_paid_standalone_invoice_on_the_board_stays_in_both_lists_and_counts_once(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $invoice = $this->invoice(100);
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->muscat->id,
            'scheduled_for' => '2026-10-20 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
        ], $this->owner->id);

        $this->assertSame(SalesRegister::AWAITING_PAYMENT, $this->row($this->register(), 'invoice', $invoice->id)['prep']);

        $this->pay($invoice, 105);
        $this->assertNull(CustomerInvoicePreparations::activateByHand($invoice->fresh(), $this->owner->id));
        $prep = $invoice->fresh()->preparation;

        foreach ([OrderStatus::READY, OrderStatus::PICKED_UP] as $to) {
            $this->assertNull(CustomerInvoicePreparations::move($this->shop->id, $prep->id, null, $to));
            $props = $this->register();
            $row = $this->row($props, 'invoice', $invoice->id);
            $this->assertSame($to, $row['prep']);
            $this->assertSame(SalesRegister::PAID, $row['pay_state']);
            $this->assertSame(105.0, $props['totals']['sales']);
            $this->assertSame(105.0, $props['totals']['collected']);
            $this->assertSame(0.0, $props['totals']['outstanding']);
        }

        // وفي «فواتير العملاء» كما كانت، ومبيعاتُ اللوحة تعدّها مرّة
        $invoices = $this->actingAs($this->owner)->get(route('admin.customerInvoices.index'))->viewData('page')['props']['invoices'];
        $this->assertSame([$invoice->id], array_column($invoices, 'id'));
        $this->assertSame(105.0, StandaloneInvoiceSales::total($this->shop->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')));
    }

    /* ═══════════ I·J — الطلبُ المفوتَر، والإلغاء، وإشعارُ الدائن ═══════════ */

    public function test_i_an_order_with_its_invoice_is_one_sale_read_through_the_invoice(): void
    {
        // بيعةٌ آجلةٌ من الصندوق بثلاثين، دُفع منها عشرة — والصندوقُ يُصدر فاتورتها (`fromOrder`)
        $order = $this->sellAtTill($this->owner, $this->muscat, null, 3, [
            'credit' => true, 'paid_now' => 10, 'customer_id' => $this->customer->id, 'customer_language' => 'ar',
        ]);
        $invoice = $order->customerInvoices()->firstOrFail();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->allBranches();

        $props = $this->register();

        $this->assertSame(['order:'.$order->id], array_column($props['rows'], 'key'), 'الطلبُ وفاتورتُه صارا بيعين');
        $row = $props['rows'][0];
        $this->assertSame(['id' => $invoice->id, 'number' => $invoice->number], $row['cover']);
        $this->assertSame(SalesRegister::PARTIAL, $row['pay_state']);
        $this->assertSame((float) $order->total, $props['totals']['sales']);
        $this->assertSame(10.0, $props['totals']['collected']);
        $this->assertSame(round((float) $order->total - 10, 3), $props['totals']['outstanding']);
        // والبحثُ برقم الفاتورة يجد طلبها
        $this->assertSame(['order:'.$order->id], array_column($this->register(['q' => $invoice->number])['rows'], 'key'));
    }

    public function test_j_a_cancelled_sale_stays_with_its_state_and_a_credit_note_counts_once(): void
    {
        $order = $this->sellAtTill($this->owner, $this->muscat);
        $this->allBranches();
        $this->actingAs($this->owner)->delete(route('admin.orders.destroy', $order->number))->assertRedirect();

        $invoice = $this->invoice(100);
        CustomerInvoices::creditNote($invoice, 52.5, 2.5, 'مردود', $this->owner->id, basis: 'return');

        $props = $this->register();
        $cancelled = $this->row($props, 'order', $order->id);

        $this->assertNotNull($cancelled, 'البيعةُ الملغاة اختفت من السجلّ');
        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame(OrderStatus::CANCELLED, $cancelled['status']);
        $this->assertSame(SalesRegister::VOID, $cancelled['pay_state']);
        $this->assertSame(52.5, $this->row($props, 'invoice', $invoice->id)['credited']);
        // ١٠٥ − ٥٢٫٥ — والملغاةُ خارج القيمة، ومرّةً واحدة
        $this->assertSame(52.5, $props['totals']['sales']);
        $this->assertSame(1, $props['totals']['cancelled']);
        $this->assertSame(['order:'.$order->id], array_column($this->register(['state' => 'cancelled'])['rows'], 'key'));
    }

    /** والمسودّةُ والسلّةُ المعلّقة ليستا بيعًا — الأولى في «فواتير العملاء» وحدها */
    public function test_a_draft_invoice_and_a_parked_cart_are_not_sales(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->customer, [], [
            ['description' => 'عرض سعر', 'quantity' => 1, 'unit_price' => 50],
        ], $this->owner->id);
        Order::create([
            'business_id' => $this->shop->id, 'number' => 'HOLD-1', 'customer_name' => 'عميل نقدي', 'employee_name' => 'أحمد',
            'user_id' => $this->ahmed->id, 'branch_id' => $this->muscat->id, 'branch' => 'مسقط', 'channel' => SalesChannel::POS,
            'payment_method' => 'نقدي', 'payment_status' => 'غير مدفوع', 'subtotal' => 40, 'discount' => 0, 'tax' => 0, 'total' => 40,
            'is_held' => true, 'ordered_at' => now(), 'status' => OrderStatus::PENDING,
        ]);
        $sold = $this->websiteOrder(30);

        $props = $this->register();

        $this->assertSame(['order:'.$sold->id], array_column($props['rows'], 'key'));
        $this->assertSame(30.0, $props['totals']['sales']);
        $invoices = $this->actingAs($this->owner)->get(route('admin.customerInvoices.index'))->viewData('page')['props']['invoices'];
        $this->assertSame([$draft->id], array_column($invoices, 'id'), 'المسودّةُ غابت عن «فواتير العملاء»');
    }

    /* ═══════════ K — الفروع ═══════════ */

    public function test_k_all_branches_shows_everything_and_a_branch_shows_its_own(): void
    {
        $muscat = $this->sellAtTill($this->ahmed, $this->muscat);
        $sohar = $this->sellAtTill($this->sara, $this->sohar);
        $noBranch = $this->invoice(100);
        $soharInvoice = $this->invoice(10, null, $this->sohar);

        /*
         * والفرعُ المختار في الترويسة يُقال فوق السجلّ، ولا يصمت.
         *
         * يُختار بمبدّل الفروع لا بفتح الصندوق: الصندوقُ كان يكتب فرعَ جهازه
         * في جلسة الإدارة (#165)، ولا يُتّكل على ذلك هنا.
         */
        $this->actingAs($this->owner)->get(route('admin.branch.switch', $this->sohar->id));
        $props = $this->register();
        $this->assertSame('صحار', $props['branch']);
        $this->assertEqualsCanonicalizing(['order:'.$sohar->id, 'invoice:'.$soharInvoice->id], array_column($props['rows'], 'key'));

        $this->allBranches();
        $all = $this->register();
        $this->assertNull($all['branch']);
        $this->assertEqualsCanonicalizing(
            ['order:'.$muscat->id, 'order:'.$sohar->id, 'invoice:'.$noBranch->id, 'invoice:'.$soharInvoice->id],
            array_column($all['rows'], 'key'),
        );
        $this->assertSame(round((float) $muscat->total + (float) $sohar->total + 105 + 10.5, 3), $all['totals']['sales']);

        $this->actingAs($this->owner)->get(route('admin.branch.switch', $this->muscat->id));
        $this->assertSame(['order:'.$muscat->id], array_column($this->register()['rows'], 'key'), 'فاتورةٌ بلا فرعٍ نُسبت إلى مسقط');
    }

    /* ═══════════ L — ثلاثة متاجر ═══════════ */

    public function test_l_three_shops_never_read_each_other_by_screen_link_or_file(): void
    {
        $mine = $this->websiteOrder(30);
        $shops = [];
        foreach (['الثاني', 'الثالث'] as $i => $name) {
            [$shop, $owner, $branch] = $this->business($name, "owner{$i}@other-shop.om");
            $order = $this->websiteOrder(999, 'مدفوع', $branch, $shop);
            $shops[] = [$shop, $owner, $order];
        }

        foreach ($shops as [$shop, $owner, $order]) {
            $props = $this->register([], $owner);
            $this->assertSame(['order:'.$order->id], array_column($props['rows'], 'key'), $shop->name.' قرأ غيرَ طلبه');
            // وموظّفُ متجرٍ آخر في المرشِّح لا يفتح شيئًا
            $this->assertSame([], $this->register(['seller' => (string) $this->owner->id], $owner)['rows']);
            // والرابطُ المباشر لطلب متجرٍ آخر لا يُفتح
            $this->actingAs($owner)->get(route('admin.orders.show', $mine->number))->assertNotFound();
            $this->actingAs($owner)->get(route('admin.customerInvoices.show', $this->invoice(1)->id))->assertNotFound();
            // ولا الملفّ
            $csv = $this->actingAs($owner)->get($this->exportRoute('admin.customerInvoices.sales.csv'))->assertOk()->streamedContent();
            $this->assertStringContainsString($order->number, $csv);
            $this->assertStringNotContainsString($mine->number, $csv);
        }
    }

    /* ═══════════ M — كلُّ الباقات ═══════════ */

    public function test_m_every_plan_reads_its_sales_and_none_unlocks_a_paid_feature(): void
    {
        foreach (SeedData::plans() as $i => $spec) {
            [$branches, $employees, $products] = $spec['limits'];
            $plan = Plan::create([
                'name' => $spec['name'], 'monthly_price' => $spec['monthly'], 'yearly_price' => $spec['yearly'],
                'features' => $spec['features'], 'capabilities' => $spec['capabilities'],
                'max_branches' => $branches, 'max_employees' => $employees, 'max_products' => $products,
            ]);
            [$shop, $owner, $branch] = $this->business('باقة '.$i, "plan{$i}@one-place.om", $plan->id);
            $order = $this->websiteOrder(20, 'مدفوع', $branch, $shop);

            $props = $this->register([], $owner);
            $this->assertSame(['order:'.$order->id], array_column($props['rows'], 'key'), $spec['name']);

            // والسجلُّ لا يفتح ما لا تملكه الباقة: الفواتيرُ بلا مفتاح باقة، والمرشِّحُ مقيَّدٌ بمتجره
            $this->assertArrayNotHasKey('admin.customerInvoices.sales', CheckPlanFeature::ROUTES);
        }
    }

    /* ═══════════ 17 — من يجهّز وحده لا يرى المال ═══════════ */

    public function test_a_preparer_without_sales_access_cannot_open_the_register_or_its_files(): void
    {
        $preparer = $this->staff('يوسف', 'inventory', 'yousef@one-place.om');
        $this->websiteOrder(30);

        $this->actingAs($preparer)->get(route('admin.preparation.index'))->assertOk();
        $this->actingAs($preparer)->get(route('admin.customerInvoices.sales'))->assertForbidden();
        $this->actingAs($preparer)->get(route('admin.customerInvoices.posSales'))->assertForbidden();
        $this->actingAs($preparer)->get($this->exportRoute('admin.customerInvoices.sales.csv', [], $this->shop->id))->assertForbidden();
    }

    /* ═══════════ N — المبالغ: المبيعات والمحصّل والمتبقّي ═══════════ */

    public function test_n_sales_collected_and_outstanding_agree_with_the_books_and_receivables(): void
    {
        // بيعةُ صندوقٍ مدفوعة (١٠ وضريبتُها)، وطلبُ موقعٍ عند الاستلام (٣٠)، وفاتورةٌ دُفع منها ٤٠ (١٠٥)،
        // وطلبٌ آجلٌ في فاتورةٍ تجمعه مع آخر (٢٠ + ١٥)، دُفع منها ٢٥
        $till = (float) $this->sellAtTill($this->owner, $this->muscat)->total;
        $this->websiteOrder(30, 'غير مدفوع');
        $this->pay($this->invoice(100), 40);
        $credit = [];
        foreach ([20, 15] as $i => $total) {
            $o = Order::create([
                'business_id' => $this->shop->id, 'number' => 'CR-'.$i, 'customer_name' => $this->customer->name, 'customer_id' => $this->customer->id,
                'employee_name' => 'أحمد', 'user_id' => $this->ahmed->id, 'branch_id' => $this->muscat->id, 'branch' => 'مسقط', 'channel' => SalesChannel::POS,
                'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع', 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
                'is_held' => false, 'ordered_at' => now()->subDays(2 - $i), 'status' => OrderStatus::COMPLETED,
            ]);
            Books::recordSale($o);
            $credit[] = $o->id;
        }
        $monthly = CustomerInvoices::consolidate($this->customer, $credit, [], $this->owner->id);
        if ($monthly->status !== CustomerInvoice::ISSUED) {
            $monthly = CustomerInvoices::issue($monthly, $this->owner->id);
        }
        $this->pay($monthly->fresh(), 25);
        $this->allBranches();

        $totals = $this->register()['totals'];

        $this->assertSame(5, $totals['count']);
        $this->assertSame(round($till + 30 + 105 + 20 + 15, 3), $totals['sales']);
        $this->assertSame(round($till + 40 + 25, 3), $totals['collected']);
        $this->assertSame(30.0 + 65 + 10, $totals['outstanding']);
        $this->assertSame(round($totals['sales'], 3), round($totals['collected'] + $totals['outstanding'], 3));

        // والمتبقّي هو رصيدُ «ذمم العملاء» في الدفتر
        $this->assertSame(round(Ledger::balance($this->shop->id, 'receivable'), 3), $totals['outstanding']);
        // وشاشةُ الذمم تعدّ ما على العملاء المسجَّلين وحدهم: طلبُ الموقع عند
        // الاستلام بلا سجلِّ عميلٍ خارجها — فرقٌ في التعريف لا مالٌ مفقود
        $this->assertSame(round($totals['outstanding'] - 30, 3), Receivables::totals($this->shop->id)['total']);
        // والمبيعاتُ هي الدفترُ بضريبته — لا إلغاءَ ولا إشعارَ في هذه
        $ledger = round(Ledger::netRevenue($this->shop->id) + Ledger::outputTax($this->shop->id), 3);
        $this->assertSame($ledger, $totals['sales']);

        // والفاتورةُ الجامعة يُوزَّع سدادُها على طلبيها من الأقدم: الأوّل كلُّه، والثاني خمسة
        $first = $this->register(['q' => 'CR-0'])['totals'];
        $this->assertSame([20.0, 20.0, 0.0], [$first['sales'], $first['collected'], $first['outstanding']]);
        $second = $this->register(['q' => 'CR-1'])['totals'];
        $this->assertSame([15.0, 5.0, 10.0], [$second['sales'], $second['collected'], $second['outstanding']]);
    }

    /**
     * وطلبُ الموقع المدفوع ببطاقة يقع في «Paymob تحت التسوية» لا في البنك (#168).
     *
     * والسجلُّ يقرأ المستندَ لا الحساب: هو محصَّلٌ من الزبون مرّةً واحدة، ولا
     * يصير ذمّةً عليه وإن لم يصل المالُ إلى البنك بعد — ذاك سؤالُ التسوية.
     */
    public function test_a_website_card_sale_held_by_paymob_is_collected_once_and_owes_nothing(): void
    {
        $order = $this->websiteOrder(30);

        $this->assertTrue(Books::heldByPaymob($order), 'لم يقع الطلبُ في حساب Paymob — تغيّر قيدُ الموقع');

        $totals = $this->register()['totals'];

        $this->assertSame([1, 30.0, 30.0, 0.0], [$totals['count'], $totals['sales'], $totals['collected'], $totals['outstanding']]);
        $this->assertSame(0.0, round(Ledger::balance($this->shop->id, 'receivable'), 3));
        $this->assertSame(30.0, round(Ledger::balance($this->shop->id, 'paymob_clearing'), 3));
    }

    /* ═══════════ O — التصدير بالمرشِّحات نفسِها ═══════════ */

    public function test_o_every_file_carries_exactly_the_filtered_rows(): void
    {
        $pos = $this->sellAtTill($this->ahmed, $this->muscat);
        $web = $this->websiteOrder(30);
        $invoice = $this->invoice(100, $this->accountant);
        $this->allBranches();

        $query = ['seller' => (string) $this->ahmed->id];
        $screen = $this->register($query);
        $this->assertSame(['order:'.$pos->id], array_column($screen['rows'], 'key'));

        $csv = $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.csv', $query))->assertOk()->streamedContent();
        $this->assertStringContainsString($pos->number, $csv);
        $this->assertStringNotContainsString($web->number, $csv);
        $this->assertStringNotContainsString((string) $invoice->number, $csv);

        $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.xlsx', $query))->assertOk();
        $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.pdf', $query))->assertOk();

        // وتبويبُ نقطة البيع يُصدِّر مبيعاتِ الصندوق وحدها
        $posCsv = $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.csv', ['source' => 'pos']))->streamedContent();
        $this->assertStringContainsString($pos->number, $posCsv);
        $this->assertStringNotContainsString($web->number, $posCsv);

        // والترتيبُ ثابت: الأحدث أوّلًا، والتساوي بالنوع ثمّ المعرّف
        $keys = array_column($this->register(['per_page' => 20])['rows'], 'key');
        $this->assertSame($keys, array_column($this->register(['per_page' => 20])['rows'], 'key'));
    }

    /* ═══════════ 20 — القراءةُ لا تكتب ═══════════ */

    public function test_reading_and_exporting_change_no_ledger_stock_or_document(): void
    {
        $this->sellAtTill($this->sara, $this->muscat);
        $this->websiteOrder(30);
        $this->pay($this->invoice(100), 40);
        $this->allBranches();

        $snapshot = fn () => [
            DB::table('journal_entries')->count(), DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'),
            DB::table('orders')->count(), DB::table('orders')->sum('total'), DB::table('customer_invoices')->count(),
            DB::table('customer_invoices')->pluck('number')->all(), DB::table('customer_payments')->sum('amount'),
            DB::table('products')->pluck('quantity')->all(), DB::table('branch_stocks')->get()->all(),
            DB::table('inventory_movements')->count(),
        ];
        $before = $snapshot();

        $this->register();
        $this->register([], null, 'admin.customerInvoices.posSales');
        $this->register([], null, 'admin.customerInvoices.websiteOrders');
        $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.csv'))->streamedContent();
        $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.xlsx'))->assertOk();
        $this->actingAs($this->owner)->get($this->exportRoute('admin.customerInvoices.sales.pdf'))->assertOk();

        $this->assertEquals($before, $snapshot(), 'قراءةُ السجلّ كتبت شيئًا');
    }
}
