<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // أهداف وعمولات الموظفين
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('monthly_target', 12, 3)->default(0)->after('sales_total');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('monthly_target'); // نسبة مئوية من المبيعات
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['monthly_target', 'commission_rate']);
        });
    }
};
