<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOrderSession;
use App\Models\WhatsAppTemplateMapping;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * لوحةُ التجهيز تُبلغ الزبونَ أين صار طلبُه — من رقم المتجر، بقالبه المعتمَد، وبلغته.
 *
 * ═══ ما كان ═══
 *
 * لوحةُ سعود مهامُّ فواتير في أغلبها (`customer_invoice_preparations`): سبعون حركةً
 * في أسبوعين مقابل حركتين لطلبات الموقع. وحركةُ المهمّة لا طلبَ لها فلا يسمعها
 * `OrderObserver` — فكانت تتحرّك صامتة: لا رسالةَ ولا صفَّ في السجلّ يقول لماذا.
 * وكان «جديد» ← «قيد التجهيز» لطلب الموقع يتخطّى «مؤكّد» فتضيع رسالةُ التأكيد.
 *
 * ═══ وما يُثبَت هنا ═══
 *
 * المهمّةُ والطلبُ بالقرار نفسِه (`WhatsAppAutomation`): مفتاحُ الحدث، والوصلة،
 * والقالبُ المعتمَدُ بلغة الزبون، ومرّةٌ واحدةٌ لكلّ حدث. والصفُّ في السجلّ وفي
 * المحادثات بحاله، وحالُ التسليم من ميتا تصله. وعطبُ واتساب لا يمسّ النقل.
 * ومتجرٌ آخر على حاله.
 *
 * ميتا محاكاةٌ هنا كلُّها، وما سواها يُسقط الاختبار.
 */
