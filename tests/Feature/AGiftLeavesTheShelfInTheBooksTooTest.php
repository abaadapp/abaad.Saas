<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الهديّةُ تخرج من الرفّ — وتخرج من الدفتر معه.
 *
 * ═══ ما كان ═══
 *
 * `Books::recordSale` كانت تبدأ بـ`if ($total <= 0) return`. وبيعةٌ بكوبون
 * «١٠٠٪»، أو بنقاطٍ تغطّي ثمنَها كلَّه، إجماليُّها صفر — فتخرج الباقةُ من
 * الرفّ، ويُنقص المخزونُ الفعليّ، **ولا يُكتب في دفتر الأستاذ حرفٌ واحد**.
 *
 * فيبقى المخزونُ في الميزانية بتكلفة بضاعةٍ لم تعد فيه، وتُقرأ الأرباحُ
 * أعلى ممّا هي بكلّ هديّةٍ وُزّعت. وحملةٌ وُضعت لتجلب زبائن تُظهر ربحًا لا
 * نقصان — وهو أسوأ ما يُقال لتاجرٍ يقرّر أيَّ حملةٍ يُعيد.
 *
 * ولا يُكتشف بالنظر: ميزانُ المراجعة متوازن، لأنّ ما لم يُكتب لا يُخلّ به.
 *
 * ═══ وما صار ═══
 *
 * الصفرُ يمنع قيدَ الإيراد وحدَه — وتكلفةُ ما خرج تُكتب: مدين «تكلفة
 * المبيعات» دائن «المخزون».
 */
class AGiftLeavesTheShelfInTheBooksTooTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);
    }

    private function sell(array $over = []): Order
    {
        $this->actingAs($this->cashier)->postJson('/pos/checkout', $over + [
            'items' => [['id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 2, 'price' => 10]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    private function freeSale(): Order
    {
        Coupon::create([
            'business_id' => $this->business->id, 'code' => 'HADIYA',
            'type' => 'نسبة', 'value' => 100, 'active' => true,
        ]);

        return $this->sell(['coupon_code' => 'HADIYA']);
    }

    /* ==================== الهديّة ==================== */

    public function test_a_free_sale_still_takes_the_flowers_off_the_shelf(): void
    {
        $this->freeSale();

        // ‏شاهدُ المسألة: البضاعةُ خرجت فعلًا
        $this->assertSame(98, (int) $this->product->fresh()->quantity);
    }

    public function test_and_the_cost_of_what_left_is_written_in_the_ledger(): void
    {
        $order = $this->freeSale();

        $this->assertSame(0.0, (float) $order->total);
        // ‏٢ × ٤ = ٨ خرجت من المخزون إلى تكلفة المبيعات
        $this->assertSame(-8.0, Ledger::balance($this->business->id, 'inventory'));
        $this->assertSame(8.0, Ledger::balance($this->business->id, 'cogs'));
    }

    public function test_and_no_income_is_claimed_for_it(): void
    {
        $this->freeSale();

        $this->assertSame(0.0, Ledger::balance($this->business->id, 'sales'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cash'));
    }

    public function test_the_only_entry_it_writes_is_its_cost(): void
    {
        $order = $this->freeSale();

        $sources = JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->pluck('source')->all();

        $this->assertSame([Books::SALE_COST], $sources);
    }

    public function test_the_trial_balance_stays_true(): void
    {
        $this->freeSale();

        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /* ==================== وما حولها ==================== */

    public function test_cancelling_a_gift_puts_the_cost_back(): void
    {
        $order = $this->freeSale();
        OrderCorrection::cancel($order, 'اختبار');

        // ‏عكسٌ لا محو: المخزون يعود، والقيدان يبقيان في التاريخ
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'inventory'));
        $this->assertSame(0.0, Ledger::balance($this->business->id, 'cogs'));
        $this->assertSame(2, JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->count());
    }

    public function test_a_gift_is_not_written_twice(): void
    {
        $order = $this->freeSale();
        Books::recordSale($order->fresh());
        Books::recordSale($order->fresh());

        $this->assertSame(8.0, Ledger::balance($this->business->id, 'cogs'));
    }

    public function test_a_sale_with_a_price_still_writes_both_entries(): void
    {
        $order = $this->sell();

        $sources = JournalEntry::where('sourceable_type', Order::class)
            ->where('sourceable_id', $order->id)->pluck('source')->all();

        sort($sources);
        $expected = [Books::SALE, Books::SALE_COST];
        sort($expected);

        $this->assertSame($expected, $sources);
        $this->assertSame(20.0, Ledger::balance($this->business->id, 'cash'));
    }

    public function test_nothing_at_all_is_written_for_a_costless_giveaway(): void
    {
        // ‏صنفٌ بلا تكلفة على بطاقته: لا مالٌ دخل ولا بضاعةٌ لها قيمةٌ خرجت
        $free = Product::create([
            'business_id' => $this->business->id, 'name' => 'بطاقة تهنئة',
            'price' => 0, 'cost' => 0, 'quantity' => 50, 'active' => true,
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $free->id, 'name' => 'بطاقة تهنئة', 'qty' => 1, 'price' => 0]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        $this->assertSame(0, JournalEntry::where('business_id', $this->business->id)->count());
    }
}
