<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\Setting;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\CrmWhatsApp;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رقمٌ واحدٌ يحمل الغرضين — بإذنٍ، وبفاصلٍ يُقاس لا يُحدس.
 *
 * ═══ ما طُلب، ولمَ لم يُنفَّذ بقلب العمود ═══
 *
 * طُلب أن يعمل دفترُ المبيعات على رقم الإشعارات نفسِه. وقلبُ `purpose` إلى
 * `crm_sales` كان يُنفّذ الطلبَ حرفًا ويكسر ثلاثةَ أشياء صمتًا:
 * `WhatsAppConnections::platform()` يرجع `null` فتتوقّف إشعاراتُ طلبات كلّ
 * المتاجر، و`SupportWhatsApp::line()` يرجع `null` فيموت صندوقُ الدعم، وكلُّ
 * زبونةٍ تسأل عن هديّتها تصير صفًّا في دفتر مبيعاتنا.
 *
 * فالوضعُ الجديد يُبقي الغرضَ `notifications` كما هو — ويفتح بابًا ثانيًا
 * على الوصلة نفسِها بإذنٍ مُدار.
 *
 * ═══ والفاصلُ حقيقةٌ عندنا لا تخمين ═══
 *
 * `whatsapp_messages.recipient_phone` تحفظ رقمَ كلّ من أرسلنا إليه إشعارَ
 * طلب. فمن كان فيه زبونُ محلٍّ يردّ على إشعاره، ومن لم يكن فيه ولا يخصّ
 * تاجرًا مسجَّلًا فهو من بدأ هو بالكتابة إلينا.
 *
 * وهذا ما يحرسه أكثرُ ما في هذا الملفّ: أنّ الفاصلَ صفٌّ في قاعدةٍ لا نصٌّ
 * يُقرأ ويُحزر.
 */
class OneNumberMayCarryBothBooksTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $notices;

    private Business $shop;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();

        $this->shop = Business::create(['name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'active']);

        $this->merchant = User::create([
            'name' => 'صاحب المحل', 'email' => 'o@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '96891112222',
        ]);

        $this->notices = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'NOTICES-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'notices-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);
    }

    /* ═══════════════════ المقبضُ مُطفأ — لا شيء يتغيّر ═══════════════════ */

    /**
     * بلا إذن: مجهولٌ يكتب إلى رقم الإشعارات فلا يصير عميلًا.
     *
     * وهذا حارسُ ما كان قبل هذه المرحلة — لئلّا يُفتح البابُ افتراضًا.
     */
    public function test_without_the_permission_a_stranger_on_the_notice_line_is_not_a_lead(): void
    {
        $this->inbound('NOTICES-PN', '96877778888')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'فُتح دفترُ المبيعات على رقم الإشعارات بلا إذن');
        $this->assertSame(0, CrmMessage::count());
    }

    /** والمقبضُ مُطفأ افتراضًا — لا يُورَّث مفتوحًا */
    public function test_the_permission_is_closed_until_it_is_opened(): void
    {
        $this->assertFalse(CrmWhatsApp::shared());
    }

    /** وبلا إذنٍ لا خطَّ مبيعاتٍ أصلًا ولو كان رقمُ الإشعارات يعمل */
    public function test_without_the_permission_there_is_no_sales_line(): void
    {
        $this->assertNull(CrmWhatsApp::line());
        $this->assertFalse(CrmWhatsApp::connected());
    }

    /* ═══════════════════ المقبضُ مُدار ═══════════════════ */

    /** وبالإذن: رقمُ الإشعارات يصير خطَّ المبيعات كذلك */
    public function test_with_the_permission_the_notice_line_carries_sales(): void
    {
        $this->allow();

        $line = CrmWhatsApp::line();

        $this->assertNotNull($line);
        $this->assertSame($this->notices->id, $line->id);
        $this->assertTrue(CrmWhatsApp::sharingNoticeLine());
    }

    /**
     * ومع ذلك تبقى إشعاراتُ الطلبات تخرج منه.
     *
     * وهذا ما كان يكسره قلبُ العمود: `platform()` تشترط `notifications`.
     */
    public function test_order_notices_still_leave_from_that_same_number(): void
    {
        $this->allow();

        $chosen = WhatsAppConnections::platform();

        $this->assertNotNull($chosen, 'توقّفت إشعاراتُ طلبات كلّ المتاجر');
        $this->assertSame($this->notices->id, $chosen->id);
    }

    /** وصندوقُ الدعم يبقى حيًّا على الرقم نفسِه */
    public function test_the_support_line_survives_the_sharing(): void
    {
        $this->allow();

        $line = SupportWhatsApp::line();

        $this->assertNotNull($line, 'مات صندوقُ دعم التجّار');
        $this->assertSame($this->notices->id, $line->id);
    }

    /** والغرضُ في العمود لا يُقلَب — الإذنُ إعدادٌ لا نسخُ وصلة */
    public function test_the_purpose_column_is_not_flipped(): void
    {
        $this->allow();

        $this->assertSame(
            WhatsAppMode::PURPOSE_NOTIFICATIONS,
            $this->notices->fresh()->purpose,
            'قُلب غرضُ الوصلة بدل أن يُفتح بابٌ ثانٍ عليها'
        );
    }

    /**
     * ورمزٌ منتهٍ على رقم الإشعارات يُطفئ خطَّ المبيعات معه.
     *
     * الحالُ تُقاس لا تُفترض: `platform()` تشترط `active` وحدَها، والرمزُ قد
     * ينتهي والصفُّ نشط. ولو قيل «موصول» حينئذٍ لَظنّ موظّفُ المبيعات أنّ
     * ردَّه يخرج — ولا يخرج شيء.
     */
    public function test_an_expired_token_on_the_notice_line_closes_the_sales_line_too(): void
    {
        $this->allow();
        $this->notices->forceFill(['token_expires_at' => now()->subDay()])->save();

        $this->assertNull(CrmWhatsApp::line(), 'خطُّ مبيعاتٍ يُعرض موصولًا برمزٍ منتهٍ');
        $this->assertFalse(CrmWhatsApp::connected());
    }

    /** ووارِدٌ على رقمٍ رمزُه منتهٍ لا يُكتب عميلًا */
    public function test_no_lead_is_written_on_a_line_that_cannot_answer(): void
    {
        $this->allow();
        $this->notices->forceFill(['token_expires_at' => now()->subDay()])->save();

        $this->inbound('NOTICES-PN', '96877778888', 'كم السعر؟')->assertOk();

        $this->assertSame(0, CrmLead::count());
    }

    /* ═══════════════════ الفاصل ═══════════════════ */

    /** غريبٌ لم نراسله قطّ: يُكتب عميلًا محتمَلًا */
    public function test_a_true_stranger_becomes_a_lead(): void
    {
        $this->allow();

        $this->inbound('NOTICES-PN', '96877778888', 'كم سعر النظام؟')->assertOk();

        $lead = CrmLead::first();

        $this->assertNotNull($lead, 'من كتب إلينا أوّلَ مرّة لم يُكتب');
        $this->assertSame('96877778888', $lead->phone);
        $this->assertSame('كم سعر النظام؟', CrmMessage::first()->body);
    }

    /**
     * وزبونُ محلٍّ أرسلنا إليه إشعارَ طلبٍ يومًا: لا يُكتب.
     *
     * وهذا أثقلُ حارسٍ هنا. سقوطُه يعني أنّ «وين الورد؟» تصير صفًّا في دفتر
     * مبيعاتنا، ونصُّها مقروءًا في لوحة المنصّة.
     */
    public function test_a_customer_we_once_notified_never_becomes_a_lead(): void
    {
        $this->allow();
        $this->notified('96877778888');

        $this->inbound('NOTICES-PN', '96877778888', 'وين الورد؟')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'زبونُ محلٍّ صار عميلًا محتمَلًا في دفترنا');
        $this->assertSame(0, CrmMessage::count(), 'نصُّ رسالةِ زبونٍ لمحلِّه صار مقروءًا في لوحة المنصّة');
    }

    /** والرقمُ يُطبَّع قبل المقارنة — صيغتان لرقمٍ واحد لا تفترقان */
    public function test_the_send_log_is_matched_after_normalising_the_number(): void
    {
        $this->allow();
        $this->notified('96877778888');

        /* الوارد بصيغةٍ أخرى لنفس الرقم */
        $this->inbound('NOTICES-PN', '00968 7777 8888', 'وصل؟')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'صيغةٌ أخرى للرقم نفسِه فتحت البابَ');
    }

    /** ورقمُ تاجرٍ مسجَّلٍ عندنا يذهب إلى الدعم لا إلى المبيعات */
    public function test_a_merchant_writing_goes_to_support_not_to_sales(): void
    {
        $this->allow();

        $this->inbound('NOTICES-PN', '96891112222', 'عندي مشكلة')->assertOk();

        $this->assertSame(1, SupportMessage::count(), 'رسالةُ التاجر لم تصل الدعم');
        $this->assertSame(0, CrmLead::count(), 'تاجرٌ يطلب دعمًا صار عميلًا محتمَلًا');
    }

    /** ورقمُ تاجرٍ مكتوبٌ بصيغةٍ أخرى في حسابه يُردُّ كذلك */
    public function test_a_merchant_number_written_differently_is_still_refused(): void
    {
        $this->allow();
        $this->merchant->forceFill(['phone' => '+968 9111 2222'])->save();

        $this->inbound('NOTICES-PN', '96891112222', 'عندي مشكلة')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'رقمُ تاجرٍ بصيغةٍ أخرى صار عميلًا محتمَلًا');
    }

    /**
     * ورقمٌ كتبه تاجران لا يُكتب عميلًا.
     *
     * `SupportWhatsApp::sender` تردُّ المكرَّر `null` لأنّها تحتاج صاحبًا
     * واحدًا بعينه — فلولا شرطٌ أوسعُ هنا لَسقط المكرَّرُ في دفتر المبيعات:
     * لا دعمٌ يقرؤه لأنّه ملتبس، ولا ردٌّ لأنّه ليس عميلًا.
     */
    public function test_a_number_two_merchants_share_is_still_not_a_lead(): void
    {
        $this->allow();

        User::create([
            'name' => 'شريك', 'email' => 'p@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '96891112222',
        ]);

        $this->inbound('NOTICES-PN', '96891112222', 'مرحبا')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'رقمٌ ملتبسٌ بين تاجرين صار عميلًا محتمَلًا');
    }

    /* ═══════════════════ حارسٌ ثانٍ خلف الموزّع ═══════════════════ */

    /** والبابُ يحرس نفسَه: نداءٌ مباشرٌ بلا إذنٍ لا يكتب حرفًا */
    public function test_the_crm_door_refuses_the_notice_line_when_called_directly(): void
    {
        CrmWhatsApp::receive($this->notices, [
            'id' => 'wamid.DIRECT', 'from' => '96877778888',
            'type' => 'text', 'text' => ['body' => 'مرحبا'],
        ]);

        $this->assertSame(0, CrmLead::count(), 'نداءٌ مباشرٌ فتح بابَ المجهول بلا إذن');
    }

    /** ووصلةُ متجرٍ لا تفتح هذا البابَ ولو أُذن — الإذنُ للمنصّة لا لمحلّ */
    public function test_a_shop_connection_never_becomes_a_sales_line(): void
    {
        $this->allow();

        $own = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'business_id' => $this->shop->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);

        $this->inbound('SHOP-PN', '96877778888', 'كم السعر؟')->assertOk();

        $this->assertSame(0, CrmLead::count(), 'وصلةُ متجرٍ صارت خطَّ مبيعاتٍ لأبعاد');
        $this->assertNotSame($own->id, CrmWhatsApp::line()?->id);
    }

    /* ═══════════════════ حالُ التسليم ═══════════════════ */

    /**
     * وحالُ ردِّ مبيعاتٍ خرج من رقم الإشعارات تُكتب في جدولها.
     *
     * وبلا هذا تبقى كلُّ رسالةٍ «أُرسلت» أبدًا — يقرأ موظّفُ المبيعات أنّ
     * رسالتَه خرجت وهي راقدةٌ عند ميتا أو فشلت بعد القبول.
     */
    public function test_delivery_of_a_sales_reply_sent_from_the_notice_line_is_written(): void
    {
        $this->allow();
        $this->inbound('NOTICES-PN', '96877778888', 'مرحبا')->assertOk();

        $lead = CrmLead::firstOrFail();

        $out = CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::OUT,
            'body' => 'أهلًا', 'delivery' => 'sent',
            'external_message_id' => 'wamid.OUT-1',
        ]);

        $this->deliveryEvent('wamid.OUT-1', 'read', 'NOTICES-PN')->assertOk();

        $this->assertSame('read', $out->fresh()->delivery, 'حالُ ردِّ المبيعات بقيت «أُرسلت» أبدًا');
    }

    /** وإشعارُ حالٍ لرسالةِ متجرٍ لا يُكتب في دفتر المبيعات */
    public function test_a_shop_message_status_is_not_written_into_the_sales_book(): void
    {
        $this->allow();

        $shopMessage = WhatsAppMessage::create([
            'business_id' => $this->shop->id,
            'whatsapp_connection_id' => $this->notices->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => 'order_confirmed',
            'direction' => 'outbound',
            'recipient_phone' => '96877778888',
            'provider_message_id' => 'wamid.SHOP-1',
            'dedupe_key' => 'shop:1',
            'status' => 'sent',
        ]);

        $this->deliveryEvent('wamid.SHOP-1', 'delivered', 'NOTICES-PN')->assertOk();

        $this->assertSame('delivered', $shopMessage->fresh()->status);
        $this->assertSame(0, CrmMessage::count());
    }

    /* ═══════════════════ أدوات ═══════════════════ */

    private function allow(): void
    {
        Setting::create(['business_id' => null, 'key' => 'crm_whatsapp_shared', 'value' => '1']);
    }

    /** أرسلنا إليه إشعارَ طلبٍ يومًا — صفٌّ في دفتر إرسالنا */
    private function notified(string $phone): void
    {
        WhatsAppMessage::create([
            'business_id' => $this->shop->id,
            'whatsapp_connection_id' => $this->notices->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => 'order_confirmed',
            'direction' => 'outbound',
            'recipient_phone' => $phone,
            'dedupe_key' => 'notice:'.$phone,
            'status' => 'sent',
        ]);
    }

    private function inbound(string $phoneId, string $from, string $text = 'مرحبا', string $wamid = 'wamid.IN-1')
    {
        return $this->signed(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [[
                'id' => $wamid,
                'from' => $from,
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'text' => ['body' => $text],
            ]],
        ]]]]]]);
    }

    private function deliveryEvent(string $wamid, string $state, string $phoneId)
    {
        return $this->signed(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'statuses' => [[
                'id' => $wamid,
                'status' => $state,
                'timestamp' => (string) now()->timestamp,
                'errors' => $state === 'failed' ? [['title' => 'تعذّر التسليم']] : [],
            ]],
        ]]]]]]);
    }

    private function signed(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }
}
