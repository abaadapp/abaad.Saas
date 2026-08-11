<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الجرد يعدّ فرعًا، فيجب أن يقارن برصيد ذلك الفرع.
 *
 * كانت الشاشة تعرض «الكمية الدفترية» = إجمالي الشركة، ويحسب التطبيق الفرقَ
 * منها ثم يكتب المعدود في الإجمالي. فمتجرٌ بفرعين — عشرة في مسقط وخمسة في
 * صلالة — يعدّ مسقط فيجدها عشرة كما يجب، فيقول له النظام إن الفرق −٥،
 * ويمحو رصيد صلالة، ويُنقص مسقط إلى خمسة. أي أن الجرد الصحيح يُفسد الأرقام،
 * والعدّ الثاني يُفسدها أكثر.
 */
class StocktakeTest extends TestCase
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
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 15, 'alert_qty' => 2, 'active' => true,
        ]);

        // عشرة في مسقط وخمسة في صلالة — والإجمالي خمسة عشر
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => 10,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'product_id' => $this->product->id, 'quantity' => 5,
        ]);
    }

    private function apply(Branch $branch, int $counted): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $branch->id,
            'counts' => [$this->product->id => $counted],
        ]);
    }

    private function stock(Branch $branch): int
    {
        return (int) BranchStock::where('branch_id', $branch->id)
            ->where('product_id', $this->product->id)->value('quantity');
    }

    public function test_a_correct_count_changes_nothing(): void
    {
        /*
         * أهمّ ما في الملفّ: من عدّ مسقط فوجدها عشرة كما في الدفتر يجب ألّا
         * يتغيّر شيء. وكان يخرج بفرقٍ −٥ وتسويةٍ تُفسد فرعين.
         */
        $this->apply($this->muscat, 10);

        $this->assertSame(10, $this->stock($this->muscat), 'رصيد الفرع المعدود تغيّر بلا سبب');
        $this->assertSame(5, $this->stock($this->salalah), 'جردُ فرعٍ مسّ رصيد فرعٍ آخر');
        $this->assertSame(15, (int) $this->product->fresh()->quantity, 'الإجمالي تغيّر بلا فرق');
        $this->assertSame(0, InventoryMovement::count(), 'سُجّلت تسويةٌ بلا فرق');
    }

    public function test_a_shortage_in_one_branch_leaves_the_other_alone(): void
    {
        // عُدّت مسقط فوُجدت ٨ بدل ١٠: نقصٌ اثنان في مسقط وحدها
        $this->apply($this->muscat, 8);

        $this->assertSame(8, $this->stock($this->muscat));
        $this->assertSame(5, $this->stock($this->salalah));
        $this->assertSame(13, (int) $this->product->fresh()->quantity, 'الإجمالي يجب أن ينقص بالفرق لا أن يصير المعدود');
    }

    public function test_a_surplus_adds_to_the_total(): void
    {
        $this->apply($this->muscat, 12);

        $this->assertSame(12, $this->stock($this->muscat));
        $this->assertSame(5, $this->stock($this->salalah));
        $this->assertSame(17, (int) $this->product->fresh()->quantity);
    }

    public function test_counting_the_second_branch_reads_its_own_book(): void
    {
        // صلالة فيها ٥؛ عدّها ٥ لا يغيّر شيئًا مهما كان الإجمالي
        $this->apply($this->salalah, 5);

        $this->assertSame(10, $this->stock($this->muscat));
        $this->assertSame(5, $this->stock($this->salalah));
        $this->assertSame(15, (int) $this->product->fresh()->quantity);
    }

    public function test_counting_both_branches_in_turn_settles_correctly(): void
    {
        /*
         * الجرد الكامل يمرّ على الفروع واحدًا واحدًا. وكان كل تطبيقٍ يكتب
         * المعدود في الإجمالي، فآخر فرعٍ يُعدّ يمحو ما قبله — والحصيلة رصيد
         * فرعٍ واحد في خانة الشركة كلّها.
         */
        $this->apply($this->muscat, 9);
        $this->apply($this->salalah, 4);

        $this->assertSame(9, $this->stock($this->muscat));
        $this->assertSame(4, $this->stock($this->salalah));
        $this->assertSame(13, (int) $this->product->fresh()->quantity);
    }

    public function test_the_screen_shows_the_branch_book_not_the_company_total(): void
    {
        /*
         * والرقم المعروض هو ما يقرأه العادّ ويقارن به. فلو عُرض الإجمالي
         * لظنّ نفسه ناقصًا خمسة وهو مضبوط — ولذهب يبحث عن بضاعةٍ لم تُفقد.
         */
        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.stocktake'))
            ->viewData('page')['props'];

        $item = collect($props['items'])->firstWhere('id', $this->product->id);

        $this->assertSame(10, $item['stock'][$this->muscat->id]);
        $this->assertSame(5, $item['stock'][$this->salalah->id]);
    }

    public function test_a_product_never_allocated_sits_in_the_first_branch(): void
    {
        /*
         * منتجٌ بلا توزيعٍ رصيدُه كلّه في الفرع الأوّل — وهي القاعدة نفسها
         * التي يطبّقها ensureAllocated عند أوّل حركة. واختلاف الشاشة عنها
         * يعني رقمًا دفتريًّا يخالف ما سيحسبه الخادم.
         */
        $fresh = Product::create([
            'business_id' => $this->business->id, 'name' => 'شمعة',
            'price' => 3, 'cost' => 1, 'quantity' => 7, 'active' => true,
        ]);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.stocktake'))
            ->viewData('page')['props'];

        $item = collect($props['items'])->firstWhere('id', $fresh->id);

        $this->assertSame(7, $item['stock'][$this->muscat->id]);
        $this->assertArrayNotHasKey($this->salalah->id, $item['stock']);
    }

    public function test_the_movement_records_the_branch_difference(): void
    {
        $this->apply($this->muscat, 8);

        $movement = InventoryMovement::first();

        $this->assertSame('تسوية جرد', $movement->type);
        $this->assertSame($this->muscat->id, $movement->branch_id);
        $this->assertSame('-2', $movement->quantity);
    }

    public function test_a_branch_from_another_store_is_refused(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);

        $this->apply($theirs, 3)->assertSessionHasErrors('branch_id');

        $this->assertSame(10, $this->stock($this->muscat));
    }
}
