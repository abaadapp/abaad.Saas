<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Coupon;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCouponSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_store_has_new_discount_codes_and_keeps_them_tenant_isolated(): void
    {
        $merchant = Business::create([
            'name' => 'متجر حقيقي',
            'type' => 'عام',
            'status' => 'نشط',
        ]);
        $demo = DemoStore::create('متجر العرض', 'صغير');

        $expected = [
            'DEMO15' => ['نسبة', 15.0, 10.0, 150],
            'SAVE5' => ['مبلغ', 5.0, 25.0, 100],
            'BIGORDER25' => ['نسبة', 25.0, 100.0, 60],
        ];

        foreach ($expected as $code => [$type, $value, $minimum, $maxUses]) {
            $coupon = Coupon::where('business_id', $demo->id)
                ->where('code', $code)
                ->firstOrFail();

            $this->assertSame($type, $coupon->type);
            $this->assertEquals($value, (float) $coupon->value);
            $this->assertEquals($minimum, (float) $coupon->min_order);
            $this->assertSame($maxUses, (int) $coupon->max_uses);
            $this->assertTrue($coupon->active);
            $this->assertTrue($coupon->expires_at->isFuture());
            $this->assertDatabaseMissing('coupons', [
                'business_id' => $merchant->id,
                'code' => $code,
            ]);
            $this->assertSame(1, Coupon::where('business_id', $demo->id)->where('code', $code)->count());
        }

        DemoStore::reseed($demo, 'صغير');

        foreach (array_keys($expected) as $code) {
            $this->assertSame(
                1,
                Coupon::where('business_id', $demo->id)->where('code', $code)->count(),
                "الكوبون {$code} يجب أن يبقى مرة واحدة بعد إعادة بناء الديمو",
            );
        }
    }
}
