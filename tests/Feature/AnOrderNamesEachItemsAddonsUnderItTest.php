<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Addon;
use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentPaper;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * إضافاتُ كلّ بندٍ تحته — في صفحة الطلب وفاتورة A4 وسند التسليم.
 *
 * ═══ العطب ═══
 *
 * الشريطُ الحراريّ يسمّي الإضافات منذ `ItemAddons`، وصفحةُ الطلب وفاتورةُ A4
 * لا: «باقة ورد 20.000» والمجموعُ «22.000»، ولا سطرَ يقول من أين الفرق.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بيعةُ صندوقٍ بصنفين، لكلٍّ إضافاته: كلُّ إضافةٍ تحت صنفها بكميّتها
 *     وثمنها، ومبلغُ الصنف ثمنُه وحده — فتجمع الأسطرُ إلى المجموع الفرعيّ
 *     ولا يُكتب المالُ مرّتين. والطلبُ والدفترُ والمخزونُ كما كانت.
 *   - ما داخل الثمن (191 بندًا على الإنتاج) يُسمّى بلا مال، ولقطاتٌ لا تطابق
 *     ما جُبي صفٌّ واحدٌ بما جُبي، ولقطةٌ بلا اسمٍ إنجليزيّ تُكتب بالعربيّ.
 *   - إضافةٌ من رفّ بوتيك: الموظّفُ يرى صنفَها ورفَّها، وورقةُ الزبون لا ترى
 *     البوتيكَ ولا التكلفة.
 *   - سندُ التسليم يسمّي الإضافات، وبلا مالٍ حين تُطفأ أسعارُه.
 */
