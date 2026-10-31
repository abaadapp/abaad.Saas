<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * سرُّ تطبيق ميتا ورموزُ الوصول لا تخرج في عنوانٍ ولا سجلٍّ ولا شاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   ١ · فحصُ إعداد التطبيق (`/{app}/subscriptions`) يُرسل رمزَ التطبيق في ترويسة
 *       `Authorization` لا في العنوان، ويبقى يعمل. ولا نداءَ آخر يحمل السرَّ في
 *       عنوانه إلّا الاثنان اللذان لا توثّق ميتا لهما غيرَ المعاملات: فحصُ الرمز
 *       (`/debug_token`) وتبديلُ الكود (`/oauth/access_token`).
 *   ٢ · خطأُ الاتصال يحمل عنوانَ الطلب بمعاملاته — وما يُكتب منه في عمود
 *       الخطأ أو السجلّ أو رسالة التاجر يُنقّى (`WhatsAppEmbeddedSignup::scrub`).
 *   ٣ · ناتجُ أمر الفحص العامّ بلا سرٍّ ولا رمز.
 *
 * وميتا كلُّها مزيّفة، ولا يتغيّر عندها شيء.
 */
class MetasSecretsNeverReachAUrlALogOrAScreenTest extends TestCase
{
    use RefreshDatabase;

    private const APP = '1082481610822941';

    private const SECRET = 'app-secret-must-never-leak-9f3c';

    private const MERCHANT_TOKEN = 'EAAGmerchantTokenMustNeverLeak0123456789';

    private Business $shop;

    private User $owner;

    /** حين يُطلب: خطأُ اتصالٍ يحمل عنوانَ الطلب كاملًا كما يكتبه cURL */
    private ?string $failOn = null;

