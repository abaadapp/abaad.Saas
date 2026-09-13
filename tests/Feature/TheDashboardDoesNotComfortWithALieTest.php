<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اللوحةُ لا تُطمئِن بكذبة — ولا تقول رقمًا تنقضه الشاشةُ المجاورة.
 *
 * ═══ ثلاثةُ أعطاب ═══
 *
 *  • **خسارةٌ تُرسم خضراء بسهمٍ صاعد.** `trend` كانت تقرأ كلَّ أساسٍ غير موجب
 *    صعودًا: خسارةُ مئتين ثمّ خسارةُ خمسِمئة ⇦ «0%» و`up = true`، واللونُ
 *    `success` مثبَّتٌ في البطاقة مهما كان الرقم. واللونُ أوّلُ ما تقرؤه
 *    العينُ على لوحةٍ فيها ثماني بطاقات — وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.
 *
 *  • **حدُّ الشهرين كان يقع في الشهرين.** `whereBetween` تشمل الطرفين
 *    والشهرُ الجاري `>=`، فما وقع على الحدّ حُسب مرّتين. و`spent_at` عمودُ
 *    تاريخٍ بلا ساعة، فمصروفُ اليوم الأوّل — يومِ الإيجار والرواتب — يقع
 *    عليه بالضبط.
 *
 *  • **بطاقةُ «منخفض المخزون» كانت تقرأ الشركة** وبقيّةُ البطاقات تتبع الفرع،
 *    وشاشةُ المخزون تحكم برصيد الفرع. سؤالٌ واحد وجوابان على شاشتين.
 */
class TheDashboardDoesNotComfortWithALieTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** البطاقةُ بموضعها — لا باسمها: العنوانُ يُترجَم واللوحةُ ثابتةُ الترتيب */
    private const NET = 7;

    private const EXPENSES = 6;

    private const LOW_STOCK = 5;

    private function card(int $at): array
    {
        return Demo::adminStats()[$at];
    }

    private function amount(string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/u', '', str_replace(',', '', $value));
    }

    private function expense(float $amount, string $on): void
    {
        Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'إيجار',
            'amount' => $amount, 'status' => 'مدفوع', 'spent_at' => $on,
        ]);
    }

    /* ═════════════ خسارةٌ لا تُرسم خضراء ═════════════ */

    public function test_a_deepening_loss_is_never_drawn_green(): void
    {
        $this->expense(200, now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString());
        $this->expense(500, now()->startOfMonth()->addDays(2)->toDateString());

        $card = $this->card(self::NET);

        $this->assertSame(-500.0, $this->amount($card['value']), 'الخسارةُ نفسُها تغيّرت');
        $this->assertFalse($card['up'], 'خسارةٌ تضاعفت رُسمت بسهمٍ صاعد');
        $this->assertSame('danger', $card['color'], 'خسارةٌ في بطاقةٍ خضراء');
    }

    public function test_a_shrinking_loss_is_an_improvement(): void
    {
        $this->expense(500, now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString());
        $this->expense(200, now()->startOfMonth()->addDays(2)->toDateString());

        $card = $this->card(self::NET);

        $this->assertSame(-200.0, $this->amount($card['value']));
        $this->assertTrue($card['up'], 'خسارةٌ تقلّصت قُرئت تراجعًا');
        // ويبقى اللونُ أحمر: الاتّجاهُ تحسّن والحالُ خسارة
        $this->assertSame('danger', $card['color']);
    }

    public function test_a_profit_after_a_loss_is_green(): void
    {
        $this->expense(300, now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString());
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة',
            'price' => 100, 'cost' => 40, 'quantity' => 10, 'alert_qty' => 2]);
        $this->sell($product, 1);

        $card = $this->card(self::NET);

        $this->assertSame(60.0, $this->amount($card['value']));
        $this->assertTrue($card['up']);
        $this->assertSame('success', $card['color']);
    }

    public function test_a_quiet_month_after_a_quiet_month_still_reads_zero(): void
    {
        $card = $this->card(self::NET);

        $this->assertSame('0%', $card['trend'], 'شهرٌ هادئ بعد هادئ لم يعد يُقرأ صفرًا');
        $this->assertTrue($card['up']);
    }

    /* ═════════════ حدُّ الشهرين لا يقع في الشهرين ═════════════ */

    public function test_an_expense_on_the_first_belongs_to_one_month_only(): void
    {
        $this->expense(300, now()->startOfMonth()->toDateString());

        $card = $this->card(self::EXPENSES);

        $this->assertSame(300.0, $this->amount($card['value']), 'مصروفُ الشهر تغيّر');
        // كان يُحسب في الشهر الماضي أيضًا، فيقول «+0%» عن مصروفٍ جديدٍ كلِّه
        $this->assertSame('+100%', $card['trend'], 'مصروفٌ جديدٌ كلُّه قُرئ «لا تغيّر»');
    }

    public function test_last_months_expense_still_counts_for_last_month(): void
    {
        $this->expense(300, now()->subMonthNoOverflow()->startOfMonth()->addDays(10)->toDateString());
        $this->expense(300, now()->startOfMonth()->addDays(3)->toDateString());

        $this->assertSame('+0%', $this->card(self::EXPENSES)['trend'],
            'مصروفان متساويان في شهرين قُرئا تغيّرًا');
    }

    public function test_a_sale_on_the_first_belongs_to_one_month_only(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة',
            'price' => 100, 'cost' => 40, 'quantity' => 10, 'alert_qty' => 2]);

        $this->sell($product, 1, over: ['ordered_at' => now()->startOfMonth()]);

        // مبيعاتُ الشهر ١٠٠ والماضي صفر — لا ١٠٠ في الاثنين
        $this->assertSame('+100%', $this->card(1)['trend'], 'بيعةٌ على الحدّ حُسبت في الشهرين');
    }

    /* ═════════════ المنخفضُ منخفضٌ في الفرع الذي تنظر إليه ═════════════ */

    private function stocked(int $muscat, int $salalah, int $alert = 10): Product
    {
        $p = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة',
            'price' => 5, 'cost' => 2, 'quantity' => $muscat + $salalah, 'alert_qty' => $alert]);

        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->muscat->id,
            'product_id' => $p->id, 'quantity' => $muscat]);
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->salalah->id,
            'product_id' => $p->id, 'quantity' => $salalah]);

        return $p;
    }

    public function test_the_card_and_the_inventory_screen_answer_alike(): void
    {
        $product = $this->stocked(50, 1);

        $this->get(route('admin.branch.switch', $this->salalah->id));

        $row = collect(Demo::inventory())->firstWhere('id', $product->id);

        $this->assertSame('منخفض', $row['status'], 'شاشةُ المخزون غيّرت حكمَها');
        $this->assertSame('1', $this->card(self::LOW_STOCK)['value'],
            'اللوحةُ تقول رقمًا تنقضه الشاشةُ المجاورة');
    }

    public function test_the_other_branch_is_not_judged_by_this_one(): void
    {
        $this->stocked(50, 1);

        $this->get(route('admin.branch.switch', $this->muscat->id));

        $this->assertSame('0', $this->card(self::LOW_STOCK)['value'],
            'فرعٌ ممتلئ قُرئ منخفضًا بسبب فرعٍ آخر');
    }

    /**
     * و«كل الفروع» يقرأ الشركة لا أوّلَ فرعٍ فيها.
     *
     * والحالةُ تفرّق بينهما: واحدةٌ في مسقط وخمسون في صلالة — الشركةُ إحدى
     * وخمسون فليست منخفضة، ومسقطُ وحدَها منخفضة. فلو سقط الشرطُ إلى فرعٍ
     * افتراضيّ لَقرأ من لم يختر فرعًا حالَ مسقطَ وحدها.
     */
    public function test_all_branches_reads_the_company_not_the_first_branch(): void
    {
        $this->stocked(1, 50);

        $this->assertNull(Demo::currentBranchId());
        $this->assertSame('0', $this->card(self::LOW_STOCK)['value'],
            '«كل الفروع» صار يقرأ فرعًا بعينه');

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $this->assertSame('1', $this->card(self::LOW_STOCK)['value'],
            'ومسقطُ وحدَها منخفضة — فالفرقُ بين القراءتين قائم');
    }

    /**
     * والحدُّ حدٌّ: ما بلغه ليس تحته.
     *
     * والقاعدةُ قاعدةُ `Product::statusFor` — «أقلُّ من الحدّ» — فرصيدٌ يساوي
     * الحدَّ بالضبط «متوفر» في شاشة المخزون، ولا يُعدّ منخفضًا في اللوحة.
     */
    public function test_a_balance_that_equals_the_alert_is_not_below_it(): void
    {
        $product = $this->stocked(50, 10, alert: 10);

        $this->get(route('admin.branch.switch', $this->salalah->id));

        $row = collect(Demo::inventory())->firstWhere('id', $product->id);

        $this->assertSame('متوفر', $row['status'], 'شاشةُ المخزون غيّرت حدَّها');
        $this->assertSame('0', $this->card(self::LOW_STOCK)['value'],
            'رصيدٌ بلغ حدَّه عُدّ تحته — واللوحةُ تفترق عن شاشة المخزون');
    }

    public function test_a_product_never_allocated_is_read_in_the_first_branch(): void
    {
        // لا صفَّ فرعٍ له — وقاعدةُ `books`: رصيدُه كلُّه في الفرع الأوّل
        Product::create(['business_id' => $this->shop->id, 'name' => 'ياسمين',
            'price' => 5, 'cost' => 2, 'quantity' => 3, 'alert_qty' => 10]);

        $this->get(route('admin.branch.switch', $this->muscat->id));
        $this->assertSame('1', $this->card(self::LOW_STOCK)['value'],
            'منتجٌ لم يُوزَّع لم يُقرأ في الفرع الأوّل');

        $this->get(route('admin.branch.switch', $this->salalah->id));
        $this->assertSame('1', $this->card(self::LOW_STOCK)['value'],
            'وصفرٌ في صلالة أقلُّ من الحدّ — فهو منخفضٌ هناك أيضًا');
    }

    /** بيعةٌ حقيقية — بندٌ بتكلفته، كما في حارس اللوحة الأوّل */
    private function sell(Product $p, int $qty, array $over = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id, 'branch_id' => $this->muscat->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 5, '0', STR_PAD_LEFT),
            'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => $p->price * $qty, 'tax' => 0, 'total' => $p->price * $qty,
            'ordered_at' => now(),
        ], $over));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => $p->price, 'cost' => $p->cost, 'quantity' => $qty,
            'total' => $p->price * $qty,
        ]);

        return $order;
    }
}
