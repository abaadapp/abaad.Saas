<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppLink;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * التسجيل المدمج — الكودُ يصل، والخادمُ يُقرّر، ولا يُلصق رمزٌ ولا يُفكّ رقم.
 *
 * ═══ ما تحرسه هذه الملفّة ═══
 *
 *   · «تمّ» في المتصفّح ليست «تمّ» عندنا: لا تُكتب وصلةٌ إلّا بعد أن يُبدَّل
 *     الكود، ويُفحص الرمز، ويُطابَق الرقمُ بحساب الأعمال الذي مُنحناه.
 *   · عزلُ الشركات: تاجرٌ لا يربط رقمَ غيره ولا ينتزعه، ومعرّفُ متجره من
 *     جلسته لا من حمولته.
 *   · الإشعارُ المكرَّر لا يُحدث شيئًا، والتوقيعُ الخاطئ لا يُقرأ أصلًا.
 *   · ولا `register` في أيّ مسار — وهي الخطوةُ التي تنزع رقمَ التاجر من
 *     تطبيق واتساب للأعمال. تخطّيها هو كلُّ ما يعنيه «التعايش» عندنا.
 */
class AShopConnectsItsOwnWhatsAppWithoutPastingATokenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_id' => '1082481610822941',
            'whatsapp.app_secret' => 'test-secret-not-a-real-one',
            'whatsapp.config_id' => '2177821789438050',
            'whatsapp.api_version' => 'v26.0',
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        $this->shop = Business::create([
            'name' => 'محل ورد', 'status' => 'نشط', 'whatsapp_own_allowed' => true, 'whatsapp_enabled' => true,
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    /**
     * ردُّ ميتا على المسار الكامل — والأرقامُ كما تُعيدها هي.
     *
     * `platform_type = SMB_APP` تعني أنّ الرقم في تطبيق واتساب للأعمال —
     * وهو ما يُكتب في `coexistence`.
     *
     * @param  array<string, mixed>  $over
     */
    private function metaSays(array $over = []): void
    {
        /*
         * ═══ الاتّحاد لا الدمج — والترتيبُ هو الحارس ═══
         *
         * `Http::fake` تُطابق **أوّلَ** نمطٍ ينطبق، و`array_merge` تُلحق
         * المفاتيحَ الجديدة في الذيل — أي بعد `'*'`. فكلُّ تخصيصٍ يُكتب في
         * اختبارٍ كان يُبتلع بالنمط العامّ قبل أن يُقرأ، ويمرّ الاختبارُ
         * على سلوكٍ لم يُقصد. والاتّحاد `+` يُبقي المخصَّص أوّلًا.
         *
         * و`Http::fake` الثانية تُضيف ولا تُبدّل — فلا تُنادى مرّتين في
         * اختبارٍ واحد: كلُّ ما يُحتاج يُكتب في نداءٍ واحد.
         */
        Http::fake($over + [
            '*/oauth/access_token*' => Http::response(['access_token' => 'BISU-TOKEN-VALUE-12345'], 200),
            '*/debug_token*' => Http::response(['data' => [
                'is_valid' => true,
                'expires_at' => 0,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
                ],
            ]], 200),
            '*/WABA-1/phone_numbers*' => Http::response(['data' => [[
                'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066',
                'verified_name' => 'RIBBON', 'platform_type' => 'SMB_APP',
            ]]], 200),
            '*/WABA-1/subscribed_apps' => Http::response(['data' => []], 200),
            '*/WABA-1?*' => Http::response(['id' => 'WABA-1', 'owner_business_info' => ['id' => 'MB-9']], 200),
            /* وإخراجُ الرسائل: الردُّ التلقائيّ يمرّ به */
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200),
            '*' => Http::response(['id' => 'WABA-1'], 200),
        ]);
    }

    private function finish(array $payload = [])
    {
        return $this->post(route('admin.integrations.whatsapp.embedded.callback'), array_merge([
            'code' => 'AQD-short-lived-code-0123456789',
            'waba_id' => 'WABA-1',
            'phone_number_id' => 'PN-1',
        ], $payload));
    }

    private function connection(): ?WhatsAppConnection
    {
        return WhatsAppConnection::where('business_id', $this->shop->id)->latest('id')->first();
    }

    /* ═════════════ ١ · المسار الكامل ═════════════ */

    public function test_a_finished_signup_becomes_a_connection_only_after_the_server_verifies_it(): void
    {
        $this->metaSays();

        $this->actingAs($this->owner)->finish()->assertSessionHasNoErrors();

        $connection = $this->connection();

        $this->assertNotNull($connection);
        $this->assertSame(WhatsAppMode::OWNER_BUSINESS, $connection->owner_type);
        $this->assertSame($this->shop->id, $connection->business_id);
        $this->assertSame('WABA-1', $connection->waba_id);
        $this->assertSame('PN-1', $connection->phone_number_id);
        $this->assertSame('MB-9', $connection->meta_business_id);
        $this->assertSame('+968 9525 9066', $connection->display_phone_number);
        $this->assertSame($this->owner->id, $connection->connected_by_user_id);
        $this->assertSame(WhatsAppConnection::ACTIVE, $connection->status);
        $this->assertTrue($connection->isUsable());
        $this->assertSame(WhatsAppLink::CONNECTED, WhatsAppLink::state($connection));
    }

    /** والرمزُ يُخزَّن مشفَّرًا — ولا يخرج إلى شاشةٍ ولا إلى `toArray` */
    public function test_the_token_is_stored_encrypted_and_never_leaves_the_server(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        $raw = (string) \DB::table('whatsapp_connections')
            ->where('business_id', $this->shop->id)->value('access_token');

        $this->assertNotSame('BISU-TOKEN-VALUE-12345', $raw, 'الرمز مكتوبٌ نصًّا في القاعدة');
        $this->assertStringNotContainsString('BISU-TOKEN', $raw);
        // والقراءةُ عبر النموذج تُعيده — فالتشفيرُ تشفيرٌ لا تلف
        $this->assertSame('BISU-TOKEN-VALUE-12345', $this->connection()->access_token);
        // ولا يخرج في `toArray` الذي تسلكه خصائصُ Inertia
        $this->assertArrayNotHasKey('access_token', $this->connection()->toArray());
    }

    /** ولا سرَّ ولا رمزَ في ما يصل الشاشة */
    public function test_the_screen_never_receives_the_app_secret_or_the_token(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        $page = $this->actingAs($this->owner)
            ->get(route('admin.integrations.whatsapp'))->viewData('page');

        $json = json_encode($page, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('test-secret-not-a-real-one', $json);
        $this->assertStringNotContainsString('BISU-TOKEN-VALUE-12345', $json);
        // ومعرّفُ التطبيق وإعدادُ التسجيل يصلان — وهما ليسا سرًّا وتطلبهما ميتا في الصفحة
        $this->assertStringContainsString('2177821789438050', $json);
    }

    /** ولا خطوةَ تسجيلِ رقمٍ في أيّ مسار — هي التي تنزعه من التطبيق */
    public function test_the_number_is_never_registered_or_deregistered(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/register')
            || str_contains($request->url(), '/deregister'));
    }

    /** والرقمُ الباقي في التطبيق يُقيَّد كذلك — فتقوله الشاشة ولا تعد بما لا تعرف */
    public function test_a_number_that_stays_in_the_business_app_is_recorded_as_coexisting(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        $this->assertTrue($this->connection()->coexistence);
    }

    /** ورقمٌ عاديّ (غيرُ مؤهّلٍ للتعايش) يُربط ولا يُقال عنه إنّه في التطبيق */
    public function test_a_cloud_api_number_is_connected_but_not_marked_coexisting(): void
    {
        $this->metaSays(['*/WABA-1/phone_numbers*' => Http::response(['data' => [[
            'id' => 'PN-1', 'display_phone_number' => '+968 9525 9066', 'platform_type' => 'CLOUD_API',
        ]]], 200)]);

        $this->actingAs($this->owner)->finish()->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);
        $this->assertFalse($this->connection()->coexistence);
    }

    /* ═════════════ ٢ · الاشتراك في الإشعارات ═════════════ */

    public function test_the_app_subscribes_to_the_account_webhooks_once(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/WABA-1/subscribed_apps'));
    }

    /** واشتراكٌ قائمٌ لا يُعاد — ولا يُكسر */
    public function test_an_existing_subscription_is_left_alone(): void
    {
        $this->metaSays(['*/WABA-1/subscribed_apps' => Http::response(['data' => [
            ['whatsapp_business_api_data' => ['id' => '1082481610822941']],
        ]], 200)]);

        $this->actingAs($this->owner)->finish()->assertSessionHasNoErrors();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/subscribed_apps'));
    }

    /* ═════════════ ٣ · ما لا يُقبل ═════════════ */

    /** حسابُ أعمالٍ لم يُمنح لنا — يُردّ ولا يُربط */
    public function test_a_waba_the_token_was_not_granted_is_refused(): void
    {
        $this->metaSays();

        $this->actingAs($this->owner)->finish(['waba_id' => 'WABA-SOMEONE-ELSE'])
            ->assertSessionHasErrors('waba_id');

        $this->assertNull($this->connection());
    }

    /** ورقمٌ ليس في قائمة الحساب لا يُستبدل بأوّل ما في القائمة */
    public function test_a_phone_number_outside_the_account_is_refused_not_swapped(): void
    {
        $this->metaSays();

        $this->actingAs($this->owner)->finish(['phone_number_id' => 'PN-STRANGER'])
            ->assertSessionHasErrors();

        $this->assertNull($this->connection());
    }

    /** وتفويضٌ ناقصُ الصلاحيات لا يُكتب «متّصلًا» */
    public function test_a_token_missing_the_messaging_scope_is_refused(): void
    {
        $this->metaSays(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']]],
        ]], 200)]);

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('code');

        $this->assertNull($this->connection());
    }

    /**
     * ورمزٌ تقول ميتا إنّه غيرُ صالح لا يُكتب «متّصلًا».
     *
     * يقع حين يُسحب التفويضُ في اللحظة نفسها، أو حين يُعاد استعمالُ كودٍ
     * استُعمل. و`debug_token` تقولها صراحةً — ومن لم يقرأها كتب وصلةً
     * برمزٍ ميّت، فتقول الشاشةُ «متّصل» ولا تخرج رسالة.
     */
    public function test_a_token_meta_calls_invalid_is_refused(): void
    {
        $this->metaSays(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => false,
            'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
            ],
        ]], 200)]);

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('code');

        $this->assertNull($this->connection());
    }

    /** وكودٌ ميّت (مضى عمرُه) يُردّ برسالةٍ ولا يُكتب شيء */
    public function test_an_expired_code_is_refused(): void
    {
        $this->metaSays(['*/oauth/access_token*' => Http::response([
            'error' => ['code' => 100, 'message' => 'This authorization code has expired.'],
        ], 400)]);

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('code');

        $this->assertNull($this->connection());
    }

    /* ═════════════ ٤ · الإذن والعزل ═════════════ */

    /**
     * موظّفٌ ليس مديرًا لا يربط — ويُردّ قبل أن يُقرأ الكود.
     *
     * وبابان يحرسانه: `CheckAbility` يردّه بـ٤٠٣ لأنّ قسم التكاملات ليس
     * له، و`mayManage` في المتحكّم يردّه لو مُنح القسم يومًا. والاختبارُ
     * يقيس الأوّل هنا والثاني في الحالة التالية — وشرطٌ في موضعٍ واحد
     * يُخفَّف يومًا بلا أن يلحظه أحد.
     */
    public function test_a_non_admin_employee_cannot_connect(): void
    {
        $this->metaSays();

        $this->actingAs($this->cashier)->finish()->assertForbidden();

        $this->assertNull($this->connection());
        Http::assertNothingSent();
    }

    /**
     * والمديرُ يملك القسمَ ولا يملك التفويض — يردّه الحارسُ الثاني.
     *
     * `manager` له قسمُ التكاملات (يضبط مفتاح الخرائط مثلًا)، فيمرّ من
     * `CheckAbility`. والتفويضُ باسم النشاط عند ميتا شيءٌ آخر: رمزٌ يُرسل
     * برقم المحلّ ويقرأ رسائل زبائنه ويبقى بعد أن يترك المديرُ عمله.
     */
    public function test_a_manager_holds_the_section_but_still_cannot_authorise(): void
    {
        $this->metaSays();

        $manager = User::create([
            'business_id' => $this->shop->id, 'name' => 'مدير', 'email' => 'm@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        // يمرّ من الصلاحيات — والقسمُ له
        $this->assertTrue($manager->allows('integrations'));

        $this->actingAs($manager)->finish()->assertSessionHasErrors('code');

        $this->assertNull($this->connection());
        Http::assertNothingSent();
    }

    /** وكذلك الردُّ التلقائيّ: المديرُ يقرؤه ولا يُشغّله */
    public function test_a_manager_cannot_turn_on_the_auto_reply(): void
    {
        $manager = User::create([
            'business_id' => $this->shop->id, 'name' => 'مدير', 'email' => 'm2@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($manager)->post(route('admin.integrations.whatsapp.autoReply'), [
            'enabled' => true, 'ar' => 'أهلًا', 'cooldown_hours' => 12,
        ])->assertSessionHasErrors();

        $this->assertFalse(WhatsAppAutoReply::settings($this->shop->id)['enabled']);
    }

    /** ومن لم تُمنح له الميزةُ لا يربط */
    public function test_a_shop_without_the_entitlement_cannot_connect(): void
    {
        $this->shop->update(['whatsapp_own_allowed' => false]);
        $this->metaSays();

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('code');

        $this->assertNull($this->connection());
        Http::assertNothingSent();
    }

    /**
     * ومعرّفُ المتجر من الجلسة لا من الحمولة.
     *
     * `business_id` الذي تُعيده ميتا هو معرّفُ **حساب الأعمال عندها** — لا
     * معرّفُ متجرٍ عندنا. ومن أرسله ظانًّا أنّه يُبدّل الوجهة لا يُبدّل شيئًا.
     */
    public function test_a_forged_business_id_in_the_payload_changes_nothing(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط', 'whatsapp_own_allowed' => true]);
        $this->metaSays();

        $this->actingAs($this->owner)->finish(['business_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame($this->shop->id, $this->connection()->business_id);
        $this->assertSame(0, WhatsAppConnection::where('business_id', $other->id)->count());
    }

    /** ورقمٌ يملكه متجرٌ آخر لا يُنتزع منه */
    public function test_a_number_already_bound_to_another_shop_cannot_be_claimed(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $other->id,
            'phone_number_id' => 'PN-1', 'access_token' => 'other-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);

        $this->metaSays();

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('phone_number_id');

        $this->assertNull($this->connection());
        $this->assertSame($other->id, WhatsAppConnection::where('phone_number_id', 'PN-1')->value('business_id'));
    }

    /* ═════════════ ٥ · بانتظار ميتا ═════════════ */

    /**
     * حسابٌ بلا رقمٍ بعد — يُحفظ التفويضُ ولا يُقال «فشل» ولا «متّصل».
     *
     * وهي حالُ مسارِ تطبيق واتساب للأعمال: يُعاد `waba_id` أوّلًا ويظهر
     * الرقمُ بعد دقائق.
     */
    public function test_an_account_with_no_number_yet_is_kept_as_connecting(): void
    {
        $this->metaSays(['*/WABA-1/phone_numbers*' => Http::response(['data' => []], 200)]);

        $this->actingAs($this->owner)->finish(['phone_number_id' => ''])->assertSessionHasNoErrors();

        $connection = $this->connection();

        $this->assertSame(WhatsAppConnection::PENDING, $connection->status);
        $this->assertNull($connection->phone_number_id);
        $this->assertSame(WhatsAppLink::CONNECTING, WhatsAppLink::state($connection));
        // ولا يُرسل منها شيء
        $this->assertFalse($connection->isUsable());
    }

    /* ═════════════ ٦ · انتهاء التفويض ═════════════ */

    public function test_a_token_that_expires_soon_asks_for_reauthorisation_but_keeps_sending(): void
    {
        $this->metaSays(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'expires_at' => now()->addDays(5)->timestamp,
            'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
            ],
        ]], 200)]);

        $this->actingAs($this->owner)->finish();

        $connection = $this->connection();

        $this->assertNotNull($connection->token_expires_at);
        $this->assertSame(WhatsAppLink::REAUTH, WhatsAppLink::state($connection));
        $this->assertSame(7, WhatsAppLink::alert($connection), 'عتبةُ التنبيه الخطأ');
        // ويبقى يُرسل: التحذيرُ قبل الانقطاع ليس انقطاعًا
        $this->assertTrue($connection->isUsable());
    }

    public function test_an_expired_token_reads_as_expired_and_stops_sending(): void
    {
        $this->metaSays(['*/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'expires_at' => now()->subDay()->timestamp,
            'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
            ],
        ]], 200)]);

        $this->actingAs($this->owner)->finish();

        $connection = $this->connection();

        $this->assertSame(WhatsAppLink::EXPIRED, WhatsAppLink::state($connection));
        $this->assertFalse($connection->isUsable());
    }

    /** ورمزٌ لا ينتهي لا يُحذَّر عنه — و`expires_at = 0` ليست سنة ١٩٧٠ */
    public function test_a_never_expiring_token_raises_no_alarm(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        $this->assertNull($this->connection()->token_expires_at);
        $this->assertNull(WhatsAppLink::alert($this->connection()));
        $this->assertSame(WhatsAppLink::CONNECTED, WhatsAppLink::state($this->connection()));
    }

    /* ═════════════ ٧ · الفصل — محلّيٌّ لا غير ═════════════ */

    public function test_disconnecting_never_touches_meta(): void
    {
        $this->metaSays();
        $this->actingAs($this->owner)->finish();

        Http::fake();

        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'))
            ->assertSessionHasNoErrors();

        // الصفُّ يبقى ويُعطَّل — ولا نداءَ إلى ميتا
        $this->assertSame(WhatsAppConnection::INACTIVE, $this->connection()->status);
        $this->assertSame('PN-1', $this->connection()->phone_number_id);
        Http::assertNothingSent();
    }

    /* ═════════════ ٨ · اختبار الاتصال ═════════════ */

    public function test_the_test_button_asks_meta_and_writes_what_it_hears(): void
    {
        $this->metaSays(['*/PN-1?*' => Http::response([
            'display_phone_number' => '+968 9525 9066', 'verified_name' => 'RIBBON',
        ], 200)]);
        $this->actingAs($this->owner)->finish();

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))
            ->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppConnection::ACTIVE, $this->connection()->status);
    }

    public function test_a_number_that_does_not_answer_is_written_as_an_error(): void
    {
        $this->metaSays(['*/PN-1?*' => Http::response([
            'error' => ['code' => 190, 'message' => 'Invalid OAuth access token.'],
        ], 401)]);
        $this->actingAs($this->owner)->finish();

        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.test'))
            ->assertSessionHasErrors('code');

        $connection = $this->connection();

        $this->assertSame(WhatsAppConnection::ERROR, $connection->status);
        $this->assertSame('190', $connection->last_error_code);
        $this->assertSame(WhatsAppLink::ERROR, WhatsAppLink::state($connection));
        // ولا شيء من الاعتماد في ما يُقيَّد
        $this->assertStringNotContainsString('BISU-TOKEN', (string) $connection->last_error_message);
    }

    /* ═════════════ ٩ · الإشعارات الواردة ═════════════ */

    private function connected(array $over = []): WhatsAppConnection
    {
        $this->metaSays($over);
        $this->actingAs($this->owner)->finish();

        return $this->connection()->fresh();
    }

    /** @param array<string, mixed> $value */
    private function webhook(array $value, bool $sign = true)
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA-1', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]];

        $body = json_encode($payload);
        $headers = $sign
            ? ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('whatsapp.app_secret'))]
            : [];

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ), $body);
    }

    /** @return array<string, mixed> */
    private function inbound(string $wamid = 'wamid.IN-1'): array
    {
        return [
            'metadata' => ['phone_number_id' => 'PN-1'],
            'messages' => [[
                'id' => $wamid, 'from' => '96891234567', 'type' => 'text',
                'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'مرحبا'],
            ]],
        ];
    }

    /** التوقيعُ الخاطئ لا يُقرأ منه حرف */
    public function test_a_webhook_with_a_bad_signature_is_refused_and_writes_nothing(): void
    {
        $this->connected();

        $this->webhook($this->inbound(), sign: false)->assertStatus(403);

        $this->assertSame(0, WhatsAppInboundMessage::count());
    }

    /** وآخرُ إشعارٍ يُختم — فيُعرف صمتُ الاشتراك */
    public function test_a_valid_webhook_stamps_the_connection(): void
    {
        $connection = $this->connected();
        $this->assertNull($connection->last_webhook_at);

        $this->webhook($this->inbound())->assertOk();

        $this->assertNotNull($this->connection()->last_webhook_at);
    }

    /** والوارد يُقيَّد مرّةً — وإعادةُ ميتا للإشعار لا تُضاعف شيئًا */
    public function test_the_same_inbound_message_is_processed_once(): void
    {
        $this->connected();

        $this->webhook($this->inbound())->assertOk();
        $this->webhook($this->inbound())->assertOk();
        $this->webhook($this->inbound())->assertOk();

        $this->assertSame(1, WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->count());
    }

    /** ورسالةُ متجرٍ لا تُكتب في دفتر متجرٍ آخر */
    public function test_an_inbound_message_is_filed_under_the_shop_that_owns_the_number(): void
    {
        $this->connected();
        $this->webhook($this->inbound())->assertOk();

        $this->assertSame($this->shop->id, WhatsAppInboundMessage::first()->business_id);
    }

    /* ═════════════ ١٠ · الردّ التلقائيّ ═════════════ */

    public function test_auto_reply_is_off_until_the_owner_turns_it_on(): void
    {
        $this->connected();

        $this->webhook($this->inbound())->assertOk();

        $row = WhatsAppInboundMessage::first();

        $this->assertSame('skipped', $row->reply_status);
        $this->assertSame('disabled', $row->reply_reason);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages'));
    }

    public function test_a_turned_on_auto_reply_answers_once_and_then_holds_its_tongue(): void
    {
        $this->connected();
        WhatsAppAutoReply::save($this->shop->id, [
            'enabled' => true, 'ar' => 'أهلًا بك', 'en' => 'Welcome', 'cooldown_hours' => 12,
        ]);

        $this->webhook($this->inbound('wamid.IN-1'))->assertOk();
        $this->webhook($this->inbound('wamid.IN-2'))->assertOk();

        $this->assertSame('sent', WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->value('reply_status'));
        $this->assertSame('cooldown', WhatsAppInboundMessage::where('wamid', 'wamid.IN-2')->value('reply_reason'));

        /*
         * ونداءُ إخراجٍ واحدٌ لا اثنان — الثانيةُ لم تخرج أصلًا.
         *
         * ويُعدّ نداءُ الإخراج وحدَه لا كلُّ ما خرج: نداءاتُ الربط الستّة
         * مسجّلةٌ معه، و`assertSentCount` عليها جميعًا تُصبح عدّادًا لخطوات
         * الربط — يسقط يوم يُضاف نداءُ تحقّقٍ سابع ولا علاقة له بالردّ.
         */
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/messages'))->count());
    }

    /**
     * وثلاثُ رسائلَ في حمولةٍ واحدة يُردّ عليها مرّةً.
     *
     * الحارسُ فوقه يُرسل إشعارين منفصلين، وميتا لا تفعل ذلك دائمًا: ما وصل
     * في ثوانٍ يُجمع في `value.messages` واحدة. والتهدئةُ تُقرأ من صفٍّ
     * مكتوب، فلو عولجت الحمولةُ دفعةً واحدةً قبل أن يُختم الأوّل لَخرجت
     * ثلاثةُ ردودٍ متطابقة — وهو ما بُنيت التهدئةُ لمنعه.
     */
    public function test_three_messages_in_one_payload_are_answered_once(): void
    {
        $this->connected();
        WhatsAppAutoReply::save($this->shop->id, [
            'enabled' => true, 'ar' => 'أهلًا بك', 'en' => 'Welcome', 'cooldown_hours' => 12,
        ]);

        // ميتا تجمع ما وصل في حمولةٍ واحدة — «مرحبا» ثمّ «أبغى باقة» ثمّ «كم سعرها»
        $this->webhook([
            'metadata' => ['phone_number_id' => 'PN-1'],
            'messages' => [
                ['id' => 'wamid.B1', 'from' => '96891234567', 'type' => 'text',
                    'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'مرحبا']],
                ['id' => 'wamid.B2', 'from' => '96891234567', 'type' => 'text',
                    'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'أبغى باقة']],
                ['id' => 'wamid.B3', 'from' => '96891234567', 'type' => 'text',
                    'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'كم سعرها']],
            ],
        ])->assertOk();

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/messages'))->count();

        $this->assertSame(1, $sent, 'خرجت '.$sent.' ردودٍ على حمولةٍ واحدة');
    }

    /** والنصُّ بلغة الزبون إن عُرفت — لا بلغة حروف رسالته */
    public function test_the_reply_speaks_the_customers_language(): void
    {
        $connection = $this->connected();
        Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبون', 'phone' => '+968 9123 4567', 'language' => 'en',
        ]);

        $settings = ['ar' => 'أهلًا', 'en' => 'Welcome', 'branch_id' => null];

        $this->assertSame('Welcome', WhatsAppAutoReply::text($connection, $settings, '96891234567'));
        $this->assertSame('أهلًا', WhatsAppAutoReply::text($connection, $settings, '96899999999'));
    }

    /** ولا يُشعَل بلا نصّ: مفتاحٌ فوق حقلٍ فارغ رسالةٌ بيضاء */
    public function test_auto_reply_cannot_be_turned_on_without_text(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.autoReply'), [
            'enabled' => true, 'ar' => '', 'en' => '', 'cooldown_hours' => 12,
        ])->assertSessionHasErrors('ar');

        $this->assertFalse(WhatsAppAutoReply::settings($this->shop->id)['enabled']);
    }

    /** وموظّفٌ ليس مديرًا لا يُشغّله */
    public function test_a_non_admin_cannot_change_the_auto_reply(): void
    {
        $this->actingAs($this->cashier)->post(route('admin.integrations.whatsapp.autoReply'), [
            'enabled' => true, 'ar' => 'أهلًا', 'cooldown_hours' => 12,
        ])->assertForbidden();

        $this->assertFalse(WhatsAppAutoReply::settings($this->shop->id)['enabled']);
    }

    /** وفرعُ متجرٍ آخر لا يُحفظ ولو أُرسل معرّفُه */
    public function test_a_branch_from_another_shop_is_not_saved(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);

        WhatsAppAutoReply::save($this->shop->id, [
            'enabled' => true, 'ar' => 'أهلًا', 'branch_id' => $theirs->id, 'cooldown_hours' => 12,
        ]);

        $this->assertNull(WhatsAppAutoReply::settings($this->shop->id)['branch_id']);
    }

    /* ═════════════ ١١ · الإعداد الناقص ═════════════ */

    public function test_without_server_configuration_the_door_is_shut_and_the_screen_says_so(): void
    {
        config(['whatsapp.config_id' => null]);

        $this->assertFalse(WhatsAppEmbeddedSignup::configured());

        $this->actingAs($this->owner)->finish()->assertSessionHasErrors('code');

        $page = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->viewData('page');

        $this->assertFalse($page['props']['automation']['embedded']['configured']);
    }
}
