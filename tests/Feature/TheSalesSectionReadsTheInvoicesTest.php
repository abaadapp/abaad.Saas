<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueInvoiceDues;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\WhatsAppOrderCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قسمُ «مبيعات البوتيك وفواتيره» يقرأ فواتيرَ العملاء بلا طلب — صفوفًا وملخّصًا ونافذة.
 *
 * الحسبةُ في `BoutiqueInvoiceDues` (اختبارُها غيرُ هذا)؛ وهنا ما يُعرض منها ولمن، وأنّ ما
 * يُعرض هو ما في الكشف بالبيسة.
 */
class TheSalesSectionReadsTheInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $scent;

    private Product $mine;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@sect.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $this->silver = $this->product('قطعة فضّة', 6, $this->lama);
        $this->scent = $this->product('عطر', 10, $this->reem);
        $this->mine = $this->product('باقة المتجر', 20);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة سرّيّة', 'phone' => '92222222', 'allow_credit_sales' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Boutique $of = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $of?->id]);
    }

    private function mixed(float $discount = 0): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [
            ['product_id' => $this->silver->id, 'description' => 'قطعة فضّة', 'quantity' => 2, 'unit_price' => 6, 'discount' => $discount],
            ['product_id' => $this->scent->id, 'description' => 'عطر', 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => $this->mine->id, 'description' => 'باقة المتجر', 'quantity' => 1, 'unit_price' => 20],
        ]));
    }

    private function pay(CustomerInvoice $i, ?float $amount = null)
    {
        return CustomerPayments::record($this->shop->id, $this->company, $amount ?? $i->fresh()->outstanding(), ['method' => 'نقدي'], [$i->id => $amount ?? $i->fresh()->outstanding()]);
    }

    private function rows(array $f = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->getJson(route('admin.boutiques.sales', $f + ['period' => '2027-02']))->assertOk()->json();
    }

    private function summary(array $f = []): array
    {
        return $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.summary', $f + ['period' => '2027-02']))->assertOk()->json();
    }

    private function till(int $qty = 1): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => 'قطعة فضّة', 'qty' => $qty, 'price' => 6]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('t', true),
        ])->assertOk();
    }

    public function test_an_invoice_line_is_a_row_with_its_door_its_state_and_its_kept_numbers(): void
    {
        $this->till(3);
        $invoice = $this->mixed();
        $rows = collect($this->rows()['rows'])->keyBy(fn ($r) => $r['type'].$r['product']);
        $this->assertCount(3, $rows);
        $line = $rows['invoiceقطعة فضّة'];
        $this->assertSame([null, (int) $invoice->id, $invoice->number, 'فاتورة عميل', 'awaiting', 'active', false], [$line['order_id'], $line['invoice_id'], $line['order_number'], $line['channel'], $line['settle_state'], $line['invoice_state'], $line['void']]);
        $this->assertEquals([2, 6, 12, 20, 2.4, 9.6], [$line['quantity'], $line['unit_price'], $line['amount'], $line['rate'], $line['commission'], $line['net']]);
        $this->assertSame('pending', $rows['standaloneقطعة فضّة']['settle_state']);

        // المُرشِّحات: النوعُ والبوتيكُ والحالُ والبحث — من القاعدة
        $this->assertSame(2, $this->rows(['type' => 'invoice'])['total']);
        $this->assertSame(1, $this->rows(['type' => 'standalone'])['total']);
        $this->assertSame(0, $this->rows(['type' => 'bundle'])['total']);
        $this->assertSame(2, $this->rows(['settle' => 'awaiting'])['total']);
        $this->assertSame(1, $this->rows(['boutique' => $this->reem->id])['total']);
        $this->assertSame(2, $this->rows(['q' => $invoice->number])['total']);

        $this->pay($invoice);
        $this->assertSame([0, 3], [$this->rows(['settle' => 'awaiting'])['total'], $this->rows(['settle' => 'pending'])['total']]);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $done = collect($this->rows(['boutique' => $this->lama->id])['rows']);
        $this->assertSame(['issued', 'issued'], $done->pluck('settle_state')->all());
        $this->assertSame([$sheet->number], $done->pluck('settlement')->unique()->all());
    }

    public function test_a_whatsapp_order_says_its_door(): void
    {
        $invoice = $this->mixed();
        DB::table('customer_invoices')->where('id', $invoice->id)->update(['form_token' => WhatsAppOrderCheckout::FORM_TOKEN.'9']);
        $this->assertSame(['طلب واتساب'], collect($this->rows()['rows'])->pluck('channel')->unique()->values()->all());
    }

    /** صفوفُ الشهر وملخّصُه وكشفُه رقمٌ واحد — والمنتظِرُ خارج المستحقّ */
    public function test_the_summary_the_rows_and_the_statement_agree_and_what_waits_stays_out(): void
    {
        $this->till(3);
        $paid = $this->mixed();
        $this->pay($paid);
        $this->mixed();

        $s = $this->summary(['boutique' => $this->lama->id]);
        $this->assertEquals([1, 1, 12, 2.4, 9.6], [$s['invoices']['lines'], $s['invoices']['invoices'], $s['invoices']['gross'], $s['invoices']['commission'], $s['invoices']['net']]);
        $this->assertEquals(9.6, $s['invoices']['awaiting']['net']);
        // المنفردُ 14.400 + المحصَّلُ 9.600 — ولا يدخله ما ينتظر
        $this->assertEquals([14.4, 24.0], [$s['standalone']['net'], $s['owed']]);
        $statement = Boutiques::statement($this->shop->id, $this->lama->id, '2027-02');
        $this->assertEquals([24.0, 24.0], [$statement['net'], $s['settlement']['pending']['total']]);

        $net = collect($this->rows(['boutique' => $this->lama->id, 'settle' => 'pending'])['rows'])->sum('net');
        $this->assertEquals(24.0, round($net, 3));

        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $after = $this->summary(['boutique' => $this->lama->id]);
        $this->assertEquals([24.0, 0, 9.6], [(float) $sheet->net, $after['settlement']['pending']['total'], $after['invoices']['net']]);
        // وشاشةُ البوتيكات تقول الشيء نفسَه: المبيعُ 30 ولا شيءَ ينتظر
        $index = $this->actingAs($this->owner)->get(route('admin.boutiques.index', ['period' => '2027-02']))->viewData('page')['props']['boutiques'];
        $lama = collect($index)->firstWhere('id', $this->lama->id);
        $this->assertEquals([30.0, 0.0], [$lama['gross'], $lama['pending']]);
    }

    /**
     * مرتجعٌ بعد الورقة يُنقص المبيعَ بقدره من أيّ باب بِيع — في القسم وشاشة البوتيكات والتقرير رقمٌ واحد.
     *
     * كانت بنودُ الفواتير تُقرأ بما حملته ورقتُها، وبنودُ الطلبات بما بقي: القطعةُ نفسُها تُردّ
     * فتنقص «مبيعات الشهر» من الصندوق ولا تنقص من الفاتورة.
     */
    public function test_a_return_after_the_sheet_lowers_what_was_sold_alike_from_the_till_and_from_an_invoice(): void
    {
        $this->lama->update(['returns_terms' => 'sellable', 'returns_terms_ref' => 'عقد 7']);
        $read = function () {
            $s = $this->summary(['boutique' => $this->lama->id]);
            $index = collect($this->actingAs($this->owner)->get(route('admin.boutiques.index', ['period' => '2027-02']))->viewData('page')['props']['boutiques'])->firstWhere('id', $this->lama->id);

            return [round($s['standalone']['gross'] + $s['invoices']['gross'], 3), round($s['standalone']['commission'] + $s['invoices']['commission'], 3), round($s['standalone']['quantity'] + $s['invoices']['quantity'], 3),
                $s['owed'], (float) $index['gross'], Boutiques::soldTotals($this->shop->id, $this->lama->fresh())['net'], $s['settlement']['issued']];
        };

        // من الصندوق: قطعتان في ورقة، ثمّ رُدّت واحدة
        $this->till(2);
        Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertEquals([12.0, 2.4, 2.0, 9.6, 12.0, 9.6, 9.6], $read());
        OrderCorrection::setQuantity(Order::sole(), OrderItem::sole(), 1, 'أُعيدت', 'refunded', 'نقدي');
        $till = $read();
        $this->assertEquals([6.0, 1.2, 1.0, 4.8, 6.0, 4.8, 9.6], $till, 'الورقةُ كما صدرت، والمبيعُ ما بقي');

        // ومن فاتورة عميل في متجرٍ مثله
        $this->setUpAgain();
        $i = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->silver->id, 'description' => 'قطعة فضّة', 'quantity' => 2, 'unit_price' => 6]]));
        $this->pay($i);
        Boutiques::settle($this->shop->fresh(), $this->lama, '2027-02');
        $this->assertEquals([12.0, 2.4, 2.0, 9.6, 12.0, 9.6, 9.6], $read());
        $item = (int) $i->items()->value('id');
        CustomerInvoices::creditNote($i->fresh(), 6, 0, 'أُعيدت', null, items: BoutiqueInvoiceDues::noteItems($i->fresh(), [$item => ['quantity' => 1, 'sellable' => 1]]), basis: 'return');
        $this->assertEquals($till, $read(), 'الفاتورةُ تقول غيرَ ما يقوله الصندوق');
        // ورُدّت الأخرى: لا بندَ بقي، ولا فاتورةَ تُعدّ
        CustomerInvoices::creditNote($i->fresh(), 6, 0, 'أُعيدت', null, items: BoutiqueInvoiceDues::noteItems($i->fresh(), [$item => ['quantity' => 1, 'sellable' => 1]]), basis: 'return');
        $this->assertEquals([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 9.6], $read());
        $this->assertEquals([0, 0], [$this->summary()['invoices']['lines'], $this->summary()['invoices']['invoices']]);
    }

    private function setUpAgain(): void
    {
        $this->shop = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o2@sect.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => 'sellable', 'returns_terms_ref' => 'عقد 7']);
        $this->silver = $this->product('قطعة فضّة', 6, $this->lama);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة سرّيّة', 'phone' => '92222222', 'allow_credit_sales' => true]);
    }

    /** ما يُعرض عن فاتورةٍ لا تُحسب: القديمةُ وحدها، وما له لقطةٌ لا يُقال مرّتين */
    public function test_only_an_invoice_without_a_kept_line_is_called_uncounted(): void
    {
        $this->mixed();
        $this->assertEquals(['lines' => 0, 'invoices' => 0, 'amount' => 0.0], $this->summary()['uncounted']);
        $old = $this->mixed();
        DB::table('boutique_invoice_lines')->where('customer_invoice_id', $old->id)->delete();
        $this->assertEquals(['lines' => 2, 'invoices' => 1, 'amount' => 22.0], $this->summary()['uncounted']);
    }

    /** نافذةُ الفاتورة: كلُّ جهةٍ بنصيبها، والخصمُ على المتجر، والمجموعُ يطابق */
    public function test_the_invoice_window_divides_it_and_charges_the_discount_to_the_shop(): void
    {
        $invoice = $this->mixed(5.0);
        $this->pay($invoice, 10.0);
        $d = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->assertOk()->json();
        $this->assertTrue($d['full']);
        $this->assertEquals([['بوتيك لمى', 9.6], ['بوتيك ريم', 9.0]], array_map(fn ($b) => [$b['name'], $b['owed']], $d['shares']['boutiques']));
        $this->assertEquals([42.0, 23.4, 18.6], [$d['shares']['lines_total'], $d['shares']['shop'], $d['money']['boutiques_owed']]);
        $this->assertEquals([42.0, 5.0, 37.0, 37.0, 10.0, 27.0, 18.4], [$d['money']['lines'], $d['money']['discount'], $d['money']['after_discount'], $d['money']['total'], $d['money']['paid'], $d['money']['balance'], $d['money']['shop_after_discount']]);
        $this->assertTrue($d['shares']['reconciles']);
        $this->assertSame(route('admin.customerInvoices.show', $invoice->id), $d['url']);
        // ولا بيانَ عميل
        $this->assertStringNotContainsString('شركة سرّيّة', json_encode($d, JSON_UNESCAPED_UNICODE));

        // ومرتجعُ قطعةٍ يظهر في سطره وفي الإشعارات، ويبقى المجموعُ مطابقًا
        $item = (int) $invoice->items()->where('product_id', $this->silver->id)->value('id');
        CustomerInvoices::creditNote($invoice->fresh(), 6, 0, 'عادت', null, items: BoutiqueInvoiceDues::noteItems($invoice, [$item => ['quantity' => 1]]), basis: CustomerCreditNote::RETURN);
        $r = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->json();
        $this->assertEquals([4.8, 9.0, 6.0, 36.0], [$r['shares']['boutiques'][0]['owed'], $r['shares']['boutiques'][1]['owed'], $r['money']['credited'], $r['shares']['lines_total']]);
        $this->assertEquals(36.0, $r['shares']['shop'] + 13.8);
        $this->assertSame(['partial', 1.0], [$r['order']['invoice_state'], (float) $r['lines'][0]['returned']]);
        $this->assertCount(1, $r['edits']);
    }

    /** خصمٌ جاوز حصّةَ المتجر: سالبٌ يُقال، والبوتيكُ بحقّه */
    public function test_a_discount_beyond_the_shops_share_is_shown_negative(): void
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->silver->id, 'description' => 'قطعة فضّة', 'quantity' => 2, 'unit_price' => 6, 'discount' => 5]]));
        $d = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->json();
        $this->assertEquals([9.6, -2.6, 7.0], [$d['money']['boutiques_owed'], $d['money']['shop_after_discount'], $d['money']['total']]);
    }

    /** الأسعارُ الشاملة والمضافة: أين الضريبةُ يُقرأ من الفاتورة نفسِها */
    public function test_where_the_tax_is_reads_from_the_invoice_itself(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        foreach (['inclusive' => ['inside', 42.0], 'exclusive' => ['added', 44.1]] as $mode => [$basis, $total]) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => $mode]);
            $d = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $this->mixed()->id))->json();
            $this->assertSame($basis, $d['order']['tax_basis']);
            $this->assertEquals([$total, 18.6], [$d['money']['total'], $d['money']['boutiques_owed']]);
        }
    }

    public function test_a_cancelled_invoice_is_a_row_with_no_share(): void
    {
        $invoice = $this->mixed();
        CustomerInvoices::cancel($invoice, 'أُلغيت');
        $row = collect($this->rows(['state' => 'cancelled'])['rows'])->firstWhere('product', 'قطعة فضّة');
        $this->assertSame([true, 'cancelled'], [$row['void'], $row['invoice_state']]);
        $this->assertEquals([0, 0], [$row['commission'], $row['net']]);
        $this->assertSame(0, $this->rows(['state' => 'active'])['total']);
        $d = $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->json();
        $this->assertTrue($d['shares']['void']);
        $this->assertEquals(0, $d['money']['boutiques_owed']);
    }

    /** لمن يقرأ المال ولا يملك المبيعات: بنودُ البوتيكات وحدها — لا منتجاتِ المتجر ولا الخصمَ ولا ما دُفع */
    public function test_without_the_sales_section_only_the_boutiques_lines_are_read(): void
    {
        $invoice = $this->mixed(5.0);
        $accountant = User::create(['business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'a@sect.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'products', 'expenses']]);
        $d = $this->actingAs($accountant)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->assertOk()->json();
        $this->assertFalse($d['full']);
        $this->assertNull($d['money']);
        $this->assertNull($d['url']);
        $this->assertSame(['قطعة فضّة', 'عطر'], array_column($d['lines'], 'name'));
        $this->assertSame([null, null, null], [$d['shares']['shop'], $d['order']['total'], $d['lines'][0]['discount']]);
        $this->assertStringNotContainsString('باقة المتجر', json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    /** كلُّ بابٍ يردّ من لا يقرأ المال — وفاتورةٌ بلا بوتيك لا تُقرأ من هنا */
    public function test_who_does_not_read_the_money_is_refused_and_a_plain_invoice_is_not_found(): void
    {
        $invoice = $this->mixed();
        $plain = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->mine->id, 'description' => 'باقة', 'quantity' => 1, 'unit_price' => 20]]));
        $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.invoice', $plain->id))->assertNotFound();
        foreach ([['sales', null], ['inventory', null], ['sales', ['products']], ['cashier', ['pos']]] as $i => [$role, $perms]) {
            $u = User::create(['business_id' => $this->shop->id, 'name' => 'م'.$i, 'email' => "u{$i}@sect.om", 'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $perms]);
            $this->actingAs($u)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->assertForbidden();
        }
    }

    /** صفحةُ الصفوف والملخّصُ بعددٍ ثابتٍ من الاستعلامات مهما كثرت الفواتير */
    public function test_the_number_of_queries_does_not_grow_with_the_invoices(): void
    {
        $count = function () {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->rows();
            $this->summary();

            return $n;
        };
        $this->pay($this->mixed());
        $few = $count();
        foreach (range(1, 30) as $_) {
            $this->pay($this->mixed());
        }
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
        $this->assertLessThanOrEqual($few, $count());
    }
}
