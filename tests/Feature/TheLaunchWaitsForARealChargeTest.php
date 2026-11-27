<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppBilling;
use App\Models\WhatsAppBillingEvent;
use App\Models\WhatsAppBillingTrial;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppMessagePrice;
use App\Models\WhatsAppSenderAdoption;
use App\Support\MetaWhatsAppClient;
use App\Support\OrderStatus;
use App\Support\WhatsAppBillingLock;
use App\Support\WhatsAppBillingTrials;
use App\Support\WhatsAppCampaigns;
use App\Support\WhatsAppCampaignsSwitch;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMerchantLink;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use App\Support\WhatsAppPrices;
use App\Support\WhatsAppTemplates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesMetaBilling;
use Tests\TestCase;

/**
 * ‏‏الإطلاقُ ينتظر رسمًا حقيقيًّا — لا يُنقل التجّار قبل أن يُرى على حساب التاجر ولا يُرى على حساب أبعاد.
 *
 *   · زرُّ النقل مقفلٌ على الخادم: لا بطلبٍ يُكتب، ولا بصفٍّ محفوظ، ولا بمتغيّر بيئة.
 *   · تجربةٌ واحدة لمتجرٍ واحد بعددٍ ومدّة — ولا تفتح غيرَه ولا حسابًا آخر له.
 *   · ونجاحُها برسمٍ لرسالةٍ بعينها قالت Meta إنّها محتسَبة ووصلت — لا بتقدير.
 *   · وما تقوله Meta عن كلّ رسالة يُحفظ كما قيل، ولا مبلغَ يُخترع.
 */
