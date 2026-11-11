<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentLink;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoicePreparations;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Loyalty;
use App\Support\OrderCorrection;
use App\Support\PaymentLinks;
use App\Support\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReturnNote;
use Tests\TestCase;

/**
 * طريقتا الاستبدال: مبلغٌ ثابتٌ لكلّ عددٍ من النقاط، أو نسبةٌ من الفاتورة المؤهّلة.
 *
 * ═══ ما يحرسه ═══
 *
 *   - متجرٌ لم يختر يبقى على المبلغ الثابت بأرقامه كما كانت، ولا يُكتب له إعداد.
 *   - صاحبُ النشاط يختار الطريقةَ ويضبط النسبة، ويُرفض ما لا يجوز باسمٍ عربيّ.
 *   - في النسبة: الخصمُ من الفاتورة المؤهّلة بعد الخصومات الأخرى، ثمّ السقفُ
 *     والحدّان. والنقاطُ المصروفةُ تتناسب مع الخصم، ولا تُصرف نقطةٌ بلا مقابل.
 *   - الصندوقُ والفاتورةُ يخرجان بالخصم والضريبة والإجماليّ نفسِها — شاملةً
 *     وغيرَ شاملة — والقيدُ متوازن.
 *   - تغييرُ النسبة أو الطريقة أثناء البيع أو بين المسودّة والإصدار يُردّ ولا
 *     يُعدّل مبلغًا في صمت. والإلغاءُ والإشعارُ يعيدان النقاطَ مرّةً.
 *   - متجرٌ آخر بطريقةٍ أخرى لا يتسرّب.
 *
 * والحسابُ نفسُه مع مرآة الشاشة في `tests/fixtures/loyalty-quote-cases.json`
 * و`loyalty-quote-random-cases.json` (يقرؤهما vitest أيضًا).
 */
