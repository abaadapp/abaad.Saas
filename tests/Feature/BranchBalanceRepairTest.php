<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكميّةُ التي لا فرعَ لها لا تُباع — وهي على الرفّ.
 *
 * قاعدةُ المخزون: إجمالي الصنف = مجموع أرصدة فروعه. ونقطة البيع تبيع من
 * رصيد الفرع وحده، فما زاد في الإجمالي ولم يُنسب إلى فرعٍ يختفي من
 * الشاشة ويبقى في المستودع.
 *
 * وقد وقع على الإنتاج: «إضافة كمية» يدويّة كانت تُقبل بلا فرع. البابُ
 * أُغلق، وهذا الأمر يعالج ما كُتب قبله.
 */
class BranchBalanceRepairTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Branch $first;
    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'b@test.local', 'status' => 'نشط']);
        $this->first = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->second = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
    }

    private function product(int $total, array $perBranch): Product
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 2, 'cost' => 1, 'quantity' => $total, 'active' => true,
        ]);

        foreach ($perBranch as $branchId => $qty) {
            BranchStock::create([
                'business_id' => $this->business->id, 'branch_id' => $branchId,
                'product_id' => $product->id, 'quantity' => $qty,
            ]);
        }

        return $product;
    }

    public function test_the_unallocated_remainder_is_given_to_the_first_branch(): void
    {
        // ١٠٠ في الدفتر، و٤٢ منسوبةٌ إلى فرعين — و٥٨ لا تُباع
        $product = $this->product(100, [$this->first->id => 21, $this->second->id => 21]);

        $this->artisan('inventory:repair-balance')->assertSuccessful();

        $this->assertSame(79, (int) BranchStock::where('branch_id', $this->first->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(21, (int) BranchStock::where('branch_id', $this->second->id)
            ->where('product_id', $product->id)->value('quantity'));
        // والإجماليّ لا يتحرّك: هو الصحيح، والفرعُ هو ما كان ناقصًا
        $this->assertSame(100, (int) $product->fresh()->quantity);
    }

    /** والأثر مكتوب: لا نقلةَ صامتة في مخزون تاجر */
    public function test_every_repair_leaves_a_movement_to_read(): void
    {
        $product = $this->product(100, [$this->first->id => 42]);

        $this->artisan('inventory:repair-balance')->assertSuccessful();

        $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('تصحيح توازن', $movement->type);
        $this->assertSame('+58', $movement->quantity);
        $this->assertSame($this->first->id, (int) $movement->branch_id);
    }

    public function test_a_balanced_product_is_left_alone(): void
    {
        $product = $this->product(50, [$this->first->id => 30, $this->second->id => 20]);

        $this->artisan('inventory:repair-balance')->assertSuccessful();

        $this->assertSame(30, (int) BranchStock::where('branch_id', $this->first->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * وصنفٌ بلا صفوف فروعٍ إطلاقًا ليس مختلًّا.
     *
     * رصيدُه كلّه في الفرع الأول بحكم `BranchStock::books` — ولا شيء ضائع،
     * فلا يُلمس.
     */
    public function test_a_product_with_no_branch_rows_at_all_is_not_touched(): void
    {
        $product = $this->product(40, []);

        $this->artisan('inventory:repair-balance')->assertSuccessful();

        $this->assertSame(0, BranchStock::where('product_id', $product->id)->count());
        $this->assertSame(0, InventoryMovement::count());
    }

    /**
     * والنقص لا يُطرح من فرعٍ لا يملكه.
     *
     * طرحُه كان سيجعل رصيد الفرع سالبًا — عطبٌ ثانٍ مكان الأول.
     */
    public function test_a_negative_gap_bigger_than_the_branch_holds_is_reported_not_forced(): void
    {
        $product = $this->product(10, [$this->first->id => 5, $this->second->id => 40]);

        $this->artisan('inventory:repair-balance')->assertSuccessful();

        $this->assertSame(5, (int) BranchStock::where('branch_id', $this->first->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $product = $this->product(100, [$this->first->id => 42]);

        $this->artisan('inventory:repair-balance --dry-run')->assertSuccessful();

        $this->assertSame(42, (int) BranchStock::where('branch_id', $this->first->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::count());
    }
}
