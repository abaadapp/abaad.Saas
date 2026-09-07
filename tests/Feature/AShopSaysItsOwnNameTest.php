<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\ShopIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اسمُ المتجر يُطبع في رأس كلّ ورقة — فلا يكتبه النظام عن صاحبه.
 *
 * تاجرٌ باع شهرًا واسمُ متجره «متجري»، وخرجت اثنتا عشرةَ فاتورةً ضريبيّةً
 * بذلك الاسم. ولم يكن ذلك إهمالًا منه: **النظامُ لم يسأله** — هبط على لوحةٍ
 * فيها أربعةَ عشرَ قسمًا ولا شيءَ يقول له بمَ يبدأ.
 */
class AShopSaysItsOwnNameTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['pos'],
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-0001',
            'customer_name' => 'زبون', 'employee_name' => 'المالك',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0.5, 'total' => 10.5,
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
        ]);
    }

    // ————— ما الذي يُعدّ اسمًا —————

    public function test_a_name_the_system_writes_is_not_a_name(): void
    {
        foreach (ShopIdentity::PLACEHOLDERS as $written) {
            $this->business->update(['name' => $written, 'identity_confirmed_at' => null]);
            $this->assertFalse(
                ShopIdentity::confirmed($this->business->fresh()),
                "«{$written}» اسمٌ يكتبه النظام — لا يُعدّ هويّةً"
            );
        }
    }

    public function test_a_real_name_needs_no_stamp(): void
    {
        $this->business->update(['name' => 'زهور مسقط']);

        // ولا تعبئةَ رجعيّة: من يعمل اليوم باسمٍ حقيقيّ لا يُسأل عن اسمه
        $this->assertNull($this->business->fresh()->identity_confirmed_at);
        $this->assertTrue(ShopIdentity::confirmed($this->business->fresh()));
    }

    public function test_a_merchant_really_called_that_confirms_once_and_is_left_alone(): void
    {
        // من سمّى متجره «متجري» فعلًا له أن يفعل — يُقرّ مرّةً فلا يُسأل بعدها
        $this->business->update(['name' => 'زهور مسقط']);
        $this->actingAs($this->owner)->post('/admin/setup/confirm');
        $this->business->update(['name' => 'متجري']);

        $this->assertTrue(ShopIdentity::confirmed($this->business->fresh()));
    }

    // ————— الشريط وشاشة التهيئة —————

    public function test_the_shop_is_asked_on_every_screen_not_only_the_dashboard(): void
    {
        /*
         * ولا يُحوَّل التاجر عن لوحته.
         *
         * جرّبتُ التحويلَ أوّلًا فكسر اثنين وعشرين اختبارًا لا علاقةَ لها
         * بالتهيئة — وكشف ما هو أهمّ: من فتح لوحته ليرى بيعَ اليوم يُرمى إلى
         * نموذجٍ بلا مخرج. والنداءُ يكون في كلّ شاشة، والمنعُ على الورق وحده.
         */
        $this->actingAs($this->owner)->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('context.setup.total', 5));

        // ومن يهبط على «المنتجات» أوّلًا لا يمرّ باللوحة أصلًا
        $this->actingAs($this->owner)->get('/admin/products')
            ->assertInertia(fn ($p) => $p->where('context.setup.total', 5));
    }

    public function test_the_call_stops_once_the_shop_is_named(): void
    {
        $this->business->update(['name' => 'زهور مسقط']);

        // شريطٌ لا ينطفئ بعد إتمامه يُقرأ زخرفةً بعد ثلاثة أيّام
        $this->actingAs($this->owner)->get('/admin/dashboard')
            ->assertInertia(fn ($p) => $p->where('context.setup', null));
    }

    public function test_the_cashier_is_not_asked_for_what_he_cannot_write(): void
    {
        /*
         * الكاشيرُ لا يملك بيانات النشاط — وشريطٌ يطلب منه ما لا باب له إليه
         * إزعاجٌ لا تنبيه.
         */
        $this->cashier->update(['permissions' => ['pos', 'dashboard']]);

        $this->actingAs($this->cashier->fresh())->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('context.setup', null));
    }

    public function test_the_banner_names_what_is_missing(): void
    {
        $this->business->update(['name' => 'زهور مسقط', 'identity_confirmed_at' => null]);

        // «أكمل بياناتك» لا تُقرأ ولا تُنفَّذ — والأسماءُ تُقرأ وتُنفَّذ
        $this->actingAs($this->owner)->get('/admin/setup')
            ->assertInertia(fn ($p) => $p->component('Admin/Setup/Index')
                ->where('steps.2.label', __('رقم الهاتف'))
                ->where('steps.2.done', false));
    }

    // ————— الإقرار —————

    public function test_the_stamp_is_refused_on_a_name_the_system_writes(): void
    {
        /*
         * زرٌّ معطَّلٌ في المتصفّح يُتخطّى بطلبٍ مباشر — فيعود الورقُ باسمٍ
         * لم يختره أحد وقد قال النظام إنّه أُقرّ.
         */
        $this->actingAs($this->owner)->post('/admin/setup/confirm')
            ->assertSessionHasErrors('name');

        $this->assertNull($this->business->fresh()->identity_confirmed_at);
    }

    public function test_the_stamp_is_taken_on_a_real_name(): void
    {
        $this->business->update(['name' => 'زهور مسقط']);

        $this->actingAs($this->owner)->post('/admin/setup/confirm')
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->business->fresh()->identity_confirmed_at);
    }

    public function test_the_setup_screen_writes_through_the_settings_door(): void
    {
        // بابان يكتبان مفتاحًا واحدًا يفترقان يومًا — فالحفظ بالباب القائم
        $this->actingAs($this->owner)->post('/admin/settings', [
            'shop_name' => 'زهور مسقط',
            'phone' => '99887766',
            'address' => 'الخوض',
        ])->assertSessionHasNoErrors();

        $this->assertSame('زهور مسقط', $this->business->fresh()->name);
        $this->assertSame('99887766', $this->business->fresh()->phone);
    }

    // ————— الورق —————

    public function test_a_tax_invoice_does_not_carry_a_name_the_system_wrote(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)
            ->get('/admin/orders/'.$order->number.'/tax-invoice')
            ->assertStatus(409);
    }

    public function test_the_tax_invoice_opens_once_the_shop_is_named(): void
    {
        $this->business->update(['name' => 'زهور مسقط']);
        $order = $this->order();

        $this->actingAs($this->owner)
            ->get('/admin/orders/'.$order->number.'/tax-invoice')
            ->assertOk();
    }

    // ————— ما لا يُسأل عنه مرّتين —————

    public function test_a_restored_shop_is_not_asked_its_name_again(): void
    {
        // الإقرارُ من ملفّ التاجر لا من ملفّ المنصّة — فيُنسخ ويُستعاد معه
        $this->assertContains('identity_confirmed_at', Business::BACKUP_FIELDS);
    }

    public function test_the_vat_step_has_an_answer_for_the_unregistered(): void
    {
        /*
         * سؤالٌ بلا جوابٍ صحيحٍ لغير المسجَّل يبقى معلّقًا أبدًا فيُقرأ عيبًا
         * في النظام.
         */
        $steps = collect(ShopIdentity::steps($this->business))->keyBy('key');
        $this->assertFalse($steps['vat']['done']);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $steps = collect(ShopIdentity::steps($this->business->fresh()))->keyBy('key');
        $this->assertTrue($steps['vat']['done']);
    }
}