class AnOrderNamesEachItemsAddonsUnderItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'Ribbon Flowers', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'owner@addon-lines.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ============================== أدوات ============================== */

    private function product(string $name, float $price, int $qty = 50): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0, 'quantity' => $qty, 'alert_qty' => 0, 'active' => true]);
    }

    private function addon(string $name, float $price, ?string $en = null): Addon
    {
        return Addon::create(['business_id' => $this->shop->id, 'name' => $name, 'name_en' => $en, 'price' => $price, 'active' => true]);
    }

    /** طلبٌ مكتوبٌ كما في القاعدة — للصور التاريخيّة التي لا يكتبها الصندوقُ اليوم */
    private function stored(array $items): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'OLD-'.(++$this->n), 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0, 'ordered_at' => now(),
        ]);
        $sum = 0.0;
        foreach ($items as $i) {
            $item = OrderItem::create([
                'order_id' => $order->id, 'name' => $i['name'], 'price' => $i['price'], 'quantity' => 1, 'cost' => 0,
                'total' => $i['price'], 'addons_total' => $i['charged'],
            ]);
            foreach ($i['addons'] as [$name, $unit, $qty, $en, $shelf]) {
                OrderItemAddon::create([
                    'order_item_id' => $item->id, 'name' => $name, 'name_en' => $en, 'unit_price' => $unit, 'quantity' => $qty,
                    'total' => round($unit * $qty, 3), 'cost' => 4.5, 'inventory_product_id' => $shelf, 'inventory_quantity' => $shelf ? 1 : null,
                ]);
            }
            $sum += $i['price'] + $i['charged'];
        }
        $order->update(['subtotal' => $sum, 'total' => $sum]);

        return $order->fresh('items.addons');
    }

    private function a4(Order $order): string
    {
        return PdfController::saleHtml($this->shop->id, $order, thermal: false)['html'];
    }

    /** عمودُ «المبلغ» في جدول الأصناف — مبلغُ كلّ صفٍّ كما رُسم */
    private static function amounts(string $html): array
    {
        preg_match('#<table class="items">(.*?)</table>#s', $html, $table);
        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $table[1] ?? '', $rows);
        $out = [];
        foreach ($rows[1] as $row) {
            // آخرُ خانة مبلغٍ في الصفّ — وصفُّ «إضافات» المجمَّع لا سعرَ واحدٍ له
            if (preg_match_all('#<td class="amt"><span dir="ltr">([^<]*)</span></td>#u', $row, $cells) && $cells[1] !== []) {
                preg_match('/\d[\d,]*\.\d+/', str_replace(',', '', end($cells[1])), $num);
                $out[] = (float) ($num[0] ?? 0);
            }
        }

        return $out;
    }

    /* ============================== بيعةُ الصندوق ============================== */

    public function test_two_products_each_show_their_own_addons_on_the_page_and_the_invoice_and_money_is_counted_once(): void
    {
        $rose = $this->product('باقة ورد', 20);
        $small = $this->product('باقة صغيرة', 12);
        $choc = $this->addon('شوكولاتة', 3, 'Chocolate');
        $card = $this->addon('بطاقة', 1, 'Card');
        $ribbon = $this->addon('شريط', 2, 'Ribbon');

        $this->postJson('/pos/checkout', [
            'items' => [
                ['id' => $rose->id, 'name' => $rose->name, 'qty' => 1, 'price' => 20, 'addons' => [['addon_id' => $choc->id, 'qty' => 2], ['addon_id' => $card->id, 'qty' => 1]]],
                ['id' => $small->id, 'name' => $small->name, 'qty' => 1, 'price' => 12, 'addons' => [['addon_id' => $ribbon->id, 'qty' => 1]]],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame([41.0, 41.0], [(float) $order->subtotal, (float) $order->total]);

        $before = [DB::table('journal_lines')->count(), Ledger::netRevenue($this->shop->id), $rose->fresh()->quantity, $small->fresh()->quantity];

        // صفحةُ الطلب: كلُّ إضافةٍ تحت صنفها، ومبلغُ الصنف ثمنُه وحده
        $this->get(route('admin.orders.show', $order->number))->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('order.items.0.addons_mode', 'priced')
                ->where('order.items.0.own_total', 20)
                ->where('order.items.0.total', 27)
                ->where('order.items.0.addons.0.name', 'شوكولاتة')
                ->where('order.items.0.addons.0.qty', 2)
                ->where('order.items.0.addons.0.unit', 3)
                ->where('order.items.0.addons.0.total', 6)
                ->where('order.items.0.addons.1.name', 'بطاقة')
                ->where('order.items.1.own_total', 12)
                ->where('order.items.1.addons.0.name', 'شريط')
                ->where('order.items.1.addons.0.from', null)
                ->etc());

        // فاتورة A4: الصفوفُ بالترتيب، تجمع إلى المجموع الفرعيّ — لا مالَ مرّتين
        $html = $this->a4($order);
        $this->assertSame(3, substr_count($html, 'data-addon="priced"'));
        $this->assertSame([20.0, 6.0, 1.0, 12.0, 2.0], self::amounts($html));
        $this->assertSame(41.0, array_sum(self::amounts($html)));
        $this->assertLessThan(strpos($html, 'باقة صغيرة'), strpos($html, 'بطاقة'), 'البطاقةُ تحت الباقة الكبيرة لا الصغيرة');

        // والعرضُ لا يكتب شيئًا: الطلبُ والدفترُ والمخزونُ كما كانت
        $this->assertSame($before, [DB::table('journal_lines')->count(), Ledger::netRevenue($this->shop->id), $rose->fresh()->quantity, $small->fresh()->quantity]);
        $this->assertSame([41.0, 41.0], [(float) $order->fresh()->subtotal, (float) $order->fresh()->total]);

        // وبالإنجليزيّة: اسمُ الإضافة من لقطتها
        app()->setLocale('en');
        $this->assertStringContainsString('+ Chocolate', $this->a4($order->fresh('items.addons')));
    }

    /* ============================== الطلباتُ التاريخيّة ============================== */

    public function test_addons_inside_the_price_are_named_without_money_and_an_old_snapshot_without_english_keeps_its_name(): void
    {
        $order = $this->stored([['name' => 'باقة بميزانيّة', 'price' => 35, 'charged' => 0, 'addons' => [['ورد أحمر', 5, 3, null, null]]]]);

        $html = $this->a4($order);
        $this->assertSame(1, substr_count($html, 'data-addon="included"'));
        $this->assertStringContainsString('ورد أحمر', $html);
        $this->assertStringContainsString('ضمن السعر', $html);
        $this->assertSame([35.0], self::amounts($html), 'ما داخل الثمن لا يُكتب مالًا');

        $this->get(route('admin.orders.show', $order->number))->assertInertia(fn (Assert $p) => $p
            ->where('order.items.0.addons_mode', 'included')->where('order.items.0.own_total', 35)->where('order.items.0.total', 35)->etc());

        app()->setLocale('en');
        $en = $this->a4($order->fresh('items.addons'));
        $this->assertStringContainsString('ورد أحمر', $en, 'لقطةٌ بلا اسمٍ إنجليزيّ تُكتب كما حُفظت');
        $this->assertStringContainsString('included in the price', $en);
    }

    public function test_snapshots_that_do_not_match_the_charge_print_one_row_with_what_was_charged(): void
    {
        $order = $this->stored([['name' => 'باقة', 'price' => 20, 'charged' => 5, 'addons' => [['شوكولاتة', 3, 1, null, null], ['بطاقة', 1, 1, null, null]]]]);

        $html = $this->a4($order);
        $this->assertSame(1, substr_count($html, 'data-addon="bundled"'));
        $this->assertSame([20.0, 5.0], self::amounts($html), 'يُكتب ما جُبي فعلًا، ولا يُوزَّع بالظنّ');

        $this->get(route('admin.orders.show', $order->number))->assertInertia(fn (Assert $p) => $p
            ->where('order.items.0.addons_mode', 'bundled')->where('order.items.0.own_total', 20)->where('order.items.0.addons_total', 5)->etc());
    }

    /* ============================== رفُّ البوتيك ============================== */

    public function test_a_boutique_shelf_addon_names_its_piece_for_staff_and_never_reaches_the_customer_paper(): void
    {
        $lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $piece = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة فضّة من الرفّ', 'price' => 7, 'cost' => 0, 'quantity' => 5, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $lama->id]);
        $order = $this->stored([['name' => 'باقة', 'price' => 20, 'charged' => 7, 'addons' => [['فضّة', 7, 1, null, $piece->id]]]]);

        $this->get(route('admin.orders.show', $order->number))->assertInertia(fn (Assert $p) => $p
            ->where('order.items.0.addons.0.from', ['product' => 'قطعة فضّة من الرفّ', 'boutique' => 'بوتيك لمى'])
            ->missing('order.items.0.addons.0.cost')
            ->etc());

        $html = $this->a4($order);
        $this->assertStringContainsString('+ فضّة', $html);
        $this->assertStringNotContainsString('بوتيك لمى', $html);
        $this->assertStringNotContainsString('قطعة فضّة من الرفّ', $html);
        $this->assertStringNotContainsString('4.500', $html, 'تكلفةُ الإضافة لا تبلغ ورقةَ الزبون');
        $this->assertSame([20.0, 7.0], self::amounts($html));
    }

    /* ============================== سندُ التسليم ============================== */

    public function test_the_delivery_note_names_the_addons_and_drops_their_money_when_prices_are_off(): void
    {
        $order = $this->stored([['name' => 'باقة', 'price' => 20, 'charged' => 2, 'addons' => [['شريط', 2, 1, null, null]]]]);

        $doc = DocumentPaper::forDelivery($order);
        $this->assertSame('priced', $doc['items'][0]['addons']['mode']);

        $withPrices = view('documents.v1.partials.items', ['items' => $doc['items'], 'showPrices' => true, 'tpl' => []])->render();
        $this->assertSame([20.0, 2.0], self::amounts($withPrices));

        $without = view('documents.v1.partials.items', ['items' => $doc['items'], 'showPrices' => false, 'tpl' => []])->render();
        $this->assertStringContainsString('+ شريط', $without);
        $this->assertStringNotContainsString('2.000', $without);
    }
}
