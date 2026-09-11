<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\Demo;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppHealth;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رسالةٌ لم تصل تُقال — لا تُكتب في جدولٍ لا يفتحه أحد.
 *
 * ═══ العطب ═══
 *
 * مراحلُ ربط واتساب أربعٌ، وكلُّها تقرأ **إعدادًا**: مفعَّلٌ في المنصّة،
 * ومفعَّلٌ لحسابك، وباقتُك تشمله، والرقمُ جاهز. ولا واحدةَ منها تقرأ
 * **نتيجة**.
 *
 * فحين حجبت Meta تطبيقَنا بقيت الأربعُ خضراء و«جاهز» فوقها، وكلُّ رسالةٍ
 * تخرج تُردّ وتُكتب `failed` في `whatsapp_messages` — جدولٌ لا تفتحه شاشة.
 * فباع التاجر، ولم يصل زبونَه شيء، ونظر إلى لوحته فطمأنته.
 *
 * **ولم يعرف أنّ شيئًا انكسر حتّى سأل.** وطمأنينةٌ كاذبة أسوأ من تحذيرٍ
 * كاذب: الثاني يُزعج، والأوّل يُنيم.
 */
class ASilentFailureIsWorseThanALoudOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        /*
         * ومتجرٌ **جاهزٌ فعلًا** — وإلّا لم يُقس شيء.
         *
         * أوّلُ صيغةٍ لهذا الحارس قارنت «جاهز» قبل الفشل وبعده على متجرٍ لم
         * يكن جاهزًا أصلًا: فبقيت `false` في الحالين ومرّ الحارسُ وهو لا
         * يحرس. فتُبنى المنصّةُ والوصلةُ والحصّة أوّلًا.
         */
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->business = Business::create([
            'name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط', 'whatsapp_enabled' => true,
        ]);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function message(string $status, ?string $code = null, ?string $error = null, int $agoHours = 1): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'business_id' => $this->business->id,
            'source_mode' => 'abaad_shared',
            'event_type' => 'order_created',
            'direction' => 'outbound',
            'dedupe_key' => 'k'.uniqid('', true),
            'status' => $status,
            'error_code' => $code,
            'error_message' => $error,
            'failed_at' => $status === 'failed' ? now()->subHours($agoHours) : null,
            'sent_at' => $status !== 'failed' ? now()->subHours($agoHours) : null,
            'created_at' => now()->subHours($agoHours),
            'updated_at' => now()->subHours($agoHours),
        ]);
    }

    /* ══════════════ متى يُنذَر ══════════════ */

    /** متجرٌ لم يُرسل شيئًا لا يُقال له «لا تصل رسائلك» — لم تُرسَل رسالةٌ لتصل */
    public function test_a_shop_that_never_sent_is_not_alarmed(): void
    {
        $this->assertNull(WhatsAppHealth::alert($this->business->id));
    }

    /** ونجاحٌ خالصٌ لا يُنذر */
    public function test_successful_sends_raise_nothing(): void
    {
        $this->message('delivered');
        $this->message('sent');

        $this->assertNull(WhatsAppHealth::alert($this->business->id));
    }

    /** وفشلٌ يُنذر — وهذا هو العطبُ كلُّه */
    public function test_a_failed_message_is_announced(): void
    {
        $this->message('failed', '200', 'API access blocked.');

        $alert = WhatsAppHealth::alert($this->business->id);

        $this->assertNotNull($alert, 'رسالةٌ لم تصل ولا إنذار');
        $this->assertSame(1, $alert['count']);
    }

    /**
     * وفشلٌ تلاه نجاحٌ لا يُنذر — العطبُ عبر.
     *
     * وإنذارٌ عن عطبٍ أُصلح يجعل التاجر يلاحق ما لا يوجد، ثمّ يتوقّف عن
     * قراءة الإنذارات كلِّها.
     */
    public function test_a_failure_followed_by_success_is_past(): void
    {
        $this->message('failed', '200', 'API access blocked.', agoHours: 5);
        $this->message('delivered', agoHours: 1);

        $this->assertNull(WhatsAppHealth::alert($this->business->id), 'أُنذر عن عطبٍ عبر');
    }

    /** وفشلٌ قديمٌ خارج النافذة تاريخٌ لا حال */
    public function test_an_old_failure_is_history(): void
    {
        $this->message('failed', '200', 'API access blocked.', agoHours: WhatsAppHealth::WINDOW_HOURS + 3);

        $this->assertNull(WhatsAppHealth::alert($this->business->id));
    }

    /* ══════════════ على مَن العطب ══════════════ */

    /**
     * تطبيقٌ محجوبٌ عطبُنا نحن — ولا يُقال للتاجر «راجع أرقام زبائنك».
     *
     * لومٌ في غير محلّه يجعله يلاحق ما لا يملك إصلاحه، ويظنّ بيانات زبائنه
     * خاطئةً وهي سليمة.
     */
    public function test_a_blocked_app_is_our_fault_not_his(): void
    {
        $this->message('failed', '200', 'API access blocked.');

        $alert = WhatsAppHealth::alert($this->business->id);

        $this->assertTrue($alert['ours'], 'نُسب حجبُ التطبيق إلى التاجر');
        $this->assertStringNotContainsString('راجع أرقام', $alert['text']);
        $this->assertStringContainsString('العطب عند أبعاد', $alert['text']);
    }

    /** ورقمٌ خاطئٌ عطبُه هو — فيُقال له ما يفعل */
    public function test_a_bad_number_is_his_to_fix(): void
    {
        $this->message('failed', '131026', 'Message undeliverable');

        $alert = WhatsAppHealth::alert($this->business->id);

        $this->assertFalse($alert['ours'], 'حُمّلت أبعادُ رقمًا خاطئًا لزبون');
        $this->assertStringContainsString('راجع أرقام', $alert['text']);
    }

    /* ══════════════ أين يُقرأ ══════════════ */

    /**
     * و«جاهز» لا تُكتب فوق أربع علاماتٍ خضراء بينما لا يصل شيء.
     *
     * وهذا أثقلُ حارسٍ في الملفّ: رأسٌ يقول «تمّ الربط — رسائلك تخرج» يُقرأ
     * قبل أيّ سطرٍ أسفلَ منه.
     */
    public function test_ready_is_false_while_messages_fail(): void
    {
        $before = WhatsAppFeature::readiness($this->business->fresh());
        $this->assertTrue($before['ready'], 'المتجر غير جاهزٍ أصلًا — فالحارس لا يقيس شيئًا');

        $this->message('failed', '200', 'API access blocked.');

        $after = WhatsAppFeature::readiness($this->business->fresh());

        $this->assertNotSame(
            $before['ready'], $after['ready'],
            'حالُ «جاهز» لم تتغيّر وكلُّ الرسائل تفشل',
        );
        $this->assertFalse($after['ready'], 'قالت الشاشة «جاهز» ولا رسالةَ تصل');
    }

    /** وخطوةُ الوصول تُعرض بنصّ العطب — لا «لم تُرسَل» مجملةً */
    public function test_the_steps_carry_the_delivery_truth(): void
    {
        $this->message('failed', '200', 'API access blocked.');

        $steps = collect(WhatsAppFeature::readiness($this->business->fresh())['steps']);
        $step = $steps->firstWhere('key', 'delivery');

        $this->assertNotNull($step, 'لا خطوةَ تقرأ نتيجة الإرسال');
        $this->assertFalse($step['done']);
        $this->assertTrue($step['theirs'], 'نُسب العطبُ إلى التاجر وهو عندنا');
        $this->assertStringContainsString('API access blocked', (string) $step['fix']);
    }

    /** ولا تُعرض الخطوةُ لمن لم يُرسل — خطوةٌ لا تعني شيئًا تُزاحم ما يعني */
    public function test_no_delivery_step_before_any_attempt(): void
    {
        $steps = collect(WhatsAppFeature::readiness($this->business->fresh())['steps']);

        $this->assertNull($steps->firstWhere('key', 'delivery'));
    }

    /**
     * ويصل الجرس — فمن لا يفتح شاشة واتساب يعرف.
     *
     * وهو الفارقُ العمليّ: التاجر يفتح لوحته كلَّ يوم ولا يفتح شاشة الربط
     * إلّا حين يشكّ. ولو بقي الخبرُ هناك لَما وصله حتّى يسأل.
     */
    public function test_the_bell_carries_it(): void
    {
        $this->message('failed', '200', 'API access blocked.');

        $this->actingAs($this->owner);
        $keys = collect(Demo::notifications())->pluck('key');

        $this->assertTrue($keys->contains('wa-delivery'), 'لم يصل الجرسَ خبرُ رسالةٍ لم تصل');
    }

    /** ولا يُزعج الجرسُ متجرًا رسائلُه تصل */
    public function test_the_bell_stays_quiet_when_all_is_well(): void
    {
        $this->message('delivered');

        $this->actingAs($this->owner);
        $keys = collect(Demo::notifications())->pluck('key');

        $this->assertFalse($keys->contains('wa-delivery'));
    }

    /** ورسائلُ متجرٍ آخر لا تُنذر هذا */
    public function test_a_neighbour_failure_is_not_his(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);

        WhatsAppMessage::create([
            'business_id' => $neighbour->id, 'source_mode' => 'abaad_shared',
            'event_type' => 'order_created', 'direction' => 'outbound',
            'dedupe_key' => 'n1', 'status' => 'failed', 'error_code' => '200',
            'error_message' => 'API access blocked.', 'failed_at' => now(),
        ]);

        $this->assertNull(WhatsAppHealth::alert($this->business->id), 'أُنذر متجرٌ بعطب جاره');
    }

    /** ومديرُ المنصّة يُخبَر مع كلّ نشر — فعطبٌ يصيب الجميع لا ينتظر شكوى */
    public function test_the_deploy_check_reads_it(): void
    {
        $source = file_get_contents(app_path('Console/Commands/Preflight.php'));

        $this->assertStringContainsString('WhatsAppHealth::platform()', $source, 'النشر لا يفحص وصول الرسائل');
    }

    /** وحالُ المنصّة تعدّ المتاجر لا الرسائل وحدها */
    public function test_the_platform_view_counts_shops(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $this->message('failed', '200', 'API access blocked.');

        WhatsAppMessage::create([
            'business_id' => $neighbour->id, 'source_mode' => 'abaad_shared',
            'event_type' => 'order_created', 'direction' => 'outbound',
            'dedupe_key' => 'n2', 'status' => 'failed', 'error_code' => '200',
            'error_message' => 'API access blocked.', 'failed_at' => now(),
        ]);

        $p = WhatsAppHealth::platform();

        $this->assertSame(2, $p['failed']);
        $this->assertSame(2, $p['shops']);
        $this->assertSame(2, $p['ours'], 'لم يُميَّز ما سببُه عندنا');
    }
}
