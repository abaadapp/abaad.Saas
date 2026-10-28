<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppLinkHealth;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesMetaLinking;
use Tests\TestCase;

/**
 * ربطُ واتساب يُقبل لتطبيق أبعاد وحده — ويُقال «يستقبل» حين يثبت فقط.
 *
 * سببُه ما وقع لـRIBBON في 2026-10-28: رمزٌ لُصق من تطبيق التاجر نفسه،
 * فأُشرك تطبيقُه هو في إشعارات حسابه وقالت ميتا `success` — والرقمُ يُرسل
 * ولا يصلنا من زبائنه حرف. وهنا يُثبت أنّ كلَّ طريقٍ للربط:
 *
 *   ١ · يردّ رمزًا من تطبيقٍ آخر — قبل أن يُكتب حرفٌ أو يُنادى `POST`.
 *   ٢ · يردّ حسابًا لم يُمنح، ورقمًا خارج الحساب، وصلاحياتٍ ناقصة، ورمزًا منتهيًا.
 *   ٣ · لا يعدّ `200` اشتراكًا حتى يظهر تطبيقُنا في `subscribed_apps`.
 *   ٤ · لا يكتب فوق عنوانٍ بديلٍ (override) ولا يعدّه اشتراكًا.
 *   ٥ · يُبقي الوصلةَ القائمة إن فشل الجديد.
 *   ٦ · يفرّق بين «متّصل» و«مشترك» و«يستقبل فعلًا».
 *
 * وميتا كلُّها مزيّفة (`FakesMetaLinking`)، وطلبٌ لم يُزيَّف يُرمى ولا يخرج.
 */
class AWhatsAppLinkMustBeAbaadsAppTest extends TestCase
{
    use FakesMetaLinking;
    use RefreshDatabase;

    private const ABAAD_APP = '1082481610822941';

