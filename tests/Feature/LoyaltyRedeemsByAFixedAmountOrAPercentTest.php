<?php

namespace Tests\Feature;

use App\Exceptions\ClosedPeriod;
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
use App\Support\BooksPeriod;
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

    /**
     * وفاتورةٌ صفريّةٌ بتاريخ فترةٍ مقفلة تُردّ كأختها بمبلغ — ولا تصرف نقطة.
     *
     * لا قيدَ لها، فلا يسألها الدفتر (`Ledger::post`). فتسأل هي (`post`).
     */
    public function test_a_whole_points_invoice_in_a_closed_period_is_refused_like_any_other(): void
    {
        $draft = $this->invoice(10000, $this->wholeInvoiceByPoints(), issue: false);
        $this->set(BooksPeriod::SETTING, '2026-11-30');

        try {
            CustomerInvoices::issue($draft, $this->owner->id);
            $this->fail('أُصدرت فاتورةٌ صفريّة في فترةٍ مقفلة');
        } catch (ClosedPeriod) {
        }
        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->number);
        $this->assertSame(20000, (int) $this->customer->fresh()->points);
        $this->assertSame(0, $this->redeems());

        Setting::where('business_id', $this->shop->id)->where('key', BooksPeriod::SETTING)->delete();
        $this->assertSame(CustomerInvoice::ISSUED, CustomerInvoices::issue($draft->fresh(), $this->owner->id)->status);
        $this->assertSame(10000, (int) $this->customer->fresh()->points);
    }

    /* ═══════════════════ مثالُ صاحب النشاط: مئةٌ ونقاطٌ بعشرين ═══════════════════ */

    /**
     * فاتورةٌ بمئةٍ ونقاطٌ بعشرين: الضريبةُ على الثمانين المقبوضة لا على المئة.
     * المستحقُّ ٨٤ (غير شاملة) و٨٠ (شاملة)، والقيدُ متوازن، ورابطُ Paymob يطلب
     * المستحقَّ نفسَه بالبيسة.
     */
    public function test_a_hundred_with_twenty_in_points_is_due_eighty_four_and_the_link_asks_exactly_that(): void
    {
        $this->vat();
        $this->customer->update(['points' => 5000]);
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 590001, 'shorten_url' => 'https://paymob.link/h', 'created_at' => '2026-11-11T10:00:00',
                'expires_at' => '2026-11-18T23:59:00', 'state' => 'created', 'order' => 717000001,
            ]),
        ]);
        $line = [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]];

        $invoice = $this->invoice(2000, $line);
        $this->assertEqualsWithDelta(20.0, (float) $invoice->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->subtotal, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $invoice->tax_total, 0.0001, 'خمسةٌ بالمئة من الثمانين لا من المئة');
        $this->assertEqualsWithDelta(84.0, (float) $invoice->total, 0.0001);
        $this->assertAddsUp($invoice);
        $this->assertBalancedEntry($invoice);
        $credits = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->sole()
            ->lines()->where('credit', '>', 0)->pluck('credit')->map(fn ($c) => round((float) $c, 3))->sort()->values()->all();
        $this->assertSame([4.0, 80.0], $credits, 'إيرادٌ بالثمانين وضريبةٌ بأربعة');
        $this->assertSame(3000, (int) $this->customer->fresh()->points);

        $link = PaymentLinks::create($this->shop->id, $this->customer->fresh(), $invoice, null, null, $this->owner->id);
        $this->assertEqualsWithDelta(84.0, (float) $link->amount, 0.0001);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->firstWhere('name', 'amount_cents')['contents'] === '84000');

        // والشاملة: المئةُ على الرفّ فيها ضريبتُها — ثمانون، ضريبتُها المستخرجة ٣٫٨١٠
        $this->set('tax_mode', 'inclusive');
        $inclusive = $this->invoice(2000, $line);
        $this->assertEqualsWithDelta(20.0, (float) $inclusive->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(3.81, (float) $inclusive->tax_total, 0.0001);
        $this->assertEqualsWithDelta(96.19, (float) $inclusive->subtotal, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $inclusive->total, 0.0001);
        $this->assertAddsUp($inclusive);
        $this->assertBalancedEntry($inclusive);
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
    }

    /**
     * وبالنسبة: بندٌ بخمسين وخصمُه خمسة، ومئةُ نقطةٍ بعشرةٍ بالمئة من المؤهّلة.
     * خمسُمئة نقطة = ٥٠٪ من ٤٥ = ٢٢٫٥٠٠ (تحت سقف الخمسين: ٢٥). الضريبةُ على ٢٢٫٥٠٠
     * = ١٫١٢٥، والمستحقّ ٢٣٫٦٢٥ — ورابطُ Paymob يطلب ٢٣٦٢٥ بيسةً بالضبط.
     */
    public function test_a_percent_invoice_with_a_line_discount_asks_paymob_for_exactly_its_total(): void
    {
        $this->vat();
        $this->percent();
        PaymentGateway::create([
            'business_id' => $this->shop->id, 'provider' => PaymentGateway::PAYMOB, 'active' => true,
            'public_key' => 'pk', 'secret_key' => 'sk', 'hmac_secret' => 'hmac-shop',
            'card_integration_id' => '111', 'api_key' => 'api', 'mode' => PaymentGateway::LIVE,
        ]);
        Http::fake([
            'oman.paymob.com/api/auth/tokens' => Http::response(['token' => 'tok']),
            'oman.paymob.com/api/ecommerce/payment-links' => Http::response([
                'id' => 590002, 'shorten_url' => 'https://paymob.link/p', 'created_at' => '2026-11-11T10:00:00',
                'expires_at' => '2026-11-18T23:59:00', 'state' => 'created', 'order' => 717000002,
            ]),
        ]);

        $invoice = $this->invoice(500, [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 50, 'discount' => 5]]);
        $this->assertEqualsWithDelta(22.5, (float) $invoice->loyalty_discount, 0.00001);
        $this->assertEqualsWithDelta(1.125, (float) $invoice->tax_total, 0.00001);
        $this->assertEqualsWithDelta(23.625, (float) $invoice->total, 0.00001);
        $this->assertAddsUp($invoice);
        $this->assertBalancedEntry($invoice);

        $link = PaymentLinks::create($this->shop->id, $this->customer->fresh(), $invoice, null, null, $this->owner->id);
        $this->assertEqualsWithDelta(23.625, (float) $link->amount, 0.00001);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/ecommerce/payment-links')
            && collect($r->data())->firstWhere('name', 'amount_cents')['contents'] === '23625');
    }

    /**
     * إصدارٌ فشل بعد أن صرف النقاط (يردّه الدفترُ لفترةٍ مقفلة) لا يُبقي صرفًا:
     * المعاملةُ كلُّها تُرجع. وإعادةُ المحاولة تصرف مرّةً، وتكرارُها لا يصرف ثانية.
     */
    public function test_an_issue_that_fails_spends_nothing_and_the_retry_spends_once(): void
    {
        $this->vat();
        $draft = $this->invoice(1000, [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]], issue: false);
        $this->set(BooksPeriod::SETTING, '2026-11-30');

        try {
            CustomerInvoices::issue($draft, $this->owner->id);
            $this->fail('أُصدرت في فترةٍ مقفلة');
        } catch (ClosedPeriod) {
        }
        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->number);
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'الصرفُ رجع مع المعاملة');
        $this->assertSame(0, $this->redeems());

        Setting::where('business_id', $this->shop->id)->where('key', BooksPeriod::SETTING)->delete();
        CustomerInvoices::issue($draft->fresh(), $this->owner->id);
        CustomerInvoices::issue($draft->fresh(), $this->owner->id);

        $this->assertSame(CustomerInvoice::ISSUED, $draft->fresh()->status);
        $this->assertSame(0, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertBalancedEntry($draft->fresh());
    }

    /**
     * يدٌ قرأت المسودّة لتلغيها، ويدٌ أخرى أصدرتها قبلها: الإلغاءُ يقرأ «صادرة»
     * تحت القفل فيعكس قيدَها ويعيد نقاطَها مرّةً — لا «ملغاةٌ» بقيدٍ قائم.
     */
    public function test_a_cancel_holding_a_stale_draft_reverses_what_an_issue_wrote_meanwhile(): void
    {
        $this->vat();
        $stale = $this->invoice(1000, [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100]], issue: false);
        CustomerInvoices::issue(CustomerInvoice::findOrFail($stale->id), $this->owner->id);
        $this->assertSame(CustomerInvoice::DRAFT, $stale->status, 'اليدُ الأولى ما زالت ترى مسودّة');
        $this->assertSame(0, (int) $this->customer->fresh()->points);

        CustomerInvoices::cancel($stale, 'طلب العميل', $this->owner->id);

        $this->assertSame(CustomerInvoice::CANCELLED, $stale->fresh()->status);
        $original = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $stale->id)
            ->whereNull('reverses_id')->sole();
        $this->assertSame(1, JournalEntry::where('reverses_id', $original->id)->count(), 'قيدُ الفاتورة الملغاة لم يُعكس');
        $this->assertSame(1000, (int) $this->customer->fresh()->points);
        $this->assertSame(1, PointTransaction::where('source_key', CustomerInvoices::redeemKey($stale).':reverse')->count());
    }

    /**
     * ═══ الصندوقُ والفاتورةُ يدوّران الضريبةَ بقاعدةٍ واحدة — سطرًا سطرًا (`VatLines`) ═══
     *
     * كانا يفترقان: الصندوقُ يجمع ضرائبَ البنود بكسورٍ عائمة ويدوّر المجموع،
     * والفاتورةُ تدوّر كلَّ بند. فالسلّتان أدناه كُتبتا ١٫٢٨٥ و١٫٢٨٦، ثمّ
     * ١٫١٦٨ و١٫١٦٧. والآن خصمُ المستند يُوزَّع بالبيسات، وضريبةُ كلّ بندٍ تُدوَّر
     * نصفًا إلى أعلى ثمّ تُجمع — قاعدةُ PINT-OM (IBR-168-OM وALIGNED-IBRP-S-09-OM).
     */
    public function test_the_till_and_the_invoice_round_vat_line_by_line_the_same_way(): void
    {
        $this->vat();
        $this->customer->update(['points' => 100000]);
        $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'فاتورة', 'phone' => '93000003', 'points' => 100000, 'language' => 'ar']);

        // (ب) بندٌ واحد: ٣٢٫٢٧٥ ونقاطٌ بـ٦٫٥٦٥ (النقطةُ بيسة) — الصافي ٢٥٫٧١ وضريبتُه ١٫٢٨٥٥ تمامًا ← ١٫٢٨٦
        $this->set('loyalty_redeem_points', '1');
        $this->set('loyalty_redeem_value', '0.001');
        $tie = Product::create(['business_id' => $this->shop->id, 'name' => 'نصف بيسة', 'price' => 32.275, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        // والشاشةُ الجديدة ترسل ما قرأته — ٣٢٫٢٧٥ − ٦٫٥٦٥ + ١٫٢٨٦
        $order = $this->sell(['redeem_points' => 6565, 'redeem_discount' => 6.565, 'expected_total' => 26.996], $tie);
        $invoice = $this->invoice(6565, [['description' => 'نصف بيسة', 'quantity' => 1, 'unit_price' => 32.275]], $onInvoice);

        $this->assertSame('line', $order->vat_rounding, 'البيعةُ الجديدة تحمل قاعدتَها');
        $this->assertEqualsWithDelta(6.565, (float) $order->discount, 0.00001);
        $this->assertEqualsWithDelta(1.286, (float) $order->tax, 0.00001, 'الصندوق: ١٫٢٨٥٥ نصفًا إلى أعلى');
        $this->assertEqualsWithDelta(1.286, (float) $invoice->tax_total, 0.00001, 'الفاتورة: كذلك');
        $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.00001);

        // (أ) بندان: ١٣٫٠٥٠ و١٤٫٣٠٠ ونقاطٌ بأربعة — الحصّتان ١٫٩٠٨ و٢٫٠٩٢، والضريبتان ٠٫٥٥٧ + ٠٫٦١٠
        $this->set('loyalty_redeem_points', '100');
        $this->set('loyalty_redeem_value', '1');
        $a = Product::create(['business_id' => $this->shop->id, 'name' => 'أ', 'price' => 13.05, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $b = Product::create(['business_id' => $this->shop->id, 'name' => 'ب', 'price' => 14.3, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $order = $this->sell([
            'items' => [['id' => $a->id, 'name' => 'أ', 'qty' => 1], ['id' => $b->id, 'name' => 'ب', 'qty' => 1]],
            'redeem_points' => 400, 'redeem_discount' => 4, 'expected_total' => 24.517,
        ]);
        $invoice = $this->invoice(400, [
            ['description' => 'أ', 'quantity' => 1, 'unit_price' => 13.05],
            ['description' => 'ب', 'quantity' => 1, 'unit_price' => 14.3],
        ], $onInvoice);

        $this->assertEqualsWithDelta((float) $order->discount, (float) $invoice->discount_total, 0.00001, 'الخصمُ واحد');
        $this->assertEqualsWithDelta(1.167, (float) $order->tax, 0.00001, 'الصندوق: بندًا بندًا');
        $this->assertEqualsWithDelta(1.167, (float) $invoice->tax_total, 0.00001, 'الفاتورة: ٠٫٥٥٧ + ٠٫٦١٠');
        $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.00001);
        $this->assertSame([1.908, 2.092], $invoice->items->sortBy('sort_order')->map(fn ($i) => round((float) $i->loyalty_discount, 3))->values()->all());
        $this->assertAddsUp($invoice);
    }

    /**
     * سلالٌ عشوائيّةٌ ببذرةٍ ثابتة — بنودٌ وكميّاتٌ وأسعارٌ بثلاث منازل، ومعفًى،
     * وشاملةٌ وغيرُ شاملة، وبنقاط: الصندوقُ والفاتورةُ بالضريبة والإجماليّ نفسِهما
     * بالبيسة، والإجماليُّ هو ما يُطلب من Paymob.
     */
    public function test_random_baskets_cost_the_same_on_the_till_and_on_the_invoice_to_the_baisa(): void
    {
        mt_srand(20261113);
        $this->set('loyalty_redeem_max_pct', '');
        $products = [];
        for ($k = 0; $k < 6; $k++) {
            $products[] = Product::create([
                'business_id' => $this->shop->id, 'name' => 'صنف '.$k, 'price' => mt_rand(1, 40000) / 1000,
                'cost' => 0, 'quantity' => 100000, 'alert_qty' => 1, 'active' => true,
                'tax' => $k === 5 ? 0 : null,
            ]);
        }

        for ($n = 0; $n < 24; $n++) {
            $this->vat($n % 2 === 1);
            $pick = array_slice($products, mt_rand(0, 3), mt_rand(2, 3));
            $items = [];
            $lines = [];
            foreach ($pick as $p) {
                $qty = mt_rand(1, 4);
                $items[] = ['id' => $p->id, 'name' => $p->name, 'qty' => $qty];
                $lines[] = ['description' => $p->name, 'quantity' => $qty, 'unit_price' => (float) $p->price]
                    + ($p->tax !== null ? ['tax_rate' => 0] : []);
            }
            $base = round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines)), 3);
            $atTill = Customer::create(['business_id' => $this->shop->id, 'name' => 'ص'.$n, 'phone' => (string) (94000000 + $n), 'points' => 100000, 'language' => 'ar']);
            $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'ف'.$n, 'phone' => (string) (95000000 + $n), 'points' => 100000, 'language' => 'ar']);
            $offer = Loyalty::quote(Loyalty::rules($this->shop->id), mt_rand(100, (int) min(100000, floor($base * 100))), $base);
            $label = "سلّة $n";

            // الفاتورةُ أوّلًا، ومبلغُها ما تقرؤه الشاشةُ الجديدة — فإن افترق الصندوقُ عنه ردّ البيعة
            $invoice = $this->invoice($offer['max_points'], $lines, $onInvoice);
            $this->customer = $atTill;
            $order = $this->sell(['items' => $items, 'redeem_points' => $offer['max_points'], 'redeem_discount' => $offer['max_discount'], 'expected_total' => (float) $invoice->total]);

            $this->assertEqualsWithDelta((float) $order->discount, (float) $invoice->discount_total, 0.00001, $label);
            $this->assertEqualsWithDelta((float) $order->tax, (float) $invoice->tax_total, 0.00001, $label.': الضريبة');
            $this->assertEqualsWithDelta((float) $order->total, (float) $invoice->total, 0.00001, $label.': الإجماليّ');
            $this->assertAddsUp($invoice);
            foreach ($invoice->items as $item) {
                $exact = ((float) $item->line_total - ($n % 2 === 1 ? 0 : (float) $item->tax_amount)) * (float) $item->tax_rate / ($n % 2 === 1 ? 100 + (float) $item->tax_rate : 100);
                $this->assertLessThanOrEqual(0.0005 + 1e-9, abs((float) $item->tax_amount - $exact), $label.': ضريبةُ السطر خارج نصف بيسة (IBR-168-OM)');
            }
            $this->assertSame((string) (int) round((float) $invoice->total * 1000), (string) \App\Support\Paymob\Amount::toMinor((float) $invoice->total, 'OMR'), $label.': مبلغ Paymob');
        }
    }

    /**
     * سقفٌ بمئةٍ بالمئة كالفارغ: النقاطُ تغطّي الفاتورةَ كلَّها ولا تتجاوزها.
     * وفاتورةٌ ببنودٍ وكسور بيسات (كميّاتٌ عشريّة وأسعارٌ بثلاث خانات ومعفى):
     * حصصُ النقاط تجمع إلى خصمها بالبيسة، والورقةُ تجمع إلى إجماليّها.
     */
    public function test_a_hundred_percent_cap_and_a_fractional_multi_line_invoice_add_up_to_the_baisa(): void
    {
        $this->vat();
        $this->set('loyalty_redeem_max_pct', '100');
        $this->customer->update(['points' => 50000]);
        $lines = [
            ['description' => 'ورد بالوزن', 'quantity' => 1.5, 'unit_price' => 3.333],
            ['description' => 'بطاقة', 'quantity' => 3, 'unit_price' => 0.999],
            ['description' => 'توصيل معفى', 'quantity' => 1, 'unit_price' => 0.333, 'tax_rate' => 0],
        ];

        // المجموع ٥٫٠٠٠ + ٢٫٩٩٧ + ٠٫٣٣٣ = ٨٫٣٣٠ — سقفُ المئة = الفاتورة كلُّها
        $offer = $this->postJson(route('admin.customerInvoices.preview'), ['customer_id' => $this->customer->id, 'items' => $lines])
            ->assertOk()->json('loyalty');
        $this->assertSame(833, $offer['points']);
        $this->assertEqualsWithDelta(8.33, $offer['discount'], 0.00001);

        $whole = $this->invoice(833, $lines);
        $this->assertEqualsWithDelta(0.0, (float) $whole->total, 0.00001);
        $this->assertEqualsWithDelta(0.0, (float) $whole->tax_total, 0.00001);
        $this->assertAddsUp($whole);

        // وجزءٌ منها بنقاطٍ بـ٣٫٣٣٣: الحصصُ بالتناسب بلا كسرٍ عائم، والضريبةُ على ما بقي من الخاضع
        $part = $this->invoice(333, $lines);
        $this->assertEqualsWithDelta(3.33, (float) $part->loyalty_discount, 0.00001);
        $this->assertAddsUp($part);
        $this->assertBalancedEntry($part);
        $this->assertEqualsWithDelta(0.0, (float) $part->items->firstWhere('description', 'توصيل معفى')->tax_amount, 0.00001);
        $this->assertSame(50000 - 833 - 333, (int) $this->customer->fresh()->points);
    }

    /** حركاتُ «ردّ نقاط بنودٍ أُرجعت» للبيعة — ومجموعُها */
    private function pointsBack(Order $order): int
    {
        return (int) PointTransaction::where('order_id', $order->id)->where('source_key', 'like', 'till-return:%')->sum('points');
    }

    /**
     * ═══ الإرجاعُ الجزئيّ في الصندوق يعيد نقاطَ ما أُرجع — بقرار المالك ═══
     *
     * النصيبُ من لقطة البيع (`TillRedemption`): الخصمُ الباقي ⌊L × G₁ ÷ G₀⌋،
     * والنقاطُ الباقية ⌈P × الخصم الباقي ÷ L⌉. فما يعود نقاطًا ونقدًا ثمنُ ما
     * أُرجع بالضبط، لا أكثر.
     */
    public function test_a_partial_till_return_gives_back_the_points_of_what_was_returned(): void
    {
        $this->customer->update(['points' => 5000]);

        // قطعتان بمئة ونقاطٌ بعشرين: ١٨٠ — ثمّ تُعاد قطعة
        $order = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 20], null, 2);
        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.0001);
        $this->assertSame(['points' => 2000, 'discount_baisa' => 20000, 'gross_baisa' => 200000], $order->loyalty_basis);
        [$before, $earned] = [(int) $this->customer->fresh()->points, (int) $order->points_earned];

        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 1, 'أعاد قطعة');
        $order = $order->fresh();
        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001, 'نصفُ الخصم لنصف البنود');
        $this->assertEqualsWithDelta(90.0, (float) $order->total, 0.0001, 'فيُستردّ ٩٠ نقدًا');
        $this->assertSame(1000, (int) $order->redeemed_points);
        $this->assertSame(1000, $this->pointsBack($order), 'وتعود ١٠٠٠ نقطة — ٩٠ + ١٠ = ثمنُ القطعة');
        $this->assertSame($before + 1000 + (int) $order->points_earned - $earned, (int) $this->customer->fresh()->points);

        // عشرُ قطعٍ بعشرة ونقاطٌ بعشرين، ثمّ تبقى واحدة: الخصمُ ٢ والنقاطُ الباقية ٢٠٠
        $cheap = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $order = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 20], $cheap, 10);
        $this->assertEqualsWithDelta(80.0, (float) $order->total, 0.0001);

        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 1, 'أعاد تسعًا');
        $order = $order->fresh();
        $this->assertEqualsWithDelta(2.0, (float) $order->discount, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) $order->total, 0.0001, 'فيُستردّ ٧٢ نقدًا');
        $this->assertSame(200, (int) $order->redeemed_points);
        $this->assertSame(1800, $this->pointsBack($order), '٧٢ + ١٨ = ٩٠ ثمنُ التسع — لا تضيع على الزبون قيمة');
    }

    /** على دفعاتٍ أو مرّةً: النقاطُ نفسُها. والإلغاءُ بعدها يعيد الباقي وحدَه، والإضافةُ لا تصرف ما رُدّ */
    public function test_points_come_back_the_same_in_steps_a_cancel_returns_only_the_rest_and_an_added_item_spends_none(): void
    {
        $this->customer->update(['points' => 5000]);
        $cheap = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);

        $order = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 20], $cheap, 10);
        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 5, 'أعاد خمسًا');
        $this->assertSame(1000, $this->pointsBack($order));
        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 1, 'أعاد أربعًا');
        $order = $order->fresh();
        $this->assertSame(1800, $this->pointsBack($order), 'كما لو أُعيدت التسعُ مرّةً');
        $this->assertSame(200, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(8.0, (float) $order->total, 0.0001);

        // ورجع الزبونُ بقطعة: لا تُصرف نقطةٌ رُدّت — يدفع ثمنَها نقدًا
        $points = (int) $this->customer->fresh()->points;
        $earned = (int) $order->points_earned;
        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 2, 'أخذ قطعة');
        $order = $order->fresh();
        $this->assertSame(200, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(2.0, (float) $order->discount, 0.0001, 'الخصمُ لا يتجاوز ما تشتريه النقاطُ الباقية');
        $this->assertEqualsWithDelta(18.0, (float) $order->total, 0.0001);
        $this->assertSame($points + (int) $order->points_earned - $earned, (int) $this->customer->fresh()->points, 'لا يُسحب من رصيده شيء');

        // والإلغاءُ يعيد ما بقي مصروفًا (٢٠٠) لا الألفين
        OrderCorrection::cancel($order->fresh(), 'ألغى');
        $back = (int) PointTransaction::where('order_id', $order->id)->where('type', 'earn')->where('note', 'like', 'ردّ نقاط فاتورة ملغاة%')->sum('points');
        $this->assertSame(200, $back);
        $this->assertSame(2000, $this->pointsBack($order) + $back, 'المصروفُ كلُّه عاد مرّةً');
    }

    /**
     * ومع كوبونٍ ثابتٍ وضريبةٍ مضافةٍ أو شاملةٍ وبندٍ معفًى، وبالطريقتين.
     *
     * الكوبونُ يبقى كاملًا على الباقي كما كان (سياسةُ الكوبون لم تتغيّر)، فلا
     * يُقاس المردودُ بثمن الرفّ وحده. والذي يُقاس: النقاطُ محفوظةُ العدد،
     * والخصمُ الباقي لا يتجاوز ما تشتريه النقاطُ الباقية ولا ينقص عنه بأكثر من نقطة.
     */
    public function test_a_return_never_gives_back_more_than_the_returned_goods_cost(): void
    {
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'OFF7', 'type' => 'مبلغ', 'value' => 7, 'max_uses' => 1000, 'used_count' => 0, 'active' => true]);
        $this->set('loyalty_redeem_max_pct', '');
        $this->customer->update(['points' => 100000]);
        $a = Product::create(['business_id' => $this->shop->id, 'name' => 'أ', 'price' => 13.333, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $b = Product::create(['business_id' => $this->shop->id, 'name' => 'ب', 'price' => 4.999, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'tax' => 0]);

        foreach ([[false, 'fixed'], [true, 'fixed'], [false, 'percent'], [true, 'percent']] as [$inclusive, $mode]) {
            $this->vat($inclusive);
            $mode === 'percent' ? $this->percent('9', '100') : $this->set('loyalty_redeem_mode', 'fixed');
            $label = ($inclusive ? 'شاملة' : 'مضافة').' / '.$mode;
            $offer = Loyalty::quote(Loyalty::rules($this->shop->id), 4321, round(3 * 13.333 + 2 * 4.999, 3), 7.0);
            $order = $this->sell([
                'items' => [['id' => $a->id, 'name' => 'أ', 'qty' => 3], ['id' => $b->id, 'name' => 'ب', 'qty' => 2]],
                'coupon_code' => 'OFF7', 'redeem_points' => $offer['max_points'], 'redeem_discount' => $offer['max_discount'],
            ]);
            $spent = (int) $order->redeemed_points;
            $this->assertGreaterThan(0, $spent, $label);
            $perPoint = $order->loyaltyDiscount() / $spent;

            foreach ([[$a, 1], [$b, 1], [$a, 0]] as [$product, $keep]) {
                $before = $order->fresh();
                $item = $before->items()->where('product_id', $product->id)->firstOrFail();

                OrderCorrection::setQuantity($before, $item, $keep, 'إرجاع');
                $after = $order->fresh();

                $this->assertGreaterThanOrEqual(0.0, round((float) $before->total - (float) $after->total, 3), $label.': الإرجاعُ لا يطلب من الزبون مالًا');
                $this->assertSame($spent, (int) $after->redeemed_points + $this->pointsBack($order), $label.': النقاطُ لا تُخلق ولا تضيع');
                // الخصمُ الباقي لا يتجاوز ما تشتريه النقاطُ الباقية — ولا ينقص عنه بأكثر من نقطة
                $keptValue = (int) $after->redeemed_points * $perPoint;
                $this->assertLessThanOrEqual($keptValue + 0.0005, $after->loyaltyDiscount(), $label.': خصمٌ بلا نقاطٍ تقابله');
                $this->assertLessThan($perPoint + 0.0015, $keptValue - $after->loyaltyDiscount(), $label.': ضاع على الزبون أكثرُ من نقطة');
                $this->assertGreaterThanOrEqual(0.0, (float) $after->total, $label);
                $this->assertEqualsWithDelta(
                    round((float) $after->subtotal - (float) $after->discount + (float) $after->tax, 3),
                    (float) $after->total, 0.00001, $label.': الفاتورةُ تجمع إلى إجماليّها',
                );
            }
        }
    }

    /** والإرجاعُ لا يكسر سقفَ المالك على ما بقي: الخصمُ يصغر بنسبة البنود، فنسبتُه ومبلغُه لا يكبران */
    public function test_a_return_keeps_the_owners_caps_on_what_is_left(): void
    {
        $this->customer->update(['points' => 100000]);
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 3.337, 'cost' => 0, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true]);

        foreach ([['25', ''], ['', '3.3'], ['33', '2']] as [$pct, $amount]) {
            $this->set('loyalty_redeem_max_pct', $pct);
            $this->set('loyalty_redeem_max_amount', $amount);
            $offer = Loyalty::quote(Loyalty::rules($this->shop->id), 100000, round(9 * 3.337, 3));
            $order = $this->sell(['redeem_points' => $offer['max_points'], 'redeem_discount' => $offer['max_discount']], $rose, 9);

            foreach ([7, 4, 2, 1] as $keep) {
                OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), $keep, 'إرجاع');
                $after = $order->fresh();
                $gross = round($keep * 3.337, 3);
                $label = "سقف [$pct|$amount] — بقي $keep";
                if ($pct !== '') {
                    $this->assertLessThanOrEqual(floor($gross * (float) $pct * 10) / 1000 + 1e-9, $after->loyaltyDiscount(), $label.': فوق السقف بالنسبة');
                }
                if ($amount !== '') {
                    $this->assertLessThanOrEqual((float) $amount + 1e-9, $after->loyaltyDiscount(), $label.': فوق السقف بالمبلغ');
                }
            }
        }
    }

    /** بيعةٌ قديمةٌ بلا لقطة: تُقرأ لقطتُها من صفّها ما دامت بنودُها كما بيعت — وإلّا يُرفض ولا يُخمَّن */
    public function test_an_old_sale_reads_its_points_from_its_row_unless_its_lines_were_already_edited(): void
    {
        $this->customer->update(['points' => 5000]);
        $order = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 20], null, 2);
        Order::whereKey($order->id)->update(['loyalty_basis' => null, 'vat_rounding' => null]);

        OrderCorrection::setQuantity($order->fresh(), $order->items()->firstOrFail(), 1, 'أعاد قطعة');
        $order = $order->fresh();
        $this->assertSame(['points' => 2000, 'discount_baisa' => 20000, 'gross_baisa' => 200000], $order->loyalty_basis, 'كُتبت لقطتُها قبل التعديل');
        $this->assertSame(1000, $this->pointsBack($order));
        $this->assertNull($order->vat_rounding, 'وتبقى على قاعدة ضريبتها القديمة');

        // وبيعةٌ عُدّلت بنودُها قبل التحديث: صفُّها كما كتبه الكودُ القديم — الخصمُ كاملًا والنقاطُ كلُّها
        $cheap = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $old = $this->sell(['redeem_points' => 2000, 'redeem_discount' => 20], $cheap, 10);
        OrderCorrection::setQuantity($old->fresh(), $old->items()->firstOrFail(), 8, 'قبل التحديث');
        PointTransaction::where('order_id', $old->id)->where('source_key', 'like', 'till-return:%')->delete();
        Order::whereKey($old->id)->update(['loyalty_basis' => null, 'redeemed_points' => 2000, 'discount' => 20, 'total' => 60]);
        $this->customer->refresh()->update(['points' => 5000]);

        try {
            OrderCorrection::setQuantity($old->fresh(), $old->items()->firstOrFail(), 1, 'أعاد سبعًا');
            $this->fail('عُدّلت بنودٌ بلا لقطة');
        } catch (\RuntimeException $e) {
            $this->assertSame(\App\Support\TillRedemption::refusal(), $e->getMessage());
        }
        $this->assertSame(8, (int) $old->items()->firstOrFail()->quantity, 'لم يتغيّر بند');
        $this->assertSame(5000, (int) $this->customer->fresh()->points, 'ولم تتحرّك نقطة');
        $this->assertEqualsWithDelta(60.0, (float) $old->fresh()->total, 0.0001);

        // ويبقى الإلغاءُ الكامل: تعود النقاطُ المصروفةُ كلُّها، ويُسحب ما اكتُسب
        $earned = (int) $old->fresh()->points_earned;
        OrderCorrection::cancel($old->fresh(), 'ألغى');
        $this->assertSame(5000 + 2000 - $earned, (int) $this->customer->fresh()->points);
    }

    /**
     * بيعةٌ قبل التحديث كُتبت ضريبتُها بقاعدتها القديمة (المجموعُ يُدوَّر مرّةً):
     * تُصحَّح بقاعدتها ولا تُرفض بحجّة أنّ الإعداد تغيّر، ولا تُنقل إلى الجديدة.
     *
     * والسلّةُ مختارةٌ تفترق فيها القاعدتان: ٣٫٥ × ٢ و١٫٢٥ ونقاطٌ باثنين —
     * سطرًا سطرًا ٠٫٢٦٥ + ٠٫٠٤٧ = ٠٫٣١٢، ومجموعًا ٠٫٣١٢٥ ← ٠٫٣١٣.
     */
    public function test_an_old_sale_is_corrected_by_its_own_vat_rule(): void
    {
        $this->vat();
        $this->customer->update(['points' => 5000]);
        $a = Product::create(['business_id' => $this->shop->id, 'name' => 'أ', 'price' => 3.5, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $b = Product::create(['business_id' => $this->shop->id, 'name' => 'ب', 'price' => 1.25, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $order = $this->sell([
            'items' => [['id' => $a->id, 'name' => 'أ', 'qty' => 2], ['id' => $b->id, 'name' => 'ب', 'qty' => 1]],
            'redeem_points' => 200, 'redeem_discount' => 2, 'expected_total' => 6.562,
        ]);
        $this->assertEqualsWithDelta(0.312, (float) $order->tax, 0.00001, 'الجديدةُ سطرًا سطرًا');
        $this->assertEqualsWithDelta(6.562, (float) $order->total, 0.00001);

        // كما كتبها الكودُ القديم
        Order::whereKey($order->id)->update(['vat_rounding' => null, 'tax' => 0.313, 'total' => 6.563]);
        OrderCorrection::setQuantity($order->fresh(), $order->items()->where('product_id', $a->id)->firstOrFail(), 1, 'أعاد واحدة');

        $order = $order->fresh();
        $this->assertNull($order->vat_rounding, 'لا تُنقل إلى القاعدة الجديدة');
        // الباقي ٤٫٧٥ وخصمُه ⌊٢ × ٤٫٧٥ ÷ ٨٫٢٥⌋ = ١٫١٥١، وضريبتُه (٤٫٧٥ − ١٫١٥١) × ٥٪ = ٠٫١٨٠
        $this->assertEqualsWithDelta(1.151, (float) $order->discount, 0.00001);
        $this->assertEqualsWithDelta(0.18, (float) $order->tax, 0.00001);
        $this->assertEqualsWithDelta(3.779, (float) $order->total, 0.00001);
        $this->assertSame(116, (int) $order->redeemed_points, '⌈٢٠٠ × ١٫١٥١ ÷ ٢⌉');
        $this->assertSame(84, $this->pointsBack($order));
    }

    /* ═══════════════════ الشاشةُ والخادمُ: المبلغُ الذي يُقبض هو المبلغُ الذي يُكتب ═══════════════════ */

    /** سعرٌ تغيّر منذ فُتحت الشاشة: تقف البيعةُ بلا كتابة، ثمّ تمضي حين يؤكّد الكاشيرُ المبلغَ الصحيح — مرّةً واحدة */
    public function test_a_screen_total_that_changed_stops_the_sale_until_the_cashier_confirms_it(): void
    {
        $coupon = Coupon::create(['business_id' => $this->shop->id, 'code' => 'OFF5', 'type' => 'مبلغ', 'value' => 5, 'max_uses' => 10, 'used_count' => 0, 'active' => true]);
        $this->customer->update(['points' => 1000]);
        $uuid = uniqid('screen', true);
        $sale = ['client_uuid' => $uuid, 'coupon_code' => 'OFF5', 'redeem_points' => 500, 'redeem_discount' => 5];

        // الشاشةُ فُتحت والباقةُ بتسعين فقرأت ٨٠، ثمّ صارت مئة فالصحيحُ ٩٠
        $refused = $this->till($sale + ['expected_total' => 80.0])->assertStatus(422);
        $this->assertEqualsWithDelta(90.0, $refused->json('totals.total'), 0.0001);
        $this->assertEqualsWithDelta(80.0, $refused->json('totals.shown'), 0.0001);
        $this->assertFalse($refused->json('totals.stale_screen'));
        $this->assertStringContainsString('اضغط الدفع مرّةً أخرى', $refused->json('message'));
        $this->assertSame(0, Order::where('client_uuid', $uuid)->count(), 'لم يُكتب طلب');
        $this->assertSame(1000, (int) $this->customer->fresh()->points, 'ولم تُصرف نقطة');
        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'ولا فرصةُ كوبون');

        // يؤكّد الكاشيرُ التسعين فتمضي — وإعادتُها لا تكتب ثانية
        $this->till($sale + ['expected_total' => 90.0])->assertOk();
        $this->till($sale + ['expected_total' => 90.0])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, Order::where('client_uuid', $uuid)->count());
        $this->assertSame(1, $this->redeems(), 'النقاطُ صُرفت مرّةً');
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
    }

    /** وشاشةٌ فُتحت قبل التحديث لا ترسل ما قرأته: تقف حين كان مبلغُها سيفترق وحده، وتمضي في غيره */
    public function test_an_old_screen_is_stopped_only_when_its_total_would_differ(): void
    {
        $this->vat();
        Coupon::create(['business_id' => $this->shop->id, 'code' => 'TWO', 'type' => 'مبلغ', 'value' => 2, 'min_order' => 5, 'max_uses' => 10, 'used_count' => 0, 'active' => true]);
        $a = Product::create(['business_id' => $this->shop->id, 'name' => 'أ', 'price' => 3.5, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $b = Product::create(['business_id' => $this->shop->id, 'name' => 'ب', 'price' => 1.25, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);
        $basket = ['items' => [['id' => $a->id, 'name' => 'أ', 'qty' => 2], ['id' => $b->id, 'name' => 'ب', 'qty' => 1]], 'coupon_code' => 'TWO'];

        // القديمةُ كانت تقرأ ٦٫٥٦٣ (٠٫٣١٢٥ مجموعًا) والصحيحُ ٦٫٥٦٢ (٠٫٢٦٥ + ٠٫٠٤٧)
        $stale = $this->till($basket)->assertStatus(422);
        $this->assertTrue($stale->json('totals.stale_screen'));
        $this->assertStringContainsString('أعد تحميل الصفحة', $stale->json('message'));
        $this->assertSame(0, Order::count());

        $this->till($basket + ['expected_total' => 6.562])->assertOk();
        // وسلّةٌ لا يفترق فيها الحسابان تمضي من الشاشة القديمة كما كانت
        $this->till(['items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]]])->assertOk();
        $this->assertSame(2, Order::count());
    }

    /** القواعدُ كما تحملها شاشةُ الصندوق — `loyaltyRules` في `usePosCart` */
    private function screenRules(array $override = []): array
    {
        $r = Loyalty::rules($this->shop->id);
        unset($r['earn_rate']);

        return array_merge($r, $override);
    }

    /** بيعةٌ اكتملت بلا اتصال: تصل من الطابور بما قُبض */
    private function replay(float $price, int $points, float $discount, array $extra = []): Order
    {
        $p = Product::create(['business_id' => $this->shop->id, 'name' => 'ورد '.uniqid(), 'price' => $price, 'cost' => 0, 'quantity' => 100, 'alert_qty' => 1, 'active' => true]);

        return $this->sell(array_merge([
            'redeem_points' => $points, 'redeem_discount' => $discount, 'replay' => true,
            'expected_total' => round($price - $discount, 3), 'queued_at' => now()->subMinutes(30)->getTimestampMs(),
        ], $extra), $p);
    }

    /**
     * ═══ بيعةٌ بلا اتصال فوق سقف اليوم: يبقى ما قُبض، ولا دين، وتُكتب مخالفةً بسببها ═══
     *
     * الشاشةُ حسبت بقواعد سقفُها ٧٠٪ وصاحبُ النشاط جعله ٥٠٪ قبل أن تصل.
     */
    public function test_an_offline_sale_over_todays_cap_keeps_what_was_paid_and_is_flagged_with_its_reason(): void
    {
        $this->customer->update(['points' => 5000]);
        $order = $this->replay(20, 1200, 12, ['loyalty_rules' => $this->screenRules(['max_pct' => 70.0])]);

        $this->assertEqualsWithDelta(8.0, (float) $order->total, 0.0001, 'يُكتب ما قُبض');
        $this->assertSame('مدفوع', $order->payment_status, 'ولا دينَ على العميل');
        $this->assertSame(1200, (int) $order->redeemed_points);
        $r = $order->loyalty_review;
        $this->assertSame(['over_cap'], $r['reasons']);
        $this->assertSame('rules_changed', $r['context']);
        $this->assertEqualsWithDelta(10.0, $r['cap'], 0.0001);

        $bell = collect(Demo::notifications())->firstWhere('key', 'loyalty-review-'.$order->id);
        $this->assertSame('warning', $bell['color']);
        $this->assertStringContainsString('مخالفةٌ ماليّة', $bell['text']);
        $this->assertStringContainsString('فوق سقف الاستبدال', $bell['hint']);
        $this->assertStringContainsString('تغيّرت قبل أن تصل', $bell['hint']);
        $this->assertStringContainsString('لم يُسجَّل دينٌ', $bell['hint']);
    }

    /** وأرقامٌ لا تتّبع حتى القواعدَ التي قالت الشاشةُ إنّها حسبت بها: مشتبهٌ بها، لا تُعتمد قبل التحقّق */
    public function test_an_offline_sale_whose_numbers_follow_no_rules_is_held_as_suspected(): void
    {
        $this->customer->update(['points' => 5000]);
        // ١٢٠٠ نقطة بقواعد اليوم نفسِها تساوي ١٠ (السقف) — والشاشةُ تقول ١٥
        $order = $this->replay(20, 1200, 15, ['loyalty_rules' => $this->screenRules()]);

        $this->assertEqualsWithDelta(5.0, (float) $order->total, 0.0001, 'يُكتب ما قُبض — ولا يُطلب الفرقُ من العميل');
        $this->assertSame('مدفوع', $order->payment_status);
        $this->assertSame('suspected', $order->loyalty_review['context']);
        $this->assertContains('over_cap', $order->loyalty_review['reasons']);

        $bell = collect(Demo::notifications())->firstWhere('key', 'loyalty-review-'.$order->id);
        $this->assertSame('danger', $bell['color']);
        $this->assertStringContainsString('لا تُعتمد قبل التحقّق', $bell['text']);
        $this->assertStringContainsString('تحقّق من البيعة مع الكاشير', $bell['hint']);
    }

    /** وطابورُ شاشةٍ أقدم لا يحمل قواعدَه: لا يُعرف السبب — وفرقُ المقبوض يُكتب كذلك */
    public function test_an_offline_sale_without_its_rules_or_with_another_amount_is_flagged_unverified(): void
    {
        $this->customer->update(['points' => 5000]);
        $order = $this->replay(20, 1200, 12);
        $this->assertSame('unverified', $order->loyalty_review['context']);

        // ما قُبض ٧٫٥ والفاتورةُ ٨ — والبيعةُ بلا نقاط
        $order = $this->replay(8, 0, 0, ['expected_total' => 7.5, 'redeem_points' => 0, 'redeem_discount' => null]);
        $this->assertSame(['amount_mismatch'], $order->loyalty_review['reasons']);
        $this->assertEqualsWithDelta(7.5, $order->loyalty_review['collected'], 0.0001);
        $this->assertEqualsWithDelta(8.0, $order->loyalty_review['recorded'], 0.0001);
        $this->assertSame('مدفوع', $order->payment_status, 'ولا دين');

        // وبيعةٌ اتّبعت قواعدَ اليوم لا تُكتب مخالفة
        $clean = $this->replay(20, 1000, 10, ['loyalty_rules' => $this->screenRules()]);
        $this->assertNull($clean->loyalty_review);
    }

    /** الكاشيرُ يستبدل في الصندوق بقواعد صاحب النشاط، ولا يغيّر القواعد — بلا قسم التسويق تُردّ */
    public function test_a_cashier_redeems_at_the_till_but_cannot_change_the_rules(): void
    {
        $cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@percent.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['pos'],
        ]);

        $this->actingAs($cashier)->post(route('admin.marketing.loyalty.save'), [
            'loyalty_enabled' => true, 'loyalty_earn_rate' => 5, 'loyalty_redeem_enabled' => true,
            'loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => 100, 'loyalty_redeem_max_pct' => '',
        ])->assertForbidden();
        $this->assertSame(0, Setting::where('business_id', $this->shop->id)->where('key', 'like', 'loyalty%')->count(), 'لم يُكتب شيء');

        $order = $this->sell(['redeem_points' => 1000, 'redeem_discount' => 10]);
        $this->assertSame(1000, (int) $order->redeemed_points);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount, 0.0001);
        $this->assertSame(1, $this->redeems());
    }

    /** بيعةُ صندوقٍ أُعيد إرسالُها بعد انقطاع الاتّصال (المعرّفُ نفسُه) تصرف نقاطَها مرّةً */
    public function test_a_till_sale_sent_twice_after_a_dropped_connection_spends_its_points_once(): void
    {
        $this->percent();
        $payload = ['client_uuid' => 'dropped-connection-1', 'redeem_points' => 100, 'redeem_discount' => 10];

        $first = $this->till($payload)->assertOk();
        $again = $this->till($payload)->assertOk();

        $this->assertSame($first->json('invoice'), $again->json('invoice'));
        $this->assertTrue($again->json('duplicate'));
        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertSame(100, (int) $order->redeemed_points);
        // صُرفت مئةٌ مرّةً، واكتُسب على التسعين مرّةً
        $this->assertSame(1000 - 100 + (int) $order->points_earned, (int) $this->customer->fresh()->points);
        $this->assertSame(1, $this->redeems());
        $this->assertSame(1, PointTransaction::where('customer_id', $this->customer->id)->where('type', 'earn')->count());
    }

    /**
     * صنفٌ معفًى يأخذ نصيبَه من خصم النقاط ولا ضريبةَ عليه — في الصندوق والفاتورة.
     *
     * مئةٌ بخمسةٍ بالمئة وخمسون معفاة، ونقاطٌ بثلاثين: نصيبُ المعفى عشرة،
     * والخاضعُ ثمانون ضريبتُه أربعة، والإجماليُّ ١٢٤ في الموضعين.
     */
    public function test_an_exempt_item_takes_its_share_of_the_points_on_the_till_and_the_invoice(): void
    {
        $this->vat();
        $this->customer->update(['points' => 5000]);
        $onInvoice = Customer::create(['business_id' => $this->shop->id, 'name' => 'فاتورة', 'phone' => '93000002', 'points' => 5000, 'language' => 'ar']);
        $exempt = Product::create([
            'business_id' => $this->shop->id, 'name' => 'خدمة معفاة', 'price' => 50, 'cost' => 0,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'tax' => 0,
        ]);

        $order = $this->sell([
            'items' => [
                ['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1],
                ['id' => $exempt->id, 'name' => $exempt->name, 'qty' => 1],
            ],
            'redeem_points' => 3000, 'redeem_discount' => 30,
        ]);
        $invoice = $this->invoice(3000, [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 100],
            ['description' => 'خدمة معفاة', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0],
        ], $onInvoice);

        $this->assertEqualsWithDelta(4.0, (float) $order->tax, 0.0001);
        $this->assertEqualsWithDelta(124.0, (float) $order->total, 0.0001);
        foreach ([[(float) $order->discount, (float) $invoice->discount_total, 'الخصم'], [(float) $order->tax, (float) $invoice->tax_total, 'الضريبة'], [(float) $order->total, (float) $invoice->total, 'الإجماليّ']] as [$till, $paper, $what]) {
            $this->assertEqualsWithDelta($till, $paper, 0.00001, $what);
        }
        $this->assertEqualsWithDelta(10.0, (float) $invoice->items->firstWhere('description', 'خدمة معفاة')->loyalty_discount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->items->firstWhere('description', 'خدمة معفاة')->tax_amount, 0.0001);
        $this->assertAddsUp($invoice);
        $this->assertBalancedEntry($invoice);
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