class LoyaltyRedeemsByAFixedAmountOrAPercentTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Branch $branch;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود النسبة', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        Ledger::seedChart($this->other->id);

        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@percent.test',
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

    /** كلُّ مئة نقطةٍ عشرةٌ بالمئة من الفاتورة المؤهّلة — مثالُ صاحب النشاط */
    private function percent(string $pct = '10', string $points = '100', ?Business $shop = null): void
    {
        $this->set('loyalty_redeem_mode', 'percent', $shop);
        $this->set('loyalty_redeem_percent', $pct, $shop);
        $this->set('loyalty_redeem_points', $points, $shop);
    }

    private function vat(bool $inclusive = false): void
    {
        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
        $this->set('tax_mode', $inclusive ? 'inclusive' : 'exclusive');
    }

    /** طلبُ الصندوق كما يُرسل — بلا افتراض نجاحه */
    private function till(array $extra = [], ?Product $product = null, float $qty = 1): TestResponse
    {
        $product ??= $this->product;

        return $this->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), array_merge([
                'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => $qty]],
                'payment_method' => 'نقدي',
                'customer_id' => $this->customer->id,
                'client_uuid' => uniqid('p', true),
            ], $extra));
    }

    private function sell(array $extra = [], ?Product $product = null, float $qty = 1): Order
    {
        $this->till($extra, $product, $qty)->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    /** مسودّةُ فاتورة عميل — تُصدَر ما لم يُطلب غيرُ ذلك */
    private function invoice(int $redeem, array $lines, ?Customer $customer = null, bool $issue = true): CustomerInvoice
    {
        $draft = CustomerInvoices::create($this->shop->id, $customer ?? $this->customer, [
            'issued_at' => '2026-11-11',
            'redeem_points' => $redeem,
        ], $lines, $this->owner->id);

        return $issue ? CustomerInvoices::issue($draft, $this->owner->id)->fresh('items') : $draft->fresh('items');
    }

    private function redeems(?Customer $customer = null): int
    {
        return PointTransaction::where('customer_id', ($customer ?? $this->customer)->id)->where('type', 'redeem')->count();
    }

    private function assertBalancedEntry(CustomerInvoice $invoice): void
    {
        $entry = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->sole();
        $lines = $entry->lines()->get();

        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) $lines->sum('credit'), 0.0001, 'قيدٌ غير متوازن');
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $lines->sum('debit'), 0.0001, 'القيدُ بغير إجماليّ الورقة');
    }

    /** الورقةُ تجمع إلى إجماليّها: رأسُها وبنودُها وحصصُ النقاط فيها */
    private function assertAddsUp(CustomerInvoice $invoice): void
    {
        $items = $invoice->items;

        $this->assertEqualsWithDelta((float) $invoice->loyalty_discount, round((float) $items->sum('loyalty_discount'), 3), 0.0001, 'حصصُ البنود غيرُ خصم النقاط');
        $this->assertEqualsWithDelta((float) $invoice->total, round((float) $items->sum('line_total'), 3), 0.0001, 'البنودُ لا تجمع إلى الإجماليّ');
        // وفي «الشاملة» كما في الصندوق: المجموعُ الفرعيّ بلا الضريبة المستخرجة، فالصيغةُ واحدة
        $this->assertEqualsWithDelta(
            (float) $invoice->total,
            round((float) $invoice->subtotal - (float) $invoice->discount_total + (float) $invoice->tax_total, 3),
            0.0001,
            'المجموعُ الفرعيّ − الخصم + الضريبة ≠ الإجماليّ',
        );
        foreach ($items as $item) {
            $this->assertLessThanOrEqual(round((float) $item->quantity * (float) $item->unit_price, 3) + 0.0005, (float) $item->discount, 'خصمُ بندٍ فوق قيمته');
            $this->assertLessThanOrEqual((float) $item->discount + 0.0005, (float) $item->loyalty_discount, 'نصيبُ النقاط خارج خصم البند');
        }
    }

    /* ═══════════════════ التاجرُ القائم لا يتغيّر عليه شيء ═══════════════════ */

    public function test_a_shop_that_saved_nothing_stays_on_the_fixed_amount(): void
    {
        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(Loyalty::FIXED, $rules['mode']);
        $this->assertSame(Loyalty::FIXED, Loyalty::tillSettings($this->shop->id)['redeemMode']);
        $this->assertSame(0, Setting::where('business_id', $this->shop->id)->where('key', 'like', 'loyalty%')->count(), 'القراءةُ لا تكتب إعدادًا');

        // مئةُ نقطةٍ بريال كما كانت: الألفُ عشرة، تحت سقف الخمسين
        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10]);
        $this->assertSame(1000, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $order->total, 0.0001);
    }

    /** إعداداتٌ محفوظةٌ قبل الطريقة (كمتجرٍ سقفُه ٢٥٪) تُقرأ كما كانت — بالأرقام نفسِها */
    public function test_settings_saved_before_the_choice_read_exactly_as_before(): void
    {
        $this->set('loyalty_redeem_max_pct', '25');
        $this->set('loyalty_redeem_points', '3');
        $this->set('loyalty_redeem_value', '0.1');
        $before = Setting::where('business_id', $this->shop->id)->orderBy('key')->get(['key', 'value'])->toArray();

        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(Loyalty::FIXED, $rules['mode']);
        // ٢٥٪ من مئة = خمسةٌ وعشرون؛ كلُّ ثلاث نقاطٍ بعُشر ريال: ٧٥٠ نقطة
        $quote = Loyalty::quote($rules, 1000, 100.0);
        $this->assertSame(750, $quote['max_points']);
        $this->assertEqualsWithDelta(25.0, $quote['max_discount'], 0.0001);

        $this->sell(['redeem_points' => 750, 'redeem_discount' => 25]);
        $this->assertSame($before, Setting::where('business_id', $this->shop->id)->orderBy('key')->get(['key', 'value'])->toArray(), 'البيعُ لا يمسّ الإعدادات');
    }

    /* ═══════════════════ الإعدادات ═══════════════════ */

    private function save(array $data): TestResponse
    {
        return $this->post(route('admin.marketing.loyalty.save'), array_merge([
            'loyalty_enabled' => true,
            'loyalty_earn_rate' => 5,
            'loyalty_redeem_enabled' => true,
            'loyalty_redeem_points' => 100,
            'loyalty_redeem_value' => 1,
            'loyalty_redeem_min_invoice' => 0,
            'loyalty_redeem_max_pct' => 50,
            'loyalty_redeem_min' => 100,
        ], $data));
    }

    public function test_the_owner_switches_to_a_percent_and_back(): void
    {
        $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 10])->assertSessionHasNoErrors();

        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(Loyalty::PERCENT, $rules['mode']);
        $this->assertSame(10.0, $rules['percent']);
        $this->assertTrue($rules['redeem']);
        $this->assertSame(1.0, $rules['value'], 'والقيمةُ الثابتةُ محفوظةٌ لمن يعود إليها');
        $this->assertSame(['redeemMode' => 'percent', 'redeemPercent' => 10.0], array_intersect_key(
            Loyalty::tillSettings($this->shop->id), ['redeemMode' => 1, 'redeemPercent' => 1],
        ));

        // ويعود إلى المبلغ الثابت — والنسبةُ تبقى محفوظةً كما كتبها
        $this->save(['loyalty_redeem_mode' => 'fixed', 'loyalty_redeem_value' => 0.5])->assertSessionHasNoErrors();
        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(Loyalty::FIXED, $rules['mode']);
        $this->assertSame(0.5, $rules['value']);
        $this->assertSame(10.0, $rules['percent']);
    }

    /** تبويبٌ أقدم لا يعرف الطريقة يحفظ فلا يقلبها */
    public function test_an_older_tab_that_does_not_send_the_method_keeps_it(): void
    {
        $this->percent('12.5');

        $this->save([])->assertSessionHasNoErrors();

        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(Loyalty::PERCENT, $rules['mode']);
        $this->assertSame(12.5, $rules['percent']);
    }

    public function test_a_percent_outside_zero_to_a_hundred_is_refused_by_its_arabic_name(): void
    {
        foreach (['0', '-5', '100.5', 'نصف'] as $bad) {
            $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => $bad])
                ->assertSessionHasErrors('loyalty_redeem_percent');
            $this->assertStringContainsString('نسبة الخصم مقابل النقاط', session('errors')->first('loyalty_redeem_percent'));
        }

        $this->save(['loyalty_redeem_mode' => 'half'])->assertSessionHasErrors('loyalty_redeem_mode');
        $this->assertStringContainsString('طريقة الاستبدال', session('errors')->first('loyalty_redeem_mode'));

        $this->assertSame(Loyalty::FIXED, Loyalty::rules($this->shop->id)['mode'], 'لم يُحفظ شيءٌ ممّا رُدّ');

        // والمئةُ نفسُها تجوز — والسقفُ يحدّها
        $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 100])->assertSessionHasNoErrors();
    }

    public function test_the_percent_accepts_arabic_digits(): void
    {
        $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => '١٢٫٥'])->assertSessionHasNoErrors();

        $this->assertSame(12.5, Loyalty::rules($this->shop->id)['percent']);
    }

    /** وفي النسبة سقفُ الاكتساب هو القديم — لا قيمةٌ مخفيّةٌ في حقلٍ لا يُرى */
    public function test_the_earn_ceiling_in_percent_mode_is_the_old_one(): void
    {
        // قيمةٌ ثابتةٌ كانت تجعل السقفَ تسعًا (عشرُ نقاطٍ بريال) — لا تُقرأ في النسبة
        $this->set('loyalty_redeem_points', '10');

        $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 10, 'loyalty_redeem_points' => 10, 'loyalty_earn_rate' => 50])
            ->assertSessionHasNoErrors();

        $this->save(['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 10, 'loyalty_earn_rate' => 100])
            ->assertSessionHasErrors('loyalty_earn_rate');
        $this->assertStringContainsString('99.9', session('errors')->first('loyalty_earn_rate'));
    }

    public function test_each_shop_keeps_its_own_method(): void
    {
        $this->percent(shop: $this->other);

        $this->assertSame(Loyalty::FIXED, Loyalty::rules($this->shop->id)['mode']);
        $this->assertSame(Loyalty::PERCENT, Loyalty::rules($this->other->id)['mode']);

        // والصندوقُ هنا يحسب بالمبلغ الثابت — نسبةُ الجار لا تصل
        $this->till(['redeem_points' => 500, 'redeem_discount' => 50])
            ->assertStatus(422)->assertJsonPath('loyalty.settings.redeemMode', 'fixed')
            ->assertJsonPath('loyalty.discount', 10);
        $this->assertSame(0, Order::count());
    }

    /* ═══════════════════ الصندوق بالنسبة ═══════════════════ */

    public function test_the_till_discounts_a_percent_of_the_invoice(): void
    {
        $this->percent();

        // ألفُ نقطةٍ = مئةٌ بالمئة، والسقفُ خمسون: يُردّ ومعه العرضُ الصحيح
        $this->till(['redeem_points' => 1000, 'redeem_discount' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.points', 500)->assertJsonPath('loyalty.discount', 50)
            ->assertJsonPath('loyalty.settings.redeemMode', 'percent')
            ->assertJsonPath('loyalty.settings.redeemPercent', 10);
        $this->assertSame(0, Order::count());

        // خمسُمئة نقطة = ٥٠٪ من مئة = خمسون
        $order = $this->sell(['redeem_points' => 500, 'redeem_discount' => 50]);
        $this->assertSame(500, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(50.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $order->total, 0.0001);
        // والاكتسابُ على ما دُفع: خمسون × خمس نقاط
        $this->assertSame(250, (int) $order->points_earned);
        $this->assertSame(1000 - 500 + 250, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
    }

    /** النسبةُ من الفاتورة المؤهّلة: بعد الكوبون — لا من المجموع قبله */
    public function test_the_till_takes_the_percent_after_a_coupon(): void
    {
        $this->percent();
        $this->customer->update(['points' => 300]);
        Coupon::create([
            'business_id' => $this->shop->id, 'code' => 'R20', 'type' => 'نسبة',
            'value' => 20, 'max_uses' => 10, 'used_count' => 0, 'active' => true,
        ]);

        // المؤهّلةُ ثمانون؛ ثلاثُمئة نقطةٍ = ٣٠٪ منها = أربعةٌ وعشرون (لا ثلاثون)
        $this->till(['coupon_code' => 'R20', 'redeem_points' => 300, 'redeem_discount' => 30])
            ->assertStatus(422)->assertJsonPath('loyalty.discount', 24);

        $order = $this->sell(['coupon_code' => 'R20', 'redeem_points' => 300, 'redeem_discount' => 24]);
        $this->assertEqualsWithDelta(20.0, (float) $order->coupon_discount, 0.0001);
        $this->assertEqualsWithDelta(44.0, (float) $order->discount, 0.0001, 'الكوبونُ والنقاطُ معًا');
        $this->assertEqualsWithDelta(24.0, $order->loyaltyDiscount(), 0.0001);
        $this->assertEqualsWithDelta(56.0, (float) $order->total, 0.0001);
    }

    public function test_the_till_taxes_what_is_left_after_a_percent_exclusive(): void
    {
        $this->percent();
        $this->vat();
        $this->customer->update(['points' => 300]);

        // ٣٠٪ من مئة، والضريبةُ ٥٪ على السبعين الباقية
        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 30]);
        $this->assertEqualsWithDelta(30.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(3.5, (float) $order->tax, 0.0001);
        $this->assertEqualsWithDelta(73.5, (float) $order->total, 0.0001);
        $this->assertEqualsWithDelta(round((float) $order->subtotal - (float) $order->discount + (float) $order->tax, 3), (float) $order->total, 0.0001);
    }

    public function test_the_till_takes_the_percent_of_the_price_shown_when_tax_is_inclusive(): void
    {
        $this->percent();
        $this->vat(inclusive: true);
        $this->customer->update(['points' => 300]);
        $this->product->update(['price' => 105]);

        // السعرُ المعروض ١٠٥ شاملًا؛ ٣٠٪ منه ٣١٫٥، ويدفع ٧٣٫٥ — منها ٣٫٥ ضريبة
        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 31.5]);
        $this->assertEqualsWithDelta(31.5, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(3.5, (float) $order->tax, 0.0001);
        $this->assertEqualsWithDelta(73.5, (float) $order->total, 0.0001);
        $this->assertEqualsWithDelta(101.5, (float) $order->subtotal, 0.0001, 'المجموعُ الفرعيّ بلا الضريبة المستخرجة');
    }

    /** نسبةٌ تغيّرت أثناء البيع: لا يُكتب شيء، ويُردّ العرضُ بالنسبة الجديدة */
    public function test_a_percent_changed_during_the_sale_is_refused_with_the_new_offer(): void
    {
        $this->percent();
        $this->customer->update(['points' => 300]);
        $this->set('loyalty_redeem_percent', '5');

        $this->till(['redeem_points' => 300, 'redeem_discount' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.settings.redeemPercent', 5)
            ->assertJsonPath('loyalty.points', 300)
            ->assertJsonPath('loyalty.discount', 15);

        $this->assertSame(0, Order::count());
        $this->assertSame(300, (int) $this->customer->fresh()->points);
    }

    /** وطريقةٌ تبدّلت أثناء البيع كذلك — الصندوقُ حسب بالمبلغ الثابت والمتجرُ صار بالنسبة */
    public function test_a_method_switched_during_the_sale_is_refused(): void
    {
        // الصندوقُ رأى ألفَ نقطةٍ بعشرة
        $this->percent();

        $this->till(['redeem_points' => 1000, 'redeem_discount' => 10])
            ->assertStatus(422)->assertJsonPath('loyalty.settings.redeemMode', 'percent')
            ->assertJsonPath('loyalty.points', 500)->assertJsonPath('loyalty.discount', 50);

        $this->assertSame(0, Order::count());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** بيعةٌ اكتملت بلا اتصال بالنسبة: لا تُردّ ولا يتغيّر مبلغُها، ويُصرف ما وُجد */
    public function test_an_offline_percent_sale_is_neither_refused_nor_repriced(): void
    {
        $this->percent();
        $this->customer->update(['points' => 200]);

        $order = $this->sell(['redeem_points' => 500, 'redeem_discount' => 50, 'replay' => true]);

        $this->assertEqualsWithDelta(50.0, (float) $order->discount, 0.0001, 'الخصمُ كما قُبض');
        $this->assertSame(200, (int) $order->redeemed_points, 'ما وُجد لا أكثر');
        $this->assertSame((int) $order->points_earned, (int) $this->customer->fresh()->points, 'ولا رصيدَ سالب');
        // مئتا نقطة = ٢٠٪ من مئة = عشرون؛ والفرقُ ثلاثون للمراجعة
        $this->assertEqualsWithDelta(20.0, $order->loyalty_review['covered'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $order->loyalty_review['gap'], 0.0001);
    }

    /** فاتورةٌ صغيرة: كلُّ نقطةٍ نصفُ بيسة — ونقاطٌ لا تبلغ بيسةً لا تُصرف */
    public function test_a_tiny_sale_never_spends_points_that_buy_nothing(): void
    {
        $this->percent();
        $cheap = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 0.5, 'cost' => 0.1,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        // ثلاثُ نقاطٍ تساوي بيسةً ونصفًا — تُقصّ إلى بيسة، فتكفي نقطتان: يُردّ بالعرض
        $this->till(['redeem_points' => 3, 'redeem_discount' => 0.001], $cheap)
            ->assertStatus(422)->assertJsonPath('loyalty.points', 500)->assertJsonPath('loyalty.discount', 0.25);
        $this->assertSame(0, Order::count());

        // ٥٠٠ نقطة = ٥٠٪ من نصف ريال = ٠٫٢٥٠
        $order = $this->sell(['redeem_points' => 500, 'redeem_discount' => 0.25], $cheap);
        $this->assertEqualsWithDelta(0.25, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(0.25, (float) $order->total, 0.0001);
        $this->assertSame(500, (int) $order->redeemed_points);
    }

    /** فاتورةٌ كبيرة بنسبةٍ كسريّة: ٢٫٥٪ لكلّ مئة نقطة، والسقفُ نصفُ ١٢٣٤٥٫٦٧٨ */
    public function test_a_large_sale_with_a_fractional_percent_is_exact_to_the_baisa(): void
    {
        $this->percent('2.5');
        $this->customer->update(['points' => 1000000]);
        $big = Product::create([
            'business_id' => $this->shop->id, 'name' => 'تنسيق قاعة', 'price' => 4115.226, 'cost' => 1000,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        // ٣ × ٤١١٥٫٢٢٦ = ١٢٣٤٥٫٦٧٨؛ السقفُ ٦١٧٢٫٨٣٩ = ٢٠٠٠ نقطة × ٢٫٥٪ ÷ ١٠٠
        $order = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 6172.839], $big, 3);
        $this->assertSame(2000, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(6172.839, (float) $order->discount, 0.00001);
        $this->assertEqualsWithDelta(6172.839, (float) $order->total, 0.00001);
    }

    /* ═══════════════════ فاتورةُ العميل بالنسبة ═══════════════════ */

    public function test_an_invoice_redeems_a_percent_and_its_entry_balances(): void
    {
        $this->percent();
        $this->vat();

        $invoice = $this->invoice(500, [['description' => 'باقة ورد أبيض', 'quantity' => 2, 'unit_price' => 50]]);

        // ٥٠٠ نقطة = ٥٠٪ من مئة = خمسون، والضريبةُ ٥٪ على الخمسين الباقية
        $this->assertSame(500, (int) $invoice->loyalty_points);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->subtotal, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->discount_total, 0.0001);
        $this->assertEqualsWithDelta(2.5, (float) $invoice->tax_total, 0.0001);
        $this->assertEqualsWithDelta(52.5, (float) $invoice->total, 0.0001);
        $this->assertAddsUp($invoice);
        $this->assertBalancedEntry($invoice);

        $this->assertSame(500, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
    }

    /**
     * بنودٌ بخصوماتها وضرائبَ مختلفة: النسبةُ من الصافي بعد خصوم البنود، وحصّةُ
     * كلّ بندٍ داخل خصمه، والضريبةُ لكلّ بندٍ على ما بقي منه.
     */
    public function test_a_percent_spreads_over_lines_with_their_own_discounts_and_tax_rates(): void
    {
        $this->percent();
        $this->vat();

        $invoice = $this->invoice(529, [
            ['description' => 'ورد أحمر', 'quantity' => 3, 'unit_price' => 20, 'discount' => 6],
            ['description' => 'خدمة توصيل معفاة', 'quantity' => 1, 'unit_price' => 46, 'tax_rate' => 0],
            ['description' => 'بطاقة', 'quantity' => 2, 'unit_price' => 0.5],
        ]);

        /*
         * المجموع ١٠٧، وخصمُ البند ستّة: المؤهّلةُ ١٠١. والسقفُ ٥٠٪ من ١٠٧ =
         * ٥٣٫٥ — فأقصى النقاط ٥٢٩ (٥٢٫٩٪ من ١٠١ = ٥٣٫٤٢٩). وتتوزّع على الصوافي
         * ٥٤ و٤٦ و١: ٢٨٫٥٦٦ و٢٤٫٣٣٤ و٠٫٥٢٩ (والبيسةُ الباقية لأكبرها).
         */
        $this->assertSame(529, (int) $invoice->loyalty_points);
        $this->assertEqualsWithDelta(53.429, (float) $invoice->loyalty_discount, 0.00001);
        $this->assertSame([28.566, 24.334, 0.529], $invoice->items->sortBy('id')->map(fn ($i) => round((float) $i->loyalty_discount, 3))->values()->all());
        $this->assertEqualsWithDelta(59.429, (float) $invoice->discount_total, 0.00001, 'خصمُ البند والنقاط معًا');

        // الضريبة: (٥٤ − ٢٨٫٥٦٦) × ٥٪ = ١٫٢٧٢، والمعفى صفر، و(١ − ٠٫٥٢٩) × ٥٪ = ٠٫٠٢٤
        $this->assertSame([1.272, 0.0, 0.024], $invoice->items->sortBy('id')->map(fn ($i) => round((float) $i->tax_amount, 3))->values()->all());
        $this->assertEqualsWithDelta(1.296, (float) $invoice->tax_total, 0.00001);
        $this->assertEqualsWithDelta(48.867, (float) $invoice->total, 0.00001);
        $this->assertAddsUp($invoice);
        $this->assertBalancedEntry($invoice);
        $this->assertSame(1000 - 529, (int) $this->customer->fresh()->points);
    }

    public function test_an_inclusive_invoice_takes_the_percent_of_the_price_shown(): void
    {
        $this->percent();
        $this->vat(inclusive: true);

        $invoice = $this->invoice(300, [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 105]]);

        $this->assertEqualsWithDelta(31.5, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(3.5, (float) $invoice->tax_total, 0.0001);
        $this->assertEqualsWithDelta(73.5, (float) $invoice->total, 0.0001);
        $this->assertBalancedEntry($invoice);
    }

    /** السلّةُ نفسُها في الصندوق وفي الفاتورة: الخصمُ والضريبةُ والإجماليُّ واحدة — شاملةً وغيرَ شاملة */
    public function test_the_same_basket_costs_the_same_on_the_till_and_on_the_invoice(): void
    {
        $this->percent('7.5', '150');

        foreach ([false, true] as $inclusive) {
            $this->vat($inclusive);
            $atTill = Customer::create(['business_id' => $this->shop->id, 'name' => 'زبون الصندوق', 'phone' => '9200000'.(int) $inclusive, 'points' => 777, 'language' => 'ar']);
            $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'زبون الفاتورة', 'phone' => '9300000'.(int) $inclusive, 'points' => 777, 'language' => 'ar']);
            $this->product->update(['price' => 33.333]);

            $offer = Loyalty::quote(Loyalty::rules($this->shop->id), 777, 33.333);
            $this->customer = $atTill;
            $order = $this->sell(['redeem_points' => $offer['max_points'], 'redeem_discount' => $offer['max_discount']]);
            $invoice = $this->invoice($offer['max_points'], [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 33.333]], $onInvoice);

            $label = $inclusive ? 'شاملة' : 'غير شاملة';
            $this->assertSame((int) $order->redeemed_points, (int) $invoice->loyalty_points, $label);
            $this->assertEqualsWithDelta($order->loyaltyDiscount(), (float) $invoice->loyalty_discount, 0.00001, $label);
            $this->assertEqualsWithDelta((float) $order->tax, (float) $invoice->tax_total, 0.00001, $label);
            $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.00001, $label);
            $this->assertSame((int) $atTill->fresh()->points - (int) $order->points_earned, (int) $onInvoice->fresh()->points, $label.': الرصيدُ نفسُه بعد الصرف');
        }
    }

    /** نسبةٌ تغيّرت بين المسودّة والإصدار: يُردّ الإصدار، ولا يتغيّر المبلغ ولا الرصيد */
    public function test_a_percent_changed_before_issuing_refuses_without_touching_the_amount(): void
    {
        $this->percent();
        $this->vat();
        $draft = $this->invoice(500, [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]], issue: false);
        $this->assertEqualsWithDelta(52.5, (float) $draft->total, 0.0001);

        $this->set('loyalty_redeem_percent', '20');

        try {
            CustomerInvoices::issue($draft);
            $this->fail('أُصدرت ورقةٌ بخصمٍ غيرِ ما تعطيه القواعد الآن');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تغيّر رصيد نقاط العميل أو قواعد الاستبدال', $e->getMessage());
        }

        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertEqualsWithDelta(52.5, (float) $draft->fresh()->total, 0.0001);
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
    }

    public function test_a_method_switched_before_issuing_refuses(): void
    {
        $this->vat();
        // كُتبت بالمبلغ الثابت: ٥٠٠ نقطة بخمسة
        $draft = $this->invoice(500, [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]], issue: false);
        $this->assertEqualsWithDelta(5.0, (float) $draft->loyalty_discount, 0.0001);

        $this->percent();

        $this->expectException(\RuntimeException::class);
        try {
            CustomerInvoices::issue($draft);
        } finally {
            $this->assertSame(1000, (int) $this->customer->fresh()->points);
            $this->assertSame(0, $this->redeems());
        }
    }

    /** نقاطٌ مطلوبةٌ فوق ما يبلغ قيمتَها تُردّ بالعرض — ولا تُكتب ورقة */
    public function test_an_invoice_refuses_points_that_buy_nothing_more(): void
    {
        $this->percent();

        try {
            $this->invoice(3, [['description' => 'وردة', 'quantity' => 1, 'unit_price' => 0.5]]);
            $this->fail('كُتبت ورقةٌ بنقطةٍ لا يقابلها خصم');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('redeem_points', $e->errors());
        }

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /** مسودّتان على الرصيد نفسِه: الأولى تصرف، والثانية تُردّ ولا تنزل بالرصيد تحت الصفر */
    public function test_two_invoices_cannot_spend_the_same_points(): void
    {
        $this->percent();
        $this->customer->update(['points' => 700]);
        $lines = [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]];

        $a = $this->invoice(500, $lines, issue: false);
        $b = $this->invoice(500, $lines, issue: false);

        CustomerInvoices::issue($a, $this->owner->id);
        try {
            CustomerInvoices::issue($b->fresh(), $this->owner->id);
            $this->fail('صُرف الرصيدُ مرّتين');
        } catch (\RuntimeException) {
        }

        $this->assertSame(200, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertSame(CustomerInvoice::DRAFT, $b->fresh()->status);
    }

    /** إشعارٌ بنصف الورقة يعيد نصفَ النقاط، والإلغاءُ يعيد الباقي — مرّةً */
    public function test_a_credit_then_a_cancel_return_the_points_once(): void
    {
        $this->percent();
        $this->vat();
        $invoice = $this->invoice(500, [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]]);
        $this->assertEqualsWithDelta(52.5, (float) $invoice->total, 0.0001);

        ReturnNote::issue($invoice->fresh(), 26.25, $this->owner->id);
        $this->assertSame(250, CustomerInvoices::pointsReturned($invoice->fresh()));
        $this->assertSame(750, (int) $this->customer->fresh()->points);

        CustomerInvoices::cancel($invoice->fresh(), 'طلب العميل');
        CustomerInvoices::cancel($invoice->fresh(), 'مكرّر');
        CustomerInvoices::returnPoints($invoice->fresh());

        $this->assertSame(500, CustomerInvoices::pointsReturned($invoice->fresh()));
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'عاد ما صُرف كلُّه، لا أكثر');
    }

    public function test_the_invoice_screen_offers_the_percent_it_will_write(): void
    {
        $this->percent();
        $this->vat();
        $items = [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]];

        $offer = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id, 'items' => $items,
        ])->assertOk()->json('loyalty');

        $this->assertSame(500, $offer['points']);
        $this->assertEqualsWithDelta(50.0, $offer['discount'], 0.0001);
        $this->assertEqualsWithDelta(50.0, $offer['percent'], 0.0001, 'يُقال كم بالمئة من الفاتورة');

        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'آجل',
            'issue' => true, 'items' => $items, 'redeem_points' => 500, 'redeem_discount' => 50,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(52.5, (float) CustomerInvoice::sole()->total, 0.0001);
        $this->assertSame(500, (int) $this->customer->fresh()->points);

        // وفي المبلغ الثابت لا نسبةَ تُقال
        $this->set('loyalty_redeem_mode', 'fixed');
        $this->assertNull($this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id, 'items' => $items,
        ])->assertOk()->json('loyalty.percent'));
    }

    /** الورقةُ المطبوعة وإيصالُ الصندوق: سطرُ النقاط بعددها وقيمتها الفعليّة، مرّةً */
    public function test_the_printed_invoice_and_receipt_show_the_points_line_once(): void
    {
        $this->percent();
        $this->vat();

        $invoice = $this->invoice(500, [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]]);
        $html = PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh());
        $this->assertSame(1, substr_count($html, 'خصم نقاط الولاء'));
        $this->assertStringContainsString('500 نقطة', $html);
        $this->assertStringContainsString('50.000', $html);
        $this->assertStringContainsString('52.500', $html);

        $this->customer->update(['points' => 300]);
        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 30]);
        $strip = PdfController::saleHtml($this->shop->id, $order->fresh('items'), true)['html'];
        $this->assertSame(1, substr_count($strip, 'data-loyalty'));
        $this->assertStringContainsString('300', $strip);
        $this->assertStringContainsString('30.000', $strip);
    }

    /* ═══════════════════ السقفان الاختياريّان: سياسةُ صاحب النشاط ═══════════════════ */

    /** متجرٌ جديد يأخذ الافتراضَ المعتمد — وسقفُ المبلغ بلا حدٍّ حتّى يكتبه */
    public function test_a_new_shop_gets_the_approved_defaults(): void
    {
        $fresh = Business::create(['name' => 'متجر جديد', 'type' => 'عام', 'status' => 'نشط']);
        $rules = Loyalty::rules($fresh->id);

        $this->assertSame(Loyalty::FIXED, $rules['mode']);
        $this->assertSame(100, $rules['points']);
        $this->assertSame(1.0, $rules['value']);
        $this->assertSame(100, $rules['min_points']);
        $this->assertSame(0.0, $rules['min_invoice']);
        $this->assertSame(50.0, $rules['max_pct']);
        $this->assertNull($rules['max_amount']);
        $this->assertNull(Loyalty::tillSettings($fresh->id)['redeemMaxAmount']);
        $this->assertSame(0, Setting::where('business_id', $fresh->id)->count(), 'ولا يُكتب له إعداد');
    }

    /** يترك صاحبُ النشاط السقفين فارغين: لا سقفَ إلّا الفاتورة نفسُها — ولا مستحقَّ سالب */
    public function test_with_both_caps_left_empty_points_may_cover_the_whole_sale_and_no_more(): void
    {
        $this->save(['loyalty_redeem_max_pct' => '', 'loyalty_redeem_max_amount' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', Setting::where('business_id', $this->shop->id)->where('key', 'loyalty_redeem_max_pct')->value('value'));

        $rules = Loyalty::rules($this->shop->id);
        $this->assertNull($rules['max_pct']);
        $this->assertNull($rules['max_amount']);
        $this->assertNull(Loyalty::tillSettings($this->shop->id)['redeemMaxPct'], '«بلا حدّ» لا صفر');

        $this->vat();
        $this->customer->update(['points' => 20000]);

        // مئةٌ وضريبتُها: النقاطُ تغطّي المئة كلَّها (١٠٠٠٠ نقطة) ولا تتجاوزها
        $this->till(['redeem_points' => 20000, 'redeem_discount' => 200])
            ->assertStatus(422)->assertJsonPath('loyalty.points', 10000)->assertJsonPath('loyalty.discount', 100);

        $order = $this->sell(['redeem_points' => 10000, 'redeem_discount' => 100]);
        $this->assertEqualsWithDelta(100.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $order->tax, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $order->total, 0.0001, 'المستحقّ صفرٌ لا سالب');
        $this->assertSame(10000, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ فاتورةٌ تغطّيها النقاطُ كلُّها: إجماليٌّ صفر ═══════════════════ */

    private function wholeInvoiceByPoints(): array
    {
        $this->set('loyalty_redeem_max_pct', '');
        $this->vat();
        $this->customer->update(['points' => 20000]);

        return [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]];
    }

    /**
     * سقوفٌ تركها صاحبُ النشاط فارغة: النقاطُ تغطّي الفاتورةَ كلَّها كما تغطّي
     * بيعةَ الصندوق. تُصدَر بصفر، وتُصرف نقاطُها مرّةً، ولا قيدَ بأصفار، وتُقرأ
     * مسدَّدة، ولا إشعارَ دائنٍ عليها، والإلغاءُ يعيد النقاطَ مرّةً.
     */
    public function test_points_may_cover_a_whole_invoice_which_issues_at_zero_and_cancels_back_once(): void
    {
        $items = $this->wholeInvoiceByPoints();

        $offer = $this->postJson(route('admin.customerInvoices.preview'), [
            'customer_id' => $this->customer->id, 'items' => $items,
        ])->assertOk()->json('loyalty');
        $this->assertTrue($offer['eligible']);
        $this->assertSame(10000, $offer['points']);
        $this->assertEqualsWithDelta(100.0, $offer['discount'], 0.0001);

        $invoice = $this->invoice(10000, $items);

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertNotNull($invoice->number, 'تُرقَّم كأيّ فاتورة');
        $this->assertEqualsWithDelta(100.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->tax_total, 0.0001, 'لا ضريبةَ على ما لم يُدفع');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->total, 0.0001);
        $this->assertAddsUp($invoice);
        $this->assertTrue(CustomerInvoices::coveredByPoints($invoice));
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->count(), 'لا قيدَ بأصفار');
        $this->assertSame('مدفوعة', $invoice->paymentState());
        $this->assertEqualsWithDelta(0.0, $invoice->outstanding(), 0.0001);
        $this->assertTrue(CustomerInvoicePreparations::paid($invoice), 'تبلغ لوحةَ التجهيز كمسدَّدة');
        $this->assertSame(10000, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());

        // والورقةُ المطبوعة: سطرُ النقاط مرّةً، والإجماليُّ صفر
        $html = PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh());
        $this->assertSame(1, substr_count($html, 'خصم نقاط الولاء'));
        $this->assertStringContainsString('10000 نقطة', $html);
        $this->assertStringContainsString('100.000', $html);

        // ولا إشعارَ دائنٍ على ما لم يُدفع منه شيء
        try {
            ReturnNote::issue($invoice->fresh(), 1, $this->owner->id);
            $this->fail('أُصدر إشعارٌ دائنٌ على فاتورةٍ صفريّة');
        } catch (\RuntimeException|ValidationException) {
        }
        $this->assertSame(0, CustomerInvoices::pointsReturned($invoice->fresh()));

        // والإلغاءُ يعيد العشرةَ آلاف — مرّةً ولو تكرّر
        CustomerInvoices::cancel($invoice->fresh(), 'طلب العميل');
        CustomerInvoices::cancel($invoice->fresh(), 'مكرّر');
        CustomerInvoices::returnPoints($invoice->fresh());
        $this->assertSame(20000, (int) $this->customer->fresh()->points);
        $this->assertSame(1, PointTransaction::where('source_key', CustomerInvoices::redeemKey($invoice).':reverse')->count());
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->count(), 'ولا عكسَ لقيدٍ لم يُكتب');
    }

    /** والشاملةُ كذلك: سعرٌ معروضٌ بـ١٠٥ تغطّيه ١٠٥٠٠ نقطة — والصندوقُ والفاتورةُ صفرٌ معًا */
    public function test_a_whole_inclusive_basket_is_zero_on_the_till_and_on_the_invoice(): void
    {
        $this->set('loyalty_redeem_max_pct', '');
        $this->vat(inclusive: true);
        $this->product->update(['price' => 105]);
        $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'فاتورة', 'phone' => '93000001', 'points' => 20000, 'language' => 'ar']);
        $this->customer->update(['points' => 20000]);

        $order = $this->sell(['redeem_points' => 10500, 'redeem_discount' => 105]);
        $invoice = $this->invoice(10500, [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 105]], $onInvoice);

        foreach ([[(float) $order->discount, (float) $invoice->discount_total, 'الخصم'], [(float) $order->tax, (float) $invoice->tax_total, 'الضريبة'], [(float) $order->total, (float) $invoice->total, 'الإجماليّ']] as [$till, $paper, $what]) {
            $this->assertEqualsWithDelta($till, $paper, 0.00001, $what);
        }
        $this->assertEqualsWithDelta(0.0, (float) $invoice->total, 0.0001);
        $this->assertEqualsWithDelta(105.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertSame(9500, (int) $onInvoice->fresh()->points);
    }

    /** من الشاشة: «نقدي» لا يُكتب له إيصالٌ بصفر، و«رابط دفع» لا يُطلب من Paymob */
    public function test_a_whole_invoice_by_points_collects_nothing_and_asks_paymob_for_nothing(): void
    {
        $items = $this->wholeInvoiceByPoints();
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake();

        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'نقدي',
            'issue' => true, 'items' => $items, 'redeem_points' => 10000, 'redeem_discount' => 100,
        ])->assertSessionHasNoErrors();
        $cash = CustomerInvoice::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $cash->total, 0.0001);
        $this->assertSame(0, CustomerPayment::count(), 'لا إيصالَ بصفر');
        $this->assertSame('مدفوعة', $cash->paymentState());

        // ومبلغٌ كُتب فوق الصفر يُردّ ولا يُكتب شيء (`refresh`: النسخةُ في اليد تحمل ٢٠٠٠٠ قديمة)
        $this->customer->refresh()->update(['points' => 20000]);
        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'نقدي', 'paid_amount' => 5,
            'issue' => true, 'items' => $items, 'redeem_points' => 10000, 'redeem_discount' => 100,
        ])->assertSessionHasErrors('paid_amount');
        $this->assertSame(1, CustomerInvoice::count());
        $this->assertSame(20000, (int) $this->customer->fresh()->points);

        $this->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-11-11', 'payment_method' => 'آجل', 'payment_link' => true,
            'issue' => true, 'items' => $items, 'redeem_points' => 10000, 'redeem_discount' => 100,
        ])->assertSessionHasNoErrors()->assertSessionHas('toast', fn ($t) => str_contains($t['msg'], 'لا يُطلب لها رابط دفع'));
        $linked = CustomerInvoice::latest('id')->firstOrFail();
        $this->assertSame(CustomerInvoice::ISSUED, $linked->status);

        // وطلبُ رابطٍ لها بعدُ يُردّ قبل Paymob
        try {
            PaymentLinks::forInvoice($linked, $this->owner->id);
            $this->fail('طُلب رابطٌ لفاتورةٍ لا باقيَ لها');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('مسدَّدة', $e->getMessage());
        }

        $this->assertSame(0, CustomerPaymentLink::count());
        Http::assertNothingSent();
    }

    /** وصفرٌ لغير النقاط يُردّ كما كان: خصمُ بندٍ يدويٌّ يمحو البندَ كلَّه */
    public function test_a_zero_invoice_not_covered_by_points_is_still_refused(): void
    {
        $this->vat();
        $draft = $this->invoice(0, [['description' => 'هديّة', 'quantity' => 1, 'unit_price' => 50, 'discount' => 50]], issue: false);
        $this->assertEqualsWithDelta(0.0, (float) $draft->total, 0.0001);

        $this->expectExceptionMessage('لا تُصدَر فاتورةٌ بلا مبلغ.');
        CustomerInvoices::issue($draft, $this->owner->id);
    }

    /** وسقفٌ وضعه صاحبُ النشاط بين المسودّة والإصدار يردّ الإصدار — ولا يمسّ المبلغ ولا الرصيد */
    public function test_a_cap_set_after_a_whole_points_draft_refuses_its_issue(): void
    {
        $draft = $this->invoice(10000, $this->wholeInvoiceByPoints(), issue: false);
        $this->assertEqualsWithDelta(0.0, (float) $draft->total, 0.0001);

        $this->set('loyalty_redeem_max_pct', '50');

        try {
            CustomerInvoices::issue($draft, $this->owner->id);
            $this->fail('أُصدرت بخصمٍ فوق السقف الجديد');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تغيّر رصيد نقاط العميل أو قواعد الاستبدال', $e->getMessage());
        }
        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertSame(20000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());
    }

    /** وتقريرُ العملاء يعدّها شراءً كما يعدّ بيعةَ الصندوق الصفريّة — بلا مبلغٍ يُضاف */
    public function test_a_whole_points_invoice_counts_as_a_purchase_in_the_customer_report(): void
    {
        $this->invoice(10000, $this->wholeInvoiceByPoints());

        $row = collect(Demo::customerSpend(ReportingPeriod::all(), $this->shop->id))->firstWhere('name', $this->customer->name);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['orders']);
        $this->assertEqualsWithDelta(0.0, $row['total'], 0.0001);
    }

    /** سقفُ المبلغ يحدّ الطريقتين — والأصغرُ من السقفين هو الحدّ */
    public function test_an_amount_cap_limits_both_methods(): void
    {
        $this->customer->update(['points' => 20000]);
        $this->set('loyalty_redeem_max_pct', '');
        $this->set('loyalty_redeem_max_amount', '15');

        // مبلغٌ ثابت: مئةُ نقطةٍ بريال — خمسة عشر = ١٥٠٠ نقطة
        $this->till(['redeem_points' => 10000, 'redeem_discount' => 100])
            ->assertStatus(422)->assertJsonPath('loyalty.points', 1500)->assertJsonPath('loyalty.discount', 15)
            ->assertJsonPath('loyalty.settings.redeemMaxAmount', 15);

        // نسبة: ١٥٪ من مئة = ١٥٠ نقطة
        $this->percent();
        $order = $this->sell(['redeem_points' => 150, 'redeem_discount' => 15]);
        $this->assertEqualsWithDelta(15.0, (float) $order->discount, 0.0001);

        // والسقفان معًا: ١٠٪ أصغرُ من خمسة عشر
        $this->set('loyalty_redeem_max_pct', '10');
        $quote = Loyalty::quote(Loyalty::rules($this->shop->id), 20000, 100.0);
        $this->assertSame(100, $quote['max_points']);
        $this->assertEqualsWithDelta(10.0, $quote['max_discount'], 0.0001);

        // ونسبةٌ كسريّة تُقبل: ١٢٫٥٪
        $this->set('loyalty_redeem_max_pct', '12.5');
        $this->assertEqualsWithDelta(12.5, Loyalty::quote(Loyalty::rules($this->shop->id), 20000, 100.0)['max_discount'], 0.0001);
    }

    public function test_the_caps_are_validated_by_their_arabic_names(): void
    {
        foreach (['0', '-1', 'كثير'] as $bad) {
            $this->save(['loyalty_redeem_max_amount' => $bad])->assertSessionHasErrors('loyalty_redeem_max_amount');
            $this->assertStringContainsString('الحد الأقصى لمبلغ الخصم', session('errors')->first('loyalty_redeem_max_amount'));
        }
        foreach (['101', '-1'] as $bad) {
            $this->save(['loyalty_redeem_max_pct' => $bad])->assertSessionHasErrors('loyalty_redeem_max_pct');
        }

        $this->save(['loyalty_redeem_max_pct' => '12.5', 'loyalty_redeem_max_amount' => '٧٫٥'])->assertSessionHasNoErrors();
        $rules = Loyalty::rules($this->shop->id);
        $this->assertSame(12.5, $rules['max_pct']);
        $this->assertSame(7.5, $rules['max_amount']);

        // وتبويبٌ أقدم لا يعرف سقفَ المبلغ يحفظ فلا يمحوه
        $this->save([])->assertSessionHasNoErrors();
        $this->assertSame(7.5, Loyalty::rules($this->shop->id)['max_amount']);
    }

    public function test_one_shops_caps_never_reach_another(): void
    {
        $this->set('loyalty_redeem_max_amount', '1', $this->other);
        $this->set('loyalty_redeem_max_pct', '', $this->other);

        $mine = Loyalty::rules($this->shop->id);
        $this->assertSame(50.0, $mine['max_pct']);
        $this->assertNull($mine['max_amount']);

        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10]);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
    }

    /* ═══════════════════ تعديلُ الإعدادات لاحقًا لا يمسّ ما صدر ═══════════════════ */

    public function test_changing_every_rule_later_leaves_issued_and_paid_documents_as_they_were(): void
    {
        $this->vat();
        $invoice = $this->invoice(500, [['description' => 'باقة', 'quantity' => 2, 'unit_price' => 50]]);
        CustomerPayments::record($this->shop->id, $this->customer, 99.75, ['method' => 'نقدي', 'occurred_at' => '2026-11-11'], [$invoice->id => 99.75]);
        $order = $this->sell(['redeem_points' => 300, 'redeem_discount' => 3]);

        $before = [
            'invoice' => $invoice->fresh()->only(['subtotal', 'discount_total', 'tax_total', 'total', 'loyalty_points', 'loyalty_discount', 'status']),
            'items' => $invoice->fresh('items')->items->map->only(['discount', 'loyalty_discount', 'tax_amount', 'line_total'])->all(),
            'order' => $order->fresh()->only(['subtotal', 'discount', 'tax', 'total', 'redeemed_points']),
        ];
        $paper = PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh());

        // صاحبُ النشاط يغيّر كلَّ شيء
        $this->save([
            'loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 50, 'loyalty_redeem_points' => 10,
            'loyalty_redeem_max_pct' => '', 'loyalty_redeem_max_amount' => '1', 'loyalty_redeem_min' => 0,
            'loyalty_redeem_min_invoice' => 1000, 'loyalty_redeem_enabled' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame($before['invoice'], $invoice->fresh()->only(['subtotal', 'discount_total', 'tax_total', 'total', 'loyalty_points', 'loyalty_discount', 'status']));
        $this->assertSame($before['items'], $invoice->fresh('items')->items->map->only(['discount', 'loyalty_discount', 'tax_amount', 'line_total'])->all());
        $this->assertSame($before['order'], $order->fresh()->only(['subtotal', 'discount', 'tax', 'total', 'redeemed_points']));
        $this->assertSame($paper, PdfController::customerInvoiceHtml($this->shop->id, $invoice->fresh()), 'الورقةُ المطبوعة كما كانت');

        // والإعادةُ بما صُرف لا بقواعد اليوم: إشعارٌ بنصف الورقة يعيد نصفَ الخمسمئة
        ReturnNote::issue($invoice->fresh(), 49.875, $this->owner->id);
        $this->assertSame(250, CustomerInvoices::pointsReturned($invoice->fresh()));

        // وإلغاءُ البيعة يعيد ثلاثَمئتها ويسحب ما اكتسبته — فيعود الرصيدُ كما كان قبلها
        $beforeCancel = (int) $this->customer->fresh()->points;
        OrderCorrection::cancel($order->fresh(), 'أُلغيت');
        $this->assertSame($beforeCancel + 300 - (int) $order->points_earned, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ الصندوقُ والفاتورةُ رقمٌ واحد ═══════════════════ */

    /**
     * السلّةُ نفسُها بابين — في كلّ ما يضبطه التاجر: الطريقتان، والضريبةُ
     * شاملةً وغيرَ شاملة، والسقوفُ (افتراضٌ، بلا حدّ، مبلغ)، وخصمٌ آخر قبل
     * النقاط (كوبونٌ بمبلغ في الصندوق، وخصمُ بندٍ بالمبلغ نفسِه في الفاتورة).
     */
    public function test_the_till_and_the_invoice_agree_in_every_setting(): void
    {
        $this->product->update(['price' => 33.333]);
        Coupon::create([
            'business_id' => $this->shop->id, 'code' => 'OFF5', 'type' => 'مبلغ',
            'value' => 5, 'max_uses' => 1000, 'used_count' => 0, 'active' => true,
        ]);
        $n = 0;

        foreach (['fixed', 'percent'] as $mode) {
            $mode === 'percent' ? $this->percent('7.5', '150') : $this->set('loyalty_redeem_mode', 'fixed');
            $this->set('loyalty_redeem_points', $mode === 'percent' ? '150' : '3');
            $this->set('loyalty_redeem_value', '0.1');

            foreach ([false, true] as $inclusive) {
                $this->vat($inclusive);

                foreach ([['50', ''], ['', ''], ['', '7']] as [$pct, $amount]) {
                    $this->set('loyalty_redeem_max_pct', $pct);
                    $this->set('loyalty_redeem_max_amount', $amount);

                    foreach ([0, 5] as $other) {
                        $n++;
                        $label = "$mode / ".($inclusive ? 'شاملة' : 'غير شاملة')." / سقف [$pct|$amount] / خصم آخر $other";
                        $atTill = Customer::create(['business_id' => $this->shop->id, 'name' => 'صندوق '.$n, 'phone' => (string) (92000000 + $n), 'points' => 777, 'language' => 'ar']);
                        $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'فاتورة '.$n, 'phone' => (string) (93000000 + $n), 'points' => 777, 'language' => 'ar']);

                        $offer = Loyalty::quote(Loyalty::rules($this->shop->id), 777, 33.333, (float) $other);
                        $this->assertTrue($offer['eligible'], $label);

                        $this->customer = $atTill;
                        $order = $this->sell(array_filter([
                            'redeem_points' => $offer['max_points'], 'redeem_discount' => $offer['max_discount'],
                            'coupon_code' => $other ? 'OFF5' : null,
                        ]));
                        $invoice = $this->invoice($offer['max_points'], [[
                            'description' => 'باقة', 'quantity' => 1, 'unit_price' => 33.333, 'discount' => $other,
                        ]], $onInvoice);

                        $this->assertSame((int) $order->redeemed_points, (int) $invoice->loyalty_points, $label);
                        $this->assertEqualsWithDelta($order->loyaltyDiscount(), (float) $invoice->loyalty_discount, 0.00001, $label);
                        $this->assertEqualsWithDelta((float) $order->discount, (float) $invoice->discount_total, 0.00001, $label.': الخصمُ كلُّه');
                        $this->assertEqualsWithDelta((float) $order->tax, (float) $invoice->tax_total, 0.00001, $label);
                        $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.00001, $label);
                        $this->assertGreaterThanOrEqual(0.0, (float) $invoice->total, $label);
                        $this->assertAddsUp($invoice);
                        $this->assertBalancedEntry($invoice);
                    }
                }
            }
        }

        $this->assertSame(24, $n);
    }

    /* ═══════════════════ الحسابُ في كلّ الأرقام ═══════════════════ */

    /**
     * أرقامٌ عشوائيّةٌ ببذرةٍ ثابتة — الطريقتان، صغيرةً وكبيرة:
     *
     *   - لا يُصرف أكثرُ من الرصيد، ولا يُخصم أكثرُ من المؤهّلة ولا من السقف.
     *   - لا نقطةَ بلا مقابل: نقطةٌ أقلّ تعطي خصمًا أقلّ.
     *   - الخصمُ يتناسب مع النقاط: لا يتجاوز قيمتَها، ولا ينقص عنها بأكثر من بيسة.
     */
    public function test_every_quote_keeps_the_money_rules(): void
    {
        mt_srand(238);

        for ($i = 0; $i < 3000; $i++) {
            $percent = $i % 2 === 0;
            $rules = [
                'on' => true, 'redeem' => true, 'min_points' => mt_rand(0, 3) * 50,
                'mode' => $percent ? Loyalty::PERCENT : Loyalty::FIXED,
                'points' => [1, 3, 7, 100, 150, 1000, 3000][mt_rand(0, 6)],
                'value' => [0.001, 0.1, 0.5, 1.0, 2.75][mt_rand(0, 4)],
                'percent' => [0.5, 2.5, 7.5, 10.0, 33.333, 100.0][mt_rand(0, 5)],
                'min_invoice' => [0.0, 0.0, 5.0][mt_rand(0, 2)],
                'max_pct' => [0.0, 10.0, 12.5, 25.0, 50.0, 100.0, null][mt_rand(0, 6)],
                'max_amount' => [null, null, 0.5, 7.0, 1234.567][mt_rand(0, 4)],
            ];
            $base = mt_rand(1, 2) === 1 ? mt_rand(1, 2000) / 1000 : mt_rand(1, 50000000) / 1000;
            $other = mt_rand(0, 2) === 0 ? round($base * mt_rand(0, 100) / 100, 3) : 0.0;
            $balance = mt_rand(0, 200000);
            $requested = mt_rand(0, 1) ? null : mt_rand(0, 200000);

            $q = Loyalty::quote($rules, $balance, $base, $other, $requested);
            $why = json_encode(compact('rules', 'base', 'other', 'balance', 'requested'));
            $remaining = round($base - $other, 3);

            $this->assertLessThanOrEqual($balance, $q['points'], $why);
            $this->assertLessThanOrEqual($q['max_points'], $q['points'], $why);
            $this->assertLessThanOrEqual($remaining + 0.0005, $q['discount'], $why);
            if ($rules['max_pct'] !== null) {
                $this->assertLessThanOrEqual(round($base * $rules['max_pct'] / 100, 3) + 0.0005, $q['max_discount'], $why);
            }
            if ($rules['max_amount'] !== null) {
                $this->assertLessThanOrEqual($rules['max_amount'] + 0.0005, $q['max_discount'], $why);
            }
            $this->assertSame(Loyalty::cap($rules, round($base, 3), max(0.0, $remaining)) >= $q['max_discount'], true, $why);
            $this->assertSame($q['discount'], Loyalty::worth($rules, $q['points'], $remaining), $why);

            if ($q['points'] > 0) {
                $this->assertGreaterThan(0, $q['discount'], 'نقاطٌ بلا خصم: '.$why);
                $this->assertLessThan($q['discount'], Loyalty::worth($rules, $q['points'] - 1, $remaining), 'نقطةٌ زائدة: '.$why);

                $exact = $percent
                    ? $q['points'] * $remaining * $rules['percent'] / 100 / $rules['points']
                    : $q['points'] * $rules['value'] / $rules['points'];
                $this->assertLessThanOrEqual($exact + 0.0000005, $q['discount'], 'خصمٌ فوق قيمة النقاط: '.$why);
                $this->assertGreaterThan($exact - 0.001, $q['discount'], 'خصمٌ دون قيمة النقاط بأكثر من بيسة: '.$why);
            } else {
                $this->assertSame(0.0, $q['discount'], $why);
            }
        }
    }

    /**
     * والحالاتُ العشوائيّةُ المشتركة مع مرآة الشاشة — كتبها الخادم.
     *
     * يقرؤها `the-till-and-the-server-redeem-the-same-points` أيضًا: أيُّ فرقٍ
     * في التقريب بين PHP وJavaScript يُسقط أحدَهما. وتُعاد كتابتُها إن تغيّرت
     * الصيغة عمدًا (`LOYALTY_REGENERATE_CASES=1`).
     */
    public function test_the_random_shared_cases_are_what_the_server_computes(): void
    {
        $path = base_path('tests/fixtures/loyalty-quote-random-cases.json');

        if (getenv('LOYALTY_REGENERATE_CASES') === '1') {
            mt_srand(2380);
            $cases = [];
            for ($i = 0; $i < 400; $i++) {
                $rules = [
                    'on' => true, 'redeem' => true, 'min_points' => mt_rand(0, 2) * 100,
                    'mode' => $i % 2 ? Loyalty::PERCENT : Loyalty::FIXED,
                    'points' => [1, 3, 7, 100, 150, 1000, 3000][mt_rand(0, 6)],
                    'value' => [0.001, 0.1, 0.5, 1.0, 2.75][mt_rand(0, 4)],
                    'percent' => [0.5, 2.5, 7.5, 10.0, 33.333, 100.0][mt_rand(0, 5)],
                    'min_invoice' => [0.0, 0.0, 5.0][mt_rand(0, 2)],
                    'max_pct' => [10.0, 12.5, 25.0, 50.0, 100.0, null][mt_rand(0, 5)],
                    'max_amount' => [null, null, 0.5, 7.0, 1234.567][mt_rand(0, 4)],
                ];
                $base = mt_rand(0, 1) ? mt_rand(1, 3000) / 1000 : mt_rand(1, 20000000) / 1000;
                $other = mt_rand(0, 2) === 0 ? round($base * mt_rand(0, 90) / 100, 3) : 0.0;
                $balance = mt_rand(0, 100000);
                $requested = mt_rand(0, 1) ? null : mt_rand(0, 100000);
                $cases[] = [$rules, $balance, $base, $other, $requested, Loyalty::quote($rules, $balance, $base, $other, $requested)];
            }
            file_put_contents($path, "[\n".implode(",\n", array_map(
                fn ($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                $cases,
            ))."\n]\n");
        }

        $cases = json_decode(file_get_contents($path), true);
        $this->assertCount(400, $cases);
        foreach ($cases as $n => [$rules, $balance, $base, $other, $requested, $expect]) {
            $this->assertEquals($expect, Loyalty::quote($rules, $balance, (float) $base, (float) $other, $requested), 'الحالة '.$n);
        }
    }
}
