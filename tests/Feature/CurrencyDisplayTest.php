<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عملة العرض مقابل عملة القيد.
 *
 * تبديل العملة يغيّر ما يراه التاجر على الشاشة فقط. القيد في القاعدة
 * والفاتورة الضريبية يبقيان بالعملة الأساسية — وإلا صارت فاتورة بـ34.125
 * وقيدٌ بـ13.125 لنفس البيعة، ولا يُعرف أيّهما الحقيقة.
 */
class CurrencyDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        // 1 ريال عماني ≈ 2.6 دولار
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'USD', 'name' => 'دولار',
            'symbol' => '$', 'rate' => 2.6, 'is_base' => false, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'quantity' => 50, 'active' => true,
        ]);
    }

    private function sell(): \App\Models\Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk();

        return \App\Models\Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    public function test_switching_display_currency_does_not_change_what_is_recorded(): void
    {
        $this->actingAs($this->owner);

        session(['display_currency' => 'USD']);
        Demo::flushCurrency();

        $order = $this->sell();

        // 10 + 5% = 10.5 بالعملة الأساسية مهما كانت عملة العرض
        $this->assertSame(10.5, (float) $order->total, 'القيد تأثّر بعملة العرض');
        $this->assertSame(10.0, (float) $order->subtotal);
    }

    public function test_the_tax_invoice_stays_in_the_base_currency(): void
    {
        $this->actingAs($this->owner);
        $order = $this->sell();

        session(['display_currency' => 'USD']);
        Demo::flushCurrency();

        // moneyBase هي ما تستعمله قوالب الفواتير
        $this->assertStringContainsString('10.500', Demo::moneyBase($order->total));
        $this->assertStringNotContainsString('27.300', Demo::moneyBase($order->total));
    }

    public function test_the_screen_converts_at_the_declared_rate(): void
    {
        $this->actingAs($this->owner);

        session(['display_currency' => 'USD']);
        Demo::flushCurrency();

        // 10.5 × 2.6 = 27.3
        $this->assertStringContainsString('27.3', Demo::money(10.5));
    }

    public function test_an_unknown_or_inactive_currency_falls_back_to_base(): void
    {
        $this->actingAs($this->owner);

        session(['display_currency' => 'EUR']);
        Demo::flushCurrency();

        $this->assertSame('OMR', Demo::displayCurrency()['code']);
    }

    public function test_one_business_cannot_display_another_businesses_currency(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $theirs->id, 'code' => 'JOD', 'name' => 'دينار',
            'symbol' => 'د.أ', 'rate' => 99, 'is_base' => false, 'active' => true,
        ]);

        $this->actingAs($this->owner);
        session(['display_currency' => 'JOD']);
        Demo::flushCurrency();

        $this->assertSame('OMR', Demo::displayCurrency()['code'], 'عرض بعملة متجر آخر');
    }
}
