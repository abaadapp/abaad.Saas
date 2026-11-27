<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\InstagramConnection;
use App\Models\InstagramDataDeletion;
use App\Models\InstagramEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\InstagramLink;
use App\Support\InstagramPlatform;
use App\Support\Integrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ‏‏التاجر يضغط «تسجيل الدخول عبر Instagram» فيرجع مربوطًا — وما يحرس ذلك.
 *
 * مقفلٌ حتى تفتحه المنصّة؛ والختمُ لمرّةٍ واحدة ولمن ضغط؛ والرمزُ القصير يصير طويلًا ويُشترك في
 * الإشعارات؛ وحسابٌ شخصيّ أو مربوطٌ بمتجرٍ آخر يُرفض؛ والرمزُ يُجدَّد قبل انتهائه؛ والإشعاراتُ لا تُقبل
 * بلا توقيع ولا تُحفظ مرّتين؛ وإلغاءُ الإذن وطلبُ الحذف من Meta يمحوان ما عندنا. وMeta كلُّها مُحاكاة.
 */
class AShopLinksItsInstagramAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ig-secret-not-a-real-one';

    private const META_SECRET = 'meta-secret-not-a-real-one';

    private Business $shop;

    private User $owner;

    /** @var array<string, mixed> ما تردّ به Meta — يُبدَّل في الاختبار */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'services.instagram.graph_url' => 'https://graph.instagram.test',
            'services.instagram.token_url' => 'https://api.instagram.test/oauth/access_token',
            'services.instagram.api_version' => 'v27.0',
            'whatsapp.app_secret' => self::META_SECRET,
        ]);

        $this->meta = [
            'token' => ['data' => [['access_token' => 'SHORT-TOKEN', 'user_id' => '17841400000000001',
                'permissions' => 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments']]],
            'long' => ['access_token' => 'LONG-TOKEN', 'token_type' => 'bearer', 'expires_in' => 5184000],
            'me' => ['id' => 'APP-SCOPED-1', 'user_id' => '17841400000000001', 'username' => 'rose.shop', 'name' => 'محل الورد',
                'account_type' => 'BUSINESS', 'profile_picture_url' => 'https://cdn.example.test/p.jpg'],
            'subscribe' => ['success' => true],
            'refresh' => ['access_token' => 'LONG-TOKEN-2', 'token_type' => 'bearer', 'expires_in' => 5184000],
            'refresh_status' => 200,
        ];

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();

            return match (true) {
                str_starts_with($url, 'https://api.instagram.test/oauth/access_token') => Http::response($this->meta['token']),
                str_contains($url, '/refresh_access_token') => Http::response($this->meta['refresh'], $this->meta['refresh_status']),
                str_contains($url, 'graph.instagram.test/access_token') => Http::response($this->meta['long']),
                str_contains($url, '/subscribed_apps') => is_callable($this->meta['subscribe']) ? $this->meta['subscribe']($r) : Http::response($this->meta['subscribe']),
                str_contains($url, '/me') => Http::response($this->meta['me']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->user($this->shop, 'admin');
    }

    /* ═══════════ أدوات ═══════════ */

    private function shop(string $name): Business
    {
        $b = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
    }

    private function user(Business $b, string $role): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'مستخدم '.$role, 'email' => $role.'-'.$b->id.'@shop.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function open(Business ...$shops): void
    {
        $this->assertTrue(InstagramPlatform::setCredentials('1234567890', self::SECRET)['ok']);
        $this->assertTrue(InstagramPlatform::setMode(InstagramPlatform::PILOT, implode(',', array_map(fn ($s) => $s->id, $shops)))['ok']);
    }

    /** ‏‏يضغط «تسجيل الدخول» ويُعيد الختمَ من عنوان صفحة إنستقرام */
    private function start(?User $as = null): string
    {
        $res = $this->actingAs($as ?? $this->owner)->post(route('admin.integrations.instagram.connect'));
        $res->assertRedirect();
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $q);

        return (string) $q['state'];
    }

    private function linked(Business $shop, array $over = []): InstagramConnection
    {
        return InstagramConnection::create($over + [
            'business_id' => $shop->id, 'ig_user_id' => '1784140000000000'.$shop->id, 'ig_scoped_id' => 'APP-'.$shop->id,
            'username' => 'shop'.$shop->id, 'access_token' => 'LIVE-TOKEN', 'token_expires_at' => now()->addDays(50),
            'permissions' => InstagramLink::SCOPES, 'subscribed_fields' => InstagramLink::FIELDS,
            'status' => InstagramConnection::ACTIVE, 'connected_at' => now()->subDays(10), 'refreshed_at' => now()->subDays(10),
        ]);
    }

    private function webhook(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/instagram', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $secret === null ? null : 'sha256='.hash_hmac('sha256', $body, $secret),
        ]), $body);
    }

    private function signed(array $payload, string $secret = self::SECRET): string
    {
        $enc = rtrim(strtr(base64_encode(json_encode($payload + ['algorithm' => 'HMAC-SHA256', 'issued_at' => time()])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $enc, $secret, true)), '+/', '-_'), '=');

        return $sig.'.'.$enc;
    }

    /** @return list<string> */
    private function metaCalls(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0]->method().' '.preg_replace('~\?.*$~', '', $p[0]->url()))->values()->all();
    }

    /* ═══════════ مقفلٌ حتى تفتحه المنصّة ═══════════ */

    public function test_nothing_shows_or_listens_until_the_platform_opens_it(): void
    {
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))->assertNotFound();
        $this->assertNotContains('instagram', array_column(Integrations::cards($this->shop), 'key'), 'لا بطاقةَ لبابٍ مقفل');

        // ‏‏ولا يُفتح قبل أن يُكتب التطبيق
        $this->assertFalse(InstagramPlatform::setMode(InstagramPlatform::ALL)['ok']);

        $this->open($this->shop);
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Integrations/Instagram')->where('instagram.link', null)->where('instagram.may_manage', true));
        $this->assertContains('instagram', array_column(Integrations::cards($this->shop), 'key'));

        $other = $this->shop('محل آخر');
        $this->actingAs($this->user($other, 'admin'))->get(route('admin.integrations.instagram'))->assertNotFound();
    }

    /* ═══════════ الربطُ بضغطة ═══════════ */

    public function test_the_owner_logs_in_with_instagram_and_comes_back_linked(): void
    {
        $this->open($this->shop);

        $res = $this->actingAs($this->owner)->post(route('admin.integrations.instagram.connect'));
        $location = (string) $res->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        $this->assertSame(['1234567890', 'code', route('admin.integrations.instagram.callback')], [$q['client_id'], $q['response_type'], $q['redirect_uri']]);
        $this->assertSame(implode(',', InstagramLink::SCOPES), $q['scope']);
        $this->assertSame(40, strlen($q['state']));

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $q['state'], 'code' => 'AUTH-CODE#_']))
            ->assertRedirect(route('admin.integrations.instagram'))->assertSessionHasNoErrors();

        $c = InstagramConnection::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame(['17841400000000001', 'APP-SCOPED-1', 'rose.shop', 'BUSINESS'], [$c->ig_user_id, $c->ig_scoped_id, $c->username, $c->account_type]);
        $this->assertSame('LONG-TOKEN', $c->access_token);
        $this->assertNotSame('LONG-TOKEN', DB::table('instagram_connections')->value('access_token'), 'الرمزُ مشفَّرٌ في عموده');
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $c->token_expires_at->timestamp, 60);
        $this->assertSame([...InstagramLink::FIELDS, InstagramLink::ECHOES], $c->subscribed_fields);
        $this->assertSame($this->owner->id, (int) $c->connected_by);
        $this->assertTrue($c->usable());

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.instagram.test/oauth/access_token')
            && $r['code'] === 'AUTH-CODE' && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === self::SECRET);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.instagram.test/access_token') && str_contains($r->url(), 'grant_type=ig_exchange_token'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/v27.0/17841400000000001/subscribed_apps')
            && $r['subscribed_fields'] === 'comments,messages,messaging_postbacks,message_echoes');

        $this->assertSame(1, ActivityLog::where('subject_type', InstagramConnection::class)->where('description', 'like', '%rose.shop%')->count());

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))
            ->assertInertia(fn ($p) => $p->where('instagram.link.state', 'connected')->where('instagram.link.username', 'rose.shop')
                ->where('instagram.link.missing_permissions', [])->where('instagram.link.subscribed', true)->missing('instagram.link.access_token'));
    }

    public function test_a_field_meta_refuses_does_not_lose_the_link(): void
    {
        $this->open($this->shop);
        $tried = [];
        $this->meta['subscribe'] = function (HttpRequest $r) use (&$tried) {
            $tried[] = (string) $r['subscribed_fields'];

            return str_contains((string) $r['subscribed_fields'], 'message_echoes')
                ? Http::response(['error' => ['message' => '(#100) Invalid parameter', 'code' => 100, 'type' => 'OAuthException']], 400)
                : Http::response(['success' => true]);
        };

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $this->start(), 'code' => 'AUTH-CODE']))->assertSessionHasNoErrors();

        $c = InstagramConnection::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame(['comments,messages,messaging_postbacks,message_echoes', 'comments,messages,messaging_postbacks'], $tried);
        $this->assertSame(InstagramLink::FIELDS, $c->subscribed_fields);
        $this->assertNull($c->last_error);
        $this->assertTrue(InstagramLink::view($c)['subscribed']);
        $this->assertFalse(InstagramLink::view($c)['hears_phone'], 'الردُّ من الهاتف لا يُسمع — ويُقال للتاجر');
    }

    public function test_the_seal_is_for_one_use_by_the_one_who_pressed(): void
    {
        $other = $this->shop('محل آخر');
        $this->open($this->shop, $other);
        $stranger = $this->user($other, 'admin');

        $state = $this->start();

        // ‏‏صاحبُ متجرٍ آخر بختم هذا المتجر — لا يُقرأ رمز
        $this->actingAs($stranger)->get(route('admin.integrations.instagram.callback', ['state' => $state, 'code' => 'X']))
            ->assertSessionHasErrors('instagram');

        // ‏‏والختمُ استُعمل مرّةً (وإن أخطأ صاحبُه) — لا يعود يصلح
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $state, 'code' => 'X']))
            ->assertSessionHasErrors('instagram');
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => str_repeat('a', 40), 'code' => 'X']))
            ->assertSessionHasErrors('instagram');

        $this->assertSame([], $this->metaCalls(), 'لا نداءَ إلى Meta بختمٍ لا يصلح');
        $this->assertSame(0, InstagramConnection::count());

        // ‏‏ورفضُ الإذن في إنستقرام يُقال كما هو
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $this->start(), 'error' => 'access_denied', 'error_reason' => 'user_denied']))
            ->assertSessionHasErrors(['instagram' => __('لم يكتمل الربط — رُفض الإذن في إنستقرام.')]);
        $this->assertSame([], $this->metaCalls());
    }

    public function test_a_personal_account_or_one_linked_to_another_shop_is_refused(): void
    {
        $other = $this->shop('محل آخر');
        $this->open($this->shop);

        $this->meta['me']['account_type'] = 'PERSONAL';
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $this->start(), 'code' => 'C']))
            ->assertSessionHasErrors('instagram');
        $this->assertSame(0, InstagramConnection::count());

        $this->meta['me']['account_type'] = 'BUSINESS';
        $this->linked($other, ['ig_user_id' => '17841400000000001', 'username' => 'rose.shop']);
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $this->start(), 'code' => 'C']))
            ->assertSessionHasErrors(['instagram' => __('حساب إنستقرام هذا مربوط بمتجر آخر في أبعاد — افصله من هناك أولًا.')]);

        $this->assertSame(0, InstagramConnection::where('business_id', $this->shop->id)->count());
        $this->assertSame((int) $other->id, (int) InstagramConnection::where('ig_user_id', '17841400000000001')->value('business_id'));
        $this->assertFalse(collect(Http::recorded())->contains(fn ($p) => str_contains($p[0]->url(), '/subscribed_apps')), 'لا اشتراكَ قبل أن يُقبل');
    }

    public function test_missing_permissions_are_named_and_a_cashier_cannot_link(): void
    {
        $this->open($this->shop);
        $this->meta['token']['data'][0]['permissions'] = 'instagram_business_basic';

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.callback', ['state' => $this->start(), 'code' => 'C']));

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))
            ->assertInertia(fn ($p) => $p->where('instagram.link.missing_permissions', ['instagram_business_manage_messages', 'instagram_business_manage_comments']));

        $cashier = $this->user($this->shop, 'cashier');
        $this->actingAs($cashier)->post(route('admin.integrations.instagram.connect'))->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.integrations.instagram.disconnect'))->assertForbidden();
        $this->assertTrue(InstagramConnection::firstOrFail()->usable());
    }

    /* ═══════════ التجديد ═══════════ */

    public function test_tokens_are_refreshed_before_they_expire_and_a_revoked_one_asks_to_relink(): void
    {
        $this->open($this->shop);
        $due = $this->linked($this->shop, ['token_expires_at' => now()->addDays(10), 'refreshed_at' => now()->subDays(50)]);
        $young = $this->linked($this->shop('محل جديد'), ['token_expires_at' => now()->addDays(10), 'refreshed_at' => now()->subHours(2)]);
        $fresh = $this->linked($this->shop('محل ثالث'), ['token_expires_at' => now()->addDays(40)]);
        InstagramEvent::create(['business_id' => $this->shop->id, 'instagram_connection_id' => $due->id, 'event_key' => 'old', 'field' => 'comments', 'payload' => [], 'received_at' => now()->subDays(31)]);

        Artisan::call('instagram:refresh-tokens');

        $this->assertSame('LONG-TOKEN-2', $due->fresh()->access_token);
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $due->fresh()->token_expires_at->timestamp, 60);
        $this->assertSame('LIVE-TOKEN', $young->fresh()->access_token, 'أحدثُ من ٢٤ ساعة — لا يُجدَّد');
        $this->assertSame('LIVE-TOKEN', $fresh->fresh()->access_token, 'بقي له أربعون يومًا — لا يُسأل عنه');
        $this->assertSame(0, InstagramEvent::count(), 'الإشعاراتُ الخام بعد ثلاثين يومًا تُحذف');

        $this->meta['refresh'] = ['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]];
        $this->meta['refresh_status'] = 400;
        $due->forceFill(['token_expires_at' => now()->addDays(5), 'refreshed_at' => now()->subDays(55)])->save();

        Artisan::call('instagram:refresh-tokens');

        $this->assertSame(InstagramConnection::NEEDS_RELINK, $due->fresh()->status);
        $this->assertStringContainsString('أعد ربط الحساب', (string) $due->fresh()->last_error);
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))->assertInertia(fn ($p) => $p->where('instagram.link.state', 'needs_relink'));
    }

    /* ═══════════ الإشعارات ═══════════ */

    public function test_webhooks_are_verified_signed_stored_once_and_only_for_a_live_linked_account(): void
    {
        $this->open($this->shop);
        $c = $this->linked($this->shop, ['ig_user_id' => '17841400000000001']);

        $this->get('/webhooks/instagram?hub.mode=subscribe&hub.verify_token='.InstagramPlatform::verifyToken().'&hub.challenge=4242')->assertOk()->assertSee('4242');
        $this->get('/webhooks/instagram?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242')->assertForbidden();

        $payload = ['object' => 'instagram', 'entry' => [[
            'id' => '17841400000000001', 'time' => time(),
            'changes' => [['field' => 'comments', 'value' => ['id' => 'COMMENT-1', 'text' => 'بكم؟', 'from' => ['id' => 'IGSID-9', 'username' => 'sara'], 'media' => ['id' => 'MEDIA-1', 'media_product_type' => 'REELS']]]],
            'messaging' => [['sender' => ['id' => 'IGSID-9'], 'recipient' => ['id' => '17841400000000001'], 'timestamp' => time(), 'message' => ['mid' => 'MID-1', 'text' => 'السعر']]],
        ]]];

        $this->webhook($payload, 'forged-secret')->assertForbidden();
        $this->webhook($payload, null)->assertForbidden();
        $this->assertSame(0, InstagramEvent::count());

        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();
        $this->webhook($payload, self::META_SECRET)->assertOk();

        $this->assertSame(['comments', 'messages'], InstagramEvent::orderBy('id')->pluck('field')->all(), 'كلُّ حدثٍ مرّةً واحدة');
        $this->assertSame('بكم؟', InstagramEvent::where('field', 'comments')->first()->payload['value']['text']);
        $this->assertNotNull($c->fresh()->last_webhook_at);

        // ‏‏حسابٌ لا نعرفه، أو فصله صاحبُه، أو أُقفل إنستقرام — يُجاب ولا يُحفظ
        $payload['entry'][0]['id'] = '999';
        $payload['entry'][0]['changes'][0]['value']['id'] = 'COMMENT-2';
        $this->webhook($payload)->assertOk();
        InstagramLink::revoke($c, 'اختبار');
        $payload['entry'][0]['id'] = '17841400000000001';
        $this->webhook($payload)->assertOk();
        $this->assertSame(2, InstagramEvent::count());
    }

    /* ═══════════ إلغاءُ الإذن وحذفُ البيانات من Meta ═══════════ */

    public function test_meta_deauthorize_and_data_deletion_wipe_what_we_hold(): void
    {
        $this->open($this->shop);
        $c = $this->linked($this->shop, ['ig_user_id' => '17841400000000001', 'ig_scoped_id' => 'APP-SCOPED-1']);

        $this->post('/webhooks/instagram/deauthorize', ['signed_request' => $this->signed(['user_id' => 'APP-SCOPED-1'], 'forged')])->assertStatus(400);
        $this->assertTrue($c->fresh()->usable());

        $this->post('/webhooks/instagram/deauthorize', ['signed_request' => $this->signed(['user_id' => 'APP-SCOPED-1'])])->assertOk();
        $this->assertSame([InstagramConnection::REVOKED, null], [$c->fresh()->status, $c->fresh()->access_token]);

        InstagramEvent::create(['business_id' => $this->shop->id, 'instagram_connection_id' => $c->id, 'event_key' => 'k', 'field' => 'comments', 'payload' => ['text' => 'x'], 'received_at' => now()]);

        $res = $this->post('/webhooks/instagram/deletion', ['signed_request' => $this->signed(['user_id' => '17841400000000001'])])->assertOk();
        $code = $res->json('confirmation_code');

        $this->assertSame(route('instagram.deletion.status', $code), $res->json('url'));
        $this->assertSame(0, InstagramConnection::count());
        $this->assertSame(0, InstagramEvent::count());
        $this->assertSame(InstagramDataDeletion::COMPLETED, InstagramDataDeletion::where('confirmation_code', $code)->value('status'));
        $this->get($res->json('url'))->assertOk()->assertSee($code)->assertSee('اكتمل الحذف')->assertDontSee('محل الورد');

        // ‏‏وطلبٌ لا حسابَ له عندنا يُقيَّد ويُجاب برمزه
        $this->post('/webhooks/instagram/deletion', ['signed_request' => $this->signed(['user_id' => 'NOBODY'])])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
        $this->post('/webhooks/instagram/deletion', ['signed_request' => 'garbage'])->assertStatus(400);
    }

    public function test_the_owner_disconnects_and_the_token_is_gone(): void
    {
        $this->open($this->shop);
        $c = $this->linked($this->shop);

        $this->actingAs($this->owner)->post(route('admin.integrations.instagram.disconnect'))->assertSessionHasNoErrors();

        $this->assertSame([InstagramConnection::REVOKED, null], [$c->fresh()->status, $c->fresh()->access_token]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/'.$c->ig_user_id.'/subscribed_apps'));
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))->assertInertia(fn ($p) => $p->where('instagram.link.state', 'revoked'));
    }

    /* ═══════════ المنصّة ═══════════ */

    public function test_the_platform_writes_the_app_and_opens_it_and_the_secret_never_comes_back(): void
    {
        $platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($platform)->post(route('super-admin.instagram.credentials'), ['app_id' => 'abc', 'app_secret' => 'x'])->assertSessionHasErrors('app_id');
        $this->actingAs($platform)->post(route('super-admin.instagram.mode'), ['mode' => 'pilot', 'pilot' => (string) $this->shop->id])->assertSessionHasErrors('pilot');

        $this->actingAs($platform)->post(route('super-admin.instagram.credentials'), ['app_id' => '1234567890', 'app_secret' => self::SECRET])->assertSessionHasNoErrors();
        $this->assertNotSame(self::SECRET, Setting::whereNull('business_id')->where('key', InstagramPlatform::SECRET_KEY)->value('value'), 'السرُّ مشفَّر');
        $this->assertSame(self::SECRET, InstagramPlatform::appSecret());
        $this->assertStringNotContainsString(self::SECRET, json_encode(InstagramPlatform::state()));

        $this->actingAs($platform)->post(route('super-admin.instagram.mode'), ['mode' => 'pilot', 'pilot' => (string) $this->shop->id])->assertSessionHasNoErrors();
        $this->assertTrue(InstagramLink::available($this->shop));
        $this->assertGreaterThanOrEqual(2, ActivityLog::where('subject_type', Setting::class)->where('description', 'like', '%إنستقرام%')->count());

        $this->actingAs($this->owner)->post(route('super-admin.instagram.mode'), ['mode' => 'all'])->assertForbidden();
        $this->assertSame(InstagramPlatform::PILOT, InstagramPlatform::mode());
    }
}
