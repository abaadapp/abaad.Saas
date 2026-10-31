<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatAttachment;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppLinkHealth;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * رقمُ المتجر المستقلّ يسمع زبائنَه — ويقول بصدقٍ متى سمعهم.
 *
 * ما يُثبت هنا، لكلّ متجرٍ على رقمه لا لمتجرٍ بعينه:
 *
 *   ١ · رسالةٌ عاديّةٌ من زبونٍ لم نراسله قطّ — بلا `context.id` ولا إشعارٍ
 *       سابق — تفتح محادثةً في متجر الرقم وحده. وقواعدُ الرقم المشترك
 *       (٧ أيّام · ٩٠ يومًا) لا تمسّ رقمًا مستقلًّا، وتبقى على حالها هناك.
 *   ٢ · حالةُ التسليم ليست رسالةَ زبون، والوصولُ ليس الحفظ: لكلٍّ ختمُه، و«تم
 *       استقبال رسالة عميل فعلية» لا تُقال إلّا برسالةٍ كتبها زبونٌ وحُفظت في
 *       صندوق متجرها. وما وصل ورُدّ يُقرأ «وصلت ولم تُحفظ» بسببه.
 *   ٣ · ما وصل ولم يُحفظ يقول لماذا — بلا نصّه. ورقمٌ لا نعرفه يُقيَّد مرّةً
 *       بلا نصّ. وتوقيعٌ مزوّرٌ لا يختم شيئًا.
 *   ٤ · المتاجرُ لا تتسرّب، والموظّفُ بلا صلاحيةٍ لا يرى، والردُّ من رقم
 *       المحادثة داخل نافذة الأربعِ والعشرين وحده، والصورةُ تُحفظ للمتجر وحده.
 *   ٥ · الفحصُ العامّ (`whatsapp:own-numbers`، وشاشةُ المنصّة) قراءةٌ عند ميتا:
 *       رمزٌ من تطبيقٍ آخر يُقال «يحتاج إعادة تفويض» ولا يُستبدل ولا يُحوَّل
 *       متجرُه إلى الرقم المشترك، وتوجيهُ المحادثات يُسمّى حين يأخذ الوارد.
 *
 * وميتا كلُّها مزيّفة: لا طلبٌ يخرج، ولا `POST` ولا `DELETE` إلّا ما يُثبت الاختبارُ غيابَه.
 */
class AnIndependentNumberHearsItsCustomersTest extends TestCase
{
    use RefreshDatabase;

    private const ABAAD_APP = '1082481610822941';

    private const OTHER_APP = '980169825105048';

    private const SECRET = 'test-secret-not-a-real-one';

    private const CUSTOMER = '96891234567';

    private Business $shopA;

    private Business $shopC;

    private Business $sharedD;

    private WhatsAppConnection $lineA;

    private WhatsAppConnection $lineC;

    private WhatsAppConnection $abaad;

    private User $ownerA;

    private User $ownerC;

    private User $super;

    private static int $seq = 0;

    /** أيُّ تطبيقٍ أصدر كلَّ رمز — و`null` رمزٌ يأبى `debug_token` وصفَه (#100) */
    private array $tokenApp = [];

    /** حسابُ كلِّ رقمٍ عند ميتا */
    private array $numbers = ['WABA-A' => 'PN-A', 'WABA-C' => 'PN-C', 'WABA-N' => 'PN-N'];

    /** التطبيقاتُ المشتركة في كلّ حساب */
    private array $subscribed = ['WABA-A' => [self::ABAAD_APP], 'WABA-C' => [self::OTHER_APP], 'WABA-N' => []];

    /** أدوارُ أبعاد في توجيه المحادثات — و`null` لا توجيهَ على الرقم */
    private ?array $routing = null;

    /** حقولُ الإشعارات في تطبيق أبعاد */
    private array $appFields = ['messages'];

