<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Bank;
use App\Support\Boutiques;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * بيعةٌ دخل بندُ بوتيكها ورقةَ تسوية لا تُلغى ولا يُغيَّر بندُها من خلفها.
 *
 * الورقةُ حصّةُ البوتيك ممّا بِيع، مجمَّدةً يومَ صدورها — دَينٌ في 2160 أو مالٌ
 * دُفع. وكان إلغاءُ البيعة بعدها يمرّ: يعود المالُ للزبون والقطعةُ إلى الرفّ،
 * ويبقى للبوتيك ثمنُ قطعةٍ لم تُبَع، ولا تقول ذلك شاشة. فيُردّ الفعلُ برسالةٍ
 * تسمّي الورقة إلى أن يُبنى تصحيحٌ مستقلّ — والورقةُ وقيدُها لا يُمسّان.
 * طلبه المالك عند مراجعة PR #177 (2026-10-25).
 */
class ABoutiqueSaleOnAnIssuedSheetIsNotUndoneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $boutique;

    private Product $theirs;

    private Product $ours;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');

        $this->shop = Business::create(['name' => 'متجر الإلغاء', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'undo@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->post(route('admin.finance.banks.store'), [
            'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $this->boutique = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->theirs = $this->product('عطر لمى', 50, $this->boutique->id);
        $this->ours = $this->product('باقة ورد', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    /** بيعةٌ فيها قطعتا بوتيك (١٠٠، للبوتيك ٨٠) وباقةٌ للمحلّ (٢٠) */
    private function sell(): Order
    {
        $this->postJson('/pos/checkout', [
            'items' => [
                ['id' => $this->theirs->id, 'name' => 'عطر لمى', 'qty' => 2, 'price' => 50],
                ['id' => $this->ours->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 20],
            ],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function issue(string $at = '2027-03-02 09:00:00'): BoutiqueSettlement
    {
        Carbon::setTestNow($at);
        $this->post(route('admin.boutiques.settle', $this->boutique->id), ['period' => '2027-02'])->assertSessionHasNoErrors();

        return BoutiqueSettlement::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    private function line(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    /** ما يجب ألّا يتغيّر حين يُردّ الفعل: الطلبُ وبنودُه والدفترُ والرفّ والدَّين */
    private function state(Order $order): array
    {
        return [
            'status' => (string) $order->fresh()->status,
            'lines' => OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all(),
            'entries' => JournalEntry::where('business_id', $this->shop->id)->count(),
            'shelf' => (int) $this->theirs->fresh()->quantity,
            'owed' => Ledger::balance($this->shop->id, 'boutique_payable'),
            'cash' => Ledger::balance($this->shop->id, 'cash'),
        ];
    }

    /* ═══════════ الإلغاءُ يُردّ ═══════════ */

    public function test_deleting_a_sale_on_an_issued_sheet_is_refused_by_name_and_nothing_moves(): void
    {
        $order = $this->sell();
        $sheet = $this->issue();
        $before = $this->state($order);

        $this->delete(route('admin.orders.destroy', $order->number))->assertSessionHasErrors('order');

        $this->assertStringContainsString($sheet->number, (string) session('errors')->first('order'));
        $this->assertStringContainsString('عطر لمى', (string) session('errors')->first('order'));
        $this->assertSame($before, $this->state($order));
        $this->assertSame(80.0, $before['owed']);
    }

    /** طلبٌ ما زال يُجهَّز — والإلغاءُ من شاشة الطلب أو لوحة التجهيز بابُه `OrderTransition` */
    public function test_moving_it_to_cancelled_says_why_and_changes_nothing(): void
    {
        $order = $this->sell();
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::PREPARING]);
        $sheet = $this->issue();
        $before = $this->state($order);

        $this->post(route('admin.orders.status', $order->number), ['status' => OrderStatus::CANCELLED])->assertSessionHasErrors('status');

        $this->assertStringContainsString($sheet->number, (string) session('errors')->first('status'));
        $this->assertSame($before, $this->state($order));
    }

    public function test_a_paid_sheet_holds_its_sale_too(): void
    {
        $order = $this->sell();
        $sheet = $this->issue();
        Carbon::setTestNow('2027-03-05 11:00:00');
        $this->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'تحويل بنكي'])->assertSessionHasNoErrors();
        $before = $this->state($order);

        $this->expectExceptionObject(new RuntimeException(__('بند «:item» في ورقة تسوية البوتيك :number — لا يُلغى الطلب ولا يُغيَّر هذا البند بعد إصدارها، كي لا يبقى للبوتيك مستحقٌّ عن بيعٍ لم يتمّ. يُصحَّح ذلك بتسويةٍ مستقلّة مع البوتيك.', ['item' => 'عطر لمى', 'number' => $sheet->number])));

        try {
            OrderCorrection::cancel($order->fresh(), 'مرتجع');
        } finally {
            $this->assertSame($before, $this->state($order));
            $this->assertSame([0.0, 920.0], [$before['owed'], round(Bank::leaf($this->shop->id)->balance(), 3)]);
        }
    }

    /** ورقةٌ صدرت قبل قيد الإصدار: دُفع عنها أو سيُدفع — فالبيعةُ تُحجز كذلك */
    public function test_an_old_sheet_without_its_entry_holds_its_sale_too(): void
    {
        $order = $this->sell();
        $sheet = $this->issue();
        $entry = JournalEntry::where('business_id', $this->shop->id)->where('source', Boutiques::ACCRUAL)->sole();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete();
        DB::table('journal_entries')->where('id', $entry->id)->delete();

        $this->delete(route('admin.orders.destroy', $order->number))->assertSessionHasErrors('order');

        $this->assertSame(OrderStatus::COMPLETED, (string) $order->fresh()->status);
        $this->assertStringContainsString($sheet->number, (string) session('errors')->first('order'));
    }

    /* ═══════════ وبندُ البوتيك لا يُنقَص ولا يُستبدَل ═══════════ */

    public function test_the_boutique_line_is_neither_reduced_nor_replaced_after_its_sheet(): void
    {
        $order = $this->sell();
        // ورقةٌ جزئيّة في يوم البيع نفسه — والتصحيحُ مفتوحٌ في يومه
        $sheet = $this->issue('2027-02-10 11:00:00');
        $before = $this->state($order);

        foreach ([
            fn () => OrderCorrection::setQuantity($order->fresh(), $this->line($order, $this->theirs), 1, 'أعاد قطعة', 'refunded', 'نقدي'),
            fn () => OrderCorrection::replaceLine($order->fresh(), $this->line($order, $this->theirs), ['product_id' => $this->ours->id, 'qty' => 1], 'غيّر رأيه', 'refunded', 'نقدي'),
        ] as $edit) {
            try {
                $edit();
                $this->fail('عُدِّل بندٌ دخل ورقة تسوية');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($sheet->number, $e->getMessage());
            }
        }

        $this->assertSame($before, $this->state($order));
    }

    public function test_the_shops_own_line_on_the_same_sale_is_still_corrected(): void
    {
        $order = $this->sell();
        $this->issue('2027-02-10 11:00:00');

        OrderCorrection::setQuantity($order->fresh(), $this->line($order, $this->ours), 0, 'أعاد الباقة', 'refunded', 'نقدي');

        $this->assertSame([2], OrderItem::where('order_id', $order->id)->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(80.0, Ledger::balance($this->shop->id, 'boutique_payable'), 'دَينُ البوتيك كما هو');
        $this->assertSame(100.0, Ledger::balance($this->shop->id, 'cash'), '١٢٠ قُبضت و٢٠ رُدّت');
    }

    /* ═══════════ وما قبل الورقة على حاله ═══════════ */

    public function test_a_sale_cancelled_before_its_sheet_is_left_out_of_it(): void
    {
        $kept = $this->sell();
        $cancelled = $this->sell();

        $this->delete(route('admin.orders.destroy', $cancelled->number))->assertSessionHasNoErrors();
        $sheet = $this->issue();

        $this->assertSame(OrderStatus::CANCELLED, (string) $cancelled->fresh()->status);
        $this->assertSame([100.0, 80.0], [(float) $sheet->gross, (float) $sheet->net]);
        $this->assertSame((int) $sheet->id, (int) $this->line($kept, $this->theirs)->boutique_settlement_id);
        $this->assertNull($this->line($cancelled, $this->theirs)->boutique_settlement_id);
    }

    /**
     * ورقةٌ تُصدَر بكشفٍ تغيّر بعد قراءته تُردّ ليُراجَع — لا تُختم برقمٍ قديم.
     *
     * ويُنادى `write` بكشفٍ قُرئ قبل إلغاء البيعة، كما يقع حين يُلغيها موظّفٌ
     * والمالكُ يضغط «أصدر» على شاشةٍ فتحها قبله.
     */
    public function test_a_statement_that_changed_before_issuing_is_refused_and_nothing_is_written(): void
    {
        $this->sell();
        $cancelled = $this->sell();
        Carbon::setTestNow('2027-03-02 09:00:00');
        $stale = Boutiques::statement($this->shop->id, $this->boutique->id, '2027-02');
        OrderCorrection::cancel($cancelled->fresh(), 'أُلغيت قبل الإصدار');

        $write = new \ReflectionMethod(Boutiques::class, 'write');

        try {
            $write->invoke(null, $this->shop, $this->boutique, '2027-02', $stale, 'المالك');
            $this->fail('صدرت ورقةٌ بكشفٍ تغيّر');
        } catch (SettlementRefused $e) {
            $this->assertStringContainsString('تغيّرت', $e->getMessage());
        }

        $this->assertSame(0, BoutiqueSettlement::count());
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, OrderItem::whereNotNull('boutique_settlement_id')->count());
        $this->assertSame(0.0, Ledger::balance($this->shop->id, 'boutique_payable'));
    }
}
