<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * فاتورةُ العميل تصل لوحةَ التجهيز حين تُسدَّد كلُّها — مرّةً واحدة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المفتاحُ مغلقٌ لكلّ نشاط، ويفتحه مديرُ المنصّة وحده؛ ومغلقًا لا يُكتب
 *     تجهيزٌ ولا يُسأل عنه.
 *   - النموذجُ يُتحقَّق منه في الخادم: التوصيلُ بمستلِمه وهاتفه وعنوانه وفرعه
 *     وموعده، والاستلامُ بفرعه وموعده ومن يأتي.
 *   - لا تظهر المهمّةُ إلّا بسدادٍ كاملٍ موثَّق — لا جزئيٌّ ولا توقيعٌ مزيّف
 *     ولا دفعةٌ لم تُسوَّ ولا رابطٌ ملغًى أو منتهٍ — وتظهر مرّةً ولو تكرّر
 *     الإشعارُ أو تعدّدت الروابط.
 *   - سقوطُ التفعيل لا يُسقط المال، والمطابقةُ تعيده.
 *   - ولا بيعَ ثانيًا: لا طلبَ ولا قيدَ ولا تحصيلَ ولا حركةَ مخزون.
 */
class ACustomerInvoiceIsPreparedOnceItIsPaidTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $customer;

    private Branch $branch;

    private int $tx = 7000;

    private int $order = 616100000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود سعود', 'type' => 'عام', 'status' => 'نشط']);
        // متجرٌ مسجَّل يجبي الضريبة — بالتفعيل الصريح، فالغيابُ إطفاء (`Vat::enabled`)
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);
        $this->open($this->shop);

        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوير']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@prep.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الضيافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/abc',
                'client_url' => 'https://oman.paymob.com/x', 'created_at' => '2026-10-19T10:00:00',
                'expires_at' => '2026-10-26T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function open(Business $shop, bool $on = true): void
    {
        $shop->forceFill(['customer_invoice_preparation_enabled' => $on])->save();
    }

    /** ما يُطلب في قسم «التجهيز والتسليم» — توصيلٌ افتراضًا */
    private function prepInput(array $over = []): array
    {
        return array_merge([
            'enabled' => true,
            'fulfillment_type' => 'delivery',
            'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-10-20 16:00',
            'recipient_name' => 'مريم البلوشية',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير، شارع ٤٥، فيلا ١٢',
            'delivery_notes' => 'الاتصال قبل الوصول',
            'internal_notes' => 'تغليف أبيض وذهبي',
        ], $over);
    }

    /** فاتورةٌ صادرة: بندان × ٥٠ + ٥٪ = ١٠٥، وتجهيزُها مطلوب */
    private function invoice(?array $prep = []): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50],
        ]);
        $invoice = CustomerInvoices::issue($invoice);

        if ($prep !== null) {
            CustomerInvoicePreparations::save($invoice, $this->prepInput($prep), $this->owner->id);
        }

        return $invoice->fresh();
    }

    /** رابطٌ على الفاتورة — وطلبُ Paymob لكلّ رابطٍ رقمُه */
    private function link(CustomerInvoice $invoice, ?float $amount = null): CustomerPaymentLink
    {
        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice, $amount, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();

        return $link->fresh();
    }

    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        return array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-19T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ], $over);
    }

    private function hook(array $obj, ?string $hmac = null): void
    {
        $this->postJson('/webhooks/paymob?hmac='.($hmac ?? Paymob::signature($obj, 'hmac-shop')), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function prep(CustomerInvoice $invoice): ?CustomerInvoicePreparation
    {
        return CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->first();
    }

    /* ============================== المفتاح ============================== */

    public function test_the_flag_is_off_for_every_shop_until_the_platform_opens_it(): void
    {
        $fresh = Business::create(['name' => 'نشاط جديد', 'type' => 'عام', 'status' => 'نشط']);
        $this->assertFalse((bool) $fresh->fresh()->customer_invoice_preparation_enabled);
        $this->assertFalse(CustomerInvoicePreparations::on($fresh->id));
        $this->assertFalse(CustomerInvoicePreparations::on($this->other->id));

        // ومتجرُ الاختبار مغلقٌ أوّلًا — وله حسابُ مالك تطلبه شاشةُ النشاط
        $this->open($this->shop, false);
        $this->assertFalse(CustomerInvoicePreparations::on($this->shop->id));

        $boss = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->actingAs($boss)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
            'customer_invoice_preparation_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(CustomerInvoicePreparations::on($this->shop->id));
        $this->assertFalse(CustomerInvoicePreparations::on($fresh->id), 'فتحُه لمتجرٍ فتحه لآخر');
        $this->assertFalse(CustomerInvoicePreparations::on($this->other->id));
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $this->shop->id, 'description' => 'فتح تجهيز فواتير العملاء بعد الدفع: '.$this->shop->name]);

        $this->actingAs($boss)->get(route('super-admin.businesses.edit', $this->shop->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('business.customer_invoice_preparation_enabled', true)->etc());

        // وحفظٌ بلا المفتاح لا يُطفئه، و«0» يُطفئه
        $this->actingAs($boss)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->shop->type, 'status' => 'نشط',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(CustomerInvoicePreparations::on($this->shop->id));

        $this->actingAs($boss)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => 'اسمٌ جديد', 'type' => $this->shop->type, 'status' => 'نشط',
            'customer_invoice_preparation_enabled' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(CustomerInvoicePreparations::on($this->shop->id));
    }

    public function test_a_merchant_cannot_open_it_for_itself(): void
    {
        $this->open($this->shop, false);

        $res = $this->actingAs($this->owner)->put(route('super-admin.businesses.update', $this->shop->id), [
            'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
            'customer_invoice_preparation_enabled' => '1',
        ]);

        $this->assertNotSame(200, $res->status());
        $this->assertFalse(CustomerInvoicePreparations::on($this->shop->id));
    }

    public function test_with_the_flag_off_the_form_shows_nothing_and_writes_nothing(): void
    {
        $this->open($this->shop, false);

        $this->get(route('admin.customerInvoices.create'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('preparation', null)->etc());

        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => CustomerInvoices::CREDIT,
            'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 20]],
            'preparation' => $this->prepInput(),
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(0, CustomerInvoicePreparation::count(), 'مغلقًا لا يُكتب تجهيز');

        $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('preparation', null)->etc());

        $this->expectException(ValidationException::class);
        CustomerInvoicePreparations::save($invoice, $this->prepInput(), $this->owner->id);
    }

    /* ============================== النموذج ============================== */

    public function test_delivery_requires_its_recipient_address_branch_and_time(): void
    {
        $base = [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => CustomerInvoices::CREDIT,
            'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 20]],
        ];

        $this->post(route('admin.customerInvoices.store'), $base + ['preparation' => ['enabled' => true, 'fulfillment_type' => 'delivery']])
            ->assertSessionHasErrors([
                'preparation.branch_id', 'preparation.scheduled_for', 'preparation.recipient_name',
                'preparation.recipient_phone', 'preparation.delivery_address',
            ]);

        // والاستلامُ من المحلّ لا يطلب عنوانًا
        // والاستلامُ من المحلّ لا يحفظ عنوانًا — ولو بقي في النموذج من «توصيل» قبله
        $this->post(route('admin.customerInvoices.store'), $base + ['preparation' => $this->prepInput([
            'fulfillment_type' => 'pickup', 'delivery_address' => 'عنوانٌ بقي من التوصيل',
        ])])->assertSessionHasNoErrors();
        $prep = CustomerInvoicePreparation::latest('id')->first();
        $this->assertSame('pickup', $prep->fulfillment_type);
        $this->assertNull($prep->delivery_address);

        // وفرعُ نشاطٍ آخر يُردّ
        $foreign = Branch::create(['business_id' => $this->other->id, 'name' => 'فرع الجار']);
        $this->post(route('admin.customerInvoices.store'), $base + ['preparation' => $this->prepInput(['branch_id' => $foreign->id])])
            ->assertSessionHasErrors('preparation.branch_id');

        // ونوعٌ غيرُ معروف يُردّ
        $this->post(route('admin.customerInvoices.store'), $base + ['preparation' => $this->prepInput(['fulfillment_type' => 'drone'])])
            ->assertSessionHasErrors('preparation.fulfillment_type');
    }

    /**
     * والتجهيزُ لا يمسّ الورقةَ الماليّة: بنودٌ من الكتالوج ومكتوبةٌ بيدٍ،
     * بكميّاتها وأسعارها وخصمها وضريبتها — كما لو لم يُطلب تجهيز.
     */
    public function test_the_invoice_is_the_same_paper_with_or_without_preparation(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه الربيع', 'price' => 30, 'cost' => 10, 'quantity' => 5]);
        $items = [
            ['product_id' => $product->id, 'description' => 'بوكيه الربيع', 'quantity' => 2, 'unit_price' => 30, 'discount' => 5],
            ['description' => 'تنسيق قاعة (بند مكتوب)', 'quantity' => 1, 'unit_price' => 40],
        ];
        $post = fn (array $extra) => $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id,
            'issue' => true,
            'payment_method' => CustomerInvoices::CREDIT,
            'items' => $items,
        ] + $extra)->assertSessionHasNoErrors();

        $post([]);
        $plain = CustomerInvoice::latest('id')->with('items')->first();
        $post(['preparation' => $this->prepInput()]);
        $prepared = CustomerInvoice::latest('id')->with('items')->first();

        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $col) {
            $this->assertSame((string) $plain->{$col}, (string) $prepared->{$col}, $col);
        }
        $this->assertSame(
            $plain->items->map->only(['product_id', 'description', 'quantity', 'unit_price', 'discount', 'tax_amount', 'line_total'])->all(),
            $prepared->items->map->only(['product_id', 'description', 'quantity', 'unit_price', 'discount', 'tax_amount', 'line_total'])->all(),
        );
        $this->assertSame('CINV-000002', $prepared->number, 'الترقيمُ يمضي كما هو');
        $this->assertNotNull($this->prep($prepared));
        $this->assertNull($this->prep($prepared)->activated_at, 'لا يظهر قبل السداد');
    }

    public function test_an_existing_unpaid_invoice_gains_preparation_without_touching_its_lines(): void
    {
        $invoice = $this->invoice(null);
        $before = $invoice->items()->get()->toArray();

        $this->post(route('admin.customerInvoices.preparation.save', $invoice->id), ['preparation' => $this->prepInput()])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->prep($invoice));
        $this->assertSame($before, $invoice->items()->get()->toArray());
        $this->assertEqualsWithDelta(105.0, (float) $invoice->fresh()->total, 0.0005);

        // وفاتورةٌ سُدّدت لا يُطلب لها تجهيزٌ جديد
        $paid = $this->invoice(null);
        CustomerPayments::record($this->shop->id, $this->customer, 105, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$paid->id => 105]);
        $this->post(route('admin.customerInvoices.preparation.save', $paid->id), ['preparation' => $this->prepInput()])
            ->assertSessionHasErrors('preparation');
        $this->assertNull($this->prep($paid));
    }

    /** وبابُ طلب التجهيز على فاتورةٍ قائمة يسأل حقولَه — ولو لم يُرسَل مربّعُ «إرسال» */
    public function test_the_existing_invoice_door_asks_its_fields_without_the_checkbox(): void
    {
        $invoice = $this->invoice(null);

        $this->post(route('admin.customerInvoices.preparation.save', $invoice->id), ['preparation' => ['fulfillment_type' => 'pickup']])
            ->assertSessionHasErrors(['preparation.branch_id', 'preparation.scheduled_for', 'preparation.recipient_name']);
        $this->assertNull($this->prep($invoice));

        // ونشاطٌ لم يُفتح له لا يجد البابَ أصلًا
        $this->open($this->shop, false);
        $this->post(route('admin.customerInvoices.preparation.save', $invoice->id), ['preparation' => $this->prepInput()])->assertNotFound();
        $this->assertNull($this->prep($invoice));
    }

    public function test_an_invoice_that_covers_orders_gets_no_second_preparation(): void
    {
        $invoice = $this->invoice(null);
        $order = Order::create(['business_id' => $this->shop->id, 'number' => 'INV-000001', 'status' => OrderStatus::CONFIRMED, 'total' => 105, 'subtotal' => 100, 'ordered_at' => now()]);
        $invoice->orders()->attach($order->id);

        $this->expectException(ValidationException::class);
        CustomerInvoicePreparations::save($invoice, $this->prepInput(), $this->owner->id);
    }

    /** رسالةٌ تكشف اسمَ من أخفى نفسَه — تُعدَّل أو تُؤكَّد صراحةً */
    public function test_a_card_that_names_a_hidden_sender_needs_an_explicit_yes(): void
    {
        $invoice = $this->invoice(null);
        $card = ['is_gift' => true, 'hide_sender' => true, 'sender_name' => 'شركة الضيافة', 'card_message' => 'مع تحيات شركة الضيافة'];

        try {
            CustomerInvoicePreparations::save($invoice, $this->prepInput($card), $this->owner->id);
            $this->fail('رسالةٌ تكشف المرسل حُفظت بلا تأكيد');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preparation.card_message', $e->errors());
        }

        $prep = CustomerInvoicePreparations::save($invoice, $this->prepInput($card + ['card_identity_confirmed' => true]), $this->owner->id);
        $this->assertNotNull($prep->card_identity_confirmed_at);

        // ورسالةٌ لا تكشف شيئًا لا تُسأل
        $quiet = CustomerInvoicePreparations::save($this->invoice(null), $this->prepInput(['hide_sender' => true, 'is_gift' => true, 'card_message' => 'كل عام وأنتِ بخير']), $this->owner->id);
        $this->assertNull($quiet->card_identity_confirmed_at);
    }

    /* ============================== السداد ============================== */

    public function test_a_verified_full_payment_reaches_the_board_once_however_often_paymob_repeats(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $obj = $this->obj($link);

        foreach (range(1, 5) as $_) {
            $this->hook($obj);
        }

        $prep = $this->prep($invoice);
        $this->assertNotNull($prep->activated_at);
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $prep->activation_source);
        $this->assertSame(OrderStatus::CONFIRMED, $prep->status);
        $this->assertSame((int) $link->fresh()->customer_payment_id, (int) $prep->activation_payment_id);
        $this->assertSame(1, CustomerPayment::count(), 'تحصيلٌ واحد');
        $this->assertSame(1, PrintJob::count(), 'ورقةٌ واحدة للصندوق');
        $this->assertSame(1, ActivityLog::where('subject_type', 'customer_invoice_preparation')->where('description', 'like', 'وصلت الفاتورة%')->count());

        // ونداءٌ مباشرٌ ثانٍ — كإشعارٍ متزامنٍ سبقه الأوّل — لا يُفعّل ثانيةً
        [$again, $why] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);
        $this->assertNull($again);
        $this->assertNull($why);
        $this->assertSame(1, PrintJob::count());
    }

    /**
     * دفعٌ وصل والمفتاحُ مغلق — المالُ يُسجَّل، والتجهيزُ لا يظهر.
     *
     * ثمّ يُفتح المفتاحُ بعد يوم: لا المطابقةُ ولا إشعارٌ مكرَّرٌ من Paymob
     * يُظهره ويطبع ورقتَه وحده — جُهّز بيدٍ يومَها أو لم يُجهَّز، وصاحبُ
     * التحصيل يُرسله بيده إن لزم. ورابطٌ يُدفع بعد الفتح يُفعِّل كما كان.
     */
    public function test_a_link_paid_while_the_flag_is_off_keeps_its_money_and_is_not_replayed_when_it_reopens(): void
    {
        $boss = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@abaadapp.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        $toggle = function (string $on) use ($boss) {
            $this->actingAs($boss)->put(route('super-admin.businesses.update', $this->shop->id), [
                'name' => $this->shop->name, 'type' => $this->shop->type, 'status' => 'نشط',
                'customer_invoice_preparation_enabled' => $on,
            ])->assertSessionHasNoErrors();
            $this->actingAs($this->owner);
        };

        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $obj = $this->obj($link);

        $toggle('0');
        $this->hook($obj);

        $this->assertSame(1, CustomerPayment::count(), 'المالُ يُسجَّل كما يُسجَّل');
        $this->assertTrue(CustomerInvoicePreparations::paid($invoice->fresh()));
        $this->assertNull($this->prep($invoice)->activated_at, 'والمفتاحُ مغلق لا يُفعِّل');
        $this->assertSame(0, PrintJob::count());

        Carbon::setTestNow(now()->addDay());
        $toggle('1');
        $this->assertNotNull($this->shop->fresh()->customer_invoice_preparation_enabled_at);

        CustomerInvoicePreparations::reconcile();
        $this->hook($obj);
        $this->assertNull($this->prep($invoice)->activated_at, 'ولا يظهر وحده حين يُفتح');
        $this->assertSame(0, PrintJob::count(), 'ولا تُطبع ورقتُه');
        $this->assertSame(1, CustomerPayment::count(), 'ولا تحصيلٌ ثانٍ');

        // ويُرسله بيده من يملك التحصيل
        $this->post(route('admin.customerInvoices.preparation.activate', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoicePreparations::FROM_HAND, $this->prep($invoice)->activation_source);
        $this->assertSame(1, PrintJob::count());

        // ورابطٌ يُدفع بعد الفتح يُفعِّل كما كان
        $next = $this->invoice();
        $this->hook($this->obj($this->link($next)));
        $this->assertSame(CustomerInvoicePreparations::FROM_PAYMOB, $this->prep($next)->activation_source);
        $this->assertSame(2, PrintJob::count());
    }

    public function test_a_partial_payment_waits_and_the_link_that_completes_it_activates_once(): void
    {
        $invoice = $this->invoice();

        $first = $this->link($invoice, 60);
        $this->hook($this->obj($first));
        $this->assertNull($this->prep($invoice)->activated_at, 'دفعةٌ جزئيّة لا تُظهر المهمّة');
        $this->assertSame(0, PrintJob::count());
        $this->assertSame('مدفوعة جزئيًا', $invoice->fresh()->paymentState());

        $second = $this->link($invoice->fresh(), 45);
        $this->hook($this->obj($second));
        $this->hook($this->obj($second, ['id' => $this->tx]));

        $prep = $this->prep($invoice);
        $this->assertNotNull($prep->activated_at);
        $this->assertSame((int) $second->fresh()->customer_payment_id, (int) $prep->activation_payment_id);
        $this->assertSame(1, PrintJob::count());
    }

    public function test_only_a_signed_settled_matching_notification_activates(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);

        $this->hook($this->obj($link), 'not-the-signature');
        $this->hook($this->obj($link, ['success' => false]));
        $this->hook($this->obj($link, ['pending' => true]));
        $this->hook($this->obj($link, ['amount_cents' => 100]));
        $this->hook($this->obj($link, ['currency' => 'USD']));
        $this->hook($this->obj($link, ['is_live' => false]));

        $this->assertSame(0, CustomerPayment::count());
        $this->assertNull($this->prep($invoice)->activated_at);
        $this->assertSame(0, PrintJob::count());

        // ولا تفعيلَ بطلب المتصفّح العائد من Paymob — لا بابَ له أصلًا
        $this->get('/admin/customer-invoices/'.$invoice->id.'?success=true&paid=1')->assertOk();
        $this->assertNull($this->prep($invoice)->activated_at);
    }

    /**
     * ومالُ رابطٍ ملغًى أو منتهٍ يُسجَّل كما يُسجَّل — ولا يُظهر المهمّة وحده.
     *
     * يُرسلها بيده من يملك التحصيل بعد أن يراجع؛ ومن لا يملكه يُردّ.
     */
    public function test_a_cancelled_or_expired_link_records_the_money_but_activates_nothing_by_itself(): void
    {
        $cancelled = $this->invoice();
        $link = $this->link($cancelled);
        $link->forceFill(['status' => CustomerPaymentLink::CANCELLED, 'cancelled_at' => now()->subMinute()])->save();
        $this->hook($this->obj($link));

        $this->assertSame(1, CustomerPayment::count(), 'المالُ سُجّل');
        $this->assertNull($this->prep($cancelled)->activated_at);

        $expired = $this->invoice();
        $old = $this->link($expired);
        $old->forceFill(['status' => CustomerPaymentLink::EXPIRED, 'expires_at' => now()->subDay()])->save();
        $this->hook($this->obj($old));
        $this->assertNull($this->prep($expired)->activated_at);

        // والمطابقةُ لا تُفعّلها كذلك — لا تُفعّل إلّا ما يُفعّله الإشعار
        Artisan::call('customer-invoices:reconcile-preparations');
        $this->assertNull($this->prep($cancelled)->activated_at);

        // ومن لا يملك التحصيل لا يُرسلها
        $cook = User::create(['business_id' => $this->shop->id, 'name' => 'المجهّز', 'email' => 'prep@prep.om', 'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['orders']]);
        $this->actingAs($cook)->post(route('admin.customerInvoices.preparation.activate', $cancelled->id))->assertForbidden();
        $this->assertNull($this->prep($cancelled)->activated_at);

        // ومن يملكه يُرسلها بعد المراجعة — والسدادُ يُقرأ في الخادم
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.preparation.activate', $cancelled->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoicePreparations::FROM_HAND, $this->prep($cancelled)->activation_source);
        $this->assertSame($this->owner->id, (int) $this->prep($cancelled)->activated_by);
    }

    /** والإرسالُ باليد لا يُرسل فاتورةً لم تُسدَّد */
    public function test_sending_by_hand_reads_the_payment_on_the_server(): void
    {
        $invoice = $this->invoice();

        $this->post(route('admin.customerInvoices.preparation.activate', $invoice->id))->assertSessionHasErrors('preparation');
        $this->assertNull($this->prep($invoice)->activated_at);

        CustomerPayments::record($this->shop->id, $this->customer, 105, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$invoice->id => 105]);
        $this->assertNull($this->prep($invoice)->activated_at, 'التحصيلُ النقديّ لا يُظهرها وحده');

        $this->post(route('admin.customerInvoices.preparation.activate', $invoice->id))->assertSessionHasNoErrors();
        $this->assertNotNull($this->prep($invoice)->activated_at);
    }

    /** ودفعةٌ أُلغيت بعد الظهور لا تُخفي العمل — لكنّها لا تُقال «مدفوعة» */
    public function test_a_payment_reversed_after_activation_leaves_the_task_but_not_the_word_paid(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $this->hook($this->obj($link));
        $this->assertNotNull($this->prep($invoice)->activated_at);

        CustomerPayments::cancel(CustomerPayment::findOrFail($link->fresh()->customer_payment_id), 'استرجاع', $this->owner->id);

        $this->assertNotNull($this->prep($invoice)->activated_at, 'العملُ الذي بدأ لا يُمحى');
        $this->assertFalse(CustomerInvoicePreparations::paid($invoice->fresh()));
        $this->assertSame(1, PrintJob::count(), 'ولا ورقةَ ثانية');
    }

    /** ومسودّةٌ أو ملغاةٌ باقيها صفرٌ بالحساب — لا تُعدّ مسدَّدة */
    public function test_a_draft_or_cancelled_invoice_is_never_paid_for_the_board(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 30],
        ]);
        CustomerInvoicePreparations::save($draft, $this->prepInput(), $this->owner->id);
        $this->assertSame(0.0, (float) $draft->fresh()->outstanding(), 'المسودّةُ باقيها صفرٌ بالحساب');

        [$none, $why] = CustomerInvoicePreparations::activate($draft->id, CustomerInvoicePreparations::FROM_HAND, null, $this->owner->id);
        $this->assertNull($none);
        $this->assertSame('الفاتورة غير صادرة.', $why);

        $cancelled = $this->invoice();
        CustomerInvoices::cancel($cancelled, 'خطأ', $this->owner->id);
        [$none] = CustomerInvoicePreparations::activate($cancelled->id, CustomerInvoicePreparations::FROM_HAND, null, $this->owner->id);
        $this->assertNull($none);
        $this->assertNull($this->prep($cancelled)->activated_at);
    }

    /** وفاتورةٌ صارت تغطّي طلبًا بعد أن طُلب تجهيزُها — طلبُها في اللوحة، فلا مهمّةَ ثانية */
    public function test_an_invoice_that_came_to_cover_an_order_does_not_activate(): void
    {
        $invoice = $this->invoice();
        $order = Order::create(['business_id' => $this->shop->id, 'number' => 'INV-000009', 'status' => OrderStatus::CONFIRMED, 'total' => 105, 'subtotal' => 100, 'ordered_at' => now()]);
        $invoice->orders()->attach($order->id);

        $this->hook($this->obj($this->link($invoice)));

        $this->assertNull($this->prep($invoice)->activated_at);
        $this->assertSame(0, PrintJob::count());
    }

    /**
     * ودفعةُ رابطٍ أُلغيت لا تُحتسب — ولو سُدّدت الفاتورةُ بغيرها.
     *
     * الإشعارُ المكرَّر بعدها لا يُظهر المهمّة بدفعةٍ ملغاة: تُرسلها يدٌ بعد المراجعة.
     */
    public function test_a_cancelled_link_payment_never_activates_even_when_the_invoice_is_paid_otherwise(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $obj = $this->obj($link);

        $this->open($this->shop, false);
        $this->hook($obj);
        $this->assertNull($this->prep($invoice)->activated_at);

        CustomerPayments::cancel(CustomerPayment::findOrFail($link->fresh()->customer_payment_id), 'استرجاع', $this->owner->id);
        CustomerPayments::record($this->shop->id, $this->customer, 105, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$invoice->id => 105]);
        $this->assertTrue(CustomerInvoicePreparations::paid($invoice->fresh()));

        $this->open($this->shop);
        $this->hook($obj);
        Artisan::call('customer-invoices:reconcile-preparations');

        $this->assertNull($this->prep($invoice)->activated_at, 'دفعةٌ ملغاةٌ لا تكون بابًا');
        $this->assertFalse(CustomerInvoicePreparations::linkCounts($link->fresh()));
    }

    /** ومتجرٌ مغلقٌ لا تُفعَّل فيه مهمّةٌ ولو كُتبت قبل إغلاقه */
    public function test_a_shop_closed_after_the_request_activates_nothing(): void
    {
        $invoice = $this->invoice();
        $this->open($this->shop, false);

        $this->hook($this->obj($this->link($invoice)));

        $this->assertNull($this->prep($invoice)->activated_at);
        $this->assertSame(0, PrintJob::count());
    }

    /* ============================== السقوط والمطابقة ============================== */

    public function test_a_failed_activation_keeps_the_money_and_is_retried(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);

        // ورقةُ الصندوق لا تُكتب — فيسقط التفعيلُ بعد ثبات المال
        Schema::rename('print_jobs', 'print_jobs_away');
        $this->hook($this->obj($link));

        $this->assertSame(1, CustomerPayment::count(), 'المالُ ثابت');
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $prep = $this->prep($invoice);
        $this->assertNull($prep->activated_at);
        $this->assertNotNull($prep->activation_error, 'وسببُ السقوط مكتوبٌ يُرى');

        Schema::rename('print_jobs_away', 'print_jobs');
        Artisan::call('customer-invoices:reconcile-preparations');

        $prep = $this->prep($invoice);
        $this->assertNotNull($prep->activated_at);
        $this->assertSame(CustomerInvoicePreparations::FROM_RECONCILE, $prep->activation_source);
        $this->assertNull($prep->activation_error);
        $this->assertSame(1, PrintJob::count());

        // والمطابقةُ مرّةً ثانية لا تكتب شيئًا
        Artisan::call('customer-invoices:reconcile-preparations');
        $this->assertSame(1, PrintJob::count());
    }

    /** ومهمّتان لفاتورةٍ واحدة يردّهما الفهرسُ الفريد — لا الكودُ وحده */
    public function test_the_database_refuses_a_second_task_for_one_invoice(): void
    {
        $invoice = $this->invoice();

        $this->expectException(QueryException::class);
        CustomerInvoicePreparation::create([
            'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id,
            'fulfillment_type' => 'pickup', 'scheduled_for' => now(),
        ]);
    }

    /* ============================== لا بيعَ ثانيًا ============================== */

    public function test_activation_and_every_move_write_no_sale_no_entry_no_payment_no_stock(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 50, 'cost' => 10, 'quantity' => 7]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, ['issued_at' => '2026-10-19'], [
            ['product_id' => $product->id, 'description' => 'بوكيه', 'quantity' => 2, 'unit_price' => 50],
        ]));
        CustomerInvoicePreparations::save($invoice, $this->prepInput(), $this->owner->id);
        $link = $this->link($invoice->fresh());

        $count = fn () => [
            'orders' => Order::count(),
            'invoices' => CustomerInvoice::count(),
            'entries' => JournalEntry::count(),
            'payments' => CustomerPayment::count(),
            'movements' => InventoryMovement::count(),
            'stock' => (float) Product::whereKey($product->id)->value('quantity'),
            'revenue' => round(Ledger::balance($this->shop->id, 'sales'), 3),
            'vat' => round(Ledger::balance($this->shop->id, 'tax_payable'), 3),
        ];

        $this->hook($this->obj($link));
        $afterPayment = $count();
        $this->assertSame(1, $afterPayment['payments']);

        $prep = $this->prep($invoice);
        $this->assertNotNull($prep->activated_at);

        foreach ([OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED] as $to) {
            $this->assertNull(CustomerInvoicePreparations::move($this->shop->id, $prep->id, null, $to));
        }

        $this->assertSame($afterPayment, $count(), 'التفعيلُ والتسليمُ لا يكتبان مالًا ولا مخزونًا');
        $this->assertSame(0, Order::count(), 'ولا طلبَ يُكتب ليظهر في اللوحة');
    }
}
