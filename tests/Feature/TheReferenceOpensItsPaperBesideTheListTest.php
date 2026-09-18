<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرجعُ في الحركة المالية بابٌ يفتح ورقته — إلى جانب الجدول لا بدلًا منه.
 *
 * ═══ ما كان ═══
 *
 * عمودُ «المرجع» نصٌّ لا يُنقر. فمن قرأ حركةً وأراد ورقتَها خرج إلى الطلبات
 * وبحث برقمها، ثمّ عاد فوجد الجدولَ على صفحته الأولى بلا فلترته ولا مدّته.
 *
 * ═══ وما صار ═══
 *
 * الخادمُ يقول أيُّ حركةٍ لها فاتورة (`invoice`)، فالشاشةُ ترسم بابًا حيث
 * يُفتح ولا ترسمه حيث لا يُفتح — مصروفٌ وتحويلٌ لا ورقةَ لهما.
 *
 * والورقةُ تُطلب حين تُطلب: رسمُ عشرين ورقةً لكلّ فتحةٍ للشاشة يُشغّل قالبَ
 * المستند عشرين مرّة، والتاجرُ يفتح واحدةً أو لا يفتح شيئًا.
 *
 * وهي HTML لا PDF: الـPDF يخرج بالتاجر من صفحته إلى قارئ المتصفّح فيفقد
 * مكانَه، ويعود بزرِّ المتصفّح لا بزرٍّ نعرفه.
 */
class TheReferenceOpensItsPaperBesideTheListTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-5001',
            'customer_name' => 'عميل', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 20,
            'ordered_at' => now(),
        ]);
        $this->order->items()->create([
            'product_id' => $product->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 2, 'total' => 20,
        ]);
    }

    private function movement(?Order $order, string $reference = 'TRX-1'): Transaction
    {
        return Transaction::create([
            'business_id' => $this->business->id,
            'order_id' => $order?->id,
            'reference' => $reference,
            'description' => $order ? 'مبيعات نقطة البيع' : 'إيجار',
            'method' => 'نقدي',
            'type' => $order ? 'دخل' : 'مصروف',
            'amount' => 20,
            'occurred_at' => now(),
        ]);
    }

    /* ───────────────────────── الشاشة ───────────────────────── */

    /** الحركةُ ذاتُ الفاتورة تحمل رقمَها، فترسم الشاشةُ بابًا */
    public function test_a_movement_with_an_invoice_carries_its_number(): void
    {
        $this->movement($this->order);

        $this->actingAs($this->owner)
            ->get(route('admin.finance.transactions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.invoice', 'INV-5001'));
    }

    /**
     * وما لا فاتورةَ له لا يُعرض له باب.
     *
     * مقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض.
     */
    public function test_a_movement_without_an_invoice_offers_no_door(): void
    {
        $this->movement(null, 'TRX-2');

        $this->actingAs($this->owner)
            ->get(route('admin.finance.transactions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.0.invoice', null));
    }

    /* ───────────────────────── الورقة ───────────────────────── */

    /** والبابُ يردّ الورقةَ مرسومةً — لا رابطَ PDF يُخرج من الصفحة */
    public function test_the_paper_comes_back_drawn(): void
    {
        $transaction = $this->movement($this->order);

        $response = $this->actingAs($this->owner)
            ->getJson(route('admin.finance.transactionPaper', $transaction->id))
            ->assertOk();

        $response->assertJsonStructure(['html', 'size', 'number', 'url']);
        $this->assertSame('INV-5001', $response->json('number'));
        $this->assertStringContainsString('INV-5001', $response->json('html'), 'الورقةُ لا تحمل رقم فاتورتها');
        $this->assertNotEmpty($response->json('size'), 'مقاسُ الورقة غائب فلا يُرسم الإطار');
    }

    /** وحركةٌ بلا فاتورة تُردّ ٤٠٤ — لا ورقةَ فارغة */
    public function test_a_movement_without_an_invoice_has_no_paper(): void
    {
        $transaction = $this->movement(null, 'TRX-3');

        $this->actingAs($this->owner)
            ->getJson(route('admin.finance.transactionPaper', $transaction->id))
            ->assertNotFound();
    }

    /* ───────────────────────── العزل ───────────────────────── */

    /** وورقةُ حركةِ جارٍ لا تُفتح برقمٍ مُخمَّن في العنوان */
    public function test_another_shops_movement_is_not_opened(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);

        $theirOrder = Order::create([
            'business_id' => $other->id, 'number' => 'INV-9001',
            'customer_name' => 'عميلهم', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 50, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 50,
            'ordered_at' => now(),
        ]);
        $theirs = Transaction::create([
            'business_id' => $other->id, 'order_id' => $theirOrder->id,
            'reference' => 'TRX-THEIRS', 'description' => 'بيع', 'method' => 'نقدي',
            'type' => 'دخل', 'amount' => 50, 'occurred_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.finance.transactionPaper', $theirs->id))
            ->assertNotFound();
    }

    /**
     * وصفُّ حركةٍ لجارٍ لا يكون مقبضًا على بياناتنا — ولو كانت الورقةُ ورقتَنا.
     *
     * وهذه وحدها تفرّق بين الحارسين: حصرُ **الحركة** بالمتجر، وحصرُ
     * **الطلب** به. فحركةُ جارٍ تشير إلى طلب جارٍ يردّها الثاني، فيمرّ نزعُ
     * الأوّل بلا أن يمسكه شيء. أمّا صفٌّ يملكه الجار ويشير إلى طلبنا فلا
     * يردّه إلّا الأوّل — والبابُ لا يُجيب عن صفٍّ ليس لصاحبه.
     */
    public function test_a_neighbours_row_is_no_handle_on_our_data(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);

        $theirs = Transaction::create([
            'business_id' => $other->id, 'order_id' => $this->order->id,
            'reference' => 'TRX-HANDLE', 'description' => 'بيع', 'method' => 'نقدي',
            'type' => 'دخل', 'amount' => 20, 'occurred_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('admin.finance.transactionPaper', $theirs->id))
            ->assertNotFound();
    }

    /**
     * وحركةٌ تشير إلى فاتورة جارٍ لا تُفتح كذلك.
     *
     * الحصرُ على الحركة وحدها لا يكفي: الورقةُ تُبنى من الطلب، فلو قُرئ
     * بمعرّفه وحده لَخرجت فاتورةُ جارٍ من باب حركتنا.
     */
    public function test_a_movement_pointing_at_a_foreign_order_opens_nothing(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $theirOrder = Order::create([
            'business_id' => $other->id, 'number' => 'INV-9002',
            'customer_name' => 'عميلهم', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 50, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 50,
            'ordered_at' => now(),
        ]);

        $mine = $this->movement(null, 'TRX-4');
        $mine->forceFill(['order_id' => $theirOrder->id])->save();

        $this->actingAs($this->owner)
            ->getJson(route('admin.finance.transactionPaper', $mine->id))
            ->assertNotFound();
    }
}
