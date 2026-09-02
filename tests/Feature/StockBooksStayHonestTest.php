<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التوازن الذي يقوم عليه المخزون كلّه: مجموع الفروع = كمية المنتج.
 *
 * وهو توازنٌ لا تعرضه شاشة ولا يحرسه قيدٌ في القاعدة — يُحفظ بأن تمرّ كلّ
 * حركةٍ من بابٍ واحد. فمتى كُتب رصيدٌ بقراءةٍ ثمّ حساب، أو خارج معاملة،
 * انكسر بلا أثرٍ يُقرأ، ولم يظهر إلّا في جردٍ لا يُعرف من أين جاء فرقُه.
 */
class StockBooksStayHonestTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $main;

    private Branch $second;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->second = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 20, 'alert_qty' => 5, 'active' => true,
            'sku' => 'R-1', 'barcode' => 'B-1',
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->main->id]);
    }

    /** التوازن: مجموع أرصدة الفروع يساوي كمية المنتج */
    private function assertBalanced(?Product $product = null): void
    {
        $product = ($product ?? $this->product)->fresh();
        $sum = (int) BranchStock::where('product_id', $product->id)->sum('quantity');

        $this->assertSame(
            (int) $product->quantity,
            $sum,
            "انكسر التوازن: الإجمالي {$product->quantity} ومجموع الفروع {$sum}",
        );
    }

    /* ------------------- الفرق يُطبَّق بجملةٍ واحدة ------------------- */

    /** يلتقط جمل SQL التي تُنفَّذ داخل الإغلاق */
    private function statements(callable $fn): array
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen) { $seen[] = $query->sql; });
        $fn();
        DB::flushQueryLog();

        return $seen;
    }

    /**
     * الفرق يُكتب نسبةً إلى ما في القاعدة — لا مجموعًا حُسب في الذاكرة.
     *
     * وهذه هي الخاصّية التي تنجو من التزامن، وهي وحدها ما يمكن إثباته في
     * عمليةٍ واحدة: طلبان يجريان معًا على عاملَي PHP لا يُصنعان في اختبار،
     * لكنّ `quantity + n` تنجو منهما و`quantity = 19` لا تنجو. فكان الصفّ
     * يُقرأ ثمّ يُحسب ثمّ يُحفظ: بيعتان تقرآن «عشرين» كلتاهما وتكتبان
     * «تسعة عشر» كلتاهما — قطعةٌ خرجت من الرفّ ولم تخرج من الدفتر.
     */
    public function test_the_branch_balance_is_written_as_a_difference_not_a_total(): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->main->id,
            'product_id' => $this->product->id, 'quantity' => 20,
        ]);

        $sql = $this->statements(fn () => BranchStock::adjust(
            $this->business->id, $this->main->id, $this->product->id, -1,
        ));

        $updates = array_values(array_filter($sql, fn ($q) => str_starts_with($q, 'update "branch_stocks"')));
        $this->assertNotEmpty($updates, 'لم تُكتب جملة تحديث');
        $this->assertStringContainsString('quantity + -1', $updates[0]);

        $this->assertSame(19, (int) BranchStock::firstWhere('product_id', $this->product->id)->quantity);
    }

    /** والإجمالي كذلك — لا إسنادَ مجموعٍ حُسب قبل القفل */
    public function test_the_company_total_is_written_as_a_difference_too(): void
    {
        $sql = $this->statements(fn () => $this->move(['quantity' => 5]));

        $increments = array_values(array_filter(
            $sql,
            fn ($q) => str_starts_with($q, 'update "products"') && str_contains($q, 'quantity" + '),
        ));

        $this->assertNotEmpty($increments, 'كُتب المجموع إسنادًا لا زيادة');
    }

    public function test_the_first_movement_creates_the_row(): void
    {
        BranchStock::adjust($this->business->id, $this->second->id, $this->product->id, 7);

        $this->assertSame(7, (int) BranchStock::where('branch_id', $this->second->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_a_zero_difference_writes_nothing(): void
    {
        BranchStock::adjust($this->business->id, $this->main->id, $this->product->id, 0);

        $this->assertSame(0, BranchStock::where('product_id', $this->product->id)->count());
    }

    /** رصيدٌ سالب إشارةُ خللٍ يجب أن تُرى، لا أن تُخبَّأ */
    public function test_a_negative_balance_is_shown_not_clipped(): void
    {
        BranchStock::adjust($this->business->id, $this->main->id, $this->product->id, 3);
        BranchStock::adjust($this->business->id, $this->main->id, $this->product->id, -5);

        $this->assertSame(-2, (int) BranchStock::where('product_id', $this->product->id)->value('quantity'));
    }

    /* ------------------- حركةٌ خارج معاملة لا تُكتب ------------------- */

    /**
     * `assertInTransaction` كانت معرَّفةً ولا يستدعيها أحد — تعليقٌ يقول
     * «لا يُستدعى إلا داخل معاملة» وسطرٌ لا يفرضه. صارت تُنادى من `move`.
     *
     * ولا يُختبَر أثرُها بالتشغيل هنا: الاختبارات نفسها تجري داخل معاملةٍ
     * تلفّ كلّ حالة (RefreshDatabase)، فالشرط محقَّقٌ دائمًا مهما فعلنا.
     * فيُقرأ الحارس من مصدره — ويبقى في الإنتاج شبكةً تلتقط أيّ كتابةٍ
     * للمخزون تُنسى خارج معاملة، وهي كتابتان تنجحان من أربع.
     */
    public function test_a_stock_move_asks_for_a_transaction_before_it_writes(): void
    {
        $source = file_get_contents(base_path('app/Support/StockLedger.php'));
        $body = substr($source, strpos($source, 'public static function move('));

        $this->assertStringContainsString('self::assertInTransaction();', $body);
        $this->assertLessThan(
            strpos($body, 'increment(\'quantity\''),
            strpos($body, 'self::assertInTransaction();'),
            'الحارس بعد الكتابة لا قبلها',
        );
    }

    public function test_the_same_move_inside_a_transaction_passes(): void
    {
        DB::transaction(fn () => StockLedger::move(
            $this->business->id, $this->main->id, [$this->product->id => -3], 'اختبار',
        ));

        $this->assertSame(17, (int) $this->product->fresh()->quantity);
        $this->assertBalanced();
    }

    /* ------------------- الحركة اليدوية ------------------- */

    private function move(array $over = [])
    {
        return $this->post(route('admin.inventory.store'), array_merge([
            'product_id' => $this->product->id, 'branch_id' => $this->main->id,
            'type' => 'إضافة كمية', 'quantity' => 5,
        ], $over));
    }

    public function test_a_manual_movement_keeps_the_books_level(): void
    {
        $this->move()->assertSessionHasNoErrors();

        $this->assertSame(25, (int) $this->product->fresh()->quantity);
        $this->assertBalanced();
    }

    public function test_a_manual_count_measures_the_branch_not_the_company(): void
    {
        // «تعديل يدوي» يعني «رصيد هذا الفرع صار كذا»
        $this->move(['type' => 'تعديل يدوي', 'quantity' => 8])->assertSessionHasNoErrors();

        $this->assertSame(8, (int) BranchStock::where('branch_id', $this->main->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertBalanced();
    }

    public function test_a_branch_cannot_be_drawn_below_zero(): void
    {
        $this->move(['type' => 'صرف', 'quantity' => 50])->assertSessionHasErrors('quantity');

        $this->assertSame(20, (int) $this->product->fresh()->quantity);
    }

    public function test_a_movement_leaves_a_trace(): void
    {
        $this->move()->assertSessionHasNoErrors();

        $this->assertSame(1, InventoryMovement::where('product_id', $this->product->id)
            ->where('branch_id', $this->main->id)->count());
    }

    /* ------------------- التعديل السريع والنموذج ------------------- */

    public function test_a_quick_quantity_edit_keeps_the_books_level(): void
    {
        $this->patch(route('admin.products.quick', $this->product->id), ['quantity' => 33])
            ->assertSessionHasNoErrors();

        $this->assertSame(33, (int) $this->product->fresh()->quantity);
        $this->assertBalanced();
    }

    public function test_editing_the_product_form_keeps_the_books_level(): void
    {
        $this->put(route('admin.products.update', $this->product->id), [
            'name' => 'وردة', 'price' => 10, 'quantity' => 12,
        ])->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $this->product->fresh()->quantity);
        $this->assertBalanced();
    }

    /* ------------------- العقد: كمّياتٌ صحيحة ------------------- */

    public function test_quantities_stay_whole_numbers(): void
    {
        $this->move(['quantity' => 5])->assertSessionHasNoErrors();

        $total = DB::table('products')->where('id', $this->product->id)->value('quantity');
        $branch = DB::table('branch_stocks')->where('product_id', $this->product->id)->value('quantity');

        $this->assertSame($total, (int) $total);
        $this->assertSame($branch, (int) $branch);
    }

    public function test_a_fractional_quantity_is_refused_at_the_door(): void
    {
        $this->move(['quantity' => 2.5])->assertSessionHasErrors('quantity');

        $this->assertSame(20, (int) $this->product->fresh()->quantity);
    }

    /* ------------------- حدّ المتجر وحدّ الفرع ------------------- */

    public function test_a_movement_refuses_a_branch_of_another_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);

        $this->move(['branch_id' => $theirBranch->id])->assertSessionHasErrors('branch_id');

        $this->assertSame(0, BranchStock::where('branch_id', $theirBranch->id)->count());
    }

    public function test_a_movement_refuses_a_product_of_another_shop(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'وردتهم', 'price' => 5, 'cost' => 2,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->move(['product_id' => $theirs->id])->assertNotFound();

        $this->assertSame(10, (int) $theirs->fresh()->quantity);
    }

    /* ------------------- الربط بالمشتريات ------------------- */

    public function test_goods_received_land_in_the_ordering_branch(): void
    {
        $supplier = \App\Models\Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);
        $po = \App\Models\PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->second->id,
            'number' => 'PO-1', 'supplier_id' => $supplier->id, 'supplier_name' => 'مورّد',
            'status' => 'مُرسل', 'total' => 40, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => 'وردة', 'cost' => 4, 'quantity' => 10,
        ]);

        $this->post(route('admin.purchases.receive', $po->id))->assertSessionHasNoErrors();

        $this->assertSame(30, (int) $this->product->fresh()->quantity);
        // البضاعة تدخل فرع الأمر لا الفرع المفتوح على الشاشة
        $this->assertSame(10, (int) BranchStock::where('branch_id', $this->second->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertBalanced();
    }

    /* ------------------- الربط بالمبيعات ------------------- */

    public function test_a_sale_takes_from_the_branch_that_sold(): void
    {

        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 4]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $this->assertSame(16, (int) $this->product->fresh()->quantity);
        $this->assertSame(16, (int) BranchStock::where('branch_id', $this->main->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertBalanced();
    }

    public function test_a_sale_cannot_exceed_the_branch_balance(): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->main->id,
            'product_id' => $this->product->id, 'quantity' => 2,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->second->id,
            'product_id' => $this->product->id, 'quantity' => 18,
        ]);

        // في الشركة عشرون، وفي هذا الفرع اثنتان — والبيع من الفرع
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'وردة', 'qty' => 5]],
            'payment_method' => 'نقدي',
        ])->assertStatus(422);

        $this->assertSame(20, (int) $this->product->fresh()->quantity);
    }
}
