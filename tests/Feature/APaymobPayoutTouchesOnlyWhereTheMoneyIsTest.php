<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CustomerPaymentLink;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymobSettlement;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Books;
use App\Support\PaymobSettlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PaymobPayouts;
use Tests\TestCase;

/**
 * تحويلُ Paymob ينقل المالَ بين أماكنه وحدها — البنكِ والتسويةِ (1360) والرسوم.
 *
 * - **لا يمسّ** إيرادًا ولا ضريبةً ولا مخزونًا ولا طلبًا: البيعُ رُحّل يومَ
 *   البيع، والتحويلُ يقول أين صار مالُه فقط.
 * - **ويبقى للمتجر كلِّه** (بلا فرع) كما في الإصدار السابق: تحويلٌ واحد يحمل
 *   مدفوعاتِ فروعٍ عدّة، فلا يُقسَم على فرعٍ بالتخمين، وتقريرُ كلِّ فرعٍ كما كان.
 * - **والإيقافُ المؤقّت** يُوقف التسجيلَ وحده: البيعُ والدفعُ وإشعارُ Paymob
 *   تمضي، والعودةُ لا ترحّل معلّقةً من تلقاء نفسها — التاجرُ يعيد فحصها من شاشته.
 */
class APaymobPayoutTouchesOnlyWhereTheMoneyIsTest extends TestCase
{
    use PaymobPayouts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        config([
            'storefront.ribbon_english_checkout_businesses' => [],
            'storefront.ribbon_gift_card_product_businesses' => [],
            'storefront.ribbon_free_gift_card_message_businesses' => [],
        ]);

