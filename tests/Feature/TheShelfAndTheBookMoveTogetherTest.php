<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\ImportBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الرفُّ والدفترُ يتحرّكان معًا — ولا يتحرّك رصيدٌ بلا أثرٍ يُقرأ.
 *
 * ═══ ثلاثةُ أعطابٍ في المخزون ═══
 *
 *  • **الجردُ كان يكتب إجماليَّ الشركة إسنادًا** لا زيادةً ذرّيّة: يقرأ الرقم
 *    ثمّ يكتب المجموع. وبيعةٌ تمرّ بينهما تُكتب في `branch_stocks` ثمّ يمحوها
 *    الحفظُ من `products.quantity` وحده — فينكسر الثابتُ الذي يقوم عليه
 *    النظام كلُّه: «مجموع الفروع = كمية المنتج». والجردُ أطولُ عمليّةٍ فيه،
 *    فنافذةُ الضياع أوسعُ ما تكون.
 *
 *  • **تعديلُ المخزون كان يحرس إجماليَّ الشركة ويكتب على فرع.** عشرٌ في مسقط
 *    وصفرٌ في صلالة، ثمّ «تلف ٨» على صلالة: تمرّ بلا رسالة ويصير رصيدُ صلالة
 *    ناقصَ ثمانية — بضاعةٌ تلفت في فرعٍ لم تدخله قطّ. والبابُ الشقيق يحرس
 *    دفترَ الفرع منذ إصلاحه.
 *
 *  • **استيرادُ الأصناف كان يحرّك الأرصدة بلا حركة.** ملفٌّ واحد يمسّ مئتَي
 *    صنفٍ في ضغطة، ولا سطرَ في «حركات المخزون» يقول متى ولا بيدِ من.
 */
class TheShelfAndTheBookMoveTogetherTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function product(int $muscat = 10, int $salalah = 0, float $cost = 2): Product
    {
        $p = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة',
            'price' => 5, 'cost' => $cost, 'quantity' => $muscat + $salalah]);

        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->muscat->id,
            'product_id' => $p->id, 'quantity' => $muscat]);
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->salalah->id,
            'product_id' => $p->id, 'quantity' => $salalah]);

        return $p;
    }

    /** الثابتُ الذي يقوم عليه بيعُ الفروع كلُّه */
    private function assertBooksBalance(Product $product, string $why): void
    {
        $this->assertSame(
            (int) BranchStock::where('product_id', $product->id)->sum('quantity'),
            (int) $product->fresh()->quantity,
            $why,
        );
    }

    private function balance(Branch $branch, Product $product): int
    {
        return (int) BranchStock::where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('quantity');
    }

    /* ═════════════ الجردُ لا يبتلع بيعةً تمرّ تحته ═════════════ */

    /**
     * بيعةٌ تقع بين قراءة الجرد وكتابته.
     *
     * وتُحاكى بزيادةٍ ذرّيّة في القاعدة لحظةَ الحفظ — وهو ما تفعله بيعةٌ
     * حقيقيّة بالضبط: `BranchStock::adjust` و`increment` كلتاهما تكتبان في
     * القاعدة لا في كائنٍ قُرئ قبلهما.
     */
    public function test_a_sale_passing_under_the_stocktake_is_not_swallowed(): void
    {
        $product = $this->product(10, 0);

        $fired = false;
        Product::saving(function (Product $m) use (&$fired, $product) {
            if ($fired || (int) $m->id !== (int) $product->id) {
                return;
            }
            $fired = true;
            DB::table('products')->where('id', $product->id)
                ->update(['quantity' => DB::raw('quantity - 1')]);
            DB::table('branch_stocks')->where('product_id', $product->id)
                ->where('branch_id', $this->muscat->id)
                ->update(['quantity' => DB::raw('quantity - 1')]);
        });

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$product->id => 12],
        ]);

        $this->assertBooksBalance($product, 'بيعةٌ مرّت تحت الجرد فمُحيت من إجمالي الشركة وحدها');
    }

    public function test_a_plain_stocktake_still_sets_what_was_counted(): void
    {
        $product = $this->product(10, 0);

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$product->id => 12],
        ])->assertRedirect();

        $this->assertSame(12, $this->balance($this->muscat, $product));
        $this->assertSame(12, (int) $product->fresh()->quantity);
        $this->assertBooksBalance($product, 'الجردُ كسر التوازن بلا منازعٍ أصلًا');
    }

    public function test_counting_one_branch_leaves_the_other_alone(): void
    {
        $product = $this->product(10, 5);

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$product->id => 7],
        ])->assertRedirect();

        $this->assertSame(7, $this->balance($this->muscat, $product));
        $this->assertSame(5, $this->balance($this->salalah, $product), 'جردُ فرعٍ مسّ رصيدَ فرعٍ آخر');
        $this->assertSame(12, (int) $product->fresh()->quantity);
        $this->assertBooksBalance($product, 'الثابتُ انكسر بعد جردٍ هادئ');
    }

    public function test_the_stocktake_writes_a_movement_for_every_difference(): void
    {
        $product = $this->product(10, 0);

        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$product->id => 12],
        ]);

        $move = InventoryMovement::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('تسوية جرد', $move->type);
        $this->assertSame('+2', (string) $move->quantity);
    }

    /* ═════════════ لا يُتلَف في فرعٍ ما ليس فيه ═════════════ */

    public function test_damage_is_refused_in_a_branch_that_has_none(): void
    {
        $product = $this->product(10, 0);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->salalah->id, 'product_id' => $product->id,
            'quantity_delta' => 8, 'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(0, $this->balance($this->salalah, $product),
            'بضاعةٌ تلفت في فرعٍ لم تدخله قطّ — ورصيدُه صار سالبًا');
        $this->assertSame(10, (int) $product->fresh()->quantity, 'الإجماليُّ نقص عن تلفٍ لم يقع');
        $this->assertSame(0, StockAdjustment::count(), 'صفُّ تعديلٍ بقي بعد ردّ التعديل');
    }

    public function test_damage_is_allowed_up_to_what_the_branch_holds(): void
    {
        $product = $this->product(10, 3);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->salalah->id, 'product_id' => $product->id,
            'quantity_delta' => 3, 'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->balance($this->salalah, $product));
        $this->assertSame(10, (int) $product->fresh()->quantity);
        $this->assertBooksBalance($product, 'التوازنُ انكسر بتلفٍ مشروع');
    }

    public function test_one_more_than_the_branch_holds_is_refused(): void
    {
        $product = $this->product(10, 3);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->salalah->id, 'product_id' => $product->id,
            'quantity_delta' => 4, 'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(3, $this->balance($this->salalah, $product));
    }

    public function test_the_refusal_names_the_branch_and_what_it_holds(): void
    {
        $product = $this->product(10, 3);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->salalah->id, 'product_id' => $product->id,
            'quantity_delta' => 9, 'reason' => 'فقد',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasErrors('quantity_delta');

        $said = session()->get('errors')->getBag('default')->first('quantity_delta');
        $this->assertStringContainsString('صلالة', $said, 'رسالةٌ لا تقول أيّ فرعٍ فارغ');
        $this->assertStringContainsString('3', $said);
    }

    /** ولا يُمنع الإدخال: الزيادةُ تدخل فرعًا فارغًا كما ينبغي */
    public function test_adding_into_an_empty_branch_is_still_allowed(): void
    {
        $product = $this->product(10, 0);

        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->salalah->id, 'product_id' => $product->id,
            'quantity_delta' => 6, 'reason' => 'تصحيح',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, $this->balance($this->salalah, $product));
        $this->assertBooksBalance($product, 'التوازنُ انكسر بزيادةٍ مشروعة');
    }

    /* ═════════════ ما دخل الرفَّ من ملفٍّ يقول من أين جاء ═════════════ */

    private function importCsv(string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'p').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'أصناف.csv', 'text/csv', null, true),
            'branch_id' => $this->muscat->id,
        ])->assertRedirect();

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'))->assertRedirect();
    }

    public function test_stock_raised_by_a_file_leaves_a_movement(): void
    {
        $product = $this->product(10, 0);

        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,90\n");

        $this->assertSame(90, (int) $product->fresh()->quantity);

        $move = InventoryMovement::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('+80', (string) $move->quantity, 'الحركةُ تكتب الرصيد لا الفارق');
        $this->assertSame('تعديل يدوي', $move->type);
        $this->assertSame($this->muscat->id, (int) $move->branch_id);
        $this->assertStringContainsString('أصناف.csv', (string) $move->note, 'حركةٌ لا تقول من أيّ ملفّ');
        $this->assertSame('المالك', (string) $move->employee_name, 'حركةٌ لا تقول بيدِ من');
    }

    public function test_a_product_born_from_a_file_gets_its_opening_movement(): void
    {
        $this->importCsv("الاسم,السعر,الكمية\nياسمين,3,40\n");

        $product = Product::where('name', 'ياسمين')->firstOrFail();
        $move = InventoryMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('+40', (string) $move->quantity);
        $this->assertSame('رصيد افتتاحي', $move->type);
    }

    public function test_the_movements_add_up_to_what_the_file_left(): void
    {
        $product = $this->product(10, 0);

        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,90\nياسمين,3,40\n");

        foreach (Product::where('business_id', $this->shop->id)->get() as $p) {
            $moved = InventoryMovement::where('product_id', $p->id)->get()
                ->sum(fn ($m) => (int) $m->quantity);
            $opening = (int) $p->id === (int) $product->id ? 10 : 0;

            $this->assertSame((int) $p->quantity, $opening + $moved,
                'مجموعُ الحركات لا يُعيد بناءَ رصيد «'.$p->name.'» — والفرقُ بلا تفسير');
        }
    }

    public function test_a_file_that_moves_nothing_writes_no_movement(): void
    {
        $product = $this->product(10, 0);

        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,10\n");

        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count(),
            'حركةٌ كُتبت ولم يتحرّك رصيد');
    }

    public function test_undoing_an_import_leaves_its_own_movement(): void
    {
        $product = $this->product(10, 0);

        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,90\n");

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'))->assertRedirect();

        $this->assertSame(10, (int) $product->fresh()->quantity);

        $moves = InventoryMovement::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $moves, 'التراجعُ محا الرصيد ولم يقل إنّه محاه');
        $this->assertSame('-80', (string) $moves[1]->quantity);
        $this->assertStringContainsString('تراجع', (string) $moves[1]->note);
    }

    /* ═════════════ والتراجعُ لا يمحو بيعةً وقعت بعد الاستيراد ═════════════ */

    private function sellAtMuscat(Product $product, int $qty): void
    {
        $this->actingAs($this->owner)->withSession(['current_branch' => $this->muscat->id])
            ->postJson('/pos/checkout', [
                'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => $qty]],
                'payment_method' => 'نقدي', 'client_uuid' => uniqid('u', true),
            ])->assertOk();
    }

    public function test_undoing_an_import_after_a_sale_takes_back_the_file_not_the_sale(): void
    {
        /*
         * التراجعُ كان يكتب كميّةَ ما قبل الملفّ رقمًا مطلقًا (٣٠)، ويحرّك
         * الفروعَ بالفرق (−٢٠). فبيعةٌ بينهما تبقى منقوصةً من الفرع وتعود
         * إلى الإجماليّ: ٣٠ في الدفتر و٢٥ على الرفوف.
         */
        $product = $this->product(30, 0);
        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,50\n");
        $this->sellAtMuscat($product, 5);
        $this->assertSame(45, (int) $product->fresh()->quantity);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'))->assertRedirect();

        $this->assertSame(25, (int) $product->fresh()->quantity, 'عادت البيعةُ إلى الرفّ مع التراجع');
        $this->assertSame(25, $this->balance($this->muscat, $product));
        $this->assertBooksBalance($product, 'افترق الإجماليُّ عن الفروع بعد التراجع');
    }

    public function test_undoing_a_price_only_import_does_not_bring_back_what_was_sold(): void
    {
        // ملفٌّ يغيّر السعر وحده — والكميّةُ محفوظةٌ في «ما قبله» كما كانت
        $product = $this->product(30, 0);
        $this->importCsv("الاسم,السعر\nوردة,7\n");
        $this->assertSame(7.0, (float) $product->fresh()->price);
        $this->sellAtMuscat($product, 5);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'))->assertRedirect();

        $this->assertSame(5.0, (float) $product->fresh()->price, 'لم يعد السعر');
        $this->assertSame(25, (int) $product->fresh()->quantity, 'بُعثت خمسُ وردٍ مبيعة');
        $this->assertBooksBalance($product, 'افترق الإجماليُّ عن الفروع بعد التراجع');
    }

    public function test_an_import_written_before_the_change_is_undone_by_its_branch_deltas(): void
    {
        // دفعةٌ كُتبت قبل أن تحفظ `moved` — فروقُ فروعها وحدها
        $product = $this->product(30, 0);
        $this->importCsv("الاسم,السعر,الكمية\nوردة,5,50\n");
        $batch = ImportBatch::latest('id')->firstOrFail();
        $payload = $batch->payload;
        unset($payload['updated'][0]['moved']);
        $batch->forceFill(['payload' => $payload])->save();
        $this->sellAtMuscat($product, 5);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'))->assertRedirect();

        $this->assertSame(25, (int) $product->fresh()->quantity);
        $this->assertBooksBalance($product, 'افترق الإجماليُّ عن الفروع بعد تراجع دفعةٍ قديمة');
    }
}
