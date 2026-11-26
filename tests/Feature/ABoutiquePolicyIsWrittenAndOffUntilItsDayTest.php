<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiquePriceAgreement;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\BoutiqueSheetHold;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BoutiqueDues;
use App\Support\BoutiqueHolds;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueMovements;
use App\Support\BoutiquePolicy;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\BoutiqueSales;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * السياسةُ المقترحة مكتوبةٌ ومطفأة: لا تسري على متجرٍ قبل يومها، ولا على ما بيع قبله.
 *
 * المتّفقُ عليه 12 بعمولة 20٪: للبوتيك 9.600 مهما خُصم أو زيد. والحصّةُ تنتظر تحصيلَ بيعها في
 * كلّ باب، وفاتورةُ الشهر كلَّها. وتحصيلٌ أُلغي بعد الورقة يوقف ما تأثّر وحده.
 */
class ABoutiquePolicyIsWrittenAndOffUntilItsDayTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Product $ring;

    private Product $bundle;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->shop = Business::create(['name' => 'متجر السياسة', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        foreach (['vat_enabled' => '0', 'pay_credit' => '1'] as $k => $v) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $k, 'value' => $v]);
        }
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'pol@test.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'ع 1']);
        $this->ring = Product::create(['business_id' => $this->shop->id, 'name' => 'خاتم', 'price' => 12, 'cost' => 0, 'quantity' => 1000, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id]);
        $this->bundle = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 30, 'cost' => 1, 'quantity' => 1000, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->bundle->id, 'component_product_id' => $this->ring->id, 'quantity' => 1, 'wastage_percent' => 0]);
        // شركةٌ تُفوتَر شهريًّا: بيعاتُها الآجلة تجمعها فاتورةُ الشهر
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true, 'credit_limit' => null, 'monthly_billing' => true]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $from = '2027-02-01'): void
    {
        DB::table('businesses')->where('id', $this->shop->id)->update(['boutique_policy_from' => $from]);
    }

    private function agree(float $price = 12, string $from = '2027-02-01', ?string $to = null): void
    {
        BoutiquePolicy::record($this->shop->id, $this->lama, ['product_id' => $this->ring->id, 'price' => $price, 'starts_on' => $from, 'ends_on' => $to, 'reference' => 'اتفاق 1'], $this->owner->id);
    }

    /** الصندوقُ يبيع بسعر البطاقة (ولو بتخفيضها) — لا بما يرسله */
    private function sell(Product $p, int $qty = 1, ?float $sale = null, bool $credit = false): Order
    {
        // وتخفيضُ البطاقة نسبةٌ في الصنف (`Product::sellingPrice`)
        $p->update(['discount' => $sale === null ? 0 : round((1 - $sale / (float) $p->price) * 100, 6)]);
        $this->postJson('/pos/checkout', ['items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty, 'price' => (float) ($sale ?? $p->price)]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('p', true)] + ($credit ? ['customer_id' => $this->company->id, 'credit' => true] : []))->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    private function statement(string $period = '2027-02'): array
    {
        return Boutiques::statement($this->shop->id, $this->lama->id, $period);
    }

    private function payable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_payable'), 3) + 0.0;
    }

    private function ties(string $why): void
    {
        $this->assertSame(number_format(BoutiqueMovements::balance($this->shop->id, $this->lama->id)['net'], 3), number_format(BoutiqueMovements::effects($this->shop->id, $this->lama->id), 3), $why);
    }

    /* ═══════════ مطفأةٌ حتّى يومها ═══════════ */

    public function test_without_its_day_nothing_changes_even_with_an_agreement_written(): void
    {
        $this->agree();
        $item = OrderItem::where('order_id', $this->sell($this->ring, 1, 10)->id)->sole();
        $this->assertSame([null, null], [$item->boutique_agreed_price, $item->boutique_policy]);
        $this->assertSame(8.0, $this->statement()['net'], 'القاعدةُ القائمة: من مبلغ البند');
        $this->sell($this->ring, 1, null, credit: true);
        $this->assertSame(17.6, $this->statement()['net'], 'والآجلُ يدخل عند البيع كما كان');
        $this->assertFalse((bool) BoutiqueComponentDue::query()->where('waits', true)->exists());
        $this->assertSame(0, DB::table('businesses')->whereNotNull('boutique_policy_from')->count(), 'ولا متجرَ له يومُها');
    }

    public function test_the_day_reaches_only_what_is_sold_after_it(): void
    {
        $this->agree();
        $before = $this->sell($this->ring, 1, 10);
        $this->on('2027-02-11');
        Carbon::setTestNow('2027-02-11 09:00:00');
        $after = $this->sell($this->ring, 1, 10);
        $this->assertSame([null, 12.0], [OrderItem::where('order_id', $before->id)->value('boutique_agreed_price'), (float) OrderItem::where('order_id', $after->id)->value('boutique_agreed_price')]);
        $this->assertSame(17.6, $this->statement()['net'], 'الأولى 8.000 كما بيعت، والثانية 9.600');
    }

    public function test_a_day_in_the_future_is_not_yet_on(): void
    {
        $this->on('2027-03-01');
        $this->agree(12, '2027-02-01');
        $item = OrderItem::where('order_id', $this->sell($this->ring, 1, null, credit: true)->id)->sole();
        $this->assertSame([null, null], [$item->boutique_agreed_price, $item->boutique_policy]);
        $this->assertSame(9.6, $this->statement()['net'], 'آجلٌ قبل يومها يدخل عند البيع كما كان');
    }

    /* ═══════════ السعرُ المتّفق عليه ═══════════ */

    public function test_a_discount_is_the_shops_and_an_overage_is_the_shops(): void
    {
        $this->on();
        $this->agree();
        // تخفيضُ البطاقة في الصندوق: 10.8، والمتّفق عليه 12 — للبوتيك 9.600، والخصمُ 1.2 على المتجر
        $this->sell($this->ring, 1, 10.8);
        // وموظّفٌ كتب 14 في فاتورة عميلٍ مدفوعة: الزيادةُ 2 للمتجر
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->ring->id, 'description' => 'خاتم', 'quantity' => 1, 'unit_price' => 14]]));
        CustomerPayments::record($this->shop->id, $this->company, 14, ['method' => 'نقدي'], [$invoice->id => 14]);
        $s = $this->statement();
        // 10.8 + 14 = 24.8؛ المتّفق عليه 24: للبوتيك 19.2، والفرقُ 0.8 للمتجر
        $this->assertSame([24.8, 0.8, 4.8, 19.2], [$s['gross'], $s['adjustment'], $s['commission'], $s['net']]);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertSame([24.8, 0.8, 4.8, 19.2], [(float) $sheet->gross, (float) $sheet->adjustment, (float) $sheet->commission, (float) $sheet->net]);
        $this->assertSame(19.2, $this->payable());
        $this->ties('خصمٌ وزيادة');
    }

    public function test_a_product_without_an_agreement_keeps_the_rule_it_had(): void
    {
        $this->on();
        $this->sell($this->ring, 1, 10);
        $this->assertSame(8.0, $this->statement()['net'], 'تخفيضُ البطاقة على البوتيك بحصّته كما اليوم');
        $this->assertNull(OrderItem::sole()->boutique_agreed_price);
    }

    public function test_a_new_agreement_is_a_new_line_and_an_offer_ends(): void
    {
        $this->on();
        $this->agree(12, '2027-02-01');
        $this->agree(9, '2027-02-12', '2027-02-14');
        $this->agree(11, '2027-02-20');
        // وعرضٌ له نهايةٌ يغلب سعرًا دائمًا بدأ في أثنائه
        $this->agree(10, '2027-02-25', '2027-02-27');
        $this->agree(13, '2027-02-26');
        $price = fn (string $day) => BoutiquePolicy::agreed($this->shop->id, $this->ring, null, Carbon::parse($day));
        $this->assertSame([12.0, 9.0, 12.0, 11.0, 10.0, 13.0], [$price('2027-02-11'), $price('2027-02-13'), $price('2027-02-15'), $price('2027-02-21'), $price('2027-02-26'), $price('2027-02-28')]);
        // ويسري يومَ بدايته ويومَ نهايته نفسَيهما
        $this->assertSame([9.0, 9.0, 11.0, 10.0], [$price('2027-02-12'), $price('2027-02-14'), $price('2027-02-20'), $price('2027-02-27')]);
        $this->expectException(\LogicException::class);
        BoutiquePriceAgreement::first()->update(['price' => 1]);
    }

    public function test_an_invoice_line_takes_its_share_from_the_agreed_price(): void
    {
        $this->on();
        $this->agree();
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->ring->id, 'description' => 'خاتم', 'quantity' => 2, 'unit_price' => 10]]));
        $line = BoutiqueInvoiceLine::sole();
        $this->assertSame([20.0, 12.0, 4.8, 19.2], [(float) $line->gross, (float) $line->agreed_price, (float) $line->commission, (float) $line->net]);
        CustomerInvoices::creditNote($invoice->fresh(), 10, 0, 'مرتجع', null, items: BoutiqueInvoiceDues::noteItems($invoice, ['i'.$line->customer_invoice_item_id => ['quantity' => 1]]), basis: CustomerCreditNote::RETURN);
        $this->assertSame(9.6, round((float) $line->fresh()->net - (float) $line->fresh()->returned_net, 3));
        CustomerPayments::record($this->shop->id, $this->company, 10, ['method' => 'نقدي'], [$invoice->id => 10]);
        $s = $this->statement();
        $this->assertSame([10.0, -2.0, 2.4, 9.6], [$s['gross'], $s['adjustment'], $s['commission'], $s['net']]);
    }

    public function test_a_return_after_the_sheet_gives_back_the_agreed_share_to_the_baisa(): void
    {
        $this->on();
        $this->agree(12.335);
        $order = $this->sell($this->ring, 3, 10.555);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $item = OrderItem::where('order_id', $order->id)->sole();
        OrderCorrection::setQuantity($order->fresh(), $item, 2, 'نقص', 'refunded', 'نقدي');
        OrderCorrection::setQuantity($order->fresh(), $item->fresh(), 1, 'نقص', 'refunded', 'نقدي');
        DB::transaction(fn () => OrderCorrection::cancel($order->fresh(), 'أُلغي', null, [$item->id => ['sellable' => 1, 'damaged' => 0]]));
        foreach (BoutiqueReturn::where('status', BoutiqueReturn::AWAITING)->get() as $n) {
            BoutiqueReturns::receive($n, (float) $n->quantity, 0, 'عادت');
        }
        $this->assertSame(number_format((float) $sheet->net, 3), number_format((float) BoutiqueReturn::sum('amount'), 3));
        $this->assertSame(0.0, $this->payable());
        $this->ties('مرتجعٌ بعد الورقة');
    }

    /* ═══════════ الحصّةُ تنتظر التحصيل ═══════════ */

    public function test_a_credit_sale_waits_for_its_whole_monthly_invoice(): void
    {
        $this->on();
        $this->agree();
        $a = $this->sell($this->ring, 1, null, credit: true);
        $b = $this->sell($this->bundle, 1, null, credit: true);
        $this->assertSame([0.0, 0.0], [$this->statement()['net'], $this->statement()['components']['net']]);
        $this->assertTrue(BoutiqueComponentDue::sole()->waits);

        $monthly = CustomerInvoices::consolidate($this->company->fresh(), [$a->id, $b->id]);
        CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$monthly->id => 12]);
        $this->assertSame(0.0, $this->statement()['net'], 'فاتورةُ الشهر مدفوعةٌ جزئيًّا: لا يُطفئ المدفوعُ أقدمَ طلباتها');
        CustomerPayments::record($this->shop->id, $this->company, 30, ['method' => 'نقدي'], [$monthly->id => 30]);
        $this->assertSame([9.6, 12.0], [$this->statement()['net'], $this->statement()['components']['net']]);
        $this->ties('فاتورة الشهر');
    }

    public function test_a_cash_sale_is_collected_at_once_and_a_balance_left_on_it_waits(): void
    {
        $this->on();
        $this->agree();
        $cash = $this->sell($this->ring, 2);
        $this->sell($this->bundle);
        $this->assertSame([19.2, 12.0], [$this->statement()['net'], $this->statement()['components']['net']]);
        // والقسمُ بالقاعدة نفسِها: المتّفقُ عليه × الكمّيّة
        $sum = BoutiqueSales::summary($this->shop->id, ['period' => '2027-02', 'boutique' => $this->lama->id, 'type' => null, 'settle' => null, 'state' => null, 'q' => '', 'page' => 1]);
        $this->assertSame([19.2, 19.2], [$sum['standalone']['net'], $sum['settlement']['pending']['standalone']]);
        // ومدفوعةٌ بقي عليها شيء: لم يكتمل تحصيلُها
        DB::table('orders')->where('id', $cash->id)->update(['balance_due' => 1]);
        $this->assertSame(0.0, $this->statement()['net'] + 0.0);
        $sum = BoutiqueSales::summary($this->shop->id, ['period' => '2027-02', 'boutique' => $this->lama->id, 'type' => null, 'settle' => null, 'state' => null, 'q' => '', 'page' => 1]);
        $this->assertSame([0.0, 19.2], [$sum['settlement']['pending']['standalone'] + 0.0, $sum['standalone']['awaiting_net']]);
    }

    /** قطعةُ بيعٍ بالسياسة يُنقص طلبُها قبل تحصيله: عكسُها ينتظر مثلها — لا يُحسب ناقصًا */
    public function test_a_waiting_piece_and_its_reversal_wait_together(): void
    {
        $this->on();
        $order = $this->sell($this->bundle, 2, null, credit: true);
        OrderCorrection::setQuantity($order->fresh(), OrderItem::where('order_id', $order->id)->sole(), 1, 'نقص', 'refunded', 'نقدي');
        $this->assertSame([true], BoutiqueComponentDue::pluck('waits')->unique()->values()->all());
        $this->assertSame(0.0, $this->statement()['components']['net'] + 0.0);
    }

    /** قطعتان في ورقة، أُلغي تحصيلُ إحداهما: تُسدَّد الأخرى، وتبقى المعلّقة دَينًا حتّى تُسدَّد */
    public function test_pieces_are_paid_without_the_one_whose_collection_was_cancelled(): void
    {
        $this->on();
        $a = $this->sell($this->bundle, 1, null, credit: true);
        $b = $this->sell($this->bundle, 1, null, credit: true);
        $first = CustomerInvoices::consolidate($this->company->fresh(), [$a->id]);
        $second = CustomerInvoices::consolidate($this->company->fresh(), [$b->id]);
        $pay = CustomerPayments::record($this->shop->id, $this->company, 30, ['method' => 'نقدي'], [$first->id => 30]);
        CustomerPayments::record($this->shop->id, $this->company, 30, ['method' => 'نقدي'], [$second->id => 30]);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertSame(24.0, (float) $sheet->components_net);
        CustomerPayments::cancel($pay, 'خطأ');
        // معلّقةٌ في ورقتها، لا «تنتظر التحصيل» قبل ورقة
        $this->artisan('boutiques:policy', ['--business' => $this->shop->id])->expectsOutputToContain('ينتظر التحصيل: 0 بندًا و0 قطعة')->assertSuccessful();

        BoutiqueDues::pay($sheet->fresh(), 'نقدي');
        $this->assertSame(12.0, round((float) Transaction::where('kind', Transaction::BOUTIQUE_PIECES)->sum('amount'), 3));
        $hold = BoutiqueSheetHold::whereNotNull('boutique_component_due_id')->sole();
        $this->assertSame([12.0, 12.0], [(float) $hold->withheld_amount, $this->payable()]);
        $this->ties('سُدّدت دون المعلّقة');
        CustomerPayments::record($this->shop->id, $this->company, 30, ['method' => 'نقدي'], [$first->id => 30]);
        BoutiqueHolds::pay($hold->fresh(), 'نقدي');
        $this->assertSame(0.0, $this->payable());
        $this->ties('سُدّدت المعلّقة');
    }

    public function test_a_collection_cancelled_after_the_sheet_holds_the_order_line_and_its_piece(): void
    {
        $this->on();
        $this->agree();
        $a = $this->sell($this->ring, 1, null, credit: true);
        $b = $this->sell($this->bundle, 1, null, credit: true);
        $cash = $this->sell($this->ring);
        $monthly = CustomerInvoices::consolidate($this->company->fresh(), [$a->id, $b->id]);
        $payment = CustomerPayments::record($this->shop->id, $this->company, 42, ['method' => 'نقدي'], [$monthly->id => 42]);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertSame([19.2, 12.0], [(float) $sheet->net, (float) $sheet->components_net]);

        CustomerPayments::cancel($payment, 'خطأ');
        $this->assertSame([9.6, 12.0], [BoutiqueHolds::heldOn($sheet->id), BoutiqueHolds::heldPieces($sheet->id)]);
        // يُسدَّد بيعُ النقد وحده، والقطعةُ كلُّها معلّقة فلا يُسدَّد جزؤها
        $this->post("/admin/expenses/{$sheet->expense_id}/paid", ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame(9.6, round((float) Expense::find($sheet->expense_id)->transaction->amount, 3));
        $this->post(route('admin.boutiques.components.pay', [$this->lama->id, $sheet->id]), ['method' => 'نقدي'])->assertSessionHasErrors('components');
        $this->assertSame(21.6, $this->payable(), 'ما عُلّق باقٍ في 2160');
        $this->ties('عُلّق');

        CustomerPayments::record($this->shop->id, $this->company, 42, ['method' => 'نقدي'], [$monthly->id => 42]);
        $this->assertSame(0.0, BoutiqueHolds::heldPieces($sheet->id));
        BoutiqueDues::pay($sheet->fresh(), 'نقدي');
        BoutiqueHolds::pay(BoutiqueSheetHold::whereNotNull('order_item_id')->sole(), 'نقدي');
        $this->assertSame(0.0, $this->payable());
        $this->ties('أُعيد وسُدّد');
    }

    /* ═══════════ والكودُ الأقدم ═══════════ */

    public function test_older_code_can_neither_carry_a_waiting_line_nor_pay_its_pieces(): void
    {
        $this->on();
        $item = OrderItem::where('order_id', $this->sell($this->ring)->id)->sole();
        $due = BoutiqueComponentDue::where('order_id', $this->sell($this->bundle)->id)->sole();
        // ورقةٌ كتبها كودٌ لا يقرأ التحصيل
        $old = BoutiqueSettlement::create(['business_id' => $this->shop->id, 'boutique_id' => $this->lama->id, 'number' => 'BQ-OLD', 'period' => '2027-02',
            'covered_from' => now(), 'covered_to' => now(), 'gross' => 12, 'commission' => 2.4, 'net' => 9.6, 'lines_count' => 1, 'kind' => 'settlement', 'issued_at' => now()]);
        $this->assertSame(0, OrderItem::whereKey($item->id)->update(['boutique_settlement_id' => $old->id]));
        $this->assertSame(0, BoutiqueComponentDue::whereKey($due->id)->update(['boutique_settlement_id' => $old->id]));
        $this->assertNull($item->fresh()->boutique_settlement_id);

        $new = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertSame([(int) $new->id, (int) $new->id], [(int) $item->fresh()->boutique_settlement_id, (int) $due->fresh()->boutique_settlement_id]);
        $this->expectException(QueryException::class);
        DB::table('boutique_settlements')->where('id', $new->id)->update(['components_paid_at' => now()]);
    }

    public function test_older_code_cannot_mark_a_sheet_with_a_waiting_line_paid(): void
    {
        $this->on();
        $this->sell($this->ring);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $sheet->expense_id)->update(['status' => Expense::PAID]);
    }

    public function test_the_migration_is_not_undone_once_something_was_sold_by_it(): void
    {
        $this->on();
        $this->sell($this->ring);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_12_05_090000_a_boutique_share_follows_the_agreed_price_and_waits_for_its_collection.php'))->down();
    }

    public function test_the_check_reads_and_writes_nothing(): void
    {
        $count = fn () => [DB::table('businesses')->whereNotNull('boutique_policy_from')->count(), BoutiquePriceAgreement::count(), DB::table('order_items')->count()];
        $before = $count();
        $this->artisan('boutiques:policy', ['--json' => true])->assertSuccessful();
        $this->assertSame($before, $count());
        $this->on();
        $this->sell($this->ring, 1, null, credit: true);
        $this->sell($this->bundle, 1, null, credit: true);
        $this->artisan('boutiques:policy', ['--business' => $this->shop->id])
            ->expectsOutputToContain('السياسة من 2027-02-01 · بلا سعر متفق عليه: 1 · بيع بها: 1 · ينتظر التحصيل: 1 بندًا و1 قطعة')->assertSuccessful();
    }

    /* ═══════════ من يكتب الاتّفاق ═══════════ */

    public function test_an_agreement_is_written_by_who_issues_sheets_for_this_boutiques_own_products(): void
    {
        $url = route('admin.boutiques.agreements.store', $this->lama->id);
        $row = ['product_id' => $this->ring->id, 'price' => 12, 'starts_on' => '2027-02-01', 'reference' => 'ع'];
        $reader = User::create(['business_id' => $this->shop->id, 'name' => 'قارئ', 'email' => 'r@pol.om', 'password' => bcrypt('x'), 'role' => 'custom', 'status' => 'نشط', 'permissions' => ['products', 'expenses']]);
        $this->actingAs($reader)->post($url, $row)->assertForbidden();
        $this->actingAs($this->owner)->post($url, ['product_id' => $this->bundle->id] + $row)->assertSessionHasErrors('product_id');
        $this->actingAs($this->owner)->post($url, $row)->assertSessionHasNoErrors();
        $this->assertSame(1, BoutiquePriceAgreement::count());
    }
}
