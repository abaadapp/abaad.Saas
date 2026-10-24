<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymobSettlement;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * رابطُ الدفع — تحصيلٌ من عميلٍ عبر Paymob، لا بيعٌ ثانٍ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإعداد: مفتاحُ API مشفَّرٌ لا يخرج، والبيئةُ صريحة، ولكلّ متجرٍ مفاتيحُه.
 *   - الإنشاء: باقي الفاتورة افتراضًا، ومن متجرها وعلى عميلها، ولا يتجاوزه.
 *     وفشلُ Paymob لا يُعطي رابطًا.
 *   - الإشعار وحده يقول «دُفع»: توقيعٌ ومبلغٌ وعملةٌ وحالٌ مستقرّة — ومرّةً واحدة.
 *   - الدفتر: ذممٌ −، «Paymob تحت التسوية» +، والبنكُ لا يتحرّك، والإيرادُ
 *     والضريبةُ والتكلفةُ كما هي. والتسويةُ: بنكٌ + صافٍ، رسومٌ مصروفًا،
 *     والتسويةُ تُصفّر.
 *   - العزل: متجرٌ لا يرى رابطَ غيره ولا يلغيه ولا يستعمل عميلَه وفاتورتَه.
 *   - ولا شيءَ منها في لوحة المنصّة.
 */
class APaymentLinkCollectsAndNeverSellsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private User $stranger;

    private Customer $ministry;

    private Customer $company;

    private int $tx = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-11 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        // متجرٌ مسجَّل يجبي الضريبة — بالتفعيل الصريح، فالغيابُ إطفاء (`Vat::enabled`)
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '1']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@links.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->stranger = User::create([
            'business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'other@links.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'email' => 'pay@moc.om', 'allow_credit_sales' => true]);
        $this->company = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الورد', 'phone' => '92222222', 'allow_credit_sales' => true]);

        $this->gateway($this->shop, 'hmac-shop', 'api-shop');
        $this->gateway($this->other, 'hmac-other', 'api-other');

        $this->actingAs($this->owner);
        $this->fakePaymob();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function gateway(Business $shop, string $hmac, ?string $api, string $mode = PaymentGateway::TEST): PaymentGateway
    {
        return PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => $hmac,
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => $api, 'mode' => $mode,
        ]);
    }

    /** Paymob مزيَّفة — رمزٌ ورابطٌ وإلغاء، ولا اتّصالَ حقيقيّ */
    private function fakePaymob(array $link = [], int $status = 200): void
    {
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok-secret-123']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true, 'message' => 'Canceled by merchant', 'payment_link_id' => 580010]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response($link + [
                'id' => 580010, 'amount_cents' => 105000, 'shorten_url' => 'https://paymob.link/abc123',
                'client_url' => 'https://oman.paymob.com/api/ecommerce/payment-links/unrestricted?token=xyz',
                'created_at' => '2026-10-11T10:00:00', 'expires_at' => '2026-10-18T23:59:00', 'state' => 'created', 'order' => 616057558,
            ], $status),
        ]);
    }

    /** فاتورةٌ صادرة: بندان × ٥٠ + ٥٪ = ١٠٥ */
    private function invoice(?Customer $customer = null, ?Business $shop = null): CustomerInvoice
    {
        $shop ??= $this->shop;
        $invoice = CustomerInvoices::create($shop->id, $customer ?? $this->ministry, ['issued_at' => '2026-10-10'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ]);

        return CustomerInvoices::issue($invoice);
    }

    private function link(?CustomerInvoice $invoice = null, ?float $amount = null, ?Customer $customer = null): CustomerPaymentLink
    {
        return PaymentLinks::create($this->shop->id, $customer ?? $this->ministry, $invoice, $amount, null, $this->owner->id);
    }

    /** إشعارُ Paymob موقَّعًا بسرّ متجر الرابط */
    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        return array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => false,
        ], $over);
    }

    private function hook(array $obj, string $secret = 'hmac-shop', ?string $hmac = null)
    {
        return $this->postJson('/webhooks/paymob?hmac='.($hmac ?? Paymob::signature($obj, $secret)), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function balance(string $key, ?Business $shop = null): float
    {
        return round(Ledger::balance(($shop ?? $this->shop)->id, $key), 3);
    }

    /** ما لا يجوز أن يتحرّك بالتحصيل: الإيرادُ والضريبةُ والتكلفة */
    private function sales(): array
    {
        return [
            'revenue' => Ledger::netRevenue($this->shop->id),
            'tax' => Ledger::outputTax($this->shop->id),
            'cogs' => $this->balance('cogs'),
            'orders' => Order::count(),
        ];
    }

    /* ═══════════ ١ · المبلغ بأصغر وحدات العملة ═══════════ */

    public function test_amounts_are_sent_in_the_currencys_smallest_unit_not_times_a_hundred(): void
    {
        $this->assertSame(20000, Amount::toMinor(20, 'OMR'));
        $this->assertSame(20000, Amount::toMinor('20.000', 'omr'));
        $this->assertSame(1, Amount::toMinor(0.001, 'OMR'));
        $this->assertSame(105250, Amount::toMinor(105.25, 'OMR'));
        $this->assertSame(10050, Amount::toMinor(10.05, 'KWD'));
        $this->assertSame(1050, Amount::toMinor(10.5, 'USD'));
        $this->assertSame(500, Amount::toMinor(500, 'JPY'));
        $this->assertSame(20.0, Amount::fromMinor(20000, 'OMR'));
        $this->assertSame(0.001, Amount::fromMinor(1, 'OMR'));
        $this->assertSame(10.5, Amount::fromMinor(1050, 'USD'));
    }

    /* ═══════════ ٢ · الإعداد ═══════════ */

    public function test_the_api_key_is_encrypted_hidden_never_shown_and_replaced_only_when_written(): void
    {
        $gateway = PaymentGateway::where('business_id', $this->shop->id)->firstOrFail();

        $raw = DB::table('payment_gateways')->where('id', $gateway->id)->value('api_key');
        $this->assertNotSame('api-shop', $raw, 'مفتاحُ API محفوظٌ نصًّا');
        $this->assertSame('api-shop', $gateway->api_key);
        $this->assertArrayNotHasKey('api_key', $gateway->toArray());

        $page = $this->get(route('admin.integrations.paymob'))->assertOk();
        $props = $page->viewData('page')['props']['gateway'];
        $this->assertTrue($props['has_api_key']);
        $this->assertSame('test', $props['mode']);
        $this->assertTrue($props['links_ready']);
        $this->assertStringNotContainsString('api-shop', json_encode($page->viewData('page')));

        // حفظٌ بلا مفتاحٍ لا يمحوه، ومفتاحٌ جديدٌ يبدّله — والبيئةُ تُكتب صراحةً
        $save = fn (array $over) => $this->post(route('admin.integrations.paymob.save'), $over + [
            'active' => true, 'public_key' => 'pk_1', 'card_integration_id' => '111',
            'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
        ]);
        $save(['mode' => 'live'])->assertSessionHasNoErrors();
        $this->assertSame('api-shop', $gateway->fresh()->api_key);
        $this->assertSame('live', $gateway->fresh()->mode);
        $save(['api_key' => 'api-new'])->assertSessionHasNoErrors();
        $this->assertSame('api-new', $gateway->fresh()->api_key);
        $save(['mode' => 'production'])->assertSessionHasErrors('mode');

        // والجارُ يرى حالَ مفاتيحه هو — لا مفاتيحَ غيره
        $this->actingAs($this->stranger);
        $this->assertStringNotContainsString('api-new', json_encode($this->get(route('admin.integrations.paymob'))->viewData('page')));

        // ولا يخرج في ملفّ النسخة الاحتياطيّة
        $this->assertContains('api_key', TenantTables::SECRETS['payment_gateways']);
    }

    public function test_test_and_live_are_never_mixed_apple_pay_rides_only_with_live(): void
    {
        $this->link($this->invoice());

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/api/ecommerce/payment-links')) {
                return false;
            }
            $parts = collect($r->data())->map(fn ($p) => [$p['name'], $p['contents']]);

            return $parts->contains(['is_live', 'false'])
                && $parts->contains(['payment_methods', '111'])
                && $parts->contains(['payment_methods', '222'])
                && ! $parts->contains(['payment_methods', '333']);
        });
        $this->assertFalse(CustomerPaymentLink::latest('id')->first()->is_live);

        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => PaymentGateway::LIVE]);
        $this->link($this->invoice());

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'is_live' && $p['contents'] === 'true')
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'payment_methods' && $p['contents'] === '333'));
        $this->assertTrue(CustomerPaymentLink::latest('id')->first()->is_live);

        // وبلا بيئةٍ مكتوبةٍ لا رابط — لا تُخمَّن
        PaymentGateway::where('business_id', $this->shop->id)->update(['mode' => null]);
        $this->assertFalse(PaymentLinks::ready($this->shop->id));
    }

    /* ═══════════ ٣ · رابطُ الفاتورة ═══════════ */

    public function test_an_invoice_link_asks_the_outstanding_of_its_own_invoice_and_sells_nothing(): void
    {
        $invoice = $this->invoice();
        $before = $this->sales();
        $entries = JournalEntry::count();

        $this->post(route('admin.finance.paymentLinks.store'), [
            'customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('paymentLink');

        $link = CustomerPaymentLink::firstOrFail();
        $this->assertSame([$this->shop->id, $this->ministry->id, $invoice->id], [(int) $link->business_id, (int) $link->customer_id, (int) $link->customer_invoice_id]);
        $this->assertEquals(105.0, (float) $link->amount);
        $this->assertSame('OMR', $link->currency);
        $this->assertSame(CustomerPaymentLink::PENDING, $link->status);
        $this->assertSame('https://paymob.link/abc123', $link->short_url);
        $this->assertSame(['580010', '616057558'], [$link->provider_link_id, $link->provider_order_id]);
        $this->assertMatchesRegularExpression('/^pay-[a-z0-9]{32}$/', $link->reference);

        Http::assertSent(function (HttpRequest $r) use ($link) {
            if (str_ends_with($r->url(), '/api/auth/tokens')) {
                return $r['api_key'] === 'api-shop';
            }
            $parts = collect($r->data())->mapWithKeys(fn ($p) => [$p['name'] => $p['contents']]);

            return $r->hasHeader('Authorization', 'Bearer tok-secret-123')
                && $parts['amount_cents'] === '105000' && $parts['currency'] === 'OMR'
                && $parts['reference_id'] === $link->reference
                && $parts['notification_url'] === rtrim(config('app.url'), '/').'/webhooks/paymob'
                && $parts['phone_number'] === '+96891234567';
        });

        // لا طلبَ ولا قيدَ ولا إيرادَ ولا ضريبةَ قبل الدفع ولا بعد الإنشاء
        $this->assertSame($before, $this->sales());
        $this->assertSame($entries, JournalEntry::count());
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'customer_payment_link', 'subject_id' => $link->id, 'business_id' => $this->shop->id]);
    }

    public function test_the_default_amount_is_what_remains_not_the_invoice_total(): void
    {
        $invoice = $this->invoice();
        CustomerPayments::record($this->shop->id, $this->ministry, 30, ['method' => 'نقدي'], [$invoice->id => 30]);

        $this->fakePaymob(['amount_cents' => 75000]);
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id])
            ->assertSessionHasNoErrors();

        $this->assertEquals(75.0, (float) CustomerPaymentLink::firstOrFail()->amount);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'amount_cents' && $p['contents'] === '75000'));
    }

    public function test_an_invoice_link_refuses_what_it_cannot_ask(): void
    {
        $invoice = $this->invoice();
        $theirs = $this->invoice(Customer::create(['business_id' => $this->other->id, 'name' => 'عميل الجار']), $this->other);

        // فاتورةُ متجرٍ آخر: ٤٠٤ لا رابط
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $theirs->id])->assertNotFound();
        // فاتورةٌ على عميلٍ آخر
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->company->id, 'customer_invoice_id' => $invoice->id])->assertSessionHasErrors('amount');
        // أكثرُ من الباقي
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id, 'amount' => 105.001])->assertSessionHasErrors('amount');

        // مسدَّدة: لا باقي
        CustomerPayments::record($this->shop->id, $this->ministry, 105, ['method' => 'نقدي'], [$invoice->id => 105]);
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id])->assertSessionHasErrors('amount');

        // ملغاة — ولو بقي عليها باقٍ في حسابها
        $cancelled = $this->invoice();
        CustomerInvoices::cancel($cancelled, 'خطأ');
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $cancelled->id])
            ->assertSessionHasErrors(['amount' => 'لا يُطلب دفعُ فاتورةٍ غير صادرة.']);

        // مسودّة
        $draft = CustomerInvoices::create($this->shop->id, $this->ministry, [], [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]]);
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $draft->id])->assertSessionHasErrors('amount');

        $this->assertSame(0, CustomerPaymentLink::count());
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links'));
    }

    public function test_without_a_finished_paymob_setup_no_link_is_made_and_the_screen_says_so(): void
    {
        PaymentGateway::where('business_id', $this->shop->id)->update(['api_key' => null]);
        $invoice = $this->invoice();

        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id])
            ->assertSessionHasErrors(['amount' => 'أكمل إعداد Paymob أولًا.']);
        Http::assertNothingSent();

        $panel = $this->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props']['paymentLinks'];
        $this->assertFalse($panel['ready']);
        $this->assertSame(route('admin.integrations.paymob'), $panel['settings_url']);
    }

    public function test_a_paymob_failure_gives_no_link_and_says_why_without_secrets(): void
    {
        /*
         * وكلُّ فشلٍ على فاتورته: فشلٌ «لا نعرف» (خطأُ خادمٍ عند Paymob، أو
         * جوابٌ لا يُقرأ، أو انقطاع) يمنع إعادةَ الطلب وحده على فاتورته — انظر
         * `AnInvoiceIsIssuedAndAskedForPaymentInOneStepTest`. والرفضُ الصريح لا يمنع.
         */
        foreach ([
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['detail' => 'Incorrect api key'], 401)]), 'رفضت Paymob مفتاح API', false],
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links' => Http::response(['detail' => 'boom'], 500)]), 'Paymob غير متاحة', true],
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links' => Http::response(['detail' => 'x'], 404)]), 'لا يطابق بيئة الحساب', false],
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links' => Http::response(['id' => 1, 'shorten_url' => ''])]), 'بلا رابطٍ صالح', true],
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links' => Http::response('not json')]), 'بما لا يُقرأ', true],
            [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timeout')]), 'تعذّر الاتصال', false],
            [fn () => Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links' => fn () => throw new ConnectionException('timeout')]), 'تعذّر الاتصال', true],
        ] as [$fake, $why, $uncertain]) {
            app()->forgetInstance(Factory::class);
            Http::swap(new Factory);
            $fake();
            $invoice = $this->invoice();

            $res = $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id]);
            $res->assertSessionHasErrors('amount');
            $this->assertStringContainsString($why, session('errors')->first('amount'));
            $this->assertStringNotContainsString('api-shop', session('errors')->first('amount'));

            // وما لا تُعرف نتيجتُه يُوسَم كذلك — وطلبُ الرمز وحده لا يُنشئ شيئًا هناك
            $failed = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
            $this->assertSame($uncertain, (bool) (($failed->meta ?? [])['uncertain'] ?? false), $why);
        }

        // صفوفُ «تعذّر» للتدقيق — بلا رابطٍ يُرسَل، ولا تُعرض في القوائم
        $this->assertSame(0, CustomerPaymentLink::where('status', '!=', CustomerPaymentLink::FAILED)->count());
        $this->assertSame(0, CustomerPaymentLink::whereNotNull('short_url')->count());
        $this->assertSame([], $this->get(route('admin.finance.paymentLinks'))->viewData('page')['props']['links']);
    }

    /* ═══════════ ٤ · الإشعار والدفتر ═══════════ */

    public function test_a_paid_invoice_link_collects_once_into_paymob_clearing_and_sells_nothing(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);
        $before = $this->sales();
        $bank = $this->balance('bank');

        $this->assertEquals(105.0, $this->balance('receivable'));

        $obj = $this->obj($link);
        foreach (range(1, 10) as $i) {
            $this->hook($obj);
        }

        $link->refresh();
        $this->assertSame(CustomerPaymentLink::PAID, $link->status);
        $this->assertSame((string) $obj['id'], $link->provider_transaction_id);

        $this->assertSame(1, CustomerPayment::count(), 'عشرةُ إشعاراتٍ كتبت أكثرَ من تحصيل');
        $payment = CustomerPayment::firstOrFail();
        $this->assertSame((int) $payment->id, (int) $link->customer_payment_id);
        $this->assertSame(CustomerPayments::PAYMOB, $payment->method);
        $this->assertEquals(105.0, (float) $payment->amount);
        $this->assertSame(1, CustomerPaymentAllocation::count());
        $this->assertSame(1, JournalEntry::where('source', CustomerPayments::SOURCE)->count());

        $this->assertEquals(0.0, $invoice->fresh()->outstanding());
        $this->assertEquals(0.0, $this->balance('receivable'));
        $this->assertEquals(105.0, $this->balance('paymob_clearing'));
        $this->assertEquals($bank, $this->balance('bank'), 'دخل البنكَ مالٌ لم يُحوَّل بعد');

        // الإيرادُ والضريبةُ والتكلفةُ والطلباتُ كما كانت
        $this->assertSame($before, $this->sales());

        // وكشفُ العميل يقول التحصيل
        $statement = $this->get(route('finance.customerStatement' === '' ? '' : 'admin.finance.customerStatement', $this->ministry->id))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString($payment->number, json_encode($statement, JSON_UNESCAPED_UNICODE));
    }

    public function test_only_a_signed_settled_matching_notification_pays(): void
    {
        $link = $this->link($this->invoice());

        $this->hook($this->obj($link), hmac: str_repeat('a', 128));                         // توقيعٌ خاطئ
        $this->hook($this->obj($link), 'hmac-other');                                        // سرُّ متجرٍ آخر
        $this->hook($this->obj($link, ['amount_cents' => 104999]));                          // مبلغٌ آخر
        $this->hook($this->obj($link, ['currency' => 'USD']));                               // عملةٌ أخرى
        $this->hook($this->obj($link, ['pending' => true]));                                 // معلَّقة
        $this->hook($this->obj($link, ['is_voided' => true]));                               // أُلغيت
        $this->hook($this->obj($link, ['is_refunded' => true]));                             // استُرجعت
        $this->hook($this->obj($link, ['success' => false, 'data' => ['message' => 'Declined']])); // رُدّت
        $this->hook($this->obj($link, ['is_live' => true]));                                 // بيئةٌ أخرى

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(CustomerPaymentLink::PENDING, $link->fresh()->status);
        $this->assertNotNull($link->fresh()->error);

        $this->hook($this->obj($link));
        $this->assertSame(1, CustomerPayment::count());
    }

    /**
     * وما رُدّ يُقال على الرابط نفسِه — وأوّلُه توقيعٌ لا يطابق: أرجحُ أسباب
     * «دفع العميلُ ولم يُسجَّل»، وكان في سجلّ الخادم وحده.
     */
    public function test_why_a_notification_was_refused_is_said_on_the_link(): void
    {
        $link = $this->link($this->invoice());
        $why = fn () => PaymentLinks::row($link->fresh())['error'];

        $this->hook($this->obj($link), 'hmac-other');
        $this->assertSame('وصل إشعارُ دفعٍ بتوقيعٍ لا يطابق HMAC المحفوظ — راجع HMAC في إعدادات Paymob.', $why());

        $this->hook($this->obj($link, ['amount_cents' => 104999]));
        $this->assertSame('المبلغُ أو العملةُ لا يطابقان الرابط', $why());

        $this->hook($this->obj($link, ['success' => false, 'data' => ['message' => 'Declined']]));
        $this->assertSame('Declined', $why());

        // والدفعُ الصحيح يمحو السبب — والمدفوعُ لا يُكتب عليه توقيعٌ خاطئٌ بعده
        $this->hook($this->obj($link));
        $this->assertNull($link->fresh()->error);
        $this->hook($this->obj($link), 'hmac-other');
        $this->assertNull($link->fresh()->error);
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
    }

    public function test_a_transaction_id_pays_one_link_only(): void
    {
        $first = $this->link($this->invoice());
        $second = $this->link($this->invoice());
        $second->update(['provider_order_id' => '777']);

        $obj = $this->obj($first, ['id' => 555]);
        $this->hook($obj);
        $this->hook($this->obj($second, ['id' => 555, 'order' => ['id' => 777]]));

        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame(CustomerPaymentLink::PENDING, $second->fresh()->status);
    }

    public function test_a_second_transaction_on_a_paid_link_is_flagged_not_silently_recorded(): void
    {
        $link = $this->link($this->invoice());

        $this->hook($this->obj($link, ['id' => 1001]));
        $this->hook($this->obj($link, ['id' => 1002]));
        $this->hook($this->obj($link, ['id' => 1002]));

        $this->assertSame(1, CustomerPayment::count(), 'دفعةٌ ثانيةٌ كُتبت تحصيلًا في صمت');
        $link->refresh();
        $this->assertSame('1001', $link->provider_transaction_id);
        $this->assertSame(['1002'], $link->meta['extra_transactions']);
        $this->assertTrue(PaymentLinks::row($link)['review']);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'customer_payment_link', 'subject_id' => $link->id, 'action' => 'warning']);
    }

    public function test_money_received_is_all_recorded_and_only_the_outstanding_is_allocated(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);

        // قبل أن يدفع: دفعةٌ يدويّةٌ بثلاثين — الباقي ٧٥
        CustomerPayments::record($this->shop->id, $this->ministry, 30, ['method' => 'نقدي'], [$invoice->id => 30]);
        $this->assertEquals(75.0, $invoice->fresh()->outstanding());

        $this->hook($this->obj($link));

        $payment = CustomerPayment::where('method', CustomerPayments::PAYMOB)->firstOrFail();
        $this->assertEquals(105.0, (float) $payment->amount, 'سُجّل أقلُّ ممّا قبضته Paymob');
        $this->assertEquals(75.0, $payment->allocatedTotal());
        $this->assertEquals(30.0, $payment->unallocated(), 'ضاع الزائد');
        $this->assertEquals(0.0, $invoice->fresh()->outstanding());
        $this->assertEquals(-30.0, $this->balance('receivable'), 'الزائدُ رصيدٌ للعميل');
    }

    public function test_a_partial_link_leaves_the_rest_on_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->fakePaymob(['amount_cents' => 50000]);
        $link = $this->link($invoice, 50);

        $this->hook($this->obj($link));

        $this->assertEquals(55.0, $invoice->fresh()->outstanding());
        $this->assertEquals(50.0, $this->balance('paymob_clearing'));
    }

    public function test_a_free_link_needs_a_customer_sells_nothing_and_stays_customer_credit(): void
    {
        $older = $this->invoice();
        $this->post(route('admin.finance.paymentLinks.store'), ['amount' => 40])->assertSessionHasErrors('customer_id');
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id])->assertSessionHasErrors('amount');

        $before = $this->sales();
        $this->fakePaymob(['amount_cents' => 40000]);
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'amount' => 40, 'description' => 'عربون'])
            ->assertSessionHasNoErrors();
        $link = CustomerPaymentLink::firstOrFail();
        $this->assertNull($link->customer_invoice_id);
        $this->assertSame('عربون', $link->description);

        $this->hook($this->obj($link));

        $payment = CustomerPayment::firstOrFail();
        $this->assertEquals(40.0, $payment->unallocated(), 'المبلغُ الحرّ سدّد أقدمَ الفواتير بلا طلب');
        $this->assertEquals(105.0, $older->fresh()->outstanding());
        $this->assertSame($before, $this->sales());
        $this->assertEquals(40.0, $this->balance('paymob_clearing'));
    }

    /* ═══════════ ٥ · التسوية ═══════════ */

    public function test_a_settlement_moves_clearing_to_the_bank_net_of_fees_in_one_balanced_entry(): void
    {
        $bank = BankAccount::create(['business_id' => $this->shop->id, 'label' => 'بنك مسقط', 'bank_name' => 'بنك مسقط', 'active' => true, 'is_primary' => true]);
        $link = $this->link($this->invoice());
        $this->hook($this->obj($link));
        $before = $this->sales();
        $opex = Ledger::operatingExpenses($this->shop->id);
        $bankBefore = Bank::total($this->shop->id);

        $this->post(route('admin.finance.paymobSettlements.store'), [
            'gross' => 105, 'fee' => 2, 'net' => 103, 'settled_on' => '2026-10-13', 'bank_account_id' => $bank->id, 'reference' => 'PMB-77',
        ])->assertSessionHasNoErrors();

        $settlement = PaymobSettlement::firstOrFail();
        $entry = JournalEntry::findOrFail($settlement->journal_entry_id);
        $this->assertEquals((float) $entry->lines()->sum('debit'), (float) $entry->lines()->sum('credit'));
        $this->assertSame('2026-10-13', substr((string) $entry->entry_date, 0, 10));

        $this->assertEquals(0.0, $this->balance('paymob_clearing'));
        $this->assertEquals(2.0, $this->balance('payment_gateway_fees'));
        $this->assertEquals(round($bankBefore + 103, 3), round(Bank::total($this->shop->id), 3), 'البنكُ لم يزد بالصافي');
        // الربحُ يتأثّر بالرسوم وحدها — مصروفًا — والإيرادُ لا يتحرّك
        $this->assertEquals(round($opex + 2, 3), Ledger::operatingExpenses($this->shop->id));
        $this->assertSame($before, $this->sales());
    }

    public function test_a_settlement_must_add_up_and_cannot_exceed_what_waits(): void
    {
        $this->hook($this->obj($this->link($this->invoice())));
        $foreign = BankAccount::create(['business_id' => $this->other->id, 'label' => 'بنك الجار', 'active' => true]);

        $send = fn (array $d) => $this->post(route('admin.finance.paymobSettlements.store'), $d + ['settled_on' => '2026-10-13']);

        $send(['gross' => 105, 'fee' => 2, 'net' => 100])->assertSessionHasErrors(['gross' => 'الإجمالي يجب أن يساوي الصافي + الرسوم.']);
        $send(['gross' => 200, 'fee' => 0, 'net' => 200])->assertSessionHasErrors('gross');
        $send(['gross' => 105, 'fee' => 2, 'net' => 103, 'bank_account_id' => $foreign->id])->assertSessionHasErrors('gross');

        $this->assertSame(0, PaymobSettlement::count());
        $this->assertEquals(105.0, $this->balance('paymob_clearing'));
    }

    /* ═══════════ ٦ · الإلغاء والانتهاء ═══════════ */

    public function test_a_pending_link_is_cancelled_at_paymob_first_and_a_paid_one_never(): void
    {
        $link = $this->link($this->invoice());

        $this->post(route('admin.finance.paymentLinks.cancel', $link->id))->assertRedirect();
        $this->assertSame(CustomerPaymentLink::CANCELLED, $link->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment-links/cancel') && $r['payment_link_id'] === '580010');

        // Paymob لم تؤكّد: لا يُكتب ملغى — ولكلّ رابطٍ طلبُه عند Paymob
        $kept = $this->link($this->invoice());
        $kept->update(['provider_order_id' => '616057559']);
        Http::swap(new Factory);
        Http::fake(['*/api/auth/tokens' => Http::response(['token' => 't']), '*/payment-links/cancel' => Http::response(['success' => false])]);
        $this->post(route('admin.finance.paymentLinks.cancel', $kept->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame(CustomerPaymentLink::PENDING, $kept->fresh()->status);

        // والمدفوعُ لا يُلغى بهذا الباب
        $this->hook($this->obj($kept));
        $this->post(route('admin.finance.paymentLinks.cancel', $kept->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame(CustomerPaymentLink::PAID, $kept->fresh()->status);
        $this->assertNull(CustomerPayment::firstOrFail()->cancelled_at);
    }

    public function test_an_expired_link_is_marked_and_money_that_arrived_anyway_is_not_lost(): void
    {
        $link = $this->link($this->invoice());

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->artisan('payment-links:expire')->assertSuccessful();
        $this->assertSame(CustomerPaymentLink::EXPIRED, $link->fresh()->status);
        $this->assertSame(0, CustomerPayment::count());

        // دفعةٌ قُبضت قبل الانتهاء ووصل إشعارُها بعده: المالُ عند Paymob — يُسجَّل
        $this->hook($this->obj($link));
        $this->assertSame(1, CustomerPayment::count());
    }

    /* ═══════════ ٧ · العزل ═══════════ */

    public function test_one_shop_cannot_see_cancel_or_borrow_anything_of_another(): void
    {
        $invoice = $this->invoice();
        $link = $this->link($invoice);

        $this->actingAs($this->stranger);

        $this->assertSame([], $this->get(route('admin.finance.paymentLinks'))->assertOk()->viewData('page')['props']['links']);
        $this->post(route('admin.finance.paymentLinks.cancel', $link->id))->assertNotFound();
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'amount' => 10])->assertNotFound();
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id])->assertNotFound();
        $this->getJson(route('admin.finance.paymentLinks.invoices', ['customer_id' => $this->ministry->id]))->assertNotFound();

        $this->assertSame(CustomerPaymentLink::PENDING, $link->fresh()->status);
        $this->assertSame(1, CustomerPaymentLink::count());

        // والإشعارُ لا يقرأ متجرًا من الطلب: موقَّعٌ بسرّ الجار على رابطنا ⇒ لا شيء
        $this->hook($this->obj($link), 'hmac-other');
        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_the_platform_admin_has_no_paymob_or_payment_link_door(): void
    {
        $platform = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'super-admin.') || str_starts_with((string) $r->getName(), 'platform.'));

        foreach ($platform as $route) {
            $this->assertDoesNotMatchRegularExpression('/paymob|paymentLink|payment-link|settlement/i', $route->getName().' '.$route->uri());
        }

        foreach (glob(resource_path('js/Pages/Platform/**/*.tsx')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/PaymentLink|payment-links|paymob|QuickLink/i', file_get_contents($file), $file);
        }
        foreach (glob(resource_path('js/Pages/SuperAdmin/*.tsx')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/PaymentLink|payment-links|paymob|QuickLink/i', file_get_contents($file), $file);
        }
    }

    /* ═══════════ ٨ · الحسابان يصلان كلَّ شجرة ═══════════ */

    public function test_existing_charts_get_the_two_new_accounts_once(): void
    {
        Account::where('business_id', $this->shop->id)->whereIn('system_key', ['paymob_clearing', 'payment_gateway_fees'])->delete();

        Ledger::ensureSystemAccounts($this->shop->id);
        Ledger::ensureSystemAccounts($this->shop->id);

        $this->assertSame(1, Account::where('business_id', $this->shop->id)->where('system_key', 'paymob_clearing')->where('type', 'أصل')->count());
        $this->assertSame(1, Account::where('business_id', $this->shop->id)->where('system_key', 'payment_gateway_fees')->where('type', 'مصروف')->count());
    }
}
