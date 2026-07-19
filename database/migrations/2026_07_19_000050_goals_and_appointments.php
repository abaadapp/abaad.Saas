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

        // المواعيد والطلبات المجدولة
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('title');
            $table->string('customer_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('branch')->default('الفرع الرئيسي');
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at');
            $table->string('status')->default('مجدول'); // مجدول | مؤكد | مكتمل | ملغي
            $table->boolean('reminded')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['monthly_target', 'commission_rate']);
        });
    }
};
