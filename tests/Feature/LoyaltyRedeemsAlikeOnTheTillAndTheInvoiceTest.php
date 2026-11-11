<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Loyalty;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * نقاطُ الولاء تُستبدل في الصندوق وفي فاتورة العميل بالقواعد نفسِها.
 *
 * ═══ ما يحرسه ═══
 *
 *   - الحسابُ واحد (`Loyalty::quote`) — والحالاتُ المشتركة مع مرآة الشاشة.
 *   - متجرٌ لم يحفظ شيئًا يستبدل كما كان (مئةُ نقطةٍ بوحدة).
 *   - صاحبُ النشاط يحدّد القيمةَ والحدودَ والتفعيل، ويُطبَّق ذلك في البابين.
 *   - الفاتورةُ تخصم النقاطَ مرّةً عند كتابتها، تحت قفل العميل، وبمفتاحٍ فريد.
 *   - الضريبةُ على ما بقي بعد الخصم، والقيدُ متوازن.
 *   - الإلغاءُ يعيد النقاطَ مرّةً واحدة.
 *   - السدادُ برابط ووصولُ الفاتورة إلى لوحة التجهيز لا يعيدان الحساب ولا الخصم،
 *     والاكتسابُ على ما دُفع فعلًا.
 *   - متجرٌ آخر وعميلُه وإعداداتُه لا تتسرّب، ومتجرٌ بلا قدرة الولاء لا يستبدل.
 */
