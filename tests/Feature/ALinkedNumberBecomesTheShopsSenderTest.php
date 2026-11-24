<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSenderAdoption;
use App\Models\WhatsAppTemplateMapping;
use App\Support\Demo;
use App\Support\MetaWhatsAppClient;
use App\Support\OrderStatus;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppCampaignTemplates;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppMerchantLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppNoticeTemplates;
use App\Support\WhatsAppOwnApp;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppSender;
use App\Support\WhatsAppSenderReadiness;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ‏‏رقمٌ ربطه التاجر يصير رقمَ إرسال متجره وحده — بعد أن تثبت جاهزيّتُه عند Meta.
 *
 * ═══ ما تحرسه هذه الملفّة ═══
 *
 *   · لا يُبدَّل الإرسالُ إلّا وكلُّ مرحلةٍ تمّت بدليل — وكلُّ نقصٍ يُبقي الإرسالَ كما هو ويُقال بسببه.
 *   · ولا يخرج بعد التبديل شيءٌ من رقم أبعاد ولا من رقم متجرٍ آخر، ولو تعطّل رقمُ المتجر.
 *   · ولا رسالةَ تتكرّر ولا تضيع في الثواني التي يُبدَّل فيها.
 *   · ووصلةُ تطبيق التاجر (RIBBON) لا تُفحص ولا تُبدَّل ولا يُنشأ في حسابها شيء.
 *   · ووصلةٌ رُبطت قبل هذا لا يتبدّل إرسالُها من تلقاء نفسه.
 */
class ALinkedNumberBecomesTheShopsSenderTest extends TestCase
{
    use RefreshDatabase;

    private const APP = '1082481610822941';

    private const CODE = 'AQD-short-lived-code-0123456789';

    private Business $shop;

    private User $owner;

    private User $super;

    private Customer $customer;

    /** ‏‏ما تقوله Meta المزيّفة الآن — يُقرأ عند كلّ نداء */
    private array $meta = [];

    /** @var array<string, array<string, array{status: string, body: string}>> حسابٌ ← اسم|لغة ← قالب */
    private array $templates = [];

    /** @var list<array{method: string, url: string, token: ?string, data: array}> */
    private array $calls = [];

