<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بنية الإعدادات بعد الدمج — والمقبضُ الذي لا يُمسك أسوأ من غيابه.
 *
 * الدمج نقل حقولًا بين أقسام: اللغة إلى بيانات النشاط، والضريبة والعملة
 * والدفع إلى صفحةٍ واحدة، والترقيم والورق إلى قوالب الفواتير، والموقع
 * والنطاق من التسويق إلى الإعدادات. ونقلُ حقلٍ يُنسى نصفه: يختفي من شاشته
 * القديمة ولا يظهر في الجديدة، فيُقرأ فقدانًا لا نقلًا.
 */
class SettingsLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function screen(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));
    }

    private function nav(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Settings/partials/SettingsNav.tsx'));
    }

    /* --------------------------- ما تعرضه اللوحة --------------------------- */

    public function test_the_board_carries_the_merged_sections(): void
    {
        $nav = $this->nav();

        foreach (['business', 'domain', 'website', 'finance', 'chart', 'templates'] as $key) {
            $this->assertStringContainsString("key: '{$key}'", $nav, "بطاقة «{$key}» غائبة");
        }
    }

    /** والأقسام التي ابتُلعت لا تبقى بطاقاتٍ تفتح فراغًا */
    public function test_the_swallowed_sections_leave_no_empty_card(): void
    {
        $nav = $this->nav();

        foreach (['language', 'taxes', 'currency', 'payments', 'invoices', 'printing', 'loyalty', 'shifts'] as $key) {
            $this->assertStringNotContainsString("key: '{$key}'", $nav, "بطاقة «{$key}» بقيت بعد دمجها");
        }
    }

    /**
     * كل حقلٍ نُقل موجودٌ في شاشته الجديدة.
     *
     * والفحص على النصّ لا على الصورة: النقل يقع بقصّ ولصق، وما يسقط منه
     * يسقط صامتًا — لا خطأ ترجمةٍ ولا اختبارَ وحدةٍ يمسّه.
     */
    public function test_every_moved_control_survived_the_move(): void
    {
        $tsx = $this->screen();

        $moved = [
            // اللغة → بيانات النشاط
            "route('admin.language.update')",
            // الضريبة والعملة والدفع → صفحةٌ واحدة
            "tab === 'finance'", 'vat_rate', 'tax_mode', 'symbol_pos', 'decimals', 'PAYMENT_METHODS',
            // الترقيم والورق → قوالب الفواتير
            'inv_prefix', 'inv_start',
            // الموقع والنطاق → الإعدادات
            "tab === 'domain'", "tab === 'website'",
            'site_domain', 'site_enabled', 'site_tagline', 'site_about',
            'site_whatsapp', 'site_instagram', 'site_show_prices', 'site_allow_orders',
        ];

        foreach ($moved as $needle) {
            $this->assertStringContainsString($needle, $tsx, "«{$needle}» ضاع في النقل");
        }
    }

    /* ------------------------- ما يصل من الخادم ------------------------- */

    public function test_the_settings_page_carries_the_website_keys(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('site', $props, 'إعدادات الموقع لا تصل الشاشة');
        $this->assertArrayHasKey('site_domain', $props['site']);
        $this->assertArrayHasKey('published', $props);
    }

    /** وشجرة الحسابات تُحسب لقسمها وحده — لا تُجرّ مع كل فتحةِ إعدادات */
    public function test_the_chart_is_computed_only_for_its_own_section(): void
    {
        $bare = $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayNotHasKey('accounts', $bare);

        $opened = $this->actingAs($this->owner)
            ->get(route('admin.settings.index', ['section' => 'chart']))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('accounts', $opened);
        $this->assertNotEmpty($opened['accounts'], 'الشجرة تُبنى عند أوّل فتح');
        $this->assertArrayHasKey('trial', $opened);
    }

    /* --------------------------- انتقال الموقع --------------------------- */

    /** الرابط القديم يوجّه ولا يسقط: تاجرٌ حفظه لا يُقابَل بـ404 */
    public function test_the_old_marketing_link_lands_in_the_new_home(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.marketing.website'))
            ->assertRedirect(route('admin.settings.index', ['section' => 'domain']));
    }

    /** والحفظ ما زال يعمل من موضعه الجديد */
    public function test_the_domain_still_saves_from_settings(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.website.save'), [
                'site_domain' => 'mystore.om',
                'site_enabled' => true,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'mystore.om',
        ]);
    }

    /* ----------------------------- شكل الشاشة ----------------------------- */

    /** «إعدادات الموقع» تبويباتٌ وبطاقات — لا عمودٌ يُمرَّر إليه */
    public function test_the_website_screen_is_tabbed(): void
    {
        $tsx = $this->screen();

        foreach (["siteTab === 'basic'", "siteTab === 'contact'", "siteTab === 'display'"] as $needle) {
            $this->assertStringContainsString($needle, $tsx, "تبويب «{$needle}» غائب");
        }
    }

    /* ------------------------------ الشعار ------------------------------ */

    /**
     * صاحب النشاط يرفع شعاره بنفسه.
     *
     * العمود كان موجودًا والرفع في لوحة المنصّة وحدها، بينما في قوالب
     * الفواتير مقبضٌ «شعار المتجر» وصفُه «يظهر فقط إن كان للنشاط شعار محفوظ»
     * — يشترط ما لا سبيل لصاحبه إليه، فيتّصل بالدعم ليرفعه عنه.
     */
    public function test_the_owner_uploads_the_logo_himself(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.settings.logo'), [
                'logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 400, 100),
            ])->assertSessionHasNoErrors();

        // العمود الخام: الخاصيّة تردّ رابطًا، والقرص يعرف المسار
        $stored = $this->business->fresh()->getRawOriginal('logo');
        $this->assertNotNull($stored, 'لم يُحفظ الشعار');
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($stored);

        // والشاشة تقرؤه رابطًا لا مسارًا خامًا
        $props = $this->actingAs($this->owner)
            ->get(route('admin.settings.index'))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString($stored, (string) $props['business']['logo']);
    }

    public function test_the_logo_is_removed_when_asked(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->business->update(['logo' => 'logos/old.png']);

        $this->actingAs($this->owner)
            ->post(route('admin.settings.logo'), ['remove' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->business->fresh()->logo);
    }

    /** وما ليس صورةً يُرفض — لا يُخزَّن ثمّ يُكتشف على فاتورةٍ مطبوعة */
    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.settings.logo'), [
                'logo' => \Illuminate\Http\UploadedFile::fake()->create('bill.pdf', 40, 'application/pdf'),
            ])->assertSessionHasErrors('logo');

        $this->assertNull($this->business->fresh()->logo);
    }

    /** ولا يرفع تاجرٌ شعارًا على متجر جاره */
    public function test_the_logo_lands_on_the_owner_business_only(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->post(route('admin.settings.logo'), [
                'logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png'),
                'business_id' => $other->id,
            ])->assertSessionHasNoErrors();

        $this->assertNull($other->fresh()->logo);
        $this->assertNotNull($this->business->fresh()->logo);
    }

    /* ---------------------------- بوّابة الوردية ---------------------------- */

    /**
     * منعُ البيع لا يبقى ساريًا بلا مفتاحٍ يُطفئه.
     *
     * القسم أُزيل، ولو بقي المفتاح مرفوعًا على متجرٍ رفعه لوقف صندوقُه بلا
     * شاشةٍ يُطفأ منها — والكاشير يرى المنع صباحًا ولا يجد له سببًا.
     */
    public function test_the_shift_gate_is_not_left_raised_without_a_switch(): void
    {
        $this->assertNotContains(
            'require_open_shift',
            array_keys((new \ReflectionClass(\App\Http\Controllers\Admin\SettingController::class))->getConstant('KEYS')),
            'مفتاحٌ يمنع البيع ما زال يُقبل من بابٍ بلا شاشة',
        );

        \App\Models\Setting::create([
            'business_id' => $this->business->id, 'key' => 'require_open_shift', 'value' => '1',
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['require_open_shift' => true])
            ->assertSessionHasNoErrors();

        // القائمة المغلقة تتجاهله: لا يُرفع من هنا بعد اليوم
        $this->assertSame(
            '1',
            \App\Models\Setting::where('business_id', $this->business->id)
                ->where('key', 'require_open_shift')->value('value'),
        );
    }
}
