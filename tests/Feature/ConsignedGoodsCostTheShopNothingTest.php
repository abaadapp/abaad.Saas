<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بضاعةُ البوتيك أمانة — لا تُكلّف المحلَّ شيئًا في تقرير الربح.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * بندُ البوتيك يُكتب بتكلفةٍ صفر (`Boutiques::CONSIGNED_COST`)، والدفترُ
 * يعرف ذلك (`Books::costOf` لا يُسقطه إلى البطاقة). لكنّ `Demo::cogsFor`
 * و`productProfitability` و`categoryProfitability` كانت تقرأ كلَّ صفرٍ
 * «لقطةً ناقصة» فتُسقطه إلى تكلفة بطاقة الصنف اليوم. فتُقرأ تكلفةُ البضاعة
 * أعلى والربحُ أقلّ بكلّ قطعة بوتيكٍ تُباع — والدفترُ يخالف تقريره.
 *
 * ═══ وما يبقى كما كان ═══
 *
 * صنفُ المحلّ بصفرٍ قديم (بيعةٌ سبقت اللقطة) يسقط إلى بطاقته كما كان.
 * والإيرادُ لا يتغيّر.
 */
class ConsignedGoodsCostTheShopNothingTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Boutique $boutique;

    private Product $ours;

    private Product $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محلّ', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@cogs.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->boutique = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك', 'commission_rate' => 20, 'active' => true]);

        $this->ours = $this->product($this->shop, 'باقة', cost: 7);
        $this->theirs = $this->product($this->shop, 'عطر', cost: 7, boutique: $this->boutique->id);
    }

    private function product(Business $b, string $name, float $cost, ?int $boutique = null): Product
    {
        return Product::create([
            'business_id' => $b->id, 'name' => $name, 'price' => 20, 'cost' => $cost,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'boutique_id' => $boutique,
        ]);
    }

    /**
     * بيعةٌ ببنودها كما كُتبت — [صنف، كميّة، تكلفةُ اللقطة، بوتيك].
     *
     * @param  list<array{0: Product, 1: int, 2: float, 3: ?int}>  $lines
     */
    private function sale(array $lines, ?Business $in = null): Order
    {
        $business = $in ?? $this->shop;
        $total = array_sum(array_map(fn ($l) => 20 * $l[1], $lines));

        $order = Order::create([
            'business_id' => $business->id, 'branch_id' => $in ? null : $this->branch->id,
            'customer_name' => 'زبون', 'employee_name' => 'المالك', 'number' => 'INV-'.(Order::count() + 1),
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'ordered_at' => now(),
        ]);

        foreach ($lines as [$p, $qty, $cost, $boutique]) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
                'price' => 20, 'quantity' => $qty, 'total' => 20 * $qty, 'cost' => $cost,
                'boutique_id' => $boutique,
                'boutique_name' => $boutique ? 'بوتيك' : null,
                'boutique_rate' => $boutique ? 20 : null,
            ]);
        }

        // يُرحَّل كما يُرحّله الصندوق — الإيرادُ يُقرأ من الدفتر
        Books::recordSale($order);

        return $order;
    }

    private function cogs(): float
    {
        $this->actingAs($this->owner);

        return round(Demo::cogsFor($this->shop->id, now()->startOfMonth()), 3);
    }

    /** ١ — صنفُ المحلّ بصفرٍ قديم يسقط إلى بطاقته كما كان: ٧ × ٣ */
    public function test_a_shop_line_without_a_snapshot_still_falls_back_to_its_card(): void
    {
        $this->sale([[$this->ours, 3, 0.0, null]]);

        $this->assertSame(21.0, $this->cogs());
    }

    /** ٢ — وبندُ البوتيك صفرُه حقيقة: لا يُقرأ من بطاقته */
    public function test_a_boutique_line_costs_the_shop_nothing(): void
    {
        $this->sale([[$this->theirs, 3, 0.0, $this->boutique->id]]);

        $this->assertSame(0.0, $this->cogs());
    }

    /** ٣ — ولو رُفعت تكلفةُ بطاقته بعد البيع */
    public function test_a_later_card_cost_does_not_reach_a_boutique_line(): void
    {
        $this->sale([[$this->theirs, 2, 0.0, $this->boutique->id]]);
        $this->theirs->update(['cost' => 40]);

        $this->assertSame(0.0, $this->cogs());
    }

    /** ٤ — فاتورةٌ مختلطة: تكلفةُ صنف المحلّ وحدها */
    public function test_a_mixed_invoice_costs_only_the_shops_line(): void
    {
        $this->sale([[$this->ours, 1, 5.0, null], [$this->theirs, 1, 0.0, $this->boutique->id]]);

        $this->assertSame(5.0, $this->cogs());
    }

    /**
     * ٥ — والدفترُ على حاله — ويتّفق مع التقرير الآن.
     *
     * `Books::costOf` لم يُمسّ: صنفُ المحلّ بصفرٍ قديم يسقط إلى بطاقته،
     * وبندُ البوتيك لا. والتقريرُ يقرأ الرقمَ نفسَه.
     */
    public function test_the_books_are_unchanged_and_now_agree_with_the_report(): void
    {
        $order = $this->sale([
            [$this->ours, 2, 0.0, null],                  // يسقط: ٧ × ٢ = ١٤
            [$this->ours, 1, 5.0, null],                  // لقطة: ٥
            [$this->theirs, 4, 0.0, $this->boutique->id], // أمانة: ٠
        ]);

        $this->assertSame(19.0, round(Books::costOf($order), 3));
        $this->assertSame(round(Books::costOf($order), 3), $this->cogs());
    }

    /** ٦ — والإيرادُ لا يتغيّر: الإصلاحُ في التكلفة وحدها */
    public function test_revenue_is_untouched(): void
    {
        $this->sale([[$this->ours, 1, 5.0, null], [$this->theirs, 1, 0.0, $this->boutique->id]]);
        $this->actingAs($this->owner);

        $summary = Demo::reportSummary('month');

        $this->assertSame(40.0, (float) $summary['sales']);
        $this->assertSame(5.0, (float) $summary['cogs']);
        $this->assertSame(35.0, (float) $summary['profit']);
    }

    /** ٧ — ولا يعبر متجرًا إلى آخر */
    public function test_another_shop_is_neither_read_nor_changed(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirsNext = $this->product($other, 'صنف الجار', cost: 9);
        $this->sale([[$theirsNext, 2, 0.0, null]], $other);
        $this->sale([[$this->theirs, 1, 0.0, $this->boutique->id]]);

        $this->assertSame(0.0, $this->cogs());
        $this->assertSame(18.0, round(Demo::cogsFor($other->id, now()->startOfMonth()), 3));
    }

    /** وربحيّةُ الصنف بالقاعدة نفسها */
    public function test_product_profitability_follows_the_same_rule(): void
    {
        $this->sale([[$this->ours, 1, 0.0, null], [$this->theirs, 1, 0.0, $this->boutique->id]]);
        $this->actingAs($this->owner);

        $rows = collect(Demo::productProfitability('month'))->keyBy('name');

        $this->assertSame(7.0, $rows['باقة']['cost']);
        $this->assertSame(0.0, $rows['عطر']['cost']);
        $this->assertSame(20.0, $rows['عطر']['revenue']);
    }

    /** وربحيّةُ القسم كذلك */
    public function test_category_profitability_follows_the_same_rule(): void
    {
        $this->sale([[$this->ours, 1, 0.0, null], [$this->theirs, 1, 0.0, $this->boutique->id]]);
        $this->actingAs($this->owner);

        $rows = Demo::categoryProfitability('month');

        // صنفان بلا قسم في قسمٍ واحد: إيرادٌ ٤٠، وتكلفةُ صنف المحلّ وحدها (٧)
        $this->assertCount(1, $rows);
        $this->assertSame(40.0, $rows[0]['revenue']);
        $this->assertSame(33.0, $rows[0]['profit']);
    }
}
