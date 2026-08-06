<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من تُنسب إليه البيعة.
 *
 * كانت كل بيعة تُكتب باسم `auth()->user()` — فإن فتح صاحب النشاط الصندوق
 * صارت مبيعات اليوم كلها باسمه مهما تناوب عليه الموظفون، وتقارير الأداء
 * بلا معنى. صار الصندوق يسأل «من عليه الآن؟» ويُسجّل باسم من يُختار.
 */
class PosCashierTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true,
        ]);

        // البيع صار يتطلّب صندوقًا مفتوحًا — شرطٌ للسيناريو لا موضوعُه
        $this->openShiftFor($this->business->id);
    }

    private function sell()
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
        ]);
    }

    /* --------------------------- البوابة --------------------------- */

    public function test_the_owner_is_sent_to_choose_an_employee_before_selling(): void
    {
        $this->actingAs($this->owner)
            ->get(route('pos.index'))
            ->assertRedirect(route('pos.cashier'));
    }

    public function test_after_choosing_the_screen_opens(): void
    {
        $this->actingAs($this->owner)
            ->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id])
            ->assertRedirect(route('pos.index'));

        $this->get(route('pos.index'))->assertOk();
    }

    /**
     * متجرٌ يديره صاحبه وحده: لا موظف يُختار، فلو حجبنا الشاشة لبقيت مقفلة
     * إلى الأبد. الميزة تبدأ بالعمل عند إضافة أول موظف.
     */
    public function test_a_shop_with_no_employees_is_not_locked_out(): void
    {
        $this->cashier->delete();

        $this->actingAs($this->owner)->get(route('pos.index'))->assertOk();
    }

    /* ------------------------- نسبة البيعة ------------------------- */

    public function test_the_sale_is_recorded_under_the_chosen_employee_not_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id]);

        $this->sell()->assertOk();

        $order = Order::where('business_id', $this->business->id)->where('is_held', false)->latest('id')->first();

        $this->assertSame('أحمد', $order->employee_name);
        $this->assertSame($this->cashier->id, $order->user_id, 'لوحة الأداء تجمع على user_id');
    }

    public function test_the_movement_row_carries_the_same_name(): void
    {
        $this->actingAs($this->owner)
            ->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id]);

        $this->sell()->assertOk();

        $this->assertSame('أحمد', \DB::table('inventory_movements')
            ->where('business_id', $this->business->id)->latest('id')->value('employee_name'));
    }

    public function test_switching_employee_returns_to_the_picker(): void
    {
        $this->actingAs($this->owner)
            ->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id]);

        $this->post(route('pos.cashier.leave'))->assertRedirect(route('pos.cashier'));

        $this->get(route('pos.index'))->assertRedirect(route('pos.cashier'));
    }

    /* --------------------------- العزل --------------------------- */

    public function test_an_employee_of_another_shop_cannot_be_selected(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $other->id, 'name' => 'موظف الجار', 'email' => 'x@jar.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)->post(route('pos.cashier.select'), ['employee_id' => $theirs->id]);

        $this->get(route('pos.index'))->assertRedirect(route('pos.cashier'));
    }

    /**
     * الجلسة قد تبقى وقد يُوقَف الموظف بعدها. لو وثقنا بالمعرّف المخزَّن
     * لبقيت البيعات تُنسب إلى موظف لم يعد يعمل.
     */
    public function test_a_disabled_employee_stops_being_the_active_cashier(): void
    {
        // زميلٌ نشط حتى تبقى القائمة غير فارغة، وإلّا سقطت الحالة في قاعدة
        // «متجر بلا موظفين» فمرّت لسببٍ آخر ولم تفحص ما نريد فحصه
        User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner)
            ->post(route('pos.cashier.select'), ['employee_id' => $this->cashier->id]);

        $this->cashier->update(['status' => 'موقوف']);

        $this->get(route('pos.index'))->assertRedirect(route('pos.cashier'));
    }

    /** الكاشير الداخل بحسابه هو نفسه على الصندوق، فلا يُسأل ولا تُنسب بيعته لغيره */
    public function test_a_cashier_logging_in_is_not_asked_who_is_at_the_register(): void
    {
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk();

        $this->sell()->assertOk();

        $order = Order::where('business_id', $this->business->id)->where('is_held', false)->latest('id')->first();
        $this->assertSame('أحمد', $order->employee_name);
        $this->assertSame($this->cashier->id, $order->user_id);
    }

    /* -------------------- زر العودة إلى اللوحة -------------------- */

    /**
     * الإشارة التي تُظهر الزر يجب أن تطابق حارس المسار حرفيًّا. لو افترقتا
     * لظهر زرٌّ يقود إلى 403 — وهو أسوأ من غياب الزر.
     */
    public function test_the_back_to_panel_flag_matches_who_can_actually_enter_it(): void
    {
        $flag = fn (User $u) => $this->actingAs($u)->get(route('pos.orders'))
            ->viewData('page')['props']['auth']['entersPanel'];

        $this->assertTrue($flag($this->owner));
        $this->assertFalse($flag($this->cashier));

        // وما تقوله الإشارة هو ما يفعله الخادم فعلًا
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->cashier)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_the_owner_is_not_in_the_list_of_employees(): void
    {
        // بمستخدمٍ صراحةً: Demo::bid() لم تعد تخمّن متجرًا لمن لا متجر له
        $this->actingAs($this->owner);
        $names = collect(\App\Support\PosCashier::selectable())->pluck('name')->all();

        $this->assertContains('أحمد', $names);
        $this->assertNotContains('صاحب النشاط', $names, 'الغرض من الشاشة ألّا تُنسب المبيعات إليه');
    }
}
