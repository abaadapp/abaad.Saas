<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueInvoiceReturn;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Baisa;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueMoney;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * حسابٌ واحد لمستحقّ البوتيك: البندُ والتقريرُ والكشفُ والورقةُ والدفترُ رقمٌ واحد بالبيسة —
 * من كلّ قناة، وبكلّ نسبة، وقبل المرتجع وبعده.
 *
 * والقاعدةُ المعروضةُ (العمولةُ قبل الضريبة) مكتوبةٌ ومختبرةٌ و**مطفأةٌ لكلّ متجر**: لا بابَ
 * في النظام يكتب يومَها.
 */
class OneCalculationForEveryBoutiqueBaisaTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true, 'order_notes_and_edits_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->set('vat_enabled', '0');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@one.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $key], ['value' => $value]);
    }

    /** الأسعارُ شاملةٌ أو مضافة بنسبةٍ، أو بلا ضريبة */
    private function tax(?string $mode, string $rate = '5'): void
    {
        $this->set('vat_enabled', $mode === null ? '0' : '1');
        $this->set('vat_rate', $rate);
        $this->set('tax_mode', $mode ?? 'exclusive');
    }

    private function beforeVat(?string $from): void
    {
        DB::table('businesses')->where('id', $this->shop->id)->update(['boutique_commission_before_vat_from' => $from]);
    }

    private function boutique(string $name, float $rate): Boutique
    {
        return Boutique::create(['business_id' => $this->shop->id, 'name' => $name, 'commission_rate' => $rate, 'active' => true]);
    }

    private function product(string $name, float $price, ?Boutique $of, ?float $tax = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 99999,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $of?->id, 'tax' => $tax]);
    }

    /** @param list<array{0: Product, 1?: int}> $lines */
    private function sell(array $lines, array $extra = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', $extra + [
            'items' => array_map(fn ($l) => ['id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1] ?? 1, 'price' => (float) $l[0]->price], $lines),
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('s', true),
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    /** @param list<array<int, mixed>> $lines */
    private function invoice(array $lines, bool $collect = true): CustomerInvoice
    {
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, ['branch_id' => $this->branch->id], array_map(fn ($l) => array_filter([
            'product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1] ?? 1, 'unit_price' => (float) $l[0]->price, 'discount' => $l[2] ?? 0,
            'tax_rate' => $l[3] ?? null,
        ], fn ($v) => $v !== null), $lines)));
        if ($collect) {
            CustomerPayments::record($this->shop->id, $this->company, (float) $invoice->total, ['method' => 'نقدي'], [$invoice->id => (float) $invoice->total]);
        }

        return $invoice;
    }

    /** التقريرُ والكشفُ والورقةُ ومصروفُها والدفترُ — رقمٌ واحد، ويُعاد */
    private function assertOneNumber(Boutique $b, string $why = ''): float
    {
        $report = Boutiques::soldTotals($this->shop->id, $b);
        $s = Boutiques::statement($this->shop->id, $b->id, self::PERIOD);
        $sheet = Boutiques::settle($this->shop->fresh(), $b, self::PERIOD);
        $expense = (float) Expense::findOrFail($sheet->expense_id)->amount;
        $ledger = $this->owedInTheLedger($sheet);
        $rows = round(collect($s['lines'])->sum('net'), 3);
        $this->assertSame(array_fill(0, 6, $s['net']), [$report['net'], $s['net'], $rows, round((float) $sheet->net, 3), $expense, $ledger], $b->name.' — '.$why);
        $this->assertSame([$s['commission'], $s['gross']], [$report['commission'], $report['gross']], $b->name.' — '.$why);
        $this->assertSame($s['gross'], round($s['vat'] + $s['commission'] + $s['net'], 3), 'الإجماليُّ لا يجمع إلى أجزائه');

        return $s['net'];
    }

    /** ما في 2160 لهذه الورقة: ما قيّدته، وما قُيّد قبلها مع فواتيرها ناقصًا ما رُدّ منه */
    private function owedInTheLedger(BoutiqueSettlement $sheet): float
    {
        $lines = BoutiqueInvoiceLine::where('boutique_settlement_id', $sheet->id)->pluck('id');

        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.system_key', 'boutique_payable')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('e.sourceable_type', BoutiqueSettlement::class)->where('e.sourceable_id', $sheet->id))
                ->orWhere(fn ($w) => $w->where('e.sourceable_type', BoutiqueInvoiceLine::class)->whereIn('e.sourceable_id', $lines))
                ->orWhere(fn ($w) => $w->where('e.sourceable_type', BoutiqueInvoiceReturn::class)
                    ->whereIn('e.sourceable_id', BoutiqueInvoiceReturn::whereIn('boutique_invoice_line_id', $lines)->pluck('id'))))
            ->selectRaw('coalesce(sum(l.credit - l.debit), 0) as v')->value('v'), 3);
    }

    /* ═══════════ ١ · القاعدةُ نفسُها ═══════════ */

    /** قاعدةُ SQL هي قاعدةُ PHP بالبيسة — على آلاف المبالغ والنِّسب وبضريبةٍ وبغيرها */
    public function test_the_database_and_the_code_round_the_same_baisa(): void
    {
        mt_srand(2027);
        $order = Order::create(['business_id' => $this->shop->id, 'number' => 'R-1', 'status' => 'مكتمل', 'is_held' => false, 'subtotal' => 0, 'total' => 0, 'ordered_at' => now()]);
        $rows = [];
        foreach (range(1, 3000) as $i) {
            $rows[] = ['order_id' => $order->id, 'name' => 'ص', 'price' => 1, 'quantity' => 1, 'total' => mt_rand(0, $i % 5 === 0 ? 99999999 : 30000) / 1000,
                'boutique_id' => 1, 'boutique_rate' => mt_rand(0, 10000) / 100, 'boutique_vat_rate' => [null, 5, 10, 0, 7.5][$i % 5], 'created_at' => now(), 'updated_at' => now()];
        }
        // أنصافُ البيسات بعينها
        foreach ([[1.005, 10], [1.015, 10], [0.004, 12.5], [0.335, 10], [99999.999, 10], [0.001, 50]] as [$total, $rate]) {
            $rows[] = ['order_id' => $order->id, 'name' => 'ص', 'price' => 1, 'quantity' => 1, 'total' => $total, 'boutique_id' => 1, 'boutique_rate' => $rate, 'boutique_vat_rate' => null, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        $sql = BoutiqueMoney::sql('order_items');
        $n = 0;
        DB::table('order_items')->where('order_id', $order->id)->select('order_items.*')
            ->selectRaw("{$sql['gross']} as g, {$sql['vat']} as v, {$sql['base']} as b, {$sql['commission']} as c, {$sql['net']} as n")
            ->orderBy('id')->each(function ($r) use (&$n) {
                $p = BoutiqueMoney::item($r);
                $this->assertSame([$p['gross'], $p['vat'], $p['base'], $p['commission'], $p['net']], [(int) $r->g, (int) $r->v, (int) $r->b, (int) $r->c, (int) $r->n], "{$r->total} × {$r->boutique_rate}٪ ضريبة {$r->boutique_vat_rate}");
                $this->assertSame($p['gross'], $p['vat'] + $p['commission'] + $p['net']);
                $n++;
            });
        $this->assertSame(3006, $n);
    }

    /** أمثلةُ النصف: كانت الورقةُ بالكسر العائم تقول غيرَها */
    public function test_half_a_baisa_goes_up_and_floating_point_decides_nothing(): void
    {
        foreach ([[1005, 1000, 101, 904], [1015, 1000, 102, 913], [4, 1250, 1, 3], [335, 1000, 34, 301], [1005, 0, 0, 1005], [1005, 10000, 1005, 0]] as [$total, $rate, $commission, $net]) {
            $p = BoutiqueMoney::split($total, $rate);
            $this->assertSame([$commission, $net], [$p['commission'], $p['net']], "{$total} × {$rate}");
        }
    }

    /* ═══════════ ٢ · رقمٌ واحد في كلّ موضع ═══════════ */

    /** نِسبٌ وأسعارٌ عشوائيّة من الصندوق والفواتير معًا: لا فرقَ بيسةٍ بين موضعين */
    public function test_every_reader_says_one_number_for_every_boutique_rate_and_door(): void
    {
        mt_srand(77);
        $boutiques = array_map(fn ($rate) => $this->boutique('ب'.$rate, $rate), [7.5, 12.5, 33.33, 20, 10, 0, 99.99]);
        $of = [];
        foreach ($boutiques as $i => $b) {
            $of[$i] = [$this->product("ص{$i}-أ", mt_rand(1, 9999) / 1000, $b), $this->product("ص{$i}-ب", mt_rand(1000, 99999) / 1000, $b)];
        }
        foreach (range(1, 60) as $_) {
            $i = mt_rand(0, 6);
            $k = mt_rand(0, 6);
            $this->sell([[$of[$i][0], mt_rand(1, 5)], [$of[$k][1], mt_rand(1, 3)]]);
        }
        foreach ($boutiques as $i => $b) {
            foreach (range(1, 3) as $_) {
                $this->invoice([[$of[$i][mt_rand(0, 1)], mt_rand(1, 4), mt_rand(0, 300) / 1000]]);
            }
        }
        foreach ($boutiques as $b) {
            $this->assertOneNumber($b, 'نسبة '.$b->commission_rate);
        }
    }

    /** ورقةٌ من فروعٍ: أنصبةُ الفروع تجمع إلى صافيها بالبيسة */
    public function test_the_branches_shares_add_up_to_the_sheet(): void
    {
        $b = $this->boutique('لمى', 12.5);
        $p = $this->product('كسر', 0.333, $b);
        $second = Branch::create(['business_id' => $this->shop->id, 'name' => 'الثاني']);
        foreach ([[$this->branch, 3], [$second, 1], [$this->branch, 1], [$second, 2], [$second, 7]] as [$at, $qty]) {
            $order = $this->sell([[$p, $qty]]);
            Order::whereKey($order->id)->update(['branch_id' => $at->id]);
        }
        $sheet = Boutiques::settle($this->shop->fresh(), $b, self::PERIOD);
        $shares = DB::table('expense_branch_allocations')->where('expense_id', $sheet->expense_id)->pluck('amount', 'branch_id')->map(fn ($v) => (float) $v);
        $this->assertCount(2, $shares);
        $this->assertSame(round((float) $sheet->net, 3), round($shares->sum(), 3));
        // الأوّل: 0.999 و0.333 ← 0.874 + 0.291. والثاني: 0.333 و0.666 و2.331 ← 0.291 + 0.583 + 2.040
        $this->assertEquals([1.165, 2.914], [$shares[$this->branch->id], $shares[$second->id]]);
    }

    /* ═══════════ ٣ · العمولةُ قبل الضريبة — معروضةٌ ومطفأة ═══════════ */

    /** مطفأةٌ لكلّ متجر: لا افتراضَ ولا بابَ يكتبها */
    public function test_the_proposed_rule_is_off_for_every_shop_and_no_door_turns_it_on(): void
    {
        $this->assertNull(Business::find($this->shop->id)->boutique_commission_before_vat_from);
        $this->assertSame(0, DB::table('businesses')->whereNotNull('boutique_commission_before_vat_from')->count());
        $this->assertSame(BoutiqueMoney::AS_RECORDED, BoutiqueMoney::rule($this->shop->id));

        $touch = [];
        foreach (Finder::create()->files()->in([app_path(), base_path('routes'), resource_path('js'), database_path('seeders'), config_path()])->name(['*.php', '*.tsx', '*.ts']) as $file) {
            if (str_contains($file->getContents(), 'boutique_commission_before_vat_from')) {
                $touch[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }
        $this->assertSame(['app/Support/BoutiqueMoney.php'], $touch, 'بابٌ صار يقرأ القاعدةَ المعروضة أو يكتبها — لا تُعتمد بلا موافقة المحاسب والمالك');
        $this->assertStringNotContainsString("->update(['boutique_commission", (string) file_get_contents(app_path('Support/BoutiqueMoney.php')));

        // وحقلٌ يُرسَل من شاشة المنصّة لا يكتبها
        $admin = User::create(['name' => 'مدير', 'email' => 'root@one.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        $this->actingAs($admin)->put('/super-admin/businesses/'.$this->shop->id, ['name' => $this->shop->name, 'boutique_commission_before_vat_from' => '2027-01-01']);
        $this->assertNull(DB::table('businesses')->where('id', $this->shop->id)->value('boutique_commission_before_vat_from'));
    }

    /** ومطفأةً: الأسعارُ الشاملةُ عمولتُها من المبلغ كما سُجّل، ولا بندَ يحمل ضريبةً تُخرَج */
    public function test_off_an_inclusive_shop_is_worked_out_as_it_always_was(): void
    {
        $this->tax('inclusive');
        $b = $this->boutique('لمى', 20);
        $this->sell([[$this->product('قطعة', 12.6, $b)]]);
        $this->assertNull(OrderItem::sole()->boutique_vat_rate);
        $s = Boutiques::statement($this->shop->id, $b->id, self::PERIOD);
        $this->assertSame([12.6, 0.0, 2.52, 10.08], [$s['gross'], $s['vat'], $s['commission'], $s['net']]);
    }

    /**
     * الأمثلةُ بالبيسة: كما هي ثمّ المعروضة — لسلّةٍ واحدة (عمولة 20٪).
     *
     * @return array<string, array{0: ?string, 1: string, 2: float, 3: list<float>, 4: list<float>}>
     */
    public static function examples(): array
    {
        // [طريقة، نسبة، سعر، كما هي [ضريبةٌ أُخرجت، عمولة، صافي البوتيك]، المعروضة]
        return [
            'بلا ضريبة 12.000' => [null, '5', 12.0, [0.0, 2.4, 9.6], [0.0, 2.4, 9.6]],
            'مضافة 5٪ 12.000' => ['exclusive', '5', 12.0, [0.0, 2.4, 9.6], [0.0, 2.4, 9.6]],
            'شاملة 5٪ 12.000' => ['inclusive', '5', 12.0, [0.0, 2.4, 9.6], [0.571, 2.286, 9.143]],
            'شاملة 5٪ 12.600' => ['inclusive', '5', 12.6, [0.0, 2.52, 10.08], [0.6, 2.4, 9.6]],
            'شاملة 10٪ 13.200' => ['inclusive', '10', 13.2, [0.0, 2.64, 10.56], [1.2, 2.4, 9.6]],
        ];
    }

    #[DataProvider('examples')]
    public function test_the_two_rules_side_by_side_at_the_till_and_on_an_invoice(?string $mode, string $rate, float $price, array $now, array $proposed): void
    {
        $this->tax($mode, $rate);
        $b = $this->boutique('لمى', 20);
        $p = $this->product('قطعة', $price, $b);

        foreach ([[null, $now], ['2027-02-01', $proposed]] as [$from, $expect]) {
            $this->beforeVat($from);
            $this->sell([[$p]]);
            $this->assertEquals($expect, self::split(OrderItem::latest('id')->first()), 'الصندوق');
            $this->invoice([[$p]]);
            $line = BoutiqueInvoiceLine::latest('id')->first();
            $this->assertEquals($expect, [round((float) $line->gross - (float) $line->base, 3), (float) $line->commission, (float) $line->net], 'فاتورة العميل');
        }
        // والورقةُ تحمل ما بيع بكلّ قاعدةٍ بقاعدته
        $this->assertSame(round(2 * ($now[2] + $proposed[2]), 3), $this->assertOneNumber($b));
    }

    /** @return list<float> [ضريبةٌ أُخرجت، عمولة، صافٍ] */
    private static function split(OrderItem $item): array
    {
        $p = BoutiqueMoney::item($item);

        return [Baisa::toMoney($p['vat']), Baisa::toMoney($p['commission']), Baisa::toMoney($p['net'])];
    }

    /** ثلاثُ نِسبٍ في سلّةٍ واحدة: خاضعٌ وصفريٌّ وعشرة — كلُّ بندٍ بضريبته هو */
    public function test_a_basket_of_three_rates_takes_out_each_lines_own_tax(): void
    {
        $this->tax('inclusive');
        $this->beforeVat('2027-02-01');
        $b = $this->boutique('لمى', 20);
        $this->sell([[$this->product('خاضع', 12.6, $b)], [$this->product('صفريّ', 10, $b, 0.0)], [$this->product('عشرة', 11, $b, 10.0)]]);
        $this->assertEquals([[0.6, 2.4, 9.6], [0.0, 2.0, 8.0], [1.0, 2.0, 8.0]], OrderItem::orderBy('id')->get()->map(fn ($i) => self::split($i))->all());
        $s = Boutiques::statement($this->shop->id, $b->id, self::PERIOD);
        // كان 26.880 وصار 25.600 — والإجماليُّ 33.600 = ضريبة 1.600 + عمولة 6.400 + صافٍ 25.600
        $this->assertSame([33.6, 1.6, 6.4, 25.6], [$s['gross'], $s['vat'], $s['commission'], $s['net']]);
        $this->assertSame(25.6, $this->assertOneNumber($b));
        $this->assertEquals(1.6, (float) BoutiqueSettlement::sole()->vat);
    }

    /** لا تسري على ما بيع قبل يومها، ولا قبل أن يحلّ، وتقف متى أُفرغت — وما كُتب يبقى بقاعدته */
    public function test_it_reaches_only_what_is_sold_from_its_day_and_stops_when_it_is_cleared(): void
    {
        $this->tax('inclusive');
        $b = $this->boutique('لمى', 20);
        $p = $this->product('قطعة', 12.6, $b);
        $this->sell([[$p]]);                                  // كما هي
        $this->beforeVat('2027-02-15');
        $this->sell([[$p]]);                                  // لم يحلّ يومُها
        Carbon::setTestNow('2027-02-15 00:00:01');
        $this->sell([[$p]]);                                  // حلّ
        $this->beforeVat(null);
        $this->sell([[$p]]);                                  // أُفرغت
        $this->tax('exclusive');
        $this->assertSame([null, null, 5.0, null], OrderItem::orderBy('id')->get()->map(fn ($i) => $i->boutique_vat_rate === null ? null : (float) $i->boutique_vat_rate)->all());
        // وتبديلُ طريقة الضريبة بعدها لا يغيّر ما كُتب
        $this->assertSame(round(10.08 * 3 + 9.6, 3), $this->assertOneNumber($b));
    }

    /** والبندُ يُصحَّح بقاعدته: إنقاصُه ومرتجعُه بعد ورقته يجمعان إلى ما عليها */
    public function test_a_line_sold_by_the_proposed_rule_is_corrected_and_returned_by_it(): void
    {
        $this->tax('inclusive');
        $this->beforeVat('2027-02-01');
        $b = $this->boutique('لمى', 17.5);
        $b->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'عقد']);
        $order = $this->sell([[$this->product('كسر', 3.333, $b), 5]]);
        OrderCorrection::setQuantity($order->fresh(), OrderItem::sole(), 4, 'أعاد واحدة', 'refunded', 'نقدي');
        $net = $this->assertOneNumber($b, 'بعد التصحيح');
        // 13.332 ← ضريبة 0.635، أساس 12.697، عمولة 2.222، صافٍ 10.475
        $this->assertSame(10.475, $net);

        OrderCorrection::setQuantity($order->fresh(), OrderItem::sole(), 3, 'أعاد أخرى', 'refunded', 'نقدي');
        OrderCorrection::cancel($order->fresh(), 'مرتجع', null, [OrderItem::sole()->id => ['sellable' => 1, 'damaged' => 2]]);
        foreach (BoutiqueReturn::where('status', BoutiqueReturn::AWAITING)->get() as $note) {
            BoutiqueReturns::receive($note, (int) $note->quantity, 0, 'استُلمت');
        }
        $this->assertSame($net, round((float) BoutiqueReturn::sum('amount'), 3));
        $this->assertSame(0.0, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
    }

    /** وبندُ الفاتورة كذلك: لقطتُه تحمل قاعدتَه، ومرتجعُه بها */
    public function test_an_invoice_line_keeps_the_rule_it_was_issued_by(): void
    {
        $this->tax('inclusive');
        $this->beforeVat('2027-02-01');
        $b = $this->boutique('لمى', 20);
        $invoice = $this->invoice([[$this->product('قطعة', 12.6, $b), 3]]);
        $this->beforeVat(null);
        $line = BoutiqueInvoiceLine::sole();
        $this->assertSame(['before_vat', 28.8], [$line->rule, (float) $line->net]);

        $item = (int) $invoice->items()->value('id');
        CustomerInvoices::creditNote($invoice->fresh(), 12.6, 0.6, 'مرتجع', null, items: BoutiqueInvoiceDues::noteItems($invoice, [$item => ['quantity' => 1]]), basis: CustomerCreditNote::RETURN);
        $this->assertEquals(9.6, (float) $line->fresh()->returned_net);
        $this->assertSame(19.2, $this->assertOneNumber($b));
    }

    /* ═══════════ ٤ · الخصمُ لا يمسّه في أيّ قناةٍ ولا أيّ قاعدة ═══════════ */

    public function test_no_discount_at_any_door_under_either_rule_lowers_what_the_boutique_is_owed(): void
    {
        $this->tax('inclusive');
        $b = $this->boutique('لمى', 20);
        $p = $this->product('قطعة', 12.6, $b);
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'TEN', 'type' => 'نسبة', 'value' => 10, 'active' => true]);

        foreach ([[null, 10.08], ['2027-02-01', 9.6]] as [$from, $net]) {
            $this->beforeVat($from);
            $order = $this->sell([[$p]], ['coupon_code' => 'TEN', 'discount' => 1.26]);
            $this->assertEquals([1.26, 11.34], [(float) $order->discount, (float) $order->total], 'كوبونٌ لم يُطبَّق — الاختبارُ لا يقيس شيئًا');
            $this->assertEquals($net, self::split(OrderItem::where('order_id', $order->id)->sole())[2], 'الصندوق');
            // وخصمُ بند الفاتورة ولو جاوز حصّةَ المتجر كلَّها
            $this->invoice([[$p, 1, 5.0]]);
            $this->assertEquals($net, (float) BoutiqueInvoiceLine::latest('id')->first()->net, 'الفاتورة');
        }
    }
}