        [$this->shop, $this->owner, $this->rose] = $this->openShop('onlymoney', 'only-money@abaadapp.om');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** رصيدُ كلِّ حسابٍ في المتجر برمزه — وفي فرعٍ إن سُمّي */
    private function balances(?int $branch = null): array
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.business_id', $this->shop->id)
            ->when($branch !== null, fn ($q) => $q->where('e.branch_id', $branch))
            ->groupBy('a.code')->orderBy('a.code')
            ->selectRaw('a.code, sum(l.debit) - sum(l.credit) as b')
            ->pluck('b', 'a.code')->map(fn ($v) => round((float) $v, 3))->all();
    }

    private function code(string $systemKey): string
    {
        return (string) DB::table('accounts')->where('business_id', $this->shop->id)->where('system_key', $systemKey)->value('code');
    }

    private function leaf(BankAccount $bank): string
    {
        return (string) DB::table('accounts')->where('id', $bank->account_id)->value('code');
    }

    /** ما في الطلبات والمخزون — ما لا يحقّ لتحويلٍ أن يغيّره */
    private function salesAndStock(): array
    {
        return [
            DB::table('orders')->where('business_id', $this->shop->id)->orderBy('id')
                ->get(['id', 'status', 'payment_status', 'subtotal', 'tax', 'total', 'balance_due'])->map(fn ($o) => (array) $o)->all(),
            Product::where('business_id', $this->shop->id)->orderBy('id')->pluck('quantity', 'id')->all(),
        ];
    }

    public function test_a_payout_moves_only_the_bank_clearing_and_fees_never_revenue_tax_or_stock(): void
    {
        // ضريبةٌ مفتوحة شاملةٌ في السعر — فالبيعةُ تحمل ضريبةً حقيقيّة
        foreach (['vat_enabled' => '1', 'vat_rate' => '5', 'tax_mode' => 'inclusive'] as $key => $value) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
        }
        $this->post(route('admin.finance.banks.store'), ['bank_name' => 'بنك ظفار', 'opening_balance' => 0, 'opening_date' => '2027-01-01'])->assertSessionHasNoErrors();
        [$muscat, $dhofar] = BankAccount::where('business_id', $this->shop->id)->orderBy('id')->get()->all();

        $new = $this->newSale();
        $old = $this->oldSale(20, $muscat->id);
        $link = $this->paidLink(30);
        $this->assertGreaterThan(0, (float) Order::findOrFail($new->order_id)->tax, 'لم تُحمَّل البيعةُ ضريبة — الاختبارُ لا يثبت شيئًا');

        $before = $this->balances();
        $sales = $this->salesAndStock();
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$new, $old, $link], 2.1, 67.9, ['bank_account_id' => $dhofar->id])->status);
        $after = $this->balances();

        $moved = array_map('strval', array_keys(array_filter(
            array_replace(array_fill_keys(array_keys($before + $after), 0.0), $after),
            fn ($balance, $code) => round($balance - ($before[$code] ?? 0.0), 3) !== 0.0,
            ARRAY_FILTER_USE_BOTH,
        )));
        sort($moved);
        $expected = [$this->code('paymob_clearing'), $this->code('payment_gateway_fees'), $this->leaf($muscat), $this->leaf($dhofar)];
        sort($expected);
        $this->assertSame($expected, $moved, 'تحرّك حسابٌ غيرُ البنك والتسوية والرسوم');

        // كلٌّ بمقداره مرّةً واحدة
        $this->assertSame(-50.0, round($after[$this->code('paymob_clearing')] - $before[$this->code('paymob_clearing')], 3));
        $this->assertSame(2.1, round($after[$this->code('payment_gateway_fees')] - ($before[$this->code('payment_gateway_fees')] ?? 0.0), 3));
        $this->assertSame(-20.0, round($after[$this->leaf($muscat)] - $before[$this->leaf($muscat)], 3));
        $this->assertSame(67.9, round($after[$this->leaf($dhofar)] - ($before[$this->leaf($dhofar)] ?? 0.0), 3));
        $this->assertSame($sales, $this->salesAndStock(), 'غيّر التحويلُ طلبًا أو مخزونًا');
    }

    public function test_a_payout_is_shop_wide_and_leaves_each_branch_as_it_was(): void
    {
        $main = Branch::where('business_id', $this->shop->id)->value('id');
        $second = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع السيب'])->id;

        $sale = $this->oldSale(20);
        $other = $this->oldSale(25);
        // البيعةُ الثانيةُ من الفرع الآخر — طلبُها وقيدُها
        DB::table('orders')->where('id', $other->order_id)->update(['branch_id' => $second]);
        DB::table('journal_entries')->where('source', Books::SALE)->where('sourceable_id', $other->order_id)->update(['branch_id' => $second]);

        $perBranch = [$main => $this->balances($main), $second => $this->balances($second)];
        $settlement = $this->settle([$sale, $other], 1.35, 43.65);

        $this->assertNull(JournalEntry::findOrFail($settlement->journal_entry_id)->branch_id, 'قُسم التحويلُ على فرع');
        foreach ($perBranch as $branch => $balances) {
            $this->assertSame($balances, $this->balances($branch), 'تغيّر تقريرُ فرع');
        }
        $this->assertSame(43.65, $this->bank());
    }

    public function test_pausing_stops_only_settling_never_a_sale_a_payment_or_a_notice_and_resuming_posts_nothing_by_itself(): void
    {
        // معلّقةٌ قبل الإيقاف: بيعةٌ لم يُرحَّل قيدُها
        $waiting = $this->newSale();
        $order = Order::findOrFail($waiting->order_id);
        Books::unpostSale($order);
        $held = $this->settle([$waiting], 0.6, 19.4);
        $this->assertSame(PaymobSettlements::HELD, $held->status);

        config(['services.paymob.settlements_paused' => true]);

        // البيعُ وإشعارُ Paymob والرابطُ تمضي كلُّها
        $sale = $this->newSale();
        $this->assertSame('مدفوع', Order::findOrFail($sale->order_id)->payment_status);
        $link = $this->paidLink(30);
        $this->assertSame(CustomerPaymentLink::PAID, $link->status);
        $this->assertSame(50.0, $this->clearing());
        $this->assertSame(PaymobSettlements::IN_CLEARING, PaymobSettlements::classify($this->shop->id, 'intent', $sale->id)['booked']);

        // والتسجيلُ وحده يُردّ برسالةٍ تقول السبب
        $this->post(route('admin.finance.paymobSettlements.store'), [
            'items' => [$this->key($sale)], 'fee' => 0.6, 'net' => 19.4, 'settled_on' => '2027-02-13',
        ])->assertSessionHasErrors('items');
        $this->assertStringContainsString('موقوف', (string) session('errors')->first('items'));
        $this->assertSame(1, PaymobSettlement::count());

        // يُصلَح سببُ التعليق والتسجيلُ موقوف، ثمّ يعود: لا شيءَ يُرحَّل وحده
        Books::recordSale($order->fresh(['items.addons']));
        config(['services.paymob.settlements_paused' => false]);
        $entries = JournalEntry::count();
        $this->get(route('admin.finance.paymentLinks'))->assertOk();
        $this->assertSame([PaymobSettlements::HELD, null], [$held->fresh()->status, $held->fresh()->journal_entry_id]);
        $this->assertSame($entries, JournalEntry::count());

        // التاجرُ يعيد الفحصَ من شاشته — بلا تدخّلٍ تقنيّ
        $this->post(route('admin.finance.paymobSettlements.retry', $held->id))->assertSessionHas('toast.type', 'success');
        $this->assertSame(PaymobSettlements::POSTED, $held->fresh()->status);
        $this->assertSame(PaymobSettlements::POSTED, $this->settle([$sale, $link], 1.5, 48.5)->status);
        $this->assertSame([0.0, 67.9, 2.1], [$this->clearing(), $this->bank(), $this->fees()]);

        try {
            $this->settle([$sale], 0.6, 19.4);
            $this->fail('سُوّيت مدفوعةٌ مرّتين بعد العودة');
        } catch (RuntimeException) {
        }
    }
}
