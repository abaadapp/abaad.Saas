<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صندوقٌ واحدٌ للمتجر — يراه صاحبُه وكلُّ من مُنحه، ويردّ فيه كلٌّ باسمه من رقم المتجر.
 *
 * ما يُحرس هنا:
 * - المشاركة: صاحبُ النشاط وموظّفو المشاهدة والردّ يرون المحادثاتِ نفسَها،
 *   وردُّ كلٍّ يظهر للباقين باسم كاتبه ومن رقم المتجر وحده.
 * - التحديث: ختمُ الصندوق يتبدّل بردّ زميل، وبرسالة زبون، وبإشعارٍ آليّ
 *   خرج أو تبدّلت حالُه — فتتحدّث الشاشاتُ المفتوحةُ كلُّها.
 * - غيرُ المقروء لكلّ قارئٍ وحده، ومرشِّحُه لا يُخفي عن زميلٍ ما قرأه غيرُه.
 * - الضغطتان ردٌّ واحد، وردٌّ رُدّ لسببه لا يحرق مفتاحَه.
 * - المنحُ الجماعيّ: لصاحب النشاط ومديره المخوَّل وحدهما، ولا للنفس ولا
 *   لصاحب النشاط ولا لموظّف متجرٍ آخر — والسحبُ يُغلق المفتوحَ في طلبه التالي.
 * - تفاصيلُ العميل: طلباتُه وفواتيرُه لمن يملك قسمَها وحده.
 */
class AShopAndItsStaffShareOneWhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = '96891234567';

    private const OTHER = '96899887766';

    private Business $shop;

    private Business $neighbour;

    private WhatsAppConnection $line;

    private User $owner;

    private User $viewer;

    private User $replier;

    private User $none;

    private static int $seq = 0;

    /** ميتا ترفض كلَّ شيء */
    private bool $metaRefuses = false;

    /** ميتا ترفض رفعَ الملفّ رقم كذا — ما سواه يُرفع */
    private ?int $failUpload = null;

    private int $uploads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v21.0',
            // ولا يقع متجرُ الاختبار على قوائم متجرٍ حقيقيّ بمعرّفه
            'whatsapp.ribbon_businesses' => [],
            'whatsapp.invoice_task_notices_businesses' => [],
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $r) => match (true) {
            $this->metaRefuses => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400),
            str_ends_with($r->url(), '/media') => ++$this->uploads === $this->failUpload
                ? Http::response(['error' => ['code' => 131053, 'message' => 'Media upload error']], 400)
                : Http::response(['id' => 'MEDIA-'.(++self::$seq)], 200),
            default => Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200),
        });
        Storage::fake('local');

        $this->shop = $this->shop('محل الورد');
        $this->neighbour = $this->shop('محل الجار');
        $this->line = $this->own($this->shop, 'PN-A');
        $this->own($this->neighbour, 'PN-B');

        $this->owner = $this->user($this->shop, 'admin', null, null, 'سعود');
        $this->viewer = $this->user($this->shop, 'sales', WhatsAppInbox::VIEW, ['dashboard'], 'سهى');
        $this->replier = $this->user($this->shop, 'sales', WhatsAppInbox::REPLY, ['dashboard'], 'منى');
        $this->none = $this->user($this->shop, 'sales', null, ['dashboard'], 'خالد');
    }

    /* ============================== أدوات ============================== */

    private function shop(string $name): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        // والردُّ التلقائيّ مطفأ — هذه الحرّاسُ تعدّ ما يُخرجه الصندوقُ وحده
        Setting::create(['business_id' => $b->id, 'key' => WhatsAppAutoReply::ENABLED, 'value' => '0']);

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

    private function user(Business $b, string $role, ?string $inbox, ?array $permissions, string $name): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => $name, 'email' => 'u'.(++self::$seq).'@a.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
            'whatsapp_inbox' => $inbox, 'permissions' => $permissions,
        ]);
    }

    private function inbound(string $pn, string $wamid, string $text = 'مرحبا', string $from = self::CUSTOMER): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => $pn],
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'سارة']]],
                'messages' => [[
                    'id' => $wamid, 'from' => $from, 'type' => 'text',
                    'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
                ]],
            ]]],
        ]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body)->assertOk();
    }

    private function chatWith(string $phone, ?Business $shop = null): WhatsAppChat
    {
        return WhatsAppChat::where('business_id', ($shop ?? $this->shop)->id)->where('customer_phone', $phone)->sole();
    }

    /** @return array<string, mixed> */
    private function screen(User $as, array $query = []): array
    {
        return $this->actingAs($as)->get(route('admin.whatsappInbox.index', $query))->assertOk()->viewData('page')['props'];
    }

    private function reply(User $as, WhatsAppChat $chat, string $body, ?string $key = null)
    {
        return $this->actingAs($as)->post(route('admin.whatsappInbox.reply', $chat->id), array_filter(['body' => $body, 'client_key' => $key]));
    }

    private function sent(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))->count();
    }

    private function stamp(User $as): string
    {
        return (string) $this->actingAs($as)->get(route('admin.whatsappInbox.poll'))->assertOk()->json('stamp');
    }

    /* ============================== المشاركة ============================== */

    public function test_the_owner_and_every_granted_employee_see_the_same_chats_and_nobody_else_does(): void
    {
        $this->inbound('PN-A', 'wamid.1', 'هل الباقة متوفّرة؟');
        $this->inbound('PN-A', 'wamid.2', 'أريد التوصيل اليوم', self::OTHER);
        $this->inbound('PN-B', 'wamid.3', 'لمحلّ الجار');

        $keys = fn (User $u) => collect($this->screen($u)['chats'])->pluck('key')->sort()->values()->all();

        $this->assertCount(2, $keys($this->owner));
        $this->assertSame($keys($this->owner), $keys($this->viewer));
        $this->assertSame($keys($this->owner), $keys($this->replier));

        // ومن لم يُمنح لا يرى شيئًا — ولا يفتح بالسؤال الدوريّ
        $this->actingAs($this->none)->get(route('admin.whatsappInbox.index'))->assertForbidden();
        $this->actingAs($this->none)->get(route('admin.whatsappInbox.poll'))->assertForbidden();

        // ومتجرُ الجار يرى زبونه وحده
        $neighbourOwner = $this->user($this->neighbour, 'admin', null, null, 'جار');
        $this->assertSame(['لمحلّ الجار'], collect($this->screen($neighbourOwner)['chats'])->pluck('preview')->all());
    }

    public function test_a_reply_by_anyone_shows_to_everyone_with_its_writers_name_and_leaves_from_the_shops_number(): void
    {
        $this->inbound('PN-A', 'wamid.R', 'متى يصل؟');
        $chat = $this->chatWith(self::CUSTOMER);

        $this->reply($this->replier, $chat, 'خلال ساعة')->assertSessionHasNoErrors();
        $this->reply($this->owner, $chat, 'وشكرًا لصبرك')->assertSessionHasNoErrors();

        foreach ([$this->owner, $this->viewer, $this->replier] as $reader) {
            $outs = collect($this->screen($reader, ['c' => $chat->id])['thread']['messages'])->where('kind', 'out')->values();

            $this->assertSame(['خلال ساعة', 'وشكرًا لصبرك'], $outs->pluck('body')->all());
            $this->assertSame(['منى', 'سعود'], $outs->pluck('sender')->all());
        }

        // والردّان من رقم المتجر وحده
        $this->assertSame(['PN-A', 'PN-A'], WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->orderBy('id')->pluck('phone_number_id')->all());
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/PN-A/messages') && $r['to'] === self::CUSTOMER);

        // والمشاهِدُ يقرأ ولا يردّ
        $this->reply($this->viewer, $chat, 'من المشاهِد')->assertForbidden();
        $this->assertSame(2, $this->sent());
    }

    public function test_every_open_screen_learns_of_a_colleagues_reply_a_customer_message_and_an_automatic_notice(): void
    {
        $this->inbound('PN-A', 'wamid.S1');
        $chat = $this->chatWith(self::CUSTOMER);

        $first = $this->stamp($this->viewer);

        // ردُّ زميل
        $this->reply($this->replier, $chat, 'أهلًا')->assertSessionHasNoErrors();
        $afterReply = $this->stamp($this->viewer);
        $this->assertNotSame($first, $afterReply);

        // ورسالةُ الزبون
        $this->inbound('PN-A', 'wamid.S2', 'شكرًا');
        $afterInbound = $this->stamp($this->viewer);
        $this->assertNotSame($afterReply, $afterInbound);

        // وإشعارُ طلبٍ خرج آليًّا من رقم المتجر
        $notice = WhatsAppMessage::create([
            'business_id' => $this->shop->id, 'whatsapp_connection_id' => $this->line->id,
            'sender_phone_number_id' => 'PN-A', 'source_mode' => WhatsAppMode::BUSINESS_OWN,
            'event_type' => WhatsAppEvent::ORDER_READY, 'direction' => 'outbound',
            'recipient_phone' => self::CUSTOMER, 'template_name' => 'order_ready', 'language_code' => 'ar',
            'dedupe_key' => 'n:'.(++self::$seq), 'status' => WhatsAppStatus::SENT,
            'provider_message_id' => 'wamid.NOTICE-'.self::$seq, 'quota_consumed' => false,
        ]);
        $afterNotice = $this->stamp($this->viewer);
        $this->assertNotSame($afterInbound, $afterNotice);

        // ثمّ تبدّلت حالُه — قرأه الزبون
        $this->travel(1)->seconds();
        $notice->forceFill(['status' => WhatsAppStatus::READ])->save();
        $this->assertNotSame($afterNotice, $this->stamp($this->viewer));

        // وإشعارُ متجرٍ آخر لا يحرّك ختمَ هذا المتجر
        $quiet = $this->stamp($this->viewer);
        $this->inbound('PN-B', 'wamid.S3');
        $this->assertSame($quiet, $this->stamp($this->viewer));
    }

    /**
     * واسمُ الكاتب للشاشة وحدها: ما يصل الزبونَ نصُّ الردّ كما كُتب — لا «منى:»
     * ولا «سعود» ولا توقيع. والاسمُ محفوظٌ على صفّ الردّ تقرؤه الإدارة.
     */
    public function test_the_customer_receives_the_reply_text_alone_never_the_writers_name(): void
    {
        $this->inbound('PN-A', 'wamid.N1', 'متى يصل؟');
        $chat = $this->chatWith(self::CUSTOMER);

        $this->reply($this->replier, $chat, 'خلال ساعة')->assertSessionHasNoErrors();
        $this->reply($this->owner, $chat, 'وشكرًا لصبرك')->assertSessionHasNoErrors();

        $texts = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/PN-A/messages'))
            ->map(fn (array $pair) => $pair[0]['text']['body'] ?? null)->values()->all();

        $this->assertSame(['خلال ساعة', 'وشكرًا لصبرك'], $texts);
        foreach (Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages')) as [$request]) {
            $this->assertStringNotContainsString('منى', $request->body());
            $this->assertStringNotContainsString('سعود', $request->body());
        }

        $this->assertSame(['منى', 'سعود'], WhatsAppChatMessage::where('chat_id', $chat->id)
            ->where('direction', WhatsAppChatMessage::OUT)->orderBy('id')->pluck('sender_name')->all());
    }

    /**
     * ومحادثةٌ طويلة — أطولُ من صفحتين — تُقرأ كلُّها: المفتوحُ وصفحاتُ «الأقدم»
     * معًا هي المحادثةُ بعينها، كلُّ رسالةٍ مرّةً، بترتيب كتابتها، وكلُّ ردٍّ باسم
     * كاتبه — زبونٌ وموظّفان وصاحبُ المتجر.
     */
    public function test_a_long_chat_reads_back_whole_in_order_with_nothing_twice_or_missing(): void
    {
        $writers = [null, 'منى', null, 'سعود', null, 'سهى'];
        $total = WhatsAppInbox::THREAD_PAGE * 2 + 17;
        $this->travel(-$total - 1)->minutes();

        $this->inbound('PN-A', 'wamid.L0', 'السلام عليكم');
        $chat = $this->chatWith(self::CUSTOMER);

        for ($i = 1; $i < $total; $i++) {
            $this->travel(1)->minutes();
            $name = $writers[$i % count($writers)];
            WhatsAppChatMessage::create([
                'business_id' => $this->shop->id, 'chat_id' => $chat->id,
                'direction' => $name === null ? WhatsAppChatMessage::IN : WhatsAppChatMessage::OUT,
                'body' => 'رسالة '.$i, 'wamid' => 'wamid.L'.$i,
                'whatsapp_connection_id' => $this->line->id, 'phone_number_id' => 'PN-A',
                'sender_name' => $name,
                'status' => $name === null ? WhatsAppChatMessage::RECEIVED : WhatsAppStatus::DELIVERED,
                'received_at' => $name === null ? now() : null,
            ]);
            // وردّان في الثانية نفسِها — يفصلهما المعرّف
            if ($i % 10 === 0) {
                WhatsAppChatMessage::create([
                    'business_id' => $this->shop->id, 'chat_id' => $chat->id, 'direction' => WhatsAppChatMessage::OUT,
                    'body' => 'رسالة '.$i.'ب', 'wamid' => 'wamid.L'.$i.'b',
                    'whatsapp_connection_id' => $this->line->id, 'phone_number_id' => 'PN-A',
                    'sender_name' => 'منى', 'status' => WhatsAppStatus::SENT,
                ]);
            }
        }
        $this->travelBack();

        $page = $this->screen($this->owner, ['c' => $chat->id])['thread'];
        $read = $page['messages'];
        $pages = 1;

        while ($page['has_more']) {
            $page = $this->actingAs($this->owner)
                ->getJson(route('admin.whatsappInbox.older', ['chat' => $chat->id, 'before' => $page['before']]))->assertOk()->json();
            $read = [...$page['messages'], ...$read];
            $pages++;
        }

        $expected = WhatsAppChatMessage::where('chat_id', $chat->id)->orderBy('id')->get();

        $this->assertSame(3, $pages);
        $this->assertSame($expected->map(fn ($m) => 'm'.$m->id)->all(), array_column($read, 'key'), 'كلُّ رسالةٍ مرّةً وبترتيبها');
        $this->assertSame($expected->pluck('body')->all(), array_column($read, 'body'));
        $this->assertSame(
            $expected->where('direction', WhatsAppChatMessage::OUT)->pluck('sender_name')->values()->all(),
            collect($read)->where('kind', 'out')->pluck('sender')->values()->all(),
        );
        $this->assertSame(['منى', 'سعود', 'سهى'], collect($read)->where('kind', 'out')->pluck('sender')->unique()->values()->all());
    }

    /* ============================== غيرُ المقروء ============================== */

    public function test_unread_belongs_to_each_reader_and_the_unread_filter_keeps_only_his(): void
    {
        $this->inbound('PN-A', 'wamid.U1', 'أوّل');
        $this->inbound('PN-A', 'wamid.U2', 'ثانٍ', self::OTHER);
        $first = $this->chatWith(self::CUSTOMER);

        // صاحبُ النشاط يفتح الأولى — فتُقرأ عنده وحده
        $this->screen($this->owner, ['c' => $first->id]);

        $owner = $this->screen($this->owner, ['f' => WhatsAppInbox::UNREAD]);
        $this->assertSame(1, $owner['unreadChats']);
        $this->assertSame([self::OTHER], collect($owner['chats'])->pluck('phone')->all());
        $this->assertSame('unread', $owner['params']['f']);

        $viewer = $this->screen($this->viewer, ['f' => WhatsAppInbox::UNREAD]);
        $this->assertSame(2, $viewer['unreadChats']);
        $this->assertCount(2, $viewer['chats']);

        // و«الكل» يُبقيهما عند الجميع
        $this->assertCount(2, $this->screen($this->owner)['chats']);
        $this->assertSame('all', $this->screen($this->owner)['params']['f']);
    }

    /* ============================== الإرسال ============================== */

    public function test_a_double_pressed_reply_goes_out_once_and_a_new_reply_goes_out_again(): void
    {
        $this->inbound('PN-A', 'wamid.D');
        $chat = $this->chatWith(self::CUSTOMER);

        $this->reply($this->replier, $chat, 'تم', 'key-one')->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');
        // وردٌّ ضاع جوابُه فأعاده المتصفّح: يُقال ما جرى أوّلَ مرّة — ولا يخرج ثانيةً
        $this->reply($this->replier, $chat, 'تم', 'key-one')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info' && str_contains($t['msg'], 'أُرسل من قبل'));
        // والمفتاحُ نفسُه من حسابٍ آخر في اللحظة نفسها لا يُخرجه ثانيةً
        $this->reply($this->owner, $chat, 'تم', 'key-one')->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
        $this->assertSame(1, $this->sent());

        // وردٌّ جديدٌ بمفتاحه يخرج
        $this->reply($this->replier, $chat, 'تم', 'key-two')->assertSessionHasNoErrors();
        $this->assertSame(2, $this->sent());

        // ومفتاحٌ مكسور يُردّ بسببه ولا يُرسل شيء
        $this->reply($this->replier, $chat, 'تم', 'bad key!')->assertSessionHasErrors('client_key');
        $this->assertSame(2, $this->sent());
    }

    public function test_a_twin_request_while_the_first_is_still_sending_sends_nothing_on_the_database_store(): void
    {
        // مخزنُ الإنتاج نفسُه — القفلُ صفٌّ في `cache_locks` لا ذاكرةُ العمليّة
        config(['cache.default' => 'database']);

        /*
         * وعلى PostgreSQL: الاختبارُ كلُّه داخل معاملة (`RefreshDatabase`)، وإدخالُ
         * قفلٍ مأخوذٍ يُرفض فتُلغى المعاملةُ كلُّها — والطلبُ على الإنتاج ليس
         * داخل معاملة. فالأقفالُ هنا على اتّصالٍ ثانٍ بالقاعدة نفسها، كما هي هناك.
         * وSQLite في الذاكرة قاعدةٌ لكلّ اتّصال، ولا تُلغي معاملتَها بإدخالٍ رُفض.
         */
        if (DB::getDriverName() === 'pgsql') {
            config([
                'database.connections.locks' => config('database.connections.pgsql'),
                'cache.stores.database.lock_connection' => 'locks',
            ]);
            $this->beforeApplicationDestroyed(fn () => DB::connection('locks')->table('cache_locks')->where('key', 'like', '%wa-inbox-reply%')->delete());
        }

        $this->inbound('PN-A', 'wamid.T');
        $chat = $this->chatWith(self::CUSTOMER);

        // الطلبُ الأوّل يحمل القفلَ ولم يكتب صفًّا بعد
        $first = Cache::lock(WhatsAppInbox::replyKey($chat, 'key-twin').':lock', 60);
        $this->assertTrue($first->get());
        $this->assertFalse(Cache::lock(WhatsAppInbox::replyKey($chat, 'key-twin').':lock', 60)->get());

        $this->reply($this->replier, $chat, 'تم', 'key-twin')
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info' && str_contains($t['msg'], 'قيد الإرسال'));
        // ومن حسابٍ آخر في اللحظة نفسها
        $this->reply($this->owner, $chat, 'تم', 'key-twin')
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info' && str_contains($t['msg'], 'قيد الإرسال'));
        $this->assertSame(0, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
        $this->assertSame(0, $this->sent());

        // أطلقه فخرج — مرّةً واحدة
        $first->release();
        $this->reply($this->replier, $chat, 'تم', 'key-twin')->assertSessionHas('toast.type', 'success');
        $this->reply($this->replier, $chat, 'تم', 'key-twin')->assertSessionHas('toast.type', 'info');
        $this->assertSame(1, $this->sent());
    }

    public function test_a_reply_recorded_before_the_request_died_is_never_sent_twice(): void
    {
        $this->inbound('PN-A', 'wamid.Z');
        $chat = $this->chatWith(self::CUSTOMER);

        // كُتب صفُّه وحُفظ تحت مفتاحه — ثمّ مات الطلبُ قبل أن يعرف جوابَ ميتا
        $row = WhatsAppChatMessage::create([
            'business_id' => $this->shop->id, 'chat_id' => $chat->id, 'direction' => WhatsAppChatMessage::OUT,
            'body' => 'تم', 'whatsapp_connection_id' => $this->line->id, 'phone_number_id' => 'PN-A',
            'sender_user_id' => $this->replier->id, 'sender_name' => 'منى', 'status' => WhatsAppStatus::QUEUED,
        ]);
        Cache::put(WhatsAppInbox::replyKey($chat, 'key-died'), [$row->id], now()->addDay());

        $this->reply($this->replier, $chat, 'تم', 'key-died')
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info' && str_contains($t['msg'], 'قيد الإرسال'));
        $this->assertSame(0, $this->sent());
        $this->assertSame(1, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
    }

    public function test_a_request_that_died_after_meta_took_the_reply_does_not_send_it_again_on_retry(): void
    {
        $this->inbound('PN-A', 'wamid.Y');
        $chat = $this->chatWith(self::CUSTOMER);

        // ميتا أخذت الردّ — ثمّ مات الطلبُ قبل أن يكتب «أُرسل» على صفّه
        $died = false;
        WhatsAppChatMessage::saving(function (WhatsAppChatMessage $m) use (&$died) {
            if (! $died && $m->status === WhatsAppStatus::SENT) {
                $died = true;
                throw new \RuntimeException('انقطع الطلب');
            }
        });

        $this->reply($this->replier, $chat, 'تم', 'key-dead')->assertServerError();
        $this->assertSame(1, $this->sent());

        // أعاده المتصفّح بالمفتاح نفسه: صفُّه مكتوبٌ تحته «قيد الإرسال» — فلا يخرج ثانيةً
        $this->reply($this->replier, $chat, 'تم', 'key-dead')
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info' && str_contains($t['msg'], 'قيد الإرسال'));
        $this->assertSame(1, $this->sent());
        $this->assertSame([WhatsAppStatus::QUEUED], WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->pluck('status')->all());
    }

    public function test_a_meta_failure_is_said_again_when_retried_with_its_key_and_a_new_reply_goes_out(): void
    {
        $this->inbound('PN-A', 'wamid.F');
        $chat = $this->chatWith(self::CUSTOMER);
        $this->metaRefuses = true;

        $this->reply($this->replier, $chat, 'هل يصل؟', 'key-fail')->assertSessionHas('toast.type', 'danger');
        $failed = WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->sole();
        $this->assertSame(WhatsAppStatus::FAILED, $failed->status);
        $this->assertStringContainsString('undeliverable', (string) $failed->error_message);

        // الضغطةُ الثانية لا تقول «أُرسل» عن فشل — ولا تُعيد المحاولة بالمفتاح نفسه
        $calls = Http::recorded()->count();
        $this->reply($this->replier, $chat, 'هل يصل؟', 'key-fail')
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'danger' && str_contains($t['msg'], 'لم يخرج'));
        $this->assertSame($calls, Http::recorded()->count());

        // وبعد الفشل المؤكَّد: ردٌّ جديدٌ بمفتاحه يخرج، والفاشلُ باقٍ بسببه
        $this->metaRefuses = false;
        $this->reply($this->replier, $chat, 'هل يصل؟', 'key-retry')->assertSessionHas('toast.type', 'success');
        $this->assertSame([WhatsAppStatus::FAILED, WhatsAppStatus::SENT], WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->orderBy('id')->pluck('status')->all());
        // محاولتان إلى ميتا: الأولى رُدّت، والثانية بمفتاحٍ جديد — لا ثالثةَ من الضغطة المكرّرة
        $this->assertSame(2, $this->sent());
    }

    public function test_one_attachment_failing_is_said_and_what_went_out_is_not_sent_again(): void
    {
        $this->inbound('PN-A', 'wamid.M');
        $chat = $this->chatWith(self::CUSTOMER);
        // الملفُّ الثاني يُردّ عند الرفع
        $this->failUpload = 2;

        $post = fn () => $this->actingAs($this->replier)->post(route('admin.whatsappInbox.reply', $chat->id), [
            'body' => 'الصورتان', 'client_key' => 'key-files',
            'files' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ]);

        $post()->assertSessionHas('toast.type', 'danger');
        $this->assertSame(
            [[null, WhatsAppStatus::SENT], ['image', WhatsAppStatus::SENT], ['image', WhatsAppStatus::FAILED]],
            WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->orderBy('id')->get()
                ->map(fn ($m) => [$m->media_type, $m->status])->all(),
        );
        $this->assertSame(2, $this->sent());

        // والإعادةُ بالمفتاح نفسه تقول الفشلَ ولا تُكرّر النصَّ ولا الصورةَ التي وصلت
        $calls = Http::recorded()->count();
        $post()->assertSessionHas('toast.type', 'danger');
        $this->assertSame($calls, Http::recorded()->count());
        $this->assertSame(3, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
    }

    public function test_a_reply_refused_for_its_reason_does_not_burn_its_key(): void
    {
        $this->inbound('PN-A', 'wamid.K1');
        $chat = $this->chatWith(self::CUSTOMER);
        // نافذةُ الأربعِ والعشرين أُغلقت
        $chat->forceFill(['last_inbound_at' => now()->subDays(2)])->save();

        $this->reply($this->replier, $chat, 'متأخّر', 'key-late')->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sent());

        // كتب الزبون فانفتحت — والمفتاحُ نفسُه يخرج الآن
        $this->inbound('PN-A', 'wamid.K2', 'هل من جديد؟');
        $this->reply($this->replier, $chat->fresh(), 'متأخّر', 'key-late')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sent());
    }

    public function test_a_disconnected_number_blocks_every_reply_and_sends_from_nowhere_else(): void
    {
        $this->inbound('PN-A', 'wamid.X');
        $chat = $this->chatWith(self::CUSTOMER);
        $this->line->forceFill(['status' => WhatsAppConnection::REVOKED])->save();

        $props = $this->screen($this->owner, ['c' => $chat->id]);
        $this->assertFalse($props['line']['usable']);
        $this->assertNotNull($props['thread']['blocked']);

        $this->reply($this->owner, $chat, 'هل يخرج؟', 'key-down')->assertSessionHasErrors('body');
        $this->reply($this->replier, $chat, 'هل يخرج؟', 'key-down-2')->assertSessionHasErrors('body');
        $this->assertSame(0, $this->sent());
    }

    /* ============================== الصلاحيات ============================== */

    public function test_the_owner_grants_and_withdraws_for_several_staff_at_once_and_it_applies_on_the_next_request(): void
    {
        $this->inbound('PN-A', 'wamid.G');
        $chat = $this->chatWith(self::CUSTOMER);

        // الشاشةُ تعرض موظّفيه بمستوياتهم — لا صاحبَ النشاط فيها
        $roster = $this->actingAs($this->owner)->get(route('admin.employees.index'))->assertOk()->viewData('page')['props']['inboxAccess'];
        $this->assertEqualsCanonicalizing(
            [[$this->viewer->id, 'view'], [$this->replier->id, 'reply'], [$this->none->id, 'none']],
            collect($roster['staff'])->map(fn ($s) => [$s['id'], $s['level']])->all(),
        );

        // والصفحةُ مفتوحةٌ أمام «منى»
        $this->actingAs($this->replier)->get(route('admin.whatsappInbox.poll'))->assertOk();

        $this->actingAs($this->owner)->post(route('admin.employees.inboxAccess'), ['levels' => [
            $this->none->id => WhatsAppInbox::REPLY,
            $this->viewer->id => WhatsAppInbox::REPLY,
            $this->replier->id => 'none',
        ]])->assertSessionHasNoErrors()->assertSessionHas('toast', fn (array $t) => $t['type'] === 'success');

        $this->assertSame(WhatsAppInbox::REPLY, $this->none->fresh()->whatsapp_inbox);
        $this->assertSame(WhatsAppInbox::REPLY, $this->viewer->fresh()->whatsapp_inbox);
        $this->assertNull($this->replier->fresh()->whatsapp_inbox);
        // وكلُّ تبدّلٍ في سجلّ النشاط باسم من فعله
        $this->assertSame(3, DB::table('activity_logs')->where('business_id', $this->shop->id)->where('description', 'like', 'محادثات واتساب لـ%')->count());

        // والسحبُ يسري في طلبها التالي — والمفتوحُ يُغلق
        $this->actingAs($this->replier->fresh())->get(route('admin.whatsappInbox.poll'))->assertForbidden();
        $this->reply($this->replier->fresh(), $chat, 'بعد السحب')->assertForbidden();
        $this->assertSame(0, $this->sent());

        // ومن مُنح الآن يردّ
        $this->reply($this->none->fresh(), $chat, 'أنا هنا الآن')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sent());

        // وحفظٌ لا يبدّل شيئًا لا يكتب شيئًا
        $this->actingAs($this->owner)->post(route('admin.employees.inboxAccess'), ['levels' => [$this->none->id => WhatsAppInbox::REPLY]])
            ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'info');
        $this->assertSame(3, DB::table('activity_logs')->where('business_id', $this->shop->id)->where('description', 'like', 'محادثات واتساب لـ%')->count());
    }

    public function test_only_the_owner_or_his_authorised_manager_grants_and_never_to_himself_the_owner_or_another_shop(): void
    {
        $levels = fn (array $l) => ['levels' => $l];

        // موظّفٌ بلا «الموظفين» لا يصل — ومديرٌ يملكها ولم يُخوَّل يُردّ
        $this->actingAs($this->replier)->post(route('admin.employees.inboxAccess'), $levels([$this->none->id => 'view']))->assertForbidden();
        $manager = $this->user($this->shop, 'manager', null, null, 'مدير');
        $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), $levels([$this->none->id => 'view']))->assertForbidden();
        $this->assertNull($this->actingAs($manager)->get(route('admin.employees.index'))->viewData('page')['props']['inboxAccess']);

        // يخوّله صاحبُ النشاط بالردّ — فيمنح غيرَه
        $manager->forceFill(['whatsapp_inbox' => WhatsAppInbox::REPLY])->save();
        $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), $levels([$this->none->id => 'view']))->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppInbox::VIEW, $this->none->fresh()->whatsapp_inbox);

        // ولا نفسَه، ولا صاحبَ النشاط، ولا موظّفَ الجار — والطلبُ كلُّه يُردّ
        $stranger = $this->user($this->neighbour, 'sales', null, ['dashboard'], 'غريب');
        foreach ([
            [$manager->id => 'none'],
            [$this->owner->id => 'view'],
            [$stranger->id => 'reply', $this->viewer->id => 'reply'],
        ] as $asked) {
            $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), $levels($asked))->assertSessionHasErrors('levels');
        }
        $this->assertSame(WhatsAppInbox::REPLY, $manager->fresh()->whatsapp_inbox);
        $this->assertNull($stranger->fresh()->whatsapp_inbox);
        $this->assertSame(WhatsAppInbox::VIEW, $this->viewer->fresh()->whatsapp_inbox);

        // ومستوًى لا وجود له يُردّ
        $this->actingAs($this->owner)->post(route('admin.employees.inboxAccess'), $levels([$this->none->id => 'admin']))->assertSessionHasErrors('levels.'.$this->none->id);

        // ومتجرٌ لم يُفتح له الصندوقُ لا يمنح شيئًا
        $this->shop->forceFill(['whatsapp_enabled' => false])->save();
        $this->actingAs($this->owner->fresh())->post(route('admin.employees.inboxAccess'), $levels([$this->none->id => 'reply']))->assertNotFound();
        $this->assertSame(WhatsAppInbox::VIEW, $this->none->fresh()->whatsapp_inbox);
    }

    public function test_an_authorised_manager_never_touches_someone_who_opens_more_than_he_does(): void
    {
        // مديرٌ مضيَّق: «الموظفين» واللوحة وحدهما — وخوّله صاحبُ النشاط بالردّ
        $manager = $this->user($this->shop, 'manager', WhatsAppInbox::REPLY, ['dashboard', 'employees'], 'مدير');
        $accountant = $this->user($this->shop, 'accountant', null, ['dashboard', 'finance'], 'محاسب');

        // ملفُّ الموظّف يردّه — والجماعيُّ لا يكون بابًا خلفيًّا له
        $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), ['levels' => [$accountant->id => WhatsAppInbox::REPLY]])
            ->assertSessionHasErrors('levels');
        $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), ['levels' => [
            $this->none->id => WhatsAppInbox::VIEW, $accountant->id => WhatsAppInbox::VIEW,
        ]])->assertSessionHasErrors('levels');
        $this->assertNull($accountant->fresh()->whatsapp_inbox);
        $this->assertNull($this->none->fresh()->whatsapp_inbox);

        // ولا يراه في الشاشة — ويرى من دونه
        $roster = collect($this->actingAs($manager)->get(route('admin.employees.index'))->assertOk()->viewData('page')['props']['inboxAccess']['staff'])->pluck('id');
        $this->assertNotContains($accountant->id, $roster);
        $this->assertNotContains($this->owner->id, $roster);
        $this->assertNotContains($manager->id, $roster);
        $this->assertContains($this->none->id, $roster);

        // ومن دونه يمنحه
        $this->actingAs($manager)->post(route('admin.employees.inboxAccess'), ['levels' => [$this->none->id => WhatsAppInbox::VIEW]])
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppInbox::VIEW, $this->none->fresh()->whatsapp_inbox);

        // وصاحبُ النشاط يمسّ الجميع
        $this->actingAs($this->owner)->post(route('admin.employees.inboxAccess'), ['levels' => [$accountant->id => WhatsAppInbox::VIEW]])
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppInbox::VIEW, $accountant->fresh()->whatsapp_inbox);

        // وسحبُ الردّ عن المدير يسحب منه المنحَ معه
        $this->actingAs($this->owner)->post(route('admin.employees.inboxAccess'), ['levels' => [$manager->id => WhatsAppInbox::VIEW]])
            ->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->post(route('admin.employees.inboxAccess'), ['levels' => [$this->none->id => 'none']])->assertForbidden();
        $this->assertSame(WhatsAppInbox::VIEW, $this->none->fresh()->whatsapp_inbox);
    }

    public function test_a_new_employee_starts_without_the_inbox_unless_the_owner_picks_it_when_adding_him(): void
    {
        $form = fn (string $name, string $login, ?string $inbox) => array_filter([
            'name' => $name, 'login_username' => $login, 'job_title' => 'بائع', 'manual_permissions' => false,
            'whatsapp_inbox' => $inbox,
        ], fn ($v) => $v !== null);
        JobTitle::firstOrCreate(['business_id' => $this->shop->id, 'name' => 'بائع'], ['role' => 'sales']);

        $this->actingAs($this->owner)->post(route('admin.employees.store'), $form('ريم', 'reem', null))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.employees.store'), $form('نور', 'noor', WhatsAppInbox::REPLY))->assertSessionHasNoErrors();

        $reem = User::where('business_id', $this->shop->id)->where('name', 'ريم')->sole();
        $noor = User::where('business_id', $this->shop->id)->where('name', 'نور')->sole();
        $this->assertNull($reem->whatsapp_inbox);
        $this->assertSame(WhatsAppInbox::REPLY, $noor->whatsapp_inbox);

        $this->actingAs($reem)->get(route('admin.whatsappInbox.index'))->assertForbidden();
        $this->actingAs($noor)->get(route('admin.whatsappInbox.index'))->assertOk();
    }

    /* ============================== تفاصيلُ العميل ============================== */

    public function test_the_customer_details_show_his_orders_and_invoices_only_to_who_may_open_them(): void
    {
        $this->inbound('PN-A', 'wamid.C');
        $chat = $this->chatWith(self::CUSTOMER);
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'سارة', 'phone' => '91234567', 'allow_credit_sales' => true]);
        $chat->forceFill(['customer_id' => $customer->id])->save();

        Order::create([
            'business_id' => $this->shop->id, 'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'customer_id' => $customer->id, 'number' => 'W-1042', 'status' => 'جديد', 'is_held' => false,
            'payment_method' => 'Paymob', 'payment_status' => 'مدفوع', 'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
        CustomerInvoice::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'number' => 'CINV-000007', 'status' => 'صادرة',
            'issued_at' => now(), 'due_at' => now()->addDays(30),
            'subtotal' => 40, 'discount_total' => 0, 'tax_total' => 0, 'total' => 40,
        ]);

        $owner = $this->screen($this->owner, ['c' => $chat->id])['thread'];
        $this->assertSame(['W-1042'], collect($owner['orders'])->pluck('number')->all());
        $this->assertSame('جديد', $owner['orders'][0]['status']);
        $this->assertSame(['CINV-000007'], collect($owner['invoices'])->pluck('number')->all());
        $this->assertNotNull($owner['customer']['url']);
        $this->assertSame('+968 7000 A', $owner['line_number']);

        // موظّفُ المحادثات وحدها يرى الاسمَ ولا يفتح الملفّ ولا الطلبات ولا الفواتير
        $staff = $this->screen($this->replier, ['c' => $chat->id])['thread'];
        $this->assertSame('سارة', $staff['customer']['name']);
        $this->assertNull($staff['customer']['url']);
        $this->assertSame([], $staff['orders']);
        $this->assertSame([], $staff['invoices']);
    }
}
