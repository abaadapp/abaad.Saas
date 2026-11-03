<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\Demo;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppHealth;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * عميلٌ لا يستقبل رقمُه ليس ربطًا مقطوعًا.
 *
 * ═══ العطب ═══
 *
 * عند RIBBON وصلت رسائلُ كلِّ الأرقام، ولم تصل رسالتان رقمَ زبونٍ واحد
 * (131026 «Message undeliverable»: قبلتهما Meta ثمّ ردّتهما عبر الإشعار).
 * فانطفأت مرحلة «رسائلك تصل زبائنك» وظهر 7/8 بعلامةٍ حمراء على ربطٍ سليم،
 * وقرأ التاجر أنّ واتساب معطّل وهو في رقم زبون.
 *
 * فالمرحلةُ تقول **هل يصل شيء**، وقائمةُ التسليم تقول **مَن لم تصله رسالتُه
 * ولماذا**. ولا يُقال «يصل» بلا دليل: الدليل «سُلّمت» أو «قُرئت» من واتساب،
 * لا قبولُ Meta ولا «أُرسلت».
 */
class OneCustomerWhoCannotReceiveIsNotABrokenLinkTest extends TestCase
{
    use RefreshDatabase;

    private const BAD = '96878000968';

    private Business $business;

    private User $owner;

    private int $seq = 0;

    private WhatsAppConnection $shared;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        // متجرٌ جاهزٌ فعلًا — وإلّا لم يُقس تبدُّلُ «جاهز»
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        $this->shared = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');
        WhatsAppTemplateMapping::query()
            ->update(['meta_status' => WhatsAppTemplates::APPROVED, 'meta_synced_at' => now()]);