class TheBoardTellsTheCustomerWhereTheirOrderIsTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'merchant-app-secret-for-tests';

    private Business $shop;

    private WhatsAppConnection $line;

    private Branch $branch;

    private User $owner;

    private User $staff;

    private int $out = 0;

    /** ما ترسله ميتا ردًّا على الرسالة — `null` قبولٌ */
    private ?array $refuse = null;

    private array $stray = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-03-10 09:00:00');

        config([
            'whatsapp.app_secret' => 'abaad-app-secret',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.merchant_link' => 'own_app',
            'whatsapp.guided_orders_businesses' => [],
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), 'graph.example.test') && str_contains($r->url(), '/messages')) {
                if ($this->refuse !== null) {
                    return Http::response(['error' => $this->refuse], 400);
                }

                return Http::response(['messages' => [['id' => 'wamid.OUT-'.(++$this->out)]]], 200);
            }

            $this->stray[] = $r->url();

            return Http::response(['error' => ['message' => 'unexpected']], 404);
        });

        [$this->shop, $this->line, $this->branch] = $this->openShop('RIBBON', 'PN-RIBBON', 'WABA-RIBBON');
        config([
            'whatsapp.ribbon_businesses' => [$this->shop->id],
            'whatsapp.invoice_task_notices_businesses' => [$this->shop->id],
        ]);

        $this->owner = $this->user($this->shop, 'سعود', 'admin');
        $this->staff = $this->user($this->shop, 'سهى', 'sales');
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame([], $this->stray, 'نداءٌ خرج إلى غير ميتا المحاكاة');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ المتجر ═══════════ */

    /** @return array{0: Business, 1: WhatsAppConnection, 2: Branch} */
    private function openShop(string $name, string $phoneId, string $waba): array
    {
        $plan = Plan::create(['name' => 'الاحترافية '.$name, 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);

        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'plan_id' => $plan->id,
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        $shop->forceFill(['customer_invoice_preparation_enabled' => true, 'customer_invoice_preparation_enabled_at' => now()->subDay()])->save();
        Ledger::seedChart($shop->id);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);

        // المفاتيحُ الأربعة مشعَلةً كما في حساب سعود — `1` لكلٍّ منها
        MarketingSettings::save($shop->id, 'whatsapp', [
            'wa_on_received' => '1', 'wa_on_order' => '1', 'wa_on_ready' => '1', 'wa_on_out_for_delivery' => '1',
            'wa_on_delivered' => '0',
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

        // القوالبُ الأربعة معتمَدةً بالعربيّة والإنجليزيّة — كما قالت ميتا لحساب سعود
        foreach (WhatsAppEvent::RIBBON_EVENTS as $event) {
            WhatsAppTemplateMapping::create([
                'scope_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $shop->id, 'event_type' => $event,
                'template_name' => WhatsAppEvent::RIBBON_TEMPLATES[$event], 'language_code' => 'ar', 'enabled' => true,
                'meta_status' => 'APPROVED', 'approved_languages' => ['ar', 'en'],
            ]);
        }

        return [$shop, $line, $branch];
    }

    private function user(Business $shop, string $name, string $role): User
    {
        return User::create([
            'business_id' => $shop->id, 'name' => $name, 'email' => uniqid('u').'@ribbon.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function customer(?string $language = 'ar', ?string $phone = '91234567', ?Business $shop = null): Customer
    {
        return Customer::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => 'مريم', 'phone' => $phone,
            'language' => $language, 'allow_credit_sales' => true,
        ]);
    }

    /** فاتورةٌ صادرةٌ مدفوعةٌ ومهمّتُها في اللوحة — كما يصل طلبُ سعود */
    private function task(Customer $customer, ?Business $shop = null, ?Branch $branch = null): CustomerInvoicePreparation
    {
        $shop ??= $this->shop;
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($shop->id, $customer, ['issued_at' => '2027-03-10'], [
            ['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 25, 'discount' => 0],
        ]));

        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'delivery',
            'branch_id' => ($branch ?? $this->branch)->id,
            'scheduled_for' => '2027-03-10 16:00',
            'recipient_name' => 'سارة',
            'recipient_phone' => '99887766',
            'delivery_address' => 'القرم، شارع 5',
        ], $this->owner->id);

        CustomerPayments::record($shop->id, $customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2027-03-10'], [$invoice->id => (float) $invoice->total]);
        [$prep] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        return $prep;
    }

    private function order(Customer $customer, string $number, string $status = OrderStatus::PENDING): Order
    {
        return Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'number' => $number, 'status' => $status, 'is_held' => false,
            'payment_method' => 'Paymob', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    private function moveTask(User $by, CustomerInvoicePreparation $task, string $to)
    {
        return $this->actingAs($by)->post(route('admin.preparation.invoiceMove', $task->id), ['status' => $to]);
    }

    /** @return list<array<string, mixed>> ما خرج إلى ميتا */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))
            ->map(fn (HttpRequest $r) => $r->data() + ['_url' => $r->url()])
            ->values()->all();
    }

    /** @return list<string> «اسمُ القالب/لغتُه» لكلّ ما خرج */
    private function templates(): array
    {
        return array_map(fn ($m) => ($m['template']['name'] ?? '?').'/'.($m['template']['language']['code'] ?? '?'), $this->sent());
    }

    private function notices(?Business $shop = null)
    {
        return WhatsAppMessage::where('business_id', ($shop ?? $this->shop)->id)->orderBy('id')->get();
    }

    /** إشعارُ حالٍ من ميتا إلى بابِ رقم المتجر — موقَّعٌ بسرّ تطبيقه */
    private function metaSays(string $wamid, string $status): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => $this->line->waba_id, 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '96876790720', 'phone_number_id' => $this->line->phone_number_id],
            'statuses' => [['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '96891234567']],
        ]]]]]];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->call('POST', WhatsAppOwnApp::callbackUrl($this->line), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ], $body)->assertOk();
    }

    /* ═════════════ ١ · مهمّةُ الفاتورة في لوحة التجهيز ═════════════ */

    public function test_a_paid_invoice_task_tells_an_arabic_customer_it_is_confirmed_then_ready_then_out(): void
    {
        $task = $this->task($this->customer('ar'));

        // ظهرت في اللوحة «مؤكّدًا» بعد السداد — رسالةُ التأكيد بالعربيّة من رقم سعود
        $this->assertSame(['ribbon_order_confirmed/ar'], $this->templates());
        $first = $this->sent()[0];
        $this->assertStringEndsWith('/PN-RIBBON/messages', $first['_url']);
        $this->assertSame('WABA-RIBBON', $first['messaging_account_id'], 'من حساب الرسائل الذي رُوجعت فوترتُه');
        $this->assertSame('96891234567', $first['to']);
        $this->assertArrayNotHasKey('components', $first['template'], 'قوالبُ RIBBON بلا متغيّرات');

        // موظّفةٌ تنقلها إلى «جاهز» — ثمّ سعود إلى «خرج للتوصيل»
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertRedirect()->assertSessionHasNoErrors();
        $this->moveTask($this->owner, $task, OrderStatus::OUT_FOR_DELIVERY)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['ribbon_order_confirmed/ar', 'ribbon_order_ready/ar', 'ribbon_order_out_for_delivery/ar'], $this->templates());

        $rows = $this->notices();
        $this->assertSame([WhatsAppEvent::ORDER_CONFIRMED, WhatsAppEvent::ORDER_READY, WhatsAppEvent::ORDER_OUT_FOR_DELIVERY], $rows->pluck('event_type')->all());
        foreach ($rows as $row) {
            $this->assertSame(WhatsAppStatus::SENT, $row->status);
            $this->assertNotNull($row->provider_message_id, 'لا «أُرسل» إلّا بمعرّفٍ أعادته ميتا');
            $this->assertSame((int) $task->customer_invoice_id, (int) $row->customer_invoice_id);
            $this->assertNull($row->order_id);
            $this->assertSame((int) $this->line->id, (int) $row->whatsapp_connection_id);
            $this->assertSame(WhatsAppMode::BUSINESS_OWN, $row->source_mode);
        }
    }

    public function test_an_english_customer_gets_the_english_template_and_a_regional_code_reads_as_its_language(): void
    {
        $task = $this->task($this->customer('en'));
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $regional = $this->task($this->customer('en-US', '91234568'));

        $this->assertSame(['ribbon_order_confirmed/en', 'ribbon_order_ready/en', 'ribbon_order_confirmed/en'], $this->templates());
    }

    public function test_a_customer_without_a_language_and_one_with_ar_om_get_arabic(): void
    {
        $this->task($this->customer(null));
        $this->task($this->customer('ar-OM', '91234569'));

        $this->assertSame(['ribbon_order_confirmed/ar', 'ribbon_order_confirmed/ar'], $this->templates());
    }

    public function test_preparing_and_delivered_send_nothing_and_a_task_back_to_ready_is_not_told_twice(): void
    {
        $task = $this->task($this->customer());

        $this->moveTask($this->staff, $task, OrderStatus::PREPARING)->assertSessionHasNoErrors();
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        $this->moveTask($this->staff, $task, OrderStatus::PREPARING)->assertSessionHasNoErrors();
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        $this->moveTask($this->staff, $task, OrderStatus::OUT_FOR_DELIVERY)->assertSessionHasNoErrors();
        $this->moveTask($this->staff, $task, OrderStatus::DELIVERED)->assertSessionHasNoErrors();

        // «قيد التجهيز» و«تم التسليم» ليسا في رسائل سعود الأربع — والجاهزيّةُ مرّةً للمهمّة
        $this->assertSame(['ribbon_order_confirmed/ar', 'ribbon_order_ready/ar', 'ribbon_order_out_for_delivery/ar'], $this->templates());
        $this->assertCount(3, $this->notices());
    }

    public function test_pressing_twice_and_two_accounts_at_once_send_one_message(): void
    {
        $task = $this->task($this->customer());

        $this->moveTask($this->owner, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        // الزرُّ نفسُه ثانيةً من حسابٍ آخر — والمهمّةُ «جاهز» سلفًا
        $this->moveTask($this->staff, $task, OrderStatus::READY);
        // وكأنّ الطلبين عبرا معًا قبل أن يرى أحدُهما الآخر: الحدثُ نفسُه مرّتين
        WhatsAppAutomation::handlePreparation($this->shop->id, $task->id, OrderStatus::CONFIRMED, OrderStatus::READY);
        WhatsAppAutomation::handlePreparation($this->shop->id, $task->id, OrderStatus::CONFIRMED, OrderStatus::READY);
        // وتفعيلٌ مكرَّرٌ للمهمّة لا يُعيد التأكيد
        CustomerInvoicePreparations::activate((int) $task->customer_invoice_id, CustomerInvoicePreparations::FROM_PAYMOB);

        $this->assertSame(['ribbon_order_confirmed/ar', 'ribbon_order_ready/ar'], $this->templates());
        $this->assertSame(1, WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)->count());
    }

    /* ═════════════ ٢ · طلبُ الموقع ═════════════ */

    public function test_a_website_order_is_received_and_confirmed_in_its_customers_language_whoever_presses(): void
    {
        $arabic = $this->order($this->customer('ar'), 'WEB-1');
        $english = $this->order($this->customer('en', '91234570'), 'WEB-2');

        $this->actingAs($this->owner)->post(route('admin.preparation.move', $arabic->number), ['status' => OrderStatus::CONFIRMED])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $english->number), ['status' => OrderStatus::CONFIRMED])->assertSessionHasNoErrors();

        $this->assertSame([
            'ribbon_order_received/ar',
            'ribbon_order_received_en/en',
            'ribbon_order_confirmed/ar',
            'ribbon_order_confirmed/en',
        ], $this->templates());
    }

    public function test_a_website_order_walks_the_board_and_each_step_reaches_the_customer_once(): void
    {
        $order = $this->order($this->customer('en'), 'WEB-5');

        $this->actingAs($this->owner)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::CONFIRMED])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::READY])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::OUT_FOR_DELIVERY])->assertSessionHasNoErrors();
        // وتكرارُ الضغط على الحال نفسها، ثمّ التسليم — لا رسالةَ فيهما
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::OUT_FOR_DELIVERY]);
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $order->number), ['status' => OrderStatus::DELIVERED])->assertSessionHasNoErrors();

        $this->assertSame([
            'ribbon_order_received_en/en',
            'ribbon_order_confirmed/en',
            'ribbon_order_ready/en',
            'ribbon_order_out_for_delivery/en',
        ], $this->templates());
        $this->assertSame(4, WhatsAppMessage::where('order_id', $order->id)->where('status', WhatsAppStatus::SENT)->count());
        foreach ($this->sent() as $m) {
            $this->assertStringEndsWith('/PN-RIBBON/messages', $m['_url'], 'من رقم سعود لا من رقم أبعاد');
        }
    }

    public function test_new_straight_to_preparing_still_confirms_once(): void
    {
        $skipped = $this->order($this->customer(), 'WEB-3');
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $skipped->number), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();

        $through = $this->order($this->customer('ar', '91234571'), 'WEB-4');
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $through->number), ['status' => OrderStatus::CONFIRMED])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('admin.preparation.move', $through->number), ['status' => OrderStatus::PREPARING])->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsAppMessage::where('order_id', $skipped->id)->where('event_type', WhatsAppEvent::ORDER_CONFIRMED)->count(), 'القفزةُ نفسُها تأكيد');
        $this->assertSame(1, WhatsAppMessage::where('order_id', $through->id)->where('event_type', WhatsAppEvent::ORDER_CONFIRMED)->count(), 'ومن مرّ بـ«مؤكّد» لا يُؤكَّد ثانيةً');
    }

    /* ═════════════ ٣ · ما لا يُرسل — ولماذا ═════════════ */

    public function test_a_template_meta_has_not_approved_is_logged_with_its_reason_and_not_sent(): void
    {
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)->update(['meta_status' => 'PAUSED']);
        $task = $this->task($this->customer());

        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $row = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)->sole();
        $this->assertSame(WhatsAppStatus::SKIPPED, $row->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $row->error_code);
        $this->assertSame(['ribbon_order_confirmed/ar'], $this->templates());
        $this->assertSame(OrderStatus::READY, $task->fresh()->status);
    }

    public function test_a_customer_without_a_phone_is_logged_not_sent(): void
    {
        $task = $this->task($this->customer('ar', null));

        $row = $this->notices()->sole();
        $this->assertSame(WhatsAppStatus::SKIPPED, $row->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_RECIPIENT, $row->error_code);
        $this->assertSame([], $this->sent());
        $this->assertNotNull($task->activated_at);
    }

    public function test_a_number_meta_cannot_send_from_is_never_reported_sent(): void
    {
        $this->line->forceFill(['billing_state' => 'unproven'])->save();
        $task = $this->task($this->customer());
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $this->assertSame([], $this->sent());
        $this->assertSame(0, WhatsAppMessage::where('business_id', $this->shop->id)->whereIn('status', [WhatsAppStatus::SENT, WhatsAppStatus::QUEUED])->count());
        $this->assertSame(OrderStatus::READY, $task->fresh()->status, 'والنقلُ تمّ');
    }

    public function test_metas_refusal_fails_the_message_not_the_move(): void
    {
        $task = $this->task($this->customer());
        $this->refuse = ['code' => 131026, 'message' => 'Message undeliverable', 'type' => 'OAuthException'];

        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::READY, $task->fresh()->status);
        $row = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $row->status);
        $this->assertSame('131026', (string) $row->error_code);
        $this->assertNull($row->provider_message_id);
    }

    public function test_a_stopped_queue_keeps_the_message_queued_and_it_leaves_once_the_worker_runs(): void
    {
        Queue::fake();
        $task = $this->task($this->customer());
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $this->assertSame([], $this->sent());
        $this->assertSame([WhatsAppStatus::QUEUED, WhatsAppStatus::QUEUED], $this->notices()->pluck('status')->all());
        Queue::assertPushedOn('whatsapp', SendWhatsAppMessage::class);
        $this->assertSame(OrderStatus::READY, $task->fresh()->status);

        // يعود العامل — فتخرج كما هي، مرّةً لكلٍّ
        foreach ($this->notices() as $row) {
            (new SendWhatsAppMessage($row->id))->handle();
            (new SendWhatsAppMessage($row->id))->handle();
        }
        $this->assertSame(['ribbon_order_confirmed/ar', 'ribbon_order_ready/ar'], $this->templates());
    }

    public function test_a_switched_off_event_sends_nothing(): void
    {
        MarketingSettings::save($this->shop->id, 'whatsapp', ['wa_on_ready' => '0']);
        $task = $this->task($this->customer());
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $this->assertSame(['ribbon_order_confirmed/ar'], $this->templates());
        $this->assertSame(0, WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_READY)->count());
    }

    public function test_a_guided_whatsapp_order_is_not_confirmed_twice(): void
    {
        $customer = $this->customer();
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $customer, ['issued_at' => '2027-03-10'], [
            ['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 25, 'discount' => 0],
        ]));
        CustomerInvoicePreparations::save($invoice, [
            'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id, 'scheduled_for' => '2027-03-10 16:00',
            'recipient_name' => 'مريم', 'recipient_phone' => '91234567',
        ], $this->owner->id);
        $chat = WhatsAppChat::create([
            'business_id' => $this->shop->id, 'phone_number_id' => 'PN-RIBBON', 'source_mode' => WhatsAppMode::BUSINESS_OWN, 'customer_phone' => '96891234567',
        ]);
        WhatsAppOrderSession::create([
            'business_id' => $this->shop->id, 'chat_id' => $chat->id, 'customer_phone' => '96891234567', 'language' => 'ar',
            'state' => WhatsAppOrderSession::AWAITING_PAYMENT, 'customer_invoice_id' => $invoice->id,
            'cart' => [], 'details' => [], 'context' => [],
        ]);
        CustomerPayments::record($this->shop->id, $customer, (float) $invoice->total, ['method' => 'Paymob', 'occurred_at' => '2027-03-10'], [$invoice->id => (float) $invoice->total]);
        [$task] = CustomerInvoicePreparations::activate($invoice->id, CustomerInvoicePreparations::FROM_PAYMOB);

        // تأكيدُه رسالةُ الطلب الآليّ نفسُه — لا قالبُ التأكيد فوقها؛ والجاهزيّةُ تصل
        $this->assertSame(0, WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_CONFIRMED)->count());
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        $this->assertContains('ribbon_order_ready/ar', $this->templates());
    }

    /* ═════════════ ٤ · السجلُّ والمحادثاتُ وحالُ التسليم ═════════════ */

    public function test_the_message_is_in_the_log_and_the_inbox_and_takes_delivered_then_read(): void
    {
        $task = $this->task($this->customer());
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        $ready = WhatsAppMessage::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_READY)->sole();

        // السجلّ: الحدثُ والفاتورةُ والحال
        $rows = $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.log'))->assertOk()->viewData('page')['props']['rows'];
        $row = collect($rows)->firstWhere('id', $ready->id);
        $this->assertNotNull($row, 'رسالةُ المهمّة في السجلّ');
        $this->assertSame(WhatsAppEvent::label(WhatsAppEvent::ORDER_READY), $row['event']);
        $this->assertStringContainsString((string) $task->invoice->number, $row['subject']['label']);

        // والمحادثات: الزبونُ لم يكتب بعد، فتظهر من رسائله الخارجة إليه
        $chats = $this->actingAs($this->owner)->get(route('admin.whatsappInbox.index'))->assertOk()->viewData('page')['props']['chats'];
        $this->assertCount(1, $chats, 'محادثةٌ واحدةٌ لزبونٍ واحد — لا محادثاتٌ وهميّة');

        // وحالُ التسليم من ميتا: وصلت ثمّ قُرئت
        $this->metaSays((string) $ready->provider_message_id, 'delivered');
        $this->assertSame(WhatsAppStatus::DELIVERED, $ready->fresh()->status);
        $this->metaSays((string) $ready->provider_message_id, 'read');
        $this->assertSame(WhatsAppStatus::READ, $ready->fresh()->status);

        // وتُفتح المحادثةُ من الرسالة: الإشعاران فيها بحالهما ورقمِ الفاتورة
        $this->actingAs($this->owner)->get(route('admin.whatsappInbox.open', $ready->id))->assertRedirect();
        $chatId = WhatsAppChat::where('business_id', $this->shop->id)->sole()->id;
        $thread = $this->actingAs($this->owner)->get(route('admin.whatsappInbox.index', ['c' => $chatId]))->assertOk()->viewData('page')['props']['thread'];
        $notices = collect($thread['messages'])->where('kind', 'notice')->keyBy('id');
        $this->assertSame(WhatsAppStatus::READ, $notices[$ready->id]['status']);
        $this->assertSame('invoice', $notices[$ready->id]['subject_kind']);
        $this->assertStringContainsString((string) $task->invoice->number, $notices[$ready->id]['subject']['label']);
        $this->assertCount(2, $notices, 'التأكيدُ والجاهزيّة');
    }

    public function test_a_failed_or_skipped_message_shows_its_reason_and_never_reads_as_delivered(): void
    {
        WhatsAppTemplateMapping::where('business_id', $this->shop->id)->where('event_type', WhatsAppEvent::ORDER_OUT_FOR_DELIVERY)->update(['meta_status' => 'REJECTED']);
        $task = $this->task($this->customer());
        $this->refuse = ['code' => 131026, 'message' => 'Message undeliverable', 'type' => 'OAuthException'];
        $this->moveTask($this->staff, $task, OrderStatus::READY)->assertSessionHasNoErrors();
        $this->refuse = null;
        $this->moveTask($this->staff, $task, OrderStatus::OUT_FOR_DELIVERY)->assertSessionHasNoErrors();

        $failed = WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_READY)->sole();
        $skipped = WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_OUT_FOR_DELIVERY)->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $failed->status);
        $this->assertNull($failed->delivered_at);
        $this->assertSame(WhatsAppStatus::SKIPPED, $skipped->status);

        $rows = collect($this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.log'))->assertOk()->viewData('page')['props']['rows'])->keyBy('id');
        $this->assertSame(WhatsAppStatus::FAILED, $rows[$failed->id]['status']);
        $this->assertNotEmpty($rows[$failed->id]['error'], 'سببُ الفشل مكتوب');
        $this->assertSame(WhatsAppStatus::SKIPPED, $rows[$skipped->id]['status']);
        $this->assertNotEmpty($rows[$skipped->id]['reason'], 'وسببُ التخطّي مكتوب');

        // والمتخطّى لم يخرج — فلا يظهر في المحادثة كأنّه أُرسل
        WhatsAppMessage::whereKey($skipped->id)->update(['recipient_phone' => '96891234567', 'sender_phone_number_id' => 'PN-RIBBON']);
        $this->actingAs($this->owner)->get(route('admin.whatsappInbox.open', $failed->id))->assertRedirect();
        $chatId = WhatsAppChat::where('business_id', $this->shop->id)->sole()->id;
        $thread = $this->actingAs($this->owner)->get(route('admin.whatsappInbox.index', ['c' => $chatId]))->assertOk()->viewData('page')['props']['thread'];
        $ids = collect($thread['messages'])->where('kind', 'notice')->pluck('status', 'id');
        $this->assertSame(WhatsAppStatus::FAILED, $ids[$failed->id], 'الفاشلُ يظهر فاشلًا');
        $this->assertArrayNotHasKey($skipped->id, $ids->all());
    }

    /* ═════════════ ٥ · متجرٌ آخر ═════════════ */

    public function test_another_shop_with_invoice_tasks_and_its_own_number_is_unchanged(): void
    {
        [$neighbour, , $branch] = $this->openShop('NEIGHBOUR', 'PN-OTHER', 'WABA-OTHER');
        $boss = $this->user($neighbour, 'الجار', 'admin');
        $task = $this->task($this->customer('ar', '91234567', $neighbour), $neighbour, $branch);

        $this->moveTask($boss, $task, OrderStatus::READY)->assertSessionHasNoErrors();

        $this->assertSame(0, WhatsAppMessage::where('business_id', $neighbour->id)->count(), 'لم يُضف إلى القائمة — فلا تتغيّر رسائلُه');
        $this->assertSame([], $this->sent());
        $this->assertNull(WhatsAppEvent::forBusinessTransition($neighbour->id, OrderStatus::PENDING, OrderStatus::PREPARING), 'وقفزةُ التأكيد لـRIBBON وحده');
    }
}
