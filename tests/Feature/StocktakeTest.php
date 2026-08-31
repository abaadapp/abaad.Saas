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

    /* ==================== الجرد يصل تقرير الهالك ==================== */

    /**
     * النقص الذي يكشفه العدّ هالكٌ يُقرأ باسم صنفه.
     *
     * كان الجرد يكتب رصيدًا وحركةً ومصروفًا واحدًا مجمَّعًا لكلّ الأصناف —
     * فلا صفَّ تعديلٍ ولا تكلفةَ لحظة ولا صنفٌ يُعرف. ومحلُّ ورد هالكُه
     * مصروفُه الأوّل، وكان النظام يبتلعه ثمّ يقول «لا هالك».
     */
    public function test_a_shortage_found_at_stocktake_lands_in_the_waste_report(): void
    {
        // عشرةٌ في الدفتر وسبعةٌ في اليد: ثلاثُ ورداتٍ عُدمت
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 7],
        ])->assertSessionHasNoErrors();

        $row = \App\Models\StockAdjustment::firstOrFail();

        $this->assertSame(\App\Models\StockAdjustment::STOCKTAKE_LOSS, $row->reason);
        $this->assertEqualsWithDelta(-3.0, (float) $row->quantity_delta, 0.001);
        // تكلفة اللحظة لا تكلفة اليوم
        $this->assertEqualsWithDelta(4.0, (float) $row->cost_at_time, 0.001);
        $this->assertSame($this->muscat->id, (int) $row->branch_id);
        $this->assertSame($this->product->id, (int) $row->product_id);

        $totals = \App\Support\Waste::totals($this->business->id, [
            'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(3.0, $totals['quantity'], 0.001);
        $this->assertEqualsWithDelta(12.0, $totals['value'], 0.001);

        // وباسم صنفه لا مجمَّعًا
        $byProduct = \App\Support\Waste::groupedBy($this->business->id, 'product', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
        ]);
        $this->assertSame('باقة ورد', $byProduct[0]['label']);
    }

    /** والزيادة تصحيحُ دفترٍ لا هالك — ولا ربح */
    public function test_a_surplus_found_at_stocktake_is_not_waste(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 13],
        ])->assertSessionHasNoErrors();

        $row = \App\Models\StockAdjustment::firstOrFail();

        $this->assertSame(\App\Models\StockAdjustment::STOCKTAKE_GAIN, $row->reason);
        $this->assertEqualsWithDelta(3.0, (float) $row->quantity_delta, 0.001);
        $this->assertFalse(\App\Support\Waste::isWaste($row->reason));

        $this->assertEqualsWithDelta(0.0, \App\Support\Waste::totals($this->business->id, [
            'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(),
        ])['value'], 0.001);
    }

    /** ولا يُسجَّل صفٌّ لصنفٍ لم يتغيّر: جردٌ صحيح لا يُنتج ضجيجًا */
    public function test_a_matching_count_writes_no_row_at_all(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, \App\Models\StockAdjustment::count());
    }

    /** ولا يُخصم المخزون مرّتين: الصفّ سجلٌّ لا حركة */
    public function test_recording_the_row_does_not_move_the_stock_again(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 7],
        ])->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $this->product->fresh()->quantity);   // 15 − 3
        $this->assertSame(7, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(5, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    /** والأرقام المتسلسلة لا تتصادم حين يُجرد أكثر من صنف */
    public function test_every_row_gets_its_own_reference(): void
    {
        $second = Product::create([
            'business_id' => $this->business->id, 'name' => 'جيبسوفيلا',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'alert_qty' => 2, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $second->id, 'quantity' => 20,
        ]);

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 7, $second->id => 15],
        ])->assertSessionHasNoErrors();

        $numbers = \App\Models\StockAdjustment::pluck('number')->all();

        $this->assertCount(2, $numbers);
        $this->assertSame($numbers, array_unique($numbers));
    }

    /* ==================== الجرد يعدّ ولا يخصم ==================== */

    /**
     * عمود العدّ يعتمد لا يطرح — مهما زُيد في الطلب.
     */
    public function test_the_counted_number_is_the_balance_and_no_stray_key_changes_that(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'mode' => 'loss',
            'counts' => [$this->product->id => 3],
        ])->assertSessionHasNoErrors();

        // ثلاثةٌ عُدَّت فثلاثةٌ في الدفتر — لا سبعة
        $this->assertSame(3, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(8, (int) $this->product->fresh()->quantity);   // 15 − 7

        // والنقص هالكُ جردٍ لا «تلف»: عدٌّ كشف فرقًا، لا إقرارٌ بعطب
        $this->assertSame(
            \App\Models\StockAdjustment::STOCKTAKE_LOSS,
            \App\Models\StockAdjustment::firstOrFail()->reason,
        );
    }
    /* ==================== عمود الفاقد — يُطرح لا يُعتمد ==================== */

    /**
     * أخطرُ عطبٍ في هذه الشاشة كان عمودًا واحدًا يُسأل عنه سؤالان.
     *
     * من عنده مئة وردةٍ تلفت ثلاثٌ منها كان يكتب «٣» في «الكمية المعدودة»
     * فيصير رصيده ثلاثًا بدل سبعٍ وتسعين: رقمٌ مشروع في حقلٍ مشروع، وتسعون
     * وردةً تختفي بلا رسالةٍ ولا أثر. فصار للفاقد عمودُه.
     */
    public function test_the_loss_column_subtracts_and_never_becomes_the_balance(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [],
            'losses' => [$this->product->id => 3],
        ])->assertSessionHasNoErrors();

        // سبعةٌ لا ثلاثة
        $this->assertSame(7, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(12, (int) $this->product->fresh()->quantity);   // 15 − 3
        // ولا يُمَسّ فرعٌ آخر
        $this->assertSame(5, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    /** والفاقد هالكٌ باسم صنفه وبتكلفة لحظته في التقرير */
    public function test_the_loss_column_lands_in_the_waste_report(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [],
            'losses' => [$this->product->id => 3],
        ])->assertSessionHasNoErrors();

        $row = \App\Models\StockAdjustment::firstOrFail();

        $this->assertSame('تلف', $row->reason);
        $this->assertEqualsWithDelta(-3.0, (float) $row->quantity_delta, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $row->cost_at_time, 0.001);
        $this->assertTrue(\App\Support\Waste::isWaste($row->reason));

        $window = ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()];

        $this->assertEqualsWithDelta(3.0, \App\Support\Waste::totals($this->business->id, $window)['quantity'], 0.001);
        $this->assertEqualsWithDelta(12.0, \App\Support\Waste::totals($this->business->id, $window)['value'], 0.001);
    }

    /** والعمودان يعملان في جردٍ واحد على صنفين مختلفين */
    public function test_counting_one_item_and_writing_off_another_in_one_pass(): void
    {
        $other = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورق تغليف',
            'price' => 1, 'cost' => 0.5, 'quantity' => 20, 'active' => true,
        ]);
        BranchStock::adjust($this->business->id, $this->muscat->id, $other->id, 20);

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 8],
            'losses' => [$other->id => 2],
        ])->assertSessionHasNoErrors();

        $this->assertSame(8, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(18, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $other->id)->value('quantity'));
    }

    /**
     * وصنفٌ واحد لا يُجرَد بالطريقتين معًا.
     *
     * من كتب «معدود ٩٧» و«فاقد ٣» يقصد شيئًا واحدًا، والطاعةُ للاثنين
     * تطرح ستًّا. والردّ خيرٌ من تخمينِ أيّهما أراد.
     */
    public function test_one_item_cannot_be_both_counted_and_written_off(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 7],
            'losses' => [$this->product->id => 3],
        ])->assertSessionHasErrors('losses');

        $this->assertSame(15, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, \App\Models\StockAdjustment::count());
    }

    /** وفاقدٌ بصفرٍ لا يكتب شيئًا */
    public function test_a_zero_loss_writes_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [],
            'losses' => [$this->product->id => 0],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, \App\Models\StockAdjustment::count());
        $this->assertSame(10, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }
}
