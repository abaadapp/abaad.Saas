<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط معاملة الدخل بطلب نقطة البيع الذي ولّدها — لتظهر المبيعات في المالية تلقائيًا،
 * ولمنع تكرار تسجيلها (معاملة واحدة لكل طلب).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('business_id');
            $table->index(['business_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'order_id']);
            $table->dropColumn('order_id');
        });
    }
};
