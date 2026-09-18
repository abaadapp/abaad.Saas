<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Books;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تكلفةُ البيعة تقرأ ما تحتاجه — لا بطاقاتِ المتجر كلَّها.
 *
 * ═══ العطب ═══
 *
 * `Books::costOf` كانت تسحب تكلفةَ **كلّ** صنفٍ في المتجر عند كلّ بيعة:
 * `Product::where('business_id', …)->pluck('cost', 'id')`. وهي لا تحتاج إلا
 * ما نقصته لقطتُه. فمتجرٌ بعشرة آلاف صنفٍ يحمل عشرة آلاف صفٍّ إلى الذاكرة
 * ليقرأ منها صفًّا واحدًا — والكاشيرُ واقفٌ ينتظر، في الطريق الذي تمرّ منه
 * كلُّ فاتورة.
 *
 * ═══ وما صار ═══
 *
 * اللقطةُ تكفي في كلّ بيعةٍ عاديّة، فلا استعلامَ أصلًا. ولا تُطلب البطاقاتُ
 * إلا حين ينقص بندًا ثمنُ تكلفته — بيعةٌ وقعت قبل أن يُلتقط العمود — وتُطلب
 * لأصنافها هي.
 *
 * ولا يتغيّر رقمٌ واحد: هذه تحرس الاستعلام، و`Books` تحرس الرقم.
 */
class TheCostReadsOnlyTheCardsItNeedsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $sold;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);

        $this->sold = Product::create([
            'business_id' => $this->business->id, 'name' => 'المبيع',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'active' => true,
        ]);
        $this->other = Product::create([
            'business_id' => $this->business->id, 'name' => 'ما لم يُبع',
            'price' => 20, 'cost' => 9, 'quantity' => 50, 'active' => true,
        ]);
    }

    private function order(float $snapshot): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-'.fake()->unique()->numerify('####'),
            'customer_name' => 'عميل', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 20,
            'ordered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->sold->id,
            'name' => 'المبيع', 'price' => 10, 'quantity' => 2, 'cost' => $snapshot,
        ]);

        return $order->fresh('items');
    }

    /** @return array<int, array{query: string, bindings: array}> */
    private function watch(callable $run): array
    {
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = ['query' => $q->sql, 'bindings' => $q->bindings];
        });

        $run();
        DB::flushQueryLog();

        return array_values(array_filter($seen, fn ($q) => str_contains($q['query'], 'from "products"')));
    }

    /** لقطةٌ كاملة: لا تُقرأ بطاقةُ منتجٍ واحد */
    public function test_a_snapshot_asks_the_products_table_nothing(): void
    {
        $order = $this->order(4);

        $queries = $this->watch(fn () => $this->assertSame(8.0, Books::costOf($order)));

        $this->assertSame([], $queries, 'بطاقاتُ المنتجات تُقرأ ولا حاجة إليها');
    }

    /**
     * ولقطةٌ ناقصة تُقرأ بطاقتُها هي — لا بطاقاتُ المتجر.
     *
     * والحارسُ يسأل عن الأصناف الملزَمة: لو عادت الدالّةُ إلى سحب المتجر كلِّه
     * لَما ذُكر معرّفُ المنتج في القيود ولَمرّ صنفٌ لم يُبع.
     */
    public function test_a_missing_snapshot_asks_only_for_its_own_card(): void
    {
        $order = $this->order(0);

        // والرقمُ من البطاقة: ٤ للواحدة في اثنتين — لو لم تُقرأ لَخرج صفرًا
        $queries = $this->watch(fn () => $this->assertSame(8.0, Books::costOf($order)));

        $this->assertCount(1, $queries, 'استعلامٌ واحدٌ يكفي لبطاقةٍ واحدة');

        $bindings = array_map(fn ($b) => (int) $b, array_filter($queries[0]['bindings'], 'is_numeric'));

        $this->assertContains($this->sold->id, $bindings, 'البطاقةُ المطلوبة ليست في الاستعلام');
        $this->assertNotContains(
            $this->other->id,
            $bindings,
            'الاستعلامُ يسحب أصنافًا لم تُبع — بطاقاتُ المتجر كلُّها في مسار كلّ فاتورة'
        );
    }

    /** وبندٌ بلا صنفٍ أصلًا — طلبٌ مخصَّص — لا يُرسل معرّفًا فارغًا */
    public function test_an_item_with_no_product_asks_for_nothing(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-X1',
            'customer_name' => 'عميل', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 30, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 30,
            'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null,
            'name' => 'تنسيق خاصّ', 'price' => 30, 'quantity' => 1, 'cost' => 0,
        ]);

        $queries = $this->watch(fn () => $this->assertSame(0.0, Books::costOf($order->fresh('items'))));

        $this->assertSame([], $queries, 'استعلامٌ ببندٍ لا صنفَ له');
    }
}
