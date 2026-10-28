<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Support\Permissions;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * صندوقُ محادثات واتساب — لكلّ متجرٍ زبائنُه، ومن رقم محادثته وحده.
 *
 * ما يُحرس هنا:
 * - الإسناد: رقمُ المتجر المستقلّ يقينٌ من الوصلة؛ ورقمُ أبعاد المشترك لمتجرٍ
 *   واحدٍ راسل الزبون — ومتجران أو لا أحد = لا يُعرض لأحد.
 * - المكرّر: إشعارٌ وصل مرّتين يُكتب مرّة.
 * - الصلاحيات: بلا شيء / مشاهدة / ردّ — من العمود وحده، لا من الدور ولا القسم،
 *   ويمنحها صاحبُ النشاط وحده، وسحبُها يسري في الطلب التالي.
 * - العزل: محادثةُ متجرٍ آخر ومرفقُه 404 مهما كُتب في العنوان.
 * - الإرسال: من رقم المحادثة وحده — لا احتياطَ عند انقطاعٍ أو تبديلِ رقم أو
 *   انتهاءِ رمز، ولا نصَّ حرًّا بعد نافذة الأربعِ والعشرين.
 * - وما كان يعمل يبقى: الردُّ الآليّ وحالاتُ إشعارات الطلبات.
 */
class AShopReadsAndAnswersItsCustomersOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '96891234567';

    private Business $shopA;

    private Business $shopB;

    private Business $sharedC;

    private Business $sharedD;

    private WhatsAppConnection $abaad;

    private WhatsAppConnection $lineA;

    private WhatsAppConnection $lineB;

    private User $ownerA;

    private User $ownerB;

    private User $ownerC;

    private static int $seq = 0;

    private bool $metaRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v21.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        // ميتا المزيّفة: رفعُ ملفٍّ يُعيد معرّفَه، والإرسالُ معرّفَ رسالة — أو خطأً حين يُطلب
        Http::fake(fn (HttpRequest $r) => match (true) {
            $this->metaRefuses => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400),
            str_ends_with($r->url(), '/media') => Http::response(['id' => 'MEDIA-'.(++self::$seq)], 200),
            default => Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200),
        });
        Storage::fake('local');

        $this->abaad = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'ABAAD-PN',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'access_token' => 'platform-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->shopA = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $this->shopB = $this->shop('محل الجار', WhatsAppMode::BUSINESS_OWN);
        $this->sharedC = $this->shop('محل الهدايا', WhatsAppMode::ABAAD_SHARED);
        $this->sharedD = $this->shop('محل الشوكولاتة', WhatsAppMode::ABAAD_SHARED);

        $this->lineA = $this->own($this->shopA, 'PN-A');
        $this->lineB = $this->own($this->shopB, 'PN-B');

        $this->ownerA = $this->user($this->shopA, 'admin');
        $this->ownerB = $this->user($this->shopB, 'admin');
        $this->ownerC = $this->user($this->sharedC, 'admin');
    }

    /* ============================== أدوات ============================== */

    private function shop(string $name, string $mode): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => true,
            'whatsapp_mode' => $mode, 'whatsapp_inbox_enabled' => true,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
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

    private function user(Business $b, string $role, ?string $inbox = null, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => $role.'-'.(++self::$seq), 'email' => 'u'.self::$seq.'@a.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
            'whatsapp_inbox' => $inbox, 'permissions' => $permissions,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function webhook(array $value)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body)->assertOk();
    }

    private function inbound(string $pn, string $wamid, string $text = 'مرحبا', string $from = self::CUSTOMER): void
    {
        $this->webhook([
            'metadata' => ['phone_number_id' => $pn],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'سارة']]],
            'messages' => [[
                'id' => $wamid, 'from' => $from, 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
            ]],
        ]);
    }

    /** إشعارُ طلبٍ خرج من رقم أبعاد باسم هذا المتجر */
    private function notified(Business $b, string $phone = self::CUSTOMER): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'business_id' => $b->id, 'whatsapp_connection_id' => $this->abaad->id,
            'sender_phone_number_id' => 'ABAAD-PN', 'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY, 'direction' => 'outbound',
            'recipient_phone' => $phone, 'template_name' => 'order_ready', 'language_code' => 'ar',
            'dedupe_key' => 'n:'.(++self::$seq), 'status' => WhatsAppStatus::SENT,
            'provider_message_id' => 'wamid.NOTICE-'.self::$seq, 'quota_consumed' => false,
        ]);
    }

    private function chatOf(Business $b): ?WhatsAppChat
    {
        return WhatsAppChat::where('business_id', $b->id)->first();
    }

    private function sentTo(string $pn): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/'.$pn.'/messages'))->count();
    }

    private function reply(User $as, WhatsAppChat $chat, string $body = 'أهلًا، طلبك جاهز')
    {
        return $this->actingAs($as)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => $body]);
    }

    /* ============================== الإسناد ============================== */

    public function test_a_customer_writing_to_a_shops_own_number_lands_in_that_shop_alone(): void
    {
        $this->inbound('PN-A', 'wamid.IN-1', 'هل الباقة متوفّرة؟');
        $this->inbound('PN-B', 'wamid.IN-2', 'سؤالٌ لمحلّ الجار');

        $chatA = $this->chatOf($this->shopA);
        $this->assertNotNull($chatA);
        $this->assertSame(['هل الباقة متوفّرة؟'], WhatsAppChatMessage::where('chat_id', $chatA->id)->pluck('body')->all());
        $this->assertSame('PN-A', $chatA->phone_number_id);
        $this->assertSame('سارة', $chatA->profile_name);

        $this->assertSame(['سؤالٌ لمحلّ الجار'], WhatsAppChatMessage::where('business_id', $this->shopB->id)->pluck('body')->all());
        $this->assertSame(0, WhatsAppChat::whereIn('business_id', [$this->sharedC->id, $this->sharedD->id])->count());
    }

    public function test_a_webhook_delivered_twice_is_written_once(): void
    {
        $this->inbound('PN-A', 'wamid.TWICE');
        $this->inbound('PN-A', 'wamid.TWICE');

        $this->assertSame(1, WhatsAppChatMessage::where('wamid', 'wamid.TWICE')->count());
    }

    public function test_a_number_we_never_linked_writes_nothing(): void
    {
        $this->inbound('PN-STRANGER', 'wamid.X');

        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_a_disconnected_own_number_writes_nothing(): void
    {
        $this->lineA->update(['status' => WhatsAppConnection::INACTIVE, 'disconnected_at' => now()]);

        $this->inbound('PN-A', 'wamid.AFTER-UNLINK');

        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_on_abaads_shared_number_a_reply_goes_to_the_one_shop_that_wrote_to_that_customer(): void
    {
        $this->notified($this->sharedC);

        $this->inbound('ABAAD-PN', 'wamid.SHARED-1', 'متى يصل طلبي؟');

        $chat = $this->chatOf($this->sharedC);
        $this->assertNotNull($chat);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $chat->source_mode);
        $this->assertSame(1, WhatsAppChatMessage::count(), 'ولا يُكتب لمتجرٍ غيره');
    }

    public function test_on_the_shared_number_two_shops_or_none_means_nobody_sees_the_message(): void
    {
        // غريبٌ لم يراسله متجرٌ قطّ
        $this->inbound('ABAAD-PN', 'wamid.NOBODY');
        $this->assertSame(0, WhatsAppChatMessage::count());

        // ومتجران راسلاه: لا يُعرف لمن كتب
        $this->notified($this->sharedC);
        $this->notified($this->sharedD);
        $this->inbound('ABAAD-PN', 'wamid.TWO-SHOPS');

        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_a_shop_closed_to_abaads_number_gets_no_shared_messages(): void
    {
        $this->sharedC->update(['whatsapp_shared_allowed' => false]);
        $this->notified($this->sharedC);

        $this->inbound('ABAAD-PN', 'wamid.CLOSED');

        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_a_merchants_own_phone_on_the_shared_number_stays_with_support(): void
    {
        $this->user($this->sharedC, 'sales')->update(['phone' => '9123 4567']);
        $this->notified($this->sharedC);

        $this->inbound('ABAAD-PN', 'wamid.MERCHANT');

        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    public function test_a_shop_without_the_inbox_records_nothing_and_its_auto_reply_still_answers(): void
    {
        $this->shopA->update(['whatsapp_inbox_enabled' => false]);
        WhatsAppAutoReply::save($this->shopA->id, ['enabled' => true, 'ar' => 'شكرًا لتواصلك', 'cooldown_hours' => 12]);

        $this->inbound('PN-A', 'wamid.LEGACY');

        $this->assertSame(0, WhatsAppChatMessage::count());
        $this->assertSame(WhatsAppInboundMessage::SENT, WhatsAppInboundMessage::where('wamid', 'wamid.LEGACY')->value('reply_status'));
        $this->assertSame(1, $this->sentTo('PN-A'));
        $this->actingAs($this->ownerA)->get(route('admin.whatsappInbox.index'))->assertNotFound();
    }

    public function test_the_inbox_records_before_the_auto_reply_answers_and_both_work(): void
    {
        WhatsAppAutoReply::save($this->shopA->id, ['enabled' => true, 'ar' => 'شكرًا لتواصلك', 'cooldown_hours' => 12]);

        $this->inbound('PN-A', 'wamid.BOTH');

        $this->assertSame(1, WhatsAppChatMessage::where('wamid', 'wamid.BOTH')->count());
        $this->assertSame(WhatsAppInboundMessage::SENT, WhatsAppInboundMessage::where('wamid', 'wamid.BOTH')->value('reply_status'));
    }

    /* ============================== الصلاحيات ============================== */

    public function test_view_only_reads_but_cannot_reply_and_the_screen_says_so(): void
    {
        $this->inbound('PN-A', 'wamid.V');
        $chat = $this->chatOf($this->shopA);
        $viewer = $this->user($this->shopA, 'sales', WhatsAppInbox::VIEW);

        $page = $this->actingAs($viewer)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($page['canReply']);
        $this->assertSame('مرحبا', $page['thread']['messages'][0]['body']);

        $this->reply($viewer, $chat)->assertForbidden();
        $this->assertSame(0, $this->sentTo('PN-A'));
    }

    public function test_reply_permission_sends_from_the_chats_number_and_records_who_sent_it(): void
    {
        $this->inbound('PN-A', 'wamid.R');
        $chat = $this->chatOf($this->shopA);
        $replier = $this->user($this->shopA, 'sales', WhatsAppInbox::REPLY);

        $this->reply($replier, $chat)->assertRedirect()->assertSessionHasNoErrors();

        $out = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->sole();
        $this->assertSame($replier->id, (int) $out->sender_user_id);
        $this->assertSame($replier->name, $out->sender_name);
        $this->assertSame('PN-A', $out->phone_number_id);
        $this->assertSame($this->lineA->id, (int) $out->whatsapp_connection_id);
        $this->assertSame(WhatsAppStatus::SENT, $out->status);
        $this->assertSame(1, $this->sentTo('PN-A'));
        $this->assertSame(0, $this->sentTo('ABAAD-PN'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/PN-A/messages') && $r['to'] === self::CUSTOMER);
    }

    public function test_no_permission_manager_role_marketing_or_integrations_do_not_open_the_inbox(): void
    {
        $this->inbound('PN-A', 'wamid.N');

        $none = $this->user($this->shopA, 'sales');
        $manager = $this->user($this->shopA, 'manager');
        $marketer = $this->user($this->shopA, 'sales', null, ['dashboard', 'marketing', 'integrations']);

        foreach ([$none, $manager, $marketer] as $u) {
            $this->actingAs($u)->get(route('admin.whatsappInbox.index'))->assertForbidden();
            $this->actingAs($u)->get(route('admin.whatsappInbox.poll'))->assertForbidden();
            $this->assertFalse($u->may(Permissions::INBOX_VIEW));
        }

        $this->actingAs($this->ownerA)->get(route('admin.whatsappInbox.index'))->assertOk();
    }

    public function test_a_staff_member_with_only_the_inbox_enters_the_panel_and_lands_on_it(): void
    {
        $cashier = $this->user($this->shopA, 'cashier', WhatsAppInbox::VIEW);

        $this->assertTrue(Permissions::entersPanel($cashier));
        $this->assertSame(route('admin.whatsappInbox.index'), Permissions::panelEntry($cashier));
        $this->actingAs($cashier)->get(route('admin.whatsappInbox.index'))->assertOk();

        // ودخولُه بالمحادثات لا يفتح له «لوحة التحكم» التي يحملها دورُه
        $this->actingAs($cashier)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($cashier)->get(route('admin.customers.index'))->assertForbidden();
    }

    public function test_another_shops_chat_message_and_attachment_cannot_be_reached_by_changing_the_url(): void
    {
        $this->inbound('PN-B', 'wamid.B');
        $chatB = $this->chatOf($this->shopB);
        $file = WhatsAppInbox::keep(WhatsAppChatMessage::where('chat_id', $chatB->id)->first(), 'PDF', 'application/pdf', 'b.pdf');

        $replierA = $this->user($this->shopA, 'sales', WhatsAppInbox::REPLY);

        $props = $this->actingAs($replierA)
            ->get(route('admin.whatsappInbox.index', ['c' => $chatB->id, 'business_id' => $this->shopB->id]))
            ->assertOk()->viewData('page')['props'];
        $this->assertNull($props['thread']);
        $this->assertSame([], $props['chats']);

        $this->actingAs($replierA)->get(route('admin.whatsappInbox.older', $chatB->id))->assertNotFound();
        $this->reply($replierA, $chatB)->assertNotFound();
        $this->actingAs($replierA)->get(route('admin.whatsappInbox.attachment', $file->id))->assertNotFound();
        $this->assertSame(0, $this->sentTo('PN-B'));

        $this->actingAs($this->ownerB)->get(route('admin.whatsappInbox.attachment', $file->id))->assertOk();
    }

    public function test_withdrawing_the_permission_stops_the_open_page_on_its_next_request(): void
    {
        $this->inbound('PN-A', 'wamid.W');
        $chat = $this->chatOf($this->shopA);
        $replier = $this->user($this->shopA, 'sales', WhatsAppInbox::REPLY, ['dashboard']);

        $this->actingAs($replier)->get(route('admin.whatsappInbox.poll'))->assertOk()->assertJson(['can_reply' => true]);

        // صاحبُ النشاط يخفّضه إلى المشاهدة — والصفحةُ عنده مفتوحة
        $this->actingAs($this->ownerA)->put(route('admin.employees.update', $replier->id), $this->employeeForm($replier, WhatsAppInbox::VIEW))
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppInbox::VIEW, $replier->fresh()->whatsapp_inbox);

        $this->actingAs($replier->fresh())->get(route('admin.whatsappInbox.poll'))->assertOk()->assertJson(['can_reply' => false]);
        $this->reply($replier->fresh(), $chat)->assertForbidden();

        // ثمّ يسحبها كلَّها
        $this->actingAs($this->ownerA)->put(route('admin.employees.update', $replier->id), $this->employeeForm($replier, 'none'));
        $this->assertNull($replier->fresh()->whatsapp_inbox);
        $this->actingAs($replier->fresh())->get(route('admin.whatsappInbox.poll'))->assertForbidden();
        $this->actingAs($replier->fresh())->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertForbidden();
        $this->assertSame(0, $this->sentTo('PN-A'));
    }

    /** @return array<string, mixed> */
    private function employeeForm(User $u, string $inbox, string $title = 'بائع', string $role = 'sales'): array
    {
        JobTitle::firstOrCreate(['business_id' => $u->business_id, 'name' => $title], ['role' => $role]);

        return [
            'name' => $u->name, 'job_title' => $title, 'phone' => '',
            'manual_permissions' => $u->permissions !== null, 'permissions' => $u->permissions ?? [],
            'whatsapp_inbox' => $inbox,
        ];
    }

    public function test_only_the_owner_grants_inbox_access_and_new_staff_start_with_none(): void
    {
        $manager = $this->user($this->shopA, 'manager');
        $staff = $this->user($this->shopA, 'sales', null, ['dashboard']);

        $this->assertNull($staff->whatsapp_inbox);

        // مديرُ الفرع يملك «الموظفين» — ولا يمنح المحادثات
        $this->actingAs($manager)->put(route('admin.employees.update', $staff->id), $this->employeeForm($staff, WhatsAppInbox::REPLY))
            ->assertSessionHasErrors('whatsapp_inbox');
        $this->assertNull($staff->fresh()->whatsapp_inbox);

        // ولا يرفع موظّفٌ نفسَه — مديرُ فرعٍ يقرأ المحادثات لا يمنح نفسَه الردّ
        $viewer = $this->user($this->shopA, 'manager', WhatsAppInbox::VIEW);
        $self = $this->employeeForm($viewer, WhatsAppInbox::REPLY, 'مدير', 'manager');
        unset($self['manual_permissions'], $self['permissions']);
        $this->actingAs($viewer)->put(route('admin.employees.update', $viewer->id), $self)
            ->assertSessionHasErrors(['whatsapp_inbox' => __('صلاحيات محادثات واتساب يمنحها صاحب النشاط وحده.')]);
        $this->assertSame(WhatsAppInbox::VIEW, $viewer->fresh()->whatsapp_inbox);

        // وصاحبُ النشاط يمنح
        $this->actingAs($this->ownerA)->put(route('admin.employees.update', $staff->id), $this->employeeForm($staff, WhatsAppInbox::REPLY))
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppInbox::REPLY, $staff->fresh()->whatsapp_inbox);
        $this->assertDatabaseHas('activity_logs', ['business_id' => $this->shopA->id, 'subject_id' => $staff->id]);

        // ومتجرٌ آخر لا يمسّ موظّفَه
        $this->actingAs($this->ownerB)->put(route('admin.employees.update', $staff->id), $this->employeeForm($staff, 'none'))
            ->assertNotFound();
        $this->assertSame(WhatsAppInbox::REPLY, $staff->fresh()->whatsapp_inbox);
    }

    /* ============================== الإرسال ============================== */

    public function test_a_changed_number_keeps_the_old_chat_readable_and_never_answers_it_from_the_new_one(): void
    {
        $this->inbound('PN-A', 'wamid.OLD');
        $chat = $this->chatOf($this->shopA);

        $this->lineA->update(['phone_number_id' => 'PN-A2']);

        $this->reply($this->ownerA, $chat)->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sentTo('PN-A2') + $this->sentTo('PN-A') + $this->sentTo('ABAAD-PN'));

        $props = $this->actingAs($this->ownerA)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->viewData('page')['props'];
        $this->assertSame('مرحبا', $props['thread']['messages'][0]['body']);
        $this->assertNotNull($props['thread']['blocked']);
    }

    public function test_a_broken_own_connection_never_falls_back_to_abaads_number(): void
    {
        $this->inbound('PN-A', 'wamid.BROKEN');
        $chat = $this->chatOf($this->shopA);

        $this->lineA->update(['status' => WhatsAppConnection::INACTIVE, 'disconnected_at' => now()]);

        $this->reply($this->ownerA, $chat)->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sentTo('ABAAD-PN') + $this->sentTo('PN-A'));
        $this->assertSame(0, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
    }

    public function test_an_expired_meta_token_blocks_the_reply_and_says_why(): void
    {
        $this->inbound('PN-A', 'wamid.EXP');
        $chat = $this->chatOf($this->shopA);

        $this->lineA->update(['token_expires_at' => now()->subDay()]);

        $this->reply($this->ownerA, $chat)->assertSessionHasErrors(['body' => __('انتهت صلاحية ربط رقم متجرك لدى ميتا — أعد الربط من «التطبيقات التكاملية ‹ واتساب». ولا يُرسَل الردّ من رقمٍ آخر.')]);
        $this->assertSame(0, $this->sentTo('PN-A') + $this->sentTo('ABAAD-PN'));
    }

    public function test_after_twenty_four_hours_no_free_text_goes_out(): void
    {
        $this->inbound('PN-A', 'wamid.LATE');
        $chat = $this->chatOf($this->shopA);
        $chat->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

        $this->reply($this->ownerA, $chat)->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sentTo('PN-A'));
    }

    public function test_a_shared_chat_is_answered_from_abaads_number_and_never_after_the_shop_moves_to_its_own(): void
    {
        $this->notified($this->sharedC);
        $this->inbound('ABAAD-PN', 'wamid.SHARED-R');
        $chat = $this->chatOf($this->sharedC);

        $this->reply($this->ownerC, $chat)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sentTo('ABAAD-PN'));

        $this->sharedC->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->reply($this->ownerC, $chat)->assertSessionHasErrors('body');
        $this->assertSame(1, $this->sentTo('ABAAD-PN'));
    }

    public function test_a_failed_send_is_recorded_on_its_row_with_the_reason(): void
    {
        $this->inbound('PN-A', 'wamid.F');
        $chat = $this->chatOf($this->shopA);
        $this->metaRefuses = true;

        $this->reply($this->ownerA, $chat);

        $out = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $out->status);
        $this->assertSame('Message undeliverable', $out->error_message);
    }

    public function test_an_attachment_goes_out_as_its_own_row(): void
    {
        $this->inbound('PN-A', 'wamid.FILE');
        $chat = $this->chatOf($this->shopA);
        $this->actingAs($this->ownerA)->post(route('admin.whatsappInbox.reply', $chat->id), [
            'body' => 'هذه الفاتورة',
            'files' => [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $rows = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('هذه الفاتورة', $rows[0]->body);
        $this->assertSame('document', $rows[1]->media_type);
        $this->assertSame(1, $rows[1]->attachments()->count());
        $this->assertSame([WhatsAppStatus::SENT, WhatsAppStatus::SENT], $rows->pluck('status')->all());
        $this->assertNotSame($rows[0]->wamid, $rows[1]->wamid, 'لكلّ جزءٍ معرّفُه وحالُه');
    }

    /* ============================== الحال والسجلّ ============================== */

    public function test_delivery_and_read_come_back_on_the_reply_and_another_line_cannot_touch_it(): void
    {
        $this->inbound('PN-A', 'wamid.S');
        $this->reply($this->ownerA, $this->chatOf($this->shopA));
        $out = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->sole();

        // إشعارٌ موقَّعٌ على خطّ متجرٍ آخر بمعرّف هذا الردّ — لا يمسّه
        $this->webhook(['metadata' => ['phone_number_id' => 'PN-B'], 'statuses' => [['id' => $out->wamid, 'status' => 'read']]]);
        $this->assertSame(WhatsAppStatus::SENT, $out->fresh()->status);

        $this->webhook(['metadata' => ['phone_number_id' => 'PN-A'], 'statuses' => [['id' => $out->wamid, 'status' => 'read']]]);
        $this->webhook(['metadata' => ['phone_number_id' => 'PN-A'], 'statuses' => [['id' => $out->wamid, 'status' => 'delivered']]]);
        $this->assertSame(WhatsAppStatus::READ, $out->fresh()->status, 'والحالُ لا ترجع إلى الوراء');
    }

    public function test_order_notification_statuses_still_reach_the_notification_log(): void
    {
        $notice = $this->notified($this->sharedC);

        $this->webhook(['metadata' => ['phone_number_id' => 'ABAAD-PN'], 'statuses' => [['id' => $notice->provider_message_id, 'status' => 'delivered']]]);

        $this->assertSame(WhatsAppStatus::DELIVERED, $notice->fresh()->status);
    }

    public function test_a_reply_row_cannot_be_rewritten_or_deleted(): void
    {
        $this->inbound('PN-A', 'wamid.SEAL');
        $this->reply($this->ownerA, $this->chatOf($this->shopA));
        $out = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->sole();

        foreach ([['sender_name' => 'غيره'], ['sender_user_id' => $this->ownerB->id], ['body' => 'نصٌّ آخر']] as $change) {
            try {
                $out->fresh()->forceFill($change)->save();
                $this->fail('عُدّل سجلُّ الردّ: '.json_encode($change, JSON_UNESCAPED_UNICODE));
            } catch (LogicException) {
            }
        }

        $this->expectException(LogicException::class);
        $out->fresh()->delete();
    }

    public function test_unread_counts_belong_to_each_reader(): void
    {
        $this->inbound('PN-A', 'wamid.U1');
        $this->inbound('PN-A', 'wamid.U2');
        $chat = $this->chatOf($this->shopA);
        $viewer = $this->user($this->shopA, 'sales', WhatsAppInbox::VIEW);

        $this->assertSame(2, WhatsAppInbox::unreadTotal($viewer));
        $this->actingAs($viewer)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk();

        $this->assertSame(0, WhatsAppInbox::unreadTotal($viewer->fresh()));
        $this->assertSame(2, WhatsAppInbox::unreadTotal($this->ownerA), 'وما قرأه زميلُه لا يُقرأ عنه');
    }

    public function test_the_screen_never_receives_the_token_or_the_phone_number_id(): void
    {
        $this->inbound('PN-A', 'wamid.SECRET');
        $chat = $this->chatOf($this->shopA);

        $html = $this->actingAs($this->ownerA)->get(route('admin.whatsappInbox.index', ['c' => $chat->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('shop-token-value-PN-A', $html);
        $this->assertStringNotContainsString('PN-A', $html);
        $this->assertStringNotContainsString('WABA-PN-A', $html);
    }
}
