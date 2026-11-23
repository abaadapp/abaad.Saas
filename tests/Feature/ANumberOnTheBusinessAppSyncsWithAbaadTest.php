<?php

namespace Tests\Feature;

use App\Jobs\RequestWhatsAppCoexistenceSync;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatAttachment;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppInboundMessage;
use App\Support\WhatsAppCoexistence;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMerchantLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ‏‏رقمٌ يبقى في تطبيق واتساب للأعمال ويصل أبعاد معًا (التعايش) — المزامنة.
 *
 * بعد الربط تُطلب جهاتُ التاجر ثمّ محادثاتُه السابقة، مرّةً لكلٍّ منهما خلال ٢٤ ساعة. وما
 * يرسله من هاتفه يُكتب في صندوقه ويُسكت الردَّ الآليّ فوقه، والتاريخُ يُستورد بأوقاته ولا يفتح
 * نافذةَ الردّ ولا يُعدّ غيرَ مقروء، وجهاتُه تُحفظ ولا تصير عملاء، وانفصالُه يُوقف الرقمَ ويُقال
 * سببُه. ورقمٌ ليس في التطبيق لا يُقرأ له شيءٌ من هذا.
 */
class ANumberOnTheBusinessAppSyncsWithAbaadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'abaad-app-secret-not-real';

    private const WABA = '7770000000001';

    private const PHONE = '8880000000001';

    private const OWN_NUMBER = '96891000001';

    private const CUSTOMER = '96891234567';

    private Business $shop;

    private User $owner;

    private WhatsAppConnection $line;

    /** ‏‏ما تردّ به Meta على طلب المزامنة — يتبدّل في الاختبار */
    private array $smb = [];

    private int $sends = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');
        Storage::fake('local');

        config([
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => self::SECRET,
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.merchant_link' => 'abaad_app',
            'whatsapp.coexistence_enabled' => false,
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $r) => $this->meta($r));

        [$this->shop, $this->owner, $this->line] = $this->merchant('محل الورد', 'rose@coex.om', self::WABA, self::PHONE, true);
    }

    private function meta(HttpRequest $r)
    {
        $url = $r->url();

        if (str_ends_with(parse_url($url, PHP_URL_PATH), '/smb_app_data')) {
            $next = array_shift($this->smb) ?? ['ok' => true];

            return $next['ok']
                ? Http::response(['messaging_product' => 'whatsapp', 'request_id' => 'REQ-'.$r['sync_type']], 200)
                : Http::response(['error' => ['code' => 131000, 'message' => 'Something went wrong']], 500);
        }

        if (str_ends_with(parse_url($url, PHP_URL_PATH), '/messages')) {
            $this->sends++;

            return Http::response(['messages' => [['id' => 'wamid.AUTO-'.$this->sends]]], 200);
        }

        if (str_contains($url, '/MEDIA-1') && $r->method() === 'GET' && ! str_contains($url, 'download')) {
            return Http::response(['url' => 'https://graph.example.test/download/MEDIA-1', 'mime_type' => 'image/jpeg'], 200);
        }

        if (str_contains($url, '/download/MEDIA-1')) {
            return Http::response("\xFF\xD8\xFF\xE0fake-jpeg", 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response(['error' => ['code' => 100, 'message' => 'unexpected '.$url]], 400);
    }

    /** @return array{Business, User, WhatsAppConnection} */
    private function merchant(string $name, string $email, string $waba, string $phone, bool $onApp): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        $u = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $c = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id, 'provider' => 'meta_cloud',
            'phone_number_id' => $phone, 'display_phone_number' => '+968 9100 0001', 'waba_id' => $waba,
            'access_token' => 'token-'.$phone.'-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(), 'coexistence' => $onApp,
        ]);

        return [$b, $u, $c];
    }

    private function hook(string $field, array $value, string $entry = self::WABA)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => $entry, 'changes' => [['field' => $field, 'value' => $value]]]]], JSON_UNESCAPED_UNICODE);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();
    }

    private function meta_(string $phone = self::PHONE): array
    {
        return ['display_phone_number' => self::OWN_NUMBER, 'phone_number_id' => $phone];
    }

    private function inbound(string $from, string $wamid, string $text = 'مرحبا'): void
    {
        $this->hook('messages', [
            'messaging_product' => 'whatsapp', 'metadata' => $this->meta_(),
            'contacts' => [['profile' => ['name' => 'سارة'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    private function smbCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/smb_app_data'))
            ->map(fn ($pair) => $pair[0]['sync_type'])->values()->all();
    }

    /* ─────────────────────────── مديرُ المنصّة يفتح الخيار ─────────────────────────── */

    public function test_the_phone_app_option_opens_only_through_abaads_app_and_by_the_platform_manager(): void
    {
        $super = User::create(['business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'p@coex.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('super-admin.whatsapp.merchant-link.coexistence'), ['enabled' => true])->assertForbidden();

        config(['whatsapp.merchant_link' => 'own_app']);
        $this->actingAs($super)->post(route('super-admin.whatsapp.merchant-link.coexistence'), ['enabled' => true])->assertSessionHasErrors('enabled');
        $this->assertFalse(WhatsAppOnboarding::coexistenceOpen());

        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->actingAs($super)->post(route('super-admin.whatsapp.merchant-link.coexistence'), ['enabled' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');
        $this->assertTrue(WhatsAppOnboarding::coexistenceOpen(), 'يسري من الطلب التالي بلا .env');
        $this->assertSame('1', Setting::whereNull('business_id')->where('key', WhatsAppMerchantLink::COEXISTENCE_KEY)->value('value'));
        $this->assertSame('فتح للتجّار خيار البقاء في تطبيق واتساب للأعمال', ActivityLog::where('subject_type', Setting::class)->latest('id')->value('description'));
        $this->assertSame(WhatsAppCoexistence::FIELDS, WhatsAppMerchantLink::state()['coexistence_fields']);

        $this->actingAs($super)->post(route('super-admin.whatsapp.merchant-link.coexistence'), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse(WhatsAppOnboarding::coexistenceOpen());
    }

    /* ─────────────────────────── طلبا المزامنة ─────────────────────────── */

    public function test_after_linking_contacts_then_history_are_asked_once_each(): void
    {
        WhatsAppCoexistence::start($this->line);

        $this->assertSame([WhatsAppCoexistence::CONTACTS, WhatsAppCoexistence::HISTORY], $this->smbCalls());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::PHONE.'/smb_app_data') && $r['messaging_product'] === 'whatsapp');

        $state = $this->line->fresh()->metadata['coexistence_sync'];
        $this->assertSame('REQ-smb_app_state_sync', $state[WhatsAppCoexistence::CONTACTS]['request_id']);
        $this->assertSame('REQ-history', $state[WhatsAppCoexistence::HISTORY]['request_id']);

        // ‏‏وما قُبل لا يُطلب ثانيةً — Meta تقبله مرّةً واحدة
        $this->assertSame(WhatsAppCoexistence::DONE, WhatsAppCoexistence::request($this->line->fresh(), WhatsAppCoexistence::HISTORY));
        (new RequestWhatsAppCoexistenceSync($this->line->id))->handle();
        $this->assertCount(2, $this->smbCalls());

        $view = WhatsAppCoexistence::view($this->line->fresh());
        $this->assertSame(['requested', 'requested'], [$view['contacts']['state'], $view['history']['state']]);
    }

    public function test_a_failed_request_is_asked_again_and_nothing_is_asked_after_a_day(): void
    {
        $this->smb = [['ok' => false]];
        WhatsAppCoexistence::start($this->line);

        // ‏‏فشلت الجهات — فلا يُطلب التاريخ قبلها، ويُقال للتاجر إنّا نعيد
        $this->assertSame([WhatsAppCoexistence::CONTACTS], $this->smbCalls());
        $this->assertSame('failed', WhatsAppCoexistence::view($this->line->fresh())['contacts']['state']);
        $this->assertSame('pending', WhatsAppCoexistence::view($this->line->fresh())['history']['state']);

        (new RequestWhatsAppCoexistenceSync($this->line->id))->handle();
        $this->assertSame([WhatsAppCoexistence::CONTACTS, WhatsAppCoexistence::CONTACTS, WhatsAppCoexistence::HISTORY], $this->smbCalls());

        // ‏‏وربطٌ مضى عليه أكثرُ من يوم: لا طلب — Meta ترفضه
        [, , $late] = $this->merchant('محل متأخّر', 'late@coex.om', '7770000000002', '8880000000002', true);
        $this->travel(-25)->hours();
        $late->forceFill(['metadata' => ['coexistence_sync' => ['started_at' => now()->toIso8601String()]]])->save();
        $this->travelBack();
        (new RequestWhatsAppCoexistenceSync($late->id))->handle();
        $this->assertCount(3, $this->smbCalls());
        $this->assertSame(['expired', 'expired'], [
            WhatsAppCoexistence::view($late->fresh())['contacts']['state'],
            WhatsAppCoexistence::view($late->fresh())['history']['state'],
        ]);
    }

    public function test_a_number_not_on_the_app_is_never_synced_nor_read_for_these_notices(): void
    {
        [$plain, , $line] = $this->merchant('محل عاديّ', 'plain@coex.om', '7770000000003', '8880000000003', false);

        WhatsAppCoexistence::start($line);
        $this->assertSame([], $this->smbCalls());
        $this->assertNull(WhatsAppCoexistence::view($line));

        $this->hook('smb_message_echoes', ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_('8880000000003'),
            'message_echoes' => [['from' => self::OWN_NUMBER, 'to' => self::CUSTOMER, 'id' => 'wamid.E-PLAIN', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'أهلًا']]]], '7770000000003');
        $this->hook('smb_app_state_sync', ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_('8880000000003'),
            'state_sync' => [['type' => 'contact', 'contact' => ['full_name' => 'سارة', 'user_id' => 'U-1', 'phone_number' => self::CUSTOMER], 'action' => 'add']]], '7770000000003');

        $this->assertSame(0, WhatsAppChatMessage::where('business_id', $plain->id)->count());
        $this->assertSame(0, WhatsAppContact::where('business_id', $plain->id)->count());
    }

    /* ─────────────────────────── ما يرسله التاجرُ من هاتفه ─────────────────────────── */

    public function test_what_the_merchant_sends_from_the_phone_lands_in_the_inbox_and_quiets_the_auto_reply(): void
    {
        $echo = ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_(),
            'contacts' => [['wa_id' => self::CUSTOMER, 'user_id' => 'U-1']],
            'message_echoes' => [['from' => self::OWN_NUMBER, 'to' => self::CUSTOMER, 'id' => 'wamid.ECHO-1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'طلبك جاهز']]]];
        $this->hook('smb_message_echoes', $echo);
        $this->hook('smb_message_echoes', $echo);

        $out = WhatsAppChatMessage::where('business_id', $this->shop->id)->get();
        $this->assertCount(1, $out, 'مرّةً واحدة ولو تكرّر الإشعار');
        $this->assertSame([WhatsAppChatMessage::OUT, WhatsAppCoexistence::PHONE_SENDER, 'طلبك جاهز'], [$out[0]->direction, $out[0]->sender_name, $out[0]->body]);
        $chat = WhatsAppChat::find($out[0]->chat_id);
        $this->assertSame(self::CUSTOMER, $chat->customer_phone);
        $this->assertNull($chat->last_inbound_at, 'ما يرسله التاجر لا يفتح نافذة الردّ');

        // ‏‏والزبونُ يكتب بعدها: التاجرُ يحادثه من هاتفه — فلا ردّ آليّ فوقه
        $this->inbound(self::CUSTOMER, 'wamid.IN-1');
        $this->assertSame(0, $this->sends);
        $this->assertSame('replied_from_phone', WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->value('reply_reason'));

        // ‏‏وزبونٌ آخر لم يحادثه التاجرُ يصله الترحيب كما كان
        $this->inbound('96899887766', 'wamid.IN-2');
        $this->assertSame(1, $this->sends);
    }

    /* ─────────────────────────── المحادثاتُ السابقة ─────────────────────────── */

    private function history(array $messages, int $progress, int $phase = 1, array $errors = []): void
    {
        $block = ['metadata' => ['phase' => $phase, 'chunk_order' => 1, 'progress' => $progress]];
        if ($errors !== []) {
            $block = ['errors' => $errors];
        }
        if ($messages !== []) {
            $block['threads'] = [['id' => self::CUSTOMER, 'context' => ['wa_id' => self::CUSTOMER], 'messages' => $messages]];
        }

        $this->hook('history', ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_(), 'history' => [$block]]);
    }

    public function test_history_lands_at_its_own_time_unread_stays_zero_and_the_window_stays_closed(): void
    {
        // ‏‏رسالةٌ حيّةٌ اليوم — ثمّ يصل تاريخُ الشهر الماضي بعدها
        $this->inbound(self::CUSTOMER, 'wamid.LIVE');
        $this->owner->forceFill(['whatsapp_inbox' => 'reply'])->save();
        $chat = WhatsAppChat::where('business_id', $this->shop->id)->first();
        WhatsAppInbox::markRead($chat, $this->owner);
        $sendsBefore = $this->sends;

        $old = now()->subDays(30)->timestamp;
        $this->history([
            ['from' => self::CUSTOMER, 'id' => 'wamid.H-1', 'timestamp' => (string) $old, 'type' => 'text', 'text' => ['body' => 'كم سعر الباقة؟'], 'history_context' => ['status' => 'read']],
            ['from' => self::OWN_NUMBER, 'id' => 'wamid.H-2', 'timestamp' => (string) ($old + 60), 'type' => 'text', 'text' => ['body' => '15 ريالًا'], 'history_context' => ['status' => 'read']],
            ['from' => self::CUSTOMER, 'id' => 'wamid.H-3', 'timestamp' => (string) ($old + 120), 'type' => 'media_placeholder', 'history_context' => ['status' => 'delivered']],
        ], 40);

        $rows = WhatsAppChatMessage::where('chat_id', $chat->id)->whereIn('wamid', ['wamid.H-1', 'wamid.H-2', 'wamid.H-3'])->orderBy('wamid')->get();
        $this->assertSame([WhatsAppChatMessage::IN, WhatsAppChatMessage::OUT, WhatsAppChatMessage::IN], $rows->pluck('direction')->all());
        $this->assertSame([WhatsAppChatMessage::HISTORY, 'read', WhatsAppChatMessage::HISTORY], $rows->pluck('status')->all());
        $this->assertSame($old, $rows[0]->created_at->timestamp, 'بوقتها الأصليّ');
        $this->assertSame(WhatsAppCoexistence::PHONE_SENDER, $rows[1]->sender_name);

        $this->assertSame(0, WhatsAppInbox::unreadChats($this->shop, $this->owner), 'المستوردُ لا يُعدّ غيرَ مقروء');
        $this->assertSame($sendsBefore, $this->sends, 'ولا يُطلق ردًّا آليًّا');
        $this->assertSame('wamid.LIVE', WhatsAppChatMessage::where('chat_id', $chat->id)->orderByDesc('created_at')->value('wamid'));
        $this->assertSame('مرحبا', $chat->fresh()->last_preview, 'ملخّصُ المحادثة لا يرجع إلى الوراء');

        // ‏‏والصفحةُ الأولى بالوقت: الحيّةُ أحدثُها ولو كُتبت قبل التاريخ
        $thread = WhatsAppInbox::thread($chat->fresh());
        $this->assertSame('مرحبا', collect($thread['messages'])->last()['body']);
        $this->assertSame('كم سعر الباقة؟', collect($thread['messages'])->first()['body']);

        $this->assertSame(['importing', 40], [WhatsAppCoexistence::view($this->line->fresh())['history']['state'], WhatsAppCoexistence::view($this->line->fresh())['history']['progress']]);
        $this->history([], 100, 2);
        $this->assertSame('done', WhatsAppCoexistence::view($this->line->fresh())['history']['state']);
    }

    public function test_a_merchant_who_declines_to_share_history_is_told_plainly(): void
    {
        $this->history([], 0, 0, [['code' => WhatsAppCoexistence::HISTORY_DECLINED, 'title' => 'History sync is turned off by the business from the WhatsApp Business App']]);

        $this->assertSame('declined', WhatsAppCoexistence::view($this->line->fresh())['history']['state']);
        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_the_media_of_an_old_message_is_attached_when_it_arrives_and_never_read_as_a_new_message(): void
    {
        $old = now()->subDays(3)->timestamp;
        $this->history([['from' => self::CUSTOMER, 'id' => 'wamid.H-IMG', 'timestamp' => (string) $old, 'type' => 'media_placeholder']], 50);

        $this->hook('history', ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_(),
            'messages' => [['from' => self::CUSTOMER, 'id' => 'wamid.H-IMG', 'timestamp' => (string) $old, 'type' => 'image', 'image' => ['caption' => 'هذا التصميم', 'mime_type' => 'image/jpeg', 'sha256' => 'x', 'id' => 'MEDIA-1']]]]);

        $row = WhatsAppChatMessage::where('wamid', 'wamid.H-IMG')->first();
        $this->assertSame(1, WhatsAppChatAttachment::where('message_id', $row->id)->count());
        $this->assertSame(1, WhatsAppChatMessage::count(), 'دفعةُ الوسائط ليست رسالةً جديدة');
        $this->assertSame(0, $this->sends, 'ولا يُردّ عليها آليًّا');
        $this->assertSame(0, WhatsAppInboundMessage::count());
    }

    /* ─────────────────────────── جهاتُ الاتّصال ─────────────────────────── */

    public function test_contacts_are_kept_edited_and_removed_but_never_become_customers(): void
    {
        $customers = Customer::count();
        $sync = fn (array $items) => $this->hook('smb_app_state_sync', ['messaging_product' => 'whatsapp', 'metadata' => $this->meta_(), 'state_sync' => $items]);

        $sync([
            ['type' => 'contact', 'contact' => ['full_name' => 'سارة أحمد', 'first_name' => 'سارة', 'phone_number' => self::CUSTOMER, 'user_id' => 'U-1'], 'action' => 'add', 'metadata' => ['timestamp' => '1']],
            ['type' => 'contact', 'contact' => ['full_name' => 'محمد', 'first_name' => 'محمد', 'user_id' => 'U-2'], 'action' => 'add', 'metadata' => ['timestamp' => '1']],
        ]);
        $sync([
            ['type' => 'contact', 'contact' => ['full_name' => 'سارة — زبونة دائمة', 'first_name' => 'سارة', 'phone_number' => self::CUSTOMER, 'user_id' => 'U-1'], 'action' => 'add', 'metadata' => ['timestamp' => '2']],
            ['type' => 'contact', 'contact' => ['user_id' => 'U-2'], 'action' => 'remove', 'metadata' => ['timestamp' => '2']],
        ]);

        $this->assertSame(2, WhatsAppContact::where('business_id', $this->shop->id)->count());
        $this->assertSame('سارة — زبونة دائمة', WhatsAppContact::where('user_id', 'U-1')->value('full_name'));
        $this->assertSame(self::CUSTOMER, WhatsAppContact::where('user_id', 'U-1')->value('phone'));
        $this->assertNull(WhatsAppContact::where('user_id', 'U-2')->value('phone'), 'الرقمُ قد لا يُرسل');
        $this->assertNotNull(WhatsAppContact::where('user_id', 'U-2')->value('removed_at'));
        $this->assertSame($customers, Customer::count(), 'لا يُنشأ منها عميل');
        $this->assertSame(1, WhatsAppCoexistence::view($this->line->fresh())['contacts']['count']);

        // ‏‏ومتجرٌ آخر على رقمه لا يرى منها شيئًا
        [$next] = $this->merchant('محل الجار', 'next@coex.om', '7770000000004', '8880000000004', true);
        $this->assertSame(0, WhatsAppContact::where('business_id', $next->id)->count());
    }

    /* ─────────────────────────── الانفصال ─────────────────────────── */

    public function test_a_number_unlinked_from_the_app_stops_and_says_why(): void
    {
        [, , $other] = $this->merchant('محل الجار', 'next@coex.om', '7770000000005', '8880000000005', true);
        $this->assertSame($this->line->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);

        $this->hook('account_update', ['event' => WhatsAppCoexistence::PARTNER_REMOVED,
            'waba_info' => ['waba_id' => self::WABA, 'owner_business_id' => '1234'],
            'disconnection_info' => ['reason' => 'PRIMARY_INACTIVITY', 'initiated_by' => 'SYSTEM']]);

        $line = $this->line->fresh();
        $this->assertSame([WhatsAppConnection::ERROR, WhatsAppCoexistence::UNLINKED], [$line->status, $line->last_error_code]);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()), 'لا يُرسل من رقمٍ انفصل');
        $this->assertStringContainsString('14 يومًا', WhatsAppCoexistence::view($line)['unlinked']['reason']);
        $this->assertSame(WhatsAppConnection::ACTIVE, $other->fresh()->status, 'حسابٌ آخر لا يُمسّ');

        // ‏‏و«عاد» لا يُعيده وحده — يعود بالربط من جديد
        $this->hook('account_update', ['event' => WhatsAppCoexistence::RECONNECTED], self::WABA);
        $this->assertSame(WhatsAppConnection::ERROR, $this->line->fresh()->status);
    }
}