    protected function setUp(): void
    {
        parent::setUp();
        // الطريقُ السابق — رمزٌ من تطبيق أبعاد للتاجر؛ والافتراضُ اليوم تطبيقُ التاجر (`WhatsAppOwnApp`)
        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => self::ABAAD_APP,
            'whatsapp.app_secret' => self::SECRET,
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.coexistence_enabled' => false,
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Storage::fake('local');

        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $r) => $this->meta($r));

        $this->abaad = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'ABAAD-PN',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'access_token' => 'platform-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->shopA = $this->shop('محل الورد', WhatsAppMode::BUSINESS_OWN);
        $this->shopC = $this->shop('محل الجار', WhatsAppMode::BUSINESS_OWN);
        $this->sharedD = $this->shop('محل الهدايا', WhatsAppMode::ABAAD_SHARED);

        $this->lineA = $this->own($this->shopA, 'PN-A', 'WABA-A', 'token-A-from-abaads-app-0123', self::ABAAD_APP);
        $this->lineC = $this->own($this->shopC, 'PN-C', 'WABA-C', 'token-C-from-abaads-app-0123', self::ABAAD_APP);

        $this->ownerA = $this->user($this->shopA, 'admin');
        $this->ownerC = $this->user($this->shopC, 'admin');
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@hears.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* ============================== ميتا المزيّفة ============================== */

    private function meta(HttpRequest $r)
    {
        $url = $r->url();
        $error = fn (int $code, string $message, int $status = 400) => Http::response(['error' => ['code' => $code, 'message' => $message]], $status);

        if (str_contains($url, '/debug_token')) {
            $app = $this->tokenApp[(string) $r['input_token']] ?? null;

            return $app === null
                ? $error(100, 'Unsupported get request.')
                : Http::response(['data' => [
                    'is_valid' => true, 'app_id' => $app, 'expires_at' => 0,
                    'granular_scopes' => [
                        ['scope' => 'whatsapp_business_management', 'target_ids' => [$this->wabaOfToken((string) $r['input_token'])]],
                        ['scope' => 'whatsapp_business_messaging', 'target_ids' => [$this->wabaOfToken((string) $r['input_token'])]],
                    ],
                ]], 200);
        }

        if (str_contains($url, '/oauth/access_token')) {
            return Http::response(['access_token' => 'token-N-from-abaads-app-0123'], 200);
        }

        if (str_contains($url, '/'.self::ABAAD_APP.'/subscriptions')) {
            return Http::response(['data' => [[
                'object' => 'whatsapp_business_account', 'callback_url' => route('webhooks.whatsapp'),
                'active' => true, 'fields' => array_map(fn ($f) => ['name' => $f, 'version' => 'v26.0'], $this->appFields),
            ]]], 200);
        }

        if (str_contains($url, '/routing_roles')) {
            return $this->routing === null
                ? $error(100, 'Conversation routing is not enabled for this phone number.')
                : Http::response(['roles' => $this->routing, 'is_standby' => false], 200);
        }

        if (str_contains($url, 'fields=is_on_biz_app')) {
            return Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200);
        }

        if (preg_match('#/(WABA-[A-Z])/phone_numbers#', $url, $m)) {
            return Http::response(['data' => [[
                'id' => $this->numbers[$m[1]], 'display_phone_number' => '+968 7000 000'.substr($m[1], -1), 'platform_type' => 'CLOUD_API',
            ]]], 200);
        }

        if (preg_match('#/(WABA-[A-Z])/subscribed_apps#', $url, $m)) {
            if ($r->method() === 'POST') {
                $this->subscribed[$m[1]][] = $this->tokenApp[str_replace('Bearer ', '', $r->header('Authorization')[0] ?? '')] ?? self::OTHER_APP;

                return Http::response(['success' => true], 200);
            }

            return Http::response(['data' => array_map(fn ($id) => ['whatsapp_business_api_data' => ['id' => $id]], $this->subscribed[$m[1]])], 200);
        }

        if (preg_match('#/(PN-[A-Z])/messages$#', $url)) {
            return Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200);
        }

        if (str_contains($url, '/MEDIA-1')) {
            return Http::response(['url' => 'https://lookaside.example.test/media/1', 'mime_type' => 'image/jpeg'], 200);
        }

        if (str_contains($url, 'lookaside.example.test')) {
            return Http::response("\xFF\xD8\xFFpicture-bytes", 200, ['Content-Type' => 'image/jpeg']);
        }

        return $error(100, 'not faked: '.$url, 404);
    }

    private function wabaOfToken(string $token): string
    {
        return match (true) {
            str_starts_with($token, 'token-A') => 'WABA-A',
            str_starts_with($token, 'token-C') => 'WABA-C',
            default => 'WABA-N',
        };
    }

    /** ما خرج إلى ميتا بغير القراءة */
    private function writesToMeta(): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() !== 'GET')->count();
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

    private function own(Business $b, string $pn, string $waba, string $token, ?string $app): WhatsAppConnection
    {
        $this->tokenApp[$token] = $app;

        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id, 'provider' => 'meta_cloud',
            'phone_number_id' => $pn, 'display_phone_number' => '+968 7000 000'.substr($pn, -1),
            'waba_id' => $waba, 'access_token' => $token,
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function user(Business $b, string $role, ?string $inbox = null): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => $role.'-'.(++self::$seq), 'email' => 'h'.self::$seq.'@hears.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'whatsapp_inbox' => $inbox,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function webhook(array $value, ?string $secret = self::SECRET)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    /** رسالةٌ كتبها زبون — كما ترسلها ميتا، بلا `context` ما لم يُطلب */
    private function customerWrites(string $pn, string $wamid, string $text = 'مرحبا', array $message = []): void
    {
        $this->webhook([
            'metadata' => ['phone_number_id' => $pn],
            'contacts' => [['wa_id' => self::CUSTOMER, 'profile' => ['name' => 'سارة']]],
            'messages' => [$message + [
                'id' => $wamid, 'from' => self::CUSTOMER, 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
            ]],
        ])->assertOk();
    }

    /** حالةُ تسليمٍ لما أرسله المتجر — لا كلمةَ فيها من الزبون */
    private function delivered(string $pn): void
    {
        $this->webhook([
            'metadata' => ['phone_number_id' => $pn],
            'statuses' => [['id' => 'wamid.NOTICE-'.(++self::$seq), 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => self::CUSTOMER]],
        ])->assertOk();
    }

    private function chatOf(Business $b): ?WhatsAppChat
    {
        return WhatsAppChat::where('business_id', $b->id)->first();
    }

    /** @return array<string, mixed> */
    private function inboxOf(User $as, array $query = []): array
    {
        return $this->actingAs($as)->get(route('admin.whatsappInbox.index', $query))->assertOk()->viewData('page')['props'];
    }

    /* ═════════════ ١ · رسالةٌ عاديّةٌ من زبونٍ جديد ═════════════ */

    public function test_a_customer_never_written_to_opens_a_chat_in_that_shop_without_any_context(): void
    {
        $this->assertSame(0, WhatsAppMessage::where('recipient_phone', self::CUSTOMER)->count(), 'لم يخرج إليه شيءٌ قطّ');

        $this->customerWrites('PN-A', 'wamid.FIRST', 'هل عندكم ورد أبيض؟');
        $this->customerWrites('PN-A', 'wamid.FIRST', 'هل عندكم ورد أبيض؟');

        $chat = $this->chatOf($this->shopA);
        $this->assertNotNull($chat);
        $this->assertSame(['هل عندكم ورد أبيض؟'], WhatsAppChatMessage::where('chat_id', $chat->id)->pluck('body')->all(), 'والإشعارُ المكرّرُ مرّةً');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $chat->source_mode);

        $props = $this->inboxOf($this->ownerA);
        $this->assertStringContainsString('هل عندكم ورد أبيض؟', json_encode($props['chats'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->inboxOf($this->ownerC)['chats'], 'ولا يراها متجرٌ آخر');

        $line = $this->lineA->fresh();
        $this->assertNotNull($line->last_inbound_at);
        $this->assertNotNull($line->last_inbound_saved_at, 'حُفظت — لا وصلت فقط');
        $this->assertNull($line->inbound_problem);
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line));
    }

    /** نسخةٌ تُعيدها ميتا من رسالةٍ حُفظت: لا محادثةَ ثانية، ولا علّة، ولا ختمَ حفظٍ جديد */
    public function test_a_redelivered_message_is_neither_saved_twice_nor_called_refused(): void
    {
        $this->customerWrites('PN-A', 'wamid.AGAIN');
        $saved = (string) $this->lineA->fresh()->last_inbound_saved_at;

        $this->travel(5)->minutes();
        $this->customerWrites('PN-A', 'wamid.AGAIN');

        $line = $this->lineA->fresh();
        $this->assertSame(1, WhatsAppChatMessage::where('wamid', 'wamid.AGAIN')->count());
        $this->assertNull($line->inbound_problem);
        $this->assertSame($saved, (string) $line->last_inbound_saved_at);
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line));
    }

    /** صاحبُ المتجر يقرأ في بطاقة ربطه متى حُفظت آخرُ رسالة — ومتى وصلت */
    public function test_the_owner_reads_when_the_last_customer_message_was_saved(): void
    {
        $this->customerWrites('PN-A', 'wamid.CARD', 'نصٌّ لا يُعرض في البطاقة');

        $health = $this->actingAs($this->ownerA)->get(route('admin.integrations.whatsapp'))->assertOk()
            ->viewData('page')['props']['automation']['health'];

        $this->assertSame(WhatsAppLinkHealth::INBOUND, $health['key']);
        $this->assertNotNull($health['last_inbound_saved_at']);
        $this->assertNotNull($health['last_inbound_at']);
        $this->assertNull($health['inbound_problem']);
        $this->assertStringNotContainsString('نصٌّ لا يُعرض', json_encode($health, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(self::CUSTOMER, json_encode($health), 'ولا رقمُ الزبون');
    }

    /** رقمٌ مستقلٌّ لا يتشاركه أحد — فنوافذُ الرقم المشترك لا تُقرأ له، وتبقى هناك كما هي */
    public function test_the_shared_numbers_seven_and_ninety_day_rules_never_touch_an_own_number(): void
    {
        WhatsAppMessage::create([
            'business_id' => $this->sharedD->id, 'whatsapp_connection_id' => $this->abaad->id,
            'sender_phone_number_id' => 'ABAAD-PN', 'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY, 'direction' => 'outbound',
            'recipient_phone' => self::CUSTOMER, 'template_name' => 'order_ready', 'language_code' => 'ar',
            'dedupe_key' => 'n:hears', 'status' => WhatsAppStatus::SENT,
            'provider_message_id' => 'wamid.NOTICE-D', 'quota_consumed' => false,
        ]);

        $this->customerWrites('PN-A', 'wamid.TO-A', 'لمحلّ الورد');
        $this->customerWrites('ABAAD-PN', 'wamid.TO-ABAAD', 'ردٌّ على إشعار الهدايا');

        $this->assertSame(['لمحلّ الورد'], WhatsAppChatMessage::where('business_id', $this->shopA->id)->pluck('body')->all());
        $this->assertSame(['ردٌّ على إشعار الهدايا'], WhatsAppChatMessage::where('business_id', $this->sharedD->id)->pluck('body')->all(), 'وقاعدةُ الرقم المشترك على حالها');
    }

    /* ═════════════ ٢ · حالةُ التسليم ليست رسالةَ زبون ═════════════ */

    public function test_a_delivery_status_is_not_a_customer_message(): void
    {
        $this->lineA->update(['token_app_id' => self::ABAAD_APP, 'token_verified_at' => now(), 'webhook_state' => WhatsAppLinkCheck::SUBSCRIBED, 'webhook_checked_at' => now()]);
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($this->lineA->fresh()));

        $this->delivered('PN-A');

        $line = $this->lineA->fresh();
        $this->assertNotNull($line->last_status_at);
        $this->assertNull($line->last_inbound_at);
        $this->assertSame(0, WhatsAppChat::count(), 'لا محادثةَ من حالة تسليم');
        $this->assertSame(WhatsAppLinkHealth::STATUSES, WhatsAppLinkHealth::state($line));
        $this->assertStringNotContainsString('رسالة عميل فعلية', WhatsAppLinkHealth::label(WhatsAppLinkHealth::state($line)));

        $this->customerWrites('PN-A', 'wamid.AFTER');

        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($this->lineA->fresh()));
        $this->assertSame('تم استقبال رسالة عميل فعلية', WhatsAppLinkHealth::label(WhatsAppLinkHealth::INBOUND));
    }

    /** تاجرٌ جديدٌ يربط بالتسجيل المدمج: «جاهز» ثمّ «حالة تسليم» ثمّ «رسالة عميل» — كلٌّ بدليله */
    public function test_a_new_merchant_is_not_called_receiving_until_a_customer_writes(): void
    {
        $shop = $this->shop('محل جديد', WhatsAppMode::BUSINESS_OWN);
        $owner = $this->user($shop, 'admin');
        $this->tokenApp['token-N-from-abaads-app-0123'] = self::ABAAD_APP;
        $this->routing = ['customer_service'];

        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => 'WABA-N', 'phone_number_id' => 'PN-N',
        ])->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $line = WhatsAppConnections::forBusiness($shop->id);
        $this->assertSame([self::ABAAD_APP], $this->subscribed['WABA-N'], 'اشترك تطبيقُ أبعاد وحده');
        $this->assertSame(WhatsAppLinkCheck::ROUTING_OURS, $line->routing_state);
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($line));

        $this->delivered('PN-N');
        $this->assertSame(WhatsAppLinkHealth::STATUSES, WhatsAppLinkHealth::state($line->fresh()));

        $this->customerWrites('PN-N', 'wamid.NEW-1');
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line->fresh()));
        $this->assertNotNull($this->chatOf($shop));
    }

    /* ═════════════ ٣ · ما لا يُكتب، ولماذا ═════════════ */

    public function test_a_forged_signature_writes_nothing_not_even_a_stamp(): void
    {
        $this->webhook([
            'metadata' => ['phone_number_id' => 'PN-A'],
            'messages' => [['id' => 'wamid.FORGED', 'from' => self::CUSTOMER, 'type' => 'text', 'text' => ['body' => 'مزوّر']]],
        ], 'not-our-secret')->assertForbidden();

        $line = $this->lineA->fresh();
        $this->assertSame(0, WhatsAppChatMessage::count());
        $this->assertNull($line->last_webhook_at);
        $this->assertNull($line->last_inbound_at);
    }

    public function test_an_unknown_number_writes_nothing_and_is_noted_once_without_its_text(): void
    {
        Log::spy();

        $this->customerWrites('PN-STRANGER', 'wamid.S1', 'نصٌّ سرّيّ من زبون');
        $this->customerWrites('PN-STRANGER', 'wamid.S2', 'نصٌّ سرّيّ من زبون');

        $this->assertSame(0, WhatsAppChatMessage::count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($event, $context) => $event === 'whatsapp.webhook_unmatched'
            && $context['phone_number_id'] === 'PN-STRANGER' && $context['messages'] === 1
            && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'سرّيّ'));
    }

    public function test_a_message_that_arrives_but_cannot_be_kept_says_why_without_its_text(): void
    {
        // صندوقُ المحادثات مغلقٌ للمتجر: وصلت، ولم تُحفظ — ويُقال ذلك
        $this->shopA->update(['whatsapp_inbox_enabled' => false]);
        $this->customerWrites('PN-A', 'wamid.CLOSED', 'نصٌّ لا يُكتب في سجلّ');

        $line = $this->lineA->fresh();
        $this->assertSame(0, WhatsAppChatMessage::count());
        $this->assertNotNull($line->last_inbound_at, 'وصلت — والخللُ بعد الوصول');
        $this->assertNull($line->last_inbound_saved_at, 'ولم تُحفظ');
        $this->assertSame(WhatsAppInbox::INBOX_CLOSED, $line->inbound_problem);
        $this->assertSame(WhatsAppLinkHealth::INBOUND_REFUSED, WhatsAppLinkHealth::state($line), 'لا «استُقبلت» عن رسالةٍ رُدّت');
        $view = WhatsAppLinkHealth::view($line);
        $this->assertSame('وصلت رسالة عميل ولم تُحفظ', $view['label']);
        $this->assertStringContainsString(WhatsAppInbox::refusal(WhatsAppInbox::INBOX_CLOSED), $view['hint']);
        $this->assertSame(WhatsAppInbox::refusal(WhatsAppInbox::INBOX_CLOSED), $view['inbound_problem']);
        $this->assertStringNotContainsString('نصٌّ لا يُكتب', json_encode($view, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(self::CUSTOMER, json_encode($view));

        // وفُتح: تُحفظ التالية، ويُختم حفظُها، وتُمحى العلّة
        $this->shopA->update(['whatsapp_inbox_enabled' => true]);
        $this->customerWrites('PN-A', 'wamid.OPEN');
        $line = $this->lineA->fresh();
        $this->assertNull($line->inbound_problem);
        $this->assertNotNull($line->last_inbound_saved_at);
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line));
        $this->assertSame(1, WhatsAppChatMessage::count());

        // وصفٌّ قديمٌ بقي على الرقم بعد أن ربط المتجرُ رقمًا آخر: لا يُدخل شيئًا، ويقول لماذا — ولا يُقرأ «استُقبلت»
        $this->own($this->shopA, 'PN-A2', 'WABA-A', 'token-A2-from-abaads-app-0123', self::ABAAD_APP);
        $this->customerWrites('PN-A', 'wamid.OLD-ROW');
        $old = $this->lineA->fresh();
        $this->assertSame(WhatsAppInbox::NOT_CURRENT, $old->inbound_problem);
        $this->assertSame(WhatsAppLinkHealth::INBOUND_REFUSED, WhatsAppLinkHealth::state($old));
        $this->assertSame(1, WhatsAppChatMessage::count());
    }

    /** وصولٌ بلا ختمِ حفظٍ ولا سبب لا يُقرأ نجاحًا — الوصولُ وحده ليس استقبالَ محادثة */
    public function test_an_arrival_never_followed_by_a_save_is_not_called_received(): void
    {
        $this->lineA->update(['webhook_state' => WhatsAppLinkCheck::SUBSCRIBED, 'webhook_checked_at' => now()->subHour(), 'last_inbound_at' => now()]);

        $line = $this->lineA->fresh();
        $this->assertNull($line->last_inbound_saved_at);
        $this->assertNull($line->inbound_problem);
        $this->assertSame(WhatsAppLinkHealth::INBOUND_REFUSED, WhatsAppLinkHealth::state($line));
        $this->assertStringContainsString('لم يثبت حفظُها', WhatsAppLinkHealth::hint(WhatsAppLinkHealth::INBOUND_REFUSED, $line));
        $this->assertNotContains(WhatsAppLinkHealth::state($line), WhatsAppLinkHealth::HEALTHY);
    }

    /** واتصالٌ فصله صاحبُه: رسالتُه تصل ولا تُحفظ، ويُقال لماذا */
    public function test_a_disconnected_own_number_records_why_and_claims_nothing(): void
    {
        $this->lineA->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->customerWrites('PN-A', 'wamid.OFF');

        $line = $this->lineA->fresh();
        $this->assertSame(0, WhatsAppChatMessage::count());
        $this->assertSame(WhatsAppInbox::LINK_INACTIVE, $line->inbound_problem);
        $this->assertNull($line->last_inbound_saved_at);
    }

    /* ═════════════ ٤ · العزل والصلاحيات والردّ والملفّات ═════════════ */

    public function test_two_own_numbers_keep_the_same_customer_apart(): void
    {
        $this->customerWrites('PN-A', 'wamid.A', 'لمحلّ الورد');
        $this->customerWrites('PN-C', 'wamid.C', 'لمحلّ الجار');

        $chatA = $this->chatOf($this->shopA);
        $this->assertSame(['لمحلّ الورد'], WhatsAppChatMessage::where('business_id', $this->shopA->id)->pluck('body')->all());
        $this->assertSame(['لمحلّ الجار'], WhatsAppChatMessage::where('business_id', $this->shopC->id)->pluck('body')->all());

        $theirs = $this->inboxOf($this->ownerC, ['c' => $chatA->id]);
        $this->assertNull($theirs['thread'], 'ومعرّفُ محادثةِ غيره في العنوان لا يفتحها');
        $this->assertStringNotContainsString('لمحلّ الورد', json_encode($theirs, JSON_UNESCAPED_UNICODE));
        $this->actingAs($this->ownerC)->post(route('admin.whatsappInbox.reply', $chatA->id), ['body' => 'تسلّل'])->assertNotFound();
    }

    public function test_staff_without_the_inbox_permission_see_nothing_and_a_viewer_cannot_answer(): void
    {
        $this->customerWrites('PN-A', 'wamid.STAFF');
        $chat = $this->chatOf($this->shopA);

        $this->actingAs($this->user($this->shopA, 'sales'))->get(route('admin.whatsappInbox.index'))->assertForbidden();
        $this->actingAs($this->user($this->shopA, 'manager'))->get(route('admin.whatsappInbox.index'))->assertForbidden();

        $viewer = $this->user($this->shopA, 'sales', WhatsAppInbox::VIEW);
        $this->assertSame('مرحبا', $this->inboxOf($viewer, ['c' => $chat->id])['thread']['messages'][0]['body']);
        $this->actingAs($viewer)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'ردّ'])->assertForbidden();
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))->count());
    }

    public function test_a_cold_chat_is_answered_from_its_own_number_inside_the_window_and_not_after(): void
    {
        $this->customerWrites('PN-A', 'wamid.COLD');
        $chat = $this->chatOf($this->shopA);

        $this->actingAs($this->ownerA)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'أهلًا بك'])->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/PN-A/messages') && $r['to'] === self::CUSTOMER);
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/ABAAD-PN/'))->count(), 'ولا احتياطَ من رقم أبعاد');

        $this->travel(WhatsAppInbox::WINDOW_HOURS + 1)->hours();
        $this->actingAs($this->ownerA)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'بعد النافذة'])->assertSessionHasErrors();
        $this->assertSame(1, WhatsAppChatMessage::where('direction', WhatsAppChatMessage::OUT)->count());
    }

    public function test_a_picture_from_a_customer_is_kept_privately_for_its_shop_alone(): void
    {
        $this->customerWrites('PN-A', 'wamid.PIC', '', [
            'type' => 'image', 'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'caption' => 'هذا ما أريد'],
        ]);

        $file = WhatsAppChatAttachment::sole();
        $this->assertSame($this->shopA->id, (int) $file->business_id);
        $this->assertSame('local', $file->disk);
        $this->assertStringNotContainsString('MEDIA-1', $file->path, 'اسمٌ عشوائيٌّ لا ما سمّاه المُرسِل');
        Storage::disk('local')->assertExists($file->path);

        $this->actingAs($this->ownerA)->get(route('admin.whatsappInbox.attachment', $file->id))->assertOk();
        $this->actingAs($this->ownerC)->get(route('admin.whatsappInbox.attachment', $file->id))->assertNotFound();
    }

    /* ═════════════ ٥ · الفحصُ العامّ: قراءةٌ لا تغيّر شيئًا ═════════════ */

    /** رمزٌ من تطبيقٍ آخر — كوصلةٍ لُصق رمزُها من تطبيق التاجر نفسه */
    public function test_a_wrong_app_link_reads_needs_reauthorization_and_keeps_sending_from_its_own_number(): void
    {
        $this->tokenApp['token-A-from-abaads-app-0123'] = null;

        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.check', $this->shopA->id))->assertSessionHas('toast.type', 'warning');

        $line = $this->lineA->fresh();
        $this->assertSame(WhatsAppLinkHealth::WRONG_APP, WhatsAppLinkHealth::state($line));
        $this->assertSame('يحتاج إعادة تفويض عبر تطبيق أبعاد', WhatsAppLinkHealth::label(WhatsAppLinkHealth::WRONG_APP));
        $this->assertSame('token-A-from-abaads-app-0123', $line->access_token, 'لا يُستبدل الرمزُ تلقائيًّا');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shopA->fresh()->whatsapp_mode, 'ولا يُحوَّل إلى الرقم المشترك');
        $this->assertSame($line->id, WhatsAppConnections::resolve($this->shopA->fresh())?->id, 'ويبقى يُرسل من رقمه');
        $this->assertSame(0, $this->writesToMeta(), 'ولا اشتراكَ يُحذف ولا يُضاف');
    }

    /** مشتركٌ في الحساب، لكنّ توجيه المحادثات يُسلّم الواردَ لغيرنا — يُسمّى ولا يُضبط من هنا */
    public function test_routing_that_hands_inbound_to_another_responder_is_named(): void
    {
        $this->routing = ['marketing'];

        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.check', $this->shopA->id));

        $line = $this->lineA->fresh();
        $this->assertSame(WhatsAppLinkCheck::SUBSCRIBED, $line->webhook_state);
        $this->assertSame(WhatsAppLinkCheck::ROUTING_ELSEWHERE, $line->routing_state);
        $this->assertSame(WhatsAppLinkHealth::ROUTED_AWAY, WhatsAppLinkHealth::state($line));
        $this->assertStringContainsString('Meta Business Suite', WhatsAppLinkHealth::hint(WhatsAppLinkHealth::ROUTED_AWAY, $line));
        $this->assertSame(0, $this->writesToMeta());

        // وصار أبعاد أساسيًّا للخدمة عند صاحب الحساب: يُقرأ في الفحص التالي
        $this->routing = ['customer_service'];
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.check', $this->shopA->id));
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($this->lineA->fresh()));
    }

    public function test_the_fleet_command_reads_every_own_number_and_writes_nothing_unless_asked(): void
    {
        $other = $this->shop('محل برمزٍ من تطبيقه', WhatsAppMode::BUSINESS_OWN);
        $legacy = $this->own($other, 'PN-L', 'WABA-L', 'token-L-from-the-shops-own-app', null);
        $before = WhatsAppConnection::orderBy('id')->get()->map->only(['access_token', 'status', 'token_app_id', 'webhook_state', 'routing_state'])->all();

        Artisan::call('whatsapp:own-numbers', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(WhatsAppLinkCheck::APP_LISTENING, $report['app']['state']);
        $rows = collect($report['numbers'])->keyBy('business_id');
        $this->assertSame(WhatsAppLinkCheck::SUBSCRIBED, $rows[$this->shopA->id]['live']['webhook']);
        $this->assertTrue($rows[$this->shopA->id]['live']['ours']);
        $this->assertSame(WhatsAppLinkCheck::NOT_SUBSCRIBED, $rows[$this->shopC->id]['live']['webhook'], 'تطبيقٌ آخر في حسابه لا تطبيقُنا');
        $this->assertSame(WhatsAppLinkCheck::WRONG_APP, $rows[$other->id]['live']['problem']);
        $this->assertFalse($rows[$other->id]['live']['ours']);
        $this->assertStringNotContainsString('token-', Artisan::output(), 'ولا رمزَ في الناتج');

        $this->assertSame($before, WhatsAppConnection::orderBy('id')->get()->map->only(['access_token', 'status', 'token_app_id', 'webhook_state', 'routing_state'])->all(), 'قراءةٌ لا تكتب');
        $this->assertSame(0, $this->writesToMeta());

        // و`--record` يكتب أعمدةَ الفحص وحدها
        Artisan::call('whatsapp:own-numbers', ['--record' => true, '--json' => true]);

        $this->assertSame(WhatsAppLinkCheck::WRONG_APP, $legacy->fresh()->link_problem);
        $this->assertSame('token-L-from-the-shops-own-app', $legacy->fresh()->access_token);
        $this->assertSame(WhatsAppConnection::ACTIVE, $legacy->fresh()->status);
        $this->assertSame(WhatsAppLinkCheck::NOT_SUBSCRIBED, WhatsAppConnections::forBusiness($this->shopC->id)->webhook_state);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $other->fresh()->whatsapp_mode);
        $this->assertSame(0, $this->writesToMeta());
    }

    public function test_the_platform_screen_lists_own_numbers_without_a_token_or_number_id(): void
    {
        $this->customerWrites('PN-A', 'wamid.LIST', 'نصُّ زبونٍ لا يظهر هنا');

        $props = $this->actingAs($this->super)->get(route('super-admin.settings.index'))->assertOk()->viewData('page')['props'];
        $rows = collect($props['ownNumbers'])->keyBy('business_id');

        $this->assertSame(WhatsAppLinkHealth::INBOUND, $rows[$this->shopA->id]['health']['key']);
        $this->assertNotNull($rows[$this->shopA->id]['health']['last_inbound_at']);
        $this->assertSame(WhatsAppLinkHealth::CONNECTED, $rows[$this->shopC->id]['health']['key']);
        $this->assertFalse($rows->has($this->sharedD->id), 'ومتجرُ الرقم المشترك ليس هنا');

        $json = json_encode($props['ownNumbers'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('token-', $json);
        $this->assertStringNotContainsString('PN-A', $json);
        $this->assertStringNotContainsString('نصُّ زبون', $json);
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'debug_token'))->count(), 'فتحُ الشاشة لا يُنادي ميتا');
    }

    public function test_the_app_webhook_check_reads_meta_and_says_what_it_found(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.app-webhook.check'))->assertSessionHas('toast.type', 'success');

        $this->appFields = ['message_template_status_update'];
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.app-webhook.check'))->assertSessionHas('toast.type', 'warning');
        $this->assertStringContainsString('messages', (string) session('toast.msg'));

        $this->assertSame(0, $this->writesToMeta());
        $this->assertTrue(ActivityLog::where('description', 'like', '%فحص إعداد إشعارات تطبيق أبعاد%')->exists());

        $this->actingAs($this->ownerA)->post(route('super-admin.whatsapp.app-webhook.check'))->assertForbidden();
    }

    /** وربطٌ جديدٌ يُردّ لا يمسّ وصلةً تستقبل — رمزُها ورقمُها وأختامُها */
    public function test_a_refused_relink_keeps_the_receiving_connection_and_its_stamps(): void
    {
        $this->customerWrites('PN-A', 'wamid.KEEP');
        $before = $this->lineA->fresh()->only(['phone_number_id', 'waba_id', 'status', 'last_inbound_at', 'access_token']);
        $this->tokenApp['pasted-token-from-another-app-01'] = self::OTHER_APP;

        $this->actingAs($this->ownerA)->post(route('admin.integrations.whatsapp.connect'), [
            'waba_id' => 'WABA-A', 'phone_number_id' => 'PN-A', 'access_token' => 'pasted-token-from-another-app-01',
        ])->assertSessionHasErrors('access_token');

        $this->assertEquals($before, $this->lineA->fresh()->only(['phone_number_id', 'waba_id', 'status', 'last_inbound_at', 'access_token']));
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($this->lineA->fresh()));
        $this->assertSame(0, $this->writesToMeta());
    }

    /**
     * وتفويضٌ جديدٌ برقمٍ آخر لا يرث ما سمعه الرقمُ القديم.
     *
     * الوصلةُ نفسُها تُحدَّث، فلو بقيت أختامُها لَقرأ الرقمُ الجديد «تم استقبال
     * رسالة عميل» ولم يصله شيء. ومحادثاتُ الرقم القديم لا تُمسّ.
     */
    public function test_a_relink_to_another_number_forgets_what_the_old_number_heard(): void
    {
        $this->customerWrites('PN-A', 'wamid.OLD-1');
        $this->delivered('PN-A');
        $this->lineA->forceFill(['inbound_problem' => WhatsAppInbox::INBOX_CLOSED, 'inbound_problem_at' => now()])->save();
        $this->tokenApp['token-N-from-abaads-app-0123'] = self::ABAAD_APP;

        $this->actingAs($this->ownerA)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => 'WABA-N', 'phone_number_id' => 'PN-N',
        ])->assertSessionHasNoErrors();

        $line = $this->lineA->fresh();
        $this->assertSame('PN-N', $line->phone_number_id);
        $this->assertSame($this->lineA->id, WhatsAppConnections::forBusiness($this->shopA->id)->id, 'الوصلةُ نفسُها حُدّثت');
        $this->assertEquals(WhatsAppLinkHealth::UNHEARD, $line->only(array_keys(WhatsAppLinkHealth::UNHEARD)));
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($line), 'لا يُقال «استُقبلت» عن رقمٍ لم يصله شيء');
        $this->assertNotNull($this->chatOf($this->shopA), 'محادثاتُ الرقم القديم باقية');

        $this->customerWrites('PN-N', 'wamid.NEW-1');
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line->fresh()));
    }

    /** والربطُ اليدويّ مثلُه: الرقمُ نفسُه يبقى تاريخُه، ورقمٌ آخر يبدأ من لا شيء */
    public function test_a_manual_relink_keeps_the_same_numbers_history_and_forgets_another_numbers(): void
    {
        $this->customerWrites('PN-A', 'wamid.KEPT-1');
        $this->tokenApp['token-A2-from-abaads-app-0123'] = self::ABAAD_APP;

        $this->actingAs($this->ownerA)->post(route('admin.integrations.whatsapp.connect'), [
            'waba_id' => 'WABA-A', 'phone_number_id' => 'PN-A', 'access_token' => 'token-A2-from-abaads-app-0123',
        ])->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($this->lineA->fresh()), 'الرقمُ نفسُه — تاريخُه باقٍ');

        $this->tokenApp['token-N2-from-abaads-app-0123'] = self::ABAAD_APP;
        $this->actingAs($this->ownerA)->post(route('admin.integrations.whatsapp.connect'), [
            'waba_id' => 'WABA-N', 'phone_number_id' => 'PN-N', 'access_token' => 'token-N2-from-abaads-app-0123',
        ])->assertSessionHasNoErrors();

        $line = $this->lineA->fresh();
        $this->assertSame('PN-N', $line->phone_number_id);
        $this->assertEquals(WhatsAppLinkHealth::UNHEARD, $line->only(array_keys(WhatsAppLinkHealth::UNHEARD)));
        $this->assertNotSame(WhatsAppLinkHealth::INBOUND, WhatsAppLinkHealth::state($line));
    }
}