        $this->business = $this->shop('زهور مسقط');
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function shop(string $name): Business
    {
        return Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'whatsapp_enabled' => true]);
    }

    /** رسالةٌ صادرة: `$minutes` منذ خرجت، وحالُها، ولمن */
    private function message(string $status, string $to, int $minutes = 60, ?string $code = null, ?Business $shop = null): WhatsAppMessage
    {
        $at = now()->subMinutes($minutes);

        return WhatsAppMessage::create([
            'business_id' => ($shop ?? $this->business)->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'whatsapp_connection_id' => $this->shared->id,
            'event_type' => 'order_confirmed',
            'template_name' => 'abaad_order_confirmed',
            'direction' => 'outbound',
            'recipient_phone' => $to,
            'provider_message_id' => $status === WhatsAppStatus::QUEUED ? null : 'wamid.'.(++$this->seq),
            'dedupe_key' => 'k'.uniqid('', true),
            'status' => $status,
            'error_code' => $code,
            'error_message' => $code === '131026' ? 'Message undeliverable' : ($code ? 'API access blocked.' : null),
            'sent_at' => $status === WhatsAppStatus::QUEUED ? null : $at,
            'delivered_at' => in_array($status, WhatsAppHealth::ARRIVED, true) ? $at->copy()->addSeconds(2) : null,
            'read_at' => $status === WhatsAppStatus::READ ? $at->copy()->addSeconds(30) : null,
            'failed_at' => $status === WhatsAppStatus::FAILED ? $at->copy()->addSecond() : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function step(?Business $shop = null): ?array
    {
        return collect(WhatsAppFeature::readiness(($shop ?? $this->business)->fresh())['steps'])->firstWhere('key', 'delivery');
    }

    /** أرقامٌ مختلفةٌ تصلها رسائلُها: 9689100000N */
    private function delivered(int $n, int $minutes = 120): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->message($i % 2 ? WhatsAppStatus::READ : WhatsAppStatus::DELIVERED, '9689100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $minutes + $i);
        }
    }

    /* ══════════════ 1. حالُ RIBBON نفسُه ══════════════ */

    /** 14 وصلت ورسالتان لم تصلا عميلًا واحدًا: الربطُ يعمل، والعميلُ يُقال */
    public function test_fourteen_delivered_and_two_failed_to_one_customer_is_partial_not_broken(): void
    {
        $this->assertTrue(WhatsAppFeature::readiness($this->business->fresh())['ready'], 'المتجر غير جاهزٍ أصلًا — فالحارس لا يقيس شيئًا');

        $this->delivered(14);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 90, '131026');
        $this->message(WhatsAppStatus::FAILED, self::BAD, 30, '131026');

        $d = WhatsAppHealth::delivery($this->business->id);

        $this->assertSame(WhatsAppHealth::PARTIAL, $d['state']);
        $this->assertSame(16, $d['attempts']);
        $this->assertSame(14, $d['delivered']);
        $this->assertSame(2, $d['failed']);
        $this->assertSame(1, $d['customers'], 'رسالتان لعميلٍ واحد عُدّتا عميلين');

        $step = $this->step();
        $this->assertTrue($step['done'], 'أُطفئت مرحلة الوصول لعميلٍ واحدٍ لا يستقبل رقمُه');
        $this->assertStringContainsString('واتساب يعمل', (string) $step['detail']);
        $this->assertTrue(WhatsAppFeature::readiness($this->business->fresh())['ready'], 'ظهر 7/8 والربطُ سليم');
        $this->assertNull(WhatsAppHealth::alert($this->business->id), 'أُنذر بعطبٍ في الربط لرقم عميل');

        // والعميلُ يُقال مرّةً واحدة، برقمٍ محجوب، وبسببٍ عربيٍّ لا يجزم
        $this->assertCount(1, $d['failures']);
        $f = $d['failures'][0];
        $this->assertSame(2, $f['count']);
        $this->assertSame('968•••••968', $f['phone']);
        $this->assertStringNotContainsString('78000', $f['phone'], 'كُشف رقمُ العميل');
        $this->assertSame('131026', $f['code']);
        $this->assertSame('Message undeliverable', $f['error']);
        $this->assertStringContainsString('تعذّر تسليم الرسالة إلى هذا الرقم', (string) $f['explain']);
        $this->assertStringContainsString('قد يكون', (string) $f['explain'], 'جُزم بسببٍ لا تقوله Meta');
        $this->assertFalse($f['ours']);
        $this->assertNotNull($f['sent_at']);
        $this->assertNotNull($f['failed_at']);
    }

    /** والجرسُ يُنبّه بلون التحذير لا بلون العطب */
    public function test_the_bell_warns_without_calling_it_a_broken_link(): void
    {
        $this->delivered(3);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 30, '131026');

        $this->actingAs($this->owner);
        $bell = collect(Demo::notifications())->firstWhere('key', 'wa-delivery');

        $this->assertNotNull($bell, 'لم يُقل للتاجر إنّ عميلًا لم تصله رسالتُه');
        $this->assertSame('warning', $bell['color']);
        $this->assertStringContainsString('واتساب يعمل', $bell['text']);
    }

    /* ══════════════ 2. نجاحٌ بعد فشل ══════════════ */

    /** عميلٌ وصلته رسالةٌ بعد التي فشلت لم يعد ينتظر شيئًا */
    public function test_a_later_delivery_to_the_same_customer_closes_his_failure(): void
    {
        $this->delivered(2);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 90, '131026');
        $this->message(WhatsAppStatus::DELIVERED, self::BAD, 10);

        $d = WhatsAppHealth::delivery($this->business->id);

        $this->assertSame(WhatsAppHealth::OK, $d['state']);
        $this->assertSame(0, $d['customers']);
        $this->assertSame([], $d['failures']);
        $this->assertSame(1, $d['failed'], 'أُخفي الفشلُ من العدّ');
    }

    /** وعطبٌ عند أبعاد عبر — نجح بعده شيء — لا يُطفئ المرحلة، ويبقى مَن لم تصله رسالتُه */
    public function test_a_platform_fault_that_passed_leaves_only_its_customers(): void
    {
        $this->message(WhatsAppStatus::FAILED, self::BAD, 300, '200');
        $this->message(WhatsAppStatus::DELIVERED, '96891112222', 20);

        $d = WhatsAppHealth::delivery($this->business->id);

        $this->assertSame(WhatsAppHealth::PARTIAL, $d['state']);
        $this->assertTrue($this->step()['done']);
        $this->assertTrue($d['failures'][0]['ours'], 'نُسب حجبُ التطبيق إلى رقم العميل');
    }

    /* ══════════════ 3. فشلٌ حديث والربطُ يعمل ══════════════ */

    /** آخرُ ما جرى فشلٌ لرقمٍ لا يستقبل، وقبله وصلت رسائل: الربطُ يعمل */
    public function test_a_recent_failure_after_deliveries_keeps_the_step(): void
    {
        $this->message(WhatsAppStatus::READ, '96891112222', 50);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 5, '131026');

        $this->assertSame(WhatsAppHealth::PARTIAL, WhatsAppHealth::delivery($this->business->id)['state']);
        $this->assertTrue($this->step()['done']);
    }

    /** لكنّ عطبًا عند أبعاد لم ينجح بعده شيء انقطاعٌ — ولو وصلت رسائلُ قبله */
    public function test_an_unrecovered_platform_fault_after_deliveries_is_failing(): void
    {
        $this->message(WhatsAppStatus::DELIVERED, '96891112222', 120);
        $this->message(WhatsAppStatus::FAILED, '96891113333', 10, '200');

        $this->assertSame(WhatsAppHealth::FAILING, WhatsAppHealth::delivery($this->business->id)['state']);
        $this->assertFalse($this->step()['done']);
        $this->assertTrue($this->step()['theirs'], 'نُسب حجبُ التطبيق إلى التاجر');
    }

    /**
     * رسائلُ وصلت، ثمّ فشلٌ لعميلٍ برمز 131026، ثمّ عطبٌ عند أبعاد لم ينجح بعده شيء.
     *
     * خطرٌ قائمٌ يُقال بلونه — بلا «لم تصل أيٌّ» وقد وصل بعضُها. ثمّ تسليمٌ بعد
     * العطب يُعيد المرحلة، ويبقى العميلان في القائمة. وعطبُ جارٍ لا يُعيد الخطر هنا.
     */
    public function test_deliveries_before_an_internal_fault_are_not_called_none(): void
    {
        $this->delivered(3);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 30, '131026');
        $this->message(WhatsAppStatus::FAILED, '96891113333', 10, '200');

        $d = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::FAILING, $d['state'], 'عطبٌ عند أبعاد لم ينجح بعده شيء ولا خطر');
        $this->assertSame(3, $d['delivered']);
        $this->assertSame(2, $d['failed']);
        $this->assertSame(2, $d['customers']);

        $alert = WhatsAppHealth::alert($this->business->id);
        $this->assertSame('danger', $alert['level']);
        $this->assertStringNotContainsString('أيٌّ', $alert['text'], 'قيل «لم تصل أيٌّ» وقد وصلت 3');
        $this->assertStringContainsString('العطب عند أبعاد', $alert['text']);

        $step = $this->step();
        $this->assertFalse($step['done']);
        $this->assertTrue($step['theirs']);
        $this->assertStringNotContainsString('أيٌّ', (string) $step['fix']);

        $this->actingAs($this->owner);
        $bell = collect(Demo::notifications())->firstWhere('key', 'wa-delivery');
        $this->assertSame('danger', $bell['color']);
        $this->assertStringNotContainsString('أيٌّ', $bell['text']);

        // وجارٌ عطبُه أحدثُ من كلّ شيءٍ هنا لا يمسّ هذا المتجر
        $neighbour = $this->shop('جار');
        $this->message(WhatsAppStatus::FAILED, '96899990000', 1, '200', $neighbour);

        // ثمّ وصلت رسالةٌ بعد العطب: عبر العطب، ويبقى من لم تصله رسالتُه
        $this->message(WhatsAppStatus::DELIVERED, '96891114444', 3);

        $d = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::PARTIAL, $d['state']);
        $this->assertSame(4, $d['delivered']);
        $this->assertSame(2, $d['failed']);
        $this->assertSame(2, $d['customers']);
        $this->assertSame(['200', '131026'], array_column($d['failures'], 'code'), 'الأحدثُ فشلًا ليس أوّلًا');
        $this->assertTrue($this->step()['done']);
        $this->assertNull(WhatsAppHealth::alert($this->business->id));
        $this->assertSame('warning', collect(Demo::notifications())->firstWhere('key', 'wa-delivery')['color']);

        $this->assertSame(WhatsAppHealth::FAILING, WhatsAppHealth::delivery($neighbour->id)['state']);
        $this->assertSame(0, WhatsAppHealth::delivery($neighbour->id)['delivered']);
    }

    /* ══════════════ 4. كلُّها تفشل ══════════════ */

    public function test_when_everything_fails_it_is_said_loudly(): void
    {
        $this->message(WhatsAppStatus::FAILED, self::BAD, 60, '131026');
        $this->message(WhatsAppStatus::FAILED, '96891113333', 30, '131026');

        $d = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::FAILING, $d['state']);
        $this->assertSame(2, $d['customers']);

        $step = $this->step();
        $this->assertFalse($step['done'], 'قيل «تصل» ولم يصل شيء');
        $this->assertNull($step['detail']);
        $this->assertStringContainsString('قد يكون الربط بواتساب قائمًا', (string) $step['fix']);
        $this->assertFalse(WhatsAppFeature::readiness($this->business->fresh())['ready']);

        $alert = WhatsAppHealth::alert($this->business->id);
        $this->assertSame('danger', $alert['level']);
        $this->assertSame(2, $alert['count']);
    }

    /* ══════════════ 5. لا رسائل ══════════════ */

    /** لم يخرج شيء: لا «يصل» ولا «لا يصل» */
    public function test_no_recent_messages_is_untested(): void
    {
        // وما خرج قبل النافذة تاريخ، وما لم يُرسَل أصلًا لم يُجرَّب تسليمُه
        $this->message(WhatsAppStatus::FAILED, self::BAD, (WhatsAppHealth::WINDOW_HOURS + 1) * 60, '131026');
        $this->message(WhatsAppStatus::SKIPPED, self::BAD, 10);

        $d = WhatsAppHealth::delivery($this->business->id);

        $this->assertSame(WhatsAppHealth::UNTESTED, $d['state']);
        $this->assertSame(0, $d['attempts']);
        $this->assertNull($this->step(), 'خطوةٌ تقول شيئًا عن تسليمٍ لم يُجرَّب');
        $this->assertTrue(WhatsAppFeature::readiness($this->business->fresh())['ready']);
    }

    /* ══════════════ 6. قُبلت ثمّ فشلت عبر الإشعار ══════════════ */

    /** قبولُ Meta ليس وصولًا: رسالةٌ أُرسلت ثمّ جاء فشلُها من الإشعار */
    public function test_accepted_then_failed_by_webhook_counts_as_failed(): void
    {
        config(['whatsapp.app_secret' => 'test-app-secret']);
        $this->message(WhatsAppStatus::READ, '96891112222', 50);
        $m = $this->message(WhatsAppStatus::SENT, self::BAD, 5);

        $this->assertSame(WhatsAppHealth::OK, WhatsAppHealth::delivery($this->business->id)['state']);

        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'ABAAD-PN'],
            'statuses' => [[
                'id' => $m->provider_message_id, 'status' => 'failed', 'timestamp' => (string) now()->timestamp,
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
            ]],
        ]]]]]]);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body)->assertOk();

        $this->assertSame(WhatsAppStatus::FAILED, $m->fresh()->status);

        $d = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::PARTIAL, $d['state']);
        $this->assertSame(1, $d['delivered']);
        $this->assertSame(1, $d['failed']);
        $this->assertSame('131026', $d['failures'][0]['code']);
    }

    /* ══════════════ 7. «أُرسلت» وحدها ══════════════ */

    /** خرجت ولم يؤكَّد تسليمُ شيء: لا يُقال «تصل» */
    public function test_sent_without_delivered_is_not_proof(): void
    {
        $this->message(WhatsAppStatus::SENT, '96891112222', 20);
        $this->message(WhatsAppStatus::QUEUED, '96891113333', 1);

        $d = WhatsAppHealth::delivery($this->business->id);

        $this->assertSame(WhatsAppHealth::UNCONFIRMED, $d['state']);
        $this->assertSame(0, $d['delivered'], 'عُدّت «أُرسلت» وصولًا');
        $this->assertSame(2, $d['pending']);

        $step = $this->step();
        $this->assertFalse($step['done'], 'قيل «تصل» بلا تأكيد تسليم');
        $this->assertTrue($step['theirs'], 'انتظارُ التأكيد عُرض عطبًا على التاجر');
        $this->assertNull(WhatsAppHealth::alert($this->business->id), 'أُنذر بعطبٍ ولم يفشل شيء');
    }

    /* ══════════════ 8. ردود الموظّفين ══════════════ */

    /** ردٌّ وصل يُعدّ وحده، ولا يُخفي أنّ الإشعارات لا تصل — و«حُفظ» ليس وصولًا */
    public function test_staff_replies_are_counted_apart_from_notifications(): void
    {
        $this->message(WhatsAppStatus::FAILED, self::BAD, 30, '131026');

        $chat = WhatsAppChat::create([
            'business_id' => $this->business->id, 'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'phone_number_id' => 'ABAAD-PN', 'customer_phone' => '96891112222',
            'last_message_at' => now(), 'last_direction' => WhatsAppChatMessage::OUT,
        ]);
        foreach ([WhatsAppStatus::DELIVERED, WhatsAppStatus::SENT, WhatsAppStatus::QUEUED] as $i => $status) {
            WhatsAppChatMessage::create([
                'business_id' => $this->business->id, 'chat_id' => $chat->id,
                'direction' => WhatsAppChatMessage::OUT, 'body' => 'ردّ', 'status' => $status,
                'wamid' => 'wamid.reply'.$i, 'sender_user_id' => $this->owner->id,
                'sent_at' => now()->subMinutes(5), 'delivered_at' => $status === WhatsAppStatus::DELIVERED ? now()->subMinutes(4) : null,
            ]);
        }

        $d = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::FAILING, $d['state'], 'ردُّ موظّفٍ أخفى أنّ الإشعارات لا تصل');

        $r = WhatsAppHealth::replies($this->business->id);
        $this->assertSame(3, $r['attempts']);
        $this->assertSame(1, $r['delivered'], 'عُدّ ردٌّ محفوظٌ أو مُرسَلٌ وصولًا');
    }

    /* ══════════════ 9. عزل المتاجر ══════════════ */

    public function test_a_neighbours_deliveries_are_not_counted_here(): void
    {
        $neighbour = $this->shop('جار');
        $this->message(WhatsAppStatus::FAILED, '96899990000', 30, '131026', $neighbour);
        $this->message(WhatsAppStatus::DELIVERED, '96899990001', 30, null, $neighbour);
        $this->message(WhatsAppStatus::DELIVERED, '96891112222', 40);

        $mine = WhatsAppHealth::delivery($this->business->id);
        $this->assertSame(WhatsAppHealth::OK, $mine['state']);
        $this->assertSame(1, $mine['attempts']);
        $this->assertSame([], $mine['failures']);

        $theirs = WhatsAppHealth::delivery($neighbour->id);
        $this->assertSame(WhatsAppHealth::PARTIAL, $theirs['state']);
        $this->assertSame(1, $theirs['customers']);
    }

    /* ══════════════ 10. مَن يرى ══════════════ */

    /** الشاشةُ لمن مُنح التكاملات، والأرقامُ محجوبة، والردودُ لمن يرى المحادثات */
    public function test_only_permitted_staff_see_the_details(): void
    {
        $this->message(WhatsAppStatus::DELIVERED, '96891112222', 40);
        $this->message(WhatsAppStatus::FAILED, self::BAD, 30, '131026');

        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard'],
        ]);
        $this->actingAs($cashier)->get(route('admin.integrations.whatsapp'))->assertForbidden();

        $connector = User::create([
            'business_id' => $this->business->id, 'name' => 'مهيّئ', 'email' => 'i@abaad.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'integrations'],
        ]);
        $response = $this->actingAs($connector)->get(route('admin.integrations.whatsapp'))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('automation.delivery.state', WhatsAppHealth::PARTIAL)
            ->where('automation.delivery.failures.0.phone', '968•••••968')
            // لا يرى المحادثات، فلا عدَّ لردودها؛ ولا يفتح التسويق، فلا رابطَ لسجلّه
            ->where('automation.delivery.replies', null)
            ->where('automation.delivery.log_url', null)
            ->etc());

        $this->assertStringNotContainsString(self::BAD, $response->getContent(), 'كُشف رقمُ العميل كاملًا');
    }
}
