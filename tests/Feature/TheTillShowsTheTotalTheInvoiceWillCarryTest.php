<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ما تقوله شاشةُ الصندوق هو ما تكتبه الفاتورة — بالبيسة.
 *
 * ═══ العطب ═══
 *
 * الشاشةُ كانت تحسب الخصمَ والضريبةَ والإجماليَّ بلا تقريب، والخادمُ يقرّب
 * كلَّ خطوةٍ إلى ثلاث منازل (`Coupon::discountFor` ثمّ `SaleLines::taxFor` ثمّ
 * الإجماليّ). فكوبونٌ بالنسبة على ٢٫٦٥٠ يُقرأ على الشاشة ٢٫٥١٨ ويُكتب في
 * الفاتورة ٢٫٥١٧: يُقال للزبون مبلغٌ ويُطبع له غيرُه، ويبقى فرقٌ في الدرج.
 *
 * والحالاتُ في `tests/fixtures/pos-coupon-totals.json` يقرؤها هذا الملفّ
 * ويقرؤها `tests/js/the-till-shows-the-total-the-invoice-will-carry.test.tsx`:
 * يوم يفترق الطرفان يسقط أحدُهما.
 */
class TheTillShowsTheTotalTheInvoiceWillCarryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $shared = json_decode((string) file_get_contents(__DIR__.'/../fixtures/pos-coupon-totals.json'), true);

        $out = [];
        foreach ($shared['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cases')]
    public function test_the_invoice_carries_the_numbers_the_till_showed(array $case): void
    {
        $shop = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => $case['vat']['enabled'] ? '1' : '0']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_rate', 'value' => (string) $case['vat']['rate']]);
        Setting::create(['business_id' => $shop->id, 'key' => 'tax_mode', 'value' => $case['vat']['inclusive'] ? 'inclusive' : 'exclusive']);

        $cashier = User::create([
            'business_id' => $shop->id, 'name' => 'كاشير', 'email' => 'c@till-total.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $items = [];
        foreach ($case['lines'] as $i => $line) {
            $product = Product::create([
                'business_id' => $shop->id, 'name' => 'صنف '.$i, 'price' => $line['price'], 'cost' => 0.1,
                'quantity' => 100, 'active' => true,
            ]);
            $items[] = ['id' => $product->id, 'name' => $product->name, 'qty' => $line['qty']];
        }

        Coupon::create([
            'business_id' => $shop->id, 'code' => 'SHARED', 'type' => $case['coupon']['type'],
            'value' => $case['coupon']['value'], 'min_order' => $case['coupon']['min_order'],
            'active' => true, 'used_count' => 0,
        ]);

        $this->actingAs($cashier)->postJson('/pos/checkout', [
            'items' => $items,
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('t', true),
            'coupon_code' => 'SHARED',
        ])->assertOk()->assertJsonPath('ok', true);

        $order = Order::latest('id')->firstOrFail();
        $expect = $case['expect'];

        // وفرقُ بيسةٍ واحدةٍ (0.001) يُسقطه — الدلتا أصغرُ منه بعشر مرّات
        $this->assertEqualsWithDelta($expect['coupon_discount'], (float) $order->coupon_discount, 0.0001, 'خصمُ الكوبون في الفاتورة غيرُ ما على الشاشة');
        $this->assertEqualsWithDelta($expect['discount'], (float) $order->discount, 0.0001, 'الخصمُ في الفاتورة غيرُ ما على الشاشة');
        $this->assertEqualsWithDelta($expect['tax'], (float) $order->tax, 0.0001, 'الضريبةُ في الفاتورة غيرُ ما على الشاشة');
        $this->assertEqualsWithDelta($expect['total'], (float) $order->total, 0.0001, 'الإجماليُّ في الفاتورة غيرُ ما قيل للزبون');
    }
}
