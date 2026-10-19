<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\CustomOrderTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\User;
use App\Support\DeliveryPaper;
use App\Support\ItemAddons;
use App\Support\Lists\ProductsList;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * الاسمُ بلغة الشاشة — من لقطته إن حُفظ، وكما كُتب إن لم يُحفظ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - **الإضافةُ المبيعة** تحمل اسمَها الإنجليزيّ في لقطتها (`order_item_addons.name_en`)
 *     — فتُعرض به في الواجهة الإنجليزيّة: بطاقةُ التجهيز، وتفاصيلُ الطلب،
 *     والإيصالُ الحراريّ، وسندُ التسليم. وبالعربيّة كما كانت.
 *   - **وبندُ الطلب** لا يحفظ اسمًا إنجليزيًّا: يُعرض كما بيع في اللغتين — لا اسمُ
 *     الكتالوج اليوم ولا ترجمةٌ تُخترع، ولو تغيّر الصنفُ أو حُذفت الإضافة بعده.
 *   - **والكتالوجُ الحيّ** بلغة الشاشة: قائمةُ الأصناف وأقسامُها والتقارير، والقيمةُ
 *     التي تطابقها المرشّحات تبقى كما حُفظت.
 *   - **والبحث** يجد الصنفَ باسمه الإنجليزيّ.
 *   - **ولا تُكتب لغةُ الشاشة في سجلٍّ دائم:** طلبٌ مخصَّصٌ يبيعه كاشيرٌ واجهتُه
 *     إنجليزيّة يُحفظ باسم قالبه كما كتبه التاجر.
 *   - **ولا تنتقل لغةُ موظّفٍ إلى آخر:** كلُّ طلبٍ بلغة جلسته.
 *   - **والمال لا يتبع اللغة:** الأسعارُ والكميّاتُ في اللغتين واحدة.
 */
class EveryNameSpeaksTheScreensLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Product $bouquet;

    private Addon $chocolate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود اللغتين', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@lang.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $category = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات المناسبات', 'name_en' => 'Occasion bouquets']);
        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'category_id' => $category->id, 'name' => 'باقة ورد', 'name_en' => 'Rose Bouquet',
            'price' => 25, 'cost' => 10, 'quantity' => 40, 'alert_qty' => 1,
        ]);
        $this->chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'price' => 3.5, 'active' => true]);

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** طلبٌ على اللوحة: باقةٌ بشوكولاتتين — والإضافةُ بلقطتها في اللغتين */
    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::CONFIRMED, 'is_held' => false, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 32, 'total' => 32, 'ordered_at' => now(), 'scheduled_for' => now()->addHours(2), 'fulfillment_type' => 'delivery',
            'recipient_name' => 'مريم', 'delivery_address' => 'الخوير',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->bouquet->id, 'name' => 'باقة ورد',
            'price' => 25, 'cost' => 10, 'quantity' => 1, 'total' => 32, 'addons_total' => 7,
        ]);
        OrderItemAddon::create(['order_item_id' => $item->id, 'addon_id' => $this->chocolate->id, 'name' => 'شوكولاتة', 'name_en' => 'Chocolate', 'unit_price' => 3.5, 'quantity' => 2, 'total' => 7]);

        return $order->fresh('items.addons');
    }

    private function english()
    {
        return $this->withSession(['locale' => 'en']);
    }

    /* ============================== الإضافةُ من لقطتها ============================== */

    public function test_the_board_card_names_the_addon_in_the_screens_language(): void
    {
        $order = $this->order();
        $addon = fn ($page) => collect($page['props']['orders'])->firstWhere('number', $order->number)['items'][0]['addons'][0];

        $ar = $this->get(route('admin.preparation.index'))->viewData('page');
        $this->assertSame('شوكولاتة', $addon($ar)['name']);

        $en = $this->english()->get(route('admin.preparation.index'))->viewData('page');
        $this->assertSame('Chocolate', $addon($en)['name']);
        $this->assertSame(2, $addon($en)['qty'], 'والكميّةُ لا تتبع اللغة');
        $this->assertSame('باقة ورد', collect($en['props']['orders'])->firstWhere('number', $order->number)['items'][0]['name'], 'وبندُ الطلب كما بيع — لا اسمُ الكتالوج اليوم');
    }

    public function test_the_order_details_name_the_addon_in_the_screens_language(): void
    {
        $order = $this->order();

        $this->english()->get(route('admin.orders.show', $order->number))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.items.0.addons.0.name', 'Chocolate')->where('order.items.0.addons.0.total', 7));
    }

    public function test_the_thermal_receipt_and_the_delivery_strip_print_the_addon_in_the_papers_language(): void
    {
        $order = $this->order();

        $this->assertSame('شوكولاتة', ItemAddons::of($order->items->first())['rows'][0]['name']);

        app()->setLocale('en');
        $this->assertSame('Chocolate', ItemAddons::of($order->items->first())['rows'][0]['name']);
        $receipt = PdfController::saleHtml($this->shop->id, $order, true)['html'];
        $this->assertStringContainsString('Chocolate', $receipt);
        $this->assertStringContainsString('7.000', $receipt, 'والمالُ نفسُه');

        $this->shop->forceFill(['thermal_delivery_note_enabled' => true])->save();
        $this->assertStringContainsString('Chocolate', DeliveryPaper::strip($this->shop->id, $order));
    }

    public function test_an_addon_renamed_or_deleted_after_the_sale_keeps_its_snapshot_names(): void
    {
        $order = $this->order();
        $this->chocolate->forceFill(['name' => 'شوكولاتة فاخرة', 'name_en' => 'Fancy chocolate'])->save();
        $this->chocolate->delete();
        $this->bouquet->forceFill(['name' => 'باقة كبرى', 'name_en' => 'Grand Bouquet'])->save();

        app()->setLocale('en');
        $order = $order->fresh('items.addons');
        $this->assertSame('Chocolate', ItemAddons::of($order->items->first())['rows'][0]['name']);
        $this->assertSame('باقة ورد', $order->items->first()->displayName(), 'لا اسمُ الكتالوج اليوم ولا ترجمةٌ تُخترع');
    }

    /* ============================== الكتالوجُ الحيّ ============================== */

    public function test_the_products_list_and_its_categories_speak_the_screens_language_and_filter_by_the_saved_name(): void
    {
        app()->setLocale('en');
        $row = ProductsList::row($this->bouquet->fresh('category'), []);
        $this->assertSame(['Rose Bouquet', 'Occasion bouquets', 'باقة ورد', 'باقات المناسبات'], [$row['label'], $row['cat_label'], $row['name'], $row['cat']]);

        app()->setLocale('ar');
        $row = ProductsList::row($this->bouquet->fresh('category'), []);
        $this->assertSame(['باقة ورد', 'باقات المناسبات'], [$row['label'], $row['cat_label']]);

        $this->english()->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('categories.0.label', 'Occasion bouquets')->where('categories.0.name', 'باقات المناسبات'));
    }

    public function test_a_product_is_found_by_its_english_name(): void
    {
        $this->get(route('admin.products.index', ['q' => 'Rose']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.0.id', $this->bouquet->id)->where('products.0.label', 'باقة ورد'));

        $this->getJson(route('admin.search', ['q' => 'rose']))->assertOk()
            ->assertJsonFragment(['label' => 'باقة ورد']);
    }

    /* ============================== لا تُكتب لغةُ الشاشة في سجلّ ============================== */

    public function test_an_english_cashier_saves_a_custom_order_under_the_templates_own_name(): void
    {
        $template = CustomOrderTemplate::ensureDefault($this->shop->id);
        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@lang.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->withSession(['locale' => 'en'])->postJson('/pos/checkout', [
            'items' => [['name' => 'Custom Order', 'qty' => 1, 'custom' => ['mode' => 'value', 'price' => 15, 'components' => []]]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertSuccessful();

        $name = Order::latest('id')->firstOrFail()->items()->value('name');
        $this->assertSame((string) ($template?->name ?: 'طلب مخصص'), $name, 'اسمُ القالب كما كتبه التاجر — لا لغةُ شاشة الكاشير');
    }

    public function test_one_employees_language_does_not_reach_another(): void
    {
        $order = $this->order();
        $colleague = User::create([
            'business_id' => $this->shop->id, 'name' => 'زميل', 'email' => 'mate@lang.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->english()->get(route('admin.preparation.index'))->assertOk();

        // جلسةٌ أخرى بلا لغةٍ مختارة — تقرأ لغتَها هي، لا لغةَ من سبقها
        $this->flushSession();
        $page = $this->actingAs($colleague)->get(route('admin.preparation.index'))->viewData('page');
        $this->assertSame('شوكولاتة', collect($page['props']['orders'])->firstWhere('number', $order->number)['items'][0]['addons'][0]['name']);
    }
}
