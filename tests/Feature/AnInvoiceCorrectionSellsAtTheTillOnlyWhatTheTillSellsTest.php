<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\NotesAndEdits;
use App\Support\OrderCorrection;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * تصحيحُ فاتورة صندوقٍ لا يبيع ما لا يبيعه الصندوق.
 *
 * ═══ ما كان (#138 مدموجًا) ═══
 *
 * أُغلقت نقطةُ البيع عن صنفٍ («للمتجر الإلكترونيّ فقط»)، فردّه الصندوقُ بيعًا
 * وتعليقًا — وبقي بابٌ ثالث: «إضافة صنف» في تصحيح فاتورةٍ صدرت اليوم. يُسعَّر
 * بلا قناة (نشطٌ وحسب)، فيدخل الصنفُ فاتورةَ الصندوق ويخرج من رفّ الفرع
 * ويزيد إيرادَ اليوم: بيعٌ من الصندوق باسم التصحيح. أثبته هذا الملفُّ قبل
 * الإصلاح (البندُ أُضيف، والرفُّ ٥٠ ← ٤٩، والإجماليّ ٤٠ ← ٧٠).
 *
 * ═══ ما يُحرس ═══
 *
 *   - بندٌ **جديد** في فاتورة صندوق — إضافةً أو استبدالًا — يُسأل سؤالَ
 *     الصندوق، ويُردّ برسالةٍ تسمّيه، ولا يتحرّك رفٌّ ولا مالٌ ولا قيد.
 *   - البندُ **القديم** لصنفٍ صار «للموقع فقط» بعد بيعه يبقى بسعره وتكلفته،
 *     ويُصحَّح كمّيّةً وحذفًا كما كان.
 *   - طلبُ الموقع يُضاف إليه بيد الموظّف كما كان.
 *   - وحوارُ فاتورة الصندوق لا يعرض ما يردّه الخادم.
 */
class AnInvoiceCorrectionSellsAtTheTillOnlyWhatTheTillSellsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private Product $online;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
            'order_notes_and_edits_enabled' => true,
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'loyalty_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك',
            'email' => 'owner@ribbon.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->rose = $this->product('باقة ورد', 20, ['pos_enabled' => true, 'published' => true]);
        $this->online = $this->product('باقة الموقع', 30, ['pos_enabled' => false, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, array $channels): Product
    {
        $p = Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 5,
            'quantity' => 50, 'alert_qty' => 0, 'active' => true] + $channels);
        BranchStock::ensureAllocated($this->shop->id, $p->id, 50);

        return $p;
    }

    /** بيعةُ صندوقٍ مدفوعة اليوم — بندٌ من الورد */
    private function tillSale(): Order
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'الخوير',
            'number' => 'INV-'.(Order::count() + 1), 'customer_name' => 'زبون',
            'channel' => SalesChannel::POS, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 40, 'discount' => 0, 'tax' => 0, 'total' => 40,
            'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);
        $order->items()->create(['product_id' => $this->rose->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 5,
            'quantity' => 2, 'total' => 40, 'addons_total' => 0]);
        $this->rose->decrement('quantity', 2);
        BranchStock::adjust($this->shop->id, $this->branch->id, $this->rose->id, -2);
        Transaction::create(['business_id' => $this->shop->id, 'order_id' => $order->id, 'reference' => $order->number,
            'description' => 'مبيعات', 'kind' => Transaction::SALE, 'method' => 'نقدي', 'type' => 'دخل',
            'amount' => 40, 'tax_amount' => 0, 'occurred_at' => now()]);
        Books::recordSale($order->fresh('items'));

        return $order->fresh();
    }

    private function add(Order $order, Product $product, int $qty = 1)
    {
        return $this->actingAs($this->owner)->post(route('admin.orders.items.store', $order->number), [
            'product_id' => $product->id, 'qty' => $qty, 'reason' => 'أضاف صنفًا', 'settle' => 'due',
        ]);
    }

    /** ما لا يتحرّك حين يُردّ البند: الرفُّ والإجماليّ والمعاملةُ والقيدُ وأثرُ التصحيح */
    private function assertNothingMoved(Order $order): void
    {
        $order->refresh();
        $this->assertEquals(40, (float) $order->total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(50, (int) $this->online->fresh()->quantity, 'خرج صنفُ الموقع من الرفّ');
        $this->assertEquals(40, (float) Transaction::where('order_id', $order->id)->sum('amount'));
        $this->assertSame(0, OrderEdit::where('order_id', $order->id)->count());
        $this->assertSame(40.0, round(Ledger::balance($this->shop->id, 'cash'), 3));
    }

    public function test_a_new_line_on_a_till_invoice_is_asked_what_the_till_asks(): void
    {
        $order = $this->tillSale();

        $this->add($order, $this->online)->assertSessionHasErrors([
            'line' => '«باقة الموقع» غير متاح للبيع في نقطة البيع.',
        ]);
        $this->assertNothingMoved($order);

        // ولا قناةَ له أصلًا — يُردّ كذلك
        $none = $this->product('باقة بلا قناة', 25, ['pos_enabled' => false, 'published' => false]);
        $this->add($order, $none)->assertSessionHasErrors('line');
        $this->assertSame(1, $order->fresh()->items()->count());

        // والخدمةُ نفسُها تردّه — لا الشاشةُ ولا المسار
        $this->expectException(ValidationException::class);
        OrderCorrection::addLine($order->fresh(), ['product_id' => $this->online->id, 'qty' => 1], 'من الخدمة', OrderCorrection::SETTLE_DUE);
    }

    public function test_replacing_a_line_with_a_product_the_till_does_not_sell_keeps_the_old_line(): void
    {
        $order = $this->tillSale();
        $line = $order->items()->firstOrFail();

        $this->actingAs($this->owner)->post(route('admin.orders.items.replace', [$order->number, $line->id]), [
            'product_id' => $this->online->id, 'qty' => 1, 'reason' => 'استبدال', 'settle' => 'due',
        ])->assertSessionHasErrors('line');

        $kept = $order->fresh()->items()->sole();
        $this->assertSame($line->id, $kept->id, 'حُذف البندُ القديم وقد رُدّ الجديد');
        $this->assertSame(2, (int) $kept->quantity);
        $this->assertEquals(20, (float) $kept->price);
        $this->assertSame(48, (int) $this->rose->fresh()->quantity, 'عاد الوردُ إلى الرفّ وقد بقي بندُه');
        $this->assertNothingMoved($order);
    }

    public function test_a_till_product_is_still_added_and_leaves_the_shelf_once(): void
    {
        $order = $this->tillSale();
        $tulip = $this->product('توليب', 15, ['pos_enabled' => true, 'published' => false]);

        $this->add($order, $tulip, 2)->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEquals(70, (float) $order->total);
        $this->assertSame(48, (int) $tulip->fresh()->quantity);
        $this->assertSame(1, OrderEdit::where('order_id', $order->id)->where('kind', OrderEdit::ADD_LINE)->count());
    }

    public function test_an_old_line_whose_product_left_the_till_keeps_its_price_and_still_corrects(): void
    {
        $order = $this->tillSale();
        $line = $order->items()->firstOrFail();
        // بِيع والصندوقُ يبيعه، ثمّ صار «للموقع فقط»
        $this->rose->update(['pos_enabled' => false]);

        $this->actingAs($this->owner)->put(route('admin.orders.items.update', [$order->number, $line->id]), [
            'quantity' => 1, 'reason' => 'أعاد واحدة', 'settle' => 'refunded', 'payment_method' => 'نقدي',
        ])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(1, (int) $line->quantity);
        $this->assertEquals(20, (float) $line->price, 'تبدّل سعرُ البند القديم');
        $this->assertEquals(5, (float) $line->cost, 'تبدّلت تكلفةُ البند القديم');
        $this->assertSame(49, (int) $this->rose->fresh()->quantity, 'لم تعد الواحدة إلى الرفّ');
        $this->assertEquals(20, (float) $order->fresh()->total);

        // ويُحذف حين يبقى في الفاتورة غيرُه
        $tulip = $this->product('توليب', 15, ['pos_enabled' => true]);
        $this->add($order, $tulip)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('admin.orders.items.update', [$order->number, $line->id]), [
            'quantity' => 0, 'reason' => 'حذف البند', 'settle' => 'refunded', 'payment_method' => 'نقدي',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($order->fresh()->items()->where('product_id', $this->rose->id)->exists());
        $this->assertSame(50, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_website_order_still_takes_a_product_added_by_staff(): void
    {
        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'الخوير',
            'number' => 'WEB-1', 'customer_name' => 'Maryam', 'channel' => SalesChannel::WEBSITE,
            'payment_method' => 'الدفع عند الاستلام', 'payment_status' => 'غير مدفوع',
            'subtotal' => 30, 'discount' => 0, 'tax' => 0, 'total' => 30,
            'status' => 'جديد', 'is_held' => false, 'ordered_at' => now(),
        ]);
        $order->items()->create(['product_id' => $this->online->id, 'name' => 'باقة الموقع', 'price' => 30, 'cost' => 5,
            'quantity' => 1, 'total' => 30, 'addons_total' => 0]);
        $tillOnly = $this->product('صنف المحل', 10, ['pos_enabled' => true, 'published' => false]);

        $this->add($order, $tillOnly)->assertSessionHasNoErrors();

        $this->assertEquals(40, (float) $order->fresh()->total);
        $this->assertSame(49, (int) $tillOnly->fresh()->quantity);
    }

    public function test_the_dialog_offers_a_till_invoice_only_what_the_till_sells(): void
    {
        $order = $this->tillSale();
        $ids = fn (?string $channel) => array_column(NotesAndEdits::catalog($this->shop->id, $channel), 'id');

        $this->assertNotContains($this->online->id, $ids(SalesChannel::POS));
        $this->assertContains($this->rose->id, $ids(SalesChannel::POS));
        $this->assertContains($this->online->id, $ids(SalesChannel::WEBSITE));

        $tillOnly = fn (AssertableInertia $page) => $page
            ->where('lineEdit.lines', true)
            ->where('lineEdit.catalog', fn ($catalog) => ! collect($catalog)->pluck('id')->contains($this->online->id)
                && collect($catalog)->pluck('id')->contains($this->rose->id));

        // شاشةُ المبيعات وشاشةُ الصندوق — بابان للتصحيح نفسِه
        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))->assertInertia($tillOnly);
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->get(route('pos.order-details', $order->number))->assertInertia($tillOnly);
    }
}
