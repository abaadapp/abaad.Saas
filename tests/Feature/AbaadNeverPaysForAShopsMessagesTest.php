<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppBilling;
use App\Models\WhatsAppBillingEvent;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSenderAdoption;
use App\Support\Demo;
use App\Support\MetaWhatsAppClient;
use App\Support\OrderStatus;
use App\Support\TenantTables;
use App\Support\WhatsAppBillingLock;
use App\Support\WhatsAppCampaignTemplates;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppSender;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesMetaBilling;
use Tests\TestCase;

/**
 * ‏‏أبعاد لا تدفع عن تاجر — لا تخرج رسالةٌ من رقمٍ رُبط عبر تطبيقها قبل أن تثبت جهةُ رسومه.
 *
 * ═══ ما تحرسه هذه الملفّة ═══
 *
 *   · من يشمله القفل ومن لا يشمله (RIBBON وكلُّ تطبيقٍ خاصّ، وما كان قبله).
 *   · أنّ كلَّ باب إرسالٍ مغلقٌ قبل الإثبات — ولا يُفتح بطلبٍ يُكتب ولا بمهمّةٍ مجدولة ولا بعمودٍ قديم.
 *   · أنّ الملكيّة لا تُثبت، وأنّ غياب المعلومة ليس نجاحًا، وأنّ ما تنقضه Meta يقف.
 *   · وأنّ ما وقف لا يخرج من رقم أبعاد ولا يُخصم من رصيدٍ اشتُري منها.
 *
 * والتجربةُ وقفلُ النقل وتسعيرُ الرسائل في `TheLaunchWaitsForARealChargeTest`.
 */
