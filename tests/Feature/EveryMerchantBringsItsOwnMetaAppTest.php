<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Support\MetaWhatsAppClient;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * كلُّ تاجرٍ بتطبيقه هو عند ميتا — ورقمِه وحسابِه ورمزِه وفوترتِه.
 *
 *  • الربطُ يُقبل برمزٍ دائمٍ من تطبيق التاجر نفسه، ويُردّ رمزُ تطبيق أبعاد،
 *    والمؤقّت، وسرٌّ خاطئ، ورقمٌ على تطبيق الهاتف، وحسابٌ رسائلُه على أبعاد.
 *  • والفوترةُ لا تثبت آليًّا: ميتا لا تُطلع غيرَ «مزوّد حلول أعمال» على وسيلة
 *    الدفع، والملكيّةُ `SELF` وإقرارُ التاجر لا يقولان من يدفع. فكلُّ ربطٍ
 *    «بانتظار إثبات الفوترة» ولا يُرسل — حتى يراجع فريقُ أبعاد دليلًا من ميتا،
 *    ووقائعُ تنقض الدليل تردّ التأكيد أو توقف الإرسال بعده.
 *  • وإشعاراتُ تطبيقه على عنوان وصلته، بسرّ تطبيقه، ولرقمها وحده.
 *  • ومتجران بتطبيقين لا يمسّ أحدُهما الآخر، والأسرارُ لا تبلغ الشاشة.
 *
 * وميتا كلُّها مُحاكاة — لا نداءَ حقيقيّ ولا رسالة ولا تكلفة.
 */
class EveryMerchantBringsItsOwnMetaAppTest extends TestCase
{
    use RefreshDatabase;

    private const ABAAD_APP = '1082481610822941';

    private const ABAAD_BIZ = '9990000000099';

    private const APP = '5550000000001';

    private const SECRET = 'merchant-app-secret-0123456789abcdef';

    private const TOKEN = 'EAAG-merchant-system-user-token-0123456789';

    private const WABA = '7770000000001';

    private const PHONE = '8880000000001';

    /** حسابُ رسائل شريكٍ على الرقم نفسه — يصله رمزُ التاجر أيضًا */
    private const PARTNER = '7770000000003';

    private Business $shop;

    private User $owner;

    /** أشُرك تطبيقُ التاجر في حسابه؟ — يتبدّل بـ`POST` كما عند ميتا */
    private array $subscribed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');
        Cache::flush();

