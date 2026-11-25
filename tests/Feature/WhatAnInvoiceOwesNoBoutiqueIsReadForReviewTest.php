<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueInvoiceReview;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\WhatsAppOrderCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مراجعةُ ما بيع في فواتير العملاء من أصناف البوتيك ولم يدخل حسابَه — قراءةٌ لا تكتب ولا تحسب.
 *
 * لا مستحقَّ ولا عمولة: صاحبُ الصنف ونسبتُه يومَ الفاتورة لم يُحفظا، فلا يُضرب
 * ثمنٌ في نسبة اليوم. ويُعرض ما كُتب، ومعه قرينةٌ من لقطةٍ محفوظة.
 */
class WhatAnInvoiceOwesNoBoutiqueIsReadForReviewTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $mine;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        [$this->shop, $this->owner] = $this->openShop('متجر', 'o@review.om');
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $this->silver = $this->product($this->shop, 'قطعة فضّة', 12, $this->lama->id);
        $this->mine = $this->product($this->shop, 'باقة المتجر', 20);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User} */
    private function openShop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return [$shop, User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط'])];
    }

    private function product(Business $shop, string $name, float $price, ?int $boutique = null): Product
    {
        return Product::create(['business_id' => $shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $boutique]);
    }

    /** @param list<array{0: Product, 1?: int, 2?: float}> $lines */
    private function invoice(array $lines, string $day = '2027-02-10', bool $issue = true, ?Customer $customer = null, bool $old = true): CustomerInvoice
    {
        $customer ??= $this->company;
        $invoice = CustomerInvoices::create((int) $customer->business_id, $customer, ['issued_at' => $day], array_map(fn ($l) => [
            'product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1] ?? 1, 'unit_price' => (float) $l[0]->price, 'discount' => $l[2] ?? 0,
        ], $lines));

        if (! $issue) {
            return $invoice;
        }

        $issued = CustomerInvoices::issue($invoice);
        // صدرت قبل أن تُحفظ اللقطة — وهي ما تُراجَع. وما له لقطةٌ محسوبٌ وخارجٌ (اختبارُه أدناه)
        if ($old) {
            BoutiqueInvoiceLine::where('customer_invoice_id', $issued->id)->delete();
        }

        return $issued;
    }

    private function sell(Product $p, array $extra = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', $extra + [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1, 'price' => (float) $p->price]],
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('rv', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function review(array $params = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->getJson(route('admin.boutiques.invoiceReview', $params));
    }

    /* ═══════════ ما يُعرض وما لا يُعرض ═══════════ */

    public function test_only_an_issued_invoice_without_an_order_is_listed_as_it_was_written(): void
    {
        $this->invoice([[$this->silver, 2, 1.0], [$this->mine]]);
        $this->invoice([[$this->silver]], issue: false);                                  // مسودّة
        CustomerInvoices::cancel($this->invoice([[$this->silver]]), 'أُلغيت');             // ملغاة
        $this->invoice([[$this->mine]]);                                                  // بلا بوتيك
        $this->sell($this->silver, ['credit' => true, 'customer_id' => $this->company->id]); // لها طلبٌ محسوب

        $r = $this->review()->assertOk()->json();
        $this->assertEquals(['lines' => 1, 'invoices' => 1, 'amount' => 23.0], $r['summary']);
        $row = $r['rows'][0];
        $this->assertSame(['قطعة فضّة', 'manual', 'unpaid', 'بوتيك لمى'], [$row['description'], $row['source'], $row['payment'], $row['boutique_today']]);
        $this->assertEquals([2.0, 12.0, 1.0, 23.0], [$row['quantity'], $row['unit_price'], $row['discount'], $row['amount']]);
        // ولا مستحقَّ ولا عمولةَ ولا نسبةَ اليوم
        $json = json_encode($r);
        foreach (['commission', 'owed', 'net', 'due', 'profit'] as $word) {
            $this->assertStringNotContainsString('"'.$word.'"', $json);
        }
    }

    /** وما حُفظت لقطتُه محسوبٌ في حساب بوتيكه — لا يُعرض للمراجعة */
    public function test_an_invoice_that_keeps_its_boutique_is_not_listed(): void
    {
        $this->invoice([[$this->silver, 2]], old: false);
        $this->assertSame(0, $this->review()->assertOk()->json('summary.lines'));
    }

    public function test_a_monthly_paper_over_orders_is_not_listed(): void
    {
        $order = $this->sell($this->silver, ['credit' => true, 'customer_id' => $this->company->id]);
        CustomerInvoices::cancel(CustomerInvoice::where('business_id', $this->shop->id)->sole(), 'تُجمع');
        CustomerInvoices::consolidate($this->company, [$order->id]);
        $this->assertSame(0, $this->review()->assertOk()->json('summary.lines'));
    }

    public function test_payment_state_and_credit_notes_are_read_and_a_credit_is_not_called_paid(): void
    {
        $paid = $this->invoice([[$this->silver]]);
        CustomerPayments::record($this->shop->id, $this->company, 12.0, ['method' => 'نقدي'], [$paid->id => 12.0]);
        $partial = $this->invoice([[$this->silver]]);
        CustomerPayments::record($this->shop->id, $this->company, 5.0, ['method' => 'نقدي'], [$partial->id => 5.0]);
        $credited = $this->invoice([[$this->silver]]);
        CustomerInvoices::creditNote($credited, 12.0, 0.0, 'رُدّت');
        $whatsapp = $this->invoice([[$this->silver]], issue: false);
        $whatsapp->forceFill(['form_token' => WhatsAppOrderCheckout::FORM_TOKEN.'9'])->save();
        CustomerInvoices::issue($whatsapp);
        BoutiqueInvoiceLine::where('customer_invoice_id', $whatsapp->id)->delete();

        $by = collect($this->review()->assertOk()->json('rows'))->keyBy('invoice_id');
        $this->assertSame('paid', $by[$paid->id]['payment']);
        $this->assertSame('partial', $by[$partial->id]['payment']);
        $this->assertSame('credited', $by[$credited->id]['payment']);
        $this->assertEquals(12.0, $by[$credited->id]['credited']);
        $this->assertSame(['whatsapp', 'unpaid'], [$by[$whatsapp->id]['source'], $by[$whatsapp->id]['payment']]);
    }

    /* ═══════════ القرينة — لقطةٌ محفوظة لا نسبةُ اليوم ═══════════ */

    public function test_the_evidence_is_a_sale_kept_before_the_invoice_and_never_todays_rate_or_owner(): void
    {
        $early = $this->invoice([[$this->silver]]);                     // لا بيعَ قبلها
        Carbon::setTestNow('2027-02-11 09:00:00');
        $order = $this->sell($this->silver);                            // لمى بـ٢٠٪
        Carbon::setTestNow('2027-02-12 09:00:00');
        $mid = $this->invoice([[$this->silver]], '2027-02-12');
        // ثمّ تغيّر كلُّ شيء: النسبةُ والصاحبُ والثمن
        $this->lama->update(['commission_rate' => 70]);
        $this->silver->update(['boutique_id' => $this->reem->id, 'price' => 99]);
        Carbon::setTestNow('2027-02-13 09:00:00');
        $this->sell($this->silver->fresh());                            // ريم بـ١٠٪ — بعد الفاتورتين

        $by = collect($this->review()->assertOk()->json('rows'))->keyBy('invoice_id');
        $this->assertNull($by[$early->id]['evidence'], 'أُخذت لقطةٌ بعد الفاتورة');
        $this->assertEquals(['boutique' => 'بوتيك لمى', 'rate' => 20.0, 'order' => $order->number, 'at' => '2027-02-11'], $by[$mid->id]['evidence']);
        $this->assertSame('بوتيك ريم', $by[$mid->id]['boutique_today'], 'بوتيكُ اليوم يُسمّى باسمه «اليوم»');
        $this->assertEquals(12.0, $by[$mid->id]['amount'], 'المبلغُ كما كُتب لا بثمن اليوم');
    }

    /** صنفٌ نُزع من بوتيكه بعد بيعه يبقى في المراجعة — بلقطةٍ تقول إنّه بيع له يومًا */
    public function test_a_piece_taken_back_from_its_boutique_is_still_listed(): void
    {
        $this->sell($this->silver);
        Carbon::setTestNow('2027-02-10 11:00:00');
        $this->invoice([[$this->silver]]);
        $this->silver->update(['boutique_id' => null]);

        $row = $this->review(['boutique' => $this->lama->id])->assertOk()->json('rows.0');
        $this->assertNull($row['boutique_today']);
        $this->assertSame('بوتيك لمى', $row['evidence']['boutique']);
        $this->assertSame(0, $this->review(['boutique' => $this->reem->id])->json('summary.lines'));
    }

    public function test_a_cancelled_or_held_sale_is_no_evidence(): void
    {
        $order = $this->sell($this->silver);
        $order->update(['status' => Order::CANCELLED]);
        Carbon::setTestNow('2027-02-10 12:00:00');
        $this->invoice([[$this->silver]]);
        $this->assertNull($this->review()->json('rows.0.evidence'));
    }

    /* ═══════════ المُرشِّحات والصفحات ═══════════ */

    public function test_month_boutique_and_pages_narrow_on_the_server_with_a_fixed_number_of_queries(): void
    {
        $perfume = $this->product($this->shop, 'عطر', 5, $this->reem->id);
        foreach (range(1, 27) as $_) {
            $this->invoice([[$this->silver]]);
        }
        $this->invoice([[$perfume]], '2027-01-15');

        $this->assertSame(28, $this->review()->json('summary.lines'));
        $this->assertSame(1, $this->review(['period' => '2027-01'])->json('summary.lines'));
        $this->assertSame(27, $this->review(['boutique' => $this->lama->id])->json('summary.lines'));
        $this->assertSame([25, 2], [count($this->review()->json('rows')), $this->review()->json('last_page')]);
        $this->assertCount(3, $this->review(['page' => 2])->json('rows'));
        $this->assertSame([], $this->review(['summary' => 1])->json('rows'));

        $count = function (int $page): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            BoutiqueInvoiceReview::rows($this->shop->id, ['period' => null, 'boutique' => null, 'page' => $page]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->assertSame($count(2), $count(1), 'استعلامٌ لكلّ صفّ');
    }

    /* ═══════════ العزلُ والإذن ═══════════ */

    public function test_another_shops_invoices_and_boutiques_are_never_read(): void
    {
        [$other, $stranger] = $this->openShop('الجار', 'n@review.om');
        $theirs = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيك الجار', 'commission_rate' => 30, 'active' => true]);
        $piece = $this->product($other, 'قطعة الجار', 50, $theirs->id);
        $theirCustomer = Customer::create(['language' => 'ar', 'business_id' => $other->id, 'name' => 'عميل الجار']);
        $this->invoice([[$piece]], customer: $theirCustomer);
        $this->invoice([[$this->silver]]);

        $mine = $this->review()->assertOk()->json();
        $this->assertEquals(['lines' => 1, 'invoices' => 1, 'amount' => 12.0], $mine['summary']);
        $this->assertStringNotContainsString('الجار', json_encode($mine, JSON_UNESCAPED_UNICODE));
        // بوتيكُ الجار لا يُرشَّح به ولا يُعرف أموجودٌ هو
        $this->assertEquals($mine['summary'], $this->review(['boutique' => $theirs->id])->json('summary'));
        $this->assertEquals(50.0, $this->review([], $stranger)->json('summary.amount'));
    }

    public function test_only_who_is_trusted_with_money_reads_it_and_opening_the_invoice_is_its_own_permission(): void
    {
        $this->invoice([[$this->silver]]);
        $staff = fn (array $permissions, string $mail) => User::create([
            'business_id' => $this->shop->id, 'name' => $mail, 'email' => $mail, 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'نشط', 'permissions' => $permissions,
        ]);
        $products = $staff(['products'], 'p@review.om');
        $accountant = $staff(['products', 'expenses'], 'a@review.om');

        $this->review([], $products)->assertForbidden();
        $rows = $this->review([], $accountant)->assertOk()->json('rows');
        $this->assertNull($rows[0]['url'], 'رابطُ الفاتورة لمن يملك قسمها');
        $this->assertNotNull($this->review()->json('rows.0.url'));

        // ومتجرٌ لا بوتيكَ عنده لا بابَ له
        $this->shop->forceFill(['boutiques_enabled' => false])->save();
        Boutique::where('business_id', $this->shop->id)->delete();
        $this->review()->assertNotFound();
    }

    /** وكاتبُ الفاتورة يُخبَر ببوتيك الصنف — اسمًا لا نسبةً، ولمتجرٍ بلا بوتيك لا شيء */
    public function test_who_writes_an_invoice_is_told_the_boutiques_name_and_never_its_rate(): void
    {
        $products = collect($this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))->assertOk()->viewData('page')['props']['products'])->keyBy('id');
        $this->assertSame('بوتيك لمى', $products[$this->silver->id]['boutique']);
        $this->assertNull($products[$this->mine->id]['boutique']);
        $this->assertSame([], array_values(array_intersect(array_keys($products[$this->silver->id]), ['boutique_id', 'rate', 'commission_rate', 'cost'])));
    }

    /* ═══════════ لا تكتب ═══════════ */

    public function test_reading_writes_nothing(): void
    {
        $this->sell($this->silver);
        $this->invoice([[$this->silver, 3]]);
        $tables = ['orders', 'order_items', 'customer_invoices', 'customer_invoice_items', 'boutique_settlements', 'boutique_component_dues', 'boutique_returns', 'expenses', 'journal_entries', 'journal_lines', 'products', 'inventory_movements'];
        $print = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => hash('sha256', DB::table($t)->orderBy('id')->get()->toJson())])->all();

        $before = $print();
        $this->review()->assertOk();
        $this->review(['boutique' => $this->lama->id, 'period' => '2027-02', 'page' => 1])->assertOk();
        $this->assertSame($before, $print());
    }

    /* ═══════════ الجرد: المحتملُ غيرُ المسجَّل ═══════════ */

    /** بيعٌ محفوظٌ في يومٍ بنسبةٍ بعينها — يُكتب بها لقطتُه كما يكتبها الصندوق */
    private function soldOn(string $day, Product $p, ?float $rate = null): void
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow($day.' 12:00:00');
        $was = $p->boutique_id === null ? null : (float) Boutique::find($p->boutique_id)->commission_rate;
        if ($rate !== null && $was !== null) {
            Boutique::whereKey($p->boutique_id)->update(['commission_rate' => $rate]);
        }
        $this->sell($p->fresh());
        if ($rate !== null && $was !== null) {
            Boutique::whereKey($p->boutique_id)->update(['commission_rate' => $was]);
        }
        Carbon::setTestNow($now);
    }

    private function collect(CustomerInvoice $invoice): void
    {
        CustomerPayments::record((int) $invoice->business_id, $this->company, (float) $invoice->total, ['method' => 'نقدي'], [$invoice->id => (float) $invoice->total]);
    }

    /** لا يُحسب إلّا ما حاصرته لقطتان متطابقتان — وبنسبةِ يومه لا بنسبة اليوم */
    public function test_the_dry_run_works_out_only_what_two_equal_sales_bracket_at_their_rate(): void
    {
        $this->soldOn('2027-01-05', $this->silver, 25);
        $bracketed = $this->invoice([[$this->silver, 2, 1.0]], '2027-01-10');
        DB::table('customer_invoices')->where('id', $bracketed->id)->update(['issued_by_at' => '2027-01-10 10:00:00']);
        $this->collect($bracketed);
        $this->soldOn('2027-01-20', $this->silver, 25);

        $r = BoutiqueInvoiceReview::dryRun((int) $this->shop->id);
        // 2 × 12 = 24 قبل الخصم، ونسبةُ يومها 25٪ (واليومَ 20٪): عمولة 6 وصافٍ 18
        $this->assertEquals(['lines' => 1, 'gross' => 24.0, 'commission' => 6.0, 'net' => 18.0], array_diff_key($r['reliable'], ['boutiques' => 1]));
        $this->assertEquals(18.0, $r['reliable']['boutiques']['بوتيك لمى']['net']);
        $this->assertSame([1, 1, 2.0, 23.0], [$r['lines'], $r['invoices'], $r['quantity'], $r['amount']]);
        $this->assertSame([['قطعة فضّة', 18.0, 0]], array_map(fn ($p) => [$p['product'], $p['reliable_net'], $p['manual_lines']], $r['products']));
        $this->assertSame(0, array_sum(array_column($r['manual'], 'lines')));
    }

    /** وكلُّ ما عداه يُعدّ بسببه ولا يُحسب له شيء */
    public function test_the_dry_run_counts_by_reason_what_it_will_not_guess(): void
    {
        $issued = function (array $lines, string $at) {
            $invoice = $this->invoice($lines, substr($at, 0, 10));
            DB::table('customer_invoices')->where('id', $invoice->id)->update(['issued_by_at' => $at]);

            return $invoice;
        };
        // لا بيعَ قبلها
        $this->collect($issued([[$this->silver]], '2027-01-02 10:00:00'));
        $this->soldOn('2027-01-05', $this->silver, 25);
        // محاصَرةٌ لكن لم يكتمل تحصيلُها
        $issued([[$this->silver]], '2027-01-10 10:00:00');
        // محاصَرةٌ محصَّلةٌ وعليها إشعارُ دائن
        $credited = $issued([[$this->silver, 2]], '2027-01-11 10:00:00');
        CustomerInvoices::creditNote($credited, 12.0, 0.0, 'خصم');
        CustomerPayments::record((int) $this->shop->id, $this->company, 12.0, ['method' => 'نقدي'], [$credited->id => 12.0]);
        $this->soldOn('2027-01-20', $this->silver, 25);
        // النسبةُ تغيّرت بين ما قبلها وما بعدها
        $this->collect($issued([[$this->silver]], '2027-01-22 10:00:00'));
        $this->soldOn('2027-01-25', $this->silver, 30);
        // ولا بيعَ بعدها
        $this->collect($issued([[$this->silver]], '2027-02-01 10:00:00'));
        // وكان للمتجر قبلها وبعدها — لا بوتيكَ فيه يومَها
        $vase = $this->product($this->shop, 'مزهرية', 5);
        $this->soldOn('2027-01-05', $vase);
        $this->collect($issued([[$vase]], '2027-01-10 10:00:00'));
        $this->soldOn('2027-01-20', $vase);
        $vase->update(['boutique_id' => $this->reem->id]);

        $r = BoutiqueInvoiceReview::dryRun((int) $this->shop->id);
        $this->assertSame(0, $r['reliable']['lines']);
        $this->assertEquals(0.0, $r['reliable']['net']);
        $this->assertSame(['before_only' => 1, 'conflict' => 1, 'none' => 1, 'credited' => 1, 'unpaid' => 1], array_map(fn ($m) => $m['lines'], $r['manual']));
        $this->assertSame(['lines' => 1, 'amount' => 5.0], $r['shop']);
        $this->assertSame(6, $r['lines']);
    }

    /** لقطةُ بوتيكٍ بلا نسبة لا تقول كم له: تُراجَع بيد، ولا تُحسب «عمولة صفر» */
    public function test_the_dry_run_never_reads_a_snapshot_without_a_rate_as_no_commission(): void
    {
        $this->soldOn('2027-01-05', $this->silver, 25);
        $invoice = $this->invoice([[$this->silver]], '2027-01-10');
        DB::table('customer_invoices')->where('id', $invoice->id)->update(['issued_by_at' => '2027-01-10 10:00:00']);
        $this->collect($invoice);
        $this->soldOn('2027-01-20', $this->silver, 25);
        DB::table('order_items')->where('product_id', $this->silver->id)->update(['boutique_rate' => null]);

        $r = BoutiqueInvoiceReview::dryRun((int) $this->shop->id);
        $this->assertSame(0, $r['reliable']['lines']);
        $this->assertEquals(0.0, $r['reliable']['net']);
        $this->assertSame(1, $r['manual']['none']['lines']);
    }

    /** والجردُ لا يكتب حرفًا، ولا يخلط متجرًا بآخر، ولا يطبع عميلًا */
    public function test_the_dry_run_writes_nothing_and_prints_each_shop_alone_and_no_customer(): void
    {
        $this->soldOn('2027-01-05', $this->silver);
        $mine = $this->invoice([[$this->silver]], '2027-01-10');
        DB::table('customer_invoices')->where('id', $mine->id)->update(['issued_by_at' => '2027-01-10 10:00:00']);
        $this->collect($mine);
        $this->soldOn('2027-01-20', $this->silver);
        [$other] = $this->openShop('متجر آخر', 'y@review.om');
        Boutique::create(['business_id' => $other->id, 'name' => 'بوتيكهم', 'commission_rate' => 33.33, 'active' => true]);
        $this->openShop('بلا بوتيك', 'z@review.om');

        $tables = ['boutiques', 'boutique_settlements', 'boutique_returns', 'boutique_invoice_lines', 'boutique_invoice_returns', 'boutique_component_dues', 'customer_invoices', 'customer_invoice_items', 'customer_credit_notes', 'order_items', 'orders', 'expenses', 'journal_entries', 'journal_lines', 'products', 'activity_logs'];
        $hash = fn () => hash('sha256', json_encode(array_map(fn ($t) => DB::table($t)->orderBy('id')->get(), $tables)));
        $before = $hash();
        $writes = 0;
        DB::listen(function ($q) use (&$writes) {
            $writes += preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)/i', $q->sql);
        });

        \Illuminate\Support\Facades\Artisan::call('boutiques:old-invoices', ['--json' => true]);
        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertSame([(int) $this->shop->id, (int) $other->id], array_keys($report));
        $this->assertEquals([1, 9.6], [$report[$this->shop->id]['reliable']['lines'], $report[$this->shop->id]['reliable']['net']]);
        $this->assertSame(0, $report[$other->id]['lines']);

        \Illuminate\Support\Facades\Artisan::call('boutiques:old-invoices', ['--business' => $this->shop->id]);
        $text = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('9.600', $text);
        $this->assertStringNotContainsString($this->company->name, $text);
        $this->assertStringNotContainsString('متجر آخر', $text);

        $this->assertSame([$before, 0], [$hash(), $writes]);
    }

    /** ومن الشاشة حين يُطلب — لمن يقرأ المالَ وحده */
    public function test_the_estimate_is_asked_for_and_is_for_who_reads_the_money(): void
    {
        $this->invoice([[$this->silver]]);
        $this->assertNull($this->review()->assertOk()->json('estimate'));
        $this->assertSame(1, $this->review(['estimate' => 1])->assertOk()->json('estimate.manual.none.lines'));
        $seller = User::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'sel@review.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط']);
        $this->review(['estimate' => 1], $seller)->assertForbidden();
    }
}