class AbaadNeverPaysForAShopsMessagesTest extends TestCase
{
    use FakesMetaBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMeta();
        // ‏‏متجرُ التجربة يربط عبر تطبيق أبعاد — والبقيّةُ على تطبيقاتها
        $this->platform('whatsapp_merchant_link_pilot', (string) $this->shop->id);
    }

    /* ═════════════ أدوات ═════════════ */

    private function link(?User $owner = null, string $waba = '1001', string $phone = 'PN-1')
    {
        $owner ??= $this->owner;
        $this->platform('whatsapp_merchant_link_pilot', implode(',', Business::pluck('id')->all()));

        return $this->actingAs($owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-'.$waba, 'waba_id' => $waba, 'phone_number_id' => $phone,
        ]);
    }

    private function c(?Business $shop = null): WhatsAppConnection
    {
        return WhatsAppConnections::forBusiness((int) ($shop ?? $this->shop)->id);
    }

    private function billing(?Business $shop = null): ?WhatsAppBilling
    {
        return WhatsAppBilling::where('business_id', ($shop ?? $this->shop)->id)->orderByDesc('id')->first();
    }

    /** رُبط وصار على رقمه — وقوالبُه معتمدة */
    private function linkedOnOwn(): WhatsAppConnection
    {
        $this->link()->assertSessionHasNoErrors();
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->approveTemplates($this->shop);
        $this->calls = [];

        return $this->c();
    }

    private function approveTemplates(Business $shop): void
    {
        WhatsAppTemplates::seedBusinessDefaults($shop->id);
        DB::table('whatsapp_template_mappings')->where('business_id', $shop->id)->update(['meta_status' => 'APPROVED', 'enabled' => true]);
    }

    private function review(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->super)->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id), $over + [
            'evidence_kind' => 'payment_settings', 'evidence_reference' => 'صورة 25-11-2026', 'evidence_waba_id' => '1001',
            'evidence_checked' => true, 'evidence_owner' => true, 'evidence_card' => true,
        ]);
    }

    private function chat(string $phone = 'PN-1'): WhatsAppChat
    {
        return WhatsAppChat::create([
            'business_id' => $this->shop->id, 'phone_number_id' => $phone, 'source_mode' => WhatsAppMode::BUSINESS_OWN, 'customer_phone' => '96891234567',
            'last_inbound_at' => now(), 'last_message_at' => now(), 'last_direction' => 'in',
        ]);
    }

    private function inbound(string $text = 'مرحبًا'): void
    {
        $this->webhook(['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PN-1'],
            'messages' => [['id' => 'wamid.IN.'.uniqid(), 'from' => '96895556666', 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text]]]]])->assertOk();
    }

    /** @return array<string, string> */
    private function bell(User $user): array
    {
        $this->actingAs($user);

        return collect(Demo::notificationFeed()['items'])->mapWithKeys(fn ($n) => [$n['key'] => (string) $n['text']])->all();
    }

    private function tick(): void
    {
        WhatsAppSenderAdoption::where('state', 'checking')->update(['next_check_at' => now()->subSecond()]);
        $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
    }

    /* ═════════════ من يشمله القفل ═════════════ */

    public function test_a_number_linked_through_abaads_app_is_linked_and_locked(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $c = $this->c();

        $this->assertFalse($c->billing_exempt);
        $this->assertTrue($c->isConnected(), 'مربوطٌ فعلًا');
        $this->assertFalse($c->isUsable(), 'ولا يُرسل');
        $this->assertSame(['unverified', null], [$this->billing()->state, $this->billing()->code], 'وملكيّةُ التاجر لا تُثبت شيئًا');
        $this->assertSame('MB-SHOP', $this->billing()->owner_id);
        $this->assertSame(WhatsAppLink::BILLING_UNPROVEN, WhatsAppLink::state($c));
        $this->assertSame(WhatsAppBillingLock::UNPROVEN, WhatsAppBillingLock::stop($c));
    }

    public function test_the_manual_form_and_the_client_link_are_locked_too(): void
    {
        $this->launchWhatsAppForAll();
        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.connect'), ['waba_id' => '1001', 'phone_number_id' => 'PN-1', 'access_token' => 'pasted-token-value-01234567891001'])->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->billing_exempt);
        $this->assertFalse($this->c()->isUsable());

        [$two, $hisOwner] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $two->id))->assertSessionHasNoErrors();
        $url = session('whatsappClientLink')['url'];
        auth()->logout();
        $this->get($url);
        $this->post(route('clientLink.connect'), ['code' => 'AQD-short-lived-code-1002', 'waba_id' => '1002', 'phone_number_id' => 'PN-2', 'shop_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertFalse($this->c($two)->billing_exempt);
        $this->assertFalse($this->c($two)->isUsable());
        $this->assertNotNull($hisOwner);
    }

    /** ‏‏وصفٌّ لم يُكتب فيه العمودُ — من أيّ بابٍ غدًا — مقفل */
    public function test_a_row_written_without_the_column_is_locked(): void
    {
        $id = DB::table('whatsapp_connections')->insertGetId([
            'owner_type' => 'business', 'business_id' => $this->shop->id, 'provider' => 'meta_cloud', 'purpose' => 'notifications', 'waba_id' => '1001',
            'phone_number_id' => 'PN-1', 'access_token' => encrypt('TOKEN', false), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(WhatsAppBillingLock::applies(WhatsAppConnection::find($id)));
        $this->assertFalse(WhatsAppConnection::find($id)->isUsable());
        // ‏‏ولو قُرئ الصفُّ بأعمدةٍ بعينها
        $this->assertTrue(WhatsAppBillingLock::applies(WhatsAppConnection::query()->select(['id', 'owner_type', 'business_id', 'waba_id', 'link_type'])->find($id)));
    }

    private function legacy(array $over = []): WhatsAppConnection
    {
        return WhatsAppConnection::create($over + [
            'owner_type' => 'business', 'business_id' => $this->shop->id, 'provider' => 'meta_cloud', 'purpose' => 'notifications', 'waba_id' => '1001',
            'phone_number_id' => 'PN-1', 'display_phone_number' => '+968 9000 0001', 'access_token' => 'TOKEN-FOR-1001', 'status' => 'active', 'connected_at' => now(), 'billing_exempt' => true,
        ]);
    }

    public function test_a_link_made_before_the_lock_keeps_sending_exactly_as_it_did(): void
    {
        $c = $this->legacy();
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->approveTemplates($this->shop);

        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing());
        $call = array_values(array_filter($this->calls, fn ($x) => str_ends_with($x['url'], '/messages')))[0];
        $this->assertArrayNotHasKey('messaging_account_id', $call['data'], 'ولا يُضاف إلى رسالته شيء');
        $this->assertNull($this->billing(), 'ولا يُفتح له صفٌّ ولا يُسأل عنه');

        // ‏‏وردُّ دفعٍ أو مالكٌ تبدّل لا يوقفه القفل
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->meta['send_error'] = [];
        $this->webhook(['field' => 'account_update', 'value' => ['event' => 'PARTNER_ADDED', 'waba_info' => ['waba_id' => '1001', 'owner_business_id' => 'MB-OTHER', 'solution_partner_business_ids' => ['MB-X']]]])->assertOk();
        $this->assertTrue($c->fresh()->isUsable());
        $this->assertSame('unverified', WhatsAppBillingLock::stateOf($c->fresh()), 'ويُقال إنّه لم يُتحقَّق منه');
        $this->assertNull(WhatsAppBillingLock::stop($c->fresh()));
    }

    public function test_it_stays_exempt_when_it_links_the_same_account_and_number_again_and_only_then(): void
    {
        $c = $this->legacy();
        $this->link()->assertSessionHasNoErrors();
        $this->assertTrue($c->fresh()->billing_exempt, 'عاد إلى حسابه ورقمه');
        $this->assertTrue($c->fresh()->isUsable());

        // ‏‏وحسابٌ آخر لم تثبت رسومُه لا يحلّ محلَّ رقمٍ يُرسل اليوم
        $this->link(null, '1002', 'PN-2')->assertSessionHasErrors('phone_number_id');
        $this->assertSame(['1001', 'PN-1', true], [$c->fresh()->waba_id, $c->fresh()->phone_number_id, $c->fresh()->isUsable()]);

        // ‏‏وبعد أن يفصله يربط غيرَه — مقفلًا
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->link(null, '1002', 'PN-2')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->billing_exempt);
        $this->assertFalse($this->c()->isUsable());
    }

    /** ‏‏والرقمُ نفسُه في حسابٍ آخر ليس «عاد إلى حسابه»: جهةُ الرسوم جهةُ الحساب لا الرقم */
    public function test_the_same_number_in_another_account_is_a_new_link(): void
    {
        $c = $this->legacy();
        $this->meta['numbers']['1002'] = 'PN-1';
        $this->link(null, '1002', 'PN-1')->assertSessionHasErrors('phone_number_id');
        $this->assertSame(['1001', true], [$c->fresh()->waba_id, $c->fresh()->isUsable()]);

        $c->update(['status' => WhatsAppConnection::EXPIRED]);
        $this->link(null, '1002', 'PN-1')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->billing_exempt);
        $this->assertFalse($this->c()->isUsable());
    }

    /** ‏‏وإثباتُ حسابٍ لا يُمرّر حسابًا آخر فوق رقمٍ يُرسل */
    public function test_a_proof_of_one_account_does_not_let_another_replace_a_sending_number(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());

        $this->link(null, '1002', 'PN-2')->assertSessionHasErrors('phone_number_id');
        $this->assertSame(['1001', 'PN-1', true], [$this->c()->waba_id, $this->c()->phone_number_id, $this->c()->isUsable()]);

        // ‏‏وحسابُه نفسُه يُعاد ربطُه
        $this->link()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());
    }

    /** ‏‏RIBBON وكلُّ تطبيقٍ خاصّ: لا صفَّ ولا نداءَ ولا حقلَ يُضاف ولا إيقاف */
    public function test_a_shop_on_its_own_meta_app_is_untouched_by_the_lock(): void
    {
        $c = WhatsAppConnection::create([
            'owner_type' => 'business', 'business_id' => $this->shop->id, 'provider' => 'meta_cloud', 'purpose' => 'notifications', 'link_type' => WhatsAppOwnApp::TYPE,
            'waba_id' => '1001', 'phone_number_id' => 'PN-1', 'access_token' => 'HIS-TOKEN', 'token_app_id' => '555', 'app_secret' => 'HIS-SECRET', 'webhook_key' => str_repeat('k', 40),
            'billing_state' => WhatsAppOwnApp::BILLING_CONFIRMED, 'billing_evidence' => ['waba_id' => '1001', 'reviewed_at' => now()->toIso8601String()],
            'status' => 'active', 'connected_at' => now(), 'billing_exempt' => false,
        ]);
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        $this->approveTemplates($this->shop);
        $before = (array) DB::table('whatsapp_connections')->where('id', $c->id)->first();

        $this->assertFalse(WhatsAppBillingLock::applies($c));
        $this->assertTrue($c->isUsable());
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing());

        // ‏‏ولا يمسّه ردُّ دفعٍ، ولا فحصٌ مجدول، ولا مراجعةٌ من باب القفل، ولا تجربة
        $this->calls = [];
        WhatsAppBillingLock::observe($c, '131042');
        WhatsAppBillingLock::accountUpdate('1001', ['event' => 'PARTNER_ADDED', 'waba_info' => ['owner_business_id' => 'MB-X', 'solution_partner_business_ids' => ['MB-Y']]]);
        $this->tick();
        $this->assertFalse(WhatsAppBillingLock::reopen($c, $this->super, 'سبب'));
        $this->assertFalse(WhatsAppBillingLock::request($c, $this->owner)['ok']);
        $this->assertNull(WhatsAppBillingLock::read($c));
        $this->assertSame([], $this->calls, 'ولا نداءَ إلى Meta');
        $this->assertNull($this->billing());
        $this->assertSame($before, (array) DB::table('whatsapp_connections')->where('id', $c->id)->first(), 'وصفُّه كما كان حرفًا بحرف');
        $this->assertSame('proven', WhatsAppBillingLock::stateOf($c->fresh()), 'وحالُه تُقرأ من مراجعته هو');
        $this->assertNull(WhatsAppBillingLock::merchantView($this->shop->fresh()));
        $this->assertNull(WhatsAppBillingLock::platformView($c->fresh()));
    }

    public function test_the_migration_exempts_what_sent_before_it_and_not_what_the_new_switch_adopted(): void
    {
        $old = $this->legacy();
        [$two] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $new = $this->legacy(['business_id' => $two->id, 'waba_id' => '1002', 'phone_number_id' => 'PN-2']);
        WhatsAppSenderAdoption::create(['business_id' => $two->id, 'whatsapp_connection_id' => $new->id, 'phone_number_id' => 'PN-2', 'via' => 'embedded', 'state' => 'adopted', 'adopted_at' => now()]);

        $migration = require database_path('migrations/2026_11_30_090000_abaad_never_pays_for_a_shops_messages.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('whatsapp_connections', 'billing_exempt'));
        $migration->up();

        $this->assertTrue((bool) DB::table('whatsapp_connections')->where('id', $old->id)->value('billing_exempt'));
        $this->assertFalse((bool) DB::table('whatsapp_connections')->where('id', $new->id)->value('billing_exempt'), 'ومن اعتُمد آليًّا مقفلٌ حتى يثبت');
        $this->assertFalse((bool) DB::table('whatsapp_connections')->where('owner_type', 'platform')->value('billing_exempt'));
    }

    public function test_rolling_back_never_drops_the_record_of_who_proved_what(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $migration = require database_path('migrations/2026_11_30_090000_abaad_never_pays_for_a_shops_messages.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_none_of_it_comes_back_from_a_shops_backup(): void
    {
        foreach (['whatsapp_billings', 'whatsapp_billing_events', 'whatsapp_billing_trials', 'whatsapp_message_prices'] as $table) {
            $this->assertArrayHasKey($table, TenantTables::NOT_MINE);
            $this->assertNotContains($table, TenantTables::ORDER);
        }
    }

    /* ═════════════ كلُّ بابٍ مغلقٌ قبل الإثبات ═════════════ */

    public function test_a_new_shop_on_its_own_number_sends_nothing_until_it_is_proven(): void
    {
        $this->linkedOnOwn();

        $this->assertNull(WhatsAppConnections::resolve($this->shop->fresh()));
        $this->order()->update(['status' => OrderStatus::READY]);
        $row = WhatsAppMessage::latest('id')->first();
        $this->assertSame([WhatsAppStatus::SKIPPED, WhatsAppStatus::SKIP_BILLING], [$row->status, $row->error_code]);
        $this->assertStringContainsString('جهة رسومه', WhatsAppStatus::reason($row->error_code));
        $this->assertSame([], $this->outgoing(), 'ولا من رقم أبعاد');
    }

    public function test_a_shop_on_abaads_number_stays_there_and_is_never_switched(): void
    {
        $this->link()->assertSessionHasNoErrors();
        for ($i = 0; $i < 6; $i++) {
            $this->tick();
        }
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        $this->assertSame(['checking', WhatsAppBillingLock::UNPROVEN], [WhatsAppSenderAdoption::latest('id')->first()->state, WhatsAppSenderAdoption::latest('id')->first()->code]);

        // ‏‏ولا تختاره يدُ أحد: لا التاجرُ ولا الفريق — ولو كُتب عمودٌ قديم
        DB::table('whatsapp_connections')->where('id', $this->c()->id)->update(['billing_state' => WhatsAppOwnApp::BILLING_CONFIRMED]);
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors(['mode' => WhatsAppBillingLock::says(WhatsAppBillingLock::UNPROVEN)['text']]);
        $this->actingAs($this->super)->put(route('super-admin.businesses.whatsapp.update', $this->shop->id), ['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN])->assertSessionHasErrors('whatsapp_mode');
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
    }

    /** @return array<string, array{0: string}> */
    public static function doors(): array
    {
        return collect(['القالب', 'النص', 'التفاعلي', 'الملف', 'الطابور', 'الصندوق', 'الرد الآلي', 'الإيقاف', 'الحملات'])->mapWithKeys(fn ($d) => [$d => [$d]])->all();
    }

    #[DataProvider('doors')]
    public function test_no_door_sends_before_proof(string $door): void
    {
        $c = $this->linkedOnOwn();

        match ($door) {
            'القالب' => $this->assertSame(WhatsAppBillingLock::UNPROVEN, MetaWhatsAppClient::sendTemplate($c, '96891234567', 't', 'ar')['code']),
            'النص' => $this->assertSame(WhatsAppBillingLock::UNPROVEN, MetaWhatsAppClient::sendText($c, '96891234567', 'أهلًا')['code']),
            'التفاعلي' => $this->assertSame(WhatsAppBillingLock::UNPROVEN, MetaWhatsAppClient::sendInteractive($c, '96891234567', ['type' => 'button'])['code']),
            'الملف' => $this->assertSame(WhatsAppBillingLock::UNPROVEN, MetaWhatsAppClient::sendMedia($c, '96891234567', 'image', 'M1')['code']),
            'الطابور' => (new SendWhatsAppMessage(WhatsAppMessage::create([
                'business_id' => $this->shop->id, 'whatsapp_connection_id' => $c->id, 'source_mode' => WhatsAppMode::BUSINESS_OWN, 'event_type' => 'order_ready', 'direction' => 'outbound',
                'recipient_phone' => '96891234567', 'template_name' => 'order_ready', 'dedupe_key' => 'forged', 'status' => WhatsAppStatus::QUEUED, 'quota_consumed' => false, 'queued_at' => now(),
            ])->id))->handle(),
            'الصندوق' => $this->assertStringContainsString('جهة رسوم', (string) WhatsAppInbox::check($this->chat())['reason']),
            'الرد الآلي' => $this->inbound(),
            'الإيقاف' => $this->inbound('إيقاف'),
            'الحملات' => $this->assertNull(WhatsAppCampaignTemplates::connection($this->shop->fresh())),
        };

        $this->assertSame([], $this->outgoing(), $door.' أخرج رسالة');

        if ($door === 'الرد الآلي') {
            $this->assertSame(['skipped', WhatsAppBillingLock::UNPROVEN], [WhatsAppInboundMessage::first()->reply_status, WhatsAppInboundMessage::first()->reply_reason]);
        }
        if ($door === 'الطابور') {
            $this->assertSame([WhatsAppStatus::SKIPPED, WhatsAppStatus::SKIP_BILLING], array_values(WhatsAppMessage::latest('id')->first()->only(['status', 'error_code'])));
        }
    }

    public function test_what_customers_write_still_arrives(): void
    {
        $this->linkedOnOwn();
        $this->inbound('هل لديكم ورد؟');
        $this->assertSame(1, WhatsAppInboundMessage::where('business_id', $this->shop->id)->count());
        $this->assertNotNull($this->c()->last_inbound_at);
    }

    public function test_the_shop_is_told_what_is_left_and_so_is_the_team(): void
    {
        $this->linkedOnOwn();
        $key = 'wa-sender-down-'.$this->c()->id;
        $this->assertStringContainsString('جهة رسوم', $this->bell($this->owner)[$key]);
        $this->assertStringContainsString('جهة رسوم', $this->bell($this->super)[$key]);

        $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('automation.sender.billing.state', 'unverified')->where('automation.sender.billing.may_request', true)->where('automation.sender.billing.account', '1001')
            ->missing('automation.sender.billing.facts'));
        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->shop->id))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('whatsapp.billing.state', 'unverified')->where('whatsapp.billing_review.locked', true)->where('whatsapp.billing_review.may_confirm', true)
            ->where('whatsapp.own_health.own_app', null));
        $this->actingAs($this->super)->get(route('super-admin.settings.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('ownNumbers.0.billing.state', 'unverified')->where('ownNumbers.0.billing.locked', true));
    }

    /* ═════════════ ما تنقضه Meta ═════════════ */

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refuting(): array
    {
        return [
            'يملكه نشاط أبعاد' => [['owner' => ['1001' => 'MB-ABAAD']], 'billed_to_abaad'],
            'في قائمة ما تملكه أبعاد' => [['owned_by_abaad' => ['9000', '1001']], 'billed_to_abaad'],
            'أُنشئ نيابة عن أبعاد' => [['behalf' => 'MB-ABAAD'], 'billed_to_abaad'],
            'ملكه شريك نيابة عن التاجر' => [['ownership' => 'ON_BEHALF_OF'], 'billing_partner_owned'],
            'شريك غير المالك' => [['behalf' => 'MB-PARTNER'], 'billing_partner_owned'],
            'بلا وسيلة دفع' => [['funding' => 'absent'], 'billing_no_payment_method'],
            'الرمز لا يرسل منه' => [['scopes' => ['7777']], 'messaging_account_unbound'],
        ];
    }

    #[DataProvider('refuting')]
    public function test_what_meta_refutes_is_a_problem_and_no_evidence_opens_it(array $says, string $code): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->meta = array_merge($this->meta, $says);

        $this->review()->assertSessionHasErrors('whatsapp');
        $this->assertSame(['problem', $code], [$this->billing()->state, $this->billing()->code]);
        $this->assertFalse($this->c()->isUsable());
        $this->assertSame($code, WhatsAppBillingEvent::where('event', 'refused')->latest('id')->first()->code, 'وما رُدّ يُقيَّد');
        $this->assertNull($this->billing()->evidence);

        // ‏‏ولا قالبَ يُنشأ في حسابٍ ليس للتاجر
        if (in_array($code, WhatsAppBillingLock::FOREIGN, true)) {
            $this->calls = [];
            $this->tick();
            $this->assertSame([], array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
        }
    }

    /** ‏‏وغيابُ المعلومة ليس نجاحًا */
    public function test_what_meta_did_not_say_is_not_a_success(): void
    {
        $this->link()->assertSessionHasNoErrors();

        $this->meta['owner'] = [];
        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppBillingLock::says('owner_unknown')['text']]);

        $this->meta['owner'] = ['1001' => 'MB-SHOP'];
        config(['whatsapp.abaad_business_ids' => []]);
        WhatsAppConnection::where('owner_type', 'platform')->update(['meta_business_id' => null, 'waba_id' => null]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->review()->assertSessionHasErrors(['whatsapp' => WhatsAppBillingLock::says('abaad_account_unknown')['text']]);

        $this->meta['down'] = true;
        $this->review()->assertSessionHasErrors('whatsapp');
        $this->assertNotSame('proven', $this->billing()->state);
        $this->assertFalse($this->c()->isUsable());
    }

    public function test_an_account_linked_to_another_shop_is_refused_for_both_ways(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());

        // ‏‏ومتجرٌ آخر بالحساب نفسه ورقمٍ آخر فيه: لا يرث الإثبات
        [$two, $his] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->meta['numbers']['1001'] = 'PN-9';
        $this->link($his, '1001', 'PN-9')->assertSessionHasNoErrors();
        $this->assertSame(['problem', 'messaging_account_taken'], [$this->billing($two)->state, $this->billing($two)->code]);
        $this->assertFalse($this->c($two)->isUsable());
    }

    /* ═════════════ المراجعة ═════════════ */

    public function test_a_documented_review_is_the_only_way_and_it_names_the_account_in_every_message(): void
    {
        $this->linkedOnOwn();
        $this->review()->assertSessionHasNoErrors();

        $b = $this->billing();
        $this->assertSame(['proven', null], [$b->state, $b->code]);
        $this->assertSame(['payment_settings', 'صورة 25-11-2026', '1001', 'MB-SHOP', $this->super->id], [$b->evidence['kind'], $b->evidence['reference'], $b->evidence['waba_id'], $b->evidence['owner_id'], $b->evidence['reviewed_by_id']]);
        $event = WhatsAppBillingEvent::where('event', 'confirmed')->first();
        $this->assertSame([$this->super->id, $this->shop->id, '1001'], [$event->by_id, $event->business_id, $event->waba_id]);
        $this->assertTrue(ActivityLog::where('business_id', $this->shop->id)->where('description', 'like', '%ثبتت الفوترة المباشرة%')->exists());

        $this->calls = [];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing());
        $call = array_values(array_filter($this->calls, fn ($x) => str_ends_with($x['url'], '/messages')))[0];
        $this->assertSame('1001', $call['data']['messaging_account_id'], 'فلا تختار Meta حسابًا غيرَ الذي رُوجع');
        $this->assertSame('TOKEN-FOR-1001', $call['token']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badReviews(): array
    {
        return [
            'معرّف حساب آخر' => [['evidence_waba_id' => '1002'], 'evidence_waba_id'],
            'معرّف فارغ' => [['evidence_waba_id' => ''], 'evidence_waba_id'],
            'نوع مخترع' => [['evidence_kind' => 'merchant_said_so'], 'evidence_kind'],
            'بلا مرجع' => [['evidence_reference' => ''], 'evidence_reference'],
            'لم ير المعرّف' => [['evidence_checked' => false], 'evidence_checked'],
            'لم يقرّ بالمالك' => [['evidence_owner' => false], 'evidence_owner'],
            'لم يقرّ بالبطاقة' => [['evidence_card' => false], 'evidence_card'],
            'رقم بطاقة في المرجع' => [['evidence_reference' => 'بطاقة 4111 1111 1111 1111'], 'evidence_reference'],
            'رقم بطاقة بأرقام عربية' => [['evidence_reference' => '٤١١١١١١١١١١١١١١١'], 'evidence_reference'],
        ];
    }

    #[DataProvider('badReviews')]
    public function test_a_review_missing_any_part_proves_nothing(array $over, string $field): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review($over)->assertSessionHasErrors($field);
        $this->assertNotSame('proven', $this->billing()->state);
        $this->assertFalse($this->c()->isUsable());
        $this->assertStringNotContainsString('4111', (string) json_encode(WhatsAppBillingEvent::all()));
    }

    public function test_only_the_platform_manager_reviews(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review([], $this->owner)->assertStatus(403);
        foreach (['read', 'reopen', 'retry', 'estimate'] as $do) {
            $this->actingAs($this->owner)->post(route('super-admin.businesses.whatsapp.billing.'.$do, $this->shop->id), ['reason' => 'سبب'])->assertStatus(403);
        }
        auth()->logout();
        $this->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id))->assertRedirect();
        $this->assertSame('unverified', $this->billing()->state);
    }

    public function test_a_card_number_is_recognised_and_an_invoice_number_is_not(): void
    {
        foreach (['4111111111111111', '٤٠١٢٨٨٨٨٨٨٨٨١٨٨١', '5500-0000-0000-0004', '3400 0000 0000 009', 'ref 4012888888881881 end'] as $card) {
            $this->assertTrue(WhatsAppBillingLock::looksLikeCard($card), $card);
        }
        foreach (['FBADS-108-100012345', '25-11-2026', 'INV 2026/11/00045', '1001', 'ملف 12'] as $ref) {
            $this->assertFalse(WhatsAppBillingLock::looksLikeCard($ref), $ref);
        }
    }

    public function test_the_proven_number_becomes_the_sender_by_itself(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);

        $this->review()->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode);
    }

    /** ‏‏وتُقرأ Meta لحظةَ التبديل: ما تبدّل بعد المراجعة أو لم يُقرأ الآن لا يُبدَّل معه */
    public function test_the_payer_is_read_again_at_the_moment_of_the_switch(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();

        $this->meta['owner'] = [];
        $this->tick();
        $this->assertSame([WhatsAppMode::ABAAD_SHARED, 'owner_unknown'], [$this->shop->fresh()->whatsapp_mode, WhatsAppSenderAdoption::latest('id')->first()->code]);

        $this->meta['owner'] = ['1001' => 'MB-NEW'];
        $this->tick();
        $this->assertSame([WhatsAppMode::ABAAD_SHARED, WhatsAppBillingLock::OWNER_CHANGED], [$this->shop->fresh()->whatsapp_mode, WhatsAppSenderAdoption::latest('id')->first()->code]);
    }

    /** ‏‏وإثباتٌ نُقض في ثواني الفحص يغلب التبديل */
    public function test_a_proof_lost_while_the_check_runs_stops_the_switch(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->review()->assertSessionHasNoErrors();
        $this->templates = ['1001' => []];
        $hit = false;
        WhatsAppSenderAdoption::updating(function ($row) use (&$hit) {
            if (! $hit && $row->isDirty('checks')) {
                $hit = true;
                WhatsAppBilling::query()->update(['state' => 'recheck', 'code' => WhatsAppBillingLock::REVOKED]);
            }
        });
        $this->tick();
        $this->assertTrue($hit);
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        WhatsAppSenderAdoption::flushEventListeners();
    }

    /** ‏‏ووصلةٌ من قبل القفل تُرسل كما كانت — لكنّ رقمها لا يصير رقمَ الإرسال آليًّا قبل الإثبات، ولو نُقض في ثواني الفحص */
    public function test_a_link_from_before_the_lock_is_not_switched_to_without_proof(): void
    {
        $this->legacy();
        $this->link()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->billing_exempt);
        $this->tick();
        $this->assertSame([WhatsAppMode::ABAAD_SHARED, WhatsAppBillingLock::UNPROVEN], [$this->shop->fresh()->whatsapp_mode, WhatsAppSenderAdoption::latest('id')->first()->code]);

        $this->proveWhatsAppBilling((int) $this->shop->id, '1001');
        $hit = false;
        WhatsAppSenderAdoption::updating(function ($row) use (&$hit) {
            if (! $hit && $row->isDirty('checks')) {
                $hit = true;
                WhatsAppBilling::query()->update(['state' => 'recheck', 'code' => WhatsAppBillingLock::REVOKED]);
            }
        });
        $this->tick();
        $this->assertTrue($hit);
        $this->assertTrue($this->c()->isUsable());
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        WhatsAppSenderAdoption::flushEventListeners();
    }

    public function test_a_reviewed_link_from_before_the_lock_carries_its_review_to_the_new_link(): void
    {
        $c = $this->legacy();
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id), ['evidence_kind' => 'meta_invoice', 'evidence_reference' => 'INV-1', 'evidence_waba_id' => '1001', 'evidence_checked' => true])->assertSessionHasNoErrors();
        $this->assertSame('proven', $this->billing()->state);

        $c->update(['status' => WhatsAppConnection::EXPIRED]);
        $this->meta['numbers']['1001'] = 'PN-7';
        $this->link(null, '1001', 'PN-7')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->billing_exempt, 'رقمٌ آخر: مقفل');
        $this->assertTrue($this->c()->isUsable(), 'وحسابُه رُوجع من قبل');
    }

    /* ═════════════ التاجر ═════════════ */

    public function test_the_owner_requests_verification_and_that_proves_nothing(): void
    {
        $this->linkedOnOwn();
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.request'))->assertSessionHasNoErrors();
        $this->assertSame(['pending', 'المالك'], [$this->billing()->state, $this->billing()->requested_by]);
        $this->assertSame(WhatsAppBillingLock::REQUESTED, WhatsAppBillingLock::stop($this->c()));
        $this->assertFalse($this->c()->isUsable());
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());

        // ‏‏وينتظره فريقُ أبعاد لا التاجر
        $this->tick();
        $row = WhatsAppSenderAdoption::latest('id')->first();
        $this->assertSame('abaad', WhatsAppSender::waitingFor($row)['who']);
    }

    public function test_only_the_shops_owner_asks_and_only_for_the_shop(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $staff = User::create(['business_id' => $this->shop->id, 'name' => 'موظف', 'email' => 'e@abaadapp.om', 'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط']);
        $this->actingAs($staff)->post(route('admin.integrations.whatsapp.billing.request'));
        $this->assertSame('unverified', $this->billing()->state);
        // ‏‏ومديرٌ يفتح التكاملات ليس صاحبَ الحساب
        $manager = User::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'email' => 'm@abaadapp.om', 'password' => bcrypt('password12345'), 'role' => 'manager', 'status' => 'نشط']);
        $this->actingAs($manager)->post(route('admin.integrations.whatsapp.billing.request'))->assertSessionHasErrors('billing');
        $this->assertSame('unverified', $this->billing()->state);

        [$two, $his] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->actingAs($his)->post(route('admin.integrations.whatsapp.billing.request'))->assertSessionHasErrors('billing');
        $this->assertSame('unverified', $this->billing()->state, 'ولا يطلبه لمتجرٍ غيره');
        $this->assertNull($this->billing($two));
    }

    /* ═════════════ الدفعُ والربطُ والجهةُ حين تتعطّل ═════════════ */

    private function proven(): WhatsAppConnection
    {
        $this->linkedOnOwn();
        $this->review()->assertSessionHasNoErrors();
        $this->tick();
        $this->calls = [];

        return $this->c();
    }

    public function test_a_refused_payment_stops_the_number_and_nothing_leaves_from_anywhere_else(): void
    {
        $this->proven();
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['problem', WhatsAppBillingLock::PAYMENT_REFUSED], [$this->billing()->state, $this->billing()->code]);

        $this->meta['send_error'] = [];
        $this->calls = [];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->inbound();
        $this->assertSame([], $this->outgoing(), 'لا من رقمه ولا من رقم أبعاد');
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode, 'ولا يُحوَّل');
        $key = 'wa-sender-down-'.$this->c()->id;
        $this->assertStringContainsString('وسيلة الدفع', $this->bell($this->owner)[$key]);
        $this->assertStringContainsString('وسيلة الدفع', $this->bell($this->super)[$key]);
        $this->assertTrue(ActivityLog::where('business_id', $this->shop->id)->where('description', 'like', '%أُوقف الإرسال%')->exists());

        // ‏‏وإعادةُ المحاولة تعيده لأنّ دليله قائم — وردٌّ آخر يوقفه من جديد
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'))->assertSessionHasNoErrors();
        $this->assertSame('proven', $this->billing()->state);
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame('problem', $this->billing()->state);
    }

    public function test_a_payment_refused_later_by_a_status_update_stops_it_too(): void
    {
        $this->proven();
        $this->order()->update(['status' => OrderStatus::READY]);
        $wamid = WhatsAppMessage::latest('id')->value('provider_message_id');
        $this->metaSaid($wamid, 'failed', ['errors' => [['code' => 131042, 'title' => 'payment']]]);
        $this->assertSame(['problem', WhatsAppBillingLock::PAYMENT_REFUSED], [$this->billing()->state, $this->billing()->code]);

        // ‏‏ورقمُ عميلٍ لا يستقبل لا يوقف شيئًا
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'))->assertSessionHasNoErrors();
        $this->metaSaid($wamid, 'failed', ['errors' => [['code' => 131026]]]);
        $this->assertSame('proven', $this->billing()->state);
    }

    public function test_retrying_never_opens_what_was_never_proven_or_what_meta_refutes(): void
    {
        $this->linkedOnOwn();
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'));
        $this->assertSame('unverified', $this->billing()->state);

        $this->billing()->update(['state' => 'problem', 'code' => WhatsAppBillingLock::PAYMENT_REFUSED]);
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'));
        $this->assertFalse($this->c()->isUsable(), 'زالت علامةُ الدفع وبقي الإثبات');

        $this->billing()->update(['state' => 'problem', 'code' => WhatsAppBillingLock::PAYMENT_REFUSED, 'evidence' => ['waba_id' => '1001', 'owner_id' => 'MB-SHOP']]);
        $this->meta['owner'] = ['1001' => 'MB-ABAAD'];
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'))->assertSessionHasErrors('billing');
        $this->assertSame('billed_to_abaad', $this->billing()->code);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function changes(): array
    {
        return [
            'مالك آخر' => [['owner' => ['1001' => 'MB-NEW']], 'recheck', WhatsAppBillingLock::OWNER_CHANGED],
            'صار لأبعاد' => [['owner' => ['1001' => 'MB-ABAAD']], 'problem', 'billed_to_abaad'],
            'صار لشريك' => [['ownership' => 'ON_BEHALF_OF'], 'problem', 'billing_partner_owned'],
            'زالت وسيلة الدفع' => [['funding' => 'absent'], 'problem', 'billing_no_payment_method'],
            'وسيلة دفع أخرى' => [['funding' => 'F-2'], 'recheck', 'billing_funding_changed'],
        ];
    }

    #[DataProvider('changes')]
    public function test_a_changed_payer_stops_the_number_from_both_schedules(array $says, string $state, string $code): void
    {
        foreach (['whatsapp:own-billing', 'whatsapp:adopt-numbers --watch'] as $i => $command) {
            $this->meta['funding'] = 'F-1';
            $this->meta['ownership'] = 'SELF';
            $this->meta['owner'] = ['1001' => 'MB-SHOP'];
            $i === 0 ? $this->proven() : $this->review()->assertSessionHasNoErrors();
            $this->assertTrue($this->c()->isUsable());

            $this->meta = array_merge($this->meta, $says);
            $this->billing()->update(['checked_at' => now()->subDays(2)]);
            WhatsAppSenderAdoption::where('state', 'adopted')->update(['health_checked_at' => now()->subHours(2)]);
            $run = $this->artisan($command)->assertSuccessful();
            $i === 0 && $run->expectsOutputToContain('أُوقفت وصلة المتجر');
            $run->run();

            $this->assertSame([$state, $code], [$this->billing()->state, $this->billing()->code], $command);
            $this->assertFalse($this->c()->isUsable());
            $this->calls = [];
            $this->order()->update(['status' => OrderStatus::READY]);
            $this->assertSame([], $this->outgoing());
        }
    }

    public function test_an_unread_day_neither_stops_nor_clears(): void
    {
        $this->proven();
        $this->meta['down'] = true;
        $this->billing()->update(['checked_at' => now()->subDays(2)]);
        $this->artisan('whatsapp:own-billing')->assertSuccessful();
        $this->assertSame('proven', $this->billing()->state);

        $this->billing()->update(['state' => 'problem', 'code' => 'billing_no_payment_method', 'checked_at' => now()->subDays(2)]);
        $this->meta['down'] = false;
        $this->meta['funding'] = null;
        WhatsAppBillingLock::read($this->c(), force: true);
        $this->assertSame('problem', $this->billing()->state, '«لا تُطلعنا» ليست «وُجدت»');
        $this->meta['funding'] = 'F-1';
        WhatsAppBillingLock::read($this->c(), force: true);
        $this->assertSame('proven', $this->billing()->state);
    }

    /** ‏‏وحسابٌ كان لغير التاجر ثمّ عاد لا يعود إرسالُه بعودته — يُراجَع من جديد */
    public function test_an_account_that_left_the_merchant_and_came_back_is_reviewed_again(): void
    {
        $this->proven();
        $this->meta['owner'] = ['1001' => 'MB-ABAAD'];
        WhatsAppBillingLock::read($this->c(), force: true);
        $this->assertSame('problem', $this->billing()->state);

        $this->meta['owner'] = ['1001' => 'MB-SHOP'];
        WhatsAppBillingLock::read($this->c(), force: true);
        $this->assertSame('recheck', $this->billing()->state);
        $this->assertFalse($this->c()->isUsable());
    }

    public function test_a_reviewed_link_from_before_the_lock_shows_a_changed_payer_and_keeps_sending(): void
    {
        $c = $this->legacy();
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id), ['evidence_kind' => 'meta_invoice', 'evidence_reference' => 'INV-1', 'evidence_waba_id' => '1001', 'evidence_checked' => true])->assertSessionHasNoErrors();
        $this->webhook(['field' => 'account_update', 'value' => ['event' => 'PARTNER_ADDED', 'waba_info' => ['waba_id' => '1001', 'solution_partner_business_ids' => ['MB-STRANGER']]]])->assertOk();
        $this->assertSame('recheck', WhatsAppBillingLock::stateOf($c->fresh()));
        $this->assertTrue($c->fresh()->isUsable());

        // ‏‏وصفٌّ بقي من ربطٍ سابق لا يمسّه خبرٌ عن وصلة تطبيق التاجر
        $this->billing()->update(['state' => 'proven', 'code' => null]);
        $c->update(['link_type' => WhatsAppOwnApp::TYPE]);
        $this->webhook(['field' => 'account_update', 'value' => ['event' => 'PARTNER_ADDED', 'waba_info' => ['waba_id' => '1001', 'solution_partner_business_ids' => ['MB-STRANGER']]]])->assertOk();
        $this->assertSame('proven', $this->billing()->state);
    }

    public function test_asking_for_verification_does_not_hide_a_problem(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->meta['owner'] = ['1001' => 'MB-ABAAD'];
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.request'))->assertSessionHasErrors('billing');
        $this->assertSame(['problem', 'billed_to_abaad', null], [$this->billing()->state, $this->billing()->code, $this->billing()->requested_at]);
    }

    public function test_meta_saying_the_owner_or_a_partner_changed_stops_at_once(): void
    {
        $this->proven();
        $tell = fn (array $info, string $event = 'PARTNER_ADDED') => $this->webhook(['field' => 'account_update', 'value' => ['event' => $event, 'waba_info' => ['waba_id' => '1001'] + $info]])->assertOk();

        // ‏‏وأبعاد نفسُها شريكٌ بالربط — لا يوقف
        $tell(['owner_business_id' => 'MB-SHOP', 'solution_partner_business_ids' => ['MB-ABAAD']]);
        $this->assertSame('proven', $this->billing()->state);
        $this->assertNull($this->billing()->checked_at, 'ويُعاد قراءته');

        $tell(['owner_business_id' => 'MB-SHOP', 'solution_partner_business_ids' => ['MB-ABAAD', 'MB-STRANGER']]);
        $this->assertSame(['recheck', WhatsAppBillingLock::PARTNER_ADDED], [$this->billing()->state, $this->billing()->code]);

        $this->review()->assertSessionHasNoErrors();
        $tell(['owner_business_id' => 'MB-NEW'], 'ACCOUNT_OFFBOARDED');
        $this->assertSame(['recheck', WhatsAppBillingLock::OWNER_CHANGED], [$this->billing()->state, $this->billing()->code]);
        $this->assertFalse($this->c()->isUsable());
    }

    public function test_a_lost_authorization_stops_it_within_the_next_run(): void
    {
        $this->proven();
        $this->meta['send_error'] = ['PN-1' => 190];
        $this->meta['token_valid'] = false;
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertNull(WhatsAppSenderAdoption::where('state', 'adopted')->latest('id')->first()->health_checked_at, 'فلا يُنتظر فحصُ الساعة');
        $this->tick();
        $this->assertSame(WhatsAppBillingLock::LINK_LOST, WhatsAppBillingLock::stop($this->c()), 'وثبت في الدورة التالية فوقف');
        $this->assertFalse($this->c()->isUsable());
        $this->calls = [];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());
        $this->assertStringContainsString('أعد الربط', $this->bell($this->owner)['wa-sender-down-'.$this->c()->id]);
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode);
        $this->assertTrue(WhatsAppSender::needsRelink($this->shop->fresh(), $this->c()), 'ويُرسل له رابطُ ربطٍ من جديد');

        // ‏‏وإعادةُ الربط تعيده بدليله القائم
        $this->meta = array_merge($this->meta, ['send_error' => [], 'token_valid' => true]);
        $this->link()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());
    }

    public function test_the_team_reopens_the_review_and_the_number_stops(): void
    {
        $this->proven();
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.reopen', $this->shop->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.reopen', $this->shop->id), ['reason' => 'اشتبه في البطاقة'])->assertSessionHasNoErrors();
        $this->assertSame(['recheck', WhatsAppBillingLock::REVOKED], [$this->billing()->state, $this->billing()->code]);
        $this->assertSame('اشتبه في البطاقة', WhatsAppBillingEvent::latest('id')->first()->data['why']);
        $this->assertFalse($this->c()->isUsable());
        $this->review()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());
    }

    /* ═════════════ العزلُ والرصيدُ والأسرار ═════════════ */

    public function test_one_shops_proof_opens_no_other_shop_or_account(): void
    {
        [$two, $his] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222', WhatsAppMode::BUSINESS_OWN);
        $this->proven();
        $this->link($his, '1002', 'PN-2')->assertSessionHasNoErrors();
        $this->assertFalse($this->c($two)->isUsable());
        $this->assertTrue($this->c()->isUsable());

        // ‏‏وحسابٌ آخر للمتجر نفسه لا يرث إثبات الأوّل
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->link(null, '1003', 'PN-3')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->isUsable());
        $this->assertSame('unverified', WhatsAppBilling::where('business_id', $this->shop->id)->where('waba_id', '1003')->value('state'));

        // ‏‏وإن عاد إلى حسابه الأوّل عاد إثباتُه — ما لم تنقضه Meta
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->link()->assertSessionHasNoErrors();
        $this->assertTrue($this->c()->isUsable());
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->meta['owner'] = ['1001' => 'MB-NEW'];
        $this->link()->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->isUsable());
    }

    public function test_a_number_of_its_own_never_spends_the_credit_bought_from_abaad(): void
    {
        $this->shop->update(['whatsapp_message_credits' => 500, 'whatsapp_monthly_limit' => 40]);
        $this->proven();
        $before = [WhatsAppQuota::credits($this->shop->fresh()), WhatsAppQuota::used($this->shop->fresh())];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->order()->update(['status' => OrderStatus::READY]);

        $this->assertSame([500, 0], $before);
        $this->assertSame($before, [WhatsAppQuota::credits($this->shop->fresh()), WhatsAppQuota::used($this->shop->fresh())]);
        $this->assertSame(0, WhatsAppMessage::where('quota_consumed', true)->count());
    }

    public function test_the_lock_touches_no_plan_price_or_credit(): void
    {
        $columns = array_diff(Schema::getColumnListing('businesses'), ['whatsapp_mode', 'updated_at']);
        $before = (array) DB::table('businesses')->where('id', $this->shop->id)->first($columns);
        $settings = Setting::where('key', 'not like', 'whatsapp%')->count();
        $this->proven();
        $this->assertSame($before, (array) DB::table('businesses')->where('id', $this->shop->id)->first($columns));
        $this->assertSame($settings, Setting::where('key', 'not like', 'whatsapp%')->count());
    }

    public function test_no_screen_carries_a_secret_or_the_payment_fingerprint(): void
    {
        $this->meta['funding'] = 'F-SECRET-FUNDING';
        $this->proven();
        $mark = $this->billing()->evidence['funding_mark'];
        $this->assertNotEmpty($mark);
        $this->assertStringNotContainsString('F-SECRET-FUNDING', (string) json_encode(WhatsAppBilling::all()), 'ولا يُحفظ معرّفُ وسيلة الدفع');

        foreach ([[$this->owner, route('admin.integrations.whatsapp')], [$this->super, route('super-admin.businesses.show', $this->shop->id)], [$this->super, route('super-admin.settings.index')]] as [$who, $url]) {
            $html = (string) $this->actingAs($who)->get($url)->getContent();
            foreach (['TOKEN-FOR-1001', 'test-secret', 'F-SECRET-FUNDING', $mark] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
        // ‏‏والتاجرُ لا يرى وقائعَ المراجعة ولا من راجع
        $this->assertStringNotContainsString('صورة 25-11-2026', (string) $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))->getContent());
    }

    public function test_every_state_and_reason_has_words_in_both_languages(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);

        foreach (WhatsAppBillingLock::STATES as $state) {
            $this->assertArrayHasKey(WhatsAppBillingLock::label($state), $en, $state);
        }
        foreach ([...WhatsAppBillingLock::STOPS, 'owner_unknown', 'abaad_account_unknown'] as $code) {
            $said = WhatsAppSender::say($code);
            $this->assertStringNotContainsString($code, $said['text']);
            $this->assertArrayHasKey($said['text'], $en, $code);
            $this->assertContains($said['who'], ['merchant', 'abaad', 'meta']);
        }
        $this->assertSame(['لم يتم التحقق', 'بانتظار التحقق', 'الفوترة المباشرة للتاجر مثبتة', 'مشكلة في الفوترة', 'يلزم إعادة التحقق'], array_map(fn ($s) => WhatsAppBillingLock::label($s), WhatsAppBillingLock::STATES));
    }

    /** ‏‏وقبل الهجرة بثوانٍ لا يسقط الجدولُ ولا تقف وصلة */
    public function test_the_scheduler_survives_the_seconds_before_the_migration(): void
    {
        $this->legacy();
        foreach (['whatsapp_billing_events', 'whatsapp_message_prices', 'whatsapp_billing_trials', 'whatsapp_billings'] as $table) {
            Schema::drop($table);
        }
        $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
        $this->assertTrue($this->c()->isUsable());
        $this->assertSame('own_app', \App\Support\WhatsAppMerchantLink::mode());
    }
}
