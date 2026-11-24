<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الفاتورةُ المختلطة: منتجاتُ التاجر ومنتجاتُ بوتيكاته في ورقةٍ واحدة.
 *
 * لا تُنشأ فاتورةٌ لكلّ بوتيك، ولا يُجمع إجماليٌّ مرّتين. ومجموعُ الأنصبة
 * مجموعُ البنود بالبيسة، والخصمُ والضريبةُ والتوصيلُ والمدفوعُ في أماكنها.
 */
class AMixedInvoiceDividesItsMoneyAndCountsNothingTwiceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $mine;

    private Product $silver;

    private Product $perfume;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->set('vat_enabled', '0');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $this->mine = $this->product('باقة التاجر', 20);
        $this->silver = $this->product('قطعة فضّة', 6, $this->lama->id);
        $this->perfume = $this->product('عطر صغير', 12, $this->reem->id);
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

    private function product(string $name, float $price, ?int $boutique = null, array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 500,
            'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique,
        ]);
    }

    /** @param list<array{0: Product, 1?: int}> $lines */
    private function sell(array $lines, array $extra = []): Order
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => array_map(fn ($l) => ['id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1] ?? 1, 'price' => (float) $l[0]->sellingPrice()], $lines),
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('m', true), ...$extra,
        ])->assertOk();

        return Order::where('business_id', $this->shop->id)->orderByDesc('id')->firstOrFail();
    }

    private function rows(array $params = []): array
    {
        return $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales', ['period' => self::PERIOD, ...$params]))->assertOk()->json();
    }

    private function summary(array $params = []): array
    {
        return $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.summary', ['period' => self::PERIOD, ...$params]))->assertOk()->json();
    }

    private function invoice(Order $order): array
    {
        return $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $order->id))->assertOk()->json();
    }

    private static function baisa(float $x): int
    {
        return (int) round($x * 1000);
    }

    /** ما يجب أن يصحّ في كلّ فاتورة: لا بيسةٌ تزيد ولا تنقص، ولا تُحسب مرّتين */
    private function assertWhole(array $inv): void
    {
        $b = fn (float|int|null $x) => self::baisa((float) $x);
        $m = $inv['money'];
        $owed = array_sum(array_map(fn ($p) => $b($p['owed']), $inv['shares']['boutiques']));

        $this->assertSame($b($inv['shares']['lines_total']), $owed + $b($inv['shares']['shop']), 'مجموعُ الأنصبة ≠ مجموعُ البنود');
        $this->assertSame($b($m['boutiques_owed']), $owed);
        $this->assertSame($b($m['shop_after_discount']), $b($inv['shares']['shop']) - $b($m['discount']), 'الخصمُ يتحمّله المتجرُ وحده');
        $this->assertTrue($inv['shares']['reconciles'], 'البنودُ لا تطابق إجماليَّ الفاتورة');
        // إجماليُّ العميل = البوتيكات + حصّةُ المتجر بعد الخصم + الضريبةُ المضافة + التوصيل
        $this->assertSame(
            $b($m['total']),
            $owed + $b($m['shop_after_discount']) + ($m['tax_basis'] === 'added' ? $b($m['tax']) : 0) + $b($m['delivery_fee']),
            'الإجماليُّ لا يساوي ما يُنسب لجهاته — أو حُسب شيءٌ مرّتين',
        );
        $this->assertSame($b($m['total']), $b($m['paid']) + $b($m['balance']), 'المدفوعُ والمتبقّي ليسا الإجمالي');
    }

    /* ═══════════ أنواعُ الفاتورة ═══════════ */

    public function test_an_invoice_of_the_merchants_own_goods_is_not_a_boutique_invoice(): void
    {
        $order = $this->sell([[$this->mine, 2]]);

        $this->assertSame(0, $this->rows()['total']);
        $s = $this->summary();
        $this->assertEquals(0, $s['standalone']['gross']);
        $this->assertEquals(0, $s['owed']);
        $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $order->id))->assertNotFound();
    }

    public function test_an_invoice_of_one_boutique_gives_the_shop_its_commission_and_nothing_else(): void
    {
        $inv = $this->invoice($this->sell([[$this->silver, 4]]));
        $this->assertWhole($inv);
        $this->assertEquals(19.2, $inv['money']['boutiques_owed']);
        $this->assertEquals(4.8, $inv['shares']['shop']);
        $this->assertEquals(4.8, $inv['money']['shop_after_discount']);
    }

    /** المثالُ الإلزاميّ: ٣٢ = بضاعةُ التاجر ٢٠ + بضاعةُ بوتيكٍ ١٢ بنسبة ٢٠٪ */
    public function test_thirty_two_rials_is_nine_six_to_the_boutique_and_twenty_two_four_to_the_shop(): void
    {
        $order = $this->sell([[$this->mine], [$this->silver, 2]]);
        $inv = $this->invoice($order);

        $this->assertWhole($inv);
        $this->assertEquals(32.0, $inv['money']['total']);
        $this->assertEquals(32.0, $inv['shares']['lines_total']);
        $this->assertEquals(9.6, $inv['money']['boutiques_owed'], 'مستحقُّ البوتيك');
        $this->assertEquals(2.4, $inv['shares']['boutiques'][0]['commission'], 'عمولةُ المتجر');
        $this->assertEquals(22.4, $inv['shares']['shop'], 'حصّةُ المتجر الإجماليّة');
        $this->assertEquals(32.0, $inv['shares']['boutiques'][0]['owed'] + $inv['shares']['shop'], 'إجماليُّ الأنصبة');
        $this->assertEquals(32.0, $inv['money']['paid']);
        // ولا تُسمّى صافي ربح — لا مفتاحَ باسمه في الاستجابة
        $this->assertStringNotContainsString('profit', json_encode($inv));
        // ولا تُنشأ فاتورةٌ لكلّ بوتيك، ولا يُكرَّر إيراد
        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertEquals(32.0, (float) Order::where('business_id', $this->shop->id)->sum('total'));
    }

    public function test_several_boutiques_with_different_rates_and_quantities_are_each_their_own(): void
    {
        $inv = $this->invoice($this->sell([[$this->mine, 3], [$this->silver, 5], [$this->perfume, 7]]));
        $this->assertWhole($inv);
        $by = collect($inv['shares']['boutiques'])->keyBy('name');
        $this->assertEquals([30.0, 6.0, 24.0], [$by['بوتيك لمى']['standalone_gross'], $by['بوتيك لمى']['commission'], $by['بوتيك لمى']['owed']]);
        $this->assertEquals([84.0, 8.4, 75.6], [$by['بوتيك ريم']['standalone_gross'], $by['بوتيك ريم']['commission'], $by['بوتيك ريم']['owed']]);
        $this->assertEquals(60.0 + 6.0 + 8.4, $inv['shares']['shop']);
    }

    /** المثالُ الإلزاميّ: ٤٢ = المتجر ٢٠ + لمى ١٢ بـ٢٠٪ + ريم ١٠ بـ١٠٪ */
    public function test_forty_two_rials_is_nine_six_and_nine_and_twenty_three_four(): void
    {
        $scarf = $this->product('شال', 10, $this->reem->id);
        $order = $this->sell([[$this->mine], [$this->silver, 2], [$scarf]]);
        $inv = $this->invoice($order);
        $this->assertWhole($inv);

        $by = collect($inv['shares']['boutiques'])->keyBy('name');
        $this->assertEquals(42.0, $inv['money']['total'], 'إجماليُّ الفاتورة');
        $this->assertEquals(9.6, $by['بوتيك لمى']['owed']);
        $this->assertEquals(9.0, $by['بوتيك ريم']['owed']);
        $this->assertEquals(3.4, round(array_sum(array_column($inv['shares']['boutiques'], 'commission')), 3), 'عمولةُ المتجر');
        $this->assertEquals(20.0, collect($inv['lines'])->firstWhere('name', 'باقة التاجر')['shop_share'], 'بضاعةُ المتجر');
        $this->assertEquals(23.4, $inv['shares']['shop'], 'حصّةُ المتجر');
        $this->assertSame(42000, self::baisa(9.6) + self::baisa(9.0) + self::baisa(23.4));
        $this->assertStringNotContainsString('profit', json_encode($inv));

        // لا يُحسب إجماليُّ الفاتورة لكلّ بوتيك: كلٌّ ببضاعته، وحصّةُ المتجر مرّةً
        $gross = fn (Boutique $b) => $this->summary(['boutique' => (string) $b->id])['standalone'];
        $this->assertEquals([12.0, 2.4, 9.6], [$gross($this->lama)['gross'], $gross($this->lama)['commission'], $gross($this->lama)['net']]);
        $this->assertEquals([10.0, 1.0, 9.0], [$gross($this->reem)['gross'], $gross($this->reem)['commission'], $gross($this->reem)['net']]);
        $this->assertEquals([22.0, 3.4, 18.6], [$this->summary()['standalone']['gross'], $this->summary()['standalone']['commission'], $this->summary()['standalone']['net']]);

        // والورقتان بما قاله القسمُ بالبيسة، والدفترُ يحمل الإيرادَ مرّةً
        $this->actingAs($this->owner);
        $this->assertEquals(9.6, (float) Boutiques::settle($this->shop, $this->lama, self::PERIOD)->net);
        $this->assertEquals(9.0, (float) Boutiques::settle($this->shop, $this->reem, self::PERIOD)->net);
        $this->assertEquals(42.0, Ledger::balance($this->shop->id, 'sales'));
        $this->assertEquals(18.6, Ledger::balance($this->shop->id, 'boutique_payable'));
    }

    /** البنودُ قبل الخصم وبعده، والمدفوعُ والمتبقي — كلٌّ في مكانه ولا يُخلط بمستحقّ البوتيك */
    public function test_before_and_after_discount_paid_and_left_are_each_said(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'FIVE', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);
        $order = $this->sell([[$this->mine], [$this->silver, 2]], ['coupon_code' => 'FIVE']);
        DB::table('orders')->where('id', $order->id)->update(['balance_due' => 7]);
        $m = $this->invoice($order)['money'];
        $this->assertEquals([32.0, 5.0, 27.0, 27.0, 20.0, 7.0, 9.6], [$m['lines'], $m['discount'], $m['after_discount'], $m['total'], $m['paid'], $m['balance'], $m['boutiques_owed']]);
    }

    /** قناةُ البيع من الطلب كما كُتب — في الصفّ والفاتورة */
    public function test_the_row_and_the_invoice_say_which_door_sold_it(): void
    {
        $order = $this->sell([[$this->silver]]);
        foreach (['pos' => 'نقطة البيع', 'website' => 'الموقع الإلكتروني', null => 'غير محدّدة'] as $channel => $label) {
            DB::table('orders')->where('id', $order->id)->update(['channel' => $channel ?: null]);
            $this->assertSame($label, $this->rows()['rows'][0]['channel']);
            $this->assertSame($label, $this->invoice($order)['order']['channel']);
        }
    }

    /* ═══════════ من يرى الفاتورة كاملة ═══════════ */

    /** من يقرأ مالَ البوتيك ولا يفتح المبيعات: بنودُ البوتيكات وحدها، ولا رقمَ يُستنتج منه ما للمتجر */
    public function test_without_the_sales_section_only_the_boutiques_lines_are_read(): void
    {
        $order = $this->sell([[$this->mine], [$this->silver, 2], [$this->perfume]]);
        $accountant = User::create(['business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'acc@abaad.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'products', 'expenses']]);

        $part = $this->actingAs($accountant)->getJson(route('admin.boutiques.sales.order', $order->id))->assertOk()->json();
        $this->assertFalse($part['full']);
        $this->assertSame(['قطعة فضّة', 'عطر صغير'], array_column($part['lines'], 'name'));
        $this->assertNull($part['money']);
        foreach (['total', 'discount', 'tax', 'delivery_fee', 'subtotal'] as $k) {
            $this->assertNull($part['order'][$k], $k);
        }
        $this->assertNull($part['shares']['shop']);
        $this->assertNull($part['shares']['lines_total']);
        $this->assertSame([false, null], [$part['can_open'], $part['url']]);
        $json = json_encode($part, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('باقة التاجر', $json);
        foreach (['44', '26.6', '20.0', '23.6'] as $leak) {
            $this->assertStringNotContainsString(':'.$leak, $json, 'رقمٌ من مال المتجر');
        }
        // وما للبوتيكات كما هو في الكاملة
        $full = $this->invoice($order);
        $this->assertTrue($full['full']);
        $this->assertCount(3, $full['lines']);
        $this->assertEquals($full['shares']['boutiques'], $part['shares']['boutiques']);
        $this->assertEquals($full['totals'], $part['totals']);

        // ومن زِيد له قسمُ المبيعات رآها كاملة
        $accountant->update(['permissions' => ['dashboard', 'products', 'expenses', 'orders']]);
        $this->assertTrue($this->actingAs($accountant->fresh())->getJson(route('admin.boutiques.sales.order', $order->id))->json('full'));
    }

    /** باقةُ المتجر التي دخلتها قطعةُ بوتيك: يُقرأ مستحقُّ القطعة، ولا يُقرأ ثمنُ الباقة */
    public function test_without_the_sales_section_the_shops_bouquet_is_named_without_its_price(): void
    {
        $this->shop->forceFill(['boutique_components_enabled' => true])->save();
        RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $this->mine->id, 'component_product_id' => $this->silver->id, 'quantity' => 1]);
        $order = $this->sell([[$this->mine]]);
        $accountant = User::create(['business_id' => $this->shop->id, 'name' => 'محاسب', 'email' => 'acc2@abaad.om', 'password' => bcrypt('x'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'products', 'expenses']]);
        $line = $this->actingAs($accountant)->getJson(route('admin.boutiques.sales.order', $order->id))->assertOk()->json('lines.0');
        $this->assertSame('باقة التاجر', $line['name']);
        $this->assertSame([null, null, null, null], [$line['price'], $line['total'], $line['shop_remaining'], $line['shop_share']]);
        $this->assertEquals(6.0, $line['pieces'][0]['amount']);
    }

    /* ═══════════ ما لا يدخل هذه الأرقام ═══════════ */

    /** فاتورةُ عميلٍ بلا طلب: تُقال بعددها ومبلغها كما كُتبت، ولا تدخل مبيعاتٍ ولا مستحقًّا */
    public function test_what_customer_invoices_sold_is_said_and_never_counted(): void
    {
        $this->sell([[$this->silver]]);
        $before = $this->summary();
        $this->assertEquals(['lines' => 0, 'invoices' => 0, 'amount' => 0.0], $before['uncounted']);

        $company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
        $invoice = fn (array $lines, string $day = '2027-02-10') => CustomerInvoices::create($this->shop->id, $company, ['issued_at' => $day],
            array_map(fn ($p) => ['product_id' => $p->id, 'description' => $p->name, 'quantity' => 1, 'unit_price' => (float) $p->price], $lines));
        CustomerInvoices::issue($invoice([$this->silver, $this->mine]));
        CustomerInvoices::issue($invoice([$this->perfume]));
        CustomerInvoices::issue($invoice([$this->silver], '2027-01-20'));            // شهرٌ آخر
        $invoice([$this->silver]);                                                    // مسودّة
        CustomerInvoices::cancel(CustomerInvoices::issue($invoice([$this->silver])), 'أُلغيت');
        // آجلٌ من الصندوق: له طلبٌ محسوب فلا يُقال مرّتين
        $this->sell([[$this->silver]], ['credit' => true, 'customer_id' => $company->id]);

        $after = $this->summary();
        $this->assertEquals(['lines' => 2, 'invoices' => 2, 'amount' => 18.0], $after['uncounted']);
        $this->assertEquals(12.0, $after['standalone']['gross'], 'الصندوقُ نقدًا وآجلًا وحدهما');
        $this->assertEquals(9.6, $after['owed']);
        $lama = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(['lines' => 1, 'invoices' => 1, 'amount' => 6.0], $lama['uncounted']);

        // ومتجرٌ آخر لا يُقرأ منه شيء
        [$other, $otherOwner] = [Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]), null];
        Ledger::seedChart($other->id);
        $otherOwner = User::create(['business_id' => $other->id, 'name' => 'الجار', 'email' => 'nb@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->assertEquals(0, $this->actingAs($otherOwner)->getJson(route('admin.boutiques.sales.summary', ['period' => self::PERIOD]))->assertOk()->json('uncounted.lines'));
    }

    /** معاملاتُ الرابط لا تغيّر المتجرَ ولا الفرع: كلُّ بابٍ مقيَّدٌ بمتجر الجالس */
    public function test_the_link_cannot_choose_another_shop_or_branch(): void
    {
        $order = $this->sell([[$this->silver]]);
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);
        $mine = $this->rows();
        foreach ([['business_id' => $other->id], ['business' => $other->id], ['branch_id' => $theirBranch->id], ['branch' => $theirBranch->id], ['shop' => $other->id]] as $extra) {
            $this->assertEquals($mine, $this->rows($extra), json_encode($extra));
            $this->assertEquals($this->summary(), $this->summary($extra), json_encode($extra));
            $this->assertSame($order->number, $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', [$order->id, ...$extra]))->assertOk()->json('order.number'));
        }
    }

    /* ═══════════ ما يتغيّر بعد البيع ═══════════ */

    /** سعرٌ أو نسبةٌ أو صاحبٌ أو حذفٌ بعد البيع لا يمسّ ما بيع — لا الصفّ ولا الملخّص ولا الفاتورة */
    public function test_nothing_after_the_sale_rewrites_what_was_sold(): void
    {
        $order = $this->sell([[$this->mine], [$this->silver, 2], [$this->perfume]]);
        $before = [$this->rows()['rows'], $this->summary(), $this->invoice($order)];
        // ما سيكون عليه كشفُ البوتيك قبل وبعد
        $stLama = Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD);

        $this->silver->update(['price' => 99, 'name' => 'اسمٌ جديد']);        // السعرُ والاسم
        $this->lama->update(['commission_rate' => 70, 'name' => 'بوتيك آخر']);  // النسبةُ والاسم
        $this->silver->update(['boutique_id' => $this->reem->id]);            // نُقل
        $this->perfume->update(['boutique_id' => null]);                      // أُزيل من بوتيكه
        $this->perfume->delete();                                             // وحُذف

        $this->assertEquals($before[0], $this->rows()['rows']);
        $this->assertEquals($before[2]['lines'], $this->invoice($order)['lines']);
        $this->assertEquals($before[2]['shares'], $this->invoice($order)['shares']);
        $s = $this->summary();
        foreach (['standalone', 'owed', 'components', 'returns'] as $k) {
            $this->assertEquals($before[1][$k], $s[$k]);
        }
        $this->assertEquals($stLama['net'], Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD)['net']);
    }

    /* ═══════════ الخصوم ═══════════ */

    /**
     * ثلاثةُ خصومٍ ليست قاعدةً واحدة:
     *
     * خصمُ المنتج نفسِه يُنقص سعرَ بيعه، فيقع على البوتيك كلِّه بحصّته (عمولتُه
     * من السعر المخصوم). وخصمُ الفاتورة والكوبون والنقاط يقع على المتجر وحده،
     * ولا ينقص به نصيبُ البوتيك.
     */
    public function test_a_products_own_discount_is_the_boutiques_and_the_invoices_discounts_are_the_shops(): void
    {
        // ١٠٪ خصمٌ على منتج البوتيك نفسِه: يُباع بـ٥٫٤ بدل ٦
        $cheap = $this->product('قطعة مخفّضة', 6, $this->lama->id, ['discount' => 10]);
        $inv = $this->invoice($this->sell([[$this->mine], [$cheap]]));
        $this->assertWhole($inv);
        $this->assertEquals(25.4, $inv['money']['lines']);
        $this->assertEquals(0, $inv['money']['discount'], 'خصمُ المنتج داخل السعر لا خصمُ فاتورة');
        $this->assertEquals(4.32, $inv['money']['boutiques_owed'], '٥٫٤ ناقصَ ٢٠٪ — حصّةٌ من السعر المخصوم');
        $this->assertEquals(1.08 + 20, $inv['shares']['shop']);

        // وكوبونٌ ثابتٌ على الفاتورة: للمتجر وحده
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'FIVE', 'type' => 'مبلغ', 'value' => 5, 'active' => true]);
        $fixed = $this->invoice($this->sell([[$this->mine], [$this->silver]], ['coupon_code' => 'FIVE']));
        $this->assertWhole($fixed);
        $this->assertEquals([5.0, 5.0, 0.0], [$fixed['money']['discount'], $fixed['money']['discount_coupon'], $fixed['money']['discount_points']]);
        $this->assertEquals(4.8, $fixed['money']['boutiques_owed']);
        $this->assertEquals(16.2, $fixed['money']['shop_after_discount']);
        $this->assertEquals(21.0, $fixed['money']['total']);

        // وكوبونٌ نسبيّ — على المجموع، وللمتجر وحده
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'TENPCT', 'type' => 'نسبة', 'value' => 10, 'active' => true]);
        $pct = $this->invoice($this->sell([[$this->mine], [$this->silver]], ['coupon_code' => 'TENPCT']));
        $this->assertWhole($pct);
        $this->assertEquals(2.6, $pct['money']['discount'], '١٠٪ من ٢٦');
        $this->assertEquals(4.8, $pct['money']['boutiques_owed']);
        $this->assertEquals(18.6, $pct['money']['shop_after_discount']);
    }

    public function test_loyalty_points_and_a_coupon_together_are_both_the_shops_and_stay_apart(): void
    {
        foreach (['loyalty_enabled' => '1', 'loyalty_redeem_max_pct' => '50', 'loyalty_redeem_min' => '100'] as $k => $v) {
            $this->set($k, $v);
        }
        $customer = Customer::create(['business_id' => $this->shop->id, 'language' => 'ar', 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000]);
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'TWO', 'type' => 'مبلغ', 'value' => 2, 'active' => true]);

        $order = $this->sell([[$this->mine], [$this->silver]], ['customer_id' => $customer->id, 'coupon_code' => 'TWO', 'redeem_points' => 999999]);
        $inv = $this->invoice($order);
        $this->assertWhole($inv);
        $m = $inv['money'];
        $this->assertEquals(2.0, $m['discount_coupon']);
        $this->assertGreaterThan(0, $m['discount_points'], 'النقاطُ لم تُستبدل — الاختبارُ لا يقيس شيئًا');
        $this->assertEquals(round((float) $order->discount, 3), round($m['discount_coupon'] + $m['discount_points'], 3), 'الخصمان هما الخصمُ كلُّه — بلا «خصمٍ آخر»');
        $this->assertEquals(4.8, $m['boutiques_owed']);
    }

    /** @return array<string, array{string, string}> */
    public static function bothModes(): array
    {
        return ['مضافة' => ['exclusive', 'added'], 'ضمن الأسعار' => ['inclusive', 'inside']];
    }

    /** كلُّ ما يجتمع: كوبون، نقاط، ضريبة، توصيل، ومرتجعٌ جزئيّ ثمّ كامل لبند */
    #[DataProvider('bothModes')]
    public function test_every_thing_at_once_stays_whole_in_both_tax_modes(string $mode, string $basis): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $this->set('tax_mode', $mode);
        foreach (['loyalty_enabled' => '1', 'loyalty_redeem_max_pct' => '20', 'loyalty_redeem_min' => '100'] as $k => $v) {
            $this->set($k, $v);
        }
        $customer = Customer::create(['business_id' => $this->shop->id, 'language' => 'ar', 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000]);
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'THREE', 'type' => 'مبلغ', 'value' => 3, 'active' => true]);

        $order = $this->sell([[$this->mine], [$this->silver, 4], [$this->perfume]], [
            'customer_id' => $customer->id, 'coupon_code' => 'THREE', 'redeem_points' => 999999, 'delivery_fee' => 2,
        ]);
        $this->actingAs($this->owner);
        $inv = $this->invoice($order);
        $this->assertWhole($inv);
        $this->assertSame($basis, $inv['money']['tax_basis']);
        $this->assertEquals(19.2 + 10.8, $inv['money']['boutiques_owed'], 'الخصمُ والضريبةُ لا يمسّان نصيبَ البوتيك');
        if ($basis === 'inside') {
            $this->assertSame(self::baisa($inv['money']['shop_after_tax']), self::baisa($inv['money']['shop_after_discount']) - self::baisa($inv['money']['tax']));
        }

        // مرتجعٌ جزئيّ ثمّ ما بقي: الفاتورةُ تبقى تامّةً بعد كلٍّ منهما
        $line = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->lama->id)->firstOrFail();
        OrderCorrection::setQuantity($order->fresh(), $line, 2, 'رُدّ اثنان');
        $inv = $this->invoice($order->refresh());
        $this->assertWhole($inv);
        $this->assertEquals(9.6 + 10.8, $inv['money']['boutiques_owed']);

        OrderCorrection::setQuantity($order->fresh(), $line->fresh(), 0, 'رُدّ البند كلُّه');
        $inv = $this->invoice($order->refresh());
        $this->assertWhole($inv);
        $this->assertSame(['بوتيك ريم'], collect($inv['shares']['boutiques'])->pluck('name')->all());
        $this->assertEquals(0, $this->summary(['boutique' => (string) $this->lama->id])['owed']);
    }

    /* ═══════════ المدفوع ═══════════ */

    /** ما بقي على العميل لا يُقرأ مدفوعًا، ولا يُنقص مستحقَّ البوتيك */
    public function test_what_the_customer_still_owes_is_not_called_paid(): void
    {
        $order = $this->sell([[$this->mine], [$this->silver, 2]]);
        DB::table('orders')->where('id', $order->id)->update(['balance_due' => 12]);
        $inv = $this->invoice($order);
        $this->assertWhole($inv);
        $this->assertEquals([32.0, 20.0, 12.0], [$inv['money']['total'], $inv['money']['paid'], $inv['money']['balance']]);
        $this->assertEquals(9.6, $inv['money']['boutiques_owed']);

        DB::table('orders')->where('id', $order->id)->update(['balance_due' => 0, 'payment_status' => 'غير مدفوع']);
        $unpaid = $this->invoice($order);
        $this->assertEquals([0.0, 32.0], [$unpaid['money']['paid'], $unpaid['money']['balance']], 'غيرُ المدفوعة لم يُدفع منها شيء');
        $this->assertEquals(9.6, $unpaid['money']['boutiques_owed']);
    }

    /* ═══════════ المرتجع قبل الورقة وبعدها وبعد السداد ═══════════ */

    public function test_a_return_before_the_sheet_and_after_it_and_after_its_payment(): void
    {
        $first = $this->sell([[$this->mine], [$this->silver, 2]]);
        $second = $this->sell([[$this->silver, 3]]);
        $third = $this->sell([[$this->silver]]);
        $this->actingAs($this->owner);

        // قبل الورقة: يخرج من المبيعات كأنّه لم يُبَع
        OrderCorrection::cancel($first->fresh(), 'رُدّ', boutiqueReturned: true);
        $this->assertEquals(24.0, $this->summary(['boutique' => (string) $this->lama->id])['standalone']['gross']);

        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->assertEquals(24.0, $sheet->gross);
        $this->assertSame(['issued'], collect($this->rows()['rows'])->where('invoice_state', 'active')->pluck('settle_state')->unique()->values()->all());
        $kept = [$sheet->gross, $sheet->commission, $sheet->net, $sheet->number];

        // بعد إصدارها (لم تُسدَّد): الورقةُ لا تُمسّ
        $items = OrderItem::where('order_id', $second->id)->get();
        OrderCorrection::cancel($second->fresh(), 'رُدّ بعد الورقة', boutiqueReturned: true,
            boutiqueLines: $items->mapWithKeys(fn ($i) => [$i->id => ['sellable' => (int) $i->quantity, 'damaged' => 0]])->all());
        $sheet->refresh();
        $this->assertSame($kept, [$sheet->gross, $sheet->commission, $sheet->net, $sheet->number], 'إلغاءُ بيعةٍ أعاد كتابة ورقةٍ صدرت');

        // وبعد سدادها
        $this->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'نقدي']);
        $payable = round((float) BoutiqueReturns::payable($sheet->fresh()->expense), 3);
        $this->assertSame(Expense::PAID, $sheet->fresh()->expense->status);
        $s = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals($payable, $s['settlement']['paid']);
        $this->assertEquals(0, $s['settlement']['unpaid']);

        // ثمّ مرتجعٌ ثالث بعد السداد: الورقةُ المسدَّدة كما هي، والمرتجعُ يُقال منفصلًا
        $paidNet = (float) $sheet->fresh()->net;
        $items = OrderItem::where('order_id', $third->id)->get();
        OrderCorrection::cancel($third->fresh(), 'رُدّ بعد السداد', boutiqueReturned: true,
            boutiqueLines: $items->mapWithKeys(fn ($i) => [$i->id => ['sellable' => (int) $i->quantity, 'damaged' => 0]])->all());
        $this->assertSame($paidNet, (float) $sheet->fresh()->net);
        $after = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertGreaterThan(0, $after['returns']['notes'], 'مرتجعٌ بعد الورقة لم يُقَل');
        $this->assertEquals($s['settlement']['paid'], $after['settlement']['paid'], 'ما سُدِّد سُدِّد');
        $this->assertEquals(0, $after['standalone']['gross'], 'الملغياتُ خرجت من المبيعات ومرّةً واحدة');
    }

    /** مرتجعٌ من فاتورةٍ مختلطة يمسّ بوتيكَه وحده — قبل الورقة وبعدها وبعد سدادها */
    public function test_a_return_touches_only_the_boutique_it_was_of(): void
    {
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $this->lama->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'عقد 1']);
        $orders = [];
        foreach (['قبل', 'بعد', 'مسدَّدة'] as $_) {
            $orders[] = $this->sell([[$this->mine], [$this->silver, 2], [$this->perfume]]);
        }
        $this->actingAs($this->owner);
        $silverOf = fn (Order $o) => OrderItem::where('order_id', $o->id)->where('boutique_id', $this->lama->id)->sole();
        $lower = fn (Order $o) => OrderCorrection::setQuantity($o->fresh(), $silverOf($o), 1, 'رُدّت قطعة', OrderCorrection::SETTLE_REFUNDED, 'نقدي');
        // نقصُ بندٍ حملته ورقةٌ إشعارٌ ينتظر استلامَ القطعة، ثمّ يُحسم بشرط الاتّفاقية الموثَّق
        $receive = fn (Order $o) => BoutiqueReturns::receive(BoutiqueReturn::where('order_id', $o->id)->sole(), 1, 0, 'عادت سليمة');
        $reem = fn () => collect($this->summary(['boutique' => (string) $this->reem->id]))->only(['standalone', 'owed', 'settlement', 'returns'])->all();
        $reemBefore = $reem();

        // قبل الورقة: تنقص لمى قطعةً (٦ − ٢٠٪ = ٤٫٨) ولا تتحرّك ريم
        $lower($orders[0]);
        $this->assertEquals(30.0, $this->summary(['boutique' => (string) $this->lama->id])['standalone']['gross'], '٣٦ − ٦');
        $this->assertEquals($reemBefore, $reem(), 'مرتجعُ لمى مسّ ريم');

        $lama = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $reemSheet = Boutiques::settle($this->shop, $this->reem, self::PERIOD);
        $frozen = fn () => [$lama->fresh()->only(['number', 'gross', 'commission', 'net', 'lines_count']), $reemSheet->fresh()->only(['number', 'gross', 'commission', 'net', 'lines_count'])];
        $kept = $frozen();
        $reemIssued = $reem();

        // بعد الورقة وقبل سدادها: إشعارٌ يُنقص ما يُدفع للمى بحصّتها وحدها، والورقتان كما صدرتا
        $lower($orders[1]);
        $this->assertSame([BoutiqueReturn::AWAITING, 24.0], [BoutiqueReturn::where('order_id', $orders[1]->id)->value('status'), round((float) BoutiqueReturns::payable($lama->fresh()->expense), 3)], 'لا يُنقص شيءٌ قبل أن تُستلم');
        $receive($orders[1]);
        $this->assertSame($kept, $frozen());
        $this->assertEquals(4.8, (float) DB::table('boutique_returns')->where('boutique_id', $this->lama->id)->sum('amount'));
        $this->assertEquals(0, DB::table('boutique_returns')->where('boutique_id', $this->reem->id)->count());
        $this->assertEquals(24.0 - 4.8, round((float) BoutiqueReturns::payable($lama->fresh()->expense), 3), 'صافيها ٢٤ ناقصَ ٤٫٨');
        $this->assertEquals($reemIssued, $reem());

        // بعد سدادها: مطالبةٌ على البوتيك لا تُعيد كتابةَ ورقةٍ سُدِّدت
        $this->post(route('admin.expenses.paid', $lama->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $cash = Ledger::balance($this->shop->id, 'cash');
        $lower($orders[2]);
        $receive($orders[2]);
        $this->assertSame($kept, $frozen());
        $s = $this->summary(['boutique' => (string) $this->lama->id]);
        $this->assertEquals(4.8, $s['returns']['claims_open'], 'مطالبةٌ بحصّته من القطعة');
        $this->assertEquals($cash - 6.0, Ledger::balance($this->shop->id, 'cash'), 'ما رُدّ للزبون ثمنُ القطعة وحده، ولا سدادَ ثانٍ ولا استرداد');
        $this->assertEquals($reemIssued, $reem(), 'ريم كما كانت في كلّ الأحوال');

        // ومن سدّد مرّةً لا يُسدَّد ثانيةً
        $this->post(route('admin.expenses.paid', $lama->expense_id), ['method' => 'نقدي']);
        $this->assertEquals($cash - 6.0, Ledger::balance($this->shop->id, 'cash'));
        $this->assertEquals(0, round((float) DB::table('journal_lines')->selectRaw('SUM(debit) - SUM(credit) as d')->value('d'), 3));
    }

    /** إرجاعُ بندٍ واحدٍ كاملٍ من فاتورةٍ مختلطة: بندُه وحده، وبقيّةُ الجهات كما كانت */
    public function test_one_line_returned_whole_leaves_every_other_party_as_it_was(): void
    {
        $this->shop->forceFill(['order_notes_and_edits_enabled' => true])->save();
        $order = $this->sell([[$this->mine], [$this->silver, 2], [$this->perfume]]);
        $this->actingAs($this->owner);
        $item = OrderItem::where('order_id', $order->id)->where('boutique_id', $this->reem->id)->sole();
        OrderCorrection::setQuantity($order->fresh(), $item, 0, 'رُدّ العطر', OrderCorrection::SETTLE_REFUNDED, 'نقدي');

        $inv = $this->invoice($order);
        $this->assertWhole($inv);
        $this->assertSame(['بوتيك لمى'], array_column($inv['shares']['boutiques'], 'name'));
        $this->assertEquals([32.0, 9.6, 22.4], [$inv['money']['total'], $inv['money']['boutiques_owed'], $inv['shares']['shop']]);
        $this->assertEquals(0, $this->summary(['boutique' => (string) $this->reem->id])['owed']);
        $this->assertEquals(9.6, $this->summary(['boutique' => (string) $this->lama->id])['owed']);
    }

    /** آجلٌ أُلغي قبل أن يُدفع: يخرج من حساب البوتيك، وورقتُه تُنقص بإشعارها — ولا مالَ يتحرّك */
    public function test_an_unpaid_sale_cancelled_before_payment_owes_nobody_and_moves_no_money(): void
    {
        $company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
        $order = $this->sell([[$this->mine], [$this->silver, 2]], ['credit' => true, 'customer_id' => $company->id]);
        $this->assertEquals(9.6, $this->summary()['owed'], 'الآجلُ بيعٌ قائم');
        $this->assertEquals([0.0, 32.0], [$this->invoice($order)['money']['paid'], $this->invoice($order)['money']['balance']]);

        $this->actingAs($this->owner);
        OrderCorrection::cancel($order->fresh(), 'أُلغي قبل الدفع', boutiqueReturned: true);
        $this->assertEquals([0.0, 0.0], [$this->summary()['owed'], $this->summary()['standalone']['gross']]);
        $this->assertEquals(12.0, $this->summary()['returns']['cancelled_gross']);
        $this->assertEquals([0.0, 0.0, 0.0], [Ledger::balance($this->shop->id, 'cash'), Ledger::balance($this->shop->id, 'receivable'), Ledger::balance($this->shop->id, 'sales')]);
        $this->expectException(SettlementRefused::class);
        Boutiques::settle($this->shop, $this->lama, self::PERIOD);
    }

    /**
     * وفاتورةٌ ملغاةٌ لم تحملها ورقة: بندُها كما سُجّل ولا نصيبَ ولا عمولةَ فيه —
     * كما في النافذة والملخّص. وما حملته ورقةٌ يبقى كما صدر.
     */
    public function test_a_cancelled_unsettled_row_shows_no_commission_or_net_and_a_settled_one_stays_as_issued(): void
    {
        $loose = $this->sell([[$this->silver, 2]]);
        $this->actingAs($this->owner);
        OrderCorrection::cancel($loose->fresh(), 'أُلغي', boutiqueReturned: true);
        $row = $this->rows()['rows'][0];
        $this->assertTrue($row['void']);
        $this->assertEquals([12.0, 0.0, 0.0], [$row['amount'], $row['commission'], $row['net']], 'المبلغُ كما سُجّل، ولا نصيبَ لأحد');

        $kept = $this->sell([[$this->silver, 3]]);
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $items = OrderItem::where('order_id', $kept->id)->get();
        OrderCorrection::cancel($kept->fresh(), 'رُدّ بعد الورقة', boutiqueReturned: true,
            boutiqueLines: $items->mapWithKeys(fn ($i) => [$i->id => ['sellable' => (int) $i->quantity, 'damaged' => 0]])->all());
        $settled = collect($this->rows()['rows'])->firstWhere('order_number', $kept->number);
        $this->assertFalse($settled['void'], 'بندٌ حملته ورقةٌ لا يُمحى نصيبُه من الصفّ');
        $this->assertEquals([3.6, 14.4], [$settled['commission'], $settled['net']], 'كما صدرت به الورقة');
        $this->assertSame($sheet->number, $settled['settlement']);
    }

    /** خصمٌ يتجاوز حصّةَ المتجر: البوتيكُ لا ينقص، والمتجرُ يتحمّل الفرق ويُقال سالبًا لا صفرًا */
    public function test_a_discount_beyond_the_shops_share_is_shown_negative_and_never_taken_from_the_boutique(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'BIG', 'type' => 'مبلغ', 'value' => 15, 'active' => true]);
        // بضاعةُ البوتيك ١٢، وعمولةُ المتجر ٢٫٤ وحدها — والخصمُ ١٥
        $inv = $this->invoice($this->sell([[$this->silver, 2]], ['coupon_code' => 'BIG']));
        $this->assertEquals(9.6, $inv['money']['boutiques_owed'], 'الخصمُ اقتُطع من البوتيك');
        $this->assertLessThan(0, $inv['money']['shop_after_discount']);
        $this->assertSame(self::baisa($inv['money']['total']), self::baisa($inv['money']['boutiques_owed']) + self::baisa($inv['money']['shop_after_discount']));
        $this->assertWhole($inv);
    }

    /* ═══════════ دون اتصال ═══════════ */

    public function test_an_offline_sale_resent_again_is_one_sale_with_one_share(): void
    {
        $body = ['items' => [
            ['id' => $this->mine->id, 'name' => 'باقة التاجر', 'qty' => 1, 'price' => 20],
            ['id' => $this->silver->id, 'name' => 'قطعة فضّة', 'qty' => 2, 'price' => 6],
        ], 'payment_method' => 'نقدي', 'client_uuid' => 'offline-1', 'replay' => true];
        $this->actingAs($this->owner)->postJson('/pos/checkout', $body)->assertOk();
        foreach (range(1, 3) as $_) {
            $this->actingAs($this->owner)->postJson('/pos/checkout', $body)->assertOk();
        }

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, $this->rows()['total']);
        $this->assertEquals(9.6, $this->summary()['owed']);
        $this->assertEquals(2.4, $this->summary()['standalone']['commission']);
        $this->assertSame(1, OrderItem::where('boutique_id', $this->lama->id)->count());
    }

    /* ═══════════ المُرشِّحات ═══════════ */

    public function test_the_invoice_state_filter_agrees_with_what_each_row_says(): void
    {
        $active = $this->sell([[$this->silver]]);
        $partial = $this->sell([[$this->silver, 3]]);
        $cancelled = $this->sell([[$this->perfume]]);
        $this->actingAs($this->owner);
        OrderCorrection::setQuantity($partial->fresh(), OrderItem::where('order_id', $partial->id)->firstOrFail(), 1, 'رُدّ اثنان');
        OrderCorrection::cancel($cancelled->fresh(), 'أُلغي', boutiqueReturned: true);

        foreach (['active' => $active, 'partial' => $partial, 'cancelled' => $cancelled] as $state => $order) {
            $rows = $this->rows(['state' => $state])['rows'];
            $this->assertSame([$order->number], collect($rows)->pluck('order_number')->all(), $state);
            $this->assertSame([$state], collect($rows)->pluck('invoice_state')->unique()->values()->all());
        }
        $this->assertSame(3, $this->rows()['total']);
        $this->assertSame(3, $this->rows(['state' => 'هراء'])['total'], 'مُرشِّحٌ مجهول يُقرأ «الكلّ»');
        // والمُرشِّحُ لا يقصّ الملخّص
        $this->assertEquals($this->summary(), $this->summary(['state' => 'cancelled']));
    }

    /* ═══════════ الملخّصُ المجمَّع يقول ما تقوله الدوالُّ نفسُها ═══════════ */

    public function test_the_grouped_summary_is_the_systems_own_functions_for_every_boutique(): void
    {
        mt_srand(20270210);
        $boutiques = [];
        foreach ([7.5, 12.5, 33.33, 5, 0, 50, 99.99] as $i => $rate) {
            $boutiques[] = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك '.$i, 'commission_rate' => $rate, 'active' => true]);
        }
        $products = collect($boutiques)->flatMap(fn ($b, $i) => [
            $this->product("ص-{$i}-أ", round(mt_rand(1, 9999) / 1000, 3), $b->id),
            $this->product("ص-{$i}-ب", round(mt_rand(1000, 99999) / 1000, 3), $b->id),
        ])->all();
        for ($n = 0; $n < 60; $n++) {
            $this->sell([[$products[mt_rand(0, count($products) - 1)], mt_rand(1, 5)], [$products[mt_rand(0, count($products) - 1)], mt_rand(1, 3)]]);
        }
        // ورقةٌ لبعض البوتيكات فيبقى بعضُ بنودها غير مسوًّى
        Boutiques::settle($this->shop, $boutiques[0], self::PERIOD);
        Boutiques::settle($this->shop, $boutiques[2], self::PERIOD);
        for ($n = 0; $n < 30; $n++) {
            $this->sell([[$products[mt_rand(0, count($products) - 1)], mt_rand(1, 5)]]);
        }

        $rowsSum = 0;
        foreach ($boutiques as $b) {
            $s = $this->summary(['boutique' => (string) $b->id]);
            $totals = Boutiques::soldTotals($this->shop->id, $b, Carbon::parse('2027-02-01'), null, null, Carbon::parse('2027-02-28 23:59:59'));
            $this->assertEquals($totals['commission'], $s['rounding']['report_commission'], "تقريرُ المبيعات — {$b->name}");
            $this->assertEquals($totals['gross'], $s['standalone']['gross']);
            $st = Boutiques::statement($this->shop->id, $b->id, self::PERIOD);
            $this->assertEquals($st['net'], $s['rounding']['sheet_pending_net'], "ما ينتظر الورقة — {$b->name}");
            $this->assertEquals($st['payable'], $s['settlement']['pending']['total']);
            $rowsSum += self::baisa($s['standalone']['commission']);
        }
        $all = $this->summary();
        $this->assertSame($rowsSum, self::baisa($all['standalone']['commission']), 'الكلّ = مجموعُ البوتيكات');
    }

    /**
     * وأوراقُ الشهر وما خُفّض منها ومستحقُّ القطع ورصيدُها: ما يُجمَّع هنا هو ما
     * تقوله دوالُّ النظام نفسُها لكلّ ورقةٍ وبوتيك — `BoutiqueReturns::payable`
     * و`BoutiqueDues::statement` — فلا يفترق ملخّصٌ عن دفتر.
     */
    public function test_grouped_sheets_dues_and_credits_are_the_systems_own_functions(): void
    {
        $this->shop->update(['boutique_components_enabled' => true]);
        $set = $this->product('هدية', 30);
        $combo = $this->product('هدية ثانية', 18);
        foreach ([[$set, $this->silver, 2], [$set, $this->perfume, 1], [$combo, $this->perfume, 1]] as [$of, $piece, $qty]) {
            RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $of->id, 'component_product_id' => $piece->id, 'quantity' => $qty, 'wastage_percent' => 0]);
        }
        $this->actingAs($this->owner);
        $a = $this->sell([[$set, 2], [$this->silver]]);
        $this->sell([[$combo], [$this->perfume]]);
        $sheetLama = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        Boutiques::settle($this->shop, $this->reem, self::PERIOD);
        // بعد الورقة: إلغاءٌ يُنتج رصيدًا للمتجر وإشعاراتٍ، ثمّ بيعٌ جديد ورصيدٌ يُقاصّ
        OrderCorrection::cancel($a->fresh(), 'أُلغي بعد الورقة', boutiqueReturned: true,
            boutiqueLines: OrderItem::where('order_id', $a->id)->whereNotNull('boutique_id')->get()->mapWithKeys(fn ($i) => [$i->id => ['sellable' => (int) $i->quantity, 'damaged' => 0]])->all());
        $this->sell([[$set], [$this->silver, 2], [$this->perfume, 3]]);

        foreach ([$this->lama, $this->reem] as $b) {
            $s = $this->summary(['boutique' => (string) $b->id]);
            $st = Boutiques::statement($this->shop->id, $b->id, self::PERIOD);
            $this->assertEquals($st['components']['net'], $s['settlement']['pending']['components'], "مكوّنات {$b->name}");
            $this->assertEquals($st['components']['credit'], $s['settlement']['pending']['credit']);
            $this->assertEquals($st['payable'], $s['settlement']['pending']['total']);
        }
        $unpaid = 0.0;
        foreach (BoutiqueSettlement::with('expense')->where('kind', BoutiqueSettlement::SETTLEMENT)->get() as $sheet) {
            $unpaid = round($unpaid + ((int) $sheet->lines_count > 0 ? BoutiqueReturns::payable($sheet->expense) : 0.0) + ($sheet->components_paid_at === null && (float) $sheet->components_due > 0 ? (float) $sheet->components_net : 0.0), 3);
        }
        $this->assertEquals($unpaid, $this->summary()['settlement']['unpaid'], 'المتبقّي = ما تقوله دالّةُ كلّ ورقة');
        $this->assertGreaterThan(0, $unpaid, 'الاختبارُ لا يقيس شيئًا');
        $this->assertNotNull($sheetLama);
    }

    /* ═══════════ ما مضى لا يُمسّ ═══════════ */

    public function test_reading_everything_and_selling_again_rewrites_no_historical_money(): void
    {
        $this->sell([[$this->mine], [$this->silver, 2]]);
        $this->sell([[$this->perfume, 2]]);
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'نقدي']);

        $prints = fn () => collect(['orders', 'order_items', 'boutique_settlements', 'expenses', 'journal_entries', 'journal_lines', 'boutique_component_dues', 'boutique_returns', 'inventory_movements'])
            ->filter(fn ($t) => Schema::hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()))])->all();
        $history = $prints();

        // كلُّ بابٍ وكلُّ مُرشِّح
        foreach ([[], ['type' => 'bundle'], ['settle' => 'paid'], ['state' => 'cancelled'], ['q' => 'فضّة'], ['page' => '2'], ['boutique' => (string) $this->reem->id]] as $params) {
            $this->rows($params);
            $this->summary($params);
        }
        foreach (Order::where('business_id', $this->shop->id)->get() as $o) {
            $this->actingAs($this->owner)->getJson(route('admin.boutiques.sales.order', $o->id));
        }
        $this->assertSame($history, $prints(), 'قراءةٌ كتبت في السجلّ الماليّ');

        // وبيعٌ جديد لا يمسّ الصفوفَ القديمة
        $oldIds = Order::where('business_id', $this->shop->id)->pluck('id');
        $oldItems = DB::table('order_items')->whereIn('order_id', $oldIds)->orderBy('id')->get()->toArray();
        $oldSheet = DB::table('boutique_settlements')->orderBy('id')->get()->toArray();
        $this->sell([[$this->silver, 9]]);
        $this->assertEquals($oldItems, DB::table('order_items')->whereIn('order_id', $oldIds)->orderBy('id')->get()->toArray());
        $this->assertEquals($oldSheet, DB::table('boutique_settlements')->orderBy('id')->get()->toArray(), 'ورقةٌ صدرت تغيّرت ببيعٍ بعدها');
        $this->assertSame(1, BoutiqueSettlement::count());
    }
}
