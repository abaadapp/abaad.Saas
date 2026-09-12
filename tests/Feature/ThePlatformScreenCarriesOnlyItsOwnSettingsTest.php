<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * شاشةُ إعدادات المنصّة تحمل إعداداتِها وحدها — لا الجدولَ كلَّه.
 *
 * ═══ العطب الذي أُغلق ═══
 *
 * كانت `platformSettings` تنشر كلَّ صفوف `business_id = null` فوق
 * الافتراضيّات. وفي الجدول ما ليس للشاشة: `google_places_key` — مفتاحُ
 * خرائط Google للمنصّة كلِّها، تُحتسب عليه فاتورةُ نداءات كلّ التجّار.
 *
 * وهو **معمًّى** في القاعدة، فلم تظهر منه كلمةٌ مقروءة. لكنّ نصَّه المعمّى
 * كاملًا — ٢٥٦ حرفًا — كان يصل حمولةَ الصفحة، فيأخذه من فتح «مصدر الصفحة»
 * أو أدوات المتصفّح أو امتدادًا يقرأ الصفحات. وفكُّه لا يحتاج إلّا
 * `APP_KEY` — وهو في `.env` وفي كلّ نسخةٍ احتياطيّة.
 *
 * ═══ ولمَ لم يمسكه حارسٌ قائم ═══
 *
 * كان في `EachBranchHasItsOwnPlaceTest` حارسٌ يقول إنّ المفتاح لا يظهر —
 * ويفحص **النصَّ الخام**. والخامُ لم يظهر قطّ، والمعمّى ظهر دائمًا. فمرّ
 * الحارسُ وهو لا يقيس ما ظُنّ أنّه يقيسه.
 *
 * فهذه الحالات تقيس **المحفوظ في القاعدة** لا ما نظنّه فيها.
 */
class ThePlatformScreenCarriesOnlyItsOwnSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boss = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->actingAs($this->boss)
            ->get(route('super-admin.settings.index'))->assertOk()
            ->viewData('page')['props'];
    }

    private function body(): string
    {
        return $this->actingAs($this->boss)
            ->get(route('super-admin.settings.index'))->assertOk()->getContent();
    }

    /* ------------------------- ما لا يتسرّب ------------------------- */

    /** المفتاحُ المعمّى لا يصل حمولةَ الصفحة — وهو ما كان يصل */
    public function test_the_encrypted_platform_key_never_reaches_the_page(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-SECRET-0000-wXyZ');

        $stored = (string) Setting::whereNull('business_id')
            ->where('key', GoogleReviews::PLATFORM_KEY)->value('value');

        $this->assertNotSame('', $stored, 'لم يُحفظ شيءٌ — فالحارس لا يقيس شيئًا');

        $this->assertStringNotContainsString($stored, $this->body(),
            'نصُّ المفتاح المعمّى في حمولة الصفحة — يأخذه من يفتح مصدرها');
    }

    /** ولا خامُه بحال */
    public function test_the_plain_platform_key_never_reaches_the_page(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-SECRET-0000-wXyZ');

        $this->assertStringNotContainsString('AIzaSy-PLATFORM-SECRET-0000-wXyZ', $this->body());
    }

    /** ولا يظهر في `settings` مفتاحًا باسمه */
    public function test_the_key_is_not_listed_among_the_settings(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-SECRET-0000-wXyZ');

        $this->assertArrayNotHasKey(GoogleReviews::PLATFORM_KEY, $this->props()['settings']);
    }

    /**
     * وسرٌّ يُخزَّن غدًا لا يتسرّب لأنّ أحدًا نسي أن يستثنيه.
     *
     * وهذا هو الفرق بين قائمةِ منعٍ وقائمةِ سماح: الأولى تُنسى عند كلّ
     * إضافة، والثانية تُغلق ما لم يُفتح عمدًا.
     */
    public function test_a_secret_stored_later_does_not_leak_either(): void
    {
        Setting::create([
            'business_id' => null,
            'key' => 'some_future_provider_secret',
            'value' => Crypt::encryptString('a-secret-nobody-remembered-to-exclude'),
        ]);

        $body = $this->body();

        $this->assertStringNotContainsString('some_future_provider_secret', $body);
        $this->assertArrayNotHasKey('some_future_provider_secret', $this->props()['settings']);
    }

    /** والشاشة تحمل مفاتيحَها هي لا أكثر */
    public function test_the_screen_carries_exactly_its_own_keys(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-SECRET-0000-wXyZ');
        Setting::create(['business_id' => null, 'key' => 'google_billing_state', 'value' => 'trial']);

        $allowed = array_keys(
            (new \ReflectionClass(PageController::class))->getConstant('SETTING_DEFAULTS')
        );

        $this->assertSame(
            [],
            array_diff(array_keys($this->props()['settings']), $allowed),
            'الشاشة تحمل مفاتيحَ ليست لها',
        );
    }

    /* ------------------------- وما يجب أن يصل ------------------------- */

    /** والإغلاقُ لا يكسر الشاشة: المحفوظُ المسموح يصلها */
    public function test_a_saved_value_still_reaches_the_screen(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => 'app_name'],
            ['value' => 'أبعاد'],
        );

        $this->assertSame('أبعاد', $this->props()['settings']['app_name']);
    }

    /** وما لم يُحفظ يبقى على افتراضيّه */
    public function test_an_unsaved_key_keeps_its_default(): void
    {
        $defaults = (new \ReflectionClass(PageController::class))->getConstant('SETTING_DEFAULTS');

        $this->assertSame($defaults['locale'], $this->props()['settings']['locale']);
    }

    /** وتلميحُ المفتاح يصل — أربعةُ أحرفٍ ليُعرف أيُّه محفوظ */
    public function test_the_hint_still_reaches_the_screen(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-SECRET-0000-wXyZ');

        $this->assertSame('••••wXyZ', $this->props()['googleKeyHint']);
    }

    /** ولا شاشةَ لمن ليس مدير المنصّة */
    public function test_a_merchant_owner_cannot_open_the_screen(): void
    {
        $business = Business::create([
            'name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route('super-admin.settings.index'))->assertForbidden();
    }
}
