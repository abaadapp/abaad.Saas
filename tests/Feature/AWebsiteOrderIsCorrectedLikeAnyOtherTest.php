<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ الموقع تُصحَّح كفاتورة الصندوق — بالشرطين نفسِهما، من البابين.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * سؤالٌ يسأله التاجر: «زبونٌ طلب من الموقع — أأقدر أعدّل فاتورته؟ وأمنح
 * موظّفي ذلك؟». والجوابُ مكتوبٌ في الكود متفرّقًا، فهذا يجمعه في مقياس:
 *
 *  · نعم — وبالشروط نفسِها: صلاحيةُ `order.edit`، ويومُ البيع لم ينتهِ،
 *    وفرعُ الطلب فرعُك. لا شرطَ زائدٌ على طلب الموقع ولا نقصان.
 *  · والصلاحيةُ تُمنح بالاسم لأيّ موظّف — وتعمل من **البابين**: شاشة
 *    المبيعات (`admin.orders.*`) وشاشة الصندوق (`pos.orders.*`). فمن مُنحها
 *    يُصحّح من حيث يقف، ولا يُطالَب بصلاحية صندوقٍ لا يقف عليه.
 *  · ومن لم تُمنح له يُردّ بنصٍّ يقول ماذا يفعل — لا «ممنوع».
 *
 * وليس حارسًا على تغييرٍ جديد: هذه أبوابٌ قائمة لم يكن يقيسها شيءٌ على
 * طلبات الموقع. وطلبُ الموقع غيرُ طلب الصندوق في ثلاثة أشياء — لا كاشيرَ
 * له، وغيرُ مدفوعٍ حين يُكتب، وموعدُه في يومٍ آخر — وكلُّها تمرّ قرب هذا
 * الباب.
 */
class AWebsiteOrderIsCorrectedLikeAnyOtherTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->app->setLocale('ar');

        $this->business = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'سعود',
            'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        $this->product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** طلبٌ يتمّه الزبون بنفسه من الموقع */
    private function webOrder(int $qty = 3): Order
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => $qty]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        return Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();
    }

    private function employee(string $email, string $role, array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /* ═════════════ نعم، تُصحَّح ═════════════ */

    public function test_the_owner_corrects_a_website_order_on_its_day(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('admin.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 1, 'reason' => 'الزبون قلّل الكمية'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $item->fresh()->quantity);
        // والإجماليُّ يتبع البند لا يبقى على ما كان
        $this->assertEquals(20, (float) $order->fresh()->total);
    }

    /** والبضاعةُ التي لم تخرج تعود إلى الرفّ — كأيّ تصحيح */
    public function test_the_stock_comes_back_to_the_shelf(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();
        $onShelf = (int) $this->product->fresh()->quantity;

        $this->actingAs($this->owner)
            ->put(route('admin.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 1, 'reason' => 'الزبون قلّل الكمية']);

        $this->assertSame($onShelf + 2, (int) $this->product->fresh()->quantity);
    }

    /* ═════════════ والموظّفُ يُمنحها بالاسم — من البابين ═════════════ */

    /** من شاشة المبيعات: موظّفُ مبيعاتٍ مُنح الفعل */
    public function test_a_granted_employee_corrects_from_the_sales_screen(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->employee('sales@abaad.om', 'sales', ['orders', 'pos', 'order.edit']))
            ->put(route('admin.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 2, 'reason' => 'صنفٌ نفد'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $item->fresh()->quantity);
    }

    /** ومن شاشة الصندوق: كاشيرٌ مُنح الفعل — ولا «المبيعات» عنده */
    public function test_a_granted_cashier_corrects_from_the_till(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->employee('cash@abaad.om', 'cashier', ['pos', 'order.edit']))
            ->put(route('pos.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 2, 'reason' => 'صنفٌ نفد'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $item->fresh()->quantity);
    }

    /** والتصحيحُ يُنسب إلى فاعله وسببه — لا يقع مجهولًا */
    public function test_the_correction_says_who_and_why(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();
        $clerk = $this->employee('who@abaad.om', 'sales', ['orders', 'order.edit']);

        $this->actingAs($clerk)->put(route('admin.orders.items.update', [$order->number, $item->id]),
            ['quantity' => 1, 'reason' => 'الزبون قلّل الكمية']);

        $edit = OrderEdit::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('الزبون قلّل الكمية', $edit->reason);
        $this->assertSame((int) $clerk->id, (int) $edit->user_id);
    }

    /* ═════════════ ومن لا يملكها يُردّ ═════════════ */

    public function test_an_employee_without_the_action_is_refused(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->employee('plain@abaad.om', 'sales', ['orders', 'pos']))
            ->put(route('admin.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 1, 'reason' => 'محاولة'])
            ->assertSessionHasErrors('permission');

        $this->assertSame(3, (int) $item->fresh()->quantity, 'فاتورةٌ صُحّحت بلا صلاحية');
    }

    /** والبابُ نفسُه من الصندوق — فلا يُفتح ما أُغلق بتبديل المسار */
    public function test_the_till_door_refuses_the_same_employee(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        $this->actingAs($this->employee('plain2@abaad.om', 'cashier', ['pos']))
            ->put(route('pos.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 1, 'reason' => 'محاولة'])
            ->assertSessionHasErrors('permission');

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /* ═════════════ ويومُ البيع حدُّها ═════════════ */

    /**
     * وطلبُ الموقع أكثرُ ما يلقى هذا الحدّ: يُكتب ليلًا لموعدٍ بعد يومين،
     * فيفتحه المحلُّ صباحًا وقد أُقفل. وهو الحدُّ المقصود لا عطبٌ — ويبقى
     * الإلغاءُ الكاملُ بابًا مفتوحًا بعده.
     */
    public function test_after_its_day_the_invoice_is_closed(): void
    {
        $order = $this->webOrder();
        $item = $order->items()->firstOrFail();

        Carbon::setTestNow('2027-02-02 09:00:00');

        $this->actingAs($this->owner)
            ->put(route('admin.orders.items.update', [$order->number, $item->id]),
                ['quantity' => 1, 'reason' => 'الزبون قلّل الكمية'])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(3, (int) $item->fresh()->quantity);
    }

    /* ═════════════ والصلاحيةُ معروضةٌ ليمنحها التاجر ═════════════ */

    /** وفعلٌ لا يظهر في قائمة «صلاحيات الموظفين» فعلٌ لا يُمنح */
    public function test_the_action_is_offered_to_the_merchant_by_name(): void
    {
        $this->assertArrayHasKey('order.edit', Permissions::actionLabels());
        $this->assertContains('order.edit', Permissions::grantable($this->owner));
    }
}
