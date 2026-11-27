<?php

namespace Tests\Feature;

use App\Exceptions\CollectionRefused;
use App\Models\BankAccount;
use App\Models\Boutique;
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
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Bank;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Receivables;
use App\Support\Store\Paymob;
use App\Support\Store\WebCheckout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * فاتورةٌ بخمسةٍ وعشرين تُحصَّل مرّةً واحدة، ولا يُحصَّل عليها أكثرُ ممّا عليها.
 *
 * كان التحصيلُ على فاتورةٍ مدفوعةٍ كلُّها يُقبل: يُقصّ الزائدُ في صمتٍ فيصير رصيدًا
 * للعميل — فاتورةٌ بخمسةٍ وعشرين يدخل الصندوقَ عليها خمسون، والذممُ سالبةٌ بخمسةٍ
 * وعشرين. وإرسالُ النافذة مرّتين — ضغطةٌ مكرّرة أو إعادةٌ بعد ردٍّ ضاع — يكتب
 * تحصيلين على فاتورةٍ عليها باقٍ.
 *
 * فصار التحصيلُ على فاتورةٍ بعينها يُحكم تحت قفل صفّها: لا يزيد على باقيها، ويُردّ
 * بسببه ولا يُكتب شيء. ومفتاحُ المحاولة يردّ التحصيلَ الأوّل ولا يكتب غيرَه. والتحصيلُ
 * على حساب العميل (دفعةٌ مقدّمة) ومالُ Paymob الذي وصل فعلًا يبقيان كما كانا: يُسجَّلان،
 * والزائدُ رصيدٌ للعميل — وما زاد من Paymob يُعلَّم للمراجعة.
 *
 * والفاتورةُ فيها صنفُ المتجر وقطعتان من بوتيكين، وبعد كلّ خطوةٍ يُقرأ بالبيسة: الصندوقُ
 * والبنكُ والذممُ والإيرادُ و2160 ومستحقّاتُ البوتيك والحركاتُ والنقاطُ والمخزونُ والفاتورة.
 */
