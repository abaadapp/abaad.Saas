<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Http\Controllers\SuperAdmin\SettingController;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\WhatsAppFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * شاشةُ إعدادات المنصّة: تحفظ، وإن رَدَّت قالت لماذا.
 *
 * ═══ العطب الذي وُلد منه هذا الملفّ ═══
 *
 * `SETTING_DEFAULTS['default_plan']` كان **«أساسية»** — اسمٌ لا تحمله أيُّ
 * باقةٍ ينشئها النظام (هي «الباقة الأساسية» و«الباقة الاحترافية» و«باقة
 * المؤسسات»). والشاشةُ تُرسل الحقولَ كلَّها في كلّ حفظٍ مهما كان التبويبُ
 * المفتوح، فيقرأ `update` هذا الافتراضيَّ ويردّ **الطلبَ كلَّه** لأنّ الاسم
 * لا يطابق باقة.
 *
 * ورسالةُ الرفض تُكتب على `default_plan` — وحقلُه في تبويب «الاشتراكات».
 * فمن كان في تبويب «واتساب» يضغط «حفظ» ولا يرى شيئًا: ترتدّ الصفحة كأنّ
 * شيئًا لم يكن. وقِيس أثرُه على الإنتاج: جدولُ إعدادات المنصّة فيه **صفٌّ
 * واحد**، فالشاشةُ لم تحفظ حرفًا منذ كُتب ذلك الافتراضيّ.
 *
 * وعطبان لا واحد: قيمةٌ افتراضيّةٌ تشير إلى ما لا وجود له، وبابٌ يُردّ ولا
 * يُقال لماذا.
 */
class ARejectedSaveSaysWhyTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'p@abaad.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** @return array<string, mixed> ما تُرسله الشاشةُ فعلًا — كلُّ الحقول لا الحقلُ المعدَّل */
    private function whatTheScreenSends(array $override = []): array
    {
        $settings = $this->actingAs($this->super)
            ->get(route('super-admin.settings.index'))
            ->viewData('page')['props']['settings'];

        $get = fn (string $k) => $settings[$k] ?? '';
        $on = fn (string $k) => ($settings[$k] ?? '0') !== '0';

        return array_merge([
            'app_name' => $get('app_name'), 'locale' => $get('locale'),
            'maintenance_mode' => $on('maintenance_mode'),
            'company' => $get('company'), 'official_email' => $get('official_email'),
            'phone' => $get('phone'), 'website' => $get('website'),
            'trial_days' => $get('trial_days'), 'grace_days' => $get('grace_days'),
            'default_plan' => $get('default_plan'), 'auto_suspend' => $on('auto_suspend'),
            'vat_rate' => $get('vat_rate'), 'tax_mode' => $get('tax_mode'),
            'from_address' => $get('from_address'), 'from_name' => $get('from_name'),
            'whatsapp_enabled' => $on('whatsapp_enabled'),
            'whatsapp_shared_enabled' => $on('whatsapp_shared_enabled'),
            'whatsapp_shared_default_monthly_limit' => $get('whatsapp_shared_default_monthly_limit'),
        ], $override);
    }

    /* ─────────── الافتراضيُّ لا يُشير إلى ما لا وجود له ─────────── */

    /**
     * شاشةٌ تُفتح ثمّ تُحفظ بلا تعديلٍ واحد — يجب أن تُحفظ.
     *
     * وهذا هو الاختبارُ الذي كان يسقط: الافتراضيُّ وحدَه كان يردّ الطلب.
     */
    public function test_saving_the_screen_untouched_writes_instead_of_bouncing(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $this->actingAs($this->super)
            ->post(route('super-admin.settings.update'), $this->whatTheScreenSends())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['business_id' => null, 'key' => 'app_name']);
    }

    /**
     * ومقبضُ واتساب يُحفظ من تبويبه — وهو ما عجز عنه صاحبُ المنصّة عشرَ مرّات.
     */
    public function test_the_whatsapp_switch_saves_from_its_own_tab(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $this->actingAs($this->super)->post(
            route('super-admin.settings.update'),
            $this->whatTheScreenSends(['whatsapp_enabled' => true]),
        )->assertSessionHasNoErrors();

        $this->assertSame('1', DB::table('settings')
            ->whereNull('business_id')->where('key', 'whatsapp_enabled')->value('value'));

        $this->assertTrue(WhatsAppFeature::globallyEnabled());
    }

    /**
     * والافتراضيُّ المرسَل لا يسمّي باقةً غيرَ موجودة.
     *
     * ولا يُقارن بالفراغ وحده: القاعدةُ أنّ ما تُرسله الشاشةُ إمّا لا شيء
     * وإمّا اسمُ باقةٍ **قائمة**. فمن كتب افتراضيًّا آخر غدًا يسقط هنا.
     */
    public function test_the_default_it_sends_is_either_nothing_or_a_real_plan(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $sent = $this->whatTheScreenSends()['default_plan'];

        $this->assertTrue(
            $sent === '' || Plan::where('name', trim((string) $sent))->exists(),
            'الشاشةُ ترسل باقةً افتراضيّةً لا وجود لها: '.var_export($sent, true),
        );
    }

    /** ولا في الثابت نفسِه اسمُ باقةٍ يخترعه الكود */
    public function test_the_constant_does_not_invent_a_plan_name(): void
    {
        $defaults = (new ReflectionClass(PageController::class))
            ->getReflectionConstant('SETTING_DEFAULTS')->getValue();

        $this->assertSame('', $defaults['default_plan'],
            'افتراضيُّ الباقة اسمٌ لا يطابق شيئًا — وهو يردّ كلَّ حفظ');
    }

    /* ─────────── وما رُدّ يُقال لماذا ─────────── */

    /** واسمُ باقةٍ لا وجود له يُردّ — الحارسُ يبقى، وهو ليس العطب */
    public function test_a_plan_name_that_matches_nothing_is_still_refused(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);

        $this->actingAs($this->super)->post(
            route('super-admin.settings.update'),
            $this->whatTheScreenSends(['default_plan' => 'باقةٌ مخترعة']),
        )->assertSessionHasErrors('default_plan');

        $this->assertDatabaseMissing('settings', ['business_id' => null, 'key' => 'default_plan']);
    }

    /**
     * والشاشةُ تفتح تبويبَ الحقل المعطوب وتقول ما وقع.
     *
     * وتُقاس على شكل الكود لا على نصٍّ عربيٍّ في تعليق: خريطةٌ تربط كلَّ
     * حقلٍ بتبويبه، وأثرٌ يفتح تبويبَ أوّلِ خطأ، وشريطٌ يطبع الرسائل.
     */
    public function test_the_screen_surfaces_an_error_that_lives_in_a_folded_tab(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Platform/Settings/Index.tsx'));

        $this->assertStringContainsString('const FIELD_TAB: Record<string, string> = {', $screen);
        $this->assertStringContainsString("default_plan: 'subscriptions',", $screen);
        $this->assertStringContainsString('setTab(owner);', $screen, 'لا يُفتح تبويبُ الخطأ');
        $this->assertStringContainsString('{errorKeys.length > 0 && (', $screen, 'لا شريطَ يقول ما وقع');
    }

    /** وكلُّ حقلٍ يُصادَق في الخادم له تبويبٌ في الخريطة — وإلّا اختفى خطؤه */
    public function test_every_saved_field_knows_which_tab_owns_it(): void
    {
        $keys = array_keys(
            (new ReflectionClass(SettingController::class))
                ->getReflectionConstant('KEYS')->getValue()
        );

        $screen = file_get_contents(resource_path('js/Pages/Platform/Settings/Index.tsx'));
        $map = substr($screen, strpos($screen, 'const FIELD_TAB'), 1400);

        foreach ($keys as $key) {
            $this->assertStringContainsString(
                $key.':',
                $map,
                "الحقل «{$key}» بلا تبويبٍ في الخريطة — خطؤه يقع في صمت",
            );
        }
    }

    /* ─────────── والشاشةُ تعرض الباقات لتُختار ─────────── */

    /** والقائمةُ من الباقات القائمة — فلا يُكتب اسمٌ يُخطئ حرفًا */
    public function test_the_screen_offers_the_real_plans(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);
        Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 19.9, 'yearly_price' => 199]);

        $this->actingAs($this->super)->get(route('super-admin.settings.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('plans', 2)
                ->where('settings.default_plan', ''));
    }

    /** وما حُفظ يسبق الافتراضيَّ — لا يُعاد إلى الفراغ بعد اختياره */
    public function test_a_saved_default_plan_wins_over_the_blank(): void
    {
        Plan::create(['name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99]);
        Setting::create(['business_id' => null, 'key' => 'default_plan', 'value' => 'الباقة الأساسية']);

        $this->actingAs($this->super)->get(route('super-admin.settings.index'))
            ->assertInertia(fn (Assert $p) => $p->where('settings.default_plan', 'الباقة الأساسية'));
    }
}
