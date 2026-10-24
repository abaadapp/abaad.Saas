<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإلغاءُ يردّ إلى الرفّ ما خرج منه — لا أقلّ ولا أكثر.
 *
 * وكان يخطئ في بابين، كلاهما في `OrderCorrection::moveStock`:
 *
 *   - **صنفٌ خارج دفتر المخزون** (`tracks_stock = false`) لا يُخصم عند بيعه
 *     (`StockLedger::move`، `SaleLines`). والإلغاءُ كان يزيد كميّتَه ويكتب
 *     حركة «+٣» كأنّ ثلاث خدماتٍ عادت إلى رفٍّ لا وجود له — وتعديلُ البند
 *     يُنقصها. فتكبر الكميّةُ القديمةُ المحفوظةُ لإعادة التتبّع أو تصغر بلا
 *     بضاعة.
 *   - **إضافةٌ بيعت بندًا مستقلًّا** (`standalone_addon_id`، بلا `product_id`)
 *     تخصم صنفَ مخزونها عند البيع (`SaleLines::addonConsumption`). والإلغاءُ
 *     كان يخرج عند «لا صنف» فلا يعود الدبُّ إلى الرفّ وهو في الثلاجة.
 *
 * وما يُردّ يُقرأ بقاعدة البيع نفسها: `AddonStock::each` و`Recipe::units`.
 */
