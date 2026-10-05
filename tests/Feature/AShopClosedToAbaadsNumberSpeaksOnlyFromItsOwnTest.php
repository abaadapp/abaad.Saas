<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\WhatsAppController;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Support\OrderStatus;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * متجرٌ أُغلق عنه رقمُ أبعاد المشترك — يُرسل من رقمه وحده، أو لا يُرسل.
 *
 * ═══ ما كان ═══
 *
 * كلُّ متجرٍ لم يربط رقمه في الوضع `abaad_shared`، و`WhatsAppConnections::
 * resolve` تُرجع له وصلةَ المنصّة. فإشعاراتُ طلباته وتذكيراتُ فواتيره تخرج
 * من رقم أبعاد. ولا مقبضَ يفصل متجرًا واحدًا عنه: مفتاحُ المنصّة يُطفئ
 * المشترك عن الكلّ.
 *
 * ═══ ما يُحرس ═══
 *
 *   · لا وصلةَ منصّةٍ تُرجَع له من المحلّل، ولا من الوظيفة وقتَ التنفيذ —
 *     ولا لرسالةٍ حُجزت على رقم أبعاد قبل الإغلاق، ولا في إعادة المحاولة.
 *   · ولا يفتحه بطلبٍ يكتبه بيده، ولا يُرجَع إليه حين يفصل رقمه.
 *   · وواردُ رقم أبعاد لا يُكتب في دفتره.
 *   · ويربط رقمه بالتسجيل المدمج القائم، فيُرسل منه وحده، وواردُه له.
 *   · ورقمُ أبعاد باقٍ كما هو، وغيرُه من المتاجر على حالها.
 *
 * والأسماءُ لاتينيّة: معرّفاتُ PostgreSQL لا تُصفَّر بين الاختبارات.
 */
