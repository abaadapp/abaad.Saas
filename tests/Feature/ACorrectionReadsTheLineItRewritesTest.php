<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * تصحيحُ بندٍ في فاتورةٍ صدرت — يُقاس على ما في البند الآن لا على ما رآه.
 *
 * ═══ العطب ═══
 *
 * `setQuantity` تقرأ الكميّةَ القديمة من نسخة المنادي **قبل** المعاملة:
 *
 *     $oldQty = (int) $item->quantity;        // قبل القفل
 *     DB::transaction(function () use ($item, $oldQty, $newQty) {
 *         self::moveStock($order, $item, $oldQty - $newQty);
 *         $item->update(['quantity' => $newQty, ...]);
 *
 * والفاتورةُ الواحدة تُفتح على صندوقين: الكاشير على الطاولة الأولى يصحّحها
 * والمحاسبُ من شاشة المبيعات يصحّحها — البابُ واحدٌ تحتهما
 * (`OrderEditController`)، والشاشتان لا تتحدّثان من أنفسهما.
 *
 * فلو خفضها الأوّلُ من ثلاثٍ إلى واحدة، ثمّ أرسل الثاني «اجعلها اثنتين» وهو
 * يقرأ ثلاثًا: يُحسب الفرقُ ٣−٢ = ١ فيعود إلى الرفّ صنفٌ **لم يُبَع أصلًا**
 * — والبندُ يصير اثنتين، أي بِيعت واحدةٌ زيادة. فالرفُّ يزيد اثنين على
 * حقيقته، والجردُ آخرَ الشهر لا يقول من أين جاءا.
 *
 * ═══ ولمَ الردّ لا التصحيحُ الصامت ═══
 *
 * حسابُ الفرق من القيمة الجديدة يُصلح الرفّ ويُبقي فعلًا آخر: من أراد
 * «٣ ← ٢» — وهي **خفض** — يقع أمره «١ ← ٢»، وهي **زيادةُ بندٍ على فاتورةٍ
 * ضريبيّةٍ سُلّمت**. وهما ليسا فعلًا واحدًا.
 *
 * فيُردّ ويُقال إنّ البند تغيّر: يقرأ المصحِّحُ ما صار إليه ثمّ يقرّر.
 */
class ACorrectionReadsTheLineItRewritesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'نورة', 'email' => 'n@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $cat = Category::create(['business_id' => $this->business->id, 'name' => 'عام']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'category_id' => $cat->id,
            'name' => 'قميص', 'sku' => 'SH-1', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 2, 'active' => true, 'tax' => 5,
        ]);
        BranchStock::ensureAllocated($this->business->id, $this->product->id, 20);

        $this->actingAs($this->cashier);
    }

    /** فاتورةٌ ببندين — الثاني ليُمنع «حذفُ آخر بند» من حجب ما نقيس */
    private function sale(int $qty = 3): Order
    {
        $gross = 10 * $qty;
        $tax = round($gross * 5 / 100, 3);

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-000001', 'customer_name' => 'عميل نقدي',
            'employee_name' => 'نورة', 'user_id' => $this->cashier->id,
            'subtotal' => $gross + 10, 'discount' => 0, 'tax' => $tax, 'delivery_fee' => 0,
            'total' => round($gross + 10 + $tax, 3), 'payment_method' => 'نقدي',
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'ordered_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $this->product->id, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => $qty, 'total' => $gross,
        ]);
        // بندٌ ثانٍ بلا صنف — لا يمسّ الرفّ ولا يُشوّش القياس
        $order->items()->create([
            'name' => 'خدمة تغليف', 'price' => 10, 'cost' => 0, 'quantity' => 1, 'total' => 10,
        ]);

        $this->product->decrement('quantity', $qty);
        BranchStock::adjust($this->business->id, $this->branch->id, $this->product->id, -$qty);

        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'reference' => $order->number, 'description' => 'مبيعات', 'method' => 'نقدي',
            'type' => 'دخل', 'amount' => $order->total, 'tax_amount' => $order->tax,
            'occurred_at' => now(),
        ]);

        return $order->fresh('items');
    }

    private function shirtLine(Order $order): OrderItem
    {
        return $order->items->firstWhere('product_id', $this->product->id);
    }

    private function onShelf(): int
    {
        return (int) Product::findOrFail($this->product->id)->quantity;
    }

    /**
     * زميلٌ يصحّحها من صندوقٍ آخر — بالباب نفسِه لا بحيلةِ اختبار.
     *
     * ولا خيطَ ثانٍ ولا إنصاتٌ لاستعلام: الشاشةُ الأولى قرأت البند قبل دقيقة،
     * والثانيةُ صحّحته الآن. ونسختُنا في اليد هي ما قرأته تلك الشاشة — وهو
     * عينُ ما يصل الخادمَ حين يُضغط الزرّ.
     */
    private function someoneElseLowersItFirst(Order $order, int $to): void
    {
        OrderCorrection::setQuantity(
            $order->fresh('items'),
            $this->shirtLine($order->fresh('items')),
            $to,
            'صحّحها زميلٌ على الصندوق الثاني',
        );
    }

    /* ─────────────── ما يقع اليوم ─────────────── */

    /**
     * تصحيحٌ بُني على كميّةٍ تجاوزها غيرُه لا يُقبل.
     *
     * والمقياسُ الأهمّ الرفُّ: هو ما لا يُكتشف إلّا في الجرد.
     */
    public function test_a_correction_built_on_a_stale_quantity_is_refused(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);

        $shelfBefore = $this->onShelf();           // ١٧ — بِيعت ثلاث من عشرين
        $this->someoneElseLowersItFirst($order, 1); // زميلٌ يجعلها واحدة فيردّ اثنتين

        $this->expectException(RuntimeException::class);

        try {
            OrderCorrection::setQuantity($order, $item, 2, 'الزبون أعاد واحدة');
        } finally {
            // مهما قيل: لا يزيد الرفُّ على ما ردّه الزميلُ وحده
            $this->assertSame(
                $shelfBefore + 2,
                $this->onShelf(),
                'الرفُّ تحرّك مرّتين لردٍّ واحد — وهذا ما لا يُكتشف إلّا في الجرد',
            );
            $this->assertSame(1, (int) $item->fresh()->quantity, 'كُتبت فوق تصحيحِ الزميل كميّةٌ بُنيت على قراءةٍ قديمة');
            // و٢٠ تعني الرفَّ كما كان قبل البيع — وقطعةٌ بِيعت ولم تُردّ
            $this->assertNotSame(20, $this->onShelf(), 'عاد الرفُّ إلى ما كان قبل البيع — وقد بِيعت قطعةٌ لم تُردّ');
        }
    }

    /** والسطرُ المكتوب لا يقول «من ٣» لبندٍ صار واحدًا */
    public function test_no_line_claims_a_quantity_that_was_already_gone(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);
        $this->someoneElseLowersItFirst($order, 1);

        try {
            OrderCorrection::setQuantity($order, $item, 2, 'الزبون أعاد واحدة');
        } catch (RuntimeException) {
            // مقصود
        }

        $this->assertSame(
            0,
            DB::table('order_edits')->where('order_id', $order->id)
                ->where('qty_before', 3)->where('qty_after', 2)->count(),
            'قُيّد تصحيحٌ يقول إنّ البند كان ثلاثًا وقد صار واحدًا قبله',
        );
    }

    /* ─────────────── وبلا سباقٍ لا يتغيّر شيء ─────────────── */

    /** والتصحيحُ الهادئ يعمل كما كان — خفضٌ يردّ إلى الرفّ ويُقيَّد */
    public function test_a_quiet_correction_still_returns_what_was_not_sold(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);
        $shelf = $this->onShelf();

        $edit = OrderCorrection::setQuantity($order, $item, 1, 'الزبون اكتفى بواحدة');

        $this->assertSame($shelf + 2, $this->onShelf(), 'لم يعد إلى الرفّ ما لم يُبَع');
        $this->assertSame(1, (int) $item->fresh()->quantity);
        $this->assertSame(3, (int) $edit->qty_before);
        $this->assertSame(1, (int) $edit->qty_after);
    }

    /** وزيادةُ كميّةٍ تأخذ من الرفّ — البابُ يعمل في الجهتين */
    public function test_a_quiet_correction_upward_takes_from_the_shelf(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);
        $shelf = $this->onShelf();

        OrderCorrection::setQuantity($order, $item, 5, 'الزبون طلب خمسًا');

        $this->assertSame($shelf - 2, $this->onShelf());
        $this->assertSame(5, (int) $item->fresh()->quantity);
    }

    /* ─────────────── والإضافةُ صفٌّ كالبند ─────────────── */

    /**
     * وصفُّ الإضافة يُقرأ مقفلًا كما يُقرأ البند — العلّةُ واحدة.
     *
     * «بوكيه + شوكولاتتان» تُخفَّض إلى واحدة من صندوق، ثمّ يُرسَل من الآخر
     * «اجعلها اثنتين» وهو يقرأ اثنتين: الفرقُ يُحسب من الاثنتين القديمتين،
     * فيتحرّك مخزونُ الإضافة على فرقٍ لا وجود له.
     */
    public function test_a_stale_addon_correction_is_refused(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);

        $addon = OrderItemAddon::create([
            'order_item_id' => $item->id, 'name' => 'شوكولاتة',
            'quantity' => 3, 'unit_price' => 3, 'total' => 9,
        ]);

        // زميلٌ يخفضها إلى واحدة من صندوقٍ آخر — بالباب نفسِه
        OrderCorrection::setAddonQuantity($order->fresh('items'), $addon->fresh(), 1, 'الزبون اكتفى بواحدة');

        /*
         * ونحن نرسل بنسختنا التي تقول ثلاثًا، ونطلب اثنتين.
         *
         * والرقمان مقصودان: لو طلبنا واحدةً لَسقط الأمرُ على «لم تتغيّر
         * الكمية» قبل أن يُبلَغ الحارسُ أصلًا — فيمرّ القياسُ ولا يقيس شيئًا.
         */
        $this->expectException(RuntimeException::class);

        try {
            OrderCorrection::setAddonQuantity($order, $addon, 2, 'تصحيحٌ بُني على قراءةٍ قديمة');
        } finally {
            $this->assertSame(1, (int) $addon->fresh()->quantity, 'كُتبت فوق تصحيحِ الزميل كميّةٌ بُنيت على قراءةٍ قديمة');
        }
    }

    /**
     * وبندٌ حذفه زميلٌ لا يُصحَّح — يُقال إنّه ذهب.
     *
     * وبلا هذا يمضي التصحيحُ على صفٍّ لا وجودَ له: يتحرّك الرفُّ ويُقيَّد
     * سطرٌ في السجلّ لبندٍ لا يظهر في الفاتورة.
     */
    public function test_a_line_someone_else_deleted_is_not_corrected(): void
    {
        $order = $this->sale(3);
        $item = $this->shirtLine($order);
        $shelfAfterColleague = null;

        // زميلٌ يحذفه — والفاتورةُ تبقى ببندها الثاني
        OrderCorrection::setQuantity($order->fresh('items'), $item->fresh(), 0, 'الزبون أعادها كلَّها');
        $shelfAfterColleague = $this->onShelf();

        $this->expectException(RuntimeException::class);

        try {
            OrderCorrection::setQuantity($order, $item, 1, 'تصحيحٌ بُني على قراءةٍ قديمة');
        } finally {
            $this->assertSame($shelfAfterColleague, $this->onShelf(), 'تحرّك الرفُّ لبندٍ لا وجودَ له');
        }
    }

    /** والإضافةُ تُصحَّح هادئةً كما كانت — لا يُغلق البابُ على من لم يسابقه أحد */
    public function test_a_quiet_addon_correction_still_works(): void
    {
        $order = $this->sale(3);
        $addon = OrderItemAddon::create([
            'order_item_id' => $this->shirtLine($order)->id, 'name' => 'شوكولاتة',
            'quantity' => 2, 'unit_price' => 3, 'total' => 6,
        ]);

        $edit = OrderCorrection::setAddonQuantity($order, $addon, 1, 'الزبون اكتفى بواحدة');

        $this->assertSame(1, (int) $addon->fresh()->quantity);
        $this->assertSame(2, (int) $edit->qty_before);
        $this->assertSame(1, (int) $edit->qty_after);
    }

    /** وما لم يتغيّر يُردّ كما كان يُردّ — لا تُكتب فاتورةٌ بلا سبب */
    public function test_an_unchanged_quantity_is_still_refused(): void
    {
        $order = $this->sale(3);

        $this->expectException(RuntimeException::class);
        OrderCorrection::setQuantity($order, $this->shirtLine($order), 3, 'بلا تغيير');
    }
}
