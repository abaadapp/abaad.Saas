<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplateMapping;
use App\Support\OrderStatus;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * ‏‏Meta كما تجيب عن الربط والملكيّة والدفع والإرسال — من دالّةٍ واحدة تقرأ `$this->meta` الآن.
 *
 * وكلُّ نداءٍ يُسجَّل برمزه وعنوانه (`$this->calls`)، ونداءٌ لا نعرفه يسقط.
 */
trait FakesMetaBilling
{
    private string $app0 = '1082481610822941';

    private string $secret = 'test-secret-not-a-real-one';

    private Business $shop;

    private User $owner;

    private User $super;

    private Customer $customer;

    private array $meta = [];

    private array $templates = [];

    /** @var list<array{method: string, url: string, token: ?string, data: array, query: array}> */
    private array $calls = [];

    private int $sent = 0;

    private function bootMeta(): void
    {
        $this->app->setLocale('ar');
        config([
            'whatsapp.merchant_link' => 'own_app',
            'whatsapp.app_id' => $this->app0,
            'whatsapp.app_secret' => $this->secret,
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.abaad_business_ids' => ['MB-ABAAD'],
            'whatsapp.ribbon_businesses' => [],
        ]);

        $this->platform('whatsapp_enabled', '1');
        $this->platform(WhatsAppQuota::DEFAULT_KEY, '100');

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN-ABAAD', 'waba_id' => '9000', 'meta_business_id' => 'MB-ABAAD',
            'display_phone_number' => '+968 7114 1624', 'access_token' => 'TOKEN-OF-ABAAD',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');
        WhatsAppTemplateMapping::query()->platform()->update(['meta_status' => 'APPROVED']);

        [$this->shop, $this->owner, $this->customer] = $this->makeShop('محل ورد', 'o@abaadapp.om', '91234567');
        $this->super = User::create(['business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaadapp.om', 'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->meta = [
            'token_valid' => true, 'down' => false,
            'numbers' => ['1001' => 'PN-1', '1002' => 'PN-2', '1003' => 'PN-3'],
            'status' => 'CONNECTED', 'can_send' => 'AVAILABLE', 'subscribed' => [],
            'owner' => ['1001' => 'MB-SHOP', '1002' => 'MB-TWO', '1003' => 'MB-THREE'], 'owner_name' => 'نشاط التاجر',
            'behalf' => null, 'ownership' => 'SELF', 'funding' => null, 'owned_by_abaad' => ['9000'], 'shared_with_abaad' => [],
            'scopes' => null, 'send_error' => [], 'points' => [], 'analytics' => true,
        ];

        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $r) {
            $path = (string) preg_replace('#^v26\.0/#', '', trim((string) parse_url($r->url(), PHP_URL_PATH), '/'));
            $query = [];
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $token = preg_match('/^(?:Bearer|OAuth) (.+)$/', (string) ($r->header('Authorization')[0] ?? ''), $m) ? $m[1] : null;
            $this->calls[] = ['method' => $r->method(), 'url' => $path, 'token' => $token, 'data' => is_array($r->data()) ? $r->data() : [], 'query' => $query];

            if ($this->meta['down']) {
                throw new ConnectionException('timeout');
            }

            return $this->metaAnswers($r, $path, $query);
        });
    }

    private function metaAnswers(HttpRequest $r, string $path, array $query)
    {
        $m = $this->meta;
        $fields = (string) ($query['fields'] ?? '');
        $waba = preg_match('#^(\d{4})(?:/|$)#', $path, $x) ? $x[1] : null;
        $phone = preg_match('#^(PN-[A-Z0-9]+)#', $path, $x) ? $x[1] : null;

        return match (true) {
            $path === 'oauth/access_token' => Http::response(['access_token' => 'TOKEN-FOR-'.substr((string) ($query['code'] ?? 'X'), -4)], 200),
            $path === 'debug_token' => Http::response(['data' => [
                'is_valid' => $m['token_valid'], 'app_id' => $this->app0, 'expires_at' => 0,
                'granular_scopes' => array_map(fn ($s) => ['scope' => $s, 'target_ids' => $m['scopes'] ?? [substr((string) ($query['input_token'] ?? ''), -4)]], ['whatsapp_business_management', 'whatsapp_business_messaging']),
            ]], 200),
            $path === $this->app0.'/subscriptions' => Http::response(['data' => [['object' => 'whatsapp_business_account', 'callback_url' => route('webhooks.whatsapp'), 'active' => true, 'fields' => [['name' => 'messages']]]]], 200),
            $path === $this->app0.'/uploads' => Http::response(['id' => 'upload:SESSION'], 200),
            str_starts_with($path, 'upload:') => Http::response(['h' => '4::HANDLE'], 200),
            str_contains($path, 'owned_whatsapp_business_accounts') => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $m['owned_by_abaad'])], 200),
            str_contains($path, 'client_whatsapp_business_accounts') => Http::response(['data' => array_map(fn ($id) => ['id' => $id], $m['shared_with_abaad'])], 200),
            $waba !== null && str_ends_with($path, '/phone_numbers') => Http::response(['data' => isset($m['numbers'][$waba]) ? [['id' => $m['numbers'][$waba], 'display_phone_number' => '+968 9000 000'.substr($waba, -1), 'platform_type' => 'CLOUD_API']] : []], 200),
            $waba !== null && str_ends_with($path, '/subscribed_apps') => $this->metaSubscribes($r, $waba),
            $waba !== null && str_ends_with($path, '/message_templates') => $this->metaTemplates($r, $waba),
            $phone !== null && str_contains($fields, 'health_status') => Http::response(['status' => $m['status'], 'health_status' => ['can_send_message' => $m['can_send'], 'entities' => []]], 200),
            $phone !== null && str_ends_with($path, '/messages') => isset($m['send_error'][$phone])
                ? Http::response(['error' => ['code' => $m['send_error'][$phone], 'message' => 'refused']], 400)
                : Http::response(['messages' => [['id' => 'wamid.'.$phone.'.'.(++$this->sent)]]], 200),
            $phone !== null && str_ends_with($path, '/media') => Http::response(['id' => 'MEDIA-1'], 200),
            $phone !== null && str_contains($path, 'routing') => Http::response(['error' => ['code' => 100, 'message' => 'Conversation routing is not enabled']], 400),
            $phone !== null && str_contains($fields, 'is_on_biz_app') => Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200),
            $waba !== null && str_contains($fields, 'pricing_analytics') => $m['analytics']
                ? Http::response(['id' => $waba, 'pricing_analytics' => ['data' => [['data_points' => $m['points']]]]], 200)
                : Http::response(['error' => ['code' => 100, 'message' => 'no']], 400),
            $waba !== null && str_contains($fields, 'primary_funding_id') => match (true) {
                $m['funding'] === null => Http::response(['error' => ['code' => 10, 'message' => '(#10)']], 403),
                $m['funding'] === 'absent' => Http::response(['id' => $waba], 200),
                default => Http::response(['id' => $waba, 'primary_funding_id' => $m['funding']], 200),
            },
            $waba !== null => Http::response(['id' => $waba, 'ownership_type' => $m['ownership']]
                + (($m['owner'][$waba] ?? null) ? ['owner_business_info' => ['id' => $m['owner'][$waba], 'name' => $m['owner_name']]] : [])
                + ($m['behalf'] ? ['on_behalf_of_business_info' => ['id' => $m['behalf'], 'type' => 'SELF']] : []), 200),
            $phone !== null => Http::response(['display_phone_number' => '+968 9000 0001', 'verified_name' => 'محل', 'platform_type' => 'CLOUD_API'], 200),
            default => Http::response(['error' => ['code' => 1, 'message' => 'unexpected '.$path]], 400),
        };
    }

    private function metaSubscribes(HttpRequest $r, string $waba)
    {
        if ($r->method() === 'POST') {
            $this->meta['subscribed'][$waba] = true;

            return Http::response(['success' => true], 200);
        }

        return Http::response(['data' => ($this->meta['subscribed'][$waba] ?? false) ? [['whatsapp_business_api_data' => ['id' => $this->app0]]] : []], 200);
    }

    private function metaTemplates(HttpRequest $r, string $waba)
    {
        if ($r->method() === 'POST') {
            $d = $r->data();
            $this->templates[$waba][$d['name'].'|'.$d['language']] = collect($d['components'])->firstWhere('type', 'BODY')['text'];

            return Http::response(['id' => 'T-'.count($this->templates[$waba]), 'status' => 'APPROVED', 'category' => $d['category']], 200);
        }

        return Http::response(['data' => collect($this->templates[$waba] ?? [])->map(function ($body, $key) {
            [$name, $lang] = explode('|', $key);

            return ['id' => 'T', 'name' => $name, 'language' => $lang, 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => $body]]];
        })->values()->all()], 200);
    }

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

        return [$shop, $owner, Customer::create(['business_id' => $shop->id, 'name' => 'زبون', 'phone' => $phone])];
    }

    private function order(?Business $shop = null, ?Customer $customer = null, string $status = OrderStatus::PENDING): Order
    {
        $shop ??= $this->shop;

        return Order::create([
            'business_id' => $shop->id, 'branch_id' => Branch::where('business_id', $shop->id)->value('id'),
            'customer_id' => ($customer ?? $this->customer)->id, 'number' => 'INV-'.uniqid(), 'status' => $status,
            'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ]);
    }

    /** @return list<string> الأرقامُ التي خرجت منها رسائلُ إلى عملاء */
    private function outgoing(): array
    {
        return array_values(array_map(fn ($c) => explode('/', $c['url'])[0], array_filter($this->calls, fn ($c) => str_ends_with($c['url'], '/messages'))));
    }

    private function webhook(array $change, string $waba = '1001'): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode(['entry' => [['id' => $waba, 'changes' => [$change]]]]);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->secret)], $body);
    }

    private function metaSaid(string $wamid, string $status, array $more = [], string $phone = 'PN-1'): void
    {
        $this->webhook(['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $phone], 'statuses' => [['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp] + $more]]])->assertOk();
    }
}
