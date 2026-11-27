<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOwnApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * كلُّ رقمِ متجرٍ يردّ على أوّل رسالة — مُشعَلٌ لمن لم يقل رأيه، وصامتٌ لمن أطفأه.
 *
 * قرارُ صاحب المنتج (2026-11-01): الافتراضُ إشعالٌ لكلّ متجرٍ برقمه، حاليٍّ وجديد،
 * بلا اسمٍ ولا رقمِ متجرٍ ولا قائمة. والإشعالُ ليس إرسالًا: يخرج الردّ إن عمل
 * الرقمُ وأذنت الباقةُ وصحّ الربطُ ورُوجع دليلُ فوترة تطبيق التاجر — ولا يُتجاوز
 * `billing_unproven`. وميتا كلُّها مُحاكاة: لا رسالةَ حقيقيّة تخرج.
 */
class EveryOwnNumberAnswersTheFirstMessageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-not-a-real-one';

    private static int $seq = 0;

    private Plan $plan;

    /** يُنادى أثناء إخراج الردّ — لرسالةٍ ثانيةٍ تصل في اللحظة نفسها */
    private ?\Closure $whileSending = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_secret' => self::SECRET,
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v21.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::fake(function (HttpRequest $r) {
            if (str_contains($r->url(), '/messages') && $this->whileSending !== null) {
                $during = $this->whileSending;
                $this->whileSending = null;
                $during();
            }

            return Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200);
        });

        $this->plan = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);

        // ورقمُ أبعاد قائمٌ — ولا يردّ منه على زبون متجر
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM, 'phone_number_id' => 'ABAAD-PN',
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'access_token' => 'platform-token-value-0123456789', 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    /* ═══════════ أدوات ═══════════ */

    private function shop(string $name, ?string $nameEn = null): Business
    {
        $b = Business::create([
            'name' => $name, 'name_en' => $nameEn, 'type' => 'محل ورود', 'status' => 'نشط', 'plan_id' => $this->plan->id,
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b->fresh();
    }

    private function own(Business $b, string $pn, array $over = []): WhatsAppConnection
    {
        return WhatsAppConnection::create($over + [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id,
            'phone_number_id' => $pn, 'display_phone_number' => '+968 7000 000'.substr($pn, -1),
            'waba_id' => 'WABA-'.$pn, 'access_token' => 'shop-token-value-'.$pn,
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function webhook(array $value): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();
    }

    private function message(string $wamid, string $from = '96891234567', string $type = 'text', ?int $at = null): array
    {
        return ['id' => $wamid, 'from' => $from, 'type' => $type, 'timestamp' => (string) ($at ?? now()->timestamp), 'text' => ['body' => 'مرحبا']];
    }

    private function inbound(string $pn, string $wamid, string $from = '96891234567', string $type = 'text', ?int $at = null): void
    {
        $this->webhook(['metadata' => ['phone_number_id' => $pn], 'messages' => [$this->message($wamid, $from, $type, $at)]]);
    }

    /** @return list<array{pn:string, to:string, body:string}> ما خرج إلى `/messages` */
    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))
            ->map(fn (HttpRequest $r) => [
                'pn' => (string) preg_replace('~^.*/([^/]+)/messages$~', '$1', $r->url()),
                'to' => (string) $r['to'], 'body' => (string) ($r['text']['body'] ?? ''),
            ])->values()->all();
    }

    private function reason(string $wamid): ?string
    {
        return WhatsAppInboundMessage::where('wamid', $wamid)->value('reply_reason');
    }

    /* ═══════════ مُشعَلٌ لمن لم يقل رأيه ═══════════ */

    public function test_an_existing_shop_that_never_set_the_reply_answers_with_its_own_name(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');

        $this->assertSame(['state' => WhatsAppAutoReply::WORKING, 'reason' => null], WhatsAppAutoReply::state($shop));

        $this->inbound('PN-1', 'wamid.A1');

        $sent = $this->replies();
        $this->assertCount(1, $sent);
        $this->assertSame(['PN-1', '96891234567'], [$sent[0]['pn'], $sent[0]['to']]);
        $this->assertSame(WhatsAppAutoReply::defaultText($shop->id, 'ar'), $sent[0]['body']);
        $this->assertStringContainsString('محل الورد', $sent[0]['body']);
        $this->assertFalse(Setting::where('business_id', $shop->id)->where('key', WhatsAppAutoReply::ENABLED)->exists(), 'لم يُكتب عنه رأي');
    }

    public function test_a_new_shop_that_just_linked_its_number_answers_without_doing_anything(): void
    {
        $shop = $this->shop('متجر جديد');
        $this->assertSame(WhatsAppAutoReply::WAITING, WhatsAppAutoReply::state($shop)['state'], 'بلا رقمٍ: بانتظار الربط لا «يعمل»');

        $this->own($shop, 'PN-2');
        $this->assertSame(WhatsAppAutoReply::WORKING, WhatsAppAutoReply::state($shop)['state']);

        $this->inbound('PN-2', 'wamid.N1');
        $this->assertCount(1, $this->replies());
        $this->assertSame('sent', WhatsAppInboundMessage::where('wamid', 'wamid.N1')->value('reply_status'));
    }

    public function test_a_merchants_own_text_and_cooldown_are_kept(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        WhatsAppAutoReply::save($shop->id, ['enabled' => true, 'ar' => 'نصّي أنا', 'en' => 'My text', 'cooldown_hours' => 2]);

        $this->inbound('PN-1', 'wamid.K1');
        $this->travel(3)->hours();
        $this->inbound('PN-1', 'wamid.K2');

        $this->assertSame(['نصّي أنا', 'نصّي أنا'], array_column($this->replies(), 'body'), 'الفترةُ التي كتبها: ساعتان');
    }

    /* ═══════════ وصامتٌ لمن أطفأه أو لم يجهز رقمُه ═══════════ */

    public function test_a_shop_that_turned_the_reply_off_stays_off(): void
    {
        $shop = $this->shop('محل أطفأه');
        $this->own($shop, 'PN-3');
        WhatsAppAutoReply::save($shop->id, ['enabled' => false, 'ar' => '', 'cooldown_hours' => 12]);

        $this->inbound('PN-3', 'wamid.O1');

        $this->assertSame([], $this->replies());
        $this->assertSame('disabled', $this->reason('wamid.O1'));
        $this->assertSame(['state' => WhatsAppAutoReply::OFF, 'reason' => null], WhatsAppAutoReply::state($shop));
    }

    public function test_an_own_app_link_awaiting_billing_proof_never_replies(): void
    {
        $shop = $this->shop('محل بتطبيقه');
        $this->own($shop, 'PN-4', [
            'link_type' => WhatsAppOwnApp::TYPE, 'billing_state' => WhatsAppOwnApp::BILLING_UNPROVEN,
            'status' => WhatsAppConnection::PENDING, 'last_error_code' => WhatsAppOwnApp::AWAITS_BILLING,
            'token_app_id' => '5550000000001', 'app_secret' => 'merchant-secret', 'webhook_key' => str_repeat('k', 40),
        ]);

        $state = WhatsAppAutoReply::state($shop);
        $this->assertSame(WhatsAppAutoReply::WAITING, $state['state'], 'مُشعَلٌ ولا يُقال «يعمل»');
        $this->assertStringContainsString('فوترة', (string) $state['reason']);

        WhatsAppAutoReply::receive(WhatsAppConnection::where('phone_number_id', 'PN-4')->first(), $this->message('wamid.B1'));

        $this->assertSame([], $this->replies());
        $this->assertSame(WhatsAppOwnApp::AWAITS_BILLING, $this->reason('wamid.B1'));
    }

    public function test_a_number_that_went_down_or_a_shop_without_whatsapp_never_replies(): void
    {
        $down = $this->shop('رقمٌ انقطع');
        $this->own($down, 'PN-5', ['status' => WhatsAppConnection::ERROR]);
        $off = $this->shop('واتساب مطفأ');
        $this->own($off, 'PN-6');
        $off->update(['whatsapp_enabled' => false]);

        $this->inbound('PN-5', 'wamid.D1');
        $this->inbound('PN-6', 'wamid.D2');

        $this->assertSame([], $this->replies());
        $this->assertSame('connection_unusable', $this->reason('wamid.D1'));
        $this->assertNotNull($this->reason('wamid.D2'));
        $this->assertSame(WhatsAppAutoReply::WAITING, WhatsAppAutoReply::state($down)['state']);
        $this->assertSame(WhatsAppAutoReply::WAITING, WhatsAppAutoReply::state($off->fresh())['state']);
    }

    public function test_only_a_real_customer_message_inside_metas_window_is_answered(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');

        // تفاعلٌ بوجه، ورسالةٌ أعادتها ميتا بعد يومين
        $this->inbound('PN-1', 'wamid.W1', type: 'reaction');
        $this->inbound('PN-1', 'wamid.W2', at: now()->subHours(25)->timestamp);

        $this->assertSame([], $this->replies());
        $this->assertSame(['not_a_message', 'outside_window'], [$this->reason('wamid.W1'), $this->reason('wamid.W2')]);

        $this->inbound('PN-1', 'wamid.W3');
        $this->assertCount(1, $this->replies());
    }

    /* ═══════════ مرّةً في كلّ فترة — لكلّ زبونٍ في كلّ متجر ═══════════ */

    public function test_several_messages_in_a_row_get_one_reply_until_twelve_hours_pass(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');

        $this->inbound('PN-1', 'wamid.R1');
        $this->inbound('PN-1', 'wamid.R2');
        $this->inbound('PN-1', 'wamid.R3');
        $this->assertCount(1, $this->replies());
        $this->assertSame(['cooldown', 'cooldown'], [$this->reason('wamid.R2'), $this->reason('wamid.R3')]);

        $this->travel(11)->hours();
        $this->inbound('PN-1', 'wamid.R4');
        $this->assertCount(1, $this->replies(), 'الافتراضُ اثنتا عشرة ساعة');

        $this->travel(2)->hours();
        $this->inbound('PN-1', 'wamid.R5');
        $this->assertCount(2, $this->replies());
    }

    public function test_two_customers_and_two_shops_each_get_their_own_reply_from_their_own_number(): void
    {
        $a = $this->shop('محل الورد');
        $b = $this->shop('محل الجار');
        $this->own($a, 'PN-1');
        $this->own($b, 'PN-7');

        $this->inbound('PN-1', 'wamid.T1', '96891111111');
        $this->inbound('PN-1', 'wamid.T2', '96892222222');
        // والزبونُ نفسُه يكتب للمتجر الآخر — تهدئةُ متجرٍ لا تُسكت جاره
        $this->inbound('PN-7', 'wamid.T3', '96891111111');

        $sent = $this->replies();
        $this->assertCount(3, $sent);
        $this->assertSame(['PN-1', 'PN-1', 'PN-7'], array_column($sent, 'pn'), 'كلٌّ من رقمه — لا من رقم أبعاد ولا من رقم جاره');
        $this->assertStringContainsString('محل الورد', $sent[0]['body']);
        $this->assertStringContainsString('محل الجار', $sent[2]['body']);
        $this->assertStringNotContainsString('محل الورد', $sent[2]['body'], 'لا يقع اسمُ متجرٍ في ردّ غيره');
        $this->assertNotContains('ABAAD-PN', array_column($sent, 'pn'));
    }

    public function test_a_duplicated_webhook_and_two_messages_at_the_same_moment_get_one_reply(): void
    {
        $shop = $this->shop('محل الورد');
        $line = $this->own($shop, 'PN-1');

        // الإشعارُ نفسُه مرّتين — صفٌّ واحدٌ وردٌّ واحد
        $this->inbound('PN-1', 'wamid.S1');
        $this->inbound('PN-1', 'wamid.S1');
        $this->assertSame(1, WhatsAppInboundMessage::where('wamid', 'wamid.S1')->count());
        $this->assertCount(1, $this->replies());

        // ورسالتان مختلفتان في اللحظة نفسها: الثانيةُ تصل والردُّ الأوّل ما زال يخرج
        $this->travel(13)->hours();
        $this->whileSending = fn () => WhatsAppAutoReply::receive($line->fresh(), $this->message('wamid.S3'));
        WhatsAppAutoReply::receive($line->fresh(), $this->message('wamid.S2'));

        $this->assertCount(2, $this->replies(), 'ردٌّ واحدٌ على الرسالتين المتزامنتين');
        $this->assertSame('cooldown', $this->reason('wamid.S3'));
    }

    public function test_a_failed_reply_does_not_silence_the_number(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');

        Http::swap(new Factory);
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)
            ->push(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->inbound('PN-1', 'wamid.F1');
        $this->inbound('PN-1', 'wamid.F2');

        $this->assertSame('failed', WhatsAppInboundMessage::where('wamid', 'wamid.F1')->value('reply_status'));
        $this->assertNull(WhatsAppInboundMessage::where('wamid', 'wamid.F1')->value('replied_at'), 'الحجزُ رُفع');
        $this->assertSame('sent', WhatsAppInboundMessage::where('wamid', 'wamid.F2')->value('reply_status'));
    }

    /* ═══════════ وبلغة الزبون ═══════════ */

    public function test_the_default_reply_speaks_the_customers_language_with_the_shops_names(): void
    {
        $shop = $this->shop('محل الورد', 'Rose Shop');
        $this->own($shop, 'PN-1');
        Customer::create(['business_id' => $shop->id, 'name' => 'Sara', 'phone' => '+968 9123 4567', 'language' => 'en']);

        $this->inbound('PN-1', 'wamid.L1', '96891234567');
        $this->inbound('PN-1', 'wamid.L2', '96899999999');

        [$en, $ar] = array_column($this->replies(), 'body');
        $this->assertSame(WhatsAppAutoReply::defaultText($shop->id, 'en'), $en);
        $this->assertStringContainsString('Rose Shop', $en);
        $this->assertStringContainsString('محل الورد', $ar);
        $this->assertStringNotContainsString('Rose Shop', $ar);
    }
}
