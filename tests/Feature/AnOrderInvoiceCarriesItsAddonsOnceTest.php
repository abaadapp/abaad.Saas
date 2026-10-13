<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * فاتورةُ الطلب تكتب إضافاتِه — مرّةً، ومن لقطة البيع.
 *
 * ═══ ما كان ═══
 *
 * `fromOrder` و`consolidate` تكتبان الصنفَ بثمنه (`price × qty`) وتُسقطان
 * إضافاته، والذيلُ من الطلب يجمعها (`addons_total`). فورقةُ بوكيهٍ بعشرين
 * وشوكولاتةٍ بثلاثة تقول سطورُها عشرين ويقول ذيلُها ثلاثةً وعشرين.
 *
 * ═══ والخطرُ في الإصلاح ═══
 *
 * أن تُضاف الإضافةُ سطرًا **وتبقى** داخل ثمن الصنف، فتُعدّ مرّتين. فيُحرس:
 *
 *   - سطرٌ لكلّ إضافةٍ جُبيت فوق الثمن، وسعرُ الصنف بلا إضافاته.
 *   - مجموعُ السطور = مجموعُ الطلب الفرعيّ، والإجماليُّ = إجماليُّ الطلب، حرفًا.
 *   - الطلبُ المخصَّص بميزانيةٍ نهائيّة: إضافاتُه داخل ثمنه — تُسمّى ولا تُجبى.
 *   - صفٌّ قديمٌ لا تطابق لقطاتُه ما جُبي: سطرٌ واحدٌ بما جُبي فعلًا.
 *   - اللقطةُ لا الكتالوج: إضافةٌ غُيّرت بعد البيع لا تُعيد كتابته.
 *   - خصمُ الطلب يُوزَّع على الإضافات معه، وورقةُ الشهر لا تضاعف شيئًا.
 */
