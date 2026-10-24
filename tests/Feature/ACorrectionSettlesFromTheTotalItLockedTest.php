<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تصحيحٌ يحسب فرقَه من الفاتورة التي أقفلها — لا من نسخةٍ قُرئت قبل القفل.
 *
 * `setQuantity` و`setAddonQuantity` يقفلان الفاتورة (`lockOrder`) ثمّ كانا
 * يقرآن «الإجماليّ قبل» من النسخة التي في اليد. وجهازان يصحّحان بندين من
 * فاتورةٍ واحدة في اللحظة نفسها: الثاني قرأها بخمسين، وانتظر القفلَ والأوّلُ
 * يُنزلها إلى أربعين ويردّ عشرة — ثمّ حسب فرقَه من الخمسين فردّ عشرين.
 * فرُدّت العشرةُ الأولى مرّتين، وكُتب في سجلّ التعديل «قبل: ٥٠».
 *
 * والسباقُ الحقيقيّ على PostgreSQL في `TwoCorrectionsOfOneSaleAtOnceRefundOnceTest`؛
 * وهنا الحالُ نفسُها بلا سباق: نسخةٌ قُرئت قبل تصحيحٍ آخر تُعطى للباب.
 */
class ACorrectionSettlesFromTheTotalItLockedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private Product $vase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورد الصباح', 'status' => 'نشط']);
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@locked-total.local',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('وردة');
        $this->vase = $this->product('مزهرية');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name): Product
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => 4,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);
        BranchStock::adjust($this->shop->id, $this->branch->id, $p->id, 10);

        return $p;
    }

    /** ثلاثُ وردٍ ومزهريّتان، مدفوعةً نقدًا: ٥٠ */
    private function sell(): Order
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10],
                ['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 2, 'price' => 10],
            ],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function line(Order $order, Product $p): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $p->id)->firstOrFail();
    }

    private function refunded(Order $order): float
    {
        return round((float) Transaction::where('order_id', $order->id)->where('kind', Transaction::ORDER_REFUND)->sum('amount'), 3);
    }

    public function test_a_second_correction_refunds_only_its_own_line_when_it_holds_an_older_copy(): void
    {
        $order = $this->sell();
        $this->assertSame(50.0, (float) $order->total);

        // الجهازُ الثاني فتح الفاتورة وهي بخمسين
        $older = Order::findOrFail($order->id);

        // والأوّلُ أعاد وردةً: ٥٠ ← ٤٠، وردّ عشرة
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity(Order::findOrFail($order->id), $this->line($order, $this->rose), 2, 'أعاد وردة', 'refunded', 'نقدي');
        $this->assertSame(40.0, (float) $order->fresh()->total);
        $this->assertSame(10.0, $this->refunded($order));

        // ثمّ الثاني بنسختِه: مزهريّةٌ عادت — ٤٠ ← ٣٠، ويُردّ عشرةٌ لا عشرون
        OrderCorrection::setQuantity($older, $this->line($order, $this->vase), 1, 'أعاد مزهرية', 'refunded', 'نقدي');

        $this->assertSame(30.0, (float) $order->fresh()->total);
        $this->assertSame(20.0, $this->refunded($order), 'رُدّت العشرةُ الأولى مرّتين');
        $this->assertSame(
            [50.0, 40.0],
            OrderEdit::where('order_id', $order->id)->where('kind', OrderEdit::LINE)->orderBy('id')->pluck('order_total_before')->map(fn ($v) => (float) $v)->all(),
            'سجلُّ التعديل يقول «قبل» غيرَ ما كانت عليه',
        );
    }

    public function test_an_addon_correction_also_settles_from_the_total_it_locked(): void
    {
        $chocolate = Addon::create(['business_id' => $this->shop->id, 'name' => 'شوكولاتة', 'price' => 2, 'active' => true]);
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->rose->id, 'name' => 'وردة', 'qty' => 3, 'price' => 10, 'addons' => [['addon_id' => $chocolate->id, 'qty' => 2]]],
                ['id' => $this->vase->id, 'name' => 'مزهرية', 'qty' => 2, 'price' => 10],
            ],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('c', true),
        ])->assertOk();
        $order = Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
        $this->assertSame(54.0, (float) $order->total);
        $older = Order::findOrFail($order->id);

        // الأوّلُ: مزهريّةٌ عادت — ٥٤ ← ٤٤ وردّ عشرة
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity(Order::findOrFail($order->id), $this->line($order, $this->vase), 1, 'أعاد مزهرية', 'refunded', 'نقدي');

        // والثاني بنسختِه: قطعةُ شوكولاتة — ٤٤ ← ٤٢ ويُردّ اثنان لا اثنا عشر
        $row = OrderItemAddon::where('order_item_id', $this->line($order, $this->rose)->id)->firstOrFail();
        OrderCorrection::setAddonQuantity($older, $row, 1, 'أعاد قطعة شوكولاتة', 'refunded', 'نقدي');

        $this->assertSame(42.0, (float) $order->fresh()->total);
        $this->assertSame(12.0, $this->refunded($order), 'رُدّت العشرةُ الأولى مرّتين');
    }

    public function test_one_correction_alone_still_refunds_its_line(): void
    {
        $order = $this->sell();

        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $this->line($order, $this->rose), 1, 'أعاد وردتين', 'refunded', 'نقدي');

        $this->assertSame(30.0, (float) $order->fresh()->total);
        $this->assertSame(20.0, $this->refunded($order));
    }
}