    private int $sent = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.merchant_link' => 'abaad_app',
            'whatsapp.app_id' => self::APP,
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.abaad_business_ids' => ['MB-ABAAD'],
            'whatsapp.ribbon_businesses' => [],
        ]);

        $this->platform('whatsapp_enabled', '1');
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '100');

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN-ABAAD', 'waba_id' => 'WABA-ABAAD', 'meta_business_id' => 'MB-ABAAD',
            'display_phone_number' => '+968 7114 1624', 'access_token' => 'TOKEN-OF-ABAAD',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');
        WhatsAppTemplateMapping::query()->platform()->update(['meta_status' => 'APPROVED']);

        [$this->shop, $this->owner, $this->customer] = $this->makeShop('محل ورد', 'o@abaadapp.om', '91234567');
        $this->super = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaadapp.om', 'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->meta = [
            'token_app' => self::APP, 'token_valid' => true, 'down' => false,
            'numbers' => ['WABA-1' => ['PN-1' => 'CLOUD_API'], 'WABA-2' => ['PN-2' => 'CLOUD_API']],
            'status' => 'CONNECTED', 'can_send' => 'AVAILABLE', 'why' => null,
            'subscribed' => ['WABA-1' => false, 'WABA-2' => false], 'accepts_subscribe' => true, 'override' => null,
            'routing' => null, 'app_fields' => ['messages'], 'app_callback' => route('webhooks.whatsapp'),
            'owner' => 'MB-SHOP', 'funding' => null, 'review' => 'APPROVED', 'refuse_create' => null, 'upload' => true,
            'send_error' => [],
        ];
        $this->fakeMeta();
    }

    /* ═════════════ التهيئة ═════════════ */

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    /** @return array{0: Business, 1: User, 2: Customer} */
    private function makeShop(string $name, string $email, string $phone, string $mode = WhatsAppMode::ABAAD_SHARED): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'whatsapp_own_allowed' => true, 'whatsapp_enabled' => true, 'whatsapp_mode' => $mode]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط']);
        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'زبون', 'phone' => $phone]);

        return [$shop, $owner, $customer];
    }

    /** ‏‏Meta كلُّها من دالّةٍ واحدة تقرأ `$this->meta` — وكلُّ نداءٍ يُسجَّل برمزه */
    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = trim((string) parse_url($r->url(), PHP_URL_PATH), '/');
            $path = (string) preg_replace('#^v26\.0/#', '', $path);
            $query = [];
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $token = preg_match('/^(?:Bearer|OAuth) (.+)$/', (string) ($r->header('Authorization')[0] ?? ''), $m) ? $m[1] : null;
            $this->calls[] = ['method' => $r->method(), 'url' => $path, 'token' => $token, 'data' => is_array($r->data()) ? $r->data() : [], 'query' => $query];

            if ($this->meta['down']) {
                throw new ConnectionException('timeout');
            }

            return $this->answer($r, $path, $query);
        });
    }

    private function answer(HttpRequest $r, string $path, array $query)
    {
        $m = $this->meta;
        $fields = (string) ($query['fields'] ?? '');
        $waba = preg_match('#^(WABA-[A-Z0-9]+)#', $path, $x) ? $x[1] : null;
        $phone = preg_match('#^(PN-[A-Z0-9]+)#', $path, $x) ? $x[1] : null;

        if ($path === 'oauth/access_token') {
            return Http::response(['access_token' => 'TOKEN-OF-SHOP-'.substr((string) ($query['code'] ?? 'X'), -1)], 200);
        }

        if ($path === 'debug_token') {
            $w = str_ends_with((string) ($query['input_token'] ?? ''), 'B') ? 'WABA-2' : 'WABA-1';

            return Http::response(['data' => [
                'is_valid' => $m['token_valid'], 'app_id' => $m['token_app'], 'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => [$w]],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => [$w]],
                ],
            ]], 200);
        }

        if ($path === self::APP.'/subscriptions') {
            return Http::response(['data' => [['object' => 'whatsapp_business_account', 'callback_url' => $m['app_callback'], 'active' => true, 'fields' => array_map(fn ($f) => ['name' => $f], $m['app_fields'])]]], 200);
        }

        if ($path === self::APP.'/uploads') {
            return $m['upload'] ? Http::response(['id' => 'upload:SESSION'], 200) : Http::response(['error' => ['code' => 200, 'message' => 'no permission']], 403);
        }

        if (str_starts_with($path, 'upload:')) {
            return Http::response(['h' => '4::HANDLE'], 200);
        }

        if (str_contains($path, '_whatsapp_business_accounts')) {
            return Http::response(['data' => [['id' => 'WABA-ABAAD']]], 200);
        }

        if ($waba !== null && str_ends_with($path, '/phone_numbers')) {
            return Http::response(['data' => collect($m['numbers'][$waba] ?? [])->map(fn ($type, $id) => ['id' => $id, 'display_phone_number' => '+968 9000 000'.substr($id, -1), 'platform_type' => $type])->values()->all()], 200);
        }

        if ($waba !== null && str_ends_with($path, '/subscribed_apps')) {
            if ($r->method() === 'POST') {
                $this->meta['subscribed'][$waba] = $m['accepts_subscribe'];

                return Http::response(['success' => true], 200);
            }

            return Http::response(['data' => ($m['subscribed'][$waba] ?? false)
                ? [['whatsapp_business_api_data' => ['id' => self::APP]] + ($m['override'] ? ['override_callback_uri' => $m['override']] : [])] : []], 200);
        }

        if ($waba !== null && str_ends_with($path, '/message_templates')) {
            if ($r->method() === 'POST') {
                if ($m['refuse_create'] !== null) {
                    return Http::response(['error' => ['code' => 100, 'message' => 'bad', 'error_user_msg' => $m['refuse_create']]], 400);
                }
                $d = $r->data();
                $body = collect($d['components'])->firstWhere('type', 'BODY')['text'];
                $this->templates[$waba][$d['name'].'|'.$d['language']] = ['status' => $m['review'], 'body' => $body];

                return Http::response(['id' => 'T-'.count($this->templates[$waba]), 'status' => $m['review'], 'category' => $d['category']], 200);
            }

            return Http::response(['data' => collect($this->templates[$waba] ?? [])->map(function ($t, $key) {
                [$name, $lang] = explode('|', $key);

                return ['id' => 'T', 'name' => $name, 'language' => $lang, 'status' => $t['status'], 'components' => [['type' => 'BODY', 'text' => $t['body']]]];
            })->values()->all()], 200);
        }

        if ($phone !== null && str_contains($fields, 'health_status')) {
            return Http::response(array_filter([
                'status' => $m['status'],
                'health_status' => $m['can_send'] === null ? null : ['can_send_message' => $m['can_send'], 'entities' => [
                    ['entity_type' => 'WABA', 'id' => 'WABA-1', 'can_send_message' => $m['can_send']] + ($m['why'] ? ['errors' => [['error_code' => 141006, 'error_description' => $m['why'], 'possible_solution' => 'Add one.']]] : []),
                ]],
            ]), 200);
        }

        if ($phone !== null && str_ends_with($path, '/messages')) {
            if (isset($m['send_error'][$phone])) {
                return Http::response(['error' => ['code' => $m['send_error'][$phone], 'message' => 'refused']], 400);
            }

            return Http::response(['messages' => [['id' => 'wamid.'.(++$this->sent)]]], 200);
        }

        if ($phone !== null && str_ends_with($path, '/media')) {
            return Http::response(['id' => 'MEDIA-1'], 200);
        }

        if ($phone !== null && str_ends_with($path, '/register')) {
            $this->meta['numbers']['WABA-1'][$phone] = 'CLOUD_API';

            return Http::response(['success' => true], 200);
        }

        if ($phone !== null && str_contains($path, 'routing')) {
            return $m['routing'] === null
                ? Http::response(['error' => ['code' => 100, 'message' => 'Conversation routing is not enabled']], 400)
                : Http::response(['roles' => $m['routing']], 200);
        }

        if ($phone !== null && str_contains($fields, 'is_on_biz_app')) {
            return Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200);
        }

        if ($waba !== null && str_contains($fields, 'primary_funding_id')) {
            return $m['funding'] === null ? Http::response(['error' => ['code' => 10, 'message' => 'no']], 403)
                : Http::response($m['funding'] === 'absent' ? ['id' => $waba] : ['id' => $waba, 'primary_funding_id' => 'F-1'], 200);
        }

        if ($waba !== null) {
            return Http::response(['id' => $waba] + ($m['owner'] ? ['owner_business_info' => ['id' => $m['owner'], 'name' => 'نشاط']] : []), 200);
        }

        if ($phone !== null) {
            return Http::response(['display_phone_number' => '+968 9000 0001', 'verified_name' => 'محل', 'platform_type' => 'CLOUD_API'], 200);
        }

        return Http::response(['error' => ['code' => 1, 'message' => 'unexpected '.$path]], 400);
    }

    /** ‏‏ربطٌ بضغطة من شاشة التاجر — الحرفُ الأخير من الكود يختار الحساب */
    private function link(?User $owner = null, string $suffix = 'A', string $waba = 'WABA-1', string $phone = 'PN-1')
    {
        return $this->actingAs($owner ?? $this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => self::CODE.$suffix, 'waba_id' => $waba, 'phone_number_id' => $phone,
        ]);
    }

    private function row(?Business $shop = null): ?WhatsAppSenderAdoption
    {
        return WhatsAppSenderAdoption::where('business_id', ($shop ?? $this->shop)->id)->orderByDesc('id')->first();
    }

    private function tick(): void
    {
        WhatsAppSenderAdoption::where('state', 'checking')->update(['next_check_at' => now()->subSecond()]);
        $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
    }

    private function watchOnce(): void
    {
        WhatsAppSenderAdoption::where('state', 'adopted')->update(['health_checked_at' => now()->subHours(2)]);
        $this->artisan('whatsapp:adopt-numbers --watch')->assertSuccessful();
    }

    private function mode(?Business $shop = null): string
    {
        return ($shop ?? $this->shop)->fresh()->whatsapp_mode;
    }

    private function adopt(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode(), 'لم يُعتمد: '.json_encode($this->row()?->checks, JSON_UNESCAPED_UNICODE));
        $this->calls = [];
    }

    private function order(?Business $shop = null, ?Customer $customer = null): Order
    {
        $shop ??= $this->shop;

        return Order::create([
            'business_id' => $shop->id, 'branch_id' => Branch::where('business_id', $shop->id)->value('id'),
            'customer_id' => ($customer ?? $this->customer)->id, 'number' => 'INV-'.uniqid(), 'status' => OrderStatus::PENDING,
            'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    /** @return list<array{phone: string, token: ?string, template: ?string}> ما خرج إلى عملاء */
    private function outgoing(): array
    {
        return array_values(array_map(fn ($c) => [
            'phone' => explode('/', $c['url'])[0], 'token' => $c['token'], 'template' => $c['data']['template']['name'] ?? null,
        ], array_filter($this->calls, fn ($c) => str_ends_with($c['url'], '/messages'))));
    }

    /** @return array<string, string> نصوصُ الجرس بمفاتيحها */
    private function bell(User $user): array
    {
        $this->actingAs($user);

        return collect(Demo::notificationFeed()['items'])->mapWithKeys(fn ($n) => [$n['key'] => (string) $n['text']])->all();
    }

    /* ═════════════ الاعتماد ═════════════ */

    public function test_a_ready_number_becomes_the_shops_sender_by_itself(): void
    {
        $this->link()->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['checking', 'embedded', 'PN-1', $this->owner->id], [$row->state, $row->via, $row->phone_number_id, $row->started_by]);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode(), 'الربطُ وحده لا يبدّل — يُفحص أوّلًا');
        $this->assertStringContainsString('تلقائيًّا', (string) session('toast.msg'));

        $this->tick();

        $row->refresh();
        $this->assertSame(['adopted', 'abaad_shared', null], [$row->state, $row->from_mode, $row->code]);
        $this->assertNotNull($row->adopted_at);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());

        $log = ActivityLog::where('business_id', $this->shop->id)->where('subject_type', WhatsAppSenderAdoption::class)->sole();
        $this->assertStringContainsString('رقم أبعاد ← رقم المتجر', $log->description);
        $this->assertSame('النظام', $log->user_name);
    }

    public function test_the_owner_and_the_platform_team_hear_that_the_number_was_adopted(): void
    {
        $this->adopt();

        $this->assertStringContainsString('+968 9000 0001', $this->bell($this->owner)['wa-sender-on-'.$this->row()->id]);
        $this->assertStringContainsString('محل ورد', $this->bell($this->super)['wa-sender-on-'.$this->row()->id]);

        // ‏‏وصاحبُ متجرٍ آخر لا يقرأ خبرَ غيره
        [, $other] = $this->makeShop('متجر آخر', 'x@abaadapp.om', '95555555');
        $this->assertSame([], array_filter(array_keys($this->bell($other)), fn ($k) => str_starts_with($k, 'wa-sender')));

        $this->travel(8)->days();
        $this->assertArrayNotHasKey('wa-sender-on-'.$this->row()->id, $this->bell($this->owner));
    }

    public function test_a_new_shop_already_on_its_own_number_is_checked_and_told_too(): void
    {
        [$shop, $owner] = $this->makeShop('متجر جديد', 'n@abaadapp.om', '95551111', WhatsAppMode::DEFAULT);
        $this->link($owner, 'B', 'WABA-2', 'PN-2')->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('تلقائيًّا', (string) session('toast.msg'));
        $this->tick();

        $row = $this->row($shop);
        $this->assertSame(['adopted', 'business_own'], [$row->state, $row->from_mode]);
        $this->assertArrayHasKey('wa-sender-on-'.$row->id, $this->bell($owner));
    }

    public function test_the_client_link_arms_the_same_adoption(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))->assertSessionHasNoErrors();
        $url = session('whatsappClientLink')['url'];
        auth()->logout();

        $this->get($url)->assertRedirect(route('clientLink.show'));
        $this->post(route('clientLink.connect'), ['code' => self::CODE.'A', 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'shop_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame(['checking', 'client_link', null], [$this->row()->state, $this->row()->via, $this->row()->started_by]);
        $this->assertStringContainsString('تلقائيًّا', (string) session('status'));

        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_the_manual_form_arms_the_same_adoption(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), [
            'phone_number_id' => 'PN-1', 'waba_id' => 'WABA-1', 'access_token' => 'TOKEN-OF-SHOP-0123456789-A',
        ])->assertSessionHasNoErrors();

        $this->assertSame('manual', $this->row()->via);
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    /* ═════════════ ما لا يُبدَّل معه ═════════════ */

    public function test_a_failed_link_changes_nothing(): void
    {
        $this->meta['token_valid'] = false;
        $this->link()->assertSessionHasErrors();

        $this->assertNull($this->row());
        $this->tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    public function test_a_link_that_showed_no_number_yet_is_not_adopted(): void
    {
        $this->meta['numbers']['WABA-1'] = [];
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), ['code' => self::CODE.'A', 'waba_id' => 'WABA-1'])->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::PENDING, WhatsAppConnections::forBusiness($this->shop->id)->status);
        $this->assertNull($this->row());
        $this->tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    public function test_a_number_awaiting_activation_is_adopted_only_after_its_pin(): void
    {
        $this->meta['numbers']['WABA-1']['PN-1'] = 'NOT_APPLICABLE';
        $this->link()->assertSessionHasNoErrors();
        $this->assertNull($this->row(), 'رقمٌ ينتظر رمزَه لا يُنظر فيه');
        $this->tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => '123456'])->assertSessionHasNoErrors();
        $this->assertSame(['checking', 'registered'], [$this->row()->state, $this->row()->via]);
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function notReady(): array
    {
        return [
            'الرمز لم يعد صالحًا' => [['token_valid' => false], 'invalid_token', 'merchant'],
            'الرمز من تطبيق آخر' => [['token_app' => '999'], 'wrong_app', 'merchant'],
            'الرقم غير متصل' => [['status' => 'DISCONNECTED'], 'number_off', 'merchant'],
            'وسيلة الدفع معطوبة' => [['can_send' => 'BLOCKED', 'why' => 'There is an error with the payment method.'], 'number_payment', 'merchant'],
            'Meta تمنع الإرسال بلا سبب' => [['can_send' => 'BLOCKED'], 'number_blocked', 'merchant'],
            'لم تقل Meta إنّه يُرسل' => [['status' => null, 'can_send' => null], 'number_unknown', 'meta'],
            'الحساب لأبعاد' => [['owner' => 'MB-ABAAD'], 'billed_to_abaad', 'abaad'],
            'لا يُعرف مالك الحساب' => [['owner' => null], 'owner_unknown', 'meta'],
            'لا وسيلة دفع' => [['funding' => 'absent'], 'no_payment_method', 'merchant'],
            'إشعاراته موجّهة إلى نظام آخر' => [['override' => 'https://elsewhere.example/hook'], 'webhook_elsewhere', 'merchant'],
            'توجيه المحادثات لغير أبعاد' => [['routing' => ['marketing']], 'routed_away', 'merchant'],
            'تطبيق أبعاد لا يستمع' => [['app_fields' => ['account_update']], 'app_deaf', 'abaad'],
            'عنوان تطبيق أبعاد إلى غيرنا' => [['app_callback' => 'https://elsewhere.example/hook'], 'app_deaf', 'abaad'],
            'قوالبه قيد المراجعة' => [['review' => 'PENDING'], 'templates_pending', 'meta'],
            'قوالبه رُفضت' => [['review' => 'REJECTED'], 'template_rejected', 'abaad'],
            'لم تُقبل قوالبه' => [['refuse_create' => 'لا يُسمح بالنص'], 'template_refused', 'abaad'],
            'انقطعت Meta' => [['down' => true], 'unreachable', 'meta'],
        ];
    }

    #[DataProvider('notReady')]
    public function test_a_number_that_is_not_ready_never_becomes_the_sender(array $says, string $code, string $who): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->meta = array_merge($this->meta, $says);

        if (isset($says['override'])) {
            $this->meta['subscribed']['WABA-1'] = true;
        }

        $this->tick();

        $row = $this->row();
        $this->assertSame(['checking', $code], [$row->state, $row->code], json_encode($row->checks, JSON_UNESCAPED_UNICODE));

        if (in_array($code, ['invalid_token', 'wrong_app', 'billed_to_abaad'], true)) {
            $this->assertSame([], array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], 'message_templates')), 'ولا يُنشأ شيءٌ في حسابٍ لم يثبت');
        }
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode(), 'لا يُبدَّل إرسالُه');
        $this->assertSame($who, WhatsAppSender::waitingFor($row)['who']);
        $this->assertNotSame('', WhatsAppSender::waitingFor($row)['text']);
        $this->assertTrue($row->next_check_at->isFuture(), 'ويُعاد فحصُه');

        // ‏‏وحين يزول السببُ يُبدَّل وحده
        $this->meta = array_merge($this->meta, [
            'token_app' => self::APP, 'token_valid' => true, 'down' => false, 'status' => 'CONNECTED', 'can_send' => 'AVAILABLE', 'why' => null,
            'override' => null, 'routing' => null, 'app_fields' => ['messages'], 'app_callback' => route('webhooks.whatsapp'),
            'owner' => 'MB-SHOP', 'funding' => null, 'review' => 'APPROVED', 'refuse_create' => null,
        ]);
        $this->templates = [];
        Cache::flush();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode(), json_encode($this->row()->checks, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_subscription_that_never_takes_keeps_the_shop_where_it_is(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->meta['subscribed']['WABA-1'] = false;
        $this->meta['accepts_subscribe'] = false;
        $this->tick();
        $this->assertSame(['checking', 'webhook_missing'], [$this->row()->state, $this->row()->code]);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        // ‏‏واشتراكٌ غاب وتقبله Meta يُصلَح في الفحص نفسه
        $this->meta['accepts_subscribe'] = true;
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function gates(): array
    {
        return [
            'واتساب موقوف في المنصة' => ['platform', 'platform_off'],
            'واتساب مطفأ للمتجر' => ['account', 'account_off'],
            'صلاحية الرقم سُحبت' => ['own', 'own_not_allowed'],
            'المتجر معطّل' => ['suspended', 'shop_suspended'],
        ];
    }

    #[DataProvider('gates')]
    public function test_our_own_gates_hold_the_switch_and_ask_meta_nothing(string $gate, string $code): void
    {
        $this->link()->assertSessionHasNoErrors();
        match ($gate) {
            'platform' => $this->platform('whatsapp_enabled', '0'),
            'account' => $this->shop->update(['whatsapp_enabled' => false]),
            'own' => $this->shop->update(['whatsapp_own_allowed' => false]),
            'suspended' => $this->shop->update(['status' => 'معطل']),
        };
        $this->calls = [];
        $this->tick();

        $this->assertSame($code, $this->row()->code);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
        $this->assertSame([], $this->calls);
    }

    public function test_the_checks_slow_down_and_one_broken_check_does_not_stop_the_others(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->meta['review'] = 'PENDING';

        foreach ([1 => 1, 5 => 5, 20 => 30, 100 => 360] as $attempt => $minutes) {
            $this->row()->update(['attempts' => $attempt - 1]);
            $this->tick();
            $this->assertEqualsWithDelta($minutes, now()->diffInMinutes($this->row()->next_check_at), 0.2, 'محاولة '.$attempt);
        }
    }

    /* ═════════════ القوالب ═════════════ */

    public function test_the_notice_templates_are_made_in_the_shops_own_account(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->tick();

        $made = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/message_templates')));
        $this->assertCount(14, $made, 'سبعةُ أحداثٍ بلغتين');

        foreach ($made as $c) {
            $this->assertSame(['WABA-1/message_templates', 'TOKEN-OF-SHOP-A', 'UTILITY'], [$c['url'], $c['token'], $c['data']['category']]);
            $body = collect($c['data']['components'])->firstWhere('type', 'BODY');
            preg_match_all('/\{\{(\d)\}\}/', $body['text'], $vars);
            $this->assertCount(count($vars[1]), $body['example']['body_text'][0], 'مثالٌ لكلّ متغيّر');
            // ‏‏Meta تردّ نصًّا يبدأ بمتغيّرٍ أو ينتهي به أو يتلاصق فيه اثنان
            $this->assertDoesNotMatchRegularExpression('/^\{\{|\}\}[\s.،,!]*$|\}\}\s*\{\{/u', $body['text']);
            // ‏‏والعددُ ما يملؤه المُرسِل بعينه
            $this->assertCount($c['data']['name'] === 'abaad_invoice_paid' ? 3 : 2, $vars[1]);
        }

        $paid = collect($made)->first(fn ($c) => $c['data']['name'] === 'abaad_invoice_paid');
        $this->assertSame('4::HANDLE', $paid['data']['components'][0]['example']['header_handle'][0]);

        // ‏‏ولا يُعاد إنشاءُ ما وُجد
        $this->calls = [];
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/message_templates')));
    }

    public function test_a_template_the_shop_made_itself_is_never_touched(): void
    {
        $this->templates['WABA-1']['abaad_order_ready|ar'] = ['status' => 'APPROVED', 'body' => 'طلبك من {{1}} رقم {{2}} ينتظرك.'];
        $this->link()->assertSessionHasNoErrors();
        $this->tick();

        $this->assertSame('طلبك من {{1}} رقم {{2}} ينتظرك.', $this->templates['WABA-1']['abaad_order_ready|ar']['body']);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_a_template_with_other_variables_holds_the_switch(): void
    {
        $this->templates['WABA-1']['abaad_order_ready|ar'] = ['status' => 'APPROVED', 'body' => 'طلبك جاهز يا {{1}} من {{2}} رقم {{3}} فتفضّل.'];
        $this->link()->assertSessionHasNoErrors();
        $this->tick();

        $this->assertSame('template_mismatch', $this->row()->code);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    public function test_only_what_the_shop_turned_on_is_waited_for(): void
    {
        // ‏‏المتجرُ أطفأ إشعاراته كلَّها: لا قالبَ يُنتظر
        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => '0']);
        }
        $this->meta['review'] = 'PENDING';
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_the_paid_invoice_is_waited_for_only_while_abaad_sends_it_today(): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'wa_on_invoice_paid'], ['value' => '1']);
        $this->assertNotContains(WhatsAppEvent::INVOICE_PAID, WhatsAppNoticeTemplates::required($this->shop));

        WhatsAppTemplateMapping::create(['scope_type' => WhatsAppMode::OWNER_PLATFORM, 'event_type' => WhatsAppEvent::INVOICE_PAID, 'template_name' => 'abaad_invoice_paid', 'language_code' => 'ar', 'enabled' => true, 'meta_status' => 'APPROVED', 'variable_mapping' => []]);
        $this->assertContains(WhatsAppEvent::INVOICE_PAID, WhatsAppNoticeTemplates::required($this->shop));

        // ‏‏ونموذجُ الفاتورة لم يُرفع: لا يُبدَّل فتضيع فاتورةُ من دفع
        $this->meta['upload'] = false;
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame('template_refused', $this->row()->code);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    /* ═════════════ ما يخرج بعد التبديل ═════════════ */

    public function test_before_the_switch_notices_leave_from_abaad_and_after_it_from_the_shop(): void
    {
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertSame([['phone' => 'PN-ABAAD', 'token' => 'TOKEN-OF-ABAAD', 'template' => 'abaad_order_confirmed']], $this->outgoing());
        $used = $this->usage();

        $this->adopt();
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);

        $this->assertSame([['phone' => 'PN-1', 'token' => 'TOKEN-OF-SHOP-A', 'template' => 'abaad_order_confirmed']], $this->outgoing());
        $this->assertSame($used, $this->usage(), 'ومن رقمه لا تُخصم من حصّته المشتركة');
    }

    private function usage(): int
    {
        return (int) WhatsAppQuota::snapshot($this->shop->fresh())['used'];
    }

    public function test_every_kind_of_message_leaves_from_the_adopted_number(): void
    {
        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => '1']);
        }
        $this->adopt();

        // حالاتُ الطلب كلُّها
        $order = $this->order();
        foreach ([OrderStatus::CONFIRMED, OrderStatus::READY, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED] as $status) {
            WhatsAppAutomation::handle($order, WhatsAppEvent::forStatus($status));
        }

        // تذكيرا الفاتورة، والفاتورةُ بعد دفعها برابط
        $invoice = CustomerInvoice::create(['business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'number' => 'CI-1', 'status' => 'issued', 'issued_at' => now(), 'total' => 10, 'subtotal' => 10]);
        WhatsAppAutomation::handleInvoice($invoice, WhatsAppEvent::INVOICE_DUE_SOON);
        WhatsAppAutomation::handleInvoice($invoice, WhatsAppEvent::INVOICE_OVERDUE);

        // وردُّ موظّفٍ في المحادثات
        $chat = WhatsAppChat::create([
            'business_id' => $this->shop->id, 'phone_number_id' => 'PN-1', 'source_mode' => WhatsAppMode::BUSINESS_OWN, 'customer_phone' => '96891234567',
            'last_inbound_at' => now(), 'last_message_at' => now(), 'last_direction' => 'in',
        ]);
        $line = WhatsAppInbox::check($chat)['line'];
        $this->assertSame('PN-1', $line?->phone_number_id);
        MetaWhatsAppClient::sendText($line, '96891234567', 'أهلًا');

        // وفاتورةٌ دُفعت برابط دفع — ملفًّا في رأس قالبها
        $link = new CustomerPaymentLink;
        $link->forceFill(['id' => 900, 'business_id' => $this->shop->id, 'customer_invoice_id' => $invoice->id, 'amount' => 10]);
        $this->assertSame(WhatsAppStatus::SENT, WhatsAppAutomation::handlePaidLink($link)?->fresh()->status);
        $this->assertContains('PN-1/media', array_column($this->calls, 'url'), 'والملفُّ رُفع إلى حساب رقمه');

        // وردٌّ آليٌّ على رسالة عميلٍ كتب إلى الرقم
        $body = json_encode(['entry' => [['id' => 'WABA-1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'PN-1'], 'messages' => [['id' => 'wamid.IN', 'from' => '96895556666', 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'مرحبًا']]],
        ]]]]]]);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one')], $body)->assertOk();

        // والحملاتُ من وصلته هو — وقالبُها في حسابه
        $this->assertSame('PN-1', WhatsAppCampaignTemplates::connection($this->shop->fresh())?->phone_number_id);

        $sent = $this->outgoing();
        $this->assertCount(9, $sent);
        $this->assertSame([['PN-1', 'TOKEN-OF-SHOP-A']], array_values(array_unique(array_map(fn ($s) => [$s['phone'], $s['token']], $sent), SORT_REGULAR)));
        $this->assertSame([WhatsAppMode::BUSINESS_OWN], WhatsAppMessage::where('business_id', $this->shop->id)->distinct()->pluck('source_mode')->all());
    }

    public function test_one_shop_switching_moves_no_other_shop(): void
    {
        [$other, , $hisCustomer] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->adopt();

        $this->order($other, $hisCustomer)->update(['status' => OrderStatus::CONFIRMED]);
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode($other));
        $this->assertSame(['PN-ABAAD', 'PN-1'], array_column($this->outgoing(), 'phone'));
    }

    public function test_two_adopted_shops_never_share_a_number_a_token_or_a_template(): void
    {
        [$b, $ownerB, $customerB] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->adopt();
        $this->link($ownerB, 'B', 'WABA-2', 'PN-2')->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode($b));

        // ‏‏قالبُ أحدهما يُوقف فلا يمسّ الآخر
        $this->templates['WABA-2']['abaad_order_confirmed|ar']['status'] = 'PAUSED';
        $this->artisan('whatsapp:sync-templates');
        $this->calls = [];

        $this->order($b, $customerB)->update(['status' => OrderStatus::CONFIRMED]);
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);

        $this->assertSame([['phone' => 'PN-1', 'token' => 'TOKEN-OF-SHOP-A', 'template' => 'abaad_order_confirmed']], $this->outgoing());
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, WhatsAppMessage::where('business_id', $b->id)->sole()->error_code);

        $this->order($b, $customerB)->update(['status' => OrderStatus::READY]);
        $this->assertSame(['phone' => 'PN-2', 'token' => 'TOKEN-OF-SHOP-B', 'template' => 'abaad_order_ready'], $this->outgoing()[1]);

        // ‏‏ووصلةُ متجرٍ لا تُردّ لغيره ولو كُتب رقمُها في صفّ رسالة
        $mine = WhatsAppConnections::forBusiness($this->shop->id);
        $message = WhatsAppMessage::create([
            'business_id' => $b->id, 'whatsapp_connection_id' => $mine->id, 'source_mode' => WhatsAppMode::BUSINESS_OWN, 'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound', 'recipient_phone' => '96892222222', 'template_name' => 'abaad_order_ready', 'language_code' => 'ar', 'status' => WhatsAppStatus::QUEUED,
            'order_id' => $this->order($b, $customerB)->id, 'dedupe_key' => 'forged', 'queued_at' => now(),
        ]);
        $this->calls = [];
        (new SendWhatsAppMessage($message->id))->handle();
        $this->assertSame('PN-2', $this->outgoing()[0]['phone']);
    }

    /* ═════════════ في أثناء التبديل ═════════════ */

    private function queuedFromAbaad(array $over = []): WhatsAppMessage
    {
        return WhatsAppMessage::create($over + [
            'business_id' => $this->shop->id, 'order_id' => $this->order()->id, 'source_mode' => WhatsAppMode::ABAAD_SHARED, 'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound', 'recipient_phone' => '96891234567', 'template_name' => 'abaad_order_ready', 'language_code' => 'ar',
            'status' => WhatsAppStatus::QUEUED, 'dedupe_key' => uniqid('q'), 'queued_at' => now(),
        ]);
    }

    public function test_a_message_on_its_way_from_abaad_leaves_before_the_switch(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $message = $this->queuedFromAbaad();
        $this->tick();

        $this->assertSame(['checking', 'queue_busy'], [$this->row()->state, $this->row()->code]);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        $this->calls = [];
        (new SendWhatsAppMessage($message->id))->handle();
        $this->assertSame(['PN-ABAAD'], array_column($this->outgoing(), 'phone'), 'خرجت من رقمها مرّةً');

        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
        $this->assertSame(['PN-ABAAD'], array_column($this->outgoing(), 'phone'), 'ولم تتكرّر بالتبديل');
    }

    public function test_a_stuck_message_does_not_hold_the_switch_forever(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->queuedFromAbaad()->forceFill(['created_at' => now()->subMinutes(WhatsAppSenderReadiness::DRAIN_MINUTES + 1)])->save();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_the_same_event_never_goes_out_twice_around_the_switch(): void
    {
        $order = $this->order();
        $order->update(['status' => OrderStatus::CONFIRMED]);
        $this->adopt();

        WhatsAppAutomation::handle($order->fresh(), WhatsAppEvent::ORDER_CONFIRMED);
        $order->update(['status' => OrderStatus::PREPARING]);
        $order->update(['status' => OrderStatus::CONFIRMED]);

        $this->assertSame([], $this->outgoing());
        $this->assertSame(1, WhatsAppMessage::where('order_id', $order->id)->where('event_type', WhatsAppEvent::ORDER_CONFIRMED)->count());
    }

    public function test_what_changes_while_the_check_runs_wins_over_the_switch(): void
    {
        // ‏‏فُصل الرقمُ بين الفحص والتبديل
        $this->link()->assertSessionHasNoErrors();
        $row = $this->row();
        $row->update(['checks' => [], 'code' => null]);
        WhatsAppConnections::forBusiness($this->shop->id)->update(['status' => WhatsAppConnection::INACTIVE]);
        $adopt = new \ReflectionMethod(WhatsAppSender::class, 'adopt');
        $this->assertFalse($adopt->invoke(null, $row));
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        // ‏‏أو دخلت رسالةٌ طابورَ رقم أبعاد
        WhatsAppConnections::forBusiness($this->shop->id)->update(['status' => WhatsAppConnection::ACTIVE]);
        $message = $this->queuedFromAbaad();
        $this->assertFalse($adopt->invoke(null, $row));

        // ‏‏أو اختار التاجر رقم أبعاد بيده
        $message->delete();
        WhatsAppSender::chosen($this->shop->id, WhatsAppMode::ABAAD_SHARED);
        $this->assertFalse($adopt->invoke(null, $row));
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        // ‏‏أو رُبط رقمٌ آخر مكانه
        $this->meta['numbers']['WABA-1']['PN-9'] = 'CLOUD_API';
        $this->link(null, 'A', 'WABA-1', 'PN-9')->assertSessionHasNoErrors();
        $this->assertFalse($adopt->invoke(null, $row));
        $this->assertSame(['closed', 'PN-1'], [$row->fresh()->state, $row->fresh()->phone_number_id]);
        $this->tick();
        $this->assertSame(['adopted', 'PN-9'], [$this->row()->state, $this->row()->phone_number_id]);
    }

    public function test_relinking_while_waiting_leaves_one_row_waiting(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->link()->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'checking'], WhatsAppSenderAdoption::orderBy('id')->pluck('state')->all());
        $this->tick();
        $this->assertSame(1, ActivityLog::where('subject_type', WhatsAppSenderAdoption::class)->count());
    }

    public function test_a_row_that_no_longer_describes_the_link_is_closed_and_asks_meta_nothing(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $c = WhatsAppConnections::forBusiness($this->shop->id);
        $adopt = new \ReflectionMethod(WhatsAppSender::class, 'adopt');

        // ‏‏رقمٌ آخر في الصفّ نفسه بلا ربطٍ من عندنا — استعادةُ نسخةٍ مثلًا
        $c->update(['phone_number_id' => 'PN-7']);
        $this->assertFalse($adopt->invoke(null, $this->row()));
        $this->calls = [];
        $this->tick();
        $this->assertSame(['closed', 'replaced', []], [$this->row()->state, $this->row()->closed_why, $this->calls]);

        // ‏‏ووصلةٌ عُطّلت من غير بابنا
        $c->update(['phone_number_id' => 'PN-1']);
        WhatsAppSender::linked($c->fresh(), WhatsAppSender::VIA_MANUAL);
        $c->update(['status' => WhatsAppConnection::INACTIVE]);
        $this->tick();
        $this->assertSame(['closed', 'unlinked', []], [$this->row()->state, $this->row()->closed_why, $this->calls]);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    public function test_only_a_link_that_can_send_is_taken_up(): void
    {
        $this->link()->assertSessionHasNoErrors();
        WhatsAppSenderAdoption::query()->delete();
        $c = WhatsAppConnections::forBusiness($this->shop->id);
        $c->update(['status' => WhatsAppConnection::PENDING]);
        WhatsAppSender::linked($c->fresh(), WhatsAppSender::VIA_EMBEDDED);
        WhatsAppSender::linked(WhatsAppConnections::platform(), WhatsAppSender::VIA_EMBEDDED);
        WhatsAppSender::linked(null, WhatsAppSender::VIA_EMBEDDED);
        $this->assertSame(0, WhatsAppSenderAdoption::count());
    }

    public function test_a_lost_link_is_told_only_to_a_shop_that_sends_from_it(): void
    {
        $this->adopt();
        WhatsAppConnections::forBusiness($this->shop->id)->update(['status' => WhatsAppConnection::EXPIRED]);
        $this->assertSame('link_expired', WhatsAppSender::problem($this->shop->fresh())['code']);
        $this->assertArrayHasKey('wa-sender-down-'.WhatsAppConnections::forBusiness($this->shop->id)->id, $this->bell($this->owner));

        // ‏‏ومن رسائلُه من رقم أبعاد لم يتعطّل إرسالُه
        $this->shop->fresh()->update(['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED]);
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));
        $this->assertArrayNotHasKey('wa-sender-down-'.WhatsAppConnections::forBusiness($this->shop->id)->id, $this->bell($this->owner));
    }

    public function test_two_runs_at_once_adopt_once(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $held = Cache::lock('whatsapp-sender:'.$this->shop->id, 60);
        $this->assertTrue($held->get());
        $this->tick();
        $this->assertSame('checking', $this->row()->state, 'فحصٌ يجري لا يُزاحَم');
        $held->release();
        $this->tick();
        $this->tick();
        $this->assertSame(1, ActivityLog::where('subject_type', WhatsAppSenderAdoption::class)->count());
    }

    /* ═════════════ التعطّل وإعادة الربط ═════════════ */

    public function test_a_broken_number_sends_from_nowhere_else_and_both_sides_are_told(): void
    {
        $this->adopt();
        $this->meta['token_valid'] = false;
        $this->watchOnce();

        $this->assertSame('invalid_token', $this->row()->health_code);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode(), 'لا يُرجَع إلى رقم أبعاد');
        $key = 'wa-sender-down-'.WhatsAppConnections::forBusiness($this->shop->id)->id;
        $this->assertStringContainsString('رسائل واتساب متجرك لا تخرج', $this->bell($this->owner)[$key]);
        $this->assertStringContainsString('محل ورد', $this->bell($this->super)[$key]);

        $this->calls = [];
        $this->meta['send_error'] = ['PN-1' => 190];
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertSame(['PN-1'], array_column($this->outgoing(), 'phone'), 'حاول من رقمه وحده');
        $this->assertSame(WhatsAppStatus::FAILED, WhatsAppMessage::where('business_id', $this->shop->id)->latest('id')->first()->status);

        $page = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'));
        $page->assertInertia(fn (AssertableInertia $p) => $p
            ->where('automation.sender.state', 'problem')->where('automation.sender.problem.who', 'merchant')
            ->where('automation.sender.shared_possible', true)->where('automation.sender.sends_from', 'own'));
        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->shop->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('whatsapp.sender.state', 'problem'));
    }

    public function test_a_link_that_cannot_send_stops_every_message_instead_of_moving_it(): void
    {
        $this->adopt();

        foreach ([WhatsAppConnection::ERROR, WhatsAppConnection::EXPIRED, WhatsAppConnection::REVOKED, WhatsAppConnection::INACTIVE] as $status) {
            WhatsAppConnections::forBusiness($this->shop->id)->update(['status' => $status]);
            $this->calls = [];
            $this->order()->update(['status' => OrderStatus::CONFIRMED]);

            $this->assertSame([], $this->outgoing(), $status);
            $this->assertSame(WhatsAppStatus::SKIP_NO_CONNECTION, WhatsAppMessage::where('business_id', $this->shop->id)->latest('id')->first()->error_code, $status);
            $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()), $status);
        }
    }

    public function test_relinking_a_broken_number_brings_the_shop_back(): void
    {
        $this->adopt();
        $first = $this->row();
        $this->meta['token_valid'] = false;
        $this->watchOnce();
        $this->assertNotNull(WhatsAppSender::problem($this->shop->fresh()));

        $this->meta['token_valid'] = true;
        $this->link()->assertSessionHasNoErrors();
        $this->assertNotSame($first->id, $this->row()->id);
        $this->tick();

        $this->assertSame(['adopted', 'business_own'], [$this->row()->state, $this->row()->from_mode]);
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));
        $this->assertArrayNotHasKey('wa-sender-down-'.WhatsAppConnections::forBusiness($this->shop->id)->id, $this->bell($this->owner));
        $this->calls = [];
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertSame(['PN-1'], array_column($this->outgoing(), 'phone'));
    }

    public function test_one_unsure_reading_is_not_called_a_breakdown(): void
    {
        $this->adopt();
        $this->meta['can_send'] = 'BLOCKED';
        $this->watchOnce();
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()), 'قراءةٌ واحدة لا تكفي');
        $this->watchOnce();
        $this->assertSame('number_blocked', WhatsAppSender::problem($this->shop->fresh())['code']);

        // ‏‏وانقطاعُ Meta لا يُثبت عطبًا ولا يمحوه
        $this->meta['down'] = true;
        $this->watchOnce();
        $this->assertSame('number_blocked', WhatsAppSender::problem($this->shop->fresh())['code']);

        $this->meta = array_merge($this->meta, ['down' => false, 'can_send' => 'AVAILABLE']);
        $this->watchOnce();
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));
        $this->assertSame(['تعطّل', 'عاد'], ActivityLog::where('subject_type', WhatsAppSenderAdoption::class)->orderBy('id')->skip(1)->take(5)->pluck('description')
            ->map(fn ($d) => str_contains($d, 'تعطّل') ? 'تعطّل' : 'عاد')->all());
    }

    public function test_the_log_is_written_in_arabic_whatever_the_schedulers_language(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->app->setLocale('en');
        $this->tick();
        $this->meta['token_valid'] = false;
        $this->watchOnce();

        $said = ActivityLog::where('subject_type', WhatsAppSenderAdoption::class)->orderBy('id')->pluck('description');
        $this->assertStringContainsString('رقم أبعاد ← رقم المتجر', $said[0]);
        $this->assertStringContainsString('منتهٍ أو مسحوب', $said[1]);
        $this->assertSame('en', $this->app->getLocale());
    }

    public function test_the_watch_only_reads_at_meta(): void
    {
        $this->adopt();
        // ‏‏ولو غاب اشتراكُ تطبيقنا: يُقال ولا يُصلَح بلا علم صاحبه
        $this->meta['subscribed']['WABA-1'] = false;
        $this->watchOnce();
        $this->watchOnce();

        $this->assertNotSame([], $this->calls);
        $this->assertSame(['GET'], array_values(array_unique(array_column($this->calls, 'method'))));
        $this->assertSame([], $this->outgoing());
        $this->assertSame('webhook_missing', WhatsAppSender::problem($this->shop->fresh())['code']);
    }

    public function test_meta_saying_something_changed_is_read_before_the_hour(): void
    {
        $this->adopt();
        $this->meta['token_valid'] = false;
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-1', 'changes' => [['field' => 'account_update', 'value' => ['event' => 'PARTNER_APP_UNINSTALLED', 'waba_info' => ['waba_id' => 'WABA-1']]]]]]]);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body)->assertOk();

        $this->assertNull($this->row()->health_checked_at);
        $this->assertTrue(WhatsAppSender::waiting());
        $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
        $this->assertSame('invalid_token', WhatsAppSender::problem($this->shop->fresh())['code']);
        $this->assertFalse(WhatsAppSender::waiting());
    }

    public function test_an_approved_template_wakes_the_number_that_waited_for_it(): void
    {
        $this->meta['review'] = 'PENDING';
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertTrue($this->row()->next_check_at->isFuture());

        foreach ($this->templates['WABA-1'] as $k => $t) {
            $this->templates['WABA-1'][$k]['status'] = 'APPROVED';
        }
        $body = json_encode(['entry' => [['id' => 'WABA-1', 'changes' => [['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED', 'message_template_name' => 'abaad_order_ready', 'message_template_language' => 'ar']]]]]]);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one')], $body)->assertOk();

        $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_sends_refused_for_the_number_itself_are_told_once_and_not_as_the_customers_fault(): void
    {
        $this->adopt();
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);

        $bell = $this->bell($this->owner);
        $key = 'wa-sender-down-'.WhatsAppConnections::forBusiness($this->shop->id)->id;
        $this->assertStringContainsString('الدفع', $bell[$key]);
        $this->assertArrayNotHasKey('wa-delivery', $bell, 'ولا يُقال مرّتين بسببين');

        // ‏‏ورسالةٌ خرجت بعدها تُغلقه
        $this->meta['send_error'] = [];
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));

        // ‏‏ورقمُ عميلٍ لا يستقبل ليس عطبًا في رقم المتجر
        $this->meta['send_error'] = ['PN-1' => 131026];
        $this->order()->update(['status' => OrderStatus::CONFIRMED]);
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));
    }

    public function test_a_meta_outage_while_checking_the_link_does_not_stop_the_shop(): void
    {
        $this->adopt();
        $this->meta['down'] = true;
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))->assertSessionHasErrors('code');
        $this->assertSame(WhatsAppConnection::ACTIVE, WhatsAppConnections::forBusiness($this->shop->id)->status);

        // ‏‏ورفضٌ من Meta نفسها يُكتب كما كان
        $this->meta['down'] = false;
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401)]);
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))->assertSessionHasErrors('code');
        $this->assertSame(WhatsAppConnection::ERROR, WhatsAppConnections::forBusiness($this->shop->id)->status);
    }

    /* ═════════════ اختيارُ صاحبه يغلب ═════════════ */

    public function test_choosing_abaads_number_by_hand_is_never_undone(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::ABAAD_SHARED])->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'chose_shared'], [$this->row()->state, $this->row()->closed_why]);
        $this->tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());

        // ‏‏وكذلك بعد الاعتماد: يرجع بيده ويبقى حيث اختار، ويعود إلى رقمه بيده بلا مراجعةٍ ثانية
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::ABAAD_SHARED])->assertSessionHasNoErrors();
        $this->tick();
        $this->watchOnce();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_the_platform_choosing_abaads_number_stops_the_switch_and_a_hand_cannot_skip_the_checks(): void
    {
        $this->link()->assertSessionHasNoErrors();

        // ‏‏قبل أن تتمّ المراحل لا يقفز فوقها أحد
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors('mode');
        $this->actingAs($this->super)->put(route('super-admin.businesses.whatsapp.update', $this->shop->id), ['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors('whatsapp_mode');
        $this->assertStringContainsString('تلقائيًّا', session('errors')->first('whatsapp_mode'));

        $this->actingAs($this->super)->put(route('super-admin.businesses.whatsapp.update', $this->shop->id), ['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED])->assertSessionHasNoErrors();
        $this->assertSame('closed', $this->row()->state);

        // ‏‏وحفظُ حدٍّ أو إذنٍ بلا وضعٍ لا يوقف شيئًا
        $this->link()->assertSessionHasNoErrors();
        $this->actingAs($this->super)->put(route('super-admin.businesses.whatsapp.update', $this->shop->id), ['whatsapp_monthly_limit' => 50])->assertSessionHasNoErrors();
        $this->assertSame('checking', $this->row()->state);
    }

    public function test_unlinking_ends_the_wait(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->assertSame(['closed', 'unlinked'], [$this->row()->state, $this->row()->closed_why]);
        $this->calls = [];
        $this->tick();
        $this->assertSame([], $this->calls);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    public function test_a_waiting_number_is_told_after_an_hour_to_whoever_can_fix_it(): void
    {
        $this->meta['funding'] = 'absent';
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $key = 'wa-sender-wait-'.$this->row()->id;
        $this->assertArrayNotHasKey($key, $this->bell($this->owner));

        $this->row()->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->assertStringContainsString('وسيلة دفع', $this->bell($this->owner)[$key]);
        $this->assertStringContainsString('وسيلة دفع', $this->bell($this->super)[$key]);

        // ‏‏وما على أبعاد لا يُطلب من التاجر، وما تنتظره Meta لا يُقال لأحد
        $this->meta = array_merge($this->meta, ['funding' => null, 'app_fields' => ['account_update']]);
        Cache::flush();
        $this->tick();
        $this->assertArrayNotHasKey($key, $this->bell($this->owner));
        $this->assertArrayHasKey($key, $this->bell($this->super));
        $this->meta = array_merge($this->meta, ['app_fields' => ['messages'], 'review' => 'PENDING']);
        foreach (array_keys($this->templates['WABA-1']) as $k) {
            $this->templates['WABA-1'][$k]['status'] = 'PENDING';
        }
        Cache::flush();
        $this->tick();
        $this->assertSame('templates_pending', $this->row()->code);
        $this->assertArrayNotHasKey($key, $this->bell($this->super));
    }

    /* ═════════════ من لا يُمسّ ═════════════ */

    private function ownAppLink(Business $shop): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $shop->id, 'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS, 'link_type' => WhatsAppOwnApp::TYPE,
            'waba_id' => 'WABA-HIS', 'phone_number_id' => 'PN-HIS', 'display_phone_number' => '+968 7679 0720',
            'access_token' => 'HIS-SYSTEM-USER-TOKEN', 'token_app_id' => '5550000000001', 'app_secret' => 'HIS-APP-SECRET',
            'webhook_key' => str_repeat('k', 40), 'webhook_verify_token' => 'HIS-VERIFY', 'webhook_verified_at' => now()->subDay(),
            'billing_state' => WhatsAppOwnApp::BILLING_CONFIRMED, 'billing_evidence' => ['waba_id' => 'WABA-HIS', 'reviewed_at' => now()->toIso8601String()],
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subMonth(),
        ]);
    }

    public function test_a_shop_on_its_own_meta_app_is_never_checked_switched_or_given_templates(): void
    {
        [$ribbon, $his] = $this->makeShop('RIBBON', 'saud@abaadapp.om', '93333333', WhatsAppMode::BUSINESS_OWN);
        $c = $this->ownAppLink($ribbon);
        $before = [(array) DB::table('whatsapp_connections')->where('id', $c->id)->first(), (array) DB::table('businesses')->where('id', $ribbon->id)->first()];

        WhatsAppSender::linked($c, WhatsAppSender::VIA_EMBEDDED, $his);
        WhatsAppSender::accountChanged('WABA-HIS');
        WhatsAppSender::soon($ribbon->id);
        $this->tick();
        $this->artisan('whatsapp:adopt-numbers --watch')->assertSuccessful();

        $this->assertSame([], $this->calls, 'لا نداءَ باسمه');
        $this->assertSame(0, WhatsAppSenderAdoption::where('business_id', $ribbon->id)->count());
        $this->assertSame(0, WhatsAppTemplateMapping::where('business_id', $ribbon->id)->count());
        $this->assertSame($before, [(array) DB::table('whatsapp_connections')->where('id', $c->id)->first(), (array) DB::table('businesses')->where('id', $ribbon->id)->first()]);
        $this->assertTrue(WhatsAppSender::mayOwn($c->fresh()), 'وطريقُه إلى رقمه كما كان');

        $view = WhatsAppSender::view($ribbon->fresh());
        $this->assertSame(['none', true, []], [$view['state'], $view['own_app'], $view['history']]);
        $this->assertSame([], array_filter(array_keys($this->bell($his)), fn ($k) => str_starts_with($k, 'wa-sender')));
    }

    public function test_an_own_app_shop_still_on_abaads_number_is_not_switched_either(): void
    {
        [$shop] = $this->makeShop('متجر بتطبيقه', 'own@abaadapp.om', '94444444');
        $this->ownAppLink($shop);
        $this->tick();
        $this->artisan('whatsapp:adopt-numbers --watch');
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode($shop));
    }

    public function test_a_link_made_before_this_is_left_where_it_is(): void
    {
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud', 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => 'OLD-TOKEN', 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subYear(),
        ]);
        $this->assertFalse(WhatsAppSender::waiting());
        $this->assertFalse(WhatsAppSender::watching());
        $this->tick();
        $this->artisan('whatsapp:adopt-numbers --watch');

        $this->assertSame([], $this->calls);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
        // ‏‏وبلا دليلٍ لا يُختار رقمُه بيدٍ أيضًا — كما كان
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors('mode');
    }

    public function test_a_shop_that_moved_to_its_own_app_while_waiting_is_dropped(): void
    {
        $this->link()->assertSessionHasNoErrors();
        WhatsAppConnections::forBusiness($this->shop->id)->update(['link_type' => WhatsAppOwnApp::TYPE]);
        $this->calls = [];
        $this->tick();
        $this->assertSame(['closed', 'own_app'], [$this->row()->state, $this->row()->closed_why]);
        $this->assertSame([], $this->calls);
    }

    /* ═════════════ الباقات والحصص كما هي ═════════════ */

    public function test_the_switch_touches_no_plan_price_quota_or_credit(): void
    {
        $this->shop->update(['whatsapp_monthly_limit' => 40, 'whatsapp_message_credits' => 500]);
        $columns = array_diff(Schema::getColumnListing('businesses'), ['whatsapp_mode', 'updated_at']);
        $before = (array) DB::table('businesses')->where('id', $this->shop->id)->first($columns);
        $this->adopt();
        $this->assertSame($before, (array) DB::table('businesses')->where('id', $this->shop->id)->first($columns));
    }

    public function test_an_addons_shop_without_the_service_is_not_switched(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->shop->update(['pricing_policy' => 'addons_v1', 'plan_id' => null]);
        $this->tick();
        $this->assertContains($this->row()->code, ['plan', 'own_not_allowed']);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode());
    }

    /* ═════════════ الشاشات ═════════════ */

    public function test_both_screens_show_the_stages_and_neither_carries_a_secret(): void
    {
        $this->meta['review'] = 'PENDING';
        $this->link()->assertSessionHasNoErrors();
        $this->tick();

        $mine = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'));
        $mine->assertInertia(fn (AssertableInertia $p) => $p
            ->where('automation.sender.state', 'checking')->where('automation.sender.sends_from', 'shared')
            ->has('automation.sender.steps', 6)->where('automation.sender.steps.0.state', 'ok')->where('automation.sender.steps.4.state', 'wait')
            ->where('automation.sender.blocking.who', 'meta'));
        $theirs = $this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->shop->id));
        $theirs->assertInertia(fn (AssertableInertia $p) => $p->where('whatsapp.sender.state', 'checking'));
        $settings = $this->actingAs($this->super)->get(route('super-admin.settings.index'));
        $settings->assertInertia(fn (AssertableInertia $p) => $p->where('ownNumbers.0.sender.state', 'checking'));

        foreach ([$mine, $theirs, $settings] as $page) {
            $this->assertStringNotContainsString('TOKEN-OF-SHOP', (string) $page->getContent());
            $this->assertStringNotContainsString('test-secret', (string) $page->getContent());
        }

        $this->meta['review'] = 'APPROVED';
        foreach ($this->templates['WABA-1'] as $k => $t) {
            $this->templates['WABA-1'][$k]['status'] = 'APPROVED';
        }
        $this->tick();
        $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('automation.sender.state', 'adopted')->has('automation.sender.history', 1)->where('automation.sender.history.0.from', 'رقم أبعاد'));
    }

    public function test_every_reason_has_words_for_a_person(): void
    {
        $codes = ['shop_suspended', 'platform_off', 'account_off', 'plan', 'own_not_allowed', 'link_connecting', 'link_needs_registration', 'link_billing_unproven', 'link_expired', 'link_reauthorization_required', 'link_error', 'link_disconnected', 'billed_to_abaad', 'no_payment_method', 'owner_unknown', 'abaad_account_unknown', 'number_off', 'number_blocked', 'number_payment', 'number_unknown', 'webhook_missing', 'webhook_elsewhere', 'routed_away', 'app_deaf', 'templates_pending', 'template_rejected', 'template_refused', 'template_mismatch', 'queue_busy', 'send_190', 'send_10', 'send_200', 'send_131005', 'send_131042', 'send_131031', 'send_368', 'send_133010', 'unreachable', 'invalid_token', 'wrong_app', 'missing_scopes', 'waba_not_granted', 'number_not_in_waba'];

        foreach ($codes as $code) {
            $said = WhatsAppSender::say($code);
            $this->assertStringNotContainsString($code, $said['text'], $code);
            $this->assertStringNotContainsString('webhook', mb_strtolower($said['text']), $code);
            $this->assertContains($said['who'], ['merchant', 'abaad', 'meta'], $code);
        }
    }

    /* ═════════════ مفتاحُ المنصّة ═════════════ */

    public function test_the_platform_can_pause_the_switch_and_nothing_moves_until_it_returns(): void
    {
        $this->adopt();
        [$other, $hisOwner] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->link($hisOwner, 'B', 'WABA-2', 'PN-2')->assertSessionHasNoErrors();

        $this->actingAs($hisOwner)->post(route('super-admin.whatsapp.merchant-link.auto-sender'), ['enabled' => false])->assertForbidden();
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.auto-sender'), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertStringContainsString('أوقف', ActivityLog::where('subject_type', Setting::class)->latest('id')->first()->description);
        $this->assertStringContainsString('أوقف', WhatsAppMerchantLink::state()['history'][0]['what'], 'ويُقرأ في شاشة المنصّة');

        $this->calls = [];
        $this->tick();
        $this->assertFalse(WhatsAppSender::waiting());
        $this->assertSame([], $this->calls, 'لا يُسأل عن رقمٍ والمفتاحُ مطفأ');
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->mode($other));
        $this->assertSame('abaad', WhatsAppSender::view($other->fresh())['blocking']['who']);
        // ‏‏ولا يقع تبديلٌ بدأ فحصُه قبل إطفائه
        $this->assertFalse((new \ReflectionMethod(WhatsAppSender::class, 'adopt'))->invoke(null, $this->row($other)));
        // ‏‏وما اعتُمد يبقى ويُرسل ويُفحص
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
        $this->watchOnce();
        $this->assertNotSame([], $this->calls);

        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.auto-sender'), ['enabled' => true])->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode($other));
    }

    public function test_a_client_whose_authorization_was_withdrawn_can_be_sent_a_link_again(): void
    {
        $this->adopt();
        $send = fn () => $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id));
        $send()->assertSessionHasErrors('client_link');

        // ‏‏علّةٌ يصلحها التاجر عند Meta لا تُعاد بسببها
        $this->meta['can_send'] = 'BLOCKED';
        $this->watchOnce();
        $this->watchOnce();
        $send()->assertSessionHasErrors('client_link');

        $this->meta = array_merge($this->meta, ['can_send' => 'AVAILABLE', 'token_valid' => false]);
        $this->watchOnce();
        $send()->assertSessionHasNoErrors();
        $url = session('whatsappClientLink')['url'];
        auth()->logout();
        $this->meta['token_valid'] = true;
        $this->get($url);
        $this->post(route('clientLink.connect'), ['code' => self::CODE.'A', 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'shop_confirmed' => true])->assertSessionHasNoErrors();
        $this->tick();
        $this->assertNull(WhatsAppSender::problem($this->shop->fresh()));
    }

    /* ═════════════ متاجرُ التجربة ═════════════ */

    private function backToOwnApps(): void
    {
        config(['whatsapp.merchant_link' => 'own_app']);
    }

    public function test_a_pilot_shop_links_through_abaads_app_while_the_others_stay_on_theirs(): void
    {
        $this->backToOwnApps();
        [$other, $hisOwner] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->link()->assertSessionHasErrors('code');

        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) $this->shop->id])->assertSessionHasNoErrors();
        $this->assertStringContainsString((string) $this->shop->id, ActivityLog::where('subject_type', Setting::class)->latest('id')->first()->description);

        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
        $this->link($hisOwner, 'B', 'WABA-2', 'PN-2')->assertSessionHasErrors('code');

        $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (AssertableInertia $p) => $p->where('automation.own_app.enabled', false));
        $this->assertTrue(WhatsAppOwnApp::onOwnApp((int) $other->id));

        // ‏‏والرابطُ كذلك: لمتجر التجربة وحده
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $other->id))->assertSessionHasErrors('client_link');

        // ‏‏وإنهاءُ التجربة لا يفصل رقمًا رُبط فيها
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => ''])->assertSessionHasNoErrors();
        $this->assertTrue(WhatsAppConnections::forBusiness($this->shop->id)->isUsable());
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->mode());
    }

    public function test_a_living_own_app_link_is_not_moved_by_the_pilot_list(): void
    {
        $this->backToOwnApps();
        [$ribbon, $his] = $this->makeShop('RIBBON', 'saud@abaadapp.om', '93333333', WhatsAppMode::BUSINESS_OWN);
        $c = $this->ownAppLink($ribbon);
        $before = (array) DB::table('whatsapp_connections')->where('id', $c->id)->first();
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) $ribbon->id])->assertSessionHasNoErrors();

        $this->assertTrue(WhatsAppOwnApp::onOwnApp((int) $ribbon->id));
        $this->link($his)->assertSessionHasErrors('code');
        $this->assertSame($before, (array) DB::table('whatsapp_connections')->where('id', $c->id)->first());
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $ribbon->id))->assertSessionHasErrors('client_link');
    }

    public function test_the_pilot_list_is_the_platforms_alone_and_names_real_shops(): void
    {
        $this->backToOwnApps();
        $this->actingAs($this->owner)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) $this->shop->id])->assertForbidden();
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) (Business::max('id') + 1000)])->assertSessionHasErrors('pilot');
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => 'abc'])->assertSessionHasErrors('pilot');
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) $this->shop->id])->assertSessionHasNoErrors();
        $this->assertSame([$this->shop->id], WhatsAppMerchantLink::pilot());

        // ‏‏ولا تجربةَ والربطُ بضغطةٍ غيرُ مهيّأ
        config(['whatsapp.config_id' => null]);
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => $this->shop->id.'،'.$this->makeShop('ث', 't@abaadapp.om', '92222223')[0]->id])->assertSessionHasErrors('pilot');
    }

    public function test_the_app_check_names_the_fields_still_missing(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.app-webhook.check'));
        $this->assertStringContainsString('message_template_status_update', (string) session('toast.msg'));

        $this->meta['app_fields'] = ['messages', 'account_update', 'message_template_status_update', 'user_preferences'];
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.app-webhook.check'));
        $this->assertStringNotContainsString('تنقصه', (string) session('toast.msg'));
    }

    /* ═════════════ النشر ═════════════ */

    public function test_the_scheduler_survives_the_minutes_before_the_migration(): void
    {
        Schema::drop('whatsapp_sender_adoptions');
        $this->assertFalse(WhatsAppSender::waiting());
        $this->assertFalse(WhatsAppSender::watching());
    }

    public function test_the_bell_never_takes_a_page_down(): void
    {
        $this->adopt();
        // ‏‏رمزٌ تلف في القاعدة — فكُّ تشفيره يرمي
        DB::table('whatsapp_connections')->where('business_id', $this->shop->id)->update(['access_token' => 'not-an-encrypted-value']);

        $this->assertSame(['adopted' => [], 'waiting' => [], 'broken' => []], array_map(fn ($l) => collect($l)->all(), WhatsAppSender::bell(5, $this->shop->id, ['merchant'])));
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_sample_invoice_is_a_real_one_page_pdf(): void
    {
        $pdf = WhatsAppNoticeTemplates::samplePdf();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $m);
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $at);
        foreach ($at[1] as $i => $offset) {
            $this->assertSame(($i + 1).' 0 obj', substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')));
        }
    }

    public function test_rolling_back_never_drops_the_record_of_a_switch(): void
    {
        $this->adopt();
        $migration = require database_path('migrations/2026_11_29_090000_a_linked_number_becomes_the_shops_sender.php');

        try {
            $migration->down();
            $this->fail('مُحي السجلّ');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('holds rows', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('whatsapp_sender_adoptions'));
    }
}
