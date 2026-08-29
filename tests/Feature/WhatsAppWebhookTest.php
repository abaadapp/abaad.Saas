<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إشعارات ميتا — بابٌ عامّ على الإنترنت.
 *
 * وأخطر ما فيه أنّه لا جلسةَ له: من يعرف عنوانه يستطيع أن يُرسل إليه ما شاء.
 * فالحارس توقيعٌ، والمتجر يُستنتج ممّا ربطناه نحن، ولا يُقرأ من الحمولة
 * معرّفُ متجرٍ ولا معرّف طلب.
 *
 * ولا يكتب هذا الباب في `orders` حرفًا: «سُلّمت الرسالة» ليست «سُلّم الورد».
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $shared;

    private WhatsAppMessage $message;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret', 'whatsapp.verify_token' => 'test-verify-token']);

        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->shared = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->order = Order::create([
            'business_id' => $business->id,
            'branch_id' => Branch::where('business_id', $business->id)->value('id'),
            'number' => 'INV-1', 'status' => OrderStatus::READY, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);

        $this->message = WhatsAppMessage::create([
            'business_id' => $business->id,
            'order_id' => $this->order->id,
            'whatsapp_connection_id' => $this->shared->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'provider_message_id' => 'wamid.ONE',
            'dedupe_key' => 'k-1',
            'status' => WhatsAppStatus::SENT,
            'quota_consumed' => true,
        ]);
    }

    /** حمولةُ حالةٍ موقَّعة كما توقّعها ميتا */
    private function hook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

        return $this->call(
            'POST',
            route('webhooks.whatsapp'),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature],
            $body,
        );
    }

    private function statusPayload(string $status, string $id = 'wamid.ONE', string $phoneId = 'ABAAD-PN'): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) now()->timestamp]],
        ]]]]]];
    }

    /* ------------------------------- التسجيل ------------------------------- */

    public function test_the_verification_challenge_is_echoed_back(): void
    {
        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => '1234567890',
        ]))->assertOk()->assertSee('1234567890');
    }

    public function test_a_wrong_verify_token_is_refused(): void
    {
        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'not-the-token',
            'hub_challenge' => '1234567890',
        ]))->assertForbidden();
    }

    /** وبلا كلمةٍ مضبوطة يُرفض التسجيل — الأمان لا يُفتح بغياب إعداد */
    public function test_with_no_verify_token_configured_nothing_is_accepted(): void
    {
        config(['whatsapp.verify_token' => '']);

        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => 'x',
        ]))->assertForbidden();
    }

    /* ------------------------------- التوقيع ------------------------------- */

    public function test_a_valid_signature_is_accepted(): void
    {
        $this->hook($this->statusPayload('delivered'))->assertOk();

        $this->assertSame(WhatsAppStatus::DELIVERED, $this->message->fresh()->status);
    }

    public function test_an_invalid_signature_changes_nothing(): void
    {
        $this->hook($this->statusPayload('delivered'), 'sha256=deadbeef')->assertForbidden();

        $this->assertSame(WhatsAppStatus::SENT, $this->message->fresh()->status);
    }

    public function test_a_missing_signature_changes_nothing(): void
    {
        $body = json_encode($this->statusPayload('delivered'));

        $this->call('POST', route('webhooks.whatsapp'), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();

        $this->assertSame(WhatsAppStatus::SENT, $this->message->fresh()->status);
    }

    /* ------------------------------- الحالات ------------------------------- */

    public function test_sent_delivered_and_read_are_recorded(): void
    {
        $this->hook($this->statusPayload('sent'))->assertOk();
        $this->assertNotNull($this->message->fresh()->sent_at);

        $this->hook($this->statusPayload('delivered'))->assertOk();
        $this->assertSame(WhatsAppStatus::DELIVERED, $this->message->fresh()->status);

        $this->hook($this->statusPayload('read'))->assertOk();
        $this->assertSame(WhatsAppStatus::READ, $this->message->fresh()->status);
    }

    public function test_a_failure_is_recorded_with_its_code(): void
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'ABAAD-PN'],
            'statuses' => [[
                'id' => 'wamid.ONE', 'status' => 'failed', 'timestamp' => (string) now()->timestamp,
                'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
            ]],
        ]]]]]];

        $this->hook($payload)->assertOk();

        $fresh = $this->message->fresh();
        $this->assertSame(WhatsAppStatus::FAILED, $fresh->status);
        $this->assertSame('131047', $fresh->error_code);
        $this->assertNotNull($fresh->failed_at);
    }

    /**
     * الحال لا ترجع إلى الوراء.
     *
     * ميتا لا تضمن ترتيب الإشعارات: «قُرئت» قد تصل قبل «سُلّمت». وبلا هذا
     * الترتيب تقول الشاشة «أُرسلت» عن رسالةٍ قرأها صاحبها.
     */
    public function test_a_late_delivered_does_not_undo_read(): void
    {
        $this->hook($this->statusPayload('read'))->assertOk();
        $this->hook($this->statusPayload('delivered'))->assertOk();

        $fresh = $this->message->fresh();
        $this->assertSame(WhatsAppStatus::READ, $fresh->status);
        // والزمن يُقيَّد على كلّ حال: الترتيب في الحالة لا في التواريخ
        $this->assertNotNull($fresh->delivered_at);
    }

    /** والإشعار المكرّر لا يُحدث شيئًا جديدًا */
    public function test_a_duplicate_webhook_is_harmless(): void
    {
        $this->hook($this->statusPayload('delivered'))->assertOk();
        $first = $this->message->fresh()->delivered_at;

        $this->hook($this->statusPayload('delivered'))->assertOk();

        $this->assertSame(WhatsAppStatus::DELIVERED, $this->message->fresh()->status);
        $this->assertEquals($first, $this->message->fresh()->delivered_at);
    }

    /* -------------------------- حال الرسالة ≠ حال الطلب -------------------------- */

    /**
     * «سُلّمت الرسالة» لا تُقفل الطلب.
     *
     * ولو فعلت لَأقفل إشعارٌ من ميتا طلبًا لم يخرج أحدٌ لتسليمه — ولا يُكتشف
     * ذلك إلا حين يتّصل الزبون سائلًا أين وردُه.
     */
    public function test_a_delivered_message_never_moves_the_order(): void
    {
        $this->hook($this->statusPayload('delivered'))->assertOk();
        $this->hook($this->statusPayload('read'))->assertOk();

        $this->assertSame(OrderStatus::READY, $this->order->fresh()->status);
    }

    /* -------------------------------- العزل -------------------------------- */

    /** رقمٌ لا نعرفه: لا يُكتب منه شيء */
    public function test_an_unknown_phone_number_id_is_ignored(): void
    {
        $this->hook($this->statusPayload('delivered', phoneId: 'SOMEONE-ELSE'))->assertOk();

        $this->assertSame(WhatsAppStatus::SENT, $this->message->fresh()->status);
    }

    /**
     * ورسالةٌ تخصّ وصلةً أخرى لا تُعدَّل من هذه.
     *
     * المعرّف وحده كان يكفي، لكنّ إضافة الوصلة تعني أنّ من سرق السرّ لا
     * يستطيع أن يُعدّل رسالة متجرٍ من وصلة متجرٍ آخر.
     */
    public function test_a_message_from_another_connection_is_not_touched(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        $ownConnection = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $other->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-9876543210',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        // إشعارٌ يصل على رقم المحلّ ويحمل معرّف رسالةٍ خرجت من رقم أبعاد
        $this->hook($this->statusPayload('delivered', 'wamid.ONE', 'SHOP-PN'))->assertOk();

        $this->assertSame(WhatsAppStatus::SENT, $this->message->fresh()->status);
        $this->assertNotNull($ownConnection->id);
    }

    /** وإشعار وصلة المحلّ يُعدّل رسالة المحلّ */
    public function test_an_own_connection_webhook_updates_its_own_message(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        $connection = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $other->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-9876543210',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $message = WhatsAppMessage::create([
            'business_id' => $other->id,
            'whatsapp_connection_id' => $connection->id,
            'source_mode' => WhatsAppMode::BUSINESS_OWN,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'provider_message_id' => 'wamid.TWO',
            'dedupe_key' => 'k-2',
            'status' => WhatsAppStatus::SENT,
        ]);

        $this->hook($this->statusPayload('delivered', 'wamid.TWO', 'SHOP-PN'))->assertOk();

        $this->assertSame(WhatsAppStatus::DELIVERED, $message->fresh()->status);
        $this->assertSame(WhatsAppStatus::SENT, $this->message->fresh()->status);
    }

    /** ورسائل الزبائن الواردة لا تُخزَّن — لا صندوق وارد في هذه النسخة */
    public function test_inbound_customer_messages_are_not_stored(): void
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'ABAAD-PN'],
            'messages' => [[
                'from' => '96899999999', 'id' => 'wamid.IN',
                'text' => ['body' => 'أين طلبي؟'], 'type' => 'text',
            ]],
        ]]]]]];

        $before = WhatsAppMessage::count();
        $this->hook($payload)->assertOk();

        $this->assertSame($before, WhatsAppMessage::count());
        $this->assertSame(0, WhatsAppMessage::where('direction', 'inbound')->count());
    }

    /** وحمولةٌ مشوّهة لا تُسقط الباب */
    public function test_a_malformed_payload_is_answered_calmly(): void
    {
        $this->hook(['entry' => 'not-an-array'])->assertOk();
        $this->hook([])->assertOk();
    }
}
