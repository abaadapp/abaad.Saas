<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيمة ضريبة القيمة المضافة ضمن المعاملة — لفصل صافي الإيرادات عن الضريبة المحصّلة
 * (التزام تجاه هيئة الضرائب، لا ربحًا). المبلغ (amount) يبقى الإجمالي المقبوض؛
 * صافي الإيراد = amount − tax_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 3)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });
    }
};