    /** تطبيقُ RIBBON كما ظهر في `subscribed_apps` يومها */
    private const OTHER_APP = '980169825105048';

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => self::ABAAD_APP,
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
            'whatsapp.coexistence_enabled' => false,
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        $this->shop = $this->shop('محل الورد');
        $this->neighbour = $this->shop('محل الجار');
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@link.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function shop(string $name): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
    }

    private function paste(array $payload = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), $payload + [
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => 'pasted-token-value-0123456789',
        ]);
    }

    private function signup(array $payload = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), $payload + [
            'code' => 'AQD-short-lived-code-0123456789', 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1',
        ]);
    }

    private function link(?Business $b = null): ?WhatsAppConnection
    {
        return WhatsAppConnection::where('business_id', ($b ?? $this->shop)->id)->latest('id')->first();
    }

    /** وصلةٌ تستقبل اليوم: من تطبيقنا، مشتركة، وصلها إشعار */
    private function receiving(): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'waba_id' => 'WABA-OLD', 'phone_number_id' => 'PN-OLD', 'access_token' => 'old-working-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subWeek(),
            'token_app_id' => self::ABAAD_APP, 'token_verified_at' => now()->subWeek(),
            'webhook_state' => WhatsAppLinkCheck::SUBSCRIBED, 'webhook_checked_at' => now()->subWeek(),
            'last_webhook_at' => now()->subDay(),
        ]);
    }

    private function posted(string $path): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_contains($r->url(), $path))->count();
    }

    /* ═════════════ ١ · رمزُ تطبيق أبعاد يُقبل — والاشتراكُ يُثبت ═════════════ */

    public function test_a_token_from_abaads_app_is_linked_subscribed_and_the_subscription_proven(): void
    {
        Http::fake($this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $link = $this->link();
        $this->assertSame(self::ABAAD_APP, $link->token_app_id);
        $this->assertSame(WhatsAppLinkCheck::SUBSCRIBED, $link->webhook_state);
        $this->assertNotNull($link->token_verified_at);
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($link));
        $this->assertSame(1, $this->posted('/WABA-1/subscribed_apps'));

        // والاشتراكُ قُرئ بعد النداء — لا يُكتفى بـ`success`
        $reads = Http::recorded(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/WABA-1/subscribed_apps'))->count();
        $this->assertSame(2, $reads, 'قبل النداء وبعده');
    }

    /* ═════════════ ٢ · ما يُردّ قبل أن يُكتب حرف ═════════════ */

    public function test_a_token_from_another_app_is_refused_before_anything_is_written_or_subscribed(): void
    {
        Http::fake($this->abaadAppMeta(appId: self::OTHER_APP));

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertSame(0, $this->posted('subscribed_apps'), 'ولا يُشرك تطبيقَ غيرنا باسمنا');
        $this->assertStringContainsString('Abaad Messaging', (string) session('errors')->first('access_token'));
    }

    /** رمزٌ يأبى `debug_token` أن يصفه لتطبيقنا (#100) — كرمز RIBBON يومها */
    public function test_a_token_meta_will_not_describe_to_our_app_is_refused_as_another_apps(): void
    {
        Http::fake(['*/debug_token*' => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request.']], 400)]
            + $this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertSame(0, $this->posted('subscribed_apps'));
    }

    public function test_an_account_the_token_was_not_granted_is_refused(): void
    {
        Http::fake($this->abaadAppMeta());

        $this->paste(['waba_id' => 'WABA-NOT-MINE'])->assertSessionHasErrors('waba_id');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertSame(0, $this->posted('subscribed_apps'));
    }

    public function test_a_number_outside_the_account_is_refused(): void
    {
        Http::fake($this->abaadAppMeta());

        $this->paste(['phone_number_id' => 'PN-SOMEONE-ELSES'])->assertSessionHasErrors('phone_number_id');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertSame(0, $this->posted('subscribed_apps'));
    }

    public function test_a_token_without_the_messaging_permission_is_refused(): void
    {
        Http::fake(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'app_id' => self::ABAAD_APP, 'expires_at' => 0,
            'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']]],
        ]], 200)] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::count());
    }

    public function test_an_expired_or_revoked_token_is_refused(): void
    {
        Http::fake(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => false, 'app_id' => self::ABAAD_APP,
        ]], 200)] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame(0, WhatsAppConnection::count());
        $this->assertSame(0, $this->posted('subscribed_apps'));
    }

    public function test_a_number_already_linked_to_another_shop_is_refused_and_left_with_it(): void
    {
        $theirs = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->neighbour->id,
            'phone_number_id' => 'PN-1', 'access_token' => 'neighbour-token-0123456789', 'status' => WhatsAppConnection::ACTIVE,
        ]);
        Http::fake($this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('phone_number_id');

        $this->assertSame($this->neighbour->id, (int) $theirs->fresh()->business_id);
        $this->assertNull($this->link());
    }

    /* ═════════════ ٣ · ٢٠٠ ليست اشتراكًا ═════════════ */

    public function test_meta_saying_success_without_listing_our_app_is_not_called_subscribed(): void
    {
        Http::fake(['*/WABA-1/subscribed_apps' => fn (HttpRequest $r) => $r->method() === 'POST'
            ? Http::response(['success' => true], 200)
            : Http::response(['data' => [['whatsapp_business_api_data' => ['id' => self::OTHER_APP, 'name' => 'Ribbon flower lounge Messaging']]]], 200),
        ] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'warning');

        $link = $this->link();
        $this->assertSame(WhatsAppLinkCheck::NOT_SUBSCRIBED, $link->webhook_state);
        $this->assertSame(WhatsAppLinkHealth::WEBHOOK_INCOMPLETE, WhatsAppLinkHealth::state($link));
        $this->assertStringContainsString('لم يثبت', (string) session('toast.msg'));
    }

    public function test_an_unexpected_override_is_reported_and_never_posted_over(): void
    {
        Http::fake(['*/WABA-1/subscribed_apps' => Http::response(['data' => [[
            'whatsapp_business_api_data' => ['id' => self::ABAAD_APP, 'name' => 'Abaad Messaging'],
            'override_callback_uri' => 'https://elsewhere.example/hook',
        ]]], 200)] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors();

        $link = $this->link();
        $this->assertSame(WhatsAppLinkCheck::OVERRIDE, $link->webhook_state);
        $this->assertSame(WhatsAppLinkHealth::WEBHOOK_INCOMPLETE, WhatsAppLinkHealth::state($link));
        $this->assertSame(0, $this->posted('subscribed_apps'), 'الاشتراكُ بلا جسمٍ يمحو العنوانَ البديل — إعدادُ غيرنا');
        $this->assertStringContainsString('عنوانٍ بديل', WhatsAppLinkHealth::hint(WhatsAppLinkHealth::state($link), $link));
    }

    public function test_an_override_pointing_at_our_own_endpoint_is_no_override(): void
    {
        Http::fake(['*/WABA-1/subscribed_apps' => Http::response(['data' => [[
            'whatsapp_business_api_data' => ['id' => self::ABAAD_APP],
            'override_callback_uri' => route('webhooks.whatsapp'),
        ]]], 200)] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppLinkCheck::SUBSCRIBED, $this->link()->webhook_state);
    }

    /* ═════════════ ٤ · الوصلةُ القائمة لا تُمسّ إن فشل الجديد ═════════════ */

    public function test_a_refused_relink_keeps_the_working_connection_as_it_was(): void
    {
        $old = $this->receiving();
        $before = $old->fresh()->only(['phone_number_id', 'waba_id', 'status', 'webhook_state', 'token_app_id']);
        Http::fake($this->abaadAppMeta(['WABA-1' => ['PN-1']], self::OTHER_APP));

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame($before, $old->fresh()->only(['phone_number_id', 'waba_id', 'status', 'webhook_state', 'token_app_id']));
        $this->assertSame('old-working-token-0123456789', $old->fresh()->access_token);
        $this->assertSame(WhatsAppLinkHealth::RECEIVING, WhatsAppLinkHealth::state($old->fresh()));
    }

    public function test_a_relink_whose_subscription_is_unproven_does_not_replace_a_receiving_connection(): void
    {
        $old = $this->receiving();
        Http::fake(['*/WABA-1/subscribed_apps' => fn (HttpRequest $r) => $r->method() === 'POST'
            ? Http::response(['success' => true], 200)
            : Http::response(['data' => []], 200),
        ] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('access_token');

        $this->assertSame('PN-OLD', $old->fresh()->phone_number_id);
        $this->assertSame('old-working-token-0123456789', $old->fresh()->access_token);
    }

    public function test_linking_twice_keeps_one_row_and_subscribes_once(): void
    {
        Http::fake($this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors();
        $this->paste()->assertSessionHasNoErrors();

        $this->assertSame(1, WhatsAppConnection::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, $this->posted('/WABA-1/subscribed_apps'), 'والثاني يجد تطبيقَنا مشتركًا فلا يُعيد');
    }

    /* ═════════════ ٥ · التسجيل المدمج يمرّ بالباب نفسه ═════════════ */

    public function test_embedded_signup_refuses_a_token_that_is_not_from_abaads_app(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200)]
            + $this->abaadAppMeta(appId: self::OTHER_APP));

        $this->signup()->assertSessionHasErrors('code');

        $this->assertNull($this->link());
        $this->assertSame(0, $this->posted('subscribed_apps'));
    }

    public function test_embedded_signup_proves_the_subscription_too(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200)]
            + $this->abaadAppMeta());

        $this->signup()->assertSessionHasNoErrors()->assertSessionHas('toast.type', 'success');

        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($this->link()));
        $this->assertFalse($this->link()->coexistence);
    }

    /** رقمٌ على تطبيق الأعمال: لا يُربط إلّا بالتعايش مختارًا صراحةً ومفتوحًا */
    public function test_a_business_app_number_is_linked_only_by_an_explicit_open_coexistence_choice(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200),
            '*/WABA-1/phone_numbers*' => Http::response(['data' => [[
                'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066', 'platform_type' => 'CLOUD_API',
            ]]], 200),
            '*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'], 200),
        ] + $this->abaadAppMeta());

        // الافتراضُ رقمٌ مستقلّ — ورقمٌ على التطبيق يُردّ
        $this->signup()->assertSessionHasErrors('code');
        $this->assertNull($this->link());

        // والتعايشُ مختارًا والمسارُ مطفأٌ على الخادم — يُردّ قبل ميتا
        $this->signup(['type' => 'coexistence'])->assertSessionHasErrors('code');
        $this->assertNull($this->link());

        // ومفتوحًا ومختارًا — يُربط ويُقيَّد تعايشًا
        config(['whatsapp.coexistence_enabled' => true]);
        $this->signup(['type' => 'coexistence'])->assertSessionHasNoErrors();
        $this->assertTrue($this->link()->coexistence);
    }

    public function test_the_manual_form_never_links_a_business_app_number(): void
    {
        Http::fake(['*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => true], 200)] + $this->abaadAppMeta());

        $this->paste()->assertSessionHasErrors('phone_number_id');

        $this->assertNull($this->link());
    }

    /* ═════════════ ٦ · متّصلٌ ≠ مشتركٌ ≠ يستقبل ═════════════ */

    public function test_receiving_is_claimed_only_after_a_signed_webhook_arrives(): void
    {
        Http::fake($this->abaadAppMeta());
        $this->paste()->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($this->link()));

        // إشعارٌ بتوقيعٍ خاطئ لا يُثبت شيئًا
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-1', 'changes' => [[
            'field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PN-1'], 'statuses' => []],
        ]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=forged',
        ], $body)->assertForbidden();
        $this->assertSame(WhatsAppLinkHealth::READY, WhatsAppLinkHealth::state($this->link()));

        // وإشعارٌ موقَّعٌ بسرّ تطبيق أبعاد هو الدليل
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret-not-a-real-one'),
        ], $body)->assertOk();
        $this->assertSame(WhatsAppLinkHealth::RECEIVING, WhatsAppLinkHealth::state($this->link()));
    }

    public function test_a_lost_subscription_found_by_the_check_outweighs_older_webhooks(): void
    {
        $old = $this->receiving();
        $old->update(['waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1']);
        Http::fake([
            '*/PN-1?*' => Http::response(['display_phone_number' => '+968 9000 0000', 'verified_name' => 'محل الورد'], 200),
            '*/WABA-1/subscribed_apps' => Http::response(['data' => []], 200),
        ] + $this->abaadAppMeta());

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppLinkHealth::WEBHOOK_INCOMPLETE, WhatsAppLinkHealth::state($old->fresh()));
        $this->assertSame(0, $this->posted('subscribed_apps'), 'الفحصُ قراءةٌ لا غير');
    }

    /** وصلةُ RIBBON كما كانت: رمزٌ من تطبيقٍ آخر — الفحصُ يقولها ولا يقطع رسائله */
    public function test_the_check_names_another_apps_token_without_touching_the_connection(): void
    {
        $legacy = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => 'token-from-the-shops-own-app-0123',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now()->subDay(),
        ]);
        $this->assertSame(WhatsAppLinkHealth::CONNECTED, WhatsAppLinkHealth::state($legacy), 'قبل الفحص: متّصلٌ لم يُتحقَّق منه');

        Http::fake([
            '*/PN-1?*' => Http::response(['display_phone_number' => '+968 7679 0720', 'verified_name' => 'RIBBON'], 200),
            '*/debug_token*' => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request.']], 400),
        ] + $this->abaadAppMeta());

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))->assertSessionHas('toast.type', 'warning');

        $legacy = $legacy->fresh();
        $this->assertSame(WhatsAppLinkCheck::WRONG_APP, $legacy->link_problem);
        $this->assertSame(WhatsAppLinkHealth::WRONG_APP, WhatsAppLinkHealth::state($legacy));
        $this->assertSame('token-from-the-shops-own-app-0123', $legacy->access_token);
        $this->assertSame(WhatsAppConnection::ACTIVE, $legacy->status);
        $this->assertTrue($legacy->isUsable(), 'ويبقى يُرسل');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
    }

    public function test_the_platform_check_reads_meta_and_changes_nothing_there(): void
    {
        $super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@link.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $link = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => 'abaad-app-token-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);
        Http::fake($this->abaadAppMeta());

        $this->actingAs($super)->post(route('super-admin.businesses.whatsapp.check', $this->shop->id))->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppLinkCheck::NOT_SUBSCRIBED, $link->fresh()->webhook_state);
        $this->assertSame(WhatsAppLinkHealth::WEBHOOK_INCOMPLETE, WhatsAppLinkHealth::state($link->fresh()));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');

        // ومن ليس مدير المنصّة لا يصل الباب
        $this->actingAs($this->owner)->post(route('super-admin.businesses.whatsapp.check', $this->shop->id))->assertForbidden();
    }

    /* ═════════════ ٧ · ما لا يتغيّر ═════════════ */

    public function test_linking_never_turns_on_the_auto_reply(): void
    {
        Http::fake($this->abaadAppMeta());

        $this->paste()->assertSessionHasNoErrors();

        $this->assertFalse(WhatsAppAutoReply::settings($this->shop->id)['enabled']);
    }

    /** الوصلاتُ القائمة لا تُفحص ولا تُمسّ بالترحيل: كلُّ أعمدة الفحص فارغة */
    public function test_existing_connections_carry_no_verdict_until_someone_checks(): void
    {
        $legacy = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id,
            'phone_number_id' => 'PN-LEGACY', 'access_token' => 'legacy-token-0123456789', 'status' => WhatsAppConnection::ACTIVE,
        ])->fresh();

        $this->assertNull($legacy->token_app_id);
        $this->assertNull($legacy->webhook_state);
        $this->assertSame(WhatsAppLinkHealth::CONNECTED, WhatsAppLinkHealth::state($legacy));
        $this->assertTrue($legacy->isUsable());
    }

    public function test_the_screens_carry_the_state_but_never_a_token_or_another_apps_id(): void
    {
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id,
            'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'access_token' => 'very-secret-token-value-0123',
            'status' => WhatsAppConnection::ACTIVE, 'token_app_id' => self::OTHER_APP,
            'link_problem' => WhatsAppLinkCheck::WRONG_APP,
        ]);

        $page = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertOk();
        $json = json_encode($page->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString(WhatsAppLinkHealth::WRONG_APP, $json);
        $this->assertStringNotContainsString('very-secret-token', $json);
        $this->assertStringNotContainsString(self::OTHER_APP, $json);
    }
}
