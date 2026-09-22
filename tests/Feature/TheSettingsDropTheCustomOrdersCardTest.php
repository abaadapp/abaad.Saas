<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomOrderTemplate;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomArrangement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الطلبات المخصصة» رُفعت من الإعدادات — ولم يُرفع معها ما تبيع به.
 *
 * ═══ ولمَ حارسٌ على شيءٍ حُذف ═══
 *
 * الحذفُ نصفُه إزالةٌ ونصفُه إبقاء. والنصفُ الثاني هو ما يسقط صامتًا:
 *
 *   · بطاقةٌ تُرفع من القائمة ويبقى مفتاحُها في `SettingController::FIELDS`
 *     يُحيل إلى قسمٍ لا وجودَ له — أو تبقى مجموعةٌ فارغةٌ تُفتح على بياض.
 *   · أو يُرفع معها ما لا يخصّها: بيعُ الطلب المخصَّص في الصندوق، وقالبُه
 *     الافتراضيّ، والمفتاحُ الذي أطفأه تاجرٌ قبل شهر.
 *
 * فالقياسُ على الطرفين: ما زال مرفوعًا، وما زال يعمل.
 */
class TheSettingsDropTheCustomOrdersCardTest extends TestCase
{
    use RefreshDatabase;

    private function nav(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Settings/partials/SettingsNav.tsx'));
    }

    private function screen(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));
    }

    /* ════════════ ما رُفع ════════════ */

    public function test_the_card_is_gone_from_the_settings_nav(): void
    {
        $nav = $this->nav();

        $this->assertStringNotContainsString("key: 'custom-orders'", $nav, 'بطاقةُ الطلبات المخصصة باقيةٌ في القائمة');

        /*
         * والقياسُ على البطاقة لا على ذكر الاسم.
         *
         * التعليقُ فوق المجموعة يقول ما كان فيها ولمَ رُحّل — وهو ما يمنع
         * أن يُعاد البندُ بعد سنةٍ لأنّ أحدًا لم يعرف لمَ رُفع. فيُطلب أن
         * تزول **البطاقة**: لا تسميةَ لها في القائمة.
         */
        $this->assertStringNotContainsString("label: 'الطلبات المخصصة'", $nav);
    }

    public function test_the_settings_screen_no_longer_draws_the_panel(): void
    {
        $screen = $this->screen();

        $this->assertStringNotContainsString('CustomOrdersPanel', $screen);
        $this->assertStringNotContainsString("tab === 'custom-orders'", $screen);
        $this->assertStringNotContainsString('custom_orders_enabled', $screen);
    }

    /** واللوحُ نفسُه رُفع — لا يبقى ملفٌّ لا يُركِّبه شيء */
    public function test_the_panel_file_is_removed(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/Pages/Admin/Settings/panels/CustomOrdersPanel.tsx'));
    }

    /**
     * ولا مفتاحَ يُحيل إلى قسمٍ لا بطاقةَ له.
     *
     * `ASettingThatRefusesSaysWhereTest` يقيس هذا على كلّ المفاتيح؛ وهذا
     * يقيسه على هذا المفتاح بعينه — فلو أُعيد يومًا بلا بطاقةٍ قيل أين.
     */
    public function test_no_setting_key_points_at_the_removed_section(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/SettingController.php'));

        $this->assertStringNotContainsString("'section' => 'custom-orders'", $controller);
        $this->assertStringNotContainsString("'custom-orders' =>", $controller);
    }

    /** ولا تُحمَّل قوالبُ لشاشةٍ لا تعرضها — استعلامٌ في كلّ فتحةِ إعدادات */
    public function test_the_settings_page_no_longer_ships_the_templates(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        CustomOrderTemplate::ensureDefault($business->id);

        $props = $this->actingAs($owner)->get(route('admin.settings.index'))->viewData('page')['props'];

        $this->assertArrayNotHasKey('customOrderTemplates', $props);
        $this->assertArrayNotHasKey('customOrderFieldTypes', $props);
        // وسائرُ الأقسام في مكانها — الحذفُ لم يأخذ معه جارَه
        $this->assertArrayHasKey('settings', $props);
        $this->assertArrayHasKey('staffPermissions', $props);
        $this->assertArrayHasKey('customAlerts', $props);
    }

    /* ════════════ وما بقي يعمل ════════════ */

    /**
     * والصندوقُ ما زال يبيع طلبًا مخصَّصًا.
     *
     * البطاقةُ في شبكة الصندوق تُقرأ من `CustomArrangement::enabled` ومن
     * قالبٍ يُزرع عند أوّل طلب — لا من شاشة الإعدادات. ورفعُ الشاشة لا
     * يمسّ واحدًا منهما.
     */
    public function test_the_till_still_sells_a_custom_order(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        $cashier = User::create([
            'business_id' => $business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Product::create([
            'business_id' => $business->id, 'name' => 'ورد أحمر', 'sku' => 'R-1',
            'price' => 2, 'cost' => 0.5, 'quantity' => 50, 'active' => true,
        ]);

        $template = CustomOrderTemplate::ensureDefault($business->id);
        $this->assertNotNull($template, 'لا قالبَ افتراضيّ بعد رفع الشاشة');
        $this->assertTrue(CustomArrangement::enabled($business->id), 'الميزةُ أُطفئت برفع شاشتها');

        $this->actingAs($cashier)->postJson('/pos/checkout', [
            'items' => [[
                'name' => 'طلب مخصص', 'qty' => 1, 'note' => 'عشرون وردة', 'addons' => [],
                'custom' => [
                    'template_id' => $template->id,
                    'mode' => CustomArrangement::MODE_BUDGET,
                    'price' => 25, 'fields' => [], 'components' => [],
                ],
            ]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ])->assertOk();
    }

    /**
     * ومن أطفأ الميزةَ قبل الرفع يبقى مُطفَأً.
     *
     * الصفُّ في `settings` لم يُمحَ، و`CustomArrangement::enabled` تقرؤه
     * كما كانت. ورفعُ المقبض ليس ضغطًا عليه.
     */
    public function test_a_shop_that_had_turned_it_off_stays_off(): void
    {
        $business = Business::create(['name' => 'محل عطور', 'status' => 'نشط']);
        Setting::create([
            'business_id' => $business->id,
            'key' => CustomArrangement::ENABLED_KEY,
            'value' => '0',
        ]);

        $this->assertFalse(CustomArrangement::enabled($business->id));
    }
}
