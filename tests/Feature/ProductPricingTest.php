<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مقبضان في نموذج المنتج لم يكن يقرؤهما أحد.
 *
 * «الضريبة» و«الخصم (%)» يُملآن ويُحفظان، والبيع يقرأ السعر وحده: الضريبة
 * تُحسب من إعداد المتجر، والخصم يأتي من الكوبون والنقاط فقط. فمن وضع خصم
 * ٢٠٪ على صنفٍ ثم باعه بكامل سعره لا يعلم — والمقبض غير الموصول أسوأ من
 * غيابه لأنه يطمئن.
 *
 * وأخطر ما في التوصيل أن يصير الصفر إعلانًا بالإعفاء: الأصناف القائمة كلّها
 * تبدأ من صفر، فتخرج فواتير المتاجر بلا ضريبة بين ليلةٍ وضحاها. فالفراغ
 * «اتبع المتجر»، والصفر قرارٌ يُكتب باليد.
 */
class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->actingAs($this->owner);
        $this->activatePosDevice($this->business->id, $this->branch->id);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 50, 'alert_qty' => 5, 'active' => true,
        ], $over));
    }

    private function sell(Product $p, int $qty = 1): Order
    {
        $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => $qty]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('t', true),
        ])->assertOk();

        return Order::latest('id')->first();
    }

    /* ------------------------------- الخصم ------------------------------- */

    public function test_the_product_discount_reaches_the_receipt(): void
    {
        $order = $this->sell($this->product(['discount' => 20]));

        $this->assertSame(80.0, (float) $order->subtotal, 'بيع بكامل السعر ومعه خصم ٢٠٪');
        $this->assertSame(80.0, (float) $order->items()->first()->price);
    }

    public function test_a_product_without_a_discount_is_untouched(): void
    {
        $order = $this->sell($this->product());

        $this->assertSame(100.0, (float) $order->subtotal);
    }

    public function test_a_discount_over_a_hundred_is_refused(): void
    {
        // خصمٌ ٥٠٠ على سعر ١٠ يجعل سطر الفاتورة سالبًا — وكان يُقبل ويُحفظ
        $this->post(route('admin.products.store'), [
            'name' => 'خصم كبير', 'price' => 10, 'discount' => 500,
        ])->assertSessionHasErrors('discount');

        $this->post(route('admin.products.store'), [
            'name' => 'ضريبة كبيرة', 'price' => 10, 'tax' => 900,
        ])->assertSessionHasErrors('tax');
    }

    public function test_a_stored_over_discount_still_cannot_go_negative(): void
    {
        // ما حُفظ قبل القيد يُقصّ عند البيع لا يُصدَّق
        $p = $this->product(['price' => 10]);
        Product::whereKey($p->id)->update(['discount' => 500]);

        $this->assertSame(0.0, $p->fresh()->sellingPrice());
    }

    /* ------------------------------ الضريبة ------------------------------ */

    public function test_an_empty_rate_follows_the_store(): void
    {
        $order = $this->sell($this->product(['tax' => null]));

        $this->assertSame(5.0, (float) $order->tax, 'الصنف بلا نسبة يتبع نسبة المتجر');
    }

    public function test_a_zero_rated_product_carries_no_tax(): void
    {
        // الخبز والحليب والدواء صفرية في عُمان — حاجةٌ لا رفاهية
        $order = $this->sell($this->product(['tax' => 0]));

        $this->assertSame(0.0, (float) $order->tax);
        $this->assertSame(100.0, (float) $order->total);
    }

    public function test_each_line_is_taxed_at_its_own_rate(): void
    {
        $bread = $this->product(['name' => 'خبز', 'price' => 100, 'tax' => 0]);
        $juice = $this->product(['name' => 'عصير', 'price' => 100, 'tax' => 5]);

        $this->postJson(route('pos.checkout'), [
            'items' => [
                ['id' => $bread->id, 'name' => 'خبز', 'qty' => 1],
                ['id' => $juice->id, 'name' => 'عصير', 'qty' => 1],
            ],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('t', true),
        ])->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame(5.0, (float) $order->tax, 'ضريبة العصير وحده');
        $this->assertSame(205.0, (float) $order->total);
    }

    public function test_a_higher_product_rate_beats_the_store_rate(): void
    {
        $order = $this->sell($this->product(['price' => 100, 'tax' => 10]));

        $this->assertSame(10.0, (float) $order->tax);
    }
}