class ACancelledSaleReturnsOnlyWhatLeftTheShelfTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل', 'email' => 'c@shelf.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'o@shelf.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Customer::create(['business_id' => $this->business->id, 'name' => 'خالد', 'phone' => '91234567', 'language' => 'ar']);
    }

    /** طلبٌ له موعد — يبدأ «جديد» فيجوز إلغاؤه، خلافًا لبيعة المنضدة */
    private function sell(array $items): Order
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson('/pos/checkout', [
                'items' => $items, 'payment_method' => 'نقدي', 'customer' => 'خالد', 'client_uuid' => uniqid('c', true),
                'fulfillment_type' => 'pickup', 'scheduled_for' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    private function cancel(Order $order): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function shelf(Product $p): array
    {
        return [
            (int) $p->fresh()->quantity,
            (int) BranchStock::where('product_id', $p->id)->sum('quantity'),
        ];
    }

    public function test_cancelling_a_service_puts_nothing_back_on_a_shelf_it_does_not_have(): void
    {
        $service = Product::create([
            'business_id' => $this->business->id, 'name' => 'رسم توصيل', 'price' => 2, 'cost' => 0,
            'quantity' => 0, 'alert_qty' => 10, 'active' => true,
        ]);
        $service->forceFill(['tracks_stock' => false])->save();

        $order = $this->sell([['id' => $service->id, 'name' => 'رسم توصيل', 'qty' => 3]]);
        $this->assertSame([0, 0], $this->shelf($service), 'خُصمت خدمةٌ عند بيعها');

        $this->cancel($order);

        $this->assertSame('ملغي', $order->fresh()->status);
        $this->assertSame([0, 0], $this->shelf($service), 'عادت ثلاثُ خدماتٍ إلى رفٍّ لا وجود له');
        $this->assertSame(0, InventoryMovement::where('product_id', $service->id)->count(), 'حركةُ مخزونٍ لصنفٍ خارج الدفتر');
    }

    public function test_an_unlinked_product_keeps_its_old_quantity_through_a_cancel(): void
    {
        // كان على رفٍّ بسبع قطع، ثمّ أُوقف تتبّعُه — والسبعُ تبقى لإعادة التتبّع
        $wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'تغليف', 'price' => 1, 'cost' => 0.5,
            'quantity' => 7, 'alert_qty' => 2, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->branch->id, $wrap->id, 7);
        $wrap->forceFill(['tracks_stock' => false])->save();

        $order = $this->sell([['id' => $wrap->id, 'name' => 'تغليف', 'qty' => 2]]);
        $this->cancel($order);

        $this->assertSame([7, 7], $this->shelf($wrap));
    }

    public function test_a_standalone_addon_line_returns_its_stock_item_once(): void
    {
        $bear = Product::create([
            'business_id' => $this->business->id, 'name' => 'دبّ', 'price' => 6, 'cost' => 3,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->branch->id, $bear->id, 5);
        $addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'دبّ صغير', 'price' => 6, 'active' => true,
            'inventory_product_id' => $bear->id, 'inventory_quantity' => 1,
        ]);

        $order = $this->sell([['id' => null, 'addon_id' => $addon->id, 'name' => 'دبّ صغير', 'qty' => 2]]);
        $this->assertNull($order->items()->sole()->product_id, 'البندُ إضافةٌ مستقلّة بلا صنف');
        $this->assertSame([3, 3], $this->shelf($bear), 'البيعُ يخصم الدبّين');

        $this->cancel($order);
        $this->assertSame([5, 5], $this->shelf($bear), 'بقي الدبّان منقوصَين من الرفّ وهما في الثلاجة');

        // ومرّةً واحدة: إلغاءٌ ثانٍ لا يردّ شيئًا
        $this->actingAs($this->owner)->post(route('admin.orders.status', $order->number), ['status' => 'ملغي']);
        $this->assertSame([5, 5], $this->shelf($bear));
    }

    public function test_a_standalone_addon_of_half_a_roll_returns_what_the_sale_rounded_up(): void
    {
        $roll = Product::create([
            'business_id' => $this->business->id, 'name' => 'لفّة شريط', 'price' => 3, 'cost' => 1,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->branch->id, $roll->id, 10);
        $addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط', 'price' => 1, 'active' => true,
            'inventory_product_id' => $roll->id, 'inventory_quantity' => 0.5,
        ]);

        // ثلاثُ أنصافٍ = لفّةٌ ونصف ← لفّتان، كما في البيع
        $order = $this->sell([['id' => null, 'addon_id' => $addon->id, 'name' => 'شريط', 'qty' => 3]]);
        $this->assertSame([8, 8], $this->shelf($roll));

        $this->cancel($order);
        $this->assertSame([10, 10], $this->shelf($roll));
    }

    /* ═══════════ والكسرُ يُجمع على الطلب كلِّه — كما في البيع ═══════════ */

    private function roll(): Product
    {
        $roll = Product::create([
            'business_id' => $this->business->id, 'name' => 'لفّة شريط', 'price' => 3, 'cost' => 1,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->branch->id, $roll->id, 10);

        return $roll;
    }

    private function bouquetUsing(Product $roll, string $name, float $per): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => $name, 'price' => 10, 'cost' => 0,
            'quantity' => 0, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $p->id,
            'component_product_id' => $roll->id, 'quantity' => $per, 'wastage_percent' => 0,
        ]);

        return $p;
    }

    private function ribbonAddon(Product $roll, string $name): Addon
    {
        return Addon::create([
            'business_id' => $this->business->id, 'name' => $name, 'price' => 1, 'active' => true,
            'inventory_product_id' => $roll->id, 'inventory_quantity' => 0.5,
        ]);
    }

    public function test_two_bouquets_of_half_a_roll_each_return_the_one_roll_they_took(): void
    {
        /*
         * البيعُ يجمع نصفَي اللفّة على السلّة ثمّ يرفع مرّةً: لفّةٌ واحدة. وكان
         * الإلغاءُ يرفع كلَّ بندٍ وحده: نصفٌ ← واحدة، مرّتين — فيعود إلى الرفّ
         * لفّتان عن لفّةٍ أُخذت، وتظهر في الجرد لفّةٌ لم تُشترَ.
         */
        $roll = $this->roll();
        $a = $this->bouquetUsing($roll, 'باقة أ', 0.5);
        $b = $this->bouquetUsing($roll, 'باقة ب', 0.5);

        $order = $this->sell([
            ['id' => $a->id, 'name' => 'باقة أ', 'qty' => 1],
            ['id' => $b->id, 'name' => 'باقة ب', 'qty' => 1],
        ]);
        $this->assertSame([9, 9], $this->shelf($roll));

        $this->cancel($order);
        $this->assertSame([10, 10], $this->shelf($roll), 'عادت لفّتان عن لفّةٍ أُخذت');
    }

    public function test_two_ribbon_addons_of_half_a_roll_return_the_one_roll_they_took(): void
    {
        $roll = $this->roll();
        $red = $this->ribbonAddon($roll, 'شريط أحمر');
        $gold = $this->ribbonAddon($roll, 'شريط ذهبي');

        $order = $this->sell([
            ['id' => null, 'addon_id' => $red->id, 'name' => 'شريط أحمر', 'qty' => 1],
            ['id' => null, 'addon_id' => $gold->id, 'name' => 'شريط ذهبي', 'qty' => 1],
        ]);
        $this->assertSame([9, 9], $this->shelf($roll));

        $this->cancel($order);
        $this->assertSame([10, 10], $this->shelf($roll));
    }

    public function test_a_recipe_half_and_an_addon_half_return_the_two_rolls_the_sale_took(): void
    {
        // والبيعُ يرفع الوصفةَ والإضافةَ كلًّا وحده: نصفٌ ← واحدة، ونصفٌ ← واحدة
        $roll = $this->roll();
        $bouquet = $this->bouquetUsing($roll, 'باقة', 0.5);
        $ribbon = $this->ribbonAddon($roll, 'شريط');

        $order = $this->sell([[
            'id' => $bouquet->id, 'name' => 'باقة', 'qty' => 1,
            'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]],
        ]]);
        $this->assertSame([8, 8], $this->shelf($roll));

        $this->cancel($order);
        $this->assertSame([10, 10], $this->shelf($roll));
    }
}
