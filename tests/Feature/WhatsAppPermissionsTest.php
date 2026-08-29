<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من يملك أن يفعل ماذا.
 *
 * والحدّ هو الموضع الذي يُختبَر أشدَّ اختبار: هو ما تدفع أبعاد ثمنه. فلو
 * استطاع تاجرٌ أن يرفعه بنفسه لَما كان حدًّا — وهذا لا يُكتشف بالنظر إلى
 * الشاشة، لأنّ الشاشة لا تعرض له الحقل أصلًا.
 */
class WhatsAppPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* --------------------------- التاجر لا يرفع حدّه --------------------------- */

    /**
     * الحدّ لا يُكتب من باب التاجر — لا بحقلٍ ولا بحقلٍ مُهرَّب.
     *
     * والفحص على القاعدة لا على الردّ: طلبٌ يُردّ بـ302 وقد كتب ما لا يجوز
     * يبدو مرفوضًا وهو ناجح.
     */
    public function test_a_business_cannot_raise_its_own_limit(): void
    {
        $this->business->update(['whatsapp_monthly_limit' => 10]);

        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.save'), [
            'whatsapp_monthly_limit' => 99999,
            'wa_enabled' => true,
        ]);

        $this->assertSame(10, $this->business->fresh()->whatsapp_monthly_limit);
    }

    public function test_a_business_cannot_grant_itself_the_own_number_feature(): void
    {
        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.save'), [
            'whatsapp_own_allowed' => true,
        ]);

        $this->assertFalse((bool) $this->business->fresh()->whatsapp_own_allowed);
    }

    /** ولا يبلغ باب المنصّة أصلًا */
    public function test_a_business_owner_cannot_reach_the_platform_whatsapp_routes(): void
    {
        $this->actingAs($this->owner)
            ->put(route('super-admin.businesses.whatsapp.update', $this->business->id), [
                'whatsapp_monthly_limit' => 5000,
            ])->assertForbidden();

        $this->actingAs($this->owner)
            ->post(route('super-admin.whatsapp.shared.connect'), [
                'phone_number_id' => 'X', 'access_token' => 'aaaaaaaaaaaaaaaaaaaaaa',
            ])->assertForbidden();

        $this->assertNull($this->business->fresh()->whatsapp_monthly_limit);
    }

    /* ------------------------------ مدير المنصّة ------------------------------ */

    public function test_the_platform_admin_sets_the_limit_and_the_entitlement(): void
    {
        $this->actingAs($this->super)
            ->put(route('super-admin.businesses.whatsapp.update', $this->business->id), [
                'whatsapp_monthly_limit' => 250,
                'whatsapp_own_allowed' => true,
                'whatsapp_enabled' => true,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->business->fresh();
        $this->assertSame(250, $fresh->whatsapp_monthly_limit);
        $this->assertTrue((bool) $fresh->whatsapp_own_allowed);
    }

    /** ولا يضع متجرًا في وضعٍ لا يُرسل منه شيء */
    public function test_the_platform_admin_cannot_set_own_mode_without_the_entitlement(): void
    {
        $this->actingAs($this->super)
            ->put(route('super-admin.businesses.whatsapp.update', $this->business->id), [
                'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
            ])->assertSessionHasErrors('whatsapp_mode');

        $this->assertSame(WhatsAppMode::ABAAD_SHARED, $this->business->fresh()->whatsapp_mode);
    }

    public function test_the_platform_admin_can_disable_whatsapp_for_one_business(): void
    {
        $this->actingAs($this->super)
            ->put(route('super-admin.businesses.whatsapp.update', $this->business->id), [
                'whatsapp_enabled' => false,
            ])->assertRedirect();

        $this->assertFalse((bool) $this->business->fresh()->whatsapp_enabled);
    }

    /** وتغيير الأذونات يُقيَّد بقيمته القديمة والجديدة */
    public function test_entitlement_changes_are_written_to_the_activity_log(): void
    {
        $this->actingAs($this->super)
            ->put(route('super-admin.businesses.whatsapp.update', $this->business->id), [
                'whatsapp_own_allowed' => true,
                'whatsapp_monthly_limit' => 300,
            ]);

        $logs = \App\Models\ActivityLog::pluck('description')->implode(' | ');
        $this->assertStringContainsString('صلاحية الرقم الخاص', $logs);
        $this->assertStringContainsString('حدّ الرسائل الشهري', $logs);
        $this->assertStringContainsString('300', $logs);
    }

    /* ------------------------------ لا سرَّ للشاشة ------------------------------ */

    /**
     * الرمز لا يبلغ المتصفّح — لا في شاشة المنصّة ولا في شاشة التاجر.
     *
     * والفحص على خصائص الصفحة كاملةً لا على ما رُسم منها: ما وصل الشاشة
     * مقروءٌ لكلّ من يفتح أدوات المتصفّح، رُسم أم لم يُرسم. وهذا الفحص هو
     * الذي يمنع أن يُمرَّر النموذج نفسه سهوًا بدل `publicView`.
     */
    public function test_no_token_reaches_the_platform_settings_screen(): void
    {
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-secret-token-abcdef',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $props = $this->actingAs($this->super)
            ->get(route('super-admin.settings.index'))
            ->viewData('page')['props'];

        $this->assertStringNotContainsString('platform-secret-token', json_encode($props));
        // وحالُها يصل: تُقرأ ليُعرف أنّ الرقم مربوط
        $this->assertSame('active', $props['whatsapp']['status']);
    }

    public function test_no_token_reaches_the_merchant_screen(): void
    {
        $this->business->update(['whatsapp_own_allowed' => true]);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'access_token' => 'platform-secret-token-abcdef',
            'status' => WhatsAppConnection::ACTIVE,
        ]);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $this->business->id,
            'phone_number_id' => 'SHOP-PN',
            'access_token' => 'shop-secret-token-uvwxyz',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.marketing.whatsapp'))
            ->viewData('page')['props'];

        $json = json_encode($props);
        $this->assertStringNotContainsString('platform-secret-token', $json);
        $this->assertStringNotContainsString('shop-secret-token', $json);
        // ولا يبلغه معرّف حساب أبعاد: ليس حسابه ولا يفعل به شيئًا
        $this->assertStringNotContainsString('ABAAD-PN', $json);
    }

    /** ولا يرى التاجر استهلاك متجرٍ آخر */
    public function test_a_merchant_screen_carries_only_its_own_usage(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);
        $other->update(['whatsapp_monthly_limit' => 777]);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.marketing.whatsapp'))
            ->viewData('page')['props'];

        $this->assertStringNotContainsString('777', json_encode($props['automation']));
        $this->assertNotNull($other->id);
    }

    /* -------------------------------- الكاشير -------------------------------- */

    public function test_a_cashier_cannot_connect_or_disconnect_a_number(): void
    {
        $this->business->update(['whatsapp_own_allowed' => true]);

        $this->actingAs($this->cashier)
            ->post(route('admin.marketing.whatsapp.connect'), [
                'phone_number_id' => 'SHOP-PN', 'access_token' => 'shop-token-value-9876543210',
            ])->assertForbidden();

        $this->actingAs($this->cashier)
            ->delete(route('admin.marketing.whatsapp.disconnect'))->assertForbidden();

        $this->assertSame(0, WhatsAppConnection::count());
    }

    public function test_a_cashier_cannot_change_the_sending_mode(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('admin.marketing.whatsapp.mode'), ['mode' => WhatsAppMode::BUSINESS_OWN])
            ->assertForbidden();
    }

    /* -------------------------------- العزل -------------------------------- */

    /** تاجرٌ لا يضبط واتساب متجرٍ آخر ولو أرسل معرّفه */
    public function test_a_business_cannot_touch_another_businesss_whatsapp(): void
    {
        $other = Business::create(['name' => 'ورد آخر', 'type' => 'محل ورود', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->put(route('super-admin.businesses.whatsapp.update', $other->id), [
                'whatsapp_enabled' => false,
            ])->assertForbidden();

        $this->assertTrue((bool) $other->fresh()->whatsapp_enabled);
    }

    /** والحدّ الافتراضي إعدادُ منصّةٍ لا يكتبه تاجر */
    public function test_a_business_cannot_write_the_platform_default_limit(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY],
            ['value' => '100'],
        );

        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            WhatsAppQuota::DEFAULT_KEY => 99999,
        ]);

        $this->assertSame(100, WhatsAppQuota::platformDefault());
    }
}
