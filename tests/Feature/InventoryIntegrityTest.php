<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المخزون: رقمٌ واحد لكل فرع، وتكلفةٌ لا تتغيّر بأثرٍ رجعيّ.
 *
 * في النظام مخزونان — إجمالي الشركة في products.quantity، ورصيدُ كل فرع في
 * branch_stocks — والبيع يقرأ الثاني. فكل شاشةٍ تقرأ الأول تكذب على من يقف
 * في فرع. وهذا الملفّ يمسك المواضع التي كانت تخلط بينهما.
 */
class InventoryIntegrityTest extends TestCase
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
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'sku' => 'ROSE-01',
            'price' => 10, 'cost' => 4, 'quantity' => 15, 'alert_qty' => 5, 'active' => true,
        ]);

        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => 10,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'product_id' => $this->product->id, 'quantity' => 5,
        ]);
    }

    private function stock(Branch $b): int
    {
        return (int) BranchStock::where('branch_id', $b->id)
            ->where('product_id', $this->product->id)->value('quantity');
    }

    private function move(string $type, int $qty, ?Branch $branch = null)
    {
        return $this->actingAs($this->owner)->post(route('admin.inventory.store'), [
            'product_id' => $this->product->id,
            'branch_id' => ($branch ?? $this->muscat)->id,
            'type' => $type,
            'quantity' => $qty,
        ]);
    }

    // ————— ١· «تعديل يدوي» —————

    public function test_a_manual_correction_speaks_about_its_branch(): void
    {
        /*
         * عطبُ الجرد نفسه بالحرف، وكان ما يزال حيًّا هنا: يُكتب الرقم المُدخَل
         * في إجمالي الشركة ثم يُدفع الفرق كلّه إلى الفرع. تعديل مسقط إلى عشرة
         * — وهي عشرة أصلًا — كان يجعل الإجمالي عشرة ورصيد مسقط خمسة.
         */
        $this->move('تعديل يدوي', 10);

        $this->assertSame(10, $this->stock($this->muscat), 'رصيد الفرع تغيّر بلا فرق');
        $this->assertSame(5, $this->stock($this->salalah), 'تعديلُ فرعٍ مسّ فرعًا آخر');
        $this->assertSame(15, (int) $this->product->fresh()->quantity);
    }

    public function test_a_manual_correction_moves_the_total_by_the_difference(): void
    {
        $this->move('تعديل يدوي', 12);

        $this->assertSame(12, $this->stock($this->muscat));
        $this->assertSame(5, $this->stock($this->salalah));
        $this->assertSame(17, (int) $this->product->fresh()->quantity);
    }

    // ————— ٣· لا رصيد سالب —————

    public function test_you_cannot_take_out_more_than_the_branch_has(): void
    {
        /*
         * الإجمالي كان محميًّا بـmax(0,…) ورصيدُ الفرع مكشوفًا: صرفُ عشرين من
         * فرعٍ فيه عشرة يتركه سالبًا بخمسة — رقمٌ لا وجود له في الواقع،
         * تقرؤه التقارير وتطرحه من قيمة المخزون.
         */
        $this->move('خصم كمية', 20)->assertSessionHasErrors('quantity');

        $this->assertSame(10, $this->stock($this->muscat));
        $this->assertSame(15, (int) $this->product->fresh()->quantity);
    }

    public function test_taking_out_exactly_what_is_there_is_allowed(): void
    {
        $this->move('خصم كمية', 10);

        $this->assertSame(0, $this->stock($this->muscat));
        $this->assertSame(5, (int) $this->product->fresh()->quantity);
    }

    // ————— ٢· الحالة تتبع الفرع —————

    public function test_a_branch_at_zero_reads_as_out_of_stock_there(): void
    {
        /*
         * صلالة صفرٌ ومسقط خمسون: الشاشة كانت تقول «متوفر» لأنها تقرأ إجمالي
         * الشركة، والكاشير في صلالة لا يستطيع البيع. ولا يعلم أحدٌ حتى يقف
         * زبونٌ أمام الصندوق.
         */
        // نفدت صلالة فعلًا: رصيدها صفر والإجمالي عشرة — لا تناقض في التركيب
        BranchStock::where('branch_id', $this->salalah->id)->update(['quantity' => 0]);
        $this->product->update(['quantity' => 10]);

        $props = $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->salalah->id])
            ->get(route('admin.inventory.index'))
            ->viewData('page')['props'];

        $row = collect($props['inventory'])->firstWhere('id', $this->product->id);

        $this->assertSame(0, $row['qty']);
        $this->assertSame('نفد المخزون', $row['status']);
        // والإجمالي معروضٌ معه: من يرى فرعه صفرًا يحتاج أن يعرف أن في غيره بضاعة
        $this->assertSame(10, $row['totalQty']);
    }

    public function test_all_branches_still_shows_the_company_total(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.inventory.index'))
            ->viewData('page')['props'];

        $row = collect($props['inventory'])->firstWhere('id', $this->product->id);

        $this->assertSame(15, $row['qty']);
    }


    // ————— ٥· التحويل —————





    // ————— ٦· فاقد الجرد —————

    public function test_a_stocktake_shortage_becomes_an_expense(): void
    {
        /*
         * كان الجرد يصحّح الرقم ولا يمسّ شيئًا آخر: تجد خمسين قطعةً ناقصة
         * فتُطرح من المخزون ولا تظهر في الربح ولا في المصروفات. فيقرأ التاجر
         * أرباحًا لم يجنها، ولا يرى كم يكلّفه الفاقد شهريًّا.
         */
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 7],
        ]);

        $expense = Expense::first();

        $this->assertNotNull($expense, 'الفاقد لم يُقيَّد');
        $this->assertSame('فاقد جرد', $expense->type);
        // ثلاث قطع بتكلفة أربعة
        $this->assertSame(12.0, (float) $expense->amount);
    }

    public function test_a_stocktake_surplus_is_not_booked_as_income(): void
    {
        // بضاعةٌ ظهرت في العدّ غالبًا خطأُ تسجيلٍ سابق لا ربحٌ جديد
        $this->actingAs($this->owner)->post(route('admin.inventory.stocktake.apply'), [
            'branch_id' => $this->muscat->id,
            'counts' => [$this->product->id => 13],
        ]);

        $this->assertSame(0, Expense::count());
    }

    // ————— ٤· التكلفة لا تتغيّر بأثرٍ رجعيّ —————

    public function test_receiving_averages_the_cost_instead_of_overwriting_it(): void
    {
        /*
         * كانت `receive` تكتب آخر سعر شراء فوق التكلفة: خمسةَ عشرَ بأربعة ثم
         * خمسةٌ بثمانية تجعل العشرين كلَّها بثمانية — فتقفز قيمة المخزون
         * بستّين لم تُدفع.
         */
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'number' => 'PO-1', 'status' => 'مؤكد', 'total' => 40,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $this->product->id,
            'name' => $this->product->name, 'quantity' => 5, 'cost' => 8,
        ]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));

        // (15×4 + 5×8) / 20 = 5
        $this->assertSame(5.0, (float) $this->product->fresh()->cost);
        $this->assertSame(20, (int) $this->product->fresh()->quantity);
    }

    public function test_past_profit_does_not_move_when_the_cost_changes(): void
    {
        /*
         * أهمّ ما في الملفّ: بيعةٌ وقعت بتكلفة أربعة تبقى بأربعة. وكان الربح
         * يُحسب بتكلفة اليوم، فرفعُ المورّد سعره ينقص ربح الشهر الماضي —
         * تقريرٌ ماليّ يتغيّر بأثرٍ رجعيّ كلّما اشتريتَ، ولا يُرى لأن الأرقام
         * تبقى معقولة.
         */
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'number' => 'ORD-1', 'status' => 'مكتمل', 'payment_method' => 'نقدي',
            'subtotal' => 20, 'tax' => 0, 'total' => 20, 'ordered_at' => now(), 'is_held' => false,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'name' => $this->product->name, 'price' => 10, 'cost' => 4, 'quantity' => 2, 'total' => 20,
        ]);

        $this->actingAs($this->owner);
        $before = Demo::profitSummary()['profit'];

        // ارتفع سعر المورّد بعد البيعة
        $this->product->update(['cost' => 9]);

        $this->assertSame($before, Demo::profitSummary()['profit'], 'ربح ما مضى تغيّر بتغيّر التكلفة');
        $this->assertSame(12.0, $before);
    }

    public function test_a_sale_before_the_snapshot_still_uses_the_product_card(): void
    {
        // بيعةٌ قديمة بلا لقطة (cost=0) — لا تنقلب أرقام ما مضى بهجرةٍ واحدة
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'number' => 'ORD-2', 'status' => 'مكتمل', 'payment_method' => 'نقدي',
            'subtotal' => 20, 'tax' => 0, 'total' => 20, 'ordered_at' => now(), 'is_held' => false,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'name' => $this->product->name, 'price' => 10, 'cost' => 0, 'quantity' => 2, 'total' => 20,
        ]);

        $this->actingAs($this->owner);

        // 20 − (4 × 2) = 12
        $this->assertSame(12.0, Demo::profitSummary()['profit']);
    }

    // ————— ٧· الحدّ والباركود —————


    public function test_two_products_cannot_share_a_barcode(): void
    {
        /*
         * صنفان بباركودٍ واحد يجعلان الماسح يختار أحدهما — فيُخصم من صنفٍ
         * ويبقى الآخر على الرفّ، ويظهر الفرق في الجرد بلا سبب.
         */
        $this->product->update(['barcode' => '6291000000001']);

        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة أخرى', 'price' => 12, 'barcode' => '6291000000001',
        ])->assertSessionHasErrors('barcode');
    }

    public function test_a_neighbouring_store_may_use_the_same_barcode(): void
    {
        // القيد داخل المتجر لا عبر المنصّة: الباركود العالمي واحدٌ لكل من يبيعه
        $this->product->update(['barcode' => '6291000000001']);

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        Setting::create(['business_id' => $other->id, 'key' => 'vat_rate', 'value' => '5']);
        $them = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($them)->post(route('admin.products.store'), [
            'name' => 'صنفهم', 'price' => 12, 'barcode' => '6291000000001',
        ])->assertSessionHasNoErrors();
    }
}
