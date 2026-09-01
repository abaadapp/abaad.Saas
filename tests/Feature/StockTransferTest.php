<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سندُ النقل بين الفروع — وثيقةٌ واحدة لحركتين.
 *
 * لم يكن في النظام نقلٌ أصلًا: طريقُ التاجر حركتان يدويّتان لا شيء يربطهما.
 * فإن نسي الثانية نقص مخزونُه بلا سبب، وإن كتبها بكميّةٍ أخرى اختلّ
 * الرصيدان — ولا يُكتشف الفرق إلّا في جردٍ آخر السنة.
 *
 * والثابت الذي يحرسه هذا الملفّ كلّه واحد: **مجموع الفروع = كمية المنتج**.
 */
class StockTransferTest extends TestCase
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
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 5, 'cost' => 2, 'quantity' => 10, 'alert_qty' => 1, 'active' => true,
        ]);

        // عشرةٌ في مسقط ولا شيء في صلالة
        BranchStock::updateOrCreate(
            ['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $this->product->id],
            ['quantity' => 10],
        );
        BranchStock::updateOrCreate(
            ['business_id' => $this->business->id, 'branch_id' => $this->salalah->id, 'product_id' => $this->product->id],
            ['quantity' => 0],
        );
    }

    private function transfer(array $overrides = [])
    {
        return $this->actingAs($this->owner)->post(route('admin.inventory.transfers.store'), array_merge([
            'from_branch_id' => $this->muscat->id,
            'to_branch_id' => $this->salalah->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
            'transferred_at' => now()->format('Y-m-d'),
        ], $overrides));
    }

    private function book(Branch $branch): int
    {
        return BranchStock::bookOf($this->business->id, $this->product->id, $branch->id);
    }

    /* ====================== ما ينقص من فرعٍ يزيد في آخر ====================== */

    public function test_the_stock_moves_between_the_two_branches(): void
    {
        $this->transfer()->assertSessionHasNoErrors();

        $this->assertSame(6, $this->book($this->muscat));
        $this->assertSame(4, $this->book($this->salalah));
    }

    public function test_the_shops_total_does_not_move_at_all(): void
    {
        /*
         * البضاعة لم تدخل ولم تخرج — إنّما تحرّكت. وتحريكُ الإجماليّ هنا يكسر
         * الثابت «مجموع الفروع = كمية المنتج» في صمت، فتقرأ التقارير مخزونًا
         * لا وجود له أو تُخفي ما هو قائم.
         */
        $this->transfer()->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $this->product->fresh()->quantity);
        $this->assertSame(
            10,
            (int) BranchStock::where('business_id', $this->business->id)
                ->where('product_id', $this->product->id)->sum('quantity'),
            'انكسر الثابت: مجموع الفروع ≠ كمية المنتج',
        );
    }

    /* ========================= الوثيقة تربط الطرفين ========================= */

    public function test_one_document_carries_both_movements(): void
    {
        /*
         * الحركتان تظهران في سجلّ المخزون صرفًا وإضافةً، وبلا رقمٍ يجمعهما
         * تُقرآن حادثتين لا واحدة: من يراجع السجلّ يظنّ أنّ فرعًا صرف بلا
         * سببٍ وأنّ آخر استلم بلا مصدر.
         */
        $this->transfer()->assertSessionHasNoErrors();

        $doc = StockTransfer::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame('TRF-000001', $doc->number);

        $moves = InventoryMovement::where('reference', $doc->number)->get();

        $this->assertCount(2, $moves, 'حركتان لا تحملان رقم السند');
        $this->assertSame('-4', (string) $moves->firstWhere('branch_id', $this->muscat->id)->quantity);
        $this->assertSame('+4', (string) $moves->firstWhere('branch_id', $this->salalah->id)->quantity);
    }

    public function test_the_document_keeps_the_branch_names_it_was_written_with(): void
    {
        // فرعٌ يُحذف غدًا يبقى مقروءًا في تاريخه — والسجلّ يُقرأ بعد الحذف
        $this->transfer()->assertSessionHasNoErrors();

        $doc = StockTransfer::firstOrFail();
        $this->assertSame('مسقط', $doc->from_branch_name);
        $this->assertSame('صلالة', $doc->to_branch_name);
    }

    /* ============================== ما يُرفض ============================== */

    public function test_a_branch_never_sends_more_than_it_holds(): void
    {
        /*
         * رصيدٌ سالب يُفسد كلّ ما يُبنى عليه: قيمة المخزون تصير سالبة،
         * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطة البيع تبيع ما ليس عندها.
         */
        $this->transfer(['quantity' => 11])->assertSessionHasErrors('quantity');

        $this->assertSame(10, $this->book($this->muscat));
        $this->assertSame(0, $this->book($this->salalah));
        $this->assertSame(0, StockTransfer::count(), 'كُتب سندٌ لنقلٍ لم يقع');
    }

    public function test_the_refusal_measures_the_sending_branch_not_the_whole_shop(): void
    {
        /*
         * في المتجر عشرة، وفي صلالة صفر. ونقلُ خمسةٍ منها إلى مسقط يمرّ لو
         * قيس بالإجماليّ — فيصير رصيد صلالة ‎−٥ والإجماليُّ كما هو.
         */
        $this->transfer([
            'from_branch_id' => $this->salalah->id,
            'to_branch_id' => $this->muscat->id,
            'quantity' => 5,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(0, $this->book($this->salalah));
    }

    public function test_a_branch_does_not_transfer_to_itself(): void
    {
        // سندٌ وحركتان تُلغيان بعضهما: سطرٌ يقول إنّ شيئًا حدث ولم يحدث
        $this->transfer(['to_branch_id' => $this->muscat->id])
            ->assertSessionHasErrors('from_branch_id');

        $this->assertSame(0, StockTransfer::count());
    }

    public function test_a_zero_or_negative_quantity_is_refused(): void
    {
        $this->transfer(['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->transfer(['quantity' => -3])->assertSessionHasErrors('quantity');

        $this->assertSame(10, $this->book($this->muscat));
    }

    /* ========================= الجدار بين المتاجر ========================= */

    public function test_a_neighbours_branch_is_not_a_destination(): void
    {
        /*
         * ومعرّفُ الفرع يصل من الطلب لا من شاشة: نقلٌ إلى فرع الجار كان
         * سيُخرج البضاعة من هذا المتجر ويُدخلها في متجرٍ آخر — وهو أسوأ من
         * ضياعها، لأنّ لها أثرًا يقول إنّها وصلت.
         */
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعهم']);

        $this->transfer(['to_branch_id' => $theirs->id])->assertSessionHasErrors('from_branch_id');

        $this->assertSame(10, $this->book($this->muscat));
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_a_neighbours_product_cannot_be_moved(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirProduct = Product::create([
            'business_id' => $neighbour->id, 'name' => 'صنفهم',
            'price' => 5, 'cost' => 2, 'quantity' => 50, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->transfer(['product_id' => $theirProduct->id])->assertSessionHasErrors('product_id');

        $this->assertSame(50, (int) $theirProduct->fresh()->quantity);
    }

    public function test_a_neighbour_sees_none_of_my_transfers(): void
    {
        $this->transfer()->assertSessionHasNoErrors();

        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirOwner = User::create([
            'business_id' => $neighbour->id, 'name' => 'جار', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($theirOwner)->get(route('admin.inventory.transfers'))
            ->assertInertia(fn ($p) => $p->has('transfers', 0));
    }

    public function test_the_screen_is_closed_to_whoever_lacks_the_inventory_section(): void
    {
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'مبيعات', 'email' => 's@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->actingAs($clerk)->get(route('admin.inventory.transfers'))->assertForbidden();
        // والباب لا الشاشة وحدها: من يكتب العنوان لا يمرّ
        $this->actingAs($clerk)->post(route('admin.inventory.transfers.store'), [
            'from_branch_id' => $this->muscat->id, 'to_branch_id' => $this->salalah->id,
            'product_id' => $this->product->id, 'quantity' => 1,
            'transferred_at' => now()->format('Y-m-d'),
        ])->assertForbidden();

        $this->assertSame(0, StockTransfer::count());
    }

    /* ====================== وحذفُ الفرع يدلّ على بابه ====================== */

    public function test_the_delete_refusal_now_names_a_door_that_exists(): void
    {
        /*
         * كانت الرسالة تحيل إلى «فرعٍ آخر» ولا نقلَ في النظام، فيبحث التاجر
         * عن زرٍّ ليس موجودًا ثمّ يظنّ العطب في بصره.
         */
        $this->actingAs($this->owner)
            ->delete(route('admin.branches.destroy', $this->muscat->id))
            ->assertSessionHasErrors('branch');

        $message = (string) session('errors')->first('branch');
        $this->assertStringContainsString('النقل بين الفروع', $message);
    }

    public function test_and_the_door_actually_empties_the_branch(): void
    {
        // النصيحة تُجرَّب لا تُقال: تُنقل الكميّة كلّها ثمّ يُحذف الفرع فعلًا
        $this->transfer(['quantity' => 10])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->book($this->muscat));

        $this->actingAs($this->owner)
            ->delete(route('admin.branches.destroy', $this->muscat->id))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('branches', ['id' => $this->muscat->id]);
    }
}
