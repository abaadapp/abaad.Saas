<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مستندا المخزون: تعديلٌ يُقيَّد، وإشعارٌ لا يُخرج البضاعة مرّتين.
 *
 * التعديل ليس تصحيحًا للرقم وحده — قطعةٌ تلفت مالٌ ضاع. والاكتفاء بتنقيص
 * العدد يُبقي قيمة المخزون في الميزانية كما كانت، فيظهر المتجر أغنى ممّا هو
 * بقيمة كلّ ما تلف عنده.
 *
 * وإشعار التسليم خطرُه معكوس: بضاعةٌ بيعت خرجت من الرصيد يوم البيع، فإشعارٌ
 * يُنقصها ثانيةً يُخرج الصنف مرّتين من رصيدٍ واحد.
 */
class InventoryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $product;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'شاي', 'sku' => 'TEA-1',
            'price' => 5, 'cost' => 2, 'quantity' => 100,
        ]);

        $this->actingAs($owner);
        $this->get(route('admin.inventory.adjustments'));
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    /* ---------------------------- سجل المخزون ---------------------------- */

    public function test_the_screen_is_named_the_stock_log_wherever_it_shows(): void
    {
        /*
         * اسمٌ واحد في موضعين: تبويبُ القسم وعنوانُ الصفحة. ولو تُرك أحدهما
         * لَقرأ التاجر اسمًا في التبويب وآخر بعد الضغط عليه — فيظنّهما بابين.
         *
         * والمسارُ لا يُمسّ: `admin.inventory.adjustments` كما هو، فالروابط
         * المحفوظة والصلاحيات لا تتغيّر بتغيّر الاسم.
         */
        $tabs = file_get_contents(resource_path('js/Components/SectionTabs.tsx'));
        $screen = file_get_contents(resource_path('js/Pages/Admin/Inventory/Adjustments.tsx'));

        $this->assertStringContainsString(
            "{ label: 'سجل المخزون', routeName: 'admin.inventory.adjustments'",
            $tabs,
            'تبويبُ القسم لا يحمل الاسم الجديد'
        );

        $this->assertSame(2, substr_count($screen, 'سجل المخزون'), 'الشاشة تحمل الاسم في عنوانها وعنوان التبويب معًا');
        $this->assertStringNotContainsString('تعديلات المخزون', $screen, 'بقي الاسمُ القديم على الشاشة');

        // والترجمة تتبع الاسم، وإلّا ظهر مفتاحٌ عربيّ في الواجهة الإنجليزية
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $this->assertArrayHasKey('سجل المخزون', $en);
        $this->assertArrayNotHasKey('تعديلات المخزون', $en, 'بقي مفتاحُ ترجمةٍ يتيمٌ للاسم القديم');

        // والصفحة تفتح كما كانت — الاسمُ تغيّر لا السلوك
        $this->get(route('admin.inventory.adjustments'))->assertOk();
    }

    private function adjust(array $overrides = [])
    {
        return $this->post(route('admin.inventory.adjustments.store'), array_merge([
            // الفرع صريحٌ في العقد منذ FIX-BATCH-001: التعديل يقع على رصيد فرع
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity_delta' => -10,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ], $overrides));
    }

    public function test_a_loss_lowers_the_stock_and_books_the_expense(): void
    {
        $this->adjust()->assertSessionHasNoErrors();

        $this->assertSame(90, (int) $this->product->fresh()->quantity);
        // عشر قطعٍ بتكلفة اثنين = عشرون خسارة
        $this->assertSame(20.0, Ledger::account($this->bid(), 'other_expenses')->balance());
        $this->assertSame(-20.0, Ledger::account($this->bid(), 'inventory')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_a_found_item_raises_the_stock_and_reverses_the_expense(): void
    {
        $this->adjust(['quantity_delta' => 5, 'reason' => 'جرد'])->assertSessionHasNoErrors();

        $this->assertSame(105, (int) $this->product->fresh()->quantity);
        $this->assertSame(10.0, Ledger::account($this->bid(), 'inventory')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_the_cost_is_copied_at_the_moment_not_read_later(): void
    {
        /*
         * تكلفة المنتج متوسّطٌ يتحرّك مع كل شراء، فقراءتها اليوم عن تلفٍ وقع
         * قبل سنة تُعطي رقمًا لم يقع.
         */
        $this->adjust();
        $this->product->update(['cost' => 9]);

        $this->assertSame(2.0, (float) StockAdjustment::first()->cost_at_time);
        $this->assertSame(-20.0, StockAdjustment::first()->valueImpact());
    }

    public function test_stock_cannot_be_driven_below_zero(): void
    {
        /*
         * رصيدٌ سالب يُفسد كلّ ما يُبنى عليه: قيمة المخزون تصير سالبة،
         * و«المنخفض» يمتلئ بأصنافٍ لا وجود لها، ونقطة البيع تبيع ما ليس عندها.
         */
        $this->adjust(['quantity_delta' => -500])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_an_adjustment_of_zero_changes_nothing_and_is_refused(): void
    {
        $this->adjust(['quantity_delta' => 0])->assertSessionHasErrors('quantity_delta');

        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_another_stores_product_cannot_be_adjusted(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'سكر', 'price' => 3, 'cost' => 1, 'quantity' => 50,
        ]);

        $this->adjust(['product_id' => $theirs->id])->assertSessionHasErrors('product_id');

        $this->assertSame(50, (int) $theirs->fresh()->quantity);
    }
}
