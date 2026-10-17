<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Coupon;
use Illuminate\Database\Seeder;

class DemoCouponSeeder extends Seeder
{
    /**
     * Extra coupon codes for the primary demo business only.
     *
     * Safe to run repeatedly: each code is updated in place for زهرة مسقط
     * and no other tenant is touched.
     */
    public function run(): void
    {
        $business = Business::query()->where('name', 'زهرة مسقط')->first();

        if (! $business) {
            throw new \RuntimeException(
                'نشاط الديمو "زهرة مسقط" غير موجود. شغّل DemoSeeder أولاً.'
            );
        }

        $coupons = [
            ['code' => 'WELCOME10', 'type' => 'نسبة', 'value' => 10, 'min_order' => 5, 'max_uses' => 100],
            ['code' => 'DEMO5', 'type' => 'مبلغ', 'value' => 5, 'min_order' => 20, 'max_uses' => 50],
            ['code' => 'VIP20', 'type' => 'نسبة', 'value' => 20, 'min_order' => 50, 'max_uses' => 25],
        ];

        foreach ($coupons as $coupon) {
            Coupon::updateOrCreate(
                [
                    'business_id' => $business->id,
                    'code' => $coupon['code'],
                ],
                array_merge($coupon, [
                    'business_id' => $business->id,
                    'used_count' => 0,
                    'active' => true,
                    'expires_at' => now()->addMonths(2),
                ])
            );
        }
    }
}
