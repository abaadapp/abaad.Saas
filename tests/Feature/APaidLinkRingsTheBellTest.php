<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Notifications;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رابطُ دفعٍ دُفع يُقال في الجرس — من دفع، وكم، وعن أيّ فاتورة.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * إشعارُ Paymob يسجّل التحصيلَ ويُقفل الفاتورة وحده، ولا يقول ذلك لأحد. فصاحبُ
 * المحلّ لا يعرف أنّ المال وصل حتّى يفتح الفاتورة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - المدفوعُ بالإشعار الموقَّع يظهر بالاسم والمبلغ ورقم الفاتورة، ويقود إليها.
 *   - وبلا فاتورة: بلا رقم، ويقود إلى صفحة الروابط.
 *   - المعلَّقُ والملغى صامتان، ويغيب المدفوعُ بعد مدّته — وهي دون مدّة الإخفاء.
 *   - لمن يفتح «المالية» وحده، والرابطُ يُفتح لقارئه. وما لمتجرٍ آخر لا يصل.
 */
class APaidLinkRingsTheBellTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Customer $ministry;

    private int $tx = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-11 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->owner = $this->user($this->shop, 'owner@bell.om');
        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        foreach ([$this->shop, $this->other] as $shop) {
            PaymentGateway::create([
                'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
                'public_key' => 'pk_'.$shop->id, 'secret_key' => 'sk_'.$shop->id, 'hmac_secret' => 'hmac-'.$shop->id,
                'card_integration_id' => '111', 'api_key' => 'api-'.$shop->id, 'mode' => PaymentGateway::TEST,
            ]);
        }

        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580010, 'shorten_url' => 'https://paymob.link/abc123', 'state' => 'created', 'order' => 616057558,
            ]),
        ]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(Business $shop, string $email, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => $email, 'email' => $email, 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    private function invoice(): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2026-10-10'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ]);

        return CustomerInvoices::issue($invoice);
    }

    private function link(?CustomerInvoice $invoice = null, ?float $amount = null): CustomerPaymentLink
    {
        return PaymentLinks::create($this->shop->id, $this->ministry, $invoice, $amount, null, $this->owner->id);
    }

    /** إشعارُ Paymob الموقَّع — الطريقُ الوحيد الذي يقول «دُفع» */
    private function pay(CustomerPaymentLink $link): CustomerPaymentLink
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => false,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-'.$this->shop->id), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();

        return $link->refresh();
    }

    /** @return array<string, mixed>|null */
    private function row(CustomerPaymentLink $link, ?User $reader = null): ?array
    {
        $this->actingAs($reader ?? $this->owner);

        return collect(Demo::notifications())->firstWhere('key', 'link-paid-'.$link->id);
    }

    public function test_a_paid_link_says_who_paid_how_much_and_for_which_invoice(): void
    {
        $invoice = $this->invoice();
        $link = $this->pay($this->link($invoice));
        $this->assertSame(CustomerPaymentLink::PAID, $link->status);

        $row = $this->row($link);

        $this->assertNotNull($row, 'رابطٌ دُفع ولم يرنّ الجرس');
        $this->assertSame('وصلت دفعة '.Demo::money((float) $link->amount).' من وزارة الثقافة عبر رابط الدفع — فاتورة '.$invoice->number, $row['text']);
        $this->assertSame(route('admin.customerInvoices.show', $invoice->id), $row['url']);
        $this->assertSame('2026-10-11 10:00', $row['time']);
        $this->get($row['url'])->assertOk();

        // خبرٌ يُقرأ ويُخفى — لا «تم» عليه
        $this->assertSame(['kind' => Notifications::INFO, 'resolve' => Notifications::MANUAL], Notifications::kindOf($row['key']));

        app()->setLocale('en');
        $this->assertStringStartsWith('Payment of ', collect(Demo::notifications())->firstWhere('key', 'link-paid-'.$link->id)['text']);
    }

    public function test_a_link_without_an_invoice_leads_to_the_links_page(): void
    {
        $link = $this->pay($this->link(null, 25.5));

        $row = $this->row($link);

        $this->assertSame('وصلت دفعة '.Demo::money(25.5).' من وزارة الثقافة عبر رابط الدفع', $row['text']);
        $this->assertSame(route('admin.finance.paymentLinks'), $row['url']);
    }

    public function test_an_unpaid_or_cancelled_link_is_silent(): void
    {
        $waiting = $this->link(null, 10);
        $cancelled = PaymentLinks::cancel($this->link(null, 12), $this->owner->id);

        $this->assertNull($this->row($waiting));
        $this->assertNull($this->row($cancelled));
    }

    /** ويغيب بعد مدّته — وهي دون مدّة الإخفاء، فما أُخفي لا يعود */
    public function test_it_leaves_the_bell_after_its_days(): void
    {
        $link = $this->pay($this->link(null, 10));
        $this->assertLessThan(Demo::DISMISSAL_DAYS, Demo::PAID_LINK_DAYS);

        Carbon::setTestNow(now()->addDays(Demo::PAID_LINK_DAYS)->subMinute());
        $this->assertNotNull($this->row($link));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertNull($this->row($link));
    }

    /** لمن يفتح «المالية» — ويقود كلَّ قارئٍ إلى بابٍ يُفتح له */
    public function test_only_who_opens_finance_hears_it_and_only_from_his_shop(): void
    {
        $invoice = $this->invoice();
        $link = $this->pay($this->link($invoice));

        $clerk = $this->user($this->shop, 'clerk@bell.om', ['orders']);
        $this->assertNull($this->row($link, $clerk), 'من لا يفتح «المالية» رنّ جرسُه بدفعة');

        $accountant = $this->user($this->shop, 'accounts@bell.om', ['finance']);
        $row = $this->row($link, $accountant);
        $this->assertSame(route('admin.finance.paymentLinks'), $row['url'], 'من لا يفتح الفواتير قِيد إليها');
        $this->get($row['url'])->assertOk();

        $this->assertNull($this->row($link, $this->user($this->other, 'neighbour@bell.om')));
    }
}
