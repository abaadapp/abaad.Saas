<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Boutique;
use App\Models\BoutiqueComponentDue;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueReturn;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueHolds;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueInvoicePieces;
use App\Support\BoutiqueMovements;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\BoutiqueSales;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Profitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * قطعُ البوتيك داخل باقات فاتورة العميل وإضافاتها — الباقةُ نفسُها من الصندوق والموقع والفاتورة مبلغٌ واحد.
 *
 * ويُحسب بحاسبة الصندوق نفسِها، ويحترم مفتاحَ القطع، ويُقيَّد يومَ الفاتورة تكلفةً، ولا يُسوّى قبل
 * تحصيلها، ويُسأل عن كلّ قطعةٍ في إشعار الدائن: عادت أم لا.
 */
class AnInvoiceOwesTheBoutiquePiecesInsideItsBundlesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $customer;

    /** @var list<Boutique> */
    private array $boutiques = [];

    /** @var list<Product> */
    private array $pieces = [];

    /** @var list<Product> */
    private array $bundles = [];

    /** @var list<Addon> */
    private array $paid = [];

    /** @var list<Addon> */
    private array $free = [];

    private ?ProductVariant $variant = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        app()->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066', 'city' => 'مسقط', 'site_slug' => 'pieceshop', 'tier' => 'gold', 'storefront_theme' => 'ribbon', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'o', 'email' => 'o@pieces.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->customer = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true]);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0', 'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م']);
        $this->actingAs($this->owner);

        foreach (['لمى' => 20, 'ريم' => 10, 'سارة' => 0] as $n => $r) {
            $this->boutiques[] = Boutique::create(['business_id' => $this->shop->id, 'name' => $n, 'commission_rate' => $r, 'active' => true]);
        }
        foreach ([7, 15, 6.335, 0.335, 37] as $i => $price) {
            $this->pieces[] = $this->product("قطعة $i", $price, $this->boutiques[$i % 3]);
        }
        $rose = $this->product('وردة', 1);
        foreach ([[[0, 1]], [[1, 1]], [[0, 2], [2, 1]], [[3, 4], [1, 1]]] as $i => $recipe) {
            $b = $this->product("باقة $i", 60);
            foreach ($recipe as [$p, $q]) {
                $this->recipe($b, $this->pieces[$p], $q);
            }
            $this->recipe($b, $rose, 5);
            $this->bundles[] = $b;
        }
        $this->variant = ProductVariant::create(['business_id' => $this->shop->id, 'product_id' => $this->bundles[0]->id, 'name' => 'كبير', 'price' => 80, 'active' => true]);
        $this->recipe($this->bundles[0], $this->pieces[4], 1, $this->variant);
        $this->bundles[] = $this->product('باقة بلا قطعة', 20);
        foreach ([[0, 1, 7], [4, 1, 37], [2, 2, 20], [null, null, 3]] as $i => [$p, $q, $price]) {
            $this->paid[] = Addon::create(['business_id' => $this->shop->id, 'name' => "إضافة $i", 'price' => $price, 'active' => true, 'scope' => Addon::SCOPE_ALL, 'inventory_product_id' => $p === null ? null : $this->pieces[$p]->id, 'inventory_quantity' => $q]);
        }
        $this->free[] = Addon::create(['business_id' => $this->shop->id, 'name' => 'هديّة', 'price' => 0, 'active' => true, 'scope' => Addon::SCOPE_ALL, 'inventory_product_id' => $this->pieces[2]->id, 'inventory_quantity' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Boutique $of = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0.4, 'quantity' => 100000, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $of?->id]);
    }

    private function recipe(Product $of, Product $c, float $q, ?ProductVariant $v = null): void
    {
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $of->id, 'variant_id' => $v?->id, 'component_product_id' => $c->id, 'quantity' => $q, 'wastage_percent' => 0]);
    }

    /** @param list<array{0: Product, 1: int, 2?: list<array{0: Addon, 1: int}>, 3?: ?ProductVariant}> $basket */
    private function till(array $basket): array
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', ['payment_method' => 'نقدي', 'client_uuid' => uniqid('s', true), 'items' => array_map(fn ($l) => array_filter([
            'id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1], 'price' => (float) ($l[3] ?? $l[0])->price, 'variant_id' => ($l[3] ?? null)?->id,
            'addons' => array_map(fn ($a) => ['addon_id' => $a[0]->id, 'qty' => $a[1]], $l[2] ?? []),
        ], fn ($v) => $v !== null && $v !== []), $basket)])->assertOk();

        return $this->written(Order::where('business_id', $this->shop->id)->latest('id')->firstOrFail());
    }

    private function written(Order $o): array
    {
        return $this->grouped(BoutiqueComponentDue::where('order_id', $o->id)->get()->map(fn ($d) => ['boutique_id' => (int) $d->boutique_id, 'product_id' => (int) $d->product_id, 'source' => $d->source, 'quantity' => (float) $d->quantity, 'amount' => (float) $d->amount]));
    }

    private function grouped($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = "{$r['boutique_id']}:{$r['product_id']}:{$r['source']}";
            $out[$k] = [round(($out[$k][0] ?? 0) + $r['quantity'], 3), round(($out[$k][1] ?? 0) + $r['amount'], 3)];
        }
        ksort($out);

        return $out;
    }

    /** من باب شاشة الفواتير نفسِه */
    private function invoice(array $basket, array $extra = []): CustomerInvoice
    {
        $this->actingAs($this->owner)->post('/admin/customer-invoices', $extra + ['customer_id' => $this->customer->id, 'issue' => true, 'payment_method' => 'آجل', 'form_token' => uniqid('f', true),
            'items' => array_map(fn ($l) => array_filter([
                'product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1], 'unit_price' => (float) ($l[3] ?? $l[0])->price, 'variant_id' => ($l[3] ?? null)?->id, 'discount' => $l[4] ?? null,
                'addons' => array_map(fn ($a) => ['addon_id' => $a[0]->id, 'quantity' => $a[1], 'included' => (float) $a[0]->price <= 0], $l[2] ?? []),
            ], fn ($v) => $v !== null && $v !== []), $basket)])->assertSessionHasNoErrors();

        return CustomerInvoice::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /** ما كتبته الفاتورة لقطعها — بالبوتيك والصنف والمصدر */
    private function owed(CustomerInvoice $i): array
    {
        return $this->grouped(BoutiqueInvoiceLine::where('customer_invoice_id', $i->id)->where('kind', BoutiqueInvoiceLine::PIECE)->get()
            ->map(fn ($l) => ['boutique_id' => (int) $l->boutique_id, 'product_id' => (int) $l->product_id, 'source' => $l->source, 'quantity' => (float) $l->quantity, 'amount' => (float) $l->net]));
    }

    /** يُرسَل ويُقال لماذا رُدّ — أو `null` */
    private function refused(array $basket, array $extra = []): ?string
    {
        $r = $this->actingAs($this->owner)->post('/admin/customer-invoices', $extra + ['customer_id' => $this->customer->id, 'issue' => true, 'payment_method' => 'آجل', 'form_token' => uniqid('f', true),
            'items' => array_map(fn ($l) => array_filter([
                'product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1], 'unit_price' => (float) ($l[3] ?? $l[0])->price, 'variant_id' => ($l[3] ?? null)?->id, 'discount' => $l[4] ?? null,
                'addons' => array_map(fn ($a) => ['addon_id' => $a[0]->id, 'quantity' => $a[1], 'included' => (float) $a[0]->price <= 0], $l[2] ?? []),
            ], fn ($v) => $v !== null && $v !== []), $basket)]);

        $errors = session('errors');
        if ($errors instanceof ViewErrorBag) {
            return $errors->first() ?: null;
        }

        return collect(data_get($errors, 'default.messages', []))->flatten()->first();
    }

    private function pay(CustomerInvoice $i, ?float $amount = null, string $method = 'نقدي')
    {
        $amount ??= $i->fresh()->outstanding();

        return CustomerPayments::record($this->shop->id, $this->customer, $amount, ['method' => $method], [$i->id => $amount], $this->owner->id);
    }

    private function payable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_payable'), 3) + 0.0;
    }

    private function bundle(float $price = 17): Product
    {
        $b = $this->product('باقة فضّة', $price);
        $this->recipe($b, $this->pieces[0], 1);

        return $b;
    }

    private function note(CustomerInvoice $i, float $amount, array $pieces): CustomerCreditNote
    {
        return CustomerInvoices::creditNote($i->fresh(), $amount, 0.0, 'مرتجع', $this->owner->id,
            items: BoutiqueInvoiceDues::noteItems($i, $pieces), basis: CustomerCreditNote::RETURN);
    }

    /* ═══════════ القناةُ لا تغيّر المبلغ ═══════════ */

    public function test_four_hundred_random_baskets_owe_the_same_from_the_till_and_from_an_invoice(): void
    {
        mt_srand(20270210);
        $owed = 0.0;
        $kinds = [];
        for ($i = 0; $i < 400; $i++) {
            $basket = [];
            for ($l = 0; $l <= mt_rand(0, 2); $l++) {
                $b = $this->bundles[mt_rand(0, 4)];
                $addons = [];
                foreach (mt_rand(0, 2) ? [] : (array) array_rand($this->paid, 2) as $a) {
                    $addons[] = [$this->paid[$a], mt_rand(1, 3)];
                }
                if (mt_rand(0, 3) === 0) {
                    $addons[] = [$this->free[0], mt_rand(1, 2)];
                }
                $basket[] = [$b, mt_rand(1, 4), $addons, $b->id === $this->bundles[0]->id ? $this->variant : null];
            }
            $till = $this->till($basket);
            $invoice = $this->owed($this->invoice($basket));
            $this->assertSame($till, $invoice, "سلّة $i: ".json_encode($till).' ≠ '.json_encode($invoice));
            $owed += array_sum(array_column($till, 1));
            foreach ($till as $k => $_) {
                $kinds[explode(':', $k)[2]] = true;
            }
        }
        $this->assertGreaterThan(10000, $owed);
        $this->assertSame(['addon', 'recipe'], collect($kinds)->keys()->sort()->values()->all());
        // والتكلفةُ في الدفتر ما للبوتيكات: من الصندوق ومن الفواتير
        $pieces = round((float) BoutiqueInvoiceLine::where('kind', 'piece')->sum('net'), 3);
        $this->assertSame(round($owed * 2, 3), $this->payable());
        $this->assertSame($pieces, round((float) BoutiqueInvoicePieces::cost($this->shop->id, null, null, null)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 3));
    }

    public function test_the_website_owes_it_too(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postJson('/s/pieceshop/checkout', ['items' => [['id' => $this->bundles[$n]->id, 'qty' => $n]], 'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'م', 'phone' => '96899110001', 'date' => '2027-02-12', 'slot' => '9 ص – 12 م'])->assertOk();
            $web = $this->written(Order::where('channel', 'website')->latest('id')->firstOrFail());
            $this->assertNotSame([], $web);
            $this->assertSame($web, $this->owed($this->invoice([[$this->bundles[$n], $n]])));
        }
    }

    /** وتكلفةُ القطع في تقرير الربح من يوم الفاتورة — كما في الصندوق */
    public function test_the_profit_report_counts_the_pieces_cost_as_the_till_does(): void
    {
        $this->till([[$this->bundles[1], 2]]);
        $till = Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'));
        $this->invoice([[$this->bundles[1], 2]]);
        $both = Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'));
        // الوردُ لا تكلفةَ له في الفاتورة (لا تُخرج من الرفّ)، والقطعتان 30
        $this->assertSame(30.0, round($both - $till, 3));
        $this->assertSame(0.0, round(Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'), null, 'pos') - ($till), 3) + 0.0);
    }

    /* ═══════════ ما يُرفض ═══════════ */

    public function test_a_discount_that_eats_the_pieces_is_refused_and_says_by_how_much(): void
    {
        $b = $this->bundle(17);
        // 17 − 11 = 6 والقطعة 7: يُرفض كما يرفضه الصندوق، ويقول الباقةَ وكم يُخفَّف
        $said = $this->refused([[$b, 1, [], null, 11]]);
        $this->assertNotNull($said);
        foreach (['باقة فضّة', '6.000', '7.000', '1.000'] as $part) {
            $this->assertStringContainsString($part, $said);
        }
        $this->assertSame(0, CustomerInvoice::where('status', CustomerInvoice::ISSUED)->count(), 'صدرت وما كان لها أن تصدر');
        $this->assertSame(0.0, $this->payable());

        // وخصمٌ يبقي لها ما يسعها يُقبل، ولا ينقص به ما للبوتيك
        $this->assertNull($this->refused([[$b, 1, [], null, 10]]));
        $this->assertSame(7.0, (float) BoutiqueInvoiceLine::sole()->net);
    }

    /** وثمنُ صنفٍ آخر لا يستر خصمَ الباقة: السؤالُ لكلّ باقة */
    public function test_another_line_does_not_cover_a_bundle_whose_discount_ate_its_pieces(): void
    {
        $this->assertNotNull($this->refused([[$this->bundle(17), 1, [], null, 11], [$this->bundles[4], 3]]));
        $this->assertSame(0, BoutiqueInvoiceLine::count());
    }

    public function test_what_the_till_refuses_is_refused_with_the_same_words(): void
    {
        $b = $this->bundle(60);
        $till = function (Product $b) {
            return collect($this->postJson('/pos/checkout', ['payment_method' => 'نقدي', 'items' => [['id' => $b->id, 'name' => $b->name, 'qty' => 1, 'price' => 60]]])->json('errors') ?? [])->flatten()->first();
        };
        $this->assertNull($this->refused([[$b, 1]]));
        foreach ([
            fn () => RecipeItem::where('product_id', $b->id)->update(['wastage_percent' => 5]),
            fn () => RecipeItem::where('product_id', $b->id)->update(['wastage_percent' => 0, 'quantity' => 1.5]),
            fn () => [RecipeItem::where('product_id', $b->id)->update(['quantity' => 1]), $this->pieces[0]->update(['price' => 0])],
            fn () => [$this->pieces[0]->update(['price' => 7]), Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1'])],
        ] as $n => $break) {
            $break();
            cache()->flush();
            $said = $this->refused([[$b, 1]]);
            $this->assertNotNull($said, "حالة $n لم تُردّ");
            $this->assertSame($till($b), $said, "حالة $n");
        }
        $this->assertSame(1, CustomerInvoice::where('status', CustomerInvoice::ISSUED)->count());
    }

    public function test_half_a_bundle_is_not_half_a_piece(): void
    {
        $this->expectException(\RuntimeException::class);
        CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->customer, [], [['product_id' => $this->bundle()->id, 'description' => 'x', 'quantity' => 0.5, 'unit_price' => 60]]));
    }

    /* ═══════════ المفتاحُ ولا ازدواج ═══════════ */

    public function test_the_switch_off_owes_nothing_and_what_was_owed_before_it_is_settled(): void
    {
        $before = $this->invoice([[$this->bundles[1], 2]]);
        DB::table('businesses')->where('id', $this->shop->id)->update(['boutique_components_enabled' => false]);
        $after = $this->invoice([[$this->bundles[1], 2]]);
        $this->assertSame([], $this->owed($after));
        $this->pay($before);
        $this->pay($after);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->boutiques[1], '2027-02');
        $this->assertSame(30.0, (float) $sheet->net);
        // ولا يُحسب بعد فتحه لما صدر وهو مطفأ
        DB::table('businesses')->where('id', $this->shop->id)->update(['boutique_components_enabled' => true]);
        $this->assertSame([], $this->owed($after));
        $this->assertSame(1, BoutiqueInvoiceLine::where('kind', 'piece')->count());
    }

    public function test_an_invoice_born_from_an_order_owes_nothing_again(): void
    {
        $this->till([[$this->bundles[1], 1]]);
        $before = BoutiqueInvoiceLine::count();
        CustomerInvoices::fromOrder(tap(Order::latest('id')->firstOrFail())->update(['customer_id' => $this->customer->id])->fresh());
        $this->assertSame($before, BoutiqueInvoiceLine::count());
        $this->assertSame(15.0, $this->payable());
    }

    public function test_a_piece_on_its_own_line_stays_standalone_and_the_one_inside_is_a_piece(): void
    {
        $i = $this->invoice([[$this->pieces[0], 1], [$this->bundles[1], 1]]);
        $lines = BoutiqueInvoiceLine::where('customer_invoice_id', $i->id)->orderBy('id')->get();
        $this->assertSame([['line', (int) $this->pieces[0]->id, 5.6], ['piece', (int) $this->pieces[1]->id, 15.0]], $lines->map(fn ($l) => [$l->kind, (int) $l->product_id, (float) $l->net])->all());
        // والقطعةُ في الباقة ليست بيعًا للبوتيك في التقرير
        $this->assertSame([7.0, 1], [Boutiques::soldTotals($this->shop->id, $this->boutiques[0])['gross'], Boutiques::soldTotals($this->shop->id, $this->boutiques[0])['lines']]);
        $this->assertSame(0.0, Boutiques::soldTotals($this->shop->id, $this->boutiques[1])['gross']);
    }

    /** الإصدارُ لا يكتب طلبًا ولا يُخرج قطعة، ولا يُضاعف إيرادًا */
    public function test_issuing_writes_no_order_and_moves_no_stock_and_its_cost_once(): void
    {
        $qty = (int) $this->pieces[1]->fresh()->quantity;
        $draft = CustomerInvoices::create($this->shop->id, $this->customer, [], [['product_id' => $this->bundles[1]->id, 'description' => 'باقة', 'quantity' => 1, 'unit_price' => 60]]);
        foreach (range(1, 3) as $_) {
            CustomerInvoices::issue($draft->fresh(), $this->owner->id);
        }
        $this->assertSame([0, 0, $qty], [Order::count(), BoutiqueComponentDue::count(), (int) $this->pieces[1]->fresh()->quantity]);
        $this->assertSame([1, 15.0, 15.0, 60.0], [BoutiqueInvoiceLine::count(), $this->payable(), round(Ledger::balance($this->shop->id, 'cogs'), 3), round(Ledger::balance($this->shop->id, 'sales'), 3)]);
        $this->assertSame(1, JournalEntry::where('source', BoutiqueInvoiceDues::PIECE_ACCRUAL)->count());
    }

    /* ═══════════ الاعترافُ والتسوية ═══════════ */

    public function test_the_piece_is_a_cost_of_the_invoices_month_and_settled_once_collected(): void
    {
        Carbon::setTestNow('2027-02-28 10:00:00');
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $this->assertSame([7.0, 0.0, 7.0], [$this->payable(), Boutiques::statement($this->shop->id, $this->boutiques[0]->id, '2027-03')['net'] + 0.0, Boutiques::statement($this->shop->id, $this->boutiques[0]->id, '2027-02')['awaiting']['net']]);
        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->pay($i, 10);
        $this->assertSame(0.0, Boutiques::statement($this->shop->id, $this->boutiques[0]->id, '2027-03')['net'] + 0.0, 'دفعٌ جزئيّ لا يجعلها مستحقّة');
        $this->pay($i);
        $s = Boutiques::statement($this->shop->id, $this->boutiques[0]->id, '2027-03');
        $this->assertSame([7.0, true, 'باقة فضّة'], [$s['net'], $s['lines'][0]['piece'], $s['lines'][0]['parent']]);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->boutiques[0], '2027-03');
        $this->assertSame([7.0, 7.0], [(float) $sheet->net, (float) $sheet->accrued_at_sale]);
        $this->post("/admin/expenses/{$sheet->expense_id}/paid", ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->payable());
        $this->assertSame(7.0, round(Ledger::balance($this->shop->id, 'cogs'), 3), 'التكلفةُ في فبراير مرّة');
        $this->assertSame(7.0, round(Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01')), 3));
    }

    public function test_a_collection_cancelled_after_the_sheet_holds_the_piece(): void
    {
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $p = $this->pay($i);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->boutiques[0], '2027-02');
        CustomerPayments::cancel($p, 'ارتدّ');
        $this->assertSame(7.0, BoutiqueHolds::heldOn($sheet->id));
        $this->post("/admin/expenses/{$sheet->expense_id}/paid", ['method' => 'نقدي'])->assertSessionHasErrors();
        $this->assertSame(7.0, $this->payable());
    }

    /* ═══════════ المرتجع ═══════════ */

    public function test_a_credit_note_asks_each_piece_and_only_what_came_back_lowers_its_due(): void
    {
        $b = $this->bundle(17);
        $i = $this->invoice([[$b, 3]]);
        $line = BoutiqueInvoiceLine::sole();
        $this->assertSame(['p'.$line->id, true, 'باقة فضّة'], [BoutiqueInvoiceDues::pieces($i)[0]['key'], BoutiqueInvoiceDues::pieces($i)[0]['piece'], BoutiqueInvoiceDues::pieces($i)[0]['parent']]);

        // ثلاثُ باقاتٍ رُدّت: قطعةٌ عادت، وقطعةٌ تلفت، وقطعةٌ لم يُتحقَّق منها
        $this->note($i, 51, ['p'.$line->id => ['quantity' => 3, 'sellable' => 1, 'damaged' => 1]]);
        $line->refresh();
        $this->assertSame([3.0, 1.0, 1.0, 1.0], [(float) $line->asked_quantity, (float) $line->returned_quantity, (float) $line->lost_quantity, BoutiqueInvoicePieces::unverified($line)]);
        $this->assertSame(14.0, $this->payable(), 'عادت واحدةٌ من ثلاث');
        $this->assertSame([['id' => $line->id, 'name' => $line->name, 'parent' => 'باقة فضّة', 'boutique' => 'لمى', 'left' => 1.0]], BoutiqueInvoicePieces::waiting($i));

        // ثمّ عادت التي لم يُتحقَّق منها — من شاشة الفاتورة
        $this->post(route('admin.customerInvoices.boutiquePieces', $i->id), ['reason' => 'وُجدت', 'pieces' => [$line->id => ['returned' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame([7.0, []], [$this->payable(), BoutiqueInvoicePieces::waiting($i)]);
        // وما سُئل عنه كلُّه لا يُسأل عنه ثانيةً في إشعار
        $this->assertSame([], array_values(array_filter(BoutiqueInvoiceDues::pieces($i), fn ($p) => $p['piece'])));
        $this->post(route('admin.customerInvoices.boutiquePieces', $i->id), ['reason' => 'x', 'pieces' => [$line->id => ['returned' => 1]]])->assertSessionHasErrors('pieces');

        // والفاتورةُ صارت مسدَّدةً بإشعارها: ما بقي للبوتيك (التالفة) يُسوّى
        $this->assertTrue(BoutiqueInvoiceDues::collected($i->id));
        $this->assertSame(7.0, (float) Boutiques::settle($this->shop->fresh(), $this->boutiques[0], '2027-02')->net);
    }

    public function test_a_piece_that_came_back_after_its_sheet_is_the_boutiques_by_the_piece_rule(): void
    {
        $i = $this->invoice([[$this->bundle(17), 2]]);
        $this->pay($i);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->boutiques[0], '2027-02');
        $line = BoutiqueInvoiceLine::sole();
        // لمى بلا شرط مرتجعٍ موثَّق: قطعةُ الباقة تُحسم بقاعدة القطع، لا تنتظر المحاسب
        $this->note($i, 17, ['p'.$line->id => ['quantity' => 1, 'sellable' => 1]]);
        $note = BoutiqueReturn::sole();
        $this->assertSame([BoutiqueReturn::REDUCED, 'piece', 7.0], [$note->status, $note->basis, (float) $note->amount]);
        $this->assertSame(7.0, BoutiqueReturns::payable($sheet->fresh()->expense));
        $this->assertSame(7.0, round(Ledger::balance($this->shop->id, 'cogs'), 3), 'رُدّت التكلفةُ لا المصروف');
        $this->assertSame(7.0, round(Demo::cogsFor($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01')), 3), 'وتقريرُ الربح يقرأ ما رُدّ');
        // وفرعُ المتجر لا يُنقص من مصروفاته ما رُدّ إلى التكلفة: الجملةُ = الفرعُ + العامّ
        $whole = Profitability::summary($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'));
        $branch = Profitability::summary($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'), (int) Branch::value('id'));
        $this->assertSame(number_format($whole['expenses'], 3), number_format($branch['expenses'] + $branch['unallocated'], 3));
        $this->post("/admin/expenses/{$sheet->expense_id}/paid", ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->note($i, 17, ['p'.$line->id => ['quantity' => 1, 'sellable' => 1]]);
        $this->assertSame([BoutiqueReturn::CLAIM, 7.0], [BoutiqueReturn::latest('id')->first()->status, round(Ledger::balance($this->shop->id, 'boutique_receivable'), 3)]);
    }

    public function test_a_cancelled_invoice_gives_back_its_pieces_cost(): void
    {
        $i = $this->invoice([[$this->bundles[3], 1]]);
        $this->assertGreaterThan(0, $this->payable());
        CustomerInvoices::cancel($i->fresh(), 'خطأ', $this->owner->id);
        $this->assertSame([0.0, 0.0], [$this->payable(), round(Ledger::balance($this->shop->id, 'cogs'), 3) + 0.0]);
        $this->assertSame(0.0, round(Demo::cogsFor($this->shop->id, null), 3) + 0.0, 'وتقريرُ الربح يقرأ الردّ');
        $this->assertSame(0, BoutiqueInvoiceLine::whereNull('voided_at')->count());
    }

    public function test_more_than_was_sold_or_a_fraction_is_refused(): void
    {
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $line = BoutiqueInvoiceLine::sole();
        foreach ([['quantity' => 2], ['quantity' => 1, 'sellable' => 1, 'damaged' => 1], ['quantity' => 0.5, 'sellable' => 0.5]] as $bad) {
            try {
                $this->note($i, 1, ['p'.$line->id => $bad]);
                $this->fail(json_encode($bad));
            } catch (ValidationException|\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([0.0, 7.0], [(float) $line->fresh()->asked_quantity, $this->payable()]);
    }

    /* ═══════════ القراءة ═══════════ */

    public function test_the_sales_section_the_statement_the_register_and_the_ledger_agree(): void
    {
        $i = $this->invoice([[$this->bundles[2], 2, [[$this->paid[2], 1]]], [$this->pieces[1], 1]]);
        $this->pay($i);
        foreach ($this->boutiques as $b) {
            $s = Boutiques::statement($this->shop->id, $b->id, '2027-02');
            $sum = BoutiqueSales::summary($this->shop->id, ['period' => '2027-02', 'boutique' => $b->id, 'type' => null, 'settle' => null, 'state' => null, 'q' => '', 'page' => 1]);
            $this->assertSame([$s['net']], [$sum['settlement']['pending']['standalone']], $b->name);
            $this->assertSame(number_format(BoutiqueMovements::balance($this->shop->id, $b->id)['net'], 3), number_format(BoutiqueMovements::effects($this->shop->id, $b->id), 3), $b->name);
        }
        $rows = BoutiqueSales::rows($this->shop->id, ['period' => '2027-02', 'boutique' => null, 'type' => 'bundle', 'settle' => null, 'state' => null, 'q' => '', 'page' => 1])->items();
        $this->assertNotEmpty($rows);
        $this->assertSame(['bundle'], collect($rows)->pluck('type')->unique()->values()->all());
        $this->assertSame('باقة 2', $rows[0]['parent']);
        $this->assertSame(round((float) BoutiqueInvoiceLine::sum('net'), 3), $this->payable());
    }

    public function test_the_shares_card_names_the_bundle_and_a_seller_sees_no_money(): void
    {
        $i = $this->invoice([[$this->bundles[1], 1]]);
        $props = $this->get(route('admin.customerInvoices.show', $i->id))->assertOk()->viewData('page')['props'];
        $this->assertSame([['piece', 'باقة 1', 15.0]], array_map(fn ($s) => [$s['kind'], $s['parent'], $s['net']], $props['boutiqueShares']));
        $seller = User::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 's@pieces.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط']);
        $sold = $this->actingAs($seller)->get(route('admin.customerInvoices.show', $i->id))->assertOk()->viewData('page')['props'];
        $this->assertSame([], $sold['boutiqueShares']);
        $this->assertStringNotContainsString('15', json_encode($sold['boutiquePieces']));
    }

    /** بندُ الباقة ليس صنفًا منفردًا: مفتاحُه في الإشعار لا يُنقص قطعتَها */
    public function test_the_bundles_own_line_key_never_lowers_its_piece(): void
    {
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $this->note($i, 17, ['i'.BoutiqueInvoiceLine::sole()->customer_invoice_item_id => ['quantity' => 1]]);
        $this->assertSame([0.0, 7.0], [(float) BoutiqueInvoiceLine::sole()->returned_quantity, $this->payable()]);
    }

    public function test_recording_a_pieces_state_is_for_who_writes_credit_notes(): void
    {
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $line = BoutiqueInvoiceLine::sole();
        $this->note($i, 17, ['p'.$line->id => ['quantity' => 1]]);
        $seller = User::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 's2@pieces.om', 'password' => bcrypt('x'), 'role' => 'custom', 'status' => 'نشط', 'permissions' => ['customer_invoices']]);
        $this->actingAs($seller)->post(route('admin.customerInvoices.boutiquePieces', $i->id), ['reason' => 'x', 'pieces' => [$line->id => ['returned' => 1]]])->assertForbidden();
        $this->assertSame(0.0, (float) $line->fresh()->returned_quantity);
    }

    public function test_another_shop_never_reaches_a_piece(): void
    {
        $i = $this->invoice([[$this->bundle(17), 1]]);
        $line = BoutiqueInvoiceLine::sole();
        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $them = User::create(['business_id' => $other->id, 'name' => 'ج', 'email' => 'n@pieces.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($them)->post(route('admin.customerInvoices.boutiquePieces', $i->id), ['reason' => 'x', 'pieces' => [$line->id => ['returned' => 1]]])->assertNotFound();
        $this->assertSame(0.0, (float) $line->fresh()->returned_quantity);
    }
}