    protected function setUp(): void
    {
        parent::setUp();
        // الطريقُ السابق — رمزٌ من تطبيق أبعاد للتاجر؛ والافتراضُ اليوم تطبيقُ التاجر (`WhatsAppOwnApp`)
        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => self::APP,
            'whatsapp.app_secret' => self::SECRET,
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v26.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $r) => $this->meta($r));

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN, 'whatsapp_inbox_enabled' => true,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@secrets.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function meta(HttpRequest $r)
    {
        $url = $r->url();

        if ($this->failOn !== null && str_contains($url, $this->failOn)) {
            // ما يكتبه cURL حقًّا: الخطأ، ثمّ العنوانُ بمعاملاته كاملةً
            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '
                .$url.(str_contains($url, '?') ? '&' : '?').'access_token='.self::APP.'%7C'.self::SECRET.'&client_secret='.self::SECRET.'&input_token='.self::MERCHANT_TOKEN);
        }

        return match (true) {
            str_contains($url, '/oauth/access_token') => Http::response(['access_token' => self::MERCHANT_TOKEN], 200),
            str_contains($url, '/debug_token') => Http::response(['data' => [
                'is_valid' => true, 'app_id' => self::APP, 'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
                ],
            ]], 200),
            str_contains($url, '/'.self::APP.'/subscriptions') => Http::response(['data' => [[
                'object' => 'whatsapp_business_account', 'callback_url' => route('webhooks.whatsapp'),
                'active' => true, 'fields' => [['name' => 'messages', 'version' => 'v26.0']],
            ]]], 200),
            str_contains($url, '/routing_roles') => Http::response(['error' => ['code' => 100, 'message' => 'Conversation routing is not enabled for this phone number.']], 400),
            str_contains($url, 'fields=is_on_biz_app') => Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200),
            str_contains($url, '/WABA-1/phone_numbers') => Http::response(['data' => [['id' => 'PN-1', 'display_phone_number' => '+968 7000 0001', 'platform_type' => 'CLOUD_API']]], 200),
            str_contains($url, '/WABA-1/subscribed_apps') => $r->method() === 'POST'
                ? Http::response(['success' => true], 200)
                : Http::response(['data' => [['whatsapp_business_api_data' => ['id' => self::APP]]]], 200),
            default => Http::response(['error' => ['code' => 100, 'message' => 'not faked']], 404),
        };
    }

    /** لا سرَّ ولا رمزَ في نصٍّ ما — والسرُّ مرمَّزًا في عنوانٍ أيضًا */
    private function assertClean(string $text, string $where): void
    {
        foreach ([self::SECRET, rawurlencode(self::SECRET), self::MERCHANT_TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "سرٌّ أو رمزٌ في {$where}");
        }
    }

    private function linkWithOldConnection(): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'waba_id' => 'WABA-OLD', 'phone_number_id' => 'PN-OLD', 'access_token' => 'old-working-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subWeek(),
        ]);
    }

    /* ═════════════ ١ · رمزُ التطبيق في الترويسة لا في العنوان ═════════════ */

    public function test_the_app_webhook_check_carries_the_app_token_in_the_header_and_still_works(): void
    {
        $super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@secrets.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($super)->post(route('super-admin.whatsapp.app-webhook.check'))->assertSessionHas('toast.type', 'success');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/'.self::APP.'/subscriptions')
            && ! str_contains($r->url(), self::SECRET) && ! str_contains($r->url(), 'access_token')
            && $r->header('Authorization') === ['Bearer '.self::APP.'|'.self::SECRET]);
        $this->assertSame(WhatsAppLinkCheck::APP_LISTENING, WhatsAppLinkCheck::appWebhook()['state']);
        $this->assertClean((string) session('toast.msg'), 'رسالة الفحص');
    }

    /**
     * ربطٌ كاملٌ وفحصٌ كامل: لا نداءَ يحمل السرَّ في عنوانه إلّا ما لا توثّق له
     * ميتا غيرَ المعاملات — وكلُّ نداءٍ برمز التاجر يحمله في الترويسة.
     */
    public function test_only_metas_parameter_only_endpoints_carry_the_secret_and_merchant_calls_use_the_header(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), [
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => self::MERCHANT_TOKEN,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'));

        $this->assertSame(self::APP, WhatsAppConnection::sole()->token_app_id);
        Http::assertNotSent(fn (HttpRequest $r) => ! str_contains($r->url(), '/debug_token') && ! str_contains($r->url(), '/oauth/access_token')
            && (str_contains($r->url(), self::SECRET) || str_contains($r->url(), rawurlencode(self::SECRET))));
        Http::assertNotSent(fn (HttpRequest $r) => ! str_contains($r->url(), '/debug_token') && str_contains($r->url(), self::MERCHANT_TOKEN));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/WABA-1/subscribed_apps')
            && $r->header('Authorization') === ['Bearer '.self::MERCHANT_TOKEN]);
    }

    /* ═════════════ ٢ · خطأُ الاتصال لا يحمل سرًّا إلى عمودٍ أو سجلٍّ أو شاشة ═════════════ */

    public function test_a_timed_out_code_exchange_leaves_no_secret_in_the_column_the_log_or_the_page(): void
    {
        Log::spy();
        $old = $this->linkWithOldConnection();
        $this->failOn = '/oauth/access_token';

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1',
        ])->assertSessionHasErrors('code');

        $old = $old->fresh();
        $this->assertSame('PN-OLD', $old->phone_number_id, 'والربطُ القديم كما هو');
        $this->assertNotNull($old->last_error_message, 'الخطأُ مكتوبٌ ليُقرأ');
        $this->assertStringContainsString('cURL error 28', (string) $old->last_error_message, 'ويبقى مفهومًا');
        $this->assertClean((string) $old->last_error_message, 'عمود الخطأ');
        $this->assertStringNotContainsString('AQD-short-lived-code', (string) $old->last_error_message);
        $this->assertClean(json_encode(session('errors')->all(), JSON_UNESCAPED_UNICODE), 'رسالة التاجر');

        $page = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertOk();
        $this->assertClean(json_encode($page->viewData('page')['props'], JSON_UNESCAPED_UNICODE), 'الشاشة');

        Log::shouldHaveReceived('warning')->withArgs(function ($event, $context) {
            $this->assertClean(json_encode($context, JSON_UNESCAPED_UNICODE), 'السجلّ: '.$event);

            return true;
        });
    }

    public function test_a_timed_out_token_check_leaves_no_secret_in_the_log_or_the_reply(): void
    {
        Log::spy();
        $this->failOn = '/debug_token';

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), [
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => self::MERCHANT_TOKEN,
        ])->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertClean(json_encode(session('errors')->all(), JSON_UNESCAPED_UNICODE), 'رسالة التاجر');
        Log::shouldHaveReceived('warning')->withArgs(function ($event, $context) {
            $this->assertClean(json_encode($context, JSON_UNESCAPED_UNICODE), 'السجلّ: '.$event);

            return true;
        });
    }

    public function test_the_scrubber_strips_urls_parameters_bearer_tokens_and_the_secret(): void
    {
        $dirty = 'cURL error 6 for https://graph.facebook.com/v26.0/debug_token?input_token='.self::MERCHANT_TOKEN
            .'&access_token='.self::APP.'%7C'.rawurlencode(self::SECRET).' — Bearer '.self::MERCHANT_TOKEN.' secret='.self::SECRET;

        $clean = WhatsAppEmbeddedSignup::scrub($dirty);

        $this->assertClean($clean, 'النصّ المنقّى');
        $this->assertStringContainsString('cURL error 6', $clean);
        $this->assertStringContainsString('https://graph.facebook.com/v26.0/debug_token', $clean, 'العنوانُ بلا معاملاته يبقى للتشخيص');
    }

    /** وما لا سرَّ فيه من نصّ ميتا يبقى كما هو — يحتاجه الدعم */
    public function test_the_scrubber_keeps_metas_own_words_and_still_drops_a_token_after_oauth(): void
    {
        foreach (['(#190) Invalid OAuth access token - Cannot parse access token', 'Error validating access token: Session has expired', 'Bearer token required'] as $words) {
            $this->assertSame($words, WhatsAppEmbeddedSignup::scrub($words));
        }

        $this->assertSame('sent OAuth [redacted] and Bearer [redacted]',
            WhatsAppEmbeddedSignup::scrub('sent OAuth '.self::APP.'|someOtherSecretValue0123 and Bearer otherProviderTokenValue0123456'));
    }

    /* ═════════════ ٣ · ناتجُ الفحص العامّ ═════════════ */

    public function test_the_fleet_command_prints_no_secret_and_no_token(): void
    {
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => self::MERCHANT_TOKEN,
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        Artisan::call('whatsapp:own-numbers');
        $this->assertClean(Artisan::output(), 'ناتج الأمر');

        Artisan::call('whatsapp:own-numbers', ['--json' => true]);
        $json = Artisan::output();
        $this->assertClean($json, 'ناتج الأمر JSON');
        $this->assertSame(WhatsAppLinkCheck::APP_LISTENING, json_decode($json, true)['app']['state'], 'والفحصُ يبقى يعمل بالترويسة');
    }
}
