<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * فاتورةٌ دُفعت برابط دفع تصل مَن دفعها على واتساب — ملفًّا مرفقًا.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * Paymob لا تُعيد العميلَ إلى صفحتنا بعد الدفع من رابط، فيبقى على صفحة نجاحها
 * ولا ورقةَ في يده. فتخرج فاتورتُه على واتساب لحظةَ يثبت الدفعُ بإشعارها الموقَّع.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - الدفعُ الموقَّع يُخرج رسالةً واحدة: القالبُ، والفاتورةُ PDF في رأسه،
 *     والمحلُّ ورقمُ الفاتورة والمبلغُ المدفوع في نصّه — ولو تكرّر الإشعار.
 *   - لا صفَّ قالبٍ قبل أن تعتمده ميتا: لا يُهيَّأ مع الوصلة، ويُربط في
 *     المزامنة يومَ يُعتمد — فلا تحمرّ «قوالب رسائلك» عند أحدٍ قبلها.
 *   - الرابطُ بلا فاتورة صامت، ومن أطفأه صامت.
 *   - رفعٌ تعذّر لا يُخرج النصَّ وحده، وتُردّ حصّتُه.
 */
class APaidInvoiceReachesItsPayerOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $ministry;

    private WhatsAppConnection $abaad;

    private int $tx = 9000;

    /** ما تقوله ميتا عن قالب الفاتورة المدفوعة، وبمَ تردّ على الرفع — يُقرآن عند كلّ نداء */
    private string $metaSays = 'APPROVED';

    private int $uploadStatus = 200;

    private int $opened = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط', 'whatsapp_enabled' => true]);
        Ledger::seedChart($this->shop->id);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@wa.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->ministry = Customer::create(['business_id' => $this->shop->id, 'name' => 'وزارة الثقافة', 'phone' => '91234567', 'allow_credit_sales' => true]);

        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::TEST,
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        $this->abaad = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'waba_id' => 'WABA-1', 'display_phone_number' => '+968 7114 1624',
            'access_token' => 'tok-0123456789abcdef', 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->fake();
        $this->actingAs($this->owner);
    }

    /** Paymob وميتا — وما لم يُذكر هنا لا يخرج */
    private function fake(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            // ولكلّ رابطٍ طلبُه عند Paymob — به يُعرف صاحبُ الإشعار
            'oman.paymob.com/api/ecommerce/payment-links' => fn () => Http::response(['id' => 580010 + ++$this->opened, 'shorten_url' => 'https://paymob.link/abc', 'order' => 616057558 + $this->opened]),
            '*message_templates*' => fn () => Http::response(['data' => [
                ['name' => 'abaad_order_ready', 'status' => 'APPROVED', 'language' => 'ar'],
                ['name' => 'abaad_invoice_paid', 'status' => $this->metaSays, 'language' => 'ar'],
            ]]),
            '*/PN/media' => fn () => $this->uploadStatus === 200
                ? Http::response(['id' => 'MEDIA-1'])
                : Http::response(['error' => ['code' => 100, 'message' => 'bad file']], $this->uploadStatus),
            '*/PN/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]]),
        ]);
    }

    private function adopt(): void
    {
        WhatsAppTemplates::sync($this->abaad, WhatsAppMode::OWNER_PLATFORM);
    }

    private function invoice(): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->shop->id, $this->ministry, ['issued_at' => '2026-10-10'], [
            ['description' => 'توريد زهور', 'quantity' => 2, 'unit_price' => 50],
        ]);

        return CustomerInvoices::issue($invoice);
    }

    private function link(?CustomerInvoice $invoice, ?float $amount = null): CustomerPaymentLink
    {
        return PaymentLinks::create($this->shop->id, $this->ministry, $invoice, $amount, null, $this->owner->id);
    }

    private function pay(CustomerPaymentLink $link, ?int $tx = null): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-10-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => $tx ?? ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => false,
        ];

        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function paidMessages()
    {
        return WhatsAppMessage::where('event_type', WhatsAppEvent::INVOICE_PAID)->get();
    }

    /** @return list<HttpRequest> */
    private function sentTo(string $path): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $path))->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_a_paid_invoice_link_sends_the_invoice_pdf_to_its_payer(): void
    {
        $this->adopt();
        $invoice = $this->invoice();
        $link = $this->link($invoice, 40);

        $this->pay($link);

        $message = $this->paidMessages()->sole();
        $this->assertSame(WhatsAppStatus::SENT, $message->status);
        $this->assertSame($invoice->id, (int) $message->customer_invoice_id);
        $this->assertSame('96891234567', $message->recipient_phone);
        $this->assertSame('abaad_invoice_paid', $message->template_name);

        $this->assertCount(1, $this->sentTo('/PN/media'), 'الفاتورةُ لم تُرفع ملفًّا');

        $sent = $this->sentTo('/PN/messages');
        $this->assertCount(1, $sent);
        $template = $sent[0]->data()['template'];
        $this->assertSame('abaad_invoice_paid', $template['name']);
        $this->assertSame(
            ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => 'MEDIA-1', 'filename' => $invoice->number.'.pdf']]]],
            $template['components'][0],
        );
        // والمبلغُ ما دُفع بالرابط (٤٠) لا إجماليُّ الفاتورة
        $this->assertSame(
            ['ورود مسقط', $invoice->number, Money::format(40, Money::of($this->shop->id))],
            array_column($template['components'][1]['parameters'], 'text'),
        );
    }

    public function test_a_notification_paymob_repeats_sends_once(): void
    {
        $this->adopt();
        $link = $this->link($this->invoice());

        $this->pay($link, 77);
        $this->pay($link->refresh(), 77);

        $this->assertCount(1, $this->paidMessages());
        $this->assertCount(1, $this->sentTo('/PN/messages'));
    }

    /** وفاتورةٌ دُفعت على رابطين يُقال لصاحبها عن كلّ دفعة — المفتاحُ الرابطُ لا الفاتورة */
    public function test_each_link_paid_on_one_invoice_is_its_own_message(): void
    {
        $this->adopt();
        $invoice = $this->invoice();

        $this->pay($this->link($invoice, 30));
        $this->pay($this->link($invoice, 20));

        $this->assertCount(2, $this->paidMessages());
        $this->assertCount(2, $this->sentTo('/PN/messages'));
    }

    /**
     * ولا صفَّ قالبٍ قبل أن تعتمده ميتا.
     *
     * لا يُهيَّأ مع الوصلة، ولا تكتبه المزامنةُ وهو قيد المراجعة — فخطوةُ
     * «قوالب رسائلك معتمَدة» لا تتغيّر عند أحد. والدفعُ قبلها يُتخطّى بسببه.
     */
    public function test_nothing_is_linked_or_sent_before_meta_approves(): void
    {
        $this->assertFalse($this->mapped(), 'هُيّئ قالبُ الفاتورة المدفوعة مع الوصلة قبل أن تعتمده ميتا');

        $this->metaSays = 'PENDING';
        $this->adopt();
        $this->assertFalse($this->mapped(), 'رُبط قالبٌ قيد المراجعة');

        $this->pay($this->link($this->invoice()));

        $message = $this->paidMessages()->sole();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $message->error_code);
        $this->assertSame([], $this->sentTo('/PN/messages'));

        $this->metaSays = 'APPROVED';
        $this->adopt();
        $this->assertTrue($this->mapped());
        $this->assertSame(WhatsAppTemplates::APPROVED, WhatsAppTemplateMapping::query()->platform()->where('event_type', WhatsAppEvent::INVOICE_PAID)->value('meta_status'));
    }

    public function test_a_link_without_an_invoice_and_a_shop_that_turned_it_off_send_nothing(): void
    {
        $this->adopt();

        $this->pay($this->link(null, 12));
        $this->assertCount(0, $this->paidMessages(), 'رابطٌ بلا فاتورة أرسل ورقة');

        Setting::create(['business_id' => $this->shop->id, 'key' => 'wa_on_invoice_paid', 'value' => '0']);
        $this->pay($this->link($this->invoice()));
        $this->assertCount(0, $this->paidMessages(), 'أُرسلت والتاجرُ أطفأها');
        $this->assertSame([], $this->sentTo('/PN/messages'));
    }

    /** ورفعٌ تعذّر لا يُخرج «وصلت دفعتُك» بلا فاتورتها — وتُردّ الحصّة */
    public function test_a_failed_upload_sends_no_text_alone(): void
    {
        $this->adopt();
        $this->uploadStatus = 400;

        $this->pay($this->link($this->invoice()));

        $message = $this->paidMessages()->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $message->status);
        $this->assertFalse((bool) $message->quota_consumed, 'حُوسب التاجرُ على رسالةٍ لم تخرج');
        $this->assertSame([], $this->sentTo('/PN/messages'));
    }

    private function mapped(): bool
    {
        return WhatsAppTemplateMapping::query()->platform()->where('event_type', WhatsAppEvent::INVOICE_PAID)->exists();
    }
}
