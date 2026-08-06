<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البيع يحترم رصيد الفرع، والدفاتر تبقى متوازنة.
 *
 * كانت نقطة البيع تقرأ products.quantity (مجموع الشركة) وتخصم من
 * branch_stocks (رصيد الفرع) — فتُجيز بيع خمس قطع من صلالة ورصيدها صفر
 * لأن في مسقط عشرًا، ويخرج الجدولان من التوازن في المعاملة نفسها. وكان
 * max(0, …) في adjust يُخفي الانحراف فلا يظهر في أي تقرير.
 */
class BranchStockTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);

        // البيع صار يتطلّب صندوقًا مفتوحًا — ولكل فرع درجه، فلكلٍّ ورديته
        $this->openShiftFor($this->business->id, $this->muscat->id);
        $this->openShiftFor($this->business->id, $this->salalah->id);
    }

    /** يوزّع 10 على مسقط و0 على صلالة */
    private function allocate(int $muscat = 10, int $salalah = 0): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => $muscat,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'product_id' => $this->product->id, 'quantity' => $salalah,
        ]);
    }

    private function inBranch(Branch $branch): void
    {
        $this->actingAs($this->owner);
        session(['current_branch' => $branch->id]);
    }

    private function sell(int $qty)
    {
        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty]],
            'payment_method' => 'نقدي',
        ]);
    }

    /* ------------------------- ما كان مكسورًا ------------------------- */

    public function test_a_branch_with_no_stock_cannot_sell_its_neighbours_goods(): void
    {
        $this->allocate(muscat: 10, salalah: 0);
        $this->inBranch($this->salalah);

        $this->sell(5)->assertStatus(422);

        $this->assertSame(10, (int) $this->product->fresh()->quantity, 'خُصم رغم رفض البيع');
    }

    public function test_the_pos_screen_shows_this_branchs_stock_not_the_company_total(): void
    {
        $this->allocate(muscat: 10, salalah: 0);
        $this->inBranch($this->salalah);

        $products = $this->get(route('pos.index'))->assertOk()
            ->viewData('page')['props']['products'];

        $row = collect($products)->firstWhere('id', $this->product->id);

        $this->assertSame(0, $row['qty'], 'عُرض رصيد مسقط لكاشير صلالة');
        $this->assertSame('نفد المخزون', $row['stock_status']);
    }

    public function test_the_live_feed_reports_the_same_branch_as_the_screen(): void
    {
        $this->allocate(muscat: 10, salalah: 0);
        $this->inBranch($this->salalah);

        $feed = $this->getJson(route('pos.stock-feed'))->assertOk()->json('products');
        $row = collect($feed)->firstWhere('id', $this->product->id);

        $this->assertSame(0, $row['qty'], 'التغذية تقيس غير ما عرضته الشاشة');
    }

    public function test_selling_from_a_stocked_branch_still_works(): void
    {
        $this->allocate(muscat: 10, salalah: 0);
        $this->inBranch($this->muscat);

        $this->sell(4)->assertOk();

        $this->assertSame(6, (int) $this->product->fresh()->quantity);
        $this->assertSame(6, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_the_books_stay_balanced_after_a_sale(): void
    {
        $this->allocate(muscat: 10, salalah: 0);
        $this->inBranch($this->muscat);

        $this->sell(4)->assertOk();

        $this->assertSame(
            (int) $this->product->fresh()->quantity,
            (int) BranchStock::where('product_id', $this->product->id)->sum('quantity'),
            'مجموع الفروع لا يساوي كمية المنتج',
        );
    }

    /* ------------------- المنتج الذي لم يُوزَّع بعد ------------------- */

    public function test_a_product_never_allocated_to_a_branch_is_still_sellable(): void
    {
        // منتج أُنشئ قبل وجود الفروع: لا صفّ له في branch_stocks
        $this->inBranch($this->muscat);

        $this->sell(3)->assertOk();

        $this->assertSame(7, (int) $this->product->fresh()->quantity);
    }

    public function test_its_first_sale_allocates_rather_than_going_negative(): void
    {
        $this->inBranch($this->muscat);

        $this->sell(3)->assertOk();

        $qty = (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity');

        $this->assertSame(7, $qty, 'بدأ صفّ الفرع من صفر فصار سالبًا');
    }

    public function test_a_second_sale_after_that_still_respects_the_branch(): void
    {
        $this->inBranch($this->muscat);

        $this->sell(7)->assertOk();
        $this->sell(5)->assertStatus(422);  // بقي 3 فقط

        $this->assertSame(3, (int) $this->product->fresh()->quantity);
    }

    /* ------------------------ لا قصّ للانحراف ------------------------ */

    public function test_adjust_no_longer_hides_drift_behind_a_zero_floor(): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => 2,
        ]);

        BranchStock::adjust($this->business->id, $this->muscat->id, $this->product->id, -5);

        $this->assertSame(
            -3,
            (int) BranchStock::where('branch_id', $this->muscat->id)
                ->where('product_id', $this->product->id)->value('quantity'),
            'قُصّ الرصيد عند صفر فاختفى الانحراف',
        );
    }

    /* --------------------- بقية مسارات الكتابة --------------------- */

    public function test_receiving_a_purchase_order_keeps_the_books_balanced(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'supplier_id' => $supplier->id, 'number' => 'PO-1',
            'status' => 'مطلوب', 'total' => 60, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'quantity' => 6, 'cost' => 10,
        ]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        $this->assertSame(16, (int) $this->product->fresh()->quantity);
        $this->assertSame(
            16,
            (int) BranchStock::where('product_id', $this->product->id)->sum('quantity'),
            'الاستلام كسر التوازن',
        );
        // البضاعة وصلت صلالة، فتُباع من صلالة لا من مسقط
        $this->assertSame(6, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_an_inventory_movement_keeps_the_books_balanced(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.store'), [
            'product_id' => $this->product->id,
            'branch_id' => $this->salalah->id,
            'type' => 'إضافة كمية',
            'quantity' => 4,
        ]);

        $this->assertSame(14, (int) $this->product->fresh()->quantity);
        $this->assertSame(
            14,
            (int) BranchStock::where('product_id', $this->product->id)->sum('quantity'),
        );
    }

    /* ---------------------- تحديد الفرع الموحَّد ---------------------- */

    public function test_all_branches_view_falls_back_to_the_first_branch_consistently(): void
    {
        // «كل الفروع» عرضٌ لا موضع بيع: الشاشة والخصم يجب أن يتفقا على فرع واحد
        $this->allocate(muscat: 10, salalah: 0);
        $this->actingAs($this->owner);
        session()->forget('current_branch');

        $this->assertSame($this->muscat->id, Demo::activeBranchId());

        $this->sell(4)->assertOk();

        $this->assertSame(6, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }
}
