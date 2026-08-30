<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حرّاسُ الثابت: مجموع الفروع = كمية المنتج — في الحالات التي كان يسقط فيها.
 *
 * `StockStaysBalancedTest` يمرّ على الأبواب كلّها، لكنّ فخّه أنّ منتجه موزَّعٌ
 * على الفروع في `setUp`. و`ensureAllocated` تنصرف مبكّرًا متى وجدت صفَّ فرعٍ
 * واحدًا — فترتيبُ ندائها لا يظهر أثره هناك أبدًا.
 *
 * والعطب كان يقع في المنتج الذي **لا صفَّ فرعٍ له**: يُنشَأ بكمية صفر (وكلّ
 * نسخةٍ من زرّ «نسخ المنتج» كذلك، فهو يصفّر الكمية عمدًا)، فلا يكتب له
 * `BranchStock::adjust` صفًّا لأن الفرق صفر. ثمّ تأتي أوّل حركةٍ عليه فتُنشئ
 * صفَّه بالكمية الجديدة ثمّ تضيف الفرق ثانيةً.
 *
 * فهذا الملفّ يبدأ من حيث ينتهي ذاك: منتجٌ بلا توزيع.
 */
class StockInvariantGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $main;
    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->other = Branch::create(['business_id' => $this->business->id, 'name' => 'الثاني']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** منتجٌ بلا أي صفّ في branch_stocks — الحالة التي كان العطب يختبئ فيها */
    private function undistributed(int $quantity = 0): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ لم يُوزَّع',
            'price' => 10, 'cost' => 4, 'quantity' => $quantity, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->assertSame(0, BranchStock::where('product_id', $p->id)->count(),
            'شرط الاختبار: المنتج يبدأ بلا توزيع');

        return $p;
    }

    private function assertBalanced(Product $p, string $door): void
    {
        $total = (int) $p->fresh()->quantity;
        $sum = (int) BranchStock::where('product_id', $p->id)->sum('quantity');

        $this->assertSame($total, $sum,
            "«{$door}» كسر التوازن: الإجماليّ {$total} ومجموع الفروع {$sum}");
    }

    /* ===================== T-1 — منتجٌ بلا توزيع ===================== */

    public function test_an_adjustment_on_an_undistributed_product_keeps_it_balanced(): void
    {
        $p = $this->undistributed();

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => $this->other->id,
                'product_id' => $p->id,
                'quantity_delta' => 5,
                'reason' => StockAdjustment::REASONS[0],
                'adjusted_at' => now()->toDateString(),
            ])->assertSessionHasNoErrors();

        $this->assertSame(5, (int) $p->fresh()->quantity, 'التعديل لم يبلغ المتحكّم');
        $this->assertBalanced($p, 'تسوية مخزون على منتجٍ بلا توزيع');
    }

    public function test_an_invoice_correction_on_an_undistributed_product_keeps_it_balanced(): void
    {
        $p = $this->undistributed(10);

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->other->id,
            'number' => 'INV-1', 'status' => 'مكتمل', 'payment_method' => 'نقدي',
            'subtotal' => 30, 'tax' => 0, 'discount' => 0, 'total' => 30,
            'is_held' => false, 'ordered_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => 10, 'cost' => 4, 'quantity' => 3, 'total' => 30,
        ]);

        // إنقاص الكمية المباعة يعيد قطعتين إلى الرفّ — وهي الحركة التي كانت
        // تُنشئ صفّ الفرع بالكمية الجديدة ثمّ تضيف الفرق فوقه
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($order, $item, 1, 'إرجاع قطعتين');

        $this->assertSame(12, (int) $p->fresh()->quantity, 'التصحيح لم يُعِد الكمية');
        $this->assertBalanced($p, 'تصحيح فاتورة على منتجٍ بلا توزيع');
    }

    public function test_a_stocktake_on_an_undistributed_product_keeps_it_balanced(): void
    {
        $p = $this->undistributed(10);

        // الجرد على الفرع الرئيسي: دفتر الفرع لمنتجٍ بلا توزيع هو كميّته كلّها
        $this->actingAs($this->owner)
            ->post(route('admin.inventory.stocktake.apply'), [
                'branch_id' => $this->main->id,
                'counts' => [$p->id => 7],
            ])->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $p->fresh()->quantity, 'الجرد لم يبلغ المتحكّم');
        $this->assertBalanced($p, 'جرد على منتجٍ بلا توزيع');
    }

    public function test_a_manual_movement_on_an_undistributed_product_keeps_it_balanced(): void
    {
        $p = $this->undistributed(4);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.store'), [
                'branch_id' => $this->main->id,
                'product_id' => $p->id, 'type' => 'إضافة كمية', 'quantity' => 6,
            ])->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $p->fresh()->quantity, 'الحركة لم تبلغ المتحكّم');
        $this->assertBalanced($p, 'حركة يدوية على منتجٍ بلا توزيع');
    }

    /* ============ T-3 — لا كتابةَ على المخزون بلا فرعٍ محدَّد ============ */

    public function test_an_adjustment_without_a_branch_is_refused_and_changes_nothing(): void
    {
        $p = $this->undistributed(20);
        BranchStock::adjust($this->business->id, $this->main->id, $p->id, 20);

        $this->actingAs($this->owner)
            // وضع «كل الفروع» — وهو الافتراضيّ في الجلسة
            ->withSession(['current_branch' => null])
            ->post(route('admin.inventory.adjustments.store'), [
                'product_id' => $p->id,
                'quantity_delta' => -5,
                'reason' => StockAdjustment::REASONS[0],
                'adjusted_at' => now()->toDateString(),
            ])->assertSessionHasErrors('branch_id');

        $this->assertSame(20, (int) $p->fresh()->quantity, 'رُفض الطلب ومع ذلك تغيّر الإجماليّ');
        $this->assertSame(0, StockAdjustment::count(), 'رُفض الطلب ومع ذلك سُجّل تعديل');
        $this->assertSame(0, InventoryMovement::count(), 'رُفض الطلب ومع ذلك سُجّلت حركة');
        $this->assertBalanced($p, 'تعديلٌ مرفوض');
    }

    public function test_an_adjustment_on_another_businesss_branch_is_refused(): void
    {
        $stranger = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $stranger->id, 'name' => 'فرعُهم']);

        $p = $this->undistributed(20);
        BranchStock::adjust($this->business->id, $this->main->id, $p->id, 20);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => $theirs->id,
                'product_id' => $p->id,
                'quantity_delta' => -5,
                'reason' => StockAdjustment::REASONS[0],
                'adjusted_at' => now()->toDateString(),
            ])->assertSessionHasErrors('branch_id');

        $this->assertSame(20, (int) $p->fresh()->quantity);
        $this->assertSame(0, BranchStock::where('branch_id', $theirs->id)->count(),
            'كُتب رصيدٌ في فرع متجرٍ آخر');
    }

    /**
     * الكسر يُردّ ولا يُقصّ.
     *
     * العمودان صحيحان، فنصفُ قطعةٍ كانت تُقبَل ثمّ تُقصّ: الإجماليّ يتحرّك
     * بشيءٍ ورصيدُ الفرع بشيءٍ آخر. والقصُّ الصامت أسوأ من الردّ — من كتب
     * «٢٫٥» يظنّ أنّ المخزون تحرّك، ولا شيء يقول له إنّه لم يتحرّك.
     */
    public function test_a_fractional_adjustment_is_refused_and_changes_nothing(): void
    {
        $p = $this->undistributed(10);
        BranchStock::adjust($this->business->id, $this->main->id, $p->id, 10);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => $this->main->id,
                'product_id' => $p->id,
                'quantity_delta' => 2.5,
                'reason' => StockAdjustment::REASONS[0],
                'adjusted_at' => now()->toDateString(),
            ])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(10, (int) $p->fresh()->quantity, 'رُفض الطلب ومع ذلك تغيّر الإجماليّ');
        $this->assertSame(10, (int) BranchStock::where('product_id', $p->id)->sum('quantity'),
            'رُفض الطلب ومع ذلك تغيّر رصيد الفرع');
        $this->assertSame(0, StockAdjustment::count(), 'رُفض الطلب ومع ذلك سُجّل تعديل');
        $this->assertSame(0, InventoryMovement::count(), 'رُفض الطلب ومع ذلك سُجّلت حركة');
        $this->assertBalanced($p, 'تعديلٌ بكسرٍ مرفوض');
    }

    /** والصحيح يمرّ كما هو — لئلّا يُقفل الباب على من يستعمله */
    public function test_a_whole_number_adjustment_still_passes(): void
    {
        $p = $this->undistributed(10);
        BranchStock::adjust($this->business->id, $this->main->id, $p->id, 10);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => $this->main->id,
                'product_id' => $p->id,
                'quantity_delta' => 3,
                'reason' => StockAdjustment::REASONS[0],
                'adjusted_at' => now()->toDateString(),
            ])->assertSessionHasNoErrors();

        $this->assertSame(13, (int) $p->fresh()->quantity);
        // الرقم الصحيح نفسه في كلّ موضعٍ يقرؤه أحد
        $this->assertSame(3.0, (float) StockAdjustment::firstOrFail()->quantity_delta);
        $this->assertSame('+3', InventoryMovement::firstOrFail()->quantity);
        $this->assertBalanced($p, 'تعديلٌ بعددٍ صحيح');
    }

    /* ============ T-11 — الجرد معاملةٌ واحدة، وقراءةٌ واحدة ============ */

    /** @return Product[] */
    private function catalogue(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $p = Product::create([
                'business_id' => $this->business->id, 'name' => "صنف {$i}", 'sku' => "S-{$i}",
                'price' => 10, 'cost' => 4, 'quantity' => 10, 'alert_qty' => 5, 'active' => true,
            ]);
            BranchStock::adjust($this->business->id, $this->main->id, $p->id, 10);
            $out[] = $p;
        }

        return $out;
    }

    public function test_a_stocktake_reads_the_branch_books_once_not_once_per_product(): void
    {
        $products = $this->catalogue(12);
        $counts = [];
        foreach ($products as $p) {
            $counts[$p->id] = 8;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.stocktake.apply'), [
                'branch_id' => $this->main->id,
                'counts' => $counts,
            ])->assertSessionHasNoErrors();

        /*
         * بصمةُ `BranchStock::books`: تُحمّل معرّفات المنتجات وكمياتها كلَّها.
         * كانت تُنادى عبر `bookOf` مرّةً لكلّ صنف — اثنتي عشرة قراءةً كاملة
         * لجدولَي المنتجات والفروع في جردٍ من اثني عشر صنفًا، ومئاتٍ في متجرٍ
         * حقيقيّ. القاعدة: قراءةٌ واحدة مهما كان عدد الأصناف.
         */
        $bookLoads = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], '"id", "quantity" from "products"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $bookLoads,
            "دفتر الفروع قُرئ {$bookLoads} مرّة في جردٍ واحد — يجب أن يُقرأ مرّة");
    }

    public function test_a_failed_stocktake_applies_nothing_at_all(): void
    {
        $products = $this->catalogue(4);
        $counts = [];
        foreach ($products as $p) {
            $counts[$p->id] = 3;   // نقصٌ في كلٍّ منها، فيُقيَّد فاقدٌ أيضًا
        }

        /*
         * انقطاعٌ في منتصف الجرد.
         *
         * كانت الحلقة بلا معاملة، فيبقى ما طُبّق قبل الانقطاع مطبَّقًا: بعض
         * الأصناف مسوّاةٌ وبعضها لا، ولا شيء يقول أين وقف العدّ.
         */
        $seen = 0;
        InventoryMovement::creating(function () use (&$seen) {
            if (++$seen === 3) {
                throw new \RuntimeException('انقطاعٌ مُفتعَل في منتصف الجرد');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->owner)
                ->post(route('admin.inventory.stocktake.apply'), [
                    'branch_id' => $this->main->id,
                    'counts' => $counts,
                ]);
            $this->fail('كان يُنتظر أن ينقطع الجرد');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('انقطاعٌ مُفتعَل', $e->getMessage());
        }

        foreach ($products as $p) {
            $this->assertSame(10, (int) $p->fresh()->quantity,
                'صنفٌ بقي مسوّى بعد جردٍ انقطع: التسوية ليست معاملةً واحدة');
            $this->assertBalanced($p, 'جردٌ انقطع');
        }

        $this->assertSame(0, InventoryMovement::count(), 'بقيت حركاتٌ من جردٍ لم يكتمل');
        $this->assertSame(0, \App\Models\Expense::count(), 'قُيّد فاقدٌ من جردٍ لم يكتمل');
    }
}
