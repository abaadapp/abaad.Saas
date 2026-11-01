<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOrderSession;
use App\Models\WhatsAppTemplateMapping;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Paymob\Amount;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\Paymob;
use App\Support\Store\StoreBranches;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOrderReplies;
use App\Support\WhatsAppOrders;
use App\Support\WhatsAppOrderTexts;
use App\Support\WhatsAppOwnApp;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * يطلب الزبونُ من واتساب المتجر، ويدفع برابط، فيصل طلبُه لوحةَ التجهيز — مرّةً واحدة.
 *
 * كلُّ رسالةٍ تصل من باب إشعارات ميتا الحقيقيّ (`webhooks.whatsapp.own`) موقَّعةً
 * بسرّ تطبيق التاجر، وكلُّ دفعةٍ من باب Paymob موقَّعةً بمفتاحه. ولا رسالةَ تخرج
 * ولا دفعة: ميتا وPaymob مزيَّفان، وما أُرسل إليهما يُقرأ هنا.
 */
class ACustomerOrdersThroughWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = 'PN-SHOP-1';

    private const WABA = 'WABA-SHOP-1';

    private const APP_SECRET = 'merchant-app-secret-for-tests';

    private const CUSTOMER = '96891234567';

    private Business $shop;

    private WhatsAppConnection $line;

    private Product $rose;

    private Product $mug;

    private Addon $chocolate;

    private Branch $main;

    private int $wamid = 0;

    private int $out = 0;

    private int $links = 0;

    private int $tx = 0;

    private ?\Closure $whileSending = null;

    /** رسالةٌ تردّها ميتا — يُسأل بما خرج إليها، و`true` تُردّ */
    private ?\Closure $refuse = null;

    /** Paymob معطّلٌ عن فتح الروابط */
    private bool $paymobDown = false;

    /** نداءٌ خرج إلى عنوانٍ لا يعرفه الاختبار — يُسقطه (`assertPostConditions`) */
    private array $stray = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // الأربعاء ١٠ فبراير، السابعة صباحًا بتوقيت مسقط
        Carbon::setTestNow('2027-02-10 07:00:00');

        // وخامسُ متجرٍ يُنشأ على PostgreSQL معرّفُه ٥ — فلا تلحقه تخصيصاتُ RIBBON بالمعرّف
        config([
            'whatsapp.app_secret' => 'abaad-app-secret',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.merchant_link' => 'own_app',
            'whatsapp.ribbon_businesses' => [],
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        // ولا نداءَ حقيقيًّا: ميتا وPaymob محاكيان هنا، وما سواهما يُسقط الاختبار
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();

            if (str_contains($url, '/messages')) {
                if ($this->whileSending !== null) {
                    $during = $this->whileSending;
                    $this->whileSending = null;
                    $during();
                }

                if ($this->refuse !== null && ($this->refuse)($r->data())) {
                    return Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable', 'type' => 'OAuthException']], 400);
                }

                return Http::response(['messages' => [['id' => 'wamid.OUT-'.(++$this->out)]]], 200);
            }

            if (str_contains($url, 'oman.paymob.com/api/auth/tokens')) {
                return Http::response(['token' => 'tok']);
            }

            if (str_contains($url, 'oman.paymob.com/api/ecommerce/payment-links')) {
                if ($this->paymobDown) {
                    return Http::response(['detail' => 'Service unavailable'], 503);
                }

                $n = ++$this->links;

                return Http::response(['id' => 580000 + $n, 'shorten_url' => 'https://paymob.link/pay'.$n, 'expires_at' => '2027-02-17T23:59:00', 'state' => 'created', 'order' => 616000000 + $n]);
            }

            $this->stray[] = $url;

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 404);
        });

        [$this->shop, $this->line] = $this->openShop('RIBBON');
        config(['whatsapp.guided_orders_businesses' => [$this->shop->id]]);
        WhatsAppOrders::save($this->shop->id, true);
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame([], $this->stray, 'نداءٌ خرج إلى غير ميتا وPaymob المحاكيَين');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ المتجر ═══════════ */

    /** @return array{0: Business, 1: WhatsAppConnection} */
    private function openShop(string $name, string $phoneId = self::PHONE_ID, string $waba = self::WABA): array
    {
        $plan = Plan::create(['name' => 'الاحترافية '.$name, 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);

        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'plan_id' => $plan->id,
            'site_slug' => strtolower($name).'-shop', 'storefront_theme' => 'ribbon',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        $shop->forceFill(['customer_invoice_preparation_enabled' => true, 'customer_invoice_preparation_enabled_at' => now()->subDay()])->save();

        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);

        MarketingSettings::save($shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_fee' => '2', 'store_free_delivery_over' => '100',
            'store_delivery_areas' => "Qurum\nAl Khuwair",
            'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        PaymentGateway::create([
            'business_id' => $shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-'.$shop->id,
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);

        $flowers = Category::create(['business_id' => $shop->id, 'name' => 'ورد', 'name_en' => 'Flowers', 'sort_order' => 1]);
        $gifts = Category::create(['business_id' => $shop->id, 'name' => 'هدايا', 'name_en' => 'Gifts', 'sort_order' => 2]);

        $rose = Product::create([
            'business_id' => $shop->id, 'category_id' => $flowers->id, 'name' => 'باقة ورد', 'name_en' => 'Rose bouquet',
            'price' => 20, 'cost' => 8, 'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        $mug = Product::create([
            'business_id' => $shop->id, 'category_id' => $gifts->id, 'name' => 'كوب', 'name_en' => 'Mug',
            'price' => 5, 'cost' => 2, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
        $chocolate = Addon::create([
            'business_id' => $shop->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3,
            'active' => true, 'pos_enabled' => true, 'web_enabled' => true, 'scope' => Addon::SCOPE_ALL,
        ]);

        $line = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $shop->id,
            'phone_number_id' => $phoneId, 'display_phone_number' => '+968 7679 0720', 'waba_id' => $waba,
            'access_token' => 'merchant-token-'.$phoneId, 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
            'link_type' => WhatsAppOwnApp::TYPE, 'app_secret' => self::APP_SECRET,
            'webhook_key' => str_pad('k'.$shop->id, 40, 'x'),
            'billing_state' => WhatsAppOwnApp::BILLING_CONFIRMED,
            'billing_evidence' => ['waba_id' => $waba, 'reviewed_at' => now()->toIso8601String()],
        ]);

        if ($name === 'RIBBON') {
            $this->rose = $rose;
            $this->mug = $mug;
            $this->chocolate = $chocolate;
            $this->main = $branch;
        }

        return [$shop, $line];
    }

    /* ═══════════ الزبونُ يكتب ═══════════ */

    private function deliver(array $message, ?WhatsAppConnection $line = null): void
    {
        $line ??= $this->line;
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => $line->waba_id, 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '96876790720', 'phone_number_id' => $line->phone_number_id],
            'contacts' => [['profile' => ['name' => 'مريم'], 'wa_id' => $message['from']]],
            'messages' => [$message],
        ]]]]]];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->call('POST', WhatsAppOwnApp::callbackUrl($line), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ], $body)->assertOk();
    }

    private function say(string $text, string $from = self::CUSTOMER, ?string $wamid = null, ?WhatsAppConnection $line = null): string
    {
        $wamid ??= 'wamid.IN-'.(++$this->wamid);
        $this->deliver(['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]], $line);

        return $wamid;
    }

    /** يضغط زرًّا أو صفًّا عنوانُه يحوي النصّ — من آخر رسالةٍ تفاعليّةٍ وصلته */
    private function press(string $label, string $from = self::CUSTOMER): string
    {
        [$id, $title, $kind] = $this->option($label);

        return $this->reply($id, $title, $kind, $from);
    }

    private function reply(string $id, string $title = 'x', string $kind = 'button_reply', string $from = self::CUSTOMER): string
    {
        $wamid = 'wamid.IN-'.(++$this->wamid);
        $this->deliver(['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'interactive',
            'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]]);

        return $wamid;
    }

    /** @return array{0: string, 1: string, 2: string} المعرّف والعنوان ونوعُ الجواب */
    private function option(string $label): array
    {
        $last = $this->lastInteractive();
        $this->assertNotNull($last, 'لم تصل رسالةٌ تفاعليّة');

        if ($last['type'] === 'button') {
            foreach ($last['action']['buttons'] as $b) {
                if (str_contains($b['reply']['title'], $label)) {
                    return [$b['reply']['id'], $b['reply']['title'], 'button_reply'];
                }
            }
        }

        if ($last['type'] === 'list') {
            foreach ($last['action']['sections'] as $section) {
                foreach ($section['rows'] as $row) {
                    if (str_contains($row['title'], $label)) {
                        return [$row['id'], $row['title'], 'list_reply'];
                    }
                }
            }
        }

        $this->fail('لا خيارَ «'.$label.'» في آخر رسالة: '.json_encode($last, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array<string, mixed>> ما خرج إلى ميتا من رقم المتجر */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))
            ->map(fn (HttpRequest $r) => $r->data() + ['_url' => $r->url()])
            ->values()->all();
    }

    private function lastInteractive(): ?array
    {
        $interactive = array_values(array_filter($this->sent(), fn ($m) => ($m['type'] ?? null) === 'interactive'));

        return $interactive === [] ? null : end($interactive)['interactive'];
    }

    /** @return array<string, mixed> آخرُ ما خرج إلى ميتا */
    private function lastSent(): array
    {
        $sent = $this->sent();

        return $sent === [] ? [] : $sent[array_key_last($sent)];
    }

    private function lastText(): string
    {
        $last = $this->lastSent();

        return (string) ($last['text']['body'] ?? $last['interactive']['body']['text'] ?? '');
    }

    private function allText(): string
    {
        return implode("\n", array_map(fn ($m) => (string) ($m['text']['body'] ?? $m['interactive']['body']['text'] ?? ''), $this->sent()));
    }

    private function order(): ?WhatsAppOrderSession
    {
        return WhatsAppOrderSession::where('business_id', $this->shop->id)->latest('id')->first();
    }

    /** طلبُ توصيلٍ من أوّله إلى الملخّص — باقتان وشوكولاتة، لقرم */
    private function orderToSummary(): void
    {
        $this->say('طلب');
        $this->press('ورد');
        $this->press('باقة ورد');
        $this->press('2');
        $this->press('شوكولاتة');
        $this->press('تم');
        $this->press('إتمام الطلب');
        $this->press('توصيل');
        $this->say('مريم علي');
        $this->press('أنا');
        $this->press('Qurum');
        $this->say('Way 1234, House 5');
        $this->press('الخميس');
        $this->press('9 ص');
    }

    /* ═══ Paymob ═══ */

    private function obj(CustomerPaymentLink $link, array $over = []): array
    {
        return array_replace_recursive([
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2027-02-10T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 900000 + (++$this->tx),
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ], $over);
    }

    private function paymob(array $obj): void
    {
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-'.$this->shop->id), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /* ═════════════ ١ · طلبُ توصيلٍ من المحادثة إلى لوحة التجهيز ═════════════ */

    public function test_a_delivery_order_goes_from_the_chat_to_a_paid_task_on_the_board(): void
    {
        $this->orderToSummary();

        $session = $this->order();
        $this->assertSame(WhatsAppOrderSession::CONFIRMING, $session->state);
        // باقتان بعشرين، والشوكولاتة مرّةً للبند (٣)، والتوصيلُ ريالان
        $this->assertSame('45.000', (string) $session->total);
        $this->assertStringContainsString('2 × باقة ورد', $this->allText());
        $this->assertStringContainsString('Qurum — Way 1234, House 5', $this->allText());
        $this->assertSame(0, CustomerInvoice::where('business_id', $this->shop->id)->count(), 'لا ورقةَ قبل الموافقة');

        $this->press('تأكيد');

        $session->refresh();
        $this->assertSame(WhatsAppOrderSession::AWAITING_PAYMENT, $session->state);

        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->sole();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertEqualsWithDelta(45.0, (float) $invoice->total, 0.0005);
        $this->assertSame(WhatsAppOrders::class === '' ? '' : 'whatsapp-order:'.$session->id, $invoice->form_token);
        $this->assertSame((int) $this->main->id, (int) $invoice->branch_id);

        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame(['باقة ورد', 'شوكولاتة', 'رسوم التوصيل'], $lines->map(fn ($l) => preg_replace('/ — .*$/u', '', (string) $l->description))->all());
        $this->assertSame((int) $this->chocolate->id, (int) $lines[1]->addon_id);
        $this->assertSame((int) $lines[0]->id, (int) $lines[1]->parent_item_id);

        $prep = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertSame('delivery', $prep->fulfillment_type);
        $this->assertSame('Qurum — Way 1234, House 5', $prep->delivery_address);
        $this->assertSame('مريم علي', $prep->recipient_name);
        $this->assertSame('91234567', $prep->recipient_phone);
        $this->assertSame('2027-02-11 09:00', $prep->scheduled_for->format('Y-m-d H:i'));
        $this->assertNull($prep->activated_at, 'لا مهمّةَ في اللوحة قبل الدفع');

        $link = CustomerPaymentLink::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertEqualsWithDelta(45.0, (float) $link->amount, 0.0005);
        $this->assertSame((int) $link->id, (int) $session->payment_link_id);

        // والرابطُ خرج من رقم المتجر بزرٍّ — ويُقرأ في المحادثة بحاله
        $cta = $this->lastInteractive();
        $this->assertSame('cta_url', $cta['type']);
        $this->assertSame('https://paymob.link/pay1', $cta['action']['parameters']['url']);
        $this->assertStringContainsString($invoice->number, $cta['body']['text']);
        $last = $this->lastSent();
        $this->assertSame(self::WABA, $last['messaging_account_id'], 'كلُّ رسالةٍ تُسمّي حسابَ الرسائل الذي رُوجعت فوترتُه');
        $this->assertStringEndsWith('/'.self::PHONE_ID.'/messages', $last['_url']);
        $this->assertTrue(WhatsAppChatMessage::where('business_id', $this->shop->id)->where('direction', 'out')
            ->where('body', 'like', '%https://paymob.link/pay1%')->where('status', WhatsAppStatus::SENT)->whereNotNull('wamid')->exists());

        // ثمّ يدفع — إشعارٌ موثَّقٌ من Paymob
        $obj = $this->obj($link);
        $this->paymob($obj);

        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertNotNull($prep->fresh()->activated_at, 'ظهرت المهمّةُ في لوحة الفرع');
        $this->assertSame(WhatsAppOrderSession::PAID, $session->fresh()->state);
        $this->assertNull($session->fresh()->open_key);
        $this->assertStringContainsString('وصلت دفعتك', $this->lastText());
        $this->assertStringContainsString($invoice->number, $this->lastText());

        // والإشعارُ نفسُه ثانيةً: لا تحصيلَ ولا مهمّةَ ولا رسالةَ ولا ورقةَ طباعةٍ ثانية
        $before = count($this->sent());
        $this->paymob($obj);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(1, PrintJob::where('business_id', $this->shop->id)->count());
        $this->assertSame($before, count($this->sent()));
        // ولا بيعَ يُكتب: الفاتورةُ هي البيع، والتجهيزُ مهمّةٌ لا طلب
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());

        // والترحيبُ صمت عن كلّ ما أخذه الطلب — ردٌّ واحدٌ لكلّ رسالة
        $this->assertSame(0, WhatsAppInboundMessage::where('business_id', $this->shop->id)->where('reply_status', WhatsAppInboundMessage::SENT)->count());
        $this->assertSame(
            WhatsAppInboundMessage::where('business_id', $this->shop->id)->count(),
            WhatsAppInboundMessage::where('business_id', $this->shop->id)->where('reply_reason', WhatsAppOrders::SKIP_REASON)->count(),
        );
    }

    /* ═════════════ ٢ · استلامٌ من المحلّ ═════════════ */

    public function test_a_pickup_order_asks_for_the_branch_the_person_and_the_time_but_no_address(): void
    {
        $second = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        MarketingSettings::save($this->shop->id, 'website', [StoreBranches::CHOICE => '1']);
        $week = array_fill(0, 7, [['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning']]);
        MarketingSettings::save($this->shop->id, 'website', [
            DeliverySchedule::ON => '1',
            DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $week, 'pickup' => array_fill(0, 7, [['id' => 'pk01', 'from' => '16:00', 'to' => '18:00', 'ar' => 'العصر', 'en' => 'Afternoon']]), 'closed' => [], 'lead' => 0]),
        ]);

        $this->say('طلب');
        $this->press('هدايا');
        $this->press('كوب');
        $this->press('3');
        $this->press('تم');
        $this->press('إتمام الطلب');
        $this->press('استلام');
        $this->say('Saeed Al Harthy');
        $this->press('صحار');
        $this->press('شخص آخر');
        $this->say('Huda');
        $this->say('٩٢٣٤٥٦٧٨');
        $this->press('الأربعاء');
        $this->press('العصر');

        $this->assertSame('15.000', (string) $this->order()->total, 'ثلاثة أكواب بخمسة، ولا رسمَ توصيلٍ للاستلام');
        $this->assertStringNotContainsString('العنوان', $this->allText());

        $this->press('تأكيد');

        $prep = CustomerInvoicePreparation::where('business_id', $this->shop->id)->sole();
        $this->assertSame('pickup', $prep->fulfillment_type);
        $this->assertSame((int) $second->id, (int) $prep->branch_id);
        $this->assertNull($prep->delivery_address);
        $this->assertSame('Huda', $prep->recipient_name);
        $this->assertSame('92345678', $prep->recipient_phone);
        $this->assertSame('2027-02-10 16:00', $prep->scheduled_for->format('Y-m-d H:i'));
        $this->assertStringContainsString('العصر', (string) $prep->delivery_notes);
        $this->assertSame(0, CustomerInvoice::sole()->items()->where('description', 'رسوم التوصيل')->count());
    }

    /* ═════════════ ٣ · هديّةٌ بإضافاتها وبطاقتها ═════════════ */

    public function test_a_gift_keeps_its_card_and_asks_before_printing_a_card_that_names_a_hidden_sender(): void
    {
        $this->shop->forceFill(['gift_orders_enabled' => true])->save();

        $this->say('طلب');
        $this->press('ورد');
        $this->press('باقة ورد');
        $this->press('1');
        $this->press('شوكولاتة');
        $this->press('شوكولاتة');
        $this->press('تم');
        $this->press('إتمام الطلب');
        $this->press('توصيل');
        $this->say('مريم علي');
        $this->press('شخص آخر');
        $this->say('نورة');
        $this->say('92345678');
        $this->press('Al Khuwair');
        $this->say('Building 7');
        $this->press('الخميس');
        $this->press('4 م');
        $this->press('هديّة');
        $this->press('عيد ميلاد');
        $this->say('كل عام وأنت بخير — مريم علي');
        $this->press('أخفِ اسمي');

        // البطاقةُ تذكر من أخفى اسمه — يُسأل قبل أن تُطبع
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'card_reveals'), $this->lastText());
        $this->press('اطبعها كما هي');

        $this->assertSame('28.000', (string) $this->order()->total, 'باقةٌ بعشرين، وشوكولاتتان بستّة، والتوصيلُ ريالان');

        $this->press('تأكيد');

        $prep = CustomerInvoicePreparation::where('business_id', $this->shop->id)->sole();
        $this->assertTrue((bool) $prep->is_gift);
        $this->assertTrue((bool) $prep->hide_sender);
        $this->assertSame('كل عام وأنت بخير — مريم علي', $prep->card_message);
        $this->assertNotNull($prep->card_identity_confirmed_at);
        $this->assertSame('birthday', $prep->occasion_type);
        $this->assertSame('نورة', $prep->recipient_name);
        $this->assertSame('مريم علي', $prep->sender_name);

        $addonLine = CustomerInvoice::sole()->items()->whereNotNull('addon_id')->sole();
        $this->assertEqualsWithDelta(2.0, (float) $addonLine->quantity, 0.0005);
    }

    /* ═════════════ ٤ · لا شيءَ مرّتين ═════════════ */

    public function test_the_same_notice_twice_and_two_messages_at_once_are_each_answered_once(): void
    {
        $wamid = $this->say('طلب');
        $afterFirst = count($this->sent());

        // ميتا تعيد الإشعارَ نفسه — لا يُعالج ثانيةً
        $this->say('طلب', wamid: $wamid);
        $this->assertSame($afterFirst, count($this->sent()));
        $this->assertSame(1, WhatsAppOrderSession::count());

        // ورسالتان معًا: الثانيةُ تصل والأولى تُرسل جوابَها — فتقرأ ما كتبته الأولى
        [$id, $title, $kind] = $this->option('ورد');
        $this->whileSending = fn () => $this->say('موظف');
        $this->reply($id, $title, $kind);

        $session = $this->order();
        $this->assertSame(1, WhatsAppOrderSession::count(), 'طلبٌ مفتوحٌ واحدٌ في المحادثة');
        $this->assertSame(WhatsAppOrderSession::NEEDS_HUMAN, $session->state);
        $this->assertSame(WhatsAppOrders::REASON_ASKED, $session->handoff_reason);

        // ومع موظّف: الآليُّ والترحيبُ يصمتان
        $before = count($this->sent());
        $this->say('مرحبا، متى يصل الطلب؟');
        $this->assertSame($before, count($this->sent()));
    }

    public function test_confirming_twice_writes_one_invoice_and_asks_for_money_once(): void
    {
        $this->orderToSummary();
        [$id, $title, $kind] = $this->option('تأكيد');

        $this->reply($id, $title, $kind);
        // الزرُّ نفسُه ثانيةً — من رسالةٍ صار لها ما بعدها
        $this->reply($id, $title, $kind);

        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, CustomerPaymentLink::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, $this->links, 'طُلب رابطٌ واحدٌ من Paymob');
        // والضغطةُ الثانيةُ تُعيد الرابطَ نفسه — لا رابطًا ولا مبلغًا آخر
        $urls = collect($this->sent())->filter(fn ($m) => ($m['interactive']['type'] ?? null) === 'cta_url')
            ->map(fn ($m) => $m['interactive']['action']['parameters']['url'])->unique()->values()->all();
        $this->assertSame(['https://paymob.link/pay1'], $urls);

        // ورسالةٌ بعدها تُعيد الرابطَ نفسه لا رابطًا جديدًا
        $this->say('وين الرابط؟');
        $this->assertSame('https://paymob.link/pay1', $this->lastInteractive()['action']['parameters']['url']);
        $this->assertSame(1, $this->links);

        // ومهمّةُ الرابط ثانيةً — أُعيدت، أو جرت مرّتين معًا: الرابطُ وصل، فلا رسالةَ أخرى
        $session = $this->order();
        $before = count($this->ctaUrls());
        $this->whileSending = fn () => WhatsAppOrders::issue((int) $session->id);
        WhatsAppOrders::issue((int) $session->id);
        $this->assertCount($before, $this->ctaUrls());
        $this->assertSame(1, $this->links);
    }

    /* ═════════════ ٥ · دفعٌ لا يكفي ═════════════ */

    public function test_a_failed_or_short_payment_leaves_the_order_waiting_off_the_board(): void
    {
        $this->orderToSummary();
        $this->press('تأكيد');
        $link = CustomerPaymentLink::sole();

        $this->paymob($this->obj($link, ['success' => false]));
        $this->paymob($this->obj($link, ['amount_cents' => Amount::toMinor(10, 'OMR')]));

        $this->assertSame(0, CustomerPayment::count());
        $this->assertNull(CustomerInvoicePreparation::sole()->activated_at);
        $this->assertSame(WhatsAppOrderSession::AWAITING_PAYMENT, $this->order()->state);
        $this->assertStringNotContainsString('وصلت دفعتك', $this->allText());
    }

    public function test_an_expired_link_hands_the_chat_to_staff_and_a_late_payment_is_not_prepared(): void
    {
        $this->orderToSummary();
        $this->press('تأكيد');
        $link = CustomerPaymentLink::sole();

        Carbon::setTestNow(now()->addDays(8));
        $this->say('أريد الدفع');

        $session = $this->order();
        $this->assertSame(WhatsAppOrderSession::NEEDS_HUMAN, $session->state);
        $this->assertSame(WhatsAppOrders::REASON_LINK_CLOSED, $session->handoff_reason);
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'handoff'), $this->lastText());

        $this->paymob($this->obj($link->fresh()));
        $this->assertNull(CustomerInvoicePreparation::sole()->activated_at, 'رابطٌ دُفع بعد انتهائه لا يُفعّل التجهيز');
    }

    /* ═════════════ ٦ · نافذةُ الأربع والعشرين ساعة ═════════════ */

    public function test_a_payment_after_the_window_closed_is_never_told_in_free_text(): void
    {
        $this->orderToSummary();
        $this->press('تأكيد');
        $link = CustomerPaymentLink::sole();
        $before = count($this->sent());

        Carbon::setTestNow(now()->addHours(25));
        $this->paymob($this->obj($link));

        $this->assertNotNull(CustomerInvoicePreparation::sole()->activated_at);
        $this->assertSame(WhatsAppOrderSession::PAID, $this->order()->state);
        // لا نصَّ حرًّا خارج النافذة — ولا قالبَ معتمَدًا بعد: يُقيَّد لم يُرسَل بسببه
        $this->assertSame($before, count($this->sent()));
        $notice = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_PAID)->sole();
        $this->assertSame(WhatsAppStatus::SKIPPED, $notice->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $notice->error_code);
    }

    public function test_an_approved_template_carries_the_paid_message_outside_the_window(): void
    {
        $this->orderToSummary();
        $this->press('تأكيد');
        $invoice = CustomerInvoice::sole();

        WhatsAppTemplateMapping::create([
            'scope_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'event_type' => WhatsAppEvent::ORDER_PAID,
            'template_name' => 'abaad_order_paid', 'language_code' => 'ar', 'enabled' => true, 'meta_status' => 'APPROVED', 'approved_languages' => ['ar'],
        ]);

        Carbon::setTestNow(now()->addHours(25));
        $this->paymob($this->obj(CustomerPaymentLink::sole()));

        $last = $this->lastSent();
        $this->assertSame('template', $last['type']);
        $this->assertSame('abaad_order_paid', $last['template']['name']);
        $values = array_column($last['template']['components'][0]['parameters'], 'text');
        $this->assertSame(['مريم علي', $invoice->number], array_slice($values, 0, 2));
        $this->assertSame(self::WABA, $last['messaging_account_id']);
    }

    /* ═════════════ ٧ · ما لا يُرسَل ═════════════ */

    public function test_a_number_whose_meta_billing_is_not_reviewed_starts_no_order_and_sends_nothing(): void
    {
        $this->line->forceFill(['billing_state' => 'unproven'])->save();

        $this->say('طلب');

        $this->assertSame(0, WhatsAppOrderSession::count());
        $this->assertSame([], $this->sent());
    }

    public function test_without_the_platform_flag_or_the_owners_switch_the_welcome_answers_as_before(): void
    {
        WhatsAppOrders::save($this->shop->id, false);
        $this->say('طلب');
        $this->assertSame(0, WhatsAppOrderSession::count());
        $this->assertSame('text', $this->lastSent()['type'] ?? null, 'الترحيبُ نصٌّ بلا أزرار');

        WhatsAppOrders::save($this->shop->id, true);
        config(['whatsapp.guided_orders_businesses' => []]);
        Carbon::setTestNow(now()->addDay());
        $this->say('طلب');
        $this->assertSame(0, WhatsAppOrderSession::count());
        $this->assertSame('text', $this->lastSent()['type'] ?? null);
    }

    public function test_the_welcome_offers_the_order_button_and_it_starts_an_order(): void
    {
        $this->say('مرحبا');
        $welcome = $this->lastInteractive();
        $this->assertSame('button', $welcome['type']);
        $this->assertSame([WhatsAppOrders::START_ID, WhatsAppOrders::STAFF_ID], array_map(fn ($b) => $b['reply']['id'], $welcome['action']['buttons']));
        $this->assertSame(0, WhatsAppOrderSession::count(), 'تحيّةٌ لا تبدأ طلبًا');

        $this->press('اطلب الآن');
        $this->assertSame(WhatsAppOrderSession::COLLECTING, $this->order()->state);
        $this->assertSame('list', $this->lastInteractive()['type']);
    }

    /* ═════════════ ٨ · موعدٌ وفرعٌ وأزرارٌ لا تصلح ═════════════ */

    public function test_a_time_that_closed_before_confirming_is_asked_again(): void
    {
        $week = array_fill(0, 7, [['id' => 'am01', 'from' => '09:00', 'to' => '12:00', 'ar' => 'الصباح', 'en' => 'Morning', 'cutoff' => ['time' => '08:00', 'day' => 'same']]]);
        MarketingSettings::save($this->shop->id, 'website', [
            DeliverySchedule::ON => '1',
            DeliverySchedule::KEY => DeliverySchedule::validated(['delivery' => $week, 'pickup' => null, 'closed' => [], 'lead' => 0]),
        ]);

        $this->say('طلب');
        $this->press('هدايا');
        $this->press('كوب');
        $this->press('1');
        $this->press('تم');
        $this->press('إتمام الطلب');
        $this->press('توصيل');
        $this->say('Maryam');
        $this->press('أنا');
        $this->press('Qurum');
        $this->say('Way 1234');
        $this->press('الأربعاء');
        $this->press('الصباح');

        // أُغلق حجزُ الصباح عند الثامنة — والزبونُ يؤكّد بعدها
        Carbon::setTestNow('2027-02-10 08:30:00');
        $this->press('تأكيد');

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'slot_gone'), $this->allText());
        $this->assertSame('date', $this->order()->step);
        $this->assertNotContains('d:2027-02-10', array_map(fn ($r) => substr($r['id'], strrpos($r['id'], 'd:')), $this->lastInteractive()['action']['sections'][0]['rows']));
    }

    public function test_a_button_from_another_shop_or_an_older_message_changes_nothing(): void
    {
        [$other] = $this->openShop('NEIGHBOUR', 'PN-OTHER', 'WABA-OTHER');
        $foreign = Product::where('business_id', $other->id)->where('name', 'كوب')->sole();

        $this->say('طلب');
        $this->press('هدايا');
        $session = $this->order();

        // صنفُ متجرٍ آخر بمعرّفه في زرٍّ مزوَّر على السؤال القائم
        $this->reply('o:'.$session->id.':'.$session->seq.':p:'.$foreign->id, 'كوب', 'list_reply');
        $this->assertSame([], (array) $this->order()->cart);
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'invalid'), $this->allText());

        // وزرٌّ من سؤالٍ سابق
        $this->reply('o:'.$session->id.':1:c:'.$this->rose->category_id, 'ورد', 'list_reply');
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'stale'), $this->allText());
        $this->assertSame([], (array) $this->order()->cart);

        // ولا شيءَ يُكتب للمتجر الآخر
        $this->assertSame(0, WhatsAppOrderSession::where('business_id', $other->id)->count());
    }

    public function test_a_price_that_changed_after_the_summary_is_shown_again_before_any_invoice(): void
    {
        $this->orderToSummary();
        $this->rose->forceFill(['price' => 25])->save();

        $this->press('تأكيد');

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(WhatsAppOrderSession::CONFIRMING, $this->order()->state);
        $this->assertSame('55.000', (string) $this->order()->total);
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'price_changed'), $this->allText());

        $this->press('تأكيد');
        $this->assertEqualsWithDelta(55.0, (float) CustomerInvoice::sole()->total, 0.0005);
    }

    public function test_the_customer_can_cancel_before_paying_and_start_again(): void
    {
        $this->say('طلب');
        $this->press('ورد');
        $this->say('إلغاء');

        $this->assertSame(WhatsAppOrderSession::CANCELLED, $this->order()->state);
        $this->assertNull($this->order()->open_key);

        $this->say('order');
        $this->assertSame(2, WhatsAppOrderSession::count());
        $this->assertSame('en', $this->order()->language);
        $this->assertStringContainsString('Choose', $this->lastInteractive()['body']['text']);
    }

    /* ═════════════ ٩ · الموظّفُ والإعداد ═════════════ */

    private function staff(string $role = 'admin'): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'staff'.uniqid().'@ribbon.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    public function test_staff_take_over_a_chat_and_close_its_order_from_the_inbox(): void
    {
        $owner = $this->staff();
        $this->say('طلب');
        $this->press('ورد');
        $chat = $this->order()->chat;

        $this->actingAs($owner)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('thread.order.state', WhatsAppOrderSession::COLLECTING)->where('thread.order.open', true));

        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.takeOver', $chat->id))->assertRedirect();
        $this->assertSame(WhatsAppOrderSession::NEEDS_HUMAN, $this->order()->state);
        $this->assertSame(WhatsAppOrders::REASON_STAFF, $this->order()->handoff_reason);

        // والآليُّ صامتٌ بعدها — والزبونُ يكتب «طلب» فلا يبدأ شيء
        $before = count($this->sent());
        $this->say('طلب');
        $this->assertSame($before, count($this->sent()));

        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.close', $chat->id))->assertRedirect();
        $this->assertSame(WhatsAppOrderSession::CANCELLED, $this->order()->state);

        $this->say('طلب');
        $this->assertSame(2, WhatsAppOrderSession::count(), 'يعود الآليُّ يستقبل بعد الإنهاء');
    }

    public function test_the_owner_switches_it_from_the_whatsapp_screen_and_another_shops_chat_is_404(): void
    {
        $owner = $this->staff();
        WhatsAppOrders::save($this->shop->id, false);

        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.guidedOrders'), ['enabled' => true])->assertRedirect();
        $this->assertTrue(WhatsAppOrders::enabled($this->shop->id));
        $this->assertTrue(WhatsAppOrders::active($this->shop->fresh()));

        // ومتجرٌ لم تأذن له المنصّة لا بابَ له
        [$other, $otherLine] = $this->openShop('NEIGHBOUR', 'PN-OTHER', 'WABA-OTHER');
        $neighbour = User::create([
            'business_id' => $other->id, 'name' => 'الجار', 'email' => 'n@ribbon.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($neighbour)->post(route('admin.integrations.whatsapp.guidedOrders'), ['enabled' => true])->assertNotFound();
        $this->assertFalse(WhatsAppOrders::enabled($other->id));

        // ومحادثةُ متجرٍ لا تُغلق من متجرٍ آخر
        $this->say('طلب');
        $chat = $this->order()->chat;
        $this->actingAs($neighbour)->post(route('admin.whatsappInbox.order.close', $chat->id))->assertNotFound();
        $this->assertSame(WhatsAppOrderSession::COLLECTING, $this->order()->state);
    }

    /* ═════════════ ٩ب · رسالةٌ لم تصل — تُعاد بلا ورقةٍ ولا رابطٍ ولا مهمّةٍ ثانية ═════════════ */

    /** @return list<string> عناوينُ أزرار الدفع التي خرجت إلى ميتا — ما قُبل وما رُدّ */
    private function ctaUrls(): array
    {
        return collect($this->sent())->filter(fn ($m) => ($m['interactive']['type'] ?? null) === 'cta_url')
            ->map(fn ($m) => $m['interactive']['action']['parameters']['url'])->values()->all();
    }

    public function test_a_link_meta_refused_is_shown_to_staff_and_the_same_link_is_resent_once(): void
    {
        $owner = $this->staff();
        $this->orderToSummary();

        $this->refuse = fn (array $m) => ($m['interactive']['type'] ?? null) === 'cta_url';
        $this->press('تأكيد');
        $this->refuse = null;

        $session = $this->order();
        $chat = $session->chat;
        $this->assertSame(WhatsAppOrderSession::AWAITING_PAYMENT, $session->state);
        $this->assertSame(WhatsAppStatus::FAILED, WhatsAppOrders::sendStatus($session, WhatsAppOrders::SEND_LINK), 'ردّته ميتا — لا يُعدّ «أُرسل»');
        $this->assertSame('link_not_sent', $session->error);

        $this->actingAs($owner)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('thread.order.state', WhatsAppOrderSession::AWAITING_PAYMENT)
                ->where('thread.order.link_message', WhatsAppStatus::FAILED)->where('thread.order.can_resend_link', true));

        // نقرتان معًا: الثانيةُ تجد الإرسالَ محجوزًا فلا تُرسل
        $second = null;
        $this->whileSending = function () use ($chat, &$second) {
            try {
                WhatsAppOrders::resendLink($chat->fresh());
                $second = 'sent';
            } catch (ValidationException) {
                $second = 'refused';
            }
        };
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendLink', $chat->id))
            ->assertRedirect()->assertSessionHas('toast.type', 'success');
        $this->assertSame('refused', $second);

        $session->refresh();
        $this->assertSame(WhatsAppStatus::SENT, WhatsAppOrders::sendStatus($session, WhatsAppOrders::SEND_LINK));
        $this->assertNull($session->error);
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, CustomerPaymentLink::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, $this->links, 'رابطٌ واحدٌ من Paymob');
        $this->assertSame(['https://paymob.link/pay1', 'https://paymob.link/pay1'], $this->ctaUrls(), 'المردودُ ثمّ الرابطُ نفسُه مرّةً');

        // وما وصل لا يُعاد من الصندوق
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendLink', $chat->id))->assertSessionHasErrors('order');
        $this->assertCount(2, $this->ctaUrls());

        // ثمّ ردّته ميتا بإشعارٍ بعد قبوله — فيعود «لم يصل»
        $accepted = WhatsAppChatMessage::where('business_id', $this->shop->id)->where('status', WhatsAppStatus::SENT)
            ->where('body', 'like', '%https://paymob.link/pay1%')->latest('id')->firstOrFail();
        WhatsAppInbox::applyStatus($this->line, (string) $accepted->wamid, ['status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]);
        $this->assertSame(WhatsAppStatus::FAILED, WhatsAppOrders::sendStatus($session->fresh(), WhatsAppOrders::SEND_LINK));

        // والزبونُ يكتب فيصله الرابطُ نفسُه — ويراه الموظّفُ «أُرسل»
        $this->say('ما وصلني الرابط');
        $this->assertSame('https://paymob.link/pay1', $this->lastInteractive()['action']['parameters']['url']);
        $this->assertSame(WhatsAppStatus::SENT, WhatsAppOrders::sendStatus($session->fresh(), WhatsAppOrders::SEND_LINK));
        $this->assertSame(1, $this->links);

        // ويدفع — مهمّةٌ واحدةٌ في اللوحة
        $this->paymob($this->obj(CustomerPaymentLink::sole()));
        $this->assertSame(WhatsAppOrderSession::PAID, $this->order()->state);
        $this->assertNotNull(CustomerInvoicePreparation::sole()->activated_at);
    }

    public function test_a_link_paymob_could_not_open_is_issued_from_the_inbox_on_the_same_invoice(): void
    {
        $owner = $this->staff();
        $this->orderToSummary();

        $this->paymobDown = true;
        $this->press('تأكيد');

        $session = $this->order();
        $chat = $session->chat;
        $this->assertSame(WhatsAppOrderSession::NEEDS_HUMAN, $session->state);
        $this->assertSame(WhatsAppOrders::REASON_LINK_FAILED, $session->handoff_reason);
        $this->assertNull($session->payment_link_id);
        $this->assertStringContainsString(WhatsAppOrderTexts::say('ar', 'failed_link'), $this->lastText(), 'قيل للزبون بصدق — لا «ادفع»');
        $this->assertSame([], $this->ctaUrls());
        $invoice = CustomerInvoice::where('business_id', $this->shop->id)->sole();

        // وPaymob لم يردّ: لا يُعرف أأُنشئ الرابطُ هناك — فالموظّفُ يُقال له ذلك
        $this->actingAs($owner)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('thread.order.reason', WhatsAppOrders::REASON_LINK_FAILED)
                ->where('thread.order.can_resend_link', true)->where('thread.order.link_uncertain', true));

        // ولا يُطلب رابطٌ جديدٌ بلا قراره الصريح — كصفحة الفاتورة
        $this->paymobDown = false;
        $told = count($this->sent());
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendLink', $chat->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame(0, $this->links);
        $this->assertSame($told, count($this->sent()), 'تعذّر ثانيةً بصمت — الزبونُ قيل له مرّة');
        $this->assertSame(WhatsAppOrderSession::NEEDS_HUMAN, $this->order()->state);

        // وبقراره: رابطٌ جديدٌ على الفاتورة نفسها، يصل الزبونَ وحده
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendLink', $chat->id), ['replace_uncertain' => true])
            ->assertSessionHas('toast.type', 'success');

        $session->refresh();
        $this->assertSame(WhatsAppOrderSession::AWAITING_PAYMENT, $session->state);
        $this->assertSame((int) $invoice->id, (int) $session->customer_invoice_id);
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->count(), 'الفاتورةُ نفسُها');
        $this->assertSame(1, CustomerPaymentLink::where('business_id', $this->shop->id)->where('status', CustomerPaymentLink::PENDING)->count());
        $this->assertSame(['https://paymob.link/pay1'], $this->ctaUrls());
        $this->assertSame(WhatsAppStatus::SENT, WhatsAppOrders::sendStatus($session, WhatsAppOrders::SEND_LINK));
    }

    public function test_a_paid_order_whose_confirmation_did_not_arrive_is_told_again_without_a_second_task(): void
    {
        $owner = $this->staff();
        $this->orderToSummary();
        $this->press('تأكيد');
        $invoice = CustomerInvoice::sole();
        $obj = $this->obj(CustomerPaymentLink::sole());

        $this->refuse = fn (array $m) => ($m['type'] ?? null) === 'text';
        $this->paymob($obj);
        $this->refuse = null;

        // التحصيلُ والمهمّةُ قائمان — الرسالةُ وحدها لم تصل
        $session = $this->order();
        $chat = $session->chat;
        $prep = CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->sole();
        $this->assertSame(WhatsAppOrderSession::PAID, $session->state);
        $this->assertNotNull($prep->activated_at);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertSame(WhatsAppStatus::FAILED, WhatsAppOrders::sendStatus($session, WhatsAppOrders::SEND_PAID));
        $this->assertSame('paid_not_sent', $session->error);

        $this->actingAs($owner)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('thread.order.state', WhatsAppOrderSession::PAID)
                ->where('thread.order.paid_message', WhatsAppStatus::FAILED)->where('thread.order.can_resend_paid', true));

        // والإشعارُ نفسُه من Paymob ثانيةً لا يرسلها
        $before = count($this->sent());
        $this->paymob($obj);
        $this->assertSame($before, count($this->sent()));

        $activated = $prep->fresh()->activated_at;
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendPaid', $chat->id))
            ->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertStringContainsString('وصلت دفعتك', $this->lastText());
        $this->assertStringContainsString($invoice->number, $this->lastText());
        $this->assertSame(WhatsAppStatus::SENT, WhatsAppOrders::sendStatus($session->fresh(), WhatsAppOrders::SEND_PAID));
        $this->assertNull($session->fresh()->error);

        // ولا تحصيلَ ولا مهمّةَ ولا طباعةَ ثانية
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, CustomerInvoicePreparation::where('customer_invoice_id', $invoice->id)->count());
        $this->assertEquals($activated, $prep->fresh()->activated_at);
        $this->assertSame(1, PrintJob::where('business_id', $this->shop->id)->count());

        // وما وصل لا يُعاد
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendPaid', $chat->id))->assertSessionHasErrors('order');
        $this->assertSame($before + 1, count($this->sent()));
    }

    public function test_a_template_meta_has_not_approved_is_never_sent_and_the_approved_one_is(): void
    {
        $owner = $this->staff();
        $this->orderToSummary();
        $this->refuse = fn (array $m) => ($m['interactive']['type'] ?? null) === 'cta_url';
        $this->press('تأكيد');
        $this->refuse = null;
        $chat = $this->order()->chat;

        // قالبٌ لم تُسأل عنه ميتا بعد، وآخرُ ينتظر مراجعتها
        $link = WhatsAppTemplateMapping::create([
            'scope_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'event_type' => WhatsAppEvent::ORDER_PAYMENT_LINK,
            'template_name' => 'abaad_order_payment_link', 'language_code' => 'ar', 'enabled' => true, 'meta_status' => null,
        ]);
        WhatsAppTemplateMapping::create([
            'scope_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'event_type' => WhatsAppEvent::ORDER_PAID,
            'template_name' => 'abaad_order_paid', 'language_code' => 'ar', 'enabled' => true, 'meta_status' => 'PENDING',
        ]);

        // بعد النافذة: لا نصَّ حرًّا، ولا قالبَ غيرَ معتمَد
        Carbon::setTestNow(now()->addHours(25));
        $before = count($this->sent());
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendLink', $chat->id))->assertSessionHas('toast.type', 'danger');
        $this->assertSame($before, count($this->sent()));
        $row = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_PAYMENT_LINK)->sole();
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $row->error_code);

        $this->paymob($this->obj(CustomerPaymentLink::sole()));
        $this->assertSame($before, count($this->sent()));
        $paid = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_PAID)->sole();
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $paid->error_code);
        $this->assertSame(WhatsAppStatus::SKIPPED, WhatsAppOrders::sendStatus($this->order(), WhatsAppOrders::SEND_PAID));

        // ثمّ اعتمدته ميتا — فيخرج من الصندوق بالقالب
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_PAID)->update(['meta_status' => 'APPROVED', 'approved_languages' => ['ar']]);
        $this->actingAs($owner)->post(route('admin.whatsappInbox.order.resendPaid', $chat->id))->assertSessionHas('toast.type', 'success');
        $last = $this->lastSent();
        $this->assertSame('template', $last['type']);
        $this->assertSame('abaad_order_paid', $last['template']['name']);
        $this->assertSame(1, CustomerPayment::where('business_id', $this->shop->id)->count());
        $this->assertSame(0, WhatsAppMessage::where('business_id', $this->shop->id)->where('template_name', $link->template_name)->where('status', '!=', WhatsAppStatus::SKIPPED)->count());
    }

    public function test_only_ribbon_is_allowed_by_default_and_nothing_runs_before_the_owner_and_every_condition(): void
    {
        // بلا متغيّر بيئة: متجرُ سعود (RIBBON) وحده — رقمٌ مقصود: معرّفُه على الإنتاج
        $this->assertNull(env('WHATSAPP_GUIDED_ORDERS_BUSINESSES'));
        $this->assertSame([5], (require config_path('whatsapp.php'))['guided_orders_businesses']);

        // ومتجرٌ أُذن له مطفأٌ حتى يُشعله صاحبُه
        [$fresh] = $this->openShop('FRESH', 'PN-FRESH', 'WABA-FRESH');
        config(['whatsapp.guided_orders_businesses' => [$this->shop->id, $fresh->id]]);
        $this->assertFalse(WhatsAppOrders::enabled($fresh->id));
        $this->assertFalse(WhatsAppOrders::active($fresh));

        // ومُشعَلٌ لا يعمل وشرطٌ ناقص — التجهيزُ بعد السداد، ثمّ روابطُ Paymob
        $this->assertTrue(WhatsAppOrders::active($this->shop->fresh()));
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => false])->save();
        $this->assertFalse(WhatsAppOrders::readiness($this->shop->fresh())['preparation']);
        $this->say('طلب');
        $this->assertSame(0, WhatsAppOrderSession::count());

        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        PaymentGateway::where('business_id', $this->shop->id)->update(['api_key' => null]);
        $this->assertFalse(WhatsAppOrders::readiness($this->shop->fresh())['payment_links']);
        Carbon::setTestNow(now()->addDay());
        $this->say('طلب');
        $this->assertSame(0, WhatsAppOrderSession::count());
    }

    /* ═════════════ ١٠ · النصوص ═════════════ */

    public function test_every_text_exists_in_both_languages_and_every_button_fits_metas_limits(): void
    {
        $this->assertSame(WhatsAppOrderTexts::keys('ar'), WhatsAppOrderTexts::keys('en'));

        $buttons = ['welcome_order', 'welcome_staff', 'confirm', 'edit', 'cancel', 'add_more', 'to_checkout', 'empty_cart', 'change_details',
            'delivery', 'pickup', 'me', 'someone_else', 'yes_gift', 'no_gift', 'hide', 'show', 'print_as_is', 'rewrite', 'skip', 'no_card', 'pay_now'];

        foreach (['ar', 'en'] as $lang) {
            foreach ($buttons as $key) {
                $this->assertLessThanOrEqual(WhatsAppOrderReplies::BUTTON_TITLE_MAX, mb_strlen(WhatsAppOrderTexts::say($lang, $key)), "{$lang}.{$key}");
            }
        }
    }
}