class TheLaunchWaitsForARealChargeTest extends TestCase
{
    use FakesMetaBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMeta();
    }

    /* ═════════════ أدوات ═════════════ */

    private function pilot(?Business $shop = null): void
    {
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => (string) ($shop ?? $this->shop)->id])->assertSessionHasNoErrors();
    }

    private function link(?User $owner = null, string $waba = '1001', string $phone = 'PN-1')
    {
        return $this->actingAs($owner ?? $this->owner)->post(route('admin.integrations.whatsapp.embedded.callback'), [
            'code' => 'AQD-short-lived-code-'.$waba, 'waba_id' => $waba, 'phone_number_id' => $phone,
        ]);
    }

    private function openTrial(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->super)->post(route('super-admin.whatsapp.billing-trial.open'), $over + [
            // ‏‏ورقمُ زبون المتجر رقمُ اختبارها — رسائلُها لا تصل غيرَه (`ATrialReachesOnlyItsTestNumbersTest`)
            'business_id' => $this->shop->id, 'messages' => 3, 'hours' => 24, 'charges_accepted' => true, 'phones' => '91234567',
        ]);
    }

    /** متجرُ تجربةٍ مربوط، وتجربتُه مفتوحة، ورسائلُه من رقمه */
    private function trial(): WhatsAppBillingTrial
    {
        $this->pilot();
        $this->link()->assertSessionHasNoErrors();
        $this->openTrial()->assertSessionHasNoErrors();
        $this->shop->update(['whatsapp_mode' => WhatsAppMode::BUSINESS_OWN]);
        WhatsAppTemplates::seedBusinessDefaults($this->shop->id);
        DB::table('whatsapp_template_mappings')->where('business_id', $this->shop->id)->update(['meta_status' => 'APPROVED', 'enabled' => true]);
        $this->calls = [];

        return WhatsAppBillingTrial::latest('id')->first();
    }

    private function c(?Business $shop = null): WhatsAppConnection
    {
        return WhatsAppConnections::forBusiness((int) ($shop ?? $this->shop)->id);
    }

    /** رسالةٌ خرجت ووصلت وصنّفتها Meta */
    private function chargedMessage(array $pricing = ['billable' => true, 'pricing_model' => 'PMP', 'type' => 'regular', 'category' => 'utility']): WhatsAppMessagePrice
    {
        $this->order()->update(['status' => OrderStatus::READY]);
        $wamid = WhatsAppMessage::where('business_id', $this->shop->id)->latest('id')->value('provider_message_id');
        $this->metaSaid($wamid, 'sent', ['pricing' => $pricing]);
        $this->metaSaid($wamid, 'delivered', ['pricing' => $pricing]);

        return WhatsAppMessagePrice::where('wamid', $wamid)->firstOrFail();
    }

    private function pass(WhatsAppBillingTrial $trial, array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->super)->post(route('super-admin.whatsapp.billing-trial.pass', $trial->id), $over + [
            'kind' => 'billing_charge', 'price_id' => WhatsAppMessagePrice::value('id'), 'waba_id' => '1001', 'reference' => 'رسم 25-11-2026', 'abaad_reference' => 'Billing Hub 25-11-2026 18:00',
            'amount' => '0.04', 'currency' => 'usd', 'merchant_checked' => true, 'abaad_checked' => true,
        ]);
    }

    private function move()
    {
        return $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'abaad_app']);
    }

    /* ═════════════ القفلُ على الخادم ═════════════ */

    public function test_the_move_is_refused_by_the_server_until_the_trial_passes(): void
    {
        $this->move()->assertSessionHasErrors('mode');
        $this->assertFalse(Setting::where('key', WhatsAppMerchantLink::KEY)->exists(), 'ولم يُكتب شيء');
        $this->assertSame('own_app', WhatsAppMerchantLink::mode());
        $this->assertSame(['ok' => false, 'changed' => false], array_intersect_key(WhatsAppMerchantLink::set('abaad_app'), ['ok' => 1, 'changed' => 1]));
        $this->actingAs($this->super)->get(route('super-admin.settings.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('whatsappLink.mode', 'own_app')->where('whatsappLink.billing.launched', false)->where('whatsappLink.pilot_max', 1));
    }

    /** ‏‏ولا يمرّ من بابٍ آخر: صفٌّ محفوظ من قبل، أو متغيّرُ بيئة */
    public function test_a_stored_choice_or_a_server_variable_does_not_take_effect(): void
    {
        config(['whatsapp.merchant_link' => 'abaad_app']);
        $this->assertSame('own_app', WhatsAppMerchantLink::mode());
        $this->assertTrue(WhatsAppOwnApp::enabled());

        $this->platform(WhatsAppMerchantLink::KEY, 'abaad_app');
        $this->assertSame('own_app', WhatsAppMerchantLink::mode());
        $this->link()->assertSessionHasErrors('code');
        $this->assertNull(WhatsAppConnections::forBusiness($this->shop->id));
        $this->move()->assertSessionHasErrors('mode');
        $this->actingAs($this->super)->get(route('super-admin.settings.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('whatsappLink.held', true));
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.clientLink.store', $this->shop->id))->assertSessionHasErrors();

        // ‏‏والعودةُ إلى تطبيقات التجّار تُكتب
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.mode'), ['mode' => 'own_app'])->assertSessionHasNoErrors();
        $this->assertSame('own_app', Setting::where('key', WhatsAppMerchantLink::KEY)->value('value'));
    }

    public function test_one_test_shop_only_before_the_launch(): void
    {
        [$two] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => $this->shop->id.','.$two->id])->assertSessionHasErrors('pilot');
        $this->assertSame([], WhatsAppMerchantLink::pilot());
        $this->pilot();
        $this->assertSame([(int) $this->shop->id], WhatsAppMerchantLink::pilot());

        $this->launchWhatsAppForAll();
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.merchant-link.pilot'), ['pilot' => $this->shop->id.','.$two->id])->assertSessionHasNoErrors();
    }

    public function test_only_the_platform_manager_touches_the_lock_or_the_trial(): void
    {
        $trial = $this->trial();
        foreach ([
            ['post', route('super-admin.whatsapp.billing-trial.open')], ['delete', route('super-admin.whatsapp.billing-trial.close', $trial->id)],
            ['post', route('super-admin.whatsapp.billing-trial.pass', $trial->id)], ['post', route('super-admin.whatsapp.billing-lock.relock')], ['post', route('super-admin.whatsapp.shared-cost')],
        ] as [$method, $url]) {
            $this->actingAs($this->owner)->{$method}($url)->assertStatus(403);
        }
        $this->assertSame('open', $trial->fresh()->state);
    }

    /* ═════════════ التجربةُ معزولة ═════════════ */

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badTrials(): array
    {
        return [
            'بلا موافقة على الرسوم' => [['charges_accepted' => false], 'charges_accepted'],
            'أكثر من الحد' => [['messages' => 51], 'messages'],
            'صفر رسائل' => [['messages' => 0], 'messages'],
            'أطول من أسبوع' => [['hours' => 169], 'hours'],
            'متجر لا وجود له' => [['business_id' => 'missing'], 'business_id'],
        ];
    }

    #[DataProvider('badTrials')]
    public function test_a_trial_needs_its_consent_and_its_limits(array $over, string $field): void
    {
        $this->pilot();
        $this->link()->assertSessionHasNoErrors();
        $over = ($over['business_id'] ?? null) === 'missing' ? ['business_id' => Business::max('id') + 500] : $over;
        $this->openTrial($over)->assertSessionHasErrors($field);
        $this->assertSame(0, WhatsAppBillingTrial::count());
    }

    public function test_a_trial_is_for_a_linked_pilot_shop_only(): void
    {
        $this->openTrial()->assertSessionHasErrors('business_id');
        $this->pilot();
        $this->openTrial()->assertSessionHasErrors('business_id');

        // ‏‏ومتجرٌ على تطبيقه أو رُبط قبل القفل لا تُفتح عليه
        $row = ['owner_type' => 'business', 'business_id' => $this->shop->id, 'provider' => 'meta_cloud', 'purpose' => 'notifications', 'waba_id' => '1001', 'phone_number_id' => 'PN-1', 'access_token' => 'TOKEN-FOR-1001', 'status' => 'active'];
        $own = WhatsAppConnection::create($row + ['link_type' => WhatsAppOwnApp::TYPE, 'billing_state' => 'confirmed', 'billing_evidence' => ['waba_id' => '1001']]);
        $this->openTrial()->assertSessionHasErrors(['business_id' => __('هذا المتجر على تطبيقه الخاص — لا تُفتح عليه تجربة.')]);
        $own->forceDelete();
        $old = WhatsAppConnection::create($row + ['billing_exempt' => true]);
        $this->openTrial()->assertSessionHasErrors('business_id');
        $old->forceDelete();

        // ‏‏ومتجرٌ رُبط ثمّ رُفع من «متجر التجربة» لا تُفتح عليه
        $this->link()->assertSessionHasNoErrors();
        $this->platform(WhatsAppMerchantLink::PILOT_KEY, '');
        $this->openTrial()->assertSessionHasErrors(['business_id' => __('اكتب المتجر في «متاجر التجربة» أولًا.')]);
        $this->pilot();

        // ‏‏وحسابٌ لأبعاد لا تفتحه تجربة
        $this->meta['owner'] = ['1001' => 'MB-ABAAD'];
        $this->openTrial()->assertSessionHasErrors('business_id');
        $this->meta['owner'] = [];
        WhatsAppBilling::query()->update(['state' => 'unverified', 'code' => null]);
        $this->openTrial()->assertSessionHasErrors('business_id');
        $this->assertSame(0, WhatsAppBillingTrial::count());
    }

    public function test_the_trial_lets_one_shop_send_a_counted_number_of_messages(): void
    {
        $trial = $this->trial();
        [$two, $his, $hisCustomer] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222', WhatsAppMode::BUSINESS_OWN);
        $this->platform(WhatsAppMerchantLink::PILOT_KEY, $this->shop->id.','.$two->id);
        $this->link($his, '1002', 'PN-2')->assertSessionHasNoErrors();
        WhatsAppTemplates::seedBusinessDefaults($two->id);
        DB::table('whatsapp_template_mappings')->where('business_id', $two->id)->update(['meta_status' => 'APPROVED', 'enabled' => true]);
        $this->calls = [];

        for ($i = 0; $i < 5; $i++) {
            $this->order()->update(['status' => OrderStatus::READY]);
            $this->order($two, $hisCustomer)->update(['status' => OrderStatus::READY]);
        }

        $this->assertSame(['PN-1', 'PN-1', 'PN-1'], $this->outgoing(), 'ثلاثٌ بحدّها، ولا شيء من المتجر الآخر ولا من رقم أبعاد');
        $this->assertSame(3, $trial->fresh()->messages_used);
        $this->assertSame(WhatsAppBillingLock::TRIAL_SPENT, WhatsAppBillingLock::stop($this->c()));
        $this->assertSame(WhatsAppBillingLock::UNPROVEN, WhatsAppBillingLock::stop($this->c($two)));
        $this->assertSame('unverified', WhatsAppBilling::where('business_id', $this->shop->id)->value('state'), 'والتجربةُ لا تُثبت');
        $call = array_values(array_filter($this->calls, fn ($x) => str_ends_with($x['url'], '/messages')))[0];
        $this->assertSame('1001', $call['data']['messaging_account_id']);
    }

    /** ‏‏وبابُ Meta نفسُه يعدّ — لا الشاشة: نداءٌ مباشر بعد الحدّ لا يخرج */
    public function test_the_count_holds_at_the_last_door_even_for_a_stale_connection(): void
    {
        $this->trial();
        $c = $this->c();
        foreach ([true, true, true, false, false] as $ok) {
            $this->assertSame($ok, MetaWhatsAppClient::sendText($c, '96891234567', 'س')['ok']);
        }
        $this->assertCount(3, $this->outgoing());

        // ‏‏والعدّادُ ذرّةٌ واحدة: لا يزيد على حدّه ولو سُئل مباشرة
        $this->assertSame(WhatsAppBillingLock::TRIAL_SPENT, WhatsAppBillingTrials::spend($c));
        $this->assertSame(3, WhatsAppBillingTrial::value('messages_used'));

        // ‏‏وما ثبتت فوترتُه لا يقف بحدّ التجربة
        $this->proveWhatsAppBilling((int) $this->shop->id, '1001');
        $this->assertNull(WhatsAppBillingTrials::spend($this->c()));
        $this->assertTrue(MetaWhatsAppClient::sendText($this->c(), '96891234567', 'س')['ok']);
    }

    public function test_the_trial_ends_by_its_time_or_by_hand(): void
    {
        $trial = $this->trial();
        $this->assertTrue($this->c()->isUsable());
        $this->travel(25)->hours();
        $this->assertSame(WhatsAppBillingLock::UNPROVEN, WhatsAppBillingLock::stop($this->c()));
        $this->assertSame(WhatsAppBillingLock::TRIAL_SPENT, WhatsAppBillingTrials::spend($this->c()), 'والعدّادُ نفسُه لا يعدّ بعد موعدها');
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());
        $this->travelBack();

        $this->actingAs($this->super)->delete(route('super-admin.whatsapp.billing-trial.close', $trial->id))->assertSessionHasNoErrors();
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());
        $this->assertSame(['closed', null], [$trial->fresh()->state, $trial->fresh()->open_slot]);
        $this->actingAs($this->super)->delete(route('super-admin.whatsapp.billing-trial.close', $trial->id))->assertSessionHasErrors('trial');
    }

    public function test_only_one_trial_is_open_in_the_whole_system(): void
    {
        $trial = $this->trial();
        $this->openTrial()->assertSessionHasErrors('trial');
        $this->assertSame(1, WhatsAppBillingTrial::count());

        // ‏‏والقاعدةُ تحرسه لا الكودُ وحده
        $this->expectException(UniqueConstraintViolationException::class);
        WhatsAppBillingTrial::create($trial->only(['business_id', 'waba_id', 'phone_number_id', 'message_limit', 'opened_by_name']) + ['state' => 'open', 'open_slot' => 1, 'opened_at' => now(), 'expires_at' => now()->addDay()]);
    }

    public function test_an_expired_trial_makes_room_for_another(): void
    {
        $this->trial();
        $this->travel(25)->hours();
        $this->openTrial()->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'open'], WhatsAppBillingTrial::orderBy('id')->pluck('state')->all());
    }

    public function test_the_trial_does_not_cover_another_account_or_number_of_the_same_shop(): void
    {
        $this->trial();
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->link(null, '1002', 'PN-2')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->isUsable());
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->meta['numbers']['1001'] = 'PN-9';
        $this->link(null, '1001', 'PN-9')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->isUsable());
        // ‏‏ورقمُها نفسُه في حسابٍ آخر
        $this->actingAs($this->owner)->delete(route('admin.integrations.whatsapp.disconnect'));
        $this->meta['numbers']['1003'] = 'PN-1';
        $this->link(null, '1003', 'PN-1')->assertSessionHasNoErrors();
        $this->assertFalse($this->c()->isUsable());
    }

    public function test_a_problem_stops_the_trial_too(): void
    {
        $this->trial();
        $this->meta['send_error'] = ['PN-1' => 131042];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(WhatsAppBillingLock::PAYMENT_REFUSED, WhatsAppBillingLock::stop($this->c()));
        $this->meta['send_error'] = [];
        $this->calls = [];
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame([], $this->outgoing());

        // ‏‏يُصلح بطاقته فيعود — في حدّ التجربة
        $this->actingAs($this->owner)->post(route('admin.integrations.whatsapp.billing.retry'))->assertSessionHasNoErrors();
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-1'], $this->outgoing());

        $this->webhook(['field' => 'account_update', 'value' => ['event' => 'X', 'waba_info' => ['waba_id' => '1001']]]);
        $this->meta['owner'] = ['1001' => 'MB-ABAAD'];
        WhatsAppBillingLock::read($this->c(), force: true);
        $this->assertSame('billed_to_abaad', WhatsAppBillingLock::stop($this->c()));
    }

    public function test_the_trial_shop_is_adopted_so_the_whole_flow_is_tried(): void
    {
        $this->pilot();
        $this->link()->assertSessionHasNoErrors();
        $tick = function () {
            WhatsAppSenderAdoption::where('state', 'checking')->update(['next_check_at' => now()->subSecond()]);
            $this->artisan('whatsapp:adopt-numbers')->assertSuccessful();
        };
        $tick();
        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->shop->fresh()->whatsapp_mode);
        $this->openTrial()->assertSessionHasNoErrors();
        $tick();
        $this->assertSame(WhatsAppMode::BUSINESS_OWN, $this->shop->fresh()->whatsapp_mode);
    }

    /* ═════════════ النتيجة ═════════════ */

    public function test_a_real_charge_on_the_merchant_opens_the_move_and_moves_nobody(): void
    {
        $trial = $this->trial();
        $message = $this->chargedMessage();
        $this->pass($trial)->assertSessionHasNoErrors();

        $trial->refresh();
        $this->assertSame(['passed', null], [$trial->state, $trial->open_slot]);
        $this->assertSame(['billing_charge', 'رسم 25-11-2026', 'Billing Hub 25-11-2026 18:00', '0.04', 'USD', $message->wamid, $this->super->id],
            [$trial->proof['kind'], $trial->proof['reference'], $trial->proof['abaad_reference'], $trial->proof['amount'], $trial->proof['currency'], $trial->proof['wamid'], $trial->proof['by_id']]);
        $this->assertSame('proven', WhatsAppBilling::where('business_id', $this->shop->id)->value('state'));
        $this->assertTrue(WhatsAppBillingEvent::where('event', 'trial_passed')->where('trial_id', $trial->id)->exists());
        $this->assertTrue(ActivityLog::where('description', 'like', '%نجحت تجربة الفوترة%')->exists());
        $this->assertTrue(WhatsAppBillingTrials::launched());

        // ‏‏ولم يُنقل أحد: الزرُّ فُتح، والاختيارُ لمدير المنصّة
        $this->assertSame('own_app', WhatsAppMerchantLink::mode());
        $this->move()->assertSessionHasNoErrors();
        $this->assertSame('abaad_app', WhatsAppMerchantLink::mode());

        // ‏‏ونجاحُها لا يُثبت تاجرًا آخر
        [$two, $his] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222', WhatsAppMode::BUSINESS_OWN);
        $this->link($his, '1002', 'PN-2')->assertSessionHasNoErrors();
        $this->assertFalse($this->c($two)->isUsable());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badProofs(): array
    {
        return [
            'تقدير لا رسم' => [['kind' => 'estimate'], 'kind'],
            'حساب آخر' => [['waba_id' => '1002'], 'waba_id'],
            'بلا مرجع' => [['reference' => ''], 'reference'],
            'لم يراجع حساب أبعاد' => [['abaad_checked' => false], 'abaad_checked'],
            'بلا مرجع لحساب أبعاد' => [['abaad_reference' => ''], 'abaad_reference'],
            'لم ير الرسم عند التاجر' => [['merchant_checked' => false], 'merchant_checked'],
            'بلا رسالة' => [['price_id' => -1], 'price_id'],
            'بطاقة في المرجع' => [['reference' => '4111111111111111'], 'reference'],
            'بطاقة في مرجع أبعاد' => [['abaad_reference' => '4111-1111-1111-1111'], 'abaad_reference'],
            'مبلغ بلا عملة' => [['currency' => ''], 'currency'],
            'عملة مخترعة' => [['currency' => 'ريالات'], 'currency'],
        ];
    }

    #[DataProvider('badProofs')]
    public function test_a_result_missing_any_part_opens_nothing(array $over, string $field): void
    {
        $trial = $this->trial();
        $this->chargedMessage();
        $this->pass($trial, $over)->assertSessionHasErrors($field);
        $this->assertFalse(WhatsAppBillingTrials::launched());
        $this->assertSame('open', $trial->fresh()->state);
        $this->assertNotSame('proven', WhatsAppBilling::where('business_id', $this->shop->id)->value('state'));
    }

    /** @return array<string, array{0: array<string, mixed>|null, 1: bool}> */
    public static function uncharged(): array
    {
        return [
            'مجانية داخل النافذة' => [['billable' => false, 'type' => 'free_customer_service', 'category' => 'service', 'subtype' => 'free_tier'], true],
            'مجانية وقيل محتسبة بالقديم' => [['billable' => true, 'type' => 'free_entry_point', 'category' => 'utility'], true],
            'محتسبة ولم تصل' => [['billable' => true, 'type' => 'regular', 'category' => 'utility'], false],
        ];
    }

    #[DataProvider('uncharged')]
    public function test_a_message_meta_did_not_charge_proves_nothing(array $pricing, bool $delivered): void
    {
        $trial = $this->trial();
        $this->order()->update(['status' => OrderStatus::READY]);
        $wamid = WhatsAppMessage::latest('id')->value('provider_message_id');
        $this->metaSaid($wamid, 'sent', ['pricing' => $pricing]);
        if ($delivered) {
            $this->metaSaid($wamid, 'delivered', ['pricing' => $pricing]);
        }
        $this->pass($trial)->assertSessionHasErrors('price_id');
        $this->assertFalse(WhatsAppBillingTrials::launched());
        // ‏‏والنوعُ يغلب `billable`: هو ما تقول Meta إنّه يبقى
        $this->assertSame($pricing['type'] === 'regular', WhatsAppPrices::charged(WhatsAppMessagePrice::first()));
    }

    public function test_a_message_of_another_shop_or_from_before_the_trial_proves_nothing(): void
    {
        $trial = $this->trial();
        $mine = $this->chargedMessage();
        [$two] = $this->makeShop('متجر ثانٍ', 's@abaadapp.om', '92222222');

        $this->pass($trial, ['price_id' => tap($mine->replicate()->fill(['wamid' => 'x1', 'business_id' => $two->id]))->save()->id])->assertSessionHasErrors('price_id');
        $this->pass($trial, ['price_id' => tap($mine->replicate()->fill(['wamid' => 'x2', 'owner_type' => 'platform']))->save()->id])->assertSessionHasErrors('price_id');
        $this->pass($trial, ['price_id' => tap($mine->replicate()->fill(['wamid' => 'x3', 'waba_id' => '1002']))->save()->id])->assertSessionHasErrors('price_id');
        $old = tap($mine->replicate()->fill(['wamid' => 'x4']))->save();
        WhatsAppMessagePrice::whereKey($old->id)->update(['created_at' => now()->subDays(3)]);
        $this->pass($trial, ['price_id' => $old->id])->assertSessionHasErrors('price_id');
        $this->assertFalse(WhatsAppBillingTrials::launched());
        $this->pass($trial, ['price_id' => $mine->id])->assertSessionHasNoErrors();
    }

    public function test_what_meta_refutes_at_the_moment_of_recording_wins(): void
    {
        $trial = $this->trial();
        $this->chargedMessage();
        foreach ([['owner' => ['1001' => 'MB-ABAAD']], ['owner' => []], ['ownership' => 'ON_BEHALF_OF', 'owner' => ['1001' => 'MB-SHOP']]] as $says) {
            $this->meta = array_merge($this->meta, $says);
            $this->pass($trial)->assertSessionHasErrors('trial');
        }
        $this->assertFalse(WhatsAppBillingTrials::launched());
    }

    /** ‏‏والقاعدةُ عند صاحبها لا في الشاشة وحدها */
    public function test_an_estimate_is_refused_even_past_the_form(): void
    {
        $trial = $this->trial();
        $message = $this->chargedMessage();
        $r = WhatsAppBillingTrials::pass($trial, $this->super, ['kind' => 'estimate', 'price_id' => $message->id, 'waba_id' => '1001', 'reference' => 'تقدير', 'abaad_reference' => 'راجعته']);
        $this->assertSame([false, 'kind'], [$r['ok'], $r['field']]);
        $this->assertFalse(WhatsAppBillingTrials::launched());
    }

    public function test_the_result_is_recorded_after_the_trial_closed_once_and_not_forever(): void
    {
        $trial = $this->trial();
        $this->chargedMessage();
        $this->actingAs($this->super)->delete(route('super-admin.whatsapp.billing-trial.close', $trial->id));
        $this->travel(20)->days();
        $this->pass($trial)->assertSessionHasNoErrors();
        $this->pass($trial)->assertSessionHasErrors('trial');
        $this->assertSame(1, WhatsAppBillingEvent::where('event', 'trial_passed')->count());
    }

    public function test_an_old_trial_is_not_recorded(): void
    {
        $trial = $this->trial();
        $this->chargedMessage();
        $this->travel(121)->days();
        $this->pass($trial)->assertSessionHasErrors('trial');
    }

    public function test_the_move_can_be_locked_again_and_what_was_proven_stays(): void
    {
        $trial = $this->trial();
        $this->chargedMessage();
        $this->pass($trial)->assertSessionHasNoErrors();
        $this->move()->assertSessionHasNoErrors();

        $this->actingAs($this->super)->post(route('super-admin.whatsapp.billing-lock.relock'), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.billing-lock.relock'), ['reason' => 'ظهر رسم على أبعاد'])->assertSessionHasNoErrors();
        $this->assertSame('own_app', WhatsAppMerchantLink::mode());
        $this->assertFalse(WhatsAppBillingTrials::launched());
        $this->assertTrue($this->c()->isUsable(), 'وما ثبت لمتجرٍ يبقى');
        $this->assertSame('ظهر رسم على أبعاد', $trial->fresh()->revoked_why);
        $this->move()->assertSessionHasErrors('mode');
    }

    /* ═════════════ ما تقوله Meta عن كلّ رسالة ═════════════ */

    public function test_metas_classification_is_kept_as_said_and_carries_no_amount(): void
    {
        $this->trial();
        $m = $this->chargedMessage();
        $this->assertSame([true, 'PMP', 'regular', 'utility', null, 'business', '1001', 'PN-1', 'notice'], [$m->billable, $m->pricing_model, $m->pricing_type, $m->category, $m->subtype, $m->owner_type, $m->waba_id, $m->phone_number_id, $m->kind]);
        $this->assertSame('delivered', $m->last_status);
        $this->assertNotNull($m->delivered_at);
        $this->assertSame([], array_filter(Schema::getColumnListing('whatsapp_message_prices'), fn ($c) => preg_match('/amount|cost|price|currency/', $c) === 1), 'ولا عمودَ لمبلغٍ لم تقله Meta');

        // ‏‏وإعادةُ الإشعار لا تضاعف ولا تبدّل ما قيل
        $this->metaSaid($m->wamid, 'read', ['pricing' => ['billable' => false, 'type' => 'free_tier', 'category' => 'service']]);
        $this->assertSame(1, WhatsAppMessagePrice::where('wamid', $m->wamid)->count());
        $this->assertSame(['regular', 'utility', true, 'read'], array_values($m->fresh()->only(['pricing_type', 'category', 'billable', 'last_status'])));
    }

    public function test_what_meta_did_not_say_is_not_written(): void
    {
        $this->trial();
        $this->order()->update(['status' => OrderStatus::READY]);
        $wamid = WhatsAppMessage::latest('id')->value('provider_message_id');
        $this->metaSaid($wamid, 'sent');
        $this->metaSaid($wamid, 'delivered', ['pricing' => 'not-an-object']);
        $this->metaSaid($wamid, 'delivered', ['pricing' => ['type' => ['x'], 'category' => '<script>', 'billable' => 'yes', 'cost' => 9]]);
        $this->assertSame(0, WhatsAppMessagePrice::count());

        $this->metaSaid($wamid, 'delivered', ['pricing' => ['category' => 'utility']]);
        $m = WhatsAppMessagePrice::first();
        $this->assertSame([null, null, 'utility'], [$m->billable, $m->pricing_type, $m->category]);
        $this->assertNull(WhatsAppPrices::charged($m));
        $this->assertNull(WhatsAppPrices::line($m)['charged']);
    }

    public function test_one_line_never_edits_another_lines_message(): void
    {
        $this->trial();
        $m = $this->chargedMessage(['category' => 'utility']);
        $this->metaSaid($m->wamid, 'read', ['pricing' => ['type' => 'regular']], 'PN-ABAAD');
        $this->assertNull($m->fresh()->pricing_type);
        $this->assertSame('delivered', $m->fresh()->last_status);
    }

    public function test_a_shared_numbers_message_is_attributed_to_its_shop_and_to_abaads_account(): void
    {
        $this->order()->update(['status' => OrderStatus::READY]);
        $this->assertSame(['PN-ABAAD'], $this->outgoing());
        $wamid = WhatsAppMessage::latest('id')->value('provider_message_id');
        $this->metaSaid($wamid, 'delivered', ['pricing' => ['billable' => true, 'type' => 'regular', 'category' => 'utility']], 'PN-ABAAD');
        $m = WhatsAppMessagePrice::first();
        $this->assertSame(['platform', '9000', (int) $this->shop->id, 'notice'], [$m->owner_type, $m->waba_id, (int) $m->business_id, $m->kind]);

        $this->metaSaid('wamid.UNKNOWN', 'delivered', ['pricing' => ['billable' => true, 'type' => 'regular', 'category' => 'service']], 'PN-ABAAD');
        $cost = WhatsAppPrices::sharedView();
        $this->assertSame(['total' => 2, 'charged' => 2, 'free' => 0, 'unknown' => 0, 'days' => 30], $cost['classified']);
        $this->assertEqualsCanonicalizing([['label' => 'إشعار', 'n' => 1], ['label' => 'ردّ خدمة', 'n' => 1]], $cost['by_category']);
        $this->assertEqualsCanonicalizing([['business' => 'محل ورد', 'n' => 1], ['business' => 'غير منسوبة لمتجر', 'n' => 1]], $cost['by_shop']);
    }

    /** حملةٌ تخرج الآن إلى ثلاثة أرقام — مقرَّةٌ على وصلة المتجر */
    private function runningCampaign(): WhatsAppCampaign
    {
        $campaign = WhatsAppCampaign::create([
            'business_id' => $this->shop->id, 'whatsapp_connection_id' => $this->c()->id, 'name' => 'حملة', 'status' => WhatsAppCampaign::SENDING, 'total' => 3, 'started_at' => now(),
            'message' => ['meta_name' => 'abaad_offer_1', 'language' => 'ar', 'variables' => []], 'audience' => [],
        ]);
        $phones = ['96891110001', '96891110002', '96891110003'];
        foreach ($phones as $phone) {
            WhatsAppCampaignRecipient::create(['business_id' => $this->shop->id, 'campaign_id' => $campaign->id, 'phone' => $phone, 'status' => WhatsAppCampaignRecipient::PENDING]);
        }
        // ‏‏وأرقامُ الحملة أرقامُ اختبار التجربة — رسالةُ التجربة لا تصل غيرَها (`ATrialReachesOnlyItsTestNumbersTest`)
        WhatsAppBillingTrial::all()->each(fn (WhatsAppBillingTrial $t) => $t->update(['allowed_phones' => $phones]));
        $this->assertTrue(WhatsAppCampaignsSwitch::set(WhatsAppCampaignsSwitch::ALL)['ok']);
        $this->travelTo(now()->setTime(12, 0));
        $this->calls = [];

        return $campaign;
    }

    public function test_a_running_campaign_stops_at_the_lock_and_burns_no_recipient(): void
    {
        $this->trial();
        $campaign = $this->runningCampaign();
        WhatsAppBillingTrial::query()->update(['state' => 'closed', 'open_slot' => null]);

        $this->artisan('whatsapp:run-campaigns')->assertSuccessful();
        $this->assertSame([], $this->outgoing());
        $this->assertSame([WhatsAppCampaign::PAUSED, WhatsAppBillingLock::UNPROVEN], [$campaign->fresh()->status, $campaign->fresh()->stop_reason]);
        $this->assertSame(3, WhatsAppCampaignRecipient::where('status', WhatsAppCampaignRecipient::PENDING)->count());
        $this->assertStringContainsString('جهة رسوم', (string) WhatsAppCampaigns::stopWhy($campaign->fresh()->stop_reason));
        // ‏‏ولا يستأنفها التاجر فوق القفل
        $this->assertFalse(WhatsAppCampaigns::resume($this->shop->fresh(), $campaign->fresh())['ok']);
    }

    public function test_a_campaign_stops_at_the_trials_count_and_its_messages_are_named(): void
    {
        $this->trial();
        $campaign = $this->runningCampaign();
        WhatsAppBillingTrial::query()->update(['message_limit' => 2]);
        $this->artisan('whatsapp:run-campaigns')->assertSuccessful();

        $this->assertSame(['PN-1', 'PN-1'], $this->outgoing());
        $this->assertSame([WhatsAppCampaign::PAUSED, WhatsAppBillingLock::TRIAL_SPENT], [$campaign->fresh()->status, $campaign->fresh()->stop_reason]);
        $this->assertSame(1, WhatsAppCampaignRecipient::where('status', WhatsAppCampaignRecipient::PENDING)->count());

        $wamid = WhatsAppCampaignRecipient::whereNotNull('wamid')->value('wamid');
        $this->metaSaid($wamid, 'delivered', ['pricing' => ['billable' => true, 'type' => 'regular', 'category' => 'marketing']]);
        $this->assertSame(['campaign', 'marketing', (int) $this->shop->id], array_values(WhatsAppMessagePrice::first()->only(['kind', 'category', 'business_id'])));
        $this->metaSaid('wamid.OTHER', 'delivered', ['pricing' => ['category' => 'service']]);
        $this->assertSame('other', WhatsAppMessagePrice::where('wamid', 'wamid.OTHER')->value('kind'));
    }

    public function test_a_failure_to_keep_it_never_loses_the_status_itself(): void
    {
        $this->trial();
        $this->order()->update(['status' => OrderStatus::READY]);
        $row = WhatsAppMessage::latest('id')->first();
        WhatsAppMessagePrice::creating(fn () => throw new \RuntimeException('disk full'));
        $this->metaSaid($row->provider_message_id, 'delivered', ['pricing' => ['category' => 'utility']]);
        WhatsAppMessagePrice::flushEventListeners();
        $this->assertSame('delivered', $row->fresh()->status);
    }

    /* ═════════════ التقدير ═════════════ */

    public function test_the_estimate_is_shown_as_an_estimate_and_never_as_proof(): void
    {
        $this->trial();
        $this->meta['points'] = [
            ['pricing_category' => 'UTILITY', 'pricing_type' => 'REGULAR', 'volume' => 3, 'cost' => 0.045],
            ['pricing_category' => 'UTILITY', 'pricing_type' => 'REGULAR', 'volume' => 1, 'cost' => 0.015],
            ['pricing_category' => 'SERVICE', 'pricing_type' => 'FREE_CUSTOMER_SERVICE', 'volume' => 9, 'cost' => 0],
        ];
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.estimate', $this->shop->id))->assertSessionHasNoErrors();
        $e = WhatsAppBilling::first()->facts['estimate'];
        $this->assertSame(['read', 0.06, 13, 4], [$e['state'], $e['cost'], $e['volume'], $e['charged']]);
        $this->assertSame('unverified', WhatsAppBilling::first()->state, 'والتقديرُ لا يُثبت');
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['method'] !== 'GET' && str_contains(json_encode($c['query']), 'pricing_analytics')));

        // ‏‏وقراءةٌ فشلت لا تمحو ما قُرئ، وقراءةُ الملكيّة تبقيه
        $this->meta['analytics'] = false;
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.estimate', $this->shop->id))->assertSessionHasErrors('whatsapp');
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.read', $this->shop->id))->assertSessionHasNoErrors();
        $this->assertSame(0.06, WhatsAppBilling::first()->facts['estimate']['cost']);
    }

    /** ‏‏Meta تحجب الكلفة عن حسابٍ يدفع عنه شريك — فلا يُثبت حتى يتّضح */
    public function test_a_hidden_cost_on_charged_messages_refuses_the_review(): void
    {
        $this->trial();
        $this->meta['points'] = [['pricing_category' => 'UTILITY', 'pricing_type' => 'REGULAR', 'volume' => 3]];
        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing.estimate', $this->shop->id))->assertSessionHasNoErrors();
        $this->assertSame('cost_hidden', WhatsAppBilling::first()->facts['estimate']['state']);
        $this->assertNull(WhatsAppBilling::first()->facts['estimate']['cost'], 'ولا يُكتب صفرًا ما لم تقله');

        $this->actingAs($this->super)->post(route('super-admin.businesses.whatsapp.billing', $this->shop->id), [
            'evidence_kind' => 'meta_invoice', 'evidence_reference' => 'INV-9', 'evidence_waba_id' => '1001', 'evidence_checked' => true, 'evidence_owner' => true, 'evidence_card' => true,
        ])->assertSessionHasErrors('whatsapp');
        $this->assertNotSame('proven', WhatsAppBilling::first()->state);
    }

    public function test_the_shared_numbers_estimate_changes_nothing(): void
    {
        $this->shop->update(['whatsapp_message_credits' => 7, 'whatsapp_monthly_limit' => 40]);
        $before = Business::find($this->shop->id)->getAttributes();
        $this->meta['points'] = [['pricing_category' => 'SERVICE', 'pricing_type' => 'REGULAR', 'volume' => 20, 'cost' => 0.5]];
        $this->actingAs($this->super)->post(route('super-admin.whatsapp.shared-cost'))->assertSessionHasNoErrors();
        $this->actingAs($this->super)->get(route('super-admin.settings.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('whatsappSharedCost.estimate.cost', 0.5)->where('whatsappSharedCost.linked', true));
        $this->assertSame($before, Business::find($this->shop->id)->getAttributes());
        $this->assertSame([], $this->outgoing());
    }
}
