<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلّ إعدادٍ معروضٍ يفعل شيئًا.
 *
 * كان في الشاشتين ثلاثة وأربعون مفتاحًا تُحفظ ولا يقرؤها سطرٌ واحد: يُطفئ
 * التاجر «بطاقة» فيبقى الصندوق يقبلها، ويكتب بادئة فاتورته فتصدر INV-،
 * ويضبط عملته AED فيرى ر.ع، ويشغّل المشغّل وضع الصيانة فيبيع الناس على
 * قاعدةٍ تُهاجَر تحتهم. والمقبض الذي لا يُمسك أسوأ من غيابه لأنه يُطمئن.
 *
 * فهذا الملفّ لا يختبر أن الحفظ يحفظ — ذاك كان يمرّ وهو راضٍ — بل أن
 * المحفوظ يُقرأ. وكلّ اختبارٍ هنا يفشل إن عاد مفتاحُه ميّتًا.
 */
class LiveSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'active' => true,
        ]);

    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
        Demo::flushCurrency();
    }

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }

    private function sell(string $method = 'نقدي', string $uuid = 'u-1')
    {
        return $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => $method,
            'client_uuid' => $uuid,
        ]);
    }

    /* --------------------------- ترقيم الفواتير --------------------------- */

    public function test_the_invoice_prefix_reaches_the_invoice(): void
    {
        $this->set('inv_prefix', 'فات-');

        $number = $this->sell()->assertOk()->json('invoice');

        $this->assertStringStartsWith('فات-', $number, 'البادئة المحفوظة لم تصل إلى رقم الفاتورة');
    }

    public function test_the_starting_number_is_where_the_first_invoice_begins(): void
    {
        $this->set('inv_prefix', 'A-');
        $this->set('inv_start', '500');

        $this->assertSame('A-000500', $this->sell()->assertOk()->json('invoice'));
    }

    public function test_the_starting_number_does_not_rewind_a_running_sequence(): void
    {
        /*
         * رقم البداية لأوّل فاتورةٍ بالبادئة، لا لكلّ فاتورة. وإلا لصدرت
         * فاتورتان بالرقم نفسه كلّما بيع شيء — وهي أسوأ حالةٍ في نظام فوترة.
         */
        $this->set('inv_prefix', 'A-');
        $this->set('inv_start', '500');

        $first = $this->sell(uuid: 'u-1')->json('invoice');
        $second = $this->sell(uuid: 'u-2')->json('invoice');

        $this->assertSame('A-000500', $first);
        $this->assertSame('A-000501', $second);
    }

    public function test_a_wildcard_in_the_prefix_is_refused_at_the_door(): void
    {
        // «%» في شرط LIKE تطابق كلّ فاتورة، فيقفز العدّاد إلى رقمٍ لا يخصّها
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), ['inv_prefix' => 'IN%-'])
            ->assertSessionHasErrors('inv_prefix');
    }

    /* ----------------------------- طرق الدفع ----------------------------- */

    public function test_a_disabled_payment_method_is_refused_by_the_server(): void
    {
        /*
         * الإخفاء من الشاشة لا يكفي: الطلب يصل من جهازٍ قد تكون شاشته قديمة،
         * ومن أطفأ البطاقة يريدها ممنوعةً لا مخفيّة.
         *
         * وكانت تُردّ إلى «نقدي» بصمت، فتُقيَّد بيعةُ بطاقةٍ نقدًا ويطلب
         * الإقفال مالًا لم يدخل الصندوق. فصارت تُرفض: خطأٌ يُقرأ خيرٌ من
         * تصحيحٍ لا يُرى.
         */
        $this->set('pay_card', '0');

        $this->sell(method: 'بطاقة')
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::where('is_held', false)->count());
    }

    public function test_an_enabled_method_passes_untouched(): void
    {
        $this->set('pay_card', '1');

        $this->sell(method: 'بطاقة')->assertOk();

        $this->assertSame('بطاقة', Order::latest('id')->first()->payment_method);
    }

    public function test_turning_everything_off_leaves_cash_not_a_dead_till(): void
    {
        // منعُ وسيلةٍ إعداد، وإيقافُ البيع عطل
        foreach (['pay_cash', 'pay_card', 'pay_transfer'] as $key) {
            $this->set($key, '0');
        }

        // المُطفأة ممنوعة…
        $this->sell(method: 'بطاقة', uuid: 'off-1')->assertStatus(422);

        // …والنقد يبقى قائمًا وإن أُطفئ، فلا يقف البيع
        $this->sell(method: 'نقدي', uuid: 'off-2')->assertOk();

        $this->assertSame('نقدي', Order::latest('id')->first()->payment_method);
    }

    public function test_the_sale_screen_only_offers_what_is_allowed(): void
    {
        $this->set('pay_transfer', '0');

        $this->actingAs($this->owner);
        $this->activatePosDevice($this->business->id, Branch::where('business_id', $this->business->id)->value('id'));

        $methods = $this->get(route('pos.index'))
            ->viewData('page')['props']['settings']['paymentMethods'];

        $this->assertSame(['نقدي', 'بطاقة'], $methods);
    }

    /* ------------------------------- العملة ------------------------------- */

    public function test_the_currency_setting_decides_what_the_merchant_sees(): void
    {
        /*
         * جدول العملات لا تملؤه شاشة، فكان السقوط على «ر.ع» مثبّتًا في الكود:
         * تاجرٌ في دبي يضبط AED ويرى الريال العماني في كل رقمٍ عنده.
         */
        $this->set('currency', 'AED');
        $this->actingAs($this->owner);

        $this->assertStringContainsString('د.إ', Demo::money(10));
    }

    public function test_the_decimal_places_setting_is_obeyed(): void
    {
        $this->set('currency', 'AED');
        $this->set('decimals', '0');
        $this->actingAs($this->owner);

        $this->assertStringStartsWith('10 ', Demo::money(10));
    }

    public function test_the_symbol_can_sit_before_the_amount(): void
    {
        $this->set('currency', 'USD');
        $this->set('symbol_pos', 'before');
        $this->actingAs($this->owner);

        $this->assertSame('$ 10.00', Demo::money(10));
    }

    /* ---------------------------- وضع الصيانة ---------------------------- */

    public function test_maintenance_mode_stops_the_merchant(): void
    {
        $this->platform('maintenance_mode', '1');

        $this->actingAs($this->owner)->get(route('admin.dashboard'))
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->component('Auth/Maintenance'));
    }

    public function test_maintenance_mode_does_not_stop_the_platform_admin(): void
    {
        // من يوقف النظام يحتاج أن يدخله ليصلحه
        $this->platform('maintenance_mode', '1');

        $super = User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($super)->get(route('super-admin.dashboard'))->assertOk();
    }

    public function test_maintenance_mode_keeps_the_session_alive(): void
    {
        // وقفةٌ دقائق لا تستحقّ أن يعود كلّ تاجرٍ ليكتب كلمة سرّه
        $this->platform('maintenance_mode', '1');

        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertStatus(503);

        $this->assertAuthenticatedAs($this->owner);
    }

    /* --------------------------- إعدادات المنصة --------------------------- */

    public function test_the_trial_period_gives_a_new_store_an_end_date(): void
    {
        $this->platform('trial_days', '30');

        $super = $this->superAdmin();
        $this->actingAs($super)->post(route('super-admin.businesses.store'), [
            'name' => 'متجر جديد', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'newstore', 'login_password' => 'secret123',
        ]);

        $created = Business::where('name', 'متجر جديد')->firstOrFail();

        $this->assertNotNull($created->ends_at, 'شركةٌ بلا تاريخ انتهاء تعمل إلى الأبد');
        // مقارنة تواريخ لا عدد أيّام: الفرق بالساعات يتقلّب بساعة تشغيل الاختبار
        $this->assertSame(now()->addDays(30)->toDateString(), $created->ends_at->toDateString());
    }

    public function test_an_explicit_end_date_beats_the_trial_default(): void
    {
        $this->platform('trial_days', '30');

        $super = $this->superAdmin();
        $this->actingAs($super)->post(route('super-admin.businesses.store'), [
            'name' => 'متجر بعقد', 'type' => 'عام', 'status' => 'نشط',
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addYear()->toDateString(),
            'login_username' => 'contract', 'login_password' => 'secret123',
        ]);

        $created = Business::where('name', 'متجر بعقد')->firstOrFail();

        $this->assertSame(now()->addYear()->toDateString(), $created->ends_at->toDateString());
    }

    public function test_the_default_plan_is_attached_by_name(): void
    {
        $plan = Plan::create(['name' => 'أساسية', 'monthly_price' => 15, 'yearly_price' => 150]);
        $this->platform('default_plan', 'أساسية');

        $super = $this->superAdmin();
        $this->actingAs($super)->post(route('super-admin.businesses.store'), [
            'name' => 'متجر بلا باقة', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'noplan', 'login_password' => 'secret123',
        ]);

        $this->assertSame($plan->id, Business::where('name', 'متجر بلا باقة')->value('plan_id'));
    }

    public function test_turning_off_auto_suspend_keeps_an_expired_store_working(): void
    {
        /*
         * متابعةٌ يدوية: تُرسل التنبيهات ويبقى المتجر يعمل حتى يقفله المشغّل
         * بنفسه. وكان المربّع معروضًا ولا يقرؤه شيء — والنظام يقفل دائمًا.
         */
        $this->business->update(['ends_at' => now()->subMonths(6)]);
        $this->platform('grace_days', '0');

        $this->platform('auto_suspend', '1');
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertRedirect(route('subscription.expired'));

        $this->platform('auto_suspend', '0');
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
    }

    private function superAdmin(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'المشغّل', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
}
