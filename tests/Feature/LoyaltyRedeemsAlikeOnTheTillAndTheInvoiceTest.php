<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
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
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\DocumentRenderer;
use App\Support\InvoiceLoyalty;
use App\Support\Ledger;
use App\Support\Loyalty;
use App\Support\Money;
use App\Support\OrderCorrection;
use App\Support\PaymentLinks;
use App\Support\Paymob\Amount;
use App\Support\Store\Paymob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReturnNote;
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

    /** طلبُ الصندوق كما يُرسل — بلا افتراض نجاحه */
    private function till(array $extra = []): TestResponse
    {
        return $this->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
                'payment_method' => 'نقدي',
                'customer_id' => $this->customer->id,
                'client_uuid' => uniqid('t', true),
            ], $extra));
    }

    /** فاتورةُ عميلٍ ببندين بمئة — وبضريبةٍ خمسةٍ بالمئة غير مشمولة. تُصدَر ما لم يُطلب غيرُ ذلك */
    private function invoice(int $redeem = 0, ?Customer $customer = null, bool $issue = true, array $lines = []): CustomerInvoice
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');

        $draft = CustomerInvoices::create($this->shop->id, $customer ?? $this->customer, [
            'issued_at' => '2026-11-11',
            'redeem_points' => $redeem,
        ], $lines ?: [
            ['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50],
        ], $this->owner->id);

        return $issue ? CustomerInvoices::issue($draft, $this->owner->id)->fresh('items') : $draft;
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
        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10]);
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

    /** والقواعدُ تُكتب بلوحةٍ عربيّة كغيرها — «٠٫٥» و«١٥» لا تُردّ (`NormalizeNumbers`) */
    public function test_the_redemption_rules_accept_arabic_digits(): void
    {
        $this->post(route('admin.marketing.loyalty.save'), [
            'loyalty_enabled' => true,
            'loyalty_earn_rate' => '١',
            'loyalty_redeem_enabled' => true,
            'loyalty_redeem_points' => '١٠',
            'loyalty_redeem_value' => '٠٫٥',
            'loyalty_redeem_min_invoice' => '١٥',
            'loyalty_redeem_max_pct' => '٣٠',
            'loyalty_redeem_min' => '٢٠',
        ])->assertSessionHasNoErrors();

        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(10, $rules['points']);
        $this->assertSame(0.5, $rules['value']);
        $this->assertSame(15.0, $rules['min_invoice']);
        $this->assertSame(30.0, $rules['max_pct']);
        $this->assertSame(20, $rules['min_points']);
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

        // وطلبُ أكثر ممّا يجوز يُردّ ومعه العرضُ الصحيح — لا يُنقص صامتًا
        $this->till(['redeem_points' => 999999, 'redeem_discount' => 50])
            ->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.points', 400)->assertJsonPath('loyalty.discount', 20);
        $this->assertSame(0, Order::count());

        $order = $this->sell(['redeem_points' => 400, 'redeem_discount' => 20]);

        // أربعمئة نقطةٍ بنصف ريالٍ لكلّ عشر = عشرون، دون سقف الخمسين
        $this->assertSame(400, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(20.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $order->total, 0.0001);
        $this->assertSame(1, $this->redeems());
    }

    public function test_with_redemption_off_the_till_still_earns_and_spends_nothing(): void
    {
        $this->set('loyalty_redeem_enabled', '0');

        // صندوقٌ يحسب بقواعد الأمس يُردّ ومعه القواعدُ الجديدة
        $this->till(['redeem_points' => 500, 'redeem_discount' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.points', 0)->assertJsonPath('loyalty.reason', Loyalty::REDEEM_OFF)
            ->assertJsonPath('loyalty.settings.redeemEnabled', false);

        $order = $this->sell();

        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(0.0, (float) $order->discount, 0.0001);
        $this->assertGreaterThan(0, (int) $order->points_earned, 'الاكتسابُ مستقلّ عن الاستبدال');
        $this->assertSame(1000 + (int) $order->points_earned, (int) $this->customer->fresh()->points);
    }

    public function test_below_the_minimum_invoice_the_till_redeems_nothing(): void
    {
        $this->set('loyalty_redeem_min_invoice', '150');

        $this->till(['redeem_points' => 500, 'redeem_discount' => 5])
            ->assertStatus(422)->assertJsonPath('loyalty.reason', Loyalty::BELOW_MIN_INVOICE);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $this->redeems());
    }

    /** والضريبةُ على ما بقي بعد النقاط — والإجماليُّ مجموعُ أجزائه */
    public function test_the_till_taxes_what_is_left_after_the_points(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');

        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10]);

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
        $invoice = $this->invoice(500);

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

        // الشاشةُ رأت خمسمئة، والفاتورةُ صرفتها: يُردّ الصندوقُ بالرصيد الصحيح ولا يُكتب شيء
        $this->till(['redeem_points' => 500, 'redeem_discount' => 5])
            ->assertStatus(422)->assertJsonPath('loyalty.balance', 0);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) $this->customer->fresh()->points);
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
        $invoice = $this->invoice(500);
        $this->assertSame(500, (int) $this->customer->fresh()->points);

        CustomerInvoices::cancel($invoice, 'طلب العميل');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        // إلغاءٌ ثانٍ، أو إعادةٌ بيدٍ ثانية — لا تعيد مرّتين
        CustomerInvoices::cancel($invoice->fresh(), 'مكرّر');
        CustomerInvoices::returnPoints($invoice->fresh());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(1, PointTransaction::where('source_key', CustomerInvoices::redeemKey($invoice).':reverse')->count());
    }

    /* ═══════════════════ القرار ٢: المسودّةُ لا تحجز ═══════════════════ */

    /** المسودّةُ تحمل الخصمَ في مبلغها ولا تمسّ الرصيد — والإصدارُ يصرف مرّةً */
    public function test_a_draft_holds_no_points_and_issuing_spends_them_once(): void
    {
        $draft = $this->invoice(500, issue: false);

        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertEqualsWithDelta(99.75, (float) $draft->total, 0.0001, 'المسودّةُ تُكتب بمبلغها بعد الخصم');
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'المسودّةُ لا تحبس نقاط العميل');
        $this->assertSame(0, $this->redeems());

        $issued = CustomerInvoices::issue($draft);
        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertEqualsWithDelta(99.75, (float) $issued->total, 0.0001, 'والإصدارُ لا يغيّر المبلغ');

        // إصدارٌ ثانٍ لا يصرف ثانيةً
        CustomerInvoices::issue($issued->fresh());
        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
    }

    /** مسودّةٌ تُلغى لم تُصرف عليها نقطة — فلا يُعاد شيء */
    public function test_a_cancelled_draft_neither_spent_nor_returns_anything(): void
    {
        $draft = $this->invoice(300, issue: false);

        CustomerInvoices::cancel($draft, 'لم تُكمل');

        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, PointTransaction::where('customer_invoice_id', $draft->id)->count());
    }

    /**
     * مسودّتان تَعِدان بالنقاط نفسِها: الأولى تُصدَر، والثانيةُ تُردّ عند إصدارها.
     *
     * والثانيةُ تبقى مسودّةً بمبلغها كما كُتب — لا يُصدَر لها مبلغٌ غيرُ الذي
     * وافق عليه التاجر، ولا ينزل الرصيدُ تحت الصفر.
     */
    public function test_two_drafts_cannot_spend_the_same_points(): void
    {
        $this->customer->update(['points' => 500]);
        $first = $this->invoice(500, issue: false);
        $second = $this->invoice(500, issue: false);

        CustomerInvoices::issue($first);

        try {
            CustomerInvoices::issue($second);
            $this->fail('صُرفت النقاطُ نفسُها مرّتين');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ألغِ المسودّة', $e->getMessage());
        }

        $second = $second->fresh();
        $this->assertSame(CustomerInvoice::DRAFT, $second->status);
        $this->assertEqualsWithDelta(99.75, (float) $second->total, 0.0001);
        $this->assertSame(0, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $second->id)->count(), 'لا قيدَ لورقةٍ لم تُصدَر');
    }

    /** قواعدُ تغيّرت بين المسودّة والإصدار — يُردّ الإصدارُ ولا يُعدَّل المبلغ في صمت */
    public function test_rules_changed_before_issuing_refuse_without_touching_the_amount(): void
    {
        $draft = $this->invoice(500, issue: false);

        $this->set('loyalty_redeem_value', '2'); // صارت مئةُ نقطةٍ بريالين

        $this->expectException(\RuntimeException::class);
        try {
            CustomerInvoices::issue($draft);
        } finally {
            $this->assertEqualsWithDelta(99.75, (float) $draft->fresh()->total, 0.0001);
            $this->assertSame(1000, (int) $this->customer->fresh()->points);
            $this->assertSame(0, $this->redeems());
        }
    }

    /** واستبدالٌ أُطفئ بعد كتابة المسودّة يُردّ إصدارَها كذلك */
    public function test_redemption_switched_off_before_issuing_refuses(): void
    {
        $draft = $this->invoice(500, issue: false);
        $this->set('loyalty_redeem_enabled', '0');

        $this->expectException(\RuntimeException::class);
        CustomerInvoices::issue($draft);
    }

    /**
     * والتزامن: نسخةٌ قديمةٌ من العميل قُرئت قبل أن يُصرف رصيدُه لا تصرفه ثانيةً.
     *
     * طلبان في اللحظة نفسها لا يُصنعان في اختبار — فيُصنع أثرُهما: كلاهما قرأ
     * الرصيدَ نفسَه، والأوّلُ صرفه. والثاني يُردّ بالشرط في جملة التحديث.
     */
    public function test_a_stale_read_cannot_spend_points_already_spent(): void
    {
        $this->customer->update(['points' => 500]);
        $stale = Customer::find($this->customer->id); // قرأ خمسمئة

        Loyalty::spend(Customer::find($this->customer->id), 500, 'الأوّل');

        try {
            Loyalty::spend($stale, 500, 'الثاني بنسخته القديمة');
            $this->fail('صُرف رصيدٌ مصروف');
        } catch (ValidationException) {
        }

        $this->assertSame(0, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
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

    /**
     * قيمةُ النقطة تغيّرت بين العرض والحفظ — العددُ نفسُه والمبلغُ غيرُه: يُردّ بالعرض الجديد.
     *
     * كان يُقارن عددُ النقاط وحده: خمسمئةٌ عرضتها الشاشةُ بخمسة، وصارت بعشرة،
     * فتُكتب الورقةُ بعشرةٍ لم يرها أحد. والردُّ يقول الرصيدَ وما يجوز الآن.
     */
    public function test_an_invoice_whose_points_value_changed_is_refused_with_the_new_offer(): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $this->set('loyalty_redeem_value', '2'); // صارت مئةُ نقطةٍ بريالين بعد أن عرضت الشاشةُ ريالًا
        $lines = [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]];
        $post = fn (float $agreed) => $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'آجل',
            'issue' => true, 'items' => $lines, 'redeem_points' => 500, 'redeem_discount' => $agreed,
        ]);

        $post(5)->assertSessionHasErrors('redeem_points');
        $said = session('errors')->first('redeem_points');
        $now = CustomerInvoices::loyaltyQuote($this->shop->id, $this->customer->fresh(), $lines);
        $this->assertStringContainsString('الرصيد الآن 1000 نقطة', $said);
        $this->assertStringContainsString(Money::format($now['max_discount'], Money::of($this->shop->id)).' مقابل '.$now['max_points'].' نقطة', $said);
        $this->assertSame(0, CustomerInvoice::count(), 'كُتبت ورقةٌ بخصمٍ لم يُرَ');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        // وبما يُعرض الآن تُكتب — خمسمئةٌ بعشرة، وضريبتُها على ما بقي
        $this->flushSession();
        $post(10)->assertSessionHasNoErrors();
        $invoice = CustomerInvoice::sole();
        $this->assertEqualsWithDelta(10.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(94.5, (float) $invoice->total, 0.0001);
        $this->assertSame(500, (int) $this->customer->fresh()->points);
    }

    /** ومسودّةٌ صُرف رصيدُها في الصندوق قبل إصدارها: يُقال الرصيدُ الآن وما يجوز — ولا يُمسّ مبلغُها */
    public function test_a_stale_draft_is_refused_with_the_balance_and_offer_now(): void
    {
        $draft = $this->invoice(500, issue: false);
        $this->customer->update(['points' => 300]);

        try {
            CustomerInvoices::issue($draft);
            $this->fail('صدرت مسودّةٌ بنقاطٍ لا رصيدَ لها');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('الرصيد الآن 300 نقطة', $e->getMessage());
            $this->assertStringContainsString('مقابل 300 نقطة', $e->getMessage());
            $this->assertStringContainsString('ألغِ المسودّة', $e->getMessage());
        }

        $this->assertEqualsWithDelta(99.75, (float) $draft->fresh()->total, 0.0001);
        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertSame(300, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
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

        $invoice = $this->invoice(500);
        CustomerInvoicePreparations::save($invoice, [
            'enabled' => true, 'fulfillment_type' => 'delivery', 'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-11-12 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير', 'delivery_notes' => null, 'internal_notes' => null,
        ], $this->owner->id);

        $before = $invoice->fresh()->only(['total', 'discount_total', 'tax_total', 'loyalty_points', 'loyalty_discount']);

        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice->fresh(), null, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();
        $this->assertEqualsWithDelta(99.75, (float) $link->amount, 0.0001, 'الرابطُ يطلب ما بعد الخصم');
        // وما يصل إلى Paymob هو إجماليُّ الورقة بعد النقاط وضريبتِها — بالبيسة، لا قبل الخصم
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->firstWhere('name', 'amount_cents')['contents'] === (string) Amount::toMinor((float) $invoice->fresh()->total, (string) $link->currency)
            && (string) Amount::toMinor((float) $invoice->fresh()->total, (string) $link->currency) === '99750');

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

    /* ═══════════════════ حارسا المسودّة لكلّ بابٍ يعدّلها (#195) ═══════════════════ */

    /**
     * مسودّةٌ عليها نقاطٌ لا يتبدّل عميلُها — فلا يُصرف من رصيد غيره خصمٌ لم يُعرض عليه.
     *
     * ولا بابَ اليوم يبدّله؛ والحارسُ لما يأتي (تعديلُ المسودّات). والمسودّةُ
     * بلا نقاطٍ يتبدّل عميلُها كما كان.
     */
    public function test_a_draft_carrying_points_keeps_its_customer(): void
    {
        $draft = $this->invoice(500, issue: false);
        $stranger = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'سالم', 'phone' => '92222222', 'points' => 5000, 'language' => 'ar',
        ]);

        try {
            $draft->update(['customer_id' => $stranger->id]);
            $this->fail('تبدّل عميلُ مسودّةٍ عليها نقاط');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('لا يُغيَّر عميلُها', $e->getMessage());
        }
        $this->assertSame($this->customer->id, (int) $draft->fresh()->customer_id);

        // والإصدارُ بعدها يصرف من رصيد عميلها وحده
        CustomerInvoices::issue($draft->fresh());
        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(5000, (int) $stranger->fresh()->points);

        // ومسودّةٌ بلا نقاطٍ يتبدّل عميلُها كما كان
        $plain = $this->invoice(0, issue: false);
        $plain->update(['customer_id' => $stranger->id]);
        $this->assertSame($stranger->id, (int) $plain->fresh()->customer_id);
    }

    /**
     * بنودٌ أُعيدت كتابتُها بلا حصّتها من خصم النقاط — لا تُصدَر، ولا تُصرف نقطة.
     *
     * كما يفعل بابٌ يعيد حسابَ البنود ولا يعرف النقاط: يمحو الحصّةَ ويُنزل
     * الإجماليَّ بلا الخصم. ولو صدرت لدفع العميلُ كاملًا وخسر نقاطَه معًا.
     */
    public function test_a_draft_whose_items_lost_their_points_share_is_not_issued(): void
    {
        $draft = $this->invoice(500, issue: false);
        CustomerInvoiceItem::where('customer_invoice_id', $draft->id)->update(['discount' => 0, 'loyalty_discount' => 0]);
        $draft->forceFill(['discount_total' => 0, 'total' => 105])->save();

        try {
            CustomerInvoices::issue($draft->fresh());
            $this->fail('صدرت مسودّةٌ لا تحمل بنودُها خصمَ نقاطها');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تغيّرت بنود المسودّة', $e->getMessage());
        }

        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
    }

    /** والحارسُ لا يردّ ورقةً سليمة: بنودٌ بقسمةٍ لا تنتهي تحمل الخصمَ كلَّه بلا فلسٍ زائدٍ أو ناقص */
    public function test_an_awkward_split_still_issues_and_spends_once(): void
    {
        $invoice = $this->invoice(700, lines: [
            ['description' => 'أ', 'quantity' => 1, 'unit_price' => 33.333],
            ['description' => 'ب', 'quantity' => 3, 'unit_price' => 17.77],
            ['description' => 'ج', 'quantity' => 7, 'unit_price' => 9.99, 'discount' => 1.5],
        ]);

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertEqualsWithDelta((float) $invoice->loyalty_discount, (float) $invoice->items->sum('loyalty_discount'), 0.0001);
        $this->assertSame(1, $this->redeems());
        $this->assertSame(300, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ الدفعُ: ملغى ومتأخّرٌ ومكرّر ═══════════════════ */

    /** رابطُ Paymob حيٌّ على فاتورةٍ صرفت نقاطًا — بلا اتّصالٍ بالبوّابة (`Http::fake`) */
    private function liveLink(CustomerInvoice $invoice): CustomerPaymentLink
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580090, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/z',
                'client_url' => 'https://oman.paymob.com/z', 'created_at' => '2026-11-11T10:00:00',
                'expires_at' => '2026-11-18T23:59:00', 'state' => 'created', 'order' => 616057590,
            ]),
        ]);

        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice->fresh(), null, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();

        return $link->fresh();
    }

    /** إشعارُ Paymob موقَّعٌ بسرّ المتجر — نجاحٌ بمبلغ الرابط ما لم يُقل غيرُه */
    private function paidNotice(CustomerPaymentLink $link, int $tx, bool $success = true): void
    {
        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-11-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => ! $success, 'has_parent_transaction' => false, 'id' => $tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => $success, 'is_live' => true,
        ];
        $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /**
     * رابطٌ أُلغي ثمّ وصل دفعُه متأخّرًا (دفع العميلُ قبل أن يصل الإلغاءُ إلى Paymob).
     *
     * المالُ دخل فعلًا فيُسجَّل كما يسجّله النظامُ اليوم — والفاتورةُ صادرةٌ
     * فيُخصَّص لها. وما يخصّ النقاط: لا صرفَ ثانيًا، والخصمُ والإجماليُّ كما
     * كُتبا، واكتسابٌ واحدٌ على ما دُفع — ولو تكرّر الإشعار.
     */
    public function test_a_late_payment_on_a_cancelled_link_spends_nothing_twice(): void
    {
        $invoice = $this->invoice(500); // ٩٩٫٧٥ — خمسمئةٌ صُرفت
        $before = $invoice->fresh()->only(['total', 'discount_total', 'tax_total', 'loyalty_points', 'loyalty_discount']);
        $link = $this->liveLink($invoice);
        PaymentLinks::cancel($link, $this->owner->id);
        $this->assertSame(CustomerPaymentLink::CANCELLED, $link->fresh()->status);

        $tx = ++$this->tx;
        foreach (range(1, 2) as $_) {
            $this->paidNotice($link->fresh(), $tx);
        }

        $invoice = $invoice->fresh();
        $this->assertSame($before, $invoice->only(array_keys($before)), 'الدفعُ المتأخّر لا يعيد حسابَ الورقة');
        $this->assertSame(1, CustomerPayment::count(), 'المالُ الذي دخل يُسجَّل مرّةً');
        $this->assertSame(1, $this->redeems(), 'لا صرفَ ثانيًا');
        $this->assertSame(0, $this->returned($invoice));
        $this->assertSame(1, PointTransaction::where('business_id', $this->shop->id)->where('type', 'earn')->count(), 'اكتسابٌ واحد');
        $this->assertSame(500 + 498, (int) $this->customer->fresh()->points);
    }

    /**
     * فاتورةٌ أُلغيت فعادت نقاطُها، ثمّ وصل دفعُ رابطها متأخّرًا.
     *
     * يبقى المالُ رصيدًا للعميل كما يفعل النظامُ اليوم (لا تخصيصَ لملغاة —
     * `CustomerPayments::record`). وما يخصّ النقاط: لا تُعاد مرّتين، ولا
     * تُصرف من جديد، ولا اكتسابَ على فاتورةٍ ملغاة، ولا رصيدَ سالب.
     */
    public function test_a_payment_arriving_after_the_invoice_was_cancelled_moves_no_points(): void
    {
        $invoice = $this->invoice(500);
        $link = $this->liveLink($invoice);
        PaymentLinks::cancel($link, $this->owner->id);
        CustomerInvoices::cancel($invoice->fresh(), 'أُلغيت قبل الدفع');
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        $tx = ++$this->tx;
        foreach (range(1, 3) as $_) {
            $this->paidNotice($link->fresh(), $tx);
        }

        $invoice = $invoice->fresh();
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->status);
        $this->assertEqualsWithDelta(99.75, (float) $invoice->total, 0.0001, 'لا يُعاد حسابُ الملغاة');
        $this->assertSame(1, CustomerPayment::count(), 'المالُ الذي دخل يُسجَّل مرّةً');
        $this->assertEqualsWithDelta(0.0, (float) DB::table('customer_payment_allocations')->where('customer_invoice_id', $invoice->id)->sum('amount'), 0.0001, 'لا يُخصَّص لملغاة');
        $this->assertSame(1, $this->redeems());
        $this->assertSame(500, $this->returned($invoice), 'لا تُعاد مرّتين');
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'لا صرفَ ولا اكتسابَ على ملغاة');
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
        $order = $this->sell(['credit' => true, 'paid_now' => 0, 'redeem_points' => 500, 'redeem_discount' => 5]);
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

    /* ═══════════════════ القرار ١: الإشعاراتُ الدائنة ═══════════════════ */

    private function credit(CustomerInvoice $invoice, float $amount): void
    {
        ReturnNote::issue($invoice->fresh(), $amount, $this->owner->id);
    }

    private function returned(CustomerInvoice $invoice): int
    {
        return CustomerInvoices::pointsReturned($invoice->fresh());
    }

    /** إشعارٌ بنصف الورقة يعيد نصفَ النقاط، والثاني بالباقي يعيد الباقي — ولا أكثر */
    public function test_credit_notes_return_points_in_proportion_and_never_more(): void
    {
        $invoice = $this->invoice(500); // ٩٩٫٧٥

        $this->credit($invoice, 49.875);
        $this->assertSame(250, $this->returned($invoice));
        $this->assertSame(750, (int) $this->customer->fresh()->points);

        $this->credit($invoice, 49.875);
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        // وإشعارٌ فوق الإجماليّ يردّه بابُ الإشعار نفسُه — ولا نقطةَ زائدة (والرفضُ تحقّقٌ بعد #202)
        try {
            $this->credit($invoice, 1);
            $this->fail('صدر إشعارٌ فوق إجماليّ الفاتورة');
        } catch (\RuntimeException|ValidationException) {
        }
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** ثلاثةُ أثلاث: التراكمُ لا يأكل نقطةً في التقريب، والمجموعُ ما صُرف بالضبط */
    public function test_repeated_partial_credits_add_up_to_exactly_what_was_spent(): void
    {
        $invoice = $this->invoice(500); // ٩٩٫٧٥

        $this->credit($invoice, 33.25);
        $this->assertSame(166, $this->returned($invoice));

        $this->credit($invoice, 33.25);
        $this->assertSame(333, $this->returned($invoice));

        $this->credit($invoice, 33.25);
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(3, PointTransaction::where('customer_invoice_id', $invoice->id)->where('source_key', 'like', '%:cn:%')->count());
    }

    /** إلغاءٌ بعد إشعارٍ جزئيّ يعيد الباقي وحده — مرّةً */
    public function test_cancelling_after_a_partial_credit_returns_only_the_rest(): void
    {
        $invoice = $this->invoice(500);
        $this->credit($invoice, 39.9); // ٤٠٪
        $this->assertSame(200, $this->returned($invoice));

        CustomerInvoices::cancel($invoice->fresh(), 'أُلغيت');
        CustomerInvoices::returnPoints($invoice->fresh());

        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** والقاعدةُ تردّ إعادةً ثانيةً للإشعار نفسِه */
    public function test_the_database_refuses_a_second_return_for_one_credit_note(): void
    {
        $invoice = $this->invoice(500);
        $this->credit($invoice, 49.875);
        $key = PointTransaction::where('customer_invoice_id', $invoice->id)->where('source_key', 'like', '%:cn:%')->value('source_key');

        $this->expectException(QueryException::class);
        PointTransaction::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id,
            'customer_invoice_id' => $invoice->id, 'type' => 'earn', 'points' => 250,
            'balance_after' => 0, 'source_key' => $key,
        ]);
    }

    /** وفاتورةٌ بلا نقاط لا يكتب إشعارُها حركةَ نقاط */
    public function test_a_credit_on_an_invoice_without_points_moves_no_points(): void
    {
        $invoice = $this->invoice(0);
        $this->credit($invoice, 50);

        $this->assertSame(0, PointTransaction::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** السياسةُ مكتوبةٌ في دالّةٍ واحدة: تراكميّة، تُقصّ، ومحصورةٌ بالمصروف */
    public function test_the_return_policy_is_one_documented_function(): void
    {
        $this->assertSame(0, CustomerInvoices::pointsDueBack(500, 0, 100));
        $this->assertSame(250, CustomerInvoices::pointsDueBack(500, 50, 100));
        $this->assertSame(166, CustomerInvoices::pointsDueBack(500, 33.3333, 100));
        $this->assertSame(500, CustomerInvoices::pointsDueBack(500, 100, 100));
        $this->assertSame(500, CustomerInvoices::pointsDueBack(500, 150, 100), 'لا يتجاوز ما صُرف');
    }

    /* ═══════════════════ القرار ٣: الورقةُ المطبوعة ═══════════════════ */

    /** الورقةُ تطبع «خصم نقاط الولاء» سطرًا مستقلًّا بعدده وقيمته — و«الخصم» ما سواه */
    public function test_the_printed_invoice_shows_the_points_discount_on_its_own_line(): void
    {
        $invoice = $this->invoice(500, lines: [
            ['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50, 'discount' => 2],
        ]);

        // خصمُ البند اثنان، والنقاطُ خمسة: الخصمُ كلُّه سبعة، يُطبع سطرين
        $this->assertEqualsWithDelta(7.0, (float) $invoice->discount_total, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $invoice->items->sum('loyalty_discount'), 0.0001);

        $html = PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh());

        $this->assertStringContainsString('خصم نقاط الولاء', $html);
        $this->assertStringContainsString('500 نقطة', $html);
        $this->assertStringContainsString('5.000', $html);
        $this->assertStringContainsString('2.000', $html, 'وخصمُ البند وحده في سطر «الخصم»');
        $this->assertStringNotContainsString('7.000', $html, 'ولا يُطبع الخصمُ مجموعًا فوق سطر النقاط');
    }

    /** وورقةُ التجهيز الحراريّة كذلك — وخصمُ البند فيها بلا نصيب النقاط */
    public function test_the_thermal_paper_shows_the_points_line_once(): void
    {
        $this->shop->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $invoice = $this->invoice(500);
        $prep = CustomerInvoicePreparations::save($invoice, [
            'enabled' => true, 'fulfillment_type' => 'pickup', 'branch_id' => $this->branch->id,
            'scheduled_for' => '2026-11-12 16:00', 'recipient_name' => 'مريم', 'recipient_phone' => '99887766',
            'delivery_address' => null, 'delivery_notes' => null, 'internal_notes' => null,
        ], $this->owner->id);

        $paper = CustomerInvoicePreparations::internalPaper($prep ?? $invoice->fresh()->preparation, $invoice->fresh('items'));

        $this->assertNull($paper['totals']['discount'], 'لا خصمَ بنودٍ — النقاطُ وحدها');
        $this->assertSame(500, $paper['totals']['loyalty']['points']);
        foreach ($paper['items'] as $item) {
            $this->assertNull($item['discount'], 'نصيبُ النقاط لا يُطبع خصمًا على البند');
        }

        $html = DocumentRenderer::customerInvoiceStrip($this->shop->id, $paper, 80);
        $this->assertSame(1, substr_count($html, 'data-loyalty'));
    }

    /**
     * وإيصالُ الصندوق كذلك — شريطًا وA4: «الخصم» للكوبون، و«خصم نقاط الولاء»
     * بعدده، ومجموعُهما خصمُ البيعة كما هو.
     */
    public function test_the_till_receipt_prints_the_points_discount_on_its_own_line(): void
    {
        $order = $this->sell(['redeem_points' => 500, 'redeem_discount' => 5]);
        $this->assertEqualsWithDelta(5.0, $order->loyaltyDiscount(), 0.0001);

        $strip = PdfController::saleHtml($this->shop->id, $order->fresh('items'), true)['html'];
        $this->assertSame(1, substr_count($strip, 'data-loyalty'));
        $this->assertStringContainsString('خصم نقاط الولاء', $strip);
        $this->assertStringContainsString('500', $strip);

        $sheet = PdfController::saleHtml($this->shop->id, $order->fresh('items'), false)['html'];
        $this->assertStringContainsString('خصم نقاط الولاء', $sheet);
        $this->assertStringContainsString('500 نقطة', $sheet);

        // وكوبونٌ مع النقاط: السطران مجموعُهما الخصم، ولا يُطبع الخصمُ كلُّه مرّتين
        $mixed = new Order(['discount' => 8, 'coupon_discount' => 3, 'redeemed_points' => 500]);
        $this->assertEqualsWithDelta(5.0, $mixed->loyaltyDiscount(), 0.0001);
        $this->assertEqualsWithDelta(0.0, (new Order(['discount' => 8, 'coupon_discount' => 3, 'redeemed_points' => 0]))->loyaltyDiscount(), 0.0001, 'بيعةٌ بلا نقاط لا سطرَ نقاطٍ لها');
    }

    /* ═══════════════════ المراجعةُ النهائيّة: الحدودُ والإعادة ═══════════════════ */

    /**
     * فاتورةٌ مدفوعة رُدّت كلُّها لا تُردّ ثانيةً — لا مالًا ولا نقاطًا.
     *
     * `outstanding` تُحصر عند الصفر، فكان حدُّ «الباقي + المسدَّد» يقبل إشعارًا
     * كاملًا بعد إشعارٍ كامل: ١٩٩٫٥ مردودةً على فاتورةٍ بـ٩٩٫٧٥.
     */
    public function test_a_paid_invoice_cannot_be_credited_past_its_total(): void
    {
        $invoice = $this->invoice(500); // ٩٩٫٧٥
        CustomerPayments::record($this->shop->id, $this->customer, 99.75, ['method' => 'نقدي', 'occurred_at' => '2026-11-11'], [$invoice->id => 99.75]);

        $this->credit($invoice, 99.75);
        $this->assertSame(500, $this->returned($invoice));

        try {
            $this->credit($invoice, 99.75);
            $this->fail('أُصدر إشعارٌ ثانٍ بكامل فاتورةٍ رُدّت كلُّها');
        } catch (\RuntimeException|ValidationException) {
            // والنصُّ يختلف بعد #202 («رُدّت الفاتورة كلها…») — والمقياسُ ما في القاعدة تحته
        }

        $this->assertEqualsWithDelta(99.75, (float) CustomerCreditNote::where('customer_invoice_id', $invoice->id)->sum('amount'), 0.0001);
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /**
     * دفعٌ بالبطاقة فشل: الورقةُ الصادرة بخصمها كما هي، والنقاطُ مصروفةٌ عليها،
     * ولا تحصيلَ ولا اكتساب. والإلغاءُ — ولو تكرّر — يعيدها مرّةً.
     */
    public function test_a_failed_card_payment_leaves_the_issued_invoice_and_its_points_as_they_were(): void
    {
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links/cancel' => Http::response(['success' => true]),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 580012, 'amount_cents' => 0, 'shorten_url' => 'https://paymob.link/f',
                'client_url' => 'https://oman.paymob.com/f', 'created_at' => '2026-11-11T10:00:00',
                'expires_at' => '2026-11-18T23:59:00', 'state' => 'created', 'order' => 616057560,
            ]),
        ]);

        $invoice = $this->invoice(500);
        $before = $invoice->fresh()->only(['total', 'discount_total', 'loyalty_points', 'loyalty_discount']);
        $link = PaymentLinks::create($this->shop->id, $this->customer, $invoice->fresh(), null, null, $this->owner->id);
        $link->forceFill(['provider_order_id' => (string) (++$this->order)])->save();

        $obj = [
            'amount_cents' => Amount::toMinor((float) $link->amount, (string) $link->currency),
            'created_at' => '2026-11-11T10:05:00.000000', 'currency' => $link->currency,
            'error_occured' => true, 'has_parent_transaction' => false, 'id' => ++$this->tx,
            'integration_id' => 111, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => (int) $link->provider_order_id, 'merchant_order_id' => null],
            'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => false, 'is_live' => true,
            'data' => ['message' => 'Insufficient funds'],
        ];
        foreach (range(1, 2) as $_) {
            $this->postJson('/webhooks/paymob?hmac='.Paymob::signature($obj, 'hmac-shop'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        }

        $invoice = $invoice->fresh();
        $this->assertSame(0, CustomerPayment::count(), 'تحصيلٌ لدفعٍ فشل');
        $this->assertNotSame(CustomerPaymentLink::PAID, $link->fresh()->status);
        $this->assertSame($before, $invoice->only(array_keys($before)), 'الفشلُ لا يغيّر الورقة');
        $this->assertEqualsWithDelta(99.75, $invoice->outstanding(), 0.0001);
        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertSame(0, PointTransaction::where('business_id', $this->shop->id)->where('type', 'earn')->count(), 'ولا اكتسابَ بلا دفع');

        // والرابطُ يُلغى قبل الورقة — فلا يدفع العميلُ فاتورةً ملغاة (ويشترطه #202)
        PaymentLinks::cancel($link->fresh(), $this->owner->id);
        CustomerInvoices::cancel($invoice, 'لم يُدفع');
        CustomerInvoices::cancel($invoice->fresh(), 'لم يُدفع');
        $this->assertSame(500, $this->returned($invoice));
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** وحركاتُ الورقة في صفحتها بأسمائها: صرفٌ، وإعادةٌ بإشعار، وإعادةٌ بإلغاء — لا «عكس تحصيل» */
    public function test_the_invoice_names_each_points_movement_by_what_it_was(): void
    {
        $invoice = $this->invoice(500);
        $this->credit($invoice, 39.9);
        CustomerInvoices::cancel($invoice->fresh(), 'أُلغيت');

        $kinds = array_column(InvoiceLoyalty::rows($invoice->fresh()), 'kind');

        $this->assertSame(['spent', 'returned_credit', 'returned_cancel'], $kinds);
    }

    /** البيعةُ نفسُها تُرسل مرّتين (انقطاعٌ ثمّ إعادة): طلبٌ واحد وخصمٌ واحد */
    public function test_the_same_till_sale_sent_twice_spends_points_once(): void
    {
        $payload = [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
            'customer_id' => $this->customer->id,
            'client_uuid' => 'same-sale-twice',
            'redeem_points' => 500,
            'redeem_discount' => 5,
        ];

        $first = $this->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $payload)->assertOk();
        $this->withSession(['current_branch' => $this->branch->id])->postJson(route('pos.checkout'), $payload)
            ->assertOk()->assertJson(['duplicate' => true, 'invoice' => $first->json('invoice')]);

        $this->assertSame(1, Order::where('business_id', $this->shop->id)->count());
        $this->assertSame(1, $this->redeems());
        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertSame(1000 - 500 + (int) $order->points_earned, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ القرار ٤: التصحيحُ الإداريّ ═══════════════════ */

    private function adjust(array $data): TestResponse
    {
        return $this->post(route('admin.customers.points.adjust', $this->customer->id), $data);
    }

    /** التصحيحُ بسببٍ مكتوب يُسجَّل حركةً وسجلَّ نشاط — ولا يُنتج مالًا ولا خصمًا */
    public function test_an_adjustment_needs_a_reason_and_leaves_an_audit_trail_without_money(): void
    {
        $this->adjust(['direction' => 'deduct', 'points' => 100])->assertSessionHasErrors('reason');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);

        $entries = JournalEntry::count();
        $this->adjust(['direction' => 'deduct', 'points' => 100, 'reason' => 'نقاط مُنحت بالخطأ'])->assertSessionHasNoErrors();
        $this->adjust(['direction' => 'add', 'points' => 30, 'reason' => 'تعويض عن تأخير'])->assertSessionHasNoErrors();

        $this->assertSame(930, (int) $this->customer->fresh()->points);
        $rows = PointTransaction::where('customer_id', $this->customer->id)->where('type', 'adjust')->orderBy('id')->get();
        $this->assertSame([-100, 30], $rows->pluck('points')->map(fn ($p) => (int) $p)->all());
        $this->assertStringContainsString('نقاط مُنحت بالخطأ', $rows[0]->note);
        $this->assertStringContainsString($this->owner->name, $rows[0]->note);
        $this->assertSame(2, ActivityLog::where('description', 'like', 'صحّح رصيد نقاط العميل%')->count());

        $this->assertSame($entries, JournalEntry::count(), 'لا قيدَ ماليّ');
        $this->assertSame(0, $this->redeems(), 'ولا استبدال');
    }

    /** والنقصُ فوق الرصيد يُردّ — لا يُحصر ولا ينزل تحت الصفر */
    public function test_an_adjustment_never_goes_below_zero(): void
    {
        $this->adjust(['direction' => 'deduct', 'points' => 1001, 'reason' => 'تصحيح'])->assertSessionHasErrors('points');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** ولمن مُنح الفعلَ باسمه وحده — مديرٌ يفتح العملاءَ لا يصحّح الرصيد */
    public function test_only_who_holds_the_action_may_adjust(): void
    {
        $manager = User::create([
            'business_id' => $this->shop->id, 'name' => 'المدير', 'email' => 'manager@loyalty.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($manager);
        // يفتح ملفَّ العميل — فالرفضُ من الفعل لا من القسم
        $this->get(route('admin.customers.show', $this->customer->id))->assertOk();
        $this->adjust(['direction' => 'add', 'points' => 10, 'reason' => 'محاولة'])->assertForbidden();
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** ومتجرٌ لا تشمل باقتُه الولاء لا يصحّح رصيدًا */
    public function test_a_shop_without_loyalty_cannot_adjust(): void
    {
        $plan = Plan::create(['name' => 'بلا ولاء ٢', 'monthly_price' => 9, 'yearly_price' => 90, 'capabilities' => ['website']]);
        $this->shop->forceFill(['plan_id' => $plan->id])->save();

        $this->adjust(['direction' => 'add', 'points' => 10, 'reason' => 'محاولة'])->assertForbidden();
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ قرارُ البند الثاني: الصندوقُ المتّصل، والانقطاع ═══════════════════ */

    /**
     * رصيدٌ تغيّر بين العرض والدفع: لا يُكتب شيء، ويُردّ العرضُ الصحيح.
     *
     * الشاشةُ رأت ألفَ نقطة، وصُرف منها سبعمئة في مكانٍ آخر قبل الدفع. كان
     * الخادمُ يُنقص الخصمَ صامتًا فيُكتب غيرُ ما قُبض؛ والآن يُردّ ومعه الرصيدُ
     * والخصمُ المتاح، ويؤكّد الكاشيرُ من جديد — فتمضي مرّةً بالأرقام الصحيحة.
     */
    public function test_a_live_sale_whose_balance_changed_is_refused_with_the_right_offer(): void
    {
        $this->customer->update(['points' => 300]);

        $refused = $this->till(['redeem_points' => 1000, 'redeem_discount' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.balance', 300)
            ->assertJsonPath('loyalty.points', 300)
            ->assertJsonPath('loyalty.discount', 3)
            ->assertJsonPath('loyalty.settings.redeemPoints', 100);
        $this->assertStringContainsString('300', $refused->json('errors.redeem_points.0'));

        $this->assertSame(0, Order::count(), 'لا بيعةَ بخصمٍ لم يُؤكَّد');
        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(300, (int) $this->customer->fresh()->points);

        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 3]);

        $this->assertSame(300, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(3.0, (float) $order->discount, 0.0001);
        $this->assertSame(1, $this->redeems());
        $this->assertNull($order->loyalty_review);
    }

    /** وقواعدُ تغيّرت بعد فتح الشاشة: يُردّ ومعه القواعدُ الجديدة، فتحسب الشاشةُ ما يحسبه الخادم */
    public function test_a_live_sale_whose_rules_changed_is_refused_with_the_new_rules(): void
    {
        $this->set('loyalty_redeem_value', '2'); // مئةُ نقطةٍ صارت بريالين

        $this->till(['redeem_points' => 500, 'redeem_discount' => 5])
            ->assertStatus(422)
            ->assertJsonPath('loyalty.settings.redeemValue', 2)
            ->assertJsonPath('loyalty.points', 1000)
            ->assertJsonPath('loyalty.discount', 20);

        $this->assertSame(0, Order::count());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /**
     * ═══ وبيعةٌ اكتملت بلا اتصال: لا تُردّ ولا يتغيّر مبلغُها ═══
     *
     * باعها الصندوقُ على ألف نقطة وقبض تسعين، ووصلت وقد صُرف من الرصيد
     * ستّمئة. فتُكتب بالخصم الذي قُبض عليه، ويُصرف من الرصيد ما وُجد لا أكثر،
     * والفرقُ للمراجعة — جرسٌ لصاحب المحلّ وسطرٌ في سجلّ النشاط.
     */
    public function test_a_completed_offline_sale_is_neither_refused_nor_repriced(): void
    {
        $this->customer->update(['points' => 400]);

        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10, 'replay' => true]);

        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001, 'الخصمُ كما قُبض');
        $this->assertEqualsWithDelta(90.0, (float) $order->total, 0.0001, 'والمبلغُ المدفوع لم يتغيّر');
        $this->assertSame(400, (int) $order->redeemed_points, 'صُرف ما وُجد لا أكثر');
        $this->assertSame((int) $order->points_earned, (int) $this->customer->fresh()->points, 'ولا رصيدَ سالب');

        $review = $order->loyalty_review;
        $this->assertSame(1000, $review['requested_points']);
        $this->assertSame(400, $review['spent_points']);
        $this->assertEqualsWithDelta(4.0, $review['covered'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $review['gap'], 0.0001);

        $bell = collect(Demo::notifications())->firstWhere('key', 'loyalty-review-'.$order->id);
        $this->assertNotNull($bell, 'صاحبُ المحلّ لا يُبلَّغ');
        $this->assertStringContainsString($order->number, $bell['text']);
        $this->assertTrue(ActivityLog::where('description', 'like', '%'.$order->number.'%تحتاج مراجعة%')->exists());

        // وجارٌ لا يرى جرسَ هذا المتجر
        $neighbour = User::create([
            'business_id' => $this->other->id, 'name' => 'الجار', 'email' => 'neighbour@loyalty.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($neighbour);
        $this->assertNull(collect(Demo::notifications())->firstWhere('key', 'loyalty-review-'.$order->id));
    }

    /** والبيعةُ نفسُها تصل مرّتين من الطابور: طلبٌ واحد، وصرفٌ واحد، ومراجعةٌ واحدة */
    public function test_a_replayed_sale_is_recorded_and_spent_once(): void
    {
        $this->customer->update(['points' => 400]);
        $payload = ['client_uuid' => 'offline-sale-1', 'redeem_points' => 1000, 'redeem_discount' => 10, 'replay' => true];

        $this->till($payload)->assertOk();
        $this->till($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, $this->redeems());
        $this->assertSame(1, Order::whereNotNull('loyalty_review')->count());
    }

    /** ولا يُزاد خصمٌ لم يعطه الصندوق: قواعدُ صارت أسخى بعد البيعة لا ترفع خصمَها */
    public function test_a_replay_never_adds_a_discount_the_till_did_not_give(): void
    {
        $this->set('loyalty_redeem_value', '2');

        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 3, 'replay' => true]);

        $this->assertEqualsWithDelta(3.0, (float) $order->discount, 0.0001, 'ثلاثةٌ كما قُبض — لا ستّة');
        $this->assertSame(300, (int) $order->redeemed_points);
        $this->assertNull($order->loyalty_review, 'نقاطُه تغطّي ما خُصم — لا مراجعة');
    }

    /** وخصمٌ قُبض فوق قيمة نقاطه يُكتب كما قُبض — والفرقُ للمراجعة لا لإعادة التسعير */
    public function test_a_replayed_discount_beyond_its_points_is_flagged_not_repriced(): void
    {
        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 30, 'replay' => true]);

        $this->assertEqualsWithDelta(30.0, (float) $order->discount, 0.0001);
        $this->assertSame(1000, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(20.0, $order->loyalty_review['gap'], 0.0001, 'ألفُ نقطةٍ تغطّي عشرة');
    }

    /** واستبدالٌ أُطفئ بعد البيعة: لا تُصرف نقطة، والخصمُ كما قُبض، والقرارُ لصاحب المحلّ */
    public function test_a_replay_after_redemption_was_switched_off_spends_nothing_and_asks(): void
    {
        $this->set('loyalty_redeem_enabled', '0');

        $order = $this->sell(['redeem_points' => 500, 'redeem_discount' => 5, 'replay' => true]);

        $this->assertEqualsWithDelta(5.0, (float) $order->discount, 0.0001);
        $this->assertSame(0, (int) $order->redeemed_points);
        $this->assertSame(0, $this->redeems());
        $this->assertSame(1000 + (int) $order->points_earned, (int) $this->customer->fresh()->points);
        $this->assertFalse($order->loyalty_review['program_on']);
        $this->assertEqualsWithDelta(5.0, $order->loyalty_review['gap'], 0.0001);
    }

    /** وإلغاءُ بيعةٍ صُرف لها أقلُّ ممّا طُلب يُعيد ما صُرف — لا ما طُلب — ويُطفئ جرسَها */
    public function test_cancelling_a_short_replayed_sale_returns_only_what_was_spent(): void
    {
        $this->customer->update(['points' => 400]);
        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10, 'replay' => true]);

        OrderCorrection::cancel($order->fresh(), 'أُلغيت');

        $this->assertSame(400, (int) $this->customer->fresh()->points, 'عاد ما صُرف — لا الألف');
        $this->assertNull(collect(Demo::notifications())->firstWhere('key', 'loyalty-review-'.$order->id));
    }

    /**
     * وشاشةٌ أقدم من هذا لا تقول ما خصمت — فلا يُعرف أكانت البيعةُ قد اكتملت.
     *
     * فلا تُردّ (ردُّها يُسقطها من طابورها)، ويُقدَّر خصمُها بقيمة نقاطها بقواعد
     * اليوم، ويُصرف ما وُجد، والفرقُ للمراجعة.
     */
    public function test_an_older_screen_without_the_agreed_discount_is_not_refused(): void
    {
        $this->customer->update(['points' => 400]);

        $order = $this->sell(['redeem_points' => 1000]);

        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
        $this->assertSame(400, (int) $order->redeemed_points);
        $this->assertNotNull($order->loyalty_review);
    }
}
