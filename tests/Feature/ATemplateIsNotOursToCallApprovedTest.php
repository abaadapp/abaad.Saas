<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplateMapping;
use App\Support\OrderStatus;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppLog;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * القالبُ لا نُسمّيه نحن معتمَدًا — ميتا تقولها.
 *
 * ═══ العطب كما وقع ═══
 *
 * ربطنا الرقم، وأنشأنا القوالبَ الستّة عند ميتا، فصارت `PENDING`. وشاشةُ
 * كلّ تاجرٍ تقول «جاهز» بأربع علاماتٍ خضراء — لأنّ الخطواتِ الأربعَ تقرأ
 * **إعدادًا**: مفعَّلٌ في المنصّة، مفعَّلٌ لحسابك، باقتُك تشمله، الرقمُ
 * مربوط. ولا واحدةَ منها تسأل ميتا: **أتقبلين هذه الرسالة؟**
 *
 * وميتا لا تقبل نصًّا حرًّا في رسالةٍ يبدؤها العمل — قالبًا معتمَدًا باسمه.
 * فأوّلُ طلبٍ يُؤكَّد: يُبنى الصفّ، وتُحجز الحصّة، ويُنادى ميتا، فتردُّه.
 * وتُقيَّد `failed` بنصٍّ إنجليزيّ، ويحمرّ جرسُ اللوحة بعطبٍ لا يملك التاجر
 * إصلاحه — وينتظر الزبون رسالةً لا تأتي.
 *
 * و`enabled` في جدولنا مقبضُنا نحن لا اعتمادُ ميتا. فصار الاعتمادُ عمودًا
 * يُقرأ منها ويُكتب كما قالته.
 *
 * ═══ وثلاثُ حالاتٍ لا اثنتان ═══
 *
 * معتمَد · ليس معتمَدًا · **لم نسأل بعد**. والثالثةُ ليست الثانية: «لا
 * نعرف» غير «لا». ولو قُرئت رفضًا لَأطفأ الترحيلُ إشعاراتِ كلّ متجرٍ في
 * اللحظة التي يجري فيها — قبل أن يجري أوّلُ مزامنة.
 */
class ATemplateIsNotOursToCallApprovedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط', 'whatsapp_enabled' => true,
        ]);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'wa_on_ready'], ['value' => '1']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'waba_id' => 'WABA-1',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);

        WhatsAppTemplates::seedPlatformDefaults('ar');

        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبون', 'phone' => '99887766',
        ]);

        $this->order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'number' => 'ORD-1',
            'status' => OrderStatus::CONFIRMED, 'total' => 10, 'subtotal' => 10,
        ]);
    }

    /** حالُ كلّ قوالب أبعاد كما لو قالتها ميتا */
    private function metaSays(string $status): void
    {
        WhatsAppTemplateMapping::query()->platform()->update(['meta_status' => $status, 'meta_synced_at' => now()]);
    }

    /** يُطلق حدثَ «جاهز» ويُرجع الصفَّ المكتوب */
    private function fire(): ?WhatsAppMessage
    {
        $this->order->update(['status' => OrderStatus::READY]);
        WhatsAppAutomation::handle($this->order->fresh(), OrderStatus::READY);

        return WhatsAppMessage::where('event_type', WhatsAppEvent::ORDER_READY)->latest('id')->first();
    }

    /* ═════════════ لا يُنادى بقالبٍ لم تعتمده ميتا ═════════════ */

    /**
     * قالبٌ ينتظر المراجعة لا يخرج به نداء.
     *
     * و`Http::preventStrayRequests` هو نصفُ الحارس: لو خرج نداءٌ إلى ميتا
     * لَسقط الاختبار قبل أن يُقاس الصفّ.
     */
    public function test_a_template_meta_has_not_approved_is_never_called(): void
    {
        $this->metaSays('PENDING');

        $message = $this->fire();

        $this->assertNotNull($message);
        $this->assertSame(WhatsAppStatus::SKIPPED, $message->status);
        $this->assertSame(WhatsAppStatus::SKIP_NO_TEMPLATE, $message->error_code);
    }

    /** ورفضٌ صريحٌ من ميتا كذلك — لا فرقَ عندنا بين ما ينتظر وما رُدّ */
    public function test_a_rejected_template_is_never_called_either(): void
    {
        $this->metaSays('REJECTED');

        $this->assertSame(WhatsAppStatus::SKIPPED, $this->fire()->status);
    }

    /**
     * ولا يُحاسَب التاجر على رسالةٍ لم تخرج.
     *
     * تركُ النداء يخرج كان يحجز حصّةً ثمّ يردُّها عند الفشل — والصفُّ يبقى
     * `failed`. والمنعُ قبله لا يحجز أصلًا.
     */
    public function test_no_quota_is_touched_by_a_template_that_cannot_be_sent(): void
    {
        $this->metaSays('PENDING');
        $this->fire();

        $this->assertSame(0, WhatsAppQuota::snapshot($this->shop)['used']);
        $this->assertFalse((bool) WhatsAppMessage::latest('id')->value('quota_consumed'));
    }

    /** والمعتمَدُ يخرج كما كان — المنعُ على غيره وحدَه */
    public function test_an_approved_template_still_goes_out(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $this->metaSays(WhatsAppTemplates::APPROVED);

        $this->assertSame(WhatsAppStatus::SENT, $this->fire()->status);
    }

    /**
     * وما لم يُسأل عنه بعد لا يُعامَل معاملةَ المرفوض.
     *
     * عمودٌ جديد على صفوفٍ قائمة يبدأ فارغًا. ولو قُرئ الفارغُ رفضًا لَأطفأ
     * الترحيلُ إشعاراتِ كلّ متجرٍ في لحظته — عطبٌ أكبرُ من الذي يُصلحه.
     */
    public function test_a_template_never_checked_is_not_treated_as_refused(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        /* ولا `metaSays`: الحالُ فارغٌ كما يتركه الترحيل */
        $this->assertSame(WhatsAppStatus::SENT, $this->fire()->status);
    }

    /* ═════════════ والشاشةُ لا تقول «جاهز» وهي ليست كذلك ═════════════ */

    /** أربعُ خطواتٍ خضراء فوق قوالبَ تنتظر ليست «جاهز» */
    public function test_readiness_is_not_green_while_meta_still_reviews(): void
    {
        $this->metaSays('PENDING');

        $r = WhatsAppFeature::readiness($this->shop);

        $this->assertFalse($r['ready'], 'الشاشة تقول «جاهز» ولا قالبَ معتمَد');
        $this->assertFalse($this->step($r, 'templates')['done']);
    }

    /** وتصير خضراء حين تعتمدها ميتا */
    public function test_readiness_turns_green_once_meta_approves(): void
    {
        $this->metaSays(WhatsAppTemplates::APPROVED);

        $r = WhatsAppFeature::readiness($this->shop);

        $this->assertTrue($r['ready']);
        $this->assertTrue($this->step($r, 'templates')['done']);
    }

    /**
     * و«لم نسأل» تُقال كما هي — لا تُلبَس ثوبَ «رُفض».
     *
     * إخبارُ التاجر أنّ واتساب لم يعتمد قوالبه ونحن لم نسأل أصلًا تقريرُ
     * حالٍ كاذب — يجعله ينتظر ما قد يكون تمّ.
     */
    public function test_unknown_is_told_as_unknown_not_as_refused(): void
    {
        $unchecked = $this->step(WhatsAppFeature::readiness($this->shop), 'templates');
        $this->metaSays('PENDING');
        $refused = $this->step(WhatsAppFeature::readiness($this->shop), 'templates');

        $this->assertNotSame($refused['fix'], $unchecked['fix'], 'المجهولُ يُقرأ رفضًا');
        $this->assertStringContainsString('لم نقرأ', (string) $unchecked['fix']);
    }

    /* ═════════════ والمزامنةُ تكتب ما قالته ميتا ═════════════ */

    /** ما ترُدّه ميتا يُكتب بحروفه */
    public function test_the_sync_writes_exactly_what_meta_said(): void
    {
        Http::fake(['*message_templates*' => Http::response(['data' => [
            ['name' => 'abaad_order_ready', 'status' => 'APPROVED'],
            ['name' => 'abaad_order_confirmed', 'status' => 'PENDING'],
        ]], 200)]);

        $result = WhatsAppTemplates::sync(
            WhatsAppConnection::firstOrFail(), WhatsAppMode::OWNER_PLATFORM,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['approved']);
        $this->assertSame('APPROVED', $this->metaStatus(WhatsAppEvent::ORDER_READY));
        $this->assertSame('PENDING', $this->metaStatus(WhatsAppEvent::ORDER_CONFIRMED));
    }

    /** واسمٌ لا تعرفه ميتا يُكتب «مفقود» لا يُترك فارغًا */
    public function test_a_name_meta_does_not_know_is_written_missing(): void
    {
        Http::fake(['*message_templates*' => Http::response(['data' => [
            ['name' => 'abaad_order_ready', 'status' => 'APPROVED'],
        ]], 200)]);

        WhatsAppTemplates::sync(WhatsAppConnection::firstOrFail(), WhatsAppMode::OWNER_PLATFORM);

        $this->assertSame(WhatsAppTemplates::MISSING, $this->metaStatus(WhatsAppEvent::ORDER_DELIVERED));
    }

    /**
     * وفشلُ النداء لا يمحو ما كنّا نعرفه.
     *
     * انقطاعُ شبكةٍ ليس رفضًا من ميتا. ولو كُتب فشلُ الاتّصال حالًا لَأطفأ
     * عطلٌ عابرٌ في الشبكة إشعاراتِ كلّ المتاجر.
     */
    public function test_a_failed_call_never_overwrites_what_we_knew(): void
    {
        $this->metaSays(WhatsAppTemplates::APPROVED);

        Http::fake(['*message_templates*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $result = WhatsAppTemplates::sync(WhatsAppConnection::firstOrFail(), WhatsAppMode::OWNER_PLATFORM);

        $this->assertFalse($result['ok']);
        $this->assertSame(WhatsAppTemplates::APPROVED, $this->metaStatus(WhatsAppEvent::ORDER_READY));
    }

    /**
     * ولا «جاهز» فوق علامةٍ حمراء — أيًّا كانت.
     *
     * وهذا حارسُ المقياسين: «جاهز» كانت تُكتب بشروطٍ بيدها والقائمةُ تعدّ
     * خطواتٍ أخرى، فظهرت خطوةُ القوالب حمراءَ و«جاهز» خضراءُ فوقها. وصارت
     * تُشتقّ من الخطوات — فخطوةٌ تُضاف غدًا تدخل الحسابَ وحدَها.
     */
    public function test_ready_is_never_claimed_above_a_red_step(): void
    {
        foreach (['PENDING', 'REJECTED', WhatsAppTemplates::MISSING, WhatsAppTemplates::APPROVED] as $state) {
            $this->metaSays($state);

            $r = WhatsAppFeature::readiness($this->shop);
            $red = collect($r['steps'])->reject(fn ($s) => $s['done'])->pluck('key')->all();

            $this->assertSame(
                $red === [],
                $r['ready'],
                '«جاهز»='.var_export($r['ready'], true).' وخطواتٌ حمراء: '.implode('، ', $red),
            );
        }
    }

    /* ═════════════ والتاجرُ يقرأ سببًا لا رمزًا ═════════════ */

    /** وسطرُ الدفتر يقول ما جرى بلغةٍ تُقرأ */
    public function test_the_trader_reads_a_sentence_not_a_column_name(): void
    {
        $this->metaSays('PENDING');
        $this->fire();

        $row = WhatsAppLog::row(WhatsAppMessage::latest('id')->firstOrFail());

        $this->assertSame(
            WhatsAppStatus::SKIP_REASONS[WhatsAppStatus::SKIP_NO_TEMPLATE],
            $row['reason'],
        );
    }

    /* ═════════════ ومالكُ المنصّة يقرأ الحال بلا أن يسأل أحدًا ═════════════ */

    /**
     * لوحةُ المنصّة تعرض حالَ كلّ قالبٍ باسمه.
     *
     * والتاجرُ يرى سطرًا واحدًا — هو لا يملك منها شيئًا. أمّا من يُنشئها
     * وينتظر ردّ ميتا فكان لا سبيل له إلى معرفة أيُّها اعتُمد إلّا أن يفتح
     * لوحة ميتا أو يسأل.
     */
    public function test_the_platform_owner_reads_every_templates_verdict(): void
    {
        WhatsAppTemplateMapping::query()->platform()
            ->where('event_type', WhatsAppEvent::ORDER_READY)
            ->update(['meta_status' => WhatsAppTemplates::APPROVED, 'meta_synced_at' => now()]);

        $view = WhatsAppTemplates::platformStatus();

        $this->assertSame(count(WhatsAppEvent::ALL), $view['total']);
        $this->assertSame(1, $view['approved']);
        $this->assertNotNull($view['synced_at'], 'قائمةٌ بلا وقتٍ لا يُعرف أهي حالُ الساعة أم حالُ الأسبوع');

        $row = collect($view['rows'])->firstWhere('name', 'abaad_order_ready');

        $this->assertSame(WhatsAppTemplates::APPROVED, $row['status']);
        /* والحدثُ بالعربية والاسمُ بحروف ميتا — به يُقارَن بلوحتها */
        $this->assertSame(WhatsAppEvent::label(WhatsAppEvent::ORDER_READY), $row['event']);
    }

    /** و«لم يُسأل» تصل الشاشة فارغةً لا تُلبَس حالًا لم تقلها ميتا */
    public function test_an_unasked_template_reaches_the_screen_as_unknown(): void
    {
        $view = WhatsAppTemplates::platformStatus();

        $this->assertNull($view['synced_at']);
        $this->assertSame(0, $view['approved']);
        $this->assertNull($view['rows'][0]['status']);
    }

    /** @return array<string, mixed> */
    private function step(array $readiness, string $key): array
    {
        foreach ($readiness['steps'] as $step) {
            if (($step['key'] ?? null) === $key) {
                return $step;
            }
        }

        $this->fail('لا خطوةَ باسم '.$key);
    }

    private function metaStatus(string $event): ?string
    {
        return WhatsAppTemplateMapping::query()->platform()
            ->where('event_type', $event)->value('meta_status');
    }
}
