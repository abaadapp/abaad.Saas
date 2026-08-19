<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفاتيح الضريبة في الإعدادات — تفعل ما تقول.
 *
 * كان «تفعيل ضريبة القيمة المضافة» يُحفَظ ولا يقرؤه شيء: يُطفئه من لا ضريبة
 * عليه — ومن يبيع دون حدّ التسجيل في عُمان كذلك — فتبقى الضريبة تُضاف إلى
 * كل فاتورة، وتُقرّ في التقرير الضريبي، ويجبيها من زبائنه وهو غير مخوَّلٍ
 * بجبايتها.
 *
 * و«طريقة الاحتساب» كذلك: يختار «مشمولة في السعر» فتُضاف فوقه.
 */
class VatSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 500, 'active' => true,
        ]);

        $this->set('vat_rate', '5');
        $this->actingAs($this->cashier);
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key],
            ['value' => $value],
        );
    }

    /** ما تُخبَر به شاشة الكاشير فعلًا عن الضريبة */
    private function posScreenVat(): array
    {
        $branch = Branch::where('business_id', $this->business->id)->first();
        $this->activatePosDevice($this->business->id, $branch->id);
        session(['pos_cashier_id' => $this->cashier->id, 'current_branch' => $branch->id]);

        return $this->get(route('pos.index'))->assertOk()
            ->viewData('page')['props']['settings']['vat'];
    }

    private function sell(): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => 'صنف', 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return Order::latest('id')->first();
    }

    /* ------------------------ تفعيل الضريبة ------------------------ */

    public function test_by_default_vat_is_applied(): void
    {
        $order = $this->sell();

        $this->assertSame(5.0, (float) $order->tax);
        $this->assertSame(105.0, (float) $order->total);
    }

    public function test_switching_it_off_actually_stops_charging_it(): void
    {
        $this->set('vat_enabled', '0');

        $order = $this->sell();

        $this->assertSame(0.0, (float) $order->tax, 'الضريبة تُجبى والمفتاح مطفأ');
        $this->assertSame(100.0, (float) $order->total);
    }

    /**
     * والصنف ذو النسبة الخاصّة يتوقّف معه.
     *
     * نسبة المتجر لا يقرؤها هذا الصنف أصلًا، فلو اكتُفي بتصفيرها لبقي وحده
     * يُضرَّب بالضريبة في متجرٍ أطفأها كلّها.
     */
    public function test_a_product_with_its_own_rate_stops_too(): void
    {
        $this->product->update(['tax' => 10]);
        $this->set('vat_enabled', '0');

        $this->assertSame(0.0, (float) $this->sell()->tax);
    }

    /** والورقة لا تحمل رقمًا ضريبيًّا لمتجرٍ لا يجبي الضريبة */
    public function test_the_tax_number_disappears_from_the_paper_when_it_is_off(): void
    {
        $this->set('vat_number', 'OM1100234567');
        $this->set('vat_enabled', '0');

        $vat = Demo::vatSettings();

        $this->assertSame(0.0, $vat['rate']);
        $this->assertSame('', $vat['number']);
    }

    public function test_turning_it_back_on_restores_it(): void
    {
        $this->set('vat_enabled', '0');
        $this->sell();
        $this->set('vat_enabled', '1');

        $this->assertSame(5.0, (float) $this->sell()->tax);
    }

    /* ------------------------ طريقة الاحتساب ------------------------ */

    public function test_exclusive_adds_the_tax_on_top(): void
    {
        $this->set('tax_mode', 'exclusive');

        $order = $this->sell();

        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(5.0, (float) $order->tax);
        $this->assertSame(105.0, (float) $order->total);
    }

    /**
     * «مشمولة»: ما على الرفّ هو ما يدفعه الزبون.
     *
     * مئةٌ بنسبة ٥٪ ضريبتها ٤.٧٦٢ لا ٥ — وتُستخرَج من المئة لا تُضاف إليها،
     * فيبقى المستحقّ مئةً كما قُرئ على الشاشة.
     */
    public function test_inclusive_pulls_the_tax_out_of_the_shelf_price(): void
    {
        $this->set('tax_mode', 'inclusive');

        $order = $this->sell();

        $this->assertSame(4.762, round((float) $order->tax, 3));
        $this->assertSame(100.0, (float) $order->total, 'أُضيفت الضريبة فوق سعرٍ يشملها');
        $this->assertSame(95.238, round((float) $order->subtotal, 3));
    }

    /** والفاتورة تبقى متماسكة: المجموع − الخصم + الضريبة = المستحقّ */
    public function test_the_invoice_still_adds_up_when_inclusive(): void
    {
        $this->set('tax_mode', 'inclusive');

        $order = $this->sell();

        $this->assertSame(
            round((float) $order->subtotal - (float) $order->discount + (float) $order->tax, 3),
            round((float) $order->total, 3),
        );
    }

    /** والإطفاء يغلب طريقة الاحتساب */
    public function test_off_beats_inclusive(): void
    {
        $this->set('tax_mode', 'inclusive');
        $this->set('vat_enabled', '0');

        $order = $this->sell();

        $this->assertSame(0.0, (float) $order->tax);
        $this->assertSame(100.0, (float) $order->total);
    }

    /* ------------------------ ما يصل الشاشة ------------------------ */

    public function test_the_cashier_screen_is_told_the_real_settings(): void
    {
        $this->set('vat_rate', '10');
        $this->set('tax_mode', 'inclusive');

        $vat = $this->posScreenVat();

        $this->assertTrue($vat['enabled']);
        $this->assertSame(10.0, $vat['rate']);
        $this->assertTrue($vat['inclusive']);
    }

    public function test_the_screen_is_told_when_it_is_off(): void
    {
        $this->set('vat_enabled', '0');

        $vat = $this->posScreenVat();

        $this->assertFalse($vat['enabled']);
        $this->assertSame(0.0, $vat['rate']);
    }
}
