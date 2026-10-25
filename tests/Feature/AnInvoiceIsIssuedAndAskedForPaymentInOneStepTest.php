<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «إصدار الفاتورة وإنشاء رابط دفع» — ورقةٌ واحدةٌ ورابطٌ واحد، وما بعدهما.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإصدارُ العاديّ كما كان، ولا يسأل Paymob شيئًا.
 *   - المجتمعان: الفاتورةُ تُصدَر **آجلة** بذمّتها وقيدها مرّةً، ثمّ يُطلب باقيها
 *     كلُّه برابطٍ واحدٍ عليها — ويُرى في صفحتها، وفي سجلّ العميل، وفي «المالية»:
 *     الصفُّ نفسُه. ولا تحصيلَ يُكتب حتّى تؤكّد Paymob.
 *   - «نقدي» لا يطلب رابطًا، وPaymob ناقصةُ الإعداد لا تُكتب لها ورقة.
 *   - Paymob تردّ أو تنقطع بعد كتابة الورقة: الورقةُ تبقى، ولا ثانية، ولا تحصيلَ
 *     مزعوم — والمحاولةُ تُعاد على الفاتورة نفسِها. وانقطاعٌ لا تُعرف نتيجتُه لا
 *     يُعاد وحده.
 *   - ضغطتان، أو إعادةُ إرسال، أو طلبان في اللحظة نفسِها: ورقةٌ واحدة.
 *   - رابطٌ قائمٌ بالمبلغ نفسِه يُعاد لا يُكرَّر؛ وبمبلغٍ آخر يُردّ؛ والملغى
 *     والمنتهي لا يمنعان؛ والمسدَّدةُ لا رابطَ لها؛ والجزئيّةُ تطلب باقيها.
 *   - ومفتاحُ التجهيز بعد السداد لا شأنَ له بالروابط.
 */
