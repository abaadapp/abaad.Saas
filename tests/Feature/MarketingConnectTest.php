<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\GoogleReviews;
use App\Support\Integration;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * أدواتُ التسويق تُفتح على بابٍ لا على شاشة.
 *
 * كانت الشاشة تُعرض كاملةً لمن لم يربط شيئًا: حقولُ معرّفاتٍ من حساب ميتا،
 * ومفتاحُ Google Cloud، ومقابضُ أحداثٍ لا تُرسل حرفًا قبل الربط. فيقرأ التاجر
 * عشرين سطرًا ليعرف أنّ لا شيء منها يعمل بعد، ثمّ يبحث عن الخطوة الأولى بين
 * البقيّة — فلا يبدأ.
 *
 * فصار البابُ بابًا: أيقونةٌ وزرٌّ واحد. وما وراءه مراحلُ بترتيبها، ولكلٍّ
 * حالُها ومن يملك إصلاحها.
 */
class MarketingConnectTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function props(string $route, array $query = []): array
    {
        return $this->actingAs($this->owner)->get(route($route, $query))
            ->assertOk()->viewData('page')['props'];
    }

    /* ------------------------------ البوّابة ------------------------------ */

    public function test_a_merchant_who_never_started_sees_the_door_not_the_screen(): void
    {
        // ولا رقمَ مشتركًا مربوطًا في المنصّة، فلا شيء جاهزٌ بعد
        $this->assertFalse($this->props('admin.integrations.whatsapp')['automation']['readiness']['connected']);
        $this->assertFalse($this->props('admin.integrations.google')['readiness']['connected']);
    }

    /**
     * و«ابدأ» في الرابط لا في حالة المكوّن.
     *
     * لو كانت في الحالة لَعاد التاجر إلى الباب مع كلّ تحديثِ صفحة — وكلُّ
     * حفظةٍ في هذه الشاشات تردّ `back()`، فيبدو ما فعله كأنّه ألغى ما بدأه.
     */
    public function test_pressing_connect_opens_the_stages_and_survives_a_reload(): void
    {
        foreach ([['whatsapp', 'admin.integrations.whatsapp'], ['google', 'admin.integrations.google']] as [$tool, $screen]) {
            $this->actingAs($this->owner)
                ->post(route('admin.integrations.connect', $tool))
                ->assertRedirect(route($screen));

            $props = $this->props($screen);
            $readiness = $props['readiness'] ?? $props['automation']['readiness'];

            $this->assertTrue($readiness['connected'], "«{$tool}» عاد إلى الباب بعد الضغط");
        }
    }

    /** والبابُ لا يُفتح بزيارةٍ — يكتب في القاعدة، فلا يُنفَّذ بجلبٍ مسبق */
    public function test_the_door_is_not_opened_by_merely_visiting_a_link(): void
    {
        $this->actingAs($this->owner)->get('/admin/integrations/connect/whatsapp')->assertStatus(405);

        $this->assertFalse($this->props('admin.integrations.whatsapp')['automation']['readiness']['connected']);
    }

    /** وأداةٌ لا نعرفها لا تُفتح لها علامة */
    public function test_an_unknown_tool_is_not_found(): void
    {
        $this->actingAs($this->owner)->post(route('admin.integrations.connect', 'facebook'))->assertNotFound();
    }

    /**
     * ومن كانت أداتُه تعمل أصلًا لا يُوقَف عند باب.
     *
     * «اربط» أمام تاجرٍ رسائلُه تخرج منذ شهور سؤالٌ عمّا تمّ — يجعله يظنّ
     * أنّ ربطه انفكّ.
     */
    public function test_a_tool_that_already_works_shows_no_door(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->assertTrue($this->props('admin.integrations.whatsapp')['automation']['readiness']['connected']);
    }

    public function test_the_google_door_closes_once_the_shop_is_pinned(): void
    {
        /* والمعرّفُ يسكن الفرع لا إعداداتِ المتجر — لكلّ فرعٍ ملفُّه */
        /* والمتجرُ في هذا الملفّ قد لا يكون له فرع — والربطُ ينتمي إلى فرع */
        $branchId = Branch::where('business_id', $this->business->id)->value('id')
            ?? Branch::create([
                'business_id' => $this->business->id, 'name' => 'الرئيسي',
            ])->id;

        BranchGooglePlace::create([
            'branch_id' => $branchId,
            'place_id' => 'ChIJrTLr-GyuEmsRBfy61i59si0',
            'place_name' => 'محل الورد',
            'linked_at' => now(),
        ]);

        $this->assertTrue($this->props('admin.integrations.google')['readiness']['connected']);
    }

    /* --------------------- مفتاحُ التاجر وحده --------------------- */

    /**
     * صفُّ مفتاحٍ قديمٍ لأبعاد — كما قد يبقى على قاعدةٍ لم تُرحَّل بعد.
     *
     * لا دالّةَ تكتبه بعد اليوم، فيُكتب خامًا: ليُثبَت أنّ وجودَه لا يُغيّر شيئًا.
     */
    private function leftoverPlatformKey(string $plain): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => 'google_places_key'],
            ['value' => Crypt::encryptString($plain)],
        );
    }

    /**
     * الخطوةُ الأولى مفتاحُ التاجر — ولا يُتمّها صفٌّ قديمٌ لأبعاد.
     */
    public function test_a_leftover_platform_key_completes_nothing_only_the_merchants_own_key_does(): void
    {
        $first = fn () => $this->props('admin.integrations.google')['readiness']['steps'][0];

        $this->assertFalse($first()['done']);

        $this->leftoverPlatformKey('AIza-platform-key');
        $this->assertFalse($first()['done'], 'مفتاحُ أبعاد أتمّ خطوةَ التاجر — والنداءاتُ على فاتورة أبعاد');
        $this->assertStringContainsString('اربط Google Maps لتفعيل هذه الميزة', (string) $first()['fix']);

        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');
        $this->assertTrue($first()['done']);
    }

    /** وصفُّ أبعاد القديم لا يُقال للتاجر «مفتاحك محفوظ» — هو لم يحفظ شيئًا */
    public function test_a_leftover_platform_key_is_not_shown_as_the_merchants_own(): void
    {
        $this->leftoverPlatformKey('AIza-platform-key');

        $this->assertNull($this->props('admin.integrations.google')['keyHint']);
    }

    /** ومفتاحُ التاجر وحده يُقرأ */
    public function test_only_the_merchants_own_key_is_read(): void
    {
        $this->leftoverPlatformKey('AIza-platform-key');
        $this->assertNull(GoogleReviews::apiKey($this->business->id), 'مفتاحُ أبعاد وقع على تاجرٍ بلا مفتاح');

        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');

        $this->assertSame('AIza-merchant-key', GoogleReviews::apiKey($this->business->id));
        $this->assertSame('••••-key', $this->props('admin.integrations.google')['keyHint']);
    }

    /** ولا يبلغ المتصفّحَ — لا في شاشة التاجر ولا في شاشة المنصّة */
    public function test_a_leftover_platform_key_never_reaches_the_browser(): void
    {
        $this->leftoverPlatformKey('AIza-platform-key');

        $merchant = $this->actingAs($this->owner)->get(route('admin.integrations.google'));
        $merchant->assertOk()->assertDontSee('AIza-platform-key', false);

        $admin = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'p@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $platform = $this->actingAs($admin)->get(route('super-admin.settings.index'));
        $platform->assertOk()->assertDontSee('AIza-platform-key', false);
        $this->assertArrayNotHasKey('googleKeyHint', $platform->viewData('page')['props']);
    }

    /* ----------------------- ولا بابَ لمفتاح المنصّة ----------------------- */

    /** مساراتُ مفتاح المنصّة وفوترته حُذفت — لا يُحفظ لأبعاد مفتاحٌ من أيّ باب */
    public function test_the_platform_key_routes_are_gone(): void
    {
        foreach (['super-admin.settings.googleKey', 'super-admin.settings.googleKey.forget', 'super-admin.settings.googleBilling'] as $name) {
            $this->assertFalse(Route::has($name), "ما زال مسار: $name");
        }

        $admin = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'p2@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($admin)->post('/super-admin/settings/google-key', ['google_places_key' => 'AIza-1234']);

        $this->assertNull(Setting::whereNull('business_id')->where('key', 'google_places_key')->value('value'));
    }

    /* -------------------------- شكلٌ واحدٌ لهما -------------------------- */

    /**
     * الأداتان تُرسمان برسّامٍ واحد — فحقولُهما واحدة.
     *
     * أداةٌ تكتب حقلًا باسمٍ آخر تعني شاشةً تعرف كلَّ أداةٍ على حدة، وتعني
     * خطوةً تُضاف في إحداهما ولا تظهر في الأخرى. وثالثةٌ تأتي غدًا فتكتب
     * شكلًا ثالثًا.
     */
    public function test_both_tools_speak_the_same_shape(): void
    {
        $whatsapp = WhatsAppFeature::readiness($this->business->fresh());
        $google = GoogleReviews::readiness($this->business->id, ['state' => 'unlinked', 'error' => null]);

        $this->assertSame(['connected', 'ready', 'steps'], array_keys($google));
        $this->assertEqualsCanonicalizing(array_keys($whatsapp), array_keys($google));

        $fields = ['key', 'label', 'done', 'detail', 'fix', 'theirs'];
        foreach ([...$whatsapp['steps'], ...$google['steps']] as $step) {
            $this->assertSame($fields, array_keys($step), 'خطوةٌ بحقولٍ غير حقول أختها');
        }
    }

    /** وما تمّ لا يُقال كيف يُصلَح — نصيحةٌ تحت خطوةٍ مكتملة ضجيج */
    public function test_a_finished_stage_carries_no_instruction(): void
    {
        $step = Integration::step('k', 'خطوة', true, detail: 'تفصيل', fix: 'أصلحها');

        $this->assertNull($step['fix']);
        $this->assertSame('تفصيل', $step['detail']);
    }
}
