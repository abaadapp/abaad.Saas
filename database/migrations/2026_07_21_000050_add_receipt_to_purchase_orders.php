<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('receipt')->nullable()->after('notes');       // مسار الملف
            $table->string('receipt_name')->nullable()->after('receipt'); // الاسم الأصلي
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['receipt', 'receipt_name']);
        });
    }
};
