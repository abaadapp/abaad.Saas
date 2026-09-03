<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الربط والتفعيل — الخطوة التي تسبق كلَّ ما في شاشة إشعارات واتساب.
 *
 * وكانت الشاشة تفتح على سطرٍ أخضر يقول «مفعّل» بشرطين اثنين، ثمّ تحته مقابضُ
 * الأحداث. فيقرأ التاجر «مفعّل» ويُشعل ما يريد ويمضي — **والمُرسِل يمتنع
 * لسببٍ ثالثٍ لا تعرضه الشاشة**: باقةٌ لا تشمل واتساب، أو وصلةٌ لا تعمل.
 * فينتظر رسائل لا تخرج، ولا شيء يقول له لماذا.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **«جاهز» لا تُقال إلا حين يقبل المُرسِل**:
 * الحالُ يُشتقّ من `blockReason` و`WhatsAppConnections::resolve` نفسيهما، فلا
 * يفترق ما على الشاشة عمّا يفعله النظام.
 */
class WhatsAppReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->business = Business::create([
            'name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط',
            'whatsapp_enabled' => true,
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function readiness(): array
    {
        return WhatsAppFeature::readiness($this->business->fresh());
    }

    private function step(string $key): array
    {
        return collect($this->readiness()['steps'])->firstWhere('key', $key) ?? [];
    }

    private function onPlan(?array $capabilities): void
    {
        $plan = Plan::create([
            'name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => $capabilities,
        ]);

        $this->business->update(['plan_id' => $plan->id]);
        $this->business->refresh();
    }

    /* ===================== «جاهز» تعني أنّ المُرسِل يقبل ===================== */

    public function test_ready_says_exactly_what_the_sender_says(): void
    {
        /*
         * الحارس الأوّل، وهو الذي يمنع عودة العطب كلِّه: مهما تغيّرت الشروط،
         * لا تقول الشاشة «جاهز» إلا حين يقبل المُرسِل فعلًا.
         */
        $platformFlag = fn (string $key, string $value) => Setting::updateOrCreate(
            ['business_id' => null, 'key' => $key], ['value' => $value],
        );

        $cases = [
            'المنصّة مطفأة' => [
                fn () => $platformFlag('whatsapp_enabled', '0'),
                fn () => $platformFlag('whatsapp_enabled', '1'),
            ],
            'الحساب مطفأ' => [
                fn () => $this->business->update(['whatsapp_enabled' => false]),
                fn () => $this->business->update(['whatsapp_enabled' => true]),
            ],
            'الباقة لا تشمله' => [
                fn () => $this->onPlan([]),
                fn () => $this->business->update(['plan_id' => null]),
            ],
            'الرقم المشترك ممنوع' => [
                fn () => $platformFlag(WhatsAppFeature::SHARED_KEY, '0'),
                fn () => $platformFlag(WhatsAppFeature::SHARED_KEY, '1'),
            ],
        ];

        // كلٌّ مفتوح: الحال الأصليّ يجب أن يكون جاهزًا وإلّا لم تقل الحالاتُ شيئًا
        $this->assertMatchesSender('كلٌّ مفتوح');

        foreach ($cases as $name => [$break, $restore]) {
            $break();
            $this->assertMatchesSender($name);
            $restore();
            $this->business->refresh();
        }

        /*
         * وحذفُ الوصلة آخرًا لأنّه لا يُستردّ في السطر نفسه — والترتيب هنا
         * قصدٌ لا صدفة.
         */
        WhatsAppConnection::query()->platform()->delete();
        $this->assertMatchesSender('لا وصلة مشتركة');
    }

    /** «جاهز» على الشاشة يجب أن تساوي «يقبل» عند المُرسِل — في كل حال */
    private function assertMatchesSender(string $case): void
    {
        $business = $this->business->fresh();

        $senderAccepts = WhatsAppFeature::blockReason($business) === null
            && WhatsAppConnections::resolve($business) !== null;

        $this->assertSame(
            $senderAccepts,
            WhatsAppFeature::readiness($business)['ready'],
            "الشاشة تفترق عن المُرسِل حين: {$case}",
        );
    }

    public function test_everything_open_is_ready(): void
    {
        $this->assertTrue($this->readiness()['ready']);

        foreach ($this->readiness()['steps'] as $step) {
            $this->assertTrue($step['done'], "خطوة «{$step['key']}» غير مكتملة وكلُّ شيء مفتوح");
        }
    }

    /* ========================= الخطوات بأسمائها ========================= */

    public function test_a_plan_that_does_not_sell_whatsapp_is_named_as_its_own_step(): void
    {
        /*
         * وهذا هو السبب الثالث الذي لم تكن الشاشة تعرضه: الحساب مفعَّل
         * والمنصّة مفعّلة — والباقة لا تشمل واتساب. فيقرأ «مفعّل» وينتظر.
         */
        $this->onPlan([]);

        $this->assertFalse($this->readiness()['ready']);
        $this->assertFalse($this->step('plan')['done']);
        $this->assertNotNull($this->step('plan')['fix']);
    }

    public function test_a_missing_platform_connection_is_named_as_its_own_step(): void
    {
        // إعدادُ الحساب مفتوحٌ ولا رقمَ يُرسل منه — والشاشة كانت تقول «مفعّل»
        WhatsAppConnection::query()->platform()->delete();

        $this->assertFalse($this->readiness()['ready']);
        $this->assertFalse($this->step('shared')['done']);
    }

    public function test_the_steps_that_only_abaad_can_open_are_marked_as_theirs(): void
    {
        /*
         * والفرق يُقال: خطوةٌ ينتظر فيها أبعاد لا يُطلب منه إصلاحها، وخطوةٌ
         * بيده تُقال بصيغة الأمر. ولو خُلطتا لَبقي ينتظر ما عليه أن يفعله،
         * أو حاول ما لا يملكه.
         */
        foreach (['platform', 'account', 'plan'] as $key) {
            $this->assertTrue($this->step($key)['theirs'], "خطوة «{$key}» تُطلب من التاجر وهي ليست بيده");
        }
    }

    public function test_connecting_your_own_number_is_the_merchants_own_step(): void
    {
        $this->business->update([
            'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);

        $own = $this->step('own');

        $this->assertFalse($own['done']);
        $this->assertFalse($own['theirs'], 'ربطُ الرقم بيد التاجر ومع ذلك عُدّ انتظارًا لأبعاد');
        $this->assertStringContainsString('اربط رقم متجرك', $own['fix']);
    }

    public function test_a_connected_own_number_completes_the_step(): void
    {
        $this->business->update([
            'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->business->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->assertTrue($this->step('own')['done']);
        $this->assertTrue($this->readiness()['ready']);
    }

    public function test_a_broken_own_connection_is_not_ready_and_does_not_fall_back(): void
    {
        /*
         * ووصلةٌ منقطعة لا تتحوّل بصمت إلى رقم أبعاد — وهو قرارٌ محروسٌ في
         * `WhatsAppConnections::resolve`. فالشاشة تقول «غير جاهز» ولا تقول
         * «يُرسل عبر أبعاد».
         */
        $this->business->update([
            'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->business->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-0123456789',
            'status' => WhatsAppConnection::REVOKED,
        ]);

        $this->assertFalse($this->step('own')['done']);
        $this->assertFalse($this->readiness()['ready']);
    }

    public function test_a_revoked_permission_is_named_not_left_as_four_ticks(): void
    {
        /*
         * متجرٌ ربط رقمه ثمّ سُحبت منه الميزة: الوصلة تبقى صالحة، والوضع
         * يبقى `business_own` (لا يُدفع إلى الرقم المشترك بصمت). فالخطوات
         * الأربع تبدو تامّة — والمُرسِل يمتنع.
         *
         * وهذا هو العطب نفسه الذي بُنيت هذه الشاشة لإزالته، في صورةٍ أخرى:
         * رأسٌ يقول «غير جاهز» وتحته أربعُ علاماتٍ خضراء ولا سببَ يُقرأ.
         */
        $this->business->update([
            'whatsapp_own_allowed' => true,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->business->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->assertTrue($this->readiness()['ready']);

        // ثمّ يُسحب الإذن — والوصلة كما هي
        $this->business->update(['whatsapp_own_allowed' => false]);

        $this->assertFalse($this->readiness()['ready']);
        $this->assertFalse($this->step('own')['done'], 'خطوةٌ تامّة ورأسٌ يقول «غير جاهز» — ولا سببَ يُقرأ');
        // ويُدَلّ على المخرج: التبديل إلى رقم أبعاد بيده، فلا يبقى واقفًا
        $this->assertStringContainsString('رقم أبعاد', $this->step('own')['fix']);
    }

    public function test_a_merchant_stripped_of_the_feature_can_still_go_back_to_the_shared_number(): void
    {
        /*
         * ولا يبقى واقفًا: التبديل إلى رقم أبعاد مفتوحٌ له وإن سُحبت ميزةُ
         * رقمه. ولو كان محروسًا بالإذن نفسه لَقُفل عليه الطريقان معًا.
         */
        $this->business->update([
            'whatsapp_own_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);

        $this->post(route('admin.marketing.whatsapp.mode'), ['mode' => WhatsAppMode::ABAAD_SHARED])
            ->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->business->fresh()->whatsapp_mode);
        $this->assertTrue($this->readiness()['ready']);
    }

    /* ========================== لا ضجيج ========================== */

    public function test_a_completed_step_carries_no_instruction(): void
    {
        // نصيحةٌ تحت خطوةٍ مكتملة ضجيجٌ يُخفي الخطوة التي تحتاج عملًا
        foreach ($this->readiness()['steps'] as $step) {
            if ($step['done']) {
                $this->assertNull($step['fix'], "خطوة مكتملة «{$step['key']}» تحمل تعليمات");
            }
        }
    }

    public function test_the_steps_come_in_the_order_they_are_done(): void
    {
        // المنصّة ثمّ الحساب ثمّ الباقة ثمّ الرقم — ولا يُقرأ ترتيبٌ غيره
        $keys = array_column($this->readiness()['steps'], 'key');

        $this->assertSame(['platform', 'account', 'plan', 'shared'], $keys);
    }

    /* =========================== الشاشة =========================== */

    public function test_the_screen_carries_the_steps_before_anything_else(): void
    {
        $this->get(route('admin.marketing.whatsapp'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Marketing/Whatsapp')
                ->where('automation.readiness.ready', true)
                ->has('automation.readiness.steps', 4));
    }

    public function test_the_screen_never_leaks_a_token(): void
    {
        // الرمز لا يخرج إلى الشاشة — تُقرأ على شاشةٍ في المحلّ
        $this->get(route('admin.marketing.whatsapp'))
            ->assertOk()
            ->assertDontSee('platform-token-value-0123456789');
    }
}