class AnInvoiceIsCollectedOnceAndNeverPastWhatItOwesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $company;

    private BankAccount $bank;

    private Product $vase;

    private Product $ring;

    private Product $scarf;

    private int $tx = 900000;

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

        $this->shop = Business::create(['name' => 'متجر التحصيل', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'r15collect', 'tier' => 'gold', 'storefront_theme' => Business::THEME_RIBBON, 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        foreach (['vat_enabled' => '0', 'pay_credit' => '1'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'collect@r15.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->company = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true, 'language' => 'ar']);
        $this->bank = BankAccount::create(['business_id' => $this->shop->id, 'bank_name' => 'بنك مسقط', 'label' => 'الرئيسي', 'active' => true, 'is_primary' => true]);

        $lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true]);
        $reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'ريم', 'commission_rate' => 10, 'active' => true]);
        $this->vase = Product::create(['business_id' => $this->shop->id, 'name' => 'مزهرية', 'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $this->ring = Product::create(['business_id' => $this->shop->id, 'name' => 'خاتم', 'price' => 8, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $lama->id]);
        $this->scarf = Product::create(['business_id' => $this->shop->id, 'name' => 'وشاح', 'price' => 7, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $reem->id]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    /** فاتورةٌ صادرةٌ بخمسةٍ وعشرين: مزهريةُ المتجر ١٠ + خاتمُ لمى ٨ + وشاحُ ريم ٧ */
    private function invoice(): CustomerInvoice
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->company, ['issued_at' => '2027-02-10'], [
            ['product_id' => $this->vase->id, 'description' => 'مزهرية', 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => $this->ring->id, 'description' => 'خاتم', 'quantity' => 1, 'unit_price' => 8],
            ['product_id' => $this->scarf->id, 'description' => 'وشاح', 'quantity' => 1, 'unit_price' => 7],
        ], $this->owner->id);

        $invoice = CustomerInvoices::issue($draft, $this->owner->id);
        $this->assertSame(25.0, round((float) $invoice->total, 3));

        return $invoice;
    }

    /** التحصيلُ من نافذة الفاتورة كما ترسله — بمفتاح محاولته */
    private function collect(CustomerInvoice $invoice, float $amount, ?string $key = null, string $method = 'نقدي'): TestResponse
    {
        return $this->post(route('admin.customerPayments.store'), [
            'request_key' => $key ?? (string) Str::uuid(),
            'customer_id' => $invoice->customer_id, 'customer_invoice_id' => $invoice->id,
            'amount' => $amount, 'method' => $method,
        ] + ($method === 'تحويل' ? ['bank_account_id' => $this->bank->id] : []));
    }

    private function balance(string $key): float
    {
        return round(Ledger::balance($this->shop->id, $key), 3) + 0.0;
    }

    /** كلُّ ما يقوله النظامُ عن المال — بالبيسة */
    private function books(?CustomerInvoice $invoice = null): array
    {
        $bid = $this->shop->id;
        $inv = $invoice?->fresh();
        $lines = DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return [
            'cash' => $this->balance('cash'),
            // والبنكُ ورقةُ حسابه إن كانت له، وإلّا «البنك» العامّة (`CustomerPayments::post`)
            'bank' => ($leaf = Bank::leaf($bid, $this->bank->id))
                ? round((float) DB::table('journal_lines')->where('account_id', $leaf->id)->sum(DB::raw('debit - credit')), 3) + 0.0
                : $this->balance('bank'),
            'cheques' => $this->balance(Cheques::account($bid)),
            'paymob' => $this->balance('paymob_clearing'),
            'receivable' => $this->balance('receivable'),
            'revenue' => round(Ledger::netRevenue($bid), 3) + 0.0,
            'boutique_payable' => $this->balance('boutique_payable'),
            'boutique_dues' => DB::table('boutique_component_dues')->count(),
            'paid' => $inv ? $inv->paidTotal() : null,
            'left' => $inv ? $inv->outstanding() : null,
            'credit' => round(Receivables::credit($bid), 3) + 0.0,
            'payments' => CustomerPayment::where('business_id', $bid)->count(),
            'live' => round((float) CustomerPayment::where('business_id', $bid)->whereNull('cancelled_at')->sum('amount'), 3) + 0.0,
            'entries' => JournalEntry::where('business_id', $bid)->count(),
            'transactions' => Transaction::where('business_id', $bid)->count(),
            'points' => PointTransaction::count(),
            'stock' => (int) Product::where('business_id', $bid)->sum('quantity'),
            'balanced' => round((float) $lines->d - (float) $lines->c, 3) + 0.0,
        ];
    }

    private function refusedWith(TestResponse $response, string $words): void
    {
        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString($words, (string) session('errors')->first('amount'));
    }

    /* ═══════════════════════ ١ · كاملًا ثمّ كاملًا ═══════════════════════ */

    public function test_a_fully_paid_invoice_refuses_another_full_collection_and_writes_nothing(): void
    {
        $invoice = $this->invoice();
        $start = $this->books($invoice);

        $this->collect($invoice, 25)->assertSessionHasNoErrors();
        $paid = $this->books($invoice);
        $this->assertSame(25.0, $paid['cash']);
        $this->assertSame(0.0, $paid['receivable']);
        $this->assertSame(25.0, $paid['paid']);
        $this->assertSame(0.0, $paid['left']);

        $this->refusedWith($this->collect($invoice, 25), 'مدفوعة بالكامل');

        $after = $this->books($invoice);
        $this->assertSame($paid, $after, 'التحصيلُ الثاني على فاتورةٍ مسدَّدةٍ كتب شيئًا');
        $this->assertSame(25.0, $after['cash'], 'دخل الصندوقَ خمسون على فاتورةٍ بخمسةٍ وعشرين');
        $this->assertSame(0.0, $after['credit'], 'صار الزائدُ رصيدًا للعميل في صمت');
        $this->assertSame(0.0, $after['balanced']);

        // ولا يمسّ التحصيلُ إيرادًا ولا مستحقَّ بوتيكٍ ولا مخزونًا ولا نقاطًا ولا حركة
        foreach (['revenue', 'boutique_payable', 'boutique_dues', 'stock', 'points', 'transactions'] as $k) {
            $this->assertSame($start[$k], $after[$k], "التحصيلُ حرّك {$k}");
        }
    }

    /* ═══════════════════════ ٢ · عشرةٌ ثمّ خمسةَ عشر ═══════════════════════ */

    public function test_ten_then_fifteen_pays_it_in_two_collections(): void
    {
        $invoice = $this->invoice();

        $this->collect($invoice, 10)->assertSessionHasNoErrors();
        $this->assertSame(15.0, $invoice->fresh()->outstanding());
        $this->assertSame('مدفوعة جزئيًا', $invoice->fresh()->paymentState());

        $this->collect($invoice, 15)->assertSessionHasNoErrors();
        $books = $this->books($invoice);
        $this->assertSame(25.0, $books['cash']);
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(25.0, $books['paid']);
        $this->assertSame(2, $books['payments']);
        $this->assertSame(0.0, $books['credit']);
        $this->assertSame(0.0, $books['receivable']);
    }

    /* ═══════════════════════ ٣ · ستّةَ عشر بعد عشرة ═══════════════════════ */

    public function test_sixteen_after_ten_is_refused_and_the_remaining_fifteen_is_said(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 10)->assertSessionHasNoErrors();
        $before = $this->books($invoice);

        $this->refusedWith($this->collect($invoice, 16), '15.000');

        $this->assertSame($before, $this->books($invoice), 'تحصيلٌ أكبرُ من الباقي كتب شيئًا');
        $this->assertSame(15.0, $invoice->fresh()->outstanding());
    }

    /* ═══════════════════════ ٤ · نافذتان في اللحظة نفسها ═══════════════════════ */

    /**
     * والحكمُ من باقٍ يُقرأ تحت القفل، لا من باقٍ قرأته النافذة.
     *
     * نافذتان فُتحتا والباقي خمسةٌ وعشرون، وكلٌّ ترسل خمسةً وعشرين بمفتاحها. على
     * PostgreSQL تنتظر الثانيةُ قفلَ الأولى ثمّ تقرأ صفرًا (اختبارُ التزامن الحقيقيّ في
     * عُدّة الجولة). وهنا الترتيبُ نفسُه بلا خيوط: الثانيةُ تصل والأولى قد كُتبت.
     */
    public function test_two_windows_collecting_it_whole_at_once_write_one_collection(): void
    {
        $invoice = $this->invoice();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();

        $this->collect($invoice, 25, $first)->assertSessionHasNoErrors();
        $this->refusedWith($this->collect($invoice, 25, $second), 'مدفوعة بالكامل');

        $books = $this->books($invoice);
        $this->assertSame(1, $books['payments']);
        $this->assertSame(25.0, $books['cash']);
        $this->assertSame(0.0, $books['credit']);

        // وكذلك من الخدمة مباشرةً — لا من النافذة وحدها
        try {
            CustomerPayments::record($this->shop->id, $this->company, 25, ['method' => 'نقدي'], [$invoice->id => 25], $this->owner->id, exact: true);
            $this->fail('الخدمةُ قبلت تحصيلًا على فاتورةٍ مسدَّدة');
        } catch (CollectionRefused $e) {
            $this->assertStringContainsString('مدفوعة بالكامل', $e->getMessage());
        }
        $this->assertSame($books, $this->books($invoice));
    }

    /* ═══════════════════════ ٥ · نجح وضاع ردُّه ═══════════════════════ */

    public function test_a_collection_whose_reply_was_lost_is_not_written_again_when_resent(): void
    {
        $invoice = $this->invoice();
        $key = (string) Str::uuid();

        // عشرةٌ نجحت وضاع ردُّها — فأُرسلت ثانيةً وثالثةً بالمفتاح نفسِه
        $this->collect($invoice, 10, $key)->assertSessionHasNoErrors();
        $once = $this->books($invoice);

        foreach ([1, 2] as $_) {
            $this->collect($invoice, 10, $key)->assertSessionHasNoErrors()
                ->assertSessionHas('toast', fn ($t) => str_contains($t['msg'], 'سُجّل من قبل'));
        }

        $this->assertSame($once, $this->books($invoice), 'الإعادةُ بالمفتاح نفسِه كتبت تحصيلًا ثانيًا');
        $this->assertSame(1, $once['payments']);
        $this->assertSame(15.0, $once['left']);
        $this->assertSame(1, CustomerPayment::where('request_key', $key)->count());

        // ومفتاحُ متجرٍ لا يردّ تحصيلَ متجرٍ آخر — الفهرسُ فريدٌ في المتجر
        $this->assertNull(CustomerPayment::where('business_id', '!=', $this->shop->id)->where('request_key', $key)->first());
    }

    public function test_the_same_key_twice_is_refused_by_the_database_itself(): void
    {
        $invoice = $this->invoice();
        $key = (string) Str::uuid();
        $this->collect($invoice, 10, $key)->assertSessionHasNoErrors();
        $row = (array) DB::table('customer_payments')->where('request_key', $key)->first();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_payments')->insert(['number' => 'CR-DUP'] + array_diff_key($row, ['id' => 1, 'number' => 1]));
    }

    /* ═══════════════════════ ٦ · إشعارُ Paymob مكرّرًا، وزائدُه ═══════════════════════ */

    private function paymob(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_r15', 'secret_key' => 'sk_r15', 'hmac_secret' => 'hmac-r15',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api-r15', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'amount_cents' => 25000, 'shorten_url' => 'https://paymob.link/r15',
                'client_url' => 'https://oman.paymob.com/x', 'created_at' => '2027-02-10T10:00:00',
                'expires_at' => '2027-02-17T23:59:00', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);
    }

    private function hook(CustomerPaymentLink $link, int $id): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2027-02-10T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => $id,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-r15'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    public function test_a_repeated_paymob_notification_collects_once(): void
    {
        $this->paymob();
        $invoice = $this->invoice();
        $link = PaymentLinks::create($this->shop->id, $this->company, $invoice, null, null, $this->owner->id);

        $this->hook($link, 4242);
        $once = $this->books($invoice);
        $this->hook($link, 4242);
        $this->hook($link, 4242);

        $this->assertSame($once, $this->books($invoice), 'الإشعارُ المكرّر كتب شيئًا');
        $this->assertSame(1, $once['payments']);
        $this->assertSame(25.0, $once['paymob']);
        $this->assertSame(0.0, $once['left']);
        $this->assertSame(0.0, $once['credit']);
        $this->assertFalse(PaymentLinks::row($link->fresh())['review']);
    }

    /**
     * ومالُ Paymob الزائد على الباقي يُسجَّل ولا يُردّ — ويُعلَّم للمراجعة.
     *
     * رابطٌ فُتح على خمسةٍ وعشرين، ثمّ حُصّلت عشرةٌ نقدًا، ثمّ دفع العميلُ الرابط.
     * المالُ وصل فعلًا: يُسجَّل خمسةً وعشرين مرّةً واحدة، يُخصَّص منها خمسةَ عشر، وتبقى
     * عشرةٌ رصيدًا للعميل — ويقول الرابطُ «يحتاج مراجعة». ولا تُقبل بعدها يدويّةٌ عليها.
     */
    public function test_paymob_money_above_what_is_left_is_kept_once_and_flagged_for_review(): void
    {
        $this->paymob();
        $invoice = $this->invoice();
        $link = PaymentLinks::create($this->shop->id, $this->company, $invoice, null, null, $this->owner->id);
        $this->collect($invoice, 10)->assertSessionHasNoErrors();

        $this->hook($link, 5151);
        $this->hook($link, 5151);

        $books = $this->books($invoice);
        $this->assertSame(10.0, $books['cash']);
        $this->assertSame(25.0, $books['paymob'], 'مالُ Paymob لم يُسجَّل كلُّه');
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(25.0, $books['paid']);
        $this->assertSame(10.0, $books['credit'], 'الزائدُ لم يبقَ رصيدًا للعميل');
        $this->assertSame(-10.0, $books['receivable']);
        $this->assertSame(2, $books['payments']);
        $this->assertSame(0.0, $books['balanced']);

        $link->refresh();
        $this->assertEquals(10.0, $link->meta['excess']);
        $this->assertTrue(PaymentLinks::row($link)['review'], 'الزائدُ لم يُعلَّم للمراجعة');
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'customer_payment_link', 'subject_id' => $link->id, 'action' => 'warning']);

        $this->refusedWith($this->collect($invoice, 10), 'مدفوعة بالكامل');
        $this->assertSame($books, $this->books($invoice));
    }

    /* ═══════════════════════ ٧ · إلغاءٌ ثمّ تحصيلٌ صحيح ═══════════════════════ */

    public function test_a_cancelled_collection_frees_the_invoice_for_a_correct_one_and_is_cancelled_once(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 25)->assertSessionHasNoErrors();
        $payment = CustomerPayment::firstOrFail();
        $stale = CustomerPayment::findOrFail($payment->id);

        $this->post(route('admin.customerPayments.cancel', $payment->id), ['reason' => 'سُجّل خطأً'])->assertSessionHasNoErrors();
        $this->assertSame(25.0, $invoice->fresh()->outstanding());
        $cancelled = $this->books($invoice);
        $this->assertSame(0.0, $cancelled['cash']);
        $this->assertSame(25.0, $cancelled['receivable']);

        // ونسخةٌ قديمةٌ قرأته قبل إلغائه تُلغيه ثانيةً: لا شيء
        CustomerPayments::cancel($stale, 'مرّة ثانية', $this->owner->id);
        $this->assertSame($cancelled, $this->books($invoice), 'الإلغاءُ الثاني كتب شيئًا');
        $this->assertSame('سُجّل خطأً', $payment->fresh()->cancellation_reason);

        $this->collect($invoice, 25)->assertSessionHasNoErrors();
        $books = $this->books($invoice);
        $this->assertSame(25.0, $books['cash']);
        $this->assertSame(0.0, $books['receivable']);
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(2, $books['payments']);
        $this->assertSame(25.0, $books['live']);
        $this->assertSame(0.0, $books['credit']);
        $this->assertSame(0.0, $books['balanced']);
    }

    /* ═══════════════════════ ٨ · الأبوابُ الأخرى ═══════════════════════ */

    public function test_a_bank_transfer_is_refused_past_what_is_left_and_resent_once(): void
    {
        $invoice = $this->invoice();
        $key = (string) Str::uuid();
        $this->collect($invoice, 25, $key, 'تحويل')->assertSessionHasNoErrors();
        $this->collect($invoice, 25, $key, 'تحويل')->assertSessionHasNoErrors();
        $this->refusedWith($this->collect($invoice, 25, null, 'تحويل'), 'مدفوعة بالكامل');

        $books = $this->books($invoice);
        $this->assertSame(25.0, $books['bank']);
        $this->assertSame(0.0, $books['cash']);
        $this->assertSame(1, $books['payments']);
        $this->assertSame(0.0, $books['credit']);
    }

    public function test_a_cheque_counts_until_it_bounces_and_then_the_invoice_is_collected_once_more(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 25, null, 'شيك')->assertSessionHasNoErrors();
        $this->assertSame(25.0, $this->books($invoice)['cheques']);

        // والشيكُ تحت التحصيل يسدّها — فلا يُحصَّل عليها غيرُه
        $this->refusedWith($this->collect($invoice, 25), 'مدفوعة بالكامل');

        Cheques::bounce(CustomerPayment::firstOrFail(), 'رصيدٌ غير كافٍ', $this->owner->id);
        $this->assertSame(25.0, $invoice->fresh()->outstanding());

        $this->collect($invoice, 25)->assertSessionHasNoErrors();
        $books = $this->books($invoice);
        $this->assertSame(25.0, $books['cash']);
        $this->assertSame(0.0, $books['cheques']);
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(0.0, $books['receivable']);
        $this->assertSame(0.0, $books['credit']);
    }

    /** ودفعةٌ على حساب العميل بلا فاتورةٍ مسمّاة — الدفعةُ المقدّمة — تبقى كما كانت */
    public function test_an_advance_on_the_customers_account_still_stays_their_credit(): void
    {
        $invoice = $this->invoice();
        $this->collect($invoice, 25)->assertSessionHasNoErrors();

        $this->post(route('admin.customerPayments.store'), [
            'request_key' => (string) Str::uuid(), 'customer_id' => $this->company->id, 'amount' => 40, 'method' => 'نقدي',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn ($t) => str_contains($t['msg'], 'رصيدًا للعميل'));

        $books = $this->books($invoice);
        $this->assertSame(65.0, $books['cash']);
        $this->assertSame(40.0, $books['credit']);
        $this->assertSame(-40.0, $books['receivable']);
        $this->assertSame(0.0, $books['left']);
    }

    /** بيعُ الصندوق الآجل: الدفعةُ عند البيع مرّةً مهما أُعيد الإرسال، والباقي لا يُتجاوز */
    public function test_a_till_credit_sale_pays_down_once_and_its_invoice_is_not_overcollected(): void
    {
        $sale = ['items' => [['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 1, 'price' => 10], ['id' => $this->ring->id, 'name' => 'خاتم', 'qty' => 1, 'price' => 8], ['id' => $this->scarf->id, 'name' => 'وشاح', 'qty' => 1, 'price' => 7]],
            'payment_method' => 'نقدي', 'client_uuid' => 'r15-credit', 'customer_id' => $this->company->id, 'credit' => true, 'paid_now' => 10];
        $this->postJson('/pos/checkout', $sale)->assertOk();
        $this->postJson('/pos/checkout', $sale)->assertOk();

        $order = Order::where('business_id', $this->shop->id)->sole();
        $invoice = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))->sole();
        $this->assertSame(1, CustomerPayment::count(), 'إعادةُ إرسال البيعة كتبت دفعةً ثانية');
        $this->assertSame(15.0, $invoice->fresh()->outstanding());
        $stock = $this->books($invoice)['stock'];

        $this->refusedWith($this->collect($invoice, 16), '15.000');
        $this->collect($invoice, 15)->assertSessionHasNoErrors();
        $this->refusedWith($this->collect($invoice, 15), 'مدفوعة بالكامل');

        $books = $this->books($invoice);
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(2, $books['payments']);
        $this->assertSame(0.0, $books['credit']);
        $this->assertSame($stock, $books['stock'], 'التحصيلُ حرّك المخزون');
    }

    /** طلبُ الموقع بالدفع عند الاستلام: فاتورتُه من الذمم، وتحصيلُها مرّةً واحدة */
    public function test_a_cash_on_delivery_web_order_is_invoiced_and_collected_once(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
        session(['current_branch' => null]);
        $order = WebCheckout::place($this->shop->fresh(), [
            'items' => [['id' => $this->vase->id, 'variant_id' => null, 'qty' => 1], ['id' => $this->ring->id, 'variant_id' => null, 'qty' => 1], ['id' => $this->scarf->id, 'variant_id' => null, 'qty' => 1]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001', 'date' => '2027-02-11',
        ], 'ar');
        $customer = Customer::findOrFail($order->fresh()->customer_id);
        $invoice = CustomerInvoices::consolidate($customer, [$order->id], [], $this->owner->id);
        $this->assertSame(25.0, $invoice->fresh()->outstanding());
        $before = $this->books($invoice);

        $key = (string) Str::uuid();
        $this->collect($invoice, 25, $key)->assertSessionHasNoErrors();
        $this->collect($invoice, 25, $key)->assertSessionHasNoErrors();
        $this->refusedWith($this->collect($invoice, 25), 'مدفوعة بالكامل');

        $books = $this->books($invoice);
        $this->assertSame(1, $books['payments']);
        $this->assertSame(round($before['cash'] + 25, 3), $books['cash']);
        $this->assertSame(0.0, $books['left']);
        $this->assertSame(0.0, $books['credit']);
        $this->assertSame($before['revenue'], $books['revenue'], 'التحصيلُ كتب إيرادًا ثانيًا');
        $this->assertSame($before['stock'], $books['stock'], 'التحصيلُ حرّك المخزون');
        $this->assertSame($before['transactions'], $books['transactions']);
    }

    /** ولا يُحصَّل على فاتورةٍ لم تعد صادرة — يُردّ ولا يصير رصيدًا */
    public function test_a_cancelled_invoice_takes_no_collection(): void
    {
        $invoice = $this->invoice();
        CustomerInvoices::cancel($invoice->fresh(), 'أُصدرت خطأً', $this->owner->id);
        $before = $this->books();

        $this->refusedWith($this->collect($invoice->fresh(), 25), 'ليست صادرة');
        $this->assertSame($before, $this->books());
    }

    /** وتحصيلٌ على فاتورةٍ بعينها لا يحمل مالًا أكثرَ ممّا خُصّص — لا يبقى منه رصيدٌ في صمت */
    public function test_an_exact_collection_must_be_wholly_allocated(): void
    {
        $invoice = $this->invoice();
        $before = $this->books($invoice);

        try {
            CustomerPayments::record($this->shop->id, $this->company, 25, ['method' => 'نقدي'], [$invoice->id => 20], $this->owner->id, exact: true);
            $this->fail('قُبل تحصيلٌ بخمسةٍ وعشرين خُصّص منه عشرون');
        } catch (CollectionRefused) {
        }

        $this->assertSame($before, $this->books($invoice));
    }
}