class LoyaltyRedeemsAlikeOnTheTillAndTheInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Branch $branch;

    private Customer $customer;

    private Product $product;

    private int $tx = 9100;

    private int $order = 717100000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الاختبار', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@loyalty.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'مريم', 'phone' => '91111111',
            'points' => 1000, 'language' => 'ar', 'allow_credit_sales' => true,
        ]);

        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 100, 'cost' => 40,
            'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->set('vat_enabled', '0');

        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function set(string $key, string $value, ?Business $shop = null): void
    {
        Setting::updateOrCreate(['business_id' => ($shop ?? $this->shop)->id, 'key' => $key], ['value' => $value]);
    }

    private function sell(array $extra = []): Order
    {
        $this->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
                'payment_method' => 'نقدي',
                'customer_id' => $this->customer->id,
                'client_uuid' => uniqid('t', true),
            ], $extra))->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** فاتورةُ عميلٍ ببندين بمئة — وبضريبةٍ خمسةٍ بالمئة غير مشمولة */
    private function invoice(int $redeem = 0, ?Customer $customer = null): CustomerInvoice
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');

        return CustomerInvoices::create($this->shop->id, $customer ?? $this->customer, [
            'issued_at' => '2026-11-11',
            'redeem_points' => $redeem,
        ], [
            ['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50],
        ], $this->owner->id);
    }

    private function redeems(): int
    {
        return PointTransaction::where('business_id', $this->shop->id)->where('type', 'redeem')->count();
    }

    /* ═══════════════════ الحسابُ الواحد ═══════════════════ */

    /** الحالاتُ المشتركة مع مرآة الشاشة — يقرؤها vitest أيضًا */
    public function test_the_shared_cases_are_what_the_server_computes(): void
    {
        $file = json_decode(file_get_contents(base_path('tests/fixtures/loyalty-quote-cases.json')), true);
        $this->assertGreaterThanOrEqual(15, count($file['cases']));

        foreach ($file['cases'] as $case) {
            $got = Loyalty::quote($case['rules'], $case['balance'], (float) $case['base'], (float) $case['other'], $case['requested']);
            $this->assertEquals($case['expect'], $got, $case['name']);
            // ولا خصمَ يتجاوز سقفَه أو الرصيد
            $this->assertLessThanOrEqual($case['balance'], $got['points'], $case['name']);
            $this->assertLessThanOrEqual(max(0, $case['base'] - $case['other']) + 0.0005, $got['discount'], $case['name']);
        }
    }

    /** متجرٌ لم يحفظ شيئًا يستبدل كما كان: مئةُ نقطةٍ بوحدة، ونصفُ الفاتورة، ومئةُ نقطةٍ حدًّا */
    public function test_a_shop_that_saved_nothing_redeems_as_it_always_did(): void
    {
        $rules = Loyalty::rules($this->shop->id);

        $this->assertTrue($rules['on']);
        $this->assertTrue($rules['redeem']);
        $this->assertSame(100, $rules['points']);
        $this->assertSame(1.0, $rules['value']);
        $this->assertSame(100, $rules['min_points']);
        $this->assertSame(50.0, $rules['max_pct']);
        $this->assertSame(0.0, $rules['min_invoice']);
        $this->assertSame(99.9, Loyalty::maxEarnRate());

        // وبيعةٌ بمئة وألفِ نقطة: عشرةُ ريالاتٍ كما كانت
        $order = $this->sell(['redeem_points' => 1000]);
        $this->assertSame(1000, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
    }

    /* ═══════════════════ صفحةُ برنامج الولاء ═══════════════════ */

    public function test_the_owner_sets_every_redemption_rule_from_the_loyalty_page(): void
    {
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_enabled' => true,
            'loyalty_earn_rate' => 2,
            'loyalty_redeem_enabled' => false,
            'loyalty_redeem_points' => 10,
            'loyalty_redeem_value' => 0.5,
            'loyalty_redeem_min_invoice' => 15,
            'loyalty_redeem_max_pct' => 30,
            'loyalty_redeem_min' => 20,
        ])->assertSessionHasNoErrors();

        $rules = Loyalty::rules($this->shop->id);
        $this->assertFalse($rules['redeem'], 'الاستبدالُ أُطفئ');
        $this->assertTrue($rules['on'], 'والاكتسابُ باقٍ');
        $this->assertSame(10, $rules['points']);
        $this->assertSame(0.5, $rules['value']);
        $this->assertSame(15.0, $rules['min_invoice']);
        $this->assertSame(30.0, $rules['max_pct']);
        $this->assertSame(20, $rules['min_points']);
        $this->assertSame(2.0, $rules['earn_rate']);
    }

    /** سقفُ الاكتساب يتبع القيمة: عشرُ نقاطٍ بريالٍ تجعل عشرَ نقاطٍ لكلّ ريالٍ استردادًا كاملًا */
    public function test_the_earn_ceiling_follows_the_value_the_owner_chose(): void
    {
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_earn_rate' => 10,
            'loyalty_redeem_points' => 10,
            'loyalty_redeem_value' => 1,
            'loyalty_redeem_max_pct' => 50,
            'loyalty_redeem_min' => 0,
        ])->assertSessionHasErrors('loyalty_earn_rate');

        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_earn_rate' => 9.9,
            'loyalty_redeem_points' => 10,
            'loyalty_redeem_value' => 1,
            'loyalty_redeem_max_pct' => 50,
            'loyalty_redeem_min' => 0,
        ])->assertSessionHasNoErrors();

        // وقيمةٌ صفريّة أو عددُ نقاطٍ صفريّ لا يُحفظان
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_earn_rate' => 1, 'loyalty_redeem_points' => 0, 'loyalty_redeem_value' => 0,
            'loyalty_redeem_max_pct' => 50, 'loyalty_redeem_min' => 0,
        ])->assertSessionHasErrors(['loyalty_redeem_points', 'loyalty_redeem_value']);
    }

    /* ═══════════════════ الصندوق ═══════════════════ */

    public function test_the_till_redeems_by_the_merchants_value(): void
    {
        $this->set('loyalty_redeem_points', '10');
        $this->set('loyalty_redeem_value', '0.5');
        $this->set('loyalty_redeem_min', '10');
        $this->customer->update(['points' => 400]);

        $order = $this->sell(['redeem_points' => 999999]);

        // أربعمئة نقطةٍ بنصف ريالٍ لكلّ عشر = عشرون، دون سقف الخمسين
        $this->assertSame(400, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(20.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $order->total, 0.0001);
        $this->assertSame(1, $this->redeems());
    }

    public function test_with_redemption_off_the_till_still_earns_and_spends_nothing(): void
    {
        $this->set('loyalty_redeem_enabled', '0');

        $order = $this->sell(['redeem_points' => 500]);

        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount, 0.0001);
        $this->assertGreaterThan(0, (int) $order->points_earned, 'الاكتسابُ مستقلّ عن الاستبدال');
        $this->assertSame(1000 + (int) $order->points_earned, (int) $this->customer->fresh()->points);
    }

    public function test_below_the_minimum_invoice_the_till_redeems_nothing(): void
    {
        $this->set('loyalty_redeem_min_invoice', '150');

        $order = $this->sell(['redeem_points' => 500]);

        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertSame(0, $this->redeems());
    }

    /** والضريبةُ على ما بقي بعد النقاط — والإجماليُّ مجموعُ أجزائه */
    public function test_the_till_taxes_what_is_left_after_the_points(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');

        $order = $this->sell(['redeem_points' => 1000]);

        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(4.5, (float) $order->tax, 0.0001);
        $this->assertEqualsWithDelta(
            round((float) $order->subtotal - (float) $order->discount + (float) $order->tax + (float) $order->delivery_fee, 3),
            (float) $order->total, 0.0001,
        );
    }

    /* ═══════════════════ فاتورةُ العميل ═══════════════════ */

    public function test_an_invoice_redeems_by_the_same_rules_and_spends_once(): void
    {
        $invoice = $this->invoice(500);

        $this->assertSame(500, (int) $invoice->loyalty_points);
        $this->assertEqualsWithDelta(5.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->subtotal, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $invoice->discount_total, 0.0001);
        // الضريبةُ على خمسةٍ وتسعين لا على مئة
        $this->assertEqualsWithDelta(4.75, (float) $invoice->tax_total, 0.0001);
        $this->assertEqualsWithDelta(99.75, (float) $invoice->total, 0.0001);

        // والبنودُ تجمع إلى رأسها
        $items = $invoice->items;
        $this->assertEqualsWithDelta(5.0, (float) $items->sum('discount'), 0.0001);
        $this->assertEqualsWithDelta(99.75, (float) $items->sum('line_total'), 0.0001);

        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $spend = PointTransaction::where('customer_invoice_id', $invoice->id)->where('type', 'redeem')->sole();
        $this->assertSame(-500, (int) $spend->points);
        $this->assertSame(CustomerInvoices::redeemKey($invoice), $spend->source_key);
        $this->assertSame(500, (int) $spend->balance_after);
    }

    /** والقيدُ عند الإصدار متوازنٌ بالخصم: مدينُ الذمّة = دائنُ المبيعات بعد الخصم + الضريبة */
    public function test_the_issued_entry_balances_with_the_points_discount(): void
    {
        $invoice = CustomerInvoices::issue($this->invoice(500));

        $entry = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->sole();
        $lines = $entry->lines()->with('account')->get();

        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) $lines->sum('credit'), 0.0001, 'قيدٌ غير متوازن');
        $this->assertEqualsWithDelta(99.75, (float) $lines->sum('debit'), 0.0001);
        // والدائنُ: مبيعاتٌ بخمسةٍ وتسعين بعد الخصم، وضريبةٌ بأربعةٍ وثلاثة أرباع
        $credits = $lines->where('credit', '>', 0)->pluck('credit')->map(fn ($v) => round((float) $v, 3))->sort()->values()->all();
        $this->assertSame([4.75, 95.0], $credits);

        // والإصدارُ لا يخصم ثانيةً
        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
    }

    /** المطلوبُ فوق ما يجوز يُردّ لا يُحصر — ولا يُكتب شيء */
    public function test_asking_for_more_than_allowed_is_refused_and_writes_nothing(): void
    {
        try {
            $this->invoice(999999);
            $this->fail('قُبل استبدالٌ فوق الحدّ');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('redeem_points', $e->errors());
        }

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
    }

    /** رصيدٌ صُرف على فاتورةٍ لا يُصرف على الثانية — ولا يصير سالبًا */
    public function test_a_balance_spent_on_one_invoice_refuses_the_second(): void
    {
        $this->customer->update(['points' => 600]);

        $this->invoice(500);

        try {
            $this->invoice(500);
            $this->fail('صُرفت النقاطُ مرّتين');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('redeem_points', $e->errors());
        }

        $this->assertSame(1, CustomerInvoice::count());
        $this->assertSame(100, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
    }

    /** والصندوقُ بعد فاتورةٍ صرفت الرصيدَ لا يجد ما يستبدله */
    public function test_the_till_cannot_spend_what_an_invoice_already_spent(): void
    {
        $this->customer->update(['points' => 500]);
        $this->invoice(500);

        $order = $this->sell(['redeem_points' => 500]);

        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertGreaterThanOrEqual(0, (int) $this->customer->fresh()->points);
    }

    /** والمفتاحُ الفريد خلف ذلك كلِّه: حركةُ استبدالٍ ثانيةٌ للورقة نفسِها تردّها القاعدة */
    public function test_the_database_refuses_a_second_spend_for_one_invoice(): void
    {
        $invoice = $this->invoice(500);

        $this->expectException(QueryException::class);
        PointTransaction::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id, 'type' => 'redeem', 'points' => -500,
            'balance_after' => 0, 'source_key' => CustomerInvoices::redeemKey($invoice),
        ]);
    }

    public function test_cancelling_returns_the_points_once(): void
    {
        $invoice = CustomerInvoices::issue($this->invoice(500));
        $this->assertSame(500, (int) $this->customer->fresh()->points);

        CustomerInvoices::cancel($invoice, 'طلب العميل');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        // إلغاءٌ ثانٍ، أو إعادةٌ بيدٍ ثانية — لا تعيد مرّتين
        CustomerInvoices::cancel($invoice->fresh(), 'مكرّر');
        CustomerInvoices::returnPoints($invoice->fresh());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(1, PointTransaction::where('source_key', CustomerInvoices::redeemKey($invoice).':reverse')->count());
    }

    /** ومسودّةٌ تُلغى تعيد ما صُرف عليها كذلك */
    public function test_a_cancelled_draft_returns_its_points(): void
    {
        $draft = $this->invoice(300);
        $this->assertSame(700, (int) $this->customer->fresh()->points);

        CustomerInvoices::cancel($draft, 'لم تُكمل');

        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** والفاتورةُ بلا طلبٍ للنقاط لا تُخصم منها نقطة — التنبيهُ لا يُطبَّق وحده */
    public function test_an_invoice_without_a_choice_spends_nothing(): void
    {
        $invoice = $this->invoice(0);

        $this->assertSame(0, (int) $invoice->loyalty_points);
        $this->assertEqualsWithDelta(105.0, (float) $invoice->total, 0.0001);
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
    }

    /** والقواعدُ نفسُها: الحدُّ الأدنى للفاتورة يُردّ في الفاتورة كما في الصندوق */
    public function test_the_invoice_refuses_below_the_minimum_invoice_like_the_till(): void
    {
        $this->set('loyalty_redeem_min_invoice', '150');

        $this->expectException(ValidationException::class);
        $this->invoice(100);
    }

    /* ═══════════════════ الشاشةُ والخادم ═══════════════════ */

    /** المعاينةُ تعرض العرضَ من الخادم، والمجاميعَ بعد الخصم من `compute` نفسِها */
    public function test_the_preview_offers_what_the_store_will_write(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $items = [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]];

        $offer = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id, 'items' => $items,
        ])->assertOk()->json();

        $this->assertTrue($offer['loyalty']['eligible']);
        $this->assertSame(1000, $offer['loyalty']['balance']);
        $this->assertSame(1000, $offer['loyalty']['points']);
        $this->assertEqualsWithDelta(10.0, $offer['loyalty']['discount'], 0.0001);
        $this->assertSame(0, $offer['totals']['loyalty_points'], 'لا خصمَ قبل الاختيار');

        $chosen = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id, 'items' => $items, 'redeem_points' => 1000,
        ])->assertOk()->json('totals');

        $this->assertSame(1000, $chosen['loyalty_points']);
        $this->assertEqualsWithDelta(94.5, $chosen['total'], 0.0001);
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'المعاينةُ لا تخصم');

        // والحفظُ من الشاشة يكتب ما عُرض
        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'آجل',
            'issue' => true, 'items' => $items, 'redeem_points' => 1000,
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::sole();
        $this->assertEqualsWithDelta($chosen['total'], (float) $invoice->total, 0.0001);
        $this->assertSame(0, (int) $this->customer->fresh()->points);
    }

    /** وما تغيّر بين العرض والحفظ يُقال على حقل النقاط — لا تُكتب ورقةٌ بغير ما وافق عليه */
    public function test_the_screen_hears_why_a_stale_offer_is_refused(): void
    {
        $this->customer->update(['points' => 200]);

        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'آجل',
            'issue' => true, 'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]],
            'redeem_points' => 1000,
        ])->assertSessionHasErrors('redeem_points');

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(200, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ السدادُ ولوحةُ التجهيز ═══════════════════ */

    /**
     * فاتورةٌ بخصم نقاطٍ تُسدَّد برابط وتصل إلى لوحة التجهيز — ولا يُعاد شيء.
     *
     * إشعارُ Paymob يتكرّر، والتفعيلُ يقع، والاكتسابُ على ما دُفع فعلًا. ولا
     * حركةَ استبدالٍ ثانية، ولا يتغيّر خصمُ الورقة ولا إجماليُّها.
     */
    public function test_a_paid_invoice_reaches_the_board_without_a_second_discount_or_spend(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'omannet_integration_id' => '222', 'apple_pay_integration_id' => '333',
            'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580011, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/l',
                'client_url' => 'https://oman.paymob.com/x', 'created_at' => '2026-11-11T10:00:00',
                'expires_at' => '2026-11-18T23:59:00', 'state' => 'created', 'order' => 616057559,
            ]),
        ]);

        $invoice = CustomerInvoices::issue($this->invoice(500));
        CustomerInvoicePreparations::save($invoice, [
            'enabled' => true, 'fulfillment_type' => 'delivery', 'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-11-12 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير', 'delivery_notes' => null, 'internal_notes' => null,
        ], $this->owner->id);

        $before = $invoice->fresh()->only(['total', 'discount_total', 'tax_total', 'loyalty_points', 'loyalty_discount']);

        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice->fresh(), null, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();
        $this->assertEqualsWithDelta(99.75, (float) $link->amount, 0.0001, 'الرابطُ يطلب ما بعد الخصم');

        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-11-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true, 'is_live' => true,
        ];
        foreach (range(1, 3) as $_) {
            $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        }

        $invoice = $invoice->fresh();
        $this->assertNotNull($invoice->preparation?->activated_at, 'وصلت إلى لوحة التجهيز');
        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame($before, $invoice->only(array_keys($before)), 'التجهيزُ والسدادُ لا يعيدان حساب الورقة');

        // استبدالٌ واحد، واكتسابٌ واحد على ما دُفع: ٩٩٫٧٥ × ٥ = ٤٩٨
        $this->assertSame(1, $this->redeems());
        $earn = PointTransaction::where('business_id', $this->shop->id)->where('type', 'earn')->sole();
        $this->assertSame(498, (int) $earn->points);
        $this->assertSame(500 + 498, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ العزلُ والباقات ═══════════════════ */

    /** عميلُ متجرٍ آخر لا يُعرض رصيدُه ولا يُصرف — وإعداداتُ الجار لا تُقرأ هنا */
    public function test_another_shops_customer_and_settings_never_leak(): void
    {
        $stranger = Customer::create(['business_id' => $this->other->id, 'name' => 'غريب', 'phone' => '92222222', 'points' => 5000]);
        $this->set('loyalty_redeem_value', '10', $this->other);
        $this->set('loyalty_redeem_enabled', '0', $this->other);

        $preview = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $stranger->id, 'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk()->json();
        $this->assertNull($preview['loyalty'], 'لا يُقرأ رصيدُ عميلِ متجرٍ آخر');

        try {
            CustomerInvoices::create($this->shop->id, $stranger, ['redeem_points' => 100], [
                ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100],
            ]);
            $this->fail('كُتبت فاتورةٌ لعميل متجرٍ آخر');
        } catch (\RuntimeException) {
        }
        $this->assertSame(5000, (int) $stranger->fresh()->points);

        // وقواعدُ هذا المتجر كما هي — لا قيمةُ الجار ولا إطفاؤه
        $mine = Loyalty::rules($this->shop->id);
        $this->assertTrue($mine['redeem']);
        $this->assertSame(1.0, $mine['value']);
    }

    /** متجرٌ لا تشمل باقتُه الولاء: لا استبدالَ في الصندوق ولا في الفاتورة — والرصيدُ كما هو */
    public function test_a_shop_without_the_loyalty_capability_cannot_redeem(): void
    {
        $plan = Plan::create(['name' => 'بلا ولاء', 'monthly_price' => 9, 'yearly_price' => 90, 'capabilities' => ['website']]);
        $this->shop->forceFill(['plan_id' => $plan->id])->save();

        $this->assertFalse(Loyalty::rules($this->shop->id)['on']);

        try {
            $this->invoice(500);
            $this->fail('استُبدل في متجرٍ بلا قدرة الولاء');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('redeem_points', $e->errors());
        }

        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /**
     * وبيعةٌ آجلةٌ استُبدلت عليها النقاطُ في الصندوق تُفوتَر بورقةٍ لا تستبدل ثانيةً.
     *
     * الصندوقُ يكتب لها فاتورةَ عميلٍ (`CustomerInvoices::fromOrder`)، وخصمُها
     * وقع عند البيع ورُحّل في دفتره. فالورقةُ لا تحمل استبدالًا، ولا حركةَ
     * نقاطٍ ثانية — ولو وصلها طلبُ استبدالٍ من أيّ باب.
     */
    public function test_a_till_sale_billed_on_credit_never_redeems_twice(): void
    {
        $order = $this->sell(['credit' => true, 'paid_now' => 0, 'redeem_points' => 500]);
        $this->assertSame(500, (int) $order->redeemed_points);

        $invoice = CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))->sole();
        $this->assertSame(0, (int) $invoice->loyalty_points);
        $this->assertSame(0, PointTransaction::where('customer_invoice_id', $invoice->id)->where('type', 'redeem')->count());
        $this->assertSame(1, $this->redeems(), 'استبدالٌ واحدٌ عند البيع لا غير');
        $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.0001);

        // وطلبُ استبدالٍ يصل `fromOrder` يُسقط ولا يُكتب
        $points = (int) $this->customer->fresh()->points;
        $again = CustomerInvoices::fromOrder($order->fresh(), ['redeem_points' => 500]);
        $this->assertSame(0, (int) $again->loyalty_points);
        $this->assertSame(1, $this->redeems());
        $this->assertSame($points, (int) $this->customer->fresh()->points);
    }
}
