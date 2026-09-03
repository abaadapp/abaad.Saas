<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleReviews;
use App\Support\Integration;
use App\Support\WhatsAppFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertFalse($this->props('admin.marketing.whatsapp')['automation']['readiness']['connected']);
        $this->assertFalse($this->props('admin.marketing.google')['readiness']['connected']);
    }

    /**
     * و«ابدأ» في الرابط لا في حالة المكوّن.
     *
     * لو كانت في الحالة لَعاد التاجر إلى الباب مع كلّ تحديثِ صفحة — وكلُّ
     * حفظةٍ في هذه الشاشات تردّ `back()`، فيبدو ما فعله كأنّه ألغى ما بدأه.
     */
    public function test_pressing_connect_opens_the_stages_and_survives_a_reload(): void
    {
        foreach ([['whatsapp', 'admin.marketing.whatsapp'], ['google', 'admin.marketing.google']] as [$tool, $screen]) {
            $this->actingAs($this->owner)
                ->post(route('admin.marketing.connect', $tool))
                ->assertRedirect(route($screen));

            $props = $this->props($screen);
            $readiness = $props['readiness'] ?? $props['automation']['readiness'];

            $this->assertTrue($readiness['connected'], "«{$tool}» عاد إلى الباب بعد الضغط");
        }
    }

    /** والبابُ لا يُفتح بزيارةٍ — يكتب في القاعدة، فلا يُنفَّذ بجلبٍ مسبق */
    public function test_the_door_is_not_opened_by_merely_visiting_a_link(): void
    {
        $this->actingAs($this->owner)->get('/admin/marketing/connect/whatsapp')->assertStatus(405);

        $this->assertFalse($this->props('admin.marketing.whatsapp')['automation']['readiness']['connected']);
    }

    /** وأداةٌ لا نعرفها لا تُفتح لها علامة */
    public function test_an_unknown_tool_is_not_found(): void
    {
        $this->actingAs($this->owner)->post(route('admin.marketing.connect', 'facebook'))->assertNotFound();
    }

    /**
     * ومن كانت أداتُه تعمل أصلًا لا يُوقَف عند باب.
     *
     * «اربط» أمام تاجرٍ رسائلُه تخرج منذ شهور سؤالٌ عمّا تمّ — يجعله يظنّ
     * أنّ ربطه انفكّ.
     */
    public function test_a_tool_that_already_works_shows_no_door(): void
    {
        \App\Models\Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        \App\Models\WhatsAppConnection::create([
            'owner_type' => \App\Support\WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => \App\Models\WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->assertTrue($this->props('admin.marketing.whatsapp')['automation']['readiness']['connected']);
    }

    public function test_the_google_door_closes_once_the_shop_is_pinned(): void
    {
        Setting::create([
            'business_id' => $this->business->id,
            'key' => 'google_place_id',
            'value' => 'ChIJrTLr-GyuEmsRBfy61i59si0',
        ]);

        $this->assertTrue($this->props('admin.marketing.google')['readiness']['connected']);
    }

    /* --------------------- أبعاد مهيّأةٌ للربط أوّلًا --------------------- */

    /**
     * مفتاحُ الخرائط على أبعاد لا على التاجر.
     *
     * وكان عليه أن يفتح حسابًا في Google Cloud وينشئ مشروعًا ويربط بطاقةً
     * ليقرأ تقييمات محلّه — فلا يفعل، فتبقى الشاشة فارغة ولا شيء يقول إنّ
     * الطريق لم يكن معبَّدًا أصلًا.
     */
    public function test_the_platform_key_completes_the_first_stage_for_every_merchant(): void
    {
        $first = fn () => $this->props('admin.marketing.google')['readiness']['steps'][0];

        $this->assertFalse($first()['done'], 'الخطوة الأولى تمّت بلا مفتاحٍ في المنصّة');
        $this->assertTrue($first()['theirs'], 'خطوةُ أبعاد تُقال للتاجر بصيغة الأمر');

        GoogleReviews::storePlatformKey('AIza-platform-key');

        $this->assertTrue($first()['done']);
    }

    /** ومفتاحُ أبعاد لا يُقال للتاجر «مفتاحك محفوظ» — هو لم يحفظ شيئًا */
    public function test_the_platform_key_is_not_shown_as_the_merchants_own(): void
    {
        GoogleReviews::storePlatformKey('AIza-platform-key');

        $this->assertNull($this->props('admin.marketing.google')['keyHint']);
    }

    /** ومفتاحُ التاجر يتقدّم على مفتاح المنصّة: فاتورتُه فاتورتُه */
    public function test_a_merchants_own_key_wins_over_the_platform_key(): void
    {
        GoogleReviews::storePlatformKey('AIza-platform-key');
        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');

        $this->assertSame('AIza-merchant-key', GoogleReviews::apiKey($this->business->id));
        $this->assertSame('••••-key', $this->props('admin.marketing.google')['keyHint']);
    }

    /**
     * والمفتاح لا يبلغ المتصفّح — لا في شاشة التاجر ولا في شاشة المنصّة.
     *
     * عليه تُحتسب فاتورةُ نداءات كلّ متاجر المنصّة، ومن فتح أدوات المتصفّح
     * أخذه.
     */
    public function test_the_platform_key_never_reaches_the_browser(): void
    {
        GoogleReviews::storePlatformKey('AIza-platform-key');

        $merchant = $this->actingAs($this->owner)->get(route('admin.marketing.google'));
        $merchant->assertOk()->assertDontSee('AIza-platform-key', false);

        $admin = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'p@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $platform = $this->actingAs($admin)->get(route('super-admin.settings.index'));
        $platform->assertOk()->assertDontSee('AIza-platform-key', false);
        $this->assertSame('••••-key', $platform->viewData('page')['props']['googleKeyHint']);
    }

    /* ----------------------- مفتاح المنصّة يُدار ----------------------- */

    public function test_the_operator_saves_and_forgets_the_platform_key(): void
    {
        $admin = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'p2@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($admin)
            ->post(route('super-admin.settings.googleKey'), ['google_places_key' => 'AIza-1234'])
            ->assertSessionHasNoErrors();
        $this->assertSame('AIza-1234', GoogleReviews::platformKey());

        $this->actingAs($admin)->delete(route('super-admin.settings.googleKey.forget'));
        $this->assertNull(GoogleReviews::platformKey());
    }

    /** وحقلٌ فارغٌ لا يمحو: صفحةٌ تُحفظ لسببٍ آخر لا تُسقط مفتاح المنصّة كلَّها */
    public function test_an_empty_field_does_not_erase_the_platform_key(): void
    {
        GoogleReviews::storePlatformKey('AIza-1234');

        $admin = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'p3@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($admin)
            ->post(route('super-admin.settings.googleKey'), ['google_places_key' => '   '])
            ->assertSessionHasErrors('google_places_key');

        $this->assertSame('AIza-1234', GoogleReviews::platformKey());
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