class AnInvoiceIsIssuedAndAskedForPaymentInOneStepTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $ministry;

    private int $tx = 7100;

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

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@onestep.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

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

    private function gateway(Business $shop, string $hmac, ?string $api): PaymentGateway
    {
        return PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => $hmac,
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => $api, 'mode' => PaymentGateway::LIVE,
        ]);
    }

    /** Paymob مزيَّفة — كلُّ رابطٍ بمعرّفه وطلبه، ولا اتّصالَ حقيقيّ */
    private function fakePaymob(?callable $links = null): void
    {
        app()->forgetInstance(Factory::class);
        Http::swap(new Factory);

        $n = 0;
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok-secret-123']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => $links ?? function () use (&$n) {
                $n++;

                return Http::response([
                    'id' => 580000 + $n, 'shorten_url' => 'https://paymob.link/link'.$n,
                    'expires_at' => '2026-10-26T23:59:00', 'state' => 'created', 'order' => 616000000 + $n,
                ]);
            },
        ]);
    }

    /** كم رابطًا طُلب من Paymob */
    private function linkRequests(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/api/ecommerce/payment-links'))->count();
    }

    /** نموذجُ الإنشاء كما ترسله الشاشة — بندان × ٥٠ + ٥٪ = ١٠٥ */
    private function form(array $over = []): array
    {
        return array_merge([
            'customer_id' => $this->ministry->id,
            'issued_at' => '2026-10-19',
            'payment_method' => 'آجل',
            'issue' => true,
            'items' => [['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50]],
        ], $over);
    }

    private function issueAndLink(array $over = [])
    {
        return $this->post(route('admin.customerInvoices.store'), $this->form($over + ['payment_link' => true, 'form_token' => 'form-'.uniqid()]));
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2026-10-19'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ]));
    }

    private function hook(CustomerPaymentLink $link, string $secret = 'hmac-shop'): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-19T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, $secret), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /* ============================== الإصدار ============================== */

    public function test_a_plain_issue_is_as_it_was_and_asks_paymob_nothing(): void
    {
        $this->post(route('admin.customerInvoices.store'), $this->form())
            ->assertRedirect()->assertSessionHas('toast.msg', 'أُنشئت الفاتورة CINV-000001');

        $invoice = CustomerInvoice::sole();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame(0, CustomerPaymentLink::count());
        Http::assertNothingSent();
    }

    public function test_issue_and_link_issues_one_credit_invoice_and_asks_for_its_whole_outstanding(): void
    {
        $res = $this->issueAndLink(['form_token' => 'form-one']);

        $invoice = CustomerInvoice::sole();
        $res->assertRedirect(route('admin.customerInvoices.show', $invoice->id))
            ->assertSessionHas('toast.msg', 'تم إصدار الفاتورة وإنشاء رابط الدفع')
            ->assertSessionHas('paymentLinkIssued', true);

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame('form-one', $invoice->form_token);
        $this->assertSame(105.0, $invoice->outstanding(), 'آجلةٌ بباقيها كلِّه');
        $this->assertSame(0, CustomerPayment::count(), 'الرابطُ طلبٌ لا تحصيل');
        $this->assertSame(1, JournalEntry::count(), 'قيدُ الإصدار وحده');
        $this->assertSame(105.0, round(Ledger::balance($this->shop->id, 'receivable'), 3));

        $link = CustomerPaymentLink::sole();
        $this->assertSame(CustomerPaymentLink::PENDING, $link->status);
        $this->assertSame($invoice->id, (int) $link->customer_invoice_id);
        $this->assertSame(105.0, (float) $link->amount);
        $this->assertSame('https://paymob.link/link1', $link->short_url);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->firstWhere('name', 'amount_cents')['contents'] === '105000');

        $this->assertSame($link->id, session('paymentLink')['id']);
        $this->assertSame('https://paymob.link/link1', session('paymentLink')['url']);

        // ويبقى بعد التحديث — في الفاتورة وسجلّ العميل و«المالية»: الصفُّ نفسُه
        $invoiceLinks = $this->get(route('admin.customerInvoices.show', $invoice->id))->viewData('page')['props']['paymentLinks']['links'];
        $customerLinks = $this->get(route('admin.customers.show', $this->ministry->id))->viewData('page')['props']['paymentLinks']['links'];
        $financeLinks = $this->get(route('admin.finance.paymentLinks'))->viewData('page')['props']['links'];

        foreach ([$invoiceLinks, $customerLinks, $financeLinks] as $links) {
            $this->assertSame([$link->id], array_column($links, 'id'));
            $this->assertSame('https://paymob.link/link1', $links[0]['url']);
            $this->assertSame($invoice->number, $links[0]['invoice']['number']);
        }

        $this->assertSame(0, Order::count(), 'ولا بيعَ ثانٍ');
    }

    public function test_cash_asks_for_no_link_and_writes_nothing(): void
    {
        $this->issueAndLink(['payment_method' => 'نقدي'])
            ->assertSessionHasErrors('payment_method');

        $this->assertStringContainsString('اختر «آجل» لإنشاء الرابط', session('errors')->first('payment_method'));
        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(0, CustomerPayment::count());
        Http::assertNothingSent();

        // والإصدارُ العاديّ بالنقد كما كان
        $this->post(route('admin.customerInvoices.store'), $this->form(['payment_method' => 'نقدي']))->assertSessionHasNoErrors();
        $this->assertSame(0.0, CustomerInvoice::sole()->outstanding());
    }

    public function test_a_link_without_issuing_is_refused(): void
    {
        $this->issueAndLink(['issue' => false])->assertSessionHasErrors('payment_link');

        $this->assertSame(0, CustomerInvoice::count());
        Http::assertNothingSent();
    }

    public function test_paymob_not_ready_or_missing_its_api_key_writes_no_invoice(): void
    {
        // والمفتاحُ مشفَّرٌ في عموده — يُكتب بالنموذج لا بنصٍّ خام
        $gateway = PaymentGateway::where('business_id', $this->shop->id)->sole();

        foreach ([['api_key' => null], ['mode' => null], ['active' => false]] as $broken) {
            $gateway->forceFill($broken)->save();

            $this->issueAndLink()->assertSessionHasErrors('payment_link');
            $this->assertStringContainsString('أكمل إعداد Paymob أولًا', session('errors')->first('payment_link'));
            $this->assertSame(0, CustomerInvoice::count());
            // والشاشةُ لا تعرض زرًّا يُردّ عنه
            $this->assertFalse($this->get(route('admin.customerInvoices.create'))->viewData('page')['props']['payment_links_ready']);

            $gateway->forceFill(['api_key' => 'api-shop', 'mode' => PaymentGateway::LIVE, 'active' => true])->save();
        }

        $this->assertTrue($this->get(route('admin.customerInvoices.create'))->viewData('page')['props']['payment_links_ready']);
        Http::assertNothingSent();
    }

    /* ============================== Paymob تردّ ============================== */

    public static function rejections(): array
    {
        return [
            'مفتاحٌ مرفوض' => [401, 'رفضت Paymob مفتاح API'],
            'بيئةٌ لا تطابق (Test/Live)' => [404, 'لا يطابق بيئة الحساب'],
            'طلبٌ مرفوض' => [422, 'رفضت Paymob الطلب'],
        ];
    }

    #[DataProvider('rejections')]
    public function test_a_paymob_rejection_keeps_the_issued_invoice_and_retries_on_it(int $status, string $why): void
    {
        $this->fakePaymob(fn () => Http::response(['detail' => 'no'], $status));

        $res = $this->issueAndLink();

        $invoice = CustomerInvoice::sole();
        $res->assertRedirect(route('admin.customerInvoices.show', $invoice->id))->assertSessionHas('toast.type', 'warning');
        $this->assertStringContainsString('أُصدرت الفاتورة '.$invoice->number.' — وتعذّر إنشاء رابط الدفع', session('toast.msg'));
        $this->assertStringContainsString($why, session('toast.msg'));
        $this->assertStringNotContainsString('api-shop', session('toast.msg'));
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame(0, CustomerPayment::count(), 'لا تحصيلَ مزعوم');
        $this->assertSame(CustomerPaymentLink::FAILED, CustomerPaymentLink::sole()->status);

        // ويبقى السببُ بعد التحديث — وإعادةُ المحاولة على الورقة نفسِها
        $failure = $this->get(route('admin.customerInvoices.show', $invoice->id))->viewData('page')['props']['paymentLinkFailure'];
        $this->assertStringContainsString($why, $failure['error']);
        $this->assertFalse($failure['uncertain'], 'رفضٌ صريح: لا رابطَ هناك');

        $this->fakePaymob();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $this->assertSame(1, CustomerInvoice::count(), 'لا فاتورةٌ ثانية');
        $this->assertSame(1, CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)->count());
        $this->assertNull($this->get(route('admin.customerInvoices.show', $invoice->id))->viewData('page')['props']['paymentLinkFailure']);
    }

    /**
     * وPaymob معطّلةٌ عند طلب الرمز — قبل أن يُطلب رابطٌ أصلًا.
     *
     * خطأُ خادمها على الرمز لا يُنشئ شيئًا هناك، فلا شكَّ يحبس الإعادة.
     */
    public function test_paymob_down_before_any_link_was_asked_is_a_plain_failure_that_retries(): void
    {
        app()->forgetInstance(Factory::class);
        Http::swap(new Factory);
        Http::fake(['oman.paymob.com/api/auth/tokens' => Http::response(['detail' => 'down'], 503)]);

        $this->issueAndLink()->assertSessionHas('toast.type', 'warning');

        $invoice = CustomerInvoice::sole();
        $this->assertSame(0, $this->linkRequests(), 'لم يُطلب رابط');
        $this->assertNull(CustomerPaymentLink::sole()->meta);
        $this->assertFalse($this->get(route('admin.customerInvoices.show', $invoice->id))->viewData('page')['props']['paymentLinkFailure']['uncertain']);

        $this->fakePaymob();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');
        $this->assertSame(1, CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)->count());
    }

    /**
     * وانقطاعٌ بعد أن بلغ الطلبُ Paymob — ربّما أُنشئ الرابطُ هناك ولم يصلنا.
     *
     * فلا يُعاد وحده: إعادتُه في صمتٍ قد ترسل للعميل رابطين يدفعهما. ويُعاد
     * بقرارٍ صريح ممّن طلبه، ويُكتب ذلك. وبعد مهلته يُعاد كأيّ فشل.
     */
    public function test_a_timeout_after_paymob_may_have_made_the_link_is_not_retried_by_itself(): void
    {
        $this->fakePaymob(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->issueAndLink()->assertSessionHas('toast.type', 'warning');

        $invoice = CustomerInvoice::sole();
        $failed = CustomerPaymentLink::sole();
        $this->assertSame(CustomerPaymentLink::FAILED, $failed->status);
        $this->assertTrue($failed->meta['uncertain']);
        $this->assertNotNull($failed->expires_at);
        $this->assertTrue($this->get(route('admin.customerInvoices.show', $invoice->id))->viewData('page')['props']['paymentLinkFailure']['uncertain']);

        $this->fakePaymob();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasErrors('payment_link');
        $this->assertStringContainsString('لم تُعرف نتيجةُ المحاولة السابقة', session('errors')->first('payment_link'));
        $this->assertSame(0, $this->linkRequests(), 'لا يُعاد الطلبُ وحده');

        // وبقرارٍ صريح: رابطٌ جديد، والسابقُ يُعلَّم بمن حلّ محلَّه
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id), ['replace_uncertain' => true])->assertSessionHasNoErrors();
        $link = CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)->sole();
        $this->assertSame($link->id, $failed->fresh()->meta['replaced_by']);
        $this->assertSame(1, ActivityLog::where('subject_type', 'customer_payment_link')->where('description', 'like', '%بعد محاولةٍ لم تُعرف نتيجتُها%')->count());
        $this->assertSame(1, CustomerInvoice::count());
    }

    public function test_a_doubtful_attempt_stops_blocking_once_its_window_has_passed(): void
    {
        $invoice = $this->invoice();
        $this->fakePaymob(fn () => throw new ConnectionException('timeout'));
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id));

        $this->fakePaymob();
        Carbon::setTestNow(CustomerPaymentLink::sole()->expires_at->copy()->addMinute());

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)->count());
    }

    /* ============================== مرّةً واحدة ============================== */

    public function test_a_double_click_or_a_resubmitted_form_issues_one_invoice_and_one_link(): void
    {
        $first = $this->issueAndLink(['form_token' => 'form-twice']);
        $second = $this->issueAndLink(['form_token' => 'form-twice']);

        $invoice = CustomerInvoice::sole();
        $first->assertRedirect(route('admin.customerInvoices.show', $invoice->id));
        $second->assertRedirect(route('admin.customerInvoices.show', $invoice->id))
            ->assertSessionHas('toast.msg', 'هذا النموذج أُرسل من قبل — الفاتورة '.$invoice->number.' كُتبت مرّةً واحدة');

        $this->assertSame(1, CustomerPaymentLink::count());
        $this->assertSame(1, $this->linkRequests());
        $this->assertSame(1, JournalEntry::count());
    }

    /**
     * وطلبان بالرمز نفسِه في اللحظة نفسِها: يسبق أحدُهما الآخرَ بين السؤال
     * والكتابة، فتردّ القاعدةُ الثاني (`customer_invoices_form_token_unique`) —
     * ويُعاد إلى ورقة الأوّل لا إلى خطأ.
     */
    public function test_two_racing_submissions_of_one_form_write_one_invoice(): void
    {
        $raced = false;
        DB::listen(function ($q) use (&$raced) {
            if (! $raced && str_contains($q->sql, 'from "customer_invoices"') && str_contains($q->sql, '"form_token"')) {
                $raced = true;
                $twin = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2026-10-19'], [
                    ['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50],
                ]));
                $twin->forceFill(['form_token' => 'form-race'])->save();
            }
        });

        $res = $this->issueAndLink(['form_token' => 'form-race']);

        $this->assertTrue($raced);
        $twin = CustomerInvoice::sole();
        $this->assertSame('form-race', $twin->form_token);
        $res->assertRedirect(route('admin.customerInvoices.show', $twin->id))->assertSessionHas('toast.type', 'info');
        $this->assertSame(0, $this->linkRequests(), 'الطلبُ الثاني لا يطلب رابطًا');
    }

    public function test_the_same_token_in_another_shop_is_another_form(): void
    {
        $stranger = User::create(['business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'n@onestep.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'allow_credit_sales' => true]);

        $this->issueAndLink(['form_token' => 'form-shared']);
        $this->actingAs($stranger)->post(route('admin.customerInvoices.store'), $this->form(['customer_id' => $theirs->id, 'form_token' => 'form-shared']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->other->id)->count());
    }

    /* ============================== رابطٌ واحدٌ للمال نفسِه ============================== */

    public function test_an_open_link_for_the_same_amount_is_shown_again_not_recreated(): void
    {
        $invoice = $this->invoice();

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHas('toast.type', 'success');
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))
            ->assertSessionHas('toast.msg', 'على الفاتورة رابطٌ قائم بالمبلغ نفسه — لم يُنشأ رابطٌ ثانٍ');

        $link = CustomerPaymentLink::sole();
        $this->assertSame($link->id, session('paymentLink')['id']);
        $this->assertSame(1, $this->linkRequests());

        // و«المالية» تمرّ بالباب نفسِه
        $this->post(route('admin.finance.paymentLinks.store'), ['customer_id' => $this->ministry->id, 'customer_invoice_id' => $invoice->id])
            ->assertSessionHas('toast.type', 'info');
        $this->assertSame(1, CustomerPaymentLink::count());
    }

    public function test_an_open_link_for_another_amount_blocks_a_second_request(): void
    {
        $invoice = $this->invoice();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id));

        // دُفع بعضُها نقدًا بعد الرابط — والرابطُ ما زال يطلب ١٠٥
        CustomerPayments::record($this->shop->id, $this->ministry, 50, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$invoice->id => 50]);

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasErrors('amount');
        $this->assertStringContainsString('على الفاتورة رابطُ دفعٍ قائم بمبلغ 105.000', session('errors')->first('amount'));
        $this->assertSame(1, CustomerPaymentLink::count());
        $this->assertSame(1, $this->linkRequests());
    }

    public function test_a_cancelled_or_expired_link_leaves_room_for_a_new_one_while_money_is_due(): void
    {
        $invoice = $this->invoice();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id));
        $this->post(route('admin.finance.paymentLinks.cancel', CustomerPaymentLink::sole()->id))->assertSessionHas('toast.type', 'success');

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame(['cancelled', 'pending'], CustomerPaymentLink::orderBy('id')->pluck('status')->all());

        Carbon::setTestNow('2026-10-27 09:00:00');
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame(['cancelled', 'expired', 'pending'], CustomerPaymentLink::orderBy('id')->pluck('status')->all());
    }

    public function test_a_paid_invoice_gets_no_link_and_a_partly_paid_one_asks_only_the_rest(): void
    {
        $paid = $this->invoice();
        CustomerPayments::record($this->shop->id, $this->ministry, 105, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$paid->id => 105]);
        $this->post(route('admin.customerInvoices.paymentLink', $paid->id))->assertSessionHasErrors('amount');
        $this->assertStringContainsString('مسدَّدة', session('errors')->first('amount'));

        $partly = $this->invoice();
        CustomerPayments::record($this->shop->id, $this->ministry, 40, ['method' => 'نقدي', 'occurred_at' => '2026-10-19'], [$partly->id => 40]);
        $this->post(route('admin.customerInvoices.paymentLink', $partly->id))->assertSessionHasNoErrors();

        $this->assertSame(65.0, (float) CustomerPaymentLink::sole()->amount);
        $this->assertSame(1, $this->linkRequests());
    }

    public function test_two_link_requests_at_once_on_one_invoice_ask_paymob_once(): void
    {
        $invoice = $this->invoice();
        $held = Cache::lock('payment-link:invoice:'.$invoice->id, 90);
        $this->assertTrue($held->get());

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasErrors('amount');
        $this->assertStringContainsString('يُنشأ رابطُ دفعٍ لهذه الفاتورة الآن', session('errors')->first('amount'));
        $this->assertSame(0, $this->linkRequests());

        $held->release();
        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->linkRequests());
    }

    /* ============================== المال ============================== */

    public function test_only_a_verified_settlement_collects_and_allocates_to_the_invoice(): void
    {
        $this->issueAndLink();
        $invoice = CustomerInvoice::sole();
        $link = CustomerPaymentLink::sole();
        $entries = JournalEntry::count();
        $revenue = round(Ledger::netRevenue($this->shop->id), 3);
        $vat = round(Ledger::outputTax($this->shop->id), 3);

        // إشعارٌ بتوقيعٍ خاطئ لا يُحصّل شيئًا
        $this->hook($link, 'hmac-wrong');
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(105.0, $invoice->fresh()->outstanding());

        $this->hook($link);

        $payment = CustomerPayment::sole();
        $this->assertSame(CustomerPayments::PAYMOB, $payment->method);
        $this->assertSame(0.0, $invoice->fresh()->outstanding(), 'مخصَّصٌ لفاتورته');
        $this->assertSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame($entries + 1, JournalEntry::count(), 'قيدُ التحصيل وحده');
        $this->assertSame($revenue, round(Ledger::netRevenue($this->shop->id), 3), 'لا إيرادَ ثانٍ');
        $this->assertSame($vat, round(Ledger::outputTax($this->shop->id), 3), 'ولا ضريبةَ ثانية');
        $this->assertSame(1, CustomerInvoice::count());
        $this->assertSame(0, Order::count());
    }

    /* ============================== الحدود ============================== */

    public function test_a_neighbours_invoice_or_customer_is_not_reachable(): void
    {
        $theirs = Customer::create(['business_id' => $this->other->id, 'name' => 'زبون الجار', 'allow_credit_sales' => true]);
        $theirInvoice = CustomerInvoices::issue(CustomerInvoices::create($this->other->id, $theirs, ['issued_at' => '2026-10-19'], [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10],
        ]));

        $this->post(route('admin.customerInvoices.paymentLink', $theirInvoice->id))->assertNotFound();
        $this->post(route('admin.customers.paymentLink', $theirs->id), ['amount' => 10])->assertNotFound();
        $this->issueAndLink(['customer_id' => $theirs->id])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, CustomerPaymentLink::count());
        Http::assertNothingSent();
    }

    /** ومفتاحُ التجهيز بعد السداد يفتح التجهيزَ وحده — الروابطُ قائمةٌ بدونه ومعه */
    public function test_payment_links_do_not_depend_on_the_preparation_flag(): void
    {
        $this->assertFalse((bool) $this->shop->fresh()->customer_invoice_preparation_enabled);
        $this->issueAndLink()->assertSessionHas('toast.msg', 'تم إصدار الفاتورة وإنشاء رابط الدفع');

        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->issueAndLink()->assertSessionHas('toast.msg', 'تم إصدار الفاتورة وإنشاء رابط الدفع');

        $this->assertSame(2, CustomerPaymentLink::where('status', CustomerPaymentLink::PENDING)->count());
        $this->assertSame(0, PrintJob::count(), 'ولا تجهيزَ بلا طلبه');
    }

    public function test_a_free_amount_request_from_the_customer_page_needs_no_finance(): void
    {
        $this->post(route('admin.customers.paymentLink', $this->ministry->id), ['amount' => 20, 'description' => 'دفعة مقدّمة'])
            ->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $link = CustomerPaymentLink::sole();
        $this->assertNull($link->customer_invoice_id);
        $this->assertSame(20.0, (float) $link->amount);
        $this->assertSame(0, CustomerPayment::count());
        $this->post(route('admin.customers.paymentLink', $this->ministry->id), [])->assertSessionHasErrors('amount');
    }

    public function test_the_retry_door_answers_only_what_was_asked(): void
    {
        $invoice = $this->invoice();

        $this->post(route('admin.customerInvoices.paymentLink', $invoice->id));
        $flash = session('paymentLink');

        // صفُّ الرابط وحده: لا بنكَ ولا تسويةَ ولا مفتاح
        $this->assertSame(['id', 'reference', 'customer', 'invoice', 'description', 'amount', 'currency', 'status', 'is_live', 'url', 'created_at', 'expires_at', 'paid_at', 'payment', 'error', 'review'], array_keys($flash));
        $this->assertStringNotContainsString('api-shop', json_encode($flash));
        $this->assertStringNotContainsString('tok-secret', json_encode($flash));
        $this->assertSame(PaymentLinks::row(CustomerPaymentLink::sole()->load(['customer', 'invoice'])), $flash);
    }
}