        config([
            'whatsapp.app_id' => self::ABAAD_APP,
            'whatsapp.app_secret' => 'abaad-app-secret-not-real',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.merchant_link' => 'own_app',
            'whatsapp.abaad_business_ids' => [self::ABAAD_BIZ],
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        [$this->shop, $this->owner] = $this->merchant('محل الورد', 'rose@own.om');
    }

    /** @return array{Business, User} */
    private function merchant(string $name, string $email): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN, 'whatsapp_shared_allowed' => false,
            'whatsapp_inbox_enabled' => true,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return [$b, User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ])];
    }

    /**
     * ميتا كما تجيب تطبيقَ تاجر — وما يُغيَّر يُمرَّر في `$over` فيُقدَّم.
     *
     * @param  array<int, array{app:string, secret:string, waba:string, phone:string, token:string, messaging?:list<string>}>  $apps
     * @param  array<string, mixed>  $over
     */
    private function meta(array $over = [], array $apps = [], array $ownership = [], int $expiresAt = 0, string $platform = 'CLOUD_API'): void
    {
        $apps = $apps ?: [['app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN]];
        $patterns = [];

        $patterns['*/debug_token*'] = function (HttpRequest $r) use ($apps, $expiresAt) {
            foreach ($apps as $a) {
                if ($r['access_token'] === $a['app'].'|'.$a['secret'] && $r['input_token'] === $a['token']) {
                    return Http::response(['data' => [
                        'is_valid' => true, 'app_id' => $a['app'], 'expires_at' => $expiresAt,
                        'granular_scopes' => [
                            ['scope' => 'whatsapp_business_management', 'target_ids' => [$a['waba']]],
                            // وحساباتُ الرسائل التي يُرسل منها الرمز — قد تكون أكثرَ من حساب
                            ['scope' => 'whatsapp_business_messaging', 'target_ids' => $a['messaging'] ?? [$a['waba']]],
                        ],
                    ]], 200);
                }
            }

            // سرٌّ لا يطابق تطبيقه — ميتا تردّ رمزَ التطبيق
            return Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token signature.']], 400);
        };

        foreach ($apps as $a) {
            $patterns["*/{$a['waba']}/phone_numbers*"] = Http::response(['data' => [[
                'id' => $a['phone'], 'display_phone_number' => '+968 9100 0001', 'platform_type' => $platform,
            ]]], 200);
            $patterns["*/{$a['waba']}/subscribed_apps*"] = function (HttpRequest $r) use ($a) {
                if ($r->method() === 'POST') {
                    $this->subscribed[$a['waba']] = true;

                    return Http::response(['success' => true], 200);
                }

                return Http::response(['data' => isset($this->subscribed[$a['waba']])
                    ? [['whatsapp_business_api_data' => ['id' => $a['app'], 'name' => 'Merchant app']]] : []], 200);
            };
            $patterns["*/{$a['waba']}?fields=owner_business_info*"] = Http::response($ownership + [
                'owner_business_info' => ['id' => 'BIZ-'.$a['waba'], 'name' => 'نشاط '.$a['waba']],
                'on_behalf_of_business_info' => ['id' => 'BIZ-'.$a['waba'], 'name' => 'نشاط '.$a['waba'], 'type' => 'SELF'],
                'ownership_type' => 'SELF',
            ], 200);
            $patterns["*/{$a['app']}/subscriptions*"] = Http::response(['data' => [[
                'object' => 'whatsapp_business_account', 'callback_url' => 'https://elsewhere.example/hook',
                'fields' => [['name' => 'messages']], 'active' => true,
            ]]], 200);
            $patterns["*/{$a['phone']}/messages"] = Http::response(['messages' => [['id' => 'wamid.OUT-'.$a['phone']]]], 200);
            $patterns["*/{$a['phone']}?fields=display_phone_number*"] = Http::response([
                'display_phone_number' => '+968 9100 0001', 'verified_name' => 'متجر', 'platform_type' => $platform,
            ], 200);
        }

        // وسيلةُ الدفع: ميتا تردّها بـ`#10` لتطبيقٍ لا يملكه «مزوّد حلول أعمال» — كما في فحص الإنتاج
        $patterns['*fields=primary_funding_id*'] = Http::response(['error' => ['code' => 10,
            'message' => 'This action requires that the Business that owns this App is a Business Solution Provider for WhatsApp.']], 400);
        $patterns['*/owned_whatsapp_business_accounts*'] = Http::response(['data' => []], 200);
        $patterns['*/client_whatsapp_business_accounts*'] = Http::response(['data' => []], 200);
        $patterns['*fields=is_on_biz_app*'] = Http::response(['is_on_biz_app' => false, 'platform_type' => $platform], 200);
        $patterns['*/routing_roles*'] = Http::response(['error' => ['code' => 100, 'message' => 'Conversation routing is not enabled for this phone number.']], 400);

        // وكلُّ نداءٍ لـ`meta()` يبدأ ميتا من جديد: `Http::fake` الثاني لا يغلب الأوّل
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        // وما يُغيّره الاختبارُ يُقدَّم — `Http::fake` يأخذ أوّلَ نمطٍ ينطبق
        Http::fake($over + $patterns);
    }

    private function link(User $owner, array $over = [])
    {
        return $this->actingAs($owner)->post(route('admin.integrations.whatsapp.connect'), $over + [
            'app_id' => self::APP, 'app_secret' => self::SECRET, 'waba_id' => self::WABA,
            'phone_number_id' => self::PHONE, 'access_token' => self::TOKEN, 'billing_ack' => '1',
        ]);
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'root@abaad.om'], [
            'name' => 'المنصّة', 'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** يراجع فريقُ أبعاد دليلًا من ميتا — مسمًّى، بمرجعه، وبمعرّف الحساب الذي فيه، وبإقرار المراجع */
    private function review(?Business $b = null, array $over = [])
    {
        $b ??= $this->shop;

        return $this->actingAs($this->admin())->post(route('super-admin.businesses.whatsapp.billing', $b->id), $over + [
            'evidence_kind' => 'meta_invoice', 'evidence_reference' => 'FBADS-2026-10-0042', 'evidence_checked' => '1',
            // والمعرّفُ كما في صفحة ميتا — حسابُ الوصلة نفسُها ما لم يُمرَّر غيرُه
            'evidence_waba_id' => (string) ($this->connection($b)?->waba_id ?? self::WABA),
        ]);
    }

    /** وصلةٌ أبعادُ فيها: رمزُ أرقام المنصّة الذي تُقرأ به حساباتُ نشاط أبعاد */
    private function platformNumber(string $waba = '7770000000099'): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'provider' => 'meta_cloud', 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'waba_id' => $waba, 'phone_number_id' => 'ABAAD-PN', 'access_token' => 'abaad-platform-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'meta_business_id' => self::ABAAD_BIZ,
        ]);
    }

    private function connection(?Business $b = null): ?WhatsAppConnection
    {
        return WhatsAppConnection::where('business_id', ($b ?? $this->shop)->id)->latest('id')->first();
    }

    private function posts(): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST')->count();
    }

    /** إشعارٌ كما توقّعه ميتا بسرّ تطبيقٍ بعينه */
    private function hook(string $url, array $payload, string $secret)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function inbound(string $phoneId, string $wamid = 'wamid.IN-1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => self::WABA, 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '96891000001', 'phone_number_id' => $phoneId],
            'contacts' => [['profile' => ['name' => 'سارة'], 'wa_id' => '96891234567']],
            'messages' => [['from' => '96891234567', 'id' => $wamid, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'هل الطلب جاهز؟']]],
        ]]]]]];
    }

    /* ═════════════ ١ · الربطُ بتطبيق التاجر — ثمّ يصلنا ما يكتبه زبائنه ═════════════ */

    public function test_a_merchant_links_its_own_app_and_abaad_hears_it_on_its_own_door(): void
    {
        $this->meta();

        $this->link($this->owner)->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'info');

        $c = $this->connection();
        $this->assertSame([WhatsAppOwnApp::TYPE, self::APP, WhatsAppConnection::PENDING, WhatsAppOwnApp::BILLING_UNPROVEN],
            [$c->link_type, $c->token_app_id, $c->status, $c->billing_state]);
        $this->assertSame('نشاط '.self::WABA, $c->billing_owner_name);
        $this->assertSame(40, strlen((string) $c->webhook_key));
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()), 'لا يُرسل قبل مراجعة دليل الفوترة');

        $this->review()->assertSessionHasNoErrors();
        $c = $c->fresh();
        $this->assertSame([WhatsAppConnection::ACTIVE, WhatsAppOwnApp::BILLING_CONFIRMED], [$c->status, $c->billing_state]);
        $this->assertSame($c->id, WhatsAppConnections::resolve($this->shop->fresh())?->id, 'يُرسل برمزه');

        // سُئل `debug_token` بتطبيق التاجر وسرّه — وأُشرك تطبيقُه هو في حسابه
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/debug_token') && $r['access_token'] === self::APP.'|'.self::SECRET);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_contains($r->url(), self::WABA.'/subscribed_apps')
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        // والسرُّ مشفَّرٌ في القاعدة
        $raw = DB::table('whatsapp_connections')->where('id', $c->id)->first();
        $this->assertNotSame(self::SECRET, $raw->app_secret);
        $this->assertSame(self::SECRET, $c->app_secret);

        // ميتا تطابق رمزَ التحقّق على عنوان الوصلة
        $verify = route('webhooks.whatsapp.own.verify', ['key' => $c->webhook_key]);
        $this->get($verify.'?'.http_build_query(['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => '42']))->assertForbidden();
        $this->get(route('webhooks.whatsapp.own.verify', ['key' => str_repeat('x', 40)]).'?hub_mode=subscribe&hub_verify_token=x&hub_challenge=1')->assertNotFound();
        $this->get($verify.'?'.http_build_query(['hub_mode' => 'subscribe', 'hub_verify_token' => $c->webhook_verify_token, 'hub_challenge' => '42']))
            ->assertOk()->assertSee('42');
        $this->assertNotNull($c->fresh()->webhook_verified_at);

        // ورسالةُ زبونٍ موقَّعةٌ بسرّ تطبيقه تُحفظ في صندوقه
        $this->hook(WhatsAppOwnApp::callbackUrl($c), $this->inbound(self::PHONE), self::SECRET)->assertOk();
        $this->assertNotNull($c->fresh()->last_inbound_at);
        $this->assertSame(1, WhatsAppChatMessage::where('business_id', $this->shop->id)->where('direction', WhatsAppChatMessage::IN)->count());

        // والمكرَّرُ مرّةً واحدة
        $this->hook(WhatsAppOwnApp::callbackUrl($c), $this->inbound(self::PHONE), self::SECRET)->assertOk();
        $this->assertSame(1, WhatsAppChatMessage::where('business_id', $this->shop->id)->where('direction', WhatsAppChatMessage::IN)->count());
    }

    public function test_the_door_takes_only_its_apps_signature_and_its_own_number(): void
    {
        $this->meta();
        $this->link($this->owner);
        $c = $this->connection();

        // بسرّ أبعاد على عنوان التاجر: مرفوض
        $this->hook(WhatsAppOwnApp::callbackUrl($c), $this->inbound(self::PHONE), 'abaad-app-secret-not-real')->assertForbidden();

        // وموقَّعٌ بسرّه لرقمٍ غير رقم الوصلة: لا يُكتب منه شيء
        $this->hook(WhatsAppOwnApp::callbackUrl($c), $this->inbound('9990000000009', 'wamid.FOREIGN'), self::SECRET)->assertOk();

        // وعلى العنوان العامّ بسرّ أبعاد: وصلةُ تطبيق التاجر لا تُعالَج هناك
        $this->hook(route('webhooks.whatsapp'), $this->inbound(self::PHONE, 'wamid.GLOBAL'), 'abaad-app-secret-not-real')->assertOk();

        $this->assertNull($c->fresh()->last_inbound_at);
        $this->assertSame(0, WhatsAppChatMessage::count());
    }

    /* ═════════════ ٢ · ما يُردّ — ولا يُحفظ منه شيءٌ ولا يُنادى عند ميتا ═════════════ */

    public function test_abaads_app_is_never_a_merchants_app(): void
    {
        $this->meta();

        $this->link($this->owner, ['app_id' => self::ABAAD_APP])->assertSessionHasErrors('app_id');

        $this->assertNull($this->connection());
        $this->assertSame(0, $this->posts());
    }

    public function test_a_wrong_secret_or_a_token_from_another_app_is_refused(): void
    {
        $this->meta(apps: [
            ['app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN],
            ['app' => '5550000000002', 'secret' => 'second-app-secret-0123456789', 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => 'EAAG-other-app-token-0123456789'],
        ]);

        $this->link($this->owner, ['app_secret' => 'not-the-secret-0123456789'])->assertSessionHasErrors('app_secret');
        // رمزٌ يصفه تطبيقٌ آخر — لا التطبيقُ الذي كُتب معرّفه
        $this->link($this->owner, ['access_token' => 'EAAG-other-app-token-0123456789'])->assertSessionHasErrors('app_secret');

        $this->assertNull($this->connection());
        $this->assertSame(0, $this->posts());
    }

    public function test_a_temporary_token_is_refused(): void
    {
        $this->meta(expiresAt: now()->addHours(2)->timestamp);

        $this->link($this->owner)->assertSessionHasErrors(['access_token' => WhatsAppOwnApp::say(WhatsAppOwnApp::TOKEN_EXPIRES)]);
        $this->assertNull($this->connection());
    }

    public function test_a_number_on_the_phone_app_is_refused_without_promising_coexistence(): void
    {
        $this->meta(['*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'], 200)]);

        $this->link($this->owner)->assertSessionHasErrors(['phone_number_id' => WhatsAppOwnApp::say(WhatsAppOwnApp::ON_PHONE_APP)]);
        $this->assertNull($this->connection());
    }

    /** وحسابٌ يملكه نشاطُ أبعاد، أو حسابُ رقمٍ من أرقام أبعاد، تُحتسب رسائلُه على أبعاد */
    public function test_an_account_billed_to_abaad_is_refused(): void
    {
        $this->meta(ownership: ['owner_business_info' => ['id' => self::ABAAD_BIZ, 'name' => 'Abaadapp']]);
        $this->link($this->owner)->assertSessionHasErrors(['waba_id' => WhatsAppOwnApp::say(WhatsAppOwnApp::BILLED_TO_ABAAD)]);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'provider' => 'meta_cloud', 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'waba_id' => self::WABA, 'phone_number_id' => 'ABAAD-PN', 'access_token' => 'abaad-platform-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'meta_business_id' => self::ABAAD_BIZ,
        ]);
        $this->meta();
        $this->link($this->owner)->assertSessionHasErrors('waba_id');

        // وحسابٌ في حسابات نشاط أبعاد عند ميتا — ولو قالت ملكيّتُه غير ذلك
        WhatsAppConnection::query()->delete();
        $this->platformNumber();
        $this->meta(['*/'.self::ABAAD_BIZ.'/owned_whatsapp_business_accounts*' => Http::response(['data' => [['id' => self::WABA]]], 200)]);
        $this->link($this->owner)->assertSessionHasErrors(['waba_id' => WhatsAppOwnApp::say(WhatsAppOwnApp::BILLED_TO_ABAAD)]);

        $this->assertNull($this->connection());
    }

    public function test_the_merchant_acknowledges_meta_fees_are_theirs(): void
    {
        $this->meta();

        $this->link($this->owner, ['billing_ack' => null])->assertSessionHasErrors('billing_ack');
        $this->assertNull($this->connection());
    }

    /* ═════════════ ٣ · الفوترةُ لا تثبت آليًّا: لا يُرسل حتى يُراجَع دليلٌ من ميتا ═════════════ */

    public function test_ownership_and_the_merchants_word_never_turn_sending_on(): void
    {
        // حسابٌ يملكه نشاطُ التاجر نفسُه (`SELF`)، والتاجرُ أقرّ أنّ الرسوم عليه — ولا يكفي
        $this->meta();

        $this->link($this->owner)->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'info');

        $c = $this->connection();
        $this->assertSame([WhatsAppConnection::PENDING, WhatsAppOwnApp::AWAITS_BILLING, WhatsAppOwnApp::BILLING_UNPROVEN],
            [$c->status, $c->last_error_code, $c->billing_state]);
        $this->assertSame(WhatsAppLink::BILLING_UNPROVEN, WhatsAppLink::state($c));
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()), 'لا يُرسل');
        $this->assertFalse(collect(WhatsAppFeature::readiness($this->shop->fresh())['steps'])->firstWhere('key', 'billing')['done']);
        // ووقائعُ ما قُرئ محفوظة: الملكيّة، ووسيلةُ الدفع التي لا تُطلعنا عليها ميتا
        $this->assertSame(['SELF', 'unreadable', 'unknown'], [
            $c->metadata['billing_facts']['ownership'], $c->metadata['billing_facts']['funding'], $c->metadata['billing_facts']['abaad_access'],
        ]);

        // و«فحص الربط» لا يقفز فوق الخطوة
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'));
        $this->assertSame(WhatsAppConnection::PENDING, $c->fresh()->status);

        // والتاجرُ لا يؤكّد فوترتَه بنفسه
        $this->actingAs($this->owner)->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id), [
            'evidence_kind' => 'meta_invoice', 'evidence_reference' => 'FBADS-2026-10-0042', 'evidence_checked' => '1',
            'evidence_waba_id' => self::WABA,
        ]);
        $this->assertSame(WhatsAppConnection::PENDING, $c->fresh()->status);

        // ولا فريقُ أبعاد بلا دليلٍ مسمًّى ومرجعِه وإقراره
        $this->review(over: ['evidence_kind' => ''])->assertSessionHasErrors('evidence_kind');
        $this->review(over: ['evidence_kind' => 'merchant_said_so'])->assertSessionHasErrors('evidence_kind');
        $this->review(over: ['evidence_reference' => ''])->assertSessionHasErrors('evidence_reference');
        $this->review(over: ['evidence_checked' => null])->assertSessionHasErrors('evidence_checked');
        // ولا بلا معرّف الحساب الذي في الدليل
        $this->review(over: ['evidence_waba_id' => ''])->assertSessionHasErrors('evidence_waba_id');
        $this->assertSame(WhatsAppConnection::PENDING, $c->fresh()->status);

        $this->review()->assertSessionHasNoErrors();

        $c = $c->fresh();
        $this->assertSame([WhatsAppConnection::ACTIVE, WhatsAppOwnApp::BILLING_CONFIRMED], [$c->status, $c->billing_state]);
        $this->assertSame($c->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);
        $this->assertSame(['meta_invoice', 'FBADS-2026-10-0042', 'المنصّة', self::WABA],
            [$c->billing_evidence['kind'], $c->billing_evidence['reference'], $c->billing_evidence['reviewed_by'], $c->billing_evidence['waba_id']]);
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))->count(), 'ولا رسالةَ خرجت');
    }

    public function test_nothing_leaves_an_unreviewed_link_even_if_its_status_says_active(): void
    {
        $this->meta();
        $this->link($this->owner);

        // حالةٌ صُحّحت خطأً إلى «نشطة» — والفوترةُ لم تُراجَع
        $c = $this->connection();
        $c->forceFill(['status' => WhatsAppConnection::ACTIVE, 'last_error_code' => null])->save();

        $this->assertFalse($c->fresh()->isUsable());
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));

        // وآخرُ بابٍ قبل ميتا يردّها أيضًا — لا نداءَ على `/messages`
        $sent = MetaWhatsAppClient::sendText($c->fresh(), '96891234567', 'مرحبًا');
        $template = MetaWhatsAppClient::sendTemplate($c->fresh(), '96891234567', 'order_ready', 'ar');
        $this->assertSame([false, WhatsAppOwnApp::AWAITS_BILLING, false], [$sent['ok'], $sent['code'], $template['ok']]);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/messages'));
        $this->assertSame(WhatsAppLink::BILLING_UNPROVEN, WhatsAppLink::state($c->fresh()));
    }

    /** دليلٌ رُئي لا يُؤكَّد به حسابٌ تقول ميتا إنّه بلا وسيلة دفع، أو لأبعاد فيه يد، أو لشريك */
    public function test_a_review_is_refused_when_meta_contradicts_it(): void
    {
        $this->meta(['*fields=primary_funding_id*' => Http::response(['id' => self::WABA], 200)]);
        $this->link($this->owner);

        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::NO_PAYMENT_METHOD)]);
        $this->assertSame(WhatsAppOwnApp::BILLING_UNPROVEN, $this->connection()->billing_state);

        // ومشارَكٌ مع نشاط أبعاد — شريكٌ قد يمدّه بخطّ ائتمانه
        $this->platformNumber();
        $this->meta(['*/'.self::ABAAD_BIZ.'/client_whatsapp_business_accounts*' => Http::response(['data' => [['id' => self::WABA]]], 200)]);
        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::SHARED_WITH_ABAAD)]);

        // ولشريكٍ نيابةً عن التاجر
        $this->meta(ownership: ['ownership_type' => 'ON_BEHALF_OF', 'on_behalf_of_business_info' => ['id' => 'BIZ-CLIENT', 'name' => 'التاجر', 'type' => 'ON_BEHALF_OF']]);
        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::PARTNER_OWNED)]);

        $c = $this->connection();
        $this->assertSame([WhatsAppConnection::PENDING, WhatsAppOwnApp::BILLING_UNPROVEN, null], [$c->status, $c->billing_state, $c->billing_evidence]);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
    }

    /** ودليلٌ رُوجع ثمّ نقضته ميتا يوقف الإرسال — كلَّ يوم يُقرأ (`whatsapp:own-billing`) */
    public function test_a_reviewed_link_stops_when_meta_later_contradicts_its_evidence(): void
    {
        $this->meta(['*fields=primary_funding_id*' => Http::response(['primary_funding_id' => '4400000000001', 'id' => self::WABA], 200)]);
        $this->link($this->owner);
        $this->review()->assertSessionHasNoErrors();
        $this->assertNotNull(WhatsAppConnections::resolve($this->shop->fresh()));

        // قراءةٌ لم تنجح لا توقف شيئًا
        $this->meta(['*fields=primary_funding_id*' => Http::response(['error' => ['code' => 2, 'message' => 'temporarily unavailable']], 503)]);
        $this->artisan('whatsapp:own-billing')->assertSuccessful();
        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);

        // ووسيلةُ دفعٍ تبدّلت بعد المراجعة: يقف حتى يُراجَع دليلٌ جديد
        $this->meta(['*fields=primary_funding_id*' => Http::response(['primary_funding_id' => '4400000000002', 'id' => self::WABA], 200)]);
        $this->artisan('whatsapp:own-billing')->assertSuccessful();

        $c = $this->connection();
        $this->assertSame([WhatsAppConnection::PENDING, WhatsAppOwnApp::AWAITS_BILLING, WhatsAppOwnApp::BILLING_UNPROVEN],
            [$c->status, $c->last_error_code, $c->billing_state]);
        $this->assertSame(WhatsAppOwnApp::say(WhatsAppOwnApp::FUNDING_CHANGED), $c->last_error_message);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
        $this->assertSame(0, Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST')->count(), 'قراءاتٌ لا غير');

        // ودليلٌ جديدٌ يُعيده
        $this->review()->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);
    }

    public function test_an_unreadable_ownership_is_unproven_not_merchant(): void
    {
        $this->meta(['*/'.self::WABA.'?fields=owner_business_info*' => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 400)]);

        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppOwnApp::BILLING_UNPROVEN, $this->connection()->billing_state);
        $this->assertNull($this->connection()->metadata['billing_facts']['ownership']);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
    }

    /* ═════════════ ٤ · متجران بتطبيقين — لكلٍّ بابُه وسرُّه ═════════════ */

    public function test_two_merchants_with_two_apps_stay_apart(): void
    {
        [$other, $otherOwner] = $this->merchant('محل الجار', 'next@own.om');
        $this->meta(apps: [
            ['app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN],
            ['app' => '5550000000002', 'secret' => 'second-app-secret-0123456789', 'waba' => '7770000000002', 'phone' => '8880000000002', 'token' => 'EAAG-second-token-0123456789'],
        ]);

        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->link($otherOwner, ['app_id' => '5550000000002', 'app_secret' => 'second-app-secret-0123456789',
            'waba_id' => '7770000000002', 'phone_number_id' => '8880000000002', 'access_token' => 'EAAG-second-token-0123456789'])->assertSessionHasNoErrors();

        $mine = $this->connection();
        $theirs = $this->connection($other);
        $this->assertNotSame($mine->webhook_key, $theirs->webhook_key);
        $this->assertNotSame($mine->access_token, $theirs->access_token);

        // رسالةُ زبون الجار موقَّعةً بسرّ تطبيقه — على بابي: مرفوضة؛ وعلى بابه لرقمي: لا تُكتب
        $this->hook(WhatsAppOwnApp::callbackUrl($mine), $this->inbound('8880000000002'), 'second-app-secret-0123456789')->assertForbidden();
        $this->hook(WhatsAppOwnApp::callbackUrl($theirs), $this->inbound(self::PHONE), 'second-app-secret-0123456789')->assertOk();
        $this->assertSame(0, WhatsAppChatMessage::count());

        $this->hook(WhatsAppOwnApp::callbackUrl($theirs), $this->inbound('8880000000002'), 'second-app-secret-0123456789')->assertOk();
        $this->assertSame([$other->id], WhatsAppChatMessage::pluck('business_id')->all());

        // ورقمُ أحدهما لا يُربط للآخر
        $this->link($otherOwner)->assertSessionHasErrors();
        $this->assertSame('8880000000002', $this->connection($other)->phone_number_id);
    }

    /* ═════════════ ٥ · الأسرارُ لا تبلغ الشاشة، ورمزُ التحقّق لصاحب الحساب ═════════════ */

    public function test_secrets_never_reach_the_screen_and_the_verify_token_is_the_owners(): void
    {
        $this->meta();
        $this->link($this->owner);
        $c = $this->connection();

        $props = fn (User $u) => $this->actingAs($u)->get(route('admin.integrations.whatsapp'))->assertOk()->viewData('page')['props'];
        $json = json_encode($props($this->owner), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::TOKEN, $json);
        $this->assertStringContainsString($c->webhook_key, $json, 'العنوانُ يُعرض ليُلصق');
        $this->assertSame($c->webhook_verify_token, $props($this->owner)['automation']['own_app']['connection']['verify_token']);

        $manager = User::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'email' => 'mgr@own.om',
            'password' => bcrypt('password12345'), 'role' => 'manager', 'status' => 'نشط', 'permissions' => ['integrations']]);
        $this->actingAs($manager)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (Assert $page) => $page
            ->where('automation.own_app.connection.verify_token', null));

        // ولا يربط غيرُ صاحب الحساب
        $this->actingAs($manager)->post(route('admin.integrations.whatsapp.connect'), [
            'app_id' => self::APP, 'app_secret' => self::SECRET, 'waba_id' => self::WABA,
            'phone_number_id' => self::PHONE, 'access_token' => self::TOKEN, 'billing_ack' => '1',
        ])->assertSessionHasErrors('app_id');
    }

    /* ═════════════ ٦ · RIBBON وأشباهُه: يُكمل الربطَ بسرّه، ورمزُه وإرسالُه كما هما ═════════════ */

    public function test_a_number_already_sending_from_its_own_app_completes_the_link_without_a_new_token(): void
    {
        $legacy = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS, 'waba_id' => self::WABA, 'phone_number_id' => self::PHONE,
            'access_token' => self::TOKEN, 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subWeek(),
            'token_app_id' => self::APP,
        ]);
        $this->assertSame($legacy->id, WhatsAppConnections::resolve($this->shop)?->id, 'يُرسل اليوم');
        $this->meta();

        // قبل مراجعة دليله: لا يُستبدل ربطُه، وإرسالُه كما هو
        $this->link($this->owner, ['access_token' => ''])->assertSessionHasErrors('phone_number_id');
        $this->assertNull($legacy->fresh()->link_type);
        $this->assertSame($legacy->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);

        // يُراجَع دليلُه وهو يُرسل بطريقه القديم — ثمّ يُكمل الربطَ بسرّه
        $this->review()->assertSessionHasNoErrors();
        $this->assertSame($legacy->id, WhatsAppConnections::resolve($this->shop->fresh())?->id, 'المراجعةُ لا توقفه');
        $this->link($this->owner, ['access_token' => ''])->assertSessionHasNoErrors();

        $c = $legacy->fresh();
        $this->assertSame([WhatsAppOwnApp::TYPE, self::TOKEN, WhatsAppConnection::ACTIVE, WhatsAppOwnApp::BILLING_CONFIRMED],
            [$c->link_type, $c->access_token, $c->status, $c->billing_state]);
        $this->assertSame(1, WhatsAppConnection::where('business_id', $this->shop->id)->count(), 'الصفُّ نفسُه');
        $this->assertSame($c->id, WhatsAppConnections::resolve($this->shop->fresh())?->id, 'وإرسالُه لم يقف');
    }

    /* ═════════════ ٧ · الفصلُ محلّيّ، والعنوانُ يبقى لإعادة الربط ═════════════ */

    public function test_disconnect_is_local_and_relinking_keeps_the_same_door(): void
    {
        $this->meta();
        $this->link($this->owner);
        $this->review();
        $key = $this->connection()->webhook_key;

        $before = $this->posts();
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'))->assertSessionHasNoErrors();
        $this->assertSame($before, $this->posts(), 'لا نداءَ إلى ميتا');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode, 'ولا رجوعَ إلى رقم أبعاد');

        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->assertSame($key, $this->connection()->webhook_key, 'لا يُعاد ضبطُ تطبيقه');
        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status, 'ودليلُ الحساب نفسِه يمضي معه');
    }

    /* ═════════════ ٨ · رقمٌ جديدٌ على Cloud API يُفعَّل برمزه — كما في التسجيل ═════════════ */

    public function test_a_new_number_waits_for_its_pin_then_sends(): void
    {
        $this->meta(['*/'.self::PHONE.'/register' => Http::response(['success' => true], 200)], platform: 'NOT_APPLICABLE');

        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppLink::BILLING_UNPROVEN, WhatsAppLink::state($this->connection()));
        $this->review()->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppLink::NEEDS_REGISTRATION, WhatsAppLink::state($this->connection()));

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.register'), ['pin' => '482915'])->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);
    }

    /* ═════════════ ٩ · وتطبيقُ أبعاد ليس طريقَ التجّار ═════════════ */

    public function test_embedded_signup_through_abaads_app_is_closed_to_merchants(): void
    {
        config(['whatsapp.config_id' => '2177821789438050']);
        $this->meta();

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789',
        ])->assertSessionHasErrors('code');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth/access_token'));
        $this->assertNull($this->connection());
    }

    /* ═════════════ ١٠ · المتجرُ الجديد بلا رقمٍ مشترك ولا حصص ═════════════ */

    public function test_a_shop_closed_to_the_shared_number_buys_no_message_packs(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.packs.request'))->assertSessionHasErrors('pack');
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::ABAAD_SHARED])->assertSessionHasErrors('mode');

        $this->assertSame(0, DB::table('whatsapp_message_packs')->count());
    }

    /* ═════════════ ١١ · كلُّ رسالةٍ تُحتسب على حساب رسائل التاجر الذي رُوجع ═════════════ */

    /** وصلةٌ رُبطت ورُوجع دليلُها — بميتا كما يُمرَّر */
    private function reviewed(array $over = [], array $apps = []): WhatsAppConnection
    {
        $this->meta($over, $apps);
        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();

        return $this->connection();
    }

    /** @return Collection<int, HttpRequest> ما خرج إلى `/messages` */
    private function sends()
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))->map(fn (array $pair) => $pair[0])->values();
    }

    /** @return array<int, array<string, mixed>> رمزُ التاجر يُرسل أيضًا من حساب شريكٍ */
    private function withPartner(): array
    {
        return [['app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN,
            'messaging' => [self::WABA, self::PARTNER]]];
    }

    public function test_every_send_path_names_the_reviewed_messaging_account(): void
    {
        $c = $this->reviewed();

        MetaWhatsAppClient::sendText($c, '96891234567', 'مرحبًا');
        MetaWhatsAppClient::sendTemplate($c, '96891234567', 'order_ready', 'ar', ['1']);
        MetaWhatsAppClient::sendMedia($c, '96891234567', 'document', 'MEDIA-1', 'فاتورة', 'invoice.pdf');

        // ومن الصندوق كما يردّ التاجر: زبونٌ كتب، فردّ عليه
        $this->hook(WhatsAppOwnApp::callbackUrl($c), $this->inbound(self::PHONE), self::SECRET)->assertOk();
        $chat = WhatsAppChat::where('business_id', $this->shop->id)->firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.whatsappInbox.reply', $chat->id), ['body' => 'جاهز'])->assertSessionHasNoErrors();

        $sent = $this->sends();
        $this->assertCount(4, $sent);
        $sent->each(fn (HttpRequest $r) => $this->assertSame(self::WABA, $r['messaging_account_id'], 'كلُّ رسالةٍ على الحساب الذي رُوجع'));
    }

    public function test_a_number_with_two_messaging_accounts_bills_only_the_reviewed_one_or_sends_nothing(): void
    {
        // حسابُ الشريك على الرقم نفسه، ورمزُ التاجر يُرسل منه أيضًا
        $partner = ['*/'.self::PARTNER.'/phone_numbers*' => Http::response(['data' => [['id' => self::PHONE]]], 200)];
        $c = $this->reviewed($partner, $this->withPartner());
        $this->assertSame(2, $c->metadata['billing_facts']['reach']);

        MetaWhatsAppClient::sendText($c, '96891234567', 'مرحبًا');
        $this->assertSame(self::WABA, $this->sends()->sole()['messaging_account_id'], 'لا على حساب الشريك');

        // وعلى إصدارٍ لا يُعرف أنّه يقبل تسميةَ الحساب: لا رسالة — خيرٌ من فوترة حسابٍ آخر
        config(['whatsapp.api_version' => 'v21.0']);
        $this->meta($partner, $this->withPartner());
        $result = MetaWhatsAppClient::sendText($c->fresh(), '96891234567', 'مرحبًا');

        $this->assertSame([false, WhatsAppOwnApp::AMBIGUOUS_ACCOUNT], [$result['ok'], $result['code']]);
        $this->assertFalse($c->fresh()->isUsable());
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
        $this->assertCount(0, $this->sends());

        // ومفتاحُ التسمية مطفأً كذلك
        config(['whatsapp.api_version' => 'v26.0', 'whatsapp.messaging_account_param' => false]);
        $this->assertFalse($c->fresh()->isUsable());
    }

    public function test_a_token_reaching_another_account_without_this_number_is_not_ambiguous(): void
    {
        // يُرسل الرمزُ من حسابٍ ثانٍ — لكنّ رقمَ المتجر ليس فيه
        $c = $this->reviewed(['*/'.self::PARTNER.'/phone_numbers*' => Http::response(['data' => [['id' => '8880000000077']]], 200)], $this->withPartner());
        $this->assertSame(1, $c->metadata['billing_facts']['reach']);

        // فحتى بلا تسمية الحساب تختاره ميتا وحده
        config(['whatsapp.messaging_account_param' => false]);
        $this->assertTrue(MetaWhatsAppClient::sendText($c->fresh(), '96891234567', 'مرحبًا')['ok']);
        $this->assertArrayNotHasKey('messaging_account_id', $this->sends()->sole()->data());
    }

    public function test_an_unread_account_list_blocks_sending_where_the_account_cannot_be_named(): void
    {
        $c = $this->reviewed(['*/'.self::PARTNER.'/phone_numbers*' => Http::response(['error' => ['code' => 2, 'message' => 'unavailable']], 503)], $this->withPartner());
        $this->assertNull($c->metadata['billing_facts']['reach'], 'لا نعرف — لا «واحد»');

        config(['whatsapp.messaging_account_param' => false]);
        $this->assertSame(WhatsAppOwnApp::AMBIGUOUS_ACCOUNT, MetaWhatsAppClient::sendText($c->fresh(), '96891234567', 'مرحبًا')['code']);
        $this->assertCount(0, $this->sends());

        // وبالتسمية يُرسل — على حسابه
        config(['whatsapp.messaging_account_param' => true]);
        $this->assertTrue(MetaWhatsAppClient::sendText($c->fresh(), '96891234567', 'مرحبًا')['ok']);
        $this->assertSame(self::WABA, $this->sends()->sole()['messaging_account_id']);
    }

    public function test_a_token_that_no_longer_sends_from_its_account_is_stopped_and_cannot_be_reviewed(): void
    {
        $c = $this->reviewed();

        // سُحب من «مستخدم النظام» الإرسالُ من هذا الحساب
        $this->meta(['*/7770000000055/phone_numbers*' => Http::response(['data' => []], 200)], [[
            'app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN,
            'messaging' => ['7770000000055'],
        ]]);
        $this->artisan('whatsapp:own-billing')->assertSuccessful();

        $c = $c->fresh();
        $this->assertSame([WhatsAppConnection::PENDING, WhatsAppOwnApp::say(WhatsAppOwnApp::ACCOUNT_UNBOUND)], [$c->status, $c->last_error_message]);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::ACCOUNT_UNBOUND)]);
    }

    /* ═════════════ ١٢ · حسابُ رسائلٍ واحد لمتجرٍ واحد ═════════════ */

    public function test_a_messaging_account_belongs_to_one_shop_only(): void
    {
        [$other, $otherOwner] = $this->merchant('محل الجار', 'next@own.om');
        $second = ['app' => '5550000000002', 'secret' => 'second-app-secret-0123456789', 'waba' => self::WABA, 'phone' => '8880000000002', 'token' => 'EAAG-second-token-0123456789'];
        // حسابٌ واحدٌ فيه رقمان، ورمزُ الجار مُنح الحسابَ نفسه
        $both = ['*/'.self::WABA.'/phone_numbers*' => Http::response(['data' => [
            ['id' => self::PHONE, 'display_phone_number' => '+968 9100 0001', 'platform_type' => 'CLOUD_API'],
            ['id' => '8880000000002', 'display_phone_number' => '+968 9100 0002', 'platform_type' => 'CLOUD_API'],
        ]], 200)];
        $apps = [['app' => self::APP, 'secret' => self::SECRET, 'waba' => self::WABA, 'phone' => self::PHONE, 'token' => self::TOKEN], $second];

        $this->meta($both, $apps);
        $this->link($this->owner)->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();

        $this->link($otherOwner, ['app_id' => $second['app'], 'app_secret' => $second['secret'], 'phone_number_id' => $second['phone'], 'access_token' => $second['token']])
            ->assertSessionHasErrors(['waba_id' => WhatsAppOwnApp::say(WhatsAppOwnApp::ACCOUNT_TAKEN)]);
        $this->assertNull($this->connection($other));

        // ولا يُؤكَّد لمتجرٍ ثانٍ صفٌّ على الحساب نفسه — ولو كتبه كودٌ قديم
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $other->id, 'provider' => 'meta_cloud', 'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'link_type' => WhatsAppOwnApp::TYPE, 'waba_id' => self::WABA, 'phone_number_id' => $second['phone'], 'access_token' => $second['token'],
            'token_app_id' => $second['app'], 'app_secret' => $second['secret'], 'webhook_key' => str_repeat('k', 40),
            'status' => WhatsAppConnection::PENDING, 'last_error_code' => WhatsAppOwnApp::AWAITS_BILLING, 'billing_state' => WhatsAppOwnApp::BILLING_UNPROVEN,
        ]);
        $this->review($other)->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::ACCOUNT_TAKEN)]);
        $this->assertNull(WhatsAppConnections::resolve($other->fresh()));

        // والأوّلُ كما هو: يُرسل، ورسائلُه على حسابه
        $this->assertSame($this->connection()->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);
    }

    /* ═════════════ ١٣ · المتجرُ القائم على الرقم المشترك: لا تحويلَ قبل الجاهزيّة، ولا توقّف ═════════════ */

    public function test_an_existing_shared_shop_keeps_sending_until_its_own_link_is_ready_and_is_never_moved_by_itself(): void
    {
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED, 'whatsapp_shared_allowed' => true]);
        $platform = $this->platformNumber();
        $this->assertSame($platform->id, WhatsAppConnections::resolve($this->shop->fresh())?->id, 'يُرسل اليوم من رقم أبعاد');

        $this->meta();
        $this->link($this->owner)->assertSessionHasNoErrors();

        // تحويلُ التاجر وتحويلُ المنصّة ينتظران مراجعةَ الدليل
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors('mode');
        $this->actingAs($this->admin())->put(route('super-admin.businesses.whatsapp.update', $this->shop->id), ['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN])
            ->assertSessionHasErrors('whatsapp_mode');
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        $this->assertSame($platform->id, WhatsAppConnections::resolve($this->shop->fresh())?->id, 'ولم يقف إرسالُه');

        // وبعد المراجعة لا يُحوَّل وحده — التحويلُ قرارٌ يُتّخذ
        $this->review()->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        $this->assertSame($platform->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasNoErrors();
        $this->assertSame($this->connection()->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);
    }

    /* ═════════════ ١٢ · حسابان باسمٍ واحد — يُفرَّق بينهما بالمعرّف لا بالاسم ═════════════ */

    /** حسابُ التاجر الذي عليه وسيلةُ الدفع — والآخرُ بالاسم نفسِه بلا وسيلة */
    private const PAID = '7770000000021';

    private const EMPTY = '7770000000022';

    /** ميتا لحسابين باسمٍ واحد: وسيلةُ الدفع تُقرأ لكلٍّ بمعرّفه، والتطبيقُ مربوطٌ بـ`$waba` */
    private function sameName(string $waba): void
    {
        $this->meta([
            '*/'.self::PAID.'?fields=primary_funding_id*' => Http::response(['id' => self::PAID, 'primary_funding_id' => 'FUNDING-VISA-9422'], 200),
            '*/'.self::EMPTY.'?fields=primary_funding_id*' => Http::response(['id' => self::EMPTY], 200),
        ], [['app' => self::APP, 'secret' => self::SECRET, 'waba' => $waba, 'phone' => self::PHONE, 'token' => self::TOKEN]], [
            'owner_business_info' => ['id' => 'BIZ-SALAM', 'name' => 'SALAM SITY Trading LLC'],
            'on_behalf_of_business_info' => ['id' => 'BIZ-SALAM', 'name' => 'SALAM SITY Trading LLC', 'type' => 'SELF'],
        ]);
    }

    /** @return list<string> ما سُئلت عنه ميتا من حسابات */
    private function fundingReads(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'primary_funding_id'))
            ->map(fn (array $pair) => $pair[0]->url())->values()->all();
    }

    public function test_two_accounts_with_one_name_are_told_apart_by_their_id(): void
    {
        $this->sameName(self::PAID);
        $this->link($this->owner, ['waba_id' => self::PAID])->assertSessionHasNoErrors();
        $c = $this->connection();

        // وسيلةُ الدفع موجودة — ولا يُقال «بلا وسيلة دفع»، ولا يُرسل قبل مراجعة الدليل
        $stages = WhatsAppOwnApp::stages($c);
        $this->assertSame(['present', 'unproven', 'blocked'], [$stages['payment_method'], $stages['responsibility'], $stages['sending']]);
        $this->assertStringContainsString(self::PAID, (string) $stages['sending_reason']);
        $this->assertStringContainsString('وسيلة دفع', (string) $stages['sending_reason']);
        $this->assertStringNotContainsString('بلا وسيلة دفع', (string) $stages['sending_reason']);
        $this->assertSame(self::PAID, WhatsAppOwnApp::reviewView($c)['waba_id']);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));

        // ودليلُ الحساب الآخر — الاسمُ نفسُه والمعرّفُ غيرُه — يُردّ قبل أن يُقرأ شيءٌ أو يُكتب
        $before = count($this->fundingReads());
        $this->review(over: ['evidence_waba_id' => self::EMPTY])
            ->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::sayFor(WhatsAppOwnApp::EVIDENCE_OTHER_ACCOUNT, self::PAID, self::EMPTY)]);
        $this->assertSame($before, count($this->fundingReads()), 'لا يُسأل عن حسابٍ لم يُربط');
        $this->assertSame([WhatsAppOwnApp::BILLING_UNPROVEN, null], [$c->fresh()->billing_state, $c->fresh()->billing_evidence]);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()), 'وما زال لا يُرسل');

        // ودليلُ الحساب المربوط نفسِه — بمعرّفه — يُؤكَّد، والإرسالُ بعده مسموح
        $this->review(over: ['evidence_waba_id' => ' '.self::PAID.' '])->assertSessionHasNoErrors();
        $c = $c->fresh();
        $this->assertSame([WhatsAppOwnApp::BILLING_CONFIRMED, self::PAID], [$c->billing_state, $c->billing_evidence['waba_id']]);
        $this->assertSame(['present', 'confirmed', 'allowed', null], array_values(WhatsAppOwnApp::stages($c)));
        $this->assertSame($c->id, WhatsAppConnections::resolve($this->shop->fresh())?->id);

        // وكلُّ قراءةٍ لوسيلة الدفع كانت للحساب المربوط — لا للحساب الآخر بالاسم نفسِه
        $reads = $this->fundingReads();
        $this->assertNotEmpty($reads);
        foreach ($reads as $url) {
            $this->assertStringContainsString('/'.self::PAID.'?', $url);
            $this->assertStringNotContainsString(self::EMPTY, $url);
        }
    }

    public function test_an_account_meta_says_has_no_payment_method_is_named_by_its_id(): void
    {
        $this->sameName(self::EMPTY);
        $this->link($this->owner, ['waba_id' => self::EMPTY])->assertSessionHasNoErrors();
        $c = $this->connection();

        $view = WhatsAppOwnApp::reviewView($c);
        $this->assertSame(['absent', 'unproven', 'blocked'], [$view['stages']['payment_method'], $view['stages']['responsibility'], $view['stages']['sending']]);
        // و«بلا وسيلة دفع» تُقال عن هذا الحساب بمعرّفه — فلا تُحمل على الحساب الذي عليه البطاقة
        $this->assertStringContainsString(self::EMPTY, (string) $view['blocker']);
        $this->assertStringContainsString('بلا وسيلة دفع', (string) $view['blocker']);
        $this->assertStringNotContainsString(self::PAID, (string) $view['blocker']);

        // ولا يُؤكَّد ولو رُئي دليلٌ بمعرّفه
        $this->review(over: ['evidence_waba_id' => self::EMPTY])
            ->assertSessionHasErrors(['whatsapp' => WhatsAppOwnApp::say(WhatsAppOwnApp::NO_PAYMENT_METHOD)]);
        $this->assertSame(WhatsAppOwnApp::BILLING_UNPROVEN, $c->fresh()->billing_state);
        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
    }

    public function test_an_unreadable_payment_method_is_unknown_not_absent_and_still_needs_a_reviewer(): void
    {
        // ميتا تردّ `#10` على وسيلة الدفع لتطبيقٍ ليس «مزوّد حلول أعمال» — كتطبيق التاجر
        $this->meta();
        $this->link($this->owner)->assertSessionHasNoErrors();
        $stages = WhatsAppOwnApp::stages($this->connection());

        $this->assertSame(['unknown', 'unproven', 'blocked'], [$stages['payment_method'], $stages['responsibility'], $stages['sending']]);
        $this->assertStringNotContainsString('بلا وسيلة دفع', (string) $stages['sending_reason']);
        $this->assertStringContainsString(self::WABA, (string) $stages['sending_reason']);
    }
}
