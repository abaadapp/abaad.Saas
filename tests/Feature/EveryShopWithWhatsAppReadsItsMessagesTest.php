<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CrmMessage;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\Crm;
use App\Support\CrmLeads;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * سجلُّ رسائل واتساب وصندوقُ محادثاته — لكلّ متجرٍ يملك واتساب، لا لمتجرٍ بعينه.
 *
 * ما يُحرس هنا:
 * - التفعيلُ تلقائيّ: متجرٌ جديدٌ باقتُه تشمل واتساب يفتح صندوقَه بلا عمودٍ ولا
 *   معرّف، ومتجرٌ لا تشمله باقتُه أو أُطفئ واتساب لحسابه لا يفتحه.
 * - السجلّ: النصُّ أو وصفُه، والعميلُ، والرقمُ الذي خرجت منه، ومتى وصلت —
 *   ويُقرأ بعد انقطاع الربط، ولا يرى متجرٌ صفَّ غيره.
 * - الصندوقُ يحمل من وصلته إشعاراتٌ ولم يكتب، ويُفتح من رسالته داخل متجرها وحده.
 * - الرقمُ المشترك بين متاجر: كلٌّ يرى زبائنَه وحده، وزبونُ اثنين لا يُكشف لأحدهما
 *   بلا اقتباس، ومن تحادثه مبيعاتُ أبعاد لا يُنسب لمتجرٍ بلا اقتباس.
 * - السجلّ يحمل ردودَ الموظّفين من جدولها بجوار الإشعارات — لا نسخةٌ ثالثة.
 * - الردُّ الآليّ لا يخرج ممّن لا يملك الإرسال، ويبقى لمن يملكه.
 * - خارج نافذة الأربعِ والعشرين: لا نصَّ حرًّا — قالبُ المتابعة المعتمَد وحده،
 *   على رقم المتجر، وفشلُ ميتا يُكتب ولا يُقال «أُرسلت».
 */
class EveryShopWithWhatsAppReadsItsMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '96891234567';

    private WhatsAppConnection $abaad;

    private static int $seq = 0;

    private bool $metaRefuses = false;

    /** ما تقوله ميتا عن قوالب الحساب — للمزامنة وحدها */
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v21.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::fake(fn (HttpRequest $r) => match (true) {
            str_contains($r->url(), '/message_templates') => Http::response(['data' => $this->templates], 200),
            $this->metaRefuses => Http::response(['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']], 400),
            default => Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200),
        });
        Storage::fake('local');

        $this->abaad = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'ABAAD-PN',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'access_token' => 'platform-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
    }

    /* ============================== أدوات ============================== */

    private function shop(string $name, string $mode, ?Plan $plan = null): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'plan_id' => $plan?->id,
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => true,
            'whatsapp_mode' => $mode,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b->fresh();
    }

    private function own(Business $b, string $pn): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id,
            'phone_number_id' => $pn, 'display_phone_number' => '+968 7000 '.substr($pn, -1),
            'waba_id' => 'WABA-'.$pn, 'access_token' => 'shop-token-value-'.$pn,
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function owner(Business $b, string $role = 'admin', ?string $inbox = null): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => $role.'-'.(++self::$seq), 'email' => 'u'.self::$seq.'@e.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'whatsapp_inbox' => $inbox,
        ]);
    }

    private function webhook(array $value): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body)->assertOk();
    }

    private function inbound(string $pn, string $wamid, string $text = 'مرحبا', array $context = []): void
    {
        $this->webhook([
            'metadata' => ['phone_number_id' => $pn],
            'messages' => [[
                'id' => $wamid, 'from' => self::CUSTOMER, 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
            ] + ($context === [] ? [] : ['context' => $context])],
        ]);
    }

    /** إشعارُ طلبٍ خرج إلى الزبون — من رقم أبعاد أو من رقم المتجر */
    private function notified(Business $b, ?WhatsAppConnection $line = null, array $extra = []): WhatsAppMessage
    {
        $line ??= $this->abaad;
        $own = $line->owner_type === WhatsAppMode::OWNER_BUSINESS;

        return WhatsAppMessage::create($extra + [
            'business_id' => $b->id, 'whatsapp_connection_id' => $line->id,
            'sender_phone_number_id' => $line->phone_number_id,
            'source_mode' => $own ? WhatsAppMode::BUSINESS_OWN : WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY, 'direction' => 'outbound',
            'recipient_phone' => self::CUSTOMER, 'template_name' => 'order_ready', 'language_code' => 'ar',
            'dedupe_key' => 'n:'.(++self::$seq), 'status' => WhatsAppStatus::SENT,
            'provider_message_id' => 'wamid.NOTICE-'.self::$seq, 'quota_consumed' => false,
        ]);
    }

    private function sentTo(string $pn): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/'.$pn.'/messages'))->count();
    }

    /** @return array<string, mixed> */
    private function props(User $as, string $route, array $query = []): array
    {
        return $this->actingAs($as)->get(route($route, $query))->assertOk()->viewData('page')['props'];
    }

    /* ============================== التفعيل ============================== */

    public function test_a_new_shop_with_whatsapp_opens_its_inbox_by_itself_and_one_without_it_does_not(): void
    {
        $withWhatsApp = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);
        $without = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => []]);

        // متجرٌ جديد لم يلمسه أحد — لا عمودَ يُفتح له ولا معرّفَ يُسمّى
        $fresh = $this->shop('متجرٌ جديد', WhatsAppMode::ABAAD_SHARED, $withWhatsApp);
        $this->assertFalse((bool) $fresh->whatsapp_inbox_enabled, 'العمودُ القديم مطفأٌ ولا يُقرأ');
        $this->assertTrue(WhatsAppInbox::available($fresh));
        $owner = $this->owner($fresh);
        $this->actingAs($owner)->get(route('admin.whatsappInbox.index'))->assertOk();
        $this->assertContains('whatsapp_inbox', $this->props($owner, 'admin.dashboard')['context']['hosted'] ?? []);

        // باقةٌ لا تشمل واتساب: لا صندوق ولا بابَ في الشريط — ويُردّ بسبب الباقة
        $basic = $this->shop('متجرٌ أساسيّ', WhatsAppMode::ABAAD_SHARED, $without);
        $this->assertFalse(WhatsAppInbox::available($basic));
        $basicOwner = $this->owner($basic);
        $this->assertNotContains('whatsapp_inbox', $this->props($basicOwner, 'admin.dashboard')['context']['hosted'] ?? []);
        $this->assertContains($this->actingAs($basicOwner)->get(route('admin.whatsappInbox.index'))->getStatusCode(), [403, 404]);

        // وواتساب مطفأٌ لحسابه: يُغلق الصندوقُ — ولا يُحفظ واردُه
        $off = $this->shop('متجرٌ مطفأ', WhatsAppMode::BUSINESS_OWN, $withWhatsApp);
        $this->own($off, 'PN-OFF');
        $off->update(['whatsapp_enabled' => false]);
        $this->actingAs($this->owner($off))->get(route('admin.whatsappInbox.index'))->assertNotFound();
        $this->inbound('PN-OFF', 'wamid.OFF');
        $this->assertSame(0, WhatsAppChatMessage::count());

        // والموظّفُ بلا صلاحيةٍ لا يدخله ولو فُتح لمتجره
        $this->actingAs($this->owner($fresh, 'sales'))->get(route('admin.whatsappInbox.index'))->assertForbidden();
    }

    public function test_a_customer_writing_to_any_shops_own_number_lands_in_its_inbox(): void
    {
        $shop = $this->shop('محلٌّ برقمه', WhatsAppMode::BUSINESS_OWN);
        $this->own($shop, 'PN-OWN');

        $this->inbound('PN-OWN', 'wamid.FIRST', 'هل يصل الطلب اليوم؟');

        $chat = WhatsAppChat::where('business_id', $shop->id)->sole();
        $this->assertSame(['هل يصل الطلب اليوم؟'], WhatsAppChatMessage::where('chat_id', $chat->id)->pluck('body')->all());
    }

    /* ============================== السجلّ ============================== */

    public function test_the_log_says_the_text_or_what_it_was_the_customer_the_number_and_when_it_arrived(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $line = $this->own($shop, 'PN-A');
        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'سارة الهنائي', 'phone' => '9123 4567']);
        $owner = $this->owner($shop);

        $this->notified($shop, $line, [
            'customer_id' => $customer->id, 'body_snapshot' => 'طلبك جاهز للاستلام',
            'status' => WhatsAppStatus::READ, 'sent_at' => now()->subMinutes(9), 'delivered_at' => now()->subMinutes(8), 'read_at' => now()->subMinutes(2),
        ]);
        // رسالةٌ قديمةٌ بلا نصٍّ محفوظ: وصفٌ لا نصٌّ مخترَع
        $this->notified($shop, $this->abaad, ['event_type' => WhatsAppEvent::ORDER_CONFIRMED]);
        $this->notified($shop, $line, [
            'status' => WhatsAppStatus::FAILED, 'error_code' => '131026', 'error_message' => 'Message undeliverable', 'failed_at' => now(),
        ]);

        $rows = collect($this->props($owner, 'admin.marketing.whatsapp.log')['rows'])->keyBy('id');
        $this->assertCount(3, $rows);

        $read = $rows->firstWhere('status', WhatsAppStatus::READ);
        $this->assertSame('طلبك جاهز للاستلام', $read['body']);
        $this->assertNull($read['summary']);
        $this->assertSame('سارة الهنائي', $read['customer']);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $read['source']);
        $this->assertSame(['sent', 'delivered', 'read'], array_keys($read['times']));

        $old = $rows->firstWhere('source', WhatsAppMode::ABAAD_SHARED);
        $this->assertNull($old['body']);
        $this->assertSame(WhatsAppEvent::label(WhatsAppEvent::ORDER_CONFIRMED), $old['summary']);
        $this->assertSame(WhatsAppStatus::label(WhatsAppStatus::SENT), $old['status_label'], '«خرجت» لا «وصلت» لما قبلته ميتا وحده');
        $this->assertArrayNotHasKey('delivered', $old['times']);

        $failed = $rows->firstWhere('status', WhatsAppStatus::FAILED);
        $this->assertSame('Message undeliverable', $failed['error']);
        $this->assertArrayHasKey('failed', $failed['times']);

        // البحثُ باسم العميل، ومرشِّحُ الرقم، وقيمةٌ لا يعرفها المرشِّح تُعاد «الكلّ»
        $this->assertSame([$read['id']], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['q' => 'الهنائي'])['rows'])->pluck('id')->all());
        $this->assertSame([$old['id']], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['source' => 'shared'])['rows'])->pluck('id')->all());
        $this->assertCount(2, $this->props($owner, 'admin.marketing.whatsapp.log', ['source' => 'own'])['rows']);
        $this->assertSame('all', $this->props($owner, 'admin.marketing.whatsapp.log', ['source' => '<x>'])['params']['source']);
    }

    public function test_the_log_stays_readable_after_the_link_is_cut_and_never_shows_another_shop(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $line = $this->own($shop, 'PN-A');
        $neighbour = $this->shop('محل الجار', WhatsAppMode::BUSINESS_OWN);
        $owner = $this->owner($shop);

        $mine = $this->notified($shop, $line);
        $this->notified($neighbour, $this->own($neighbour, 'PN-B'), ['recipient_phone' => '96899998888']);

        // فصل المتجرُ رقمه — الدفترُ باقٍ ويُقرأ، والبابُ «اربط أوّلًا» لا يحجبه
        $line->update(['status' => WhatsAppConnection::INACTIVE]);
        $props = $this->props($owner, 'admin.marketing.whatsapp.log');
        $this->assertTrue($props['hasHistory']);
        $this->assertSame([$mine->id], collect($props['rows'])->pluck('id')->all());
        $this->assertFalse($props['readiness']['ready']);

        // ورقمُ الجار في البحث لا يُرجع صفَّه
        $this->assertSame([], $this->props($owner, 'admin.marketing.whatsapp.log', ['q' => '99998888'])['rows']);
    }

    public function test_the_log_holds_staff_replies_beside_the_notices_without_copying_them(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $line = $this->own($shop, 'PN-A');
        $neighbour = $this->shop('محل الجار', WhatsAppMode::BUSINESS_OWN);
        $this->own($neighbour, 'PN-B');
        $owner = $this->owner($shop);
        $staff = $this->owner($shop, 'sales', WhatsAppInbox::REPLY);

        $notice = $this->notified($shop, $line, ['body_snapshot' => 'طلبك جاهز', 'created_at' => now()->subMinutes(5)]);
        $this->inbound('PN-A', 'wamid.Q', 'متى يصل؟');
        $chat = WhatsAppChat::where('business_id', $shop->id)->sole();
        $this->actingAs($staff)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'خلال ساعة إن شاء الله'])->assertSessionHasNoErrors();
        $this->inbound('PN-B', 'wamid.QB', 'سؤالٌ للجار');
        $this->actingAs($this->owner($neighbour))->post(route('admin.whatsappInbox.reply', WhatsAppChat::where('business_id', $neighbour->id)->sole()->id), ['body' => 'ردُّ الجار'])
            ->assertSessionHasNoErrors();

        $before = WhatsAppMessage::count();
        $props = $this->props($owner, 'admin.marketing.whatsapp.log');
        $this->assertSame($before, WhatsAppMessage::count(), 'السجلّ يقرأ ولا يكتب');
        $this->assertSame(1, WhatsAppMessage::count(), 'الردُّ لا يُنسخ إلى دفتر الإشعارات');

        $rows = collect($props['rows']);
        $this->assertSame(['reply', 'notice'], $rows->pluck('kind')->all());
        $reply = $rows->first();
        $this->assertSame(['خلال ساعة إن شاء الله', $staff->name, self::CUSTOMER, WhatsAppStatus::SENT], [$reply['body'], $reply['sender'], $reply['phone'], $reply['status']]);
        $this->assertSame(route('admin.whatsappInbox.index', ['c' => $chat->id]), $reply['open']);
        $this->assertNotContains('ردُّ الجار', $rows->pluck('body')->all());

        // والبحثُ بنصّ الرسالة يجد الردّ والإشعار — والنوعُ يفصلهما
        $this->assertSame(['r'.$reply['id']], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['q' => 'إن شاء الله'])['rows'])->pluck('key')->all());
        $this->assertSame(['n'.$notice->id], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['q' => 'طلبك جاهز'])['rows'])->pluck('key')->all());
        $this->assertSame(['notice'], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['kind' => 'notices'])['rows'])->pluck('kind')->all());
        $this->assertSame(['reply'], collect($this->props($owner, 'admin.marketing.whatsapp.log', ['kind' => 'replies'])['rows'])->pluck('kind')->all());
        $this->assertSame([], $this->props($owner, 'admin.marketing.whatsapp.log', ['q' => 'ردُّ الجار'])['rows']);

        // وعدّادُ الشهر عدّادُ الإشعارات كما كان
        $this->assertSame(1, array_sum($props['summary']));
    }

    /* ============================== الردّ الآليّ ============================== */

    public function test_the_auto_reply_never_leaves_a_shop_that_may_not_send_and_still_answers_for_one_that_may(): void
    {
        $withWhatsApp = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);
        $without = Plan::create(['name' => 'الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99, 'capabilities' => []]);
        $cases = [
            'eligible' => [$withWhatsApp, []],
            'plan' => [$without, []],
            'off' => [$withWhatsApp, ['whatsapp_enabled' => false]],
            'own_revoked' => [$withWhatsApp, ['whatsapp_own_allowed' => false]],
            // يُرسل من رقم أبعاد، وبقي ربطُ رقمه بلا إذن: لا يردّ منه
            'shared_mode' => [$withWhatsApp, ['whatsapp_own_allowed' => false, 'whatsapp_mode' => WhatsAppMode::ABAAD_SHARED]],
        ];

        $n = 0;
        foreach ($cases as $case => [$plan, $change]) {
            $shop = $this->shop('متجر '.$case, WhatsAppMode::BUSINESS_OWN, $plan);
            $this->own($shop, 'PN-'.$case);
            $shop->update($change);
            WhatsAppAutoReply::save($shop->id, ['enabled' => true, 'ar' => 'شكرًا لتواصلك', 'cooldown_hours' => 12]);

            $this->inbound('PN-'.$case, 'wamid.AR-'.(++$n));
        }

        $reply = fn (string $case) => WhatsAppInboundMessage::where('whatsapp_connection_id', WhatsAppConnection::where('phone_number_id', 'PN-'.$case)->value('id'))
            ->first(['reply_status', 'reply_reason'])?->only(['reply_status', 'reply_reason']);

        $this->assertSame(['reply_status' => 'sent', 'reply_reason' => null], $reply('eligible'));
        $this->assertSame(1, $this->sentTo('PN-eligible'));
        $this->assertSame(['reply_status' => 'skipped', 'reply_reason' => WhatsAppStatus::SKIP_PLAN], $reply('plan'));
        $this->assertSame(['reply_status' => 'skipped', 'reply_reason' => WhatsAppStatus::SKIP_AUTOMATION_OFF], $reply('off'));
        $this->assertSame(['reply_status' => 'skipped', 'reply_reason' => WhatsAppStatus::SKIP_OWN_NOT_ALLOWED], $reply('own_revoked'));
        $this->assertSame(['reply_status' => 'skipped', 'reply_reason' => WhatsAppStatus::SKIP_OWN_NOT_ALLOWED], $reply('shared_mode'));
        $this->assertSame(0, $this->sentTo('PN-plan') + $this->sentTo('PN-off') + $this->sentTo('PN-own_revoked') + $this->sentTo('PN-shared_mode'));

        // وواتساب مطفأٌ في المنصّة كلّها: لا ردَّ آليًّا من أحد
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '0']);
        $this->webhook([
            'metadata' => ['phone_number_id' => 'PN-eligible'],
            'messages' => [['id' => 'wamid.AR-GLOBAL', 'from' => '96899990000', 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'مرحبا']]],
        ]);
        $this->assertSame(WhatsAppStatus::SKIP_AUTOMATION_OFF, WhatsAppInboundMessage::where('wamid', 'wamid.AR-GLOBAL')->value('reply_reason'));
        $this->assertSame(1, $this->sentTo('PN-eligible'));
    }

    /* ============================== الصندوق ============================== */

    public function test_a_customer_who_only_received_notices_has_a_conversation_that_opens_inside_its_shop(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $line = $this->own($shop, 'PN-A');
        $owner = $this->owner($shop);
        $notice = $this->notified($shop, $line, ['body_snapshot' => 'تم تأكيد طلبك']);
        // وما لم يخرج إلى واتساب ليس محادثة
        $skipped = $this->notified($shop, $line, ['recipient_phone' => '96877776666', 'status' => WhatsAppStatus::SKIPPED]);

        $chats = $this->props($owner, 'admin.whatsappInbox.index')['chats'];
        $this->assertCount(1, $chats);
        $this->assertNull($chats[0]['id']);
        $this->assertSame(self::CUSTOMER, $chats[0]['phone']);
        $this->assertSame('تم تأكيد طلبك', $chats[0]['preview']);
        $this->assertSame(route('admin.whatsappInbox.open', $notice->id), $chats[0]['url']);
        $this->assertSame(0, WhatsAppChat::count(), 'القائمةُ لا تكتب صفًّا');

        // تُفتح من رسالتها — محادثةٌ على رقمها، والإشعارُ في خيطها
        $to = $this->actingAs($owner)->get(route('admin.whatsappInbox.open', $notice->id))->assertRedirect();
        $chat = WhatsAppChat::sole();
        $this->assertSame([$shop->id, 'PN-A', WhatsAppMode::BUSINESS_OWN, null], [$chat->business_id, $chat->phone_number_id, $chat->source_mode, $chat->last_message_at]);
        $to->assertRedirect(route('admin.whatsappInbox.index', ['c' => $chat->id]));
        $thread = $this->props($owner, 'admin.whatsappInbox.index', ['c' => $chat->id])['thread'];
        $this->assertSame(['تم تأكيد طلبك'], collect($thread['messages'])->pluck('body')->all());
        $this->assertNotNull($thread['blocked'], 'لم يكتب الزبون — النافذةُ مغلقة');

        // وفتحُها مرّتين محادثةٌ واحدة، والقائمةُ تحملها مرّةً
        $this->actingAs($owner)->get(route('admin.whatsappInbox.open', $notice->id))->assertRedirect();
        $this->assertSame(1, WhatsAppChat::count());
        $this->assertSame([$chat->id], collect($this->props($owner, 'admin.whatsappInbox.index')['chats'])->pluck('id')->all());

        $this->actingAs($owner)->get(route('admin.whatsappInbox.open', $skipped->id))->assertNotFound();
    }

    public function test_a_message_of_another_shop_opens_no_conversation_and_writes_nothing(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::ABAAD_SHARED);
        $neighbour = $this->shop('محل الجار', WhatsAppMode::ABAAD_SHARED);
        $theirs = $this->notified($neighbour);

        $this->actingAs($this->owner($shop))->get(route('admin.whatsappInbox.open', $theirs->id))->assertNotFound();
        $this->assertSame(0, WhatsAppChat::count());
        $this->assertSame([], $this->props($this->owner($shop), 'admin.whatsappInbox.index')['chats']);
    }

    public function test_a_conversation_opened_from_a_failed_notice_is_no_evidence_for_a_shared_reply(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::ABAAD_SHARED);
        $failed = $this->notified($shop, null, ['status' => WhatsAppStatus::FAILED]);

        $this->actingAs($this->owner($shop))->get(route('admin.whatsappInbox.open', $failed->id))->assertRedirect();
        $this->inbound('ABAAD-PN', 'wamid.WHO');

        // رسالةٌ لم تصله ومحادثةٌ فُتحت للقراءة ليستا دليلًا أنّه كتب لهذا المحلّ
        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    /* ============================== الرقم المشترك ============================== */

    public function test_shops_sharing_abaads_number_each_see_their_own_customers_only(): void
    {
        $gifts = $this->shop('محل الهدايا', WhatsAppMode::ABAAD_SHARED);
        $sweets = $this->shop('محل الحلوى', WhatsAppMode::ABAAD_SHARED);
        $giftsOwner = $this->owner($gifts);
        $sweetsOwner = $this->owner($sweets);

        // زبونُ الهدايا وحده: ردُّه للهدايا وحدها
        $this->notified($gifts);
        $this->inbound('ABAAD-PN', 'wamid.G1', 'طلبي من الهدايا');
        $chat = WhatsAppChat::where('business_id', $gifts->id)->sole();
        $this->assertSame(['طلبي من الهدايا'], WhatsAppChatMessage::where('chat_id', $chat->id)->pluck('body')->all());

        // ولا يرى محلُّ الحلوى محادثتَه ولا إشعارَه ولا سجلَّه — ولا بتبديل الرقم في العنوان
        $this->assertSame([], $this->props($sweetsOwner, 'admin.whatsappInbox.index')['chats']);
        $this->actingAs($sweetsOwner)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->assertViewHas('page', fn ($p) => $p['props']['thread'] === null);
        $this->actingAs($sweetsOwner)->get(route('admin.whatsappInbox.older', $chat->id))->assertNotFound();
        $this->actingAs($sweetsOwner)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'x'])->assertNotFound();
        $this->assertSame([], $this->props($sweetsOwner, 'admin.marketing.whatsapp.log')['rows']);

        // ثمّ راسله محلُّ الحلوى أيضًا: صار زبونَ اثنين — ردُّه بلا اقتباس لأحد
        $sweetNotice = $this->notified($sweets);
        $this->inbound('ABAAD-PN', 'wamid.G2', 'وصل؟');
        $this->assertSame(1, WhatsAppChatMessage::count(), 'لا يُكشف لأحدهما ما لا يُعرف لمن كُتب');

        // والاقتباسُ يحسم لصاحب الرسالة المقتبَسة وحده
        $this->inbound('ABAAD-PN', 'wamid.G3', 'بخصوص الحلوى', ['from' => '96870000000', 'id' => $sweetNotice->provider_message_id]);
        $this->assertSame(['بخصوص الحلوى'], WhatsAppChatMessage::where('business_id', $sweets->id)->pluck('body')->all());
        $this->assertSame(['طلبي من الهدايا'], WhatsAppChatMessage::where('business_id', $gifts->id)->pluck('body')->all());

        // وكلٌّ في قائمته محادثتُه وحدها
        $this->assertSame([$chat->id], collect($this->props($giftsOwner, 'admin.whatsappInbox.index')['chats'])->pluck('id')->all());
        $this->assertSame([WhatsAppChat::where('business_id', $sweets->id)->value('id')], collect($this->props($sweetsOwner, 'admin.whatsappInbox.index')['chats'])->pluck('id')->all());
    }

    public function test_a_customer_who_talks_to_abaad_sales_on_the_shared_number_is_no_shops_without_a_quote(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::ABAAD_SHARED);
        $notice = $this->notified($shop);
        $lead = CrmLeads::findOrCreateByPhone(self::CUSTOMER, Crm::SOURCE_MANUAL)['lead'];
        CrmMessage::create(['lead_id' => $lead->id, 'direction' => CrmMessage::OUT, 'body' => 'سعر الباقة الاحترافية ٢٩٫٩']);

        $this->inbound('ABAAD-PN', 'wamid.SALES', 'وكم مدّة التجربة؟');
        $this->assertSame(0, WhatsAppChatMessage::count(), 'سؤالٌ لمبيعات أبعاد لا يُكشف لتاجر');

        // والاقتباسُ يحسم: ردٌّ على إشعار المحلّ للمحلّ
        $this->inbound('ABAAD-PN', 'wamid.QUOTE', 'متى يصل؟', ['from' => '96870000000', 'id' => $notice->provider_message_id]);
        $this->assertSame(['متى يصل؟'], WhatsAppChatMessage::where('business_id', $shop->id)->pluck('body')->all());

        // ومحادثةُ مبيعاتٍ مضى عليها أكثرُ من مدّة الالتباس لا تحجب
        CrmMessage::query()->update(['created_at' => now()->subDays(WhatsAppInbox::AMBIGUOUS_DAYS + 1)]);
        $this->inbound('ABAAD-PN', 'wamid.LATER', 'شكرًا');
        $this->assertSame(2, WhatsAppChatMessage::where('business_id', $shop->id)->count());
    }

    /* ============================== قالبُ المتابعة ============================== */

    private function closedChat(Business $shop, string $pn): WhatsAppChat
    {
        $this->inbound($pn, 'wamid.OLD-'.(++self::$seq), 'هل عندكم توصيل؟');
        $chat = WhatsAppChat::where('business_id', $shop->id)->sole();
        $chat->forceFill(['last_inbound_at' => now()->subHours(WhatsAppInbox::WINDOW_HOURS + 1)])->save();

        return $chat->fresh();
    }

    private function approveFollowUp(Business $shop): WhatsAppTemplateMapping
    {
        return WhatsAppTemplateMapping::create([
            'scope_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $shop->id,
            'event_type' => WhatsAppInbox::FOLLOW_UP_EVENT, 'template_name' => WhatsAppInbox::FOLLOW_UP_TEMPLATE,
            'language_code' => 'ar', 'enabled' => true, 'variable_mapping' => ['1' => 'business_name'],
            'meta_status' => WhatsAppTemplates::APPROVED, 'approved_languages' => ['ar'],
            'body_by_language' => ['ar' => 'مرحبًا، لدى {{1}} ردٌّ على رسالتك — اكتب لنا هنا لنكمل.'],
        ]);
    }

    public function test_outside_the_window_no_free_text_leaves_and_the_approved_follow_up_template_does(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $this->own($shop, 'PN-A');
        $owner = $this->owner($shop);
        $chat = $this->closedChat($shop, 'PN-A');

        // لا قالبَ بعد: النصُّ الحرّ يُردّ، والمنعُ يسمّي القالبَ المنتظَر
        $this->actingAs($owner)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'نعم'])->assertSessionHasErrors('body');
        $thread = $this->props($owner, 'admin.whatsappInbox.index', ['c' => $chat->id])['thread'];
        $this->assertNull($thread['template']);
        $this->assertStringContainsString(WhatsAppInbox::FOLLOW_UP_TEMPLATE, $thread['blocked']);
        $this->actingAs($owner)->post(route('admin.whatsappInbox.followUp', $chat->id))->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sentTo('PN-A'));

        // اعتُمد: يخرج القالبُ باسمه ومتغيّره — والنصُّ الحرُّ ما زال يُردّ
        $this->approveFollowUp($shop);
        $this->assertSame(WhatsAppInbox::FOLLOW_UP_TEMPLATE, $this->props($owner, 'admin.whatsappInbox.index', ['c' => $chat->id])['thread']['template']);
        $this->actingAs($owner)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'نعم'])->assertSessionHasErrors('body');

        $this->actingAs($owner)->post(route('admin.whatsappInbox.followUp', $chat->id))->assertSessionHasNoErrors();
        $sent = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/PN-A/messages'))->sole()[0]->data();
        $this->assertSame('template', $sent['type']);
        $this->assertSame(WhatsAppInbox::FOLLOW_UP_TEMPLATE, $sent['template']['name']);
        $this->assertSame([['type' => 'text', 'text' => 'محل الورد']], $sent['template']['components'][0]['parameters']);

        $out = WhatsAppChatMessage::where('chat_id', $chat->id)->where('direction', WhatsAppChatMessage::OUT)->sole();
        $this->assertSame('مرحبًا، لدى محل الورد ردٌّ على رسالتك — اكتب لنا هنا لنكمل.', $out->body);
        $this->assertSame(WhatsAppStatus::SENT, $out->status, 'قبلتها ميتا — لا «وصلت»');
        $this->assertNull($out->delivered_at);
    }

    public function test_a_refused_follow_up_is_written_failed_with_metas_words_and_view_only_staff_cannot_send_it(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $this->own($shop, 'PN-A');
        $chat = $this->closedChat($shop, 'PN-A');
        $this->approveFollowUp($shop);

        $this->actingAs($this->owner($shop, 'sales', WhatsAppInbox::VIEW))
            ->post(route('admin.whatsappInbox.followUp', $chat->id))->assertForbidden();
        $this->assertSame(0, $this->sentTo('PN-A'));

        $this->metaRefuses = true;
        $this->actingAs($this->owner($shop))->post(route('admin.whatsappInbox.followUp', $chat->id))
            ->assertSessionHas('toast', fn ($t) => $t['type'] === 'danger');

        $out = WhatsAppChatMessage::where('chat_id', $chat->id)->where('direction', WhatsAppChatMessage::OUT)->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $out->status);
        $this->assertStringContainsString('Template name does not exist', $out->error_message);
    }

    public function test_the_shared_number_sends_no_follow_up_template_for_a_shop(): void
    {
        $shop = $this->shop('محل الهدايا', WhatsAppMode::ABAAD_SHARED);
        $this->notified($shop);
        $chat = $this->closedChat($shop, 'ABAAD-PN');
        $this->approveFollowUp($shop);

        $this->assertNull(WhatsAppInbox::followUpTemplate($chat));
        $this->actingAs($this->owner($shop))->post(route('admin.whatsappInbox.followUp', $chat->id))->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sentTo('ABAAD-PN'));
    }

    public function test_the_hourly_sync_adopts_the_follow_up_template_and_its_state_never_reddens_the_notices(): void
    {
        $shop = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $line = $this->own($shop, 'PN-A');

        $this->templates = [
            ['name' => WhatsAppInbox::FOLLOW_UP_TEMPLATE, 'language' => 'ar', 'status' => 'APPROVED', 'components' => [
                ['type' => 'BODY', 'text' => 'مرحبًا، لدى {{1}} ردٌّ على رسالتك.'],
            ]],
        ];

        WhatsAppTemplates::sync($line, WhatsAppMode::OWNER_BUSINESS, $shop->id);

        $mapping = WhatsAppTemplateMapping::where('business_id', $shop->id)->where('event_type', WhatsAppInbox::FOLLOW_UP_EVENT)->sole();
        $this->assertSame([WhatsAppInbox::FOLLOW_UP_TEMPLATE, WhatsAppTemplates::APPROVED], [$mapping->template_name, $mapping->meta_status]);

        // ومتابعةٌ أوقفتها ميتا لا تُحمِّر خطوةَ قوالب الإشعارات
        $mapping->update(['meta_status' => 'PAUSED']);
        $this->assertNull(WhatsAppTemplates::approvalState(WhatsAppMode::OWNER_BUSINESS, $shop->id));
    }
}