class AShopClosedToAbaadsNumberSpeaksOnlyFromItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $abaad;

    private Business $closed;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
        ]);

        $this->platform('whatsapp_enabled', '1');
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '100');

        $this->abaad = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'waba_id' => 'ABAAD-WABA',
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        [$this->closed, $this->owner, $this->customer] = $this->shop('Ribbon Test', 'o@abaad.om', '91234567');
        $this->closed->update(['whatsapp_shared_allowed' => false]);
        $this->closed->refresh();
    }

    /* ------------------------------- التهيئة ------------------------------- */

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    /** @return array{0: Business, 1: User, 2: Customer} */
    private function shop(string $name, string $email, string $phone): array
    {
        $business = Business::create([
            'name' => $name, 'type' => 'Flowers', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true,
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'Main']);

        $user = User::create([
            'business_id' => $business->id, 'name' => 'Owner', 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $customer = Customer::create(['business_id' => $business->id, 'name' => 'Customer', 'phone' => $phone]);

        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $business->id, 'key' => $key], ['value' => '1']);
        }

        return [$business, $user, $customer];
    }

    private function order(Business $business, Customer $customer): Order
    {
        return Order::create([
            'business_id' => $business->id,
            'branch_id' => Branch::where('business_id', $business->id)->value('id'),
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    /**
     * ميتا كلُّها — التسجيلُ المدمج والإرسال — في نداءٍ واحد.
     *
     * و`Http::fake` تُضيف ولا تُبدّل، فلا تُنادى مرّتين في اختبار.
     */
    private function meta(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200),
            '*/debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
                ],
            ]], 200),
            '*/WABA-1/phone_numbers*' => Http::response(['data' => [[
                'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066',
                'verified_name' => 'RIBBON', 'platform_type' => 'CLOUD_API',
            ]]], 200),
            '*/WABA-1/subscribed_apps' => Http::response(['data' => []], 200),
            '*/WABA-1?*' => Http::response(['id' => 'WABA-1', 'owner_business_info' => ['id' => 'MB-9']], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            '*' => Http::response(['id' => 'WABA-1'], 200),
        ]);
    }

    /** المتجرُ يربط رقمه بالتسجيل المدمج القائم — لا بمسارٍ خاصّ به */
    private function connectOwnNumber(): WhatsAppConnection
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789',
            'waba_id' => 'WABA-1',
            'phone_number_id' => 'PN-1',
        ])->assertSessionHasNoErrors();

        return WhatsAppConnection::where('business_id', $this->closed->id)->latest('id')->firstOrFail();
    }

    /** ما نُودي به ميتا — عناوينُ الرسائل وحدها */
    private function sentFrom(): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn (HttpRequest $r) => $r->url())
            ->values()->all();
    }

    /** @param array<string, mixed> $value */
    private function webhook(array $value)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'X', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body);
    }

    private function inbound(string $phoneNumberId, string $from, string $wamid): array
    {
        return [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'messages' => [[
                'id' => $wamid, 'from' => $from, 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'Hello'],
            ]],
        ];
    }

    /* ═════════════ ١ · المحلّلُ لا يُرجع رقمَ أبعاد ═════════════ */

    public function test_the_resolver_never_hands_the_closed_shop_abaads_connection(): void
    {
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->closed->whatsapp_mode, 'صفُّه بقي كما كان');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, WhatsAppFeature::effectiveMode($this->closed));
        $this->assertNull(WhatsAppConnections::resolve($this->closed));

        // ولو كُتب في صفّه وضعُ المشترك صراحةً — الإغلاقُ أقوى من الصفّ
        $this->closed->forceFill(['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED])->save();
        $this->assertNull(WhatsAppConnections::resolve($this->closed->fresh()));
    }

    /* ═════════════ ٢ · غيرُ مربوطٍ — وتقوله الشاشة ═════════════ */

    public function test_without_its_own_number_the_shop_reads_disconnected_and_not_abaad(): void
    {
        $this->actingAs($this->owner);
        $view = WhatsAppController::view($this->closed);

        $this->assertFalse($view['shared_allowed']);
        $this->assertFalse($view['shared_active'], 'رقمُ أبعاد لا يُعرض له متاحًا');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $view['effective_mode']);
        $this->assertSame(WhatsAppMode::label(WhatsAppMode::BUSINESS_OWN), $view['sending_via']);
        $this->assertNull($view['own_connection']);
        $this->assertNull($view['usage'], 'لا حصّةَ من رقم أبعاد تُعرض له');
        $this->assertFalse($view['readiness']['ready']);

        $step = collect($view['readiness']['steps'])->firstWhere('key', 'own');
        $this->assertNotNull($step, 'خطوتُه ربطُ رقمه — لا «الرقم المشترك جاهز»');
        $this->assertFalse($step['done']);
        $this->assertNull(collect($view['readiness']['steps'])->firstWhere('key', 'shared'));
        $this->assertStringNotContainsString('رقم أبعاد', (string) $step['fix']);

        // والشاشةُ تُفتح وبابُ الربط فيها
        $this->get(route('admin.integrations.whatsapp'))->assertOk();
    }

    /* ═════════════ ٣ · إشعارُ الطلب لا يخرج من رقم أبعاد ═════════════ */

    public function test_an_order_notice_does_not_leave_through_abaad(): void
    {
        $this->meta();

        $this->order($this->closed, $this->customer)->update(['status' => OrderStatus::READY]);

        $this->assertSame([], $this->sentFrom());

        $row = WhatsAppMessage::where('business_id', $this->closed->id)->firstOrFail();
        $this->assertSame(WhatsAppStatus::SKIPPED, $row->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_CONNECTION, $row->error_code);
        $this->assertNull($row->whatsapp_connection_id);
        $this->assertSame(0, WhatsAppQuota::used($this->closed->fresh()), 'ولا تُخصم من حصّةٍ مشتركة');
    }

    /* ═════════════ ٤ · ولا تذكيرُ الفاتورة ═════════════ */

    public function test_an_invoice_reminder_does_not_leave_through_abaad(): void
    {
        $this->meta();

        $invoice = CustomerInvoice::create([
            'business_id' => $this->closed->id, 'customer_id' => $this->customer->id, 'number' => 'CINV-1',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(2)->toDateString(),
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0, 'total' => 10, 'customer_name' => 'Smith',
        ]);

        WhatsAppAutomation::handleInvoice($invoice, WhatsAppEvent::INVOICE_DUE_SOON);

        $this->assertSame([], $this->sentFrom());

        $row = WhatsAppMessage::where('customer_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(WhatsAppStatus::SKIPPED, $row->status);
        $this->assertNull($row->whatsapp_connection_id);
    }

    /* ═════════════ ٥ · ولا يفتحه بطلبٍ يكتبه بيده ═════════════ */

    public function test_the_shop_cannot_switch_itself_back_to_abaad(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::ABAAD_SHARED])
            ->assertSessionHasErrors('mode');

        $this->assertNull(WhatsAppConnections::resolve($this->closed->fresh()));
    }

    /**
     * ولا ينتزع رقمَ أبعاد بلصق معرّفه في الربط اليدويّ.
     */
    public function test_the_shop_cannot_claim_abaads_number_as_its_own(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), [
            'phone_number_id' => 'ABAAD-PN',
            'waba_id' => 'ABAAD-WABA',
            'access_token' => 'stolen-or-guessed-token-0123456789',
        ])->assertSessionHasErrors('phone_number_id');

        $this->assertSame(0, WhatsAppConnection::where('business_id', $this->closed->id)->count());
        $this->assertSame(WhatsAppMode::OWNER_PLATFORM, $this->abaad->fresh()->owner_type);
        $this->assertNull($this->abaad->fresh()->business_id);
    }

    /* ═════════════ ٦ · ولا احتياط: فصلُ رقمه لا يُرجعه إلى أبعاد ═════════════ */

    public function test_disconnecting_its_own_number_does_not_fall_back_to_abaad(): void
    {
        $this->meta();
        $this->connectOwnNumber();
        $this->closed->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);

        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'))->assertRedirect();

        $closed = $this->closed->fresh();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $closed->whatsapp_mode, 'لا يُكتب له وضعُ المشترك');
        $this->assertNull(WhatsAppConnections::resolve($closed));

        $this->order($closed, $this->customer)->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->sentFrom());
    }

    /* ═════════════ ٧ · ٨ · رسالةٌ حُجزت قبل الإغلاق لا تخرج بعده ═════════════ */

    /**
     * رسالةٌ دخلت الطابور على رقم أبعاد ثمّ أُغلق — لا تخرج منه، ولا في
     * إعادة المحاولة. والوصلةُ صالحةٌ عند ميتا: هذا ما كانت الوظيفة تكتفي به.
     */
    public function test_a_message_queued_on_abaad_before_the_cutover_never_leaves_after_it(): void
    {
        $this->meta();
        $this->closed->update(['whatsapp_shared_allowed' => true]);

        $message = WhatsAppMessage::create([
            'business_id' => $this->closed->id,
            'order_id' => $this->order($this->closed, $this->customer)->id,
            'customer_id' => $this->customer->id,
            'whatsapp_connection_id' => $this->abaad->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'template_name' => 'order_ready',
            'dedupe_key' => 'pre-cutover',
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
        ]);

        // الإغلاقُ يقع والرسالةُ في الطابور
        $this->closed->update(['whatsapp_shared_allowed' => false]);

        (new SendWhatsAppMessage($message->id))->handle();
        // وإعادةُ الطابور لها لا تجد بابًا آخر
        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame([], $this->sentFrom());

        $message->refresh();
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_CONNECTION, $message->error_code);
        $this->assertFalse((bool) $message->quota_consumed);
        // والتاريخُ يبقى صادقًا: حُجزت على رقم أبعاد، ولا يُكتب غيرُ ذلك
        $this->assertSame($this->abaad->id, $message->whatsapp_connection_id);
    }

    /**
     * ولا تُحوَّل إلى رقمه حين يربطه: قُرّرت بقالب أبعاد وحصّته — لا تعبر.
     */
    public function test_a_pre_cutover_message_is_not_rerouted_through_the_newly_connected_number(): void
    {
        $this->meta();

        $message = WhatsAppMessage::create([
            'business_id' => $this->closed->id,
            'order_id' => $this->order($this->closed, $this->customer)->id,
            'customer_id' => $this->customer->id,
            'whatsapp_connection_id' => $this->abaad->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'template_name' => 'order_ready',
            'dedupe_key' => 'pre-cutover-2',
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
        ]);

        $this->connectOwnNumber();

        (new SendWhatsAppMessage($message->id))->handle();

        $this->assertSame([], $this->sentFrom());
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->fresh()->status);
    }

    /**
     * والوصلةُ المحفوظة في الصفّ لا تُصدَّق لأنّها صالحة.
     *
     * صفٌّ يشير إلى وصلةٍ صالحةٍ ليست وصلتَه — وصلةِ متجرٍ آخر هنا — لا
     * يخرج منها: يخرج من وصلته هو كما يقولها المحلّل الآن. وهذا ما كانت
     * الوظيفةُ تتجاوزه: تسأل «أصالحة؟» ولا تسأل «أهي له؟».
     */
    public function test_a_queued_row_never_leaves_through_a_connection_that_is_not_its_shops_now(): void
    {
        $this->meta();
        $mine = $this->connectOwnNumber();

        [$other] = $this->shop('Iris Own', 'i@abaad.om', '96667777');
        $foreign = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $other->id,
            'phone_number_id' => 'IRIS-PN', 'access_token' => 'iris-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);

        $message = WhatsAppMessage::create([
            'business_id' => $this->closed->id,
            'order_id' => $this->order($this->closed, $this->customer)->id,
            'customer_id' => $this->customer->id,
            'whatsapp_connection_id' => $foreign->id,
            'source_mode' => WhatsAppMode::BUSINESS_OWN,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'template_name' => 'order_ready',
            'dedupe_key' => 'foreign-row',
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
        ]);

        (new SendWhatsAppMessage($message->id))->handle();

        $from = $this->sentFrom();
        $this->assertCount(1, $from);
        $this->assertStringContainsString('/PN-1/messages', $from[0]);
        $this->assertSame($mine->id, $message->fresh()->whatsapp_connection_id);
    }

    /* ═════════════ ٩ · واردُ رقم أبعاد لا يُكتب في دفتره ═════════════ */

    public function test_inbound_on_abaads_number_is_never_filed_under_the_closed_shop(): void
    {
        $this->meta();

        // زبونُ المحلّ يردّ على إشعارٍ قديمٍ خرج من رقم أبعاد
        $this->webhook($this->inbound('ABAAD-PN', '96891234567', 'wamid.IN-ABAAD'))->assertOk();

        $this->assertSame(0, WhatsAppInboundMessage::where('business_id', $this->closed->id)->count());
        $this->assertSame(0, SupportConversation::where('business_id', $this->closed->id)->count());
        $this->assertSame([], $this->sentFrom(), 'ولا يُرَدّ عليه من رقمه ولا من رقم أبعاد');
    }

    /* ═════════════ ١٠ · ١١ · غيرُه على حاله ═════════════ */

    public function test_other_shops_keep_abaads_number_and_their_own(): void
    {
        $this->meta();

        [$shared, , $sharedCustomer] = $this->shop('Rose Shared', 's@abaad.om', '92223333');
        [$owning, , $owningCustomer] = $this->shop('Lily Own', 'l@abaad.om', '93334444');

        $own = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $owning->id,
            'phone_number_id' => 'LILY-PN', 'display_phone_number' => '+968 9333 4444',
            'access_token' => 'lily-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedBusinessDefaults($owning->id);
        $owning->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);

        $this->assertSame($this->abaad->id, WhatsAppConnections::resolve($shared)?->id);
        $this->assertSame($own->id, WhatsAppConnections::resolve($owning->fresh())?->id);

        $this->order($shared, $sharedCustomer)->update(['status' => OrderStatus::READY]);
        $this->order($owning->fresh(), $owningCustomer)->update(['status' => OrderStatus::READY]);

        $from = $this->sentFrom();
        $this->assertCount(2, $from);
        $this->assertStringContainsString('/ABAAD-PN/messages', $from[0], 'المشترك يبقى على رقم أبعاد');
        $this->assertStringContainsString('/LILY-PN/messages', $from[1], 'ومن ربط رقمه يبقى عليه');
    }

    /* ═════════════ ١٢ · ١٣ · ١٤ · يربط رقمه — فيُرسل منه وحده ═════════════ */

    public function test_the_closed_shop_connects_its_own_number_through_the_normal_flow(): void
    {
        $this->meta();

        $connection = $this->connectOwnNumber();

        $this->assertSame(WhatsAppMode::OWNER_BUSINESS, $connection->owner_type);
        $this->assertSame('PN-1', $connection->phone_number_id);
        $this->assertTrue($connection->isUsable());

        // بلا تبديل وضعٍ يدويّ ولا نشرٍ — الربطُ وحده يكفي
        $closed = $this->closed->fresh();
        $this->assertSame($connection->id, WhatsAppConnections::resolve($closed)?->id);
        $this->assertTrue(WhatsAppController::view($closed)['readiness']['steps'][3]['done']);
    }

    public function test_once_connected_every_send_uses_its_own_number_only(): void
    {
        $this->meta();
        $connection = $this->connectOwnNumber();
        $closed = $this->closed->fresh();

        $this->order($closed, $this->customer)->update(['status' => OrderStatus::READY]);

        $invoice = CustomerInvoice::create([
            'business_id' => $closed->id, 'customer_id' => $this->customer->id, 'number' => 'CINV-2',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(2)->toDateString(),
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0, 'total' => 10, 'customer_name' => 'Smith',
        ]);
        WhatsAppAutomation::handleInvoice($invoice, WhatsAppEvent::INVOICE_DUE_SOON);

        $from = $this->sentFrom();
        $this->assertCount(2, $from);
        foreach ($from as $url) {
            $this->assertStringContainsString('/PN-1/messages', $url);
            $this->assertStringNotContainsString('ABAAD-PN', $url);
        }

        $this->assertSame(
            [$connection->id],
            WhatsAppMessage::where('business_id', $closed->id)->pluck('whatsapp_connection_id')->unique()->values()->all(),
        );
        $this->assertSame(0, WhatsAppQuota::used($closed), 'رقمُه على حسابه — لا حصّةَ من أبعاد');
    }

    public function test_inbound_on_its_own_number_is_filed_under_it_alone(): void
    {
        $this->meta();
        $this->connectOwnNumber();

        $this->webhook($this->inbound('PN-1', '96899887766', 'wamid.IN-OWN'))->assertOk();

        $row = WhatsAppInboundMessage::where('wamid', 'wamid.IN-OWN')->firstOrFail();
        $this->assertSame($this->closed->id, $row->business_id);
    }

    /* ═════════════ ١٥ · ١٦ · المقبضُ لمدير المنصّة — ورقمُ أبعاد باقٍ ═════════════ */

    public function test_only_the_platform_closes_the_door_and_abaads_number_is_untouched(): void
    {
        [$other, $otherOwner] = $this->shop('Tulip Shared', 't@abaad.om', '94445555');

        // التاجر لا يفتح بابه بنفسه — المقبضُ في شاشة المنصّة
        $this->actingAs($this->owner)
            ->put(route('super-admin.businesses.whatsapp.update', $this->closed->id), ['whatsapp_shared_allowed' => true]);
        $this->assertFalse($this->closed->fresh()->whatsapp_shared_allowed);

        $super = User::create([
            'business_id' => null, 'name' => 'Platform', 'email' => 'root@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        // ومديرُ المنصّة يُغلقه لمتجرٍ واحد — ولا يمسّ غيره
        $this->actingAs($super)
            ->put(route('super-admin.businesses.whatsapp.update', $other->id), ['whatsapp_shared_allowed' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($other->fresh()->whatsapp_shared_allowed);
        $this->assertNull(WhatsAppConnections::resolve($other->fresh()));

        // ورقمُ أبعاد كما كان: نشطٌ، للمنصّة، برمزه ومعرّفاته
        $abaad = $this->abaad->fresh();
        $this->assertSame(WhatsAppConnection::ACTIVE, $abaad->status);
        $this->assertSame(WhatsAppMode::OWNER_PLATFORM, $abaad->owner_type);
        $this->assertSame('ABAAD-PN', $abaad->phone_number_id);
        $this->assertSame('platform-token-value-0123456789', $abaad->access_token);
        $this->assertSame($abaad->id, WhatsAppConnections::platform()?->id);
        $this->assertTrue(WhatsAppFeature::sharedEnabled(), 'ومفتاحُ المنصّة للمشترك لم يُمسّ');

        // ومتجرٌ لم يُغلق عنه على رقم أبعاد
        [$open] = $this->shop('Daisy Shared', 'd@abaad.om', '95556666');
        $this->assertSame($abaad->id, WhatsAppConnections::resolve($open)?->id);
    }
}
