<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\Setting;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * رقمٌ واحدٌ وثلاثةُ دفاتر — ولا دفترَ يقرأ الآخر.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * `+968 7114 1624` يحمل اليوم ثلاثةَ أشياء: يُرسل إشعاراتِ الطلبات نيابةً
 * عن المحلّات، ويستقبل دعمَ أصحابها، ويستقبل من يريد أن يشتري أبعاد. وقد
 * أُذن له بالثالث صراحةً (`crm_whatsapp_shared`).
 *
 * والأملاكُ ثلاثة لا واحد: صاحبُ المحلّ يملك رسائلَه إلى زبائنه، وأبعاد
 * تملك دفترَ دعمها ودفترَ مبيعاتها. وخلطُ واحدٍ بآخر ليس عيبَ عرضٍ —
 * هو أن يقرأ تاجرٌ ما كتبه تاجرٌ آخر إلى الدعم، أو أن يقرأ موظّفُ مبيعاتِنا
 * ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها.
 *
 * ولذلك لا يُقاس الفصلُ بالنظر إلى ثلاث شاشات — يُقاس هنا: وارِدٌ واحدٌ
 * يدخل من الباب الحقيقيّ، ثمّ **تُعدّ الدفاترُ الثلاثة كلُّها** في كلّ
 * حارس. فصفٌّ يُكتب في غير موضعه يسقط الحارسَ حتّى لو كان موضعُه الصحيح
 * مكتوبًا أيضًا.
 */
class OneNumberAndNoBookReadsAnothersTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $merchant;

    /** الرقمُ الذي أرسلنا إليه إشعارَ طلبٍ يومًا — زبونُ محلٍّ لا عميلٌ محتمَل */
    private const NOTIFIED = '96899887766';

    /** رقمٌ لم نراسله قطّ ولا يخصّ تاجرًا — هذا وحدَه عميلٌ محتمَل */
    private const STRANGER = '96877778888';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'active', 'whatsapp_enabled' => true,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->merchant = User::create([
            'name' => 'صاحب المحل', 'email' => 'o@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $this->shop->id,
            'phone' => '96891112222',
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'NOTICES-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'notices-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);

        /* والإذنُ مُدارٌ كما هو على الإنتاج — فالحارسُ يقيس ما يجري لا ما نوينا */
        Setting::updateOrCreate(['business_id' => null, 'key' => 'crm_whatsapp_shared'], ['value' => '1']);

        /* وإشعارٌ خرج فعلًا إلى زبونٍ — وهو ما يُقاس به «هل راسلناه؟» */
        $this->sent(self::NOTIFIED);
    }

    /** صفٌّ في دفتر إشعارات المحلّ — رسالةٌ خرجت إلى زبونه */
    private function sent(string $phone, array $attrs = []): WhatsAppMessage
    {
        return WhatsAppMessage::create(array_merge([
            'business_id' => $this->shop->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => $phone,
            'dedupe_key' => 'k-'.uniqid('', true),
            'status' => WhatsAppStatus::SENT,
        ], $attrs));
    }

    /** واردٌ على الرقم — من الباب الحقيقيّ بتوقيعه، لا بنداءٍ داخليّ */
    private function inbound(string $from, string $text = 'مرحبا', string $wamid = 'wamid.IN-1')
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'NOTICES-PN'],
            'messages' => [[
                'id' => $wamid, 'from' => $from, 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
            ]],
        ]]]]]];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body);
    }

    /**
     * الدفاترُ الثلاثة تُعدّ معًا — لا دفترٌ وحده.
     *
     * حارسٌ يعدّ موضعَ الصفّ الصحيح وحدَه يمرّ وإن كُتب الصفُّ في موضعين.
     *
     * @return array{support:int, crm:int, trader:int}
     */
    private function books(): array
    {
        return [
            'support' => SupportMessage::count(),
            'crm' => CrmMessage::count(),
            /* ودفترُ التاجر: رسائلُه إلى زبائنه — والإشعارُ التمهيديّ منها */
            'trader' => WhatsAppMessage::where('business_id', $this->shop->id)->count(),
        ];
    }

    /** ما يراه التاجرُ في شاشته فعلًا — لا ما في الجدول */
    private function traderSees(): array
    {
        $response = $this->actingAs($this->merchant)
            ->get(route('admin.marketing.whatsapp.log'));

        $response->assertOk();

        $rows = [];
        $response->assertInertia(function (Assert $page) use (&$rows) {
            $rows = $page->toArray()['props']['rows'] ?? [];
        });

        return $rows;
    }

    /* ═════════════ الوارد يذهب إلى دفترٍ واحد — والباقي صفر ═════════════ */

    /** تاجرٌ يكتب: الدعمُ وحدَه يزيد */
    public function test_a_merchant_writing_reaches_support_and_nothing_else(): void
    {
        $this->inbound('96891112222')->assertOk();

        $this->assertSame(['support' => 1, 'crm' => 0, 'trader' => 1], $this->books());
        $this->assertSame(0, CrmLead::count());
    }

    /** غريبٌ يكتب: دفترُ المبيعات وحدَه يزيد */
    public function test_a_stranger_writing_reaches_sales_and_nothing_else(): void
    {
        $this->inbound(self::STRANGER)->assertOk();

        $this->assertSame(['support' => 0, 'crm' => 1, 'trader' => 1], $this->books());
        $this->assertSame(1, CrmLead::count());
        $this->assertSame(0, SupportConversation::count());
    }

    /**
     * وزبونُ المحلّ يردّ على إشعاره: لا يُكتب في دفترٍ أصلًا.
     *
     * وهذا أهمُّ الثلاثة: ردُّه ملكُ محلِّه لا ملكُنا. وكتابتُه في دفتر
     * المبيعات تجعل موظّفَ مبيعاتنا يقرأ ما كتبته زبونةٌ عن هديّتها.
     */
    public function test_a_customer_answering_his_notice_is_written_in_no_book_at_all(): void
    {
        $this->inbound(self::NOTIFIED, 'وصل الورد؟')->assertOk();

        $this->assertSame(['support' => 0, 'crm' => 0, 'trader' => 1], $this->books());
    }

    /** والثلاثةُ على الرقم نفسه في وقتٍ واحد — كلٌّ في دفتره */
    public function test_three_senders_on_one_number_and_none_crosses(): void
    {
        $this->inbound('96891112222', 'عندي سؤال', 'wamid.A')->assertOk();
        $this->inbound(self::STRANGER, 'كم سعر أبعاد؟', 'wamid.B')->assertOk();
        $this->inbound(self::NOTIFIED, 'وصل الورد؟', 'wamid.C')->assertOk();

        $this->assertSame(['support' => 1, 'crm' => 1, 'trader' => 1], $this->books());
    }

    /* ═════════════ وشاشةُ التاجر لا تعرض إلّا دفترَه ═════════════ */

    /**
     * سجلُّ التاجر لا يعرض رسالةَ دعمٍ — لا رسالتَه هو ولا رسالةَ غيره.
     *
     * وهو ما يُخشى فعلًا: لو خُلط الدفتران لَقرأ تاجرٌ في «سجلّ رسائلي» ما
     * كتبه تاجرٌ آخر إلى الدعم.
     */
    public function test_the_traders_log_shows_no_support_message(): void
    {
        $this->inbound('96891112222', 'كلامٌ لا يخصّ زبائني')->assertOk();

        $rows = $this->traderSees();

        $this->assertCount(1, $rows, 'دخلت رسالةُ دعمٍ إلى سجلّ التاجر');
        $this->assertSame(self::NOTIFIED, $rows[0]['phone']);
    }

    /** ولا رسالةَ عميلٍ محتمَلٍ من دفتر مبيعاتنا */
    public function test_the_traders_log_shows_no_sales_message(): void
    {
        $this->inbound(self::STRANGER, 'كم سعر أبعاد؟')->assertOk();

        $rows = $this->traderSees();

        $this->assertCount(1, $rows);
        $this->assertSame(self::NOTIFIED, $rows[0]['phone']);
    }

    /**
     * ولا واردًا أصلًا: السجلُّ دفترُ ما **خرج** إلى زبائنه.
     *
     * وصفُّ واردٍ في هذا الجدول — أيًّا كان من كتبه — ليس رسالةً أرسلها
     * التاجر، فلا يُعدّ في «كم خرج» ولا يُعرض في «ماذا جرى لها».
     */
    public function test_the_traders_log_shows_no_inbound_row(): void
    {
        $this->sent('96895554444', ['direction' => 'inbound']);

        $rows = $this->traderSees();

        $this->assertCount(1, $rows, 'صفٌّ واردٌ عُرض في دفتر ما خرج');
    }

    /* ═════════════ ولا دفترَ تاجرٍ يُقرأ من دفتر تاجرٍ آخر ═════════════ */

    /** وشاشةُ التاجر لا ترى صفوفَ متجرٍ ثانٍ ولو كتب إلى الرقم نفسه */
    public function test_a_second_shop_on_the_same_number_keeps_its_own_book(): void
    {
        $other = Business::create([
            'name' => 'محل ثانٍ', 'type' => 'محل ورود', 'status' => 'active', 'whatsapp_enabled' => true,
        ]);

        WhatsAppMessage::create([
            'business_id' => $other->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96893332222',
            'dedupe_key' => 'k-other',
            'status' => WhatsAppStatus::SENT,
        ]);

        $rows = $this->traderSees();

        $this->assertCount(1, $rows, 'صفُّ متجرٍ آخر ظهر في سجلّ هذا المتجر');
        $this->assertSame(self::NOTIFIED, $rows[0]['phone']);
    }
}
