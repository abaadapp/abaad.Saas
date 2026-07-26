<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // كود الكوبون المستخدم في الطلب (إن وُجد) — للسجل والتحليلات
            $table->string('coupon_code', 40)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('coupon_code'));
    }
};
