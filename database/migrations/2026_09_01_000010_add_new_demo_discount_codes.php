<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupons')) {
            return;
        }

        $demoBusinessIds = DB::table('coupons')
            ->whereIn('code', ['WELCOME10', 'SUMMER5', 'VIP20'])
            ->groupBy('business_id')
            ->havingRaw('COUNT(DISTINCT code) = 3')
            ->pluck('business_id');

        foreach ($demoBusinessIds as $businessId) {
            $expiresAt = now()->addMonths(2);
            $now = now();

            $coupons = [
                ['code' => 'DEMO15', 'type' => 'نسبة', 'value' => 15, 'min_order' => 10, 'max_uses' => 75],
                ['code' => 'SAVE3', 'type' => 'مبلغ', 'value' => 3, 'min_order' => 15, 'max_uses' => 50],
                ['code' => 'WEEKEND25', 'type' => 'نسبة', 'value' => 25, 'min_order' => 100, 'max_uses' => 20],
            ];

            foreach ($coupons as $coupon) {
                DB::table('coupons')->updateOrInsert(
                    ['business_id' => $businessId, 'code' => $coupon['code']],
                    array_merge($coupon, [
                        'used_count' => 0,
                        'expires_at' => $expiresAt,
                        'active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ])
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('coupons')) {
            return;
        }

        $demoBusinessIds = DB::table('coupons')
            ->whereIn('code', ['WELCOME10', 'SUMMER5', 'VIP20'])
            ->groupBy('business_id')
            ->havingRaw('COUNT(DISTINCT code) = 3')
            ->pluck('business_id');

        DB::table('coupons')
            ->whereIn('business_id', $demoBusinessIds)
            ->whereIn('code', ['DEMO15', 'SAVE3', 'WEEKEND25'])
            ->delete();
    }
};
