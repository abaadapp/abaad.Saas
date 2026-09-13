<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رصيدٌ يتغيّر يقول من غيّره ومتى.
 *
 * ═══ العطبُ الذي كان ═══
 *
 * شاشةُ المنتج تُغيّر الكمية في ثلاثة مواضع — عند الإنشاء، وعند التعديل،
 * وفي التعديل السريع — وكانت تُحرّك `products.quantity` و`branch_stocks`
 * ولا تكتب سطرًا في `inventory_movements`.
 *
 * فيرى التاجرُ رصيدَه تغيّر ولا يجد في «حركات المخزون» ما يقول متى ولا بيدِ
 * من. ومخزونٌ يتغيّر بلا أثرٍ يُقرأ بابٌ مفتوحٌ على نقصٍ لا يُكتشف: من يخفض
 * الكمية ثمّ يأخذ البضاعة لا يترك أثرًا يُسأل عنه.
 *
 * وكان واقعًا في الإنتاج: سبعةُ أصناف في «متجري» رصيدُها لا يُساوي مجموعَ
 * حركاتها، والفرقُ هو الرصيدُ الافتتاحيّ الذي لم يُقيَّد قطّ.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * أنّ كلَّ تغييرٍ يُقيَّد، وأنّ ما يُقيَّد يُساوي ما تغيّر، وأنّ اسمَ من
 * غيّره مكتوبٌ فيه. ومجموعُ الحركات يُعيد بناءَ الرصيد — وهو الاختبارُ
 * الوحيد الذي يُثبت أنّ الدفترَ دفتر.
 */
class StockThatChangesSaysWhoChangedItTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ═══════════════════ الإنشاء ═══════════════════ */

    /** رصيدٌ افتتاحيٌّ يُقيَّد — لا يبدأ الصنفُ برقمٍ بلا أصل */
    public function test_opening_stock_is_written_as_a_movement(): void
    {
        $this->create(['quantity' => 13]);

        $move = InventoryMovement::first();

        $this->assertNotNull($move, 'بدأ الصنفُ برصيدٍ لا يقول أحدٌ من أين جاء');
        $this->assertSame(StockLedger::OPENING, $move->type);
        $this->assertSame('+13', $move->quantity);
        $this->assertSame($this->branch->id, $move->branch_id);
    }

    /** واسمُ من كتبه فيه — «بيدِ من» نصفُ الأثر */
    public function test_the_opening_movement_names_who_wrote_it(): void
    {
        $this->create(['quantity' => 5]);

        $this->assertSame('سالم', InventoryMovement::first()->employee_name);
    }

    /** وصنفٌ بلا رصيدٍ لا يُقيَّد له شيء — صفرٌ ليس حركة */
    public function test_a_product_created_empty_writes_no_movement(): void
    {
        $this->create(['quantity' => 0]);

        $this->assertSame(0, InventoryMovement::count(), 'قُيّدت حركةٌ لصفر');
    }

    /* ═══════════════════ التعديل ═══════════════════ */

    /** زيادةٌ بيدٍ تُقيَّد بفارقها لا بالكمية الجديدة */
    public function test_a_manual_increase_is_written_as_its_difference(): void
    {
        $product = $this->create(['quantity' => 4]);
        InventoryMovement::query()->delete();

        $this->edit($product, ['quantity' => 10]);

        $move = InventoryMovement::first();

        $this->assertNotNull($move, 'تغيّر الرصيدُ صامتًا');
        $this->assertSame(StockLedger::MANUAL, $move->type);
        $this->assertSame('+6', $move->quantity, 'قُيّدت الكميةُ الجديدة لا فارقُها');
    }

    /**
     * ونقصٌ بيدٍ يُقيَّد كذلك — وهو أخطرُ ما يُحرَس.
     *
     * من يخفض الكمية ثمّ يأخذ البضاعة لا يترك أثرًا يُسأل عنه إن لم يُكتب.
     */
    public function test_a_manual_decrease_is_written_too(): void
    {
        $product = $this->create(['quantity' => 10]);
        InventoryMovement::query()->delete();

        $this->edit($product, ['quantity' => 3]);

        $this->assertSame('-7', InventoryMovement::first()?->quantity, 'نقصٌ بيدٍ مرّ بلا أثر');
    }

    /** وحفظٌ بلا تغييرِ كميةٍ لا يُقيَّد — سجلٌّ يمتلئ بلا شيءٍ لا يُقرأ */
    public function test_saving_without_changing_the_quantity_writes_nothing(): void
    {
        $product = $this->create(['quantity' => 7]);
        InventoryMovement::query()->delete();

        $this->edit($product, ['quantity' => 7, 'name' => 'اسمٌ آخر']);

        $this->assertSame(0, InventoryMovement::count(), 'قُيّدت حركةٌ لتغييرِ اسم');
    }

    /* ═══════════════════ التعديل السريع ═══════════════════ */

    /** والتعديلُ السريع تعديل — سرعتُه لا تُسقط أثرَه */
    public function test_the_quick_edit_is_written_as_well(): void
    {
        $product = $this->create(['quantity' => 2]);
        InventoryMovement::query()->delete();

        $this->actingAs($this->owner)
            ->patch(route('admin.products.quick', $product->id), ['quantity' => 9]);

        $move = InventoryMovement::first();

        $this->assertNotNull($move, 'التعديلُ السريع يُغيّر الرصيدَ بلا أثر');
        $this->assertSame(StockLedger::MANUAL, $move->type);
        $this->assertSame('+7', $move->quantity);
        $this->assertSame('سالم', $move->employee_name);
    }

    /** وتغييرُ سعرٍ وحدَه لا يُقيَّد حركةَ مخزون */
    public function test_a_quick_price_change_writes_no_stock_movement(): void
    {
        $product = $this->create(['quantity' => 2]);
        InventoryMovement::query()->delete();

        $this->actingAs($this->owner)
            ->patch(route('admin.products.quick', $product->id), ['price' => 99]);

        $this->assertSame(0, InventoryMovement::count());
    }

    /* ═══════════════════ الدفترُ يُعيد بناءَ الرصيد ═══════════════════ */

    /**
     * وهذا الحارسُ هو الغاية: مجموعُ الحركات = الرصيد.
     *
     * وهو ما سقط في الإنتاج. فما لم يُجمع الدفترُ إلى رصيده فليس دفترًا،
     * وسائلُ «من أين جاء هذا الرقم؟» لا يجد جوابًا.
     */
    public function test_the_movements_add_up_to_the_balance(): void
    {
        $product = $this->create(['quantity' => 20]);

        $this->edit($product, ['quantity' => 14]);
        $this->actingAs($this->owner)
            ->patch(route('admin.products.quick', $product->id), ['quantity' => 31]);

        $stock = BranchStock::where('product_id', $product->id)
            ->where('branch_id', $this->branch->id)->value('quantity');

        $sum = InventoryMovement::where('product_id', $product->id)
            ->get()->sum(fn ($m) => (int) $m->quantity);

        $this->assertSame(31, (int) $stock);
        $this->assertSame(
            (int) $stock,
            $sum,
            'مجموعُ الحركات لا يُعيد بناءَ الرصيد — والفرقُ بلا تفسير'
        );
    }

    /* ═══════════════════ أدوات ═══════════════════ */

    private function create(array $overrides = []): Product
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد',
            'price' => 10,
            'cost' => 4,
            ...$overrides,
        ]);

        return Product::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function edit(Product $product, array $overrides = []): void
    {
        $this->actingAs($this->owner)->put(route('admin.products.update', $product->id), [
            'name' => 'باقة ورد',
            'price' => 10,
            'cost' => 4,
            ...$overrides,
        ]);
    }
}
