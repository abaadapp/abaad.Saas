<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppClientLink;
use App\Models\WhatsAppConnection;
use App\Support\Demo;
use App\Support\WhatsAppClientLinks;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ‏‏«أرسل رابطًا لعميلك» — فريقُ أبعاد يرسل رابطًا، وصاحبُ المتجر يربط منه رقمَه بلا دخول.
 *
 * ═══ ما تحرسه هذه الملفّة ═══
 *
 *   · الرابطُ لمديرِ المنصّة وحده، ولمرّةٍ واحدة، وبمدّةٍ، وواحدٌ مفتوحٌ للمتجر — ورمزُه لا يُحفظ.
 *   · الرقمُ يُربط بمتجر الرابط لا بمتجر من فتحه ولا بما في الحمولة.
 *   · لا يمسّ رقمًا يعمل، ولا وصلةَ تطبيق التاجر، ولا رقمَ متجرٍ آخر.
 *   · ومن ليس له الرابطُ لا يقرأ منه رقمًا ولا اسمَ متجر.
 */
class AbaadSendsAClientAWhatsAppLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'AQD-short-lived-code-0123456789';

    private Business $shop;

    private User $owner;

    private User $super;

    private bool $subscribed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.merchant_link' => 'abaad_app',
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        $this->shop = $this->shop('محل ورد');
        $this->owner = $this->user('o@abaadapp.om', 'admin', $this->shop->id);
        $this->super = $this->user('root@abaadapp.om', 'super_admin', null);
    }

    private function shop(string $name): Business
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'whatsapp_own_allowed' => true, 'whatsapp_enabled' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        return $shop;
    }

    private function user(string $email, string $role, ?int $businessId): User
    {
        return User::create([
            'business_id' => $businessId, 'name' => 'م '.$role, 'email' => $email,
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** @param  array<string, mixed>  $over */
    private function metaSays(array $over = []): void
    {
        // الاتّحاد لا الدمج: `Http::fake` تُطابق أوّلَ نمطٍ ينطبق، فالمخصَّص أوّلًا
        Http::fake($over + [
            '*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200),
            '*/debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'app_id' => '1082481610822941',
                'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
                ],
            ]], 200),
            '*/WABA-1/phone_numbers*' => Http::response(['data' => [[
                'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066',
                'verified_name' => 'RIBBON', 'platform_type' => 'CLOUD_API',
            ]]], 200),
            '*fields=is_on_biz_app*' => Http::response(['is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'], 200),
            '*/WABA-1/subscribed_apps' => function ($request) {
                if ($request->method() === 'POST') {
                    $this->subscribed = true;

                    return Http::response(['success' => true], 200);
                }

                return Http::response(['data' => $this->subscribed
                    ? [['whatsapp_business_api_data' => ['id' => '1082481610822941', 'name' => 'Abaad Messaging']]]
                    : []], 200);
            },
            '*/WABA-1?*' => Http::response(['id' => 'WABA-1', 'owner_business_info' => ['id' => 'MB-9']], 200),
            '*/register' => Http::response(['success' => true], 200),
            '*' => Http::response(['id' => 'WABA-1'], 200),
        ]);
    }

    /** ‏‏رابطٌ جديد — ورمزُه كما يراه الموظّف مرّةً واحدة */
    private function makeUrl(?Business $shop = null): string
    {
        $response = $this->actingAs($this->super)
            ->post(route('super-admin.businesses.whatsapp.clientLink.store', ($shop ?? $this->shop)->id))
            ->assertSessionHasNoErrors();
        $made = session('whatsappClientLink');
        $response->assertSessionHas('whatsappClientLink');
        $this->leave();

        return $made['url'];
    }

    /** ‏‏يخرج ويترك المتصفّح — جلسةٌ جديدة كهاتفٍ آخر */
    private function leave(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function finish(array $payload = [])
    {
        return $this->post(route('clientLink.connect'), array_merge([
            'code' => self::CODE, 'waba_id' => 'WABA-1', 'phone_number_id' => 'PN-1', 'shop_confirmed' => true,
        ], $payload));
    }

    private function connection(?Business $shop = null): ?WhatsAppConnection
    {
        return WhatsAppConnection::where('business_id', ($shop ?? $this->shop)->id)->latest('id')->first();
    }

    private function row(): WhatsAppClientLink
    {
        return WhatsAppClientLink::latest('id')->firstOrFail();
    }

    private function link(array $attributes): WhatsAppConnection
    {
        return WhatsAppConnection::create($attributes + [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $this->shop->id, 'provider' => 'meta_cloud',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS, 'waba_id' => 'WABA-OLD', 'phone_number_id' => 'PN-OLD',
            'display_phone_number' => '+968 9000 0000', 'access_token' => 'OLD-TOKEN', 'status' => WhatsAppConnection::ACTIVE,
        ]);
    }

    /* ═════════════ ١ · من ينشئه، وماذا يُحفظ ═════════════ */

    public function test_the_link_is_shown_once_and_only_its_fingerprint_is_kept(): void
    {
        $url = $this->makeUrl();
        $token = basename($url);
        $link = $this->row();

        $this->assertSame(WhatsAppClientLinks::TOKEN_LENGTH, strlen($token));
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('whatsapp_client_links')->get()), 'الرمزُ نفسُه محفوظ في القاعدة');
        $this->assertEqualsWithDelta(WhatsAppClientLinks::HOURS * 60, now()->diffInMinutes($link->expires_at), 2);
        $this->assertSame($this->super->id, $link->created_by);

        // ‏‏والسطرُ في سجلّ المتجر نفسِه، بلا رمز
        $log = ActivityLog::where('business_id', $this->shop->id)->latest('id')->first();
        $this->assertStringContainsString('رابط ربطٍ', $log->description);
        $this->assertStringNotContainsString($token, json_encode(ActivityLog::all(), JSON_UNESCAPED_UNICODE));

        // ‏‏والصفحةُ بعد ذلك لا تعرف العنوان: تقول الحالَ وحدها
        $page = json_encode($this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->shop->id))->viewData('page'));
        $this->assertStringNotContainsString($token, $page);
        $this->assertStringNotContainsString($link->token_hash, $page);
    }

    public function test_the_ready_message_names_the_shop_and_carries_the_link(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id));
        $made = session('whatsappClientLink');

        $this->assertStringContainsString('محل ورد', $made['message']);
        $this->assertStringContainsString($made['url'], $made['message']);
    }

    public function test_only_the_platform_makes_or_cancels_a_link(): void
    {
        foreach ([$this->owner, $this->user('c@abaadapp.om', 'cashier', $this->shop->id)] as $user) {
            $this->actingAs($user)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))->assertForbidden();
            $this->actingAs($user)->delete(route('super-admin.businesses.whatsapp.clientLink.destroy', $this->shop->id))->assertForbidden();
        }
        $this->leave();
        $this->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))->assertRedirect(route('login'));

        $this->assertSame(0, WhatsAppClientLink::count());
    }

    public function test_a_new_link_closes_the_one_before_it(): void
    {
        $this->metaSays();
        $first = $this->makeUrl();
        $second = $this->makeUrl();

        $this->assertSame(1, WhatsAppClientLink::whereNull('revoked_at')->count());

        $this->get($first);
        $this->finish()->assertSessionHasErrors('code');
        $this->assertNull($this->connection());

        $this->get($second);
        $this->finish()->assertSessionHasNoErrors();
        $this->assertNotNull($this->connection());
    }

    /* ═════════════ ٢ · ما يفتحه صاحبُ المتجر ═════════════ */

    public function test_the_page_that_opens_has_no_token_in_its_address_and_no_secret_in_it(): void
    {
        $url = $this->makeUrl();

        $this->get($url)->assertRedirect(route('clientLink.show'));
        $response = $this->get(route('clientLink.show'))->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/WhatsappLink')
            ->where('link.state', 'open')->where('link.shop', 'محل ورد')->where('link.phone', null)
            ->where('config.config_id', '2177821789438050')->where('auth', null));

        $html = $response->getContent();
        $this->assertStringNotContainsString(basename($url), $html);
        $this->assertStringNotContainsString('test-secret-not-a-real-one', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unknown_link_is_not_found_and_a_page_without_one_says_open_it(): void
    {
        $this->get(route('clientLink.open', str_repeat('a', 40)))->assertNotFound();
        $this->get('/connect/whatsapp/short')->assertNotFound();

        $this->get(route('clientLink.show'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('link.state', 'missing')->where('link.shop', null)->where('config', null));
        $this->finish()->assertSessionHasErrors('code');
    }

    public function test_a_preview_of_the_message_is_not_an_open(): void
    {
        $url = $this->makeUrl();

        $this->withHeader('User-Agent', 'WhatsApp/2.24 A')->get($url);
        $this->withHeader('User-Agent', 'WhatsApp/2.24 A')->get(route('clientLink.show'));
        $this->assertNull($this->row()->opened_at);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')->get(route('clientLink.show'));
        $this->assertNotNull($this->row()->opened_at);
    }

    /* ═════════════ ٣ · الربط ═════════════ */

    public function test_the_client_links_the_number_to_the_shop_the_link_was_made_for(): void
    {
        $this->metaSays();
        $other = $this->shop('متجر آخر');
        $this->get($this->makeUrl());

        $this->finish(['business_id' => $other->id])->assertSessionHasNoErrors()->assertRedirect(route('clientLink.show'));
        $this->assertNull($this->connection($other));

        $connection = $this->connection();
        $this->assertSame(WhatsAppConnection::ACTIVE, $connection->status);
        $this->assertSame('PN-1', $connection->phone_number_id);
        $this->assertTrue($connection->isUsable());
        // ‏‏لا حسابَ ربطه — ولا يُنسب إلى الموظّف الذي أرسل الرابط
        $this->assertNull($connection->connected_by_user_id);

        $link = $this->row();
        $this->assertNotNull($link->connected_at);
        $this->assertSame($connection->id, $link->whatsapp_connection_id);
        $this->assertSame('+968 9525 9066', $link->connected_phone);

        // ‏‏والسطران في سجلّ المتجر لا في فراغ
        $logs = ActivityLog::where('business_id', $this->shop->id)->pluck('description')->implode("\n");
        $this->assertStringContainsString('رُبط رقم المتجر', $logs);
        $this->assertStringContainsString('بالتسجيل المدمج', $logs);

        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('link.state', 'used')->where('link.phone', '+968 9525 9066')->where('link.needs_pin', false)->where('config', null));
    }

    /** ‏‏من فتح رابطَ متجرٍ وهو داخلٌ حسابَ متجرٍ آخر — يُربط متجرُ الرابط وحده */
    public function test_a_merchant_signed_in_elsewhere_does_not_link_his_own_shop(): void
    {
        $this->metaSays();
        $other = $this->shop('متجر آخر');
        $stranger = $this->user('x@abaadapp.om', 'admin', $other->id);
        $url = $this->makeUrl();

        $this->actingAs($stranger)->get($url);
        $this->finish()->assertSessionHasNoErrors();

        $this->assertNotNull($this->connection());
        $this->assertNull($this->connection($other));
    }

    public function test_the_shop_must_be_confirmed_before_anything_reaches_meta(): void
    {
        $this->metaSays();
        $this->get($this->makeUrl());

        $this->finish(['shop_confirmed' => false])->assertSessionHasErrors('shop_confirmed');

        Http::assertNothingSent();
        $this->assertNull($this->connection());
        $this->assertSame(0, $this->row()->attempts);
    }

    public function test_a_used_link_links_nothing_again_and_shows_a_stranger_nothing(): void
    {
        $this->metaSays();
        $url = $this->makeUrl();
        $this->get($url);
        $this->finish();

        $this->leave();
        $this->get($url)->assertRedirect(route('clientLink.show'));
        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('link.state', 'used')->where('link.shop', null)->where('link.phone', null)->where('link.needs_pin', false));

        // ‏‏وصلةُ المتجر فُصلت — فيُسأل الرابطُ نفسُه: أيعود؟
        $this->connection()->update(['status' => WhatsAppConnection::INACTIVE]);
        $this->finish()->assertSessionHasErrors('code');
        $this->assertSame(WhatsAppConnection::INACTIVE, $this->connection()->status);
    }

    public function test_an_expired_cancelled_or_exhausted_link_reaches_no_meta(): void
    {
        $this->metaSays();
        $dead = [
            'expired' => ['expires_at' => now()->subMinute()],
            'revoked' => ['revoked_at' => now()],
            'exhausted' => ['attempts' => WhatsAppClientLinks::MAX_ATTEMPTS],
        ];

        foreach ($dead as $state => $attributes) {
            $url = $this->makeUrl();
            $this->get($url);
            $this->row()->update($attributes);

            $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page
                ->where('link.state', $state)->where('link.shop', null)->where('config', null));
            $this->finish()->assertSessionHasErrors('code');
            $this->leave();
        }

        Http::assertNothingSent();
        $this->assertNull($this->connection());
    }

    public function test_the_platform_cancels_an_open_link(): void
    {
        $this->metaSays();
        $url = $this->makeUrl();
        $this->actingAs($this->super)->delete(route('super-admin.businesses.whatsapp.clientLink.destroy', $this->shop->id));
        $this->assertNotNull($this->row()->revoked_at);
        $this->leave();

        $this->get($url);
        $this->finish()->assertSessionHasErrors('code');
        Http::assertNothingSent();
    }

    public function test_a_failed_try_is_counted_and_its_reason_is_told_to_the_team(): void
    {
        $this->metaSays(['*/oauth/access_token*' => Http::response(['error' => ['code' => 100, 'message' => 'bad code']], 400)]);
        $this->get($this->makeUrl());

        $this->finish()->assertSessionHasErrors('code');

        $this->assertSame(1, $this->row()->attempts);
        $this->assertSame('open', WhatsAppClientLinks::state($this->row()));
        $view = WhatsAppClientLinks::view($this->shop->fresh());
        $this->assertStringContainsString('تعذّر إكمال الربط', (string) $view['link']['problem']);
    }

    /** ‏‏إذنٌ تمّ ولم يظهر رقم — الرابطُ يبقى مفتوحًا ليُعاد به */
    public function test_a_signup_without_a_number_yet_leaves_the_link_open(): void
    {
        $this->metaSays(['*/WABA-1/phone_numbers*' => Http::response(['data' => []], 200)]);
        $this->get($this->makeUrl());

        $this->finish(['phone_number_id' => ''])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame('open', WhatsAppClientLinks::state($this->row()));
        $this->assertSame(WhatsAppConnection::PENDING, $this->connection()->status);
        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('link.state', 'open'));
    }

    /* ═════════════ ٤ · ما لا يُمسّ ═════════════ */

    public function test_a_number_that_works_is_never_replaced_by_a_link(): void
    {
        $this->metaSays();
        // ‏‏الرابطُ أُرسل، ثمّ ربط التاجرُ رقمَه من حسابه
        $url = $this->makeUrl();
        $this->link([]);
        $this->get($url);

        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('link.state', 'closed')->where('config', null));
        $this->finish()->assertSessionHasErrors('code');

        $this->assertSame('PN-OLD', $this->connection()->phone_number_id);
        $this->assertSame('OLD-TOKEN', $this->connection()->access_token);
        $this->assertNull($this->connection()->last_error_code);
        Http::assertNothingSent();

        // ‏‏ولا يُنشأ له رابط
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))
            ->assertSessionHasErrors('client_link');
        $this->assertSame(1, WhatsAppClientLink::count());
    }

    /** ‏‏وصلةُ تطبيق التاجر (سعود) — تعمل أو مفصولة، لا يُربط متجرُها برابط */
    public function test_a_shop_on_its_own_meta_app_is_never_linked_by_a_link(): void
    {
        $this->metaSays();

        foreach ([WhatsAppConnection::ACTIVE, WhatsAppConnection::INACTIVE, WhatsAppConnection::ERROR] as $status) {
            WhatsAppConnection::query()->delete();
            $own = $this->link(['link_type' => WhatsAppOwnApp::TYPE, 'status' => $status, 'app_secret' => 'HIS-SECRET', 'webhook_key' => str_repeat('k', 40)]);

            $this->assertStringContainsString('بتطبيقه على Meta', (string) WhatsAppClientLinks::refusal($this->shop));
            $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))
                ->assertSessionHasErrors('client_link');
            $this->assertSame('OLD-TOKEN', $own->fresh()->access_token);
            $this->assertSame('HIS-SECRET', $own->fresh()->app_secret);
        }

        $this->assertSame(0, WhatsAppClientLink::count());
        Http::assertNothingSent();
    }

    public function test_another_shops_number_is_not_taken(): void
    {
        $this->metaSays();
        $other = $this->shop('متجر آخر');
        $this->link(['business_id' => $other->id, 'phone_number_id' => 'PN-1']);
        $this->get($this->makeUrl());

        $this->finish()->assertSessionHasErrors('phone_number_id');

        $this->assertNull($this->connection());
        $this->assertSame('PN-1', $this->connection($other)->phone_number_id);
    }

    public function test_while_merchants_link_their_own_apps_no_link_is_made_or_works(): void
    {
        $this->metaSays();
        $url = $this->makeUrl();
        config(['whatsapp.merchant_link' => 'own_app']);

        $this->get($url);
        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('link.state', 'closed'));
        $this->finish()->assertSessionHasErrors('code');

        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))
            ->assertSessionHasErrors('client_link');
        $this->assertFalse(WhatsAppClientLinks::view($this->shop)['can']);
        Http::assertNothingSent();
        $this->assertNull($this->connection());
    }

    public function test_a_stopped_shop_or_one_without_its_own_number_gets_no_link(): void
    {
        foreach ([['status' => 'معطل'], ['status' => Business::AWAITING_PAYMENT], ['status' => 'نشط', 'whatsapp_own_allowed' => false]] as $attributes) {
            $this->shop->update(['whatsapp_own_allowed' => true, ...$attributes]);
            $this->assertNotNull(WhatsAppClientLinks::refusal($this->shop->fresh()), json_encode($attributes, JSON_UNESCAPED_UNICODE));
        }

        $this->shop->update(['status' => 'نشط', 'whatsapp_own_allowed' => true]);
        $this->assertNull(WhatsAppClientLinks::refusal($this->shop->fresh()));
    }

    public function test_coexistence_is_refused_while_it_is_closed(): void
    {
        $this->metaSays();
        $this->get($this->makeUrl());

        $this->finish(['type' => 'coexistence'])->assertSessionHasErrors('code');

        Http::assertNothingSent();
        $this->assertSame(0, $this->row()->attempts);
    }

    /* ═════════════ ٥ · تفعيلُ رقمٍ جديد ═════════════ */

    private function newNumber(): void
    {
        $this->metaSays(['*/WABA-1/phone_numbers*' => Http::response(['data' => [[
            'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066', 'platform_type' => 'NOT_APPLICABLE',
        ]]], 200)]);
    }

    public function test_the_one_who_linked_a_new_number_activates_it_with_its_pin(): void
    {
        $this->newNumber();
        $this->get($this->makeUrl());
        $this->finish()->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::PENDING, $this->connection()->status);
        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('link.needs_pin', true));

        $this->post(route('clientLink.register'), ['pin' => '12'])->assertSessionHasErrors('pin');
        $this->post(route('clientLink.register'), ['pin' => '123456'])->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/PN-1/register') && $request['pin'] === '123456');
        $this->assertStringNotContainsString('123456', json_encode([WhatsAppClientLink::all(), ActivityLog::all(), $this->connection()->getAttributes()]));
        $this->get(route('clientLink.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('link.needs_pin', false));
    }

    public function test_another_phone_holding_the_link_cannot_set_the_pin(): void
    {
        $this->newNumber();
        $url = $this->makeUrl();
        $this->get($url);
        $this->finish();

        $this->leave();
        $this->get($url);
        $this->post(route('clientLink.register'), ['pin' => '123456'])->assertSessionHasErrors('pin');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/register'));
        $this->assertSame(WhatsAppConnection::PENDING, $this->connection()->status);
    }

    public function test_the_pin_waits_a_day_then_only_from_the_shops_account(): void
    {
        $this->newNumber();
        $this->get($this->makeUrl());
        $this->finish();
        $this->row()->update(['connected_at' => now()->subHours(WhatsAppClientLinks::PIN_HOURS)->subMinute()]);

        $this->post(route('clientLink.register'), ['pin' => '123456'])->assertSessionHasErrors('pin');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/register'));
    }

    /** ‏‏رقمٌ رُبط وينتظر رمزَه — لا يحلّ رابطٌ ثانٍ محلَّه */
    public function test_a_number_waiting_for_its_pin_is_not_replaced(): void
    {
        $this->newNumber();
        $this->get($this->makeUrl());
        $this->finish();

        $this->assertStringContainsString('ينتظر رمز التفعيل', (string) WhatsAppClientLinks::refusal($this->shop->fresh()));
    }

    /* ═════════════ ٦ · الخبر ═════════════ */

    public function test_the_owner_and_the_team_are_told_and_no_other_shop_is(): void
    {
        $this->metaSays();
        $other = $this->user('x@abaadapp.om', 'admin', $this->shop('متجر آخر')->id);
        $this->get($this->makeUrl());
        $this->finish();

        $bell = function (User $user): string {
            $this->actingAs($user);

            return json_encode(Demo::notificationFeed(), JSON_UNESCAPED_UNICODE);
        };

        $this->assertStringContainsString('رُبط رقم واتساب +968 9525 9066', $bell($this->owner));
        $this->assertStringContainsString('«محل ورد»', $bell($this->super));
        $this->assertStringNotContainsString('9525 9066', $bell($other));
    }
}