class AnOrderInvoiceCarriesItsAddonsOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private Customer $company;

    private Product $bouquet;

    private Addon $chocolate;

    private Addon $card;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الطلبات', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        Ledger::seedChart($this->shop->id);

        $this->actingAs(User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@orders.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]));

        $this->company = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'شركة النخيل', 'phone' => '92345678', 'allow_credit_sales' => true,
        ]);

        $this->bouquet = Product::create(['business_id' => $this->shop->id, 'name' => 'بوكيه ورد', 'price' => 20, 'cost' => 8]);
        $this->chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 3, 'active' => true]);
        $this->card = Addon::create(['business_id' => $this->shop->id, 'name' => 'بطاقة', 'price' => 1, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * بيعةٌ آجلةٌ كما يكتبها الصندوق — `subtotal = Σ(price × qty + addons_total)`.
     *
     * @param  array<int, array{product?: ?Product, name?: string, price: float, qty?: int, addons?: array<int, array{0: Addon, 1: int}>, addons_total?: float, standalone?: ?Addon}>  $items
     */
    private function sale(array $items, float $discount = 0.0): Order
    {
        $subtotal = 0.0;
        foreach ($items as $i) {
            $charged = $i['addons_total'] ?? collect($i['addons'] ?? [])->sum(fn ($a) => (float) $a[0]->price * $a[1]);
            $subtotal += $i['price'] * ($i['qty'] ?? 1) + $charged;
        }
        $tax = round(($subtotal - $discount) * 0.05, 3);

        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'S-'.(++$this->n), 'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'آجل', 'payment_status' => 'غير مدفوع',
            'customer_id' => $this->company->id, 'customer_name' => $this->company->name,
            'subtotal' => round($subtotal, 3), 'discount' => $discount, 'tax' => $tax,
            'total' => round($subtotal - $discount + $tax, 3), 'ordered_at' => now(),
        ]);

        foreach ($items as $i) {
            $qty = $i['qty'] ?? 1;
            $addons = $i['addons'] ?? [];
            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => ($i['product'] ?? null)?->id,
                'standalone_addon_id' => ($i['standalone'] ?? null)?->id,
                'name' => $i['name'] ?? ($i['product'] ?? null)?->name,
                'price' => $i['price'], 'quantity' => $qty, 'cost' => 0,
                'total' => round($i['price'] * $qty, 3),
                'addons_total' => $i['addons_total'] ?? round(collect($addons)->sum(fn ($a) => (float) $a[0]->price * $a[1]), 3),
            ]);

            foreach ($addons as [$addon, $n]) {
                OrderItemAddon::create([
                    'order_item_id' => $item->id, 'addon_id' => $addon->id, 'name' => $addon->name,
                    'unit_price' => $addon->price, 'quantity' => $n, 'total' => round((float) $addon->price * $n, 3),
                ]);
            }
        }

        return $order->fresh('items');
    }

    /** @return array<int, array{0: string, 1: ?int, 2: ?int, 3: float, 4: float}> */
    private function lines(CustomerInvoice $invoice): array
    {
        return $invoice->items->sortBy('sort_order')->map(fn (CustomerInvoiceItem $i) => [
            $i->description,
            $i->product_id === null ? null : (int) $i->product_id,
            $i->addon_id === null ? null : (int) $i->addon_id,
            round((float) $i->quantity, 3),
            round((float) $i->unit_price, 3),
        ])->values()->all();
    }

    /** ما تجمعه السطورُ قبل الخصم والضريبة — يقابل `subtotal` الطلب */
    private function gross(CustomerInvoice $invoice): float
    {
        return round($invoice->items->sum(fn ($i) => round((float) $i->quantity * (float) $i->unit_price, 3)), 3);
    }

    /* ═══════════ ١ · فاتورةُ الطلب الواحد ═══════════ */

    public function test_an_order_invoice_lists_each_addon_once_and_totals_the_order_exactly(): void
    {
        $order = $this->sale([
            ['product' => $this->bouquet, 'price' => 20, 'addons' => [[$this->chocolate, 1], [$this->card, 2]]],
        ]);
        $this->assertSame(25.0, (float) $order->subtotal);

        $invoice = CustomerInvoices::fromOrder($order);

        $this->assertSame([
            // الصنفُ بثمنه وحده — لا عشرون وخمسةٌ تُضاف ثانيةً تحته
            ['بوكيه ورد', $this->bouquet->id, null, 1.0, 20.0],
            ['شوكولاتة', null, $this->chocolate->id, 1.0, 3.0],
            ['بطاقة', null, $this->card->id, 2.0, 1.0],
        ], $this->lines($invoice));

        $this->assertSame(25.0, $this->gross($invoice), 'السطورُ لا تجمع إلى مجموع الطلب');
        $this->assertSame((float) $order->total, (float) $invoice->total, 'الورقةُ غيرُ الطلب');
        $this->assertSame(26.25, (float) $invoice->total);

        // والإضافةُ تعرف بندَها في الطلب
        $item = $order->items->sole();
        $this->assertSame([$item->id, $item->id, $item->id], $invoice->items->pluck('order_item_id')->map(fn ($id) => (int) $id)->all());
    }

    /** والإضافةُ المبيعةُ وحدَها من شريط الصندوق تحمل هويّتَها في الورقة */
    public function test_a_standalone_addon_sold_at_the_till_keeps_its_identity_on_the_paper(): void
    {
        $order = $this->sale([['name' => 'شوكولاتة', 'price' => 3, 'standalone' => $this->chocolate]]);

        $invoice = CustomerInvoices::fromOrder($order);

        $this->assertSame([['شوكولاتة', null, $this->chocolate->id, 1.0, 3.0]], $this->lines($invoice));
        $this->assertSame((float) $order->total, (float) $invoice->total);
    }

    /* ═══════════ ٢ · داخل الثمن لا فوقه ═══════════ */

    public function test_a_budget_arrangement_names_its_addons_without_charging_them_twice(): void
    {
        // «ثلاثون للطلب كلّه»: الشوكولاتةُ داخلها — `addons_total` صفر ولقطتُها بثمنها
        $order = $this->sale([
            ['name' => 'تنسيق مخصّص', 'price' => 30, 'addons' => [[$this->chocolate, 1]], 'addons_total' => 0.0],
        ]);

        $invoice = CustomerInvoices::fromOrder($order);
        $lines = $this->lines($invoice);

        $this->assertCount(1, $lines, 'إضافةٌ داخل الميزانية كُتبت سطرًا بثمنٍ فوقها');
        $this->assertSame(30.0, $lines[0][4]);
        $this->assertStringContainsString('تشمل: شوكولاتة', $lines[0][0]);
        $this->assertSame(30.0, $this->gross($invoice));
        $this->assertSame((float) $order->total, (float) $invoice->total);
    }

    /** وصفٌّ لا تطابق لقطاتُه ما جُبي: سطرٌ واحدٌ بما جُبي — لا توزيعَ بالظنّ */
    public function test_a_charge_its_snapshots_do_not_explain_is_written_once_as_charged(): void
    {
        $order = $this->sale([
            ['product' => $this->bouquet, 'price' => 20, 'addons' => [[$this->chocolate, 1]], 'addons_total' => 4.0],
        ]);

        $invoice = CustomerInvoices::fromOrder($order);
        $lines = $this->lines($invoice);

        $this->assertCount(2, $lines);
        $this->assertSame([null, null, 1.0, 4.0], array_slice($lines[1], 1));
        $this->assertStringContainsString('شوكولاتة', $lines[1][0]);
        $this->assertSame(24.0, $this->gross($invoice));
    }

    /* ═══════════ ٣ · اللقطةُ لا الكتالوج ═══════════ */

    public function test_the_invoice_reads_the_sale_snapshot_not_todays_catalog(): void
    {
        $order = $this->sale([['product' => $this->bouquet, 'price' => 20, 'addons' => [[$this->chocolate, 1]]]]);

        $this->chocolate->update(['name' => 'شوكولاتة فاخرة', 'price' => 9, 'active' => false]);

        $invoice = CustomerInvoices::fromOrder($order);

        $this->assertSame(['شوكولاتة', null, $this->chocolate->id, 1.0, 3.0], $this->lines($invoice)[1]);
        $this->assertSame((float) $order->total, (float) $invoice->total);
    }

    /* ═══════════ ٤ · الخصم ═══════════ */

    public function test_the_order_discount_is_shared_over_the_addons_too(): void
    {
        $order = $this->sale([
            ['product' => $this->bouquet, 'price' => 20, 'addons' => [[$this->chocolate, 1], [$this->card, 2]]],
        ], discount: 5.0);

        $invoice = CustomerInvoices::fromOrder($order);
        $discounts = $invoice->items->sortBy('sort_order')->map(fn ($i) => round((float) $i->discount, 3))->values()->all();

        // خمسةٌ على خمسةٍ وعشرين: أربعةُ أخماسها على البوكيه والباقي على الإضافتين
        $this->assertSame([4.0, 0.6, 0.4], $discounts);
        $this->assertSame((float) $order->total, (float) $invoice->total);
    }

    /* ═══════════ ٥ · ورقةُ الشهر ═══════════ */

    public function test_a_month_paper_carries_each_orders_addons_once(): void
    {
        $a = $this->sale([['product' => $this->bouquet, 'price' => 20, 'addons' => [[$this->chocolate, 1], [$this->card, 2]]]]);
        $b = $this->sale([['product' => $this->bouquet, 'price' => 20]]);

        $invoice = CustomerInvoices::consolidate($this->company, [$a->id, $b->id]);

        $this->assertSame([
            ['بوكيه ورد — S-1', $this->bouquet->id, null, 1.0, 20.0],
            ['شوكولاتة — S-1', null, $this->chocolate->id, 1.0, 3.0],
            ['بطاقة — S-1', null, $this->card->id, 2.0, 1.0],
            ['بوكيه ورد — S-2', $this->bouquet->id, null, 1.0, 20.0],
        ], $this->lines($invoice));

        $this->assertSame(45.0, $this->gross($invoice));
        $this->assertSame(round((float) $a->total + (float) $b->total, 3), (float) $invoice->total);
        $this->assertSame(47.25, (float) $invoice->total);
    }
}
